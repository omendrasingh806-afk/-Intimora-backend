<?php
// Intimora PHP Backend — BestFile helper
// Sign-in by email, then fetch a fresh CDN (Backblaze B2) signed URL for streaming.
// Verified flow: login (POST /en/login) -> refresh CSRF (GET /en) ->
//   POST /<id>/download/create -> follow redirects -> final B2 signed URL.
// Session cookies are cached in a temp JSON file so they survive across requests.

function bestfile_load_session() {
    $f = BESTFILE_SESSION_FILE;
    if (is_file($f)) {
        $s = json_decode(@file_get_contents($f), true);
        if ($s && !empty($s['cookies'])) return $s;
    }
    return ['cookies' => [], 'csrf' => ''];
}

function bestfile_save_session($s) {
    $f = BESTFILE_SESSION_FILE;
    @file_put_contents($f, json_encode($s));
    @chmod($f, 0644);
}

function bestfile_cookie_header($cookies) {
    $parts = [];
    foreach ($cookies as $k => $v) $parts[] = $k . '=' . $v;
    return implode('; ', $parts);
}

function bestfile_store_cookies($session, $setCookieHeaders) {
    if (empty($setCookieHeaders)) return $session;
    $list = is_array($setCookieHeaders) ? $setCookieHeaders : [$setCookieHeaders];
    foreach ($list as $c) {
        if (preg_match('/^([^=]+)=([^;]*)/', $c, $m)) {
            $session['cookies'][trim($m[1])] = $m[2];
        }
    }
    return $session;
}

function bestfile_csrf_from($html) {
    if (preg_match('/csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

/**
 * Low-level HTTP request to bestfile.io.
 * Returns [status, body, effectiveUrl].
 */
function bestfile_request($method, $path, &$session, $opts = []) {
    $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'];
    if (!empty($session['cookies'])) $headers[] = 'Cookie: ' . bestfile_cookie_header($session['cookies']);
    $extra = $opts['headers'] ?? [];
    foreach ($extra as $h) $headers[] = $h;

    $body = $opts['body'] ?? null;
    if (isset($opts['form'])) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query($opts['form']);
    }

    if (function_exists('curl_init')) {
        $ch = curl_init('https://' . BESTFILE_HOST . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CUSTOMREQUEST    => $method,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 10,
            CURLOPT_CONNECTTIMEOUT   => 20,
            CURLOPT_TIMEOUT          => 40,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_HEADER           => true,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        // Capture set-cookie headers
        $setCookie = [];
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if ($resp !== false && $headerSize > 0) {
            $headerPart = substr($resp, 0, $headerSize);
            if (preg_match_all('/^Set-Cookie:\s*(.*)$/mi', $headerPart, $m)) {
                $setCookie = $m[1];
            }
        }
        curl_close($ch);
        if ($setCookie) $session = bestfile_store_cookies($session, $setCookie);
        $respBody = ($resp !== false) ? substr($resp, $headerSize) : '';
        return [$status, $respBody, $effUrl];
    }

    // Fallback: stream context (if cURL missing)
    $ctx = stream_context_create([
        'http'  => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 40, 'follow_location' => 10],
        'ssl'   => ['verify_peer' => true],
    ]);
    $resp = @file_get_contents('https://' . BESTFILE_HOST . $path, false, $ctx);
    $status = 0;
    $effUrl = 'https://' . BESTFILE_HOST . $path;
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?: [])
        : ($http_response_header ?? []);
    if (!empty($responseHeaders)) {
        foreach ($responseHeaders as $h) {
            if (preg_match('/^Set-Cookie:\s*(.*)$/i', $h, $m)) {
                $session = bestfile_store_cookies($session, $m[1]);
            }
        }
    }
    return [$status, $resp, $effUrl];
}

/**
 * Get a fresh CDN (B2) signed URL for a BestFile file.
 * Pass the download_link (page URL, e.g. https://bestfile.io/<id>/file).
 * Returns the direct Backblaze B2 URL, or false on failure.
 *
 * The file's own page hands out the CSRF token and the session cookie the
 * create call needs. There is no sign-in step: /en/login is behind a bot
 * challenge and answers 403, while the file page itself still works.
 */
function bestfile_get_cdn_url($downloadLink) {
    if (!preg_match('#https?://' . preg_quote(BESTFILE_HOST, '#') . '/([^/]+)/file#i', $downloadLink, $m)) return false;
    $pageId = $m[1];

    $session = ['cookies' => [], 'csrf' => ''];
    [$st, $html] = bestfile_request('GET', '/en/' . rawurlencode($pageId) . '/file', $session);
    $csrf = bestfile_csrf_from($html);
    if ($st !== 200 || $csrf === '') return false;

    [$st2, $body] = bestfile_request('POST', '/' . $pageId . '/download/create', $session, [
        'headers' => [
            'X-CSRF-TOKEN: ' . $csrf,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'Referer: https://' . BESTFILE_HOST . '/en/' . $pageId . '/file',
        ],
    ]);
    if ($st2 !== 200) return false;

    $data = json_decode($body, true);
    $link = is_array($data) ? (string) ($data['download_link'] ?? '') : '';
    if ($link === '') return false;

    // Follow the redirect chain to the final Backblaze B2 signed URL (1 byte).
    $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'];
    if (!empty($session['cookies'])) $headers[] = 'Cookie: ' . bestfile_cookie_header($session['cookies']);
    $headers[] = 'Range: bytes=0-0';

    if (function_exists('curl_init')) {
        $ch = curl_init($link);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPGET          => true,
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS        => 10,
            CURLOPT_CONNECTTIMEOUT  => 20,
            CURLOPT_TIMEOUT          => 40,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);
        $resp = curl_exec($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $final ? $final : false;
    }
    // Fallback: stream context (the client follows the redirect).
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 40, 'follow_location' => 10], 'ssl' => ['verify_peer' => true]]);
    $resp = @file_get_contents($link, false, $ctx);
    if (empty($resp)) return false;
    return $link;
}

// Page id of a BestFile page link, or '' when the link is not one.
function bestfile_page_id($downloadLink) {
    if (!is_string($downloadLink) || $downloadLink === '') return '';
    if (!preg_match('#https?://' . preg_quote(BESTFILE_HOST, '#') . '/([^/]+)/file#i', $downloadLink, $m)) return '';
    return $m[1];
}

// Run several curl handles at once and return their status, body and final URL.
function bestfile_multi_run(array $handles) {
    $mh = curl_multi_init();
    foreach ($handles as $h) curl_multi_add_handle($mh, $h);
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $key => $h) {
        $out[$key] = [
            'status' => (int) curl_getinfo($h, CURLINFO_HTTP_CODE),
            'body' => (string) curl_multi_getcontent($h),
            'url' => (string) curl_getinfo($h, CURLINFO_EFFECTIVE_URL),
        ];
        curl_multi_remove_handle($mh, $h);
        curl_close($h);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Resolve many page links to direct (B2) URLs in one batch, so a list of twelve
 * videos costs one round of parallel requests instead of twelve slow chains.
 * Returns link => direct URL; a link that could not be resolved is left out.
 */
function bestfile_get_cdn_urls(array $downloadLinks) {
    $links = array_values(array_unique(array_filter(array_map('strval', $downloadLinks))));
    if (!$links) return [];
    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($links as $link) {
            $direct = bestfile_get_cdn_url($link);
            if (is_string($direct) && $direct !== '') $out[$link] = $direct;
        }
        return $out;
    }

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
    $pages = [];
    $handles = [];

    // 1. Every file page at once: it hands out the CSRF token and its cookies.
    foreach ($links as $i => $link) {
        $pageId = bestfile_page_id($link);
        if ($pageId === '') continue;
        $pages[$i] = ['link' => $link, 'pageId' => $pageId, 'cookies' => [], 'csrf' => '', 'download' => ''];
        $ch = curl_init('https://' . BESTFILE_HOST . '/en/' . rawurlencode($pageId) . '/file');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$pages, $i) {
                if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $m)) {
                    $pages[$i]['cookies'][trim($m[1])] = $m[2];
                }
                return strlen($header);
            },
        ]);
        $handles[$i] = $ch;
    }
    if (!$handles) return [];

    foreach (bestfile_multi_run($handles) as $i => $r) {
        if ($r['status'] === 200) $pages[$i]['csrf'] = bestfile_csrf_from($r['body']);
    }

    // 2. Ask each page for its download link.
    $handles = [];
    foreach ($pages as $i => $p) {
        if ($p['csrf'] === '') continue;
        $ch = curl_init('https://' . BESTFILE_HOST . '/' . rawurlencode($p['pageId']) . '/download/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => [
                'Cookie: ' . bestfile_cookie_header($pages[$i]['cookies']),
                'X-CSRF-TOKEN: ' . $p['csrf'],
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json',
                'Referer: https://' . BESTFILE_HOST . '/en/' . $p['pageId'] . '/file',
            ],
        ]);
        $handles[$i] = $ch;
    }

    foreach (bestfile_multi_run($handles) as $i => $r) {
        if ($r['status'] !== 200) continue;
        $data = json_decode($r['body'], true);
        if (is_array($data) && !empty($data['download_link'])) {
            $pages[$i]['download'] = (string) $data['download_link'];
        }
    }

    // 3. Follow each download link to the signed Backblaze URL.
    $handles = [];
    foreach ($pages as $i => $p) {
        if ($p['download'] === '') continue;
        $ch = curl_init($p['download']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => [
                'Cookie: ' . bestfile_cookie_header($p['cookies']),
                'Range: bytes=0-0',
            ],
        ]);
        $handles[$i] = $ch;
    }

    $out = [];
    foreach (bestfile_multi_run($handles) as $i => $r) {
        if ($r['url'] !== '' && strpos($r['url'], BESTFILE_HOST) === false) {
            $out[$pages[$i]['link']] = $r['url'];
        }
    }
    return $out;
}

