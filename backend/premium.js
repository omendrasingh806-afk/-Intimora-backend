// Intimora — Premium engine (referral based) for the Node/Vercel backend.
// Reads and writes the SAME Telegram ledger channel as the PHP backend, so both
// servers always agree. Nothing is stored in a database.
//
// Ledger record: {"t":"prem_period","id":"pp_x","uid":"...","source":"referral",
//   "hours":5,"seconds":18000,"refsUsed":["REF-A"],"amountPaise":0,
//   "startedAt":...,"endsAt":...,"at":...}
//   {"t":"prem_adjust","id":"pa_x","uid":"...","seconds":-18000,"note":"...","at":...}
//
// 1 unused referral = 2.5h. A referred user is spent only once (refsUsed is the
// idempotency key). Premium extends from max(now, current end).
//
// Stream links never time out: the same user+video+kind always gets the same
// signed link. A full-video link still stops when the premium end time carried
// inside it passes, so access follows the account and not the clock.

const crypto = require("crypto");
const tg = require("./telegram");
const users = require("./users");

const SECONDS_PER_REF = 9000; // 2h 30m
const LEDGER_TTL = 60 * 1000;
const SECRET = process.env.PREMIUM_SECRET || "im_prem_7c1f9a24db6e8053";

let ledgerCache = null;
let ledgerAt = 0;

function ledgerChannel() {
  return process.env.PREMIUM_CHANNEL || tg.channelPremium();
}

async function ledger(fresh = false) {
  if (!fresh && ledgerCache && Date.now() - ledgerAt < LEDGER_TTL) return ledgerCache;
  const messages = await tg.readMessages(ledgerChannel(), 5000);
  const out = [];
  for (const m of messages) {
    const text = m.text;
    if (!text || text[0] !== "{") continue;
    let data;
    try { data = JSON.parse(text); } catch { continue; }
    if (data.t === "prem_period" || data.t === "prem_adjust") out.push(data);
  }
  ledgerCache = out;
  ledgerAt = Date.now();
  return out;
}

async function writeLedger(entry) {
  const withTime = { ...entry, at: entry.at || Math.floor(Date.now() / 1000) };
  await tg.sendMessage(ledgerChannel(), JSON.stringify(withTime));
  ledgerCache = null;
  return withTime;
}

async function premiumUntil(uid, fresh = false) {
  uid = String(uid || "");
  if (!uid) return 0;
  let until = 0, adjust = 0;
  for (const e of await ledger(fresh)) {
    if (String(e.uid) !== uid) continue;
    if (e.t === "prem_period") until = Math.max(until, Number(e.endsAt) || 0);
    else if (e.t === "prem_adjust") adjust += Number(e.seconds) || 0;
  }
  return Math.max(0, until + adjust);
}

async function isActive(uid) {
  if (!uid) return false;
  return (await premiumUntil(uid)) > Math.floor(Date.now() / 1000);
}

async function referralSlots(uid) {
  uid = String(uid || "");
  const user = await users.findByUid(uid);
  if (!user || !user.referralCode) return { total: 0, used: 0, available: [], myCode: "" };

  const myCode = user.referralCode;
  const all = await users.readReferrals();
  const mine = new Map();
  for (const r of all) {
    if (String(r.ownerCode) !== myCode) continue;
    const code = String(r.referredCode || "");
    if (!code) continue;
    if (!mine.has(code)) mine.set(code, Number(r.timestamp) || 0);
  }

  const usedCodes = new Set();
  for (const e of await ledger()) {
    if (String(e.uid) !== uid || e.t !== "prem_period") continue;
    for (const c of e.refsUsed || []) usedCodes.add(String(c));
  }

  const ordered = [...mine.entries()].sort((a, b) => a[1] - b[1]).map(([c]) => c);
  const available = ordered.filter((c) => !usedCodes.has(c));

  return { total: mine.size, used: mine.size - available.length, available, myCode };
}

// Live premium state. `until` and `serverNow` are real UTC epoch seconds, and
// everything is derived on every read: nothing is stored as a running counter,
// so a closed window can never keep reporting time left. The client compares
// serverNow with its own clock to fix drift and trusts `active` from here only.
async function status(uid) {
  const until = Math.floor(await premiumUntil(uid));
  const now = Math.floor(Date.now() / 1000);
  const active = until > now;
  const slots = await referralSlots(uid);
  return {
    active,
    until: active ? until : 0,
    serverNow: now,
    remainingSeconds: active ? (until - now) : 0,
    referrals: {
      total: slots.total,
      used: slots.used,
      available: slots.available.length,
      availableCodes: slots.available,
      secondsEach: SECONDS_PER_REF,
      hoursEach: SECONDS_PER_REF / 3600,
    },
    referralCode: slots.myCode,
  };
}

async function history(uid) {
  uid = String(uid || "");
  const periods = [];
  let totalSeconds = 0, totalPaise = 0;
  const bySource = {};

  for (const e of await ledger()) {
    if (String(e.uid) !== uid) continue;
    if (e.t === "prem_period") {
      const seconds = Number(e.seconds) || 0;
      periods.push({
        id: e.id || "", source: e.source || "referral", hours: Number(e.hours) || 0,
        seconds, refsUsed: [...(e.refsUsed || [])], amountPaise: Number(e.amountPaise) || 0,
        startedAt: Number(e.startedAt) || 0, endsAt: Number(e.endsAt) || 0, at: Number(e.at) || 0,
      });
      if (seconds > 0) totalSeconds += seconds;
      totalPaise += Number(e.amountPaise) || 0;
      const src = e.source || "referral";
      bySource[src] = bySource[src] || { periods: 0, seconds: 0, amountPaise: 0 };
      bySource[src].periods++;
      bySource[src].seconds += seconds;
      bySource[src].amountPaise += Number(e.amountPaise) || 0;
    } else if (e.t === "prem_adjust") {
      const seconds = Number(e.seconds) || 0;
      periods.push({
        id: e.id || "", source: "adjustment", hours: Math.round((seconds / 3600) * 100) / 100,
        seconds, refsUsed: [], amountPaise: 0, startedAt: 0, endsAt: 0,
        note: e.note || "", at: Number(e.at) || 0,
      });
      totalSeconds += seconds;
    }
  }

  periods.sort((a, b) => (b.at || 0) - (a.at || 0));
  return {
    periods,
    total: {
      seconds: totalSeconds,
      hours: Math.round((totalSeconds / 3600) * 100) / 100,
      amountPaise: totalPaise,
      amountRupees: Math.round(totalPaise) / 100,
      bySource,
    },
  };
}

async function redeemReferrals(uid, count) {
  count = parseInt(count, 10) || 0;
  if (count < 1) return { ok: false, error: "Kam se kam 1 referral chahiye" };

  const user = await users.findByUid(uid);
  if (!user) return { ok: false, error: "User not found", code: 404 };

  const slots = await referralSlots(uid); // ledger read is cached only 60s
  const available = slots.available;
  if (!available.length) return { ok: false, error: "Koi referral available nahi hai" };
  if (count > available.length) return { ok: false, error: `Sirf ${available.length} referral available hain` };

  const chosen = available.slice(0, count);
  const seconds = count * SECONDS_PER_REF;
  const now = Math.floor(Date.now() / 1000);
  const start = Math.max(now, await premiumUntil(uid, true));

  const entry = await writeLedger({
    t: "prem_period", id: "pp_" + crypto.randomBytes(6).toString("hex"), uid: String(uid),
    source: "referral", hours: seconds / 3600, seconds, refsUsed: chosen,
    amountPaise: 0, startedAt: start, endsAt: start + seconds,
  });

  return { ok: true, entry, status: await status(uid) };
}

async function grant(uid, hours, source = "admin", amountPaise = 0, note = "") {
  const seconds = Math.round(Number(hours) * 3600);
  if (!uid || !seconds) return { ok: false, error: "uid and hours required" };
  const now = Math.floor(Date.now() / 1000);
  const start = Math.max(now, await premiumUntil(uid, true));

  const entry = await writeLedger({
    t: "prem_period", id: "pp_" + crypto.randomBytes(6).toString("hex"), uid: String(uid),
    source, hours: seconds / 3600, seconds, refsUsed: [], amountPaise: Number(amountPaise) || 0,
    note, startedAt: start, endsAt: Math.max(start, start + seconds),
  });
  return { ok: true, entry, status: await status(uid) };
}

// ---- signed stream links (same format as PHP) ----
// No issue/expiry stamp: the link is permanent, the premium end time inside it
// is what the stream route enforces for a full video.
function token(uid, videoId, kind = "video", premiumUntilValue = 0) {
  const payload = {
    u: String(uid || ""), v: String(videoId), k: kind === "trailer" ? "trailer" : "video",
    p: Number(premiumUntilValue) || 0,
  };
  const body = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const sig = crypto.createHmac("sha256", SECRET).update(body).digest("hex");
  return `${body}.${sig}`;
}

function checkToken(value, videoId, kind) {
  if (!value || typeof value !== "string" || !value.includes(".")) return null;
  const [body, sig] = value.split(".");
  const expect = crypto.createHmac("sha256", SECRET).update(body).digest("hex");
  const a = Buffer.from(String(sig || ""));
  const b = Buffer.from(expect);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  let payload;
  try { payload = JSON.parse(Buffer.from(body, "base64url").toString("utf8")); } catch { return null; }
  if (!payload) return null;
  if (String(payload.v) !== String(videoId)) return null;
  if (String(payload.k) !== (kind === "trailer" ? "trailer" : "video")) return null;
  return payload;
}

// ---- video shaping ----
function isPremiumVideo(v) {
  return v && (v.premium === true || v.premium === "true");
}

function hasTrailer(v) {
  const t = v && v.trailer;
  return !!(t && ((t.docId || t.tg_doc_id) || (t.bestfile && t.bestfile.download_link)));
}

async function shapeVideo(pub, video, uid) {
  const premium = isPremiumVideo(video);
  const trailer = hasTrailer(video);
  const id = String(video.id || "");

  pub.locked = false;
  pub.premium = premium;
  pub.hasTrailer = trailer;

  if (!premium) {
    // Public on purpose: a trailer needs no login and no token.
    if (trailer) pub.trailerUrl = `/api/videos/${encodeURIComponent(id)}/stream?kind=trailer`;
    return pub;
  }

  const active = uid ? await isActive(uid) : false;
  const until = active ? await premiumUntil(uid) : 0;

  // Never hand out a permanent BestFile link for premium content.
  pub.bestfile = { download_id: "", download_link: "", size: (video.bestfile && video.bestfile.size) || 0, };

  if (active) {
    pub.streamUrl = `/api/videos/${encodeURIComponent(id)}/stream?t=${token(uid, id, "video", until)}`;
  } else {
    pub.locked = true;
    delete pub.streamUrl;
  }

  if (trailer) {
    // Public on purpose: the trailer plays even when the account is locked.
    pub.trailerUrl = `/api/videos/${encodeURIComponent(id)}/stream?kind=trailer`;
  } else {
    delete pub.trailerUrl;
  }

  return pub;
}

module.exports = {
  SECONDS_PER_REF,
  ledger,
  premiumUntil,
  isActive,
  status,
  history,
  redeemReferrals,
  grant,
  token,
  checkToken,
  isPremiumVideo,
  hasTrailer,
  shapeVideo,
};
