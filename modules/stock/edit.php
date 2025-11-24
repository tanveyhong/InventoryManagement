<?php
// modules/stock/edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../activity_logger.php';
require_once __DIR__ . '/../../sql_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

function fs_resolve_low_stock_if_recovered(
  $db,
  string $productId,
  ?int $qtyOverride = null,
  ?int $minOverride = null,
  ?string $resolvedBy = null
): void {
  if ($productId === '') return;

  // Prefer overrides (fresh from POST) to avoid read-after-write lag
  $qty = $qtyOverride;
  $min = $minOverride;

  // If needed, fetch once
  if ($qty === null || $min === null) {
    $p = null;
    if (method_exists($db, 'readDoc'))      $p = $db->readDoc('products', $productId);
    elseif (method_exists($db, 'read'))     $p = $db->read('products', $productId);
    if (!$p) return;

    if ($qty === null) $qty = (int)($p['quantity'] ?? 0);
    if ($min === null) {
      $min = isset($p['min_stock_level']) ? (int)$p['min_stock_level']
        : (isset($p['reorder_level'])   ? (int)$p['reorder_level']   : 0);
    }
  }

  // Only RESOLVED when strictly above min (qty == min is still low)
  $recovered = ($min > 0) ? ($qty > $min) : ($qty > 0);
  if (!$recovered) return;

  $docId  = 'LOW_' . $productId;  // MUST match list.php creator
  $nowIso = date('c');
  $payload = [
    'status'      => 'RESOLVED',
    'resolved_at' => $nowIso,
    'updated_at'  => $nowIso,
  ];
  if ($resolvedBy) {
    $payload['resolved_by']     = $resolvedBy;
    $payload['resolution_note'] = 'User edited quantity';
  }

  if (method_exists($db, 'update'))        $db->update('alerts', $docId, $payload);
  elseif (method_exists($db, 'writeDoc'))  $db->writeDoc('alerts', $docId, $payload);
  elseif (method_exists($db, 'setDoc'))    $db->setDoc('alerts', $docId, array_merge(['alert_type' => 'LOW_STOCK'], $payload));
  elseif (method_exists($db, 'write'))     $db->write('alerts', $docId, $payload);
}

function fs_resolve_expiry_if_normal(
  $db,
  string $productId,
  ?string $expiryRaw = null,   // pass the new value if you have it
  ?string $who = null
): void {
  // Expiry logic removed
  return;
}

function fs_delete_expiry_alert_if_normal(
  $db,
  string $productId,
  ?string $newExpiryRaw = null
): void {
  // Expiry logic removed
  return;
}

// ----- load target product -----
$docId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$skuQ  = isset($_GET['sku']) ? norm_sku((string)$_GET['sku']) : '';

$stock = null;

// 1. Try SQL Loading (Primary)
if ($docId !== '') {
    try {
        $sqlDb = SQLDatabase::getInstance();
        // If docId looks like an integer, try ID lookup
        if (ctype_digit($docId)) {
            $row = $sqlDb->fetch("SELECT * FROM products WHERE id = ?", [$docId]);
            if ($row) {
                $stock = $row;
                $stock['doc_id'] = (string)$row['id']; // Map ID to doc_id
                // Ensure numeric types
                $stock['quantity'] = (int)$stock['quantity'];
                $stock['reorder_level'] = (int)$stock['reorder_level'];
                $stock['price'] = (float)$stock['price'];
                $stock['cost_price'] = (float)($stock['cost_price'] ?? 0.0);
            }
        }
    } catch (Exception $e) {
        // SQL failed, ignore
    }
}

// 2. Fallback to Firestore
if (!$stock && $docId !== '') $stock = fs_get_product_by_doc($docId);
if (!$stock && $skuQ !== '') $stock = fs_get_product($skuQ);

if (!$stock) {
  http_response_code(404);
  echo 'Product not found.';
  exit;
}
$docId = $stock['doc_id']; // ensure we have doc id for updates

$errors = [];
$notice = '';

// Ensure we have the same DB wrapper as add.php
if (!isset($db) || !$db) {
  $db = getDB();
}

$stores = [];
try {
  // identical to add.php
  $stores = $db->fetchAll("SELECT id, name FROM stores WHERE is_active = 1 ORDER BY name");
} catch (Throwable $t) {
  error_log('Load stores failed in edit.php: ' . $t->getMessage());
  $stores = []; // keep safe
}

// Fetch suppliers
$suppliers = [];
try {
    $sqlDb = SQLDatabase::getInstance();
    $suppliers = $sqlDb->fetchAll("SELECT id, name FROM suppliers WHERE active = TRUE ORDER BY name");
} catch (Exception $e) {
    error_log('Load suppliers failed in edit.php: ' . $e->getMessage());
}

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
  $costPrice     = (float)($_POST['cost_price'] ?? (float)($stock['cost_price'] ?? 0.0));
  $storeId       = trim((string)($_POST['store_id'] ?? ($stock['store_id'] ?? '')));
  $supplierId    = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
  $location      = trim((string)($_POST['location'] ?? ($stock['location'] ?? '')));
  $unit          = trim((string)($_POST['unit'] ?? ($stock['unit'] ?? '')));
  $barcode       = trim((string)($_POST['barcode'] ?? ($stock['barcode'] ?? '')));


  // Basic validation
  if ($name === '') $errors[] = 'Product name is required.';
  if ($quantity < 0) $errors[] = 'Quantity cannot be negative.';
  if ($reorderLevel < 0) $errors[] = 'Reorder level cannot be negative.';
  if ($price < 0) $errors[] = 'Price cannot be negative.';
  if ($costPrice < 0) $errors[] = 'Cost price cannot be negative.';

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
      'cost_price'    => $costPrice,
      'store_id' => ($storeId !== '' ? $storeId : null),
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
      'store_id'     => ($storeId !== '' ? $storeId : ($stock['store_id'] ?? null)),
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

    // --- Update SQL (Primary) ---
    $updateSuccess = false;
    try {
        $sqlDb = SQLDatabase::getInstance();
        if (ctype_digit((string)$docId)) {
            $sqlDb->execute("
                UPDATE products SET
                    name = ?,
                    sku = ?,
                    description = ?,
                    category = ?,
                    store_id = ?,
                    supplier_id = ?,
                    quantity = ?,
                    price = ?,
                    selling_price = ?,
                    cost_price = ?,
                    reorder_level = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [
                $name,
                $sku !== '' ? $sku : null,
                $description,
                $category,
                $storeId !== '' ? $storeId : null,
                $supplierId,
                $quantity,
                $price,
                $price, // selling_price
                $costPrice,
                $reorderLevel,
                $docId
            ]);
            $updateSuccess = true;
        }
    } catch (Exception $e) {
        error_log("SQL Update failed in edit.php: " . $e->getMessage());
        $errors[] = "Database error: " . $e->getMessage();
    }

    // --- Update Firebase (Secondary/Backup) ---
    if ($updateSuccess) {
        try {
            $db = db_obj();
            if ($db && method_exists($db, 'update')) {
                $db->update('products', $docId, $data);
            }
        } catch (Throwable $e) {
            error_log("Firebase Update failed: " . $e->getMessage());
            // Continue anyway, SQL is primary
        }

        // --- Log Activity ---
        try {
            // Calculate changes for all fields
            $allChanges = [];
            $fieldsToCheck = [
                'name' => 'Name',
                'sku' => 'SKU',
                'category' => 'Category',
                'quantity' => 'Quantity',
                'price' => 'Price',
                'cost_price' => 'Cost Price',
                'reorder_level' => 'Reorder Level',
                'store_id' => 'Store',
                'supplier_id' => 'Supplier'
            ];

            foreach ($fieldsToCheck as $field => $label) {
                $oldVal = $stock[$field] ?? null;
                // Handle numeric comparisons loosely
                if ($field === 'price' || $field === 'cost_price') {
                    $newVal = ${str_replace('_', '', lcfirst(ucwords($field, '_')))}; // $price, $costPrice
                    if (abs((float)$oldVal - (float)$newVal) > 0.001) {
                        $allChanges[$label] = ['from' => $oldVal, 'to' => $newVal];
                    }
                } elseif ($field === 'quantity' || $field === 'reorder_level') {
                    $newVal = ${lcfirst(str_replace('_', '', ucwords($field, '_')))}; // $quantity, $reorderLevel
                    if ((int)$oldVal !== (int)$newVal) {
                        $allChanges[$label] = ['from' => $oldVal, 'to' => $newVal];
                    }
                } else {
                    // String fields
                    $varName = lcfirst(str_replace('_', '', ucwords($field, '_'))); // $name, $sku, $category...
                    // Special cases for variable names
                    if ($field === 'store_id') $varName = 'storeId';
                    if ($field === 'supplier_id') $varName = 'supplierId';
                    
                    $newVal = $$varName ?? null;
                    if ((string)$oldVal !== (string)$newVal) {
                        $allChanges[$label] = ['from' => $oldVal, 'to' => $newVal];
                    }
                }
            }

            logActivity('product_updated', "Updated product: {$name}", [
                'product_id' => $docId,
                'product_name' => $name,
                'sku' => $sku,
                'changes' => $allChanges
            ]);
        } catch (Throwable $e) {
            error_log("Logging failed: " . $e->getMessage());
        }

        // === Resolve Alerts ===
        $who = $_SESSION['username'] ?? 'user';
        fs_resolve_low_stock_if_recovered($db, (string)$docId, (int)$quantity, (int)$reorderLevel, (string)$who);

        // Clear cache
        $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        $_SESSION['success'] = "Product updated successfully.";
        header('Location: list.php');
        exit;
    }
  }
}

// ---- UI ----
$CATEGORY_OPTIONS = $CATEGORY_OPTIONS ?? [
  'Skincare',
  'Haircare',
  'Body Care',
  'Cosmetics',
  'Fragrances',
  'Oral Care',
  'Personal Hygiene',
  'Baby Care',
  'Men\'s Grooming',
  'Health & Wellness',
  'General',
];

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$docId = $_GET['id'] ?? '';
$returnParam = isset($_GET['return']) ? rawurlencode($_GET['return']) : '';
$backToView = "view.php?id=" . rawurlencode($docId) . ($returnParam ? "&return={$returnParam}" : '');

$editProductId = isset($_GET['id']) ? (string)$_GET['id'] : '';
$returnRaw     = isset($_GET['return']) ? (string)$_GET['return'] : '';

$backToView = 'view.php?id=' . rawurlencode($editProductId);
if ($returnRaw !== '') {
  $backToView .= '&return=' . rawurlencode($returnRaw);
}

// Determine Cancel URL (prefer return param to go back to list, otherwise default to list.php)
$cancelUrl = 'list.php';
if ($returnRaw !== '') {
    $cancelUrl = $returnRaw;
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
  include '../../includes/dashboard_header.php';
  ?>
  <div class="shell">

    <div class="page-header">
      <h1>Edit Product</h1>
      <div><a href="<?php echo htmlspecialchars($backToView, ENT_QUOTES); ?>" class="btn btn-outline">← Back to Product</a></div>
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
            <label class="label">Supplier</label>
            <select class="control" name="supplier_id">
              <option value="">-- Select Supplier --</option>
              <?php
              $selSup = $stock['supplier_id'] ?? '';
              foreach ($suppliers as $supplier) {
                $s = ($supplier['id'] == $selSup) ? 'selected' : '';
                echo '<option ' . $s . ' value="' . h($supplier['id']) . '">' . h($supplier['name']) . '</option>';
              }
              ?>
            </select>
          </div>
          <div class="form-group">
            <label for="store_id" class="label">Store (Read-only)</label>
            <select id="store_id" name="store_id" class="control" disabled style="background-color: #f0f0f0; cursor: not-allowed;">
              <option value="">Main Stock (Warehouse)</option>
              <?php
              // POST value (after validation error) or the product’s current store_id
              $selectedStoreId = isset($_POST['store_id'])
                ? (string)$_POST['store_id']
                : (string)($stock['store_id'] ?? '');

              foreach ($stores as $store):
                $sid   = (string)$store['id'];   // SQL id is the Firestore doc id in your setup
                $sname = (string)$store['name'];
                if ($sid === '' || $sname === '') continue;
                $sel = ($sid === $selectedStoreId) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($sid) ?>" <?= $sel ?>>
                  <?= htmlspecialchars($sname) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Store assignment cannot be changed here.</div>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Quantity (Read-only)</label>
            <input class="control" type="number" name="quantity" value="<?php echo h((string)$stock['quantity']); ?>" readonly style="background-color: #f0f0f0; cursor: not-allowed;">
            <div class="hint">Use "Restock" or "Adjustments" to change quantity.</div>
          </div>
          <div class="field">
            <label class="label">Reorder level</label>
            <input class="control" type="number" min="0" step="1" name="reorder_level" value="<?php echo h((string)$stock['reorder_level']); ?>">
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <label class="label">Cost Price (Supplier)</label>
            <input class="control" type="number" min="0" step="0.01" name="cost_price" id="cost_price" value="<?php echo h(number_format((float)($stock['cost_price'] ?? 0), 2, '.', '')); ?>">
            <div class="hint">What you pay the supplier.</div>
          </div>
          <div class="field">
            <label class="label">Unit Price (Selling)</label>
            <input class="control" type="number" min="0" step="0.01" name="price" id="unit_price" value="<?php echo h(number_format((float)$stock['price'], 2, '.', '')); ?>">
            <div class="hint">Auto-calculated: Cost / 0.70 (30% margin)</div>
          </div>
        </div>

        <div class="grid grid-2">
          <div class="field">
            <!-- Expiry date removed -->
          </div>
        </div>

        <div class="toolbar">
          <a href="<?php echo htmlspecialchars($cancelUrl, ENT_QUOTES); ?>" class="btn btn-outline">Cancel</a>
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

    // Auto-calculate Selling Price based on Cost Price
    const costInput = document.getElementById('cost_price');
    const priceInput = document.getElementById('unit_price');

    if (costInput && priceInput) {
        costInput.addEventListener('input', function() {
            const cost = parseFloat(this.value);
            if (!isNaN(cost) && cost > 0) {
                // Formula: Selling Price = Cost / 0.70
                const sellingPrice = cost / 0.70;
                priceInput.value = sellingPrice.toFixed(2);
            }
        });
    }
  </script>
</body>

</html>