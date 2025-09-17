<?php
// Security Configuration for Farmer Marketplace
class SecurityConfig {
    // Database configuration
    const DB_HOST = 'localhost';
    const DB_NAME = 'farmer_marketplace';
    const DB_USER = 'root';
    const DB_PASS = '';
    
    // Security settings
    const SESSION_TIMEOUT = 3600; // 1 hour
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_TIME = 900; // 15 minutes
    const PASSWORD_MIN_LENGTH = 8;
    const MFA_ENABLED = true;
    
    // Encryption settings
    const ENCRYPTION_METHOD = 'AES-256-CBC';
    const HASH_ALGORITHM = 'sha256';
    
    // File upload settings
    const MAX_FILE_SIZE = 5242880; // 5MB
    const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif'];
    const ALLOWED_DOCUMENT_TYPES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    
    // JWT settings
    const JWT_SECRET = 'farmer_marketplace_jwt_secret_key_2024';
    const JWT_EXPIRY = 86400; // 24 hours
    
    // API rate limiting
    const API_RATE_LIMIT = 100; // requests per hour
    
    // GDPR settings
    const DATA_RETENTION_DAYS = 2555; // 7 years
    const CONSENT_REQUIRED = true;
    
    // Audit logging
    const AUDIT_LOG_ENABLED = true;
    const LOG_SENSITIVE_DATA = false;
    
    public static function getEncryptionKey() {
        // In production, store this in environment variables
        return hash('sha256', 'farmer_marketplace_encryption_key_2024');
    }
    
    public static function getDatabaseConnection() {
        try {
            $pdo = new PDO(
                "mysql:host=" . self::DB_HOST . ";dbname=" . self::DB_NAME . ";charset=utf8mb4",
                self::DB_USER,
                self::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            return $pdo;
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
}

// Security headers
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net; style-src \'self\' \'unsafe-inline\' https://cdnjs.cloudflare.com; img-src \'self\' data: https:; font-src \'self\' https://cdnjs.cloudflare.com;');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Initialize security headers
setSecurityHeaders();
?>
