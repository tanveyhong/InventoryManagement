<?php
require_once '../../config.php';
require_once '../../sql_db.php';

$sqlDb = SQLDatabase::getInstance();

// Ensure table exists (same logic as in terminal.php)
$idType = "SERIAL PRIMARY KEY";
if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
    $idType = "INTEGER PRIMARY KEY AUTOINCREMENT";
}

$sqlDb->execute("CREATE TABLE IF NOT EXISTS customer_accounts (
    id $idType,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_type VARCHAR(20) NOT NULL,
    balance DECIMAL(10, 2) DEFAULT 0.00,
    pin VARCHAR(255),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Create credentials for senpaifruit@gmail.com
$email = 'senpaifruit@gmail.com';

// 1. Bank Account
$bankAccNum = '4888888888888888';
$checkBank = $sqlDb->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$bankAccNum]);

if (!$checkBank) {
    $sqlDb->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, email) 
               VALUES (?, 'Senpai Fruit Bank', 'bank', 10000.00, ?)", [$bankAccNum, $email]);
    $message = "Bank account created.";
}

// 2. E-Wallet (TnG)
$walletAccNum = '01234567890'; // Phone number style
$checkWallet = $sqlDb->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$walletAccNum]);

if (!$checkWallet) {
    $pin = password_hash('123456', PASSWORD_DEFAULT);
    $sqlDb->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, pin, email) 
               VALUES (?, 'Senpai Fruit TnG', 'ewallet', 1000.00, ?, ?)", [$walletAccNum, $pin, $email]);
    $message .= " E-Wallet account created.";
}

// Fetch accounts for display
$accounts = $sqlDb->fetchAll("SELECT * FROM customer_accounts WHERE email = ?", [$email]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Balance Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            width: 100%;
            max-width: 800px;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .avatar {
            width: 50px;
            height: 50px;
            background: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-bank {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
        }
        .card-ewallet {
            background: linear-gradient(135deg, #2980b9 0%, #2ecc71 100%); /* TnG colors roughly */
            color: white;
        }
        .card-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 40px;
            opacity: 0.3;
        }
        .card-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        .card-balance {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .card-number {
            font-family: 'Courier New', monospace;
            font-size: 18px;
            letter-spacing: 2px;
            margin-bottom: 10px;
        }
        .card-name {
            font-size: 16px;
            text-transform: uppercase;
        }
        .refresh-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .refresh-btn:hover {
            background: #2980b9;
        }
        .credentials-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            color: #e74c3c;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="user-info">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <div>
                <h2 style="margin: 0;">Welcome, Senpai Fruit</h2>
                <div style="color: #7f8c8d;"><?= htmlspecialchars($email) ?></div>
            </div>
        </div>
        <button class="refresh-btn" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Refresh Balance
        </button>
    </div>

    <div class="cards-grid">
        <?php foreach ($accounts as $acc): ?>
            <?php if ($acc['email'] === $email): ?>
                <div class="card <?= $acc['account_type'] == 'bank' ? 'card-bank' : 'card-ewallet' ?>">
                    <i class="fas <?= $acc['account_type'] == 'bank' ? 'fa-credit-card' : 'fa-mobile-alt' ?> card-icon"></i>
                    <div class="card-label"><?= $acc['account_type'] == 'bank' ? 'Bank Account' : 'Touch \'n Go eWallet' ?></div>
                    <div class="card-balance">RM <?= number_format($acc['balance'], 2) ?></div>
                    <div class="card-number">
                        <?php 
                        if ($acc['account_type'] == 'bank') {
                            echo chunk_split($acc['account_number'], 4, ' ');
                        } else {
                            echo $acc['account_number'];
                        }
                        ?>
                    </div>
                    <div class="card-name"><?= htmlspecialchars($acc['account_name']) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="credentials-box">
        <h3><i class="fas fa-key"></i> Demo Credentials</h3>
        <p>Use these details in the POS terminal to test payments:</p>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <h4><i class="fas fa-credit-card"></i> Credit Card</h4>
                <p><strong>Card Number:</strong> <code><?= $bankAccNum ?></code></p>
                <p><strong>OTP:</strong> (Check alert popup)</p>
            </div>
            <div>
                <h4><i class="fas fa-mobile-alt"></i> Touch 'n Go eWallet</h4>
                <p><strong>Wallet ID:</strong> <code><?= $walletAccNum ?></code></p>
                <p><strong>PIN:</strong> <code>123456</code></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
