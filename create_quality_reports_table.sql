-- Create quality_reports table for farmer marketplace
USE farmer_marketplace;

-- Quality reports table (for buyer quality report functionality)
CREATE TABLE IF NOT EXISTS quality_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    farmer_name VARCHAR(200) NOT NULL,
    quality_grade ENUM('A', 'B', 'C', 'D') NOT NULL,
    inspection_date DATE NOT NULL,
    inspector_name VARCHAR(100) NOT NULL,
    report_details TEXT,
    overall_rating INT NOT NULL CHECK (overall_rating >= 1 AND overall_rating <= 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Create index for better performance
CREATE INDEX idx_quality_reports_order ON quality_reports(order_id);
CREATE INDEX idx_quality_reports_date ON quality_reports(inspection_date);

-- Insert some sample data for testing
INSERT INTO quality_reports (order_id, product_name, farmer_name, quality_grade, inspection_date, inspector_name, report_details, overall_rating) VALUES
(1, 'Basmati Rice', 'Raj Kumar', 'A', '2024-07-25', 'Dr. Sharma', 'Excellent quality rice with proper moisture content and minimal foreign matter.', 5),
(2, 'Wheat', 'Priya Singh', 'B', '2024-07-24', 'Inspector Gupta', 'Good quality wheat, slight discoloration observed but within acceptable limits.', 4),
(3, 'Tomatoes', 'Amit Patel', 'A', '2024-07-23', 'Quality Team Lead', 'Fresh tomatoes with excellent color and firmness. No defects found.', 5);
