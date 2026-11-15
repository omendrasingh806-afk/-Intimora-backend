<?php
// Intimora PHP Backend — Telegram storage layer
//
// Mirrors backend/store.js + backend/users.js from the Node/Vercel backend, so
// both backends read and write the exact same Telegram channels in the same
// JSON format:
//
//   Videos  → TG_CHANNEL_META      {"t":"meta", id, title, ..., video:{docId,...}, thumbnail:{...}}
//   Users   → TG_CHANNEL_USERS     {"t":"user", uid, email, name, username, referralCode, ...}
//   Refs    → TG_CHANNEL_REFERRALS {"t":"ref", ownerCode, referredCode, timestamp}
//
// Older PHP-written records (referral_code / tg_doc_id / thumb_*) are still
// read correctly, so no existing data is lost.

// Everything is declared inside this guard so the file can be included twice
// (plain require after require_once) without "Cannot redeclare function" errors.
if (!defined('INTIMORA_STORE')) {
define('INTIMORA_STORE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

// === In-Memory Cache (one request lifetime) ===
$_storeCache = [
    'videos' => ['data' => null, 'time' => 0],
    'users' => ['data' => null, 'time' => 0],
    'referrals' => ['data' => null, 'time' => 0],
];

function store_cache($type, $data) {
    global $_storeCache;
    if (!isset($_storeCache[$type])) {
        $_storeCache[$type] = ['data' => null, 'time' => 0];
    }
    $_storeCache[$type]['data'] = $data;
    $_storeCache[$type]['time'] = time();
}

function store_get_cache($type) {
    global $_storeCache;
    if (!isset($_storeCache[$type]['data']) || $_storeCache[$type]['data'] === null) {
        return null;
    }
    if ((time() - $_storeCache[$type]['time']) >= 60) {
        return null;
    }
    return $_storeCache[$type]['data'];
}

function store_clear_cache($type = null) {
    global $_storeCache;
    if ($type) {
        if (isset($_storeCache[$type])) {
            $_storeCache[$type]['data'] = null;
            $_storeCache[$type]['time'] = 0;
        }
    } else {
        foreach (array_keys($_storeCache) as $key) {
            $_storeCache[$key]['data'] = null;
            $_storeCache[$key]['time'] = 0;
        }
    }
}

// === Field helpers ===
// Records created by the Node backend use camelCase keys, records created by
// older PHP versions used snake_case. Both are accepted everywhere.

function store_field($arr, $camel, $snake, $default = null) {
    if (is_array($arr)) {
        if (array_key_exists($camel, $arr) && $arr[$camel] !== null && $arr[$camel] !== '') {
            return $arr[$camel];
        }
        if (array_key_exists($snake, $arr) && $arr[$snake] !== null && $arr[$snake] !== '') {
            return $arr[$snake];
        }
    }
    return $default;
}

// Convert a stored metadata record into the flat tg_* format the MTProto
// download helpers (mtproto_media) expect.
function store_to_stream_format($m) {
    $video = $m['video'] ?? [];
    $thumb = $m['thumbnail'] ?? [];

    return [
        'tg_doc_id' => (string) store_field($video, 'docId', 'tg_doc_id', ''),
        'tg_access_hash' => (string) store_field($video, 'accessHash', 'tg_access_hash', ''),
        'tg_file_ref' => (string) store_field($video, 'fileRef', 'tg_file_ref', ''),
        'tg_size' => (int) store_field($video, 'size', 'tg_size', 0),
        'tg_mime' => (string) store_field($video, 'mime', 'tg_mime', 'video/mp4'),
        'tg_dc_id' => (int) store_field($video, 'dcId', 'tg_dc_id', 0),
        'tg_msg_id' => (int) store_field($video, 'msgId', 'tg_msg_id', 0),
        'tg_filename' => (string) store_field($video, 'filename', 'tg_filename', 'video.mp4'),
        'tg_channel' => $m['video']['channel'] ?? ($m['tg_channel'] ?? TG_CHANNEL_VIDEO),
        'thumb_doc_id' => (string) store_field($thumb, 'docId', 'thumb_doc_id', ''),
        'thumb_access_hash' => (string) store_field($thumb, 'accessHash', 'thumb_access_hash', ''),
        'thumb_file_ref' => (string) store_field($thumb, 'fileRef', 'thumb_file_ref', ''),
        'thumb_size' => (int) store_field($thumb, 'size', 'thumb_size', 0),
        'thumb_msg_id' => store_field($thumb, 'msgId', 'thumb_msg_id', null),
        'thumb_channel' => $m['thumbnail']['channel'] ?? TG_CHANNEL_THUMB,
    ];
}

// Public video JSON — identical to publicVideo() in backend/server.js
function store_public_video($v) {
    $video = $v['video'] ?? [];
    $thumb = $v['thumbnail'] ?? [];

    return [
        'id' => $v['id'] ?? '',
        'title' => $v['title'] ?? '',
        'description' => $v['description'] ?? '',
        'exclusive' => (($v['exclusive'] ?? false) === true || ($v['exclusive'] ?? '') === 'true'),
        'size' => isset($video['size']) ? (int) $video['size'] : (isset($video['tg_size']) ? (int) $video['tg_size'] : null),
        'mime' => store_field($video, 'mime', 'tg_mime', 'video/mp4'),
        'thumbSize' => isset($thumb['size']) ? (int) $thumb['size'] : (isset($thumb['thumb_size']) ? (int) $thumb['thumb_size'] : 0),
        'duration' => (int) ($v['duration'] ?? 0),
        'views' => (int) ($v['views'] ?? 0),
        'likes' => (int) ($v['likes'] ?? 0),
        'shares' => (int) ($v['shares'] ?? 0),
        'createdAt' => (int) ($v['createdAt'] ?? 0),
        'streamUrl' => '/api/videos/' . ($v['id'] ?? '') . '/stream',
        'thumbnailUrl' => '/api/videos/' . ($v['id'] ?? '') . '/thumbnail',
    ];
}

// === Channel reading ===

// Read messages of a channel, newest first (paginated MTProto history)
function store_read_messages($channel, $limit = 5000) {
    $all = [];
    $offsetId = 0;
    $pageSize = 200;

    while (count($all) < $limit) {
        $want = min($pageSize, $limit - count($all));
        $messages = tg_get_history($channel, $want, $offsetId);
        if (empty($messages)) {
            break;
        }

        $all = array_merge($all, $messages);

        $oldest = $messages[count($messages) - 1];
        $newest = $messages[0];
        $next = (int) ($oldest['id'] ?? 0);
        if ($next <= 0 || $next === $offsetId) {
            break;
        }
        $offsetId = $next;

        if (count($messages) < $want) {
            break;
        }
    }

    return $all;
}

// Legacy name kept for existing callers
function store_get_channel_messages($channel, $maxPages = 50) {
    return store_read_messages($channel, $maxPages * 200);
}

// Parse one message into a metadata array (text JSON, or .json document)
function store_parse_meta_message($msg) {
    $text = $msg['message'] ?? '';
    $data = null;

    if (is_string($text) && $text !== '') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // Metadata stored as a .json file upload (older admin flow)
    if ($data === null) {
        $doc = $msg['media']['document'] ?? null;
        if ($doc) {
            $fileName = '';
            foreach ($doc['attributes'] ?? [] as $attr) {
                if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                    $fileName = $attr['file_name'] ?? '';
                    break;
                }
            }
            if (str_ends_with(strtolower($fileName), '.json') && (int) ($doc['size'] ?? 0) < 262144) {
                try {
                    $raw = tg_read_file_range([
                        'tg_doc_id' => (string) $doc['id'],
                        'tg_access_hash' => (string) $doc['access_hash'],
                        'tg_file_ref' => base64_encode($doc['file_reference'] ?? ''),
                        'tg_size' => (int) $doc['size'],
                        'tg_mime' => $doc['mime_type'] ?? 'application/json',
                        'tg_dc_id' => (int) ($doc['dc_id'] ?? 0),
                        'tg_channel' => TG_CHANNEL_META,
                        'tg_msg_id' => (int) $msg['id'],
                        'tg_filename' => $fileName,
                    ], 0, (int) $doc['size'] - 1);
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                } catch (\Throwable $e) {
                    // unreadable JSON file — skip
                }
            }
        }
    }

    if (!is_array($data) || ($data['t'] ?? '') !== 'meta') {
        return null;
    }

    $data['_msgId'] = (int) $msg['id'];
    return $data;
}

// All video metadata, newest first
function store_read_videos() {
    $cached = store_get_cache('videos');
    if ($cached !== null) {
        return $cached;
    }

    $videos = [];
    foreach (store_read_messages(TG_CHANNEL_META) as $msg) {
        $parsed = store_parse_meta_message($msg);
        if ($parsed !== null) {
            $videos[] = $parsed;
        }
    }

    usort($videos, function ($a, $b) {
        return ((int) ($b['createdAt'] ?? 0)) <=> ((int) ($a['createdAt'] ?? 0));
    });

    store_cache('videos', $videos);
    return $videos;
}

// Legacy name kept for existing callers
function store_get_videos() {
    return store_read_videos();
}

// Infinite scroll — cursor is the createdAt of the last item of the previous page
function store_list_videos($cursor = 0, $limit = 12) {
    $metas = store_read_videos();

    if ($cursor) {
        $start = 0;
        foreach ($metas as $i => $m) {
            if (((int) ($m['createdAt'] ?? 0)) <= (int) $cursor) {
                $start = $i;
                break;
            }
        }
        return array_slice($metas, $start, $limit);
    }

    return array_slice($metas, 0, $limit);
}

function store_list_all_videos() {
    return store_read_videos();
}

function store_search_videos($query) {
    $q = mb_strtolower(trim((string) $query));
    if ($q === '') {
        return [];
    }
    $matches = [];
    foreach (store_read_videos() as $m) {
        if (mb_strpos(mb_strtolower((string) ($m['title'] ?? '')), $q) !== false) {
            $matches[] = $m;
        }
    }
    return $matches;
}

function store_get_video($videoId) {
    foreach (store_read_videos() as $v) {
        if ((string) ($v['id'] ?? '') === (string) $videoId) {
            return $v;
        }
    }
    return null;
}

// === Video writes ===

// Same object shape as buildMetadata() in backend/telegram.js
function store_build_metadata($v) {
    $video = $v['video'] ?? [];
    $thumb = $v['thumbnail'] ?? null;

    return [
        't' => 'meta',
        'id' => $v['id'],
        'title' => $v['title'] ?? 'Untitled',
        'description' => mb_substr((string) ($v['description'] ?? ''), 0, 1000),
        'exclusive' => (($v['exclusive'] ?? false) === true || ($v['exclusive'] ?? '') === 'true'),
        'views' => (int) ($v['views'] ?? 0),
        'likes' => (int) ($v['likes'] ?? 0),
        'shares' => (int) ($v['shares'] ?? 0),
        'likedBy' => $v['likedBy'] ?? [],
        'duration' => (int) ($v['duration'] ?? 0),
        'createdAt' => (int) ($v['created_at'] ?? $v['createdAt'] ?? time()),
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
        'thumbnail' => ($thumb !== null && store_field($thumb, 'docId', 'thumb_doc_id', '') !== '') ? [
            'docId' => (string) store_field($thumb, 'docId', 'thumb_doc_id', ''),
            'accessHash' => (string) store_field($thumb, 'accessHash', 'thumb_access_hash', ''),
            'fileRef' => (string) store_field($thumb, 'fileRef', 'thumb_file_ref', ''),
            'size' => (int) store_field($thumb, 'size', 'thumb_size', 0),
            'msgId' => store_field($thumb, 'msgId', 'thumb_msg_id', null),
        ] : null,
    ];
}

// Send a metadata JSON message to the meta channel, returns the message id
function store_send_metadata($metadata) {
    $result = tg_send_message(TG_CHANNEL_META, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    store_clear_cache('videos');
    return tg_msg_id($result);
}

// Edit an existing metadata message
function store_edit_metadata($msgId, $metadata) {
    tg_edit_message(TG_CHANNEL_META, (int) $msgId, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    store_clear_cache('videos');
}

// Add a new video; returns the stored metadata (with _msgId)
function store_add_video($videoData) {
    $metadata = store_build_metadata($videoData);
    $msgId = store_send_metadata($metadata);
    $metadata['_msgId'] = $msgId;
    return $metadata;
}

// Patch a single entry in the (possibly warm) cache after a write
function store_cache_patch_video($videoId, $fresh) {
    global $_storeCache;
    if (!isset($_storeCache['videos']['data']) || !is_array($_storeCache['videos']['data'])) {
        return;
    }
    foreach ($_storeCache['videos']['data'] as $i => $v) {
        if ((string) ($v['id'] ?? '') === (string) $videoId) {
            $_storeCache['videos']['data'][$i] = $fresh;
            $_storeCache['videos']['time'] = time();
            return;
        }
    }
}

// Merge $updates into a video's metadata message (edit in place)
function store_update_video_stats($videoId, $updates) {
    $msg = store_get_video($videoId);
    if (!$msg) {
        return null;
    }

    $data = array_merge($msg, $updates);
    unset($data['_msgId']);

    store_edit_metadata($msg['_msgId'], $data);
    $data['_msgId'] = $msg['_msgId'];
    store_cache_patch_video($videoId, $data);

    return $data;
}

function store_increment_views($videoId) {
    $v = store_get_video($videoId);
    if (!$v) {
        return null;
    }
    return store_update_video_stats($videoId, ['views' => (int) ($v['views'] ?? 0) + 1]);
}

function store_toggle_video_like($videoId, $userId) {
    $v = store_get_video($videoId);
    if (!$v) {
        return null;
    }

    $likedBy = $v['likedBy'] ?? [];
    if (in_array($userId, $likedBy, true)) {
        $likedBy = array_values(array_filter($likedBy, function ($u) use ($userId) {
            return $u !== $userId;
        }));
        $likes = max(0, (int) ($v['likes'] ?? 0) - 1);
        $liked = false;
    } else {
        $likedBy[] = $userId;
        $likes = (int) ($v['likes'] ?? 0) + 1;
        $liked = true;
    }

    $result = store_update_video_stats($videoId, ['likedBy' => $likedBy, 'likes' => $likes]);
    if (!$result) {
        return null;
    }

    return ['liked' => $liked, 'likes' => (int) ($result['likes'] ?? 0)];
}

// Legacy helper used by older code
function store_toggle_like($videoId, $userId) {
    $res = store_toggle_video_like($videoId, $userId);
    return $res === null ? null : $res['liked'];
}

function store_toggle_save($videoId, $userId) {
    $v = store_get_video($videoId);
    if (!$v) {
        return null;
    }

    $savedBy = $v['savedBy'] ?? [];
    if (in_array($userId, $savedBy, true)) {
        $savedBy = array_values(array_filter($savedBy, function ($u) use ($userId) {
            return $u !== $userId;
        }));
        $saved = false;
    } else {
        $savedBy[] = $userId;
        $saved = true;
    }

    store_update_video_stats($videoId, ['savedBy' => $savedBy]);
    return $saved;
}

function store_increment_shares($videoId) {
    $v = store_get_video($videoId);
    if (!$v) {
        return null;
    }
    return store_update_video_stats($videoId, ['shares' => (int) ($v['shares'] ?? 0) + 1]);
}

// Delete the video message, its thumbnail and the metadata message
function store_delete_video($videoId) {
    $msg = store_get_video($videoId);
    if (!$msg) {
        return ['deleted' => false, 'channels' => []];
    }

    $stream = store_to_stream_format($msg);
    $channels = [];

    try {
        tg_delete_messages(TG_CHANNEL_META, [(int) $msg['_msgId']]);
        $channels['meta'] = true;
    } catch (\Throwable $e) {
        $channels['meta'] = false;
    }

    if (!empty($stream['tg_msg_id'])) {
        try {
            tg_delete_messages(TG_CHANNEL_VIDEO, [$stream['tg_msg_id']]);
            $channels['video'] = true;
        } catch (\Throwable $e) {
            $channels['video'] = false;
        }
    }

    if (!empty($stream['thumb_msg_id'])) {
        try {
            tg_delete_messages(TG_CHANNEL_THUMB, [(int) $stream['thumb_msg_id']]);
            $channels['thumb'] = true;
        } catch (\Throwable $e) {
            $channels['thumb'] = false;
        }
    }

    store_clear_cache('videos');
    return ['deleted' => true, 'channels' => $channels];
}

// === Users ===

function store_read_users() {
    $cached = store_get_cache('users');
    if ($cached !== null) {
        return $cached;
    }

    $users = [];
    foreach (store_read_messages(TG_CHANNEL_USERS) as $msg) {
        $text = $msg['message'] ?? '';
        if (!is_string($text) || $text === '') {
            continue;
        }
        $data = json_decode($text, true);
        if (!is_array($data) || ($data['t'] ?? '') !== 'user') {
            continue;
        }
        $data['_msgId'] = (int) $msg['id'];
        $users[] = $data;
    }

    store_cache('users', $users);
    return $users;
}

function store_get_users() {
    return store_read_users();
}

function store_user_uid($u) {
    return (string) ($u['uid'] ?? $u['id'] ?? '');
}

function store_user_referral_code($u) {
    return (string) store_field($u, 'referralCode', 'referral_code', '');
}

function store_find_user_by_uid($uid) {
    foreach (store_read_users() as $u) {
        if (store_user_uid($u) === (string) $uid) {
            return $u;
        }
    }
    return null;
}

function store_get_user($userId) {
    return store_find_user_by_uid($userId);
}

function store_find_user_by_email($email) {
    foreach (store_read_users() as $u) {
        if ((string) ($u['email'] ?? '') === (string) $email) {
            return $u;
        }
    }
    return null;
}

function store_find_user_by_username($username) {
    foreach (store_read_users() as $u) {
        if (strcasecmp((string) ($u['username'] ?? ''), (string) $username) === 0) {
            return $u;
        }
    }
    return null;
}

function store_find_user_by_referral_code($code) {
    foreach (store_read_users() as $u) {
        if (store_user_referral_code($u) === (string) $code) {
            return $u;
        }
    }
    return null;
}

function store_generate_referral_code() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'REF-' . $code;
}

// Create a user in the users channel. Same record shape as users.createUser().
function store_create_user($params) {
    $user = [
        't' => 'user',
        'email' => $params['email'] ?? '',
        'name' => $params['name'] ?? '',
        'username' => $params['username'] ?? '',
        'uid' => $params['uid'] ?? '',
        'referralCode' => $params['referralCode'] ?? store_generate_referral_code(),
        'referredBy' => $params['referredBy'] ?? '',
        'likedVideos' => [],
        'savedVideos' => [],
        'avatar' => $params['avatar'] ?? '',
        'createdAt' => time(),
    ];

    $result = tg_send_message(TG_CHANNEL_USERS, json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $user['_msgId'] = tg_msg_id($result);

    if (!empty($user['referredBy'])) {
        store_add_referral($user['referredBy'], $user['referralCode']);
    }

    store_clear_cache('users');
    return $user;
}

// Legacy helper (old index.php) — maps to createUser
function store_add_user($userData) {
    return store_create_user([
        'email' => $userData['email'] ?? '',
        'name' => $userData['name'] ?? '',
        'username' => $userData['username'] ?? '',
        'uid' => $userData['uid'] ?? ($userData['id'] ?? ''),
        'avatar' => $userData['avatar'] ?? '',
        'referredBy' => $userData['referredBy'] ?? '',
    ]);
}

// Update a user's record (edit the existing message)
function store_update_user($uid, $updates) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }

    $msgId = $user['_msgId'] ?? null;
    $data = array_merge($user, $updates);
    unset($data['_msgId']);

    try {
        tg_edit_message(TG_CHANNEL_USERS, (int) $msgId, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        store_clear_cache('users');
    } catch (\Throwable $e) {
        return null;
    }

    $data['_msgId'] = $msgId;
    return $data;
}

function store_toggle_user_like($uid, $videoId) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }

    $liked = $user['likedVideos'] ?? [];
    $idx = array_search($videoId, $liked, true);
    if ($idx !== false) {
        array_splice($liked, $idx, 1);
    } else {
        $liked[] = $videoId;
    }

    store_update_user($uid, ['likedVideos' => array_values($liked)]);
    return ['liked' => $idx === false, 'likedVideos' => array_values($liked)];
}

function store_toggle_user_save($uid, $videoId) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }

    $saved = $user['savedVideos'] ?? [];
    $idx = array_search($videoId, $saved, true);
    if ($idx !== false) {
        array_splice($saved, $idx, 1);
    } else {
        $saved[] = $videoId;
    }

    store_update_user($uid, ['savedVideos' => array_values($saved)]);
    return ['saved' => $idx === false, 'savedVideos' => array_values($saved)];
}

function store_get_liked_videos($uid) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }
    return $user['likedVideos'] ?? [];
}

function store_get_saved_videos($uid) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }
    return $user['savedVideos'] ?? [];
}

// === Referrals ===

function store_read_referrals() {
    $cached = store_get_cache('referrals');
    if ($cached !== null) {
        return $cached;
    }

    $referrals = [];
    foreach (store_read_messages(TG_CHANNEL_REFERRALS) as $msg) {
        $text = $msg['message'] ?? '';
        if (!is_string($text) || $text === '') {
            continue;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            continue;
        }
        // Node writes {"t":"ref"}, older PHP wrote {"t":"referral"}
        if (($data['t'] ?? '') !== 'ref' && ($data['t'] ?? '') !== 'referral') {
            continue;
        }
        $referrals[] = $data;
    }

    store_cache('referrals', $referrals);
    return $referrals;
}

function store_get_referrals() {
    return store_read_referrals();
}

function store_add_referral($ownerCode, $referredCode, $referredUid = '') {
    if ($ownerCode === '' || $referredCode === '') {
        return null;
    }

    $ref = [
        't' => 'ref',
        'ownerCode' => $ownerCode,
        'referredCode' => $referredCode,
        'timestamp' => time(),
    ];

    if ($referredUid !== '') {
        $ref['referredUid'] = $referredUid;
    }

    tg_send_message(TG_CHANNEL_REFERRALS, json_encode($ref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    store_clear_cache('referrals');
    return $ref;
}

function store_get_referral_tree($uid) {
    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }

    $myCode = store_user_referral_code($user);
    $all = store_read_referrals();

    $direct = array_values(array_filter($all, function ($r) use ($myCode) {
        return (string) ($r['ownerCode'] ?? '') === $myCode && $myCode !== '';
    }));

    $directCodes = array_map(function ($r) {
        return (string) ($r['referredCode'] ?? '');
    }, $direct);

    $level2 = array_values(array_filter($all, function ($r) use ($directCodes) {
        return in_array((string) ($r['ownerCode'] ?? ''), $directCodes, true);
    }));

    $userMap = [];
    foreach (store_read_users() as $u) {
        $code = store_user_referral_code($u);
        if ($code !== '') {
            $userMap[$code] = $u;
        }
    }

    $formatUser = function ($u) {
        if (!is_array($u)) {
            return null;
        }
        return [
            'email' => (string) ($u['email'] ?? ''),
            'firebaseUid' => store_user_uid($u),
            'name' => (string) ($u['name'] ?? ''),
            'username' => (string) ($u['username'] ?? ''),
            'avatar' => (string) ($u['avatar'] ?? ''),
            'referralCode' => store_user_referral_code($u),
            'likedVideos' => $u['likedVideos'] ?? [],
            'savedVideos' => $u['savedVideos'] ?? [],
            'createdAt' => (int) ($u['createdAt'] ?? 0),
        ];
    };

    $mapUsers = function ($list) use ($userMap, $formatUser) {
        $out = [];
        foreach ($list as $r) {
            $u = $userMap[$r['referredCode'] ?? ''] ?? null;
            $formatted = $formatUser($u);
            if ($formatted !== null) {
                $out[] = $formatted;
            }
        }
        return $out;
    };

    return [
        'code' => $myCode,
        'direct' => $mapUsers($direct),
        'level2' => $mapUsers($level2),
    ];
}

} // end include guard
