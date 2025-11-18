<?php

/**
 * modules/alerts/expiry_alert.php
 * Show only expired + near-expiring products (today, next 7 days, next 30 days)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

// Start session after config (to allow ini_set to work)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

// --- helpers ---------------------------------------------------------------

/** days between today (00:00) and a date string; negative if past */
function days_until(string $dateStr): ?int
{
    $ts = strtotime($dateStr);
    if ($ts === false) return null;
    $today = strtotime(date('Y-m-d'));      // today at 00:00
    $daySec = 86400;
    return (int) floor(($ts - $today) / $daySec);
}

/** bucket: expired|today|week|month|null */
function expiry_bucket(?string $dateStr): ?string
{
    if (!$dateStr) return null;
    $d = days_until($dateStr);
    if ($d === null) return null;
    if ($d < 0) return 'expired';
    if ($d === 0) return 'today';
    if ($d <= 7) return 'week';
    if ($d <= 30) return 'month';
    return null;
}

function safe_text($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// --- read all products from Firestore --------------------------------------
$all = [];
try {
    $db = getDB();
    $docs = $db->readAll('products', [], null, 1000); // up to 1000
    foreach ($docs as $r) {
        // Skip soft deleted / disabled rows
        if (!empty($r['deleted_at'])) continue;
        if (isset($r['status']) && $r['status'] === 'disabled') continue;
        if (isset($r['quantity']) && (int)$r['quantity'] === 0) continue;

        $all[] = [
            'doc_id'        => $r['id'] ?? null, // your wrapper usually returns id
            'name'          => $r['name'] ?? '',
            'sku'           => $r['sku'] ?? '',
            'description'   => $r['description'] ?? '',
            'quantity'      => isset($r['quantity']) ? (int)$r['quantity'] : 0,
            'reorder_level' => isset($r['reorder_level']) ? (int)$r['reorder_level'] : 0,
            'price'         => isset($r['price']) ? (float)$r['price'] : 0.0,
            'expiry_date'   => $r['expiry_date'] ?? null,
            'category_name' => $r['category'] ?? ($r['category_name'] ?? ''),
            'store_id'      => $r['store_id'] ?? '',
            'created_at'    => $r['created_at'] ?? null,
        ];
    }
} catch (Throwable $e) {
    $all = [];
}

// --- compute buckets / counts ---------------------------------------------
$expired = $today = $week = $month = [];
foreach ($all as $p) {
    $bucket = expiry_bucket($p['expiry_date'] ?? null);
    if ($bucket === 'expired') $expired[] = $p;
    elseif ($bucket === 'today') $today[] = $p;
    elseif ($bucket === 'week') $week[] = $p;
    elseif ($bucket === 'month') $month[] = $p;
}
$counts = [
    'expired' => count($expired),
    'today'   => count($today),
    'week'    => count($week),
    'month'   => count($month),
];

$filter = $_GET['filter'] ?? 'all'; // all|expired|today|week|month
switch ($filter) {
    case 'expired':
        $list = $expired;
        break;
    case 'today':
        $list = $today;
        break;
    case 'week':
        $list = $week;
        break;
    case 'month':
        $list = $month;
        break;
    default:
        $list = array_merge($expired, $today, $week, $month);
        break;
}

// Sort: nearest expiry first (expired at top by most overdue)
usort($list, function ($a, $b) {
    $da = days_until($a['expiry_date'] ?? '') ?? 99999;
    $db = days_until($b['expiry_date'] ?? '') ?? 99999;
    return $da <=> $db;
});

$page_title = 'Expiry Alerts - Inventory System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= safe_text($page_title) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* --- page shell --- */
        .page-header {
            background: #fff;
            border: 1px solid #e5eaf1;
            border-radius: 14px;
            padding: 16px 22px;
            margin: 20px auto 16px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, .03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .page-header h2 {
            margin: 0;
            font-size: 28px;
            letter-spacing: .2px;
            color: #0f172a;
        }

        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .btn-primary {
            background: #2d7ef7;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1e63d3;
        }

        .btn-muted {
            background: #94a3b8;
            color: #fff;
        }

        .btn-muted:hover {
            background: #64748b;
        }

        .btn-outline {
            background: #fff;
            color: #1f2937;
            border-color: #e5e7eb;
        }

        .btn-outline:hover {
            background: #f3f4f6;
        }

        /* --- KPI cards row --- */
        .kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(220px, 1fr));
            gap: 16px;
            margin: 8px 0 18px;
        }

        .kpi {
            position: relative;
            background: #fff;
            border-radius: 14px;
            padding: 18px;
            border: 1px solid #e8eef7;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.05);
        }

        .kpi h4 {
            margin: 0 0 10px;
            font-size: 18px;
            color: #0f172a;
        }

        .kpi .num {
            font-size: 34px;
            font-weight: 800;
            color: #0b75ff;
            line-height: 1;
        }

        .kpi.expired {
            background: #fff5f5;
            border-left: 6px solid #ef4444;
        }

        .kpi.today {
            background: #fff7ed;
            border-left: 6px solid #f59e0b;
        }

        .kpi.week {
            background: #fffbea;
            border-left: 6px solid #f59e0b;
        }

        .kpi.month {
            background: #eff6ff;
            border-left: 6px solid #3b82f6;
        }

        /* --- small pills for filter nav --- */
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 4px 0 2px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2f7;
            color: #0f172a;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #e3e8ef;
        }

        .pill.active {
            background: #0b75ff;
            color: #fff;
            border-color: #0b75ff;
        }

        .pill .count {
            font-weight: 800;
        }

        /* --- table --- */
        .table-container {
            overflow: auto;
            background: #fff;
            border: 1px solid #e8eef7;
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 920px;
        }

        thead th {
            background: #0f172a;
            color: #ecf0f8;
            text-align: left;
            font-weight: 700;
            letter-spacing: .3px;
            padding: 12px;
        }

        tbody td {
            padding: 12px;
            border-top: 1px solid #eef2f7;
            color: #0f172a;
        }

        .muted {
            color: #64748b;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 9px;
            border-radius: 8px;
            font-size: .85rem;
            font-weight: 700;
        }

        .b-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .b-today {
            background: #ffedd5;
            color: #9a3412;
        }

        .b-week {
            background: #fef3c7;
            color: #92400e;
        }

        .b-month {
            background: #dbeafe;
            color: #1e40af;
        }

        .days-left {
            color: #475569;
            font-weight: 700;
        }

        .sku {
            color: #64748b;
            font-weight: 600;
        }

        .toolbar-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 12px 0 8px;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/../../includes/dashboard_header.php'; ?>

    <div class="container" style="max-width: 1600px; margin: 0 auto; padding: 20px;">
        <main>
            <div class="page-header">
                <h2>Products Nearing Expiry (<?= number_format(array_sum($counts)) ?>)</h2>
                <div class="page-actions">
                    <a class="btn btn-primary" href="expiry_alert.php"><i class="fa-solid fa-rotate-right"></i> Refresh Alerts</a>
                    <a class="btn btn-outline" href="../stock/list.php?status=expiring"><i class="fa-solid fa-boxes-stacked"></i> Go to Stock</a>
                </div>
            </div>

            <!-- KPI row -->
            <div class="kpis">
                <div class="kpi expired">
                    <h4>Expired</h4>
                    <div class="num"><?= number_format($counts['expired']) ?></div>
                </div>
                <div class="kpi today">
                    <h4>Expiring Today</h4>
                    <div class="num"><?= number_format($counts['today']) ?></div>
                </div>
                <div class="kpi week">
                    <h4>Next 7 Days</h4>
                    <div class="num"><?= number_format($counts['week']) ?></div>
                </div>
                <div class="kpi month">
                    <h4>Next 30 Days</h4>
                    <div class="num"><?= number_format($counts['month']) ?></div>
                </div>
            </div>

            <!-- Filter pills -->
            <div class="filter-tabs">
                <?php
                $tabs = [
                    'all'     => ['label' => 'All', 'count' => array_sum($counts)],
                    'expired' => ['label' => 'Expired', 'count' => $counts['expired']],
                    'today'   => ['label' => 'Today', 'count' => $counts['today']],
                    'week'    => ['label' => 'Next 7 Days', 'count' => $counts['week']],
                    'month'   => ['label' => 'Next 30 Days', 'count' => $counts['month']],
                ];
                foreach ($tabs as $key => $t): $active = ($filter === $key) ? 'active' : ''; ?>
                    <a class="pill <?= $active ?>" href="?filter=<?= urlencode($key) ?>">
                        <?= safe_text($t['label']) ?> <span class="count"><?= number_format($t['count']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($list)): ?>
                <div class="table-container" style="padding:24px; border-style:dashed;">
                    <strong>No products match this expiry filter.</strong>
                    <div class="muted" style="margin-top:6px">Tip: add expiry dates in product details to start seeing alerts.</div>
                </div>
            <?php else: ?>
                <div class="table-container" style="margin-top:10px">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:26%">Product</th>
                                <th style="width:10%">SKU</th>
                                <th style="width:14%">Category</th>
                                <th style="width:10%">Qty</th>
                                <th style="width:16%">Expiry Date</th>
                                <th style="width:12%">Days</th>
                                <th style="width:12%">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list as $p):
                                $days = days_until($p['expiry_date'] ?? '');
                                $bucket = expiry_bucket($p['expiry_date'] ?? null);
                                $badgeClass = $bucket === 'expired' ? 'b-expired' : ($bucket === 'today' ? 'b-today' : ($bucket === 'week' ? 'b-week' : 'b-month'));
                                $badgeText  = $bucket === 'expired' ? 'Expired'  : ($bucket === 'today' ? 'Today' : ($bucket === 'week' ? 'Next 7 days' : 'Next 30 days'));
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= safe_text($p['name']) ?></strong><br>
                                        <?php if (!empty($p['description'])): ?>
                                            <span class="muted"><?= safe_text(mb_strimwidth($p['description'], 0, 90, '…')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="sku"><?= safe_text($p['sku'] ?: '—') ?></span></td>
                                    <td><?= safe_text($p['category_name'] ?: '—') ?></td>
                                    <td><?= number_format((int)($p['quantity'] ?? 0)) ?></td>
                                    <td><?= $p['expiry_date'] ? safe_text(date('M j, Y', strtotime($p['expiry_date']))) : '—' ?></td>
                                    <td>
                                        <?php if ($days !== null): ?>
                                            <span class="days-left">
                                                <?php if ($days < 0): ?>
                                                    <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> ago
                                                <?php elseif ($days === 0): ?>
                                                    today
                                                <?php else: ?>
                                                    in <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge <?= $badgeClass ?>"><i class="fa-solid fa-triangle-exclamation"></i> <?= $badgeText ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="toolbar-bottom">
                    <div class="muted">Showing <?= number_format(count($list)) ?> of <?= number_format(array_sum($counts)) ?> expiring items</div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>