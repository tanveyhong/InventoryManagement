<?php
require __DIR__ . '/functions.php';
$db = getDB();
$all = $db->readAll('users');
if (empty($all)) {
    echo "readAll returned empty or no users\n";
    var_export($all);
    exit(0);
}

echo "Found " . count($all) . " users:\n";
foreach ($all as $u) {
    var_export($u);
    echo "\n----\n";
}
?>