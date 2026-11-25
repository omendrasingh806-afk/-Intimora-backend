// Long-running host entrypoint (Botkeep free server, Render, a VPS, a worker dyno).
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
// Render and plain VPS boxes working with the same file (Render injects PORT).
const PORT = Number(process.env.SERVER_PORT || process.env.PORT || 3000);
const HOST = process.env.HOST || "0.0.0.0";
const KEEPALIVE_MS = Number(process.env.MTPROTO_KEEPALIVE_MS) || 60000;
const REQUIRED = ["TELEGRAM_API_ID", "TELEGRAM_API_HASH", "TELEGRAM_SESSION"];

const missing = REQUIRED.filter((key) => !process.env[key]);

// Render's free plan stops a web service after 15 minutes without inbound
// traffic — and a stopped process has no socket left to keep warm. Render
// publishes its own public URL as RENDER_EXTERNAL_URL, so the app can wake
// itself with a plain health request. SELF_PING_URL does the same on any other
// free host; SELF_PING_MS=0 turns the feature off.
const selfPingUrl =
  process.env.SELF_PING_MS === "0"
    ? ""
    : process.env.SELF_PING_URL ||
      (process.env.RENDER_EXTERNAL_URL
        ? `${process.env.RENDER_EXTERNAL_URL.replace(/\/+$/, "")}/api/health`
        : "");
const SELF_PING_MS = Math.max(60000, Number(process.env.SELF_PING_MS) || 600000);

function startSelfPing() {
  if (!selfPingUrl) return;
  const tick = async () => {
    try {
      const res = await fetch(selfPingUrl, { method: "GET" });
      if (!res.ok) console.error(`[self-ping] ${res.status} ${selfPingUrl}`);
    } catch (e) {
      console.error("[self-ping] failed:", e && e.message);
    }
  };
  const timer = setInterval(tick, SELF_PING_MS);
  if (typeof timer.unref === "function") timer.unref();
  console.log(`[boot] self-ping every ${SELF_PING_MS}ms -> ${selfPingUrl}`);
}

const server = app.listen(PORT, HOST, async () => {
  console.log(`[boot] API listening on http://${HOST}:${PORT}`);
  startSelfPing();
  if (missing.length) {
    console.error(`[boot] MTProto disabled — missing env: ${missing.join(", ")}`);
    return;
  }
  try {
    await tg.warmUp();
    console.log(`[boot] MTProto connected once; keepalive every ${KEEPALIVE_MS}ms`);
  } catch (e) {
    // Keep serving: catalogue reads still work, and the keepalive reconnects
    // from TELEGRAM_SESSION on its own — one bad boot must not leave the process
    // without a socket for good.
    console.error("[boot] MTProto connect failed, retrying in background:", e && e.message);
    tg.startKeepAlive(KEEPALIVE_MS);
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

// This process is the only thing holding the socket, so a stray Telegram/proxy
// error must not take it down (an exit costs a whole new handshake). Log it and
// drop the socket instead: the next call rebuilds it from TELEGRAM_SESSION.
process.on("uncaughtException", (e) => {
  console.error("[uncaughtException]", (e && e.stack) || e);
  try {
    tg.dropClient();
  } catch { /* already gone */ }
});
