ALTER TABLE product_listings ADD COLUMN listing_type ENUM('vendor', 'company', 'both') NOT NULL DEFAULT 'vendor';
