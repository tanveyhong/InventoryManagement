<?php
/**
 * modules/alerts/low_stock.php
 * Usage: include via <div id="lowStockHost"></div> then fetch('?id=DOC_ID') and inject.
 * Shows a modal only if the product is in LOW STOCK (qty <= reorder_level and qty > 0).
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

header('Content-Type: text/html; charset=UTF-8');

// Read key
$key = '';
if (!empty($_GET['id']))  $key = trim((string)$_GET['id']);
if (!empty($_GET['sku'])) $key = trim((string)$_GET['sku']) ?: $key;

if ($key === '') { echo '<!-- low_stock.php: missing id/sku -->'; exit; }

$p = fs_get_product($key);
if (!$p) { echo '<!-- low_stock.php: product not found -->'; exit; }

// Compute status
$qty = (int)($p['quantity'] ?? 0);
$min = (int)($p['reorder_level'] ?? 0);

$isLow = ($qty > 0 && $min > 0 && $qty <= $min);
if (!$isLow) { echo '<!-- low_stock.php: not low stock -->'; exit; }

// Basic values
$docId   = $p['doc_id'] ?? ($p['id'] ?? (string)$key);
$name    = $p['name'] ?? '(no name)';
$sku     = $p['sku'] ?? '—';
$created = $p['created_at'] ?? null;
$minLbl  = $min > 0 ? number_format($min) : '—';
$qtyLbl  = number_format($qty);

// LocalStorage dismiss key (don’t nag repeatedly same day)
$dismissKey = 'lowstock_dismiss_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$docId) . '_' . date('Ymd');
?>
<style>
  /* Overlay + modal container */
  .lsk-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }

  /* Modal box */
  .lsk-modal {
    width: 100%;
    max-width: 460px;              /* narrower than before */
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 30px 80px rgba(15,23,42,.35);
    border: 1px solid #e5e7eb;
    font-family: system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
    overflow: hidden;
  }

  /* Header with centered title */
  .lsk-head {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px 18px;
    border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(180deg,#fff 0,#f8fafc 100%);
  }

  .lsk-head .icon {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #f59e0b;
    font-size: 20px;
  }

  .lsk-head .title {
    text-align: center;
    font-size: 1.5rem;
    font-weight: 800;
    color: #b91c1c;
    line-height: 1.2;
  }

  .lsk-close {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #475569;
  }
  .lsk-close:hover { color: #0f172a; }

  /* Body */
  .lsk-body { padding: 18px; }
  .lsk-body p.lead { margin: 0 0 12px; color: #334155; }

  /* Info table */
  .lsk-table {
    width: 100%;
    border-collapse: collapse;
    border: 1px solid #e2e8f0;
  }
  .lsk-table th, .lsk-table td {
    padding: 10px 12px;
    border-top: 1px solid #e2e8f0;
  }
  .lsk-table th {
    width: 42%;
    background: #f8fafc;
    color: #0f172a;
    text-align: left;
    font-weight: 700;
  }
  .lsk-table tr:first-child th, .lsk-table tr:first-child td { border-top: none; }

  /* Alert box */
  .lsk-alert {
    margin-top: 14px;
    background: #fff7ed;
    border: 1px dashed #f59e0b;
    padding: 12px 14px;
    border-radius: 10px;
    color: #7c2d12;
    font-weight: 600;
    text-align: center;
  }

  /* Action buttons */
  .lsk-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
    padding: 14px 18px;
    background: #f8fafc;
    border-top: 1px solid #e5e7eb;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid transparent;
    cursor: pointer;
  }
  .btn-green { background:#22c55e;color:#fff; }
  .btn-green:hover { background:#16a34a; }
  .btn-blue { background:#1d4ed8;color:#fff; }
  .btn-blue:hover { background:#1e40af; }
  .btn-ghost { background:#e5e7eb;color:#334155; }
  .btn-ghost:hover { background:#d1d5db; }
  .btn i { font-size:14px; }

  @media (max-width: 420px) {
    .lsk-modal { max-width: 92vw; }
    .lsk-head .title { font-size: 1.35rem; }
  }
</style>

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
      <a class="btn btn-green" href="../stock/edit.php?id=<?= rawurlencode((string)$docId) ?>">
        <i class="fas fa-truck"></i> Reorder Now
      </a>
      <a class="btn btn-blue" href="../stock/adjust.php?id=<?= rawurlencode((string)$docId) ?>">
        <i class="fas fa-circle-plus"></i> Add Stock
      </a>
      <button class="btn btn-ghost" type="button" onclick="LSK_dismiss(this)">Dismiss</button>
    </div>
  </div>
</div>


<script>
(function(){
  // If dismissed earlier today, auto-close
  try{
    var overlay = document.currentScript.previousElementSibling;
    if(overlay && overlay.classList && overlay.classList.contains('lsk-overlay')){
      var key = overlay.getAttribute('data-dismiss-key');
      if(key && localStorage.getItem(key)==='1'){
        overlay.remove();
      }
    }
  }catch(e){}
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
