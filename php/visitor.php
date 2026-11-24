<?php
// Intimora PHP Backend — visitor log (IP / geo / device)
//
// The frontend calls `POST /api/visit` once per session, right after the age
// gate. The request arrives in the browser, straight from the PHP origin (no
// proxy in between — the hosting challenge is solved by /__unlock), so
// REMOTE_ADDR is the visitor's real address and HTTP_USER_AGENT is the real
// browser string.
//
// What the server can know by itself:
//   ip, country/region/city (from a cached IP lookup), browser, browser version,
//   operating system, device class, Android model number (Android only).
//
// What it cannot know: the exact phone model on iPhone/iPad (Apple never puts it
// in the user agent) — for that the frontend sends the value of
// navigator.userAgentData.getHighEntropyValues(["model"]) when the browser
// supports it.
//
// Everything is stored in MySQL (visitor_logs). Writes happen after the response
// has been flushed, so a slow geo lookup or a missing database never delays the
// visitor. A failed insert is silent: logging must never break the API.

if (!defined('INTIMORA_VISITOR')) {
define('INTIMORA_VISITOR', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fastcache.php';

// Accounts are tried in order; the first one that answers owns the database.
// An empty 'name' means "auto-detect" (SHOW DATABASES, first match for the
// account's own prefix), because InfinityFree names databases
// `<user>_<something>` and the suffix is chosen in the panel.
if (!defined('VISITOR_DB_ACCOUNTS')) {
    define('VISITOR_DB_ACCOUNTS', []);
}
if (!defined('VISITOR_RETENTION_DAYS')) {
    define('VISITOR_RETENTION_DAYS', 90);
}

// === Connection ===

function visitor_db_connect($account) {
    if (!class_exists('mysqli')) {
        return null;
    }
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    $conn = @new mysqli(
        $account['host'],
        $account['user'],
        $account['pass'],
        '',
        (int) ($account['port'] ?? 3306)
    );
    if (!$conn || $conn->connect_errno) {
        return null;
    }
    @$conn->set_charset('utf8mb4');
    return $conn;
}

// Database name: the configured one, or the only database of this account.
function visitor_db_name($conn, $account) {
    $name = (string) ($account['name'] ?? '');
    if ($name !== '') {
        return $name;
    }

    $cacheKey = 'visitor_dbname_' . md5($account['user']);
    $cached = fc_get($cacheKey, 86400);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $prefix = $account['user'] . '_';
    $result = @$conn->query('SHOW DATABASES');
    if (!$result) {
        return '';
    }
    $names = [];
    while ($row = $result->fetch_row()) {
        $candidate = (string) ($row[0] ?? '');
        if (str_starts_with($candidate, $prefix)) {
            $names[] = $candidate;
        }
    }
    if (!$names) {
        // Some plans do not list databases through SHOW DATABASES. Fall back to
        // the conventional name (<user>_intimora) via information_schema, which
        // only works when this account really owns that database.
        foreach (['intimora', 'visitor', 'data'] as $suffix) {
            $guess = $prefix . $suffix;
            $stmt = @$conn->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            if (!$stmt) {
                break;
            }
            @$stmt->bind_param('s', $guess);
            $found = '';
            if (@$stmt->execute()) {
                @$stmt->bind_result($found);
                @$stmt->fetch();
            }
            @$stmt->close();
            if ($found !== '') {
                $names[] = $found;
                break;
            }
        }
    }
    if (!$names) {
        return '';
    }
    // "xxx_intimora" wins over an arbitrary suffix when several exist.
    foreach ($names as $candidate) {
        if (stripos($candidate, 'intimora') !== false) {
            fc_set($cacheKey, $candidate);
            return $candidate;
        }
    }
    fc_set($cacheKey, $names[0]);
    return $names[0];
}

function visitor_db() {
    static $conn = null;
    static $tried = false;
    if ($tried) {
        return $conn;
    }
    $tried = true;

    $index = fc_get('visitor_db_account', 86400);
    $accounts = (array) VISITOR_DB_ACCOUNTS;
    $order = array_keys($accounts);
    if (is_int($index) && isset($accounts[$index])) {
        // Try the account that worked last time first.
        $order = array_merge([$index], array_values(array_filter($order, function ($i) use ($index) {
            return $i !== $index;
        })));
    }

    foreach ($order as $i) {
        $account = $accounts[$i] ?? null;
        if (!is_array($account) || empty($account['host'])) {
            continue;
        }
        $candidate = visitor_db_connect($account);
        if (!$candidate) {
            continue;
        }
        $name = visitor_db_name($candidate, $account);
        if ($name === '' || !@$candidate->select_db($name)) {
            @$candidate->close();
            continue;
        }
        fc_set('visitor_db_account', (int) $i);
        return $conn = $candidate;
    }

    return null;
}

function visitor_table_sql() {
    return 'CREATE TABLE IF NOT EXISTS visitor_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) DEFAULT NULL,
        ip_hash CHAR(64) DEFAULT NULL,
        country CHAR(2) DEFAULT NULL,
        region VARCHAR(96) DEFAULT NULL,
        city VARCHAR(96) DEFAULT NULL,
        browser VARCHAR(32) DEFAULT NULL,
        browser_ver VARCHAR(16) DEFAULT NULL,
        os VARCHAR(32) DEFAULT NULL,
        device VARCHAR(16) DEFAULT NULL,
        model VARCHAR(64) DEFAULT NULL,
        ua VARCHAR(512) DEFAULT NULL,
        lang VARCHAR(24) DEFAULT NULL,
        tz VARCHAR(48) DEFAULT NULL,
        screen VARCHAR(24) DEFAULT NULL,
        path VARCHAR(255) DEFAULT NULL,
        referer VARCHAR(255) DEFAULT NULL,
        user_id VARCHAR(64) DEFAULT NULL,
        created_at INT UNSIGNED DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_time (created_at),
        KEY idx_ip (ip_hash),
        KEY idx_country (country)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
}

function visitor_ensure_table($conn) {
    if (fc_get('visitor_table_ok', 1800) === true) {
        return true;
    }
    if (!@$conn->query(visitor_table_sql())) {
        return false;
    }
    fc_set('visitor_table_ok', true);
    return true;
}

// === Request facts ===

// Real visitor address. X-Client-IP / CF-Connecting-IP are only set when the
// request came through Cloudflare; REMOTE_ADDR is the hosting's own answer.
function visitor_ip() {
    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    $firstForwarded = '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $firstForwarded = trim((string) ($parts[0] ?? ''));
    }

    $candidates = [
        (string) ($_SERVER['HTTP_X_CLIENT_IP'] ?? ''),
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        $firstForwarded,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate, " \t\"'");
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return '';
}

// Browser / OS / device class / Android model, parsed from a user agent string.
function visitor_ua($ua) {
    $out = [
        'browser' => 'Other',
        'browser_ver' => '',
        'os' => 'Other',
        'device' => 'desktop',
        'model' => '',
    ];
    $ua = (string) $ua;
    if ($ua === '') {
        return $out;
    }

    // Order matters: every Chromium browser also says "Safari/".
    $browsers = [
        ['Edg/', 'Edge'],
        ['EdgA/', 'Edge'],
        ['OPR/', 'Opera'],
        ['OPiOS/', 'Opera'],
        ['YaBrowser/', 'Yandex'],
        ['SamsungBrowser/', 'Samsung'],
        ['Firefox/', 'Firefox'],
        ['FxiOS/', 'Firefox'],
        ['CriOS/', 'Chrome'],
        ['Chrome/', 'Chrome'],
        ['Safari/', 'Safari'],
    ];
    foreach ($browsers as $entry) {
        $needle = $entry[0];
        if (strpos($ua, $needle) === false) {
            continue;
        }
        $out['browser'] = $entry[1];
        if (preg_match('#' . preg_quote($needle, '#') . '([0-9]+(?:\.[0-9]+)*)#', $ua, $m)) {
            $out['browser_ver'] = $m[1];
        }
        break;
    }

    if (preg_match('/Android[ ]+([0-9.]+)/i', $ua, $m)) {
        $out['os'] = 'Android ' . $m[1];
    } elseif (preg_match('/iPhone OS[ ]+([0-9_]+)/i', $ua, $m)) {
        $out['os'] = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/CPU OS[ ]+([0-9_]+)/i', $ua, $m)) {
        $out['os'] = 'iPadOS ' . str_replace('_', '.', $m[1]);
    } elseif (strpos($ua, 'Windows NT 10.0') !== false) {
        $out['os'] = 'Windows 10/11';
    } elseif (preg_match('/Windows NT[ ]+([0-9.]+)/i', $ua, $m)) {
        $out['os'] = 'Windows ' . $m[1];
    } elseif (preg_match('/Mac OS X[ ]+([0-9_.]+)/i', $ua, $m)) {
        $out['os'] = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (stripos($ua, 'CrOS') !== false) {
        $out['os'] = 'ChromeOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $out['os'] = 'Linux';
    }

    if (strpos($ua, 'iPhone') !== false) {
        $out['device'] = 'mobile';
        $out['model'] = 'iPhone';
    } elseif (strpos($ua, 'iPad') !== false) {
        $out['device'] = 'tablet';
        $out['model'] = 'iPad';
    } elseif (preg_match('#Android[ ]+[0-9.]+;[ ]*([^;)]+?)(?:[ ]+Build[/:]|[ ]*\))#i', $ua, $m)) {
        $model = trim($m[1]);
        // "K" and "wv" are Chrome's placeholders, not a real model.
        $placeholder = ($model === '' || strcasecmp($model, 'wv') === 0 || strcasecmp($model, 'K') === 0);
        if (!$placeholder) {
            $out['model'] = $model;
        }
        $out['device'] = stripos($ua, 'Mobile') !== false ? 'mobile' : 'tablet';
    } elseif (stripos($ua, 'Android') !== false) {
        $out['device'] = stripos($ua, 'Mobile') !== false ? 'mobile' : 'tablet';
    } elseif (stripos($ua, 'Mobile') !== false) {
        $out['device'] = 'mobile';
    }

    return $out;
}

function visitor_http_get($url, $timeout = 3) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        // curl_close() is a no-op since PHP 8 and deprecated in 8.5, so it is
        // not called: the handle is released when the request ends.
        return is_string($raw) ? $raw : '';
    }

    $context = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return is_string($raw) ? $raw : '';
}

// Country / region / city for an address. Cached per IP for a day, so the
// lookup service is asked once per visitor, not once per page view.
function visitor_geo($ip) {
    $out = ['country' => '', 'region' => '', 'city' => ''];
    if ($ip === '') {
        return $out;
    }

    // Free when the connection came through Cloudflare.
    $header = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $header) && $header !== 'XX') {
        $out['country'] = $header;
    }

    $public = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if (!$public) {
        return $out;
    }

    $cacheKey = 'geo_' . md5($ip);
    $cached = fc_get($cacheKey, 86400);
    if (is_array($cached)) {
        return [
            'country' => (string) ($cached['country'] ?? $out['country']),
            'region' => (string) ($cached['region'] ?? ''),
            'city' => (string) ($cached['city'] ?? ''),
        ];
    }
    // A failed lookup is retried after six hours, not on every request.
    if (fc_get('geofail_' . md5($ip), 21600) === true) {
        return $out;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
        . '?fields=status,countryCode,regionName,city';
    $raw = visitor_http_get($url, 3);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        fc_set('geofail_' . md5($ip), true);
        return $out;
    }

    $geo = [
        'country' => strtoupper((string) ($data['countryCode'] ?? '')),
        'region' => (string) ($data['regionName'] ?? ''),
        'city' => (string) ($data['city'] ?? ''),
    ];
    fc_set($cacheKey, $geo);
    return $geo;
}

// === Write ===

function visitor_trim($value, $max) {
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return mb_substr($value, 0, $max);
}

// $payload is the JSON body the frontend sent (ua, model, lang, tz, screen,
// path, referer, userId). Every field is optional: a plain `GET /api/visit`
// already records IP, geo and whatever the user agent says.
function visitor_log($payload = []) {
    try {
        $payload = is_array($payload) ? $payload : [];
        $conn = visitor_db();
        if (!$conn || !visitor_ensure_table($conn)) {
            return false;
        }

        $ua = '';
        foreach (['ua', 'userAgent', 'user_agent'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $ua = $payload[$key];
                break;
            }
        }
        if ($ua === '') {
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        }
        $ua = visitor_trim($ua, 512);

        $parsed = visitor_ua($ua);
        $ip = visitor_ip();
        $geo = visitor_geo($ip);

        // The client may know the Android model when the user agent is generic.
        $model = visitor_trim($payload['model'] ?? null, 64);
        if ($model !== null) {
            $parsed['model'] = $model;
        }

        $salt = defined('VISITOR_IP_SALT') ? VISITOR_IP_SALT : 'intimora';

        $values = [
            'ip' => $ip !== '' ? $ip : null,
            'ip_hash' => $ip !== '' ? hash('sha256', $ip . '|' . $salt) : null,
            'country' => $geo['country'] !== '' ? $geo['country'] : null,
            'region' => visitor_trim($geo['region'], 96),
            'city' => visitor_trim($geo['city'], 96),
            'browser' => visitor_trim($parsed['browser'], 32),
            'browser_ver' => visitor_trim($parsed['browser_ver'], 16),
            'os' => visitor_trim($parsed['os'], 32),
            'device' => visitor_trim($parsed['device'], 16),
            'model' => visitor_trim($parsed['model'], 64),
            'ua' => $ua,
            'lang' => visitor_trim($payload['lang'] ?? ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null), 24),
            'tz' => visitor_trim($payload['tz'] ?? null, 48),
            'screen' => visitor_trim($payload['screen'] ?? null, 24),
            'path' => visitor_trim($payload['path'] ?? null, 255),
            'referer' => visitor_trim($payload['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? null), 255),
            'user_id' => visitor_trim($payload['userId'] ?? null, 64),
            'created_at' => time(),
        ];

        $stmt = @$conn->prepare(
            'INSERT INTO visitor_logs
                (ip, ip_hash, country, region, city, browser, browser_ver, os,
                 device, model, ua, lang, tz, screen, path, referer, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'sssssssssssssssssi',
            $values['ip'],
            $values['ip_hash'],
            $values['country'],
            $values['region'],
            $values['city'],
            $values['browser'],
            $values['browser_ver'],
            $values['os'],
            $values['device'],
            $values['model'],
            $values['ua'],
            $values['lang'],
            $values['tz'],
            $values['screen'],
            $values['path'],
            $values['referer'],
            $values['user_id'],
            $values['created_at']
        );
        $ok = @$stmt->execute();
        @$stmt->close();

        // Old rows go once a day, in small batches, so the table cannot grow
        // without limit on a free plan.
        if (fc_get('visitor_purge_ok', 86400) !== true) {
            $cutoff = time() - ((int) VISITOR_RETENTION_DAYS * 86400);
            @$conn->query('DELETE FROM visitor_logs WHERE created_at < ' . $cutoff . ' LIMIT 5000');
            fc_set('visitor_purge_ok', true);
        }

        return (bool) $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

// Diagnostic used by /api/debug: is MySQL reachable, which database was found,
// does the table exist and how many rows are stored. Never throws.
function visitor_status() {
    // Never let a warning/exception escape: this hosting's error handler dumps
    // the call arguments, and the DB credentials are an argument here.
    try {
        return visitor_status_probe();
    } catch (\Throwable $e) {
        return ['errors' => ['exception: ' . $e->getMessage()]];
    }
}

function visitor_status_probe() {
    $out = [
        'mysqli' => class_exists('mysqli'),
        'db_accounts' => count((array) VISITOR_DB_ACCOUNTS),
        'connected_as' => null,
        'database' => null,
        'table_ready' => false,
        'rows' => null,
        'errors' => [],
    ];
    if (!$out['mysqli']) {
        $out['errors'][] = 'mysqli extension missing';
        return $out;
    }

    foreach ((array) VISITOR_DB_ACCOUNTS as $account) {
        if (!is_array($account) || empty($account['host'])) {
            continue;
        }
        $user = (string) ($account['user'] ?? '?');
        $conn = visitor_db_connect($account);
        if (!$conn) {
            $error = function_exists('mysqli_connect_error') ? (string) mysqli_connect_error() : '';
            $out['errors'][] = $user . ': connect failed' . ($error !== '' ? ' — ' . $error : '');
            continue;
        }

        $out['connected_as'] = $user;
        // This host does not grant SHOW DATABASES, so an empty list here is
        // normal — the configured database name is what actually matters.
        $visible = [];
        $res = @$conn->query('SHOW DATABASES');
        if ($res) {
            while ($row = $res->fetch_row()) {
                $visible[] = (string) ($row[0] ?? '');
            }
        }
        $out['visible_databases'][$user] = $visible;
        $name = visitor_db_name($conn, $account);
        if ($name === '') {
            $out['errors'][] = $user . ': no database on this account (create one in the control panel)';
            @$conn->close();
            continue;
        }
        if (!@$conn->select_db($name)) {
            $out['errors'][] = $user . ': cannot open ' . $name . ' — ' . (string) $conn->error;
            @$conn->close();
            continue;
        }

        $out['database'] = $name;
        $out['table_ready'] = visitor_ensure_table($conn);
        if (!$out['table_ready']) {
            $out['errors'][] = $name . ': cannot create visitor_logs — ' . (string) $conn->error;
        } else {
            $res = @$conn->query('SELECT COUNT(*) FROM visitor_logs');
            if ($res) {
                $row = $res->fetch_row();
                $out['rows'] = (int) ($row[0] ?? 0);
            }
        }
        @$conn->close();
        break;
    }

    return $out;
}

} // end include guard
