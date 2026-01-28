const CACHE_NAME = '3zbawyh-v2'; // غيّر الرقم مع أي تحديث

const STATIC_ASSETS = [
  '/3zbawyh/assets/style.css',
  '/3zbawyh/icons/favicon.png',
  '/3zbawyh/icons/elezbawiya.png'
];

// install
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
  );
});

// activate
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => key !== CACHE_NAME && caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

// fetch
self.addEventListener('fetch', event => {
  const req = event.request;

  // ✅ أي صفحة HTML (زي login.php) = من السيرفر دايمًا
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req));
    return;
  }

  // ✅ ملفات ثابتة = cache first
  event.respondWith(
    caches.match(req).then(res => res || fetch(req))
  );
});
