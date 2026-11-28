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
      const next = new URL(loc, url).toString();
      // A location on the storage host is already the signed direct link; no
      // point downloading a byte of it just to learn the address.
      if (!next.includes(BF_HOST)) return next;
      url = next;
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

// Resolving one page costs three BestFile round trips, and a listing asks for the
// same pages again on the next request. The signed B2 link keeps working for a
// while, so it is remembered for a short window and concurrent requests for the
// same page share one resolution instead of repeating it.
const CDN_TTL_MS = Math.max(1000, Number(process.env.BESTFILE_CDN_TTL_MS) || 120000);
const CDN_MAX_ENTRIES = 500;
const cdnCache = new Map(); // pageId -> { url, at }
const cdnInflight = new Map(); // pageId -> Promise<string|null>

function pageIdOf(downloadLink) {
  if (typeof downloadLink !== "string") return "";
  const m = downloadLink.match(
    new RegExp("https?://" + BF_HOST.replace(/\./g, "\\.") + "/([^/]+)/file", "i")
  );
  return m ? m[1] : "";
}

function rememberCdnUrl(pageId, url) {
  if (!pageId || !url) return;
  if (cdnCache.size >= CDN_MAX_ENTRIES) {
    // Oldest first: a Map keeps insertion order, and a re-set refreshes it.
    cdnCache.delete(cdnCache.keys().next().value);
  }
  cdnCache.set(pageId, { url, at: Date.now() });
}

// `fresh` skips the in-memory cache, so the caller gets a link signed for this
// request instead of one issued earlier (the signed file URL expires).
async function bfGetCdnUrl(downloadLink, { fresh = false } = {}) {
  const pageId = pageIdOf(downloadLink);
  if (!fresh && pageId) {
    const hit = cdnCache.get(pageId);
    if (hit && Date.now() - hit.at < CDN_TTL_MS) return hit.url;
    const pending = cdnInflight.get(pageId);
    if (pending) return pending;
  }

  let timer;
  const budget = new Promise((resolve) => {
    timer = setTimeout(() => resolve(null), TOTAL_BUDGET_MS);
  });
  const work = bfGetCdnUrlInner(downloadLink, fresh).then((url) => {
    rememberCdnUrl(pageId, url);
    return url;
  });
  if (pageId) cdnInflight.set(pageId, work);
  try {
    return await Promise.race([work, budget]);
  } finally {
    clearTimeout(timer);
    if (pageId) cdnInflight.delete(pageId);
  }
}

async function bfGetCdnUrlInner(downloadLink, fresh = false) {
  try {
    const pageId = pageIdOf(downloadLink);
    if (!pageId) return null;

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

    return await bfResolveFinalUrl(link, session.jar);
  } catch (e) {
    console.error("bfGetCdnUrl error:", e.message);
    return null;
  }
}

module.exports = { bfGetCdnUrl };
