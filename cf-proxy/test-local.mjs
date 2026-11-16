// Local smoke test — runs the Worker logic in Node (fetch/Request/Response exist in Node 18+).
import worker, { solveChallenge } from "./src/index.js";
import crypto from "node:crypto";

const env = {
  PHP_BACKEND_URL: "https://intimora-api3.site.je",
  ALLOWED_ORIGINS: "https://intimora.new.tg",
};
const base = "https://proxy.test";
const call = (path, init) => worker.fetch(new Request(base + path, init), env);

// 1. AES implementation must match node:crypto on the same challenge page
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36";
const html = await (await fetch(env.PHP_BACKEND_URL + "/api/health", { headers: { "user-agent": UA } })).text();
const m = html.match(/toNumbers\("([0-9a-f]+)"\),b=toNumbers\("([0-9a-f]+)"\),c=toNumbers\("([0-9a-f]+)"\)/);
const d = crypto.createDecipheriv("aes-128-cbc", Buffer.from(m[1], "hex"), Buffer.from(m[2], "hex"));
d.setAutoPadding(false);
const expected = Buffer.concat([d.update(Buffer.from(m[3], "hex")), d.final()]).toString("hex");
const got = solveChallenge(html);
console.log("AES match:", got === expected, got === expected ? "" : `got=${got} want=${expected}`);

// 2. health endpoint (also warms the cookie)
console.log("health:", (await (await call("/__health")).text()).slice(0, 140));

// 3. JSON API through the proxy
const v = await call("/api/videos?limit=1");
console.log("videos:", v.status, String(await v.text()).slice(0, 100));

// 4. thumbnail must be a real JPEG
const t = await call("/api/videos/local-1794307320405/thumbnail");
const tb = await t.arrayBuffer();
console.log("thumbnail:", t.status, t.headers.get("content-type"), tb.byteLength, "bytes");

// 5. stream must answer a Range request
const s = await call("/api/videos/local-1794307320405/stream", { headers: { Range: "bytes=0-1023" } });
console.log("stream:", s.status, s.headers.get("content-type"), s.headers.get("content-range"), s.headers.get("accept-ranges"));
