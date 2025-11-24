<?php
// modules/suppliers/edit.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';
require_once '../../activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (empty($id)) {
    header('Location: list.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
$supplier = $sqlDb->fetch("SELECT * FROM suppliers WHERE id = ?", [$id]);

if (!$supplier) {
    die('Supplier not found.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($name)) {
        $error = 'Supplier name is required.';
    } else {
        try {
            $sqlDb->execute(
                "UPDATE suppliers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?",
                [$name, $contact_person, $email, $phone, $address, $id]
            );
            logActivity('supplier_updated', "Updated supplier: $name", ['supplier_id' => $id]);
            
            $_SESSION['success'] = 'Supplier updated successfully.';
            header('Location: list.php');
            exit;
        } catch (Exception $e) {
            $error = 'Error updating supplier: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Supplier</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <div class="page-header-inner">
                    <div class="left">
                        <h1 class="title">Edit Supplier</h1>
                        <p class="subtitle">Update supplier information.</p>
                    </div>
                    <div class="right">
                        <a href="list.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" class="product-form">
                    <div class="form-sections">
                        <div class="form-section">
                            <h3>Supplier Details</h3>
                            
                            <div class="form-group required">
                                <label>Supplier Name</label>
                                <input type="text" name="name" required class="form-control" value="<?php echo htmlspecialchars($supplier['name']); ?>">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Contact Person</label>
                                    <input type="text" name="contact_person" class="form-control" value="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Update Supplier</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
