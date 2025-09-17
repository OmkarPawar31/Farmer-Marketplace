<?php
// Utility functions for Farmer Marketplace

/**
 * Sanitize user input to prevent XSS attacks
 * @param string $data The input data to sanitize
 * @return string Sanitized data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool True if valid, false otherwise
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate a secure random token
 * @param int $length Length of the token
 * @return string Random token
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Format currency value
 * @param float $amount Amount to format
 * @param string $currency Currency symbol
 * @return string Formatted currency
 */
function format_currency($amount, $currency = '₹') {
    return $currency . number_format($amount, 2);
}

/**
 * Time ago function
 * @param string $datetime Datetime string
 * @return string Time ago string
 */
function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user has specific role
 * @param string $role Role to check
 * @return bool True if user has role, false otherwise
 */
function has_role($role) {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === $role;
}

/**
 * Redirect to login if not authenticated
 * @param string $redirect_url URL to redirect to after login
 */
function require_login($redirect_url = '../login.php') {
    if (!is_logged_in()) {
        header("Location: $redirect_url");
        exit();
    }
}

/**
 * Generate star rating HTML
 * @param float $rating Rating value (0-5)
 * @param bool $show_number Whether to show the number
 * @return string HTML for star rating
 */
function generate_star_rating($rating, $show_number = true) {
    $html = '<div class="star-rating">';
    
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color: #ffc107;"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $html .= '<i class="fas fa-star-half-alt" style="color: #ffc107;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color: #ffc107;"></i>';
        }
    }
    
    if ($show_number) {
        $html .= ' <span class="rating-number">(' . number_format($rating, 1) . ')</span>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * Upload file with validation
 * @param array $file $_FILES array element
 * @param string $upload_dir Directory to upload to
 * @param array $allowed_types Allowed file types
 * @param int $max_size Maximum file size in bytes
 * @return array Result array with success/error
 */
function upload_file($file, $upload_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'gif'], $max_size = 5242880) {
    $result = ['success' => false, 'message' => '', 'filename' => ''];
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $result['message'] = 'File upload failed';
        return $result;
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        $result['message'] = 'File size too large. Maximum size is ' . ($max_size / 1024 / 1024) . 'MB';
        return $result;
    }
    
    // Check file type
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_types)) {
        $result['message'] = 'Invalid file type. Allowed types: ' . implode(', ', $allowed_types);
        return $result;
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . time() . '.' . $file_extension;
    $full_path = $upload_dir . '/' . $filename;
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $full_path)) {
        $result['success'] = true;
        $result['filename'] = $filename;
        $result['message'] = 'File uploaded successfully';
    } else {
        $result['message'] = 'Failed to move uploaded file';
    }
    
    return $result;
}

/**
 * Send notification (placeholder for future implementation)
 * @param int $user_id User ID to send notification to
 * @param string $message Notification message
 * @param string $type Notification type
 * @return bool Success status
 */
function send_notification($user_id, $message, $type = 'info') {
    // TODO: Implement notification system
    // This could store in database, send email, etc.
    return true;
}

/**
 * Log activity (placeholder for future implementation)
 * @param int $user_id User ID
 * @param string $action Action performed
 * @param string $details Additional details
 * @return bool Success status
 */
function log_activity($user_id, $action, $details = '') {
    // TODO: Implement activity logging
    // This could store in database for audit trail
    return true;
}

/**
 * Get user's full name for display
 * @param array $user User data array
 * @return string Formatted name
 */
function get_display_name($user) {
    if (!empty($user['business_name'])) {
        return $user['business_name'];
    }
    return $user['full_name'] ?? $user['name'] ?? 'Unknown User';
}

/**
 * Calculate distance between two coordinates (placeholder)
 * @param float $lat1 Latitude 1
 * @param float $lon1 Longitude 1
 * @param float $lat2 Latitude 2
 * @param float $lon2 Longitude 2
 * @return float Distance in kilometers
 */
function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    // Haversine formula for calculating distance
    $earth_radius = 6371; // km
    
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    
    $a = sin($dlat/2) * sin($dlat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon/2) * sin($dlon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c;
}

/**
 * Create JSON response
 * @param bool $success Success status
 * @param string $message Response message
 * @param array $data Additional data
 * @return string JSON response
 */
function json_response($success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    
    header('Content-Type: application/json');
    return json_encode($response);
}
?>
