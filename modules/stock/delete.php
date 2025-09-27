<?php
// Delete Product
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$product_id = intval($_GET['id'] ?? 0);

if (!$product_id) {
    header('Location: list.php');
    exit;
}

$db = getDB();

// Get product details
$product = $db->fetch("
    SELECT p.*, c.name as category_name, s.name as store_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN stores s ON p.store_id = s.id 
    WHERE p.id = ?
", [$product_id]);

if (!$product) {
    header('Location: list.php?error=' . urlencode('Product not found'));
    exit;
}

$errors = [];
$confirmation_required = !isset($_POST['confirm_delete']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Delete related stock movements (Firebase style)
        $movements = $db->readAll('stock_movements', [['product_id', '==', $product_id]]);
        foreach ($movements as $movement) {
            $db->delete('stock_movements', $movement['id']);
        }

        // Delete the product
        $db->delete('products', $product_id);

        // Redirect with success message
        header("Location: list.php?success=" . urlencode("Product '{$product['name']}' has been deleted successfully"));
        exit;
    } catch (Exception $e) {
        $errors[] = "Error deleting product: " . $e->getMessage();
    }
}

$page_title = 'Delete Product - ' . htmlspecialchars($product['name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <header>
            <h1>Delete Product</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../alerts/low_stock.php">Alerts</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Delete Product</h2>
                <div class="page-actions">
                    <a href="list.php" class="btn btn-secondary">← Back to Stock List</a>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <h3>Error:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="delete-container">
                <div class="delete-warning">
                    <div class="warning-icon">⚠️</div>
                    <h3>Warning: This action cannot be undone!</h3>
                    <p>You are about to permanently delete this product and all its associated data.</p>
                </div>

                <!-- Product Details -->
                <div class="product-details">
                    <h3>Product Information</h3>
                    <div class="details-grid">
                        <div class="detail-item">
                            <label>Product Name:</label>
                            <span><?php echo htmlspecialchars($product['name']); ?></span>
                        </div>
                        
                        <?php if ($product['sku']): ?>
                        <div class="detail-item">
                            <label>SKU:</label>
                            <span class="sku"><?php echo htmlspecialchars($product['sku']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-item">
                            <label>Current Stock:</label>
                            <span class="stock-level"><?php echo number_format($product['quantity']); ?> units</span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Unit Price:</label>
                            <span class="price">$<?php echo number_format($product['unit_price'], 2); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Total Value:</label>
                            <span class="total-value">$<?php echo number_format($product['quantity'] * $product['unit_price'], 2); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Category:</label>
                            <span><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <label>Store:</label>
                            <span><?php echo htmlspecialchars($product['store_name'] ?? 'No Store'); ?></span>
                        </div>
                        
                        <?php if ($product['expiry_date']): ?>
                        <div class="detail-item">
                            <label>Expiry Date:</label>
                            <span><?php echo date('M j, Y', strtotime($product['expiry_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($product['description']): ?>
                    <div class="description">
                        <label>Description:</label>
                        <p><?php echo htmlspecialchars($product['description']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Deletion Impact -->
                <div class="deletion-impact">
                    <h3>What will be deleted:</h3>
                    <ul class="impact-list">
                        <li>✗ Product information and details</li>
                        <li>✗ All stock movement history</li>
                        <li>✗ Current inventory quantity (<?php echo number_format($product['quantity']); ?> units)</li>
                        <li>✗ Inventory value of $<?php echo number_format($product['quantity'] * $product['unit_price'], 2); ?></li>
                        <?php if ($product['expiry_date']): ?>
                        <li>✗ Expiry date tracking</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Alternative Actions -->
                <div class="alternatives">
                    <h3>Consider these alternatives:</h3>
                    <div class="alternative-actions">
                        <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-info">
                            📝 Edit Product
                            <small>Modify product information instead</small>
                        </a>
                        <a href="adjust.php?id=<?php echo $product['id']; ?>" class="btn btn-warning">
                            📊 Adjust Stock to Zero
                            <small>Keep product but set quantity to 0</small>
                        </a>
                        <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-secondary">
                            👁️ View Details
                            <small>Review product information</small>
                        </a>
                    </div>
                </div>

                <!-- Confirmation Form -->
                <div class="confirmation-form">
                    <form method="POST" id="deleteForm">
                        <div class="confirmation-checkbox">
                            <label class="checkbox-label">
                                <input type="checkbox" id="confirmCheckbox" required>
                                <span class="checkmark"></span>
                                I understand that this action is permanent and cannot be undone
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="confirm_delete" value="1" class="btn btn-danger" id="deleteButton" disabled>
                                🗑️ Permanently Delete Product
                            </button>
                            <a href="list.php" class="btn btn-secondary">Cancel</a>
                            <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline">View Product</a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Enable delete button only when checkbox is checked
        document.getElementById('confirmCheckbox').addEventListener('change', function() {
            document.getElementById('deleteButton').disabled = !this.checked;
        });
        
        // Double confirmation on form submit
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const productName = <?php echo json_encode($product['name']); ?>;
            const stockValue = <?php echo json_encode(number_format($product['quantity'] * $product['unit_price'], 2)); ?>;
            
            const confirmMessage = `Are you absolutely sure you want to delete "${productName}"?\n\n` +
                                 `This will permanently remove:\n` +
                                 `• Product information\n` +
                                 `• Stock history\n` +
                                 `• Inventory value: $${stockValue}\n\n` +
                                 `This action CANNOT be undone!`;
                                 
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
            
            // Final confirmation
            if (!confirm('Last chance! Are you really sure you want to delete this product?')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>

    <style>
        .delete-container {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        
        .delete-warning {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border: 2px solid #fc8181;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
        }
        
        .warning-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .delete-warning h3 {
            color: #c53030;
            margin-bottom: 1rem;
        }
        
        .delete-warning p {
            color: #744210;
            font-size: 1.1rem;
        }
        
        .product-details {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .detail-item label {
            font-weight: 600;
            color: #666;
            font-size: 0.9rem;
        }
        
        .detail-item span {
            color: #333;
            font-size: 1rem;
        }
        
        .sku {
            font-family: monospace;
            background: #f5f5f5;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .stock-level {
            font-weight: bold;
            color: #333;
        }
        
        .price, .total-value {
            font-weight: bold;
            color: #28a745;
        }
        
        .description {
            border-top: 1px solid #e0e0e0;
            padding-top: 1rem;
        }
        
        .description label {
            font-weight: 600;
            color: #666;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .deletion-impact {
            background: #fff8e1;
            border: 1px solid #ffcc02;
            border-radius: 8px;
            padding: 2rem;
        }
        
        .deletion-impact h3 {
            color: #e65100;
            margin-bottom: 1rem;
        }
        
        .impact-list {
            list-style: none;
            padding: 0;
        }
        
        .impact-list li {
            padding: 0.5rem 0;
            color: #d84315;
            font-weight: 500;
        }
        
        .alternatives {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 2rem;
        }
        
        .alternatives h3 {
            color: #2e7d32;
            margin-bottom: 1rem;
        }
        
        .alternative-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .alternative-actions .btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem;
            text-align: center;
            text-decoration: none;
        }
        
        .alternative-actions .btn small {
            margin-top: 0.5rem;
            opacity: 0.8;
            font-size: 0.8rem;
        }
        
        .confirmation-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .confirmation-checkbox {
            margin-bottom: 2rem;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            font-size: 1.1rem;
            color: #333;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }
        
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .form-actions .btn {
            min-width: 150px;
        }
        
        #deleteButton:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
            
            .alternative-actions {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</body>
</html>