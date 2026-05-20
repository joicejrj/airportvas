// frontend/team-leader/pwa/db.js
//
// Tiny promise-friendly IndexedDB wrapper.
//
// Stores:
//   jobs_cache      — last seen list of TL's claimed jobs (keyPath: id)
//   available_cache — last seen available list (keyPath: id)
//   sync_queue      — queued offline actions (keyPath: id)
//   kv              — generic key/value (user profile, settings) (keyPath: k)
//
// We deliberately keep this thin — most callers want `getAll(store)`,
// `put(store, obj)`, `delete(store, id)` and that's it.

const DB_NAME    = 'airvas-tl';
const DB_VERSION = 1;

let _dbPromise = null;

function open() {
  if (_dbPromise) return _dbPromise;
  _dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = (e) => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('jobs_cache')) {
        db.createObjectStore('jobs_cache', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('available_cache')) {
        db.createObjectStore('available_cache', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('sync_queue')) {
        const s = db.createObjectStore('sync_queue', { keyPath: 'id' });
        s.createIndex('by_status', 'sync_status');
        s.createIndex('by_created', 'created_at');
      }
      if (!db.objectStoreNames.contains('kv')) {
        db.createObjectStore('kv', { keyPath: 'k' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror   = () => reject(req.error);
  });
  return _dbPromise;
}

function tx(store, mode = 'readonly') {
  return open().then((db) => db.transaction(store, mode).objectStore(store));
}

function wrap(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror   = () => reject(req.error);
  });
}

// ─── Public API ─────────────────────────────────────────────────────
export async function dbGet(store, key) {
  const s = await tx(store);
  return wrap(s.get(key));
}

export async function dbGetAll(store) {
  const s = await tx(store);
  return wrap(s.getAll());
}

export async function dbPut(store, obj) {
  const s = await tx(store, 'readwrite');
  return wrap(s.put(obj));
}

export async function dbPutMany(store, items) {
  if (!items?.length) return;
  const s = await tx(store, 'readwrite');
  for (const item of items) s.put(item);
  return new Promise((resolve, reject) => {
    s.transaction.oncomplete = () => resolve();
    s.transaction.onerror    = () => reject(s.transaction.error);
  });
}

export async function dbDelete(store, key) {
  const s = await tx(store, 'readwrite');
  return wrap(s.delete(key));
}

export async function dbClear(store) {
  const s = await tx(store, 'readwrite');
  return wrap(s.clear());
}

export async function dbReplaceAll(store, items) {
  await dbClear(store);
  return dbPutMany(store, items);
}

// ─── Convenience for kv store ───────────────────────────────────────
export async function kvGet(key) {
  const row = await dbGet('kv', key);
  return row?.v ?? null;
}
export async function kvSet(key, value) {
  return dbPut('kv', { k: key, v: value });
}
export async function kvDel(key) {
  return dbDelete('kv', key);
}
