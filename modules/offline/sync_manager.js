/**
 * Profile Sync Manager
 * Handles synchronization of offline profile updates when connectivity is restored
 */

class ProfileSyncManager {
    constructor() {
        this.storage = window.profileOfflineStorage;
        this.isSyncing = false;
        this.syncInterval = null;
        this.retryDelay = 5000; // 5 seconds
        this.maxRetries = 3;
        this.listeners = [];
    }

    /**
     * Start automatic sync monitoring
     */
    startAutoSync() {
        // Sync immediately if online
        if (navigator.onLine) {
            this.sync();
        }

        // Sync every 30 seconds when online
        this.syncInterval = setInterval(() => {
            if (navigator.onLine && !this.isSyncing) {
                this.sync();
            }
        }, 30000);

        console.log('Auto-sync started');
    }

    /**
     * Stop automatic sync
     */
    stopAutoSync() {
        if (this.syncInterval) {
            clearInterval(this.syncInterval);
            this.syncInterval = null;
            console.log('Auto-sync stopped');
        }
    }

    /**
     * Sync all pending profile updates
     */
    async sync() {
        if (this.isSyncing) {
            console.log('Sync already in progress, skipping...');
            return;
        }

        if (!navigator.onLine) {
            console.log('Offline, skipping sync');
            return;
        }

        this.isSyncing = true;
        this.notifyListeners('sync-start');

        try {
            const pendingUpdates = await this.storage.getPendingUpdates();
            
            if (pendingUpdates.length === 0) {
                console.log('No pending updates to sync');
                this.isSyncing = false;
                return;
            }

            console.log(`Syncing ${pendingUpdates.length} pending updates...`);
            
            let successCount = 0;
            let failCount = 0;

            for (const update of pendingUpdates) {
                try {
                    const success = await this.syncUpdate(update);
                    if (success) {
                        successCount++;
                        // Mark as synced
                        await this.storage.markAsSynced(update.id);
                        // Delete after successful sync
                        setTimeout(() => {
                            this.storage.deleteSyncedUpdate(update.id);
                        }, 5000);
                    } else {
                        failCount++;
                    }
                } catch (error) {
                    console.error('Sync error for update:', update.id, error);
                    failCount++;
                }
            }

            console.log(`Sync complete: ${successCount} success, ${failCount} failed`);
            
            this.notifyListeners('sync-complete', {
                total: pendingUpdates.length,
                success: successCount,
                failed: failCount
            });

        } catch (error) {
            console.error('Sync process error:', error);
            this.notifyListeners('sync-error', error);
        } finally {
            this.isSyncing = false;
        }
    }

    /**
     * Sync a single update to the server
     */
    async syncUpdate(update) {
        try {
            const formData = new FormData();
            
            // Add all profile fields from the update data
            for (const [key, value] of Object.entries(update.data)) {
                formData.append(key, value);
            }

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const text = await response.text();
            
            // Check if update was successful (look for success message)
            if (text.includes('Profile updated successfully') || 
                text.includes('success') || 
                response.status === 200) {
                console.log('Update synced successfully:', update.id);
                return true;
            } else {
                console.warn('Update sync may have failed:', update.id);
                return false;
            }

        } catch (error) {
            console.error('Failed to sync update:', update.id, error);
            
            // Increment retry count
            update.retryCount = (update.retryCount || 0) + 1;
            
            if (update.retryCount >= this.maxRetries) {
                console.error('Max retries reached for update:', update.id);
                this.notifyListeners('sync-failed', update);
            }
            
            return false;
        }
    }

    /**
     * Force immediate sync
     */
    async forceSync() {
        console.log('Force sync requested');
        await this.sync();
    }

    /**
     * Add event listener for sync events
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
     * Notify all listeners of sync events
     */
    notifyListeners(event, data = null) {
        this.listeners.forEach(callback => {
            try {
                callback(event, data);
            } catch (error) {
                console.error('Listener error:', error);
            }
        });
    }

    /**
     * Get sync status
     */
    getStatus() {
        return {
            isSyncing: this.isSyncing,
            isOnline: navigator.onLine,
            autoSyncEnabled: this.syncInterval !== null
        };
    }
}

// Export as global instance
window.profileSyncManager = new ProfileSyncManager();
