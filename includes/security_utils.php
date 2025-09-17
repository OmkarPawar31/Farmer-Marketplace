<?php
require_once '../config/security_config.php';

class SecurityUtils {
    private $pdo;
    private $encryptionKey;
    
    public function __construct() {
        $this->pdo = SecurityConfig::getDatabaseConnection();
        $this->encryptionKey = SecurityConfig::getEncryptionKey();
    }
    
    // Data encryption
    public function encrypt($data) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, SecurityConfig::ENCRYPTION_METHOD, $this->encryptionKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    // Data decryption
    public function decrypt($encryptedData) {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, SecurityConfig::ENCRYPTION_METHOD, $this->encryptionKey, 0, $iv);
    }
    
    // Validate file upload
    public function validateFileUpload($file, $allowedTypes, $maxSize) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'File upload error'];
        }
        
        if ($file['size'] > $maxSize) {
            return ['error' => 'File size exceeds maximum limit'];
        }
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedTypes)) {
            return ['error' => 'File type not allowed'];
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf'
        ];
        
        if (!isset($allowedMimes[$fileExtension]) || $allowedMimes[$fileExtension] !== $mimeType) {
            return ['error' => 'Invalid file type'];
        }
        
        return ['success' => true];
    }
    
    // Secure file upload
    public function secureFileUpload($file, $uploadDir, $allowedTypes) {
        $validation = $this->validateFileUpload($file, $allowedTypes, SecurityConfig::MAX_FILE_SIZE);
        
        if (isset($validation['error'])) {
            return $validation;
        }
        
        // Generate secure filename
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
        $filePath = $uploadDir . '/' . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            // Log file upload
            $this->logActivity('FILE_UPLOAD', 'File uploaded: ' . $fileName);
            return ['success' => true, 'filename' => $fileName, 'path' => $filePath];
        }
        
        return ['error' => 'Failed to upload file'];
    }
    
    // Audit logging
    public function logActivity($action, $details, $userId = null) {
        if (!SecurityConfig::AUDIT_LOG_ENABLED) {
            return;
        }
        
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, timestamp) 
            VALUES (:user_id, :action, :details, :ip_address, :user_agent, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':details' => SecurityConfig::LOG_SENSITIVE_DATA ? $details : $this->sanitizeLogData($details),
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);
    }
    
    // Sanitize log data
    private function sanitizeLogData($data) {
        // Remove sensitive information from logs
        $sensitivePatterns = [
            '/password\s*[:=]\s*[^\s]+/i',
            '/token\s*[:=]\s*[^\s]+/i',
            '/secret\s*[:=]\s*[^\s]+/i',
            '/key\s*[:=]\s*[^\s]+/i'
        ];
        
        foreach ($sensitivePatterns as $pattern) {
            $data = preg_replace($pattern, '[REDACTED]', $data);
        }
        
        return $data;
    }
    
    // Rate limiting
    public function checkRateLimit($identifier, $limit = null, $window = 3600) {
        $limit = $limit ?? SecurityConfig::API_RATE_LIMIT;
        
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits 
            WHERE identifier = :identifier AND timestamp > DATE_SUB(NOW(), INTERVAL :window SECOND)
        ");
        $stmt->execute([':identifier' => $identifier, ':window' => $window]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $limit) {
            return false;
        }
        
        // Record this request
        $stmt = $this->pdo->prepare("INSERT INTO rate_limits (identifier, timestamp) VALUES (:identifier, NOW())");
        $stmt->execute([':identifier' => $identifier]);
        
        return true;
    }
    
    // GDPR compliance - Data export
    public function exportUserData($userId) {
        $tables = ['users', 'farmer_profiles', 'buyer_profiles', 'products', 'orders', 'messages'];
        $exportData = [];
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $exportData[$table] = $stmt->fetchAll();
        }
        
        $this->logActivity('DATA_EXPORT', 'User data exported', $userId);
        return $exportData;
    }
    
    // GDPR compliance - Data deletion
    public function deleteUserData($userId, $hardDelete = false) {
        if ($hardDelete) {
            // Hard delete - permanently remove data
            $tables = ['users', 'farmer_profiles', 'buyer_profiles', 'products', 'orders', 'messages'];
            
            foreach ($tables as $table) {
                $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $userId]);
            }
        } else {
            // Soft delete - anonymize data
            $stmt = $this->pdo->prepare("
                UPDATE users SET 
                username = CONCAT('deleted_', id),
                email = CONCAT('deleted_', id, '@deleted.com'),
                phone = NULL,
                deleted_at = NOW()
                WHERE id = :user_id
            ");
            $stmt->execute([':user_id' => $userId]);
        }
        
        $this->logActivity('DATA_DELETION', $hardDelete ? 'Hard delete' : 'Soft delete', $userId);
        return true;
    }
    
    // Data retention cleanup
    public function cleanupExpiredData() {
        $retentionDays = SecurityConfig::DATA_RETENTION_DAYS;
        
        // Clean up old audit logs
        $stmt = $this->pdo->prepare("DELETE FROM audit_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->execute([':days' => $retentionDays]);
        
        // Clean up old rate limit records
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmt->execute();
        
        $this->logActivity('DATA_CLEANUP', 'Expired data cleaned up');
        return true;
    }
    
    // Input validation and sanitization
    public function validateInput($input, $type) {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL);
            case 'phone':
                return preg_match('/^[+]?[0-9\s\-()]+$/', $input);
            case 'alphanumeric':
                return preg_match('/^[a-zA-Z0-9]+$/', $input);
            case 'numeric':
                return is_numeric($input);
            case 'text':
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
            default:
                return false;
        }
    }
    
    // Generate secure token
    public function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    // Hash data
    public function hashData($data) {
        return hash(SecurityConfig::HASH_ALGORITHM, $data);
    }
    
    // Verify CSRF token
    public function verifyCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    // Generate CSRF token
    public function generateCsrfToken() {
        $token = $this->generateSecureToken();
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
}
?>
