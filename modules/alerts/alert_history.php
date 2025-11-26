<?php
require_once '../../config.php';
require_once '../../functions.php';
require_once '../../sql_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Permission guard -------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_manage_alerts')) {
    header('Location: ../../index.php');
    exit;
}

// --- Load low stock alerts from PostgreSQL (Supabase) -----------------------
$low_stock_alerts = [];
$pending_low_stock = [];

try {
    $sqlDb = SQLDatabase::getInstance();

    // Adjust table / column names if your alerts table is different
    $rows = $sqlDb->fetchAll("
        SELECT 
            a.id,
            a.product_id,
            a.alert_type,
            a.status,
            a.created_at,
            a.updated_at,
            a.resolved_at,
            COALESCE(p.name, a.product_name) AS product_name
        FROM alerts a
        LEFT JOIN products p ON a.product_id = p.id
        WHERE a.alert_type = 'LOW_STOCK'
        ORDER BY a.created_at DESC
    ");

    $low_stock_alerts = $rows ?? [];

    // Filter for currently pending low-stock alerts
    $pending_low_stock = array_filter(
        $low_stock_alerts,
        fn($a) => strtoupper($a['status'] ?? '') === 'PENDING'
    );

} catch (Throwable $e) {
    error_log('Alert history SQL error: ' . $e->getMessage());
    $low_stock_alerts = [];
    $pending_low_stock = [];
}

// --- Helpers ----------------------------------------------------------------
function status_badge($status)
{
    $upper = strtoupper($status ?? '');
    $color = match ($upper) {
        'RESOLVED'   => '#16a34a',   // green
        'PENDING'    => '#f97316',   // orange
        default      => '#6b7280',   // gray
    };

    return "<span style='padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:{$color};'>"
         . htmlspecialchars(ucfirst(strtolower($status ?? 'Pending')))
         . "</span>";
}

function fmt_ts($v)
{
    if (empty($v)) return '-';
    $t = strtotime($v);
    if ($t === false) return '-';
    return date('Y-m-d H:i:s', $t);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Alert History Log</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #fafafa;
        }

        .container {
            padding: 20px;
        }

        h2 {
            margin-top: 0;
            color: #204d9c;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            font-weight: bold;
            margin-bottom: 10px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f2f2f2;
        }

        /* ---------- LOW STOCK ALERT (light red / pink) ---------- */
        .low-stock-header {
            color: #b3002d;
            /* dark red text */
            border-left: 6px solid #ff4d6d;
            padding: 10px 15px;
            border-radius: 6px 6px 0 0;
        }

        .low-stock-table thead th {
            background-color: #ffdce1;
            /* slightly lighter pink for table head */
            color: #a30028;
            border-bottom: 2px solid #ff4d6d;
        }

        /* ---------- EXPIRY ALERT (orange) ---------- */
        .expiry-header {
            color: #d46a00;
            /* deep orange text */
            border-left: 6px solid #ff9f1a;
            padding: 10px 15px;
            border-radius: 6px 6px 0 0;
        }

        .expiry-table thead th {
            background-color: #ffe8cc;
            /* lighter orange for table head */
            color: #b85e00;
            border-bottom: 2px solid #ff9f1a;
        }
    </style>
</head>

<body>
<?php include '../../includes/dashboard_header.php'; ?>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <h2>Alert History Log</h2>


    <!-- LOW STOCK ALERT HISTORY (ALL) ---------------------------------------->
    <div class="card low-stock-card">
        <div class="card-header low-stock-header">
            Low Stock Alerts
        </div>
        <div class="card-body">

            <table class="low-stock-table">
                <thead>
                <tr>
                    <th>Product ID</th>
                    <th>Product Name</th>
                    <th>Alert Type</th>
                    <th>Timestamp</th>
                    <th>Resolved At</th>
                    <th>Resolution Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($low_stock_alerts)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;">No low stock alerts</td>
                    </tr>
                <?php else: foreach ($low_stock_alerts as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['product_id'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['product_name'] ?? '-') ?></td>
                        <td>Low Stock</td>
                        <td><?= fmt_ts($r['created_at'] ?? null) ?></td>
                        <td><?= fmt_ts($r['resolved_at'] ?? null) ?></td>
                        <td><?= status_badge($r['status'] ?? 'Pending') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>