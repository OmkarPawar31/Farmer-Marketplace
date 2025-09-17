<?php
require_once 'config/security_config.php';

class Auth {
    private $pdo;

    public function __construct() {
        $this->pdo = SecurityConfig::getDatabaseConnection();
        session_start();
    }
    
    // Register new user
    public function register($username, $password, $email, $role) {
        if (strlen($password) < SecurityConfig::PASSWORD_MIN_LENGTH) {
            return ['error' => 'Password must be at least ' . SecurityConfig::PASSWORD_MIN_LENGTH . ' characters long.'];
        }
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (:username, :password, :email, :role)");
        $stmt->execute([':username' => $username, ':password' => $hashed_password, ':email' => $email, ':role' => $role]);

        return ['success' => 'User registered successfully.'];
    }

    // Login user
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            return ['success' => 'Logged in successfully.'];
        }
        return ['error' => 'Invalid username or password.'];
    }

    // Logout user
    public function logout() {
        session_unset();
        session_destroy();
        return ['success' => 'Logged out successfully.'];
    }

    // Check if the user is authenticated
    public function isAuthenticated() {
        return isset($_SESSION['user_id']);
    }

    // Check user role
    public function hasRole($role) {
        return $this->isAuthenticated() && $_SESSION['role'] === $role;
    }

    // Multi-factor authentication
    public function verifyMFA($userInput) {
        // Example: Verify with user's input (e.g. OTP)
        if (SecurityConfig::MFA_ENABLED) {
            // Implement MFA logic
            return true;
        }
        return true;
    }

    // Rate-limiting
    public function isRateLimited() {
        // Implement rate limiting logic
        return false;
    }
}

// Initialize the Auth class
$auth = new Auth();
?>
