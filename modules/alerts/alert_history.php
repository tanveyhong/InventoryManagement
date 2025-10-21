<?php
session_start();
require_once '../../functions.php';   // <-- adjust if your DB helper is in another folder

// --- Admin-only guard -------------------------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../users/login.php');
    exit;
}

// --- Connect to Firestore ---------------------------------------------------
$db = getDB(); // your Firebase wrapper

// --- Fetch alerts from Firestore -------------------------------------------
// Read all docs in 'alerts' (you can later limit to 500 or so)
$alerts = [];
try {
    $docs = $db->readAll('alerts', [], null, 1000);
    foreach ($docs as $doc) {
        $alerts[] = [
            'product_id'   => $doc['product_id']   ?? '',
            'product_name' => $doc['product_name'] ?? '',
            'alert_type'   => strtoupper($doc['alert_type'] ?? ''),
            'quantity_affected' => $doc['quantity_affected'] ?? null,
            'expiry_kind'  => $doc['expiry_kind']  ?? null,
            'status'       => ucfirst(strtolower($doc['status'] ?? 'Pending')),
            'created_at'   => $doc['created_at']   ?? '',
        ];
    }
} catch (Exception $e) {
    $alerts = [];
}

// --- Split into Low Stock and Expiry groups --------------------------------
$low_stock_alerts = array_filter($alerts, fn($a) => $a['alert_type'] === 'LOW_STOCK');
$expiry_alerts    = array_filter($alerts, fn($a) => $a['alert_type'] === 'EXPIRY');

// Sort newest first
usort($low_stock_alerts, fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
usort($expiry_alerts,    fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));

// --- Small helper for colored badges ---------------------------------------
function status_badge($status) {
    $color = match (strtoupper($status)) {
        'RESOLVED'   => 'green',
        'UNRESOLVED' => 'red',
        default      => 'orange'
    };
    return "<span style='color:$color;font-weight:600'>".htmlspecialchars($status)."</span>";
}

function fmt_ts($v) {
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
body { font-family: Arial, sans-serif; background:#fafafa; }
.container { padding:20px; }
h2 { margin-top:0; color:#204d9c; }
.card { background:#fff; border-radius:10px; padding:15px 20px; margin-bottom:25px; box-shadow:0 1px 4px rgba(0,0,0,0.1);}
.card-header { font-weight:bold; margin-bottom:10px; border-bottom:2px solid #ddd; padding-bottom:5px;}
table { width:100%; border-collapse:collapse; font-size:14px;}
th,td { border:1px solid #ddd; padding:8px; text-align:left;}
th { background:#f2f2f2;}
</style>
</head>
<body>
<?php include '../../includes/dashboard_header.php'; ?>

<div class="container">
    <h2>Alert History Log</h2>

    <!-- Low Stock Alerts ------------------------------------------------------>
    <div class="card">
        <div class="card-header">Low Stock Alerts</div>
        <div class="card-body">
            <table>
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Product Name</th>
                        <th>Alert Type</th>
                        <th>Timestamp</th>
                        <th>Resolution Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($low_stock_alerts)): ?>
                    <tr><td colspan="5" style="text-align:center;">No low stock alerts</td></tr>
                <?php else: foreach ($low_stock_alerts as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['product_id']) ?></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td>Low Stock</td>
                        <td><?= fmt_ts($r['created_at'] ?? ($r['updated_at'] ?? null)) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Expiry Products ------------------------------------------------------->
    <div class="card">
        <div class="card-header">Expiry Products</div>
        <div class="card-body">
            <table>
                <thead>
                    <tr>
                        <th>Product ID</th>
                        <th>Product Name</th>
                        <th>Quantity Affected</th>
                        <th>Alert Type</th>
                        <th>Timestamp</th>
                        <th>Resolution Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($expiry_alerts)): ?>
                    <tr><td colspan="6" style="text-align:center;">No expiry alerts</td></tr>
                <?php else: foreach ($expiry_alerts as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['product_id']) ?></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td><?= htmlspecialchars($r['quantity_affected'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(ucwords(strtolower($r['expiry_kind'] ?? 'Expired'))) ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
