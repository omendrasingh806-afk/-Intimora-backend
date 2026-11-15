<?php
// Intimora PHP Backend — Telegram MTProto wrappers (MadelineProto 8.x)
// Full MTProto support — no Bot API limits, direct channel access

// Everything is declared inside this guard so the file can be included twice
// (plain require after require_once) without "Cannot redeclare function" errors.
if (!defined('INTIMORA_TELEGRAM')) {
define('INTIMORA_TELEGRAM', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mtproto.php';

// === Telegram Wrapper Functions ===

// Connect to Telegram (reuses MTProto client)
function tg_connect() {
    return mtproto_connect();
}

// Peer for a channel — MadelineProto 8 accepts the channel id string as-is
function tg_get_input_entity($channel) {
    tg_connect();
    return $channel;
}

// Get history from channel ($offsetId = oldest message id of the previous page)
function tg_get_history($channel, $limit = 200, $offsetId = 0) {
    return mtproto_get_history($channel, $limit, $offsetId);
}

// Get messages by ID
function tg_get_messages($channel, $ids) {
    $client = tg_connect();
    $ids = array_map(function ($id) {
        return ['_' => 'inputMessageID', 'id' => (int) $id];
    }, (array) $ids);

    $result = $client->client()->messages->getMessages(id: $ids);
    return $result['messages'] ?? [];
}

// Get file chunk for streaming ($limit is a byte count)
function tg_get_file($videoMeta, $offset, $limit) {
    return mtproto_read_file_range($videoMeta, $offset, $offset + $limit - 1);
}

// Read file range (for thumbnails / moov probes)
function tg_read_file_range($videoMeta, $start, $end) {
    return mtproto_read_file_range($videoMeta, $start, $end);
}

// Stream file range (for HTTP streaming — generator)
function tg_stream_range($videoMeta, $start, $end) {
    return mtproto_stream_range($videoMeta, $start, $end);
}

// Find moov atom in MP4 (checks first 1MB, then last 2MB if needed)
function tg_find_moov_atom($videoMeta) {
    $tempFile = tempnam(sys_get_temp_dir(), 'moov_check_');

    // Read first 1MB
    $firstChunk = tg_read_file_range($videoMeta, 0, 1048576);
    file_put_contents($tempFile, $firstChunk);

    $moov = find_moov_in_file($tempFile, 0);
    if ($moov) {
        unlink($tempFile);
        return $moov;
    }

    // moov not in first 1MB — check last 2MB
    $fileSize = $videoMeta['tg_size'];
    $moovStart = max(0, $fileSize - 2097152);

    $endChunk = tg_read_file_range($videoMeta, $moovStart, $fileSize - 1);
    file_put_contents($tempFile, $endChunk);

    $moov = find_moov_in_file($tempFile, $moovStart);
    unlink($tempFile);

    return $moov;
}

// Helper: find moov atom in a local file
function find_moov_in_file($filePath, $absoluteOffsetBase = 0) {
    $fh = fopen($filePath, 'rb');
    if (!$fh) return null;

    $header = fread($fh, 32);
    if (strpos($header, 'ftyp') === false) {
        fclose($fh);
        return null;
    }

    $offset = 32;

    while (!feof($fh)) {
        fseek($fh, $offset);
        $sizeBytes = fread($fh, 4);
        $typeBytes = fread($fh, 4);

        if (strlen($sizeBytes) < 4 || strlen($typeBytes) < 4) break;

        $size = unpack('N', $sizeBytes)[1];
        $type = $typeBytes;

        if ($type === 'moov') {
            $absoluteStart = $absoluteOffsetBase + $offset;
            fseek($fh, $offset);
            $data = fread($fh, $size);
            fclose($fh);
            return ['start' => $absoluteStart, 'size' => $size, 'data' => $data];
        }

        if ($size <= 0) break;
        $offset += $size;
    }

    fclose($fh);
    return null;
}

// Send message to channel (plain text — no HTML parsing, JSON stays intact)
function tg_send_message($channel, $text) {
    return mtproto_send_message($channel, $text);
}

// Edit a message in place
function tg_edit_message($channel, $msgId, $text) {
    return mtproto_edit_message($channel, $msgId, $text);
}

// Delete messages from a channel
function tg_delete_messages($channel, $ids) {
    return mtproto_delete_messages($channel, $ids);
}

// Telegram returns a message constructor — pull its id out
function tg_msg_id($message) {
    if (!is_array($message)) {
        return null;
    }
    if (isset($message['id'])) {
        return (int) $message['id'];
    }
    // Updates container (messages.sendMessage may answer with an update object)
    if (isset($message['updates'][0]['message']['id'])) {
        return (int) $message['updates'][0]['message']['id'];
    }
    return null;
}

// Send JSON to meta channel
function tg_send_meta($videoData) {
    $json = json_encode($videoData, JSON_PRETTY_PRINT);
    return tg_send_message(TG_CHANNEL_META, $json);
}

// Send JSON to users channel
function tg_send_user($userData) {
    $json = json_encode($userData, JSON_PRETTY_PRINT);
    return tg_send_message(TG_CHANNEL_USERS, $json);
}

// Upload video to Telegram channel — returns the sent message constructor
function tg_upload_video($channel, $filePath, $caption = '', $fileName = 'video.mp4') {
    return mtproto_upload_video($channel, $filePath, $caption, $fileName);
}

// === Video Processing ===

function tg_process_fast_start($inputPath, $outputPath) {
    if (!defined('FFMPEG_PATH') || !function_exists('shell_exec')) {
        return false;
    }

    $cmd = escapeshellarg(FFMPEG_PATH) . ' -i ' . escapeshellarg($inputPath)
        . ' -c copy -movflags faststart ' . escapeshellarg($outputPath)
        . ' 2>&1';

    shell_exec($cmd);
    return file_exists($outputPath);
}

function tg_get_duration($filePath) {
    if (!defined('FFPROBE_PATH') || !function_exists('shell_exec')) {
        return 0;
    }

    $cmd = escapeshellarg(FFPROBE_PATH) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($filePath);
    $duration = trim(shell_exec($cmd));
    return (int) $duration;
}

} // end include guard
