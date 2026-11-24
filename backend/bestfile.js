// BestFile helper for the Vercel/Node backend.
// Resolves a BestFile page link (https://bestfile.io/<id>/file) to the signed
// Backblaze B2 URL that actually serves the bytes.
//
// Flow: GET /en/<id>/file (CSRF token + session cookie) ->
//   POST /<id>/download/create (JSON download_link) -> follow redirects -> B2.
// There is no sign-in step: /en/login is behind a bot challenge and answers 403,
// while the file page itself issues everything the create call needs.

const https = require("https");
const http = require("http");

const BF_HOST = "bestfile.io";
const BF_UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36";

// Every network call is bounded: a serverless request must never hang on BestFile.
const REQ_TIMEOUT_MS = 8000;
// A resolved link is reused for a few minutes: the signed URL itself lives an
// hour, so a page of videos is not re-resolved on every load. The stream route
// asks BestFile again for each play.
const CDN_TTL_MS = 5 * 60 * 1000;

const cdnCache = new Map(); // pageId -> { url, exp }

function cookieHeader(jar) {
  return Object.entries(jar).map(([k, v]) => k + "=" + v).join("; ");
}

function storeCookies(jar, setCookie) {
  if (!setCookie) return jar;
  const list = Array.isArray(setCookie) ? setCookie : [setCookie];
  for (const c of list) {
    const m = c.match(/^([^=]+)=([^;]*)/);
    if (m) jar[m[1].trim()] = m[2];
  }
  return jar;
}

function csrfFrom(html) {
  const m = (html || "").match(/csrf-token" content="([^"]+)"/);
  return m ? m[1] : "";
}

// Raw request to any host. Always resolves/rejects within REQ_TIMEOUT_MS.
function rawRequest(urlStr, { method = "GET", headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const mod = u.protocol === "http:" ? http : https;
    const finalHeaders = { "User-Agent": BF_UA, ...headers };
    if (body) finalHeaders["Content-Length"] = Buffer.byteLength(body);

    const req = mod.request(
      {
        hostname: u.hostname,
        path: u.pathname + u.search,
        method,
        headers: finalHeaders,
        timeout: REQ_TIMEOUT_MS,
      },
      (res) => {
        const chunks = [];
        res.on("data", (d) => chunks.push(d));
        res.on("end", () =>
          resolve({
            status: res.statusCode,
            headers: res.headers,
            body: Buffer.concat(chunks).toString("utf-8"),
          })
        );
      }
    );
    req.on("timeout", () => req.destroy(new Error("BestFile request timed out (" + urlStr + ")")));
    req.on("error", reject);
    if (body) req.end(body);
    else req.end();
  });
}

// Follow the BestFile redirect chain (bestfile.io -> Backblaze B2) and return the
// first URL that actually serves bytes. Node's https does not follow redirects.
async function bfResolveFinalUrl(link, jar, maxHops = 6) {
  let url = link;
  for (let i = 0; i < maxHops; i++) {
    const headers = { Range: "bytes=0-0" };
    const cookie = cookieHeader(jar);
    if (cookie) headers.Cookie = cookie;
    const r = await rawRequest(url, { method: "GET", headers });
    const loc = r.headers && r.headers.location ? String(r.headers.location) : "";
    if (r.status >= 300 && r.status < 400 && loc) {
      url = new URL(loc, url).toString();
      continue;
    }
    if (r.status === 200 || r.status === 206) return url;
    return null;
  }
  return null;
}

// The page's own session: its CSRF token and the cookies that go with it.
async function bfPageSession(pageId) {
  const jar = {};
  const r = await rawRequest(`https://${BF_HOST}/en/${encodeURIComponent(pageId)}/file`, {
    headers: { Accept: "text/html" },
  });
  storeCookies(jar, r.headers["set-cookie"]);
  const csrf = csrfFrom(r.body);
  if (r.status !== 200 || !csrf) return null;
  return { jar, csrf };
}

async function bfCreateLink(pageId, { jar, csrf }) {
  const r = await rawRequest(`https://${BF_HOST}/${encodeURIComponent(pageId)}/download/create`, {
    method: "POST",
    headers: {
      Cookie: cookieHeader(jar),
      "X-CSRF-TOKEN": csrf,
      "X-Requested-With": "XMLHttpRequest",
      Accept: "application/json",
      Referer: `https://${BF_HOST}/en/${encodeURIComponent(pageId)}/file`,
    },
  });
  storeCookies(jar, r.headers["set-cookie"]);
  if (r.status !== 200) return null;
  try {
    const data = JSON.parse(r.body);
    return data && typeof data.download_link === "string" ? data.download_link : null;
  } catch {
    return null;
  }
}

// Get a fresh CDN (B2) signed URL for a BestFile download_link (page URL).
// Returns the direct Backblaze B2 URL, or null on failure — never throws and
// never holds a request open longer than the total budget below.
const TOTAL_BUDGET_MS = 20000;

// `fresh` skips the in-memory cache, so the caller gets a link signed for this
// request instead of one issued earlier (the signed file URL expires).
async function bfGetCdnUrl(downloadLink, { fresh = false } = {}) {
  let timer;
  const budget = new Promise((resolve) => {
    timer = setTimeout(() => resolve(null), TOTAL_BUDGET_MS);
  });
  try {
    return await Promise.race([bfGetCdnUrlInner(downloadLink, fresh), budget]);
  } finally {
    clearTimeout(timer);
  }
}

async function bfGetCdnUrlInner(downloadLink, fresh = false) {
  try {
    if (typeof downloadLink !== "string") return null;
    const m = downloadLink.match(
      new RegExp("https?://" + BF_HOST.replace(/\./g, "\\.") + "/([^/]+)/file", "i")
    );
    if (!m) return null;
    const pageId = m[1];

    const cached = cdnCache.get(pageId);
    if (!fresh && cached && cached.exp > Date.now()) return cached.url;

    const session = await bfPageSession(pageId);
    if (!session) return null;

    let link = await bfCreateLink(pageId, session);
    if (!link) {
      // A stale page session answers 419; one fresh page is enough to retry.
      const retry = await bfPageSession(pageId);
      if (!retry) return null;
      link = await bfCreateLink(pageId, retry);
      if (!link) return null;
      session.jar = retry.jar;
    }

    const finalUrl = await bfResolveFinalUrl(link, session.jar);
    if (!finalUrl) return null;

    cdnCache.set(pageId, { url: finalUrl, exp: Date.now() + CDN_TTL_MS });
    return finalUrl;
  } catch (e) {
    console.error("bfGetCdnUrl error:", e.message);
    return null;
  }
}

module.exports = { bfGetCdnUrl };
