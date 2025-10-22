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
            'product_id'        => $doc['product_id']        ?? '',
            'product_name'      => $doc['product_name']      ?? '',
            'alert_type'        => strtoupper($doc['alert_type'] ?? ''),
            'expiry_kind'       => $doc['expiry_kind']       ?? null,
            'expiry_date'       => $doc['expiry_date']       ?? null,
            'quantity_affected' => $doc['quantity_affected'] ?? null,  // <-- add this
            'status'            => ucfirst(strtolower($doc['status'] ?? 'Pending')),
            'created_at'        => $doc['created_at']        ?? '',
            'updated_at'        => $doc['updated_at']        ?? '',
            'resolved_at'       => $doc['resolved_at']       ?? null,
        ];
    }
} catch (Exception $e) {
    $alerts = [];
}

foreach ($alerts as &$a) {
    // Backfill quantity_affected for EXPIRY alerts (you already do this)
    // ...

    // NEW: if an EXPIRY alert is still pending but the product is deleted/disabled, flip to RESOLVED
    if (strtoupper($a['alert_type'] ?? '') === 'EXPIRY' && strtoupper($a['status'] ?? '') !== 'RESOLVED') {
        $pid = (string)($a['product_id'] ?? '');
        if ($pid !== '' && fs_is_product_deleted($db, $pid)) {
            // Update the in-memory row so UI shows Resolved
            $a['status']      = 'Resolved';
            $a['resolved_at'] = date('c');

            // Also patch the alert doc so it stays fixed
            $expDocId = 'EXP_' . $pid;                   // common convention
            fs_mark_alert_resolved($db, $expDocId, [
                'resolved_by'     => 'system',
                'resolution_note' => 'Auto-resolved: product deleted/disabled',
            ]);
        }
    }
}
unset($a);

// --- Split into Low Stock and Expiry groups --------------------------------
$low_stock_alerts = array_filter($alerts, fn($a) => $a['alert_type'] === 'LOW_STOCK');
$expiry_alerts    = array_filter($alerts, fn($a) => $a['alert_type'] === 'EXPIRY');

// Sort newest first
usort($low_stock_alerts, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
usort($expiry_alerts,    fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

// --- Small helper for colored badges ---------------------------------------
function status_badge($status)
{
    $color = match (strtoupper($status)) {
        'RESOLVED'   => 'green',
        'UNRESOLVED' => 'red',
        default      => 'orange'
    };
    return "<span style='color:$color;font-weight:600'>" . htmlspecialchars($status) . "</span>";
}

function fmt_ts($v)
{
    if (empty($v)) return '-';
    $t = strtotime($v);
    if ($t === false) return '-';
    return date('Y-m-d H:i:s', $t);
}

function fs_get_product_qty($db, string $pid): ?int
{
    if ($pid === '') return null;
    try {
        if (method_exists($db, 'readDoc')) {
            $p = $db->readDoc('products', $pid);
        } elseif (method_exists($db, 'read')) {
            $p = $db->read('products', $pid);
        } else {
            return null;
        }
        if (!$p) return null;
        // support different field names just in case
        if (isset($p['quantity']))       return (int)$p['quantity'];
        if (isset($p['stock_qty']))      return (int)$p['stock_qty'];
        if (isset($p['current_qty']))    return (int)$p['current_qty'];
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

function fs_is_product_deleted($db, string $pid): bool
{
    if ($pid === '') return false;
    try {
        $p = method_exists($db, 'readDoc') ? $db->readDoc('products', $pid)
            : (method_exists($db, 'read') ? $db->read('products', $pid) : null);
        if (!$p) return false; // product gone; treat as deleted for expiry purpose?
        if (!empty($p['deleted_at'])) return true;
        if (isset($p['status']) && strtolower((string)$p['status']) === 'disabled') return true;
    } catch (Throwable $e) {
    }
    return false;
}

function fs_mark_alert_resolved($db, string $docId, array $merge = []): void
{
    $payload = array_merge([
        'status'      => 'RESOLVED',
        'updated_at'  => date('c'),
        'resolved_at' => date('c'),
    ], $merge);
    if (method_exists($db, 'update'))        $db->update('alerts', $docId, $payload);
    elseif (method_exists($db, 'writeDoc'))  $db->writeDoc('alerts', $docId, $payload);
    elseif (method_exists($db, 'setDoc'))    $db->setDoc('alerts', $docId, $payload);
    elseif (method_exists($db, 'write'))     $db->write('alerts', $docId, $payload);
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

    <div class="container">
        <h2>Alert History Log</h2>

        <!-- Low Stock Alerts ------------------------------------------------------>
        <div class="card low-stock-card">
            <div class="card-header low-stock-header">Low Stock Alerts</div>
            <div class="card-body">
                <table class="low-stock-table">
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
                            <tr>
                                <td colspan="5" style="text-align:center;">No low stock alerts</td>
                            </tr>
                            <?php else: foreach ($low_stock_alerts as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['product_id']) ?></td>
                                    <td><?= htmlspecialchars($r['product_name']) ?></td>
                                    <td>Low Stock</td>
                                    <td><?= fmt_ts($r['created_at'] ?? ($r['updated_at'] ?? null)) ?></td>
                                    <td><?= status_badge($r['status']) ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Expiry Products ------------------------------------------------------->
        <div class="card expiry-card">
            <div class="card-header expiry-header">Expiry Products</div>
            <div class="card-body">
                <table class="expiry-table">
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
                            <tr>
                                <td colspan="6" style="text-align:center;">No expiry alerts</td>
                            </tr>
                            <?php else: foreach ($expiry_alerts as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['product_id'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($r['product_name'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $qa = $r['quantity_affected'] ?? null;
                                        echo ($qa === null || $qa === '') ? '-' : htmlspecialchars((string)$qa);
                                        ?>
                                    </td>


                                    <td>
                                        <?= htmlspecialchars(
                                            ucwords(
                                                strtolower(
                                                    str_replace('_', ' ', $r['expiry_kind'] ?? 'Expired')
                                                )
                                            )
                                        ) ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($r['created_at'])) {
                                            $t = strtotime($r['created_at']);
                                            echo date('Y-m-d H:i:s', $t);
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?= status_badge($r['status'] ?? 'Pending') ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>

            </div>
        </div>
    </div>
</body>

</html>