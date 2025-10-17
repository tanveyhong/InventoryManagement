<?php
require_once 'db.php';
require_once 'firebase_rest_client.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die("No session found");
}

$client = new FirebaseRestClient();
$user = $client->getDocument('users', $_SESSION['user_id']);

echo "User data:\n";
echo "==========\n";
if ($user) {
    foreach ($user as $key => $value) {
        echo "$key: " . var_export($value, true) . "\n";
        echo "  Length: " . strlen($value) . "\n";
        echo "  JSON encoded: " . json_encode($value) . "\n";
        echo "\n";
    }
} else {
    echo "No user found\n";
}
