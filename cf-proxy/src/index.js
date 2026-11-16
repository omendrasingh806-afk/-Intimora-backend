/**
 * PHP backend proxy for Cloudflare Workers.
 *
 * The PHP backend (InfinityFree) answers every non-browser request with a small
 * HTML page that sets a __test cookie:
 *
 *   document.cookie = "__test=" + toHex(slowAES.decrypt(c, 2, a, b)) +
 *                     "; max-age=21600; path=/";
 *
 * That is plain AES-128-CBC (mode 2, key = a, IV = b) without padding, so the
 * same token can be computed here. Workers' crypto.subtle always validates
 * PKCS#7 padding, which fails on this data, so a small pure-JS AES decrypt is
 * used instead. The cookie is account-wide and lasts six hours, so it is cached
 * per isolate and refreshed when it expires or the backend rejects it.
 */

const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

const DEFAULT_BACKEND = "https://intimora-api3.site.je";
const COOKIE_TTL_MS = 5.5 * 60 * 60 * 1000; // challenge sets max-age=21600 (6h)

const FORWARD_REQUEST_HEADERS = [
  "authorization",
  "range",
  "if-range",
  "if-none-match",
  "content-type",
  "x-api-key",
  "x-admin-key",
];

const FORWARD_RESPONSE_HEADERS = [
  "content-type",
  "content-length",
  "content-range",
  "accept-ranges",
  "location",
  "etag",
  "last-modified",
  "cache-control",
];

// ---------------------------------------------------------------- AES decrypt

const SBOX = new Uint8Array([
  0x63, 0x7c, 0x77, 0x7b, 0xf2, 0x6b, 0x6f, 0xc5, 0x30, 0x01, 0x67, 0x2b, 0xfe, 0xd7, 0xab, 0x76,
  0xca, 0x82, 0xc9, 0x7d, 0xfa, 0x59, 0x47, 0xf0, 0xad, 0xd4, 0xa2, 0xaf, 0x9c, 0xa4, 0x72, 0xc0,
  0xb7, 0xfd, 0x93, 0x26, 0x36, 0x3f, 0xf7, 0xcc, 0x34, 0xa5, 0xe5, 0xf1, 0x71, 0xd8, 0x31, 0x15,
  0x04, 0xc7, 0x23, 0xc3, 0x18, 0x96, 0x05, 0x9a, 0x07, 0x12, 0x80, 0xe2, 0xeb, 0x27, 0xb2, 0x75,
  0x09, 0x83, 0x2c, 0x1a, 0x1b, 0x6e, 0x5a, 0xa0, 0x52, 0x3b, 0xd6, 0xb3, 0x29, 0xe3, 0x2f, 0x84,
  0x53, 0xd1, 0x00, 0xed, 0x20, 0xfc, 0xb1, 0x5b, 0x6a, 0xcb, 0xbe, 0x39, 0x4a, 0x4c, 0x58, 0xcf,
  0xd0, 0xef, 0xaa, 0xfb, 0x43, 0x4d, 0x33, 0x85, 0x45, 0xf9, 0x02, 0x7f, 0x50, 0x3c, 0x9f, 0xa8,
  0x51, 0xa3, 0x40, 0x8f, 0x92, 0x9d, 0x38, 0xf5, 0xbc, 0xb6, 0xda, 0x21, 0x10, 0xff, 0xf3, 0xd2,
  0xcd, 0x0c, 0x13, 0xec, 0x5f, 0x97, 0x44, 0x17, 0xc4, 0xa7, 0x7e, 0x3d, 0x64, 0x5d, 0x19, 0x73,
  0x60, 0x81, 0x4f, 0xdc, 0x22, 0x2a, 0x90, 0x88, 0x46, 0xee, 0xb8, 0x14, 0xde, 0x5e, 0x0b, 0xdb,
  0xe0, 0x32, 0x3a, 0x0a, 0x49, 0x06, 0x24, 0x5c, 0xc2, 0xd3, 0xac, 0x62, 0x91, 0x95, 0xe4, 0x79,
  0xe7, 0xc8, 0x37, 0x6d, 0x8d, 0xd5, 0x4e, 0xa9, 0x6c, 0x56, 0xf4, 0xea, 0x65, 0x7a, 0xae, 0x08,
  0xba, 0x78, 0x25, 0x2e, 0x1c, 0xa6, 0xb4, 0xc6, 0xe8, 0xdd, 0x74, 0x1f, 0x4b, 0xbd, 0x8b, 0x8a,
  0x70, 0x3e, 0xb5, 0x66, 0x48, 0x03, 0xf6, 0x0e, 0x61, 0x35, 0x57, 0xb9, 0x86, 0xc1, 0x1d, 0x9e,
  0xe1, 0xf8, 0x98, 0x11, 0x69, 0xd9, 0x8e, 0x94, 0x9b, 0x1e, 0x87, 0xe9, 0xce, 0x55, 0x28, 0xdf,
  0x8c, 0xa1, 0x89, 0x0d, 0xbf, 0xe6, 0x42, 0x68, 0x41, 0x99, 0x2d, 0x0f, 0xb0, 0x54, 0xbb, 0x16,
]);

const INV_SBOX = new Uint8Array(256);
for (let i = 0; i < 256; i++) INV_SBOX[SBOX[i]] = i;

function xtime(a) {
  return ((a << 1) ^ (a & 0x80 ? 0x1b : 0)) & 0xff;
}

function gmul(a, b) {
  let p = 0;
  for (let i = 0; i < 8; i++) {
    if (b & 1) p ^= a;
    const hi = a & 0x80;
    a = (a << 1) & 0xff;
    if (hi) a ^= 0x1b;
    b >>= 1;
  }
  return p;
}

function expandKey(key) {
  const w = [];
  for (let i = 0; i < 4; i++) w.push([key[4 * i], key[4 * i + 1], key[4 * i + 2], key[4 * i + 3]]);
  let rcon = 1;
  for (let i = 4; i < 44; i++) {
    let t = w[i - 1].slice();
    if (i % 4 === 0) {
      t = [t[1], t[2], t[3], t[0]].map((b) => SBOX[b]);
      t[0] ^= rcon;
      rcon = xtime(rcon);
    }
    w.push(w[i - 4].map((b, j) => b ^ t[j]));
  }
  const roundKeys = [];
  for (let r = 0; r < 11; r++) roundKeys.push(w.slice(4 * r, 4 * r + 4).flat());
  return roundKeys;
}

function invShiftRows(state) {
  const out = new Uint8Array(16);
  for (let row = 0; row < 4; row++) {
    for (let col = 0; col < 4; col++) {
      out[row + 4 * ((col + row) % 4)] = state[row + 4 * col];
    }
  }
  return out;
}

function invSubBytes(state) {
  const out = new Uint8Array(16);
  for (let i = 0; i < 16; i++) out[i] = INV_SBOX[state[i]];
  return out;
}

function invMixColumns(state) {
  const out = new Uint8Array(16);
  for (let c = 0; c < 4; c++) {
    const s0 = state[4 * c],
      s1 = state[4 * c + 1],
      s2 = state[4 * c + 2],
      s3 = state[4 * c + 3];
    out[4 * c] = gmul(s0, 14) ^ gmul(s1, 11) ^ gmul(s2, 13) ^ gmul(s3, 9);
    out[4 * c + 1] = gmul(s0, 9) ^ gmul(s1, 14) ^ gmul(s2, 11) ^ gmul(s3, 13);
    out[4 * c + 2] = gmul(s0, 13) ^ gmul(s1, 9) ^ gmul(s2, 14) ^ gmul(s3, 11);
    out[4 * c + 3] = gmul(s0, 11) ^ gmul(s1, 13) ^ gmul(s2, 9) ^ gmul(s3, 14);
  }
  return out;
}

function addRoundKey(state, roundKey) {
  const out = new Uint8Array(16);
  for (let i = 0; i < 16; i++) out[i] = state[i] ^ roundKey[i];
  return out;
}

function decryptBlock(block, roundKeys) {
  let state = addRoundKey(block, roundKeys[10]);
  for (let round = 9; round >= 1; round--) {
    state = invShiftRows(state);
    state = invSubBytes(state);
    state = addRoundKey(state, roundKeys[round]);
    state = invMixColumns(state);
  }
  state = invShiftRows(state);
  state = invSubBytes(state);
  return addRoundKey(state, roundKeys[0]);
}

function hexToBytes(hex) {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
  return out;
}

function bytesToHex(bytes) {
  let s = "";
  for (const b of bytes) s += (b < 16 ? "0" : "") + b.toString(16);
  return s;
}

/** Reimplementation of toHex(slowAES.decrypt(c, 2, a, b)) — AES-128-CBC, no padding. */
function aesCbcDecryptNoPadding(cipher, key, iv) {
  const roundKeys = expandKey(key);
  const out = new Uint8Array(cipher.length);
  let prev = iv;
  for (let off = 0; off < cipher.length; off += 16) {
    const block = cipher.subarray(off, off + 16);
    const plain = decryptBlock(block, roundKeys);
    for (let i = 0; i < 16; i++) out[off + i] = plain[i] ^ prev[i];
    prev = block;
  }
  return out;
}

// ------------------------------------------------------------- challenge glue

let cookieCache = { backend: "", value: "", exp: 0 };

function solveChallenge(html) {
  const m = String(html).match(
    /toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/
  );
  if (!m) return null;
  const key = hexToBytes(m[1]);
  const iv = hexToBytes(m[2]);
  const cipher = hexToBytes(m[3]);
  return bytesToHex(aesCbcDecryptNoPadding(cipher, key, iv));
}

async function refreshCookie(backend) {
  const res = await fetch(backend + "/api/health", {
    headers: { "user-agent": UA, accept: "*/*" },
  });
  const html = await res.text();
  const token = solveChallenge(html);
  if (!token) throw new Error("Hosting challenge could not be solved");
  cookieCache = { backend, value: token, exp: Date.now() + COOKIE_TTL_MS };
  return token;
}

async function ensureCookie(backend) {
  if (!cookieCache.value || cookieCache.backend !== backend || cookieCache.exp < Date.now()) {
    await refreshCookie(backend);
  }
  return cookieCache.value;
}

function isChallengeResponse(res) {
  return String(res.headers.get("content-type") || "").includes("text/html");
}

// --------------------------------------------------------------- CORS helpers

function allowedOrigins(env) {
  return String(env.ALLOWED_ORIGINS || "https://intimora.new.tg,https://intimora.tg")
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
}

function withCors(response, request, env) {
  const origin = request.headers.get("origin") || "";
  const headers = new Headers(response.headers);
  if (allowedOrigins(env).includes(origin)) {
    headers.set("Access-Control-Allow-Origin", origin);
    headers.set("Vary", "Origin");
  }
  headers.set("Access-Control-Allow-Methods", "GET, POST, HEAD, OPTIONS");
  headers.set(
    "Access-Control-Allow-Headers",
    "Authorization, Content-Type, Range, If-Range, X-Api-Key, X-Admin-Key"
  );
  headers.set(
    "Access-Control-Expose-Headers",
    "Accept-Ranges, Content-Range, Content-Length, Content-Type, ETag"
  );
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

// ------------------------------------------------------------------- handler

async function forward(request, env, url) {
  const backend = String(env.PHP_BACKEND_URL || DEFAULT_BACKEND).replace(/\/+$/, "");
  const target = backend + url.pathname + url.search;

  const headers = new Headers();
  for (const name of FORWARD_REQUEST_HEADERS) {
    const v = request.headers.get(name);
    if (v) headers.set(name, v);
  }
  headers.set("user-agent", UA);

  const hasBody = !["GET", "HEAD"].includes(request.method);
  const send = async () => {
    headers.set("cookie", "__test=" + (await ensureCookie(backend)));
    return fetch(target, {
      method: request.method,
      headers,
      body: hasBody ? request.body : undefined,
      redirect: "manual",
    });
  };

  let upstream = await send();
  if (isChallengeResponse(upstream)) {
    await refreshCookie(backend);
    upstream = await send();
  }

  const outHeaders = new Headers();
  for (const name of FORWARD_RESPONSE_HEADERS) {
    const v = upstream.headers.get(name);
    if (v) outHeaders.set(name, v);
  }
  return new Response(upstream.body, { status: upstream.status, statusText: upstream.statusText, headers: outHeaders });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === "OPTIONS") {
      return withCors(new Response(null, { status: 204 }), request, env);
    }

    if (url.pathname === "/" || url.pathname === "/__health") {
      const backend = String(env.PHP_BACKEND_URL || DEFAULT_BACKEND).replace(/\/+$/, "");
      let token = cookieCache.value;
      let expiresIn = Math.max(0, Math.round((cookieCache.exp - Date.now()) / 1000));
      try {
        token = await ensureCookie(backend);
        expiresIn = Math.max(0, Math.round((cookieCache.exp - Date.now()) / 1000));
      } catch (e) {
        return withCors(
          Response.json({ ok: false, error: e.message }, { status: 502 }),
          request,
          env
        );
      }
      return withCors(
        Response.json({ ok: true, backend, cookieExpiresInSec: expiresIn, solved: !!token }),
        request,
        env
      );
    }

    try {
      const response = await forward(request, env, url);
      return withCors(response, request, env);
    } catch (e) {
      return withCors(
        Response.json({ ok: false, error: e.message || "Proxy error" }, { status: 502 }),
        request,
        env
      );
    }
  },
};

export { solveChallenge };
