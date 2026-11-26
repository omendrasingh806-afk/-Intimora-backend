<?php
// Intimora PHP Backend — Main API router
//
// Route-for-route compatible with the Node/Vercel backend (backend/server.js):
//   GET  /api/health
//   GET  /api/videos?cursor=&limit=
//   GET  /api/search?q=&limit=
//   GET  /api/videos/:id
//   GET  /api/videos/:id/stream
//   GET  /api/videos/:id/thumbnail
//   POST /api/videos/:id/share
//   POST /api/auth/verify
//   POST /api/auth/profile
//   GET  /api/auth/me
//   POST /api/videos/:id/like
//   POST /api/videos/:id/save
//   GET  /api/user/liked
//   GET  /api/user/saved
//   GET  /api/user/referrals
//   POST /api/admin/metadata            (x-api-key)
//   GET  /api/admin/videos              (x-api-key)
//   POST /api/admin/videos/:id/delete   (x-api-key)
//
// PHP-only extras (not present in the Vercel build):
//   GET  /api/debug
//   POST /api/admin/upload              (x-api-key)
//   POST /api/admin/sync                (x-api-key)
//   GET  /api/admin/export              (x-api-key)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fastcache.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/premium.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/bestfile.php';
require_once __DIR__ . '/visitor.php';

// === CORS ===
$allowed = ['https://intimora.net', 'https://intimora.tg'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
  header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type, Range, If-Range, X-Api-Key');
header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
header('Access-Control-Expose-Headers: Accept-Ranges, Content-Range, Content-Length, Content-Type, ETag');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// === One-time unlock =========================================================
// The hosting security challenge sets __test without SameSite=None, so the
// browser never sends it on cross-site requests from the frontend domain.
// Here the same cookie is re-issued as SameSite=None; Secure, which makes it
// usable from https://intimora.net (fetch with credentials: "include").
// Must stay here, before the /api path normalisation below.
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/__unlock')) {
    $back   = (string) ($_GET['back'] ?? '');
    $host   = parse_url($back, PHP_URL_HOST) ?: '';
    $scheme = parse_url($back, PHP_URL_SCHEME) ?: '';
    $okBack = $scheme === 'https' && in_array($host, ['intimora.net', 'intimora.tg'], true);

    $token = $_COOKIE['__test'] ?? '';
    if ($token !== '') {
        // Don't wait for PHP to flush the body; this is a redirect only.
        setcookie('__test', $token, [
            'expires'  => time() + 21600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'None',
        ]);
    }

    $dest = $okBack
        ? $back
        : 'https://intimora.net/?__unlocked=0';
    if ($okBack && strpos($dest, '__unlocked=') === false) {
        $dest .= (strpos($dest, '?') === false ? '?' : '&') . '__unlocked=1';
    }
    header('Location: ' . $dest, true, 302);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// === JSON helpers ===

// Routes that may be cached by the browser/CDN set a TTL here (seconds).
$PUBLIC_CACHE_TTL = 0;

// Work that runs after the response has been flushed to the client.
$AFTER_RESPONSE = null;

function after_response($fn) {
    global $AFTER_RESPONSE;
    $AFTER_RESPONSE = $fn;
}

function flush_and_detach() {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

function thumbs_payload($stream) {
    return [
        'tg_doc_id' => $stream['thumb_doc_id'],
        'tg_access_hash' => $stream['thumb_access_hash'],
        'tg_file_ref' => $stream['thumb_file_ref'],
        'tg_size' => $stream['thumb_size'],
        'tg_mime' => 'image/jpeg',
        'tg_channel' => $stream['thumb_channel'],
        'tg_msg_id' => (int) ($stream['thumb_msg_id'] ?? 0),
    ];
}

// Fill thumbnail disk caches for the first $max videos that don't have one yet.
// Runs after the client already has its response, so nobody waits for Telegram.
function warm_thumbs($metas, $max = 3) {
    $done = 0;
    $deadline = microtime(true) + 15;

    foreach ((array) $metas as $meta) {
        if ($done >= $max || microtime(true) > $deadline) {
            break;
        }
        try {
            $stream = store_to_stream_format($meta);
        } catch (\Throwable $e) {
            continue;
        }
        if (empty($stream['thumb_doc_id'])) {
            continue;
        }

        $cacheKey = 'thumb_' . $stream['thumb_doc_id'] . '_' . $stream['thumb_size'];
        if (fc_file($cacheKey, 2592000) !== null) {
            continue;
        }

        try {
            $bytes = $stream['thumb_size'] > 0
                ? tg_read_file_range(thumbs_payload($stream), 0, $stream['thumb_size'] - 1)
                : tg_read_file_range(thumbs_payload($stream), 0, 262143);
            if (is_string($bytes) && $bytes !== '') {
                fc_put($cacheKey, $bytes);
                $done++;
            }
        } catch (\Throwable $e) {
            // ignore: warming is best effort
        }
    }
}

function public_cache($seconds = 20) {
    global $PUBLIC_CACHE_TTL;
    $PUBLIC_CACHE_TTL = max(0, (int) $seconds);
}

function json_ok($data = []) {
    global $PUBLIC_CACHE_TTL, $AFTER_RESPONSE;
    header('Content-Type: application/json');
    $payload = json_encode(['ok' => true, 'data' => $data]);

    if ($PUBLIC_CACHE_TTL > 0) {
        $etag = '"' . md5($payload) . '"';
        header('Cache-Control: public, max-age=' . $PUBLIC_CACHE_TTL . ', stale-while-revalidate=' . ($PUBLIC_CACHE_TTL * 2));
        header('ETag: ' . $etag);
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            exit;
        }
    } else {
        // Data routes are never cached — not by the browser and not by a proxy.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // Compress JSON: the video list is a few KB and most visitors are on mobile
    // data, where the transfer time is a real part of the wait.
    if (strlen($payload) > 1024
        && function_exists('gzencode')
        && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
        $gz = gzencode($payload, 5);
        if (is_string($gz) && $gz !== '') {
            header('Content-Encoding: gzip');
            header('Vary: Origin, Accept-Encoding');
            $payload = $gz;
        }
    }

    echo $payload;

    $needsBackground = $AFTER_RESPONSE !== null || store_has_stale();
    if ($needsBackground) {
        $job = $AFTER_RESPONSE;
        flush_and_detach();
        ignore_user_abort(true);
        @set_time_limit(30);
        try {
            store_refresh_stale();   // stale-while-revalidate: refresh after the response
        } catch (\Throwable $e) {
            // background work must never leak an error to the client
        }
        if ($job !== null) {
            try {
                $job();
            } catch (\Throwable $e) {
                // background work must never leak an error to the client
            }
        }
    }
    exit;
}

function json_fail($error, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

// An uncaught error (Telegram/TL, BestFile, anything) must never reach the
// browser as a PHP stack trace: every API route answers JSON, and a trace is
// both unreadable to the frontend and leaks file paths. The details go to the
// error log, the client gets a short JSON 503.
set_exception_handler(function ($e) {
    error_log('[intimora] uncaught ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(['ok' => false, 'error' => 'Service temporarily unavailable']);
    }
});

function json_body() {
    // Cached: the auth token may be read out of the body (see get_bearer_token)
    // and route handlers read the same body again.
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input');
    $body = [];
    if (is_string($raw) && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $body = $data;
        }
    }
    return $body;
}

// Remove internal bookkeeping keys before sending a record to a client
function strip_internal($record) {
    if (is_array($record)) {
        unset($record['_msgId']);
    }
    return $record;
}

// === Firebase auth ===

function get_bearer_token() {
    // 1) Authorization header — works from server-to-server, Node/Vercel and
    //    curl. A browser cannot use it here: the header is not CORS-safelisted,
    //    so it triggers a preflight, and the preflight never carries the
    //    hosting __test cookie.
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($auth === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $auth = $value;
                break;
            }
        }
    }

    if (preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
        return trim($m[1]);
    }

    // 2) JSON body — the browser sends it as "Content-Type: text/plain", which
    //    is a safelisted content type, so the request stays a CORS "simple
    //    request" and no preflight is made.
    $body = json_body();
    foreach (['idToken', 'token', 'authToken', 'id_token'] as $key) {
        if (!empty($body[$key]) && is_string($body[$key])) {
            return trim($body[$key]);
        }
    }

    // 3) Query string — keeps plain GET requests preflight-free.
    foreach (['token', 'id_token', 'access_token'] as $key) {
        if (!empty($_GET[$key]) && is_string($_GET[$key])) {
            return trim($_GET[$key]);
        }
    }

    return null;
}

// Verify a Firebase ID token through the Identity Toolkit REST API — same
// endpoint the Node backend uses, so no service account key is needed.
function verify_firebase_token($token) {
    if (!$token || !defined('FIREBASE_API_KEY') || FIREBASE_API_KEY === '') {
        return null;
    }

    // A verified token never changes, and it stays valid for an hour: cache the
    // Identity Toolkit answer so authenticated routes skip the round trip.
    $cacheKey = 'fbtok_' . sha1($token);
    $cached = fc_get($cacheKey, 900);
    if (is_array($cached)) {
        return $cached;
    }

    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => $token, 'maxResults' => 1]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!is_string($resp)) {
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['error'])) {
        return null;
    }
    if (empty($data['users'][0])) {
        return null;
    }

    fc_set($cacheKey, $data['users'][0]);
    return $data['users'][0];
}

// Require a valid Firebase token; returns ['uid' => ..., 'email' => ...]
function require_auth() {
    $token = get_bearer_token();
    if (!$token) {
        json_fail('No auth token', 401);
    }

    $decoded = verify_firebase_token($token);
    if (!$decoded || empty($decoded['localId'])) {
        json_fail('Invalid token', 401);
    }

    return [
        'uid' => (string) $decoded['localId'],
        'email' => (string) ($decoded['email'] ?? ''),
    ];
}

// === Admin auth (same header as the Vercel backend: x-api-key) ===

function require_admin() {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? ($_GET['admin_key'] ?? ''));
    if ($key === '' || $key !== ADMIN_API_KEY) {
        json_fail('Unauthorized', 401);
    }
}

// === Route matching ===

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Allow the API to live in a subfolder (e.g. /php-backend/api/health)
$apiPos = strpos($path, '/api/');
if ($apiPos !== false) {
    $path = substr($path, $apiPos);
} else {
    $path = '/' . trim($path, '/');
    if (!str_starts_with($path, '/api')) {
        $path = '/api' . ($path === '/' ? '' : $path);
    }
}
$path = rtrim($path, '/') === '' ? '/api' : rtrim($path, '/');

// ============================
// PUBLIC ROUTES
// ============================

// Health check
if ($path === '/api/health' && ($method === 'GET' || $method === 'HEAD')) {
    json_ok(['status' => 'up', 'time' => (int) round(microtime(true) * 1000)]);
}

// PHP-only debug route
if ($path === '/api/debug' && $method === 'GET') {
    if (function_exists('mtproto_autoload')) {
        mtproto_autoload();
    }
    json_ok([
        'php_version' => PHP_VERSION,
        'madeline_version' => class_exists('\danog\MadelineProto\API') ? 'installed' : 'NOT installed',
        'amp_version' => class_exists('\Amp\Future') ? 'installed' : 'NOT installed',
        'mtproto' => mtproto_test_connection(),
        'store' => ['videos_count' => count(store_read_videos())],
        'visitor' => visitor_status(),
    ]);
}

// Visitor log — the frontend calls this once per session, after the age gate.
// The row is written after the response left the server, so the geo lookup and
// the MySQL insert never delay the visitor (and never break the request).
if ($path === '/api/visit' && ($method === 'POST' || $method === 'GET')) {
    $payload = $method === 'POST' ? json_body() : $_GET;
    after_response(function () use ($payload) {
        visitor_log($payload);
    });
    json_ok(['logged' => true]);
}

// Every public answer carries a BestFile *direct* link, resolved from the page
// link on this request. The signed file URL expires, so it is never reused from
// an earlier answer: each request asks BestFile for a new one. A failure keeps
// the page link rather than dropping the file.
function public_with_direct_links(array $pub, $resolved = null) {
    $direct = function ($link) use ($resolved) {
        $link = (string) $link;
        if ($link === '') return '';
        if ($resolved === null) {
            return bestfile_direct_link($link);
        }
        return (string) ($resolved[$link] ?? '');
    };

    $link = (string) ($pub['bestfile']['download_link'] ?? '');
    $url = $direct($link);
    if ($url !== '') {
        $pub['bestfile']['page_url'] = $link;
        $pub['bestfile']['download_link'] = $url;
        $pub['cdnUrl'] = $url;
    }

    $trailer = (string) ($pub['trailerBestfile'] ?? '');
    $trailerUrl = $direct($trailer);
    if ($trailerUrl !== '') {
        $pub['trailerPageUrl'] = $trailer;
        $pub['trailerBestfile'] = $trailerUrl;
    }

    // `type` says what the caller actually got: the whole file or only the
    // trailer. premium_shape_video already decided it from the account; this is
    // the fallback for a video that never went through that gate.
    if (!isset($pub['type']) || !in_array($pub['type'], ['full', 'trailer'], true)) {
        if ((string) ($pub['bestfile']['download_link'] ?? '') !== '') {
            $pub['type'] = 'full';
        } elseif ((string) ($pub['trailerBestfile'] ?? '') !== '') {
            $pub['type'] = 'trailer';
        } else {
            $pub['type'] = 'none';
        }
    }
    if (!isset($pub['bestfile']['type'])) {
        $pub['bestfile']['type'] = $pub['type'] === 'full' && (string) ($pub['bestfile']['download_link'] ?? '') !== '' ? 'full' : '';
    }

    return $pub;
}

// Resolve the BestFile page link of every video in a list. Trailers keep their
// page link here: asking for one fresh direct link per card already costs the
// host a couple of seconds per item.
function public_list_with_direct_links(array $videos) {
    $links = [];
    foreach ($videos as $v) {
        $link = (string) ($v['bestfile']['download_link'] ?? '');
        if ($link !== '') $links[] = $link;
        // A locked premium video withholds the full link, so its trailer's direct
        // link is the one the caller can actually use.
        if (!empty($v['premium'])) {
            $trailer = (string) ($v['trailerBestfile'] ?? '');
            if ($trailer !== '') $links[] = $trailer;
        }
    }
    $resolved = $links ? bestfile_get_cdn_urls($links) : [];
    return array_map(function ($v) use ($resolved) {
        return public_with_direct_links($v, $resolved);
    }, $videos);
}

// Video list — infinite scroll (?cursor= & ?limit=)
if ($path === '/api/videos' && $method === 'GET') {
    $cursor = (int) ($_GET['cursor'] ?? 0);
    $limit = (int) ($_GET['limit'] ?? 12);
    if ($limit <= 0) {
        $limit = 12;
    }
    $limit = min($limit, 50);

    $metas = store_list_videos($cursor, $limit);
    $uid = premium_uid_optional();
    $videos = public_list_with_direct_links(array_map(function ($v) use ($uid) {
        return premium_shape_video(store_public_video($v), $v, $uid);
    }, $metas));
    $nextCursor = $videos ? (int) $metas[count($metas) - 1]['createdAt'] : null;

    // An anonymous list is the same for every visitor, so the browser (and the
    // hosting proxy) may reuse it for a few seconds: repeat visits paint from
    // disk instead of waiting for PHP. Signed-in lists carry per-user premium
    // links and stay uncached.
    if ($uid === '') {
        public_cache(15);
    }

    after_response(function () use ($metas) {
        warm_thumbs($metas, 3);
    });
    json_ok(['videos' => $videos, 'nextCursor' => $nextCursor]);
}

// Search by title
if ($path === '/api/search' && $method === 'GET') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = (int) ($_GET['limit'] ?? 12);
    if ($limit <= 0) {
        $limit = 12;
    }
    $limit = min($limit, 50);

    if ($q === '') {
        json_ok(['videos' => [], 'nextCursor' => null]);
    }

    $metas = array_slice(store_search_videos($q), 0, $limit);
    $uid = premium_uid_optional();
    $videos = public_list_with_direct_links(array_map(function ($v) use ($uid) {
        return premium_shape_video(store_public_video($v), $v, $uid);
    }, $metas));
    if ($uid === '') {
        public_cache(15);
    }
    json_ok(['videos' => $videos, 'nextCursor' => null]);
}

// Video detail — counts a view
if (preg_match('#^/api/videos/([^/]+)$#', $path, $m) && $method === 'GET') {
    $videoId = urldecode($m[1]);

    $video = store_get_video($videoId);
    if (!$video) {
        json_fail('Video not found', 404);
    }

    // Count the view, but only after the response left the server: the Telegram
    // message edit behind store_increment_views takes 1.5-2 s and the client
    // has no reason to wait for it.
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['ok' => true, 'data' => public_with_direct_links(premium_shape_video(store_public_video($video), $video, premium_uid_optional(), true))]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
    ignore_user_abort(true);
    @set_time_limit(25);

    // The view is counted locally and folded into the Telegram message at most
    // once every 20 s. Editing it here would make every play wait for a round
    // trip; the list route is what refreshes the channel cache.
    try {
        store_queue_view($videoId);
        store_flush_views(1);
    } catch (\Throwable $e) {
        // a missed view count must never break the response
    }
    exit;
}

// Thumbnail
if (preg_match('#^/api/videos/([^/]+)/thumbnail$#', $path, $m) && $method === 'GET') {
    $video = store_get_video(urldecode($m[1]));
    if (!$video) {
        json_fail('Video not found', 404);
    }
    if (!empty($video['external'])) {
        // Its poster lives on the other host and is already in thumbnailUrl.
        json_fail('No thumbnail', 404);
    }

    $stream = store_to_stream_format($video);
    if (empty($stream['thumb_doc_id'])) {
        json_fail('No thumbnail', 404);
    }

    $thumbMeta = [
        'tg_doc_id' => $stream['thumb_doc_id'],
        'tg_access_hash' => $stream['thumb_access_hash'],
        'tg_file_ref' => $stream['thumb_file_ref'],
        'tg_size' => $stream['thumb_size'],
        'tg_mime' => 'image/jpeg',
        'tg_channel' => $stream['thumb_channel'],
        'tg_msg_id' => (int) ($stream['thumb_msg_id'] ?? 0),
    ];

    // Thumbnails never change once uploaded: serve them from disk after the
    // first Telegram read (that read is what made them take seconds).
    $thumbCacheKey = 'thumb_' . $stream['thumb_doc_id'] . '_' . $stream['thumb_size'];
    $thumbFile = fc_file($thumbCacheKey, 2592000);
    if ($thumbFile !== null) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=2592000, immutable');
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Content-Length: ' . filesize($thumbFile));
        readfile($thumbFile);
        exit;
    }

    try {
        $bytes = $stream['thumb_size'] > 0
            ? tg_read_file_range($thumbMeta, 0, $stream['thumb_size'] - 1)
            : tg_read_file_range($thumbMeta, 0, 262143);
    } catch (\Throwable $e) {
        json_fail('Thumbnail unavailable: ' . $e->getMessage(), 500);
    }

    if (is_string($bytes) && $bytes !== '') {
        fc_put($thumbCacheKey, $bytes);
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

// Stream — HTTP Range support + fast-start
if (preg_match('#^/api/videos/([^/]+)/stream$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    $video = store_get_video(urldecode($m[1]));
    if (!$video) {
        json_fail('Video not found', 404);
    }
    if (!empty($video['external'])) {
        // We never host a third-party file, so there is nothing to stream.
        json_fail('Video is hosted by its source', 404);
    }

    // Premium videos are never served from a bare URL: a signed token is
    // required. The link never expires; for a full video the premium end time
    // carried inside the token decides whether it may play.
    $kind = (($_GET['kind'] ?? '') === 'trailer') ? 'trailer' : 'video';
    if ($kind === 'trailer') {
        // A trailer is public: it plays logged in or logged out, with a token or
        // without one. A premium video only has to actually have a trailer.
        if (premium_video_flag($video) && !premium_has_trailer($video)) {
            json_fail('No trailer for this video', 404);
        }
    } elseif (premium_video_flag($video)) {
        // A full video is never served from a bare URL: the signed token says
        // which account asked, and the ledger decides right now whether that
        // account's premium is running.
        $payload = premium_token_check($_GET['t'] ?? '', urldecode($m[1]), $kind);
        if ($payload === null) {
            json_fail('Invalid link — reload the page', 403);
        }
        if (!premium_is_active((string) ($payload['u'] ?? ''))) {
            json_fail('Premium required', 403);
        }
    }

    // --- BestFile first: if the file has a BestFile link, redirect to its CDN ---
    $bfLink = $kind === 'trailer'
        ? (string) ($video['trailer']['bestfile']['download_link'] ?? '')
        : (string) ($video['bestfile']['download_link'] ?? '');
    if ($bfLink) {
        $cdnUrl = bestfile_get_cdn_url($bfLink);
        if ($cdnUrl) {
            http_response_code(302);
            header('Location: ' . $cdnUrl);
            // The signed URL is per-request; a cached redirect would hand out
            // an expired link, so the browser must never store it.
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            exit;
        }
        // If CDN URL fetch fails, fall through to Telegram MTProto streaming below
    }

    $stream = store_to_stream_format($video, $kind);
    $fileSize = (int) $stream['tg_size'];

    if ($fileSize <= 0) {
        json_fail('Video size unknown', 500);
    }

    $start = 0;
    $end = $fileSize - 1;
    $status = 200;

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $r)) {
        if ($r[1] !== '') {
            $start = (int) $r[1];
            $end = $r[2] !== '' ? (int) $r[2] : $fileSize - 1;
        } elseif ($r[2] !== '') {
            $suffix = (int) $r[2];
            $start = max(0, $fileSize - $suffix);
            $end = $fileSize - 1;
        }
        $end = min($end, $fileSize - 1);
        $status = 206;
    }

    if ($start >= $fileSize || $start > $end) {
        header('Content-Range: bytes */' . $fileSize);
        header('Accept-Ranges: bytes');
        json_fail('Range not satisfiable', 416);
    }

    // Fast-start: if moov is at the end of the file, send ftyp + moov first
    if ($start === 0) {
        // The ftyp+moov prefix is what the player needs first, and finding it
        // costs several megabytes of Telegram reads — cache the result.
        $moovCacheKey = 'moov_' . $stream['tg_doc_id'] . '_' . $fileSize;
        $moovChunk = fc_get($moovCacheKey, 2592000);

        if (!is_string($moovChunk) || $moovChunk === '') {
            $moovChunk = null;
            try {
                $moov = tg_find_moov_atom($stream);
                if ($moov) {
                    $ftypData = tg_read_file_range($stream, 0, 31);
                    $moovChunk = $ftypData . $moov['data'];
                    fc_set($moovCacheKey, $moovChunk);
                }
            } catch (\Throwable $e) {
                // moov probe failed — fall through to normal streaming
            }
        }

        if (is_string($moovChunk) && $moovChunk !== '') {
            $totalBytes = strlen($moovChunk);

            http_response_code(206);
            header('Content-Type: video/mp4');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . $totalBytes);
            header('Content-Range: bytes 0-' . ($totalBytes - 1) . '/' . $fileSize);
            header('Cache-Control: public, max-age=3600');
            header('Vary: Origin, Range');
            header('Cross-Origin-Resource-Policy: cross-origin');

            echo $moovChunk;
            exit;
        }
    }

    // Playback always starts near byte 0 and the first Telegram read is what
    // makes it slow, so the first 2 MB of every video is kept on disk.
    if ($start < 2097152 && $fileSize > 0) {
        $prefixKey = 'vpre_' . $stream['tg_doc_id'] . '_' . $fileSize;
        $need = min(2097152, $end + 1);
        $prefix = fc_get($prefixKey, 2592000);

        if (!is_string($prefix) || strlen($prefix) < $need) {
            try {
                $prefix = tg_read_file_range($stream, 0, (min(2097152, $fileSize) - 1));
                if (is_string($prefix) && $prefix !== '') {
                    fc_set($prefixKey, $prefix);
                } else {
                    $prefix = null;
                }
            } catch (\Throwable $e) {
                $prefix = null;
            }
        }

        if (is_string($prefix) && $need > 0 && strlen($prefix) >= $need) {
            $chunk = substr($prefix, $start, $end - $start + 1);

            http_response_code($status);
            header('Content-Type: ' . ($stream['tg_mime'] ?: 'video/mp4'));
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . strlen($chunk));
            header('Content-Disposition: inline');
            header('Cache-Control: public, max-age=3600');
            header('Vary: Origin, Range');
            header('Cross-Origin-Resource-Policy: cross-origin');
            if ($status === 206) {
                header('Content-Range: bytes ' . $start . '-' . ($start + strlen($chunk) - 1) . '/' . $fileSize);
            }
            if ($method !== 'HEAD') {
                echo $chunk;
            }
            exit;
        }
    }

    http_response_code($status);
    header('Content-Type: ' . ($stream['tg_mime'] ?: 'video/mp4'));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=3600');
    header('Vary: Origin, Range');
    header('Cross-Origin-Resource-Policy: cross-origin');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    if ($method === 'HEAD') {
        exit;
    }

    try {
        foreach (mtproto_stream_range($stream, $start, $end) as $chunk) {
            echo $chunk;
            flush();
        }
    } catch (\Throwable $e) {
        // Headers are already sent — nothing sensible left to do
    }
    exit;
}

// Share count +1
if (preg_match('#^/api/videos/([^/]+)/share$#', $path, $m) && $method === 'POST') {
    $video = store_increment_shares(urldecode($m[1]));
    if (!$video) {
        json_fail('Video not found', 404);
    }
    json_ok(['shares' => (int) ($video['shares'] ?? 0)]);
}

// ============================
// USER ROUTES (Firebase auth)
// ============================

// Verify token — tells the client whether the user already exists
if ($path === '/api/auth/verify' && $method === 'POST') {
    $auth = require_auth();

    $user = store_find_user_by_uid($auth['uid']);
    if (!$user) {
        json_ok(['exists' => false, 'email' => $auth['email'], 'uid' => $auth['uid']]);
    }

            json_ok(['exists' => true, 'user' => strip_internal(store_user_with_collections($user))]);
}

// Complete profile — new user only
if ($path === '/api/auth/profile' && $method === 'POST') {
    $auth = require_auth();
    $body = json_body();

    $name = trim((string) ($body['name'] ?? ''));
    $username = trim((string) ($body['username'] ?? ''));
    $referralCode = trim((string) ($body['referralCode'] ?? ''));
    $avatar = (string) ($body['avatar'] ?? '');

    if ($name === '' || $username === '') {
        json_fail('Name and username required', 400);
    }

    if (store_find_user_by_uid($auth['uid'])) {
        json_fail('User already exists', 400);
    }

    if (store_find_user_by_username($username)) {
        json_fail('Username already exists', 400);
    }

    $referredBy = '';
    if ($referralCode !== '') {
        $referrer = store_find_user_by_referral_code($referralCode);
        if (!$referrer) {
            json_fail('Invalid referral code', 400);
        }
        $referredBy = store_user_referral_code($referrer);
    }

    $user = store_create_user([
        'email' => $auth['email'],
        'name' => $name,
        'username' => $username,
        'uid' => $auth['uid'],
        'avatar' => $avatar,
        'referredBy' => $referredBy,
    ]);

    json_ok([
        'user' => [
            'id' => $user['uid'],
            'name' => $user['name'],
            'username' => $user['username'],
            'referralCode' => $user['referralCode'],
        ],
    ]);
}

// Current user — POST is accepted as well: a browser GET cannot carry a body,
// and every write-free auth call has to stay a CORS "simple request".
if ($path === '/api/auth/me' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();

    $user = store_find_user_by_uid($auth['uid']);
    if (!$user) {
        json_fail('User not found', 404);
    }

    json_ok(['user' => strip_internal(store_user_with_collections($user))]);
}

// Like / unlike (updates the user's likedVideos, like the Node backend)
if (preg_match('#^/api/videos/([^/]+)/like$#', $path, $m) && $method === 'POST') {
    $auth = require_auth();
    $videoId = urldecode($m[1]);

    // A third-party video (one we never uploaded) is remembered the first time
    // it is liked, so the account's liked list can show it again. The id is
    // always the one in the URL; the body only supplies its public facts.
    $body = json_body();
    if (is_array($body['video'] ?? null)) {
        store_register_external_video($videoId, $body['video']);
    }

    $result = store_toggle_user_like($auth['uid'], $videoId);
    if ($result === null) {
        json_fail('User not found', 404);
    }

    json_ok($result);
}

// Save / unsave
if (preg_match('#^/api/videos/([^/]+)/save$#', $path, $m) && $method === 'POST') {
    $auth = require_auth();
    $videoId = urldecode($m[1]);

    $body = json_body();
    if (is_array($body['video'] ?? null)) {
        store_register_external_video($videoId, $body['video']);
    }

    $result = store_toggle_user_save($auth['uid'], $videoId);
    if ($result === null) {
        json_fail('User not found', 404);
    }

    json_ok($result);
}

function json_videos_for_ids($ids) {
    $videos = [];
    $uid = premium_uid_optional();
    foreach ((array) $ids as $id) {
        $v = store_get_video($id);
        if ($v) {
            // Shaped like every other video, so a third-party entry says where
            // it is played (embedUrl/pageUrl) instead of claiming a stream.
            $videos[] = premium_shape_video(store_public_video($v), $v, $uid);
        }
    }
    return $videos;
}

// Liked videos
if ($path === '/api/user/liked' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();

    $ids = store_get_liked_videos($auth['uid']);
    if ($ids === null) {
        json_fail('User not found', 404);
    }

    json_ok(['videos' => json_videos_for_ids($ids)]);
}

// Saved videos
if ($path === '/api/user/saved' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();

    $ids = store_get_saved_videos($auth['uid']);
    if ($ids === null) {
        json_fail('User not found', 404);
    }

    json_ok(['videos' => json_videos_for_ids($ids)]);
}

// Referral tree (direct + level 2)
if ($path === '/api/user/referrals' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();

    $tree = store_get_referral_tree($auth['uid']);
    if ($tree === null) {
        json_fail('User not found', 404);
    }

    json_ok(['tree' => $tree]);
}

// ============================
// ADMIN ROUTES (x-api-key)
// ============================

// Store metadata that the browser-side admin panel uploaded to Telegram itself
// ============================
// PREMIUM ROUTES (referral based)
// ============================

if ($path === '/api/premium/status' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();
    json_ok(premium_status($auth['uid']));
}

if ($path === '/api/premium/history' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();
    $status = premium_status($auth['uid']);
    $history = premium_history($auth['uid']);
    $history['status'] = $status;
    json_ok($history);
}

// Spend referrals: {count: 1} → 2.5h, 2 → 5h, 4 → 10h
if ($path === '/api/premium/redeem' && $method === 'POST') {
    $auth = require_auth();
    $body = json_body();
    $result = premium_redeem_referrals($auth['uid'], (int) ($body['count'] ?? 1));
    if (empty($result['ok'])) {
        json_fail($result['error'] ?? 'Could not activate premium', $result['code'] ?? 400);
    }
    json_ok(['status' => $result['status'], 'entry' => $result['entry']]);
}

// Exchange the Firebase token for a short-lived signed viewer token. The video
// reads accept it as `?viewer=`, which avoids the CORS preflight this host's
// browser check would answer with HTML.
if ($path === '/api/premium/viewer' && ($method === 'GET' || $method === 'POST')) {
    $auth = require_auth();
    json_ok(premium_viewer_token($auth['uid']));
}

// Admin: grant premium by hand (payment settled offline, or a correction)
if ($path === '/api/admin/premium/grant' && $method === 'POST') {
    require_admin();
    $body = json_body();
    $result = premium_grant(
        (string) ($body['uid'] ?? ''),
        (float) ($body['hours'] ?? 0),
        (string) ($body['source'] ?? 'admin'),
        (int) ($body['amountPaise'] ?? 0),
        (string) ($body['note'] ?? '')
    );
    if (empty($result['ok'])) {
        json_fail($result['error'] ?? 'Could not grant premium');
    }
    json_ok($result);
}

// Admin: one user's premium state + full history
if ($path === '/api/admin/premium' && $method === 'GET') {
    require_admin();
    $uid = (string) ($_GET['uid'] ?? '');
    if ($uid === '') {
        json_fail('uid required');
    }
    json_ok(['status' => premium_status($uid), 'history' => premium_history($uid)]);
}

// Admin: every account with the premium time it still has. Server clock and
// end timestamp travel with each row so the panel can count down live.
if ($path === '/api/admin/users' && ($method === 'GET' || $method === 'POST')) {
    require_admin();
    $now = time();
    $q = strtolower(trim((string) ($_GET['q'] ?? '')));
    $users = store_read_users();
    $out = [];
    foreach ($users as $u) {
        $uid = store_user_uid($u);
        if ($uid === '') {
            continue;
        }
        $hay = strtolower((string) ($u['name'] ?? '') . ' ' . (string) ($u['username'] ?? '') . ' ' . (string) ($u['email'] ?? '') . ' ' . $uid);
        if ($q !== '' && strpos($hay, $q) === false) {
            continue;
        }
        $until = (int) premium_until($uid);
        $out[] = [
            'uid' => $uid,
            'name' => (string) ($u['name'] ?? ''),
            'username' => (string) ($u['username'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'referralCode' => (string) ($u['referralCode'] ?? ($u['referral_code'] ?? '')),
            'referredBy' => (string) ($u['referredBy'] ?? ($u['referred_by'] ?? '')),
            'createdAt' => (int) ($u['createdAt'] ?? ($u['at'] ?? 0)),
            'premiumUntil' => $until,
            'premiumActive' => $until > $now,
            'remainingSeconds' => $until > $now ? ($until - $now) : 0,
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['premiumActive'] !== $b['premiumActive']) {
            return $a['premiumActive'] ? -1 : 1;
        }
        return $b['premiumUntil'] <=> $a['premiumUntil'];
    });
    json_ok(['users' => $out, 'serverNow' => $now, 'total' => count($users)]);
}

if ($path === '/api/admin/metadata' && $method === 'POST') {
    require_admin();

    $body = json_body();
    if (empty($body['id']) || empty($body['video'])) {
        json_fail('Missing required fields (id, video)');
    }

    $video = $body['video'];
    $thumb = $body['thumbnail'] ?? null;

    $normalized = [
        't' => 'meta',
        'id' => $body['id'],
        'title' => $body['title'] ?? 'Untitled',
        'description' => mb_substr((string) ($body['description'] ?? ''), 0, 1000),
        'exclusive' => (($body['exclusive'] ?? false) === true || ($body['exclusive'] ?? '') === 'true'),
        'premium' => (($body['premium'] ?? false) === true || ($body['premium'] ?? '') === 'true'),
        'views' => (int) ($body['views'] ?? 0),
        'likes' => (int) ($body['likes'] ?? 0),
        'shares' => (int) ($body['shares'] ?? 0),
        'likedBy' => $body['likedBy'] ?? [],
        'duration' => (int) ($body['duration'] ?? 0),
        'createdAt' => (int) ($body['createdAt'] ?? time()),
        'trailer' => store_build_trailer($body['trailer'] ?? null),
        'video' => [
            'docId' => (string) store_field($video, 'docId', 'tg_doc_id', ''),
            'accessHash' => (string) store_field($video, 'accessHash', 'tg_access_hash', ''),
            'fileRef' => (string) store_field($video, 'fileRef', 'tg_file_ref', ''),
            'size' => (int) store_field($video, 'size', 'tg_size', 0),
            'mime' => (string) store_field($video, 'mime', 'tg_mime', 'video/mp4'),
            'dcId' => (int) store_field($video, 'dcId', 'tg_dc_id', 0),
            'msgId' => (int) store_field($video, 'msgId', 'tg_msg_id', 0),
            'filename' => (string) store_field($video, 'filename', 'tg_filename', 'video.mp4'),
        ],
        'thumbnail' => $thumb ? [
            'docId' => (string) store_field($thumb, 'docId', 'thumb_doc_id', ''),
            'accessHash' => (string) store_field($thumb, 'accessHash', 'thumb_access_hash', ''),
            'fileRef' => (string) store_field($thumb, 'fileRef', 'thumb_file_ref', ''),
            'size' => (int) store_field($thumb, 'size', 'thumb_size', 0),
            'msgId' => store_field($thumb, 'msgId', 'thumb_msg_id', null),
        ] : null,
    ];

    try {
        $metaMsgId = store_send_metadata($normalized);
    } catch (\Throwable $e) {
        json_fail('Failed to save metadata: ' . $e->getMessage(), 500);
    }

    json_ok(['id' => $normalized['id'], 'meta_msg_id' => $metaMsgId]);
}

// Admin: all videos
if ($path === '/api/admin/videos' && $method === 'GET') {
    require_admin();

    $metas = store_list_all_videos();
    json_ok(array_map('store_public_video', $metas));
}

// Admin: delete a video (metadata + video + thumbnail messages)
if (preg_match('#^/api/admin/videos/([^/]+)/delete$#', $path, $m) && $method === 'POST') {
    require_admin();

    $result = store_delete_video(urldecode($m[1]));
    if (empty($result['deleted'])) {
        json_fail('Video not found', 404);
    }

    json_ok(['deleted' => true]);
}

// Admin: export every stored record (PHP-only helper)
if ($path === '/api/admin/export' && $method === 'GET') {
    require_admin();
    json_ok(store_read_videos());
}

// Admin: upload a file straight from the PHP server (PHP-only helper)
if ($path === '/api/admin/upload' && $method === 'POST') {
    require_admin();

    if (empty($_FILES['video'])) {
        json_fail('No video file provided');
    }

    $file = $_FILES['video'];
    $title = $_POST['title'] ?? 'Untitled';
    $description = $_POST['description'] ?? '';
    $exclusive = (($_POST['exclusive'] ?? '') === 'true');
    $premium = (($_POST['premium'] ?? '') === 'true');

    // Trailer: uploaded to the same Telegram video channel, stored inside the
    // same metadata message as the video (PHP has no BestFile uploader, so the
    // BestFile trailer copy is only made from the local admin panel).
    $trailer = null;
    if (!empty($_FILES['trailer']) && ($_FILES['trailer']['error'] ?? 1) === 0) {
        try {
            $tmsg = tg_upload_video(TG_CHANNEL_VIDEO, $_FILES['trailer']['tmp_name'], $title . ' (trailer)', $_FILES['trailer']['name']);
            $tdoc = $tmsg['media']['document'] ?? ($tmsg['document'] ?? null);
            $trailer = [
                'docId' => (string) ($tdoc['id'] ?? ''),
                'accessHash' => (string) ($tdoc['access_hash'] ?? ''),
                'fileRef' => base64_encode($tdoc['file_reference'] ?? ''),
                'size' => (int) ($tdoc['size'] ?? 0),
                'mime' => $tdoc['mime_type'] ?? 'video/mp4',
                'msgId' => (int) ($tmsg['id'] ?? 0),
                'filename' => $_FILES['trailer']['name'],
            ];
        } catch (\Throwable $e) {
            json_fail('Trailer upload failed: ' . $e->getMessage(), 500);
        }
        @unlink($_FILES['trailer']['tmp_name']);
    }

    try {
        $msg = tg_upload_video(TG_CHANNEL_VIDEO, $file['tmp_name'], $title, $file['name']);
        $doc = $msg['media']['document'] ?? ($msg['document'] ?? null);

        $videoId = 'vid-' . time();
        store_add_video([
            'id' => $videoId,
            'title' => $title,
            'description' => $description,
            'exclusive' => $exclusive,
            'premium' => $premium,
            'video' => [
                'docId' => (string) ($doc['id'] ?? ''),
                'accessHash' => (string) ($doc['access_hash'] ?? ''),
                'fileRef' => base64_encode($doc['file_reference'] ?? ''),
                'size' => (int) ($doc['size'] ?? 0),
                'mime' => $doc['mime_type'] ?? 'video/mp4',
                'dcId' => (int) ($doc['dc_id'] ?? 0),
                'msgId' => (int) ($msg['id'] ?? 0),
                'filename' => $file['name'],
            ],
            'thumbnail' => null,
            'trailer' => $trailer,
        ]);
    } catch (\Throwable $e) {
        json_fail('Upload to Telegram failed: ' . $e->getMessage(), 500);
    }

    if (file_exists($file['tmp_name'])) {
        @unlink($file['tmp_name']);
    }

    json_ok(['id' => $videoId, 'title' => $title]);
}

// Admin: import videos from the Telegram channel (PHP-only helper)
if ($path === '/api/admin/sync' && $method === 'POST') {
    require_admin();

    try {
        $connection = mtproto_test_connection();
        if (empty($connection['ok'])) {
            json_fail('Telegram connection failed: ' . ($connection['error'] ?? 'unknown'), 500);
        }

        $added = 0;
        $totalFetched = 0;
        $offsetId = 0;

        for ($page = 0; $page < 50; $page++) {
            $messages = tg_get_history(TG_CHANNEL_VIDEO, 200, $offsetId);
            if (empty($messages)) {
                break;
            }

            $totalFetched += count($messages);

            foreach ($messages as $msg) {
                $doc = $msg['media']['document'] ?? null;
                if (!$doc || !str_starts_with($doc['mime_type'] ?? '', 'video/')) {
                    continue;
                }

                $msgId = (int) $msg['id'];
                $videoId = 'vid-' . $msgId;
                if (store_get_video($videoId)) {
                    continue;
                }

                $fileName = 'video.mp4';
                foreach ($doc['attributes'] ?? [] as $attr) {
                    if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                        $fileName = $attr['file_name'];
                        break;
                    }
                }

                store_add_video([
                    'id' => $videoId,
                    'title' => $msg['message'] ?: $fileName,
                    'description' => '',
                    'exclusive' => false,
                    'premium' => false,
                    'video' => [
                        'docId' => (string) $doc['id'],
                        'accessHash' => (string) $doc['access_hash'],
                        'fileRef' => base64_encode($doc['file_reference'] ?? ''),
                        'size' => (int) $doc['size'],
                        'mime' => $doc['mime_type'],
                        'dcId' => (int) ($doc['dc_id'] ?? 0),
                        'msgId' => $msgId,
                        'filename' => $fileName,
                    ],
                    'thumbnail' => null,
                ]);

                $added++;
            }

            $oldest = $messages[count($messages) - 1];
            $offsetId = (int) $oldest['id'];

            if (count($messages) < 200) {
                break;
            }
        }

        json_ok(['added' => $added, 'total' => $totalFetched]);
    } catch (\Throwable $e) {
        json_fail('Sync error: ' . $e->getMessage(), 500);
    }
}

json_fail('Not found', 404);
