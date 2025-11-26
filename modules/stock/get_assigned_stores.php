<?php
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

header('Content-Type: application/json');

session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$product_id = $_GET['id'] ?? null;
if (!$product_id) {
    echo json_encode(['assigned_stores' => []]);
    exit;
}

try {
    $db = getSQLDB();
    
    // Log for debugging
    error_log("get_assigned_stores.php called for ID: " . $product_id);
    
    $product = $db->fetch("SELECT sku, name, barcode FROM products WHERE id = ?", [$product_id]);

    if (!$product) {
        error_log("Product not found for ID: " . $product_id);
        echo json_encode(['assigned_stores' => []]);
        exit;
    }

    $sku = $product['sku'];
    $name = $product['name'];
    $barcode = $product['barcode'] ?? '';
    
    error_log("Checking assignments for SKU: $sku, Name: $name");

    // Get all stores for validation
    $stores = $db->fetchAll("SELECT id, name FROM stores");
    $storeMap = [];
    foreach ($stores as $s) {
        $storeMap[$s['id']] = $s['name'];
    }

    // Find potential variants
    // 1. Exact SKU match
    // 2. SKU pattern (case insensitive)
    // 3. Name match (case insensitive)
    // 4. Barcode match
    
    $params = [$sku, $sku . '-%', $name];
    
    $sql = "SELECT id, sku, store_id, name FROM products WHERE (sku = ? OR LOWER(sku) LIKE LOWER(?) OR LOWER(name) = LOWER(?)";
    
    if (!empty($barcode)) {
        $sql .= " OR barcode = ?";
        $params[] = $barcode;
    }
    
    $sql .= ") AND store_id IS NOT NULL AND active = TRUE AND deleted_at IS NULL";
    
    $candidates = $db->fetchAll($sql, $params);
    
    error_log("Found " . count($candidates) . " candidate assignments");

    $assignedIds = [];
    foreach ($candidates as $cand) {
        $candSku = strtoupper($cand['sku']);
        $mainSku = strtoupper($sku);
        $sid = $cand['store_id'];
        
        // Check 1: Exact SKU match (unlikely for variant but possible)
        if ($candSku === $mainSku) {
            $assignedIds[] = $sid;
            continue;
        }
        
        // Check 2: Standard Suffix -S{store_id}
        if ($candSku === $mainSku . '-S' . $sid) {
            $assignedIds[] = $sid;
            continue;
        }
        
        // Check 3: Store Name Suffixes
        if (isset($storeMap[$sid])) {
            $storeName = $storeMap[$sid];
            $sanitized = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $storeName));
            
            if (!empty($sanitized)) {
                // -POS-{name}
                if ($candSku === $mainSku . '-POS-' . $sanitized) {
                    $assignedIds[] = $sid;
                    continue;
                }
                // -{name}
                if ($candSku === $mainSku . '-' . $sanitized) {
                    $assignedIds[] = $sid;
                    continue;
                }
            }
        }
        
        // Check 4: Name Match (Fallback for legacy/manual)
        // Only if names are identical (case-insensitive)
        if (strcasecmp($cand['name'], $name) === 0) {
             $assignedIds[] = $sid;
             continue;
        }
        
        // If none of the above, it's likely a false positive (e.g. SHIRT-BLUE vs SHIRT)
        error_log("Ignoring false positive assignment: {$cand['sku']} for store $sid");
    }

    $ids = array_unique(array_map('intval', $assignedIds));
    
    echo json_encode(['assigned_stores' => array_values($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
