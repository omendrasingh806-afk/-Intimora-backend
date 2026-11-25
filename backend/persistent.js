// Long-running host entrypoint (Botkeep free server, a VPS, a worker dyno).
//
// Same Express app as backend/server.js — the only difference is process
// lifetime. Here the MTProto session is opened ONCE at boot and kept warm by a
// keepalive ping, so:
//   * no video request pays for a connect() handshake,
//   * a new Telegram socket is not created per request (that is what serverless
//     does, because there `_client` dies with the invocation),
//   * if Telegram silently drops the socket, the ping drops it too and the next
//     call reconnects from TELEGRAM_SESSION (no new login code needed).
//
// Vercel/Netlify keep using backend/server.js via backend/api/index.js; they must
// never run this file.
const app = require("./server");
const tg = require("./telegram");

// Botkeep hands out SERVER_PORT and expects the process on 0.0.0.0. PORT keeps
// Render and plain VPS boxes working with the same file.
const PORT = Number(process.env.SERVER_PORT || process.env.PORT || 3000);
const HOST = process.env.HOST || "0.0.0.0";
const REQUIRED = ["TELEGRAM_API_ID", "TELEGRAM_API_HASH", "TELEGRAM_SESSION"];

const missing = REQUIRED.filter((key) => !process.env[key]);

const server = app.listen(PORT, HOST, async () => {
  console.log(`[boot] API listening on http://${HOST}:${PORT}`);
  if (missing.length) {
    console.error(`[boot] MTProto disabled — missing env: ${missing.join(", ")}`);
    return;
  }
  try {
    await tg.warmUp();
    console.log(
      `[boot] MTProto connected once; keepalive every ${Number(process.env.MTPROTO_KEEPALIVE_MS) || 60000}ms`
    );
  } catch (e) {
    // Keep serving: catalogue reads still work and the next request retries.
    console.error("[boot] MTProto connect failed:", e && e.message);
  }
});

// A live stream can outlast a default timeout, and a reverse proxy in front of
// this process reuses sockets — keep our own timeouts above the proxy's.
server.keepAliveTimeout = 65000;
server.headersTimeout = 70000;
server.requestTimeout = 0;

let closing = false;
async function shutdown(signal) {
  if (closing) return;
  closing = true;
  console.log(`[shutdown] ${signal}`);
  server.close(() => {});
  try {
    await tg.shutdown();
  } catch { /* socket already gone */ }
  // Let in-flight streams finish before the process leaves.
  setTimeout(() => process.exit(0), 1500).unref();
}

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => shutdown(signal));
}

process.on("unhandledRejection", (e) => {
  console.error("[unhandledRejection]", e && e.message);
});
