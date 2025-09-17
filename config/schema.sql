-- Farmer Marketplace Database Schema
-- Create database
CREATE DATABASE IF NOT EXISTS farmer_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE farmer_marketplace;

-- Users table (main authentication table)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(15),
    user_type ENUM('farmer', 'buyer', 'admin') NOT NULL,
    status ENUM('active', 'inactive', 'pending', 'suspended') DEFAULT 'pending',
    verification_status ENUM('unverified', 'pending', 'verified') DEFAULT 'unverified',
    profile_image VARCHAR(255),
    preferred_language VARCHAR(10) DEFAULT 'en',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Farmers table (extended profile for farmers)
CREATE TABLE farmers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    farm_name VARCHAR(200),
    farm_size DECIMAL(10,2), -- in acres
    farm_address TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    state VARCHAR(50),
    district VARCHAR(50),
    village VARCHAR(100),
    pincode VARCHAR(10),
    aadhaar_number VARCHAR(12),
    pan_number VARCHAR(10),
    bank_account VARCHAR(20),
    ifsc_code VARCHAR(15),
    kisan_credit_card VARCHAR(20),
    farm_documents JSON, -- store document paths
    certifications JSON, -- organic, fair trade etc.
    experience_years INT,
    total_earnings DECIMAL(15,2) DEFAULT 0,
    rating DECIMAL(3,2) DEFAULT 0,
    total_orders INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Buyers table (extended profile for buyers)
CREATE TABLE buyers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    company_name VARCHAR(200),
    business_type ENUM('company', 'vendor', 'both') NOT NULL,
    business_registration VARCHAR(50),
    gst_number VARCHAR(15),
    business_address TEXT,
    state VARCHAR(50),
    district VARCHAR(50),
    pincode VARCHAR(10),
    contact_person VARCHAR(100),
    business_documents JSON,
    procurement_capacity DECIMAL(15,2), -- in tonnes per month
    payment_terms VARCHAR(100),
    rating DECIMAL(3,2) DEFAULT 0,
    total_purchases DECIMAL(15,2) DEFAULT 0,
    total_orders INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Crop categories
CREATE TABLE crop_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_hi VARCHAR(100), -- Hindi name
    name_regional VARCHAR(100), -- Regional language name
    description TEXT,
    image VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Crops table
CREATE TABLE crops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_hi VARCHAR(100),
    name_regional VARCHAR(100),
    scientific_name VARCHAR(100),
    description TEXT,
    image VARCHAR(255),
    season ENUM('kharif', 'rabi', 'zaid', 'perennial'),
    harvest_duration INT, -- days from planting to harvest
    storage_life INT, -- days after harvest
    nutritional_info JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES crop_categories(id)
);

-- Farmer crops (what farmers grow)
CREATE TABLE farmer_crops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT NOT NULL,
    crop_id INT NOT NULL,
    area_cultivated DECIMAL(10,2), -- in acres
    planting_date DATE,
    expected_harvest_date DATE,
    expected_quantity DECIMAL(10,2), -- in kg/tonnes
    organic_certified BOOLEAN DEFAULT FALSE,
    cultivation_method VARCHAR(50),
    irrigation_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
    FOREIGN KEY (crop_id) REFERENCES crops(id)
);

-- Product listings (crops available for sale)
CREATE TABLE product_listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT NOT NULL,
    crop_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    quantity_available DECIMAL(10,2) NOT NULL, -- in kg
    unit ENUM('kg', 'quintal', 'tonne') DEFAULT 'kg',
    price_per_unit DECIMAL(10,2) NOT NULL,
    minimum_order DECIMAL(10,2),
    harvest_date DATE,
    expiry_date DATE,
    quality_grade ENUM('A', 'B', 'C') DEFAULT 'A',
    organic_certified BOOLEAN DEFAULT FALSE,
    images JSON, -- array of image paths
    location_state VARCHAR(50),
    location_district VARCHAR(50),
    packaging_available BOOLEAN DEFAULT FALSE,
    delivery_available BOOLEAN DEFAULT FALSE,
    delivery_radius INT, -- in km
    status ENUM('active', 'sold', 'expired', 'inactive') DEFAULT 'active',
    views INT DEFAULT 0,
    featured BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
    FOREIGN KEY (crop_id) REFERENCES crops(id)
);

-- Orders table
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    delivery_address TEXT,
    delivery_date DATE,
    payment_method ENUM('bank_transfer', 'upi', 'cash', 'escrow') DEFAULT 'bank_transfer',
    payment_status ENUM('pending', 'paid', 'partial', 'refunded') DEFAULT 'pending',
    order_status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    quality_rating INT, -- 1-5 stars
    quality_notes TEXT,
    tracking_info JSON,
    commission_rate DECIMAL(5,2) DEFAULT 2.5, -- platform commission percentage
    commission_amount DECIMAL(10,2),
    farmer_earnings DECIMAL(15,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES buyers(id),
    FOREIGN KEY (farmer_id) REFERENCES farmers(id),
    FOREIGN KEY (product_id) REFERENCES product_listings(id)
);

-- Bidding system
CREATE TABLE bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    bid_amount DECIMAL(10,2) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    message TEXT,
    status ENUM('active', 'accepted', 'rejected', 'expired') DEFAULT 'active',
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES product_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES buyers(id)
);

-- Market prices (reference prices from mandis)
CREATE TABLE market_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    crop_id INT NOT NULL,
    market_name VARCHAR(100),
    state VARCHAR(50),
    district VARCHAR(50),
    min_price DECIMAL(10,2),
    max_price DECIMAL(10,2),
    modal_price DECIMAL(10,2),
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (crop_id) REFERENCES crops(id)
);

-- Reviews and ratings
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    reviewer_id INT NOT NULL, -- user who is giving review
    reviewee_id INT NOT NULL, -- user being reviewed
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    title VARCHAR(200),
    comment TEXT,
    images JSON,
    helpful_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id),
    FOREIGN KEY (reviewee_id) REFERENCES users(id)
);

-- Messages/Chat system
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    product_id INT, -- optional, if message is about specific product
    subject VARCHAR(200),
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    message_type ENUM('text', 'image', 'document') DEFAULT 'text',
    attachment VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    FOREIGN KEY (receiver_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES product_listings(id)
);

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('order', 'bid', 'payment', 'system', 'promotion') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Weather data (for crop planning)
CREATE TABLE weather_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(100) NOT NULL,
    state VARCHAR(50),
    district VARCHAR(50),
    temperature_min DECIMAL(5,2),
    temperature_max DECIMAL(5,2),
    humidity DECIMAL(5,2),
    rainfall DECIMAL(8,2),
    wind_speed DECIMAL(5,2),
    weather_condition VARCHAR(50),
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Support tickets
CREATE TABLE support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    category ENUM('technical', 'payment', 'dispute', 'general') DEFAULT 'general',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    assigned_to INT, -- admin user
    resolution TEXT,
    attachments JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- System settings
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('platform_commission', '1.5', 'Platform commission percentage'),
('max_bid_duration', '7', 'Maximum bid duration in days'),
('default_language', 'en', 'Default platform language'),
('sms_enabled', '1', 'SMS notifications enabled'),
('email_enabled', '1', 'Email notifications enabled'),
('maintenance_mode', '0', 'Platform maintenance mode');

-- Insert default crop categories
INSERT INTO crop_categories (name, name_hi, description) VALUES
('Cereals', 'अनाज', 'Rice, wheat, maize, barley etc.'),
('Pulses', 'दाल', 'Lentils, chickpeas, beans etc.'),
('Vegetables', 'सब्जियां', 'Fresh vegetables for daily consumption'),
('Fruits', 'फल', 'Fresh fruits and dry fruits'),
('Spices', 'मसाले', 'Spices and condiments'),
('Cash Crops', 'नकदी फसल', 'Cotton, sugarcane, tobacco etc.'),
('Oilseeds', 'तिलहन', 'Groundnut, sunflower, mustard etc.');

-- Insert some common crops
INSERT INTO crops (category_id, name, name_hi, scientific_name, season) VALUES
(1, 'Rice', 'चावल', 'Oryza sativa', 'kharif'),
(1, 'Wheat', 'गेहूं', 'Triticum aestivum', 'rabi'),
(1, 'Maize', 'मक्का', 'Zea mays', 'kharif'),
(2, 'Chickpea', 'चना', 'Cicer arietinum', 'rabi'),
(2, 'Pigeon Pea', 'अरहर', 'Cajanus cajan', 'kharif'),
(3, 'Tomato', 'टमाटर', 'Solanum lycopersicum', 'rabi'),
(3, 'Onion', 'प्याज', 'Allium cepa', 'rabi'),
(3, 'Potato', 'आलू', 'Solanum tuberosum', 'rabi'),
(4, 'Mango', 'आम', 'Mangifera indica', 'perennial'),
(4, 'Banana', 'केला', 'Musa acuminata', 'perennial'),
(5, 'Turmeric', 'हल्दी', 'Curcuma longa', 'kharif'),
(5, 'Chilli', 'मिर्च', 'Capsicum annuum', 'kharif');

-- Create indexes for better performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_type ON users(user_type);
CREATE INDEX idx_farmers_location ON farmers(state, district);
CREATE INDEX idx_buyers_type ON buyers(business_type);
CREATE INDEX idx_products_status ON product_listings(status);
CREATE INDEX idx_products_location ON product_listings(location_state, location_district);
CREATE INDEX idx_products_crop ON product_listings(crop_id);
CREATE INDEX idx_orders_status ON orders(order_status);
CREATE INDEX idx_orders_buyer ON orders(buyer_id);
CREATE INDEX idx_orders_farmer ON orders(farmer_id);
CREATE INDEX idx_bids_status ON bids(status);
CREATE INDEX idx_messages_receiver ON messages(receiver_id, is_read);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);

-- Auctions table for real-time bidding system
CREATE TABLE auctions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    start_price DECIMAL(10,2) NOT NULL,
    current_bid DECIMAL(10,2) NOT NULL,
    end_time TIMESTAMP NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    winner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES product_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_id) REFERENCES buyers(id)
);

-- Update bids table to link with auctions
ALTER TABLE bids ADD COLUMN auction_id INT AFTER product_id;
ALTER TABLE bids ADD FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE;

-- Inventory logs for audit purposes
CREATE TABLE inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    previous_quantity DECIMAL(10,2) NOT NULL,
    new_quantity DECIMAL(10,2) NOT NULL,
    changed_by INT NOT NULL,
    change_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES product_listings(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

-- Create indexes for auctions
CREATE INDEX idx_auctions_status ON auctions(status);
CREATE INDEX idx_auctions_end_time ON auctions(end_time);
CREATE INDEX idx_bids_auction ON bids(auction_id);
CREATE INDEX idx_inventory_logs_product ON inventory_logs(product_id);
CREATE INDEX idx_inventory_logs_date ON inventory_logs(created_at);
