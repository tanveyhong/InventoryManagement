<?php
// Stock Adjustment Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../redis.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$redis = new RedisConnection();

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = '';
$error = '';

if (!$product_id) {
    header('Location: list.php');
    exit;
}

// Get product details
$product = $db->fetchOne("
    SELECT p.*, c.name as category_name, s.name as store_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    LEFT JOIN stores s ON p.store_id = s.id 
    WHERE p.id = ?
", [$product_id]);

if (!$product) {
    header('Location: list.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adjustment_type = $_POST['adjustment_type'] ?? '';
    $quantity_change = intval($_POST['quantity_change'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($quantity_change > 0 && !empty($reason)) {
        try {
            $db->beginTransaction();
            
            // Calculate new quantity
            $new_quantity = $product['quantity'];
            if ($adjustment_type === 'add') {
                $new_quantity += $quantity_change;
            } elseif ($adjustment_type === 'subtract') {
                $new_quantity -= $quantity_change;
            } elseif ($adjustment_type === 'set') {
                $new_quantity = $quantity_change;
            }
            
            // Ensure quantity doesn't go negative
            $new_quantity = max(0, $new_quantity);
            
            // Update product quantity
            $db->execute("
                UPDATE products 
                SET quantity = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ", [$new_quantity, $product_id]);
            
            // Log the adjustment
            $db->execute("
                INSERT INTO stock_adjustments (
                    product_id, user_id, old_quantity, new_quantity, 
                    quantity_change, adjustment_type, reason, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ", [
                $product_id, 
                $_SESSION['user_id'], 
                $product['quantity'], 
                $new_quantity, 
                $quantity_change, 
                $adjustment_type, 
                $reason, 
                $notes
            ]);
            
            $db->commit();
            
            // Clear cache
            $redis->delete("product:{$product_id}");
            $redis->delete("stock_summary:*");
            
            // Publish alert if stock is now low
            if ($new_quantity <= $product['min_stock_level']) {
                $redis->publish('alerts:low_stock', json_encode([
                    'product_id' => $product_id,
                    'product_name' => $product['name'],
                    'current_stock' => $new_quantity,
                    'min_stock_level' => $product['min_stock_level'],
                    'timestamp' => time()
                ]));
            }
            
            $message = "Stock adjusted successfully! New quantity: " . number_format($new_quantity);
            
            // Redirect after success
            header("Location: list.php?success=" . urlencode($message));
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Failed to adjust stock: " . $e->getMessage();
        }
    } else {
        $error = "Please provide a valid quantity and reason for the adjustment.";
    }
}

$page_title = 'Adjust Stock - ' . $product['name'];
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
            <h1>Stock Adjustment</h1>
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
                <h2>Adjust Stock: <?php echo htmlspecialchars($product['name']); ?></h2>
                <div class="page-actions">
                    <a href="list.php" class="btn btn-outline">Back to Stock List</a>
                    <a href="view.php?id=<?php echo $product_id; ?>" class="btn btn-secondary">View Product</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <!-- Product Information -->
                <div class="product-summary">
                    <div class="product-details">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <?php if ($product['sku']): ?>
                            <p><strong>SKU:</strong> <?php echo htmlspecialchars($product['sku']); ?></p>
                        <?php endif; ?>
                        <p><strong>Current Stock:</strong> 
                            <span class="current-stock"><?php echo number_format($product['quantity']); ?></span>
                        </p>
                        <p><strong>Minimum Level:</strong> <?php echo number_format($product['min_stock_level']); ?></p>
                        <?php if ($product['category_name']): ?>
                            <p><strong>Category:</strong> <?php echo htmlspecialchars($product['category_name']); ?></p>
                        <?php endif; ?>
                        <?php if ($product['store_name']): ?>
                            <p><strong>Store:</strong> <?php echo htmlspecialchars($product['store_name']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Adjustment Form -->
                <form method="POST" class="adjustment-form">
                    <div class="form-group">
                        <label for="adjustment_type">Adjustment Type:</label>
                        <select id="adjustment_type" name="adjustment_type" required>
                            <option value="">Select adjustment type</option>
                            <option value="add">Add Stock</option>
                            <option value="subtract">Remove Stock</option>
                            <option value="set">Set Exact Quantity</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity_change">Quantity:</label>
                        <input type="number" id="quantity_change" name="quantity_change" min="1" step="1" required>
                        <small class="help-text" id="quantity_help">Enter the quantity to add, remove, or set</small>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason for Adjustment:</label>
                        <select id="reason" name="reason" required>
                            <option value="">Select reason</option>
                            <option value="stock_received">Stock Received</option>
                            <option value="sale">Sale/Customer Purchase</option>
                            <option value="damaged">Damaged/Defective</option>
                            <option value="expired">Expired Product</option>
                            <option value="theft">Theft/Loss</option>
                            <option value="return">Customer Return</option>
                            <option value="transfer">Store Transfer</option>
                            <option value="inventory_count">Inventory Count Correction</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes (Optional):</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Additional details about this adjustment..."></textarea>
                    </div>

                    <!-- Preview -->
                    <div class="adjustment-preview" id="adjustmentPreview" style="display: none;">
                        <h4>Adjustment Preview</h4>
                        <div class="preview-details">
                            <p><strong>Current Quantity:</strong> <span id="currentQty"><?php echo $product['quantity']; ?></span></p>
                            <p><strong>Change:</strong> <span id="changeAmount">-</span></p>
                            <p><strong>New Quantity:</strong> <span id="newQty">-</span></p>
                            <div id="warningMessage" class="warning-message" style="display: none;"></div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Confirm Adjustment</button>
                        <a href="list.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const adjustmentType = document.getElementById('adjustment_type');
            const quantityChange = document.getElementById('quantity_change');
            const reason = document.getElementById('reason');
            const preview = document.getElementById('adjustmentPreview');
            const submitBtn = document.getElementById('submitBtn');
            const quantityHelp = document.getElementById('quantity_help');
            
            const currentQuantity = <?php echo $product['quantity']; ?>;
            const minStockLevel = <?php echo $product['min_stock_level']; ?>;
            
            function updatePreview() {
                const type = adjustmentType.value;
                const quantity = parseInt(quantityChange.value) || 0;
                
                if (!type || quantity <= 0) {
                    preview.style.display = 'none';
                    submitBtn.disabled = true;
                    return;
                }
                
                let newQuantity = currentQuantity;
                let changeText = '';
                
                switch(type) {
                    case 'add':
                        newQuantity = currentQuantity + quantity;
                        changeText = '+' + quantity;
                        quantityHelp.textContent = 'Enter quantity to add to current stock';
                        break;
                    case 'subtract':
                        newQuantity = Math.max(0, currentQuantity - quantity);
                        changeText = '-' + quantity;
                        quantityHelp.textContent = 'Enter quantity to remove from current stock';
                        break;
                    case 'set':
                        newQuantity = quantity;
                        changeText = 'Set to ' + quantity;
                        quantityHelp.textContent = 'Enter the exact quantity to set';
                        break;
                }
                
                document.getElementById('changeAmount').textContent = changeText;
                document.getElementById('newQty').textContent = newQuantity;
                
                // Show warnings
                const warningDiv = document.getElementById('warningMessage');
                warningDiv.style.display = 'none';
                warningDiv.className = 'warning-message';
                
                if (newQuantity <= 0) {
                    warningDiv.textContent = 'Warning: This will result in zero stock!';
                    warningDiv.className = 'warning-message error';
                    warningDiv.style.display = 'block';
                } else if (newQuantity <= minStockLevel) {
                    warningDiv.textContent = 'Warning: Stock will be below minimum level (' + minStockLevel + ')';
                    warningDiv.className = 'warning-message warning';
                    warningDiv.style.display = 'block';
                }
                
                preview.style.display = 'block';
                submitBtn.disabled = !reason.value;
            }
            
            function validateForm() {
                const hasType = adjustmentType.value !== '';
                const hasQuantity = quantityChange.value && parseInt(quantityChange.value) > 0;
                const hasReason = reason.value !== '';
                
                submitBtn.disabled = !(hasType && hasQuantity && hasReason);
            }
            
            adjustmentType.addEventListener('change', updatePreview);
            quantityChange.addEventListener('input', updatePreview);
            reason.addEventListener('change', validateForm);
            
            // Confirmation before submit
            document.querySelector('.adjustment-form').addEventListener('submit', function(e) {
                const newQty = document.getElementById('newQty').textContent;
                const changeAmount = document.getElementById('changeAmount').textContent;
                
                const confirmed = confirm(
                    `Are you sure you want to ${changeAmount} units?\n` +
                    `New quantity will be: ${newQty}`
                );
                
                if (!confirmed) {
                    e.preventDefault();
                }
            });
        });
    </script>
    
    <style>
        .product-summary {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .current-stock {
            font-size: 1.2em;
            font-weight: bold;
            color: #28a745;
        }
        
        .adjustment-preview {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .preview-details p {
            margin: 0.5rem 0;
        }
        
        .warning-message {
            padding: 0.75rem;
            border-radius: 4px;
            margin-top: 1rem;
            font-weight: bold;
        }
        
        .warning-message.warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .warning-message.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .form-actions {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }
    </style>
</body>
</html>