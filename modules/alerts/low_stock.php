<?php
/**
 * modules/alerts/low_stock.php
 * Shows a modal only if the product is in LOW STOCK (qty <= reorder_level and qty > 0).
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../sql_db.php';   // Supabase / SQL wrapper

header('Content-Type: text/html; charset=UTF-8');

// Read key
$key = '';
if (!empty($_GET['id']))  $key = trim((string)$_GET['id']);
if (!empty($_GET['sku'])) $key = trim((string)$_GET['sku']) ?: $key;

if ($key === '') { echo '<!-- low_stock.php v2: missing id/sku -->'; exit; }

// ---------- Load product from Supabase (SQL) ----------
$product = null;
try {
    $sqlDb = SQLDatabase::getInstance();

    if (ctype_digit($key)) {
        // numeric => id
        $product = $sqlDb->fetch(
            "SELECT * FROM products 
             WHERE id = ? 
               AND active = TRUE 
               AND deleted_at IS NULL",
            [$key]
        );
    } else {
        // otherwise treat as SKU (case-insensitive)
        $sku = strtoupper($key);
        $product = $sqlDb->fetch(
            "SELECT * FROM products 
             WHERE UPPER(sku) = ? 
               AND active = TRUE 
               AND deleted_at IS NULL
             LIMIT 1",
            [$sku]
        );
    }
} catch (Throwable $e) {
    error_log('low_stock.php SQL load failed: ' . $e->getMessage());
}

if (!$product) { echo '<!-- low_stock.php v2: product not found -->'; exit; }

// Compute status
$qty = (int)($product['quantity'] ?? 0);
$min = (int)($product['reorder_level'] ?? 0);

$isLow = ($qty > 0 && $min > 0 && $qty <= $min);
if (!$isLow) { echo '<!-- low_stock.php v2: not low stock -->'; exit; }

// Basic values
$docId      = (string)($product['id'] ?? $key);
$name       = $product['name'] ?? '(no name)';
$sku        = $product['sku'] ?? '—';
$created    = $product['created_at'] ?? null;
$minLbl     = $min > 0 ? number_format($min) : '—';
$qtyLbl     = number_format($qty);
$supplierId = $product['supplier_id'] ?? null;

// LocalStorage dismiss key (don’t nag repeatedly same day)
$dismissKey = 'lowstock_dismiss_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$docId) . '_' . date('Ymd');
?>

<!-- DEBUG: low_stock.php v2 -->
<div class="lsk-overlay" role="dialog" aria-modal="true" aria-label="Low Stock Alert" data-dismiss-key="<?= htmlspecialchars($dismissKey) ?>">
  <div class="lsk-modal">
    <div class="lsk-head">
      <i class="icon fas fa-triangle-exclamation" aria-hidden="true"></i>
      <div class="title">Low Stock Alert</div>
      <button class="lsk-close" type="button" aria-label="Close" onclick="LSK_close(this)">×</button>
    </div>

    <div class="lsk-body">
      <p class="lead">The following item has fallen below the minimum stock level:</p>
      <table class="lsk-table">
        <tr><th>Product ID</th><td><?= htmlspecialchars((string)$docId) ?></td></tr>
        <tr><th>Product Name</th><td><?= htmlspecialchars($name) ?></td></tr>
        <tr><th>SKU</th><td><?= htmlspecialchars($sku) ?></td></tr>
        <tr><th>Current Quantity</th><td><?= $qtyLbl ?></td></tr>
        <tr><th>Minimum Stock Level</th><td><?= $minLbl ?></td></tr>
        <?php if (!empty($created)): ?>
          <tr><th>Created</th><td><?= htmlspecialchars(date('M j, Y H:i', strtotime($created))) ?></td></tr>
        <?php endif; ?>
      </table>

      <div class="lsk-alert">
        <div>Recommended Action:</div>
        <div>Reorder or add stock immediately to avoid stockouts.</div>
      </div>
    </div>

    <div class="lsk-actions">
      <?php if (!empty($supplierId)): ?>
        <a class="btn btn-green"
           href="../purchase_orders/create.php?supplier_id=<?= rawurlencode((string)$supplierId) ?>&product_id=<?= rawurlencode((string)$docId) ?>"
           style="text-align:center; padding:10px;">
          <i class="fas fa-truck"></i> Restock from Supplier
        </a>
      <?php endif; ?>

      <button class="btn btn-ghost" type="button" onclick="LSK_dismiss(this)">
        Dismissss
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  // If dismissed earlier today, auto-close the matching overlay(s)
  try {
    var overlays = document.querySelectorAll('.lsk-overlay');
    overlays.forEach(function (overlay) {
      var key = overlay.getAttribute('data-dismiss-key');
      if (key && localStorage.getItem(key) === '1') {
        overlay.remove();
      }
    });
  } catch (e) {}
})();

function LSK_close(btn){
  var root = btn.closest('.lsk-overlay'); if(root) root.remove();
}

function LSK_dismiss(btn){
  var root = btn.closest('.lsk-overlay');
  if(root){
    var key = root.getAttribute('data-dismiss-key');
    try{ if(key) localStorage.setItem(key,'1'); }catch(e){}
    root.remove();
  }
}

// Close on backdrop click or ESC
document.addEventListener('click', function(e){
  var ov = document.querySelector('.lsk-overlay');
  if(!ov) return;
  if(e.target === ov) ov.remove();
});
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){
    var ov = document.querySelector('.lsk-overlay');
    if(ov) ov.remove();
  }
});
</script>
