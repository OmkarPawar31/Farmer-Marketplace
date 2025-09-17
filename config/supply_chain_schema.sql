-- Supply Chain Tracking and Payment Escrow Schema Extension
-- This extends the existing farmer_marketplace database

USE farmer_marketplace;

-- Supply chain tracking statuses
CREATE TABLE supply_chain_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status_code VARCHAR(50) UNIQUE NOT NULL,
    status_name VARCHAR(100) NOT NULL,
    description TEXT,
    stage_order INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default supply chain statuses
INSERT INTO supply_chain_statuses (status_code, status_name, description, stage_order) VALUES
('ORDER_PLACED', 'Order Placed', 'Order has been placed by buyer', 1),
('ORDER_CONFIRMED', 'Order Confirmed', 'Order confirmed by farmer', 2),
('PREPARING_HARVEST', 'Preparing Harvest', 'Farmer is preparing for harvest', 3),
('HARVESTING', 'Harvesting', 'Crops are being harvested', 4),
('QUALITY_CHECK', 'Quality Check', 'Quality inspection in progress', 5),
('PACKAGING', 'Packaging', 'Products are being packaged', 6),
('READY_FOR_PICKUP', 'Ready for Pickup', 'Products ready for transportation', 7),
('IN_TRANSIT', 'In Transit', 'Products are being transported', 8),
('OUT_FOR_DELIVERY', 'Out for Delivery', 'Products are out for final delivery', 9),
('DELIVERED', 'Delivered', 'Products delivered to buyer', 10),
('RECEIVED', 'Received', 'Delivery confirmed by buyer', 11);

-- Supply chain tracking
CREATE TABLE supply_chain_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status_id INT NOT NULL,
    location VARCHAR(255),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    notes TEXT,
    estimated_date DATETIME,
    actual_date DATETIME,
    updated_by INT,
    images JSON, -- photos of products at this stage
    temperature DECIMAL(5,2), -- for cold chain tracking
    humidity DECIMAL(5,2),
    vehicle_number VARCHAR(20),
    driver_name VARCHAR(100),
    driver_phone VARCHAR(15),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (status_id) REFERENCES supply_chain_statuses(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- Payment escrow accounts
CREATE TABLE escrow_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    account_type ENUM('order', 'dispute', 'security') DEFAULT 'order',
    status ENUM('active', 'closed', 'frozen') DEFAULT 'active',
    balance DECIMAL(15,2) DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'INR',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Payment escrow transactions
CREATE TABLE escrow_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    escrow_account_id INT NOT NULL,
    transaction_id VARCHAR(100) UNIQUE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    transaction_type ENUM('deposit', 'hold', 'release', 'refund', 'partial_release') NOT NULL,
    status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    payment_method ENUM('bank_transfer', 'upi', 'credit_card', 'debit_card', 'wallet') NOT NULL,
    payment_gateway VARCHAR(50),
    gateway_transaction_id VARCHAR(255),
    gateway_response JSON,
    release_condition VARCHAR(255), -- condition for fund release
    release_trigger ENUM('delivery_confirmation', 'quality_approval', 'time_based', 'manual') NOT NULL,
    release_date DATETIME,
    initiated_by INT NOT NULL,
    approved_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (escrow_account_id) REFERENCES escrow_accounts(id),
    FOREIGN KEY (initiated_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Payment gateways configuration
CREATE TABLE payment_gateways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_name VARCHAR(50) NOT NULL,
    gateway_code VARCHAR(20) UNIQUE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    supports_escrow BOOLEAN DEFAULT FALSE,
    api_endpoint VARCHAR(255),
    webhook_url VARCHAR(255),
    configuration JSON, -- store API keys, secrets etc (encrypted)
    fee_percentage DECIMAL(5,4) DEFAULT 0.0000,
    fee_fixed DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default payment gateways
INSERT INTO payment_gateways (gateway_name, gateway_code, supports_escrow, fee_percentage, fee_fixed) VALUES
('Razorpay', 'razorpay', TRUE, 0.0200, 0.00),
('PayU', 'payu', TRUE, 0.0199, 0.00),
('CCAvenue', 'ccavenue', FALSE, 0.0250, 0.00),
('Paytm', 'paytm', TRUE, 0.0180, 0.00),
('PhonePe', 'phonepe', FALSE, 0.0150, 0.00);

-- Delivery tracking
CREATE TABLE delivery_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    tracking_number VARCHAR(100) UNIQUE NOT NULL,
    courier_service VARCHAR(100),
    pickup_date DATETIME,
    expected_delivery_date DATETIME,
    actual_delivery_date DATETIME,
    delivery_status ENUM('pending', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned') DEFAULT 'pending',
    delivery_address TEXT,
    delivery_instructions TEXT,
    recipient_name VARCHAR(100),
    recipient_phone VARCHAR(15),
    delivery_proof JSON, -- photos, signatures
    delivery_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Quality inspection records
CREATE TABLE quality_inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    inspector_id INT,
    inspection_date DATETIME NOT NULL,
    inspection_location VARCHAR(255),
    overall_grade ENUM('A+', 'A', 'B+', 'B', 'C', 'REJECTED') NOT NULL,
    weight_actual DECIMAL(10,2),
    weight_expected DECIMAL(10,2),
    moisture_content DECIMAL(5,2),
    foreign_matter DECIMAL(5,2),
    damage_percentage DECIMAL(5,2),
    color_grade VARCHAR(20),
    size_grade VARCHAR(20),
    inspection_notes TEXT,
    images JSON, -- inspection photos
    lab_report_url VARCHAR(255),
    passed BOOLEAN DEFAULT TRUE,
    rejection_reason TEXT,
    reinspection_required BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (inspector_id) REFERENCES users(id)
);

-- Dispute management
CREATE TABLE disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    raised_by INT NOT NULL,
    dispute_type ENUM('quality', 'delivery', 'payment', 'quantity', 'damage', 'other') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    amount_disputed DECIMAL(15,2),
    evidence JSON, -- photos, documents
    status ENUM('open', 'under_review', 'resolved', 'closed', 'escalated') DEFAULT 'open',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    assigned_to INT, -- mediator/admin
    resolution_notes TEXT,
    resolution_amount DECIMAL(15,2),
    resolved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (raised_by) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id)
);

-- Dispute messages/communication
CREATE TABLE dispute_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    attachments JSON,
    is_internal BOOLEAN DEFAULT FALSE, -- internal admin notes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id)
);

-- Insurance policies for orders
CREATE TABLE order_insurance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    policy_number VARCHAR(100) UNIQUE NOT NULL,
    insurance_provider VARCHAR(100),
    coverage_amount DECIMAL(15,2) NOT NULL,
    premium_amount DECIMAL(10,2) NOT NULL,
    coverage_type ENUM('crop_damage', 'transit_damage', 'quality_loss', 'comprehensive') NOT NULL,
    policy_start_date DATE NOT NULL,
    policy_end_date DATE NOT NULL,
    terms_conditions TEXT,
    status ENUM('active', 'expired', 'claimed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Insurance claims
CREATE TABLE insurance_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    insurance_id INT NOT NULL,
    claim_number VARCHAR(100) UNIQUE NOT NULL,
    claim_amount DECIMAL(15,2) NOT NULL,
    claim_reason TEXT NOT NULL,
    evidence JSON, -- photos, documents
    claim_date DATE NOT NULL,
    status ENUM('submitted', 'under_review', 'approved', 'rejected', 'paid') DEFAULT 'submitted',
    assessment_notes TEXT,
    approved_amount DECIMAL(15,2),
    payment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (insurance_id) REFERENCES order_insurance(id) ON DELETE CASCADE
);

-- Cold chain monitoring (for perishable goods)
CREATE TABLE cold_chain_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sensor_id VARCHAR(100),
    temperature DECIMAL(5,2) NOT NULL,
    humidity DECIMAL(5,2),
    location VARCHAR(255),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    recorded_at DATETIME NOT NULL,
    alert_triggered BOOLEAN DEFAULT FALSE,
    alert_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Payment schedules (for installment payments)
CREATE TABLE payment_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    installment_number INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    due_date DATE NOT NULL,
    payment_status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    paid_date DATE,
    paid_amount DECIMAL(15,2),
    late_fee DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Create indexes for better performance
CREATE INDEX idx_supply_chain_tracking_order ON supply_chain_tracking(order_id);
CREATE INDEX idx_supply_chain_tracking_status ON supply_chain_tracking(status_id);
CREATE INDEX idx_supply_chain_tracking_date ON supply_chain_tracking(actual_date);
CREATE INDEX idx_escrow_transactions_order ON escrow_transactions(order_id);
CREATE INDEX idx_escrow_transactions_status ON escrow_transactions(status);
CREATE INDEX idx_escrow_transactions_type ON escrow_transactions(transaction_type);
CREATE INDEX idx_delivery_tracking_order ON delivery_tracking(order_id);
CREATE INDEX idx_delivery_tracking_number ON delivery_tracking(tracking_number);
CREATE INDEX idx_quality_inspections_order ON quality_inspections(order_id);
CREATE INDEX idx_disputes_order ON disputes(order_id);
CREATE INDEX idx_disputes_status ON disputes(status);
CREATE INDEX idx_cold_chain_order ON cold_chain_logs(order_id);
CREATE INDEX idx_cold_chain_recorded ON cold_chain_logs(recorded_at);
CREATE INDEX idx_payment_schedules_order ON payment_schedules(order_id);
CREATE INDEX idx_payment_schedules_due ON payment_schedules(due_date);

-- Create triggers for automated escrow handling
DELIMITER //

-- Trigger to create escrow account when order is placed
CREATE TRIGGER create_escrow_on_order_insert 
AFTER INSERT ON orders 
FOR EACH ROW 
BEGIN
    DECLARE escrow_acc_num VARCHAR(50);
    SET escrow_acc_num = CONCAT('ESC', YEAR(NOW()), MONTH(NOW()), DAY(NOW()), '_', NEW.id);
    
    INSERT INTO escrow_accounts (account_number, account_type, status) 
    VALUES (escrow_acc_num, 'order', 'active');
END//

-- Trigger to update escrow balance on transaction
CREATE TRIGGER update_escrow_balance_on_transaction 
AFTER UPDATE ON escrow_transactions 
FOR EACH ROW 
BEGIN
    IF NEW.status = 'completed' AND OLD.status != 'completed' THEN
        IF NEW.transaction_type = 'deposit' THEN
            UPDATE escrow_accounts 
            SET balance = balance + NEW.amount 
            WHERE id = NEW.escrow_account_id;
        ELSEIF NEW.transaction_type IN ('release', 'refund', 'partial_release') THEN
            UPDATE escrow_accounts 
            SET balance = balance - NEW.amount 
            WHERE id = NEW.escrow_account_id;
        END IF;
    END IF;
END//

-- Trigger to auto-release funds on delivery confirmation
CREATE TRIGGER auto_release_on_delivery 
AFTER UPDATE ON supply_chain_tracking 
FOR EACH ROW 
BEGIN
    DECLARE escrow_acc_id INT;
    DECLARE order_amount DECIMAL(15,2);
    
    IF NEW.status_id = (SELECT id FROM supply_chain_statuses WHERE status_code = 'RECEIVED') THEN
        -- Get escrow account and order amount
        SELECT ea.id, o.total_amount 
        INTO escrow_acc_id, order_amount
        FROM escrow_accounts ea
        JOIN orders o ON o.id = NEW.order_id
        WHERE ea.account_number = CONCAT('ESC', YEAR(NOW()), MONTH(NOW()), DAY(NOW()), '_', NEW.order_id)
        LIMIT 1;
        
        -- Create release transaction
        IF escrow_acc_id IS NOT NULL THEN
            INSERT INTO escrow_transactions (
                order_id, escrow_account_id, transaction_id, amount, 
                transaction_type, status, payment_method, release_trigger, 
                release_date, initiated_by
            ) VALUES (
                NEW.order_id, escrow_acc_id, 
                CONCAT('REL_', NEW.order_id, '_', UNIX_TIMESTAMP()), 
                order_amount, 'release', 'completed', 'bank_transfer', 
                'delivery_confirmation', NOW(), NEW.updated_by
            );
        END IF;
    END IF;
END//

DELIMITER ;

-- Views for easier querying
CREATE VIEW order_supply_chain_status AS
SELECT 
    o.id as order_id,
    o.order_number,
    o.buyer_id,
    o.farmer_id,
    scs.status_name as current_status,
    scs.stage_order,
    sct.actual_date as status_date,
    sct.location,
    sct.notes
FROM orders o
LEFT JOIN supply_chain_tracking sct ON o.id = sct.order_id
LEFT JOIN supply_chain_statuses scs ON sct.status_id = scs.id
WHERE sct.id = (
    SELECT MAX(sct2.id) 
    FROM supply_chain_tracking sct2 
    WHERE sct2.order_id = o.id
);

CREATE VIEW escrow_order_summary AS
SELECT 
    o.id as order_id,
    o.order_number,
    o.total_amount,
    ea.account_number as escrow_account,
    ea.balance as escrow_balance,
    ea.status as escrow_status,
    COUNT(et.id) as total_transactions,
    SUM(CASE WHEN et.transaction_type = 'deposit' AND et.status = 'completed' THEN et.amount ELSE 0 END) as total_deposits,
    SUM(CASE WHEN et.transaction_type = 'release' AND et.status = 'completed' THEN et.amount ELSE 0 END) as total_releases
FROM orders o
LEFT JOIN escrow_accounts ea ON ea.account_number = CONCAT('ESC', YEAR(o.created_at), MONTH(o.created_at), DAY(o.created_at), '_', o.id)
LEFT JOIN escrow_transactions et ON et.order_id = o.id
GROUP BY o.id, ea.id;
