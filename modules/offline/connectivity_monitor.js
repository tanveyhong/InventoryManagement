/**
 * Connectivity Monitor for Profile Module
 * Monitors online/offline status and provides visual feedback
 */

class ConnectivityMonitor {
    constructor() {
        this.isOnline = navigator.onLine;
        this.listeners = [];
        this.indicator = null;
        this.init();
    }

    /**
     * Initialize connectivity monitoring
     */
    init() {
        // Listen for online/offline events
        window.addEventListener('online', () => this.handleOnline());
        window.addEventListener('offline', () => this.handleOffline());

        // Create visual indicator
        this.createIndicator();

        // Set initial state
        this.updateIndicator();

        console.log('Connectivity monitor initialized. Status:', this.isOnline ? 'Online' : 'Offline');
    }

    /**
     * Create visual connectivity indicator
     */
    createIndicator() {
        // Check if indicator already exists
        if (document.getElementById('connectivity-indicator')) {
            this.indicator = document.getElementById('connectivity-indicator');
            return;
        }

        // Create indicator element
        const indicator = document.createElement('div');
        indicator.id = 'connectivity-indicator';
        indicator.style.cssText = `
            position: fixed;
            top: 70px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        `;

        document.body.appendChild(indicator);
        this.indicator = indicator;
    }

    /**
     * Update indicator appearance based on connection status
     */
    updateIndicator() {
        if (!this.indicator) return;

        if (this.isOnline) {
            this.indicator.style.background = '#10b981';
            this.indicator.style.color = 'white';
            this.indicator.innerHTML = `
                <i class="fas fa-wifi"></i>
                <span>Online</span>
            `;
            
            // Hide after 3 seconds when online
            setTimeout(() => {
                if (this.isOnline) {
                    this.indicator.style.opacity = '0';
                    setTimeout(() => {
                        if (this.isOnline) {
                            this.indicator.style.display = 'none';
                        }
                    }, 300);
                }
            }, 3000);
        } else {
            this.indicator.style.display = 'flex';
            this.indicator.style.opacity = '1';
            this.indicator.style.background = '#ef4444';
            this.indicator.style.color = 'white';
            this.indicator.innerHTML = `
                <i class="fas fa-wifi-slash"></i>
                <span>Offline - Changes will sync when reconnected</span>
            `;
        }
    }

    /**
     * Handle online event
     */
    handleOnline() {
        console.log('Connection restored');
        this.isOnline = true;
        this.updateIndicator();
        this.notifyListeners('online');

        // Show sync notification
        this.showNotification('Connection restored. Syncing changes...', 'success');

        // Trigger sync
        if (window.profileSyncManager) {
            window.profileSyncManager.forceSync();
        }
    }

    /**
     * Handle offline event
     */
    handleOffline() {
        console.log('Connection lost');
        this.isOnline = false;
        this.updateIndicator();
        this.notifyListeners('offline');

        // Show offline notification
        this.showNotification('You are offline. Changes will be saved locally.', 'warning');
    }

    /**
     * Show temporary notification
     */
    showNotification(message, type = 'info') {
        // Check if notification container exists
        let container = document.getElementById('notification-container');
        
        if (!container) {
            container = document.createElement('div');
            container.id = 'notification-container';
            container.style.cssText = `
                position: fixed;
                top: 130px;
                right: 20px;
                z-index: 9999;
                max-width: 400px;
            `;
            document.body.appendChild(container);
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        
        const colors = {
            success: { bg: '#10b981', icon: 'check-circle' },
            warning: { bg: '#f59e0b', icon: 'exclamation-triangle' },
            error: { bg: '#ef4444', icon: 'times-circle' },
            info: { bg: '#3b82f6', icon: 'info-circle' }
        };

        const color = colors[type] || colors.info;

        notification.style.cssText = `
            background: ${color.bg};
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        `;

        notification.innerHTML = `
            <i class="fas fa-${color.icon}"></i>
            <span>${message}</span>
        `;

        container.appendChild(notification);

        // Remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 5000);
    }

    /**
     * Add event listener
     */
    addEventListener(callback) {
        this.listeners.push(callback);
    }

    /**
     * Remove event listener
     */
    removeEventListener(callback) {
        const index = this.listeners.indexOf(callback);
        if (index > -1) {
            this.listeners.splice(index, 1);
        }
    }

    /**
     * Notify all listeners
     */
    notifyListeners(status) {
        this.listeners.forEach(callback => {
            try {
                callback(status, this.isOnline);
            } catch (error) {
                console.error('Listener error:', error);
            }
        });
    }

    /**
     * Get current status
     */
    getStatus() {
        return {
            isOnline: this.isOnline,
            indicator: this.indicator !== null
        };
    }

    /**
     * Manually update pending count badge
     */
    async updatePendingCount() {
        if (!window.profileOfflineStorage) return;

        try {
            const count = await window.profileOfflineStorage.getPendingCount();
            
            // Update or create pending badge
            let badge = document.getElementById('pending-updates-badge');
            
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.id = 'pending-updates-badge';
                    badge.style.cssText = `
                        position: fixed;
                        top: 75px;
                        right: 180px;
                        background: #f59e0b;
                        color: white;
                        padding: 5px 10px;
                        border-radius: 12px;
                        font-size: 12px;
                        font-weight: 600;
                        z-index: 9998;
                    `;
                    document.body.appendChild(badge);
                }
                badge.textContent = `${count} pending update${count > 1 ? 's' : ''}`;
                badge.style.display = 'block';
            } else if (badge) {
                badge.style.display = 'none';
            }
        } catch (error) {
            console.error('Failed to update pending count:', error);
        }
    }
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Export as global instance
window.connectivityMonitor = new ConnectivityMonitor();
