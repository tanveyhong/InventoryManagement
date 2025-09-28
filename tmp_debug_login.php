<?php
require __DIR__ . '/functions.php';

$email = $argv[1] ?? 'owo@gmail.com';
$user = findUserByUsernameOrEmail($email);

if ($user === null) {
    echo "No user found for: $email\n";
    exit(0);
}

echo "User found:\n";
var_export($user);

echo "\n\nPassword fields probe:\n";
$fields = ['password_hash','password','pass'];
foreach ($fields as $f) {
    echo "$f: ";
    if (isset($user[$f])) echo $user[$f] . "\n"; else echo "<not set>\n";
}

// If password_hash present, show its prefix
if (isset($user['password_hash'])) {
    $h = $user['password_hash'];
    echo "password_hash prefix: " . substr($h,0,4) . "\n";
}

?>