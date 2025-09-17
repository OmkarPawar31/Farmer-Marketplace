-- Create quality_reports table for farmer marketplace
USE farmer_marketplace;

-- Drop table if exists to recreate it properly
DROP TABLE IF EXISTS quality_reports;

-- Quality reports table (for buyer quality report functionality)
CREATE TABLE quality_reports (
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
