<?php
// stock/stockAuditHis.php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
session_start();

// --- Admin-only guard ---
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo '
    <div style="
        font-family: Segoe UI, Arial, sans-serif;
        background-color: #f8fafc;
        color: #b91c1c;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100vh;
        text-align: center;
    ">
        <h1 style="font-size: 48px; margin-bottom: 10px;">403 Forbidden</h1>
        <p style="font-size: 20px; color: #7f1d1d; font-weight: 600;">
            Access Denied: Admins Only
        </p>
        <a href="../../index.php" 
           style="margin-top: 20px; display:inline-block; 
                  background:#2563eb; color:#fff; 
                  padding:10px 20px; border-radius:8px; 
                  text-decoration:none; font-size:14px;">
            Back to Dashboard
        </a>
    </div>';
    exit;
}


$page_title = 'Stock Audit History - Inventory System';

// --- Filters (optional) ---
$actionFilter = isset($_GET['action']) ? trim($_GET['action']) : '';
$skuFilter    = isset($_GET['sku'])    ? strtoupper(trim($_GET['sku'])) : '';
$pidFilter    = isset($_GET['pid'])    ? trim($_GET['pid']) : '';
$limit        = isset($_GET['limit'])  ? max(10, (int)$_GET['limit']) : 200;

$records = [];
try {
    require_once __DIR__ . '/../../sql_db.php';
    $sqlDb = SQLDatabase::getInstance();
    
    $query = "SELECT * FROM stock_audits WHERE 1=1";
    $params = [];
    
    if ($actionFilter !== '') {
        $query .= " AND action = ?";
        $params[] = $actionFilter;
    }
    if ($skuFilter !== '') {
        $query .= " AND sku = ?";
        $params[] = $skuFilter;
    }
    if ($pidFilter !== '') {
        $query .= " AND product_id = ?";
        $params[] = $pidFilter;
    }
    
    $query .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $rows = $sqlDb->fetchAll($query, $params);
    
    foreach ($rows as $r) {
        $records[] = [
            'id'              => $r['id'],
            'created_at'      => $r['created_at'],
            'action'          => $r['action'],
            'product_id'      => $r['product_id'],
            'sku'             => $r['sku'],
            'product_name'    => $r['product_name'],
            'store_id'        => $r['store_id'],
            'quantity_before' => $r['quantity_before'],
            'quantity_after'  => $r['quantity_after'],
            'quantity_delta'  => $r['quantity_delta'],
            'description_before' => $r['description_before'],
            'description_after' => $r['description_after'],
            'reorder_before'  => $r['reorder_before'],
            'reorder_after'   => $r['reorder_after'],
            'changed_by'      => $r['changed_by'],
            'changed_name'    => $r['changed_name'],
        ];
    }
} catch (Throwable $t) {
    error_log('load stock_audits SQL failed: ' . $t->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ===== STOCK AUDIT HISTORY (ADMIN) ===== */
        body {
            font-family: "Inter", "Segoe UI", Roboto, Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }

        /* --- Page Container --- */
        .audit-wrap {
            max-width: 1200px;
            margin: 40px auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            padding: 30px 36px;
            border: 1px solid #e2e8f0;
        }

        .audit-wrap h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .audit-wrap h1::before {
            content: "\f46d";
            /* clipboard-list icon */
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            color: #1d4ed8;
        }

        /* --- Filter Toolbar --- */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            background: #f1f5f9;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 18px;
            border: 1px solid #e2e8f0;
        }

        .filters select,
        .filters input[type="text"] {
            height: 38px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background-color: #fff;
            font-size: 14px;
            color: #0f172a;
        }

        .filters input::placeholder {
            color: #94a3b8;
        }

        .filters .btn {
            height: 38px;
            padding: 0 14px;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .filters .btn i {
            font-size: 14px;
        }

        .btn-blue {
            background: #2563eb;
            color: white;
        }

        .btn-blue:hover {
            background: #1d4ed8;
        }

        .btn-ghost {
            background: #e2e8f0;
            color: #334155;
        }

        .btn-ghost:hover {
            background: #cbd5e1;
        }

        /* --- Table Styling --- */
        .audit-table {
            width: 100%;
            border-collapse: separate;
            /* allows precise border control */
            border-spacing: 0;
            /* remove gaps */
            margin-top: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .audit-table thead th {
            background-color: #f8fafc;
            font-weight: 700;
            color: #1e293b;
            font-size: 13px;
            text-align: left;
            padding: 10px 12px;
            height: 42px;
            border-bottom: 1px solid #e2e8f0;
        }

        .audit-table th:first-child {
            border-top-left-radius: 10px;
        }

        .audit-table th:last-child {
            border-top-right-radius: 10px;
        }

        .audit-table td {
            padding: 10px 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 13.5px;
            color: #0f172a;
            height: 40px;
            vertical-align: middle;
        }

        .audit-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .audit-table tbody tr:hover {
            background-color: #f1f5f9;
        }

        .audit-table td:nth-child(4),
        .audit-table td:nth-child(5),
        .audit-table td:nth-child(6),
        .audit-table th:nth-child(4),
        .audit-table th:nth-child(5),
        .audit-table th:nth-child(6) {
            text-align: center;
        }

        .audit-table td[colspan="9"] {
            text-align: center;
            color: #64748b;
            font-style: italic;
            padding: 16px;
        }


        /* --- Badges --- */
        .badge {
            display: inline-block;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 999px;
            text-transform: none;
            border: 1px solid transparent;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e3a8a;
            border-color: #bfdbfe;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }

        .badge-secondary {
            background: #ede9fe;
            color: #5b21b6;
            border-color: #ddd6fe;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .badge-light {
            background: #f1f5f9;
            color: #334155;
            border-color: #cbd5e1;
        }

        /* --- Quantity delta --- */
        .delta-pos {
            color: #166534;
            font-weight: 700;
        }

        .delta-neg {
            color: #b91c1c;
            font-weight: 700;
        }

        /* --- Responsive --- */
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .filters select,
            .filters input[type="text"],
            .filters .btn {
                width: 100%;
            }

            .audit-wrap {
                padding: 20px;
            }

            .audit-table th,
            .audit-table td {
                font-size: 13px;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="audit-wrap">
        <h1>Stock Audit History (Admin)</h1>

        <form class="filters" method="get">
            <select name="action">
                <option value="">All actions</option>
                <option value="create" <?= $actionFilter === 'create' ? 'selected' : '' ?>>Product Added</option>
                <option value="adjust_quantity" <?= $actionFilter === 'adjust_quantity' ? 'selected' : '' ?>>Quantity Adjusted</option>
                <option value="update_description" <?= $actionFilter === 'update_description' ? 'selected' : '' ?>>Description Updated</option>
                <option value="update_min_level" <?= $actionFilter === 'update_min_level' ? 'selected' : '' ?>>Min Level Updated</option>
                <option value="delete_product" <?= $actionFilter === 'delete_product' ? 'selected' : '' ?>>Product Deleted</option>
            </select>
            <input type="text" name="sku" placeholder="SKU (e.g. G-002)" value="<?= htmlspecialchars($skuFilter) ?>">
            <select name="limit">
                <?php foreach ([50, 100, 200, 500] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>>Show <?= $n ?> records</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-blue" type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a class="btn btn-ghost" href="stockAuditHis.php">Reset</a>
        </form>

        <table class="audit-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Action</th>
                    <th>Qty Before → After</th>
                    <th>Δ</th>
                    <th>Timestamp</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9">No audit records found.</td>
                    </tr>
                    <?php else: foreach ($records as $r): ?>
                        <?php
                        $badgeClass = match ($r['action']) {
                            'create'             => 'badge b-create',
                            'adjust_quantity'    => 'badge b-adjust',
                            'update_description' => 'badge b-desc',
                            'update_min_level'   => 'badge b-desc',
                            'remove_stock'       => 'badge b-remove',
                            'delete_product'     => 'badge b-remove',
                            default              => 'badge',
                        };
                        $delta  = $r['quantity_delta'];
                        $dClass = ($delta === null) ? '' : (($delta >= 0) ? 'delta-pos' : 'delta-neg');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($r['sku'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['product_name'] ?? '') ?></td>
                            <?php
                            $action = $r['action'] ?? '';
                            $label = '';
                            $class = '';

                            switch ($action) {
                                case 'create':
                                    $label = 'Product Added';
                                    $class = 'badge badge-success';
                                    break;
                                case 'adjust_quantity':
                                    $label = 'Quantity Adjusted';
                                    $class = 'badge badge-info';
                                    break;
                                case 'update_description':
                                    $label = 'Description Updated';
                                    $class = 'badge badge-warning';
                                    break;
                                case 'update_min_level':
                                    $label = 'Min Level Updated';
                                    $class = 'badge badge-secondary';
                                    break;
                                case 'delete_product':
                                    $label = 'Product Deleted';
                                    $class = 'badge badge-danger';
                                    break;
                                default:
                                    $label = ucfirst(str_replace('_', ' ', $action));
                                    $class = 'badge badge-light';
                                    break;
                            }
                            ?>
                            <td><span class="<?= $class ?>"><?= $label ?></span></td>

                            <td>
                                <?php
                                $action = $r['action'] ?? '';
                                if (in_array($action, ['adjust_quantity', 'remove_stock'], true)) {
                                    // show quantity before → after
                                    if ($r['quantity_before'] !== null || $r['quantity_after'] !== null) {
                                        echo htmlspecialchars((string)$r['quantity_before']) . ' → ' . htmlspecialchars((string)$r['quantity_after']);
                                    } else {
                                        echo '−';
                                    }
                                } elseif ($action === 'update_min_level') {
                                    // show min level before → after (use the qty column to highlight min level change)
                                    if ($r['reorder_before'] !== null || $r['reorder_after'] !== null) {
                                        echo htmlspecialchars((string)$r['reorder_before']) . ' → ' . htmlspecialchars((string)$r['reorder_after']);
                                    } else {
                                        echo '−';
                                    }
                                } else {
                                    // update_description or others
                                    echo '−';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $action = $r['action'] ?? '';
                                if (
                                    in_array($action, ['adjust_quantity', 'remove_stock'], true) &&
                                    $r['quantity_before'] !== null && $r['quantity_after'] !== null
                                ) {
                                    $delta = (int)$r['quantity_after'] - (int)$r['quantity_before'];
                                } elseif (
                                    $action === 'update_min_level' &&
                                    $r['reorder_before'] !== null && $r['reorder_after'] !== null
                                ) {
                                    // delta based on min level change
                                    $delta = (int)$r['reorder_after'] - (int)$r['reorder_before'];
                                } else {
                                    $delta = null;
                                }

                                if ($delta === null) {
                                    echo '−';
                                } else {
                                    $dClass = $delta >= 0 ? 'delta-pos' : 'delta-neg';
                                    echo '<span class="' . $dClass . '">' . ($delta >= 0 ? '+' : '') . $delta . '</span>';
                                }
                                ?>
                            </td>

                            <td><?= htmlspecialchars($r['created_at'] ? date('Y-m-d H:i:s', strtotime($r['created_at'])) : '-') ?></td>
                            <td><?= htmlspecialchars($r['changed_name'] ?? ($r['changed_by'] ?? '-')) ?></td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>