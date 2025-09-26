<?php
// Sync Manager - Handles synchronization between local and central databases
require_once 'hybrid_config.php';
require_once 'hybrid_db.php';

class SyncManager {
    private $db;
    private $logFile;
    
    public function __construct() {
        $this->db = getHybridDB();
        $this->logFile = __DIR__ . '/logs/sync.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    // Main sync process - pull from central, push to central
    public function performFullSync() {
        $this->log("Starting full synchronization...");
        
        $results = [
            'pull_results' => null,
            'push_results' => null,
            'success' => false,
            'message' => ''
        ];
        
        try {
            // Step 1: Pull updates from central server
            if (CENTRAL_AVAILABLE && PULL_FROM_CENTRAL) {
                $this->log("Pulling updates from central server...");
                $results['pull_results'] = $this->pullFromCentral();
            }
            
            // Step 2: Push local changes to central server
            if (CENTRAL_AVAILABLE && PUSH_TO_CENTRAL) {
                $this->log("Pushing local changes to central server...");
                $results['push_results'] = $this->db->syncToCenter();
            }
            
            $results['success'] = true;
            $results['message'] = 'Full synchronization completed successfully';
            $this->log("Full synchronization completed successfully");
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Sync failed: ' . $e->getMessage();
            $this->log("Sync failed: " . $e->getMessage());
        }
        
        return $results;
    }
    
    // Pull updates from central database
    private function pullFromCentral() {
        $centralConfig = getDatabaseConfig()['secondary'];
        if (!$centralConfig) {
            throw new Exception('Central database configuration not available');
        }
        
        // Connect to central database
        $centralPdo = new PDO(
            $centralConfig['driver'] . ":host=" . $centralConfig['host'] . ";port=" . $centralConfig['port'] . ";dbname=" . $centralConfig['database'],
            $centralConfig['username'],
            $centralConfig['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => CONNECTION_TIMEOUT
            ]
        );
        
        $pulled = 0;
        $errors = [];
        
        try {
            // Get last sync timestamps for each table
            $lastSyncTimes = $this->getLastSyncTimes();
            
            // Pull data for each syncable table
            $tables = ['users', 'stores', 'categories', 'products'];
            
            foreach ($tables as $table) {
                try {
                    $lastSync = $lastSyncTimes[$table] ?? '1970-01-01 00:00:00';
                    $count = $this->pullTableData($centralPdo, $table, $lastSync);
                    $pulled += $count;
                    $this->log("Pulled $count records from $table");
                    
                } catch (Exception $e) {
                    $errors[] = "Error pulling $table: " . $e->getMessage();
                    $this->log("Error pulling $table: " . $e->getMessage());
                }
            }
            
            // Update last sync time
            $this->updateLastSyncTime();
            
            return [
                'success' => true,
                'pulled' => $pulled,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            throw new Exception("Pull from central failed: " . $e->getMessage());
        }
    }
    
    // Pull data for a specific table
    private function pullTableData($centralPdo, $table, $lastSync) {
        // Get records updated since last sync
        $sql = "SELECT * FROM $table WHERE updated_at > ? ORDER BY updated_at ASC LIMIT " . SYNC_BATCH_SIZE;
        $stmt = $centralPdo->prepare($sql);
        $stmt->execute([$lastSync]);
        $records = $stmt->fetchAll();
        
        if (empty($records)) {
            return 0;
        }
        
        $count = 0;
        $localPdo = $this->db->getWriteConnection();
        
        foreach ($records as $record) {
            try {
                // Check if record exists locally
                $checkSql = "SELECT id, updated_at FROM $table WHERE id = ?";
                $checkStmt = $localPdo->prepare($checkSql);
                $checkStmt->execute([$record['id']]);
                $localRecord = $checkStmt->fetch();
                
                if (!$localRecord) {
                    // Insert new record
                    $this->insertRecord($localPdo, $table, $record);
                    $count++;
                } else {
                    // Check if central record is newer
                    if (strtotime($record['updated_at']) > strtotime($localRecord['updated_at'])) {
                        $this->updateRecord($localPdo, $table, $record);
                        $count++;
                    }
                }
                
            } catch (Exception $e) {
                $this->log("Error processing record {$record['id']} from $table: " . $e->getMessage());
            }
        }
        
        return $count;
    }
    
    // Insert record into local database
    private function insertRecord($pdo, $table, $record) {
        // Remove synced_at field as it's local only
        unset($record['synced_at']);
        
        $columns = array_keys($record);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';
        
        $sql = "INSERT INTO $table (" . implode(',', $columns) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($record));
    }
    
    // Update record in local database
    private function updateRecord($pdo, $table, $record) {
        // Remove synced_at field as it's local only
        unset($record['synced_at']);
        
        $id = $record['id'];
        unset($record['id']);
        
        $setParts = [];
        foreach (array_keys($record) as $column) {
            $setParts[] = "$column = ?";
        }
        
        $sql = "UPDATE $table SET " . implode(', ', $setParts) . ", synced_at = datetime('now') WHERE id = ?";
        $values = array_values($record);
        $values[] = $id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    }
    
    // Get last sync times for each table
    private function getLastSyncTimes() {
        $syncFile = __DIR__ . '/data/last_sync.json';
        
        if (file_exists($syncFile)) {
            $data = json_decode(file_get_contents($syncFile), true);
            return $data ?: [];
        }
        
        return [];
    }
    
    // Update last sync time
    private function updateLastSyncTime() {
        $syncFile = __DIR__ . '/data/last_sync.json';
        $syncDir = dirname($syncFile);
        
        if (!file_exists($syncDir)) {
            mkdir($syncDir, 0755, true);
        }
        
        $currentTime = date('Y-m-d H:i:s');
        $data = [
            'users' => $currentTime,
            'stores' => $currentTime,
            'categories' => $currentTime,
            'products' => $currentTime,
            'last_full_sync' => $currentTime
        ];
        
        file_put_contents($syncFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    // Get sync statistics
    public function getSyncStats() {
        $status = $this->db->getSyncStatus();
        $lastSyncTimes = $this->getLastSyncTimes();
        
        // Count records by sync status
        $localPdo = $this->db->getReadConnection();
        
        $stats = [
            'sync_status' => $status,
            'last_sync_times' => $lastSyncTimes,
            'table_stats' => []
        ];
        
        $tables = ['users', 'stores', 'categories', 'products', 'transactions'];
        
        foreach ($tables as $table) {
            try {
                $totalStmt = $localPdo->prepare("SELECT COUNT(*) FROM $table");
                $totalStmt->execute();
                $total = $totalStmt->fetchColumn();
                
                $unsyncedStmt = $localPdo->prepare("SELECT COUNT(*) FROM $table WHERE synced_at IS NULL");
                $unsyncedStmt->execute();
                $unsynced = $unsyncedStmt->fetchColumn();
                
                $stats['table_stats'][$table] = [
                    'total' => $total,
                    'unsynced' => $unsynced,
                    'synced' => $total - $unsynced,
                    'sync_percentage' => $total > 0 ? round(($total - $unsynced) / $total * 100, 1) : 100
                ];
                
            } catch (Exception $e) {
                $stats['table_stats'][$table] = [
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $stats;
    }
    
    // Auto sync based on conditions
    public function autoSync() {
        if (!AUTO_SYNC_ENABLED) {
            return ['success' => false, 'message' => 'Auto sync disabled'];
        }
        
        $lastAutoSync = $this->getLastAutoSyncTime();
        $timeSinceLastSync = time() - $lastAutoSync;
        
        // Check if enough time has passed
        if ($timeSinceLastSync < AUTO_SYNC_INTERVAL) {
            return ['success' => false, 'message' => 'Too soon for auto sync'];
        }
        
        // Check if queue size threshold is met
        $status = $this->db->getSyncStatus();
        if ($status['queue_size'] < AUTO_SYNC_QUEUE_THRESHOLD) {
            return ['success' => false, 'message' => 'Queue size below threshold'];
        }
        
        // Perform sync
        $this->setLastAutoSyncTime();
        return $this->performFullSync();
    }
    
    // Get last auto sync time
    private function getLastAutoSyncTime() {
        $file = __DIR__ . '/data/last_auto_sync.txt';
        return file_exists($file) ? intval(file_get_contents($file)) : 0;
    }
    
    // Set last auto sync time
    private function setLastAutoSyncTime() {
        $file = __DIR__ . '/data/last_auto_sync.txt';
        $dir = dirname($file);
        
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($file, time());
    }
    
    // Conflict resolution - last write wins for now
    public function resolveConflicts() {
        // This would implement more sophisticated conflict resolution
        // For now, we use "last write wins" strategy
        $this->log("Conflict resolution using last-write-wins strategy");
        return ['resolved' => 0, 'strategy' => 'last-write-wins'];
    }
    
    // Log sync activities
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    // Get recent sync logs
    public function getRecentLogs($lines = 50) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $logs = file($this->logFile, FILE_IGNORE_NEW_LINES);
        return array_slice($logs, -$lines);
    }
    
    // Clear sync queue manually (emergency)
    public function clearSyncQueue() {
        if (file_exists(SYNC_QUEUE_PATH)) {
            unlink(SYNC_QUEUE_PATH);
            $this->log("Sync queue cleared manually");
            return true;
        }
        return false;
    }
    
    // Test connection to central server
    public function testCentralConnection() {
        try {
            $centralConfig = getDatabaseConfig()['secondary'];
            if (!$centralConfig) {
                return ['success' => false, 'message' => 'No central database configuration'];
            }
            
            $centralPdo = new PDO(
                $centralConfig['driver'] . ":host=" . $centralConfig['host'] . ";port=" . $centralConfig['port'] . ";dbname=" . $centralConfig['database'],
                $centralConfig['username'],
                $centralConfig['password'],
                [PDO::ATTR_TIMEOUT => 5]
            );
            
            $stmt = $centralPdo->query("SELECT 1");
            $result = $stmt->fetch();
            
            return [
                'success' => true,
                'message' => 'Central database connection successful',
                'server_time' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Central database connection failed: ' . $e->getMessage()
            ];
        }
    }
}

// Global function to get sync manager instance
function getSyncManager() {
    static $instance = null;
    if ($instance === null) {
        $instance = new SyncManager();
    }
    return $instance;
}

// CLI interface for sync operations
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $syncManager = getSyncManager();
    
    switch ($argv[1]) {
        case 'full-sync':
            echo "Starting full synchronization...\n";
            $result = $syncManager->performFullSync();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'auto-sync':
            echo "Running auto sync...\n";
            $result = $syncManager->autoSync();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'stats':
            echo "Sync statistics:\n";
            $stats = $syncManager->getSyncStats();
            echo json_encode($stats, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'test-connection':
            echo "Testing central connection...\n";
            $result = $syncManager->testCentralConnection();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;
            
        case 'logs':
            echo "Recent sync logs:\n";
            $logs = $syncManager->getRecentLogs(20);
            foreach ($logs as $log) {
                echo $log . "\n";
            }
            break;
            
        default:
            echo "Available commands:\n";
            echo "  full-sync      - Perform full synchronization\n";
            echo "  auto-sync      - Run auto sync if conditions are met\n";
            echo "  stats          - Show sync statistics\n";
            echo "  test-connection - Test connection to central server\n";
            echo "  logs           - Show recent sync logs\n";
    }
}

?>