<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    header('Location: login.php');
    exit();
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Update profile
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $farm_name = trim($_POST['farm_name']);
        $farm_size = $_POST['farm_size'];
        $farm_address = trim($_POST['farm_address']);
        $state = trim($_POST['state']);
        $district = trim($_POST['district']);
        $village = trim($_POST['village']);
        $pincode = trim($_POST['pincode']);
        $experience_years = $_POST['experience_years'];
        $aadhaar_number = trim($_POST['aadhaar_number']);
        $pan_number = trim($_POST['pan_number']);
        $bank_account = trim($_POST['bank_account']);
        $ifsc_code = trim($_POST['ifsc_code']);
        $preferred_language = $_POST['preferred_language'];
        
        try {
            $db->beginTransaction();
            
            // Check if username or email already exists for other users
            $check_stmt = $db->prepare("
                SELECT id FROM users 
                WHERE (username = ? OR email = ?) AND id != ?
            ");
            $check_stmt->execute([$username, $email, $user_id]);
            
            if ($check_stmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
                exit();
            }
            
            // Update users table
            $update_user_stmt = $db->prepare("
                UPDATE users SET 
                    username = ?, 
                    email = ?, 
                    phone = ?, 
                    preferred_language = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_user_stmt->execute([$username, $email, $phone, $preferred_language, $user_id]);
            
            // Update farmers table
            $update_farmer_stmt = $db->prepare("
                UPDATE farmers SET 
                    farm_name = ?, 
                    farm_size = ?, 
                    farm_address = ?, 
                    state = ?, 
                    district = ?, 
                    village = ?, 
                    pincode = ?, 
                    experience_years = ?, 
                    aadhaar_number = ?, 
                    pan_number = ?, 
                    bank_account = ?, 
                    ifsc_code = ?
                WHERE user_id = ?
            ");
            $update_farmer_stmt->execute([
                $farm_name, $farm_size, $farm_address, $state, $district, 
                $village, $pincode, $experience_years, $aadhaar_number, 
                $pan_number, $bank_account, $ifsc_code, $user_id
            ]);
            
            // Update session variables
            $_SESSION['username'] = $username;
            $_SESSION['farm_name'] = $farm_name;
            $_SESSION['farm_address'] = $farm_address;
            $_SESSION['farm_size'] = $farm_size;
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
            
        } catch (PDOException $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error updating profile: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // Change password
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($new_password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit();
        }
        
        if (strlen($new_password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
            exit();
        }
        
        try {
            // Verify current password
            $verify_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $verify_stmt->execute([$user_id]);
            $user = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current_password, $user['password'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit();
            }
            
            // Update password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_password_hash, $user_id]);
            
            echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error changing password.']);
        }
        exit();
    }
}

// Fetch current user data
$user_stmt = $db->prepare("
    SELECT u.*, f.*
    FROM users u
    LEFT JOIN farmers f ON u.id = f.user_id
    WHERE u.id = ?
");
$user_stmt->execute([$user_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Farmer Marketplace</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 80px 20px 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }

        .page-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .profile-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }

        .profile-sidebar {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            height: fit-content;
        }

        .profile-avatar {
            text-align: center;
            margin-bottom: 30px;
        }

        .avatar-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2a9d8f, #e9c46a);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 3rem;
            color: white;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: #264653;
            margin-bottom: 5px;
        }

        .profile-type {
            color: #666;
            font-size: 14px;
        }

        .profile-stats {
            margin-top: 20px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }

        .stat-value {
            font-weight: 500;
            color: #264653;
        }

        .profile-main {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .tab-nav {
            display: flex;
            gap: 5px;
            margin-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }

        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            color: #666;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            color: #2a9d8f;
            border-bottom-color: #2a9d8f;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #264653;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2a9d8f;
            box-shadow: 0 0 0 3px rgba(42, 157, 143, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .required {
            color: #e76f51;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .btn-cancel, .btn-save {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-cancel {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #ddd;
        }

        .btn-cancel:hover {
            background: #e9ecef;
        }

        .btn-save {
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }

        .btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            transform: translateX(400px);
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 300px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .notification.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        @media (max-width: 768px) {
            .profile-content {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .tab-nav {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-user-edit"></i> Edit Profile</h1>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="profile-content">
            <!-- Profile Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="profile-name"><?php echo htmlspecialchars($user_data['username']); ?></div>
                    <div class="profile-type">Farmer</div>
                </div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-value"><?php echo date('M Y', strtotime($user_data['created_at'])); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Farm Size</span>
                        <span class="stat-value"><?php echo number_format($user_data['farm_size'] ?? 0, 2); ?> acres</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Experience</span>
                        <span class="stat-value"><?php echo $user_data['experience_years'] ?? 'Not specified'; ?> years</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Status</span>
                        <span class="stat-value" style="color: #2a9d8f;">
                            <i class="fas fa-check-circle"></i> <?php echo ucfirst($user_data['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Profile Main Content -->
            <div class="profile-main">
                <!-- Tab Navigation -->
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="switchTab('profile')">
                        <i class="fas fa-user"></i> Profile Information
                    </button>
                    <button class="tab-btn" onclick="switchTab('security')">
                        <i class="fas fa-lock"></i> Security
                    </button>
                </div>

                <!-- Profile Tab -->
                <div id="profile-tab" class="tab-content active">
                    <form id="profileForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="username">Username <span class="required">*</span></label>
                                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address <span class="required">*</span></label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="phone">Phone Number <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="preferred_language">Preferred Language</label>
                                <select id="preferred_language" name="preferred_language">
                                    <option value="en" <?php echo ($user_data['preferred_language'] == 'en') ? 'selected' : ''; ?>>English</option>
                                    <option value="hi" <?php echo ($user_data['preferred_language'] == 'hi') ? 'selected' : ''; ?>>हिंदी</option>
                                    <option value="mr" <?php echo ($user_data['preferred_language'] == 'mr') ? 'selected' : ''; ?>>मराठी</option>
                                    <option value="gu" <?php echo ($user_data['preferred_language'] == 'gu') ? 'selected' : ''; ?>>ગુજરાતી</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="farm_name">Farm Name <span class="required">*</span></label>
                                <input type="text" id="farm_name" name="farm_name" value="<?php echo htmlspecialchars($user_data['farm_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="farm_size">Farm Size (acres) <span class="required">*</span></label>
                                <input type="number" id="farm_size" name="farm_size" step="0.01" min="0.01" value="<?php echo $user_data['farm_size']; ?>" required>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="farm_address">Farm Address</label>
                            <textarea id="farm_address" name="farm_address" placeholder="Enter your complete farm address"><?php echo htmlspecialchars($user_data['farm_address']); ?></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($user_data['state']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="district">District</label>
                                <input type="text" id="district" name="district" value="<?php echo htmlspecialchars($user_data['district']); ?>">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="village">Village</label>
                                <input type="text" id="village" name="village" value="<?php echo htmlspecialchars($user_data['village']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="pincode">PIN Code</label>
                                <input type="text" id="pincode" name="pincode" value="<?php echo htmlspecialchars($user_data['pincode']); ?>" pattern="[0-9]{6}" maxlength="6">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="experience_years">Years of Experience</label>
                                <input type="number" id="experience_years" name="experience_years" min="0" max="100" value="<?php echo $user_data['experience_years']; ?>">
                            </div>
                            <div class="form-group">
                                <label for="aadhaar_number">Aadhaar Number</label>
                                <input type="text" id="aadhaar_number" name="aadhaar_number" value="<?php echo htmlspecialchars($user_data['aadhaar_number']); ?>" pattern="[0-9]{12}" maxlength="12">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="pan_number">PAN Number</label>
                                <input type="text" id="pan_number" name="pan_number" value="<?php echo htmlspecialchars($user_data['pan_number']); ?>" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" maxlength="10" style="text-transform: uppercase;">
                            </div>
                            <div class="form-group">
                                <label for="bank_account">Bank Account Number</label>
                                <input type="text" id="bank_account" name="bank_account" value="<?php echo htmlspecialchars($user_data['bank_account']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="ifsc_code">IFSC Code</label>
                            <input type="text" id="ifsc_code" name="ifsc_code" value="<?php echo htmlspecialchars($user_data['ifsc_code']); ?>" pattern="[A-Z]{4}[0-9]{7}" maxlength="11" style="text-transform: uppercase;">
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-cancel" onclick="resetForm()">Reset</button>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Tab -->
                <div id="security-tab" class="tab-content">
                    <form id="passwordForm">
                        <div class="form-group">
                            <label for="current_password">Current Password <span class="required">*</span></label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password <span class="required">*</span></label>
                            <input type="password" id="new_password" name="new_password" minlength="8" required>
                            <small style="color: #666;">Password must be at least 8 characters long</small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn-cancel" onclick="clearPasswordForm()">Clear</button>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-lock"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab button
            event.target.classList.add('active');
        }

        // Profile form submission
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_profile');
            
            const submitBtn = this.querySelector('.btn-save');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while updating profile', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Password form submission
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                showNotification('New passwords do not match', 'error');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('action', 'change_password');
            
            const submitBtn = this.querySelector('.btn-save');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    clearPasswordForm();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while changing password', 'error');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Reset form to original values
        function resetForm() {
            document.getElementById('profileForm').reset();
            // You might want to reload the page to get fresh data from server
            location.reload();
        }

        // Clear password form
        function clearPasswordForm() {
            document.getElementById('passwordForm').reset();
        }

        // Notification function
        function showNotification(message, type = 'info') {
            const existingNotification = document.querySelector('.notification');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';
            
            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification && notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 4000);
        }

        // Format PAN and IFSC to uppercase
        document.getElementById('pan_number').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        document.getElementById('ifsc_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Validate Aadhaar number (12 digits only)
        document.getElementById('aadhaar_number').addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 12);
        });

        // Validate PIN code (6 digits only)
        document.getElementById('pincode').addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    </script>
</body>
</html>
