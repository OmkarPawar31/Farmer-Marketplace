-- Create admin user for farmer marketplace
USE farmer_marketplace;

-- Insert admin user (password is 'admin123' - you should change this!)
INSERT INTO users (username, email, password, user_type, status, verification_status, created_at) 
VALUES (
    'admin', 
    'admin@farmermarketplace.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: admin123
    'admin', 
    'active', 
    'verified', 
    NOW()
);

-- You can also create additional admin users if needed
INSERT INTO users (username, email, password, user_type, status, verification_status, created_at) 
VALUES (
    'superadmin', 
    'superadmin@farmermarketplace.com', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: admin123
    'admin', 
    'active', 
    'verified', 
    NOW()
);
