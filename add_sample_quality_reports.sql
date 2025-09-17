-- Add sample quality reports data
USE farmer_marketplace;

-- Insert sample quality reports for existing orders
INSERT INTO quality_reports (order_id, product_name, farmer_name, quality_grade, inspection_date, inspector_name, report_details, overall_rating) VALUES
(1, 'Organic Tomatoes', 'John Farmer', 'A', '2024-07-27', 'Dr. Rajesh Kumar', 'Excellent quality organic tomatoes. Fresh, well-formed fruits with no visible defects. Moisture content within acceptable limits. Color and texture are excellent.', 5),
(2, 'Basmati Rice', 'John Farmer', 'B', '2024-07-26', 'Inspector Priya Singh', 'Good quality basmati rice with proper grain length and aroma. Minor discoloration observed in 2% of grains but within acceptable standards. Overall quality is satisfactory.', 4);
