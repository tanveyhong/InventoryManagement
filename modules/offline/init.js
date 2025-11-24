if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Determine the correct path to the service worker
        // If we are at root (index.php), path is modules/offline/service_worker.php
        // If we are in a module (modules/stock/list.php), path is ../../modules/offline/service_worker.php
        
        // A more robust way is to use an absolute path if we know the app root
        // Or we can try to detect the base path.
        
        // Let's assume the app is hosted at /InventorySystem/ or /
        // We can find the path relative to the current location
        
        let swPath = 'modules/offline/service_worker.php';
        
        // Check if we are deep in the directory structure
        if (window.location.pathname.includes('/modules/')) {
            // Count how many levels deep we are
            const parts = window.location.pathname.split('/');
            const modulesIndex = parts.indexOf('modules');
            if (modulesIndex !== -1) {
                const depth = parts.length - modulesIndex - 1;
                // Add ../ for each level deep
                let prefix = '';
                for (let i = 0; i < depth; i++) {
                    prefix += '../';
                }
                swPath = prefix + 'modules/offline/service_worker.php';
            }
        }
        
        // Register with root scope to control the entire app
        navigator.serviceWorker.register(swPath, { scope: './' }) // Use ./ scope relative to where the SW file is located? 
        // No, we want the scope to be the app root.
        // If the SW is at /InventorySystem/modules/offline/service_worker.php
        // And we want scope /InventorySystem/
        // We need to set scope: '../../' relative to the SW file location?
        // Actually, the scope is resolved relative to the script URL.
        
        // Let's try a different approach. We will use the absolute path if possible.
        // But since we don't know the domain root, let's try to construct it.
        
        // Better approach: The SW file itself sends 'Service-Worker-Allowed: /'
        // So we can register it with scope: '/' (or the app root)
        
        // Let's try to find the app root based on the location of this script?
        // No, this is a JS file.
        
        // Let's just try to register it with a scope that covers the whole app.
        // If we are at /InventorySystem/index.php, scope './' covers /InventorySystem/
        
        // If we are at /InventorySystem/modules/stock/list.php, scope '../../' covers /InventorySystem/
        
        let scope = './';
        if (window.location.pathname.includes('/modules/')) {
             const parts = window.location.pathname.split('/');
             const modulesIndex = parts.indexOf('modules');
             if (modulesIndex !== -1) {
                 const depth = parts.length - modulesIndex - 1;
                 scope = '';
                 for (let i = 0; i < depth; i++) {
                     scope += '../';
                 }
             }
        }
        
        navigator.serviceWorker.register(swPath, { scope: scope })
            .then((registration) => {
                console.log('Service Worker registered with scope:', registration.scope);
                
                // Check for updates
                registration.update();
                
                // Start Cache Warmer (Background Pre-caching)
                // We do this here because fetch() from the page includes cookies/session,
                // allowing us to cache protected pages that the SW install event cannot access.
                warmCache(scope);
            })
            .catch((error) => {
                console.error('Service Worker registration failed:', error);
            });
    });
}

// Function to pre-cache key modules in the background
function warmCache(baseUrl) {
    // Check if Service Worker is actually controlling the page
    // If not, these requests won't be cached, so we should wait or skip
    if (!navigator.serviceWorker.controller) {
        console.log('Service Worker not yet controlling page. Waiting for controller...');
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('Controller acquired. Starting cache warming...');
            warmCache(baseUrl);
        });
        return;
    }

    // Optimization: Don't run if we just did it recently (e.g., last 5 minutes)
    // Reduced time for testing purposes
    const lastWarmed = localStorage.getItem('offline_cache_last_warmed');
    const now = Date.now();
    if (lastWarmed && (now - parseInt(lastWarmed)) < 5 * 60 * 1000) {
        console.log('Cache warmed recently, skipping...');
        // return; // Commented out to force retry for debugging
    }

    // Ensure baseUrl ends with / if it's not empty
    if (baseUrl !== '' && !baseUrl.endsWith('/')) {
        baseUrl += '/';
    }
    
    const modulesToCache = [
        'index.php',
        'modules/stock/list.php',
        'modules/stores/list.php',
        'modules/stores/map.php',
        'modules/users/profile.php',
        'modules/reports/inventory_report.php',
        'modules/alerts/expiry_alert.php',
        'modules/forecasting/index.php',
        'modules/purchase_orders/list.php',
        'modules/suppliers/list.php',
        'modules/alerts/alert_history.php',
        'modules/stock/stockAuditHis.php',
        'modules/users/management.php',
        'modules/pos/stock_pos_integration.php',
        'pos_terminal.php',
        
        // Activity Log, Permissions, Store Access
        'modules/users/activity.php',
        'modules/users/roles.php',
        
        // API Endpoints (Pre-cache for offline use)
        // Note: These must match EXACTLY what the frontend requests
        'modules/users/profile/api.php?action=get_activities&limit=1000&user_id=all',
        'modules/users/profile/api.php?action=get_all_users',
        'modules/users/profile/api.php?action=get_all_users&exclude_admins=true',
        'modules/users/profile/api.php?action=get_all_users&include_deleted=true',
        'modules/users/profile/api.php?action=get_permissions', // For own permissions
        'modules/users/profile/api.php?action=get_stores'       // For own store access
    ];
    
    console.log('Starting background cache warming...');
    
    // Fetch each module sequentially to avoid overwhelming the network
    let chain = Promise.resolve();
    
    modulesToCache.forEach(url => {
        // Construct full relative path
        // If baseUrl is './', url becomes './index.php'
        // If baseUrl is '../../', url becomes '../../index.php'
        const fullUrl = baseUrl + url;
        
        chain = chain.then(() => {
            return fetch(fullUrl, { 
                method: 'GET',
                credentials: 'include', // Send cookies
                cache: 'no-cache' // Force network request
            })
            .then(response => {
                if (response.ok) {
                    console.log('Cached:', fullUrl);
                } else {
                    console.warn('Failed to cache:', fullUrl, response.status);
                }
            })
            .catch(err => {
                console.warn('Error caching:', fullUrl, err);
            });
        });
    });
    
    chain.then(() => {
        console.log('Background cache warming complete.');
        localStorage.setItem('offline_cache_last_warmed', Date.now().toString());
    });
}

// Offline Status Notification
window.addEventListener('load', () => {
    function updateOnlineStatus() {
        const status = navigator.onLine ? 'online' : 'offline';
        
        if (status === 'offline') {
            showOfflineBanner();
        } else {
            hideOfflineBanner();
            // Optional: Show "Back Online" toast if we were previously offline
            if (document.body.classList.contains('was-offline')) {
                showOnlineToast();
                document.body.classList.remove('was-offline');
            }
        }
    }

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', () => {
        document.body.classList.add('was-offline');
        updateOnlineStatus();
    });

    // Check initial status
    updateOnlineStatus();
});

function showOfflineBanner() {
    // Check if banner already exists
    if (document.getElementById('offline-mode-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'offline-mode-banner';
    banner.style.cssText = `
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background-color: #e74c3c;
        color: white;
        text-align: center;
        padding: 12px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-weight: 500;
        z-index: 99999;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        transform: translateY(100%);
        transition: transform 0.3s ease-out;
    `;
    
    banner.innerHTML = `
        <i class="fas fa-wifi-slash"></i>
        <span><strong>You are currently offline.</strong> You can view cached pages, but changes may not be saved.</span>
    `;
    
    document.body.appendChild(banner);
    
    // Animate in
    requestAnimationFrame(() => {
        banner.style.transform = 'translateY(0)';
    });
}

function hideOfflineBanner() {
    const banner = document.getElementById('offline-mode-banner');
    if (banner) {
        banner.style.transform = 'translateY(100%)';
        setTimeout(() => {
            banner.remove();
        }, 300);
    }
}

function showOnlineToast() {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background-color: #2ecc71;
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-weight: 500;
        z-index: 99999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideInRight 0.5s ease-out forwards;
    `;
    
    toast.innerHTML = `
        <i class="fas fa-wifi"></i>
        <span>Back Online!</span>
    `;
    
    document.body.appendChild(toast);
    
    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.5s ease';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

// Add keyframes for toast animation if not exists
if (!document.getElementById('offline-animations')) {
    const style = document.createElement('style');
    style.id = 'offline-animations';
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
}
