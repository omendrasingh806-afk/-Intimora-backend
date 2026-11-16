// Local smoke test — runs the Worker logic in Node (fetch/Request/Response exist in Node 18+).
import worker, { solveChallenge } from "./src/index.js";
import crypto from "node:crypto";

const env = {
  PHP_BACKEND_URLS: "https://intimora-api3.site.je,https://intimora-api2.site.je",
  ALLOWED_ORIGINS: "https://intimora.new.tg",
};
const base = "https://proxy.test";
const call = (path, init) => worker.fetch(new Request(base + path, init), env);

// 1. AES implementation must match node:crypto on the same challenge page
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36";
const html = await (await fetch("https://intimora-api3.site.je/api/health", { headers: { "user-agent": UA } })).text();
const m = html.match(/toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/);
const d = crypto.createDecipheriv("aes-128-cbc", Buffer.from(m[1], "hex"), Buffer.from(m[2], "hex"));
d.setAutoPadding(false);
const expected = Buffer.concat([d.update(Buffer.from(m[3], "hex")), d.final()]).toString("hex");
console.log("AES match:", solveChallenge(html) === expected);

// 2. health + cookie
console.log("health:", (await (await call("/__health")).text()).slice(0, 160));

// 3. backend 1 (default /api)
const v1 = await call("/api/videos?limit=1");
console.log("b1 videos:", v1.status, String(await v1.text()).slice(0, 80));

// 4. backend 2 via /b2 prefix — must reuse the SAME cookie (no second solve)
const v2 = await call("/b2/api/videos?limit=1");
console.log("b2 videos:", v2.status, String(await v2.text()).slice(0, 80));

// 5. thumbnail from backend 2
const t = await call("/b2/api/videos/local-1794307320405/thumbnail");
const tb = await t.arrayBuffer();
console.log("b2 thumbnail:", t.status, t.headers.get("content-type"), tb.byteLength, "bytes");

// 6. Range stream from backend 1
const s = await call("/api/videos/local-1794307320405/stream", { headers: { Range: "bytes=0-1023" } });
console.log("b1 stream:", s.status, s.headers.get("content-type"), s.headers.get("content-range"));
