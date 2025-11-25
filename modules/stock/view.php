<?php

/**
 * modules/stock/view.php — product detail from Firestore "products"
 * Enhanced with modern design elements
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../sql_db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Accept id (doc id) or sku
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '') {
  http_response_code(400);
  exit('Missing product ID');
}

// Fetch by Firestore document name
$stock = null;

// 1. Try SQL Loading (Primary)
if ($id !== '') {
    try {
        $sqlDb = SQLDatabase::getInstance();

        if (ctype_digit($id)) {
            $row = $sqlDb->fetch("
                SELECT p.*, s.name AS store_name
                FROM products p
                LEFT JOIN stores s ON p.store_id = s.id
                WHERE p.id = ?
            ", [$id]);

            if ($row) {
                $stock = $row;
                $stock['doc_id'] = (string)$row['id'];

                $stock['quantity'] = (int)$stock['quantity'];
                $stock['reorder_level'] = (int)$stock['reorder_level'];
                $stock['price'] = (float)$stock['price'];

                // store name directly available now
                $storeName = $stock['store_name'] ?? '—';
            }
        }
    } catch (Exception $e) {
        // SQL failed, ignore
    }
}


// 2. Fallback to Firestore
if (!$stock) $stock = fs_get_product_by_doc($id);

if (!$stock) {
  http_response_code(404);
  exit('Stock not found');
}

// ---- Resolve store name (SQL first, Firestore fallback) --------------------
// ---- Resolve store name (SQL only, same source as add.php) ----
$storeName = $stock['store_name'] ?? '—';


if ($storeId !== '') {
  try {
    $db = getDB(); // same as add.php uses

    // Try a direct fetch() first (if supported)
    $row = null;
    if ($db && method_exists($db, 'fetch')) {
      $row = $db->fetch("SELECT name FROM stores WHERE id = ?", [$storeId]);
    }

    // Fallback: some wrappers only have fetchAll(), like add.php uses
    if ((!$row || empty($row['name'])) && $db && method_exists($db, 'fetchAll')) {
      $rows = $db->fetchAll("SELECT name FROM stores WHERE id = ?", [$storeId]);
      $row  = $rows[0] ?? null;
    }

    if ($row && !empty($row['name'])) {
      $storeName = (string)$row['name'];
    }
  } catch (Throwable $t) {
    error_log('Store name lookup failed: ' . $t->getMessage());
  }
}



// Derived/status
$qty        = (int)$stock['quantity'];
$reorder    = (int)$stock['reorder_level'];
$unit       = $stock['unit'] ?? '';
$price      = (float)$stock['price'];
$totalValue = $qty * $price;
$status     = $qty <= 0 ? 'Out of stock' : ($qty <= max(0, $reorder) ? 'Low stock' : 'In stock');
$statusType = $qty <= 0 ? 'alert' : ($qty <= max(0, $reorder) ? 'warning' : 'success');

// Header vars for shared header
$header_title        = $stock['name'] ?? 'Untitled Product';
$header_subtitle     = 'SKU: ' . ($stock['sku'] ?: '—') . ' • Category: ' . ($stock['category'] ?: 'General');
$header_icon         = 'fas fa-box-open';
$show_compact_toggle = false;
$header_stats = [
  ['value' => number_format($qty) . ($unit ? " {$unit}" : ''), 'label' => 'Quantity'],
  ['value' => 'RM ' . number_format($price, 2),               'label' => 'Unit Price'],
  ['value' => 'RM ' . number_format($totalValue, 2),          'label' => 'Total Value'],
  ['value' => $reorder > 0 ? number_format($reorder) : '—',   'label' => 'Reorder Level'],
];

$imageUrl   = $stock['image_url'] ?? '';
$barcode    = $stock['barcode']   ?? '';
$notes      = $stock['description'] ?? '';
$location   = $stock['location']  ?? '—';
$supplier   = $stock['supplier']  ?? '—';
$created    = $stock['created_at']  ?: '—';
$updated    = $stock['updated_at']  ?: '—';

// Format times for display (human friendly)
function _fmt_date($ts)
{
  if (!$ts) return '—';
  try {
    $d = new DateTime($ts);
    return $d->format('Y-m-d H:i');
  } catch (Exception $e) {
    return $ts;
  }
}

$created_display = _fmt_date($created);
$updated_display = _fmt_date($updated);

$backToList = 'list.php'; // fallback
if (!empty($_GET['return'])) {
  $candidate = rawurldecode($_GET['return']);
  // Safety: allow only local list page
  // (adjust path if your list is in a folder)
  if (preg_match('#(^|/)list\.php(\?|$)#', $candidate)) {
    $backToList = $candidate;
  }
}

$viewProductId = isset($_GET['id']) ? (string)$_GET['id'] : '';
$returnRaw     = isset($_GET['return']) ? (string)$_GET['return'] : '';

// Build the Edit URL once, then escape it once
$editHref = 'edit.php?id=' . rawurlencode($viewProductId);
if ($returnRaw !== '') {
  $editHref .= '&return=' . rawurlencode($returnRaw);
}
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

  <style>
    /* Enhanced Design Styles */
    .product-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 2rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
    }

    .product-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100%" height="100%" fill="url(%23grain)"/></svg>');
      opacity: 0.3;
    }

    .product-header-content {
      position: relative;
      z-index: 1;
    }

    /* Container to stack both badges vertically */
    .status-container {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      /* align to right corner same as before */
      gap: 6px;
      /* spacing between In Stock and Expiring Soon */
      position: absolute;
      top: 20px;
      right: 30px;
    }

    /* Existing badge style stays the same */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem 1.25rem;
      border-radius: 50px;
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      backdrop-filter: blur(10px);
    }

    .status-badge.success {
      background: rgba(34, 197, 94, 0.9);
      color: white;
    }

    .status-badge.warning {
      background: rgba(245, 158, 11, 0.9);
      color: white;
    }

    .status-badge.alert {
      background: rgba(239, 68, 68, 0.9);
      color: white;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1.5rem;
      margin-top: 2rem;
    }

    .stat-card {
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 120px;
      padding: 1.5rem;
      text-align: center;
      transition: all 0.3s ease;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .stat-card .icon {
      font-size: 2rem;
      margin-bottom: 0.5rem;
      opacity: 0.8;
    }

    .stat-card .value {
      font-size: 1.75rem;
      font-weight: 700;
      margin-bottom: 0.25rem;
      color: #1e293b;
    }

    .stat-card .label {
      font-size: 0.875rem;
      opacity: 0.9;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #1e293b;
    }

    .product-image-container {
      position: relative;
      border-radius: 16px;
      overflow: hidden;
      background: linear-gradient(45deg, #f3f4f6, #e5e7eb);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }

    .product-image {
      width: 100%;
      height: 300px;
      object-fit: cover;
      transition: transform 0.3s ease;
    }

    .product-image:hover {
      transform: scale(1.05);
    }

    .no-image {
      width: 100%;
      height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(45deg, #f8fafc, #f1f5f9);
      color: #64748b;
    }

    .details-card {
      background: white;
      border-radius: 16px;
      padding: 2rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .details-grid {
      display: grid;
      gap: 1.0rem;
      margin-top: 1.5rem;
      max-width: 600px;
      /* limits overall width */
      margin-right: auto;
      /* centers the grid */
    }

    .detail-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.9rem 1.2rem;
      background: #f8fafc;
      border-radius: 10px;
      border-left: 4px solid #cbd5e1;
      /* softer left accent */
      transition: all 0.25s ease;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    }

    /* Hover for subtle interaction */
    .detail-item:hover {
      background: #f1f5f9;
      transform: translateY(-2px);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
    }

    .detail-label {
      font-weight: 600;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.85rem;
    }

    .detail-value {
      color: #1e293b;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .description-card {
      background: white;
      border-radius: 16px;
      padding: 2rem;
      margin-top: 2rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .action-buttons {
      display: flex;
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .btn-enhanced {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.875rem 1.5rem;
      border-radius: 10px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
      font-size: 0.9rem;
    }

    .btn-primary {
      background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      color: white;
      box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    }

    .btn-secondary {
      background: white;
      color: #6b7280;
      border: 2px solid #e5e7eb;
    }

    .btn-secondary:hover {
      background: #f9fafb;
      border-color: #d1d5db;
      transform: translateY(-1px);
    }

    .breadcrumb {
      background: white;
      padding: 1rem 1.5rem;
      border-radius: 10px;
      margin-bottom: 2rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .breadcrumb a {
      color: #3b82f6;
      text-decoration: none;
      font-weight: 500;
    }

    .breadcrumb a:hover {
      color: #1d4ed8;
    }

    .breadcrumb .sep {
      margin: 0 0.75rem;
      color: #9ca3af;
    }

    .breadcrumb .current {
      color: #6b7280;
      font-weight: 600;
    }

    .barcode-display {
      background: linear-gradient(45deg, #f8fafc, #f1f5f9);
      border: 2px dashed #cbd5e1;
      border-radius: 8px;
      padding: 1rem;
      margin-top: 1rem;
      text-align: center;
      font-family: 'Courier New', monospace;
      font-size: 1.1rem;
      font-weight: 600;
      color: #475569;
    }

    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
      }

      .stat-card {
        padding: 1rem;
      }

      .stat-card .value {
        font-size: 1.25rem;
      }

      .action-buttons {
        flex-direction: column;
      }

      .product-header {
        padding: 1.5rem;
      }
    }

  </style>
</head>

<body>

  <?php require_once __DIR__ . '/../../includes/dashboard_header.php'; ?>

  <div class="container">
    <main>

      <nav class="breadcrumb">
        <a href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
        <span class="sep">/</span>
        <a href="./list.php"><i class="fas fa-boxes"></i> Stock</a>
        <span class="sep">/</span>
        <span class="current"><?php echo htmlspecialchars($header_title); ?></span>
      </nav>

      <div class="action-buttons">
        <?php
        $id = $_GET['id'] ?? '';
        $returnParam = isset($_GET['return']) ? '&return=' . rawurlencode($_GET['return']) : '';
        ?>
        <a class="btn-enhanced btn-primary" href="<?php echo htmlspecialchars($editHref, ENT_QUOTES); ?>">
          <i class="fas fa-edit"></i> Edit Product
        </a>
        <a class="btn-enhanced btn-secondary" href="<?php echo htmlspecialchars($backToList, ENT_QUOTES); ?>">
          <i class="fas fa-arrow-left"></i> Back to List
        </a>
      </div>

      <!-- Product Header with Status -->
      <div class="product-header">
        <div class="product-header-content">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
            <div>
              <h1 style="margin: 0 0 0.5rem 0; font-size: 2.5rem; font-weight: 700;">
                <?php echo htmlspecialchars($header_title); ?>
              </h1>
              <p style="margin: 0; font-size: 1.1rem; opacity: 0.9;">
                <?php echo htmlspecialchars($header_subtitle); ?>
              </p>
            </div>
            <div class="status-container">
              <div class="status-badge <?php echo $statusType; ?>">
                <i class="fas fa-circle"></i>
                <?php echo htmlspecialchars($status); ?>
              </div>
            </div>


          </div>

          <div class="stats-grid">
            <?php foreach ($header_stats as $s): ?>
              <div class="stat-card">
                <div class="value"><?php echo htmlspecialchars($s['value']); ?></div>
                <div class="label"><?php echo htmlspecialchars($s['label']); ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="margin-top: 1.5rem; font-size: 0.9rem; opacity: 0.8;">
            <i class="fas fa-clock"></i> Created: <?php echo htmlspecialchars($created_display); ?> •
            <i class="fas fa-sync-alt"></i> Updated: <?php echo htmlspecialchars($updated_display); ?>
          </div>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: 0.5fr 0.5fr; gap: 2rem; margin-bottom: 2rem;">

        <!-- Product Image -->
        <div class="details-card">
          <h3 style="margin: 0 0 1.5rem 0; font-size: 1.5rem; font-weight: 700; color: #1e293b;">
            <i class="fas fa-qrcode"></i> Product Barcode
          </h3>

          <?php if ($barcode): ?>
            <div class="barcode-display">
              <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($barcode); ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Product Details -->
        <div class="details-card">
          <h3 style="margin: 0 0 1.5rem 0; font-size: 1.5rem; font-weight: 700; color: #1e293b;">
            <i class="fas fa-info-circle"></i> Product Details
          </h3>
          <div class="details-grid">
            <div class="detail-item">
              <span class="detail-label">SKU</span>
              <span class="detail-value"><?php echo htmlspecialchars($stock['sku'] ?: '—'); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Category</span>
              <span class="detail-value"><?php echo htmlspecialchars($stock['category']); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Quantity</span>
              <span class="detail-value"><?php echo number_format($qty) . ($unit ? " {$unit}" : ''); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Reorder Level</span>
              <span class="detail-value"><?php echo $reorder > 0 ? number_format($reorder) : '—'; ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Unit Price</span>
              <span class="detail-value">RM <?php echo number_format($price, 2); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Total Value</span>
              <span class="detail-value">RM <?php echo number_format($totalValue, 2); ?></span>
            </div>
            <div class="detail-item">
              <span class="detail-label">Store</span>
              <span class="detail-value"><?= htmlspecialchars($storeName) ?></span>
            </div>
          </div>
        </div>

      </div>

      <!-- Description Section -->
      <?php if ($notes): ?>
        <div class="description-card">
          <h3 style="margin: 0 0 1.5rem 0; font-size: 1.5rem; font-weight: 700; color: #1e293b;">
            <i class="fas fa-file-alt"></i> Product Description
          </h3>
          <div style="background: #f8fafc; padding: 1.5rem; border-radius: 10px; border-left: 4px solid #3b82f6; font-size: 1rem; line-height: 1.6;">
            <?php echo nl2br(htmlspecialchars($notes)); ?>
          </div>
        </div>
      <?php endif; ?>

    </main>
  </div>

</body>

</html>