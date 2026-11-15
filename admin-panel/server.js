// Intimora Local Admin v2 — live job tracking (upload + re-process)
// Runs on your PC only. Uploads videos to Telegram via MTProto.

const express = require('express');
const { TelegramClient, Api } = require('teleproto');
const { StringSession } = require('teleproto/sessions');
const multer = require('multer');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const https = require('https');
const { execSync, spawn } = require('child_process');

// ================= Config =================
const CONFIG_FILE = path.join(__dirname, 'config.json');
if (!fs.existsSync(CONFIG_FILE)) {
  console.error('❌ config.json not found. Copy config.example.json to config.json and fill it.');
  process.exit(1);
}
const config = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf-8'));

const WORK_DIR = path.join(__dirname, 'work'); // temp files live here (visible, auto-cleaned)
fs.mkdirSync(WORK_DIR, { recursive: true });
for (const f of fs.readdirSync(WORK_DIR)) { try { fs.unlinkSync(path.join(WORK_DIR, f)); } catch {} }

const META_FILE = path.join(__dirname, 'metadata.json');
let localMetadata = [];
if (fs.existsSync(META_FILE)) localMetadata = JSON.parse(fs.readFileSync(META_FILE, 'utf-8'));
// Old sync wrongly marked every synced video as fast-start. Reset to "unknown".
for (const v of localMetadata) {
  if (String(v.id).startsWith('tg-') && v.is_fast_start === true) v.is_fast_start = null;
}
function saveMetadata() {
  const tmp = META_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(localMetadata, null, 2));
  fs.renameSync(tmp, META_FILE);
}
saveMetadata();

// ================= Helpers =================
const fmtBytes = (b) => {
  if (!b || b < 0) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  return (b / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + u[i];
};
const fmtTime = (s) => {
  s = Math.max(0, Math.round(s));
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
  return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
};
const rm = (p) => { try { if (p && fs.existsSync(p)) fs.unlinkSync(p); } catch {} };
const refToBase64 = (ref) => (ref ? Buffer.from(ref).toString('base64') : '');

// ================= FFmpeg =================
let ffmpegPath = null;
let ffmpegAvailable = false;
(function checkFFmpeg() {
  try { execSync('ffmpeg -version', { stdio: 'pipe' }); ffmpegPath = 'ffmpeg'; ffmpegAvailable = true; console.log('✅ ffmpeg found in PATH'); return; } catch {}
  try {
    const p = require('ffmpeg-static');
    if (p) { execSync(`"${p}" -version`, { stdio: 'pipe' }); ffmpegPath = p; ffmpegAvailable = true; console.log('✅ ffmpeg-static in use'); return; }
  } catch {}
  console.log('❌ ffmpeg not found. Run "npm install" (gets ffmpeg-static) or install ffmpeg and add it to PATH.');
})();

// Read the top-level MP4 atoms straight from disk (no big buffers).
function analyzeMp4(filePath) {
  const fd = fs.openSync(filePath, 'r');
  try {
    const fileSize = fs.fstatSync(fd).size;
    const hdr = Buffer.alloc(16);
    const atoms = [];
    let pos = 0;
    while (pos + 8 <= fileSize && atoms.length < 500) {
      fs.readSync(fd, hdr, 0, 16, pos);
      let size = hdr.readUInt32BE(0);
      const type = hdr.toString('latin1', 4, 8);
      if (!/^[\x20-\x7e]{4}$/.test(type)) break;
      if (size === 1) size = Number(hdr.readBigUInt64BE(8));
      else if (size === 0) size = fileSize - pos;
      if (size < 8) break;
      atoms.push(type);
      pos += size;
    }
    const mp4Like = atoms.length > 0 && ['ftyp', 'moov', 'mdat', 'free', 'wide', 'skip'].includes(atoms[0]);
    const moov = atoms.indexOf('moov');
    const mdat = atoms.indexOf('mdat');
    const fastStart = mp4Like && moov !== -1 && (mdat === -1 || moov < mdat);
    return { mp4Like, fastStart, atoms };
  } finally { fs.closeSync(fd); }
}

function probeDuration(file) {
  return new Promise((resolve) => {
    if (!ffmpegAvailable) return resolve(0);
    const c = spawn(ffmpegPath, ['-hide_banner', '-i', file], { windowsHide: true });
    let err = '';
    c.stderr.on('data', (d) => { err += d; });
    c.on('error', () => resolve(0));
    c.on('close', () => {
      const m = err.match(/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/);
      resolve(m ? Math.round(+m[1] * 3600 + +m[2] * 60 + +m[3]) : 0);
    });
  });
}

// ffmpeg -c copy -movflags +faststart, with live progress (bytes written)
function remuxFastStart(input, output, onBytes) {
  return new Promise((resolve, reject) => {
    const args = ['-y', '-hide_banner', '-nostats', '-progress', 'pipe:1', '-i', input,
      '-map', '0:v:0', '-map', '0:a?', '-sn', '-dn', '-c', 'copy', '-movflags', '+faststart', output];
    const c = spawn(ffmpegPath, args, { windowsHide: true });
    let errTail = '', buf = '';
    c.stderr.on('data', (d) => { errTail = (errTail + d).slice(-3000); });
    c.stdout.on('data', (d) => {
      buf += d;
      let i;
      while ((i = buf.indexOf('\n')) >= 0) {
        const line = buf.slice(0, i).trim();
        buf = buf.slice(i + 1);
        const m = line.match(/^total_size=(\d+)/);
        if (m) onBytes(Number(m[1]));
      }
    });
    c.on('error', (e) => reject(new Error('Cannot start ffmpeg: ' + e.message)));
    c.on('close', (code) => code === 0
      ? resolve()
      : reject(new Error(`ffmpeg failed (exit ${code}): ` + errTail.split('\n').filter(Boolean).slice(-3).join(' | '))));
  });
}

// ================= Job system (live tracking) =================
const STEPS = {
  reprocess: [['download', 'Download'], ['check', 'Check moov'], ['convert', 'Move moov to start'], ['connect', 'Connect'], ['video', 'Upload video'], ['meta', 'Metadata'], ['cleanup', 'Delete old']],
};
// Upload steps are built per-job from the selected targets.
// Metadata always goes to Telegram, so 'connect' + 'meta' are always present.
function buildUploadSteps(targets) {
  const steps = [['check', 'Check moov'], ['convert', 'Move moov to start'], ['connect', 'Connect to Telegram']];
  for (const t of targets) {
    if (t === 'telegram') steps.push(['video', 'Upload to Telegram'], ['thumb', 'Thumbnail']);
    if (t === 'bestfile') steps.push(['bestfile', 'Upload to BestFile']);
  }
  steps.push(['meta', 'Metadata']);
  return steps;
}
const jobs = new Map();
let queue = Promise.resolve();

function createJob(kind, title, videoId = null, targets = []) {
  const job = {
    id: crypto.randomBytes(4).toString('hex'), kind, title, videoId,
    targets: (targets || []).slice(),
    status: 'queued', stage: 'Waiting in queue…', percent: 0,
    detail: '', speed: '', eta: '', log: [], error: null, result: null,
    createdAt: Date.now(), startedAt: null, finishedAt: null, temp: [],
    steps: (kind === 'upload' ? buildUploadSteps(targets) : STEPS[kind])
      .map(([key, label]) => ({ key, label, state: 'pending' })),
  };
  jobs.set(job.id, job);
  if (jobs.size > 40) {
    for (const [id, j] of jobs) { if (j.status === 'done' || j.status === 'error') { jobs.delete(id); if (jobs.size <= 30) break; } }
  }
  return job;
}
function jlog(job, msg) {
  job.log.push(`[${new Date().toLocaleTimeString()}] ${msg}`);
  if (job.log.length > 300) job.log.shift();
  console.log(`[${job.kind} ${job.id}] ${msg}`);
}
// state: pending | running | done | skipped | error
function step(job, key, state) {
  const st = job.steps.find((x) => x.key === key);
  if (st) st.state = state;
}
function publicJob(j) {
  const { temp, ...rest } = j;
  return { ...rest, elapsed: ((j.finishedAt || Date.now()) - (j.startedAt || j.createdAt)) / 1000 };
}
function runJob(job, fn) {
  queue = queue.then(async () => {
    job.status = 'running';
    job.startedAt = Date.now();
    try {
      await fn(job);
      job.status = 'done'; job.percent = 100; job.stage = 'Done'; job.speed = ''; job.eta = '';
      jlog(job, '✅ Finished');
    } catch (e) {
      job.steps.forEach((x) => { if (x.state === 'running') x.state = 'error'; });
      job.status = 'error'; job.error = e.message || String(e); job.speed = ''; job.eta = '';
      jlog(job, '❌ FAILED: ' + job.error);
    } finally {
      job.finishedAt = Date.now();
      job.temp.forEach(rm);
    }
  });
}
// Maps a byte-progress of one stage onto the job's overall 0-100 bar, with speed + ETA.
function stage(job, label, from, to, totalBytes) {
  const t0 = Date.now();
  let last = 0;
  job.stage = label; job.percent = from; job.detail = ''; job.speed = ''; job.eta = '';
  jlog(job, label);
  return (done, total) => {
    const tot = total || totalBytes;
    const frac = tot ? Math.min(1, done / tot) : 0;
    const now = Date.now();
    if (now - last < 200 && frac < 1) return;
    last = now;
    job.percent = from + (to - from) * frac;
    const el = (now - t0) / 1000;
    const sp = el > 0.5 ? done / el : 0;
    job.speed = sp ? fmtBytes(sp) + '/s' : '';
    job.eta = sp && tot ? fmtTime((tot - done) / sp) : '';
    job.detail = tot ? `${fmtBytes(done)} / ${fmtBytes(tot)}` : fmtBytes(done);
  };
}

// ================= Telegram =================
const tg = new TelegramClient(new StringSession(config.session), config.apiId, config.apiHash, { connectionRetries: 5 });
let tgReady = false;
async function ensureConnected() {
  if (!tg.connected) {
    await Promise.race([
      tg.connect(),
      new Promise((_, rej) => setTimeout(() => rej(new Error('Telegram connection timed out (check internet / VPN / session)')), 45000)),
    ]);
  }
  tgReady = true;
}
ensureConnected().then(() => console.log('✅ Telegram connected')).catch((e) => console.log('❌ Telegram connect failed:', e.message));

const history = (peer, extra) => new Api.messages.GetHistory({
  peer, offsetId: 0, offsetDate: 0, addOffset: 0, limit: 100, maxId: 0, minId: 0, hash: BigInt(0), ...extra,
});
const docOf = (msg) => {
  const d = msg.document || msg.media?.document;
  return d && d.className === 'Document' ? d : null;
};

// Fetch one message by id. (offsetId is exclusive, so ask for id+1 and take 1.)
async function fetchMessage(channel, id) {
  await ensureConnected();
  const peer = await tg.getInputEntity(channel);
  const r = await tg.invoke(history(peer, { offsetId: Number(id) + 1, limit: 1 }));
  const msg = r.messages && r.messages[0];
  if (!msg || msg.id !== Number(id)) throw new Error(`Telegram message ${id} not found (deleted?)`);
  return msg;
}

// Find the JSON "meta" text message in the metadata channel that points at this video message id.
async function findMetaMessage(videoMsgId) {
  const peer = await tg.getInputEntity(config.metaChannel);
  let offsetId = 0;
  for (let page = 0; page < 40; page++) {
    const r = await tg.invoke(history(peer, { offsetId, limit: 100 }));
    const msgs = r.messages || [];
    if (!msgs.length) break;
    for (const m of msgs) {
      if (!m.message || m.message[0] !== '{') continue;
      try { const j = JSON.parse(m.message); if (Number(j?.video?.tg_msg_id) === Number(videoMsgId)) return m.id; } catch {}
    }
    offsetId = msgs[msgs.length - 1].id; // GetHistory is newest→oldest, so last = oldest
    if (msgs.length < 100) break;
  }
  return null;
}
const tgMetaOf = (m) => { const { is_fast_start, meta_msg_id, ...rest } = m; return { t: 'meta', ...rest }; };

// Upload a file with live progress
async function sendVideo(job, peer, filePath, caption, thumbPath, from, to) {
  const size = fs.statSync(filePath).size;
  const tick = stage(job, 'Uploading video to Telegram', from, to, size);
  const msg = await tg.sendFile(peer, {
    file: filePath, caption, forceDocument: true, workers: 4,
    thumb: thumbPath && fs.existsSync(thumbPath) ? thumbPath : undefined,
    progressCallback: (p) => tick(Number(p) * size, size),
  });
  tick(size, size);
  return { msg, size };
}

// ================= BestFile (email sign-in + chunked-style upload) =================
// Verified flow: login (POST /en/login) -> refresh CSRF (GET /en) -> upload (POST /upload).
const BF_HOST = 'bestfile.io';
const BF_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
let bfCookies = {};      // name -> value (in-memory session, local tool only)
let bfCsrf = '';
let bfLoggedIn = false;

function bfCookieHeader() {
  return Object.entries(bfCookies).map(([k, v]) => k + '=' + v).join('; ');
}
function bfStoreCookies(setCookie) {
  if (!setCookie) return;
  for (const c of (Array.isArray(setCookie) ? setCookie : [setCookie])) {
    const m = c.match(/^([^=]+)=([^;]*)/);
    if (m) bfCookies[m[1].trim()] = m[2];
  }
}
// Low-level request. body: string (urlencoded) or null.
function bfRequest(method, reqPath, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const req = https.request({
      hostname: BF_HOST, path: reqPath, method,
      headers: { 'User-Agent': BF_UA, Cookie: bfCookieHeader(), ...headers },
    }, (res) => {
      bfStoreCookies(res.headers['set-cookie']);
      const chunks = [];
      res.on('data', (d) => chunks.push(d));
      res.on('end', () => resolve({
        status: res.statusCode, headers: res.headers,
        body: Buffer.concat(chunks).toString('utf-8'),
      }));
    });
    req.on('error', reject);
    if (body) { req.setHeader('Content-Length', Buffer.byteLength(body)); req.end(body); }
    else req.end();
  });
}
const bfCsrfFrom = (html) => { const m = html.match(/csrf-token" content="([^"]+)"/); return m ? m[1] : ''; };

async function bfRefreshCsrf() {
  const r = await bfRequest('GET', '/en');
  bfCsrf = bfCsrfFrom(r.body);
  return bfCsrf;
}
async function bfLogin() {
  // Always start from a clean session: /en/login redirects to /user when already
  // signed in, which yields an empty CSRF token and a 419 on the POST.
  bfCookies = {};
  bfCsrf = '';
  const r1 = await bfRequest('GET', '/en/login');
  const csrf = bfCsrfFrom(r1.body);
  const form = new URLSearchParams({
    _token: csrf, email: config.bestfileEmail, password: config.bestfilePassword,
  }).toString();
  const r2 = await bfRequest('POST', '/en/login', {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Referer': `https://${BF_HOST}/en/login` },
    body: form,
  });
  if (r2.status !== 302) throw new Error(`BestFile login failed (HTTP ${r2.status}). Check bestfileEmail/bestfilePassword in config.json.`);
  bfLoggedIn = true;
  await bfRefreshCsrf();
}
async function bfEnsureLogin() {
  if (!bfLoggedIn) await bfLogin();
  else await bfRefreshCsrf();
}

// Upload one file to BestFile with live progress. Returns { download_id, download_link }.
async function bfUpload(job, filePath, from, to) {
  const size = fs.statSync(filePath).size;
  const tick = stage(job, '⬆️ Uploading to BestFile…', from, to, size);
  const boundary = '----bf' + Date.now().toString(16) + Math.random().toString(16).slice(2);
  const fileName = path.basename(filePath);
  const head = `--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="${fileName}"\r\nContent-Type: application/octet-stream\r\n\r\n`;
  const tail = `\r\n--${boundary}\r\nContent-Disposition: form-data; name="upload_auto_delete"\r\n\r\n0\r\n--${boundary}\r\nContent-Disposition: form-data; name="size"\r\n\r\n${size}\r\n--${boundary}--\r\n`;

  const attempt = (csrf) => new Promise((resolve, reject) => {
    // Fresh stream per attempt: a stream opened before the login round-trip is
    // already drained (the progress listener puts it in flowing mode), so the
    // request body would never end and the upload would hang.
    const fileStream = fs.createReadStream(filePath);
    let uploaded = 0;
    const req = https.request({
      hostname: BF_HOST, path: '/upload', method: 'POST',
      headers: {
        'User-Agent': BF_UA, Cookie: bfCookieHeader(),
        'X-CSRF-TOKEN': csrf, 'Referer': `https://${BF_HOST}/en`,
        'Content-Type': 'multipart/form-data; boundary=' + boundary,
        'Content-Length': Buffer.byteLength(head) + size + Buffer.byteLength(tail),
      },
    }, (res) => {
      bfStoreCookies(res.headers['set-cookie']);
      const chunks = [];
      res.on('data', (d) => chunks.push(d));
      res.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf-8');
        try {
          const j = JSON.parse(body);
          if (j.type === 'success') { tick(size, size); resolve(j); }
          else reject(new Error('BestFile upload error: ' + (j.msg || j.error || body.slice(0, 200))));
        } catch { reject(new Error('BestFile upload bad response: ' + body.slice(0, 200))); }
      });
    });
    req.on('error', reject);
    req.write(head);
    fileStream.on('data', (d) => { uploaded += d.length; tick(uploaded, size); });
    fileStream.pipe(req, { end: false });
    fileStream.on('end', () => { req.write(tail); req.end(); });
    fileStream.on('error', reject);
  });

  await bfEnsureLogin();
  try {
    return await attempt(bfCsrf);
  } catch (e) {
    // Session may have expired (CSRF 419 / redirect) -> fresh login once and retry.
    bfLoggedIn = false;
    await bfLogin();
    return await attempt(bfCsrf);
  }
}

// ================= Job: upload =================
async function doUpload(job, p) {
  job.temp.push(p.videoPath, p.thumbPath);
  let finalPath = p.videoPath;
  const targets = job.targets.filter((t) => t === 'telegram' || t === 'bestfile');
  const hasTg = targets.includes('telegram');
  const hasBf = targets.includes('bestfile');

  // ---- Common: check moov + convert to fast-start (runs ONCE) ----
  job.stage = 'Checking where moov atom is…'; job.percent = 2; step(job, 'check', 'running');
  const info = analyzeMp4(p.videoPath);
  const ext = path.extname(p.origName).toLowerCase();
  jlog(job, `Atoms: ${info.atoms.slice(0, 6).join(', ')} → ${info.fastStart ? 'moov at START (fast-start OK)' : 'NOT fast-start'}`);
  let duration = p.duration || (await probeDuration(p.videoPath));
  step(job, 'check', 'done');

  let upFrom = 4;
  if (!info.fastStart || ext !== '.mp4') {
    step(job, 'convert', 'running');
    if (!ffmpegAvailable) throw new Error('ffmpeg not found — cannot make video fast-start. Run "npm install" or install ffmpeg.');
    finalPath = path.join(WORK_DIR, `fs-${job.id}.mp4`);
    job.temp.push(finalPath);
    const inSize = fs.statSync(p.videoPath).size;
    const tick = stage(job, 'Converting: moving moov to start (no re-encode)', 4, 30, inSize);
    await remuxFastStart(p.videoPath, finalPath, (b) => tick(b, inSize));
    if (!analyzeMp4(finalPath).fastStart) throw new Error('Converted file still is not fast-start');
    rm(p.videoPath);
    jlog(job, `Converted OK — moov is now at the START (${fmtBytes(fs.statSync(finalPath).size)})`);
    step(job, 'convert', 'done');
    upFrom = 30;
  } else {
    step(job, 'convert', 'skipped');
    job.stage = 'moov already at start ✅ — conversion not needed'; job.percent = 4;
    jlog(job, 'moov already at start → conversion skipped');
  }

  // ---- Allocate percent ranges across selected targets ----
  let cursor = upFrom;
  let tgEnd = 100, bfStart = cursor;
  if (hasTg && hasBf) { tgEnd = Math.round(cursor + (100 - cursor) * 0.5); bfStart = tgEnd; }
  else if (hasTg) { tgEnd = 100; }
  else { bfStart = cursor; }

  let tgInfo = null;

  // ---- Always connect to Telegram (metadata always goes to Telegram) ----
  step(job, 'connect', 'running');
  job.stage = 'Connecting to Telegram…';
  await ensureConnected();
  const inputMeta = await tg.getInputEntity(config.metaChannel);
  let inputVideo = null, inputThumb = null;
  if (hasTg) {
    inputVideo = await tg.getInputEntity(config.videoChannel);
    inputThumb = await tg.getInputEntity(config.thumbChannel);
  }
  step(job, 'connect', 'done');

  // ---- Telegram target: video file + thumbnail (only if Telegram selected) ----
  if (hasTg) {
    step(job, 'video', 'running');
    job.stage = '⬆️ Uploading video to Telegram…';
    const { msg: videoMsg, size } = await sendVideo(job, inputVideo, finalPath, p.title, p.thumbPath, cursor, tgEnd);
    step(job, 'video', 'done');

    step(job, 'thumb', 'running');
    job.stage = '⬆️ Uploading thumbnail to Telegram…'; job.percent = Math.min(99, tgEnd - 1);
    jlog(job, 'Uploading thumbnail');
    const thumbMsg = await tg.sendFile(inputThumb, { file: p.thumbPath, forceDocument: false, workers: 2 });
    step(job, 'thumb', 'done');

    const doc = videoMsg.document || videoMsg.media?.document;
    const photo = thumbMsg.photo || thumbMsg.media?.photo;
    tgInfo = {
      video: {
        tg_doc_id: doc?.id ? String(doc.id) : '', tg_access_hash: doc?.accessHash ? String(doc.accessHash) : '',
        tg_file_ref: refToBase64(doc?.fileReference), tg_size: doc?.size ? Number(doc.size) : size,
        tg_mime: doc?.mimeType || 'video/mp4', tg_channel: config.videoChannel, tg_msg_id: videoMsg.id,
        tg_filename: p.origName.replace(/\.[^.]+$/, '') + '.mp4',
      },
      thumbnail: {
        thumb_doc_id: photo?.id ? String(photo.id) : '', thumb_access_hash: photo?.accessHash ? String(photo.accessHash) : '',
        thumb_file_ref: refToBase64(photo?.fileReference), thumb_size: p.thumbSize, thumb_msg_id: thumbMsg.id,
      },
      meta_msg_id: null,
    };
  }

  // ---- BestFile target: video file only ----
  let bfInfo = null;
  if (hasBf) {
    step(job, 'bestfile', 'running');
    job.stage = '⬆️ Uploading video to BestFile…';
    bfInfo = await bfUpload(job, finalPath, bfStart, 100);
    step(job, 'bestfile', 'done');
    jlog(job, `BestFile upload done → ${bfInfo.download_link}`);
  }

  // ---- Build metadata (BestFile link lives inside the metadata) ----
  const metadata = {
    t: 'meta', id: `local-${Date.now()}`, title: p.title, description: p.description || '',
    exclusive: !!p.exclusive, targets,
    views: 0, likes: 0, shares: 0, likedBy: [], duration,
    createdAt: Math.floor(Date.now() / 1000),
  };
  if (hasTg) { metadata.video = tgInfo.video; metadata.thumbnail = tgInfo.thumbnail; }
  if (hasBf) {
    metadata.bestfile = {
      download_id: bfInfo.download_id, download_link: bfInfo.download_link,
      size: fs.statSync(finalPath).size,
    };
  }

  // ---- Metadata ALWAYS sent to Telegram (holds the BestFile link too) ----
  step(job, 'meta', 'running');
  job.stage = '⬆️ Saving metadata to Telegram…'; job.percent = 97; jlog(job, 'Sending metadata to Telegram');
  const metaMsg = await tg.sendMessage(inputMeta, { message: JSON.stringify(metadata) });
  metadata.meta_msg_id = metaMsg.id;
  if (tgInfo) tgInfo.meta_msg_id = metaMsg.id;
  step(job, 'meta', 'done');

  localMetadata.unshift({ ...metadata, is_fast_start: true });
  saveMetadata();
  job.result = { id: metadata.id, targets, telegram: hasTg, bestfile: hasBf };
}

// ================= Job: re-process =================
async function doReprocess(job, videoId) {
  const v = localMetadata.find((x) => x.id === videoId);
  if (!v) throw new Error('Video not found in local metadata');
  const oldMsgId = Number(v.video.tg_msg_id);
  const size = Number(v.video.tg_size) || 0;
  if (!ffmpegAvailable) throw new Error('ffmpeg not found — run "npm install" or install ffmpeg.');

  const dl = path.join(WORK_DIR, `dl-${job.id}.mp4`);
  const fixed = path.join(WORK_DIR, `fix-${job.id}.mp4`);
  const thumb = path.join(WORK_DIR, `th-${job.id}.jpg`);
  job.temp.push(dl, fixed, thumb);

  // 1. download
  step(job, 'download', 'running');
  const tickD = stage(job, 'Downloading from Telegram', 0, 30, size);
  const msg = await fetchMessage(config.videoChannel, oldMsgId);
  await tg.downloadMedia(msg, { outputFile: dl, progressCallback: (d, t) => tickD(Number(d), Number(t) || size) });
  const dlSize = fs.statSync(dl).size;
  tickD(dlSize, dlSize);
  step(job, 'download', 'done');

  // 2. check
  job.stage = 'Checking where moov atom is…'; job.percent = 31; step(job, 'check', 'running');
  const info = analyzeMp4(dl);
  jlog(job, `Atoms: ${info.atoms.slice(0, 6).join(', ')}`);
  step(job, 'check', 'done');
  if (info.fastStart) {
    ['convert', 'connect', 'video', 'meta', 'cleanup'].forEach((k) => step(job, k, 'skipped'));
    job.stage = 'moov already at start ✅ — nothing to do';
    v.is_fast_start = true; saveMetadata();
    job.result = { skipped: true };
    jlog(job, '✅ Already fast-start — nothing to re-upload');
    return;
  }

  // 3. convert
  step(job, 'convert', 'running');
  const tickC = stage(job, 'Converting: moving moov to start (no re-encode)', 32, 50, dlSize);
  await remuxFastStart(dl, fixed, (b) => tickC(b, dlSize));
  if (!analyzeMp4(fixed).fastStart) throw new Error('Converted file still is not fast-start');
  rm(dl);
  step(job, 'convert', 'done');
  jlog(job, 'moov is now at the START');

  // 4. old thumbnail (optional)
  if (v.thumbnail?.thumb_msg_id) {
    try {
      const tm = await fetchMessage(config.thumbChannel, v.thumbnail.thumb_msg_id);
      await tg.downloadMedia(tm, { outputFile: thumb });
    } catch (e) { jlog(job, '⚠️ Thumbnail not re-attached: ' + e.message); }
  }

  const targets = (v.targets || ['telegram']).filter((t) => t === 'telegram' || t === 'bestfile');
  const hasTg = targets.includes('telegram');
  const hasBf = targets.includes('bestfile');

  // 5. upload new (to each selected target)
  let newMsg = null, newSize = 0, doc = null;
  if (hasTg) {
    job.stage = '⬆️ Uploading to Telegram…'; job.detail = ''; job.speed = ''; job.eta = ''; step(job, 'connect', 'running');
    await ensureConnected();
    const inputVideo = await tg.getInputEntity(config.videoChannel);
    step(job, 'connect', 'done');
    step(job, 'video', 'running');
    job.stage = '⬆️ Uploading to Telegram…';
    const r = await sendVideo(job, inputVideo, fixed, v.title, thumb, 51, 85);
    newMsg = r.msg; newSize = r.size;
    step(job, 'video', 'done');
    doc = newMsg.document || newMsg.media?.document;
  }

  let bfInfo = null;
  if (hasBf) {
    job.stage = '⬆️ Uploading to BestFile…';
    bfInfo = await bfUpload(job, fixed, hasTg ? 85 : 51, 95);
    jlog(job, `BestFile re-upload done → ${bfInfo.download_link}`);
  }

  // 6. update local + Telegram metadata
  step(job, 'meta', 'running');
  job.stage = 'Updating metadata'; job.percent = 92; jlog(job, 'Updating metadata');
  const updated = { ...v, is_fast_start: true };
  if (hasTg) {
    updated.video = {
      ...v.video, tg_doc_id: doc?.id ? String(doc.id) : '', tg_access_hash: doc?.accessHash ? String(doc.accessHash) : '',
      tg_file_ref: refToBase64(doc?.fileReference), tg_size: doc?.size ? Number(doc.size) : newSize,
      tg_mime: doc?.mimeType || 'video/mp4', tg_channel: config.videoChannel, tg_msg_id: newMsg.id,
    };
  }
  if (hasBf) {
    updated.bestfile = {
      download_id: bfInfo.download_id, download_link: bfInfo.download_link,
      size: fs.statSync(fixed).size,
    };
  }
  if (!updated.duration) updated.duration = await probeDuration(fixed);

  // The metadata channel still points at the OLD message → edit it (or create one if none exists).
  if (hasTg) {
    const inputMeta = await tg.getInputEntity(config.metaChannel);
    let metaId = v.meta_msg_id || (await findMetaMessage(oldMsgId));
    const text = JSON.stringify(tgMetaOf(updated));
    if (metaId) {
      await tg.editMessage(inputMeta, { message: metaId, text });
      jlog(job, `Metadata message ${metaId} updated in Telegram`);
    } else {
      const m = await tg.sendMessage(inputMeta, { message: text });
      metaId = m.id;
      jlog(job, `No old metadata message found → created new one (${metaId})`);
    }
    updated.meta_msg_id = metaId;
  }
  localMetadata[localMetadata.findIndex((x) => x.id === videoId)] = updated;
  saveMetadata();
  step(job, 'meta', 'done');

  // 7. delete old video message last (everything else already succeeded)
  if (hasTg) {
    step(job, 'cleanup', 'running');
    job.stage = 'Deleting old video message'; job.percent = 97;
    const inputVideo = await tg.getInputEntity(config.videoChannel);
    try { await tg.deleteMessages(inputVideo, [oldMsgId], { revoke: true }); jlog(job, 'Old message deleted'); }
    catch (e) { jlog(job, '⚠️ Could not delete old message: ' + e.message); }
    step(job, 'cleanup', 'done');
  }
  job.result = { newMsgId: newMsg?.id, targets, telegram: hasTg, bestfile: hasBf };
}

// ================= Sync =================
async function syncVideosFromTelegram() {
  await ensureConnected();
  const peer = await tg.getInputEntity(config.videoChannel);
  const found = new Map();
  let offsetId = 0, fetched = 0;
  for (let page = 0; page < 100; page++) {
    const r = await tg.invoke(history(peer, { offsetId, limit: 100 }));
    const msgs = r.messages || [];
    if (!msgs.length) break;
    fetched += msgs.length;
    for (const m of msgs) {
      const d = docOf(m);
      if (!d || !String(d.mimeType || '').startsWith('video/')) continue;
      const va = (d.attributes || []).find((a) => a.className === 'DocumentAttributeVideo');
      found.set(m.id, {
        id: `tg-${m.id}`, title: m.message || d.attributes?.find((a) => a.fileName)?.fileName || 'Untitled', description: '',
        exclusive: false, duration: va ? Math.round(Number(va.duration)) : 0, views: 0, likes: 0, shares: 0, likedBy: [],
        video: {
          tg_doc_id: String(d.id), tg_access_hash: String(d.accessHash), tg_file_ref: refToBase64(d.fileReference),
          tg_size: Number(d.size), tg_mime: d.mimeType, tg_channel: config.videoChannel, tg_msg_id: m.id,
          tg_filename: d.attributes?.find((a) => a.fileName)?.fileName || 'video.mp4',
        },
        thumbnail: { thumb_doc_id: '', thumb_access_hash: '', thumb_file_ref: '', thumb_size: 0, thumb_msg_id: null },
        is_fast_start: null, // unknown until re-process checks it
        createdAt: typeof m.date === 'number' ? m.date : Math.floor(Date.now() / 1000),
      });
    }
    offsetId = msgs[msgs.length - 1].id; // oldest message of this page
    if (msgs.length < 100) break;
  }
  const have = new Set(localMetadata.map((v) => Number(v.video?.tg_msg_id)));
  const fresh = [...found.values()].filter((v) => !have.has(v.video.tg_msg_id));
  localMetadata = [...fresh, ...localMetadata];
  saveMetadata();
  return { total: localMetadata.length, added: fresh.length, scanned: fetched };
}

// ================= Express =================
const app = express();
const upload = multer({
  storage: multer.diskStorage({
    destination: WORK_DIR,
    filename: (req, file, cb) => cb(null, `up-${Date.now()}-${crypto.randomBytes(3).toString('hex')}${path.extname(file.originalname)}`),
  }),
  limits: { fileSize: 4 * 1024 * 1024 * 1024 },
});
app.use(express.static(path.join(__dirname, 'public')));
app.use(express.json());

app.get('/api/health', (req, res) => res.json({ ok: true, data: { status: 'up', ffmpeg: ffmpegAvailable, telegram: tgReady, bestfile: !!(config.bestfileEmail && config.bestfilePassword) } }));
app.get('/api/ffmpeg-status', (req, res) => res.json({ ok: true, data: { available: ffmpegAvailable } }));
app.get('/api/videos', (req, res) => res.json({ ok: true, data: { videos: localMetadata, nextCursor: null } }));
app.get('/api/jobs', (req, res) => res.json({ ok: true, data: { jobs: [...jobs.values()].reverse().map(publicJob) } }));
app.get('/api/jobs/:id', (req, res) => {
  const j = jobs.get(req.params.id);
  j ? res.json({ ok: true, data: publicJob(j) }) : res.status(404).json({ ok: false, error: 'Job not found' });
});

// Parse selected upload targets from the form. Default: Telegram.
function parseTargets(raw) {
  let arr = [];
  if (Array.isArray(raw)) arr = raw;
  else if (typeof raw === 'string') arr = raw.split(',').map((s) => s.trim()).filter(Boolean);
  arr = arr.filter((t) => t === 'telegram' || t === 'bestfile');
  return arr.length ? arr : ['telegram'];
}

// Upload: returns immediately with a jobId; everything else runs as a tracked job.
app.post('/api/upload', upload.fields([{ name: 'video', maxCount: 1 }, { name: 'thumb', maxCount: 1 }]), (req, res) => {
  const v = req.files?.video?.[0], t = req.files?.thumb?.[0];
  const bad = (msg) => { rm(v?.path); rm(t?.path); res.status(400).json({ ok: false, error: msg }); };
  if (!req.body.title) return bad('Title required');
  if (!v) return bad('Video required');
  const targets = parseTargets(req.body.targets);
  const needsThumb = targets.includes('telegram');
  if (needsThumb && !t) return bad('Thumbnail required (needed for Telegram upload)');
  const job = createJob('upload', req.body.title, null, targets);
  runJob(job, (j) => doUpload(j, {
    title: req.body.title, description: req.body.description, exclusive: req.body.exclusive === 'true',
    duration: parseInt(req.body.duration) || 0, videoPath: v.path, thumbPath: t?.path, thumbSize: t?.size || 0, origName: v.originalname,
  }));
  res.json({ ok: true, data: { jobId: job.id, targets } });
});

app.post('/api/reprocess/:id', (req, res) => {
  const v = localMetadata.find((x) => x.id === req.params.id);
  if (!v) return res.status(404).json({ ok: false, error: 'Video not found' });
  for (const j of jobs.values()) {
    if (j.videoId === v.id && (j.status === 'queued' || j.status === 'running')) {
      return res.status(409).json({ ok: false, error: 'Already being processed', jobId: j.id });
    }
  }
  const targets = (v.targets || ['telegram']).filter((t) => t === 'telegram' || t === 'bestfile');
  const job = createJob('reprocess', v.title || v.video?.tg_filename || 'Untitled', v.id, targets);
  runJob(job, (j) => doReprocess(j, v.id));
  res.json({ ok: true, data: { jobId: job.id, targets } });
});

app.post('/api/delete/:id', (req, res) => {
  localMetadata = localMetadata.filter((v) => v.id !== req.params.id);
  saveMetadata();
  res.json({ ok: true, data: { message: 'Deleted (local list only; Telegram messages untouched)' } });
});

app.post('/api/sync-videos', async (req, res) => {
  try { res.json({ ok: true, data: await syncVideosFromTelegram() }); }
  catch (e) { console.error('Sync error:', e); res.status(500).json({ ok: false, error: e.message }); }
});

app.get('/api/export', (req, res) => {
  res.setHeader('Content-Disposition', 'attachment; filename=intimora-metadata.json');
  res.json(localMetadata);
});

const PORT = 3000;
// 127.0.0.1 only: this panel holds your Telegram session, so nobody else on your Wi-Fi may reach it.
app.listen(PORT, '127.0.0.1', () => console.log(`\n🎬 Intimora Local Admin\n👉 http://localhost:${PORT}\n`));
