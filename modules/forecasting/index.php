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
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Modern Page Header */
        .forecast-container .page-header {
            background: white !important;
            border-radius: 24px !important;
            padding: 40px 50px !important;
            margin-bottom: 35px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1) !important;
            position: relative !important;
            overflow: hidden !important;
            border: 1px solid rgba(102, 126, 234, 0.1) !important;
            display: block !important;
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
            margin: 0 0 12px 0 !important;
            color: #1a202c !important;
            font-size: 42px !important;
            font-weight: 800 !important;
            display: flex !important;
            align-items: center !important;
            gap: 20px !important;
            letter-spacing: -0.5px !important;
        }
        
        .forecast-container .page-header h1 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 48px;
        }
        
        .forecast-container .page-header p {
            margin: 0 !important;
            color: #64748b !important;
            font-size: 18px !important;
            font-weight: 500 !important;
            padding-left: 68px !important;
        }
        
        /* Modern Filter Section */
        .forecast-container .filter-section {
            background: white !important;
            padding: 40px !important;
            border-radius: 20px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 35px !important;
            border: 1px solid rgba(102, 126, 234, 0.1) !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .forecast-container .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .forecast-container .filter-section h3 {
            margin: 0 0 30px 0 !important;
            color: #1a202c !important;
            font-size: 24px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            letter-spacing: -0.3px !important;
        }

        .forecast-container .filter-section h3 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 26px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.5fr 1fr;
            gap: 28px;
            margin-bottom: 28px;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 12px;
            color: #334155;
            font-size: 13px;
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
            padding: 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            transition: var(--transition);
            background: white;
            color: #1a202c;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .form-group select:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1), 0 4px 12px rgba(102, 126, 234, 0.2);
        }

        /* Modern Select2 Customization */
        .select2-container--default .select2-selection--single {
            height: 54px !important;
            border: 2px solid #e2e8f0 !important;
            border-radius: 14px !important;
            padding: 10px 20px !important;
            background: white !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
            transition: var(--transition) !important;
        }

        .select2-container--default .select2-selection--single:hover {
            border-color: #667eea !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15) !important;
            transform: translateY(-2px) !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 34px !important;
            color: #1a202c !important;
            font-size: 15px !important;
            font-weight: 600 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 52px !important;
            right: 20px !important;
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
        
        /* Modern Generate Button */
        .btn-generate {
            background: var(--primary-gradient);
            color: white;
            padding: 16px 40px;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            cursor: pointer;
            font-size: 17px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
            width: 100%;
            justify-content: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }

        .btn-generate::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .btn-generate:hover::before {
            left: 100%;
        }
        
        .btn-generate:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(102, 126, 234, 0.4);
        }

        .btn-generate:active {
            transform: translateY(-1px);
            box-shadow: 0 6px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-generate i {
            font-size: 20px;
        }

        /* Modern Quick Filters */
        .quick-filters {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 25px;
            padding: 24px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            align-items: center;
        }

        .quick-filter-label {
            font-weight: 700;
            color: #475569;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: flex;
            align-items: center;
            margin-right: 8px;
        }

        .quick-filter-btn {
            padding: 10px 20px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 24px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            color: #64748b;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }

        .quick-filter-btn:hover {
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }

        .quick-filter-btn.active {
            border-color: #667eea;
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.35);
        }
        
        /* Modern Stats Grid */
        .forecast-container .stats-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)) !important;
            gap: 24px !important;
            margin-bottom: 35px !important;
        }
        
        /* Modern Stat Cards with Glassmorphism */
        .forecast-container .stat-card {
            background: white !important;
            padding: 32px !important;
            border-radius: 20px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid rgba(102, 126, 234, 0.1) !important;
            position: relative !important;
            overflow: hidden !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .forecast-container .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .forecast-container .stat-card:hover {
            transform: translateY(-5px) !important;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
        }

        .forecast-container .stat-card:hover::before {
            width: 100%;
            opacity: 0.05;
        }
        
        .forecast-container .stat-card h3 {
            margin: 0 0 15px 0 !important;
            font-size: 13px !important;
            color: #64748b !important;
            text-transform: uppercase !important;
            font-weight: 700 !important;
            letter-spacing: 1px !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .forecast-container .stat-card h3 i {
            color: #667eea !important;
            font-size: 15px !important;
        }
        
        .forecast-container .stat-value {
            font-size: 42px !important;
            font-weight: 800 !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px !important;
            line-height: 1.2 !important;
        }
        
        .forecast-container .stat-label {
            font-size: 14px !important;
            color: #94a3b8 !important;
            font-weight: 500 !important;
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
        
        /* Modern Chart Container */
        .forecast-container .chart-container {
            background: white !important;
            padding: 30px !important;
            border-radius: 20px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1) !important;
            margin-bottom: 35px !important;
            border: 1px solid rgba(102, 126, 234, 0.1) !important;
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
            margin: 0 0 20px 0 !important;
            color: #1a202c !important;
            font-size: 22px !important;
            font-weight: 700 !important;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
        }

        .forecast-container .chart-container h2 i {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .forecast-container .chart-container > div {
            height: 450px !important;
            max-height: 450px !important;
            position: relative !important;
        }

        .forecast-container #forecastChart {
            height: 100% !important;
            max-height: 450px !important;
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
        
        /* Modern Recommendations Section */
        .forecast-container .recommendations {
            background: white !important;
            padding: 30px !important;
            border-radius: 20px !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1) !important;
            border: 1px solid rgba(102, 126, 234, 0.1) !important;
            position: relative !important;
            overflow: visible !important;
            height: fit-content !important;
            max-height: 550px !important;
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
        
        /* Modern Recommendation Items */
        .forecast-container .recommendation-item {
            padding: 18px !important;
            border-radius: 14px !important;
            margin-bottom: 12px !important;
            display: flex !important;
            gap: 14px !important;
            align-items: start !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            border: 2px solid transparent !important;
            position: relative !important;
            overflow: hidden !important;
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
        
        /* Modern Empty State */
        .empty-state {
            text-align: center;
            padding: 100px 40px;
            color: #64748b;
            background: white;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(102, 126, 234, 0.1);
            position: relative;
            overflow: hidden;
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
            font-size: 96px;
            margin-bottom: 30px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
        }

        .empty-state h3 {
            color: #1a202c;
            font-size: 28px;
            margin: 0 0 20px 0;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .empty-state p {
            font-size: 17px;
            margin: 0 0 40px 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.8;
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
            padding: 28px;
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

        /* Responsive Design */
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .forecast-container {
                padding: 20px 15px;
            }
            .page-header {
                padding: 30px 25px;
            }
            .page-header h1 {
                font-size: 32px;
            }
            .filter-section,
            .chart-container,
            .recommendations {
                padding: 30px 25px;
            }
            .empty-state {
                padding: 60px 30px;
            }
            .empty-state i {
                font-size: 72px;
            }
            .empty-state-features {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="forecast-container">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Demand Forecasting</h1>
            <p>Predict future demand and optimize inventory levels</p>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <h3><i class="fas fa-sliders-h"></i> Forecast Configuration</h3>
            
            <!-- Quick Filters for Forecast Period -->
            <div class="quick-filters">
                <span class="quick-filter-label"><i class="fas fa-clock"></i> Quick Period:</span>
                <button type="button" class="quick-filter-btn" data-days="7">1 Week</button>
                <button type="button" class="quick-filter-btn" data-days="14">2 Weeks</button>
                <button type="button" class="quick-filter-btn active" data-days="30">1 Month</button>
                <button type="button" class="quick-filter-btn" data-days="60">2 Months</button>
                <button type="button" class="quick-filter-btn" data-days="90">3 Months</button>
            </div>

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

                // Quick filter buttons
                $('.quick-filter-btn').on('click', function() {
                    const days = $(this).data('days');
                    $('#days').val(days);
                    
                    // Update active state
                    $('.quick-filter-btn').removeClass('active');
                    $(this).addClass('active');
                });

                // Sync dropdown with quick filters
                $('#days').on('change', function() {
                    const selectedDays = $(this).val();
                    $('.quick-filter-btn').removeClass('active');
                    $(`.quick-filter-btn[data-days="${selectedDays}"]`).addClass('active');
                });
            });
        </script>
        
        <?php if ($forecast): ?>
            <!-- Stats Summary -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-box"></i> Current Stock</h3>
                    <div class="stat-value"><?php echo number_format($forecast['current_stock']); ?></div>
                    <div class="stat-label">
                        <span class="status-badge <?php echo $forecast['stock_status']['class']; ?>">
                            <?php echo $forecast['stock_status']['label']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-chart-bar"></i> Predicted Demand</h3>
                    <div class="stat-value"><?php echo number_format($forecast['total_predicted_demand']); ?></div>
                    <div class="stat-label">
                        next <?php echo $forecast_days; ?> days
                        <?php if (isset($forecast['method_used'])): ?>
                            <br><small style="opacity: 0.7;">Method: <?php echo ucwords(str_replace('_', ' ', $forecast['method_used'])); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-sync"></i> Reorder Point</h3>
                    <div class="stat-value"><?php echo number_format($forecast['reorder_point']); ?></div>
                    <div class="stat-label">smart trigger level</div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-check-circle"></i> Forecast Quality</h3>
                    <div class="stat-value">
                        <span class="status-badge <?php echo $forecast['confidence_level'] >= 70 ? 'success' : ($forecast['confidence_level'] >= 50 ? 'warning' : 'danger'); ?>">
                            <?php echo $forecast['confidence_level']; ?>%
                        </span>
                    </div>
                    <div class="stat-label">
                        <?php if (isset($forecast['forecast_accuracy']) && $forecast['forecast_accuracy'] > 0): ?>
                            Accuracy: <?php echo round($forecast['forecast_accuracy']); ?>% |
                        <?php endif; ?>
                        Trend: <?php echo ucfirst($forecast['trend']); ?>
                        <?php if (isset($forecast['seasonality']['detected']) && $forecast['seasonality']['detected']): ?>
                            <br><small style="opacity: 0.7;">📊 Seasonality: <?php echo $forecast['seasonality']['strength']; ?>%</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Chart and Recommendations Layout -->
            <div class="forecast-content">
                <div class="forecast-left">
                    <!-- Chart -->
                    <div class="chart-container">
                        <h2><i class="fas fa-chart-area"></i> Demand Forecast Chart</h2>
                        <div style="position: relative; height: 450px; width: 100%;">
                            <canvas id="forecastChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="forecast-right">
                    <!-- Recommendations -->
                    <div class="recommendations">
                        <h2><i class="fas fa-lightbulb"></i> Recommendations</h2>
                        <div class="recommendations-list">
                            <?php foreach ($forecast['recommendations'] as $rec): ?>
                                <div class="recommendation-item <?php echo $rec['type']; ?>">
                                    <div class="rec-icon"><?php echo $rec['icon']; ?></div>
                                    <div class="rec-content">
                                        <h4><?php echo htmlspecialchars($rec['title']); ?></h4>
                                        <p><?php echo htmlspecialchars($rec['message']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
                // Render Chart with Confidence Intervals
                const ctx = document.getElementById('forecastChart').getContext('2d');
                const chartData = <?php echo json_encode($forecast['chart_data']); ?>;
                
                const datasets = [
                    {
                        label: 'Historical Sales',
                        data: chartData.historical,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        order: 2
                    },
                    {
                        label: 'Predicted Demand',
                        data: chartData.forecast,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 3,
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        order: 1
                    }
                ];
                
                // Add confidence interval bands if available
                if (chartData.upper_bound && chartData.lower_bound) {
                    datasets.push({
                        label: 'Upper Bound (95% CI)',
                        data: chartData.upper_bound,
                        borderColor: 'rgba(239, 68, 68, 0.3)',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        borderWidth: 1,
                        borderDash: [2, 2],
                        tension: 0.4,
                        fill: false,
                        pointRadius: 0,
                        order: 3
                    });
                    
                    datasets.push({
                        label: 'Lower Bound (95% CI)',
                        data: chartData.lower_bound,
                        borderColor: 'rgba(59, 130, 246, 0.3)',
                        backgroundColor: 'rgba(59, 130, 246, 0.05)',
                        borderWidth: 1,
                        borderDash: [2, 2],
                        tension: 0.4,
                        fill: false,
                        pointRadius: 0,
                        order: 3
                    });
                }
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        weight: '600'
                                    }
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 13
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Quantity',
                                    font: {
                                        size: 13,
                                        weight: '600'
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date',
                                    font: {
                                        size: 13,
                                        weight: '600'
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            </script>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <h3>Ready to Forecast Demand?</h3>
                <p>Select a product from the dropdown above to generate an intelligent demand forecast based on historical sales data and advanced algorithms.</p>
                
                <div class="empty-state-features">
                    <div class="empty-state-feature">
                        <i class="fas fa-robot"></i>
                        <h4>Smart Algorithms</h4>
                        <p>5 forecasting methods compete, best one wins automatically</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-chart-area"></i>
                        <h4>Seasonality Detection</h4>
                        <p>Identifies weekly patterns and adjusts predictions</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-bullseye"></i>
                        <h4>Confidence Intervals</h4>
                        <p>Shows upper/lower bounds with 95% confidence</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-bell"></i>
                        <h4>Smart Alerts</h4>
                        <p>Automatic reorder recommendations and warnings</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
