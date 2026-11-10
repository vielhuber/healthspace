let cacheName = 'healthspace-static-v2';
let offlineUrl = '/?asset=pwa/offline.html&v=2';
let staticUrls = [
    offlineUrl,
    '/?asset=css/healthspace.css&v=2',
    '/?asset=js/theme.js&v=2',
    '/?asset=js/charts.js&v=2',
    '/?asset=js/nutrition.js&v=2',
    '/?asset=js/pwa.js&v=2',
    '/?asset=js/chart.min.js&v=2',
    '/?asset=pwa/manifest.json&v=2',
    '/?asset=pwa/icon.svg',
    '/?asset=pwa/icon-192.png',
    '/?asset=pwa/icon-512.png',
    '/?asset=pwa/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(cacheName).then(async cache => {
            await cache.addAll(staticUrls.map(url => new Request(url, { cache: 'reload' })));
            await self.skipWaiting();
        })
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        (async () => {
            let names = await caches.keys();
            await Promise.all(
                names
                    .filter(name => name.startsWith('healthspace-static-') && name !== cacheName)
                    .map(name => caches.delete(name))
            );
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', event => {
    let url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    // Private pages and meal uploads must never be stored in the offline cache.
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => caches.match(offlineUrl)));
        return;
    }
    if (staticUrls.includes(url.pathname + url.search)) {
        event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
    }
});
