# Demand Forecasting Module - Technical Documentation

## Overview

The Demand Forecasting Module uses advanced statistical methods to predict future product demand based on historical sales data. This enables intelligent inventory management, preventing stockouts and reducing overstock situations.

---

## Table of Contents

1. [Core Concepts](#core-concepts)
2. [Forecasting Methods](#forecasting-methods)
3. [Calculation Details](#calculation-details)
4. [Metrics & Indicators](#metrics--indicators)
5. [Recommendations System](#recommendations-system)
6. [Usage Examples](#usage-examples)

---

## Core Concepts

### Data Sources

The forecasting system analyzes:
- **Historical Sales Data**: Past 180 days of completed sales transactions
- **Current Stock Levels**: Real-time inventory quantities
- **Store-Specific Data**: Optional filtering by store location
- **Time Series Patterns**: Daily sales aggregated by date

### Key Components

1. **Time Series Analysis**: Decomposes sales data into trend, seasonality, and residual components
2. **Multiple Forecasting Methods**: Compares 5 different algorithms to find the best fit
3. **Confidence Intervals**: Provides upper and lower bounds for predictions (95% confidence)
4. **Seasonality Detection**: Identifies weekly patterns (day-of-week effects)
5. **Outlier Handling**: Uses IQR method to cap extreme values
6. **Automated Recommendations**: Generates actionable inventory suggestions

---

## Forecasting Methods

The system implements and compares 5 statistical forecasting methods:

### 1. Simple Moving Average (SMA)

**Purpose**: Baseline forecast using recent average demand

**Formula**:
```
Forecast = (Sum of last N days sales) / N
```

**Parameters**:
- Window size: 14 days (or all available data if less)

**Best for**: Stable demand with no trend or seasonality

**Example**:
```
Sales: [10, 12, 11, 13, 12] (last 5 days)
SMA = (10 + 12 + 11 + 13 + 12) / 5 = 11.6 ≈ 12 units/day
```

---

### 2. Exponential Smoothing (Single)

**Purpose**: Weighted average that gives more importance to recent data

**Formula**:
```
S[t] = α × Y[t] + (1 - α) × S[t-1]

Where:
- S[t] = Smoothed value at time t
- Y[t] = Actual value at time t
- α (alpha) = Smoothing factor (0.3 by default)
- S[t-1] = Previous smoothed value
```

**Parameters**:
- Alpha (α) = 0.3 (30% weight to new data, 70% to historical trend)

**Best for**: Data with no strong trend, moderate fluctuations

**Example**:
```
Day 1: Actual = 10, S[1] = 10
Day 2: Actual = 15, S[2] = 0.3(15) + 0.7(10) = 11.5
Day 3: Actual = 12, S[3] = 0.3(12) + 0.7(11.5) = 11.65
Day 4: Actual = 18, S[4] = 0.3(18) + 0.7(11.65) = 13.555
Forecast = 13.6 units/day
```

---

### 3. Double Exponential Smoothing (Holt's Method)

**Purpose**: Handles data with trend (increasing or decreasing patterns)

**Formula**:
```
Level[t] = α × Y[t] + (1 - α) × (Level[t-1] + Trend[t-1])
Trend[t] = β × (Level[t] - Level[t-1]) + (1 - β) × Trend[t-1]
Forecast[t+h] = Level[t] + h × Trend[t]

Where:
- Level[t] = Smoothed level at time t
- Trend[t] = Smoothed trend at time t
- α (alpha) = Level smoothing factor (0.3)
- β (beta) = Trend smoothing factor (0.1)
- h = Forecast horizon (days ahead)
```

**Parameters**:
- Alpha (α) = 0.3
- Beta (β) = 0.1

**Best for**: Data with clear upward or downward trends

**Example**:
```
Initial: Level[0] = 10, Trend[0] = 2
Day 1: Actual = 12
  Level[1] = 0.3(12) + 0.7(10 + 2) = 12
  Trend[1] = 0.1(12 - 10) + 0.9(2) = 2

Day 2: Actual = 15
  Level[2] = 0.3(15) + 0.7(12 + 2) = 14.3
  Trend[2] = 0.1(14.3 - 12) + 0.9(2) = 2.03

Forecast for Day 3 = 14.3 + 1(2.03) = 16.33 units
Forecast for Day 4 = 14.3 + 2(2.03) = 18.36 units
```

---

### 4. Linear Regression

**Purpose**: Mathematical trend line through historical data points

**Formula**:
```
Y = mx + b

Where:
m (slope) = Σ[(Xi - X̄)(Yi - Ȳ)] / Σ[(Xi - X̄)²]
b (intercept) = Ȳ - m × X̄

Where:
- Y = Predicted sales
- x = Day number
- X̄ = Mean of day numbers
- Ȳ = Mean of sales quantities
```

**Best for**: Strong linear trends (steady increase or decrease)

**Example**:
```
Data: Day 1=10, Day 2=12, Day 3=14, Day 4=16, Day 5=18

X̄ = (1+2+3+4+5)/5 = 3
Ȳ = (10+12+14+16+18)/5 = 14

m = [(1-3)(10-14) + (2-3)(12-14) + ... + (5-3)(18-14)] / [(1-3)² + (2-3)² + ... + (5-3)²]
m = [8 + 2 + 0 + 2 + 8] / [4 + 1 + 0 + 1 + 4] = 20/10 = 2

b = 14 - 2(3) = 8

Forecast for Day 6 = 2(6) + 8 = 20 units
Forecast for Day 7 = 2(7) + 8 = 22 units
```

---

### 5. Weighted Moving Average (WMA)

**Purpose**: Recent data weighted more heavily than older data

**Formula**:
```
WMA = Σ(Wi × Yi) / ΣWi

Where:
- Wi = Weight for period i (linearly increasing: 1, 2, 3, ...)
- Yi = Sales quantity for period i
```

**Parameters**:
- Window size: 14 days
- Weights: Linear (most recent day gets highest weight)

**Best for**: Data where recent changes are more predictive

**Example**:
```
Last 5 days: [10, 11, 13, 12, 15]
Weights:      [1,   2,  3,  4,  5]

WMA = (1×10 + 2×11 + 3×13 + 4×12 + 5×15) / (1+2+3+4+5)
    = (10 + 22 + 39 + 48 + 75) / 15
    = 194 / 15
    = 12.93 ≈ 13 units/day
```

---

## Calculation Details

### Time Series Decomposition

The system breaks down sales data into components:

#### 1. Trend Component

**Formula** (Moving Average):
```
Trend[i] = Average of values in window centered at i

Window size = min(7 days, n/3)
```

**Purpose**: Identifies long-term direction (increasing, decreasing, stable)

**Classification**:
```
slope > 0.3  → Increasing trend
slope < -0.3 → Decreasing trend
otherwise    → Stable trend
```

#### 2. Seasonality Detection

**Formula** (Day-of-Week Analysis):
```
For each day (Monday to Sunday):
  Average[day] = Sum of sales on that day / Count of occurrences

Seasonality Strength = Standard Deviation / Mean

If Strength > 0.2 (20%) → Seasonality detected
```

**Seasonal Factors**:
```
Factor[day] = Average[day] / Overall Average

Adjusted Forecast = Base Forecast × Factor[current_day]
```

**Example**:
```
Monday avg: 15 units    → Factor = 15/12 = 1.25 (+25%)
Tuesday avg: 10 units   → Factor = 10/12 = 0.83 (-17%)
Wednesday avg: 12 units → Factor = 12/12 = 1.00 (baseline)
...
Overall avg: 12 units

If base forecast = 10 units for Monday:
Adjusted = 10 × 1.25 = 12.5 ≈ 13 units
```

---

### Outlier Detection (IQR Method)

**Purpose**: Remove extreme values that distort forecasts

**Formula**:
```
Q1 = 25th percentile of data
Q3 = 75th percentile of data
IQR = Q3 - Q1

Lower Bound = Q1 - 1.5 × IQR
Upper Bound = Q3 + 1.5 × IQR

If value < Lower Bound → Cap at Lower Bound
If value > Upper Bound → Cap at Upper Bound
```

**Example**:
```
Sorted sales: [5, 7, 8, 10, 12, 13, 15, 50]

Q1 = 7.5 (25th percentile)
Q3 = 13.5 (75th percentile)
IQR = 13.5 - 7.5 = 6

Lower = 7.5 - 1.5(6) = -1.5 → 0 (minimum)
Upper = 13.5 + 1.5(6) = 22.5

Value 50 is outlier → Cap at 22.5
Adjusted: [5, 7, 8, 10, 12, 13, 15, 22.5]
```

---

### Confidence Intervals

**Purpose**: Provide uncertainty bounds (95% confidence level)

**Formula**:
```
Standard Deviation = √[Σ(Xi - X̄)² / n]

Lower Bound = Forecast - 1.96 × SD
Upper Bound = Forecast + 1.96 × SD

Where:
- 1.96 = Z-score for 95% confidence
- SD = Standard deviation of historical sales
```

**Interpretation**:
- **95% confidence**: Actual sales will fall within bounds 95% of the time
- Wide interval = High uncertainty
- Narrow interval = High confidence

**Example**:
```
Forecast = 15 units/day
SD = 3 units
95% CI = 15 ± (1.96 × 3) = 15 ± 5.88

Lower Bound = 15 - 5.88 = 9.12 ≈ 9 units
Upper Bound = 15 + 5.88 = 20.88 ≈ 21 units

Interpretation: 95% confident actual sales will be between 9-21 units
```

---

### Volatility Calculation

**Purpose**: Measure demand consistency

**Formula** (Coefficient of Variation):
```
CV = Standard Deviation / Mean

Volatility = CV

Where:
CV < 0.3  → Low volatility (stable demand)
CV > 0.7  → High volatility (unpredictable demand)
```

**Example**:
```
Sales: [10, 12, 11, 9, 13, 10, 12]
Mean = 11
SD = 1.41

CV = 1.41 / 11 = 0.128 (12.8% variation)
→ Low volatility, stable demand
```

---

## Metrics & Indicators

### 1. Daily Average

**Formula**:
```
Daily Average = Total Sales / Number of Days
```

**Purpose**: Baseline demand rate

### 2. Total Predicted Demand

**Formula**:
```
Total = Sum of daily predictions over forecast period
```

**Purpose**: Total units needed for forecast period

### 3. Reorder Point

**Formula** (Smart Calculation):
```
Lead Time = 7 days (typical supplier lead time)
Safety Stock Multiplier = base × volatility_factor × seasonality_factor

Base = 1.5 (standard)
Volatility Factor = 1.0 (low) to 1.33 (high)
Seasonality Factor = 1.0 (none) to 1.2 (strong)

Reorder Point = Daily Average × Lead Time × Safety Stock Multiplier
Minimum = 5 units (safety minimum)
```

**Example**:
```
Daily Average = 10 units
Volatility = 0.6 (high) → Factor = 1.33
Seasonality Strength = 35% → Factor = 1.2

Reorder Point = 10 × 7 × 1.5 × 1.33 × 1.2 = 167 units
```

### 4. Confidence Level

**Formula**:
```
Base = 60%

Adjustments:
+25% if data_points ≥ 90
+20% if data_points ≥ 60
+10% if data_points ≥ 30
-10% if data_points < 30

+10% if volatility < 0.2
-15% if volatility > 0.6

+5% if seasonality detected
+5% if method = double_exponential
+3% if method = linear_regression

Final = max(0, min(100, adjusted_confidence))
```

**Example**:
```
Base = 60%
Data points = 75 → +20%
Volatility = 0.4 → +0%
Seasonality = Yes → +5%
Method = double_exp → +5%

Confidence = 60 + 20 + 5 + 5 = 90%
```

### 5. Stock Status

**Decision Tree**:
```
IF current_stock = 0:
  → OUT OF STOCK (Critical)

ELSE IF current_stock ≤ reorder_point:
  → REORDER NOW (High Priority)

ELSE IF current_stock < predicted_demand:
  → LOW STOCK (Medium Priority)

ELSE IF current_stock > predicted_demand × 2.5:
  → OVERSTOCK (Low Priority)

ELSE:
  → GOOD (Optimal)
```

---

## Recommendations System

### Recommendation Types

#### 1. Critical - Out of Stock
```
Condition: current_stock = 0
Message: "Product is currently out of stock"
Action: "Order Now"
Priority: Immediate
```

#### 2. High Priority - Reorder Required
```
Condition: current_stock ≤ reorder_point
Order Quantity = ceil((predicted_demand - current_stock) × 1.2)
Message: "Stock at reorder point. Order {quantity} units"
Action: "Create Purchase Order"
Priority: Urgent
```

**Formula**:
```
Order Quantity = (Predicted Demand - Current Stock) × 1.2

Where 1.2 = 20% safety buffer
```

**Example**:
```
Current = 50 units
Predicted = 200 units
Reorder = 100 units

Current (50) ≤ Reorder (100) → REORDER
Order = (200 - 50) × 1.2 = 180 units
```

#### 3. Medium Priority - Potential Shortage
```
Condition: current_stock < predicted_demand
Shortage = predicted_demand - current_stock
Days Until Stockout = current_stock / daily_average
Message: "Shortage: {shortage} units. Stockout in {days} days"
Action: "Plan Reorder"
```

#### 4. Low Priority - Overstock
```
Condition: current_stock > predicted_demand × 2.5
Excess = current_stock - (predicted_demand × 2)
Message: "Excess inventory: {excess} units"
Action: "Review Inventory" (consider promotions)
```

#### 5. Success - Optimal Stock
```
Condition: All other cases
Message: "Stock levels well-balanced"
Action: None
```

---

## Usage Examples

### Example 1: Stable Demand Product

**Scenario**: Office supplies with consistent sales

**Input Data**:
```
Historical sales (30 days): [45, 47, 46, 48, 45, 46, 47, ...]
Current stock: 500 units
```

**Output**:
```
Daily Average: 46 units/day
Trend: Stable
Volatility: 0.15 (Low)
Seasonality: None detected
Best Method: Simple Moving Average
30-Day Forecast: 1,380 units
Confidence: 85%
Reorder Point: 483 units (46 × 7 × 1.5)
Status: GOOD
```

### Example 2: Trending Product

**Scenario**: New product with growing popularity

**Input Data**:
```
Historical sales (60 days): [5, 7, 8, 10, 12, 15, 18, 22, ...]
Current stock: 200 units
```

**Output**:
```
Daily Average: 12 units/day
Trend: Increasing (+0.8 units/day)
Volatility: 0.35 (Moderate)
Seasonality: None
Best Method: Double Exponential Smoothing
30-Day Forecast: 540 units (increasing pattern)
Confidence: 75%
Reorder Point: 176 units (12 × 7 × 1.5 × 1.2)
Status: LOW STOCK
Recommendation: Order 480 units
```

### Example 3: Seasonal Product

**Scenario**: Fresh bakery items with weekly patterns

**Input Data**:
```
Historical sales (90 days):
Monday avg: 80 units
Tuesday avg: 60 units
Wednesday avg: 65 units
Thursday avg: 70 units
Friday avg: 90 units
Saturday avg: 120 units
Sunday avg: 100 units
```

**Output**:
```
Daily Average: 83 units/day
Trend: Stable
Volatility: 0.25 (Low-Moderate)
Seasonality: Weekly pattern detected (28% strength)
Seasonal Factors:
  Mon: 0.96, Tue: 0.72, Wed: 0.78, Thu: 0.84,
  Fri: 1.08, Sat: 1.45, Sun: 1.20
Best Method: Exponential Smoothing + Seasonality
7-Day Forecast (if starting Monday):
  Mon: 80, Tue: 60, Wed: 65, Thu: 70, 
  Fri: 90, Sat: 120, Sun: 100
Confidence: 88%
Reorder Point: 1,050 units (83 × 7 × 1.5 × 1.2)
```

---

## Algorithm Selection Process

**Step 1**: Calculate all 5 methods on historical data

**Step 2**: Create validation split
- Training set: 80% of historical data
- Test set: Last 20% (minimum 7 days)

**Step 3**: Calculate MAPE for each method
```
MAPE = Average(|Actual - Predicted| / Actual)
Accuracy = (1 - MAPE) × 100%
```

**Step 4**: Select method with highest accuracy

**Example**:
```
Test period (7 days): [50, 52, 48, 51, 49, 53, 50]

Simple MA predictions: [50, 50, 50, 50, 50, 50, 50]
MAPE = 0.032 → Accuracy = 96.8%

Double Exp predictions: [49, 50, 51, 51, 52, 52, 53]
MAPE = 0.025 → Accuracy = 97.5% ← BEST

Linear Reg predictions: [48, 49, 50, 51, 52, 53, 54]
MAPE = 0.045 → Accuracy = 95.5%

Selected: Double Exponential Smoothing
```

---

## Limitations & Considerations

### Data Requirements

- **Minimum**: 14 days of sales history for basic forecasting
- **Recommended**: 60+ days for accurate trend detection
- **Optimal**: 90+ days for seasonality detection

### Accuracy Factors

**High Accuracy Scenarios**:
- Consistent sales patterns
- Low volatility (CV < 0.3)
- Sufficient historical data (90+ days)
- Clear trend or seasonality

**Low Accuracy Scenarios**:
- New products (< 30 days data)
- High volatility (CV > 0.7)
- Promotional periods
- External factors (holidays, events)

### Not Accounted For

The system does NOT consider:
- Promotional campaigns
- Marketing activities
- Competitive actions
- Economic changes
- Supply chain disruptions
- Product lifecycle stage

**Recommendation**: Use forecast as baseline, adjust manually for known events

---

## Technical Implementation

### Database Tables

**Sales Table**:
```sql
SELECT DATE(s.created_at) as sale_date, 
       SUM(si.quantity) as quantity_sold
FROM sales s
JOIN sale_items si ON s.id = si.sale_id
WHERE si.product_id = ?
  AND s.payment_status = 'completed'
  AND s.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
GROUP BY DATE(s.created_at)
```

### Performance

- **Data retrieval**: < 50ms (PostgreSQL indexed queries)
- **Calculation time**: 100-200ms (5 methods + analysis)
- **Total response**: < 300ms per product
- **Concurrent forecasts**: 50+ products/second

---

## API Response Format

```json
{
  "product_id": 123,
  "store_id": 5,
  "current_stock": 450,
  "daily_average": 15.5,
  "trend": "increasing",
  "total_predicted_demand": 465,
  "reorder_point": 163,
  "stock_status": {
    "status": "good",
    "label": "Good",
    "class": "success"
  },
  "confidence_level": 85,
  "predictions": [15, 16, 16, 17, 17, ...],
  "confidence_intervals": {
    "lower": [10, 11, 11, 12, 12, ...],
    "upper": [20, 21, 21, 22, 22, ...]
  },
  "seasonality": {
    "detected": true,
    "pattern": "weekly",
    "strength": 23.5,
    "day_factors": [0.96, 0.88, 1.05, 1.12, 1.08, 0.95, 0.96]
  },
  "method_used": "double_exponential",
  "forecast_accuracy": 87.5,
  "recommendations": [...],
  "generated_at": "2025-11-09 10:30:00"
}
```

---

## Mathematical References

1. **Exponential Smoothing**: Holt, C. C. (1957). *Forecasting Trends and Seasonals*
2. **Time Series Decomposition**: Cleveland, R. B. (1990). *STL: A Seasonal-Trend Decomposition*
3. **Confidence Intervals**: Box, G. E. P. (1976). *Time Series Analysis: Forecasting and Control*
4. **Outlier Detection**: Tukey, J. W. (1977). *Exploratory Data Analysis*

---

## Glossary

- **MAPE**: Mean Absolute Percentage Error - measures forecast accuracy
- **IQR**: Interquartile Range - measures data spread
- **CV**: Coefficient of Variation - measures relative variability
- **Seasonality**: Repeating patterns at fixed intervals
- **Trend**: Long-term direction of data
- **Volatility**: Degree of variation in demand
- **Confidence Interval**: Range where true value likely falls
- **Reorder Point**: Stock level triggering new order
- **Lead Time**: Time between ordering and receiving stock
- **Safety Stock**: Extra inventory buffer for uncertainty

---

**Document Version**: 1.0  
**Last Updated**: November 9, 2025  
**Author**: Inventory Management System Development Team
