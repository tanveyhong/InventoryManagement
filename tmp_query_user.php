<?php
require __DIR__ . '/functions.php';
$db = getDB();
$cond = [['email','==','owo@gmail.com']];
$res = $db->readAll('users', $cond, null, 10);
var_export($res);
?>