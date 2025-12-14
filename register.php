<?php
// Start session for notifications
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once "config.php";
require_once "phpmailer.php"; // Include PHPMailer

// Define variables and initialize with empty values
$username = $email = $password = $confirm_password = "";
$username_err = $email_err = $password_err = $confirm_password_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // Validate username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter a username.";
    } else{
        // Prepare a select statement
        $sql = "SELECT id FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            
            // Set parameters
            $param_username = trim($_POST["username"]);
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                /* store result */
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){
                    $username_err = "This username is already taken.";
                } else{
                    $username = trim($_POST["username"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter an email.";     
    } else{
        // Prepare a select statement to check if email exists
        $sql = "SELECT id FROM users WHERE email = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            
            // Set parameters
            $param_email = trim($_POST["email"]);
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                /* store result */
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){
                    $email_err = "This email is already registered.";
                } else{
                    $email = trim($_POST["email"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a password.";     
    } elseif(strlen(trim($_POST["password"])) < 6){
        $password_err = "Password must have at least 6 characters.";
    } else{
        $password = trim($_POST["password"]);
        
        // Check if password is identical to username
        if(strtolower($password) === strtolower(trim($_POST["username"]))){
            $password_err = "Password cannot be identical to your username.";
        }
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($password_err) && ($password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before inserting in database
    if(empty($username_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err)){
        
        // Generate verification token
        $verification_token = bin2hex(random_bytes(32));
        $verification_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Prepare an insert statement
        $sql = "INSERT INTO users (username, email, password, verification_token, verification_token_expiry) VALUES (?, ?, ?, ?, ?)";
         
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "sssss", $param_username, $param_email, $param_password, $param_token, $param_expiry);
            
            // Set parameters
            $param_username = $username;
            $param_email = $email;
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Creates a password hash
            $param_token = $verification_token;
            $param_expiry = $verification_expiry;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Send verification email
                $verification_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/verify_email.php?token=" . $verification_token;
                
                $email_subject = "Verify Your Email - Artisan Link";
                $email_body = "
                    <h2>Welcome to Artisan Link!</h2>
                    <p>Thank you for registering. Please verify your email address by clicking the link below:</p>
                    <p><a href='$verification_link' style='background-color: #4361ee; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Verify Email Address</a></p>
                    <p>Or copy and paste this link in your browser:</p>
                    <p>$verification_link</p>
                    <p>This link will expire in 24 hours.</p>
                    <p>If you didn't create an account, you can safely ignore this email.</p>
                ";
                
                if(sendEmail($email, $email_subject, $email_body)) {
                    $verification_sent = true;
                } else {
                    $verification_sent = false;
                }
                
                // Set success flag
                $account_created = true;
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Close connection
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS styles remain the same */
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --accent-color: #4cc9f0;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --success-color: #4bb543;
            --error-color: #ff3860;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .wrapper {
            width: 100%;
            max-width: 450px;
            padding: 40px 30px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            margin: 20px auto;
            position: relative;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .logo-icon i {
            font-size: 2.5rem;
            color: white;
        }
        
        .logo h2 {
            color: var(--secondary-color);
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #6c757d;
            font-size: 1rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 8px;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }
        
        .input-group-text {
            background-color: white;
            border: 2px solid #e9ecef;
            border-right: none;
            border-radius: 10px 0 0 10px;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        
        .input-group:focus-within .input-group-text {
            border-color: var(--primary-color);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
        }
        
        .back-btn {
            position: absolute;
            top: 25px;
            left: 25px;
            border-radius: 10px;
            padding: 10px 15px;
            transition: all 0.3s;
            z-index: 100;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .back-btn:hover {
            transform: translateX(-3px);
            background: var(--primary-color);
            color: white;
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.3);
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        .password-input {
            position: relative;
        }
        
        .is-invalid {
            border-color: var(--error-color) !important;
        }
        
        .invalid-feedback {
            display: block;
            color: var(--error-color);
            font-size: 0.875rem;
            margin-top: 5px;
        }
        
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .terms-text {
            font-size: 0.9rem;
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
        }
        
        .terms-text a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .terms-text a:hover {
            text-decoration: underline;
        }
        
        /* Success Popup Styles */
        .success-popup {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .success-popup.show {
            opacity: 1;
            visibility: visible;
        }
        
        .popup-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 350px;
            width: 90%;
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        
        .success-popup.show .popup-content {
            transform: scale(1);
        }
        
        .popup-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--success-color), #3a9c3a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .popup-icon i {
            font-size: 1.8rem;
            color: white;
        }
        
        .popup-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 10px;
        }
        
        .popup-message {
            color: #6c757d;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .popup-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .popup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.4);
            color: white;
        }
        
        /* Confirmation Popup Styles */
        .confirmation-popup {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .confirmation-popup.show {
            opacity: 1;
            visibility: visible;
        }
        
        .confirmation-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        
        .confirmation-popup.show .confirmation-content {
            transform: scale(1);
        }
        
        .confirmation-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .confirmation-icon i {
            font-size: 1.8rem;
            color: white;
        }
        
        .confirmation-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 15px;
        }
        
        .confirmation-message {
            color: #6c757d;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .confirmation-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: left;
        }
        
        .confirmation-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .confirmation-detail:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .confirmation-label {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .confirmation-value {
            color: #6c757d;
        }
        
        .confirmation-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
            color: white;
            transform: translateY(-2px);
        }
        
        /* Verification Sent Popup */
        .verification-popup {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .verification-popup.show {
            opacity: 1;
            visibility: visible;
        }
        
        .verification-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: scale(0.7);
            transition: transform 0.3s ease;
        }
        
        .verification-popup.show .verification-content {
            transform: scale(1);
        }
        
        .verification-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4cc9f0, #4361ee);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .verification-icon i {
            font-size: 1.8rem;
            color: white;
        }
        
        @media (max-width: 576px) {
            .wrapper {
                margin: 10px;
                padding: 30px 20px;
            }
            
            .back-btn {
                top: 15px;
                left: 15px;
                padding: 8px 12px;
                font-size: 0.9rem;
            }
            
            .popup-content, .confirmation-content, .verification-content {
                padding: 25px 20px;
                margin: 20px;
            }
            
            .confirmation-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <a href="index.php" class="btn back-btn">
        <i class="fas fa-arrow-left me-1"></i> Back to Home
    </a>
    
    <!-- Success Popup -->
    <div class="success-popup" id="successPopup">
        <div class="popup-content">
            <div class="popup-icon">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="popup-title">Success!</h3>
            <p class="popup-message">Your account has been created successfully.</p>
            <a href="login.php" class="popup-btn">Continue to Login</a>
        </div>
    </div>
    
    <!-- Verification Sent Popup -->
    <div class="verification-popup" id="verificationPopup">
        <div class="verification-content">
            <div class="verification-icon">
                <i class="fas fa-envelope"></i>
            </div>
            <h3 class="confirmation-title">Verification Email Sent!</h3>
            <p class="confirmation-message">We've sent a verification link to your email address. Please check your inbox and click the link to verify your account.</p>
            <p class="confirmation-message">If you don't see the email, please check your spam folder.</p>
            <button class="popup-btn" id="closeVerificationPopup">OK</button>
        </div>
    </div>
    
    <!-- Confirmation Popup -->
    <div class="confirmation-popup" id="confirmationPopup">
        <div class="confirmation-content">
            <div class="confirmation-icon">
                <i class="fas fa-user-check"></i>
            </div>
            <h3 class="confirmation-title">Confirm Registration</h3>
            <p class="confirmation-message">Please review your information before creating your account:</p>
            
            <div class="confirmation-details">
                <div class="confirmation-detail">
                    <span class="confirmation-label">Username:</span>
                    <span class="confirmation-value" id="confirmUsername"></span>
                </div>
                <div class="confirmation-detail">
                    <span class="confirmation-label">Email:</span>
                    <span class="confirmation-value" id="confirmEmail"></span>
                </div>
            </div>
            
            <div class="confirmation-buttons">
                <button type="button" class="btn-cancel" id="cancelRegistration">Cancel</button>
                <button type="button" class="popup-btn" id="confirmRegistration">Create Account</button>
            </div>
        </div>
    </div>
    
    <div class="container d-flex justify-content-center">
        <div class="wrapper">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-hands-helping"></i>
                </div>
                <h2>Artisan Link</h2>
                <p>Join our creative community</p>
            </div>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="registrationForm">
                <div class="form-group mb-4">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>" placeholder="Choose a username">
                    </div>
                    <span class="invalid-feedback"><?php echo $username_err; ?></span>
                    <div class="form-text">Your username must be unique and 3-20 characters long.</div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>" placeholder="Enter your email">
                    </div>
                    <span class="invalid-feedback"><?php echo $email_err; ?></span>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Password</label>
                    <div class="password-input">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $password; ?>" placeholder="Create a password">
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="invalid-feedback"><?php echo $password_err; ?></span>
                        <div class="form-text">Password must be at least 6 characters long and cannot be identical to your username.</div>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label class="form-label">Confirm Password</label>
                    <div class="password-input">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $confirm_password; ?>" placeholder="Confirm your password">
                            <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                    </div>
                </div>
                
                <div class="form-group mt-4">
                    <button type="button" class="btn btn-primary w-100 py-2" id="submitBtn">Create Account</button>
                </div>
                
                <p class="text-center mt-4 mb-0">Already have an account? <a href="login.php" class="text-decoration-none fw-bold">Sign in here</a>.</p>
            </form>
            
            <div class="terms-text">
                By creating an account, you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const icon = this.querySelector('i');
            
            if (confirmPasswordInput.type === 'password') {
                confirmPasswordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                confirmPasswordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Add focus effects to form inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.classList.remove('focused');
            });
        });
        
        // Real-time validation for username and password match
        document.getElementById('password').addEventListener('input', function() {
            validatePasswordNotUsername();
        });
        
        document.querySelector('input[name="username"]').addEventListener('input', function() {
            validatePasswordNotUsername();
        });
        
        function validatePasswordNotUsername() {
            const username = document.querySelector('input[name="username"]').value.toLowerCase().trim();
            const password = document.getElementById('password').value.toLowerCase().trim();
            const passwordInput = document.getElementById('password');
            const passwordGroup = passwordInput.closest('.form-group');
            let existingError = passwordGroup.querySelector('.username-password-error');
            
            if (username && password && username === password) {
                if (!existingError) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'invalid-feedback username-password-error';
                    errorDiv.textContent = 'Password cannot be identical to your username.';
                    passwordGroup.appendChild(errorDiv);
                }
                passwordInput.classList.add('is-invalid');
            } else {
                if (existingError) {
                    existingError.remove();
                }
                passwordInput.classList.remove('is-invalid');
            }
        }
        
        // Show confirmation popup when submit button is clicked
        document.getElementById('submitBtn').addEventListener('click', function() {
            const username = document.querySelector('input[name="username"]').value.trim();
            const email = document.querySelector('input[name="email"]').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            // Basic validation
            if (!username || !email || !password || !confirmPassword) {
                alert('Please fill in all fields before submitting.');
                return;
            }
            
            if (password !== confirmPassword) {
                alert('Passwords do not match. Please check your password confirmation.');
                return;
            }
            
            // Check if password is identical to username
            if (username.toLowerCase() === password.toLowerCase()) {
                alert('Password cannot be identical to your username. Please choose a different password.');
                return;
            }
            
            // Show confirmation popup with user details
            document.getElementById('confirmUsername').textContent = username;
            document.getElementById('confirmEmail').textContent = email;
            document.getElementById('confirmationPopup').classList.add('show');
        });
        
        // Handle confirmation popup buttons
        document.getElementById('cancelRegistration').addEventListener('click', function() {
            document.getElementById('confirmationPopup').classList.remove('show');
        });
        
        document.getElementById('confirmRegistration').addEventListener('click', function() {
            // Submit the form
            document.getElementById('registrationForm').submit();
        });
        
        // Handle verification popup
        document.getElementById('closeVerificationPopup').addEventListener('click', function() {
            document.getElementById('verificationPopup').classList.remove('show');
        });
        
        // Show success popup if account was created
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($account_created) && $account_created): ?>
                <?php if (isset($verification_sent) && $verification_sent): ?>
                    showVerificationPopup();
                <?php else: ?>
                    showSuccessPopup();
                <?php endif; ?>
            <?php endif; ?>
        });
        
        function showSuccessPopup() {
            const popup = document.getElementById('successPopup');
            popup.classList.add('show');
            
            // Prevent closing by clicking outside
            popup.addEventListener('click', function(e) {
                if (e.target === popup) {
                    // Optional: Uncomment if you want to allow closing by clicking outside
                    // popup.classList.remove('show');
                }
            });
        }
        
        function showVerificationPopup() {
            const popup = document.getElementById('verificationPopup');
            popup.classList.add('show');
            
            // Prevent closing by clicking outside
            popup.addEventListener('click', function(e) {
                if (e.target === popup) {
                    // Optional: Uncomment if you want to allow closing by clicking outside
                    // popup.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>