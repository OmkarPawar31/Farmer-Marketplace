-- Update admin user passwords with correct hash for 'admin123'
USE farmer_marketplace;

UPDATE users 
SET password = '$2y$10$KTRA5szhKnFcjAlNA1rKG.nzLmXc0YFB2vfh6R8V8XXWXUZU/CBa2'
WHERE user_type = 'admin';
