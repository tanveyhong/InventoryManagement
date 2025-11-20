-- Add payment details columns to sales table
-- This stores additional payment information for auditing

ALTER TABLE sales ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_change DECIMAL(10,2);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100);
ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_details TEXT;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS cashier_id INTEGER;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS cashier_name VARCHAR(100);

-- Add index for better query performance
CREATE INDEX IF NOT EXISTS idx_sales_cashier ON sales(cashier_id);
CREATE INDEX IF NOT EXISTS idx_sales_payment_method ON sales(payment_method);
CREATE INDEX IF NOT EXISTS idx_sales_date ON sales(sale_date);
