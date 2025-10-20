<?php
/**
 * Performance Monitor
 * Add to any page to measure load time and Firebase calls
 */

// Start timing
if (!isset($GLOBALS['_perf_start'])) {
    $GLOBALS['_perf_start'] = microtime(true);
    $GLOBALS['_firebase_calls'] = 0;
}

// Function to track Firebase calls
function trackFirebaseCall() {
    $GLOBALS['_firebase_calls']++;
}

// Function to display performance stats
function showPerformanceStats() {
    $end = microtime(true);
    $duration = round(($end - $GLOBALS['_perf_start']) * 1000, 2);
    $calls = $GLOBALS['_firebase_calls'];
    
    $color = $duration < 500 ? 'green' : ($duration < 1000 ? 'orange' : 'red');
    
    echo '
    <style>
        .perf-monitor {
            position: fixed;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            z-index: 10000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .perf-monitor .perf-time {
            font-size: 16px;
            font-weight: bold;
            color: ' . $color . ';
        }
        .perf-monitor .perf-label {
            color: #aaa;
            font-size: 11px;
        }
        .perf-monitor .perf-metric {
            margin: 5px 0;
        }
    </style>
    <div class="perf-monitor">
        <div class="perf-metric">
            <span class="perf-label">Load Time:</span>
            <span class="perf-time">' . $duration . 'ms</span>
        </div>
        <div class="perf-metric">
            <span class="perf-label">API Calls:</span>
            <span style="color: ' . ($calls < 5 ? '#4CAF50' : ($calls < 15 ? '#FF9800' : '#f44336')) . '">' . $calls . '</span>
        </div>
        <div class="perf-metric">
            <span class="perf-label">Memory:</span>
            <span style="color: #2196F3">' . round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB</span>
        </div>
    </div>
    ';
}

// Register shutdown function to display stats
register_shutdown_function('showPerformanceStats');
