// Telegram-based data store — replaces SQLite entirely.
// All video metadata is stored as JSON text messages in Telegram.
// No database, no SQLite — works on any serverless platform (Netlify, Vercel, etc.)
const tg = require("./telegram");

// ---- UUID generator ----
function newId() {
  return require("crypto").randomUUID();
}

// ---- Convert Telegram metadata to stream format (for tg.streamRange / tg.fetchThumbnail) ----
function toStreamFormat(m) {
  return {
    tg_doc_id: m.video.docId || m.video.tg_doc_id,
    tg_access_hash: m.video.accessHash || m.video.tg_access_hash,
    tg_file_ref: m.video.fileRef || m.video.tg_file_ref,
    tg_size: m.video.size || m.video.tg_size,
    tg_mime: m.video.mime || m.video.tg_mime || "",
    tg_msg_id: m.video.msgId || m.video.tg_msg_id,
    thumb_doc_id: m.thumbnail ? (m.thumbnail.docId || m.thumbnail.thumb_doc_id) : "",
    thumb_access_hash: m.thumbnail ? (m.thumbnail.accessHash || m.thumbnail.thumb_access_hash) : "",
    thumb_file_ref: m.thumbnail ? (m.thumbnail.fileRef || m.thumbnail.thumb_file_ref) : "",
    thumb_size: m.thumbnail ? (m.thumbnail.size || m.thumbnail.thumb_size) : 0,
    thumb_msg_id: m.thumbnail ? (m.thumbnail.msgId || m.thumbnail.thumb_msg_id) : null,
  };
}

// ---- Metadata cache ----
// Reading the catalogue used to walk the whole META channel (up to 5000
// messages) inside the live request: on a free instance that is ~90 s, far past
// the gateway's timeout, so the visitor got a 500 instead of the list. One copy
// is kept per process and served instantly. After the TTL the copy is still
// served while it refreshes behind the request (stale-while-revalidate), and
// concurrent requests share a single channel read. A write drops or patches the
// copy, so an upload, a delete or a like is visible at once.
const METADATA_TTL_MS = Math.max(1000, Number(process.env.METADATA_CACHE_MS) || 60000);
let metaCache = null; // { at, metas }
let metaInflight = null;

function refreshMetas() {
  if (!metaInflight) {
    metaInflight = tg.readMetadataFromTelegram(5000)
      .then((metas) => {
        metaCache = { at: Date.now(), metas };
        return metas;
      })
      .catch((e) => {
        // A slightly old catalogue beats an error page.
        if (!metaCache) throw e;
        console.error("metadata refresh failed, serving cached copy:", e.message);
        return metaCache.metas;
      })
      .finally(() => { metaInflight = null; });
  }
  return metaInflight;
}

async function readMetas() {
  if (!metaCache) return (await refreshMetas()).slice();
  if (Date.now() - metaCache.at > METADATA_TTL_MS) refreshMetas().catch(() => {});
  return metaCache.metas.slice();
}

// Keep the cached copy in step with a stats edit instead of dropping it: a view
// or a like must not make the next visitor pay for a full channel read.
function patchMeta(id, updates) {
  const record = metaCache && metaCache.metas.find((m) => m.id === id);
  if (record) Object.assign(record, updates);
}

function invalidateMetas() { metaCache = null; }

// ---- List videos (infinite scroll) ----
// Reads the cached metadata, sorts by createdAt desc, paginates by cursor.
async function listVideos(cursor, limit = 12) {
  // Third-party records exist only so a user can like/save them; they are not
  // part of our catalogue and must never appear in a listing.
  const metas = (await readMetas()).filter(m => m.external !== true);
  metas.sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
  if (cursor) {
    const idx = metas.findIndex(m => (m.createdAt || 0) <= cursor);
    const start = idx === -1 ? 0 : idx;
    return metas.slice(start, start + limit);
  }
  return metas.slice(0, limit);
}

// ---- Get single video by id ----
async function getVideo(id) {
  return await tg.findVideoById(id);
}

// ---- Search videos by title ----
async function searchVideos(query) {
  const metas = (await readMetas()).filter(m => m.external !== true);
  const q = query.toLowerCase().trim();
  return metas.filter(m => (m.title || "").toLowerCase().includes(q));
}

// ---- Third-party (external) videos ----
// A video hosted elsewhere had no record at all, so a like or a save was stored
// on the user but the account could never show it again. The first like/save
// writes one small `external` metadata record, so the same store, the same
// liked/saved lists and the same lookup work for it. Every field is bounded and
// only the client's public facts are kept; no Telegram file is involved.
const EXTERNAL_ID = /^[A-Za-z0-9_-]{1,128}$/;

function externalText(value, max) {
  if (typeof value !== "string") return "";
  return value.replace(/[\u0000-\u001f\u007f]+/g, " ").replace(/\s+/g, " ").trim().slice(0, max);
}

function externalUrl(value) {
  if (typeof value !== "string" || value.length > 2048) return "";
  try {
    const url = new URL(value.trim());
    if (url.protocol !== "https:" || url.username || url.password) return "";
    return url.href;
  } catch {
    return "";
  }
}

function externalRecord(id, data) {
  const videoId = typeof id === "string" ? id : "";
  if (!EXTERNAL_ID.test(videoId) || !data || typeof data !== "object") return null;
  const title = externalText(data.title, 300);
  const thumbnailUrl = externalUrl(data.thumbnailUrl || data.thumbnail);
  if (!title || !thumbnailUrl) return null;
  const source = externalText(data.source, 32).toLowerCase().replace(/[^a-z0-9-]/g, "");
  return tg.buildExternalMetadata({
    id: videoId,
    source: source || "external",
    title,
    description: externalText(data.description, 1000),
    pageUrl: externalUrl(data.pageUrl),
    embedUrl: externalUrl(data.embedUrl),
    thumbnailUrl,
    duration: Number.isFinite(Number(data.duration)) ? Math.max(0, Math.trunc(Number(data.duration))) : 0,
  });
}

// A real upload with the same id always wins, and an already registered id is
// reused so a second user's like writes nothing new. `findVideoById` walks the
// channel, so a warm isolate remembers what it already resolved.
const externalSeen = new Set();

async function registerExternalVideo(id, data) {
  if (externalSeen.has(id)) return null;
  const existing = await tg.findVideoById(id);
  if (existing) {
    if (existing.external === true) externalSeen.add(id);
    return existing.external === true ? existing : null;
  }
  const metadata = externalRecord(id, data);
  if (!metadata) return null;
  const msgId = await tg.sendMetadata(metadata);
  externalSeen.add(id);
  invalidateMetas();
  return { ...metadata, _msgId: msgId };
}

// ---- Add video (send metadata to Telegram) ----
async function addVideo(data) {
  const metadata = tg.buildMetadata(data);
  const msgId = await tg.sendMetadata(metadata);
  invalidateMetas();
  return { ...metadata, meta_msg_id: msgId };
}

// ---- Increment views ----
async function incrementViews(id) {
  const v = await tg.findVideoById(id);
  if (!v) return null;
  const updated = await tg.updateVideoStats(id, { views: (v.views || 0) + 1 });
  if (updated) patchMeta(id, { views: updated.views });
  return updated;
}

// ---- Toggle like / unlike ----
async function toggleLike(id, userId) {
  const v = await tg.findVideoById(id);
  if (!v) return null;
  const likedBy = v.likedBy || [];
  if (likedBy.includes(userId)) {
    const result = await tg.updateVideoStats(id, {
      likedBy: likedBy.filter(u => u !== userId),
      likes: Math.max(0, (v.likes || 0) - 1),
    });
    patchMeta(id, { likedBy: result.likedBy, likes: result.likes });
    return { liked: false, likes: result.likes };
  } else {
    const result = await tg.updateVideoStats(id, {
      likedBy: [...likedBy, userId],
      likes: (v.likes || 0) + 1,
    });
    patchMeta(id, { likedBy: result.likedBy, likes: result.likes });
    return { liked: true, likes: result.likes };
  }
}

// ---- Increment shares ----
async function incrementShares(id) {
  const v = await tg.findVideoById(id);
  if (!v) return null;
  const updated = await tg.updateVideoStats(id, { shares: (v.shares || 0) + 1 });
  if (updated) patchMeta(id, { shares: updated.shares });
  return updated;
}

// ---- Delete video (deletes all 3 messages from Telegram) ----
async function deleteVideo(id) {
  const result = await tg.deleteVideoMessages(id);
  if (result && result.deleted) invalidateMetas();
  return result;
}

// ---- Get all videos (admin list) ----
async function listAllVideos() {
  const metas = await readMetas();
  metas.sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
  return metas;
}

module.exports = {
  newId,
  toStreamFormat,
  listVideos,
  getVideo,
  searchVideos,
  addVideo,
  registerExternalVideo,
  incrementViews,
  toggleLike,
  incrementShares,
  deleteVideo,
  listAllVideos,
};
