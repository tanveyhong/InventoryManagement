<?php
/**
 * Demand Forecasting Model
 * Clean PostgreSQL-based forecasting with no legacy dependencies
 */

class DemandForecast {
    private $db;
    
    public function __construct() {
        require_once __DIR__ . '/../../sql_db.php';
        $this->db = SQLDatabase::getInstance();
    }
    
    /**
     * Generate demand forecast for a product
     * 
     * @param int $product_id Product ID
     * @param int|null $store_id Optional store filter
     * @param int $forecast_days Number of days to forecast (default: 30)
     * @return array Forecast data with predictions and recommendations
     */
    public function forecast($product_id, $store_id = null, $forecast_days = 30) {
        // Get historical demand data (sales + transfers)
        $historical = $this->getHistoricalDemand($product_id, $store_id, 180);
        
        // Get current stock level
        $current_stock = $this->getCurrentStock($product_id, $store_id);
        
        // If no historical data, return basic forecast
        if (empty($historical)) {
            return $this->getBasicForecast($product_id, $store_id, $current_stock, $forecast_days);
        }
        
        // Fill gaps in historical data (important for time series)
        $historical = $this->fillMissingDates($historical);
        
        // Detect and handle outliers
        $historical = $this->handleOutliers($historical);
        
        // Decompose time series (trend + seasonality + residual)
        $decomposition = $this->decomposeTimeSeries($historical);
        
        // Detect seasonality patterns
        $seasonality = $this->detectSeasonality($historical);
        
        // Calculate multiple forecasting methods
        $methods = [
            'simple_average' => $this->simpleMovingAverage($historical, $forecast_days),
            'exponential_smoothing' => $this->exponentialSmoothing($historical, $forecast_days),
            'double_exponential' => $this->doubleExponentialSmoothing($historical, $forecast_days),
            'linear_regression' => $this->linearRegressionForecast($historical, $forecast_days),
            'weighted_moving_average' => $this->weightedMovingAverage($historical, $forecast_days)
        ];
        
        // Select best method based on historical accuracy
        $best_method = $this->selectBestMethod($methods, $historical);
        $predictions = $methods[$best_method]['predictions'];
        
        // Apply seasonality adjustment
        if ($seasonality['detected']) {
            $predictions = $this->applySeasonality($predictions, $seasonality);
        }
        
        // Calculate confidence intervals
        $confidence_intervals = $this->calculateConfidenceIntervals($predictions, $historical);
        
        // Calculate metrics
        $daily_average = array_sum(array_column($historical, 'quantity_sold')) / count($historical);
        $trend = $decomposition['trend_direction'];
        $volatility = $this->calculateVolatility($historical);
        $total_demand = array_sum($predictions);
        
        // Smart reorder point calculation
        $reorder_point = $this->calculateSmartReorderPoint($daily_average, $volatility, $seasonality);
        
        // Determine stock status
        $stock_status = $this->getStockStatus($current_stock, $total_demand, $reorder_point);
        
        // Calculate confidence level
        $confidence = $this->calculateAdvancedConfidence($historical, $volatility, $seasonality, $best_method);
        
        // Prepare chart data with confidence intervals
        $chart_data = $this->prepareAdvancedChartData($historical, $predictions, $confidence_intervals, $current_stock);
        
        return [
            'product_id' => $product_id,
            'store_id' => $store_id,
            'current_stock' => $current_stock,
            'daily_average' => round($daily_average, 2),
            'trend' => $trend,
            'total_predicted_demand' => $total_demand,
            'reorder_point' => $reorder_point,
            'stock_status' => $stock_status,
            'confidence_level' => $confidence,
            'predictions' => $predictions,
            'confidence_intervals' => $confidence_intervals,
            'chart_data' => $chart_data,
            'seasonality' => $seasonality,
            'method_used' => $best_method,
            'forecast_accuracy' => $methods[$best_method]['accuracy'] ?? 0,
            'recommendations' => $this->generateAdvancedRecommendations(
                $product_id,
                $store_id,
                $current_stock, 
                $total_demand, 
                $reorder_point, 
                $daily_average, 
                $seasonality, 
                $trend,
                $confidence_intervals
            ),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get historical demand data (Sales + Stock Movements)
     */
    private function getHistoricalDemand($product_id, $store_id, $days) {
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $params = [];
        
        // 1. Sales Data Query (Primary Source for Sales)
        $sales_sql = "SELECT DATE(s.created_at) as date, 
                       COALESCE(SUM(si.quantity), 0) as quantity
                FROM sales s
                INNER JOIN sale_items si ON s.id = si.sale_id
                WHERE si.product_id = ? 
                  AND s.created_at >= ?
                  AND s.payment_status = 'completed'";
        
        $params[] = $product_id;
        $params[] = $start_date;
        
        if ($store_id) {
            $sales_sql .= " AND s.store_id = ?";
            $params[] = $store_id;
        }
        $sales_sql .= " GROUP BY DATE(s.created_at)";

        // 2. Stock Movements Query (For Transfers, Adjustments, etc.)
        // We capture all OUTGOING movements that are NOT sales
        // (Since we already count sales above, we exclude movement_type = 'sale')
        // Note: quantity in stock_movements is negative for outflows, so we use ABS()
        $movements_sql = "SELECT DATE(created_at) as date, 
                          SUM(ABS(quantity)) as quantity
                          FROM stock_movements
                          WHERE product_id = ?
                          AND created_at >= ?
                          AND quantity < 0
                          AND movement_type != 'sale'
                          GROUP BY DATE(created_at)";
        
        $params[] = $product_id;
        $params[] = $start_date;

        // Combine both sources
        $sql = "SELECT date as sale_date, SUM(quantity) as quantity_sold 
                FROM (
                    ($sales_sql)
                    UNION ALL
                    ($movements_sql)
                ) as combined_demand
                GROUP BY date
                ORDER BY date ASC";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    /**
     * Get current stock level
     */
    private function getCurrentStock($product_id, $store_id) {
        if ($store_id) {
            $result = $this->db->fetch(
                "SELECT quantity FROM products WHERE id = ? AND store_id = ? AND active = true",
                [$product_id, $store_id]
            );
        } else {
            $result = $this->db->fetch(
                "SELECT SUM(quantity) as quantity FROM products WHERE id = ? AND active = true",
                [$product_id]
            );
        }
        
        return $result ? (int)$result['quantity'] : 0;
    }
    
    /**
     * Calculate daily average sales
     */
    private function calculateDailyAverage($historical) {
        if (empty($historical)) return 0;
        
        $total = array_sum(array_column($historical, 'quantity_sold'));
        return $total / count($historical);
    }
    
    /**
     * Calculate sales trend (slope)
     */
    private function calculateTrend($historical) {
        $count = count($historical);
        if ($count < 2) return 'stable';
        
        $quantities = array_column($historical, 'quantity_sold');
        
        // Simple linear regression
        $x_values = range(1, $count);
        $y_values = $quantities;
        
        $x_mean = array_sum($x_values) / $count;
        $y_mean = array_sum($y_values) / $count;
        
        $numerator = 0;
        $denominator = 0;
        
        for ($i = 0; $i < $count; $i++) {
            $numerator += ($x_values[$i] - $x_mean) * ($y_values[$i] - $y_mean);
            $denominator += pow($x_values[$i] - $x_mean, 2);
        }
        
        $slope = $denominator != 0 ? $numerator / $denominator : 0;
        
        // Classify trend
        if ($slope > 0.5) return 'increasing';
        if ($slope < -0.5) return 'decreasing';
        return 'stable';
    }
    
    /**
     * Calculate volatility (coefficient of variation)
     */
    private function calculateVolatility($historical) {
        if (empty($historical)) return 0;
        
        $quantities = array_column($historical, 'quantity_sold');
        $mean = array_sum($quantities) / count($quantities);
        
        if ($mean == 0) return 0;
        
        $variance = 0;
        foreach ($quantities as $qty) {
            $variance += pow($qty - $mean, 2);
        }
        
        $std_dev = sqrt($variance / count($quantities));
        
        return $std_dev / $mean; // Coefficient of variation
    }
    
    /**
     * Generate daily predictions
     */
    private function generatePredictions($daily_average, $trend, $days) {
        $predictions = [];
        $base_demand = max(0, $daily_average);
        
        // Adjust for trend
        $trend_multiplier = 1;
        if ($trend === 'increasing') {
            $trend_multiplier = 1.1;
        } elseif ($trend === 'decreasing') {
            $trend_multiplier = 0.9;
        }
        
        for ($i = 0; $i < $days; $i++) {
            $prediction = round($base_demand * $trend_multiplier);
            $predictions[] = max(0, $prediction);
        }
        
        return $predictions;
    }
    
    /**
     * Determine stock status
     */
    private function getStockStatus($current_stock, $predicted_demand, $reorder_point) {
        if ($current_stock <= 0) {
            return ['status' => 'out_of_stock', 'label' => 'Out of Stock', 'class' => 'danger'];
        } elseif ($current_stock <= $reorder_point) {
            return ['status' => 'reorder_now', 'label' => 'Reorder Now', 'class' => 'danger'];
        } elseif ($current_stock < $predicted_demand) {
            return ['status' => 'low_stock', 'label' => 'Low Stock', 'class' => 'warning'];
        } elseif ($current_stock > $predicted_demand * 2) {
            return ['status' => 'overstock', 'label' => 'Overstock', 'class' => 'info'];
        } else {
            return ['status' => 'good', 'label' => 'Good', 'class' => 'success'];
        }
    }
    
    /**
     * Calculate confidence level (0-100)
     */
    private function calculateConfidence($data_points, $volatility) {
        $confidence = 70; // Base confidence
        
        // More data = higher confidence
        if ($data_points >= 60) {
            $confidence += 20;
        } elseif ($data_points >= 30) {
            $confidence += 10;
        } elseif ($data_points < 14) {
            $confidence -= 20;
        }
        
        // Lower volatility = higher confidence
        if ($volatility < 0.3) {
            $confidence += 10;
        } elseif ($volatility > 0.7) {
            $confidence -= 20;
        }
        
        return max(0, min(100, $confidence));
    }
    
    /**
     * Prepare data for Chart.js
     */
    private function prepareChartData($historical, $predictions) {
        $labels = [];
        $historical_data = [];
        $forecast_data = [];
        
        // Historical data
        foreach ($historical as $data) {
            $labels[] = date('M j', strtotime($data['sale_date']));
            $historical_data[] = (int)$data['quantity_sold'];
            $forecast_data[] = null;
        }
        
        // Future predictions
        $forecast_days = count($predictions);
        for ($i = 1; $i <= $forecast_days; $i++) {
            $labels[] = date('M j', strtotime("+{$i} days"));
            $historical_data[] = null;
            $forecast_data[] = $predictions[$i - 1];
        }
        
        return [
            'labels' => $labels,
            'historical' => $historical_data,
            'forecast' => $forecast_data
        ];
    }
    
    /**
     * Generate recommendations
     */
    private function generateRecommendations($current_stock, $predicted_demand, $reorder_point, $daily_average) {
        $recommendations = [];
        
        // Critical: Out of stock
        if ($current_stock <= 0) {
            $recommendations[] = [
                'type' => 'critical',
                'icon' => '🚨',
                'title' => 'Out of Stock',
                'message' => 'Product is currently out of stock. Immediate reorder required!',
                'action' => 'Order Now'
            ];
        }
        
        // High Priority: At or below reorder point
        elseif ($current_stock <= $reorder_point) {
            $order_quantity = ceil(($predicted_demand - $current_stock) * 1.2);
            $recommendations[] = [
                'type' => 'high',
                'icon' => '⚠️',
                'title' => 'Reorder Required',
                'message' => "Stock is at reorder point. Recommended order quantity: {$order_quantity} units",
                'action' => 'Create Purchase Order'
            ];
        }
        
        // Medium Priority: Below predicted demand
        elseif ($current_stock < $predicted_demand) {
            $shortage = $predicted_demand - $current_stock;
            $days_until_stockout = ceil($current_stock / max(1, $daily_average));
            $recommendations[] = [
                'type' => 'medium',
                'icon' => '📉',
                'title' => 'Potential Shortage',
                'message' => "Current stock may not meet predicted demand. Potential shortage: {$shortage} units. Estimated stockout in {$days_until_stockout} days.",
                'action' => 'Plan Reorder'
            ];
        }
        
        // Low Priority: Overstock
        elseif ($current_stock > $predicted_demand * 2.5) {
            $excess = $current_stock - ($predicted_demand * 2);
            $recommendations[] = [
                'type' => 'low',
                'icon' => '📦',
                'title' => 'Overstock Alert',
                'message' => "Current stock significantly exceeds predicted demand. Excess inventory: {$excess} units. Consider promotions or reducing future orders.",
                'action' => 'Review Inventory'
            ];
        }
        
        // Good status
        else {
            $recommendations[] = [
                'type' => 'success',
                'icon' => '✅',
                'title' => 'Stock Level Optimal',
                'message' => 'Current stock levels are well-balanced for predicted demand.',
                'action' => null
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Get basic forecast when no historical data exists
     */
    private function getBasicForecast($product_id, $store_id, $current_stock, $days) {
        $daily_estimate = 1; // Conservative estimate
        
        return [
            'product_id' => $product_id,
            'store_id' => $store_id,
            'current_stock' => $current_stock,
            'daily_average' => $daily_estimate,
            'trend' => 'unknown',
            'total_predicted_demand' => $daily_estimate * $days,
            'reorder_point' => 10,
            'stock_status' => $this->getStockStatus($current_stock, $daily_estimate * $days, 10),
            'confidence_level' => 20,
            'predictions' => array_fill(0, $days, $daily_estimate),
            'confidence_intervals' => ['lower' => array_fill(0, $days, 0), 'upper' => array_fill(0, $days, 2)],
            'chart_data' => [
                'labels' => array_map(fn($i) => date('M j', strtotime("+{$i} days")), range(1, $days)),
                'historical' => [],
                'forecast' => array_fill(0, $days, $daily_estimate),
                'lower_bound' => array_fill(0, $days, 0),
                'upper_bound' => array_fill(0, $days, 2)
            ],
            'seasonality' => ['detected' => false, 'pattern' => 'none'],
            'method_used' => 'basic_estimate',
            'recommendations' => [[
                'type' => 'info',
                'icon' => 'ℹ️',
                'title' => 'Insufficient Data',
                'message' => 'No historical sales data available. Forecast based on conservative estimates. Start recording sales to improve accuracy.',
                'action' => null
            ]],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    // ========== ADVANCED FORECASTING METHODS ==========
    
    /**
     * Fill missing dates in historical data
     */
    private function fillMissingDates($historical) {
        if (empty($historical)) return [];
        
        $filled = [];
        $start_date = strtotime($historical[0]['sale_date']);
        $end_date = strtotime($historical[count($historical) - 1]['sale_date']);
        
        $existing_data = [];
        foreach ($historical as $row) {
            $existing_data[$row['sale_date']] = (int)$row['quantity_sold'];
        }
        
        for ($date = $start_date; $date <= $end_date; $date += 86400) {
            $date_str = date('Y-m-d', $date);
            $filled[] = [
                'sale_date' => $date_str,
                'quantity_sold' => $existing_data[$date_str] ?? 0
            ];
        }
        
        return $filled;
    }
    
    /**
     * Detect and handle outliers using IQR method
     */
    private function handleOutliers($historical) {
        $quantities = array_column($historical, 'quantity_sold');
        sort($quantities);
        
        $count = count($quantities);
        if ($count < 4) return $historical;
        
        $q1 = $quantities[(int)($count * 0.25)];
        $q3 = $quantities[(int)($count * 0.75)];
        $iqr = $q3 - $q1;
        
        $lower_bound = $q1 - (1.5 * $iqr);
        $upper_bound = $q3 + (1.5 * $iqr);
        
        // Cap outliers instead of removing them
        foreach ($historical as &$row) {
            if ($row['quantity_sold'] < $lower_bound) {
                $row['quantity_sold'] = $lower_bound;
            } elseif ($row['quantity_sold'] > $upper_bound) {
                $row['quantity_sold'] = $upper_bound;
            }
        }
        
        return $historical;
    }
    
    /**
     * Decompose time series into trend, seasonality, and residual
     */
    private function decomposeTimeSeries($historical) {
        $quantities = array_column($historical, 'quantity_sold');
        $n = count($quantities);
        
        // Calculate trend using moving average
        $window = min(7, (int)($n / 3));
        $trend = [];
        
        for ($i = 0; $i < $n; $i++) {
            $start = max(0, $i - (int)($window / 2));
            $end = min($n - 1, $i + (int)($window / 2));
            $sum = 0;
            $count = 0;
            
            for ($j = $start; $j <= $end; $j++) {
                $sum += $quantities[$j];
                $count++;
            }
            
            $trend[] = $count > 0 ? $sum / $count : 0;
        }
        
        // Determine trend direction
        $trend_slope = 0;
        if ($n > 1) {
            $trend_slope = ($trend[$n - 1] - $trend[0]) / $n;
        }
        
        $trend_direction = 'stable';
        if ($trend_slope > 0.3) {
            $trend_direction = 'increasing';
        } elseif ($trend_slope < -0.3) {
            $trend_direction = 'decreasing';
        }
        
        return [
            'trend' => $trend,
            'trend_direction' => $trend_direction,
            'trend_slope' => $trend_slope
        ];
    }
    
    /**
     * Detect seasonality patterns (day of week, weekly, monthly)
     */
    private function detectSeasonality($historical) {
        if (count($historical) < 14) {
            return ['detected' => false, 'pattern' => 'none', 'strength' => 0];
        }
        
        // Day of week analysis
        $day_patterns = array_fill(0, 7, []);
        
        foreach ($historical as $row) {
            $day_of_week = date('N', strtotime($row['sale_date'])) - 1; // 0 = Monday
            $day_patterns[$day_of_week][] = (int)$row['quantity_sold'];
        }
        
        // Calculate average for each day
        $day_averages = [];
        foreach ($day_patterns as $day => $values) {
            if (empty($values)) {
                $day_averages[$day] = 0;
            } else {
                $day_averages[$day] = array_sum($values) / count($values);
            }
        }
        
        // Calculate seasonality strength
        $overall_avg = array_sum($day_averages) / count($day_averages);
        $variance = 0;
        
        foreach ($day_averages as $avg) {
            $variance += pow($avg - $overall_avg, 2);
        }
        
        $variance = $variance / count($day_averages);
        $strength = $overall_avg > 0 ? sqrt($variance) / $overall_avg : 0;
        
        $detected = $strength > 0.2; // 20% variation indicates seasonality
        
        return [
            'detected' => $detected,
            'pattern' => $detected ? 'weekly' : 'none',
            'strength' => round($strength * 100, 1),
            'day_factors' => $this->normalizeSeasonalFactors($day_averages)
        ];
    }
    
    /**
     * Normalize seasonal factors
     */
    private function normalizeSeasonalFactors($day_averages) {
        $avg = array_sum($day_averages) / count($day_averages);
        
        if ($avg == 0) {
            return array_fill(0, 7, 1);
        }
        
        $factors = [];
        foreach ($day_averages as $day_avg) {
            $factors[] = $day_avg / $avg;
        }
        
        return $factors;
    }
    
    /**
     * Simple Moving Average Forecast
     */
    private function simpleMovingAverage($historical, $forecast_days) {
        $window = min(14, count($historical));
        $recent = array_slice($historical, -$window);
        $avg = array_sum(array_column($recent, 'quantity_sold')) / count($recent);
        
        return [
            'predictions' => array_fill(0, $forecast_days, round($avg)),
            'method' => 'Simple Moving Average'
        ];
    }
    
    /**
     * Exponential Smoothing (Single)
     */
    private function exponentialSmoothing($historical, $forecast_days, $alpha = 0.3) {
        $quantities = array_column($historical, 'quantity_sold');
        
        // Initialize with first value
        $smoothed = [$quantities[0]];
        
        // Apply exponential smoothing
        for ($i = 1; $i < count($quantities); $i++) {
            $smoothed[] = $alpha * $quantities[$i] + (1 - $alpha) * $smoothed[$i - 1];
        }
        
        $last_value = end($smoothed);
        
        return [
            'predictions' => array_fill(0, $forecast_days, round($last_value)),
            'method' => 'Exponential Smoothing'
        ];
    }
    
    /**
     * Double Exponential Smoothing (Holt's method - handles trend)
     */
    private function doubleExponentialSmoothing($historical, $forecast_days, $alpha = 0.3, $beta = 0.1) {
        $quantities = array_column($historical, 'quantity_sold');
        $n = count($quantities);
        
        if ($n < 2) {
            return $this->exponentialSmoothing($historical, $forecast_days);
        }
        
        // Initialize
        $level = [$quantities[0]];
        $trend = [$quantities[1] - $quantities[0]];
        
        // Apply double exponential smoothing
        for ($i = 1; $i < $n; $i++) {
            $prev_level = $level[$i - 1];
            $prev_trend = $trend[$i - 1];
            
            $new_level = $alpha * $quantities[$i] + (1 - $alpha) * ($prev_level + $prev_trend);
            $new_trend = $beta * ($new_level - $prev_level) + (1 - $beta) * $prev_trend;
            
            $level[] = $new_level;
            $trend[] = $new_trend;
        }
        
        // Generate forecasts
        $predictions = [];
        $last_level = end($level);
        $last_trend = end($trend);
        
        for ($i = 1; $i <= $forecast_days; $i++) {
            $predictions[] = max(0, round($last_level + $i * $last_trend));
        }
        
        return [
            'predictions' => $predictions,
            'method' => 'Double Exponential Smoothing'
        ];
    }
    
    /**
     * Linear Regression Forecast
     */
    private function linearRegressionForecast($historical, $forecast_days) {
        $n = count($historical);
        $x_values = range(1, $n);
        $y_values = array_column($historical, 'quantity_sold');
        
        // Calculate regression coefficients
        $x_mean = array_sum($x_values) / $n;
        $y_mean = array_sum($y_values) / $n;
        
        $numerator = 0;
        $denominator = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $numerator += ($x_values[$i] - $x_mean) * ($y_values[$i] - $y_mean);
            $denominator += pow($x_values[$i] - $x_mean, 2);
        }
        
        $slope = $denominator != 0 ? $numerator / $denominator : 0;
        $intercept = $y_mean - $slope * $x_mean;
        
        // Generate predictions
        $predictions = [];
        for ($i = 1; $i <= $forecast_days; $i++) {
            $x = $n + $i;
            $predictions[] = max(0, round($slope * $x + $intercept));
        }
        
        return [
            'predictions' => $predictions,
            'method' => 'Linear Regression',
            'slope' => $slope,
            'intercept' => $intercept
        ];
    }
    
    /**
     * Weighted Moving Average (recent data weighted more)
     */
    private function weightedMovingAverage($historical, $forecast_days) {
        $window = min(14, count($historical));
        $recent = array_slice($historical, -$window);
        
        $weighted_sum = 0;
        $weight_total = 0;
        
        foreach ($recent as $i => $row) {
            $weight = $i + 1; // Linear weights
            $weighted_sum += $row['quantity_sold'] * $weight;
            $weight_total += $weight;
        }
        
        $avg = $weight_total > 0 ? $weighted_sum / $weight_total : 0;
        
        return [
            'predictions' => array_fill(0, $forecast_days, round($avg)),
            'method' => 'Weighted Moving Average'
        ];
    }
    
    /**
     * Select best forecasting method based on historical accuracy
     */
    private function selectBestMethod($methods, $historical) {
        if (count($historical) < 14) {
            return 'exponential_smoothing'; // Default for limited data
        }
        
        // Use last 7 days as test set
        $test_size = min(7, (int)(count($historical) * 0.2));
        $train = array_slice($historical, 0, -$test_size);
        $test = array_slice($historical, -$test_size);
        
        $accuracies = [];
        
        foreach ($methods as $name => $result) {
            // Re-run method on training data
            $test_predictions = array_slice($result['predictions'], 0, $test_size);
            
            // Calculate MAPE (Mean Absolute Percentage Error)
            $errors = [];
            for ($i = 0; $i < count($test); $i++) {
                $actual = $test[$i]['quantity_sold'];
                $predicted = $test_predictions[$i] ?? $result['predictions'][0];
                
                if ($actual > 0) {
                    $errors[] = abs(($actual - $predicted) / $actual);
                }
            }
            
            $mape = !empty($errors) ? array_sum($errors) / count($errors) : 1;
            $accuracy = max(0, (1 - $mape) * 100);
            
            $methods[$name]['accuracy'] = round($accuracy, 1);
            $accuracies[$name] = $accuracy;
        }
        
        // Return method with highest accuracy
        arsort($accuracies);
        return array_key_first($accuracies);
    }
    
    /**
     * Apply seasonality adjustment to predictions
     */
    private function applySeasonality($predictions, $seasonality) {
        if (!$seasonality['detected']) {
            return $predictions;
        }
        
        $adjusted = [];
        $factors = $seasonality['day_factors'];
        
        foreach ($predictions as $i => $value) {
            $day_of_week = (date('N') + $i) % 7;
            $factor = $factors[$day_of_week] ?? 1;
            $adjusted[] = round($value * $factor);
        }
        
        return $adjusted;
    }
    
    /**
     * Calculate confidence intervals
     */
    private function calculateConfidenceIntervals($predictions, $historical) {
        $quantities = array_column($historical, 'quantity_sold');
        $std_dev = $this->standardDeviation($quantities);
        
        $lower = [];
        $upper = [];
        
        foreach ($predictions as $pred) {
            $lower[] = max(0, round($pred - 1.96 * $std_dev)); // 95% confidence
            $upper[] = round($pred + 1.96 * $std_dev);
        }
        
        return [
            'lower' => $lower,
            'upper' => $upper
        ];
    }
    
    /**
     * Standard deviation helper
     */
    private function standardDeviation($values) {
        $mean = array_sum($values) / count($values);
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return sqrt($variance / count($values));
    }
    
    /**
     * Smart reorder point with seasonality consideration
     */
    private function calculateSmartReorderPoint($daily_average, $volatility, $seasonality) {
        $lead_time_days = 7;
        
        // Adjust safety stock based on volatility and seasonality
        $base_safety = 1.5;
        
        if ($volatility > 0.5) {
            $base_safety = 2.0; // Higher volatility = more safety stock
        }
        
        if ($seasonality['detected'] && $seasonality['strength'] > 30) {
            $base_safety *= 1.2; // Seasonal patterns need extra buffer
        }
        
        return max(5, ceil($daily_average * $lead_time_days * $base_safety));
    }
    
    /**
     * Advanced confidence calculation
     */
    private function calculateAdvancedConfidence($historical, $volatility, $seasonality, $method) {
        $confidence = 60; // Base confidence
        
        // Data quantity
        $data_points = count($historical);
        if ($data_points >= 90) {
            $confidence += 25;
        } elseif ($data_points >= 60) {
            $confidence += 20;
        } elseif ($data_points >= 30) {
            $confidence += 10;
        } else {
            $confidence -= 10;
        }
        
        // Volatility impact
        if ($volatility < 0.2) {
            $confidence += 10;
        } elseif ($volatility > 0.6) {
            $confidence -= 15;
        }
        
        // Seasonality detection
        if ($seasonality['detected']) {
            $confidence += 5;
        }
        
        // Method-specific adjustments
        if ($method === 'double_exponential') {
            $confidence += 5; // Best for trending data
        } elseif ($method === 'linear_regression') {
            $confidence += 3;
        }
        
        return max(0, min(100, $confidence));
    }
    
    /**
     * Prepare advanced chart data with confidence intervals and stock projection
     */
    private function prepareAdvancedChartData($historical, $predictions, $confidence_intervals, $current_stock) {
        $labels = [];
        $historical_data = [];
        $forecast_data = [];
        $lower_bound = [];
        $upper_bound = [];
        $projected_stock = [];
        
        // Historical data
        foreach ($historical as $data) {
            $labels[] = date('M j', strtotime($data['sale_date']));
            $historical_data[] = (int)$data['quantity_sold'];
            $forecast_data[] = null;
            $lower_bound[] = null;
            $upper_bound[] = null;
            $projected_stock[] = null;
        }
        
        // Future predictions with confidence intervals
        $running_stock = $current_stock;
        $forecast_days = count($predictions);
        
        for ($i = 1; $i <= $forecast_days; $i++) {
            $labels[] = date('M j', strtotime("+{$i} days"));
            $historical_data[] = null;
            
            $daily_demand = $predictions[$i - 1];
            $forecast_data[] = $daily_demand;
            
            $lower_bound[] = $confidence_intervals['lower'][$i - 1];
            $upper_bound[] = $confidence_intervals['upper'][$i - 1];
            
            // Calculate projected stock
            $running_stock -= $daily_demand;
            $projected_stock[] = $running_stock; // Allow negative to show shortage
        }
        
        return [
            'labels' => $labels,
            'historical' => $historical_data,
            'forecast' => $forecast_data,
            'lower_bound' => $lower_bound,
            'upper_bound' => $upper_bound,
            'projected_stock' => $projected_stock
        ];
    }
    
    /**
     * Generate advanced recommendations
     */
    private function generateAdvancedRecommendations($product_id, $store_id, $current_stock, $predicted_demand, $reorder_point, $daily_average, $seasonality, $trend, $confidence_intervals) {
        $recommendations = [];
        
        // Check Warehouse Stock (for Transfer recommendation)
        $warehouse_stock = 0;
        if ($store_id) {
            // If we are forecasting for a specific store, check if warehouse has stock
            // We need to find the warehouse product ID (same SKU, store_id IS NULL)
            $product = $this->db->fetch("SELECT sku FROM products WHERE id = ?", [$product_id]);
            if ($product && $product['sku']) {
                // Logic to find base SKU (stripping store suffixes if any)
                $sku = $product['sku'];
                // Simplified SKU matching logic for warehouse
                $warehouse_prod = $this->db->fetch("SELECT quantity FROM products WHERE sku = ? AND store_id IS NULL", [$sku]);
                if ($warehouse_prod) {
                    $warehouse_stock = (int)$warehouse_prod['quantity'];
                }
            }
        }

        // Critical: Out of stock
        if ($current_stock <= 0) {
            $rec = [
                'type' => 'critical',
                'icon' => '🚨',
                'title' => 'Out of Stock - Immediate Action Required',
                'message' => 'Product is currently out of stock. Lost sales opportunity!',
                'action' => null
            ];

            // Smart Action Logic
            if ($store_id && $warehouse_stock > 0) {
                $rec['message'] .= " Warehouse has {$warehouse_stock} units available.";
                $rec['action'] = 'Transfer from Warehouse';
                $rec['url'] = '../stock/transfer_from_warehouse.php?product_id=' . $product_id;
            } else {
                $rec['message'] .= " Order immediately.";
                $rec['action'] = 'Order from Supplier';
                $rec['url'] = '../purchase_orders/create.php?product_id=' . $product_id;
            }
            $recommendations[] = $rec;
        }
        
        // High Priority: At or below reorder point
        elseif ($current_stock <= $reorder_point) {
            $order_quantity = ceil(($predicted_demand - $current_stock) * 1.3);
            $max_demand = end($confidence_intervals['upper']);
            
            $rec = [
                'type' => 'high',
                'icon' => '⚠️',
                'title' => 'Reorder Point Reached',
                'message' => "Stock is at reorder point. Recommended: {$order_quantity} units.",
                'action' => null
            ];

            // Smart Action Logic
            if ($store_id && $warehouse_stock >= $order_quantity) {
                $rec['message'] .= " Warehouse has enough stock ({$warehouse_stock}).";
                $rec['action'] = 'Transfer from Warehouse';
                $rec['url'] = '../stock/transfer_from_warehouse.php?product_id=' . $product_id;
            } elseif ($store_id && $warehouse_stock > 0) {
                $rec['message'] .= " Warehouse has partial stock ({$warehouse_stock}). Transfer what you can, order the rest.";
                $rec['action'] = 'Transfer & Order';
                $rec['url'] = '../stock/transfer_from_warehouse.php?product_id=' . $product_id;
            } else {
                $rec['action'] = 'Order from Supplier';
                $rec['url'] = '../purchase_orders/create.php?product_id=' . $product_id;
            }
            $recommendations[] = $rec;
        }
        
        // Medium Priority: Below predicted demand
        elseif ($current_stock < $predicted_demand) {
            $shortage = $predicted_demand - $current_stock;
            $days_until_stockout = ceil($current_stock / max(1, $daily_average));
            
            $rec = [
                'type' => 'medium',
                'icon' => '📉',
                'title' => 'Potential Shortage Ahead',
                'message' => "Potential shortage: {$shortage} units. Stockout in {$days_until_stockout} days.",
                'action' => 'Plan Reorder'
            ];

             if ($store_id && $warehouse_stock > 0) {
                $rec['action'] = 'Check Warehouse';
                $rec['url'] = '../stock/transfer_from_warehouse.php?product_id=' . $product_id;
            } else {
                $rec['action'] = 'Plan Purchase Order';
                $rec['url'] = '../purchase_orders/create.php?product_id=' . $product_id;
            }
            $recommendations[] = $rec;
        }
        
        // Low Priority: Overstock
        elseif ($current_stock > $predicted_demand * 2.5) {
            $excess = $current_stock - ($predicted_demand * 2);
            $days_of_stock = ceil($current_stock / max(1, $daily_average));
            
            $recommendations[] = [
                'type' => 'low',
                'icon' => '📦',
                'title' => 'Overstock Detected',
                'message' => "Excess: {$excess} units ({$days_of_stock} days). Consider promotions.",
                'action' => 'Manual Adjustment', // If they want to write off or move stock
                'url' => '../stock/adjust.php?product_id=' . $product_id
            ];
        }
        
        // Good status
        else {
            $days_of_stock = ceil($current_stock / max(1, $daily_average));
            $recommendations[] = [
                'type' => 'success',
                'icon' => '✅',
                'title' => 'Stock Level Optimal',
                'message' => "Current stock levels are well-balanced. ~{$days_of_stock} days of stock.",
                'action' => null
            ];
        }
        
        // Seasonality insights
        if ($seasonality['detected']) {
            $recommendations[] = [
                'type' => 'info',
                'icon' => '📊',
                'title' => 'Seasonal Pattern Detected',
                'message' => "Sales show {$seasonality['pattern']} seasonality (strength: {$seasonality['strength']}%).",
                'action' => null
            ];
        }
        
        // Trend insights
        if ($trend === 'increasing') {
            $recommendations[] = [
                'type' => 'info',
                'icon' => '📈',
                'title' => 'Growing Demand Trend',
                'message' => 'Sales are trending upward. Consider increasing order quantities.',
                'action' => null
            ];
        } elseif ($trend === 'decreasing') {
            $recommendations[] = [
                'type' => 'info',
                'icon' => '📉',
                'title' => 'Declining Demand Trend',
                'message' => 'Sales are trending downward. Monitor closely.',
                'action' => null
            ];
        }
        
        return $recommendations;
    }
}
