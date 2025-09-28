<?php
/**
 * modules/stock/view.php — product detail from Firestore "products"
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

// Accept id (doc id) or sku
// Accept id (doc id) or sku
$stockKey = '';
if (!empty($_GET['id']))  $stockKey = trim((string)$_GET['id']);
if (!empty($_GET['sku'])) $stockKey = trim((string)$_GET['sku']) ?: $stockKey;

if ($stockKey === '') { http_response_code(400); echo 'Bad request: missing id or sku'; exit; }

if (isset($_GET['debug']) && $_GET['debug'] == '1') {
  $all = fs_fetch_all_products();
  echo "<pre style='background:#eef;padding:8px;border:1px solid #99c'>DEBUG\n";
  echo "received key: " . htmlspecialchars($stockKey) . "\n";
  echo "items loaded: " . count($all) . "\n";
  if ($all) {
    $first = $all[0];
    echo "example id: " . htmlspecialchars($first['id'] ?? '') . "\n";
    echo "example sku: " . htmlspecialchars($first['sku'] ?? '') . "\n";
  }
  echo "</pre>";
}

// Fetch (now bullet-proof because of the fallback)
$stock = fs_get_product($stockKey);
if (!$stock) { http_response_code(404); echo 'Stock not found'; exit; }


// Derived/status
$qty        = (int)$stock['quantity'];
$reorder    = (int)$stock['reorder_level'];
$unit       = $stock['unit'] ?? '';
$price      = (float)$stock['price'];     // <- price field
$totalValue = $qty * $price;
$status     = $qty <= 0 ? 'Out of stock' : ($qty <= max(0, $reorder) ? 'Low stock' : 'In stock');
$statusType = $qty <= 0 ? 'alert' : ($qty <= max(0, $reorder) ? 'warning' : 'success');

// Header vars for shared header
$header_title        = $stock['name'];
$header_subtitle     = 'SKU: '.($stock['sku'] ?: '—').' • Category: '.($stock['category'] ?: 'General');
$header_icon         = 'fas fa-box-open';
$show_compact_toggle = false;
$header_stats = [
    ['value' => number_format($qty) . ($unit ? " {$unit}" : ''), 'label' => 'Quantity',    'icon' => 'fas fa-cubes',        'type' => $statusType],
    ['value' => 'RM ' . number_format($price, 2),               'label' => 'Unit Price',  'icon' => 'fas fa-dollar-sign',  'type' => 'primary'],
    ['value' => 'RM ' . number_format($totalValue, 2),          'label' => 'Total Value', 'icon' => 'fas fa-sack-dollar',  'type' => 'success'],
    ['value' => $reorder > 0 ? number_format($reorder) : '—',   'label' => 'Reorder Lv',  'icon' => 'fas fa-level-down-alt','type' => $reorder > 0 ? 'warning' : 'neutral'],
];

$imageUrl   = $stock['image_url'] ?? ''; // not in schema; will be empty
$barcode    = $stock['barcode']   ?? '';
$notes      = $stock['description'] ?? ''; // show description here
$location   = $stock['location']  ?? '—';
$supplier   = $stock['supplier']  ?? '—';
$expiry     = $stock['expiry_date'] ?: '—';
$created    = $stock['created_at']  ?: '—';
$updated    = $stock['updated_at']  ?: '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title><?php echo htmlspecialchars($header_title); ?> • Stock</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="../../assets/css/app.css" />
  <link rel="stylesheet" href="../../assets/css/dashboard.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
</head>
<body>

<?php require_once __DIR__ . '/../../dashboard_header.php'; ?>

<div class="container">
  <main>

    <nav class="breadcrumb">
      <a href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
      <span class="sep">/</span>
      <a href="./list.php"><i class="fas fa-boxes"></i> Stock</a>
      <span class="sep">/</span>
      <span class="current"><?php echo htmlspecialchars($header_title); ?></span>
    </nav>

    <div class="page-actions" style="margin-bottom: 1rem;">
      <a class="btn" href="./edit.php?id=<?php echo urlencode($stock['id']); ?>"><i class="fas fa-edit"></i> Edit</a>
      <a class="btn btn-secondary" href="./list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <div class="grid-2 gap-lg">

      <!-- Left: image/summary -->
      <section class="card p-lg">
        <div class="flex items-start gap-md">
          <div class="thumb-xl">
            <?php if ($imageUrl): ?>
              <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($header_title); ?>" style="max-width:160px;border-radius:12px;object-fit:cover;">
            <?php else: ?>
              <div class="no-image" style="width:160px;height:160px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#6b7280;">
                <i class="fas fa-image fa-2x"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="grow">
            <div class="badge badge-<?php echo $statusType; ?>" style="margin-bottom:.5rem;">
              <i class="fas fa-circle"></i> <?php echo htmlspecialchars($status); ?>
            </div>
            <h2 class="mt-0"><?php echo htmlspecialchars($header_title); ?></h2>
            <p class="muted">Created: <?php echo htmlspecialchars($created); ?> • Updated: <?php echo htmlspecialchars($updated); ?></p>
            <?php if ($barcode): ?><p class="muted"><i class="fas fa-barcode"></i> <?php echo htmlspecialchars($barcode); ?></p><?php endif; ?>
            <?php if ($notes): ?>
              <div class="mt-md">
                <h4 class="mb-sm">Description</h4>
                <p><?php echo nl2br(htmlspecialchars($notes)); ?></p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Right: key facts -->
      <section class="card p-lg">
        <h3 class="mt-0 mb-md">Details</h3>
        <div class="details-grid">
          <div><span class="label">SKU</span><span class="value"><?php echo htmlspecialchars($stock['sku'] ?: '—'); ?></span></div>
          <div><span class="label">Category</span><span class="value"><?php echo htmlspecialchars($stock['category']); ?></span></div>
          <div><span class="label">Quantity</span><span class="value"><?php echo number_format($qty) . ($unit ? " {$unit}" : ''); ?></span></div>
          <div><span class="label">Reorder Level</span><span class="value"><?php echo $reorder > 0 ? number_format($reorder) : '—'; ?></span></div>
          <div><span class="label">Unit Price</span><span class="value">RM <?php echo number_format($price, 2); ?></span></div>
          <div><span class="label">Total Value</span><span class="value">RM <?php echo number_format($totalValue, 2); ?></span></div>
          <div><span class="label">Store ID</span><span class="value"><?php echo htmlspecialchars($stock['store_id'] ?: '—'); ?></span></div>
          <div><span class="label">Location</span><span class="value"><?php echo htmlspecialchars($location); ?></span></div>
          <div><span class="label">Supplier</span><span class="value"><?php echo htmlspecialchars($supplier); ?></span></div>
          <div><span class="label">Expiry Date</span><span class="value"><?php echo htmlspecialchars($expiry ?: '—'); ?></span></div>
        </div>
      </section>
    </div>

  </main>
</div>

</body>
</html>
