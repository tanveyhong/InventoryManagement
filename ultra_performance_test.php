<?php
// Clear cache if requested (before any output)
if (isset($_GET['clear_cache'])) {
    require_once 'includes/fast_init.php';
    DatabaseCache::clearAll();
    if (isset($_SESSION['_cache'])) {
        $_SESSION['_cache'] = [];
    }
    if (isset($_SESSION['_db_cache'])) {
        $_SESSION['_db_cache'] = [];
    }
    header('Location: ultra_performance_test.php');
    exit;
}

$start = microtime(true);

require_once 'includes/fast_init.php';

if (!isset($_SESSION['user_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ultra Performance Test - Login Required</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container { max-width: 500px; width: 100%; }
            .header {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                text-align: center;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 8px;
                margin-top: 20px;
                transition: all 0.3s ease;
            }
            .btn:hover {
                background: #764ba2;
                transform: translateY(-2px);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-lock"></i> Login Required</h1>
                <p style="margin: 20px 0;">You need to be logged in to run performance tests.</p>
                <a href="modules/users/login.php" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Go to Login
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$userId = $_SESSION['user_id'];

// === TEST 1: Database Call Performance ===
$testStart = microtime(true);
for ($i = 0; $i < 100; $i++) {
    getUserInfoCached($userId);
}
$cachedTime = round((microtime(true) - $testStart) * 1000, 2);
$perCall = round($cachedTime / 100, 3);

// === TEST 2: Permission Check Performance ===
$testStart = microtime(true);
for ($i = 0; $i < 100; $i++) {
    quickPermissionCheck('manage_inventory');
}
$permTime = round((microtime(true) - $testStart) * 1000, 2);
$perPermCheck = round($permTime / 100, 3);

// === TEST 3: Cache Hit Rate ===
$cacheStats = DatabaseCache::getStats();

// === TEST 4: Memory Usage ===
$memoryMB = round(memory_get_peak_usage() / 1024 / 1024, 2);

// === Calculate Performance Score ===
$score = 100;
if ($perCall > 1) $score -= 20;
if ($perCall > 5) $score -= 30;
if ($perPermCheck > 0.1) $score -= 10;
if ($memoryMB > 20) $score -= 10;
$hitRate = floatval(str_replace('%', '', $cacheStats['hit_rate']));
if ($hitRate < 80) $score -= 20;

$score = max(0, $score);

// Performance grade
if ($score >= 90) {
    $grade = 'A+';
    $gradeColor = '#27ae60';
    $gradeText = 'Blazing Fast! 🚀';
} elseif ($score >= 80) {
    $grade = 'A';
    $gradeColor = '#2ecc71';
    $gradeText = 'Excellent Performance';
} elseif ($score >= 70) {
    $grade = 'B';
    $gradeColor = '#3498db';
    $gradeText = 'Good Performance';
} elseif ($score >= 60) {
    $grade = 'C';
    $gradeColor = '#f39c12';
    $gradeText = 'Acceptable';
} else {
    $grade = 'D';
    $gradeColor = '#e74c3c';
    $gradeText = 'Needs Optimization';
}

$totalTime = round((microtime(true) - $start) * 1000, 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultra Performance Test</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        .test-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .test-card h3 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            margin: 8px 0;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .metric.excellent { border-left: 4px solid #27ae60; background: #e8f5e9; }
        .metric.good { border-left: 4px solid #3498db; background: #e3f2fd; }
        .metric.warning { border-left: 4px solid #f39c12; background: #fff3e0; }
        .metric.poor { border-left: 4px solid #e74c3c; background: #ffebee; }
        .metric-label { font-weight: 500; color: #333; }
        .metric-value {
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 1.1rem;
        }
        .metric.excellent .metric-value { background: #27ae60; color: white; }
        .metric.good .metric-value { background: #3498db; color: white; }
        .metric.warning .metric-value { background: #f39c12; color: white; }
        .metric.poor .metric-value { background: #e74c3c; color: white; }
        .speedometer {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
            color: white;
            border-radius: 15px;
            margin: 20px 0;
        }
        .speedometer .speed {
            font-size: 4rem;
            font-weight: 900;
            margin: 20px 0;
        }
        .speedometer .label {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin: 5px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn:hover {
            background: #764ba2;
            transform: translateY(-2px);
        }
        .progress-bar {
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #229954);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            transition: width 0.5s ease;
        }
        .cache-layers {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .cache-layer {
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .cache-layer.l1 { background: #27ae60; color: white; }
        .cache-layer.l2 { background: #3498db; color: white; }
        .cache-layer.l3 { background: #f39c12; color: white; }
        .cache-layer .speed { font-size: 2rem; font-weight: 700; }
        .cache-layer .label { font-size: 0.9rem; margin-top: 5px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-rocket"></i> Ultra Performance Test</h1>
        <p>Advanced caching and optimization benchmarks</p>
    </div>
    
    <!-- Performance Score -->
    <div class="speedometer" style="background: linear-gradient(135deg, <?php echo $gradeColor; ?> 0%, <?php echo $gradeColor; ?>dd 100%);">
        <div class="label">PERFORMANCE GRADE</div>
        <div class="speed"><?php echo $grade; ?></div>
        <div class="label"><?php echo $gradeText; ?></div>
        <div style="margin-top: 20px;">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $score; ?>%;">
                    <?php echo $score; ?>/100
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cache Layer Visualization -->
    <div class="test-card">
        <h3><i class="fas fa-layer-group"></i> Multi-Level Cache Architecture</h3>
        <div class="cache-layers">
            <div class="cache-layer l1">
                <div class="speed">&lt;0.01ms</div>
                <div class="label">Level 1: Memory Cache</div>
                <div style="font-size: 0.8rem; margin-top: 10px;">In-Process RAM</div>
            </div>
            <div class="cache-layer l2">
                <div class="speed">&lt;0.1ms</div>
                <div class="label">Level 2: Session Cache</div>
                <div style="font-size: 0.8rem; margin-top: 10px;">Per-Session Storage</div>
            </div>
            <div class="cache-layer l3">
                <div class="speed">&lt;5ms</div>
                <div class="label">Level 3: File Cache</div>
                <div style="font-size: 0.8rem; margin-top: 10px;">Persistent Disk</div>
            </div>
        </div>
        <div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
            <strong>Firebase Database:</strong> ~200ms (Only on cache miss)
        </div>
    </div>
    
    <div class="test-grid">
        <!-- Database Performance -->
        <div class="test-card">
            <h3><i class="fas fa-database"></i> Database Operations</h3>
            <div class="metric <?php echo $perCall < 0.1 ? 'excellent' : ($perCall < 1 ? 'good' : 'warning'); ?>">
                <span class="metric-label">100 getUserInfo() Calls:</span>
                <span class="metric-value"><?php echo $cachedTime; ?>ms</span>
            </div>
            <div class="metric <?php echo $perCall < 0.1 ? 'excellent' : ($perCall < 1 ? 'good' : 'warning'); ?>">
                <span class="metric-label">Average per Call:</span>
                <span class="metric-value"><?php echo $perCall; ?>ms</span>
            </div>
            <div class="metric excellent">
                <span class="metric-label">Cache Benefit:</span>
                <span class="metric-value"><?php echo round(200 / max($perCall, 0.001)); ?>x faster</span>
            </div>
        </div>
        
        <!-- Permission Performance -->
        <div class="test-card">
            <h3><i class="fas fa-shield-alt"></i> Permission Checks</h3>
            <div class="metric <?php echo $perPermCheck < 0.1 ? 'excellent' : ($perPermCheck < 1 ? 'good' : 'warning'); ?>">
                <span class="metric-label">100 Permission Checks:</span>
                <span class="metric-value"><?php echo $permTime; ?>ms</span>
            </div>
            <div class="metric <?php echo $perPermCheck < 0.1 ? 'excellent' : ($perPermCheck < 1 ? 'good' : 'warning'); ?>">
                <span class="metric-label">Average per Check:</span>
                <span class="metric-value"><?php echo $perPermCheck; ?>ms</span>
            </div>
            <div class="metric excellent">
                <span class="metric-label">Throughput:</span>
                <span class="metric-value"><?php echo round(1000 / max($perPermCheck, 0.001)); ?>/sec</span>
            </div>
        </div>
        
        <!-- Cache Statistics -->
        <div class="test-card">
            <h3><i class="fas fa-chart-pie"></i> Cache Statistics</h3>
            <div class="metric <?php echo $hitRate > 80 ? 'excellent' : ($hitRate > 60 ? 'good' : 'warning'); ?>">
                <span class="metric-label">Cache Hit Rate:</span>
                <span class="metric-value"><?php echo $cacheStats['hit_rate']; ?></span>
            </div>
            <div class="metric good">
                <span class="metric-label">Cache Hits:</span>
                <span class="metric-value"><?php echo $cacheStats['hits']; ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Cache Misses:</span>
                <span class="metric-value"><?php echo $cacheStats['misses']; ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Cache Writes:</span>
                <span class="metric-value"><?php echo $cacheStats['writes']; ?></span>
            </div>
        </div>
        
        <!-- System Resources -->
        <div class="test-card">
            <h3><i class="fas fa-microchip"></i> System Resources</h3>
            <div class="metric <?php echo $memoryMB < 10 ? 'excellent' : ($memoryMB < 20 ? 'good' : 'warning'); ?>">
                <span class="metric-label">Memory Usage:</span>
                <span class="metric-value"><?php echo $memoryMB; ?> MB</span>
            </div>
            <div class="metric <?php echo $totalTime < 100 ? 'excellent' : ($totalTime < 500 ? 'good' : 'warning'); ?>">
                <span class="metric-label">Page Load Time:</span>
                <span class="metric-value"><?php echo $totalTime; ?>ms</span>
            </div>
            <div class="metric excellent">
                <span class="metric-label">Firebase Calls:</span>
                <span class="metric-value"><?php echo $GLOBALS['_firebase_calls'] ?? 0; ?></span>
            </div>
        </div>
        
        <!-- Performance Breakdown -->
        <div class="test-card" style="grid-column: 1 / -1;">
            <h3><i class="fas fa-chart-line"></i> Performance Breakdown</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="padding: 12px; text-align: left;">Operation</th>
                        <th style="padding: 12px; text-align: center;">Without Cache</th>
                        <th style="padding: 12px; text-align: center;">With Cache</th>
                        <th style="padding: 12px; text-align: center;">Improvement</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding: 12px;">Single DB Read</td>
                        <td style="padding: 12px; text-align: center;">~200ms</td>
                        <td style="padding: 12px; text-align: center;"><?php echo $perCall; ?>ms</td>
                        <td style="padding: 12px; text-align: center; color: #27ae60; font-weight: 700;">
                            <?php echo round((200 - $perCall) / 200 * 100, 1); ?>%
                        </td>
                    </tr>
                    <tr style="background: #f8f9fa;">
                        <td style="padding: 12px;">Permission Check</td>
                        <td style="padding: 12px; text-align: center;">~150ms</td>
                        <td style="padding: 12px; text-align: center;"><?php echo $perPermCheck; ?>ms</td>
                        <td style="padding: 12px; text-align: center; color: #27ae60; font-weight: 700;">
                            <?php echo round((150 - $perPermCheck) / 150 * 100, 1); ?>%
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px;">Page with 20 checks</td>
                        <td style="padding: 12px; text-align: center;">~3000ms</td>
                        <td style="padding: 12px; text-align: center;"><?php echo round($perPermCheck * 20, 1); ?>ms</td>
                        <td style="padding: 12px; text-align: center; color: #27ae60; font-weight: 700;">
                            <?php echo round((3000 - ($perPermCheck * 20)) / 3000 * 100, 1); ?>%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
        <a href="index.php" class="btn">
            <i class="fas fa-home"></i> Back to Dashboard
        </a>
        <button onclick="location.reload()" class="btn" style="background: #27ae60;">
            <i class="fas fa-redo"></i> Run Test Again
        </button>
        <button onclick="clearCache()" class="btn" style="background: #e74c3c;">
            <i class="fas fa-trash"></i> Clear Cache & Test
        </button>
    </div>
</div>

<script>
function clearCache() {
    if (confirm('Clear all caches and rerun test?')) {
        location.href = '?clear_cache=1';
    }
}
</script>

</body>
</html>
