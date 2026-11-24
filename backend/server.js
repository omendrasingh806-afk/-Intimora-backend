// Express server — backend only. Reads data from Telegram, streams videos.
// Admin panel uploads directly via MTProto (browser), sends metadata here.
// Compatible with Netlify (serverless-http) and local (node functions/server.js).
const express = require("express");
const path = require("path");
const serverless = require("serverless-http");

const store = require("./store");
const tg = require("./telegram");
const users = require("./users");
const bestfile = require("./bestfile");
const premium = require("./premium");
const phpProxy = require("./phpProxy");

// ---- Firebase Auth — token verification via REST API (no service account key needed) ----
const FIREBASE_API_KEY = process.env.FIREBASE_API_KEY;

async function verifyFirebaseToken(token) {
  if (!FIREBASE_API_KEY) throw new Error("FIREBASE_API_KEY not set");
  const res = await fetch(
    `https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=${FIREBASE_API_KEY}`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ idToken: token, maxResults: 1 }),
    }
  );
  const data = await res.json();
  if (data.error) throw new Error(data.error.message);
  if (!data.users || data.users.length === 0) throw new Error("Invalid token");
  return data.users[0];
}

// ---- Auth middleware — verify Firebase ID token ----
async function auth(req, res, next) {
  const header = req.headers.authorization;
  if (!header || !header.startsWith("Bearer ")) {
    return res.status(401).json({ ok: false, error: "No auth token" });
  }
  const token = header.split(" ")[1];
  try {
    const decoded = await verifyFirebaseToken(token);
    req.userId = decoded.localId;
    req.userEmail = decoded.email || "";
    next();
  } catch (e) {
    return res.status(401).json({ ok: false, error: "Invalid token" });
  }
}

// Optional auth: a valid Firebase token gives the uid, no token gives "".
async function optionalUid(req) {
  const header = req.headers.authorization || "";
  if (!header.startsWith("Bearer ")) return "";
  try {
    const decoded = await verifyFirebaseToken(header.split(" ")[1]);
    return decoded.localId || "";
  } catch { return ""; }
}

const app = express();
app.use(express.json({ limit: "10mb" }));
app.use(express.urlencoded({ extended: true }));

// ---- CORS middleware ----
// These endpoints carry no cookies (a caller proves itself with a Bearer token),
// so the answer echoes whatever origin asked — the same rule the PHP backend
// uses. A fixed allowlist silently broke every other frontend host (www, a
// preview domain), which the browser reports as a failed request.
app.use((req, res, next) => {
  const origin = req.headers.origin;
  res.set({
    "Access-Control-Allow-Origin": origin || "*",
    "Vary": "Origin",
    "Access-Control-Allow-Methods": "GET, POST, HEAD, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization, Range, If-Range",
    "Access-Control-Max-Age": "86400",
    "Access-Control-Expose-Headers": "Accept-Ranges, Content-Range, Content-Length, Content-Type, ETag",
  });
  // Handle OPTIONS preflight — empty 204, no HTML
  if (req.method === "OPTIONS") return res.status(204).end();
  next();
});

// ---- Path normalization for Netlify serverless ----
// When Netlify routes to this function, the path may be:
//   /api/health          (correct — from redirect rule)
//   /.netlify/functions/api/health  (when accessed directly)
// Normalize both to /api/* so Express routes match.
app.use((req, res, next) => {
  // Only normalize paths that are actually API calls
  if (req.url.startsWith('/.netlify/functions/api')) {
    req.url = req.url.replace(/^\/\.netlify\/functions\/api/, '/api');
  } else if (req.url.startsWith('/api')) {
    // already correct
  } else {
    // non-API path (e.g. /admin) — leave as-is
    return next();
  }
  next();
});

const PORT = parseInt(process.env.PORT, 10) || 10000;

// ---- helpers ----
function ok(res, data) { return res.json({ ok: true, data }); }
// Premium answers must never be frozen by a browser or a proxy: the state is
// decided from the clock on every call.
function noStore(res) {
  res.set("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0");
  res.set("Pragma", "no-cache");
  res.set("Expires", "0");
  return res;
}
function fail(res, msg, code = 400) { return res.status(code).json({ ok: false, error: msg }); }

// serialize video metadata for the public API
function publicVideo(v) {
  // A third-party record is played by its own source: it has no stream of ours
  // and its poster is the URL the client sent when it was first liked/saved.
  const external = v.external === true;
  return {
    id: v.id,
    title: v.title,
    description: v.description,
    exclusive: v.exclusive === true || v.exclusive === 'true',
    premium: v.premium === true || v.premium === 'true',
    size: v.video?.size,
    mime: v.video?.mime,
    thumbSize: v.thumbnail?.size,
    duration: v.duration,
    views: v.views,
    likes: v.likes,
    shares: v.shares,
    createdAt: v.createdAt,
    external,
    source: external ? (v.source || "") : "",
    pageUrl: external ? (v.pageUrl || "") : "",
    embedUrl: external ? (v.embedUrl || "") : "",
    streamUrl: external ? null : `/api/videos/${v.id}/stream`,
    thumbnailUrl: external ? (v.thumbnailUrl || "") : `/api/videos/${v.id}/thumbnail`,
    // The file's BestFile copy, so a client can link the CDN file directly
    // instead of streaming the bytes through this server. A premium video gets
    // this blanked by premium.shapeVideo; a trailer is public, so its link stays.
    bestfile: {
      download_id: (v.bestfile && v.bestfile.download_id) || "",
      download_link: (v.bestfile && v.bestfile.download_link) || "",
      size: (v.bestfile && Number(v.bestfile.size)) || 0,
    },
    trailerBestfile: (v.trailer && v.trailer.bestfile && v.trailer.bestfile.download_link) || "",
  };
}

// ---- admin auth ----
function adminAuth(req, res, next) {
  const key = req.headers["x-api-key"];
  if (!key || key !== process.env.ADMIN_API_KEY) return fail(res, "Unauthorized", 401);
  next();
}

// ============================================================
// PUBLIC API (no API key needed)
// ============================================================

app.get("/api/health", (req, res) => ok(res, { status: "up", time: Date.now() }));

// ---- PHP backend proxy ----
// The PHP servers sit behind a hosting-level browser challenge that blocks any
// non-browser client. phpProxy solves that cookie and forwards the request.
app.use("/api/php", async (req, res) => {
  try {
    const rest = req.originalUrl.replace(/^\/api\/php/, "") || "/api/health";
    const fwdHeaders = {};
    for (const h of ["authorization", "range", "if-range", "x-api-key", "x-admin-key"]) {
      if (req.headers[h]) fwdHeaders[h] = req.headers[h];
    }
    let body = null;
    if (req.method !== "GET" && req.method !== "HEAD") {
      body = Buffer.from(JSON.stringify(req.body || {}));
      fwdHeaders["content-type"] = "application/json";
      fwdHeaders["content-length"] = String(body.length);
    }
    const r = await phpProxy.phpFetch(rest, { method: req.method, headers: fwdHeaders, body });
    for (const h of ["content-type", "content-range", "accept-ranges", "location"]) {
      if (r.headers[h]) res.set(h, r.headers[h]);
    }
    if (!r.headers["content-range"] && !r.headers["location"]) {
      res.set("Content-Length", String(r.body.length));
    }
    res.status(r.status).end(r.body);
  } catch (e) {
    fail(res, e.message || "PHP backend unavailable", 502);
  }
});

// Infinite scroll — cursor-based pagination
app.get("/api/videos", async (req, res) => {
  try {
    const cursor = parseInt(req.query.cursor, 10) || 0;
    const limit = Math.min(parseInt(req.query.limit, 10) || 12, 50);
    const metas = await store.listVideos(cursor, limit);
    const uid = await optionalUid(req);
    const videos = await Promise.all(metas.map((m) => premium.shapeVideo(publicVideo(m), m, uid)));
    const nextCursor = videos.length ? metas[metas.length - 1].createdAt : null;
    ok(res, { videos, nextCursor });
  } catch (e) { fail(res, e.message, 500); }
});

// Search
app.get("/api/search", async (req, res) => {
  try {
    const q = (req.query.q || "").trim();
    if (!q) return ok(res, { videos: [], nextCursor: null });
    const limit = Math.min(parseInt(req.query.limit, 10) || 12, 50);
    const metas = await store.searchVideos(q);
    const uid = await optionalUid(req);
    const videos = await Promise.all(metas.slice(0, limit).map((m) => premium.shapeVideo(publicVideo(m), m, uid)));
    ok(res, { videos, nextCursor: null });
  } catch (e) { fail(res, e.message, 500); }
});

// Video detail (counts a view — directly edits Telegram metadata message)
app.get("/api/videos/:id", async (req, res) => {
  try {
    let v = await store.getVideo(req.params.id);
    if (!v) return fail(res, "Video not found", 404);
    // A third-party record has no view count of ours to edit.
    if (v.external !== true) {
      v = await store.incrementViews(req.params.id);
      if (!v) return fail(res, "Video not found", 404);
    }
    ok(res, await premium.shapeVideo(publicVideo(v), v, await optionalUid(req)));
  } catch (e) { fail(res, e.message, 500); }
});

// Thumbnail — fetch from Telegram, serve as image
app.get("/api/videos/:id/thumbnail", async (req, res) => {
  try {
    const v = await store.getVideo(req.params.id);
    if (!v) return fail(res, "Video not found", 404);
    // Its poster lives on the other host and is already in thumbnailUrl.
    if (v.external === true) return fail(res, "No thumbnail", 404);
    if (!v.thumbnail) return fail(res, "No thumbnail", 404);
    const streamFormat = store.toStreamFormat(v);
    const img = await tg.fetchThumbnail(streamFormat);
    if (!img) return fail(res, "No thumbnail", 404);
    res.set("Content-Type", "image/jpeg");
    res.set("Cache-Control", "public, max-age=86400");
    res.set("Cross-Origin-Resource-Policy", "cross-origin");
    res.send(img);
  } catch (e) {
    fail(res, e.message, 500);
  }
});

// Stream video — server-side MTProto streaming with HTTP Range support
// Handles: bytes=0-1023, bytes=1024-, bytes=-1024 (suffix), clamps to fileSize
app.get("/api/videos/:id/stream", async (req, res) => {
  try {
    const v = await store.getVideo(req.params.id);
    if (!v) return fail(res, "Video not found", 404);
    // We never host a third-party file, so there is nothing to stream.
    if (v.external === true) return fail(res, "Video is hosted by its source", 404);

    // Premium videos are never served from a bare URL: a signed token is
    // required. The link never expires; for a full video the premium end time
    // carried inside the token decides whether it may play.
    const kind = req.query.kind === "trailer" ? "trailer" : "video";
    if (kind === "trailer") {
      // A trailer is public: it plays logged in or logged out, with a token or
      // without one. A premium video only has to actually have a trailer.
      if (premium.isPremiumVideo(v) && !premium.hasTrailer(v)) return fail(res, "No trailer for this video", 404);
    } else if (premium.isPremiumVideo(v)) {
      // A full video is never served from a bare URL: the signed token says
      // which account asked, and the ledger decides right now whether that
      // account's premium is running.
      const payload = premium.checkToken(req.query.t, req.params.id, kind);
      if (!payload) return fail(res, "Invalid link — reload the page", 403);
      if (!(await premium.isActive(payload.u))) return fail(res, "Premium required", 403);
    }
    const source = kind === "trailer" ? (v.trailer || {}) : v;
    const bf = kind === "trailer" ? (source.bestfile || null) : v.bestfile;

    // --- BestFile first: if the file has a BestFile link, redirect to its CDN ---
    if (bf && bf.download_link) {
      let cdnUrl = null;
      try {
        cdnUrl = await bestfile.bfGetCdnUrl(bf.download_link);
      } catch (e) {
        console.error("bestfile cdn error:", e.message);
      }
      if (cdnUrl) {
        // Express does not close a response that only sets status + headers,
        // so the redirect must be ended explicitly (otherwise the request hangs).
        res.status(302);
        res.set("Location", cdnUrl);
        res.set("Cache-Control", "no-store");
        return res.end();
      }
      // If CDN URL fetch fails, fall through to Telegram MTProto streaming below
    }

    // BestFile-only videos have no Telegram copy to fall back to.
    const hasTgSource = kind === "trailer" ? !!(v.trailer && v.trailer.tg_doc_id) : !!v.video;
    if (!hasTgSource) return fail(res, "No playable source for this video", 404);

    const streamFormat = kind === "trailer"
      ? {
          tg_doc_id: v.trailer.tg_doc_id, tg_access_hash: v.trailer.tg_access_hash, tg_file_ref: v.trailer.tg_file_ref,
          tg_size: v.trailer.tg_size, tg_mime: v.trailer.tg_mime || "video/mp4", tg_msg_id: v.trailer.tg_msg_id,
        }
      : store.toStreamFormat(v);
    const fileSize = streamFormat.tg_size;
    let start = 0, end = fileSize - 1, status = 200;
    const OPEN_RANGE_CHUNK = 8 * 1024 * 1024; // 8 MB per open-ended response

    const range = req.headers.range;
    if (range) {
      const m = /bytes=(\d*)-(\d*)/.exec(range);
      if (m) {
        if (m[1]) {
          // bytes=start-end or bytes=start-
          start = parseInt(m[1], 10);
          end = m[2] ? parseInt(m[2], 10) : fileSize - 1;
          if (!m[2]) {
            // Open-ended range (the player asks "bytes=0-"): answer with one
            // capped chunk and let the player ask for the next one. A whole
            // 300 MB file cannot be pushed through a 60 s serverless limit.
            end = Math.min(end, start + OPEN_RANGE_CHUNK - 1);
          }
        } else if (m[2]) {
          // bytes=-suffix → last N bytes
          const suffix = parseInt(m[2], 10);
          start = Math.max(0, fileSize - suffix);
          end = fileSize - 1;
        }
        // Clamp end to fileSize
        end = Math.min(end, fileSize - 1);
        status = 206;
      }
    }
    if (start >= fileSize || start > end) {
      return res.status(416).set({
        "Content-Range": `bytes */${fileSize}`,
        "Accept-Ranges": "bytes",
      }).json({ ok: false, error: "Range not satisfiable" });
    }

    // ---- Fast-start optimization ----
    // If browser requests from offset 0 and moov is at the END of the file,
    // send ftyp + moov first so browser can start playing immediately.
    // If moov is already at the beginning, findMoovAtom returns null → normal streaming.
    if (start === 0) {
      try {
        const moov = await tg.findMoovAtom(streamFormat);
        if (moov) {
          // moov is at the end — send ftyp + moov as 206 partial response
          // Browser needs 206 (not 200) so it knows to request mdat chunks after
          const ftypData = await tg.readFileRange(streamFormat, 0, 31);
          const totalBytes = ftypData.length + moov.data.length;

          res.status(206);
          res.set({
            "Content-Type": "video/mp4",
            "Accept-Ranges": "bytes",
            "Content-Length": totalBytes,
            "Content-Range": `bytes 0-${totalBytes - 1}/${fileSize}`,
            "Cache-Control": "public, max-age=3600",
            "Vary": "Origin, Range",
            "Cross-Origin-Resource-Policy": "cross-origin",
          });

          res.write(ftypData);
          res.write(moov.data);
          return res.end();
        }
      } catch (e) {
        console.log("[fast-start] moov search failed:", e.message);
      }
    }

    res.status(status);
    res.set({
      "Content-Type": streamFormat.tg_mime || "video/mp4",
      "Accept-Ranges": "bytes",
      "Content-Length": end - start + 1,
      "Content-Disposition": "inline",
      "Cache-Control": "public, max-age=3600",
      "Vary": "Origin, Range",
      "Cross-Origin-Resource-Policy": "cross-origin",
    });
    if (status === 206) res.set("Content-Range", `bytes ${start}-${end}/${fileSize}`);

    // Send the headers now: a serverless host kills the invocation if the
    // first byte takes too long, and the player should know the stream started.
    if (typeof res.flushHeaders === "function") res.flushHeaders();

    for await (const chunk of tg.streamRange(streamFormat, start, end)) {
      if (!res.write(chunk)) {
        await new Promise((r) => res.once("drain", r));
      }
    }
    res.end();
  } catch (e) {
    console.error("stream error:", e.message);
    // Content-Length is already promised, so ending cleanly would leave the
    // client waiting for the rest of the file. Abort the response instead.
    try { res.destroy(e); } catch {}
  }
});

// Record a share — edits Telegram metadata message directly
app.post("/api/videos/:id/share", async (req, res) => {
  try {
    const v = await store.incrementShares(req.params.id);
    if (!v) return fail(res, "Video not found", 404);
    ok(res, { shares: v.shares });
  } catch (e) { fail(res, e.message, 500); }
});

// ============================================================
// USER SYSTEM — Firebase Auth + Telegram storage
// ============================================================

// Verify Firebase token — returns user if exists
app.post("/api/auth/verify", auth, async (req, res) => {
  try {
    const user = await users.findByUid(req.userId);
    if (!user) {
      return ok(res, { exists: false, email: req.userEmail, uid: req.userId });
    }
    ok(res, { exists: true, user });
  } catch (e) { fail(res, e.message, 500); }
});

// Complete profile — new user only
app.post("/api/auth/profile", auth, async (req, res) => {
  try {
    const { name, username, referralCode, avatar } = req.body;
    if (!name || !username) return fail(res, "Name and username required", 400);

    // Check if user already exists
    const existing = await users.findByUid(req.userId);
    if (existing) return fail(res, "User already exists", 400);

    // Check username availability
    const usernameTaken = await users.findByUsername(username);
    if (usernameTaken) return fail(res, "Username already exists", 400);

    // Validate referral code if provided
    let referredBy = "";
    if (referralCode && referralCode.trim()) {
      const referrer = await users.findByReferralCode(referralCode.trim());
      if (!referrer) return fail(res, "Invalid referral code", 400);
      referredBy = referrer.referralCode;
    }

    const user = await users.createUser({
      email: req.userEmail,
      name,
      username,
      uid: req.userId,
      avatar: avatar || "",
      referredBy,
    });

    ok(res, { user: { id: user.id, name: user.name, username: user.username, referralCode: user.referralCode } });
  } catch (e) { fail(res, e.message, 500); }
});

// Get current user
app.get("/api/auth/me", auth, async (req, res) => {
  try {
    const user = await users.findByUid(req.userId);
    if (!user) return fail(res, "User not found", 404);
    // The ids live in the like/save collection channel; the account response
    // still carries them, so a client never needs to know that.
    ok(res, { user: await users.withCollections(user) });
  } catch (e) { fail(res, e.message, 500); }
});

// Like / unlike video (authenticated)
app.post("/api/videos/:id/like", auth, async (req, res) => {
  try {
    // A third-party video is remembered the first time it is liked, so the
    // account's liked list can show it again. The id is always the URL's; the
    // body only supplies its public facts, and is ignored for our own videos.
    if (req.body && typeof req.body.video === "object" && req.body.video !== null) {
      await store.registerExternalVideo(req.params.id, req.body.video);
    }
    const result = await users.toggleLike(req.userId, req.params.id);
    if (!result) return fail(res, "User not found", 404);
    ok(res, result);
  } catch (e) { fail(res, e.message, 500); }
});

// Save / unsave video (authenticated)
app.post("/api/videos/:id/save", auth, async (req, res) => {
  try {
    if (req.body && typeof req.body.video === "object" && req.body.video !== null) {
      await store.registerExternalVideo(req.params.id, req.body.video);
    }
    const result = await users.toggleSave(req.userId, req.params.id);
    if (!result) return fail(res, "User not found", 404);
    ok(res, result);
  } catch (e) { fail(res, e.message, 500); }
});

// Get user's liked videos
app.get("/api/user/liked", auth, async (req, res) => {
  try {
    const likedIds = await users.getLikedVideos(req.userId);
    if (!likedIds) return fail(res, "User not found", 404);
    const uid = await optionalUid(req);
    const videos = [];
    for (const id of likedIds) {
      const v = await store.getVideo(id);
      // Shaped like every other video, so a third-party entry says where it is
      // played (embedUrl/pageUrl) instead of claiming a stream of ours.
      if (v) videos.push(await premium.shapeVideo(publicVideo(v), v, uid));
    }
    ok(res, { videos });
  } catch (e) { fail(res, e.message, 500); }
});

// Get user's saved videos
app.get("/api/user/saved", auth, async (req, res) => {
  try {
    const savedIds = await users.getSavedVideos(req.userId);
    if (!savedIds) return fail(res, "User not found", 404);
    const uid = await optionalUid(req);
    const videos = [];
    for (const id of savedIds) {
      const v = await store.getVideo(id);
      if (v) videos.push(await premium.shapeVideo(publicVideo(v), v, uid));
    }
    ok(res, { videos });
  } catch (e) { fail(res, e.message, 500); }
});

// Get referral tree
app.get("/api/user/referrals", auth, async (req, res) => {
  try {
    const tree = await users.getReferralTree(req.userId);
    if (!tree) return fail(res, "User not found", 404);
    ok(res, { tree });
  } catch (e) { fail(res, e.message, 500); }
});

// ============================================================
// PREMIUM ROUTES (referral based)
// ============================================================

app.get("/api/premium/status", auth, async (req, res) => {
  noStore(res);
  try { ok(res, await premium.status(req.userId)); } catch (e) { fail(res, e.message, 500); }
});

app.get("/api/premium/history", auth, async (req, res) => {
  noStore(res);
  try {
    const status = await premium.status(req.userId);
    const h = await premium.history(req.userId);
    h.status = status;
    ok(res, h);
  } catch (e) { fail(res, e.message, 500); }
});

// Spend referrals: {count: 1} → 2.5h, 2 → 5h, 4 → 10h
app.post("/api/premium/redeem", auth, async (req, res) => {
  noStore(res);
  try {
    const result = await premium.redeemReferrals(req.userId, req.body.count || 1);
    if (!result.ok) return fail(res, result.error, result.code || 400);
    ok(res, { status: result.status, entry: result.entry });
  } catch (e) { fail(res, e.message, 500); }
});

app.post("/api/admin/premium/grant", adminAuth, async (req, res) => {
  noStore(res);
  try {
    const r = await premium.grant(req.body.uid, req.body.hours, req.body.source || "admin",
      req.body.amountPaise || 0, req.body.note || "");
    if (!r.ok) return fail(res, r.error);
    ok(res, r);
  } catch (e) { fail(res, e.message, 500); }
});

app.get("/api/admin/premium", adminAuth, async (req, res) => {
  noStore(res);
  try {
    const uid = String(req.query.uid || "");
    if (!uid) return fail(res, "uid required");
    ok(res, { status: await premium.status(uid), history: await premium.history(uid) });
  } catch (e) { fail(res, e.message, 500); }
});

// ============================================================
// ADMIN API (x-api-key required)
// ============================================================

// Receive metadata from admin panel (admin uploads directly via MTProto in browser)
// Stores metadata as JSON message in Telegram metadata channel
app.post("/api/admin/metadata", adminAuth, async (req, res) => {
  try {
    const metadata = req.body;
    if (!metadata || !metadata.id) {
      return fail(res, "Missing required fields (id)");
    }
    const hasTgVideo = !!(metadata.video && (metadata.video.tg_doc_id || metadata.video.docId));
    const bfLink = metadata.bestfile && metadata.bestfile.download_link;
    if (!hasTgVideo && !bfLink) {
      return fail(res, "Missing video (Telegram) or bestfile.download_link");
    }

    // Normalize field names from admin panel format (tg_doc_id → docId etc.)
    const normalized = {
      t: "meta",
      id: metadata.id,
      title: metadata.title || "Untitled",
      description: (metadata.description || "").slice(0, 1000),
      views: metadata.views || 0,
      likes: metadata.likes || 0,
      shares: metadata.shares || 0,
      likedBy: metadata.likedBy || [],
      duration: metadata.duration || 0,
      createdAt: metadata.createdAt || Math.floor(Date.now() / 1000),
    };
    if (metadata.targets) normalized.targets = metadata.targets;

    if (hasTgVideo) {
      normalized.video = {
        docId: metadata.video.tg_doc_id || metadata.video.docId,
        accessHash: metadata.video.tg_access_hash || metadata.video.accessHash,
        fileRef: metadata.video.tg_file_ref || metadata.video.fileRef,
        size: metadata.video.tg_size || metadata.video.size,
        mime: metadata.video.tg_mime || metadata.video.mime || "video/mp4",
        msgId: metadata.video.tg_msg_id || metadata.video.msgId,
        filename: metadata.video.tg_filename || metadata.video.filename || "video.mp4",
      };
    }

    if (metadata.thumbnail) {
      normalized.thumbnail = {
        docId: metadata.thumbnail.thumb_doc_id || metadata.thumbnail.docId,
        accessHash: metadata.thumbnail.thumb_access_hash || metadata.thumbnail.accessHash,
        fileRef: metadata.thumbnail.thumb_file_ref || metadata.thumbnail.fileRef,
        size: metadata.thumbnail.thumb_size || metadata.thumbnail.size,
        msgId: metadata.thumbnail.thumb_msg_id || metadata.thumbnail.msgId,
      };
    }

    // BestFile video-only uploads: the B2-backed link lives inside the metadata.
    if (bfLink) {
      normalized.bestfile = {
        download_id: metadata.bestfile.download_id || "",
        download_link: metadata.bestfile.download_link,
        size: metadata.bestfile.size || 0,
      };
    }

    const metaMsgId = await tg.sendMetadata(normalized);
    ok(res, { id: metadata.id, meta_msg_id: metaMsgId });
  } catch (e) {
    console.error("metadata save error:", e);
    fail(res, e.message || "Failed to save metadata", 500);
  }
});

// Admin: list all videos
app.get("/api/admin/videos", adminAuth, async (req, res) => {
  try {
    const metas = await store.listAllVideos();
    ok(res, metas.map(publicVideo));
  } catch (e) { fail(res, e.message, 500); }
});

// Admin: delete video (deletes all 3 messages from Telegram)
app.post("/api/admin/videos/:id/delete", adminAuth, async (req, res) => {
  try {
    const result = await store.deleteVideo(req.params.id);
    if (!result.deleted) return fail(res, "Video not found", 404);
    ok(res, { deleted: true });
  } catch (e) { fail(res, e.message, 500); }
});

// ============================================================
// STATIC — Admin panel only (no frontend)
// ============================================================
// JSON error handler
app.use((err, req, res, next) => {
  if (err) return fail(res, err.message || "Internal error", 400);
  next();
});

// Admin panel at /admin
app.get("/admin", (req, res) => {
  res.sendFile(path.join(__dirname, "..", "public", "admin.html"));
});

app.use(express.static(path.join(__dirname, "..", "public")));

// ---- Serverless handler + local listen ----
module.exports = app;
module.exports.handler = serverless(app); // Netlify compatibility

if (require.main === module) {
  app.listen(PORT, "0.0.0.0", () => {
    console.log(`Backend running on port ${PORT}`);
    console.log(`Video channel:     ${process.env.TELEGRAM_CHANNEL_VIDEO || "(not set)"}`);
    console.log(`Thumbnail channel: ${process.env.TELEGRAM_CHANNEL_THUMB || "(not set)"}`);
    console.log(`Metadata channel:  ${process.env.TELEGRAM_CHANNEL_META || "(not set)"}`);
  });
}
