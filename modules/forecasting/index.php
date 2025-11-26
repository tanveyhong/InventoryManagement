<?php
/**
 * Demand Forecasting - Main Interface
 */

require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once 'DemandForecast.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_view_forecasting')) {
    header('Location: ../../index.php');
    exit;
}

$db = SQLDatabase::getInstance();
$forecaster = new DemandForecast();

// Get parameters
$product_id = isset($_GET['product']) ? intval($_GET['product']) : 0;
$store_id = isset($_GET['store']) ? intval($_GET['store']) : 0;
$forecast_days = isset($_GET['days']) ? intval($_GET['days']) : 30;

// Get products and stores for filters
$products = $db->fetchAll("SELECT id, name, sku, category FROM products WHERE active = true ORDER BY name");
$stores = $db->fetchAll("SELECT id, name FROM stores WHERE active = true ORDER BY name");

// Generate forecast if product selected
$forecast = null;
$product_info = null;

if ($product_id > 0) {
    $product_info = $db->fetch("SELECT * FROM products WHERE id = ? AND active = true", [$product_id]);
    
    if ($product_info) {
        $forecast = $forecaster->forecast($product_id, $store_id ?: null, $forecast_days);
    }
}

$page_title = 'Demand Forecasting';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --info-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            --card-hover-shadow: 0 15px 50px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .forecast-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 15px;
        }
        
        /* Compact Page Header */
        .forecast-container .page-header {
            background: white !important;
            border-radius: 12px !important;
            padding: 15px 20px !important;
            margin-bottom: 15px !important;
            box-shadow: var(--card-shadow) !important;
            position: relative !important;
            overflow: hidden !important;
            border: 1px solid #e2e8f0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }

        .forecast-container .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .forecast-container .page-header h1 {
            margin: 0 !important;
            color: #1e293b !important;
            font-size: 20px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
        }
        
        .forecast-container .page-header h1 i {
            color: #667eea;
            font-size: 22px;
            background: none;
            -webkit-text-fill-color: initial;
        }
        
        .forecast-container .page-header p {
            margin: 0 !important;
            color: #64748b !important;
            font-size: 13px !important;
            font-weight: 500 !important;
        }
        
        /* Compact Filter Section */
        .forecast-container .filter-section {
            background: white !important;
            padding: 15px !important;
            border-radius: 12px !important;
            box-shadow: var(--card-shadow) !important;
            margin-bottom: 15px !important;
            border: 1px solid #e2e8f0 !important;
        }

        .forecast-container .filter-section h3 {
            margin: 0 0 10px 0 !important;
            color: #1e293b !important;
            font-size: 15px !important;
            font-weight: 600 !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .forecast-container .filter-section h3 i {
            color: #667eea;
            font-size: 16px;
            background: none;
            -webkit-text-fill-color: initial;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.5fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label i {
            margin-right: 10px;
            color: #667eea;
            font-size: 16px;
        }
        
        .form-group select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            height: 34px;
        }

        /* Compact Select2 */
        .select2-container--default .select2-selection--single {
            height: 34px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            padding: 2px 8px !important;
            background: white !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px !important;
            color: #334155 !important;
            font-size: 13px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 32px !important;
            right: 5px !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #667eea !important;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1), 0 4px 12px rgba(102, 126, 234, 0.2) !important;
        }

        .select2-dropdown {
            border: 2px solid #667eea !important;
            border-radius: 14px !important;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15) !important;
            overflow: hidden !important;
        }

        .select2-search--dropdown .select2-search__field {
            border: 2px solid #e2e8f0 !important;
            border-radius: 10px !important;
            padding: 12px 16px !important;
            font-size: 15px !important;
            font-weight: 600 !important;
        }

        .select2-search--dropdown .select2-search__field:focus {
            border-color: #667eea !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
        }

        .select2-results__option {
            padding: 14px 20px !important;
            font-size: 15px !important;
            font-weight: 500 !important;
            transition: var(--transition) !important;
        }

        .select2-results__option--highlighted {
            background: var(--primary-gradient) !important;
        }
        
        /* Compact Generate Button */
        .btn-generate {
            background: var(--primary-gradient);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            box-shadow: 0 2px 5px rgba(102, 126, 234, 0.3);
            height: 34px;
        }

        .btn-generate:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.4);
        }

        .btn-generate i {
            font-size: 14px;
        }

        /* Compact Quick Filters */
        .quick-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            align-items: center;
        }

        .quick-filter-label {
            font-weight: 600;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            margin-right: 5px;
        }

        .quick-filter-btn {
            padding: 4px 10px;
            border: 1px solid #cbd5e1;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }

        .quick-filter-btn.active {
            border-color: #667eea;
            background: var(--primary-gradient);
            color: white;
        }
        
        /* Compact Stats Grid */
        .forecast-container .stats-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
            gap: 15px !important;
            margin-bottom: 15px !important;
        }
        
        /* Compact Stat Cards */
        .forecast-container .stat-card {
            background: white !important;
            padding: 15px !important;
            border-radius: 10px !important;
            box-shadow: var(--card-shadow) !important;
            border: 1px solid #e2e8f0 !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .forecast-container .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
        }
        
        .forecast-container .stat-card h3 {
            margin: 0 0 5px 0 !important;
            font-size: 11px !important;
            color: #64748b !important;
            text-transform: uppercase !important;
            font-weight: 600 !important;
            display: flex !important;
            align-items: center !important;
            gap: 5px !important;
        }

        .forecast-container .stat-card h3 i {
            color: #667eea !important;
            font-size: 12px !important;
        }
        
        .forecast-container .stat-value {
            font-size: 24px !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            background: none !important;
            -webkit-text-fill-color: initial !important;
            margin-bottom: 2px !important;
            line-height: 1.2 !important;
        }
        
        .forecast-container .stat-label {
            font-size: 11px !important;
            color: #94a3b8 !important;
        }
        
        /* Modern Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge.success { 
            background: var(--success-gradient); 
            color: white; 
        }
        .status-badge.warning { 
            background: var(--warning-gradient); 
            color: white; 
        }
        .status-badge.danger { 
            background: var(--danger-gradient); 
            color: white; 
        }
        .status-badge.info { 
            background: var(--info-gradient); 
            color: white; 
        }
        
        /* Compact Chart Container */
        .forecast-container .chart-container {
            background: white !important;
            padding: 15px !important;
            border-radius: 12px !important;
            box-shadow: var(--card-shadow) !important;
            margin-bottom: 15px !important;
            border: 1px solid #e2e8f0 !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .forecast-container .chart-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .forecast-container .chart-container h2 {
            margin: 0 0 10px 0 !important;
            color: #1e293b !important;
            font-size: 16px !important;
            font-weight: 600 !important;
        }

        .forecast-container .chart-container h2 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }


        /* Better grid layout for main content */
        .forecast-container .forecast-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 35px;
            margin-bottom: 35px;
        }

        .forecast-container .forecast-left {
            display: flex;
            flex-direction: column;
            gap: 35px;
        }

        .forecast-container .forecast-right {
            display: flex;
            flex-direction: column;
            gap: 35px;
        }

        @media (max-width: 1200px) {
            .forecast-container .forecast-content {
                grid-template-columns: 1fr;
            }
        }
        
        /* Compact Recommendations */
        .forecast-container .recommendations {
            background: white !important;
            padding: 15px !important;
            border-radius: 12px !important;
            box-shadow: var(--card-shadow) !important;
            border: 1px solid #e2e8f0 !important;
            height: fit-content !important;
            max-height: 400px !important;
        }

        .forecast-container .recommendations::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .forecast-container .recommendations h2 {
            margin: 0 0 25px 0 !important;
            color: #1a202c !important;
            font-size: 22px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            position: sticky !important;
            top: 0 !important;
            background: white !important;
            z-index: 10 !important;
            padding-bottom: 15px !important;
        }

        .forecast-container .recommendations h2 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .forecast-container .recommendations-list {
            max-height: 450px !important;
            overflow-y: auto !important;
            padding-right: 10px !important;
        }

        .forecast-container .recommendations-list::-webkit-scrollbar {
            width: 8px;
        }

        .forecast-container .recommendations-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .forecast-container .recommendations-list::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }

        .forecast-container .recommendations-list::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        
        /* Compact Recommendation Items */
        .forecast-container .recommendation-item {
            padding: 10px !important;
            border-radius: 8px !important;
            margin-bottom: 8px !important;
            display: flex !important;
            gap: 10px !important;
            border: 1px solid transparent !important;
        }

        .forecast-container .recommendation-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .forecast-container .recommendation-item:hover {
            transform: translateX(5px) !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1) !important;
        }
        
        .recommendation-item.critical { 
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); 
            border-color: rgba(239, 68, 68, 0.2);
        }
        .recommendation-item.critical::before { background: var(--danger-gradient); }

        .recommendation-item.high { 
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); 
            border-color: rgba(245, 158, 11, 0.2);
        }
        .recommendation-item.high::before { background: var(--warning-gradient); }

        .recommendation-item.medium { 
            background: linear-gradient(135deg, #f0f9ff 0%, #dbeafe 100%); 
            border-color: rgba(59, 130, 246, 0.2);
        }
        .recommendation-item.medium::before { background: var(--info-gradient); }

        .recommendation-item.low { 
            background: linear-gradient(135deg, #f5f3ff 0%, #e0e7ff 100%); 
            border-color: rgba(99, 102, 241, 0.2);
        }
        .recommendation-item.low::before { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }

        .recommendation-item.success { 
            background: linear-gradient(135deg, #f0fdf4 0%, #d1fae5 100%); 
            border-color: rgba(16, 185, 129, 0.2);
        }
        .recommendation-item.success::before { background: var(--success-gradient); }

        .recommendation-item.info { 
            background: linear-gradient(135deg, #fafafa 0%, #f3f4f6 100%); 
            border-color: rgba(107, 114, 128, 0.2);
        }
        .recommendation-item.info::before { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }
        
        .forecast-container .rec-icon {
            font-size: 26px !important;
            min-width: 26px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        
        .forecast-container .rec-content {
            flex: 1 !important;
        }
        
        .forecast-container .rec-content h4 {
            margin: 0 0 6px 0 !important;
            color: #1a202c !important;
            font-size: 15px !important;
            font-weight: 700 !important;
        }
        
        .forecast-container .rec-content p {
            margin: 0 !important;
            color: #475569 !important;
            font-size: 13px !important;
            line-height: 1.6 !important;
            font-weight: 500 !important;
        }
        
        /* Compact Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            border: 1px solid #e2e8f0;
        }

        .empty-state::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--primary-gradient);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
        }

        .empty-state h3 {
            color: #1a202c;
            font-size: 20px;
            margin: 0 0 10px 0;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 14px;
            margin: 0 0 20px 0;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
            font-weight: 500;
            color: #64748b;
        }

        .empty-state-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 24px;
            max-width: 900px;
            margin: 0 auto;
            text-align: left;
        }

        .empty-state-feature {
            padding: 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .empty-state-feature::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--primary-gradient);
        }

        .empty-state-feature:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
            border-color: #667eea;
        }

        .empty-state-feature i {
            font-size: 32px;
            margin-bottom: 15px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
        }

        .empty-state-feature h4 {
            margin: 0 0 10px 0;
            color: #1a202c;
            font-size: 18px;
            font-weight: 700;
        }

        .empty-state-feature p {
            margin: 0;
            font-size: 14px;
            color: #64748b;
            line-height: 1.7;
            font-weight: 500;
        }

        /* Plain English Summary Box */
        .summary-box {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid transparent;
        }
        
        .summary-box.critical-alert {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border-color: #feb2b2;
            color: #c53030;
        }
        
        .summary-box.warning-alert {
            background: linear-gradient(135deg, #fffaf0 0%, #feebc8 100%);
            border-color: #fbd38d;
            color: #c05621;
        }
        
        .summary-box.success-alert {
            background: linear-gradient(135deg, #f0fff4 0%, #c6f6d5 100%);
            border-color: #9ae6b4;
            color: #2f855a;
        }

        .summary-icon {
            font-size: 32px;
            min-width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.5);
            border-radius: 50%;
        }

        .summary-content h2 {
            margin: 0 0 5px 0;
            font-size: 18px;
            font-weight: 700;
        }

        .summary-content p {
            margin: 0;
            font-size: 15px;
            opacity: 0.9;
        }

        /* Tooltip helper */
        .help-tip {
            display: inline-block;
            margin-left: 5px;
            color: #94a3b8;
            cursor: help;
            font-size: 12px;
        }

        /* Fullscreen Chart Styles Removed */
        
        .chart-canvas-wrapper {
            height: 700px;
            position: relative;
            width: 100%;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="forecast-container">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Demand Forecasting</h1>
            <p>Predict future sales and know exactly when to reorder</p>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <h3><i class="fas fa-sliders-h"></i> Forecast Settings</h3>

            <form method="GET" action="" id="forecastForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="product">
                            <i class="fas fa-box"></i> Select Product
                        </label>
                        <select id="product" name="product" class="product-select" required>
                            <option value="">🔍 Search for a product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $product_id == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?> 
                                    <?php if ($p['sku']): ?>
                                        (SKU: <?php echo htmlspecialchars($p['sku']); ?>)
                                    <?php endif; ?>
                                    <?php if ($p['category']): ?>
                                        - <?php echo htmlspecialchars($p['category']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Type to search by name, SKU, or category
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="store">
                            <i class="fas fa-store"></i> Store Location
                        </label>
                        <select id="store" name="store" class="store-select">
                            <option value="0">📊 All Stores Combined</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $store_id == $s['id'] ? 'selected' : ''; ?>>
                                    📍 <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Optional: Filter by specific store
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="days">
                            <i class="fas fa-calendar-alt"></i> Forecast Period
                        </label>
                        <select id="days" name="days">
                            <option value="7" <?php echo $forecast_days == 7 ? 'selected' : ''; ?>>📅 7 Days</option>
                            <option value="14" <?php echo $forecast_days == 14 ? 'selected' : ''; ?>>📅 14 Days</option>
                            <option value="30" <?php echo $forecast_days == 30 ? 'selected' : ''; ?>>📅 30 Days</option>
                            <option value="60" <?php echo $forecast_days == 60 ? 'selected' : ''; ?>>📅 60 Days</option>
                            <option value="90" <?php echo $forecast_days == 90 ? 'selected' : ''; ?>>📅 90 Days</option>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> How far into the future?
                        </small>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; align-items: center;">
                    <button type="submit" class="btn-generate">
                        <i class="fas fa-magic"></i> Generate Forecast
                    </button>
                    <?php if ($product_id > 0): ?>
                        <a href="index.php" style="color: #6b7280; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-redo"></i> Reset & Start Over
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <script>
            // Initialize Select2 for searchable dropdowns
            $(document).ready(function() {
                $('.product-select').select2({
                    placeholder: '🔍 Search for a product by name, SKU, or category...',
                    allowClear: true,
                    width: '100%',
                    theme: 'default'
                });

                $('.store-select').select2({
                    placeholder: '📍 Select a store (optional)',
                    allowClear: false,
                    width: '100%',
                    theme: 'default'
                });
            });
        </script>
        
        <?php if ($forecast): ?>
            <!-- Plain English Summary -->
            <?php
                $summaryClass = 'success-alert';
                $summaryIcon = 'fa-check-circle';
                $summaryTitle = 'Stock Levels Healthy';
                $summaryText = 'You have enough stock to cover predicted demand for the selected period.';
                
                if ($forecast['stock_status']['status'] === 'out_of_stock') {
                    $summaryClass = 'critical-alert';
                    $summaryIcon = 'fa-exclamation-triangle';
                    $summaryTitle = 'Action Needed: Out of Stock';
                    $summaryText = 'This product is out of stock. You are missing sales opportunities.';
                } elseif ($forecast['stock_status']['status'] === 'reorder_now') {
                    $summaryClass = 'critical-alert';
                    $summaryIcon = 'fa-exclamation-circle';
                    $summaryTitle = 'Action Needed: Reorder Now';
                    $summaryText = 'Stock is critically low. Place an order immediately to avoid running out.';
                } elseif ($forecast['stock_status']['status'] === 'low_stock') {
                    $summaryClass = 'warning-alert';
                    $summaryIcon = 'fa-bell';
                    $summaryTitle = 'Warning: Low Stock';
                    $summaryText = 'Stock is getting low. You should plan a reorder soon.';
                } elseif ($forecast['stock_status']['status'] === 'overstock') {
                    $summaryClass = 'warning-alert'; // Use warning color for overstock too
                    $summaryIcon = 'fa-boxes';
                    $summaryTitle = 'Notice: Overstocked';
                    $summaryText = 'You have significantly more stock than needed. Consider running a promotion.';
                }
            ?>
            
            <div class="summary-box <?php echo $summaryClass; ?>">
                <div class="summary-icon">
                    <i class="fas <?php echo $summaryIcon; ?>"></i>
                </div>
                <div class="summary-content">
                    <h2><?php echo $summaryTitle; ?></h2>
                    <p><?php echo $summaryText; ?></p>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-box"></i> Current Stock <i class="fas fa-question-circle help-tip" title="The amount of inventory you currently have on hand."></i></h3>
                    <div class="stat-value"><?php echo number_format($forecast['current_stock']); ?></div>
                    <div class="stat-label">
                        <span class="status-badge <?php echo $forecast['stock_status']['class']; ?>">
                            <?php echo $forecast['stock_status']['label']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-chart-bar"></i> Predicted Sales <i class="fas fa-question-circle help-tip" title="How many units we expect to sell (or transfer out) in the next <?php echo $forecast_days; ?> days based on past trends."></i></h3>
                    <div class="stat-value"><?php echo number_format($forecast['total_predicted_demand']); ?></div>
                    <div class="stat-label">
                        next <?php echo $forecast_days; ?> days
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-sync"></i> Reorder At <i class="fas fa-question-circle help-tip" title="When your stock drops to this number, you should place a new order."></i></h3>
                    <div class="stat-value"><?php echo number_format($forecast['reorder_point']); ?></div>
                    <div class="stat-label">units remaining</div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-check-circle"></i> Reliability <i class="fas fa-question-circle help-tip" title="How confident the system is in this prediction based on the quality and consistency of your historical data."></i></h3>
                    <div class="stat-value">
                        <span class="status-badge <?php echo $forecast['confidence_level'] >= 70 ? 'success' : ($forecast['confidence_level'] >= 50 ? 'warning' : 'danger'); ?>">
                            <?php echo $forecast['confidence_level'] >= 80 ? 'High' : ($forecast['confidence_level'] >= 50 ? 'Medium' : 'Low'); ?>
                        </span>
                    </div>
                    <div class="stat-label">
                        Trend: <?php echo ucfirst($forecast['trend']); ?>
                    </div>
                </div>
            </div>
            
            <!-- Chart and Recommendations Layout -->
            <div class="forecast-content">
                <div class="forecast-left">
                    <!-- Chart -->
                    <div class="chart-container" id="chartContainerWrapper">
                        <h2 style="margin: 0 0 10px 0;"><i class="fas fa-chart-area"></i> Stock Countdown</h2>
                        
                        <div class="chart-canvas-wrapper">
                            <canvas id="forecastChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="forecast-right">
                    <!-- Recommendations -->
                    <div class="recommendations">
                        <h2><i class="fas fa-lightbulb"></i> Smart Suggestions</h2>
                        <div class="recommendations-list">
                            <?php foreach ($forecast['recommendations'] as $rec): ?>
                                <div class="recommendation-item <?php echo $rec['type']; ?>">
                                    <div class="rec-icon"><?php echo $rec['icon']; ?></div>
                                    <div class="rec-content">
                                        <h4><?php echo htmlspecialchars($rec['title']); ?></h4>
                                        <p><?php echo htmlspecialchars($rec['message']); ?></p>
                                        <?php if (isset($rec['action']) && $rec['action']): ?>
                                            <div style="margin-top: 8px;">
                                                <a href="<?php echo isset($rec['url']) ? $rec['url'] : '#'; ?>" 
                                                   class="btn-action" 
                                                   style="display: inline-block; padding: 4px 12px; background: white; border: 1px solid #cbd5e1; border-radius: 4px; color: #475569; font-size: 12px; font-weight: 600; text-decoration: none;">
                                                    <?php echo htmlspecialchars($rec['action']); ?> <i class="fas fa-arrow-right" style="font-size: 10px;"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- How to Read Guide (Moved here) -->
                    <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 15px; border: 1px solid #e2e8f0;">
                        <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #334155;"><i class="fas fa-info-circle" style="color: #667eea;"></i> How to read the chart:</h4>
                        <ul style="margin: 0; padding-left: 20px; color: #475569; font-size: 13px; line-height: 1.5;">
                            <li style="margin-bottom: 4px;"><strong>The Purple Line 📉</strong> shows your stock dropping over time. <span style="color: #667eea; font-weight: 600;">Follow this line!</span></li>
                            <li style="margin-bottom: 4px;"><strong>The Red Dotted Line 🔴</strong> is your "Safety Level". If the purple line drops below this, you are in danger of running out.</li>
                            <li><strong>The Bars 📊</strong> at the bottom show how many items you sold (Green) and how many we think you will sell (Blue).</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <script>
                // Render Chart with Confidence Intervals
                const ctx = document.getElementById('forecastChart').getContext('2d');
                const chartData = <?php echo json_encode($forecast['chart_data']); ?>;
                const reorderPoint = <?php echo $forecast['reorder_point']; ?>;
                
                // Create Reorder Point Line Data
                const reorderLine = chartData.labels.map(() => reorderPoint);

                const datasets = [
                    // 1. Projected Stock Level (Primary Focus)
                    {
                        label: 'Stock Remaining',
                        data: chartData.projected_stock,
                        borderColor: '#8b5cf6', // Purple
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHitRadius: 20,
                        yAxisID: 'y',
                        order: 0
                    },
                    // 2. Reorder Point Line
                    {
                        label: 'Safety Level (Reorder Point)',
                        data: reorderLine,
                        borderColor: '#ef4444', // Red
                        borderWidth: 2,
                        borderDash: [5, 5],
                        pointRadius: 0,
                        pointHitRadius: 20,
                        fill: false,
                        yAxisID: 'y',
                        order: 1
                    },
                    // 3. Historical Sales (Bars)
                    {
                        label: 'History: Sales',
                        data: chartData.historical,
                        backgroundColor: 'rgba(16, 185, 129, 0.6)', // Green
                        borderColor: '#10b981',
                        borderWidth: 1,
                        type: 'bar',
                        yAxisID: 'y1',
                        order: 2
                    },
                    // 4. Future Demand (Bars)
                    {
                        label: 'Forecast: Sales',
                        data: chartData.forecast,
                        backgroundColor: 'rgba(59, 130, 246, 0.4)', // Blue
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderDash: [2, 2],
                        type: 'bar',
                        yAxisID: 'y1',
                        order: 3
                    }
                ];
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    color: '#334155',
                                    font: {
                                        weight: 600,
                                        size: 14
                                    }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                                titleColor: '#1e293b',
                                bodyColor: '#334155',
                                borderColor: '#e2e8f0',
                                borderWidth: 1,
                                padding: 12,
                                boxPadding: 4,
                                usePointStyle: true,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed.y !== null) {
                                            label += context.parsed.y.toLocaleString();
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: '#e2e8f0',
                                    lineWidth: 1
                                },
                                ticks: {
                                    color: '#334155',
                                    font: {
                                        weight: 500,
                                        size: 12
                                    }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: '#e2e8f0',
                                    lineWidth: 1
                                },
                                ticks: {
                                    color: '#334155',
                                    font: {
                                        weight: 500,
                                        size: 12
                                    },
                                    callback: function(value) {
                                        return value.toLocaleString();
                                    }
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: {
                                    color: 'rgba(229, 231, 235, 0.5)',
                                    lineWidth: 1
                                },
                                ticks: {
                                    color: '#334155',
                                    font: {
                                        weight: 500,
                                        size: 12
                                    },
                                    callback: function(value) {
                                        return value.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            </script>
        <?php endif; ?>
        
        <!-- Page Footer -->
        <div style="margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 20px; color: #64748b; font-size: 12px;">
            <p style="margin: 0;">&copy; 2023 Inventory System. All rights reserved.</p>
            <p style="margin: 0;">Built with <i class="fas fa-heart" style="color: #ef4444;"></i> by Your Company</p>
        </div>
    </div>

    <script>
        // Close any open dropdowns when clicking outside
        $(document).mouseup(function(e) {
            var container = $(".select2-container");
            if (!container.is(e.target) && container.has(e.target).length === 0) {
                container.each(function() {
                    var $this = $(this);
                    if ($this.hasClass("select2-container--focus")) {
                        $this.select2("close");
                    }
                });
            }
        });
    </script>
</body>
</html>
