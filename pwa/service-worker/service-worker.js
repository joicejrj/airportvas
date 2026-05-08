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
    '/icons/icon-192.png',
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
    if (!event.data) return;

    let data;
    try {
        data = event.data.json();
    } catch {
        data = { title: 'Airport Parking', body: event.data.text() };
    }

    const options = {
        body:    data.body  || '',
        icon:    '/icons/icon-192.png',
        badge:   '/icons/badge-96.png',
        data:    data.data  || {},
        actions: buildActions(data.type),
        vibrate: [200, 100, 200],
        tag:     data.data?.service_id || 'ap-notification',
        renotify: true,
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

function buildActions(type) {
    if (type === 'new_assignment') {
        return [
            { action: 'accept', title: 'Accept' },
            { action: 'reject', title: 'Reject' },
        ];
    }
    return [{ action: 'view', title: 'View' }];
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data    = event.notification.data;
    const action  = event.action;
    const svcId   = data.service_id;

    if (action === 'accept' && svcId) {
        event.waitUntil(handleQuickAction(svcId, 'accept'));
    } else if (action === 'reject' && svcId) {
        event.waitUntil(handleQuickAction(svcId, 'reject'));
    } else {
        // Open provider app
        event.waitUntil(
            self.clients.matchAll({ type: 'window' }).then(clients => {
                const url = data.order_id
                    ? `/provider/index.html#job/${svcId}`
                    : '/provider/index.html';
                if (clients.length > 0) {
                    clients[0].focus();
                    clients[0].navigate(url);
                } else {
                    self.clients.openWindow(url);
                }
            })
        );
    }
});

async function handleQuickAction(serviceId, action) {
    // Quick accept/reject directly from notification without opening app
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

    // Attempt immediate sync
    try {
        await processSyncQueue();
    } catch {
        // Will sync when online
    }
}

function chunkArray(arr, size) {
    const chunks = [];
    for (let i = 0; i < arr.length; i += size) {
        chunks.push(arr.slice(i, i + size));
    }
    return chunks;
}
