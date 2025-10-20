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

  // existing
  $desc_before = isset($stock['description'])
    ? trim(preg_replace('/\s+/', ' ', (string)$stock['description']))
    : null;

  // ✅ add this line:
  if ($desc_before === '') $desc_before = null;

  $desc_after = trim(preg_replace('/\s+/', ' ', (string)$description));
  if ($desc_after === '') $desc_after = null;

  $description = $desc_after; // save & audit use normalized value


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

    $before = [
      'quantity'       => (int)($stock['quantity'] ?? 0),
      'description'    => $desc_before,  // normalized before
      'reorder_level'  => isset($stock['reorder_level']) ? (int)$stock['reorder_level'] : null,
    ];

    $after = [
      'quantity'       => (int)$quantity,
      'description'    => $desc_after,   // normalized after
      'reorder_level'  => (int)$reorderLevel,
    ];

    $common = [
      'product_id'   => (string)$docId,
      'sku'          => $data['sku'] ?? ($stock['sku'] ?? null),
      'product_name' => $name,
      'store_id'     => $storeId ?: ($stock['store_id'] ?? null),
      'user_id'      => $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null,
      'username'     => $_SESSION['username'] ?? $_SESSION['email'] ?? null,
    ];

    // 1) Quantity changed?
    if ($before['quantity'] !== $after['quantity']) {
      log_stock_audit([
        'action' => 'adjust_quantity',
        'before' => ['quantity' => $before['quantity']],
        'after'  => ['quantity' => $after['quantity']],
      ] + $common);
    }

    // Description changed? (compare normalized values only)
    if ($desc_before !== $desc_after) {
      log_stock_audit([
        'action' => 'update_description',
        'before' => [
          'description' => $desc_before,
          // removed: 'quantity' => $before['quantity'],
        ],
        'after'  => [
          'description' => $desc_after,
          // removed: 'quantity' => $after['quantity'],
        ],
      ] + $common);
    }

    // 3) Min level changed?
    if (($before['reorder_level'] ?? null) !== ($after['reorder_level'] ?? null)) {
      log_stock_audit([
        'action' => 'update_min_level',
        'before' => [
          'reorder_level' => $before['reorder_level'],
          'quantity'      => $before['quantity'],
        ],
        'after'  => [
          'reorder_level' => $after['reorder_level'],
          'quantity'      => $after['quantity'],
        ],
      ] + $common);
    }




    $db = db_obj();
    if (!$db || !method_exists($db, 'update')) {
      $errors[] = 'Database update not available.';
    } else {
      try {
        $db->update('products', $docId, $data);

        // ✅ move the WHOLE audit block to here (just below the update)
        //    (the three if-blocks: adjust_quantity / update_description / update_min_level)

        // refresh local copy for re-render (optional if you redirect)
        $stock = fs_get_product_by_doc($docId);

        // Redirect
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

    .shell {
      max-width: 1000px;
      margin: 24px auto;
      padding: 0 18px
    }

    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px
    }

    .page-header h1 {
      margin: 0;
      font-size: 1.6rem;
      font-weight: 800;
      color: #0f172a
    }

    .card {
      background: #fff;
      border: 1px solid #e5eaf1;
      border-radius: 14px;
      box-shadow: 0 8px 30px rgba(2, 6, 23, .05)
    }

    .card .inner {
      padding: 18px
    }

    .grid {
      display: grid;
      gap: 14px
    }

    .grid-2 {
      grid-template-columns: 1fr 1fr
    }

    @media (max-width:860px) {
      .grid-2 {
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

    .hint {
      color: #64748b;
      font-size: .85rem
    }

    .control {
      border: 1px solid #dfe6f2;
      border-radius: 10px;
      padding: .75rem .9rem;
      background: #fff;
      color: #0f172a
    }

    .control:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .15)
    }

    textarea.control {
      min-height: 110px;
      resize: vertical
    }

    .toolbar {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 16px
    }

    .btn {
      border: none;
      border-radius: 10px;
      padding: .7rem 1.1rem;
      font-weight: 700;
      cursor: pointer
    }

    .btn-primary {
      background: #2563eb;
      color: #fff
    }

    .btn.btn-outline {
      background-color: #f1f5f9e4;
      /* light grey */
      color: #1e293b;
      /* dark text */
      border: 1px solid #d1d5db;
      font-weight: 600;
      padding: 0.6rem 1rem;
      border-radius: 8px;
      transition: all 0.25s ease;
    }

    /* Hover effect */
    .btn.btn-outline:hover {
      background-color: #e2e8f0;
      /* slightly darker grey */
      border-color: #cbd5e1;
      color: #111827;
      transform: translateY(-1px);
    }

    .alert {
      margin: 10px 0;
      padding: 10px 12px;
      border-radius: 10px
    }

    .alert-error {
      background: #fee2e2;
      color: #7f1d1d;
      border: 1px solid #fecaca
    }

    .alert-ok {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #d1fae5
    }
  </style>
</head>

<body>
  <?php
  include '../../includes/dashboard_header2.php';
  ?>
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