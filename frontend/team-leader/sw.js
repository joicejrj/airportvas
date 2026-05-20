// frontend/team-leader/sw.js
//
// Strategy:
//   • Shell (HTML, JS, CSS, fonts) — cache-first, falls back to network.
//   • API calls (/api/*)            — network-first, never cached (we have
//                                      IndexedDB for offline state).
//   • Other navigations            — fall through to the cached shell so
//                                      the SPA loads when offline.

const VERSION = 'tl-v1.0.5';
const SHELL_CACHE = `tl-shell-${VERSION}`;

// Files that make up the app shell.
const SHELL_ASSETS = [
  '/team-leader/',
  '/team-leader/index.html',
  '/team-leader/app.js',
  '/team-leader/api.js',
  '/team-leader/state.js',
  '/team-leader/manifest.json',
  '/team-leader/pwa/db.js',
  '/team-leader/pwa/sync-engine.js',
];

// ─── Install: pre-cache the shell ───────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL_CACHE);
    // Cache each asset individually so one bad URL doesn't fail the whole install
    await Promise.all(
      SHELL_ASSETS.map(async (url) => {
        try {
          await cache.add(url);
        } catch (e) {
          console.warn('[sw] precache miss:', url, e);
        }
      })
    );
    self.skipWaiting();
  })());
});

// ─── Activate: drop old caches, take control ────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((k) => k.startsWith('tl-shell-') && k !== SHELL_CACHE)
        .map((k) => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

// ─── Fetch routing ──────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;   // POST/PATCH/etc. go straight to network

  const url = new URL(req.url);

  // API: network only. Never cache; offline reads come from IndexedDB.
  if (url.pathname.startsWith('/api/')) {
    return; // let the browser handle it (no caching layer for API)
  }

  // Navigations: shell-first
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(req);
      } catch {
        const shell = await caches.match('/team-leader/index.html');
        return shell || new Response('Offline', { status: 503 });
      }
    })());
    return;
  }

  // Other GETs (JS/CSS/images): cache-first with background refresh
  event.respondWith((async () => {
    const cached = await caches.match(req);
    const fetched = fetch(req).then(async (res) => {
      if (res && res.ok && url.origin === self.location.origin) {
        const cache = await caches.open(SHELL_CACHE);
        cache.put(req, res.clone());
      }
      return res;
    }).catch(() => null);
    return cached || (await fetched) || new Response('', { status: 504 });
  })());
});

// ─── Background sync — kicks the sync engine when we come online ────
self.addEventListener('sync', (event) => {
  if (event.tag === 'tl-sync-queue') {
    event.waitUntil((async () => {
      const clients = await self.clients.matchAll();
      for (const client of clients) {
        client.postMessage({ type: 'SYNC_NOW' });
      }
    })());
  }
});

// ─── Allow the page to trigger skipWaiting on update ────────────────
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});