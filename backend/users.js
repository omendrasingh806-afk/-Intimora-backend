// User management — all data stored in Telegram channels
// Users channel: JSON text messages with user data
// Referrals channel: JSON text messages with referral links

const tg = require("./telegram");

// ---- User JSON format ----
// {
//   t: "user",
//   email: "user@gmail.com",
//   name: "John Doe",
//   username: "johndoe",
//   uid: "firebase-uid",
//   referralCode: "REF-ABC123",
//   referredBy: "REF-XYZ789",
//   likedVideos: ["vid-1", "vid-2"],
//   savedVideos: ["vid-3"],
//   avatar: "https://...",
//   createdAt: 1234567890
// }

// ---- Referral JSON format ----
// {
//   t: "ref",
//   ownerCode: "REF-AAA",
//   referredCode: "REF-BBB",
//   timestamp: 1234567890
// }

// ---- Generate referral code ----
function generateReferralCode() {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let code = "";
  for (let i = 0; i < 6; i++) {
    code += chars[Math.floor(Math.random() * chars.length)];
  }
  return "REF-" + code;
}

// ---- In-memory cache for users ----
let usersCache = null;
let usersCacheTime = 0;
const CACHE_TTL = 60000; // 60 seconds

async function readUsers() {
  // Check cache first
  if (usersCache && (Date.now() - usersCacheTime) < CACHE_TTL) {
    return usersCache;
  }

  const messages = await tg.readMessages(tg.channelUsers(), 5000);
  usersCache = messages
    .map((m) => {
      try {
        const user = JSON.parse(m.text);
        user._msgId = m.id;
        return user;
      } catch { return null; }
    })
    .filter((u) => u && u.t === "user");
  usersCacheTime = Date.now();
  return usersCache;
}

function invalidateCache() {
  usersCache = null;
  usersCacheTime = 0;
}

// ---- Read all referrals from Telegram ----
async function readReferrals() {
  const messages = await tg.readMessages(tg.channelReferrals(), 5000);
  return messages
    .map((m) => {
      try { return JSON.parse(m.text); } catch { return null; }
    })
    .filter((r) => r && r.t === "ref");
}

// ---- Find user by email ----
async function findByEmail(email) {
  const users = await readUsers();
  return users.find((u) => u.email === email);
}

// ---- Find user by uid ----
async function findByUid(uid) {
  const users = await readUsers();
  return users.find((u) => u.uid === uid);
}

// ---- Find user by username ----
async function findByUsername(username) {
  const users = await readUsers();
  return users.find((u) => u.username === username);
}

// ---- Find user by referral code ----
async function findByReferralCode(code) {
  const users = await readUsers();
  return users.find((u) => u.referralCode === code);
}

// ---- Create new user ----
async function createUser({ email, name, username, uid, avatar, referredBy }) {
  const user = {
    t: "user",
    email,
    name,
    username,
    uid,
    referralCode: generateReferralCode(),
    referredBy: referredBy || "",
    likedVideos: [],
    savedVideos: [],
    avatar: avatar || "",
    createdAt: Math.floor(Date.now() / 1000),
  };

  // Save user to Telegram Users channel
  await tg.sendMessage(tg.channelUsers(), JSON.stringify(user));

  // Save referral link if referredBy is set
  if (referredBy) {
    const ref = {
      t: "ref",
      ownerCode: referredBy,
      referredCode: user.referralCode,
      timestamp: Math.floor(Date.now() / 1000),
    };
    await tg.sendMessage(tg.channelReferrals(), JSON.stringify(ref));
  }

  invalidateCache();
  return user;
}

// ---- Update user ----
async function updateUser(uid, updates) {
  const user = await findByUid(uid);
  if (!user) return null;
  Object.assign(user, updates);
  await tg.editMessage(tg.channelUsers(), user._msgId, JSON.stringify(user));
  invalidateCache();
  return user;
}

// ---- Like/save collections ----
// One JSON message per user in their own channel, keyed by the user's uid:
//   { t: "likes", uid, likedVideos: [...], savedVideos: [...], updatedAt }
// Keeping the id lists out of the user record means a growing list can never hit
// Telegram's message-size limit, and a like never rewrites the account. Records
// written before this channel existed are seeded from the user record on the
// first read and written here on the first change, so nothing is lost.
let collectionsCache = null;
let collectionsCacheTime = 0;

async function readCollections() {
  if (collectionsCache && (Date.now() - collectionsCacheTime) < CACHE_TTL) {
    return collectionsCache;
  }
  const messages = await tg.readMessages(tg.channelAccounts(), 5000);
  const records = new Map();
  for (const m of messages) {
    let data;
    try { data = JSON.parse(m.text); } catch { continue; }
    if (!data || data.t !== "likes" || typeof data.uid !== "string") continue;
    data.likedVideos = Array.isArray(data.likedVideos) ? data.likedVideos : [];
    data.savedVideos = Array.isArray(data.savedVideos) ? data.savedVideos : [];
    // A uid should have exactly one record; if an older one is still in the
    // channel, the newest (read first) wins.
    if (records.has(data.uid)) continue;
    data._msgId = m.id;
    records.set(data.uid, data);
  }
  collectionsCache = records;
  collectionsCacheTime = Date.now();
  return records;
}

function invalidateCollections() {
  collectionsCache = null;
  collectionsCacheTime = 0;
}

async function readCollection(uid) {
  const collections = await readCollections();
  const existing = collections.get(uid);
  if (existing) return existing;
  const user = await findByUid(uid);
  if (!user) return null;
  return {
    t: "likes",
    uid,
    likedVideos: [...(user.likedVideos || [])],
    savedVideos: [...(user.savedVideos || [])],
    _msgId: null,
  };
}

async function writeCollection(record) {
  const data = {
    t: "likes",
    uid: record.uid,
    likedVideos: [...(record.likedVideos || [])],
    savedVideos: [...(record.savedVideos || [])],
    updatedAt: Math.floor(Date.now() / 1000),
  };
  const text = JSON.stringify(data);
  if (record._msgId) await tg.editMessage(tg.channelAccounts(), record._msgId, text);
  else data._msgId = await tg.sendMessage(tg.channelAccounts(), text);
  invalidateCollections();
  return data;
}

// The user JSON a client reads carries the ids from the collection channel, so
// the app never has to know where they are stored.
async function withCollections(user) {
  if (!user) return user;
  const collection = await readCollection(user.uid);
  if (!collection) return user;
  return { ...user, likedVideos: collection.likedVideos, savedVideos: collection.savedVideos };
}

// ---- Toggle like on video ----
async function toggleLike(uid, videoId) {
  const collection = await readCollection(uid);
  if (!collection) return null;
  const liked = collection.likedVideos;
  const idx = liked.indexOf(videoId);
  if (idx >= 0) {
    liked.splice(idx, 1);
  } else {
    liked.push(videoId);
  }
  await writeCollection({ ...collection, likedVideos: liked });
  return { liked: idx === -1, likedVideos: liked };
}

// ---- Toggle save on video ----
async function toggleSave(uid, videoId) {
  const collection = await readCollection(uid);
  if (!collection) return null;
  const saved = collection.savedVideos;
  const idx = saved.indexOf(videoId);
  if (idx >= 0) {
    saved.splice(idx, 1);
  } else {
    saved.push(videoId);
  }
  await writeCollection({ ...collection, savedVideos: saved });
  return { saved: idx === -1, savedVideos: saved };
}

// ---- Get referral tree ----
async function getReferralTree(uid) {
  const user = await findByUid(uid);
  if (!user) return null;

  const allReferrals = await readReferrals();
  const myCode = user.referralCode;

  // Direct referrals (used my code)
  const direct = allReferrals.filter((r) => r.ownerCode === myCode);

  // Level 2: referrals of my referrals
  const directCodes = direct.map((r) => r.referredCode);
  const level2 = allReferrals.filter((r) => directCodes.includes(r.ownerCode));

  // Get user details for each referral
  const users = await readUsers();
  const userMap = {};
  users.forEach((u) => { userMap[u.referralCode] = u; });

  // Format referral users with explicit fields
  const formatUser = (u) => ({
    email: u.email || '',
    firebaseUid: u.uid || '',
    name: u.name || '',
    username: u.username || '',
    avatar: u.avatar || '',
    referralCode: u.referralCode || '',
    likedVideos: u.likedVideos || [],
    savedVideos: u.savedVideos || [],
    createdAt: u.createdAt || 0,
  });

  return {
    code: myCode,
    direct: direct.map((r) => formatUser(userMap[r.referredCode])).filter(Boolean),
    level2: level2.map((r) => formatUser(userMap[r.referredCode])).filter(Boolean),
  };
}

// ---- Get user's liked videos ----
async function getLikedVideos(uid) {
  const collection = await readCollection(uid);
  return collection ? collection.likedVideos : null;
}

// ---- Get user's saved videos ----
async function getSavedVideos(uid) {
  const collection = await readCollection(uid);
  return collection ? collection.savedVideos : null;
}

module.exports = {
  generateReferralCode,
  readUsers,
  readReferrals,
  findByEmail,
  findByUid,
  findByUsername,
  findByReferralCode,
  createUser,
  updateUser,
  withCollections,
  toggleLike,
  toggleSave,
  getReferralTree,
  getLikedVideos,
  getSavedVideos,
};
