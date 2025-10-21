<?php
// modules/stock/delete.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../getDB.php';

function db_obj()
{
  return function_exists('getDB') ? @getDB() : null;
}
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fs_resolve_expiry_on_delete($db, string $productId, ?string $who = null): void
{
  if ($productId === '') return;

  $docId  = 'EXP_' . $productId;
  $nowIso = date('c');

  $payload = [
    'status'      => 'RESOLVED',
    'resolved_at' => $nowIso,
    'updated_at'  => $nowIso,
  ];
  if (!empty($who)) {
    $payload['resolved_by']     = $who;
    $payload['resolution_note'] = 'Product deleted';
  }

  // Upsert/update alert doc safely
  if (method_exists($db, 'update'))        $db->update('alerts', $docId, $payload);
  elseif (method_exists($db, 'writeDoc'))  $db->writeDoc('alerts', $docId, $payload);
  elseif (method_exists($db, 'setDoc'))    $db->setDoc('alerts', $docId, array_merge(['alert_type' => 'EXPIRY'], $payload));
  elseif (method_exists($db, 'write'))     $db->write('alerts', $docId, $payload);
}

function fs_resolve_low_on_delete($db, string $productId, ?string $who = null): void
{
  if ($productId === '') return;
  $docId  = 'LOW_' . $productId;
  $nowIso = date('c');
  $payload = [
    'status'      => 'RESOLVED',
    'resolved_at' => $nowIso,
    'updated_at'  => $nowIso,
  ];
  if (!empty($who)) {
    $payload['resolved_by']     = $who;
    $payload['resolution_note'] = 'Product deleted';
  }
  if (method_exists($db, 'update'))        $db->update('alerts', $docId, $payload);
  elseif (method_exists($db, 'writeDoc'))  $db->writeDoc('alerts', $docId, $payload);
  elseif (method_exists($db, 'setDoc'))    $db->setDoc('alerts', $docId, array_merge(['alert_type' => 'LOW_STOCK'], $payload));
  elseif (method_exists($db, 'write'))     $db->write('alerts', $docId, $payload);
}

function alert_ids_for_product(array $stock): array
{
  $ids = [];
  if (!empty($stock['doc_id']))     $ids[] = (string)$stock['doc_id'];
  if (!empty($stock['id']))         $ids[] = (string)$stock['id'];
  if (!empty($stock['product_id'])) $ids[] = (string)$stock['product_id'];
  // de-dup
  return array_values(array_unique($ids));
}

/**
 * Resolve a single alert doc if it exists (read → overwrite write).
 * We don't "update" first because some wrappers don't replace values reliably.
 */
function fs_force_resolve_alert($db, string $docId, string $alertType, ?string $who = null): bool
{
  // 1) See if it exists
  $existing = null;
  if (method_exists($db, 'readDoc'))      $existing = $db->readDoc('alerts', $docId);
  elseif (method_exists($db, 'read'))     $existing = $db->read('alerts', $docId);

  if (!$existing) return false; // don't create new docs; only resolve existing

  $nowIso = date('c');
  $payload = [
    'alert_type'  => strtoupper($alertType), // ensure correct type is stored
    'status'      => 'RESOLVED',
    'resolved_at' => $nowIso,
    'updated_at'  => $nowIso,
  ];
  if (!empty($who)) {
    $payload['resolved_by']     = $who;
    $payload['resolution_note'] = 'Product deleted';
  }

  // 2) Overwrite write (prefer non-merge)
  $ok = false;
  if (method_exists($db, 'writeDoc')) {
    $db->writeDoc('alerts', $docId, array_merge($existing, $payload));
    $ok = true;
  } elseif (method_exists($db, 'setDoc')) {
    $db->setDoc('alerts', $docId, array_merge($existing, $payload));
    $ok = true;
  } elseif (method_exists($db, 'upsert')) {
    $db->upsert('alerts', $docId, array_merge($existing, $payload));
    $ok = true;
  } elseif (method_exists($db, 'update')) {
    $db->update('alerts', $docId, $payload);
    $ok = true;
  } elseif (method_exists($db, 'write')) {
    $db->write('alerts', $docId, array_merge($existing, $payload));
    $ok = true;
  }

  // 3) Optional sanity re-read + log
  try {
    if ($ok) {
      $check = method_exists($db, 'readDoc') ? $db->readDoc('alerts', $docId) : (method_exists($db, 'read') ? $db->read('alerts', $docId) : null);
      error_log("Resolved alert {$docId}: status=" . ($check['status'] ?? 'UNKNOWN'));
    }
  } catch (\Throwable $t) { /* ignore */
  }

  return $ok;
}

/** Resolve all matching EXP_ / LOW_ alerts for a product (tries all id variants). */
function resolve_all_alerts_for_product($db, array $stock, ?string $who = null): void
{
  foreach (alert_ids_for_product($stock) as $pid) {
    // EXPIRY
    $expDoc = 'EXP_' . $pid;
    try {
      fs_force_resolve_alert($db, $expDoc, 'EXPIRY', $who);
    } catch (\Throwable $t) {
      error_log("resolve expiry ($expDoc) failed: " . $t->getMessage());
    }
    // LOW (optional but recommended)
    $lowDoc = 'LOW_' . $pid;
    try {
      fs_force_resolve_alert($db, $lowDoc, 'LOW_STOCK', $who);
    } catch (\Throwable $t) {
      error_log("resolve low ($lowDoc) failed: " . $t->getMessage());
    }
  }
}

function resolve_alerts_by_scan($db, array $stock, ?string $who = null): int
{
  $candidates = alert_ids_for_product($stock);
  if (!$candidates) return 0;

  $n = 0;
  try {
    $rows = method_exists($db, 'readAll') ? $db->readAll('alerts', [], null, 2000) : [];
    foreach ($rows as $row) {
      $pid   = (string)($row['product_id'] ?? '');
      if ($pid === '' || !in_array($pid, $candidates, true)) continue;

      $alertDocId = $row['id'] ?? $row['doc_id'] ?? null;
      if (!$alertDocId) continue;

      $atype  = strtoupper($row['alert_type'] ?? '');
      $status = strtoupper($row['status'] ?? '');
      if (($atype === 'EXPIRY' || $atype === 'LOW_STOCK') && $status !== 'RESOLVED') {
        if (fs_force_resolve_alert($db, $alertDocId, $atype, $who)) $n++;
      }
    }
  } catch (Throwable $t) {
    error_log('resolve_alerts_by_scan failed: ' . $t->getMessage());
  }
  return $n;
}


// Identify the product (prefer Firestore doc id via ?id=; allow ?sku= fallback)
$docId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$skuQ  = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';

$stock = null;
if ($docId !== '') $stock = fs_get_product_by_doc($docId);
if (!$stock && $skuQ !== '') $stock = fs_get_product($skuQ);

if (!$stock) {
  http_response_code(404);
  echo 'Product not found.';
  exit;
}
$docId = (string)$stock['doc_id'];   // normalize to Firestore doc id

// Build your two-field soft-delete flags
$softFlags = [
  'status'     => 'disabled',
  'deleted_at' => date('c'),
];

// Get DB wrapper
$db = db_obj();
if (!$db) {
  http_response_code(500);
  echo 'Database unavailable.';
  exit;
}

// --- Preferred path: if your wrapper supports MERGE/PATCH semantics ---
if (method_exists($db, 'updateMerge')) {
  try {
    // Soft-delete the product
    $db->updateMerge('products', $docId, $softFlags);

    // Who performed the delete (best-effort)
    $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id'] ?? null);
    $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

    $who = $_SESSION['username']
      ?? $_SESSION['email']
      ?? $_SESSION['user_id']
      ?? $_SESSION['uid']
      ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? 'admin');

    // Try direct id-based resolve
    resolve_all_alerts_for_product($db, $current, (string)$who);
    resolve_alerts_by_scan($db, $stock, (string)$who);

    // Then scan-based resolve to catch id mismatches
    if (resolve_alerts_by_scan($db, $current, (string)$who) === 0) {
      error_log('No alerts resolved by scan (fallback path) — check product_id vs doc ids.');
    }


    // Audit
    try {
      log_stock_audit([
        'action'       => 'delete_product',
        'product_id'   => (string)$docId,
        'sku'          => $stock['sku'] ?? null,
        'product_name' => $stock['name'] ?? null,
        'store_id'     => $stock['store_id'] ?? null,
        'user_id'      => $changedBy,
        'username'     => $changedName,
        'changed_by'   => $changedBy,
        'changed_name' => $changedName,
      ]);
    } catch (Throwable $t) {
      error_log('delete audit failed: ' . $t->getMessage());
    }

    header('Location: list.php?deleted=1');
    exit;
  } catch (Throwable $e) {
    error_log('Soft delete (merge) failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Failed to soft delete product.';
    exit;
  }
}


// --- Fallback path: read → merge → full update (preserves all fields) ---
try {
  // Re-read full document to ensure latest state
  $current = fs_get_product_by_doc($docId);
  if (!$current) {
    http_response_code(404);
    echo 'Product not found (reload).';
    exit;
  }

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

  header('Location: list.php?deleted=1');
  exit;
} catch (\Throwable $e) {
  error_log('Soft delete (full update) failed: ' . $e->getMessage());
  http_response_code(500);
  echo 'Failed to soft delete product.';
  exit;
}
