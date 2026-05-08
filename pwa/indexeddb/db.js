// pwa/indexeddb/db.js
// Airport Parking — IndexedDB layer
// Manages: sync_queue, job_cache, order_cache, service_catalogue

const DB_NAME    = 'AirportParkingPWA';
const DB_VERSION = 1;

let _db = null;

/**
 * Open (or upgrade) the IndexedDB database.
 * Returns a Promise<IDBDatabase>.
 */
export function openDB() {
    if (_db) return Promise.resolve(_db);

    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);

        req.onupgradeneeded = (event) => {
            const db = event.target.result;

            // ── SYNC QUEUE ──────────────────────────────────────────
            if (!db.objectStoreNames.contains('sync_queue')) {
                const sq = db.createObjectStore('sync_queue', { keyPath: 'id' });
                sq.createIndex('sync_status', 'sync_status', { unique: false });
                sq.createIndex('created_at',  'created_at',  { unique: false });
                sq.createIndex('entity_type', 'entity_type', { unique: false });
            }

            // ── JOB CACHE (provider's assigned jobs) ────────────────
            if (!db.objectStoreNames.contains('job_cache')) {
                const jc = db.createObjectStore('job_cache', { keyPath: 'id' });
                jc.createIndex('status',     'status',     { unique: false });
                jc.createIndex('updated_at', 'updated_at', { unique: false });
            }

            // ── ORDER CACHE (agent's orders) ─────────────────────────
            if (!db.objectStoreNames.contains('order_cache')) {
                const oc = db.createObjectStore('order_cache', { keyPath: 'id' });
                oc.createIndex('created_at',     'created_at',     { unique: false });
                oc.createIndex('payment_status', 'payment_status', { unique: false });
            }

            // ── SERVICE CATALOGUE (for offline order creation) ───────
            if (!db.objectStoreNames.contains('service_catalogue')) {
                db.createObjectStore('service_catalogue', { keyPath: 'id' });
            }

            // ── USER PREFERENCES ─────────────────────────────────────
            if (!db.objectStoreNames.contains('user_prefs')) {
                db.createObjectStore('user_prefs', { keyPath: 'key' });
            }

            // ── CONFLICT LOG ─────────────────────────────────────────
            if (!db.objectStoreNames.contains('conflict_log')) {
                const cl = db.createObjectStore('conflict_log', { keyPath: 'action_id' });
                cl.createIndex('created_at', 'created_at', { unique: false });
            }
        };

        req.onsuccess = (event) => {
            _db = event.target.result;

            _db.onversionchange = () => {
                _db.close();
                _db = null;
                window.location.reload();
            };

            resolve(_db);
        };

        req.onerror = () => reject(req.error);
    });
}

// ── GENERIC HELPERS ──────────────────────────────────────────────────────

export async function dbGet(storeName, key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readonly');
        const req = tx.objectStore(storeName).get(key);
        req.onsuccess = () => resolve(req.result ?? null);
        req.onerror   = () => reject(req.error);
    });
}

export async function dbPut(storeName, value) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readwrite');
        const req = tx.objectStore(storeName).put(value);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

export async function dbDelete(storeName, key) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readwrite');
        const req = tx.objectStore(storeName).delete(key);
        req.onsuccess = () => resolve();
        req.onerror   = () => reject(req.error);
    });
}

export async function dbGetAll(storeName) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx  = db.transaction(storeName, 'readonly');
        const req = tx.objectStore(storeName).getAll();
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

export async function dbGetByIndex(storeName, indexName, value) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx    = db.transaction(storeName, 'readonly');
        const store = tx.objectStore(storeName);
        const index = store.index(indexName);
        const req   = index.getAll(value);
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => reject(req.error);
    });
}

// ── CATALOGUE ────────────────────────────────────────────────────────────

export async function seedCatalogue(services) {
    const db = await openDB();
    const tx = db.transaction('service_catalogue', 'readwrite');
    const store = tx.objectStore('service_catalogue');
    services.forEach(svc => store.put(svc));
    return new Promise((res, rej) => {
        tx.oncomplete = res;
        tx.onerror    = () => rej(tx.error);
    });
}

export async function getCatalogue() {
    return dbGetAll('service_catalogue');
}

// ── USER PREFS ───────────────────────────────────────────────────────────

export async function savePref(key, value) {
    return dbPut('user_prefs', { key, value });
}

export async function getPref(key, defaultValue = null) {
    const row = await dbGet('user_prefs', key);
    return row ? row.value : defaultValue;
}
