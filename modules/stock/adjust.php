<?php
require_once '../../config.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';
require_once '../../sql_db.php';
require_once '../../firebase_config.php';
require_once '../../firebase_rest_client.php';
require_once '../../getDB.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_restock_inventory')) {
    $_SESSION['error'] = 'You do not have permission to adjust stock manually';
    header('Location: list.php');
    exit;
}

$db = getDB();
$docId = $_GET['id'] ?? '';

function fs_resolve_low_stock_if_recovered(
  $db,
  string $productId,
  ?int $qtyOverride = null,        // <-- pass new qty here to avoid stale reads
  ?int $minOverride = null,        // <-- pass min level you already know
  ?string $resolvedBy = null
): void {
  if ($productId === '') return;

  // 1) Determine latest qty and min
  $qty = null;
  $min = null;

  if ($qtyOverride !== null) {
    $qty = (int)$qtyOverride;
  }
  if ($minOverride !== null) {
    $min = (int)$minOverride;
  }

  // If we still need values, read once from DB
  if ($qty === null || $min === null) {
    $p = null;
    if (method_exists($db, 'readDoc')) {
      $p = $db->readDoc('products', $productId);
    } elseif (method_exists($db, 'read')) {
      $p = $db->read('products', $productId);
    }
    if (!$p) return;

    if ($qty === null) $qty = (int)($p['quantity'] ?? 0);
    if ($min === null) {
      $min = isset($p['min_stock_level']) ? (int)$p['min_stock_level']
        : (isset($p['reorder_level']) ? (int)$p['reorder_level'] : 0);
    }
  }

  // 2) Only resolve if strictly recovered (qty > min).
  // NOTE: qty == min remains low (PENDING).
  $recovered = ($min > 0) ? ($qty > $min) : ($qty > 0);
  if (!$recovered) return;

  // 3) Resolve the alert doc
  $docId  = 'LOW_' . $productId;
  $nowIso = date('c');
  $payload = [
    'status'      => 'RESOLVED',
    'resolved_at' => $nowIso,
    'updated_at'  => $nowIso,
  ];
  if (!empty($resolvedBy)) {
    $payload['resolved_by'] = $resolvedBy;
    $payload['resolution_note'] = 'User adjusted quantity';
  }

  if (method_exists($db, 'update'))        $db->update('alerts', $docId, $payload);
  elseif (method_exists($db, 'writeDoc'))  $db->writeDoc('alerts', $docId, $payload);
  elseif (method_exists($db, 'setDoc'))    $db->setDoc('alerts', $docId, array_merge(['alert_type' => 'LOW_STOCK'], $payload));
  elseif (method_exists($db, 'write'))     $db->write('alerts', $docId, $payload);
}


// Fetch product
$product = null;

// 1. Try SQL Loading (Primary)
if ($docId !== '') {
    try {
        $sqlDb = SQLDatabase::getInstance();
        // If docId looks like an integer, try ID lookup
        if (ctype_digit($docId)) {
            $row = $sqlDb->fetch("SELECT * FROM products WHERE id = ?", [$docId]);
            if ($row) {
                $product = $row;
                $product['doc_id'] = (string)$row['id']; // Map ID to doc_id
                $product['quantity'] = (int)$product['quantity']; // Ensure int
            }
        }
    } catch (Exception $e) {
        // SQL failed, ignore
    }
}

// 2. Fallback to Firestore
if (!$product) $product = fs_get_product_by_doc($docId);

if (!$product) die('Product not found');

// Handle AJAX update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');

  // 1) Parse & validate
  $raw = $_POST['quantity'] ?? null;
  if ($raw === null || $raw === '') {
    echo json_encode(['success' => false, 'error' => 'Missing quantity.']);
    exit;
  }

  // allow only integers (cast is fine for your UI; tighten if you accept decimals)
  if (!is_numeric($raw)) {
    echo json_encode(['success' => false, 'error' => 'Quantity must be a number.']);
    exit;
  }

  $newQty = (int)$raw;
  $oldQty = (int)($product['quantity'] ?? 0);

  if ($newQty < 0) {
    echo json_encode(['success' => false, 'error' => 'Quantity cannot be negative.']);
    exit;
  }

  if ($newQty === $oldQty) {
    echo json_encode(['success' => false, 'error' => 'Quantity is unchanged.']);
    exit;
  }

  // 2) Persist to both Firebase AND SQL
  $ok = false;
  $err = null;
  
  // Update SQL (Primary)
  try {
      $sqlDb = SQLDatabase::getInstance();
      if (ctype_digit((string)$docId)) {
          $sqlDb->execute("UPDATE products SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$newQty, $docId]);
          $ok = true;
      }
  } catch (Exception $e) {
      error_log("SQL Update failed in adjust.php: " . $e->getMessage());
      // If SQL fails, we might still want to try Firestore if it's a Firestore-only product
  }

  // Update Firestore (Secondary/Sync)
  if ($db && method_exists($db, 'update')) {
    try {
      $db->update('products', $docId, [
        'quantity'   => $newQty,
        'updated_at' => date('c'),
      ]);
      if (!$ok) $ok = true; // If SQL failed but Firestore worked, consider it a success (or partial)
    } catch (Throwable $t) {
      $err = $t->getMessage();
      if (!$ok) $ok = false; // If both failed
    }
  } else {
     if (!$ok) $err = 'Database update not available.';
  }
  
  // Clear caches
  if ($ok) {
    try {
        // Clear POS cache
        $posCacheFile = __DIR__ . '/../../storage/cache/pos_products.cache';
        if (file_exists($posCacheFile)) @unlink($posCacheFile);
        
        // Clear List cache
        $listCacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
        if (file_exists($listCacheFile)) @unlink($listCacheFile);
        
    } catch (Throwable $sqlErr) {
      // ignore cache clear errors
    }
  }

  // 3) Audit (only if update succeeded)
  if ($ok) {
    try {
      $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id']   ?? null);
      $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

      // Log activity
      // logActivity('stock_adjusted', "Adjusted stock for {$product['name']}", [
      //     'product_id' => $docId,
      //     'product_name' => $product['name'] ?? null,
      //     'sku' => $product['sku'] ?? null,
      //     'old_quantity' => $oldQty,
      //     'new_quantity' => $newQty
      // ]);

      log_stock_audit([
        'action'         => 'adjust_quantity',
        'product_id'     => (string)$docId,
        'sku'            => $product['sku'] ?? null,
        'product_name'   => $product['name'] ?? null,
        'store_id'       => $product['store_id'] ?? null,

        'before'         => ['quantity' => $oldQty],
        'after'          => ['quantity' => $newQty],

        // keep the generic keys you already had...
        'user_id'        => $changedBy,
        'username'       => $changedName,
        // ...and ALSO pass the exact keys your history page shows:
        'changed_by'     => $changedBy,
        'changed_name'   => $changedName,
      ]);
    } catch (Throwable $t) {
      error_log('adjust.php audit failed: ' . $t->getMessage());
    }

    $minNow = isset($product['min_stock_level']) ? (int)$product['min_stock_level']
      : (isset($product['reorder_level']) ? (int)$product['reorder_level'] : 0);

    $who = $_SESSION['username']
      ?? $_SESSION['email']
      ?? $_SESSION['user_id']
      ?? $_SESSION['uid']
      ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? 'admin');

    // pass the new qty and current min level so we don't rely on a fresh read
    fs_resolve_low_stock_if_recovered($db, (string)$docId, (int)$newQty, (int)$minNow, (string)$who);
  }

  // 4) Respond
  if ($ok) {
    // Clear cache when stock is updated
    $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
    
    echo json_encode([
      'success' => true,
      'old'     => $oldQty,
      'new'     => $newQty,
      'delta'   => $newQty - $oldQty,
    ]);
  } else {
    echo json_encode(['success' => false, 'error' => $err ?? 'Update failed.']);
  }
  exit;
}

$adjustProductId = $_GET['id'] ?? '';
$returnRaw = $_GET['return'] ?? '';  // encoded list.php URL from previous page

// Build safe redirect after successful update - add refresh parameter
$redirectAfterSave = 'list.php?refresh=1';
if ($returnRaw !== '') {
  $decoded = rawurldecode($returnRaw);
  if (preg_match('#(^|/)list\.php(\?|$)#', $decoded)) {
    // Add refresh parameter if not already present
    $redirectAfterSave = $decoded;
    if (strpos($redirectAfterSave, '?') !== false) {
        $redirectAfterSave .= '&refresh=1';
    } else {
        $redirectAfterSave .= '?refresh=1';
    }
  }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Adjust Stock - <?php echo htmlspecialchars($product['name']); ?></title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <style>
    body {
      font-family: system-ui, sans-serif;
      background: #f9fafb;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
    }

    .adjust-card {
      background: #fff;
      padding: 32px 40px;
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
      text-align: center;
      max-width: 400px;
      width: 100%;
    }

    .adjust-card h2 {
      font-size: 20px;
      margin-bottom: 16px;
      color: #111827;
    }

    .qty-control {
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 20px 0;
      gap: 15px;
    }

    .qty-control button {
      font-size: 24px;
      width: 48px;
      height: 48px;
      border-radius: 12px;
      border: none;
      cursor: pointer;
      background: #2563eb;
      color: white;
      transition: 0.2s;
    }

    .qty-control button:hover {
      background: #1e40af;
    }

    .qty-value {
      font-size: 28px;
      width: 90px;
      text-align: center;
      font-weight: bold;
      border: none;
      background: #f1f5f9;
      border-radius: 8px;
      padding: 8px 0;
    }

    .actions {
      margin-top: 24px;
    }

    .actions button {
      padding: 10px 18px;
      font-size: 15px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
    }

    .save-btn {
      background: #16a34a;
      color: white;
      margin-right: 10px;
    }

    .cancel-btn {
      background: #e5e7eb;
      color: #111827;
    }
  </style>
</head>

<body>

  <div class="adjust-card">
    <h2>Adjust Stock<br><small><?php echo htmlspecialchars($product['name']); ?></small></h2>

    <div class="qty-control">
      <button id="minus">−</button>
      <input type="text" id="quantity" class="qty-value" value="<?php echo (int)$product['quantity']; ?>">
      <button id="plus">+</button>
    </div>

    <div class="actions">
      <button class="cancel-btn" onclick="window.history.back()">Cancel</button>
      <button class="save-btn" id="saveBtn">Save</button>

    </div>
  </div>

  <script>
    const minus = document.getElementById('minus');
    const plus = document.getElementById('plus');
    const qtyInput = document.getElementById('quantity');
    const saveBtn = document.getElementById('saveBtn');

    function clampQuantity() {
      let val = parseInt(qtyInput.value || '0', 10);
      if (isNaN(val) || val < 0) val = 0;
      qtyInput.value = val;
    }

    minus.addEventListener('click', () => {
      clampQuantity();
      let val = parseInt(qtyInput.value, 10);
      if (val > 0) qtyInput.value = val - 1;
    });

    plus.addEventListener('click', () => {
      clampQuantity();
      qtyInput.value = parseInt(qtyInput.value, 10) + 1;
    });

    // Revalidate when user types manually
    qtyInput.addEventListener('input', clampQuantity);


    saveBtn.addEventListener('click', async () => {
      const quantity = parseInt(qtyInput.value || '0', 10);
      const res = await fetch(window.location.href, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'quantity=' + encodeURIComponent(quantity)
      });
      const data = await res.json();

      if (data.success) {
        alert('Stock updated successfully!');
        // Use PHP-echoed redirect target (preserves filters)
        window.location.href = "<?php echo htmlspecialchars($redirectAfterSave, ENT_QUOTES); ?>";
      } else {
        alert('Failed to update quantity.');
      }
    });
  </script>

</body>

</html>