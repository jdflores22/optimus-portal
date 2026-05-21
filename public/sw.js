// Service Worker for Optimus Shipping PWA
// Handles push notifications, offline caching, and background sync

const CACHE_NAME = 'optimus-v7';
const OFFLINE_CACHE = 'optimus-offline-v7';

// Resources to cache for offline functionality - use relative URLs only
// DO NOT cache root URL as it redirects to /login
// DO NOT cache /login as it contains CSRF tokens that must be fresh
const CACHE_URLS = [
  '/css/app.css',
  '/js/notifications.js'
];

// Install event - cache critical resources
self.addEventListener('install', (event) => {
  console.log('[Service Worker] Installing...');
  
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('[Service Worker] Caching app shell');
        // Cache resources one by one to avoid failures
        return Promise.allSettled(
          CACHE_URLS.map(url => 
            cache.add(url).catch(err => {
              console.warn('[Service Worker] Failed to cache:', url, err);
            })
          )
        );
      })
      .then(() => {
        console.log('[Service Worker] Install complete, skipping waiting');
        return self.skipWaiting();
      })
      .catch(err => {
        console.error('[Service Worker] Install failed:', err);
        // Continue anyway - service worker can still handle push notifications
        return self.skipWaiting();
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  console.log('[Service Worker] Activating...');
  
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName !== OFFLINE_CACHE) {
            console.log('[Service Worker] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      console.log('[Service Worker] Claiming clients');
      return self.clients.claim();
    })
  );
});

// Fetch event - serve from cache when offline
self.addEventListener('fetch', (event) => {
  const requestUrl = event.request.url;
  
  // Skip invalid URLs
  try {
    const url = new URL(requestUrl);
    
    // Skip if not http/https
    if (!url.protocol.startsWith('http')) {
      console.log('[Service Worker] Skipping non-http request:', requestUrl);
      return;
    }
    
    // Skip if not same origin
    if (url.origin !== self.location.origin) {
      console.log('[Service Worker] Skipping cross-origin request:', requestUrl);
      return;
    }
    
    // Skip if not GET request
    if (event.request.method !== 'GET') {
      return;
    }
    
  } catch (error) {
    console.error('[Service Worker] Invalid URL:', requestUrl, error);
    return;
  }
  
  event.respondWith(
    (async () => {
      try {
        const url = new URL(event.request.url);
        
        // NEVER cache or serve root URL from cache - always fetch fresh to allow redirect
        if (url.pathname === '/') {
          console.log('[Service Worker] Root URL - fetching fresh to allow redirect');
          return fetch(event.request);
        }
        
        // NEVER cache /login - it contains CSRF tokens that must be fresh for PWA
        if (url.pathname === '/login') {
          console.log('[Service Worker] Login page - fetching fresh for CSRF token');
          return fetch(event.request, {
            cache: 'no-store',
            headers: {
              'Cache-Control': 'no-cache, no-store, must-revalidate',
              'Pragma': 'no-cache'
            }
          });
        }
        
        // Check if this is a static asset (CSS, JS, images, fonts)
        const isStaticAsset = /\.(css|js|jpg|jpeg|png|gif|svg|woff|woff2|ttf|eot|ico)$/i.test(url.pathname);
        
        // For static assets, check cache first
        if (isStaticAsset) {
          const cachedResponse = await caches.match(event.request);
          if (cachedResponse) {
            console.log('[Service Worker] Serving static asset from cache:', url.pathname);
            return cachedResponse;
          }
        }
        
        // For HTML pages and API calls, ALWAYS fetch fresh from network
        console.log('[Service Worker] Fetching fresh from network:', url.pathname);
        
        // Fetch from network
        const fetchResponse = await fetch(event.request);
        
        // Don't cache redirects (3xx), error responses, or opaque responses
        if (!fetchResponse || 
            fetchResponse.status < 200 ||
            fetchResponse.status >= 300 ||
            fetchResponse.type === 'opaque' ||
            fetchResponse.type === 'error') {
          console.log('[Service Worker] Not caching response (status:', fetchResponse?.status, 'type:', fetchResponse?.type, ')');
          return fetchResponse;
        }
        
        // Only cache static assets (not HTML pages)
        if (isStaticAsset) {
          try {
            const responseUrl = new URL(fetchResponse.url);
            if (responseUrl.origin === self.location.origin) {
              const responseToCache = fetchResponse.clone();
              const cache = await caches.open(CACHE_NAME);
              await cache.put(event.request, responseToCache);
              console.log('[Service Worker] Cached static asset:', url.pathname);
            }
          } catch (error) {
            console.warn('[Service Worker] Error caching response:', error);
          }
        }
        
        return fetchResponse;
        
      } catch (error) {
        console.log('[Service Worker] Fetch failed:', error);
        
        // Return offline page if available
        try {
          const offlineResponse = await caches.match('/offline.html');
          if (offlineResponse) {
            return offlineResponse;
          }
        } catch (e) {
          console.error('[Service Worker] Offline page not available:', e);
        }
        
        // If offline page not available, return a basic response
        return new Response('Offline', {
          status: 503,
          statusText: 'Service Unavailable',
          headers: new Headers({
            'Content-Type': 'text/plain'
          })
        });
      }
    })()
  );
});

// Push event - receive and display push notifications
self.addEventListener('push', (event) => {
  console.log('[Service Worker] Push notification received');
  
  let notificationData = {
    title: 'Optimus Shipping',
    message: 'You have a new notification',
    icon: '/images/notification-icon.svg',
    badge: '/images/badge.svg',
    url: '/notifications',
    id: null
  };
  
  // Parse notification data if available
  if (event.data) {
    try {
      const data = event.data.json();
      notificationData = {
        title: data.title || notificationData.title,
        message: data.message || notificationData.message,
        icon: data.icon || notificationData.icon,
        badge: data.badge || notificationData.badge,
        url: data.url || notificationData.url,
        id: data.id || notificationData.id,
        type: data.type || 'general'
      };
    } catch (e) {
      console.error('[Service Worker] Error parsing push data:', e);
    }
  }
  
  const options = {
    body: notificationData.message,
    icon: notificationData.icon,
    badge: notificationData.badge,
    data: {
      url: notificationData.url,
      notificationId: notificationData.id,
      type: notificationData.type
    },
    vibrate: [200, 100, 200],
    tag: notificationData.type || 'general',
    requireInteraction: false,
    actions: [
      {
        action: 'open',
        title: 'View'
      },
      {
        action: 'close',
        title: 'Dismiss'
      }
    ]
  };
  
  event.waitUntil(
    self.registration.showNotification(notificationData.title, options)
  );
});

// Notification click event - handle user interaction with notifications
self.addEventListener('notificationclick', (event) => {
  console.log('[Service Worker] Notification clicked:', event.action);
  
  event.notification.close();
  
  // Handle action buttons
  if (event.action === 'close') {
    return;
  }
  
  // Get the URL from notification data
  const urlToOpen = event.notification.data.url || '/';
  
  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then((clientList) => {
      // Check if there's already a window open
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if (client.url === urlToOpen && 'focus' in client) {
          return client.focus();
        }
      }
      
      // If no window is open, open a new one
      if (clients.openWindow) {
        return clients.openWindow(urlToOpen);
      }
    })
  );
});

// Background sync event - handle queued actions when online
self.addEventListener('sync', (event) => {
  console.log('[Service Worker] Background sync:', event.tag);
  
  if (event.tag === 'sync-notifications') {
    event.waitUntil(syncNotifications());
  }
});

// Helper function to sync notifications
async function syncNotifications() {
  try {
    // Fetch any pending notifications from the server
    const response = await fetch('/api/notifications/pending');
    if (response.ok) {
      const notifications = await response.json();
      console.log('[Service Worker] Synced notifications:', notifications.length);
    }
  } catch (error) {
    console.error('[Service Worker] Sync failed:', error);
  }
}

// Message event - handle messages from the main app
self.addEventListener('message', (event) => {
  console.log('[Service Worker] Message received:', event.data);
  
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'CACHE_URLS') {
    event.waitUntil(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.addAll(event.data.urls);
      })
    );
  }
});
