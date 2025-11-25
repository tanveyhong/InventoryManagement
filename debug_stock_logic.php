<?php
// Simulate the logic in list.php

$all_products = [
    [
        'id' => 1,
        'name' => 'Extension Cord',
        'sku' => 'CORD-038',
        'quantity' => 100,
        'store_id' => null,
        'store_name' => null
    ],
    [
        'id' => 2,
        'name' => 'Extension Cord',
        'sku' => 'CORD-038-S8',
        'quantity' => 0,
        'store_id' => 8,
        'store_name' => 'Vey Hong Tandddd'
    ]
];

// 1. Build Warehouse Stock
$warehouseStock = [];
foreach ($all_products as $p) {
    if (empty($p['store_id'])) {
        $warehouseStock[$p['sku']] = $p['quantity'];
    }
}

echo "Warehouse Stock:\n";
print_r($warehouseStock);

// 2. Grouping Logic
$productGroups = [];
foreach ($all_products as $product) {
    $sku = $product['sku'] ?? '';
    $storeId = $product['store_id'] ?? null;
    
    $baseSku = $sku;
    $isStoreVariant = false;
    
    if (preg_match('/^(.+)-S(\d+)$/', $sku, $matches)) {
        $baseSku = $matches[1];
        $isStoreVariant = true;
        $product['_is_store_variant'] = true;
    } elseif (!empty($storeId)) {
        // Fallback for store products without standard suffix
        $isStoreVariant = true;
        $product['_is_store_variant'] = true;
    }
    
    if (!isset($productGroups[$baseSku])) {
        $productGroups[$baseSku] = [
            'main' => null,
            'variants' => []
        ];
    }
    
    if (!$isStoreVariant) {
        $productGroups[$baseSku]['main'] = $product;
    } else {
        $productGroups[$baseSku]['variants'][] = $product;
    }
}

// 3. Flattening
$filtered_products = [];
foreach ($productGroups as $baseSku => $group) {
    if ($group['main']) {
        $group['main']['_group_key'] = $baseSku;
        $filtered_products[] = $group['main'];
    }
    
    foreach ($group['variants'] as $variant) {
        $variant['_group_key'] = $baseSku;
        $filtered_products[] = $variant;
    }
}

echo "\nFiltered Products:\n";
foreach ($filtered_products as $p) {
    echo "SKU: " . $p['sku'] . "\n";
    echo "Group Key: " . ($p['_group_key'] ?? 'N/A') . "\n";
    
    $whQty = 0;
    $lookupSku = $p['_group_key'] ?? $p['sku'];
    if (!empty($p['store_id']) && isset($warehouseStock[$lookupSku])) {
        $whQty = $warehouseStock[$lookupSku];
    }
    echo "Calculated WH Qty: $whQty\n\n";
}
