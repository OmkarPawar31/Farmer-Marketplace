-- Extended Auction System Schema for Real-time Bidding
-- This extends the existing auction system with additional features

USE farmer_marketplace;

-- Auction activity log for real-time tracking
CREATE TABLE auction_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    user_id INT NOT NULL,
    activity_type ENUM('bid_placed', 'bid_outbid', 'auction_won', 'auction_lost', 'bid_withdrawn', 'auto_bid_triggered') NOT NULL,
    bid_amount DECIMAL(10,2),
    previous_bid DECIMAL(10,2),
    message TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Bid increments configuration for different auction types
CREATE TABLE bid_increments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    min_bid_amount DECIMAL(10,2) NOT NULL,
    max_bid_amount DECIMAL(10,2) NOT NULL,
    increment_amount DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Auto-bidding system for buyers
CREATE TABLE auto_bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    buyer_id INT NOT NULL,
    max_bid_amount DECIMAL(10,2) NOT NULL,
    increment_amount DECIMAL(10,2) DEFAULT 1.00,
    is_active BOOLEAN DEFAULT TRUE,
    total_bids_placed INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES buyers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_auction_buyer (auction_id, buyer_id)
);

-- Watchlist for auctions that users want to monitor
CREATE TABLE auction_watchlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    user_id INT NOT NULL,
    notification_preferences JSON, -- {'bid_outbid': true, 'ending_soon': true, 'won': true}
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_auction_user (auction_id, user_id)
);

-- Auction categories for better organization
CREATE TABLE auction_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Extend auctions table with additional fields
ALTER TABLE auctions 
ADD COLUMN auction_category_id INT AFTER product_id,
ADD COLUMN reserve_price DECIMAL(10,2) AFTER start_price,
ADD COLUMN bid_increment DECIMAL(10,2) DEFAULT 1.00 AFTER current_bid,
ADD COLUMN auto_extend_minutes INT DEFAULT 5 AFTER end_time,
ADD COLUMN total_bids INT DEFAULT 0 AFTER status,
ADD COLUMN unique_bidders INT DEFAULT 0 AFTER total_bids,
ADD COLUMN auction_type ENUM('standard', 'reserve', 'dutch', 'sealed_bid') DEFAULT 'standard' AFTER auction_category_id,
ADD COLUMN min_participants INT DEFAULT 1 AFTER auction_type,
ADD COLUMN max_participants INT AFTER min_participants,
ADD COLUMN auction_rules JSON AFTER max_participants, -- Custom rules for the auction
ADD COLUMN inspection_period_hours INT DEFAULT 24 AFTER auction_rules,
ADD COLUMN payment_deadline_hours INT DEFAULT 72 AFTER inspection_period_hours,
ADD COLUMN extended_count INT DEFAULT 0 AFTER auto_extend_minutes,
ADD COLUMN max_extensions INT DEFAULT 3 AFTER extended_count,
ADD FOREIGN KEY (auction_category_id) REFERENCES auction_categories(id);

-- Auction participants tracking
CREATE TABLE auction_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    highest_bid DECIMAL(10,2) DEFAULT 0,
    total_bids INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_auction_participant (auction_id, user_id)
);

-- Real-time auction room sessions for WebSocket connections
CREATE TABLE auction_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    session_id VARCHAR(128) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    connection_data JSON, -- Store WebSocket connection metadata
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Auction analytics for performance tracking
CREATE TABLE auction_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    total_views INT DEFAULT 0,
    unique_viewers INT DEFAULT 0,
    total_participants INT DEFAULT 0,
    total_bids INT DEFAULT 0,
    average_bid_time DECIMAL(8,2), -- seconds between bids
    peak_concurrent_users INT DEFAULT 0,
    final_sale_price DECIMAL(10,2),
    price_appreciation_percent DECIMAL(5,2), -- % increase from start to final price
    duration_minutes INT,
    extensions_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE
);

-- Bid history with enhanced tracking
ALTER TABLE bids 
ADD COLUMN bid_increment DECIMAL(10,2) AFTER bid_amount,
ADD COLUMN auto_bid BOOLEAN DEFAULT FALSE AFTER quantity,
ADD COLUMN ip_address VARCHAR(45) AFTER message,
ADD COLUMN user_agent TEXT AFTER ip_address,
ADD COLUMN bid_time_seconds DECIMAL(8,2) AFTER user_agent, -- Time taken to place bid
ADD COLUMN outbid_at TIMESTAMP NULL AFTER expires_at,
ADD COLUMN outbid_by_user_id INT NULL AFTER outbid_at,
ADD FOREIGN KEY (outbid_by_user_id) REFERENCES users(id);

-- Auction dispute system
CREATE TABLE auction_disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    complainant_id INT NOT NULL,
    respondent_id INT NOT NULL,
    dispute_type ENUM('quality', 'payment', 'delivery', 'description', 'other') NOT NULL,
    description TEXT NOT NULL,
    evidence JSON, -- Store file paths for evidence
    status ENUM('open', 'under_review', 'resolved', 'rejected') DEFAULT 'open',
    resolution TEXT,
    resolved_by INT, -- Admin user who resolved
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auctions(id) ON DELETE CASCADE,
    FOREIGN KEY (complainant_id) REFERENCES users(id),
    FOREIGN KEY (respondent_id) REFERENCES users(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- Insert default bid increments
INSERT INTO bid_increments (min_bid_amount, max_bid_amount, increment_amount) VALUES
(0.00, 100.00, 1.00),
(100.01, 500.00, 5.00),
(500.01, 1000.00, 10.00),
(1000.01, 5000.00, 25.00),
(5000.01, 10000.00, 50.00),
(10000.01, 999999.99, 100.00);

-- Insert default auction categories
INSERT INTO auction_categories (name, description) VALUES
('Fresh Produce', 'Fruits, vegetables and perishable items'),
('Grains & Cereals', 'Rice, wheat, corn and other grains'),
('Pulses & Legumes', 'Lentils, chickpeas, beans'),
('Spices & Condiments', 'Fresh and dried spices'),
('Cash Crops', 'Cotton, sugarcane, tobacco'),
('Organic Produce', 'Certified organic products'),
('Bulk Commodities', 'Large quantity wholesale items'),
('Seasonal Specials', 'Season-specific crops and produce');

-- Create comprehensive indexes for performance
CREATE INDEX idx_auction_activity_auction ON auction_activity_log(auction_id);
CREATE INDEX idx_auction_activity_user ON auction_activity_log(user_id);
CREATE INDEX idx_auction_activity_type ON auction_activity_log(activity_type);
CREATE INDEX idx_auction_activity_time ON auction_activity_log(created_at);

CREATE INDEX idx_auto_bids_auction ON auto_bids(auction_id);
CREATE INDEX idx_auto_bids_buyer ON auto_bids(buyer_id);
CREATE INDEX idx_auto_bids_active ON auto_bids(is_active);

CREATE INDEX idx_watchlist_auction ON auction_watchlist(auction_id);
CREATE INDEX idx_watchlist_user ON auction_watchlist(user_id);

CREATE INDEX idx_participants_auction ON auction_participants(auction_id);
CREATE INDEX idx_participants_user ON auction_participants(user_id);
CREATE INDEX idx_participants_active ON auction_participants(is_active);

CREATE INDEX idx_sessions_auction ON auction_sessions(auction_id);
CREATE INDEX idx_sessions_user ON auction_sessions(user_id);
CREATE INDEX idx_sessions_active ON auction_sessions(is_active);
CREATE INDEX idx_sessions_activity ON auction_sessions(last_activity);

CREATE INDEX idx_analytics_auction ON auction_analytics(auction_id);

CREATE INDEX idx_disputes_auction ON auction_disputes(auction_id);
CREATE INDEX idx_disputes_status ON auction_disputes(status);
CREATE INDEX idx_disputes_type ON auction_disputes(dispute_type);

CREATE INDEX idx_bids_outbid ON bids(outbid_at);
CREATE INDEX idx_bids_auto ON bids(auto_bid);

-- Create views for common queries
CREATE VIEW active_auctions AS
SELECT 
    a.*,
    pl.title as product_title,
    pl.quantity_available,
    pl.unit,
    c.name as crop_name,
    f.farm_name,
    u.username as farmer_username,
    ac.name as category_name
FROM auctions a
JOIN product_listings pl ON a.product_id = pl.id
JOIN crops c ON pl.crop_id = c.id
JOIN farmers f ON pl.farmer_id = f.id
JOIN users u ON f.user_id = u.id
LEFT JOIN auction_categories ac ON a.auction_category_id = ac.id
WHERE a.status = 'active' AND a.end_time > NOW();

CREATE VIEW auction_leaderboard AS
SELECT 
    a.id as auction_id,
    a.product_id,
    pl.title as product_title,
    b.buyer_id,
    u.username as buyer_username,
    b.bid_amount as highest_bid,
    b.created_at as bid_time,
    ROW_NUMBER() OVER (PARTITION BY a.id ORDER BY b.bid_amount DESC, b.created_at ASC) as rank_position
FROM auctions a
JOIN bids b ON a.id = b.auction_id
JOIN product_listings pl ON a.product_id = pl.id
JOIN buyers buy ON b.buyer_id = buy.id
JOIN users u ON buy.user_id = u.id
WHERE a.status = 'active' AND b.status = 'active'
ORDER BY a.id, b.bid_amount DESC, b.created_at ASC;

CREATE VIEW user_auction_summary AS
SELECT 
    u.id as user_id,
    u.username,
    u.user_type,
    COUNT(DISTINCT CASE WHEN a.status = 'active' THEN ap.auction_id END) as active_participations,
    COUNT(DISTINCT CASE WHEN a.status = 'completed' AND a.winner_id = CASE WHEN u.user_type = 'buyer' THEN buy.id ELSE NULL END THEN a.id END) as auctions_won,
    COUNT(DISTINCT CASE WHEN u.user_type = 'farmer' THEN fa.id END) as auctions_created,
    SUM(CASE WHEN a.status = 'completed' AND a.winner_id = CASE WHEN u.user_type = 'buyer' THEN buy.id ELSE NULL END THEN a.current_bid ELSE 0 END) as total_winnings,
    AVG(CASE WHEN b.buyer_id = buy.id THEN b.bid_amount END) as avg_bid_amount
FROM users u
LEFT JOIN buyers buy ON u.id = buy.user_id
LEFT JOIN farmers f ON u.id = f.user_id
LEFT JOIN auction_participants ap ON u.id = ap.user_id
LEFT JOIN auctions a ON ap.auction_id = a.id
LEFT JOIN auctions fa ON f.id = (SELECT farmers.id FROM farmers JOIN product_listings ON farmers.id = product_listings.farmer_id WHERE product_listings.id = fa.product_id)
LEFT JOIN bids b ON buy.id = b.buyer_id
GROUP BY u.id, u.username, u.user_type;

-- Triggers for auction management

-- Trigger to update auction statistics when a bid is placed
DELIMITER $$
CREATE TRIGGER update_auction_on_bid 
AFTER INSERT ON bids
FOR EACH ROW
BEGIN
    DECLARE bid_count INT DEFAULT 0;
    DECLARE bidder_count INT DEFAULT 0;
    
    -- Update current bid in auctions table
    UPDATE auctions 
    SET current_bid = NEW.bid_amount,
        total_bids = total_bids + 1,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.auction_id;
    
    -- Count unique bidders
    SELECT COUNT(DISTINCT buyer_id) INTO bidder_count
    FROM bids 
    WHERE auction_id = NEW.auction_id AND status = 'active';
    
    UPDATE auctions 
    SET unique_bidders = bidder_count
    WHERE id = NEW.auction_id;
    
    -- Update auction participants
    INSERT INTO auction_participants (auction_id, user_id, highest_bid, total_bids)
    VALUES (NEW.auction_id, (SELECT user_id FROM buyers WHERE id = NEW.buyer_id), NEW.bid_amount, 1)
    ON DUPLICATE KEY UPDATE
        highest_bid = GREATEST(highest_bid, NEW.bid_amount),
        total_bids = total_bids + 1;
    
    -- Mark previous bids as outbid
    UPDATE bids 
    SET status = 'rejected', 
        outbid_at = CURRENT_TIMESTAMP,
        outbid_by_user_id = (SELECT user_id FROM buyers WHERE id = NEW.buyer_id)
    WHERE auction_id = NEW.auction_id 
    AND bid_amount < NEW.bid_amount 
    AND status = 'active'
    AND id != NEW.id;
    
    -- Log activity
    INSERT INTO auction_activity_log (auction_id, user_id, activity_type, bid_amount, previous_bid)
    SELECT NEW.auction_id, 
           (SELECT user_id FROM buyers WHERE id = NEW.buyer_id),
           'bid_placed',
           NEW.bid_amount,
           (SELECT current_bid FROM auctions WHERE id = NEW.auction_id);
END$$

-- Trigger to handle auction completion
CREATE TRIGGER complete_auction
AFTER UPDATE ON auctions
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' AND OLD.status != 'completed' THEN
        -- Set winner
        UPDATE auctions a
        SET winner_id = (
            SELECT b.buyer_id 
            FROM bids b 
            WHERE b.auction_id = a.id 
            AND b.status = 'active'
            ORDER BY b.bid_amount DESC, b.created_at ASC
            LIMIT 1
        )
        WHERE a.id = NEW.id;
        
        -- Update analytics
        INSERT INTO auction_analytics (
            auction_id, 
            total_bids, 
            total_participants, 
            final_sale_price,
            price_appreciation_percent,
            duration_minutes,
            extensions_used
        )
        VALUES (
            NEW.id,
            NEW.total_bids,
            NEW.unique_bidders,
            NEW.current_bid,
            CASE WHEN NEW.start_price > 0 THEN ((NEW.current_bid - NEW.start_price) / NEW.start_price) * 100 ELSE 0 END,
            TIMESTAMPDIFF(MINUTE, NEW.created_at, NEW.updated_at),
            NEW.extended_count
        )
        ON DUPLICATE KEY UPDATE
            total_bids = VALUES(total_bids),
            total_participants = VALUES(total_participants),
            final_sale_price = VALUES(final_sale_price),
            price_appreciation_percent = VALUES(price_appreciation_percent),
            duration_minutes = VALUES(duration_minutes),
            extensions_used = VALUES(extensions_used);
            
        -- Log winner activity
        INSERT INTO auction_activity_log (auction_id, user_id, activity_type, bid_amount)
        SELECT NEW.id, 
               (SELECT user_id FROM buyers WHERE id = NEW.winner_id),
               'auction_won',
               NEW.current_bid
        WHERE NEW.winner_id IS NOT NULL;
    END IF;
END$$

DELIMITER ;

-- Add auction-related settings
INSERT INTO settings (setting_key, setting_value, description) VALUES
('auction_auto_extend_minutes', '5', 'Minutes to extend auction when bid placed near end'),
('auction_max_extensions', '3', 'Maximum number of auto-extensions allowed'),
('auction_min_increment', '1.00', 'Minimum bid increment amount'),
('auction_session_timeout', '1800', 'WebSocket session timeout in seconds'),
('auction_inspection_hours', '24', 'Default inspection period after auction'),
('auction_payment_deadline_hours', '72', 'Payment deadline after winning auction');
