// pwa/service-worker.js
// Airport Parking PWA — Service Worker
// Handles: offline caching, background sync, push notifications

const CACHE_NAME     = 'ap-pwa-v1';
const SYNC_TAG       = 'ap-offline-sync';
const API_BASE       = '/api';

const STATIC_ASSETS = [
    '/',
    '/agent/index.html',
    '/provider/index.html',
    '/shared/styles.css',
    '/pwa/db.js',
    '/pwa/sync-engine.js',
    '/iconspwa/provider-192.png',
    '/icons/icon-512.png',
];

// ── INSTALL ───────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// ── ACTIVATE ──────────────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k !== CACHE_NAME)
                    .map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// ── FETCH — Network-first for API, Cache-first for static ─────────────────
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // API calls: network-first with offline fallback
    if (url.pathname.startsWith(API_BASE)) {
        event.respondWith(networkFirstApi(event.request));
        return;
    }

    // Static assets: cache-first
    event.respondWith(cacheFirst(event.request));
});

async function networkFirstApi(request) {
    try {
        const response = await fetch(request.clone());
        // Cache GET API responses for offline reads
        if (request.method === 'GET' && response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        // Offline: return cached API response if available
        const cached = await caches.match(request);
        if (cached) return cached;
        // No cache: return offline JSON
        return new Response(
            JSON.stringify({ error: 'offline', message: 'No cached data available' }),
            { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
    }
}

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        return new Response('Offline', { status: 503 });
    }
}

// ── BACKGROUND SYNC ───────────────────────────────────────────────────────
self.addEventListener('sync', (event) => {
    if (event.tag === SYNC_TAG) {
        event.waitUntil(processSyncQueue());
    }
});

async function processSyncQueue() {
    // Import sync engine logic
    const { SyncQueue } = await import('/pwa/sync-engine.js');
    const queue = new SyncQueue();

    try {
        const pendingActions = await queue.getPendingActions();
        if (pendingActions.length === 0) return;

        const batches = chunkArray(pendingActions, 20);

        for (const batch of batches) {
            const response = await fetch(`${API_BASE}/sync`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ actions: batch }),
            });

            if (!response.ok) {
                if (response.status >= 500) {
                    // Server error: all items stay pending for retry
                    await queue.incrementRetryCount(batch.map(a => a.id));
                }
                continue;
            }

            const result = await response.json();

            // Mark successes
            for (const item of result.results?.success ?? []) {
                await queue.markSynced(item.action_id, item.server_id);
            }
            // Mark failures
            for (const item of result.results?.failed ?? []) {
                await queue.markFailed(item.action_id, item.reason);
            }
            // Handle conflicts
            for (const item of result.results?.conflicts ?? []) {
                await queue.markConflict(item.action_id, item);
            }
        }

        // Notify clients of sync completion
        const clients = await self.clients.matchAll();
        clients.forEach(client => client.postMessage({
            type: 'SYNC_COMPLETE',
            timestamp: Date.now(),
        }));

    } catch (err) {
        console.error('[SW] Sync failed:', err);
        // Will retry on next sync event
    }
}

// ── PUSH NOTIFICATIONS ────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
    console.log('[SW PUSH] event received!');
    console.log('[SW PUSH] has data:', !!event.data);

    if (!event.data) {
        console.warn('[SW PUSH] event has no data — ignoring');
        return;
    }

    let payload;
    try {
        payload = event.data.json();
        console.log('[SW PUSH] payload parsed:', payload);
    } catch (e) {
        console.warn('[SW PUSH] JSON parse failed:', e);
        payload = { title: 'Airport Parking', body: event.data.text(), data: {} };
    }

    const inner = payload.data || {};

    const options = {
        body:      payload.body || '',
        icon:      '/iconspwa/provider-192.png',
        badge:     '/icons/badge-96.png',
        data:      inner,
        actions:   buildActions(payload.type),
        vibrate:   [200, 100, 200],
        tag:       inner.service_id || inner.order_id || 'ap-notification',
        renotify:  true,
        requireInteraction: payload.type === 'new_assignment', // sticky until tapped
    };

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Airport Parking', options)
    );
});

function buildActions(type) {
    if (type === 'new_assignment') {
        return [
            { action: 'accept', title: '✓ Accept' },
            { action: 'reject', title: '✗ Reject' },
        ];
    }
    return [{ action: 'view', title: 'View' }];
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data    = event.notification.data || {};
    const action  = event.action;
    const svcId   = data.service_id;

    if (action === 'accept' && svcId) {
        event.waitUntil(handleQuickAction(svcId, 'accept'));
        return;
    }
    if (action === 'reject' && svcId) {
        event.waitUntil(handleQuickAction(svcId, 'reject'));
        return;
    }

    // Default: open the relevant page. `data.url` is set by the server.
    const targetUrl = data.url
        || (svcId ? `/provider/index.html#job/${svcId}` : '/provider/index.html');

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            // Reuse an existing window if one is already on this origin.
            for (const c of clients) {
                if ('focus' in c) {
                    c.focus();
                    if ('navigate' in c) c.navigate(targetUrl).catch(() => {});
                    return;
                }
            }
            return self.clients.openWindow(targetUrl);
        })
    );
});

async function handleQuickAction(serviceId, action) {
    // Quick accept/reject directly from the notification, without opening the app.
    // Queues into the offline sync engine so it works even with flaky network.
    try {
        const { SyncQueue } = await import('/pwa/sync-engine.js');
        const queue = new SyncQueue();

        await queue.addAction({
            entity_type: 'service',
            entity_id:   serviceId,
            action_type: 'status',
            payload: {
                id:     serviceId,
                status: action === 'accept' ? 'accepted' : 'rejected',
            },
        });

        try { await processSyncQueue(); } catch { /* will retry */ }
    } catch (err) {
        console.error('[SW] quick action failed:', err);
    }
}

function chunkArray(arr, size) {
    const chunks = [];
    for (let i = 0; i < arr.length; i += size) {
        chunks.push(arr.slice(i, i + size));
    }
    return chunks;
}
