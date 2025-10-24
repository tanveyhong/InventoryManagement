<?php
// modules/reports/inventory_report.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../getDB.php';

// ---------- Auth ----------
if (!isset($_SESSION['user_id'])) {
  header('Location: ../users/login.php');
  exit;
}

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function db_obj()
{
  return function_exists('getDB') ? @getDB() : null;
}

// Firestore for products, SQL for stores
$fs     = db_obj();      // Firestore wrapper
$sqlDb  = getDB();       // SQL wrapper (same one add.php uses)

$all_products = [];
$categories   = [];

// ---- Load products from Firestore ----
if ($fs) {
  try {
    // IMPORTANT: Increased limit but consider adding pagination for large inventories
    $productDocs = $fs->readAll('products', [], null, 1000);
    foreach ($productDocs as $r) {
      if (!empty($r['deleted_at'])) continue;
      if (isset($r['status']) && $r['status'] === 'disabled') continue;

      // Skip out-of-stock items
      if (isset($r['quantity']) && (int)$r['quantity'] === 0) continue;

      $all_products[] = [
        'doc_id'          => $r['id'] ?? ($r['doc_id'] ?? null),
        'id'              => $r['id'] ?? null,
        'name'            => $r['name'] ?? '',
        'sku'             => $r['sku'] ?? '',
        'description'     => $r['description'] ?? '',
        'quantity'        => isset($r['quantity']) ? (int)$r['quantity'] : 0,
        'min_stock_level' => isset($r['reorder_level']) ? (int)$r['reorder_level'] : (isset($r['min_stock_level']) ? (int)$r['min_stock_level'] : 0),
        'unit_price'      => isset($r['price']) ? (float)$r['price'] : (isset($r['unit_price']) ? (float)$r['unit_price'] : 0.0),
        'expiry_date'     => $r['expiry_date'] ?? null,
        'created_at'      => $r['created_at'] ?? null,
        'updated_at'      => $r['updated_at'] ?? null,
        'category_name'   => $r['category'] ?? ($r['category_name'] ?? null),
        'store_id'        => $r['store_id'] ?? null,
        '_raw'            => $r,
      ];

      if (!empty($r['category'] ?? null)) {
        $categories[$r['category']] = true;
      } elseif (!empty($r['category_name'] ?? null)) {
        $categories[$r['category_name']] = true;
      }
    }
  } catch (Throwable $e) {
    error_log('Inventory report load failed: ' . $e->getMessage());
    $all_products = [];
  }
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);


$category     = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$storeFilter  = isset($_GET['store_id'])
                  ? trim((string)$_GET['store_id'])
                  : trim((string)($_GET['location'] ?? '')); // legacy fallback
$date_field   = isset($_GET['date_field']) ? trim((string)$_GET['date_field']) : 'created_at'; // created_at | updated_at | expiry_date
$from_date    = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$to_date      = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
$do_preview   = isset($_GET['run']) && $_GET['run'] === '1';
$export       = isset($_GET['export']) ? trim((string)$_GET['export']) : ''; // excel | pdf

$stores = [];
try {
  if ($sqlDb && method_exists($sqlDb, 'fetchAll')) {
    $stores = $sqlDb->fetchAll("SELECT id, name FROM stores WHERE COALESCE(is_active,1)=1 ORDER BY name");
  }
} catch (Throwable $t) {
  error_log('Load stores failed: ' . $t->getMessage());
}

// Build store id => name map
$storeMap = [];
foreach ($stores as $s) {
  $sid = (string)($s['id'] ?? '');
  $sname = (string)($s['name'] ?? '');
  if ($sid !== '' && $sname !== '') $storeMap[$sid] = $sname;
}

// ---------- Filter + hide disabled ----------
function is_active_product(array $p): bool
{
  if (!empty($p['deleted_at'])) return false;
  $dbStatus = $p['status_db'] ?? '';
  if (is_string($dbStatus) && strtolower($dbStatus) === 'disabled') return false;
  return true;
}

$filtered = [];
if ($do_preview || $export) {
  $filtered = array_values(array_filter($all_products, function ($p) use ($category, $storeFilter, $date_field, $from_date, $to_date) {
    if (!is_active_product($p)) return false;

    if ($category !== '' && ($p['category_name'] ?? '') !== $category) return false;
    if ($storeFilter !== '' && (string)($p['store_id'] ?? '') !== $storeFilter) return false;

    // Date range filter
    if (!in_array($date_field, ['created_at', 'updated_at', 'expiry_date'], true)) $date_field = 'created_at';
    $dv = $p[$date_field] ?? null;
    if ($from_date !== '' || $to_date !== '') {
      if (!$dv) return false;
      $ts = strtotime($dv);
      if ($ts === false) return false;
      if ($from_date !== '' && $ts < strtotime($from_date . ' 00:00:00')) return false;
      if ($to_date   !== '' && $ts > strtotime($to_date . ' 23:59:59')) return false;
    }

    return true;
  }));
}

$storeFilter = isset($_GET['store_id'])
  ? trim((string)$_GET['store_id'])
  : trim((string)($_GET['location'] ?? ''));

// load stores for dropdown (id, name)
$stores = [];
try {
  $stores = $db->fetchAll("SELECT id, name FROM stores WHERE COALESCE(is_active,1)=1 ORDER BY name");
} catch (Throwable $t) {
  error_log('Load stores failed: ' . $t->getMessage());
}

// ---------- Export handlers ----------
if ($export === 'excel') {
  // Try PhpSpreadsheet if available; else fall back to CSV (Excel opens it fine).
  $filename = 'Inventory Report_' . date('Ymd_His');
  $columns = ['Name', 'SKU', 'Category', 'Store', 'Quantity', 'Min Level', 'Unit Price (RM)', 'Total Value (RM)', 'Expiry Date', 'Created At'];

  // Fallback CSV
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, $columns);

foreach ($filtered as $p) {
  $sid = (string)($p['store_id'] ?? '');
  $storeName = ($sid !== '' && isset($storeMap[$sid])) ? $storeMap[$sid] : '';

  $row = [
    $p['name'] ?? '',
    $p['sku'] ?? '',
    $p['category_name'] ?? '',
    $storeName,
    (string)($p['quantity'] ?? 0),
    (string)($p['min_stock_level'] ?? 0),
    number_format((float)($p['unit_price'] ?? 0), 2, '.', ''),
    number_format(((float)($p['unit_price'] ?? 0) * (int)($p['quantity'] ?? 0)), 2, '.', ''),
    $p['expiry_date'] ?? '',
    $p['created_at'] ?? '',
  ];
  fputcsv($out, $row);
}
  fclose($out);
  exit;
}

if ($export === 'pdf') {
  // Use Dompdf if available; else show a friendly message.
  $hasDompdf = class_exists('\Dompdf\Dompdf');
  if ($hasDompdf) {
    $html = '<h2 style="margin:0 0 10px">Inventory Report</h2>';
    $html .= '<div style="font-size:12px;margin-bottom:8px">';
    $html .= 'Category: ' . h($category ?: 'All') . ' &nbsp; | &nbsp; Store: ' . h($storeFilter ?: 'All') . ' &nbsp; | &nbsp; Date Field: ' . h($date_field);
    if ($from_date || $to_date) $html .= ' &nbsp; | &nbsp; Range: ' . h($from_date ?: '—') . ' to ' . h($to_date ?: '—');
    $html .= '</div>';
    $html .= '<table width="100%" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-size:12px">';
    $html .= '<thead><tr style="background:#e5e7eb">';
    $heads = ['Name', 'SKU', 'Category', 'Store', 'Qty', 'Min', 'Unit Price (RM)', 'Total (RM)', 'Expiry', 'Created'];
    foreach ($heads as $hcell) $html .= '<th style="border:1px solid #cbd5e1;text-align:left">' . $h($hcell) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($filtered as $p) {
      $html .= '<tr>';
      $html .= '<td style="border:1px solid #e5e7eb">' . h($p['name'] ?? '') . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . h($p['sku'] ?? '') . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . h($p['category_name'] ?? '') . '</td>';
$sid = (string)($p['store_id'] ?? '');
$storeName = ($sid !== '' && isset($storeMap[$sid])) ? $storeMap[$sid] : '';
$html .= '<td style="border:1px solid #e5e7eb">' . h($storeName) . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . (int)($p['quantity'] ?? 0) . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . (int)($p['min_stock_level'] ?? 0) . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . number_format((float)($p['unit_price'] ?? 0), 2) . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . number_format(((float)($p['unit_price'] ?? 0) * (int)($p['quantity'] ?? 0)), 2) . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . h($p['expiry_date'] ?? '') . '</td>';
      $html .= '<td style="border:1px solid #e5e7eb">' . h($p['created_at'] ?? '') . '</td>';
      $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('inventory_report_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
    exit;
  } else {
    // Friendly fallback
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PDF Export</title></head><body>';
    echo '<p style="font-family:system-ui">PDF export requires <code>dompdf/dompdf</code>. ';
    echo 'Please install it via Composer, then retry. For now, you can use the browser’s “Print to PDF”.</p>';
    echo '<p><a href="?' . h(http_build_query(array_merge($_GET, ['export' => null]))) . '">Back</a></p>';
    echo '</body></html>';
    exit;
  }
}

// ---------- Page ----------
$page_title = 'Inventory Report – Stock Management';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title><?php echo h($page_title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body {
      background: #f6f8fb
    }

    .wrap {
      max-width: 1100px;
      margin: 20px auto;
      padding: 0 18px
    }

    .card {
      background: #fff;
      border: 1px solid #e5eaf1;
      border-radius: 14px;
      box-shadow: 0 8px 30px rgba(2, 6, 23, .04);
      margin-bottom: 16px
    }

    .card .inner {
      padding: 18px
    }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 10px 0 14px
    }

    .page-header h2 {
      margin: 0;
      font-size: 1.5rem;
      color: #0f172a
    }

    .grid {
      display: grid;
      gap: 14px
    }

    .grid-3 {
      grid-template-columns: 1fr 1fr 1fr
    }

    @media (max-width:900px) {
      .grid-3 {
        grid-template-columns: 1fr
      }
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 6px
    }

    .label {
      font-weight: 700;
      color: #0f172a;
      font-size: .92rem
    }

    .control {
      border: 1px solid #dfe6f2;
      border-radius: 10px;
      padding: .6rem .8rem;
      background: #fff
    }

    .control:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .15)
    }

    .toolbar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap
    }

    .btn {
      border: none;
      border-radius: 10px;
      padding: .7rem 1rem;
      font-weight: 700;
      cursor: pointer
    }

    .btn-primary {
      background: #2563eb;
      color: #fff
    }

    .btn-outline {
      background: #fff;
      border: 1px solid #dfe6f2
    }

    .filters-note {
      color: #64748b;
      font-size: .9rem;
      margin-bottom: 6px
    }

    .table-container {
      overflow: auto
    }

    table.report {
      width: 100%;
      border-collapse: collapse
    }

    table.report thead th {
      background: #1e293b;
      color: #fff;
      letter-spacing: .5px;
      padding: 10px 12px;
      border-bottom: 2px solid #0f172a
    }

    table.report th,
    table.report td {
      border-bottom: 1px solid #eef2f7;
      padding: 10px 12px;
      text-align: left
    }

    .summary {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      margin: 8px 0 0
    }

    .pill {
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 999px;
      padding: 6px 10px;
      font-weight: 600
    }

    /* Back to Stock button (grey) */
    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #e5e7eb;
      /* light grey */
      color: #374151;
      /* dark grey text */
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 10px;
      font-weight: 600;
      transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
    }

    .btn-back:hover {
      background: #d1d5db;
      /* darker grey on hover */
      color: #111827;
      /* darker text */
      transform: translateY(-1px);
    }

    /* Export buttons (green) */
    .btn-export {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #16a34a;
      /* emerald green */
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 10px;
      font-weight: 600;
      transition: background 0.25s ease, transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn-export:hover {
      background: #15803d;
      /* slightly darker green on hover */
      box-shadow: 0 4px 10px rgba(21, 128, 61, 0.25);
      transform: translateY(-1px);
    }

    .print-header,
    .print-footer {
      display: none;
    }

    /* Print layout */
      @media print {

        /* Page setup */
        @page {
          size: A4 landscape;
          /* or 'A4 portrait' */
          margin: 14mm 12mm;
          /* top/bottom left/right */
        }

        /* Make colors print as seen (Chrome) */
        * {
          -webkit-print-color-adjust: exact;
          print-color-adjust: exact;
        }

        .no-print {
          display: none !important;
        }

        /* Hide chrome when printing */
        .page-header,
        .toolbar,
        .filters-panel,
        .filters-form,
        .stock-summary,
        .btn,
        .btn-back,
        nav,
        header,
        footer,
        .criteria-panel,
        .criteria-hint,
        .criteria-row {
          display: none !important;
        }

        /* Show print header/footer only on paper */
        .print-header,
        .print-footer {
          display: block !important;
        }


        /* Print header */
        .print-header {
          margin-bottom: 10px;
          border-bottom: 2px solid #111827;
          padding-bottom: 8px;
          font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        }

        .print-header .title {
          font-size: 20px;
          font-weight: 800;
          margin: 0 0 4px;
          color: #111827;
        }

        .print-header .meta {
          font-size: 12px;
          color: #111827;
        }

        .print-header .meta span {
          display: inline-block;
          margin-right: 12px;
        }

        .print-header .brand {
          display: flex;
          align-items: center;
          gap: 10px;
        }

        .print-header .brand img {
          height: 28px;
          width: auto;
        }

        /* Table: make it printable and tidy */
        .table-container {
          overflow: visible !important;
        }

        table.report {
          width: 100%;
          border-collapse: collapse;
          table-layout: fixed;
          /* align th/td widths */
          font-size: 12px;
        }

        table.report thead th,
        table.report tbody td {
          border: 1px solid #000;
          /* strong borders for paper */
          padding: 6px 8px;
          text-align: left;
        }

        /* Repeat header/footer on each page */
        thead {
          display: table-header-group;
        }

        tfoot {
          display: table-row-group !important;
        }

        /* Avoid breaking row items across pages */
        tr,
        img {
          page-break-inside: avoid;
          break-inside: avoid;
        }

        /* Footer pinned at bottom */
        .print-footer {
          position: static !important;
          /* was: fixed */
          bottom: auto;
          left: auto;
          right: auto;
          margin-top: 12mm;
          font-size: 11px;
          color: #111827;
          display: flex;
          justify-content: space-between;
        }

      }
  </style>
</head>

<body>
  <?php
  include '../../includes/dashboard_header.php';
  ?>
  <div class="wrap">
    <div class="page-header">
      <h2>Inventory Report Generation</h2>
      <div class="toolbar">
        <a href="../stock/list.php" class="btn btn-back"><i class="fas fa-boxes-stacked"></i>&nbsp; Back to Stock</a>
      </div>
    </div>

    <div class="no-print">
      <div class="card">
        <form method="get" class="inner">
          <div class="filters-note">Select criteria and click <strong>Generate Report</strong>.</div>

          <div class="grid grid-3">
            <div class="field">
              <label class="label">Category</label>
              <select class="control" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?php echo h($c); ?>" <?php echo $category === $c ? 'selected' : ''; ?>>
                    <?php echo h($c); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

<div class="field">
  <label class="label">Store</label>
  <select class="control" name="store_id">
    <option value="">All stores</option>
    <?php
      foreach ($stores as $s):
        $sid   = (string)$s['id'];
        $sname = (string)$s['name'];
        if ($sid === '' || $sname === '') continue;
        $sel = ($sid === $storeFilter) ? 'selected' : '';
    ?>
      <option value="<?= htmlspecialchars($sid) ?>" <?= $sel ?>>
        <?= htmlspecialchars($sname) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>



            <div class="field">
              <label class="label">Date Field</label>
              <select class="control" name="date_field">
                <option value="Created At" <?php echo $date_field === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                <option value="Expiry Date" <?php echo $date_field === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
              </select>
            </div>
          </div>

          <div class="grid grid-3" style="margin-top:10px">
            <div class="field">
              <label class="label">From</label>
              <input class="control" type="date" name="from" value="<?php echo h($from_date); ?>">
            </div>
            <div class="field">
              <label class="label">To</label>
              <input class="control" type="date" name="to" value="<?php echo h($to_date); ?>">
            </div>
            <div class="field" style="align-self:end">
              <button type="submit" name="run" value="1" class="btn btn-primary">
                <i class="fas fa-file-lines"></i> Generate Report
              </button>
            </div>
          </div>
      </div>

      </form>
    </div>

    <?php if ($do_preview): ?>
  <?php
  // Ensure we have id->name map available here (defensive)
  if (!isset($storeMap) || !is_array($storeMap)) {
      $storeMap = [];
      if (isset($stores) && is_array($stores)) {
          foreach ($stores as $s) {
              $sid   = (string)($s['id'] ?? '');
              $sname = (string)($s['name'] ?? '');
              if ($sid !== '' && $sname !== '') $storeMap[$sid] = $sname;
          }
      }
  }

  // Pretty label for the currently selected store in the print header
  $selectedStoreName = 'All';
  if (!empty($storeFilter)) {
      $selectedStoreName = $storeMap[$storeFilter] ?? $storeFilter; // fallback to id if name missing
  }
  ?>
  <div class="card">
    <div class="inner">
      <div class="toolbar" style="justify-content:space-between;align-items:center;margin-bottom:10px">
        <div>
          <span class="pill">Matches: <?php echo count($filtered); ?></span>
          <?php
          $totalValue = 0;
          $totalQty = 0;
          foreach ($filtered as $p) {
            $totalQty  += (int)($p['quantity'] ?? 0);
            $totalValue += ((float)($p['unit_price'] ?? 0)) * (int)($p['quantity'] ?? 0);
          }
          ?>
          <span class="pill">Total Qty: <?php echo number_format($totalQty); ?></span>
          <span class="pill">Total Value: RM <?php echo number_format($totalValue, 2); ?></span>
        </div>
        <div class="toolbar">
          <a class="btn btn-export" href="?<?php
            $q = $_GET; $q['export'] = 'excel'; echo h(http_build_query($q));
          ?>"><i class="fas fa-file-excel"></i> Export Excel</a>
          <button onclick="window.print()" class="btn btn-export">
            <i class="fas fa-file-pdf"></i> Export to PDF
          </button>
        </div>
      </div>

      <!-- Print-only header -->
      <div class="print-header">
        <div class="brand">
          <!-- <img src="...logo..." alt="Logo"> -->
          <div>
            <div class="title">Inventory Report</div>
            <div class="meta">
              <span><strong>Category:</strong> <?php echo h($category ?: 'All'); ?></span>
              <span><strong>Store:</strong> <?php echo h($selectedStoreName); ?></span>
              <span><strong>Date Field:</strong> <?php echo h($date_field); ?></span>
              <span><strong>Range:</strong> <?php echo h($from_date ?: '—'); ?> to <?php echo h($to_date ?: '—'); ?></span>
              <span><strong>Generated:</strong> <?php echo date('Y-m-d H:i'); ?></span>
            </div>
          </div>
        </div>
      </div>

      <?php if (empty($filtered)): ?>
        <p style="color:#475569;margin:6px 0 0">No products match the selected criteria.</p>
      <?php else: ?>
        <div class="table-container">
          <table class="report">
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Store</th> <!-- changed from Location -->
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total Value</th>
                <th>Expiry</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($filtered as $p): ?>
                <?php
                  // Resolve store name for the row
                  $sid = (string)($p['store_id'] ?? '');
                  $rowStoreName = $sid !== '' ? ($storeMap[$sid] ?? '—') : '—';
                ?>
                <tr>
                  <td><?php echo h($p['name'] ?? ''); ?></td>
                  <td><?php echo h($p['sku'] ?? ''); ?></td>
                  <td><?php echo h($p['category_name'] ?? ''); ?></td>
                  <td><?php echo h($rowStoreName); ?></td> <!-- use store name -->
                  <td><?php echo number_format((int)($p['quantity'] ?? 0)); ?></td>
                  <td>RM <?php echo number_format((float)($p['unit_price'] ?? 0), 2); ?></td>
                  <td>RM <?php echo number_format(((float)($p['unit_price'] ?? 0) * (int)($p['quantity'] ?? 0)), 2); ?></td>
                  <td><?php echo !empty($p['expiry_date']) ? h(date('M j, Y', strtotime($p['expiry_date']))) : '—'; ?></td>
                  <td><?php echo !empty($p['created_at']) ? h(date('M j, Y', strtotime($p['created_at']))) : '—'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
                <tfoot>
                  <tr class="totals-row">
                    <th colspan="4" style="text-align:right;">Totals</th>
                    <th><?php echo number_format($totalQty); ?></th>
                    <th></th>
                    <th>RM <?php echo number_format($totalValue, 2); ?></th>
                    <th colspan="2"></th>
                  </tr>
                </tfoot>
              </table>
            </div>
          <?php endif; ?>
          <!-- Print-only footer -->
          <div class="print-footer">
            <div>Generated by Inventory Management System</div>
            <div><?php echo date('Y-m-d H:i'); ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>

</html>