/**
 * Offline Storage Handler for Profile Module
 * Uses IndexedDB to store profile updates when offline
 */

class ProfileOfflineStorage {
    constructor() {
        this.dbName = 'InventorySystemDB';
        this.version = 1;
        this.db = null;
        this.init();
    }

    /**
     * Initialize IndexedDB database
     */
    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.version);

            request.onerror = () => {
                console.error('IndexedDB failed to open:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                console.log('IndexedDB initialized successfully');
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                // Create object store for pending profile updates
                if (!db.objectStoreNames.contains('pendingProfileUpdates')) {
                    const store = db.createObjectStore('pendingProfileUpdates', { 
                        keyPath: 'id', 
                        autoIncrement: true 
                    });
                    store.createIndex('timestamp', 'timestamp', { unique: false });
                    store.createIndex('userId', 'userId', { unique: false });
                    store.createIndex('synced', 'synced', { unique: false });
                }

                // Create object store for cached profile data
                if (!db.objectStoreNames.contains('cachedProfiles')) {
                    const cacheStore = db.createObjectStore('cachedProfiles', { keyPath: 'userId' });
                    cacheStore.createIndex('lastUpdated', 'lastUpdated', { unique: false });
                }

                console.log('IndexedDB stores created');
            };
        });
    }

    /**
     * Save pending profile update to local storage
     */
    async savePendingUpdate(userId, updateData) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pendingProfileUpdates'], 'readwrite');
            const store = transaction.objectStore('pendingProfileUpdates');

            const update = {
                userId: userId,
                data: updateData,
                timestamp: new Date().toISOString(),
                synced: false,
                retryCount: 0
            };

            const request = store.add(update);

            request.onsuccess = () => {
                console.log('Pending update saved:', update);
                resolve(request.result);
            };

            request.onerror = () => {
                console.error('Failed to save pending update:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get all pending updates for a user
     */
    async getPendingUpdates(userId = null) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pendingProfileUpdates'], 'readonly');
            const store = transaction.objectStore('pendingProfileUpdates');

            let request;
            if (userId) {
                const index = store.index('userId');
                request = index.getAll(userId);
            } else {
                request = store.getAll();
            }

            request.onsuccess = () => {
                const updates = request.result.filter(u => !u.synced);
                resolve(updates);
            };

            request.onerror = () => {
                console.error('Failed to get pending updates:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Mark an update as synced
     */
    async markAsSynced(updateId) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pendingProfileUpdates'], 'readwrite');
            const store = transaction.objectStore('pendingProfileUpdates');

            const getRequest = store.get(updateId);

            getRequest.onsuccess = () => {
                const update = getRequest.result;
                if (update) {
                    update.synced = true;
                    update.syncedAt = new Date().toISOString();
                    
                    const putRequest = store.put(update);
                    
                    putRequest.onsuccess = () => {
                        console.log('Update marked as synced:', updateId);
                        resolve(true);
                    };
                    
                    putRequest.onerror = () => reject(putRequest.error);
                } else {
                    resolve(false);
                }
            };

            getRequest.onerror = () => reject(getRequest.error);
        });
    }

    /**
     * Delete a synced update
     */
    async deleteSyncedUpdate(updateId) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pendingProfileUpdates'], 'readwrite');
            const store = transaction.objectStore('pendingProfileUpdates');

            const request = store.delete(updateId);

            request.onsuccess = () => {
                console.log('Synced update deleted:', updateId);
                resolve(true);
            };

            request.onerror = () => {
                console.error('Failed to delete update:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Cache profile data for offline access
     */
    async cacheProfile(userId, profileData) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedProfiles'], 'readwrite');
            const store = transaction.objectStore('cachedProfiles');

            const cache = {
                userId: userId,
                data: profileData,
                lastUpdated: new Date().toISOString()
            };

            const request = store.put(cache);

            request.onsuccess = () => {
                console.log('Profile cached:', userId);
                resolve(true);
            };

            request.onerror = () => {
                console.error('Failed to cache profile:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get cached profile data
     */
    async getCachedProfile(userId) {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['cachedProfiles'], 'readonly');
            const store = transaction.objectStore('cachedProfiles');

            const request = store.get(userId);

            request.onsuccess = () => {
                resolve(request.result ? request.result.data : null);
            };

            request.onerror = () => {
                console.error('Failed to get cached profile:', request.error);
                reject(request.error);
            };
        });
    }

    /**
     * Get count of pending updates
     */
    async getPendingCount(userId = null) {
        const pending = await this.getPendingUpdates(userId);
        return pending.length;
    }

    /**
     * Clear all synced updates (cleanup)
     */
    async clearSyncedUpdates() {
        if (!this.db) await this.init();

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pendingProfileUpdates'], 'readwrite');
            const store = transaction.objectStore('pendingProfileUpdates');
            const index = store.index('synced');

            const request = index.openCursor(IDBKeyRange.only(true));
            let deletedCount = 0;

            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    cursor.delete();
                    deletedCount++;
                    cursor.continue();
                } else {
                    console.log(`Cleared ${deletedCount} synced updates`);
                    resolve(deletedCount);
                }
            };

            request.onerror = () => {
                console.error('Failed to clear synced updates:', request.error);
                reject(request.error);
            };
        });
    }
}

// Export as global instance
window.profileOfflineStorage = new ProfileOfflineStorage();
