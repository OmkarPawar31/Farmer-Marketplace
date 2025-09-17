<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['user_type'] === 'buyer') {
    header('Location: dashboard.php');
    exit();
}

// Get any error messages from form submission
$errors = $_SESSION['registration_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['registration_errors'], $_SESSION['form_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Registration - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .registration-container {
            max-width: 700px;
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
            display: none;
        }
        
        .form-step.active {
            display: block;
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
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #2a9d8f;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
            color: #666;
            font-size: 14px;
        }
        
        .business-type-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .type-option {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .type-option:hover {
            border-color: #2a9d8f;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(42, 157, 143, 0.15);
        }
        
        .type-option.selected {
            border-color: #2a9d8f;
            background: linear-gradient(135deg, #f0fdfc 0%, #e6fffa 100%);
        }
        
        .type-option i {
            font-size: 2.5rem;
            color: #2a9d8f;
            margin-bottom: 15px;
        }
        
        .type-option h3 {
            color: #264653;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        
        .type-option p {
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .business-type-selector {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-header">
            <h1><i class="fas fa-shopping-cart"></i> Buyer Registration</h1>
            <p>Join our marketplace and source fresh produce directly from farmers</p>
        </div>
        
        <?php if (!empty($errors)): ?>
        <div class="error-messages" style="background: #fee; border: 1px solid #f88; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #c33;">
            <h4><i class="fas fa-exclamation-triangle"></i> Please fix the following errors:</h4>
            <ul style="margin: 10px 0 0 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- Step Indicators -->
        <div class="step-indicators">
            <div class="step-indicator active" data-step="1">1</div>
            <div class="step-indicator" data-step="2">2</div>
            <div class="step-indicator" data-step="3">3</div>
            <div class="step-indicator" data-step="4">4</div>
        </div>
        
        <form id="buyerRegistrationForm" action="submit_registration.php" method="post" enctype="multipart/form-data">
            <!-- Step 1: Business Type Selection -->
            <div class="form-step active" id="step1">
                <h2><i class="fas fa-building"></i> Business Type</h2>
                <p style="margin-bottom: 25px; color: #666;">Select the type of business you represent</p>
                
                <div class="business-type-selector">
                    <div class="type-option" data-type="company">
                        <i class="fas fa-industry"></i>
                        <h3>Company</h3>
                        <p>Manufacturing companies, processing units, large enterprises</p>
                    </div>
                    <div class="type-option" data-type="vendor">
                        <i class="fas fa-store"></i>
                        <h3>Vendor/Retailer</h3>
                        <p>Retail stores, restaurants, small businesses, vendors</p>
                    </div>
                    <div class="type-option" data-type="both">
                        <i class="fas fa-exchange-alt"></i>
                        <h3>Both</h3>
                        <p>Businesses involved in both manufacturing and retail operations</p>
                    </div>
                </div>
                
                <input type="hidden" id="business_type" name="business_type" required>
                
                <div class="form-buttons">
                    <div></div>
                    <button type="button" class="btn btn-primary btn-next-step">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 2: Account Information -->
            <div class="form-step" id="step2">
                <h2><i class="fas fa-user"></i> Account Information</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone Number *</label>
                        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" placeholder="Enter 10-digit mobile number" required>
                        <small>Enter your 10-digit mobile number for verification</small>
                    </div>
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" minlength="8" required>
                        <small>Password must be at least 8 characters long</small>
                    </div>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-prev-step"><i class="fas fa-arrow-left"></i> Previous</button>
                    <button type="button" class="btn btn-primary btn-next-step">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 3: Business Details -->
            <div class="form-step" id="step3">
                <h2><i class="fas fa-building"></i> Business Details</h2>
                <div class="form-group">
                    <label for="company_name">Company/Business Name *</label>
                    <input type="text" id="company_name" name="company_name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="business_registration">Business Registration Number</label>
                        <input type="text" id="business_registration" name="business_registration" placeholder="CIN/Registration Number">
                    </div>
                    <div class="form-group">
                        <label for="gst_number">GST Number</label>
                        <input type="text" id="gst_number" name="gst_number" placeholder="GST Number (if applicable)">
                    </div>
                </div>
                <div class="form-group">
                    <label for="contact_person">Contact Person Name *</label>
                    <input type="text" id="contact_person" name="contact_person" required>
                </div>
                <div class="form-group">
                    <label for="business_address">Business Address *</label>
                    <textarea id="business_address" name="business_address" rows="3" required placeholder="Complete business address"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="state">State *</label>
                        <select id="state" name="state" required>
                            <option value="">Select State</option>
                            <option value="Andhra Pradesh">Andhra Pradesh</option>
                            <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                            <option value="Assam">Assam</option>
                            <option value="Bihar">Bihar</option>
                            <option value="Chhattisgarh">Chhattisgarh</option>
                            <option value="Goa">Goa</option>
                            <option value="Gujarat">Gujarat</option>
                            <option value="Haryana">Haryana</option>
                            <option value="Himachal Pradesh">Himachal Pradesh</option>
                            <option value="Jharkhand">Jharkhand</option>
                            <option value="Karnataka">Karnataka</option>
                            <option value="Kerala">Kerala</option>
                            <option value="Madhya Pradesh">Madhya Pradesh</option>
                            <option value="Maharashtra">Maharashtra</option>
                            <option value="Manipur">Manipur</option>
                            <option value="Meghalaya">Meghalaya</option>
                            <option value="Mizoram">Mizoram</option>
                            <option value="Nagaland">Nagaland</option>
                            <option value="Odisha">Odisha</option>
                            <option value="Punjab">Punjab</option>
                            <option value="Rajasthan">Rajasthan</option>
                            <option value="Sikkim">Sikkim</option>
                            <option value="Tamil Nadu">Tamil Nadu</option>
                            <option value="Telangana">Telangana</option>
                            <option value="Tripura">Tripura</option>
                            <option value="Uttar Pradesh">Uttar Pradesh</option>
                            <option value="Uttarakhand">Uttarakhand</option>
                            <option value="West Bengal">West Bengal</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="district">District *</label>
                        <input type="text" id="district" name="district" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="pincode">Pincode *</label>
                    <input type="text" id="pincode" name="pincode" pattern="[0-9]{6}" required>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-prev-step"><i class="fas fa-arrow-left"></i> Previous</button>
                    <button type="button" class="btn btn-primary btn-next-step">Next <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            
            <!-- Step 4: Procurement Details -->
            <div class="form-step" id="step4">
                <h2><i class="fas fa-chart-bar"></i> Procurement Details</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="procurement_capacity">Monthly Procurement Capacity (in Tonnes) *</label>
                        <input type="number" id="procurement_capacity" name="procurement_capacity" step="0.1" min="0.1" required>
                        <small>Estimated quantity you plan to purchase monthly</small>
                    </div>
                    <div class="form-group">
                        <label for="payment_terms">Preferred Payment Terms *</label>
                        <select id="payment_terms" name="payment_terms" required>
                            <option value="">Select Payment Terms</option>
                            <option value="immediate">Immediate Payment</option>
                            <option value="7_days">7 Days</option>
                            <option value="15_days">15 Days</option>
                            <option value="30_days">30 Days</option>
                            <option value="45_days">45 Days</option>
                            <option value="custom">Custom Terms</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="crop_interests">Crops of Interest</label>
                    <textarea id="crop_interests" name="crop_interests" rows="3" placeholder="List the types of crops/produce you are interested in purchasing"></textarea>
                </div>
                <div class="form-group">
                    <label for="business_documents">Upload Business Documents</label>
                    <input type="file" id="business_documents" name="business_documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
                    <small>Upload business registration, GST certificate, etc. (optional)</small>
                </div>
                <div class="form-group">
                    <input type="checkbox" id="terms_agreement" name="terms_agreement" required style="width: auto; margin-right: 10px;">
                    <label for="terms_agreement" style="display: inline;">I agree to the <a href="#" style="color: #2a9d8f;">Terms & Conditions</a> and <a href="#" style="color: #2a9d8f;">Privacy Policy</a></label>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn btn-prev-step"><i class="fas fa-arrow-left"></i> Previous</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Register</button>
                </div>
            </div>
        </form>
    </div>

    <script src="../js/multi-step-form.js"></script>
    <script>
        // Business type selection
        document.querySelectorAll('.type-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Set hidden input value
                document.getElementById('business_type').value = this.dataset.type;
            });
        });

        // Form validation for business type
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('buyerRegistrationForm');
            const nextButtons = document.querySelectorAll('.btn-next-step');
            
            nextButtons[0].addEventListener('click', function(e) {
                const businessType = document.getElementById('business_type').value;
                if (!businessType) {
                    alert('Please select a business type to continue.');
                    e.preventDefault();
                    return false;
                }
            });
        });
    </script>
</body>
</html>
