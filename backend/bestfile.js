// BestFile helper for the Vercel/Node backend.
// Fetches a fresh CDN (Backblaze B2) signed URL for streaming.
// Verified flow: login (POST /en/login) -> refresh CSRF (GET /en) ->
//   POST /<id>/download/create -> follow redirects -> final B2 signed URL.
// Session cookies are kept in memory (Vercel serverless = ephemeral; cold start re-logs in).

const https = require("https");
const http = require("http");

const BF_HOST = "bestfile.io";
const BF_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36";
const BF_EMAIL = "pr3095079@gmail.com";
const BF_PASSWORD = "noida123";

// Every network call is bounded: a serverless request must never hang on BestFile.
const REQ_TIMEOUT_MS = 8000;
const CDN_TTL_MS = 45 * 60 * 1000; // B2 signed URLs are valid for 1 hour

let bfCookies = {};
let bfCsrf = "";
let bfLoggedIn = false;
const cdnCache = new Map(); // pageId -> { url, exp }

function bfCookieHeader() {
  return Object.entries(bfCookies).map(([k, v]) => k + "=" + v).join("; ");
}
function bfStoreCookies(setCookie) {
  if (!setCookie) return;
  const list = Array.isArray(setCookie) ? setCookie : [setCookie];
  for (const c of list) {
    const m = c.match(/^([^=]+)=([^;]*)/);
    if (m) bfCookies[m[1].trim()] = m[2];
  }
}
function bfCsrfFrom(html) {
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

// Request to bestfile.io with the current session cookies.
async function bfRequest(method, reqPath, { headers = {}, body = null } = {}) {
  const r = await rawRequest("https://" + BF_HOST + reqPath, {
    method,
    headers: { Cookie: bfCookieHeader(), ...headers },
    body,
  });
  bfStoreCookies(r.headers["set-cookie"]);
  return r;
}

async function bfLogin() {
  // Always start from a clean session: /en/login redirects to /user when already
  // signed in, which yields an empty CSRF token and a 419 on the POST.
  bfCookies = {};
  bfCsrf = "";
  bfLoggedIn = false;

  const r1 = await bfRequest("GET", "/en/login");
  const form = new URLSearchParams({
    _token: bfCsrfFrom(r1.body),
    email: BF_EMAIL,
    password: BF_PASSWORD,
  }).toString();

  const r2 = await bfRequest("POST", "/en/login", {
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Referer: "https://" + BF_HOST + "/en/login",
    },
    body: form,
  });

  // A successful login answers 302 -> /user (redirects are not followed here).
  const location = r2.headers && r2.headers.location ? String(r2.headers.location) : "";
  if (r2.status !== 302 && !location.includes("/user")) {
    throw new Error("BestFile login failed (HTTP " + r2.status + ") — check bestfile credentials.");
  }
  bfLoggedIn = true;

  const r3 = await bfRequest("GET", "/en");
  bfCsrf = bfCsrfFrom(r3.body);
  if (!bfCsrf) throw new Error("BestFile: CSRF token missing after login");
}

async function bfEnsureLogin() {
  if (!bfLoggedIn) return await bfLogin();
  const r = await bfRequest("GET", "/en");
  const csrf = bfCsrfFrom(r.body);
  if (csrf) bfCsrf = csrf;
  else await bfLogin();
}

// Follow the BestFile redirect chain (bestfile.io -> Backblaze B2) and return the
// first URL that actually serves bytes. Node's https does not follow redirects.
async function bfResolveFinalUrl(link, maxHops = 6) {
  let url = link;
  for (let i = 0; i < maxHops; i++) {
    const r = await rawRequest(url, {
      method: "GET",
      headers: { Cookie: bfCookieHeader(), Range: "bytes=0-0" },
    });
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

// Get a fresh CDN (B2) signed URL for a BestFile download_link (page URL).
// Returns the direct Backblaze B2 URL, or null on failure — never throws and
// never holds a request open longer than the total budget below.
const TOTAL_BUDGET_MS = 20000;

async function bfGetCdnUrl(downloadLink) {
  let timer;
  const budget = new Promise((resolve) => {
    timer = setTimeout(() => resolve(null), TOTAL_BUDGET_MS);
  });
  try {
    return await Promise.race([bfGetCdnUrlInner(downloadLink), budget]);
  } finally {
    clearTimeout(timer);
  }
}

async function bfGetCdnUrlInner(downloadLink) {
  try {
    if (typeof downloadLink !== "string") return null;
    const m = downloadLink.match(
      new RegExp("https?://" + BF_HOST.replace(/\./g, "\\.") + "/([^/]+)/file", "i")
    );
    if (!m) return null;
    const pageId = m[1];

    const cached = cdnCache.get(pageId);
    if (cached && cached.exp > Date.now()) return cached.url;

    const createLink = async () => {
      const r = await bfRequest("POST", "/" + pageId + "/download/create", {
        headers: {
          "X-CSRF-TOKEN": bfCsrf,
          Referer: "https://" + BF_HOST + "/en",
        },
      });
      if (r.status !== 200) return null;
      try {
        const data = JSON.parse(r.body);
        return data.download_link || null;
      } catch {
        return null;
      }
    };

    await bfEnsureLogin();

    let link = await createLink();
    if (!link) {
      // Session may have expired -> fresh login once and retry
      await bfLogin();
      link = await createLink();
    }
    if (!link) return null;

    const finalUrl = await bfResolveFinalUrl(link);
    if (!finalUrl) return null;

    cdnCache.set(pageId, { url: finalUrl, exp: Date.now() + CDN_TTL_MS });
    return finalUrl;
  } catch (e) {
    console.error("bfGetCdnUrl error:", e.message);
    return null;
  }
}

module.exports = { bfGetCdnUrl };
