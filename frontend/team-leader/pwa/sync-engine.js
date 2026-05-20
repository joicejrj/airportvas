// frontend/team-leader/pwa/sync-engine.js
//
// Offline action queue.
//
// When the app calls `queueAction({ entity_type, entity_id, action_type, payload })`:
//   • It's persisted to IndexedDB.sync_queue with status='pending'.
//   • A flush is scheduled (immediate if online, otherwise on reconnect).
//   • On flush we POST /api/sync with the whole batch and apply results.
//
// Conflict handling (e.g. another TL claimed the order first) marks the
// action as 'conflict' and emits a callback so the UI can react.

import { dbPut, dbGetAll, dbDelete } from './db.js';
import { post as apiPost } from '../api.js';

let CLIENT_ID = localStorage.getItem('tl_client_id');
if (!CLIENT_ID) {
  CLIENT_ID = crypto.randomUUID();
  localStorage.setItem('tl_client_id', CLIENT_ID);
}

const MAX_RETRIES        = 6;
const RETRY_BASE_DELAY   = 2000;     // ms — exponential: 2s, 4s, 8s, …
const FLUSH_INTERVAL_MS  = 30_000;   // periodic safety-net retry

let _flushing = false;
let _listeners = { conflict: [], success: [], failed: [] };

// ─── Public API ─────────────────────────────────────────────────────
export function on(event, fn) {
  if (_listeners[event]) _listeners[event].push(fn);
}

export async function queueAction({ entity_type, entity_id, action_type, payload }) {
  const action = {
    id:           crypto.randomUUID(),
    entity_type,
    entity_id:    entity_id ?? null,
    action_type,
    payload:      payload ?? {},
    sync_status:  'pending',
    retry_count:  0,
    last_attempt: null,
    created_at:   Date.now(),
  };
  await dbPut('sync_queue', action);

  // Try right away
  if (navigator.onLine) flush();
  return action.id;
}

export async function flush() {
  if (_flushing) return;
  if (!navigator.onLine) return;
  _flushing = true;

  try {
    const all = await dbGetAll('sync_queue');
    const due = all
      .filter((a) => a.sync_status === 'pending')
      .filter((a) => {
        if (a.retry_count === 0) return true;
        const delay = RETRY_BASE_DELAY * Math.pow(2, a.retry_count - 1);
        return Date.now() - (a.last_attempt || 0) >= delay;
      })
      .sort((a, b) => a.created_at - b.created_at)
      .slice(0, 50);  // batch cap

    if (!due.length) return;

    // POST /api/sync — server processes each independently.
    let resp;
    try {
      resp = await apiPost('/sync', {
        client_id: CLIENT_ID,
        actions:   due.map((a) => ({
          id:          a.id,
          entity_type: a.entity_type,
          entity_id:   a.entity_id,
          action_type: a.action_type,
          payload:     a.payload,
        })),
      });
    } catch (e) {
      // Network error — bump retry counts; we'll try again later
      const now = Date.now();
      for (const a of due) {
        a.retry_count  = (a.retry_count || 0) + 1;
        a.last_attempt = now;
        if (a.retry_count >= MAX_RETRIES) {
          a.sync_status = 'failed';
          _emit('failed', a, { reason: 'max_retries' });
        }
        await dbPut('sync_queue', a);
      }
      return;
    }

    const data = resp?.data ?? resp;
    const byId = Object.fromEntries(due.map((a) => [a.id, a]));

    for (const ok of data.success ?? []) {
      const a = byId[ok.action_id];
      if (a) {
        await dbDelete('sync_queue', a.id);
        _emit('success', a, ok.data ?? null);
      }
    }
    for (const cf of data.conflict ?? []) {
      const a = byId[cf.action_id];
      if (a) {
        a.sync_status = 'conflict';
        await dbPut('sync_queue', a);
        _emit('conflict', a, cf);
      }
    }
    for (const f of data.failed ?? []) {
      const a = byId[f.action_id];
      if (!a) continue;
      a.retry_count  = (a.retry_count || 0) + 1;
      a.last_attempt = Date.now();
      if (a.retry_count >= MAX_RETRIES) {
        a.sync_status = 'failed';
        _emit('failed', a, f);
      }
      await dbPut('sync_queue', a);
    }
  } finally {
    _flushing = false;
  }
}

export async function pendingCount() {
  const all = await dbGetAll('sync_queue');
  return all.filter((a) => a.sync_status === 'pending').length;
}

export async function listAll() {
  return dbGetAll('sync_queue');
}

export async function discard(id) {
  return dbDelete('sync_queue', id);
}

// ─── Wire up triggers ───────────────────────────────────────────────
export function initSync() {
  // Periodic retry
  setInterval(() => { flush().catch(() => {}); }, FLUSH_INTERVAL_MS);

  // Reconnect → immediate flush
  window.addEventListener('online',  () => { flush().catch(() => {}); });

  // Background Sync API (best effort)
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready
      .then((reg) => reg.sync.register('tl-sync-queue'))
      .catch(() => { /* ignore — periodic retry covers us */ });
  }

  // Service worker pings us when sync event fires
  navigator.serviceWorker?.addEventListener('message', (e) => {
    if (e.data?.type === 'SYNC_NOW') flush().catch(() => {});
  });

  // First flush
  if (navigator.onLine) flush().catch(() => {});
}

// ─── Internals ──────────────────────────────────────────────────────
function _emit(event, action, info) {
  for (const fn of _listeners[event] || []) {
    try { fn(action, info); } catch (e) { console.error(e); }
  }
}
