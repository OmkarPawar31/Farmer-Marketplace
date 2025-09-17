<?php
require_once 'config/database.php';

function alterTables() {
    $pdo = getDB();

    // Add full_name column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) AFTER username");
        echo "full_name column added to users table.\n";
    } catch (PDOException $e) {
        echo "Error adding full_name column: " . $e->getMessage() . "\n";
    }

    // Create farmer_stats table
    try {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS farmer_stats (
            id INT PRIMARY KEY AUTO_INCREMENT,
            farmer_id INT NOT NULL,
            total_products INT DEFAULT 0,
            total_orders INT DEFAULT 0,
            rating DECIMAL(3,2) DEFAULT 0.00,
            reviews_count INT DEFAULT 0,
            specialization TEXT,
            verified_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
        )
        ");
        echo "farmer_stats table created.\n";
    } catch (PDOException $e) {
        echo "Error creating farmer_stats table: " . $e->getMessage() . "\n";
    }

    // Update charsets
    try {
        $pdo->exec("ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("ALTER TABLE farmer_stats CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Character set updated.\n";
    } catch (PDOException $e) {
        echo "Error updating character sets: " . $e->getMessage() . "\n";
    }
}

alterTables();

?>
