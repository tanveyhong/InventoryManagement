<?php
header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /');
?>
const CACHE_NAME = 'inventory-v10';
const ASSETS_TO_CACHE = [
    // Core App Shell (Static Assets Only)
    '../../assets/css/style.css',
    '../../assets/js/main.js',
    
    // External Libraries (CDNs)
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/chart.js',
    
    // Map Libraries (Leaflet)
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css',
    'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
    'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js'
];

self.addEventListener('install', (event) => {
    console.log('[Service Worker] Installing Service Worker ...', event);
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[Service Worker] Caching App Shell');
                return cache.addAll(ASSETS_TO_CACHE);
            })
            .catch((err) => {
                console.error('[Service Worker] Cache addAll failed:', err);
            })
    );
});

self.addEventListener('activate', (event) => {
    console.log('[Service Worker] Activating Service Worker ....', event);
    event.waitUntil(
        caches.keys().then((keyList) => {
            return Promise.all(keyList.map((key) => {
                if (key !== CACHE_NAME) {
                    console.log('[Service Worker] Removing old cache.', key);
                    return caches.delete(key);
                }
            }));
        })
    );
    return self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    // Skip cross-origin requests for now unless they are in our cache list
    // (The cache list has full URLs for CDNs, so caches.match handles them)
    
    event.respondWith(
        caches.match(event.request)
            .then((response) => {
                // 1. If it's in the cache, return it (Fastest)
                // But for HTML pages and API calls, we might want to try network first to get fresh data
                const isHTML = event.request.headers.get('accept').includes('text/html');
                const isAPI = event.request.url.includes('api.php');
                
                if (response && !isHTML && !isAPI) {
                    return response;
                }

                // 2. If not in cache (or it's HTML/API), try the network
                return fetch(event.request)
                    .then((networkResponse) => {
                        // Check if we received a valid response
                        // Allow 'cors' type for CDNs, and 'basic' for same-origin
                        if(!networkResponse || networkResponse.status !== 200 || (networkResponse.type !== 'basic' && networkResponse.type !== 'cors')) {
                            return networkResponse;
                        }

                        // Clone the response
                        const responseToCache = networkResponse.clone();

                        // Cache the fresh version
                        caches.open(CACHE_NAME)
                            .then((cache) => {
                                // Don't cache API calls or POST requests here, just pages and assets
                                if (event.request.method === 'GET') {
                                    cache.put(event.request, responseToCache);
                                }
                            });

                        return networkResponse;
                    })
                    .catch(() => {
                        // 3. Network failed (Offline). If we have a cached version (even if old), return it.
                        if (response) {
                            return response;
                        }
                        
                        // 4. If no cache and no network, return a basic offline response
                        // This prevents the "Failed to convert value to 'Response'" error
                        return new Response(
                            '<div style="font-family: sans-serif; text-align: center; padding: 50px;">' +
                            '<h1>You are offline</h1>' +
                            '<p>We couldn\'t load this page and it\'s not in your cache.</p>' +
                            '<p>Please check your internet connection and try again.</p>' +
                            '<button onclick="window.location.reload()" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 20px;">Retry</button>' +
                            '</div>', 
                            {
                                headers: { 'Content-Type': 'text/html' }
                            }
                        );
                    });
            })
    );
});
