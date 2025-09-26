<?php
require_once 'hybrid_config.php';
require_once 'hybrid_db.php';
require_once 'sync_manager.php';
require_once 'functions.php';

// Initialize components
$db = getHybridDB();
$syncManager = getSyncManager();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'force_sync':
            $result = $syncManager->performFullSync();
            echo json_encode($result);
            exit;
            
        case 'test_connection':
            $result = $syncManager->testCentralConnection();
            echo json_encode($result);
            exit;
            
        case 'clear_queue':
            $result = $syncManager->clearSyncQueue();
            echo json_encode(['success' => $result, 'message' => $result ? 'Queue cleared' : 'Queue not found']);
            exit;
            
        case 'get_stats':
            $stats = $syncManager->getSyncStats();
            echo json_encode($stats);
            exit;
    }
}

// Get current statistics
$syncStats = $syncManager->getSyncStats();
$recentLogs = $syncManager->getRecentLogs(10);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hybrid Database Dashboard - Store <?= STORE_ID ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .header-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .info-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }
        
        .info-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .status-online { border-left-color: #27ae60; }
        .status-offline { border-left-color: #e74c3c; }
        .status-syncing { border-left-color: #f39c12; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 18px;
            font-weight: bold;
        }
        
        .card-content {
            padding: 20px;
        }
        
        .sync-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #219a52;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d68910;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .table-stats {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .table-stats th,
        .table-stats td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .table-stats th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #ecf0f1;
            border-radius: 4px;
            overflow: hidden;
            margin: 5px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            transition: width 0.3s ease;
        }
        
        .logs-container {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .log-entry {
            margin-bottom: 5px;
            padding: 2px 0;
        }
        
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .config-section {
            grid-column: 1 / -1;
        }
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .config-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .config-label {
            font-weight: bold;
            color: #495057;
            margin-bottom: 5px;
        }
        
        .config-value {
            color: #6c757d;
            font-family: monospace;
        }
        
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        .alert-warning {
            background: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Hybrid Database Dashboard</h1>
            <p>Store <?= STORE_ID ?> - <?= CURRENT_MODE === 'hybrid' ? 'Hybrid Mode (Offline Ready)' : ucfirst(CURRENT_MODE) . ' Mode' ?></p>
            
            <div class="header-info">
                <div class="info-card <?= $syncStats['sync_status']['central_connected'] ? 'status-online' : 'status-offline' ?>">
                    <div class="info-label">Central Connection</div>
                    <div class="info-value">
                        <span class="status-indicator <?= $syncStats['sync_status']['central_connected'] ? 'status-online' : 'status-offline' ?>"></span>
                        <?= $syncStats['sync_status']['central_connected'] ? 'Online' : 'Offline' ?>
                    </div>
                </div>
                
                <div class="info-card">
                    <div class="info-label">Database Mode</div>
                    <div class="info-value"><?= ucfirst($syncStats['sync_status']['mode']) ?></div>
                </div>
                
                <div class="info-card status-warning">
                    <div class="info-label">Sync Queue</div>
                    <div class="info-value"><?= $syncStats['sync_status']['queue_size'] ?> pending</div>
                </div>
                
                <div class="info-card">
                    <div class="info-label">Last Sync</div>
                    <div class="info-value"><?= $syncStats['sync_status']['last_sync'] ?></div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">🔄 Sync Controls</div>
                <div class="card-content">
                    <div class="sync-controls">
                        <button class="btn btn-primary" onclick="performSync()" id="syncBtn">
                            Force Full Sync
                        </button>
                        <button class="btn btn-warning" onclick="testConnection()">
                            Test Connection
                        </button>
                        <button class="btn btn-danger" onclick="clearQueue()">
                            Clear Queue
                        </button>
                    </div>
                    
                    <div id="syncResults"></div>
                    
                    <div style="margin-top: 20px;">
                        <h4>Auto Sync Configuration</h4>
                        <div class="config-item" style="margin-top: 10px;">
                            <div class="config-label">Status</div>
                            <div class="config-value"><?= AUTO_SYNC_ENABLED ? 'Enabled' : 'Disabled' ?></div>
                        </div>
                        
                        <?php if (AUTO_SYNC_ENABLED): ?>
                        <div class="config-item">
                            <div class="config-label">Interval</div>
                            <div class="config-value"><?= AUTO_SYNC_INTERVAL ?> seconds</div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Queue Threshold</div>
                            <div class="config-value"><?= AUTO_SYNC_QUEUE_THRESHOLD ?> items</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">📊 Table Statistics</div>
                <div class="card-content">
                    <table class="table-stats">
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Total</th>
                                <th>Sync Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($syncStats['table_stats'] as $table => $stats): ?>
                                <?php if (isset($stats['error'])): ?>
                                    <tr>
                                        <td><?= ucfirst($table) ?></td>
                                        <td colspan="2" style="color: #e74c3c;">Error: <?= $stats['error'] ?></td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= ucfirst($table) ?></td>
                                        <td><?= $stats['total'] ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <span><?= $stats['sync_percentage'] ?>% synced</span>
                                                <small style="color: #7f8c8d;"><?= $stats['unsynced'] ?> pending</small>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?= $stats['sync_percentage'] ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card full-width">
                <div class="card-header">📝 Recent Sync Logs</div>
                <div class="card-content">
                    <div class="logs-container" id="logsContainer">
                        <?php if (empty($recentLogs)): ?>
                            <div class="log-entry">No sync logs available</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($recentLogs) as $log): ?>
                                <div class="log-entry"><?= htmlspecialchars($log) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 15px;">
                        <button class="btn btn-primary" onclick="refreshLogs()">Refresh Logs</button>
                        <button class="btn btn-success" onclick="refreshStats()">Refresh All Data</button>
                    </div>
                </div>
            </div>
            
            <div class="card config-section">
                <div class="card-header">⚙️ System Configuration</div>
                <div class="card-content">
                    <div class="config-grid">
                        <div class="config-item">
                            <div class="config-label">Store ID</div>
                            <div class="config-value"><?= STORE_ID ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Current Mode</div>
                            <div class="config-value"><?= CURRENT_MODE ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Environment</div>
                            <div class="config-value"><?= ENVIRONMENT ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Sync Enabled</div>
                            <div class="config-value"><?= SYNC_ENABLED ? 'Yes' : 'No' ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Central Available</div>
                            <div class="config-value"><?= CENTRAL_AVAILABLE ? 'Yes' : 'No' ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Batch Size</div>
                            <div class="config-value"><?= SYNC_BATCH_SIZE ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Max Queue Size</div>
                            <div class="config-value"><?= SYNC_QUEUE_MAX_SIZE ?></div>
                        </div>
                        
                        <div class="config-item">
                            <div class="config-label">Connection Timeout</div>
                            <div class="config-value"><?= CONNECTION_TIMEOUT ?>s</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php" class="btn btn-primary">← Back to Dashboard</a>
            <a href="pos_terminal.php" class="btn btn-success">POS Terminal →</a>
        </div>
    </div>

    <script>
        function performSync() {
            const btn = document.getElementById('syncBtn');
            const resultsDiv = document.getElementById('syncResults');
            
            btn.disabled = true;
            btn.textContent = 'Syncing...';
            btn.classList.add('loading');
            
            const formData = new FormData();
            formData.append('action', 'force_sync');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-success">
                            <strong>Sync Completed Successfully!</strong><br>
                            ${data.push_results ? 'Pushed: ' + (data.push_results.synced || 0) + ' records<br>' : ''}
                            ${data.pull_results ? 'Pulled: ' + (data.pull_results.pulled || 0) + ' records<br>' : ''}
                            ${data.message}
                        </div>
                    `;
                    
                    // Refresh page after 2 seconds
                    setTimeout(() => location.reload(), 2000);
                } else {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <strong>Sync Failed!</strong><br>
                            ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>Error!</strong><br>
                        Network error occurred during sync.
                    </div>
                `;
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Force Full Sync';
                btn.classList.remove('loading');
            });
        }
        
        function testConnection() {
            const formData = new FormData();
            formData.append('action', 'test_connection');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const alertClass = data.success ? 'alert-success' : 'alert-danger';
                document.getElementById('syncResults').innerHTML = `
                    <div class="alert ${alertClass}">
                        <strong>Connection Test:</strong><br>
                        ${data.message}
                    </div>
                `;
            });
        }
        
        function clearQueue() {
            if (!confirm('Are you sure you want to clear the sync queue? This will remove all pending sync operations.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'clear_queue');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const alertClass = data.success ? 'alert-success' : 'alert-warning';
                document.getElementById('syncResults').innerHTML = `
                    <div class="alert ${alertClass}">
                        <strong>Queue Clear:</strong><br>
                        ${data.message}
                    </div>
                `;
                
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }
        
        function refreshStats() {
            location.reload();
        }
        
        function refreshLogs() {
            // This would typically make an AJAX call to get fresh logs
            // For now, just reload the page
            location.reload();
        }
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            if (!document.querySelector('.loading')) {
                refreshStats();
            }
        }, 30000);
    </script>
</body>
</html>