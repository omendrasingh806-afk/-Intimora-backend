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
require_once __DIR__ . '/fastcache.php';
require_once __DIR__ . '/telegram.php';

// How long the channel history is kept on disk (seconds). This is only the
// freshness window: the copy is served immediately all the time, and once it is
// older than this it is refreshed in the background after the response (only
// the messages newer than the cached newest one are pulled). 15 s is short
// enough that a video uploaded on the other backend shows up almost at once,
// and long enough that a burst of visitors costs one refresh, not one each.
if (!defined('STORE_DISK_TTL')) {
    define('STORE_DISK_TTL', 15);
}
// After the TTL the cached copy is still served immediately (stale-while-
// revalidate) and only refreshed in the background, so no visitor ever waits.
if (!defined('STORE_DISK_STALE')) {
    define('STORE_DISK_STALE', 604800);
}
// Fresh reads are OFF: reading the whole channel from Telegram on every request
// was the single slowest thing this backend did (each page is a network round
// trip). The disk copy answers in milliseconds; writes patch it in place and
// the background refresh keeps it current, so the data stays warm and correct.
if (!defined('STORE_FRESH_READS')) {
    define('STORE_FRESH_READS', false);
}

// === In-Memory Cache (one request lifetime) ===
$_storeCache = [
    'videos' => ['data' => null, 'time' => 0],
    'users' => ['data' => null, 'time' => 0],
    'referrals' => ['data' => null, 'time' => 0],
    'collections' => ['data' => null, 'time' => 0],
];

// Types that were served stale in this request and need a background refresh.
$_storeStale = [];
// id => record lookup, built once per request (store_get_video was a scan).
$_storeVideoIndex = null;
// How many new messages one background refresh may pull from Telegram.
if (!defined('STORE_REFRESH_MAX_PAGES')) {
    define('STORE_REFRESH_MAX_PAGES', 5);
}

function store_cache($type, $data) {
    global $_storeCache, $_storeVideoIndex, $_storeStale;
    if ($type === 'videos') {
        $_storeVideoIndex = null;
    }
    unset($_storeStale[$type]);
    if (!isset($_storeCache[$type])) {
        $_storeCache[$type] = ['data' => null, 'time' => 0];
    }
    $_storeCache[$type]['data'] = $data;
    $_storeCache[$type]['time'] = time();

    // Persist across requests: the disk copy is what lets the next request
    // answer without a single Telegram round trip.
    fc_set('store_' . $type, $data);
}

function store_get_cache($type) {
    global $_storeCache, $_storeStale;

    if (isset($_storeCache[$type]['data']) && $_storeCache[$type]['data'] !== null) {
        if ((time() - $_storeCache[$type]['time']) < STORE_DISK_TTL) {
            return $_storeCache[$type]['data'];
        }
    }

    // Always ask Telegram for the current channel contents.
    if (STORE_FRESH_READS) {
        return null;
    }

    $age = fc_age('store_' . $type);
    if ($age === null || $age >= STORE_DISK_STALE) {
        return null;
    }

    // Fresh or stale: read the cached copy once, no Telegram round trip.
    $fromDisk = fc_peek('store_' . $type);
    if (!is_array($fromDisk)) {
        return null;
    }
    if (!isset($_storeCache[$type])) {
        $_storeCache[$type] = ['data' => null, 'time' => 0];
    }
    $_storeCache[$type]['data'] = $fromDisk;
    $_storeCache[$type]['time'] = time() - $age;

    if ($age >= STORE_DISK_TTL) {
        $_storeStale[$type] = true;
    }
    return $fromDisk;
}

// Is any cached list stale (so the request should schedule a background refresh)?
function store_has_stale() {
    global $_storeStale;
    return !empty($_storeStale);
}

// Age of a cached list in seconds, or null when nothing is cached.
function store_cache_age($type) {
    global $_storeCache;
    if (isset($_storeCache[$type]['data']) && $_storeCache[$type]['data'] !== null) {
        return max(0, time() - (int) $_storeCache[$type]['time']);
    }
    return fc_age('store_' . $type);
}

// Refresh the lists that were served stale. Runs after the response left the
// server (see json_ok in index.php), so the visitor never waits for Telegram.
function store_refresh_stale() {
    global $_storeStale;
    $types = array_keys((array) $_storeStale);
    $_storeStale = [];
    foreach ($types as $type) {
        try {
            if ($type === 'videos') {
                store_refresh_videos();
            } elseif ($type === 'users') {
                store_read_users(true);
            } elseif ($type === 'referrals') {
                store_read_referrals(true);
            }
        } catch (\Throwable $e) {
            // a failed refresh just leaves the stale copy in place
        }
    }
}

function store_clear_cache($type = null) {
    global $_storeCache, $_storeVideoIndex, $_storeStale;
    $_storeVideoIndex = null;
    if ($type) {
        unset($_storeStale[$type]);
        if (isset($_storeCache[$type])) {
            $_storeCache[$type]['data'] = null;
            $_storeCache[$type]['time'] = 0;
        }
        fc_forget('store_' . $type);
    } else {
        $_storeStale = [];
        foreach (array_keys($_storeCache) as $key) {
            $_storeCache[$key]['data'] = null;
            $_storeCache[$key]['time'] = 0;
            fc_forget('store_' . $key);
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
function store_to_stream_format($m, $kind = 'video') {
    $video = $kind === 'trailer' ? ($m['trailer'] ?? []) : ($m['video'] ?? []);
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
        'tg_channel' => $kind === 'trailer'
            ? (string) store_field($m['trailer'] ?? [], 'channel', 'tg_channel', TG_CHANNEL_VIDEO)
            : ($m['video']['channel'] ?? ($m['tg_channel'] ?? TG_CHANNEL_VIDEO)),
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
    $external = !empty($v['external']);

    return [
        'id' => $v['id'] ?? '',
        'title' => $v['title'] ?? '',
        'description' => $v['description'] ?? '',
        'exclusive' => (($v['exclusive'] ?? false) === true || ($v['exclusive'] ?? '') === 'true'),
        'premium' => (($v['premium'] ?? false) === true || ($v['premium'] ?? '') === 'true'),
        'hasTrailer' => is_array($v['trailer'] ?? null) && (!empty($v['trailer']['docId']) || !empty($v['trailer']['tg_doc_id'])),
        'trailerSize' => (int) ($v['trailer']['size'] ?? $v['trailer']['tg_size'] ?? 0),
        'size' => isset($video['size']) ? (int) $video['size'] : (isset($video['tg_size']) ? (int) $video['tg_size'] : null),
        'mime' => store_field($video, 'mime', 'tg_mime', 'video/mp4'),
        'thumbSize' => isset($thumb['size']) ? (int) $thumb['size'] : (isset($thumb['thumb_size']) ? (int) $thumb['thumb_size'] : 0),
        'duration' => (int) ($v['duration'] ?? 0),
        'views' => (int) ($v['views'] ?? 0),
        'likes' => (int) ($v['likes'] ?? 0),
        'shares' => (int) ($v['shares'] ?? 0),
        'createdAt' => (int) ($v['createdAt'] ?? 0),
        // A third-party video is played by its own source, so it carries no
        // stream of ours and its poster is the URL the client sent.
        'streamUrl' => $external ? null : '/api/videos/' . ($v['id'] ?? '') . '/stream',
        'thumbnailUrl' => $external
            ? (string) ($v['thumbnailUrl'] ?? '')
            : '/api/videos/' . ($v['id'] ?? '') . '/thumbnail',
        'external' => $external,
        'source' => $external ? (string) ($v['source'] ?? '') : '',
        'pageUrl' => $external ? (string) ($v['pageUrl'] ?? '') : '',
        'embedUrl' => $external ? (string) ($v['embedUrl'] ?? '') : '',
        'bestfile' => [
            'download_id' => $v['bestfile']['download_id'] ?? '',
            'download_link' => $v['bestfile']['download_link'] ?? '',
            'size' => (int) ($v['bestfile']['size'] ?? 0),
        ],
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

// Newest Telegram message id in a stored video list (0 when unknown).
function store_newest_msg_id($videos) {
    $newest = 0;
    foreach ((array) $videos as $v) {
        $id = (int) ($v['_msgId'] ?? 0);
        if ($id > $newest) {
            $newest = $id;
        }
    }
    return $newest;
}

// All video metadata, newest first
function store_read_videos($force = false) {
    if (!$force) {
        $cached = store_get_cache('videos');
        if ($cached !== null) {
            return $cached;
        }
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

// Background refresh: pull only the messages newer than the cached newest one.
// The old cached list stays in place, so this is a few round trips instead of
// re-reading the whole channel (which was the multi-second part).
function store_refresh_videos() {
    $old = fc_peek('store_videos');
    if (!is_array($old) || !$old) {
        store_read_videos(true);
        return;
    }

    $newestId = store_newest_msg_id($old);
    $fresh = [];
    $offsetId = 0;
    $done = false;

    for ($page = 0; $page < STORE_REFRESH_MAX_PAGES && !$done; $page++) {
        $messages = tg_get_history(TG_CHANNEL_META, 200, $offsetId);
        if (empty($messages)) {
            break;
        }

        foreach ($messages as $msg) {
            $id = (int) ($msg['id'] ?? 0);
            if ($newestId > 0 && $id <= $newestId) {
                $done = true;
                break;
            }
            $parsed = store_parse_meta_message($msg);
            if ($parsed !== null) {
                $fresh[] = $parsed;
            }
        }

        $oldest = (int) ($messages[count($messages) - 1]['id'] ?? 0);
        if ($oldest <= 0 || $oldest === $offsetId) {
            break;
        }
        $offsetId = $oldest;
    }

    if (!$fresh) {
        // Nothing new: only bump the cache timestamp, no re-sorting needed.
        store_cache('videos', $old);
        return;
    }

    $merged = array_merge($fresh, $old);
    usort($merged, function ($a, $b) {
        return ((int) ($b['createdAt'] ?? 0)) <=> ((int) ($a['createdAt'] ?? 0));
    });
    store_cache('videos', $merged);
}

// Legacy name kept for existing callers
function store_get_videos() {
    return store_read_videos();
}

// Infinite scroll — cursor is the createdAt of the last item of the previous page
function store_list_videos($cursor = 0, $limit = 12) {
    // Third-party records exist only so a user can like/save them; they are not
    // part of our catalogue and must never appear in a listing.
    $metas = array_values(array_filter(store_read_videos(), function ($m) {
        return empty($m['external']);
    }));

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
        if (!empty($m['external'])) {
            continue;
        }
        if (mb_strpos(mb_strtolower((string) ($m['title'] ?? '')), $q) !== false) {
            $matches[] = $m;
        }
    }
    return $matches;
}

// id => record map, built once per request
function store_video_index() {
    global $_storeVideoIndex;
    if (is_array($_storeVideoIndex)) {
        return $_storeVideoIndex;
    }
    $_storeVideoIndex = [];
    foreach (store_read_videos() as $v) {
        $_storeVideoIndex[(string) ($v['id'] ?? '')] = $v;
    }
    return $_storeVideoIndex;
}

function store_get_video($videoId) {
    $index = store_video_index();
    $key = (string) $videoId;
    return $index[$key] ?? null;
}

// === Video writes ===

// Trailer block for the metadata message (video + trailer live in one message).
function store_build_trailer($t) {
    if (!is_array($t)) {
        return null;
    }
    $docId = (string) store_field($t, 'docId', 'tg_doc_id', '');
    $bfLink = (string) ($t['bestfile']['download_link'] ?? '');
    if ($docId === '' && $bfLink === '') {
        return null;
    }
    return [
        'docId' => $docId,
        'accessHash' => (string) store_field($t, 'accessHash', 'tg_access_hash', ''),
        'fileRef' => (string) store_field($t, 'fileRef', 'tg_file_ref', ''),
        'size' => (int) store_field($t, 'size', 'tg_size', 0),
        'mime' => (string) store_field($t, 'mime', 'tg_mime', 'video/mp4'),
        'msgId' => (int) store_field($t, 'msgId', 'tg_msg_id', 0),
        'filename' => (string) store_field($t, 'filename', 'tg_filename', 'trailer.mp4'),
        'bestfile' => [
            'download_id' => (string) ($t['bestfile']['download_id'] ?? ''),
            'download_link' => $bfLink,
            'size' => (int) ($t['bestfile']['size'] ?? 0),
        ],
    ];
}

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
        'premium' => (($v['premium'] ?? false) === true || ($v['premium'] ?? '') === 'true'),
        'trailer' => store_build_trailer($v['trailer'] ?? null),
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

// Send a metadata JSON message to the meta channel, returns the message id.
// The warm list is patched instead of discarded: a write must never make the
// next visitor pay a full channel re-read.
function store_send_metadata($metadata) {
    $result = tg_send_message(TG_CHANNEL_META, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $msgId = tg_msg_id($result);
    if ($msgId !== null) {
        $metadata['_msgId'] = $msgId;
    }
    store_cache_store_video($metadata);
    return $msgId;
}

// Edit an existing metadata message (the caller patches the cache with the new
// record, so the list stays warm)
function store_edit_metadata($msgId, $metadata) {
    tg_edit_message(TG_CHANNEL_META, (int) $msgId, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// Add a new video; returns the stored metadata (with _msgId)
function store_add_video($videoData) {
    $metadata = store_build_metadata($videoData);
    $msgId = store_send_metadata($metadata);
    $metadata['_msgId'] = $msgId;
    return $metadata;
}

// === Third-party (external) videos ===
//
// A video that lives on another host — a partner API, a scraped provider — is
// not uploaded to our channels, so it used to have no record at all: a like or a
// save was stored on the user, but the account could never show it again. The
// first like/save now writes one small `external` metadata record, so the same
// store, the same liked/saved lists and the same lookup work for it. That record
// carries no Telegram file and is never streamed from us; it only remembers the
// facts the client sent, each of them bounded.

function store_external_id($id) {
    $id = (string) $id;
    return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) === 1 ? $id : null;
}

function store_external_text($value, $max) {
    if (!is_string($value)) {
        return '';
    }
    $text = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value);
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    return mb_substr($text, 0, (int) $max);
}

function store_external_url($value) {
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048) {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }
    return $value;
}

function store_build_external_metadata($id, $data) {
    $videoId = store_external_id($id);
    if ($videoId === null || !is_array($data)) {
        return null;
    }
    $title = store_external_text($data['title'] ?? '', 300);
    $thumbnail = store_external_url($data['thumbnailUrl'] ?? ($data['thumbnail'] ?? ''));
    if ($title === '' || $thumbnail === '') {
        return null;
    }
    $source = preg_replace('/[^a-z0-9-]/', '', strtolower(store_external_text($data['source'] ?? '', 32)));

    return [
        't' => 'meta',
        'id' => $videoId,
        'external' => true,
        'source' => $source !== '' ? $source : 'external',
        'title' => $title,
        'description' => store_external_text($data['description'] ?? '', 1000),
        'pageUrl' => store_external_url($data['pageUrl'] ?? ''),
        'embedUrl' => store_external_url($data['embedUrl'] ?? ''),
        'thumbnailUrl' => $thumbnail,
        'duration' => max(0, (int) ($data['duration'] ?? 0)),
        'views' => 0,
        'likes' => 0,
        'shares' => 0,
        'likedBy' => [],
        'createdAt' => time(),
        'video' => [],
        'thumbnail' => null,
    ];
}

// Register a third-party video the first time it is liked or saved. A real
// upload with the same id always wins, and an already registered id is reused so
// a second user's like writes nothing new.
function store_register_external_video($id, $data) {
    $existing = store_get_video($id);
    if ($existing) {
        return empty($existing['external']) ? null : $existing;
    }
    $metadata = store_build_external_metadata($id, $data);
    if ($metadata === null) {
        return null;
    }
    $metadata['_msgId'] = store_send_metadata($metadata);
    return $metadata;
}

// === Cache patching ========================================================
// Every write (new video, edit, delete, like, save) patches the warm list in
// place. Clearing the cache instead would turn the next request into a full
// Telegram re-read, which is exactly the multi-second wait this backend is
// trying to avoid. A cold cache stays cold: the next read fills it normally.

function store_videos_sort(&$list) {
    usort($list, function ($a, $b) {
        return ((int) ($b['createdAt'] ?? 0)) <=> ((int) ($a['createdAt'] ?? 0));
    });
}

// Insert or replace one video record in the warm videos cache.
function store_cache_store_video($record) {
    global $_storeCache;
    if (!is_array($record) || (string) ($record['id'] ?? '') === '') {
        return;
    }
    if (!isset($_storeCache['videos']['data']) || !is_array($_storeCache['videos']['data'])) {
        return;
    }

    $id = (string) $record['id'];
    $list = $_storeCache['videos']['data'];
    $found = false;
    foreach ($list as $i => $v) {
        if ((string) ($v['id'] ?? '') === $id) {
            $list[$i] = $record;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $list[] = $record;
        store_videos_sort($list);
    }
    store_cache('videos', $list);
}

// Patch a single entry in the (possibly warm) cache after a write
function store_cache_patch_video($videoId, $fresh) {
    store_cache_store_video($fresh);
}

// Drop a deleted video from the warm cache.
function store_cache_remove_video($videoId) {
    global $_storeCache;
    if (!isset($_storeCache['videos']['data']) || !is_array($_storeCache['videos']['data'])) {
        return;
    }
    $list = array_values(array_filter($_storeCache['videos']['data'], function ($v) use ($videoId) {
        return (string) ($v['id'] ?? '') !== (string) $videoId;
    }));
    store_cache('videos', $list);
}

// Insert or replace one user record in the warm users cache.
function store_cache_store_user($record) {
    global $_storeCache;
    if (!is_array($record) || store_user_uid($record) === '') {
        return;
    }
    if (!isset($_storeCache['users']['data']) || !is_array($_storeCache['users']['data'])) {
        return;
    }

    $uid = store_user_uid($record);
    $list = $_storeCache['users']['data'];
    $found = false;
    foreach ($list as $i => $u) {
        if (store_user_uid($u) === $uid) {
            $list[$i] = $record;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $list[] = $record;
    }
    store_cache('users', $list);
}

// Insert or replace one user's like/save collection in the warm cache.
function store_cache_store_collection($record) {
    global $_storeCache;
    $uid = (string) ($record['uid'] ?? '');
    if ($uid === '') {
        return;
    }
    if (!isset($_storeCache['collections']['data']) || !is_array($_storeCache['collections']['data'])) {
        return;
    }
    $_storeCache['collections']['data'][$uid] = $record;
    store_cache('collections', $_storeCache['collections']['data']);
}

// Merge $updates into a video's metadata message (edit in place)
function store_update_video_stats($videoId, $updates) {
    $msg = store_get_video($videoId);
    if (!$msg) {
        return null;
    }

    // Keep the cached list around: store_edit_metadata() clears it, and
    // re-reading the whole channel from Telegram for one view/like would make
    // every following request slow again.
    $list = store_read_videos();

    $data = array_merge($msg, $updates);
    unset($data['_msgId']);

    store_edit_metadata($msg['_msgId'], $data);
    $data['_msgId'] = $msg['_msgId'];

    foreach ($list as $i => $v) {
        if ((string) ($v['id'] ?? '') === (string) $videoId) {
            $list[$i] = $data;
        }
    }
    store_cache('videos', $list);

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

    store_cache_remove_video($videoId);
    return ['deleted' => true, 'channels' => $channels];
}

// === Users ===

function store_read_users($force = false) {
    if (!$force) {
        $cached = store_get_cache('users');
        if ($cached !== null) {
            return $cached;
        }
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

    store_cache_store_user($user);
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
    } catch (\Throwable $e) {
        return null;
    }

    $data['_msgId'] = $msgId;
    store_cache_store_user($data);
    return $data;
}

// === Like/save collections ===
//
// Likes and saves live in their own channel (TG_CHANNEL_ACCOUNTS), one JSON
// message per user keyed by the user's uid:
//   {"t":"likes","uid":"…","likedVideos":[…],"savedVideos":[…],"updatedAt":…}
// Keeping the id lists out of the user record means a growing list can never hit
// Telegram's message-size limit, and the lists never rewrite an account. Records
// written before this channel existed are seeded from the user record on the
// first read and written here on the first change, so nothing is lost.

function store_read_collections($force = false) {
    if (!$force) {
        $cached = store_get_cache('collections');
        if ($cached !== null) {
            return $cached;
        }
    }

    $records = [];
    foreach (store_read_messages(TG_CHANNEL_ACCOUNTS) as $msg) {
        $text = $msg['message'] ?? '';
        if (!is_string($text) || $text === '') {
            continue;
        }
        $data = json_decode($text, true);
        if (!is_array($data) || ($data['t'] ?? '') !== 'likes' || !is_string($data['uid'] ?? null)) {
            continue;
        }
        $data['likedVideos'] = array_values((array) ($data['likedVideos'] ?? []));
        $data['savedVideos'] = array_values((array) ($data['savedVideos'] ?? []));
        // A uid should have exactly one record; if an older one is still in the
        // channel, the newest (read first) wins.
        if (isset($records[$data['uid']])) {
            continue;
        }
        $data['_msgId'] = (int) ($msg['id'] ?? 0);
        $records[$data['uid']] = $data;
    }

    store_cache('collections', $records);
    return $records;
}

function store_collection($uid) {
    $uid = (string) $uid;
    if ($uid === '') {
        return null;
    }
    $all = store_read_collections();
    if (isset($all[$uid])) {
        return $all[$uid];
    }

    $user = store_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }
    return [
        't' => 'likes',
        'uid' => $uid,
        'likedVideos' => array_values((array) ($user['likedVideos'] ?? [])),
        'savedVideos' => array_values((array) ($user['savedVideos'] ?? [])),
        '_msgId' => 0,
    ];
}

function store_write_collection($record) {
    $data = [
        't' => 'likes',
        'uid' => (string) $record['uid'],
        'likedVideos' => array_values((array) ($record['likedVideos'] ?? [])),
        'savedVideos' => array_values((array) ($record['savedVideos'] ?? [])),
        'updatedAt' => time(),
    ];
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!empty($record['_msgId'])) {
        tg_edit_message(TG_CHANNEL_ACCOUNTS, (int) $record['_msgId'], $json);
    } else {
        $result = tg_send_message(TG_CHANNEL_ACCOUNTS, $json);
        $data['_msgId'] = tg_msg_id($result);
    }

    store_cache_store_collection($data);
    return $data;
}

// The user JSON a client reads carries the ids from the collection channel, so
// the app never has to know where they are stored.
function store_user_with_collections($user) {
    if (!is_array($user) || (string) ($user['uid'] ?? '') === '') {
        return $user;
    }
    $collection = store_collection($user['uid']);
    if ($collection === null) {
        return $user;
    }
    $user['likedVideos'] = $collection['likedVideos'];
    $user['savedVideos'] = $collection['savedVideos'];
    return $user;
}

function store_toggle_user_like($uid, $videoId) {
    // A toggle is a read-modify-write: never build the new list from a copy
    // that another backend may already have moved on from.
    $age = store_cache_age('collections');
    if ($age !== null && $age > 3) {
        store_read_collections(true);
    }

    $collection = store_collection($uid);
    if ($collection === null) {
        return null;
    }

    $liked = $collection['likedVideos'];
    $idx = array_search($videoId, $liked, true);
    if ($idx !== false) {
        array_splice($liked, $idx, 1);
    } else {
        $liked[] = $videoId;
    }

    $collection['likedVideos'] = array_values($liked);
    store_write_collection($collection);
    return ['liked' => $idx === false, 'likedVideos' => array_values($liked)];
}

function store_toggle_user_save($uid, $videoId) {
    // Same read-modify-write guard as a like.
    $age = store_cache_age('collections');
    if ($age !== null && $age > 3) {
        store_read_collections(true);
    }

    $collection = store_collection($uid);
    if ($collection === null) {
        return null;
    }

    $saved = $collection['savedVideos'];
    $idx = array_search($videoId, $saved, true);
    if ($idx !== false) {
        array_splice($saved, $idx, 1);
    } else {
        $saved[] = $videoId;
    }

    $collection['savedVideos'] = array_values($saved);
    store_write_collection($collection);
    return ['saved' => $idx === false, 'savedVideos' => array_values($saved)];
}

function store_get_liked_videos($uid) {
    $collection = store_collection($uid);
    return $collection === null ? null : $collection['likedVideos'];
}

function store_get_saved_videos($uid) {
    $collection = store_collection($uid);
    return $collection === null ? null : $collection['savedVideos'];
}

// === Referrals ===

function store_read_referrals($force = false) {
    if (!$force) {
        $cached = store_get_cache('referrals');
        if ($cached !== null) {
            return $cached;
        }
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
