<?php
// modules/stock/edit.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

// --- helpers (lightweight, safe) ---
function norm_sku(string $raw): string
{
  $sku = strtoupper(trim($raw));
  $sku = preg_replace('/\s+/', '-', $sku);
  return preg_replace('/[^A-Z0-9._\-]/', '', $sku);
}
function db_obj()
{
  require_once __DIR__ . '/../../getDB.php';
  return function_exists('getDB') ? @getDB() : null;
}
/** Find by SKU using your list source (works even without query support) */
function _find_product_by_sku_live(string $sku): ?array
{
  $sku = norm_sku($sku);
  if ($sku === '') return null;

  $all = fs_fetch_all_products();
  foreach ($all as $p) {
    if (!empty($p['sku']) && norm_sku((string)$p['sku']) === $sku) return $p;
  }
  return null;
}

// ----- load target product -----
$docId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$skuQ  = isset($_GET['sku']) ? norm_sku((string)$_GET['sku']) : '';

$stock = null;
if ($docId !== '') $stock = fs_get_product_by_doc($docId);
if (!$stock && $skuQ !== '') $stock = fs_get_product($skuQ);

if (!$stock) {
  http_response_code(404);
  echo 'Product not found.';
  exit;
}
$docId = $stock['doc_id']; // ensure we have doc id for updates

$errors = [];
$notice = '';

// ----- handle POST (save) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name          = trim((string)($_POST['name'] ?? $stock['name']));
  $skuInput      = (string)($_POST['sku'] ?? ($stock['sku'] ?? ''));
  $sku           = norm_sku($skuInput);               // optional; can be blank
  $category      = (string)($_POST['category'] ?? ($stock['category'] ?? 'General'));
  $description   = trim((string)($_POST['description'] ?? ($stock['description'] ?? '')));
  $quantity      = (int)($_POST['quantity'] ?? (int)$stock['quantity']);
  $reorderLevel  = (int)($_POST['reorder_level'] ?? (int)$stock['reorder_level']);
  $price         = (float)($_POST['price'] ?? (float)$stock['price']);
  $expiryDate    = trim((string)($_POST['expiry_date'] ?? ($stock['expiry_date'] ?? '')));
  $storeId       = trim((string)($_POST['store_id'] ?? ($stock['store_id'] ?? '')));
  $location      = trim((string)($_POST['location'] ?? ($stock['location'] ?? '')));
  $unit          = trim((string)($_POST['unit'] ?? ($stock['unit'] ?? '')));
  $barcode       = trim((string)($_POST['barcode'] ?? ($stock['barcode'] ?? '')));


  // Basic validation
  if ($name === '') $errors[] = 'Product name is required.';
  if ($quantity < 0) $errors[] = 'Quantity cannot be negative.';
  if ($reorderLevel < 0) $errors[] = 'Reorder level cannot be negative.';
  if ($price < 0) $errors[] = 'Price cannot be negative.';

  // Only check SKU uniqueness if user changed it AND it is non-empty
  $oldSku = norm_sku($stock['sku'] ?? '');
  if (empty($errors) && $sku !== '' && $sku !== $oldSku) {
    $dup = _find_product_by_sku_live($sku);
    if ($dup && (string)$dup['doc_id'] !== (string)$docId) {
      $errors[] = "SKU '{$sku}' already exists (product: " . htmlspecialchars($dup['name'] ?? '') . ").";
    }
  }


  if (empty($errors)) {

    $originalCreatedAt = $stock['created_at'] ?? null;
    $data = [
      'name'          => $name,
      'category'      => $category,
      'description'   => $description,
      'quantity'      => $quantity,
      'reorder_level' => $reorderLevel,
      'price'         => $price,
      'expiry_date'   => $expiryDate !== '' ? $expiryDate : null,
      'store_id'      => $storeId,
      'location'      => $location,
      'unit'          => $unit,
      'barcode'       => $barcode,
      'updated_at'    => date('c'),
    ];
    // include SKU only if set (let it be empty to clear if you want)
    $data['sku'] = $sku;

    if (!empty($originalCreatedAt)) {
      $data['created_at'] = $originalCreatedAt;
    }

    $db = db_obj();
    if (!$db || !method_exists($db, 'update')) {
      $errors[] = 'Database update not available.';
    } else {
      try {
        $db->update('products', $docId, $data);
        // refresh local copy for re-render
        $stock = fs_get_product_by_doc($docId);
        $notice = 'Product updated successfully.';
        // redirect to view page (comment this if you prefer staying on edit)
        header('Location: view.php?id=' . rawurlencode($docId) . '&updated=1');
        exit;
      } catch (Throwable $e) {
        $errors[] = 'Failed to update product: ' . $e->getMessage();
      }
    }
  }
}

// ---- UI ----
$CATEGORY_OPTIONS = $CATEGORY_OPTIONS ?? [
  'General',
  'Foods',
  'Beverages',
  'Snacks',
  'Personal Care',
  'Furniture',
  'Electronics',
  'Stationery',
  'Canned Foods',
  'Frozen'
];

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Edit Product – <?php echo h($stock['name']); ?></title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Inter, Helvetica, Arial, sans-serif;
      background: #f6f8fb;
      margin: 0
    }

/* Hover effect */
.btn.btn-outline:hover {
  background-color: #e2e8f0;  /* slightly darker grey */
  border-color: #cbd5e1;
  color: #111827;
  transform: translateY(-1px);
}
  .alert{margin:10px 0;padding:10px 12px;border-radius:10px}
  .alert-error{background:#fee2e2;color:#7f1d1d;border:1px solid #fecaca}
  .alert-ok{background:#ecfdf5;color:#065f46;border:1px solid #d1fae5}

  /* Main content spacing */
  .main-content {
      margin-top: 80px;
      padding: 20px 0;
  }

  .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 20px;
  }
</style>
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
<div class="main-content">
<div class="container">
<div class="shell">

    <div class="page-header">
      <h1>Edit Product</h1>
      <div><a href="view.php?id=<?php echo h($docId); ?>" class="btn btn-outline">← Back to Product</a></div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $e) echo '<div>' . h($e) . '</div>'; ?>
      </div>
    <?php elseif ($notice): ?>
      <div class="alert alert-ok"><?php echo h($notice); ?></div>
    <?php endif; ?>

    <div class="card">
      <form method="post" class="inner">
        <div class="grid grid-2">
          <div class="field">
            <label class="label">Product Name *</label>
            <input class="control" type="text" name="name" required value="<?php echo h($stock['name']); ?>">
          </div>
          <div class="field">
            <label class="label">SKU (optional)</label>
            <input class="control" type="text" name="sku" value="<?php echo h($stock['sku'] ?? ''); ?>">
            <div class="hint">Leave blank to keep empty. No auto-generation.</div>
          </div>
        </div>

        <div class="field">
          <label class="label">Description</label>
          <textarea class="control" name="description"><?php echo h($stock['description'] ?? ''); ?></textarea>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Category</label>
            <select class="control" name="category">
              <?php
              $sel = $stock['category'] ?? 'General';
              foreach ($CATEGORY_OPTIONS as $opt) {
                $s = ($opt === $sel) ? 'selected' : '';
                echo '<option ' . $s . ' value="' . h($opt) . '">' . h($opt) . '</option>';
              }
              ?>
            </select>
          </div>
          <div class="field">
            <label class="label">Store (optional)</label>
            <input class="control" type="text" name="store_id" value="<?php echo h($stock['store_id'] ?? ''); ?>">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Quantity</label>
            <input class="control" type="number" min="0" step="1" name="quantity" value="<?php echo h((string)$stock['quantity']); ?>">
          </div>
          <div class="field">
            <label class="label">Reorder level</label>
            <input class="control" type="number" min="0" step="1" name="reorder_level" value="<?php echo h((string)$stock['reorder_level']); ?>">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Unit price</label>
            <input class="control" type="number" min="0" step="0.01" name="price" value="<?php echo h(number_format((float)$stock['price'], 2, '.', '')); ?>">
          </div>
          <div class="field">
            <label class="label">Expiry date</label>
            <input class="control" type="date" name="expiry_date" value="<?php
                                                                          $d = $stock['expiry_date'] ?? '';
                                                                          // keep YYYY-MM-DD if already that; else try to parse
                                                                          if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                                                                            echo h($d);
                                                                          } elseif ($d) {
                                                                            echo h(date('Y-m-d', strtotime($d)));
                                                                          }
                                                                          ?>">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Unit (optional)</label>
            <input class="control" type="text" name="unit" value="<?php echo h($stock['unit'] ?? ''); ?>">
          </div>
          <div class="field">
            <label class="label">Location (optional)</label>
            <input class="control" type="text" name="location" value="<?php echo h($stock['location'] ?? ''); ?>">
          </div>
        </div>

        <div class="field">
          <label class="label">Barcode (optional)</label>
          <input class="control" type="text" name="barcode" value="<?php echo h($stock['barcode'] ?? ''); ?>">
        </div>

        <div class="toolbar">
          <a href="view.php?id=<?php echo h($docId); ?>" class="btn btn-outline">Cancel</a>
          <button class="btn btn-primary" type="submit">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
</div>
</div>

  <script>
    // Avoid scroll changing numeric inputs
    document.querySelectorAll('input[type="number"]').forEach(i => {
      i.addEventListener('wheel', () => i.blur(), {
        passive: true
      });
    });
  </script>
</body>

</html>