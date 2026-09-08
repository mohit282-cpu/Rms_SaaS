// service-worker.js - Production-Ready Service Worker for RMS SaaS
// Strategy: Cache-First for static assets, Network-Only for APIs, auth, POS, KDS & dynamic pages.

const CACHE_NAME = 'rms-static-v1.0.0';

// Immutable & Static Assets to Cache
const PRECACHE_ASSETS = [
  './',
  'manifest.json',
  'css/modern.css',
  'css/spatial.css',
  'css/style.css',
  'js/modern.js',
  'js/script.js',
  'js/pwa-app.js',
  'images/icon-192.png',
  'images/icon-512.png',
  'images/icon-180.png',
  'images/icon-512-maskable.png',
  'images/favicon.png'
];

// Paths that MUST NEVER be cached under any circumstances (Tenant/Auth Isolation & Operational Freshness)
const NEVER_CACHE_PATTERNS = [
  /\/api\//i,
  /\/admin\//i,
  /\/super-admin\//i,
  /checkout\.php/i,
  /place-order\.php/i,
  /cart\.php/i,
  /receipt\.php/i,
  /kitchen-dashboard\.php/i,
  /kitchen-menu\.php/i,
  /login/i,
  /logout/i
];

// Install Event: Cache Precache Assets & Skip Waiting
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(PRECACHE_ASSETS).catch((err) => {
        console.warn('[RMS SW] Static precache warning:', err);
      });
    }).then(() => {
      return self.skipWaiting();
    })
  );
});

// Activate Event: Purge Obsolete Caches & Claim Clients Immediately
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName.startsWith('rms-')) {
            console.log('[RMS SW] Purging obsolete cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      return self.clients.claim();
    })
  );
});

// Fetch Event: Implement Cache-First for static assets, Network-Only for dynamic content
self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only handle GET requests
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  // Check if URL matches NEVER_CACHE_PATTERNS
  const isDynamicOrApi = NEVER_CACHE_PATTERNS.some((pattern) => pattern.test(url.pathname));

  if (isDynamicOrApi) {
    // Network Only - Do not intercept or cache
    return;
  }

  // Handle static asset requests (Cache First, Network Fallback)
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }
      return fetch(request).then((networkResponse) => {
        // Cache valid static responses (CSS, JS, Images, Fonts)
        if (
          networkResponse &&
          networkResponse.status === 200 &&
          networkResponse.type === 'basic' &&
          /\.(css|js|png|jpg|jpeg|svg|webp|woff|woff2|ttf|eot|ico)$/i.test(url.pathname)
        ) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(request, responseToCache);
          });
        }
        return networkResponse;
      }).catch((err) => {
        console.warn('[RMS SW] Fetch error for resource:', request.url, err);
      });
    })
  );
});
