<?php
// Intimora PHP Backend — BestFile helper
// Turns a BestFile page link (https://bestfile.io/<id>/file) into the signed
// Backblaze B2 URL that serves the bytes. A new link is asked for on every
// request: the signed URL carries an expiry, so an old one is worthless.
//
// Verified flow: GET /en/<id>/file (CSRF token + session cookie) ->
//   POST /<id>/download/create (JSON download_link) -> follow BestFile's own
//   302s and read the Location header, which is already the signed B2 URL.
// The file itself is never downloaded here.

if (!defined('BESTFILE_UA')) {
    define('BESTFILE_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');
}

function bestfile_handle() {
    static $ch = null;
    if ($ch !== null) return $ch;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_COOKIEFILE     => '',   // in-memory cookie jar, keeps the TLS session warm
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => BESTFILE_UA,
    ]);
    return $ch;
}

// Raw request on the shared handle. Returns [status, headers, body].
function bestfile_raw($url, $opts = []) {
    $ch = bestfile_handle();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers'] ?? ['User-Agent: ' . BESTFILE_UA]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $opts['follow'] ?? true);
    if (!empty($opts['reset_cookies'])) curl_setopt($ch, CURLOPT_COOKIELIST, 'ALL');
    if (!empty($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $raw = $resp === false ? '' : $resp;
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

// The file page hands out the CSRF token and the session cookie. There is no
// sign-in step: /en/login is behind a bot challenge and answers 403, while the
// file page itself still works.
function bestfile_page_token($pageId, $refresh = false) {
    // One page load per request is enough: the token and cookie belong to the
    // session, not to the file, so a list reuses them until a call says otherwise.
    if (!$refresh && isset($GLOBALS['bf_page_tokens'][$pageId])) {
        return $GLOBALS['bf_page_tokens'][$pageId];
    }
    [$status, , $html] = bestfile_raw('https://' . BESTFILE_HOST . '/en/' . rawurlencode($pageId) . '/file', [
        'headers' => ['User-Agent: ' . BESTFILE_UA, 'Accept: text/html'],
        'reset_cookies' => true,
    ]);
    if ($status !== 200) return '';
    if (preg_match('/csrf-token" content="([^"]+)"/', $html, $m)) {
        $GLOBALS['bf_page_tokens'][$pageId] = $m[1];
        return $m[1];
    }
    return '';
}

function bestfile_create_link($pageId, $csrf) {
    [$status, , $body] = bestfile_raw('https://' . BESTFILE_HOST . '/' . rawurlencode($pageId) . '/download/create', [
        'post' => true,
        'headers' => [
            'User-Agent: ' . BESTFILE_UA,
            'X-CSRF-TOKEN: ' . $csrf,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'Referer: https://' . BESTFILE_HOST . '/en/' . $pageId . '/file',
        ],
    ]);
    if ($status !== 200) return '';
    $data = json_decode($body, true);
    return is_array($data) ? (string) ($data['download_link'] ?? '') : '';
}

// Follow BestFile's redirect chain and stop at the first URL that belongs to the
// storage host. That Location is the signed direct link — no need to fetch it.
function bestfile_signed_url($link) {
    $url = $link;
    for ($hop = 0; $hop < 5; $hop++) {
        [$status, $headers] = bestfile_raw($url, [
            'headers' => ['User-Agent: ' . BESTFILE_UA, 'Accept: */*'],
            'follow' => false,
        ]);
        $location = '';
        if (preg_match('/^location:\s*(.+)$/mi', $headers, $m)) $location = trim($m[1]);
        if ($location === '') {
            // 200/206 means this URL already serves the bytes.
            return ($status === 200 || $status === 206) ? $url : '';
        }
        $next = strpos($location, 'http') === 0 ? $location : 'https://' . BESTFILE_HOST . '/' . ltrim($location, '/');
        if (stripos($next, BESTFILE_HOST) === false) return $next;
        $url = $next;
    }
    return '';
}

// Page id of a BestFile page link, or '' when the link is not one.
function bestfile_page_id($downloadLink) {
    if (!is_string($downloadLink) || $downloadLink === '') return '';
    if (!preg_match('#https?://' . preg_quote(BESTFILE_HOST, '#') . '/([^/]+)/file#i', $downloadLink, $m)) return '';
    return $m[1];
}

/**
 * Fresh direct link for one BestFile page link, or '' when it cannot be resolved.
 * A stale page token answers 419, so one refreshed page is enough to retry.
 */
function bestfile_get_cdn_url($downloadLink) {
    $pageId = bestfile_page_id($downloadLink);
    if ($pageId === '') return '';

    $csrf = bestfile_page_token($pageId);
    if ($csrf === '') return '';
    $link = bestfile_create_link($pageId, $csrf);
    if ($link === '') {
        $csrf = bestfile_page_token($pageId, true);
        if ($csrf === '') return '';
        $link = bestfile_create_link($pageId, $csrf);
        if ($link === '') return '';
    }

    return bestfile_signed_url($link);
}

// Same answer as bestfile_get_cdn_url(); kept so callers read as a pair.
function bestfile_direct_link($downloadLink) {
    return bestfile_get_cdn_url($downloadLink);
}

/**
 * Resolve every link of a list on this request. The loop stops at the deadline
 * (this host allows 60s) so a cold, long list cannot hit the execution limit;
 * the caller then keeps the page link for whatever is left.
 */
function bestfile_get_cdn_urls(array $downloadLinks, $budgetSeconds = 50.0) {
    $out = [];
    $deadline = microtime(true) + $budgetSeconds;
    foreach (array_unique(array_map('strval', $downloadLinks)) as $link) {
        if ($link === '') continue;
        if (microtime(true) >= $deadline) break;
        $url = bestfile_get_cdn_url($link);
        if ($url !== '') $out[$link] = $url;
    }
    return $out;
}
