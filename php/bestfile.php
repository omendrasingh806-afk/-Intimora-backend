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
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^Set-Cookie:\s*(.*)$/i', $h, $m)) {
                $session = bestfile_store_cookies($session, $m[1]);
            }
        }
    }
    return [$status, $resp, $effUrl];
}

function bestfile_login() {
    $session = bestfile_load_session();
    // 1. Get login page + CSRF + cookies
    $session['cookies'] = [];
    [$st, $html] = bestfile_request('GET', '/en/login', $session);
    $csrf = bestfile_csrf_from($html);
    // 2. POST login
    [$st, $body] = bestfile_request('POST', '/en/login', $session, [
        'form' => ['_token' => $csrf, 'email' => BESTFILE_EMAIL, 'password' => BESTFILE_PASSWORD],
    ]);
    if ($st !== 302) {
        return false; // login failed (wrong email/password, or CSRF)
    }
    // 3. Refresh CSRF from an authenticated page
    [$st, $html] = bestfile_request('GET', '/en', $session);
    $session['csrf'] = bestfile_csrf_from($html);
    bestfile_save_session($session);
    return true;
}

function bestfile_ensure_login() {
    $session = bestfile_load_session();
    if (empty($session['cookies'])) {
        if (!bestfile_login()) return false;
        $session = bestfile_load_session();
    }
    // Always refresh CSRF (token expires ~2h)
    [$st, $html] = bestfile_request('GET', '/en', $session);
    $session['csrf'] = bestfile_csrf_from($html);
    bestfile_save_session($session);
    return true;
}

/**
 * Get a fresh CDN (B2) signed URL for a BestFile file.
 * Pass the download_link (page URL, e.g. https://bestfile.io/<id>/file).
 * Returns the direct Backblaze B2 URL, or false on failure.
 */
function bestfile_get_cdn_url($downloadLink) {
    // Extract the page id from the download_link: https://bestfile.io/<id>/file
    if (!preg_match('#https?://' . preg_quote(BESTFILE_HOST, '#') . '/([^/]+)/file#i', $downloadLink, $m)) return false;
    $pageId = $m[1];

    if (!bestfile_ensure_login()) return false;
    $session = bestfile_load_session();

    // POST /<id>/download/create -> returns download_link (bestfile.io redirect chain)
    [$st, $body, $eff] = bestfile_request('POST', '/' . $pageId . '/download/create', $session, [
        'headers' => ['X-CSRF-TOKEN: ' . ($session['csrf'] ?? ''), 'Referer: https://' . BESTFILE_HOST . '/en'],
    ]);
    if ($st !== 200) {
        // Session may have expired -> re-login once
        bestfile_login();
        $session = bestfile_load_session();
        [$st, $body, $eff] = bestfile_request('POST', '/' . $pageId . '/download/create', $session, [
            'headers' => ['X-CSRF-TOKEN: ' . ($session['csrf'] ?? ''), 'Referer: https://' . BESTFILE_HOST . '/en'],
        ]);
        if ($st !== 200) return false;
    }

    $data = json_decode($body, true);
    $link = $data['download_link'] ?? '';
    if (!$link) return false;

    // Follow the redirect chain to the final Backblaze B2 signed URL (fetch only 1 byte).
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
    // Fallback: stream context
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 40, 'follow_location' => 10], 'ssl' => ['verify_peer' => true]]);
    $resp = @file_get_contents($link, false, $ctx);
    if (empty($resp)) return false;
    // stream_context doesn't expose final URL reliably; return the link itself (client follows redirects)
    return $link;
}
