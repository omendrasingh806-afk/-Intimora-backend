<?php
// Intimora PHP Backend — MTProto client (MadelineProto 8.x)
// The 8.x API is blocking: every call returns its result directly (no promises).
// The Telegram login is imported once from TELEGRAM_SESSION into a real
// MadelineProto session stored in ./sessions/telegram.madeline

use danog\MadelineProto\API;
use danog\MadelineProto\Conversion;
use danog\MadelineProto\Logger;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\Settings;

// Everything else is declared inside this guard so the file can be included
// twice (plain require after require_once) without redeclare errors.
if (!defined('INTIMORA_MTPROTO')) {
define('INTIMORA_MTPROTO', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

// === IPC handling ===
// MadelineProto 8 normally runs the session in a background IPC worker process
// (proc_open + stream_socket_server/posix_mkfifo). Shared hosts such as
// InfinityFree disable all of those, so we run everything inside this request
// instead: MT_IPC_DISABLED skips the IPC server, and the client is put in
// "worker" mode so the session is unserialized in-process.
if (!defined('MT_IPC_DISABLED')) {
    define('MT_IPC_DISABLED', !function_exists('proc_open') || !function_exists('stream_socket_server'));
}

// === Session handling ===

function mtproto_session_path() {
    $dir = __DIR__ . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/telegram.madeline';
}

/**
 * Parse a string session (Telethon v1 / GramJS / teleproto layouts) and return
 * ['dc' => int, 'auth_key' => string], or null when the string is unusable.
 */
function mtproto_parse_session_string($session) {
    $session = trim((string) $session);
    if ($session === '') {
        return null;
    }
    // Version prefix used by Telethon/GramJS string sessions
    if ($session[0] === '1') {
        $session = substr($session, 1);
    }
    $b64 = strtr($session, '-_', '+/');
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $raw = base64_decode($b64, true);
    if (!is_string($raw) || strlen($raw) < 257) {
        return null;
    }
    $dc = ord($raw[0]);
    if ($dc < 1 || $dc > 5) {
        return null;
    }
    return ['dc' => $dc, 'auth_key' => substr($raw, -256)];
}

function mtproto_settings() {
    $settings = new Settings();
    $settings->getAppInfo()
        ->setApiId((int) TG_API_ID)
        ->setApiHash(TG_API_HASH);
    // Logs must never reach the HTTP response body
    $settings->getLogger()
        ->setType(Logger::FILE_LOGGER)
        ->setLevel(defined('MTPROTO_DEBUG') && MTPROTO_DEBUG ? Logger::VERBOSE : Logger::FATAL_ERROR)
        ->setExtra(sys_get_temp_dir() . '/intimora_madeline.log');

    // No IPC available: behave like the (normally spawned) worker process so
    // MadelineProto loads the whole session here instead of dialling a worker.
    if (defined('MT_IPC_DISABLED') && MT_IPC_DISABLED) {
        \danog\MadelineProto\Magic::$isIpcWorker = true;
    }

    return $settings;
}

class MTProtoClient {
    private $apiId;
    private $apiHash;
    private $session;
    private $api = null;

    public function __construct($apiId, $apiHash, $session) {
        $this->apiId = $apiId;
        $this->apiHash = $apiHash;
        $this->session = $session;
    }

    /**
     * Open the session (imports the login from TELEGRAM_SESSION on first run).
     */
    public function connect() {
        if ($this->api !== null) {
            return;
        }

        $path = mtproto_session_path();

        if (!file_exists($path)) {
            $parsed = mtproto_parse_session_string($this->session);
            if ($parsed === null) {
                throw new RuntimeException(
                    'TELEGRAM_SESSION is empty or invalid — cannot import the Telegram login'
                );
            }
            // Creates a MadelineProto session from the raw auth key
            $this->api = Conversion::importAuthorization(
                [$parsed['dc'] => $parsed['auth_key']],
                $parsed['dc'],
                $path,
                mtproto_settings()
            );
            // A brand new session has an empty peer database: channels cannot be
            // addressed by ID until the dialog list (with access hashes) is loaded.
            $this->primePeers();
            return;
        }

        $this->api = new API($path, mtproto_settings());
    }

    /**
     * Load the peer database (access hashes) from the account's dialogs.
     */
    public function primePeers() {
        if ($this->api === null) {
            return;
        }
        try {
            $this->api->messages->getDialogs(limit: 200);
        } catch (Throwable $e) {
            // Non-fatal: the caller surfaces a more specific error if it matters.
        }
    }

    public function isConnected() {
        return $this->api !== null;
    }

    /**
     * Raw MadelineProto API instance.
     */
    public function client() {
        $this->connect();
        return $this->api;
    }

    /**
     * Data center the login lives on (used as download fallback).
     */
    public function dataCenter() {
        $parsed = mtproto_parse_session_string($this->session);
        return $parsed['dc'] ?? 2;
    }

    public function disconnect() {
        $this->api = null;
    }
}

// === MTProto Functions ===

$mtprotoClient = null;

function mtproto() {
    global $mtprotoClient;
    if ($mtprotoClient === null) {
        $mtprotoClient = new MTProtoClient(TG_API_ID, TG_API_HASH, TELEGRAM_SESSION);
    }
    return $mtprotoClient;
}

// Connect to Telegram
function mtproto_connect() {
    $client = mtproto();
    if (!$client->isConnected()) {
        $client->connect();
    }
    return $client;
}

// Get history from a channel ($offsetId = oldest message id of the previous page)
function mtproto_get_history($channel, $limit = 200, $offsetId = 0) {
    $client = mtproto_connect();
    $api = $client->client();
    try {
        $result = $api->messages->getHistory(
            peer: $channel,
            offset_id: (int) $offsetId,
            limit: (int) $limit
        );
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'peer is not present') === false) {
            throw $e;
        }
        // Peer cache was empty (fresh session): load it and retry once.
        $client->primePeers();
        $result = $api->messages->getHistory(
            peer: $channel,
            offset_id: (int) $offsetId,
            limit: (int) $limit
        );
    }
    return $result['messages'] ?? [];
}

/**
 * Build the TL media constructor MadelineProto needs to download a stored file.
 */
function mtproto_media($videoMeta) {
    $fileRef = base64_decode((string) ($videoMeta['tg_file_ref'] ?? ''), true);
    $dc = (int) ($videoMeta['tg_dc_id'] ?? 0);
    if ($dc < 1 || $dc > 5) {
        $dc = mtproto()->dataCenter();
    }

    return [
        '_' => 'messageMediaDocument',
        'document' => [
            '_' => 'document',
            'id' => (int) $videoMeta['tg_doc_id'],
            'access_hash' => (int) $videoMeta['tg_access_hash'],
            'file_reference' => is_string($fileRef) ? $fileRef : '',
            'dc_id' => $dc,
            'mime_type' => $videoMeta['tg_mime'] ?? 'video/mp4',
            'size' => (int) ($videoMeta['tg_size'] ?? 0),
            'date' => 0,
            'version' => 0,
            'attributes' => [
                ['_' => 'documentAttributeFilename', 'file_name' => $videoMeta['tg_filename'] ?? 'video.mp4'],
            ],
        ],
    ];
}

/**
 * Re-fetch the media of a stored message — stored file references expire and
 * must be replaced with a fresh one from Telegram.
 */
function mtproto_refresh_media($videoMeta) {
    $channel = $videoMeta['tg_channel'] ?? '';
    $msgId = (int) ($videoMeta['tg_msg_id'] ?? 0);
    if ($channel === '' || $msgId <= 0) {
        return null;
    }

    // offset_id is exclusive, so msgId + 1 with limit 1 returns exactly that message
    $messages = mtproto_get_history($channel, 1, $msgId + 1);
    $media = $messages[0]['media'] ?? null;
    if (!is_array($media)) {
        return null;
    }
    if (($media['_'] ?? '') === 'messageMediaDocument' || ($media['_'] ?? '') === 'messageMediaPhoto') {
        return $media;
    }
    return null;
}

/**
 * Download the range [$start, $end] (both inclusive) as a chunk generator.
 * Refreshes an expired file reference once and retries.
 */
function mtproto_download_range($videoMeta, $start, $end) {
    $client = mtproto_connect();
    $media = mtproto_media($videoMeta);
    $refreshed = false;

    while (true) {
        $stream = $client->client()->downloadToReturnedStream($media, null, (int) $start, (int) $end + 1);
        $emitted = 0;

        try {
            while (($chunk = $stream->read()) !== null) {
                $emitted += strlen($chunk);
                yield $chunk;
            }
            return;
        } catch (Throwable $e) {
            $expired = stripos($e->getMessage(), 'FILE_REFERENCE_EXPIRED') !== false;
            if ($refreshed || $emitted > 0 || !$expired) {
                throw $e;
            }
            $fresh = mtproto_refresh_media($videoMeta);
            if ($fresh === null) {
                throw $e;
            }
            $media = $fresh;
            $refreshed = true;
        } finally {
            $stream->close();
        }
    }
}

// Read file range into a string (used for thumbnails / moov probing)
function mtproto_read_file_range($videoMeta, $start, $end) {
    $data = '';
    foreach (mtproto_download_range($videoMeta, $start, $end) as $chunk) {
        $data .= $chunk;
    }
    return $data;
}

// Stream file range (generator — used for HTTP range responses)
function mtproto_stream_range($videoMeta, $start, $end) {
    return mtproto_download_range($videoMeta, $start, $end);
}

// Send message to channel.
// $parseMode is null by default: JSON metadata must not be parsed as HTML,
// otherwise titles containing < or & get mangled.
function mtproto_send_message($channel, $text, $parseMode = null) {
    $client = mtproto_connect();
    $params = [
        'peer' => $channel,
        'message' => (string) $text,
    ];
    if ($parseMode !== null) {
        $params['parse_mode'] = $parseMode;
    }

    $result = $client->client()->messages->sendMessage(...$params);

    // Normally an Updates container — return the plain message (with id) when possible
    if (method_exists($client->client(), 'extractMessage')) {
        try {
            return $client->client()->extractMessage($result);
        } catch (\Throwable $e) {
            // fall back to the raw update
        }
    }

    return $result;
}

// Edit an existing message in place (used to update stored JSON metadata)
function mtproto_edit_message($channel, $msgId, $text) {
    $client = mtproto_connect();
    return $client->client()->messages->editMessage(
        peer: $channel,
        id: (int) $msgId,
        message: (string) $text
    );
}

// Delete messages from a channel
function mtproto_delete_messages($channel, $ids) {
    $client = mtproto_connect();
    $ids = array_values(array_map('intval', (array) $ids));
    if (!$ids) {
        return null;
    }
    return $client->client()->channels->deleteMessages(
        channel: $channel,
        id: $ids
    );
}

// Upload video to channel — returns the sent message constructor
function mtproto_upload_video($channel, $filePath, $caption, $fileName) {
    $client = mtproto_connect();
    $api = $client->client();

    // Upload the file first, then send it as a video document
    $inputFile = $api->upload($filePath, $fileName);

    $result = $api->messages->sendMedia(
        peer: $channel,
        message: $caption,
        media: [
            '_' => 'inputMediaUploadedDocument',
            'file' => $inputFile,
            'mime_type' => 'video/mp4',
            'force_file' => false,
            'attributes' => [
                ['_' => 'documentAttributeFilename', 'file_name' => $fileName],
                [
                    '_' => 'documentAttributeVideo',
                    'duration' => 0,
                    'w' => 0,
                    'h' => 0,
                    'supports_streaming' => true,
                ],
            ],
        ]
    );

    return $api->extractMessage($result);
}

// Debug: test connection
function mtproto_test_connection() {
    try {
        $client = mtproto_connect();
        $me = $client->client()->getSelf();

        if ($me === false) {
            return [
                'ok' => false,
                'error' => 'Not authorized — the Telegram session is not logged in',
            ];
        }

        return [
            'ok' => true,
            'user' => $me['id'] ?? null,
            'username' => $me['username'] ?? null,
            'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

} // end include guard
