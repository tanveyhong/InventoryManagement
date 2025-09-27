<?php
// Always return Firebase Database instance
require_once 'db.php';

function getDB() {
    return Database::getInstance();
}
