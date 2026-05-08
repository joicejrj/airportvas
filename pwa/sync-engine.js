// pwa/sync-engine.js
// Airport Parking — Client-side Sync Engine
//
// Responsibilities:
//   1. Add offline actions to IndexedDB queue
//   2. Process queue FIFO (oldest first)
//   3. Retry with exponential backoff
//   4. Detect conflicts and surface them to the UI
//   5. Register Background Sync (+ polling fallback)

import { openDB, dbPut, dbGet, dbGetByIndex, dbDelete } from './indexeddb/db.js';

const SYNC_TAG       = 'ap-offline-sync';
const MAX_RETRIES    = 5;
const BASE_BACKOFF_MS = 2000;   // 2s, 4s, 8s, 16s, 32s
const POLL_INTERVAL_MS = 30_000; // 30s fallback polling

let _pollTimer = null;

// ── UUID GENERATOR ────────────────────────────────────────────────────────
function uuidv4() {
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
}

// ─────────────────────────────────────────────────────────────────────────
// SyncQueue
// ─────────────────────────────────────────────────────────────────────────
export class SyncQueue {

    /**
     * Add an action to the offline queue.
     *
     * @param {object} action
     *   entity_type  — 'order' | 'service' | 'payment'
     *   entity_id    — existing record id, or null for creates
     *   action_type  — 'create' | 'update' | 'status' | 'payment'
     *   payload      — action-specific data
     *
     * @returns {string} action id (UUID)
     */
    async addAction(action) {
        const id = uuidv4();
        const item = {
            id,
            entity_type:  action.entity_type,
            entity_id:    action.entity_id ?? null,
            action_type:  action.action_type,
            payload:      action.payload ?? {},
            created_at:   new Date().toISOString(),
            retry_count:  0,
            last_attempt: null,
            sync_status:  'pending',   // pending | syncing | success | failed | conflict
        };

        await dbPut('sync_queue', item);
        this._triggerSync();
        return id;
    }

    /**
     * Get all pending actions, sorted FIFO.
     */
    async getPendingActions() {
        const all = await dbGetByIndex('sync_queue', 'sync_status', 'pending');
        return all.sort((a, b) => a.created_at.localeCompare(b.created_at));
    }

    /**
     * Get all actions regardless of status (for UI display).
     */
    async getAllActions() {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx  = db.transaction('sync_queue', 'readonly');
            const req = tx.objectStore('sync_queue').getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }

    async markSynced(actionId, serverId = null) {
        const item = await dbGet('sync_queue', actionId);
        if (!item) return;
        await dbPut('sync_queue', {
            ...item,
            sync_status:  'success',
            server_id:    serverId,
            last_attempt: new Date().toISOString(),
        });
    }

    async markFailed(actionId, reason = '') {
        const item = await dbGet('sync_queue', actionId);
        if (!item) return;
        const newCount = item.retry_count + 1;
        await dbPut('sync_queue', {
            ...item,
            sync_status:  newCount >= MAX_RETRIES ? 'failed' : 'pending',
            retry_count:  newCount,
            last_attempt: new Date().toISOString(),
            fail_reason:  reason,
        });
    }

    async markConflict(actionId, conflictData) {
        const item = await dbGet('sync_queue', actionId);
        if (!item) return;
        await dbPut('sync_queue', {
            ...item,
            sync_status:   'conflict',
            conflict_data: conflictData,
            last_attempt:  new Date().toISOString(),
        });
        // Also save to conflict_log for user review
        await dbPut('conflict_log', {
            action_id:     actionId,
            entity_type:   item.entity_type,
            action_type:   item.action_type,
            payload:       item.payload,
            conflict_data: conflictData,
            created_at:    new Date().toISOString(),
        });
        // Notify UI
        window.dispatchEvent(new CustomEvent('sync:conflict', { detail: { actionId, conflictData } }));
    }

    async incrementRetryCount(actionIds) {
        for (const id of actionIds) {
            const item = await dbGet('sync_queue', id);
            if (item) {
                await dbPut('sync_queue', {
                    ...item,
                    retry_count:  item.retry_count + 1,
                    last_attempt: new Date().toISOString(),
                });
            }
        }
    }

    /**
     * Clear successfully synced items older than 24h.
     */
    async prune() {
        const cutoff = new Date(Date.now() - 86_400_000).toISOString();
        const all    = await this.getAllActions();
        for (const item of all) {
            if (item.sync_status === 'success' && item.last_attempt < cutoff) {
                await dbDelete('sync_queue', item.id);
            }
        }
    }

    // ── SYNC TRIGGER ─────────────────────────────────────────────────────

    _triggerSync() {
        if ('serviceWorker' in navigator && 'sync' in ServiceWorkerRegistration.prototype) {
            navigator.serviceWorker.ready
                .then(reg => reg.sync.register(SYNC_TAG))
                .catch(() => this._startPollingFallback());
        } else {
            this._startPollingFallback();
        }
    }

    _startPollingFallback() {
        if (_pollTimer) return;
        _pollTimer = setInterval(() => {
            if (navigator.onLine) {
                this.flushOnline();
            }
        }, POLL_INTERVAL_MS);
    }

    stopPolling() {
        if (_pollTimer) {
            clearInterval(_pollTimer);
            _pollTimer = null;
        }
    }

    // ── FOREGROUND FLUSH (called when coming online) ──────────────────────

    async flushOnline() {
        const pending = await this.getPendingActions();
        if (pending.length === 0) return;

        // Filter out items in backoff window
        const now   = Date.now();
        const ready = pending.filter(item => {
            if (item.retry_count === 0 || !item.last_attempt) return true;
            const backoff = Math.min(BASE_BACKOFF_MS * Math.pow(2, item.retry_count - 1), 60_000);
            return (now - new Date(item.last_attempt).getTime()) >= backoff;
        });

        if (ready.length === 0) return;

        const BATCH_SIZE = 20;
        for (let i = 0; i < ready.length; i += BATCH_SIZE) {
            const batch = ready.slice(i, i + BATCH_SIZE);
            await this._sendBatch(batch);
        }
    }

    async _sendBatch(actions) {
        let response;
        try {
            response = await fetch('/api/sync', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${getAuthToken()}`,
                },
                body: JSON.stringify({ actions }),
            });
        } catch (networkErr) {
            // Offline: increment retry counts
            await this.incrementRetryCount(actions.map(a => a.id));
            return;
        }

        if (!response.ok) {
            await this.incrementRetryCount(actions.map(a => a.id));
            return;
        }

        const result = await response.json();

        for (const item of result.results?.success ?? []) {
            await this.markSynced(item.action_id, item.server_id);
        }
        for (const item of result.results?.failed ?? []) {
            await this.markFailed(item.action_id, item.reason);
        }
        for (const item of result.results?.conflicts ?? []) {
            await this.markConflict(item.action_id, item);
        }

        // Notify UI
        window.dispatchEvent(new CustomEvent('sync:complete', {
            detail: result.results
        }));
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────

function getAuthToken() {
    return localStorage.getItem('ap_token') ?? '';
}

// ── CONVENIENCE FUNCTIONS ─────────────────────────────────────────────────

const _queue = new SyncQueue();

/** Queue an order creation. Returns local action id. */
export async function queueOrderCreate(orderData) {
    return _queue.addAction({
        entity_type: 'order',
        entity_id:   null,
        action_type: 'create',
        payload:     { ...orderData, id: orderData.id ?? uuidv4() },
    });
}

/** Queue a payment. Generates uuid_ref for dedup. */
export async function queuePayment(orderId, paymentData) {
    return _queue.addAction({
        entity_type: 'payment',
        entity_id:   orderId,
        action_type: 'payment',
        payload: {
            order_id: orderId,
            uuid_ref: uuidv4(),  // dedup key
            ...paymentData,
        },
    });
}

/** Queue a service status change. */
export async function queueStatusChange(serviceId, newStatus, version) {
    return _queue.addAction({
        entity_type: 'service',
        entity_id:   serviceId,
        action_type: 'status',
        payload: { id: serviceId, status: newStatus, version },
    });
}

/** Initialize: seed catalogue, start polling, listen for online events. */
export async function initSync() {
    // Seed service catalogue from server (if online)
    if (navigator.onLine) {
        try {
            const res = await fetch('/api/services', {
                headers: { 'Authorization': `Bearer ${getAuthToken()}` }
            });
            if (res.ok) {
                const { data } = await res.json();
                const { seedCatalogue } = await import('./indexeddb/db.js');
                await seedCatalogue(data);
            }
        } catch { /* offline */ }
    }

    _queue._startPollingFallback();

    window.addEventListener('online', () => {
        console.log('[Sync] Back online — flushing queue');
        _queue.flushOnline();
    });

    // Listen for SW sync-complete messages
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data?.type === 'SYNC_COMPLETE') {
                window.dispatchEvent(new CustomEvent('sync:complete', { detail: event.data }));
            }
        });
    }
}

export { _queue as syncQueue, uuidv4 };
