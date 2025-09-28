<?php
require __DIR__ . '/functions.php';

$username = $argv[1] ?? 'owo@gmail.com';
$password = $argv[2] ?? 'secret'; // change to your test password

$user = findUserByUsernameOrEmail($username);
if (!$user) { echo "No user\n"; exit(1); }

// mimic auth logic
$possible_hashes = [
    'password_hash' => $user['password_hash'] ?? null,
    'password' => $user['password'] ?? null,
    'pass' => $user['pass'] ?? null
];
$auth_ok = false;
foreach ($possible_hashes as $k => $h) {
    if (!empty($h) && verifyPassword($password, $h)) { $auth_ok = true; break; }
}

echo 'Auth result: ' . ($auth_ok ? 'OK' : 'FAIL') . PHP_EOL;
if ($auth_ok) {
    echo 'User id: ' . ($user['id'] ?? '<no id>') . PHP_EOL;
}
?>