// Telegram MTProto storage layer (teleproto)
// - upload video + thumbnail to a private channel
// - stream byte ranges back for HTTP range requests (video playback, no direct download)
const { TelegramClient } = require("teleproto");
const { StringSession } = require("teleproto/sessions");
const { Api } = require("teleproto");

let _client = null;

function cfg() {
  const apiId = parseInt(process.env.TELEGRAM_API_ID, 10);
  const apiHash = process.env.TELEGRAM_API_HASH;
  if (!apiId || !apiHash) throw new Error("TELEGRAM_API_ID / TELEGRAM_API_HASH not set");
  return { apiId, apiHash };
}

// ---- Channel helpers (3 separate channels) ----
function channelVideo() {
  const c = process.env.TELEGRAM_CHANNEL_VIDEO;
  if (!c) throw new Error("TELEGRAM_CHANNEL_VIDEO not set");
  return c;
}
function channelThumb() {
  const c = process.env.TELEGRAM_CHANNEL_THUMB;
  if (!c) throw new Error("TELEGRAM_CHANNEL_THUMB not set");
  return c;
}
function channelMeta() {
  const c = process.env.TELEGRAM_CHANNEL_META;
  if (!c) throw new Error("TELEGRAM_CHANNEL_META not set");
  return c;
}
function channelUsers() {
  const c = process.env.TELEGRAM_CHANNEL_USERS;
  if (!c) throw new Error("TELEGRAM_CHANNEL_USERS not set");
  return c;
}
function channelReferrals() {
  const c = process.env.TELEGRAM_CHANNEL_REFERRALS;
  if (!c) throw new Error("TELEGRAM_CHANNEL_REFERRALS not set");
  return c;
}
// Premium ledger — the same append-only channel the PHP backend uses.
function channelPremium() {
  return process.env.TELEGRAM_CHANNEL_PREMIUM || process.env.PREMIUM_CHANNEL || "-1004327041194";
}
// Like/save collections — one JSON message per user, keyed by the user's uid.
// Same channel and default as the PHP backend's TG_CHANNEL_ACCOUNTS.
function channelAccounts() {
  return process.env.TELEGRAM_CHANNEL_ACCOUNTS || "-1004393682063";
}

// A function host (Vercel, Netlify, Lambda) starts a fresh process for an
// invocation and may run several of them at once. Two processes on one auth key
// make Telegram deactivate the session ("concurrent usage"), and that kills the
// session for every host that shares it.
function isServerless() {
  return !!(
    process.env.VERCEL ||
    process.env.VERCEL_ENV ||
    process.env.NETLIFY ||
    process.env.AWS_LAMBDA_FUNCTION_NAME ||
    process.env.AWS_EXECUTION_ENV
  );
}

let _clientLock = Promise.resolve();
async function getClient() {
  if (_client && _client.isConnected && _client.isConnected()) return _client;
  // A function host owns its own connection exactly like a persistent one, so a
  // visitor's request never depends on another process being awake. That makes
  // the session string per host: one string used by two live hosts makes
  // Telegram deactivate it ("concurrent usage"), which then breaks every host
  // sharing it. A host told to forward (TG_GATEWAY_URL in server.js) never
  // reaches this function — the proxy answers before any route runs.
  // Lock: prevent multiple clients being created with same session (causes "Concurrent usage" error)
  const prevLock = _clientLock;
  let resolveLock;
  _clientLock = new Promise((r) => { resolveLock = r; });
  try {
    // Wait for previous lock to resolve
    await prevLock;
    // Re-check after acquiring lock (another request may have already created the client)
    if (_client && _client.isConnected && _client.isConnected()) return _client;
    const { apiId, apiHash } = cfg();
    const session = new StringSession(process.env.TELEGRAM_SESSION || "");
    _client = new TelegramClient(session, apiId, apiHash, {
      connectionRetries: 5,
      maxConcurrentDownloads: 4,
      downloadPool: { poolSize: 2, workers: 4, requestDeadlineMs: 60000 },
    });
    await _client.connect();
    if (!(await _client.isUserAuthorized())) {
      throw new Error("Telegram session not authorized — run `npm run login` and set TELEGRAM_SESSION");
    }
    return _client;
  } finally {
    resolveLock();
  }
}

// ================= PERSISTENT RUNTIME (one socket, kept warm) =================
// A long-running host (Botkeep, a VPS, a worker dyno) pays for the MTProto
// handshake exactly once and reuses that socket for every request. Serverless
// hosts (Vercel/Netlify) must never start this: their process is torn down
// between invocations, so a timer would either be lost or pin the invocation.
// Nothing here changes getClient() — it stays lazy, which is what makes the
// serverless path keep working untouched.
let _keepAlive = null;
let _tickBusy = false;

function clientConnected() {
  return !!(_client && typeof _client.isConnected === "function" && _client.isConnected());
}

// One cheap RPC proves the socket is still usable. isConnected() alone keeps
// answering "true" on a socket Telegram has already dropped, which surfaced as a
// hung request instead of a reconnect.
async function ping() {
  const client = await getClient();
  await client.getMe();
  return true;
}

async function dropClient() {
  const dead = _client;
  _client = null;
  if (!dead) return;
  try { await dead.disconnect(); } catch { /* socket already gone */ }
}

// Keep the session alive and self-heal: a failed ping drops the socket, so the
// next tick (or the next request) reconnects from TELEGRAM_SESSION. The saved
// session string means this never needs a new login code.
function startKeepAlive(intervalMs = 60000) {
  if (_keepAlive) return _keepAlive;
  const tick = async () => {
    if (_tickBusy) return;
    _tickBusy = true;
    try {
      await ping();
    } catch (e) {
      console.error("[mtproto] keepalive failed, reconnecting:", e && e.message);
      await dropClient();
    } finally {
      _tickBusy = false;
    }
  };
  _keepAlive = setInterval(tick, Math.max(15000, intervalMs));
  if (typeof _keepAlive.unref === "function") _keepAlive.unref();
  tick(); // connect now, not after the first interval
  return _keepAlive;
}

function stopKeepAlive() {
  if (!_keepAlive) return;
  clearInterval(_keepAlive);
  _keepAlive = null;
}

// Boot helper for a persistent host: connect once, then keep it warm.
async function warmUp() {
  await getClient();
  startKeepAlive(Number(process.env.MTPROTO_KEEPALIVE_MS) || 60000);
}

// Shutdown helper: stop the keepalive before closing the socket.
async function shutdown() {
  stopKeepAlive();
  await dropClient();
}

// ================= LOGIN (web, two-step, no 2FA) =================
async function sendLoginCode(phoneNumber) {
  const { apiId, apiHash } = cfg();
  const client = new TelegramClient(new StringSession(""), apiId, apiHash, { connectionRetries: 3 });
  await client.connect();
  await client.invoke(new Api.auth.SendCode({
    phoneNumber,
    settings: new Api.CodeSettings(),
  }));
  await client.disconnect();
  return true;
}

async function verifyLogin(phoneNumber, phoneCode) {
  const { apiId, apiHash } = cfg();
  const session = new StringSession("");
  const client = new TelegramClient(session, apiId, apiHash, { connectionRetries: 3 });
  await client.connect();
  try {
    await client.invoke(new Api.auth.SignIn({ phoneNumber, phoneCode }));
  } catch (err) {
    if (err.errorType === "SESSION_PASSWORD_NEEDED" || err.errorCode === 400 && /password/i.test(String(err.message))) {
      throw new Error("2FA is enabled on this account. Use `npm run login` locally to log in with your 2FA password, then paste the session string.");
    }
    throw err;
  }
  const me = await client.getMe();
  const saved = client.session.save();
  await client.disconnect();
  return { session: saved, user: { id: String(me.id), username: me.username || "", firstName: me.firstName || "" } };
}

// ================= UTIL: fileReference base64 =================
function refToBase64(ref) {
  if (!ref) return "";
  if (Buffer.isBuffer(ref)) return ref.toString("base64");
  if (typeof ref === "string") return ref;
  return Buffer.from(ref).toString("base64");
}
function refFromBase64(b64) {
  return Buffer.from(b64 || "", "base64");
}

// ================= UPLOAD =================
// Sends the video to the channel AS A DOCUMENT (forceDocument) so Telegram stores
// the exact bytes (no transcoding / compression). The thumbnail is sent separately
// as a photo so we can serve it independently and durably.
async function uploadVideo(filePath, { caption, thumbPath }) {
  const client = await getClient();
  const channel = channelVideo();

  // 1) upload the video document (with thumbnail attached) — VIDEO channel
  const msg = await client.sendFile(channel, {
    file: filePath,
    caption: caption || "",
    forceDocument: true,
    thumb: thumbPath,
    workers: 4,
  });

  const doc = msg.document;
  if (!doc) throw new Error("Telegram did not return a document — check channel/permissions");

  const videoLoc = {
    tg_doc_id: String(doc.id),
    tg_access_hash: String(doc.accessHash),
    tg_file_ref: refToBase64(doc.fileReference),
    tg_size: Number(doc.size),
    tg_mime: doc.mimeType || "",
    tg_channel: channel,
    tg_msg_id: msg.id,
  };

  // 2) upload the thumbnail as a separate photo — THUMBNAIL channel
  let thumbLoc = null;
  if (thumbPath) {
    const thumbChannel = channelThumb();
    const tmsg = await client.sendFile(thumbChannel, {
      file: thumbPath,
      forceDocument: false,
      workers: 2,
    });
    const photo = tmsg.photo;
    if (photo) {
      thumbLoc = {
        thumb_doc_id: String(photo.id),
        thumb_access_hash: String(photo.accessHash),
        thumb_file_ref: refToBase64(photo.fileReference),
        thumb_size: Number(photo.size),
        thumb_msg_id: tmsg.id,
      };
    }
  }

  return { ...videoLoc, ...thumbLoc };
}

// ================= UPLOAD THUMBNAIL (custom) =================
// Uploads a custom thumbnail image to the channel as a photo.
async function uploadThumbnail(imagePath) {
  const client = await getClient();
  const channel = channelThumb();

  const msg = await client.sendFile(channel, {
    file: imagePath,
    forceDocument: false,     // send as a photo
    workers: 2,
  });
  const photo = msg.photo;
  if (!photo) throw new Error("Telegram did not return a photo");
  return {
    thumb_doc_id: String(photo.id),
    thumb_access_hash: String(photo.accessHash),
    thumb_file_ref: refToBase64(photo.fileReference),
    thumb_size: Number(photo.size),
    thumb_msg_id: msg.id,
  };
}

// ================= STREAMING (HTTP range) =================
// Telegram stores files as 512 KB parts. GetFile limit is 512KB per call.
// We align to 512KB boundaries, fetch in parallel (8 at once = 4MB in flight),
// and trim head/tail to return exact browser-requested bytes.
const PART = 512 * 1024; // 512 KB — Telegram's actual part size & GetFile limit

function makeDocLocation(docId, accessHash, fileRef, thumbSize = "") {
  return new Api.InputDocumentFileLocation({
    id: BigInt(docId),
    accessHash: BigInt(accessHash),
    fileReference: refFromBase64(fileRef),
    thumbSize,
  });
}

function makePhotoLocation(docId, accessHash, fileRef, thumbSize = "") {
  return new Api.InputPhotoFileLocation({
    id: BigInt(docId),
    accessHash: BigInt(accessHash),
    fileReference: refFromBase64(fileRef),
    thumbSize,
  });
}

// Refresh expired file reference for a document (video).
// This teleproto build has no messages.getDocument, so the message itself is
// re-fetched and its document (with a fresh file_reference) is used.
async function refreshDocFileRef(docId, accessHash, msgId) {
  const client = await getClient();
  if (msgId) {
    const messages = await client.getMessages(channelVideo(), { ids: [Number(msgId)] });
    const msg = Array.isArray(messages) ? messages[0] : messages;
    const doc = msg && (msg.document || (msg.media && msg.media.document));
    if (doc && doc.fileReference) return Buffer.from(doc.fileReference);
  }
  return null;
}

// Refresh expired file reference for a photo (thumbnail)
async function refreshPhotoFileRef(photoId, accessHash, fileRef) {
  const client = await getClient();
  const locations = await client.invoke(new Api.photos.GetPhotoFileLocations({
    id: new Api.InputPhoto({
      id: BigInt(photoId),
      accessHash: BigInt(accessHash),
      fileReference: refFromBase64(fileRef),
    }),
  }));
  if (locations.locations && locations.locations.length > 0) {
    return locations.locations[0].fileReference;
  }
  return null;
}

// fetch a single 512 KB part from Telegram (GetFile limit is 512KB)
async function getFileChunk(client, video, offset, limit) {
  const fetchPart = () => client.invoke(new Api.upload.GetFile({
    location: makeDocLocation(video.tg_doc_id, video.tg_access_hash, video.tg_file_ref, ""),
    offset: BigInt(offset),
    limit,
    precise: false,
    cdnSupported: false,
  }));

  try {
    const res = await fetchPart();
    return res.bytes || Buffer.alloc(0);
  } catch (e) {
    // Stored file references expire (Telegram rotates them). Re-fetch the
    // message once and retry instead of failing the stream.
    if (!/file reference/i.test(String(e && e.message)) || !video.tg_msg_id) throw e;
    const fresh = await refreshDocFileRef(video.tg_doc_id, video.tg_access_hash, video.tg_msg_id);
    if (!fresh) throw e;
    video.tg_file_ref = fresh.toString("base64");
    console.log("[getFileChunk] refreshed expired file ref");
    const res = await fetchPart();
    return res.bytes || Buffer.alloc(0);
  }
}

// Stream a byte range [start, end] as an async generator of Buffers.
// - Aligns to 512KB Telegram part boundaries
// - Fetches up to 8 chunks in parallel (ordered queue, no skipping)
// - Trims head/tail based on actual bytes returned from Telegram
// - Handles EOF correctly (last chunk may be shorter than PART)
async function* streamRange(video, start, end) {
  const client = await getClient();
  const fileSize = video.tg_size;
  if (start >= fileSize) return;
  end = Math.min(end, fileSize - 1);

  // For small files (< 50MB), use downloadMedia to avoid file ref expiration
  if (fileSize < 50 * 1024 * 1024 && video.tg_msg_id) {
    try {
      const videoChannel = channelVideo();
      const messages = await client.getMessages(videoChannel, { ids: [video.tg_msg_id] });
      if (messages && messages.length > 0) {
        const msg = messages[0];
        const bytes = await client.downloadMedia(msg, { outputFile: null });
        if (bytes && bytes.length > 0) {
          const chunk = bytes.subarray(start, end + 1);
          yield chunk;
          return;
        }
      }
    } catch (e) {
      console.log("[streamRange] downloadMedia failed:", e.message);
    }
  }

  // Fallback: use upload.GetFile with refreshed file reference
  try {
    const freshRef = await refreshDocFileRef(video.tg_doc_id, video.tg_access_hash, video.tg_msg_id);
    if (freshRef) {
      video.tg_file_ref = refToBase64(freshRef);
      console.log("[streamRange] refreshed video file ref");
    }
  } catch (e) {
    console.log("[streamRange] file ref refresh failed:", e.message);
  }

  // Align to 512KB part boundaries — but clamp to fileSize
  const alignedStart = Math.floor(start / PART) * PART;
  const alignedEnd = Math.min(
    Math.ceil((end + 1) / PART) * PART - 1,
    Math.ceil(fileSize / PART) * PART - 1  // don't go past EOF
  );
  let headDiscard = start - alignedStart;

  // Build part list — ALWAYS request full PART (512KB) from Telegram.
  // Telegram GetFile rejects limits < PART (e.g. limit=13). We trim to exact
  // requested bytes after receiving the full part.
  const parts = [];
  for (let offset = alignedStart; offset <= alignedEnd; offset += PART) {
    parts.push({ offset, limit: PART }); // always full part — no small limits
  }

  // Ordered queue: fetch in batches of 8, yield in order
  const POOL = 8;
  let results = [];
  let bytesRemaining = end - start + 1; // total bytes we need to yield

  for (let i = 0; i < parts.length; i += POOL) {
    const batch = parts.slice(i, i + POOL);
    // Fetch all in parallel
    const batchResults = await Promise.all(
      batch.map((p) => getFileChunk(client, video, p.offset, p.limit))
    );
    results = results.concat(batchResults);

    // Yield results in order — trim each part to not exceed bytesRemaining
    for (let j = 0; j < results.length; j++) {
      let bytes = results[j];

      // Handle short responses — Telegram may return fewer bytes for last part
      // If short response AND not the last part, log warning but continue
      if (bytes.length < PART && bytes.length === 0) {
        continue; // skip empty
      }

      // Trim head from first part
      if (headDiscard > 0) {
        const skip = Math.min(headDiscard, bytes.length);
        bytes = bytes.subarray(skip);
        headDiscard -= skip;
      }

      // Trim tail — only yield what we still need
      // After head trim, bytes start at 'start' for first part, or at part.offset for others
      // But we track via bytesRemaining — simpler and correct
      if (bytes.length > bytesRemaining) {
        bytes = bytes.subarray(0, bytesRemaining);
      }

      if (bytes.length > 0) {
        bytesRemaining -= bytes.length;
        yield bytes;
      }

      if (bytesRemaining <= 0) break;
    }

    if (bytesRemaining <= 0) break;
    results = [];
  }
}

// ---- Fast-start: find moov atom by reading the end of the file ----
// Returns { offset, size, data } if moov is at the end, null if moov is at the beginning (or not found)
async function findMoovAtom(video) {
  const client = await getClient();
  const fileSize = video.tg_size;

  // Read last 4 parts (2MB), aligned to 512KB
  const numParts = 4;
  const alignedStart = Math.floor(Math.max(0, fileSize - numParts * PART) / PART) * PART;

  // Fetch parts in parallel
  const promises = [];
  for (let offset = alignedStart; offset < fileSize; offset += PART) {
    // Telegram only accepts limits that are multiples of 4096.
    const limit = Math.min(PART, Math.ceil((fileSize - offset) / 4096) * 4096);
    promises.push(getFileChunk(client, video, offset, limit));
  }
  const parts = await Promise.all(promises);
  const combined = Buffer.concat(parts);

  // Parse atoms from the combined buffer
  let offset = 0;
  while (offset < combined.length - 8) {
    try {
      const size = combined.readUInt32BE(offset);
      const type = combined.toString("ascii", offset + 4, offset + 8);

      if (type === "moov") {
        return {
          offset: alignedStart + offset,
          size: size,
          data: combined.subarray(offset, offset + size),
        };
      }

      if (size === 0 || size < 8) break;
      offset += size;
    } catch {
      break;
    }
  }

  return null; // moov not at end — probably at beginning (fast-start already OK)
}

// ---- Read a specific byte range from the file ----
async function readFileRange(video, start, end) {
  const client = await getClient();
  const alignedStart = Math.floor(start / PART) * PART;
  // Telegram rejects limits that are not a multiple of 4096, so ask for a
  // part-aligned window and trim afterwards.
  const limit = Math.min(PART, Math.ceil((end - alignedStart + 1) / 4096) * 4096);
  const bytes = await getFileChunk(client, video, alignedStart, limit);
  let headDiscard = start - alignedStart;
  let result = bytes.subarray(headDiscard);
  const neededEnd = end - alignedStart;
  if (neededEnd < result.length) {
    result = result.subarray(0, neededEnd + 1);
  }
  return result;
}

// thumbnail (separate photo) — fetch whole small image using downloadMedia (no file ref needed)
async function fetchThumbnail(video) {
  if (!video.thumb_msg_id) {
    // Fallback: try file-based approach
    if (!video.thumb_doc_id) return null;
    const client = await getClient();
    try {
      const freshRef = await refreshPhotoFileRef(video.thumb_doc_id, video.thumb_access_hash, video.thumb_file_ref);
      if (freshRef) video.thumb_file_ref = refToBase64(freshRef);
    } catch {}
    try {
      const loc = makePhotoLocation(video.thumb_doc_id, video.thumb_access_hash, video.thumb_file_ref, "");
      const res = await client.invoke(new Api.upload.GetFile({
        location: loc,
        offset: BigInt(0),
        limit: PART,
        precise: false,
        cdnSupported: false,
      }));
      return res.bytes || null;
    } catch {}
    return null;
  }

  // Primary: use downloadMedia with message ID (no file ref expiration issue)
  const client = await getClient();
  const thumbChannel = channelThumb();
  try {
    const messages = await client.getMessages(thumbChannel, { ids: [video.thumb_msg_id] });
    if (!messages || messages.length === 0) return null;
    const msg = messages[0];
    const bytes = await client.downloadMedia(msg, { outputFile: null });
    return bytes || null;
  } catch (e) {
    console.log("[fetchThumbnail] downloadMedia failed:", e.message);
    return null;
  }
}

// ================= METADATA STORAGE (in Telegram) =================
// Each video's metadata is stored as a JSON text message in the same channel.
// SQLite is a fast cache; Telegram is the durable source of truth.
// Format of the JSON: { t:"meta", id, title, description, views, likes, shares,
//   duration, createdAt, video:{...}, thumbnail:{...} }

function buildMetadata(v) {
  return {
    t: "meta",
    id: v.id,
    title: v.title,
    description: (v.description || "").slice(0, 1000),
    views: v.views || 0,
    likes: v.likes || 0,
    shares: v.shares || 0,
    likedBy: v.likedBy || [],
    duration: v.duration || 0,
    createdAt: v.created_at || Math.floor(Date.now() / 1000),
    trailer: v.trailer || null,
    video: {
      docId: v.tg_doc_id,
      accessHash: v.tg_access_hash,
      fileRef: v.tg_file_ref,
      size: v.tg_size,
      mime: v.tg_mime,
      msgId: v.tg_msg_id,
      filename: v.tg_filename || "video.mp4",
    },
    thumbnail: v.thumb_doc_id ? {
      docId: v.thumb_doc_id,
      accessHash: v.thumb_access_hash,
      fileRef: v.thumb_file_ref,
      size: v.thumb_size,
      msgId: v.thumb_msg_id || null,
    } : null,
  };
}

// A third-party video has no file of ours: this record only remembers the
// public facts a client sent, so the same looked-up shape (id, title, poster,
// link) can be returned for a like or a save without touching our catalogue.
function buildExternalMetadata(v) {
  return {
    t: "meta",
    id: v.id,
    external: true,
    source: v.source,
    title: v.title,
    description: v.description || "",
    pageUrl: v.pageUrl || "",
    embedUrl: v.embedUrl || "",
    thumbnailUrl: v.thumbnailUrl,
    duration: v.duration || 0,
    views: 0,
    likes: 0,
    shares: 0,
    likedBy: [],
    createdAt: Math.floor(Date.now() / 1000),
    video: {},
    thumbnail: null,
  };
}

// Send metadata as a text message. Returns the Telegram message id.
async function sendMetadata(metadata) {
  const client = await getClient();
  const channel = channelMeta();
  const msg = await client.sendMessage(channel, { message: JSON.stringify(metadata) });
  return msg.id;
}

// Edit an existing metadata message with fresh data.
async function editMetadata(msgId, metadata) {
  const client = await getClient();
  const channel = channelMeta();
  await client.editMessage(channel, { message: msgId, text: JSON.stringify(metadata) });
}

// Read metadata messages from the META channel (for sync / rebuild).
// Returns array of { ...metadata, _msgId: Telegram message id }.
// Reads BOTH text messages AND file messages (JSON files).
async function readMetadataFromTelegram(limit = 5000) {
  const client = await getClient();
  const channel = channelMeta();
  const result = [];
  for await (const msg of client.iterMessages(channel, { limit })) {
    let data = null;

    // Try text message first
    if (msg.message) {
      try {
        data = JSON.parse(msg.message);
      } catch { /* not JSON — skip */ }
    }

    // If no text, try file message (JSON file)
    if (!data && msg.document && msg.document.fileName && msg.document.fileName.endsWith(".json")) {
      try {
        const bytes = await client.downloadMedia(msg);
        const text = bytes.toString("utf-8");
        data = JSON.parse(text);
      } catch (e) {
        console.error(`readMetadataFromTelegram: failed to read JSON file from msg ${msg.id}:`, e.message);
      }
    }

    if (data && data.t === "meta") {
      result.push({ ...data, _msgId: msg.id });
    }
  }
  return result;
}

// Find a specific video's metadata message by id. Scans messages for the matching JSON.
// Reads BOTH text messages AND file messages (JSON files).
async function findVideoById(videoId) {
  const client = await getClient();
  const channel = channelMeta();
  for await (const msg of client.iterMessages(channel, { limit: 5000 })) {
    let data = null;

    if (msg.message) {
      try { data = JSON.parse(msg.message); } catch { /* skip */ }
    }

    if (!data && msg.document && msg.document.fileName && msg.document.fileName.endsWith(".json")) {
      try {
        const bytes = await client.downloadMedia(msg);
        data = JSON.parse(bytes.toString("utf-8"));
      } catch { /* skip */ }
    }

    if (data && data.t === "meta" && data.id === videoId) {
      return { ...data, _msgId: msg.id };
    }
  }
  return null;
}

// Update stats on an existing metadata message (edit in place).
// `updates` is a partial object to merge into the metadata.
async function updateVideoStats(videoId, updates) {
  const msg = await findVideoById(videoId);
  if (!msg) return null;
  const data = { ...msg };
  Object.assign(data, updates);
  await editMetadata(msg._msgId, data);
  return data;
}

// Delete all 3 messages for a video from 3 separate channels.
async function deleteVideoMessages(videoId) {
  const client = await getClient();

  const msg = await findVideoById(videoId);
  if (!msg) return { deleted: false };

  const results = { deleted: true, channels: {} };

  // delete metadata from META channel
  try { await client.deleteMessages(channelMeta(), [msg._msgId]); results.channels.meta = true; } catch {}
  // delete video from VIDEO channel
  if (msg.video && msg.video.msgId) {
    try { await client.deleteMessages(channelVideo(), [msg.video.msgId]); results.channels.video = true; } catch {}
  }
  // delete thumbnail from THUMBNAIL channel
  if (msg.thumbnail && msg.thumbnail.msgId) {
    try { await client.deleteMessages(channelThumb(), [msg.thumbnail.msgId]); results.channels.thumb = true; } catch {}
  }

  return results;
}

// Get direct Telegram CDN URL for a video (for redirect streaming on serverless platforms).
// Requires TELEGRAM_CHANNEL_PUBLIC env var (the VIDEO channel's public username, e.g. "intimora_videos").
// Returns null if not set (fallback to server streaming).
function getDirectUrl(video) {
  const publicChannel = process.env.TELEGRAM_CHANNEL_PUBLIC;
  if (!publicChannel) return null;
  const filename = video.video?.filename || "video.mp4";
  const msgId = video.video?.msgId;
  if (!msgId) return null;
  return `https://t.me/${publicChannel}/${msgId}/${filename}`;
}

// Sync one video's metadata to Telegram (reads DB row, edits the message).
async function syncMetadataToTelegram(video) {
  if (!video || !video.meta_msg_id) return;
  const metadata = buildMetadata(video);
  await editMetadata(video.meta_msg_id, metadata);
}

// ---- Generic message functions (for any channel) ----
async function sendMessage(channel, text) {
  const client = await getClient();
  const msg = await client.sendMessage(channel, {
    message: text,
    parseMode: false,
  });
  return msg.id;
}

async function editMessage(channel, msgId, text) {
  const client = await getClient();
  await client.editMessage(channel, {
    message: msgId,
    text,
    parseMode: false,
  });
}

async function readMessages(channel, limit = 5000) {
  const client = await getClient();
  const result = [];
  for await (const msg of client.iterMessages(channel, { limit })) {
    if (msg.message) {
      result.push({ id: msg.id, text: msg.message });
    }
  }
  return result;
}

module.exports = {
  isServerless,
  channelPremium,
  channelAccounts,
  getClient,
  clientConnected,
  ping,
  dropClient,
  warmUp,
  startKeepAlive,
  stopKeepAlive,
  shutdown,
  sendLoginCode,
  verifyLogin,
  uploadVideo,
  uploadThumbnail,
  streamRange,
  findMoovAtom,
  readFileRange,
  fetchThumbnail,
  buildMetadata,
  buildExternalMetadata,
  sendMetadata,
  editMetadata,
  readMetadataFromTelegram,
  findVideoById,
  updateVideoStats,
  deleteVideoMessages,
  channelUsers,
  channelReferrals,
  sendMessage,
  editMessage,
  readMessages,
  getDirectUrl,
  syncMetadataToTelegram,
  refToBase64,
};
