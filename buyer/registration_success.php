<?php
session_start();

if (!isset($_SESSION['registration_success'])) {
    header('Location: register.php');
    exit();
}

$email = $_SESSION['registered_email'] ?? '';
unset($_SESSION['registration_success']);
unset($_SESSION['registered_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .success-container {
            max-width: 600px;
            margin: 100px auto;
            padding: 50px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .success-icon {
            font-size: 4rem;
            color: #16a34a;
            margin-bottom: 30px;
        }
        
        .success-title {
            color: #264653;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .success-message {
            color: #666;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .success-details {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        .detail-item:last-child {
            margin-bottom: 0;
        }
        
        .detail-item i {
            color: #2a9d8f;
            margin-right: 12px;
            width: 20px;
        }
        
        .success-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .success-container {
                margin: 50px 20px;
                padding: 30px;
            }
            
            .success-title {
                font-size: 2rem;
            }
            
            .success-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <h1 class="success-title">Registration Successful! 🎉</h1>
        
        <p class="success-message">
            Welcome to FarmConnect! Your buyer account has been created successfully. 
            We're excited to have you join our marketplace community.
        </p>
        
        <?php if ($email): ?>
        <p style="color: #2a9d8f; font-weight: 500; margin-bottom: 25px;">
            A confirmation email has been sent to: <strong><?php echo htmlspecialchars($email); ?></strong>
        </p>
        <?php endif; ?>
        
        <div class="success-details">
            <h3 style="color: #264653; margin-bottom: 20px; font-size: 1.2rem;">What happens next?</h3>
            
            <div class="detail-item">
                <i class="fas fa-clock"></i>
                <span>Your account is currently under review and will be activated within 24-48 hours</span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-envelope"></i>
                <span>You'll receive an email notification once your account is verified</span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-phone"></i>
                <span>Our team may contact you for additional verification if needed</span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-shopping-cart"></i>
                <span>Once verified, you can start browsing and purchasing from farmers</span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-handshake"></i>
                <span>Connect directly with farmers for bulk orders and contract farming</span>
            </div>
        </div>
        
        <div class="success-actions">
            <a href="login.php" class="btn btn-primary btn-large">
                <i class="fas fa-sign-in-alt"></i> Login to Your Account
            </a>
            <a href="../index.php" class="btn btn-secondary btn-large">
                <i class="fas fa-home"></i> Back to Homepage
            </a>
        </div>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <p style="color: #666; font-size: 14px;">
                Need help? Contact our support team at 
                <a href="mailto:support@farmconnect.com" style="color: #2a9d8f;">support@farmconnect.com</a> 
                or call <strong>1800-XXX-XXXX</strong>
            </p>
        </div>
    </div>
</body>
</html>
