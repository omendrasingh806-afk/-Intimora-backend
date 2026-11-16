# PHP proxy (Cloudflare Worker)

Ek chhota proxy jo **PHP backend se data fetch** karta hai — chahe wo PHP hosting
(InfinityFree) har non-browser request ko JS "browser security" page se rok de.

## Ye kya karta hai

1. Backend se koi bhi API call karta hai.
2. Agar HTML challenge page aaya, usme se `a, b, c` nikaal ke `__test` cookie
   compute karta hai:
   `AES-128-CBC decrypt(c, key=a, iv=b)` → hex (padding ke bina, kyunki
   `slowAES` padding check nahi karta).
3. Cookie **5.5 ghante cache** rehti hai (asli max-age 6h hai), expire ya reject
   hone par dobara solve karti hai.
4. Request/response headers pass-through: `Authorization`, `Range`, `If-Range`,
   `Content-Type`, `X-Api-Key` → aur `Content-Type`, `Content-Range`,
   `Accept-Ranges`, `Location`.

Isliye JSON, thumbnails (`image/jpeg`) aur video (`206 Range`) sab kaam karte hain.

## Deploy (Cloudflare)

```bash
cd cf-proxy
npm install
npx wrangler login          # browser me Allow karna hai
npx wrangler deploy
```

Deploy ke baad URL milega:

```
https://intimora-php-proxy.<tumhara-subdomain>.workers.dev
```

## Backend badalna / origins

`wrangler.toml` ke `[vars]` me:

```toml
PHP_BACKEND_URL = "https://intimora-api3.site.je"   # ya https://intimora-api2.site.je
ALLOWED_ORIGINS = "https://intimora.new.tg,https://intimora.tg"
```

Deploy ke baad worker ka root/`/__health` batata hai cookie kitni der valid hai:

```json
{ "ok": true, "backend": "https://intimora-api3.site.je", "cookieExpiresInSec": 19800, "solved": true }
```

## Frontend me kaise use kare

API base worker ka URL, aur `/api/*` paths waise hi rakho:

```js
const API_BASE = "https://intimora-php-proxy.<subdomain>.workers.dev";
fetch(`${API_BASE}/api/videos?limit=12`);          // -> PHP ka JSON
img.src = `${API_BASE}/api/videos/<id>/thumbnail`;  // -> image/jpeg
video.src = `${API_BASE}/api/videos/<id>/stream`;   // -> 302 / 206 stream
```

`/api/get-server` wala discovery hata do — uski zaroorat nahi, worker khud
backend handle karta hai.

## Local test

```bash
node test-local.mjs
```

Ye Worker ke `fetch` ko Node me chala ke check karta hai: AES output `node:crypto`
se match, `/api/videos` JSON, thumbnail `image/jpeg`, aur stream `206` + Range.

## Dhyan rakhne wali baat

Ye hosting ke security system ko bypass karta hai, jo InfinityFree ke terms ke
against hai — account suspend ho sakta hai. Agar wo apna challenge algorithm
badal dein to proxy band ho jayega. Long-term safe options: frontend ko usi PHP
host par serve karna (same-origin), ya PHP ko aise host par shift karna jahan ye
check nahi hai.
