<?php
// modules/stock/delete.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../getDB.php';

function db_obj() { return function_exists('getDB') ? @getDB() : null; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Identify the product (prefer Firestore doc id via ?id=; allow ?sku= fallback)
$docId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$skuQ  = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';

$stock = null;
if ($docId !== '') $stock = fs_get_product_by_doc($docId);
if (!$stock && $skuQ !== '') $stock = fs_get_product($skuQ);

if (!$stock) { http_response_code(404); echo 'Product not found.'; exit; }
$docId = (string)$stock['doc_id'];   // normalize to Firestore doc id

// Build your two-field soft-delete flags
$softFlags = [
  'status'     => 'disabled',
  'deleted_at' => date('c'),
];

// Get DB wrapper
$db = db_obj();
if (!$db) { http_response_code(500); echo 'Database unavailable.'; exit; }

// --- Preferred path: if your wrapper supports MERGE/PATCH semantics ---
if (method_exists($db, 'updateMerge')) {
  // updateMerge(collection, docId, fieldsToMerge)
  try {
    $db->updateMerge('products', $docId, $softFlags);
    // --- audit: delete_product (no qty fields) ---
try {
  $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id'] ?? null);
  $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

  log_stock_audit([
    'action'         => 'delete_product',
    'product_id'     => (string)$docId,
    'sku'            => $stock['sku'] ?? null,
    'product_name'   => $stock['name'] ?? null,
    'store_id'       => $stock['store_id'] ?? null,

    // no 'before'/'after' so qty renders as "–"
    'user_id'        => $changedBy,
    'username'       => $changedName,
    'changed_by'     => $changedBy,
    'changed_name'   => $changedName,
  ]);
} catch (Throwable $t) {
  error_log('delete audit failed: ' . $t->getMessage());
}

    header('Location: list.php?deleted=1'); exit;
  } catch (\Throwable $e) {
    error_log('Soft delete (merge) failed: '.$e->getMessage());
    http_response_code(500); echo 'Failed to soft delete product.'; exit;
  }
}

// --- Fallback path: read → merge → full update (preserves all fields) ---
try {
  // Re-read full document to ensure latest state
  $current = fs_get_product_by_doc($docId);
  if (!$current) { http_response_code(404); echo 'Product not found (reload).'; exit; }

  // Convert normalized row back to a plain data array for write.
  // (Remove helper-only keys like 'doc_id' and 'id' if those are not stored as fields.)
  $payload = $current;
  unset($payload['doc_id']); // Firestore doc id is path, not a field
  // If your schema does not store internal 'id', also unset it:
  // unset($payload['id']);

  // Merge the two soft-delete flags
  $payload['status']     = 'disabled';
  $payload['deleted_at'] = date('c');

  // Keep timestamps sane (don’t touch created_at; do refresh updated_at if you track it)
  if (!empty($current['created_at'])) {
    $payload['created_at'] = $current['created_at'];
  }
  $payload['updated_at'] = date('c');

if (method_exists($db, 'update')) {
  $db->update('products', $docId, $payload);
} elseif (method_exists($db, 'write')) {
  $db->write('products', $docId, $payload);
} else {
  throw new \RuntimeException('No suitable write method (update/write) found.');
}

// --- audit: delete_product (no qty fields) ---
try {
  $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id'] ?? null);
  $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

  log_stock_audit([
    'action'         => 'delete_product',
    'product_id'     => (string)$docId,
    'sku'            => $current['sku'] ?? null,
    'product_name'   => $current['name'] ?? null,
    'store_id'       => $current['store_id'] ?? null,

    // no qty fields
    'user_id'        => $changedBy,
    'username'       => $changedName,
    'changed_by'     => $changedBy,
    'changed_name'   => $changedName,
  ]);
} catch (Throwable $t) {
  error_log('delete audit failed: ' . $t->getMessage());
}

  header('Location: list.php?deleted=1'); exit;
} catch (\Throwable $e) {
  error_log('Soft delete (full update) failed: '.$e->getMessage());
  http_response_code(500); echo 'Failed to soft delete product.'; exit;
}

