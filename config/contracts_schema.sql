-- Contracts table schema
-- This extends the existing farmer_marketplace database with contracts functionality

USE farmer_marketplace;

-- Contracts table for formal agreements between buyers and farmers
CREATE TABLE contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id VARCHAR(50) UNIQUE NOT NULL,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    product_id INT, -- optional, can be linked to specific product listing
    crop_id INT NOT NULL,
    farmer_name VARCHAR(200) NOT NULL,
    buyer_name VARCHAR(200) NOT NULL,
    product VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit ENUM('kg', 'quintal', 'tonne') DEFAULT 'kg',
    amount DECIMAL(15,2) NOT NULL,
    price_per_unit DECIMAL(10,2) NOT NULL,
    status ENUM('draft', 'pending', 'active', 'completed', 'cancelled', 'expired') DEFAULT 'draft',
    contract_type ENUM('spot', 'forward', 'seasonal') DEFAULT 'spot',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    delivery_location TEXT,
    quality_specifications TEXT,
    payment_terms TEXT,
    special_conditions TEXT,
    created_by INT NOT NULL, -- who created the contract
    signed_by_farmer BOOLEAN DEFAULT FALSE,
    signed_by_buyer BOOLEAN DEFAULT FALSE,
    farmer_signature_date DATETIME NULL,
    buyer_signature_date DATETIME NULL,
    contract_document VARCHAR(255), -- path to PDF contract
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES buyers(id) ON DELETE CASCADE,
    FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product_listings(id) ON DELETE SET NULL,
    FOREIGN KEY (crop_id) REFERENCES crops(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Contract amendments/modifications
CREATE TABLE contract_amendments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    amendment_type ENUM('quantity', 'price', 'date', 'terms', 'other') NOT NULL,
    original_value TEXT,
    new_value TEXT,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    requested_by INT NOT NULL,
    approved_by INT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Link contracts to orders when they are executed
CREATE TABLE contract_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    order_id INT NOT NULL,
    quantity_fulfilled DECIMAL(10,2) NOT NULL,
    amount_fulfilled DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Insert some sample contracts for testing
INSERT INTO contracts (
    contract_id, buyer_id, farmer_id, crop_id, farmer_name, buyer_name, 
    product, quantity, unit, amount, price_per_unit, status, 
    start_date, end_date, created_by
) VALUES 
(
    'CON2024001', 1, 1, 1, 'Ramesh Kumar', 'ABC Traders', 
    'Basmati Rice', 1000.00, 'kg', 45000.00, 45.00, 'active',
    '2024-01-15', '2024-03-15', 1
),
(
    'CON2024002', 1, 2, 3, 'Suresh Patel', 'ABC Traders', 
    'Yellow Corn', 2000.00, 'kg', 30000.00, 15.00, 'pending',
    '2024-02-01', '2024-04-01', 1
),
(
    'CON2024003', 2, 1, 6, 'Ramesh Kumar', 'Fresh Foods Ltd', 
    'Organic Tomatoes', 500.00, 'kg', 15000.00, 30.00, 'completed',
    '2024-01-01', '2024-01-31', 2
);

-- Create indexes for better performance
CREATE INDEX idx_contracts_buyer ON contracts(buyer_id);
CREATE INDEX idx_contracts_farmer ON contracts(farmer_id);
CREATE INDEX idx_contracts_status ON contracts(status);
CREATE INDEX idx_contracts_dates ON contracts(start_date, end_date);
CREATE INDEX idx_contracts_contract_id ON contracts(contract_id);
CREATE INDEX idx_contract_amendments_contract ON contract_amendments(contract_id);
CREATE INDEX idx_contract_orders_contract ON contract_orders(contract_id);
CREATE INDEX idx_contract_orders_order ON contract_orders(order_id);

-- View for easy contract querying
CREATE VIEW contract_summary AS
SELECT 
    c.id,
    c.contract_id,
    c.farmer_name,
    c.buyer_name,
    c.product,
    c.quantity,
    c.unit,
    c.amount,
    c.status,
    c.start_date,
    c.end_date,
    c.signed_by_farmer,
    c.signed_by_buyer,
    CASE 
        WHEN c.signed_by_farmer AND c.signed_by_buyer THEN 'Fully Signed'
        WHEN c.signed_by_farmer OR c.signed_by_buyer THEN 'Partially Signed'
        ELSE 'Unsigned'
    END as signature_status,
    COALESCE(SUM(co.quantity_fulfilled), 0) as quantity_fulfilled,
    COALESCE(SUM(co.amount_fulfilled), 0) as amount_fulfilled,
    (c.quantity - COALESCE(SUM(co.quantity_fulfilled), 0)) as quantity_remaining
FROM contracts c
LEFT JOIN contract_orders co ON c.id = co.contract_id
GROUP BY c.id;
