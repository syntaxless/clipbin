// Pastey service worker. Its only job: receive files shared from the
// phone's share sheet (Android "Share to Pastey") and hand them to the page.
// It deliberately caches nothing else, so the site never goes stale.
const SHARE_CACHE = 'pastey-share';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'POST' || !url.searchParams.has('share-target')) return;
  event.respondWith((async () => {
    try {
      const form = await event.request.formData();
      // "video" is the field name in the manifest; it carries photos and audio too.
      const files = form.getAll('video').filter((f) => f && f.size);
      const cache = await caches.open(SHARE_CACHE);
      let i = 0;
      for (const f of files) {
        await cache.put(`shared/${Date.now()}-${i++}`, new Response(f, {
          headers: { 'Content-Type': f.type || 'application/octet-stream', 'X-Name': encodeURIComponent(f.name || 'shared') },
        }));
      }
    } catch (err) {
      // fall through to the page either way
    }
    return Response.redirect(new URL('./?shared=1', self.registration.scope).href, 303);
  })());
});
