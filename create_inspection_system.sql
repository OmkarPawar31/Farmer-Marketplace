-- Create inspection system for farmer marketplace
USE farmer_marketplace;

-- Create inspectors table
CREATE TABLE IF NOT EXISTS inspectors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inspector_name VARCHAR(100) NOT NULL,
    inspector_code VARCHAR(20) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(15),
    inspector_type ENUM('third_party', 'platform', 'buyer_representative', 'government') NOT NULL,
    company_organization VARCHAR(200),
    license_number VARCHAR(50),
    certification_details JSON, -- Store certifications, specializations
    specialization TEXT, -- Areas of expertise (crops, organic, etc.)
    experience_years INT,
    rating DECIMAL(3,2) DEFAULT 0.00,
    total_inspections INT DEFAULT 0,
    location_state VARCHAR(50),
    location_district VARCHAR(50),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create inspection requests table
CREATE TABLE IF NOT EXISTS inspection_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    requested_by INT NOT NULL, -- user_id (buyer or farmer)
    inspector_id INT,
    request_type ENUM('pre_delivery', 'post_delivery', 'dispute_resolution') DEFAULT 'pre_delivery',
    inspection_date DATE,
    preferred_time TIME,
    location VARCHAR(255),
    special_instructions TEXT,
    status ENUM('pending', 'assigned', 'scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    inspection_fee DECIMAL(10,2),
    payment_status ENUM('pending', 'paid', 'waived') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (inspector_id) REFERENCES inspectors(id)
);

-- Update quality_reports table to reference inspectors
ALTER TABLE quality_reports 
ADD COLUMN inspector_id INT AFTER inspector_name,
ADD FOREIGN KEY (inspector_id) REFERENCES inspectors(id);

-- Create inspection criteria table
CREATE TABLE IF NOT EXISTS inspection_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    crop_category VARCHAR(100) NOT NULL,
    criteria_name VARCHAR(100) NOT NULL,
    criteria_description TEXT,
    measurement_unit VARCHAR(20),
    min_acceptable_value DECIMAL(10,3),
    max_acceptable_value DECIMAL(10,3),
    weight_percentage DECIMAL(5,2), -- How much this criteria affects overall grade
    is_mandatory BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create detailed inspection results table
CREATE TABLE IF NOT EXISTS inspection_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quality_report_id INT NOT NULL,
    criteria_id INT NOT NULL,
    measured_value DECIMAL(10,3),
    passed BOOLEAN NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quality_report_id) REFERENCES quality_reports(report_id) ON DELETE CASCADE,
    FOREIGN KEY (criteria_id) REFERENCES inspection_criteria(id)
);

-- Insert sample inspectors
INSERT INTO inspectors (inspector_name, inspector_code, email, phone, inspector_type, company_organization, license_number, specialization, experience_years, location_state, location_district) VALUES
('Dr. Rajesh Kumar', 'INS001', 'rajesh.kumar@agriinspect.com', '9876543210', 'third_party', 'AgriInspect Solutions Pvt Ltd', 'AGI/2020/001', 'Cereals, Pulses, Quality Assessment', 8, 'Maharashtra', 'Pune'),
('Priya Singh', 'INS002', 'priya.singh@qualitycheck.com', '9876543211', 'platform', 'Farmer Marketplace Quality Team', 'FMP/QC/002', 'Organic Certification, Vegetables', 5, 'Karnataka', 'Bangalore'),
('Inspector Gupta', 'INS003', 'gupta@govagri.in', '9876543212', 'government', 'Agricultural Department - Maharashtra', 'GOV/AGR/003', 'Food Safety, Government Standards', 12, 'Maharashtra', 'Mumbai'),
('Quality Team Lead', 'INS004', 'quality@farmermarket.com', '9876543213', 'platform', 'Farmer Marketplace', 'FMP/QL/004', 'All Crops, General Quality Assessment', 6, 'Delhi', 'New Delhi'),
('Ahmed Ali', 'INS005', 'ahmed.ali@freelanceinspect.com', '9876543214', 'third_party', 'Freelance Agricultural Inspector', 'FRL/001', 'Fruits, Export Quality Standards', 10, 'Uttar Pradesh', 'Lucknow');

-- Insert sample inspection criteria
INSERT INTO inspection_criteria (crop_category, criteria_name, criteria_description, measurement_unit, min_acceptable_value, max_acceptable_value, weight_percentage, is_mandatory) VALUES
('Cereals', 'Moisture Content', 'Moisture percentage in grain', '%', 0, 14, 25.00, TRUE),
('Cereals', 'Foreign Matter', 'Percentage of foreign particles', '%', 0, 3, 20.00, TRUE),
('Cereals', 'Broken Grains', 'Percentage of broken grains', '%', 0, 5, 15.00, FALSE),
('Cereals', 'Pest Damage', 'Percentage of pest damaged grains', '%', 0, 2, 20.00, TRUE),
('Cereals', 'Color Grade', 'Visual color assessment', 'grade', 1, 5, 10.00, FALSE),
('Cereals', 'Size Uniformity', 'Uniformity in grain size', 'grade', 1, 5, 10.00, FALSE),

('Vegetables', 'Freshness', 'Visual freshness assessment', 'grade', 3, 5, 30.00, TRUE),
('Vegetables', 'Size Consistency', 'Uniformity in size', 'grade', 2, 5, 15.00, FALSE),
('Vegetables', 'Surface Defects', 'Percentage of surface damage', '%', 0, 10, 25.00, TRUE),
('Vegetables', 'Ripeness Level', 'Appropriate ripeness for transport', 'grade', 2, 5, 20.00, TRUE),
('Vegetables', 'Pesticide Residue', 'Pesticide residue test result', 'ppm', 0, 0.5, 10.00, TRUE),

('Fruits', 'Sugar Content', 'Brix measurement for sweetness', 'brix', 8, 25, 25.00, FALSE),
('Fruits', 'Firmness', 'Fruit firmness measurement', 'grade', 2, 5, 20.00, TRUE),
('Fruits', 'Visual Defects', 'Percentage of visual defects', '%', 0, 15, 25.00, TRUE),
('Fruits', 'Size Grade', 'Size classification', 'grade', 1, 5, 15.00, FALSE),
('Fruits', 'Color Development', 'Color maturity assessment', 'grade', 2, 5, 15.00, FALSE);

-- Update existing quality reports with inspector IDs
UPDATE quality_reports qr 
SET inspector_id = (
    SELECT i.id 
    FROM inspectors i 
    WHERE i.inspector_name = qr.inspector_name 
    LIMIT 1
);

-- Create indexes for better performance
CREATE INDEX idx_inspectors_type ON inspectors(inspector_type);
CREATE INDEX idx_inspectors_location ON inspectors(location_state, location_district);
CREATE INDEX idx_inspection_requests_status ON inspection_requests(status);
CREATE INDEX idx_inspection_requests_order ON inspection_requests(order_id);
CREATE INDEX idx_quality_reports_inspector ON quality_reports(inspector_id);
CREATE INDEX idx_inspection_results_report ON inspection_results(quality_report_id);

-- Create a view for complete inspection details
CREATE VIEW inspection_summary AS
SELECT 
    qr.report_id,
    qr.order_id,
    qr.product_name,
    qr.farmer_name,
    qr.quality_grade,
    qr.overall_rating,
    qr.inspection_date,
    i.inspector_name,
    i.inspector_type,
    i.company_organization,
    i.license_number,
    i.specialization,
    COUNT(ir.id) as criteria_tested,
    AVG(CASE WHEN ir.passed THEN 1 ELSE 0 END) * 100 as pass_percentage
FROM quality_reports qr
LEFT JOIN inspectors i ON qr.inspector_id = i.id
LEFT JOIN inspection_results ir ON qr.report_id = ir.quality_report_id
GROUP BY qr.report_id;
