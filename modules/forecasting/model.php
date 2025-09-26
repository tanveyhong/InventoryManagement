<?php
// Forecasting Model Class with Redis Caching
require_once '../../config.php';
require_once '../../db.php';
require_once '../../redis.php';

class ForecastingModel {
    private $db;
    private $redis;
    
    public function __construct() {
        $this->db = getDB();
        $this->redis = new RedisConnection();
    }
    
    /**
     * Generate demand forecast for a product with Redis caching
     */
    public function generateForecast($product_id, $store_id = null, $days = 30) {
        // Check Redis cache first
        $cache_key = "forecast:{$product_id}:" . ($store_id ?? 'all') . ":{$days}";
        $cached_forecast = $this->redis->get($cache_key);
        
        if ($cached_forecast) {
            return json_decode($cached_forecast, true);
        }
        
        // Get historical sales data
        $historical_data = $this->getHistoricalSales($product_id, $store_id, 90); // Last 90 days
        $current_stock = $this->getCurrentStock($product_id, $store_id);
        
        if (empty($historical_data)) {
            $forecast = $this->getDefaultForecast($product_id, $store_id, $days, $current_stock);
        } else {
            // Calculate forecast using different methods
            $moving_average = $this->calculateMovingAverage($historical_data, 7);
            $trend_analysis = $this->calculateTrend($historical_data);
            $seasonal_analysis = $this->calculateSeasonality($historical_data);
            
            // Generate forecast
            $forecast_data = $this->generateForecastData($historical_data, $days, $moving_average, $trend_analysis, $seasonal_analysis);
            
            // Calculate metrics
            $total_predicted_demand = array_sum($forecast_data['predictions']);
            $reorder_point = $this->calculateReorderPoint($historical_data, $days);
            $stock_status = $this->determineStockStatus($current_stock, $total_predicted_demand, $reorder_point);
            
            $forecast = [
                'current_stock' => $current_stock,
                'total_predicted_demand' => $total_predicted_demand,
                'daily_predictions' => $forecast_data['predictions'],
                'reorder_point' => $reorder_point,
                'stock_status' => $stock_status,
                'confidence_level' => $forecast_data['confidence'],
                'chart_data' => $this->prepareChartData($historical_data, $forecast_data['predictions'], $days),
                'cached_at' => time()
            ];
        }
        
        // Cache the result for 1 hour (3600 seconds)
        $this->redis->setex($cache_key, 3600, json_encode($forecast));
        
        return $forecast;
    }
    
    /**
     * Generate recommendations based on forecast data
     */
    public function generateRecommendations($product_id, $store_id, $forecast_data) {
        $recommendations = [];
        $current_stock = $forecast_data['current_stock'];
        $predicted_demand = $forecast_data['total_predicted_demand'];
        $reorder_point = $forecast_data['reorder_point'];
        
        // Get product details
        $product = $this->db->fetch("SELECT * FROM products WHERE id = ?", [$product_id]);
        
        // Stock level recommendations
        if ($current_stock <= $reorder_point) {
            $recommendations[] = [
                'type' => 'reorder',
                'priority' => 'high',
                'title' => 'Reorder Required',
                'message' => 'Current stock is at or below the reorder point. Consider placing an order soon.',
                'action' => [
                    'text' => 'Create Purchase Order',
                    'url' => '../stock/add.php?product_id=' . $product_id
                ]
            ];
        }
        
        if ($current_stock < $predicted_demand) {
            $shortage = $predicted_demand - $current_stock;
            $recommendations[] = [
                'type' => 'warning',
                'priority' => 'medium',
                'title' => 'Potential Stockout',
                'message' => "Predicted demand ({$predicted_demand}) exceeds current stock. You may run short by {$shortage} units."
            ];
        }
        
        // Overstock recommendations
        if ($current_stock > ($predicted_demand * 2)) {
            $recommendations[] = [
                'type' => 'info',
                'priority' => 'low',
                'title' => 'Overstock Alert',
                'message' => 'Current stock levels are significantly higher than predicted demand. Consider reducing future orders.'
            ];
        }
        
        // Seasonal recommendations
        $seasonal_trend = $this->getSeasonalTrend($product_id);
        if ($seasonal_trend) {
            $recommendations[] = [
                'type' => 'info',
                'priority' => 'medium',
                'title' => 'Seasonal Trend Detected',
                'message' => $seasonal_trend['message']
            ];
        }
        
        return $recommendations;
    }
    
    // Private helper methods
    
    private function getHistoricalSales($product_id, $store_id = null, $days = 90) {
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $sql = "SELECT DATE(s.created_at) as sale_date, SUM(si.quantity) as total_quantity
                FROM sales s
                JOIN sale_items si ON s.id = si.sale_id
                WHERE si.product_id = ? AND s.created_at >= ?";
        
        $params = [$product_id, $start_date];
        
        if ($store_id) {
            $sql .= " AND s.store_id = ?";
            $params[] = $store_id;
        }
        
        $sql .= " GROUP BY DATE(s.created_at) ORDER BY sale_date";
        
        return $this->db->fetchAll($sql, $params);
    }
    
    private function getCurrentStock($product_id, $store_id = null) {
        if ($store_id) {
            $result = $this->db->fetch("SELECT quantity FROM products WHERE id = ? AND store_id = ?", [$product_id, $store_id]);
        } else {
            $result = $this->db->fetch("SELECT SUM(quantity) as quantity FROM products WHERE id = ? OR (name = (SELECT name FROM products WHERE id = ?) AND active = 1)", [$product_id, $product_id]);
        }
        
        return $result ? $result['quantity'] : 0;
    }
    
    private function calculateMovingAverage($historical_data, $window = 7) {
        if (count($historical_data) < $window) {
            return 0;
        }
        
        $recent_sales = array_slice($historical_data, -$window);
        $total = array_sum(array_column($recent_sales, 'total_quantity'));
        
        return $total / count($recent_sales);
    }
    
    private function calculateTrend($historical_data) {
        if (count($historical_data) < 2) {
            return 0;
        }
        
        $x_values = range(1, count($historical_data));
        $y_values = array_column($historical_data, 'total_quantity');
        
        // Simple linear regression
        $n = count($historical_data);
        $sum_x = array_sum($x_values);
        $sum_y = array_sum($y_values);
        $sum_xy = 0;
        $sum_xx = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sum_xy += $x_values[$i] * $y_values[$i];
            $sum_xx += $x_values[$i] * $x_values[$i];
        }
        
        $slope = ($n * $sum_xy - $sum_x * $sum_y) / ($n * $sum_xx - $sum_x * $sum_x);
        
        return $slope;
    }
    
    private function calculateSeasonality($historical_data) {
        // Simple day-of-week seasonality
        $day_totals = array_fill(0, 7, 0);
        $day_counts = array_fill(0, 7, 0);
        
        foreach ($historical_data as $data) {
            $day_of_week = date('w', strtotime($data['sale_date']));
            $day_totals[$day_of_week] += $data['total_quantity'];
            $day_counts[$day_of_week]++;
        }
        
        $day_averages = [];
        for ($i = 0; $i < 7; $i++) {
            $day_averages[$i] = $day_counts[$i] > 0 ? $day_totals[$i] / $day_counts[$i] : 0;
        }
        
        return $day_averages;
    }
    
    private function generateForecastData($historical_data, $days, $moving_average, $trend, $seasonality) {
        $predictions = [];
        $confidence = 0.7; // Base confidence level
        
        $base_demand = $moving_average > 0 ? $moving_average : 1;
        
        for ($i = 1; $i <= $days; $i++) {
            $trend_factor = $trend * $i;
            $seasonal_factor = 1;
            
            if (!empty($seasonality)) {
                $day_of_week = date('w', strtotime("+{$i} days"));
                $avg_seasonal = array_sum($seasonality) / count(array_filter($seasonality));
                $seasonal_factor = $avg_seasonal > 0 ? $seasonality[$day_of_week] / $avg_seasonal : 1;
            }
            
            $prediction = max(0, round($base_demand + $trend_factor) * $seasonal_factor);
            $predictions[] = $prediction;
        }
        
        // Adjust confidence based on data quality
        if (count($historical_data) < 30) {
            $confidence *= 0.8;
        }
        
        if (abs($trend) > $base_demand * 0.1) {
            $confidence *= 0.9; // Less confident with high volatility
        }
        
        return [
            'predictions' => $predictions,
            'confidence' => round($confidence * 100)
        ];
    }
    
    private function calculateReorderPoint($historical_data, $days) {
        $daily_average = count($historical_data) > 0 ? 
            array_sum(array_column($historical_data, 'total_quantity')) / count($historical_data) : 1;
        
        $lead_time_days = 7; // Assume 7-day lead time
        $safety_stock_days = 3; // 3 days safety stock
        
        return max(1, round($daily_average * ($lead_time_days + $safety_stock_days)));
    }
    
    private function determineStockStatus($current_stock, $predicted_demand, $reorder_point) {
        if ($current_stock <= $reorder_point) {
            return ['text' => 'Reorder Now', 'class' => 'danger'];
        } elseif ($current_stock < $predicted_demand) {
            return ['text' => 'Low Stock', 'class' => 'warning'];
        } elseif ($current_stock > $predicted_demand * 2) {
            return ['text' => 'Overstock', 'class' => 'info'];
        } else {
            return ['text' => 'Good', 'class' => 'success'];
        }
    }
    
    private function prepareChartData($historical_data, $predictions, $days) {
        $labels = [];
        $historical = [];
        $forecast = [];
        
        // Historical data labels and values
        foreach ($historical_data as $data) {
            $labels[] = date('M j', strtotime($data['sale_date']));
            $historical[] = $data['total_quantity'];
            $forecast[] = null; // No forecast for historical dates
        }
        
        // Forecast data labels and values
        for ($i = 1; $i <= $days; $i++) {
            $date = date('M j', strtotime("+{$i} days"));
            $labels[] = $date;
            $historical[] = null; // No historical data for future dates
            $forecast[] = $predictions[$i - 1];
        }
        
        return [
            'labels' => $labels,
            'historical' => $historical,
            'forecast' => $forecast
        ];
    }
    
    private function getDefaultForecast($product_id, $store_id, $days, $current_stock) {
        // Return basic forecast when no historical data is available
        $daily_demand = 1; // Assume 1 unit per day as default
        
        return [
            'current_stock' => $current_stock,
            'total_predicted_demand' => $daily_demand * $days,
            'daily_predictions' => array_fill(0, $days, $daily_demand),
            'reorder_point' => 10,
            'stock_status' => $this->determineStockStatus($current_stock, $daily_demand * $days, 10),
            'confidence_level' => 30, // Low confidence without data
            'chart_data' => [
                'labels' => array_map(function($i) { return date('M j', strtotime("+{$i} days")); }, range(1, $days)),
                'historical' => [],
                'forecast' => array_fill(0, $days, $daily_demand)
            ]
        ];
    }
    
    private function getSeasonalTrend($product_id) {
        // Analyze seasonal trends - simplified version
        $current_month = date('n');
        
        $seasonal_messages = [
            12 => 'Holiday season - expect higher demand',
            1 => 'Post-holiday period - demand may be lower',
            6 => 'Summer season beginning',
            9 => 'Back-to-school period'
        ];
        
        if (isset($seasonal_messages[$current_month])) {
            return ['message' => $seasonal_messages[$current_month]];
        }
        
        return null;
    }
    
    /**
     * Clear forecast cache for a specific product
     */
    public function clearForecastCache($product_id, $store_id = null) {
        $pattern = "forecast:{$product_id}:" . ($store_id ?? '*');
        $keys = $this->redis->keys($pattern);
        
        foreach ($keys as $key) {
            $this->redis->del($key);
        }
        
        return count($keys);
    }
    
    /**
     * Clear all forecast caches
     */
    public function clearAllForecastCache() {
        $keys = $this->redis->keys('forecast:*');
        
        foreach ($keys as $key) {
            $this->redis->del($key);
        }
        
        return count($keys);
    }
    
    /**
     * Get cached forecast statistics
     */
    public function getCacheStats() {
        $keys = $this->redis->keys('forecast:*');
        $stats = [
            'total_cached_forecasts' => count($keys),
            'cache_size_mb' => 0,
            'oldest_cache' => null,
            'newest_cache' => null
        ];
        
        $oldest_time = time();
        $newest_time = 0;
        
        foreach ($keys as $key) {
            $data = $this->redis->get($key);
            if ($data) {
                $forecast = json_decode($data, true);
                if (isset($forecast['cached_at'])) {
                    $cache_time = $forecast['cached_at'];
                    if ($cache_time < $oldest_time) $oldest_time = $cache_time;
                    if ($cache_time > $newest_time) $newest_time = $cache_time;
                }
                $stats['cache_size_mb'] += strlen($data);
            }
        }
        
        $stats['cache_size_mb'] = round($stats['cache_size_mb'] / 1024 / 1024, 2);
        $stats['oldest_cache'] = $oldest_time < time() ? date('Y-m-d H:i:s', $oldest_time) : null;
        $stats['newest_cache'] = $newest_time > 0 ? date('Y-m-d H:i:s', $newest_time) : null;
        
        return $stats;
    }
}
?>