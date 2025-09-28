<?php
$pw='secret';
$h1 = password_hash($pw, PASSWORD_DEFAULT);
$h2 = md5($pw);
$h3 = sha1($pw);
require __DIR__ . '/functions.php';
echo 'password_hash verify: ' . (verifyPassword($pw, $h1) ? 'OK' : 'FAIL') . PHP_EOL;
echo 'md5 verify: ' . (verifyPassword($pw, $h2) ? 'OK' : 'FAIL') . PHP_EOL;
echo 'sha1 verify: ' . (verifyPassword($pw, $h3) ? 'OK' : 'FAIL') . PHP_EOL;
