/**
 * Conflict Resolver for Profile Module
 * Handles conflicts between offline and online profile updates
 */

class ConflictResolver {
    constructor() {
        this.resolutionStrategy = 'timestamp'; // timestamp, server-wins, client-wins, manual
        this.conflictLog = [];
    }

    /**
     * Detect conflicts between local and server data
     */
    async detectConflict(localUpdate, serverData) {
        if (!serverData) {
            // No conflict if server has no data
            return null;
        }

        const localTimestamp = new Date(localUpdate.timestamp);
        const serverTimestamp = serverData.updated_at ? 
            new Date(serverData.updated_at) : 
            new Date(0);

        // Check if server was updated after local change was made
        if (serverTimestamp > localTimestamp) {
            const conflict = {
                type: 'update_conflict',
                localData: localUpdate.data,
                serverData: serverData,
                localTimestamp: localUpdate.timestamp,
                serverTimestamp: serverData.updated_at,
                detectedAt: new Date().toISOString()
            };

            console.warn('Conflict detected:', conflict);
            this.conflictLog.push(conflict);
            
            return conflict;
        }

        return null;
    }

    /**
     * Resolve conflict based on strategy
     */
    async resolveConflict(conflict) {
        switch (this.resolutionStrategy) {
            case 'timestamp':
                return this.resolveByTimestamp(conflict);
            
            case 'server-wins':
                return this.resolveServerWins(conflict);
            
            case 'client-wins':
                return this.resolveClientWins(conflict);
            
            case 'manual':
                return await this.resolveManual(conflict);
            
            default:
                console.warn('Unknown resolution strategy, using timestamp');
                return this.resolveByTimestamp(conflict);
        }
    }

    /**
     * Resolve by most recent timestamp (default)
     */
    resolveByTimestamp(conflict) {
        const localTime = new Date(conflict.localTimestamp);
        const serverTime = new Date(conflict.serverTimestamp);

        if (localTime > serverTime) {
            console.log('Conflict resolved: Local changes are newer');
            return {
                action: 'use_local',
                data: conflict.localData,
                reason: 'Local timestamp is more recent'
            };
        } else {
            console.log('Conflict resolved: Server changes are newer');
            return {
                action: 'use_server',
                data: conflict.serverData,
                reason: 'Server timestamp is more recent'
            };
        }
    }

    /**
     * Resolve by always using server data
     */
    resolveServerWins(conflict) {
        console.log('Conflict resolved: Server wins strategy');
        return {
            action: 'use_server',
            data: conflict.serverData,
            reason: 'Server-wins strategy'
        };
    }

    /**
     * Resolve by always using client data
     */
    resolveClientWins(conflict) {
        console.log('Conflict resolved: Client wins strategy');
        return {
            action: 'use_local',
            data: conflict.localData,
            reason: 'Client-wins strategy'
        };
    }

    /**
     * Resolve manually with user input
     */
    async resolveManual(conflict) {
        return new Promise((resolve) => {
            this.showConflictDialog(conflict, (resolution) => {
                resolve(resolution);
            });
        });
    }

    /**
     * Show conflict resolution dialog to user
     */
    showConflictDialog(conflict, callback) {
        // Create modal overlay
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10001;
            display: flex;
            align-items: center;
            justify-content: center;
        `;

        // Create dialog
        const dialog = document.createElement('div');
        dialog.style.cssText = `
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        `;

        dialog.innerHTML = `
            <h2 style="margin: 0 0 20px 0; color: #ef4444;">
                <i class="fas fa-exclamation-triangle"></i> Sync Conflict Detected
            </h2>
            <p style="margin-bottom: 20px; color: #6b7280;">
                Your offline changes conflict with updates made on the server. 
                Please choose which version to keep:
            </p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div style="border: 2px solid #3b82f6; border-radius: 8px; padding: 15px;">
                    <h3 style="margin: 0 0 10px 0; color: #3b82f6;">
                        <i class="fas fa-laptop"></i> Your Changes
                    </h3>
                    <div style="font-size: 14px; color: #6b7280;">
                        <strong>Modified:</strong> ${new Date(conflict.localTimestamp).toLocaleString()}<br>
                        ${this.formatChanges(conflict.localData)}
                    </div>
                </div>
                
                <div style="border: 2px solid #10b981; border-radius: 8px; padding: 15px;">
                    <h3 style="margin: 0 0 10px 0; color: #10b981;">
                        <i class="fas fa-server"></i> Server Version
                    </h3>
                    <div style="font-size: 14px; color: #6b7280;">
                        <strong>Modified:</strong> ${new Date(conflict.serverTimestamp).toLocaleString()}<br>
                        ${this.formatChanges(conflict.serverData)}
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button id="use-local-btn" style="
                    padding: 10px 20px;
                    background: #3b82f6;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                ">
                    <i class="fas fa-laptop"></i> Use My Changes
                </button>
                <button id="use-server-btn" style="
                    padding: 10px 20px;
                    background: #10b981;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                ">
                    <i class="fas fa-server"></i> Use Server Version
                </button>
            </div>
        `;

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        // Add event listeners
        document.getElementById('use-local-btn').addEventListener('click', () => {
            document.body.removeChild(overlay);
            callback({
                action: 'use_local',
                data: conflict.localData,
                reason: 'User chose local changes'
            });
        });

        document.getElementById('use-server-btn').addEventListener('click', () => {
            document.body.removeChild(overlay);
            callback({
                action: 'use_server',
                data: conflict.serverData,
                reason: 'User chose server version'
            });
        });
    }

    /**
     * Format changes for display
     */
    formatChanges(data) {
        const fields = ['first_name', 'last_name', 'email', 'username', 'phone'];
        const changes = [];

        for (const field of fields) {
            if (data[field] !== undefined) {
                changes.push(`<strong>${field}:</strong> ${data[field]}`);
            }
        }

        return changes.join('<br>') || 'No changes';
    }

    /**
     * Merge non-conflicting fields
     */
    mergeChanges(localData, serverData) {
        const merged = { ...serverData };

        // Only override server data with local if field was explicitly changed
        for (const [key, value] of Object.entries(localData)) {
            if (value !== undefined && value !== null) {
                merged[key] = value;
            }
        }

        return merged;
    }

    /**
     * Get conflict history
     */
    getConflictLog() {
        return this.conflictLog;
    }

    /**
     * Clear conflict log
     */
    clearConflictLog() {
        this.conflictLog = [];
    }

    /**
     * Set resolution strategy
     */
    setStrategy(strategy) {
        const valid = ['timestamp', 'server-wins', 'client-wins', 'manual'];
        if (valid.includes(strategy)) {
            this.resolutionStrategy = strategy;
            console.log('Conflict resolution strategy set to:', strategy);
        } else {
            console.error('Invalid strategy. Valid options:', valid);
        }
    }

    /**
     * Get current strategy
     */
    getStrategy() {
        return this.resolutionStrategy;
    }
}

// Export as global instance
window.conflictResolver = new ConflictResolver();
