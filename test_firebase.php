<?php
// Simple Firebase connection test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Firebase Connection...\n";
echo "================================\n\n";

try {
    require_once 'db.php';
    
    echo "1. Loading database class... ";
    $db = getDB();
    echo "✓ OK\n";
    
    echo "2. Testing connection type... ";
    if (property_exists($db, 'useRestClient')) {
        $reflection = new ReflectionClass($db);
        $property = $reflection->getProperty('useRestClient');
        $property->setAccessible(true);
        $useRest = $property->getValue($db);
        echo ($useRest ? "REST Client" : "Firebase SDK") . "\n";
    } else {
        echo "Firebase SDK\n";
    }
    
    echo "3. Testing create operation... ";
    $testData = [
        'test' => true,
        'message' => 'Connection test',
        'timestamp' => date('c'),
        'random' => rand(1000, 9999)
    ];
    
    $docId = $db->create('test_connection', $testData);
    if ($docId) {
        echo "✓ Created document: $docId\n";
    } else {
        echo "✗ Failed\n";
    }
    
    echo "4. Testing read operation... ";
    if ($docId) {
        $retrieved = $db->read('test_connection', $docId);
        if ($retrieved && isset($retrieved['test'])) {
            echo "✓ Retrieved document successfully\n";
        } else {
            echo "✗ Failed to retrieve\n";
        }
    } else {
        echo "Skipped (no document to read)\n";
    }
    
    echo "5. Testing update operation... ";
    if ($docId) {
        $updateData = ['updated' => true, 'update_time' => date('c')];
        $updated = $db->update('test_connection', $docId, $updateData);
        if ($updated) {
            echo "✓ Updated successfully\n";
        } else {
            echo "✗ Failed to update\n";
        }
    } else {
        echo "Skipped (no document to update)\n";
    }
    
    echo "6. Testing list operation... ";
    $documents = $db->readAll('test_connection', [], null, 5);
    if (is_array($documents)) {
        echo "✓ Retrieved " . count($documents) . " documents\n";
    } else {
        echo "✗ Failed to list documents\n";
    }
    
    echo "7. Testing delete operation... ";
    if ($docId) {
        $deleted = $db->delete('test_connection', $docId);
        if ($deleted) {
            echo "✓ Deleted successfully\n";
        } else {
            echo "✗ Failed to delete\n";
        }
    } else {
        echo "Skipped (no document to delete)\n";
    }
    
    echo "\n================================\n";
    echo "Firebase connection test completed!\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>