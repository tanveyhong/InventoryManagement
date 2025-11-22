<?php
// modules/suppliers/delete.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (!empty($id)) {
    try {
        $sqlDb = SQLDatabase::getInstance();
        // Soft delete or hard delete? Schema has 'active' column.
        $sqlDb->execute("UPDATE suppliers SET active = FALSE WHERE id = ?", [$id]);
        $_SESSION['success'] = 'Supplier deleted successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting supplier: ' . $e->getMessage();
    }
}

header('Location: list.php');
exit;
?>
