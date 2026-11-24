<?php
// Intimora PHP Backend — cache warmer
//
// The first request for a thumbnail (or for the first megabyte of a video) has
// to read from Telegram, which takes seconds. This script fills those disk
// caches ahead of time so visitors always hit the fast path.
//
//   GET /warm.php?key=<ADMIN_API_KEY>
//   GET /warm.php?key=<ADMIN_API_KEY>&thumbs=12&streams=0
//
// Point a free cron (e.g. cron-job.org) at it every 10-15 minutes.
// Keep thumbs low on free hosting: every uncached thumbnail is a Telegram read.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fastcache.php';
require_once __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

$key = (string) ($_GET['key'] ?? '');
if ($key !== ADMIN_API_KEY) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Free hosting kills long requests, so keep the budget small by default.
$maxThumbs = max(0, min(30, (int) ($_GET['thumbs'] ?? 6)));
$maxStreams = max(0, min(10, (int) ($_GET['streams'] ?? 0)));
$deadline = microtime(true) + 20;

$started = microtime(true);
$report = ['videos' => 0, 'thumbs' => 0, 'thumbs_skipped' => 0, 'streams' => 0, 'errors' => []];

try {
    $videos = store_read_videos();          // also refreshes the 60 s list cache
    $report['videos'] = count($videos);
} catch (\Throwable $e) {
    $report['errors'][] = 'videos: ' . $e->getMessage();
    echo json_encode(['ok' => true, 'report' => $report, 'ms' => (int) ((microtime(true) - $started) * 1000)]);
    exit;
}

$thumbDone = 0;
$streamDone = 0;

foreach ($videos as $v) {
    if ($thumbDone >= $maxThumbs && $streamDone >= $maxStreams) {
        break;
    }
    if (microtime(true) > $deadline) {
        $report['errors'][] = 'time budget reached';
        break;
    }

    try {
        $stream = store_to_stream_format($v);
    } catch (\Throwable $e) {
        $report['errors'][] = 'stream format: ' . $e->getMessage();
        continue;
    }

    // --- thumbnail ---
    if ($thumbDone < $maxThumbs && !empty($stream['thumb_doc_id'])) {
        $key = 'thumb_' . $stream['thumb_doc_id'] . '_' . $stream['thumb_size'];
        if (fc_file($key, 2592000) !== null) {
            $report['thumbs_skipped']++;
        } else {
            try {
                $thumbMeta = [
                    'tg_doc_id' => $stream['thumb_doc_id'],
                    'tg_access_hash' => $stream['thumb_access_hash'],
                    'tg_file_ref' => $stream['thumb_file_ref'],
                    'tg_size' => $stream['thumb_size'],
                    'tg_mime' => 'image/jpeg',
                    'tg_channel' => $stream['thumb_channel'],
                    'tg_msg_id' => (int) ($stream['thumb_msg_id'] ?? 0),
                ];
                $bytes = $stream['thumb_size'] > 0
                    ? tg_read_file_range($thumbMeta, 0, $stream['thumb_size'] - 1)
                    : tg_read_file_range($thumbMeta, 0, 262143);

                if (is_string($bytes) && $bytes !== '') {
                    fc_put($key, $bytes);
                    $report['thumbs']++;
                }
            } catch (\Throwable $e) {
                $report['errors'][] = 'thumb ' . ($v['id'] ?? '?') . ': ' . $e->getMessage();
            }
            $thumbDone++;
        }
    }

    // --- video start: moov prefix + first 2 MB ---
    if ($streamDone < $maxStreams && !empty($stream['tg_doc_id'])) {
        $fileSize = (int) ($stream['tg_size'] ?? 0);
        if ($fileSize > 0) {
            $moovKey = 'moov_' . $stream['tg_doc_id'] . '_' . $fileSize;
            $preKey = 'vpre_' . $stream['tg_doc_id'] . '_' . $fileSize;

            try {
                if (!is_string(fc_get($moovKey, 2592000))) {
                    $moov = tg_find_moov_atom($stream);
                    if ($moov) {
                        fc_set($moovKey, tg_read_file_range($stream, 0, 31) . $moov['data']);
                    }
                }
                if (!is_string(fc_get($preKey, 2592000))) {
                    $prefix = tg_read_file_range($stream, 0, min(2097152, $fileSize) - 1);
                    if (is_string($prefix) && $prefix !== '') {
                        fc_set($preKey, $prefix);
                    }
                }
                $report['streams']++;
            } catch (\Throwable $e) {
                $report['errors'][] = 'stream ' . ($v['id'] ?? '?') . ': ' . $e->getMessage();
            }
            $streamDone++;
        }
    }
}

echo json_encode([
    'ok' => true,
    'report' => $report,
    'ms' => (int) ((microtime(true) - $started) * 1000),
]);
