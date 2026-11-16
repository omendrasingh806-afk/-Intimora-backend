// PHP backend proxy with InfinityFree "browser security" handling.
//
// InfinityFree answers any non-browser request with a small HTML page that
// computes a __test cookie in JavaScript (slowAES.decrypt(c, CBC, key, iv)) and
// reloads the URL. That cookie is account-wide and valid for 6 hours, so the
// same value can be computed here with node:crypto instead of a browser.
//
// Usage:  const r = await phpFetch("/api/videos?limit=12", { method: "GET" });

const https = require("https");
const crypto = require("crypto");

const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

const DEFAULT_HOST = (process.env.PHP_BACKEND_URL || "https://intimora-api3.site.je").replace(/\/+$/, "");
const COOKIE_TTL_MS = 5.5 * 60 * 60 * 1000; // challenge sets max-age=21600 (6h)

let cookie = { host: "", value: "", exp: 0 };

function request(urlStr, { method = "GET", headers = {}, body = null, timeout = 20000 } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const mod = u.protocol === "http:" ? require("http") : https;
    const req = mod.request(
      {
        hostname: u.hostname,
        path: u.pathname + u.search,
        method,
        headers,
        timeout,
      },
      (res) => {
        const chunks = [];
        res.on("data", (d) => chunks.push(d));
        res.on("end", () =>
          resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) })
        );
      }
    );
    req.on("timeout", () => req.destroy(new Error("PHP proxy timeout")));
    req.on("error", reject);
    if (body) req.end(body);
    else req.end();
  });
}

function isChallenge(res) {
  const ct = String(res.headers["content-type"] || "");
  if (!ct.includes("text/html")) return false;
  return res.body.slice(0, 800).toString("utf-8").includes("aes.js");
}

// Mirror of the page's toHex(slowAES.decrypt(c, 2, a, b)).
function solveChallenge(html) {
  const m = html.match(
    /toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/
  );
  if (!m) return null;
  const key = Buffer.from(m[1], "hex");
  const iv = Buffer.from(m[2], "hex");
  const data = Buffer.from(m[3], "hex");
  const d = crypto.createDecipheriv("aes-128-cbc", key, iv);
  d.setAutoPadding(false); // slowAES does not validate padding
  return Buffer.concat([d.update(data), d.final()]).toString("hex");
}

async function refreshCookie(origin) {
  const r = await request(origin + "/api/health", { headers: { "User-Agent": UA, Accept: "*/*" } });
  const token = solveChallenge(r.body.toString("utf-8"));
  if (!token) throw new Error("Could not solve the hosting security challenge");
  cookie = { host: origin, value: token, exp: Date.now() + COOKIE_TTL_MS };
  return token;
}

async function phpFetch(pathQuery, { method = "GET", headers = {}, body = null, origin = DEFAULT_HOST } = {}) {
  const url = origin + (pathQuery.startsWith("/") ? pathQuery : "/" + pathQuery);
  const outHeaders = { "User-Agent": UA, Accept: "*/*", ...headers };

  if (!cookie.value || cookie.exp < Date.now() || cookie.host !== origin) {
    await refreshCookie(origin);
  }

  outHeaders.Cookie = "__test=" + cookie.value;
  let res = await request(url, { method, headers: outHeaders, body });

  if (isChallenge(res)) {
    await refreshCookie(origin);
    outHeaders.Cookie = "__test=" + cookie.value;
    res = await request(url, { method, headers: outHeaders, body });
  }
  return res;
}

module.exports = { phpFetch, DEFAULT_HOST };
