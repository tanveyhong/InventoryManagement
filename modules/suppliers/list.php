<?php
// modules/suppliers/list.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
// Fetch all active suppliers for client-side filtering
$suppliers = $sqlDb->fetchAll("SELECT * FROM suppliers WHERE active = TRUE ORDER BY name ASC");

$page_title = 'Supplier Management';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .search-container {
            margin-bottom: 20px;
        }
        .search-input {
            width: 100%;
            max-width: 300px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>

    <div class="container">
        <main>
            <div class="page-header">
                <div class="page-header-inner">
                    <div class="left">
                        <h1 class="title">Supplier Management</h1>
                        <p class="subtitle">Manage your supply chain partners.</p>
                    </div>
                    <div class="right">
                        <a href="add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Supplier</a>
                    </div>
                </div>
            </div>

            <div class="search-container">
                <input type="text" id="searchInput" class="search-input" placeholder="Search suppliers...">
            </div>

            <div class="table-container">
                <table class="data-table" id="suppliersTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact Person</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="5" style="text-align:center;">No suppliers found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr class="supplier-row">
                                    <td class="searchable"><?php echo htmlspecialchars($supplier['name']); ?></td>
                                    <td class="searchable"><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></td>
                                    <td class="searchable"><?php echo htmlspecialchars($supplier['email'] ?? '-'); ?></td>
                                    <td class="searchable"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                                    <td>
                                        <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="delete.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this supplier?');" title="Delete"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('suppliersTable');
            const rows = table.querySelectorAll('.supplier-row');

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();

                rows.forEach(row => {
                    const searchableCells = row.querySelectorAll('.searchable');
                    let match = false;

                    searchableCells.forEach(cell => {
                        if (cell.textContent.toLowerCase().includes(searchTerm)) {
                            match = true;
                        }
                    });

                    if (match) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });
    </script>
</body>
</html>
