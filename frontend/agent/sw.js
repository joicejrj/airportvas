// Minimal install-friendly service worker for the Agent portal.
// Caches the shell on install, network-first for everything else.

const CACHE = 'airvas-agent-v1';
const SHELL = [
  '/agent/',
  '/agent/index.html',
  '/agent/manifest.json',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL).catch(()=>{})));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first; fall back to cache only when offline.
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  // Skip cross-origin
  if (!event.request.url.startsWith(self.location.origin)) return;

  event.respondWith(
    fetch(event.request)
      .then((res) => {
        // Cache successful navigations/static assets within /agent/
        if (res.ok && event.request.url.includes('/agent/')) {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(event.request, copy));
        }
        return res;
      })
      .catch(() => caches.match(event.request))
  );
});