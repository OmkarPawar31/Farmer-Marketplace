<?php
// Database configuration for XAMPP local environment
class Database {
    private $host = "localhost";
    private $db_name = "farmer_marketplace";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}

// Database connection instance
function getDB() {
    $database = new Database();
    return $database->getConnection();
}

// Configuration constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'farmer_marketplace');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/farmer-marketplace/');
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Session configuration
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
