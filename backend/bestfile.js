// BestFile helper for the Vercel/Node backend.
// Fetches a fresh CDN (Backblaze B2) signed URL for streaming.
// Verified flow: login (POST /en/login) -> refresh CSRF (GET /en) ->
//   POST /<id>/download/create -> follow redirects -> final B2 signed URL.
// Session cookies are kept in memory (Vercel serverless = ephemeral; cold start re-logs in).

const https = require("https");

const BF_HOST = "bestfile.io";
const BF_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36";
const BF_EMAIL = "pr3095079@gmail.com";
const BF_PASSWORD = "noida123";

let bfCookies = {};
let bfCsrf = "";
let bfLoggedIn = false;

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
  const m = html.match(/csrf-token" content="([^"]+)"/);
  return m ? m[1] : "";
}

// Low-level request. Returns { status, body, headers, effectiveUrl }.
function bfRequest(method, reqPath, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const req = https.request(
      {
        hostname: BF_HOST,
        path: reqPath,
        method,
        headers: {
          "User-Agent": BF_UA,
          Cookie: bfCookieHeader(),
          ...headers,
        },
      },
      (res) => {
        bfStoreCookies(res.headers["set-cookie"]);
        const chunks = [];
        res.on("data", (d) => chunks.push(d));
        res.on("end", () =>
          resolve({
            status: res.statusCode,
            headers: res.headers,
            body: Buffer.concat(chunks).toString("utf-8"),
            effectiveUrl: res.responseUrl || "https://" + BF_HOST + reqPath,
          })
        );
      }
    );
    req.on("error", reject);
    if (body) {
      req.setHeader("Content-Length", Buffer.byteLength(body));
      req.end(body);
    } else {
      req.end();
    }
  });
}

async function bfLogin() {
  bfCookies = {};
  const r1 = await bfRequest("GET", "/en/login");
  const csrf = bfCsrfFrom(r1.body);
  const form = new URLSearchParams({
    _token: csrf,
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
  if (r2.status !== 302) {
    throw new Error("BestFile login failed (HTTP " + r2.status + ") — check bestfile credentials.");
  }
  bfLoggedIn = true;
  const r3 = await bfRequest("GET", "/en");
  bfCsrf = bfCsrfFrom(r3.body);
}

async function bfEnsureLogin() {
  if (!bfLoggedIn) await bfLogin();
  else {
    const r = await bfRequest("GET", "/en");
    bfCsrf = bfCsrfFrom(r.body);
  }
}

// Get a fresh CDN (B2) signed URL for a BestFile download_link (page URL).
// Returns the direct Backblaze B2 URL, or null on failure.
async function bfGetCdnUrl(downloadLink) {
  const m = downloadLink.match(new RegExp("https?://" + BF_HOST.replace(/\./g, "\\.") + "/([^/]+)/file", "i"));
  if (!m) return null;
  const pageId = m[1];

  await bfEnsureLogin();

  const attempt = async () => {
    const r = await bfRequest("POST", "/" + pageId + "/download/create", {
      headers: {
        "X-CSRF-TOKEN": bfCsrf,
        Referer: "https://" + BF_HOST + "/en",
      },
    });
    if (r.status !== 200) return null;
    const data = JSON.parse(r.body);
    return data.download_link || null;
  };

  let link = await attempt();
  if (!link) {
    // Session may have expired -> re-login once and retry
    try {
      await bfLogin();
      link = await attempt();
    } catch (e) {
      return null;
    }
  }
  if (!link) return null;

  // Follow the redirect chain to the final B2 signed URL (fetch only 1 byte).
  return new Promise((resolve) => {
    const url = new URL(link);
    const req = https.request(
      {
        hostname: url.hostname,
        path: url.pathname + url.search,
        method: "GET",
        headers: {
          "User-Agent": BF_UA,
          Cookie: bfCookieHeader(),
          Range: "bytes=0-0",
        },
      },
      (res) => {
        // res.responseUrl holds the final URL after redirects
        const finalUrl = res.responseUrl || link;
        resolve(finalUrl);
        res.resume();
      }
    );
    req.on("error", () => resolve(link)); // fallback: return the link, client follows redirects
    req.end();
  });
}

module.exports = { bfGetCdnUrl };
