# Intimora Cloudflare Load Balancer

**No KV, no env vars, no config — just paste and deploy!**

## Servers (hardcoded, order = rotation order):
1. `https://intimora-backend-pumn.onrender.com` — persistent Render service, Telegram session ka owner (php: false)
2. `https://intimora-api2.site.je` — Node + PHP
3. `http://intimora-api3.site.je` — Node + PHP
4. `https://api-intimora.vercel.app` — Node only
5. `https://intimora-api1.vercel.app` — Node only

Render sabse pehle check aur use hota hai (`/api/health` har 2 min), taaki wahi ek process session serve kare.

---

## Setup (2 minutes):

### 1. Cloudflare Dashboard kholo
https://dash.cloudflare.com

### 2. Workers & Pages → Create → Create Worker
### 3. Code editor me pura code paste karo
(`src/index.js` ka pura content)

### 4. Deploy karo
**Deploy** button pe click karo.

**Done! No KV, no env vars, no bindings!**

### 5. Test karo
```
https://intimora-load-balancer.xxx.workers.dev/api/health
```
Should return: `{"ok":true,"data":{"status":"up"}}`

---

## Adding More Servers:
`src/index.js` me `SERVERS` array me entry add karo (`php: true` sirf un hosts par jinke paas PHP bhi hai):
```javascript
const SERVERS = [
  { url: "https://intimora-backend-pumn.onrender.com", php: false },
  { url: "https://third-intimora.vercel.app", php: false },
];
```
Phir redeploy karo.

## How It Works:
- Round-robin: requests alternate between servers
- Health check every 2 minutes
- Dead servers (3 failed checks) suspended for 5 min
- Auto-recovers when server comes back
- No KV needed — all state in memory
