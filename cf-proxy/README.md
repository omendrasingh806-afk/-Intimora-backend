# PHP proxy (Cloudflare Worker)

Ek chhota proxy jo **PHP backend se data fetch** karta hai — chahe wo PHP hosting
(InfinityFree) har non-browser request ko JS "browser security" page se rok de.

**Deployed:** https://intimora-php-proxy.pr3095079.workers.dev

## Ye kya karta hai

1. Backend se koi bhi API call karta hai.
2. Agar HTML challenge page aaya, usme se `a, b, c` nikaal ke `__test` cookie
   compute karta hai: `AES-128-CBC decrypt(c, key=a, iv=b)` → hex (padding ke
   bina, kyunki `slowAES` padding check nahi karta).
3. Cookie **5.5 ghante cache** rehti hai (asli max-age 6h hai), expire ya reject
   hone par dobara solve karti hai.
4. Request/response headers pass-through: `Authorization`, `Range`, `If-Range`,
   `If-None-Match`, `Content-Type`, `X-Api-Key` → aur `Content-Type`,
   `Content-Range`, `Accept-Ranges`, `Location`.

## Do PHP servers (ek hi worker, ek hi cookie)

| URL | Backend |
|---|---|
| `/api/...` | pehla server (default: `intimora-api3.site.je`) |
| `/b1/api/...` | pehla server |
| `/b2/api/...` | dusra server (`intimora-api2.site.je`) |

`__test` cookie **host-independent** hai — ek server par solve hui cookie dusre
server par bhi chalti hai (dono par verified), isliye worker ek hi token cache
karta hai aur dono backends ke liye reuse karta hai.

## Config

`wrangler.toml`:

```toml
[vars]
PHP_BACKEND_URLS = "https://intimora-api3.site.je,https://intimora-api2.site.je"
ALLOWED_ORIGINS = "https://intimora.new.tg,https://intimora.tg,http://localhost:5173"
```

## Deploy

```bash
cd cf-proxy
npm install
npx wrangler login      # ek baar
npx wrangler deploy
```

## Health

```bash
curl https://intimora-php-proxy.pr3095079.workers.dev/__health
```
```json
{"ok":true,"backends":["https://intimora-api3.site.je","https://intimora-api2.site.je"],
 "cookieExpiresInSec":19777,"solved":true}
```

## Frontend me use

```js
const API_BASE = "https://intimora-php-proxy.pr3095079.workers.dev";

fetch(`${API_BASE}/api/videos?limit=12`);            // server 1 ka JSON
fetch(`${API_BASE}/b2/api/videos?limit=12`);         // server 2 ka JSON
img.src   = `${API_BASE}/b2/api/videos/${id}/thumbnail`; // image/jpeg
video.src = `${API_BASE}/api/videos/${id}/stream`;      // 206 / 302 (BestFile)
```

`/api/get-server` wala discovery hata do — worker khud backend handle karta hai.

## Local test

```bash
node test-local.mjs
```

Check karta hai: AES output `node:crypto` se match, `/api/videos` JSON,
`/b2/api/videos` JSON (same cookie), thumbnail `image/jpeg`, stream `206`.

## Dhyan

Ye hosting ke security system ko bypass karta hai, jo InfinityFree ke terms ke
against hai — account suspend ho sakta hai. Agar wo challenge algorithm badal
dein to proxy band ho jayega (tab `/__health` par `solved:false` dikhega).
