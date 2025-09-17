<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Registration - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .registration-container {
            max-width: 600px;
            margin: 80px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        
        .registration-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .registration-header h1 {
            color: #264653;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .registration-header p {
            color: #666;
            font-size: 1rem;
        }
        
        .form-step {
            padding: 20px 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #264653;
            font-weight: 500;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
            color: #666;
            font-size: 14px;
        }
        
        .form-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }
        
        .btn-prev-step {
            background: #e9ecef;
            color: #264653;
            border: none;
        }
        
        .btn-prev-step:hover {
            background: #dee2e6;
        }
        
        .step-indicators {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            gap: 10px;
        }
        
        .step-indicator {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            position: relative;
        }
        
        .step-indicator.active {
            background: #2a9d8f;
            color: white;
        }
        
        .step-indicator.completed {
            background: #e9c46a;
            color: white;
        }
        
        .step-indicator:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 30px;
            height: 2px;
            background: #e9ecef;
            transform: translateY(-50%);
        }
        
        .step-indicator.completed:not(:last-child)::after {
            background: #e9c46a;
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-header">
            <h1><i class="fas fa-seedling"></i> Farmer Registration</h1>
            <p>Join our marketplace and connect directly with buyers</p>
        </div>
        
        <!-- Step Indicators -->
        <div class="step-indicators">
            <div class="step-indicator" data-step="1">1</div>
            <div class="step-indicator" data-step="2">2</div>
            <div class="step-indicator" data-step="3">3</div>
        </div>
        
        <form id="farmerRegistrationForm" action="submit_registration.php" method="post" enctype="multipart/form-data">
            <!-- Step 1: Account Information -->
            <div class="form-step active" id="step1">
                <h2><i class="fas fa-user"></i> Account Information</h2>
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" placeholder="Enter 10-digit mobile number" required>
                    <small>Enter your 10-digit mobile number for registration and verification</small>
                </div>
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" minlength="8" required>
                    <small>Password must be at least 8 characters long with numbers and letters</small>
                </div>
                <div class="form-buttons">
                    <div></div>
                    <button type="button" class="btn btn-primary btn-next-step">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 2: Farm Details -->
            <div class="form-step" id="step2">
                <h2><i class="fas fa-tractor"></i> Farm Details</h2>
                <div class="form-group">
                    <label for="farm_name">Farm Name *</label>
                    <input type="text" id="farm_name" name="farm_name" required>
                </div>
                <div class="form-group">
                    <label for="farm_size">Farm Size (in acres) *</label>
                    <input type="number" id="farm_size" name="farm_size" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" placeholder="Village, District, State">
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-prev-step"><i class="fas fa-arrow-left"></i> Previous</button>
                    <button type="button" class="btn btn-primary btn-next-step">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 3: Documents Upload -->
            <div class="form-step" id="step3">
                <h2><i class="fas fa-upload"></i> Documents Upload</h2>
                <div class="form-group">
                    <label for="documents">Upload Documents</label>
                    <input type="file" id="documents" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
                    <small>Upload relevant documents like land records, identity proof, etc. (PDF, JPG, PNG)</small>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-prev-step"><i class="fas fa-arrow-left"></i> Previous</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Complete Registration</button>
                </div>
            </div>
        </form>
    </div>
    
    <script src="../js/multi-step-form.js"></script>
    <script>
        // Handle form submission
        document.getElementById('farmerRegistrationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
            submitBtn.disabled = true;
            
            fetch('submit_registration.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    alert('Registration successful! You can now login with your phone number.');
                    window.location.href = 'login.php';
                } else {
                    // Show error messages
                    alert('Registration failed:\n' + data.errors.join('\n'));
                }
            })
            .catch(error => {
                alert('Registration failed. Please try again.');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    </script>
</body>
</html>
