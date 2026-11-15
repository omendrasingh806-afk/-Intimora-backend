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
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/telegram.php';

// === CORS ===
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Range, If-Range, X-Api-Key, X-Admin-Key');
header('Access-Control-Expose-Headers: Accept-Ranges, Content-Range, Content-Length, Content-Type, ETag');
header('Access-Control-Max-Age: 86400');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// === JSON helpers ===

function json_ok($data = []) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_fail($error, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

function json_body() {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
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
    return null;
}

// Verify a Firebase ID token through the Identity Toolkit REST API — same
// endpoint the Node backend uses, so no service account key is needed.
function verify_firebase_token($token) {
    if (!$token || !defined('FIREBASE_API_KEY') || FIREBASE_API_KEY === '') {
        return null;
    }

    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => $token, 'maxResults' => 1]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
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
    json_ok([
        'php_version' => PHP_VERSION,
        'madeline_version' => class_exists('\danog\MadelineProto\API') ? 'installed' : 'NOT installed',
        'amp_version' => class_exists('\Amp\Future') ? 'installed' : 'NOT installed',
        'mtproto' => mtproto_test_connection(),
        'store' => ['videos_count' => count(store_read_videos())],
    ]);
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
    $videos = array_map('store_public_video', $metas);
    $nextCursor = $videos ? (int) $metas[count($metas) - 1]['createdAt'] : null;

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
    json_ok(['videos' => array_map('store_public_video', $metas), 'nextCursor' => null]);
}

// Video detail — counts a view
if (preg_match('#^/api/videos/([^/]+)$#', $path, $m) && $method === 'GET') {
    $videoId = urldecode($m[1]);

    $video = store_get_video($videoId);
    if (!$video) {
        json_fail('Video not found', 404);
    }

    $video = store_increment_views($videoId);
    if (!$video) {
        json_fail('Video not found', 404);
    }

    json_ok(store_public_video($video));
}

// Thumbnail
if (preg_match('#^/api/videos/([^/]+)/thumbnail$#', $path, $m) && $method === 'GET') {
    $video = store_get_video(urldecode($m[1]));
    if (!$video) {
        json_fail('Video not found', 404);
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

    try {
        $bytes = $stream['thumb_size'] > 0
            ? tg_read_file_range($thumbMeta, 0, $stream['thumb_size'] - 1)
            : tg_read_file_range($thumbMeta, 0, 262143);
    } catch (\Throwable $e) {
        json_fail('Thumbnail unavailable: ' . $e->getMessage(), 500);
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('Cross-Origin-Resource-Policy: cross-origin');
    echo $bytes;
    exit;
}

// Stream — HTTP Range support + fast-start
if (preg_match('#^/api/videos/([^/]+)/stream$#', $path, $m) && ($method === 'GET' || $method === 'HEAD')) {
    $video = store_get_video(urldecode($m[1]));
    if (!$video) {
        json_fail('Video not found', 404);
    }

    $stream = store_to_stream_format($video);
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
        try {
            $moov = tg_find_moov_atom($stream);
            if ($moov) {
                $ftypData = tg_read_file_range($stream, 0, 31);
                $totalBytes = strlen($ftypData) + strlen($moov['data']);

                http_response_code(206);
                header('Content-Type: video/mp4');
                header('Accept-Ranges: bytes');
                header('Content-Length: ' . $totalBytes);
                header('Content-Range: bytes 0-' . ($totalBytes - 1) . '/' . $fileSize);
                header('Cache-Control: public, max-age=3600');
                header('Vary: Origin, Range');
                header('Cross-Origin-Resource-Policy: cross-origin');

                echo $ftypData;
                echo $moov['data'];
                exit;
            }
        } catch (\Throwable $e) {
            // moov probe failed — fall through to normal streaming
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

    json_ok(['exists' => true, 'user' => strip_internal($user)]);
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

// Current user
if ($path === '/api/auth/me' && $method === 'GET') {
    $auth = require_auth();

    $user = store_find_user_by_uid($auth['uid']);
    if (!$user) {
        json_fail('User not found', 404);
    }

    json_ok(['user' => strip_internal($user)]);
}

// Like / unlike (updates the user's likedVideos, like the Node backend)
if (preg_match('#^/api/videos/([^/]+)/like$#', $path, $m) && $method === 'POST') {
    $auth = require_auth();

    $result = store_toggle_user_like($auth['uid'], urldecode($m[1]));
    if ($result === null) {
        json_fail('User not found', 404);
    }

    json_ok($result);
}

// Save / unsave
if (preg_match('#^/api/videos/([^/]+)/save$#', $path, $m) && $method === 'POST') {
    $auth = require_auth();

    $result = store_toggle_user_save($auth['uid'], urldecode($m[1]));
    if ($result === null) {
        json_fail('User not found', 404);
    }

    json_ok($result);
}

function json_videos_for_ids($ids) {
    $videos = [];
    foreach ((array) $ids as $id) {
        $v = store_get_video($id);
        if ($v) {
            $videos[] = strip_internal($v);
        }
    }
    return $videos;
}

// Liked videos
if ($path === '/api/user/liked' && $method === 'GET') {
    $auth = require_auth();

    $ids = store_get_liked_videos($auth['uid']);
    if ($ids === null) {
        json_fail('User not found', 404);
    }

    json_ok(['videos' => json_videos_for_ids($ids)]);
}

// Saved videos
if ($path === '/api/user/saved' && $method === 'GET') {
    $auth = require_auth();

    $ids = store_get_saved_videos($auth['uid']);
    if ($ids === null) {
        json_fail('User not found', 404);
    }

    json_ok(['videos' => json_videos_for_ids($ids)]);
}

// Referral tree (direct + level 2)
if ($path === '/api/user/referrals' && $method === 'GET') {
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
        'views' => (int) ($body['views'] ?? 0),
        'likes' => (int) ($body['likes'] ?? 0),
        'shares' => (int) ($body['shares'] ?? 0),
        'likedBy' => $body['likedBy'] ?? [],
        'duration' => (int) ($body['duration'] ?? 0),
        'createdAt' => (int) ($body['createdAt'] ?? time()),
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

    try {
        $msg = tg_upload_video(TG_CHANNEL_VIDEO, $file['tmp_name'], $title, $file['name']);
        $doc = $msg['media']['document'] ?? ($msg['document'] ?? null);

        $videoId = 'vid-' . time();
        store_add_video([
            'id' => $videoId,
            'title' => $title,
            'description' => $description,
            'exclusive' => $exclusive,
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
