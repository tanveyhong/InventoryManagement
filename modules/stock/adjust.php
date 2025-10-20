<?php
require_once '../../config.php';
require_once '../../functions.php';
require_once '../../firebase_config.php';
require_once '../../firebase_rest_client.php';
require_once '../../getDB.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$db = getDB();
$docId = $_GET['id'] ?? '';

// Fetch product
$product = fs_get_product_by_doc($docId);
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

  // 2) Persist
  $ok = false;
  $err = null;
  if ($db && method_exists($db, 'update')) {
    try {
      $ok = $db->update('products', $docId, [
        'quantity'   => $newQty,
        'updated_at' => date('c'),
      ]);
    } catch (Throwable $t) {
      $err = $t->getMessage();
      $ok = false;
    }
  } else {
    $err = 'Database update not available.';
  }

  // 3) Audit (only if update succeeded)
  if ($ok) {
    try {
      $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id']   ?? null);
      $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

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
  }

  // 4) Respond
  if ($ok) {
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
        window.location.href = 'list.php';
      } else {
        alert('Failed to update quantity.');
      }
    });
  </script>

</body>

</html>