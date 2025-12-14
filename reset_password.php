<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once "config.php";

// Define variables and initialize with empty values
$pin = $new_password = $confirm_password = "";
$pin_err = $new_password_err = $confirm_password_err = "";
$success_msg = $error_msg = "";

// Check if email is set in session
if(!isset($_SESSION['reset_email'])) {
    header("location: forgot_password.php");
    exit;
}

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Validate PIN
    if(empty(trim($_POST["pin"]))){
        $pin_err = "Please enter the 6-digit PIN.";
    } elseif(strlen(trim($_POST["pin"])) != 6){
        $pin_err = "PIN must be exactly 6 digits.";
    } else{
        $pin = trim($_POST["pin"]);
    }
    
    // Validate new password
    if(empty(trim($_POST["new_password"]))){
        $new_password_err = "Please enter the new password.";     
    } elseif(strlen(trim($_POST["new_password"])) < 6){
        $new_password_err = "Password must have at least 6 characters.";
    } else{
        $new_password = trim($_POST["new_password"]);
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm the password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($new_password_err) && ($new_password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before updating the database
    if(empty($pin_err) && empty($new_password_err) && empty($confirm_password_err)){
        // Get email from session
        $email = $_SESSION['reset_email'];
        
        // DEBUG: Log the email being used
        error_log("Reset password attempt for email: " . $email);
        
        // Prepare a select statement to check PIN validity
        $sql = "SELECT id, reset_token, reset_token_expiry FROM users WHERE email = ? AND reset_token IS NOT NULL";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $id, $reset_token, $reset_token_expiry);
                    if(mysqli_stmt_fetch($stmt)){
                        // DEBUG: Log what we found
                        error_log("Found user ID: " . $id);
                        error_log("Reset token exists: " . ($reset_token ? "Yes" : "No"));
                        error_log("Reset token expiry: " . $reset_token_expiry);
                        error_log("Current time: " . date('Y-m-d H:i:s'));
                        
                        // Check if token is expired
                        $current_time = date('Y-m-d H:i:s');
                        if(strtotime($reset_token_expiry) < strtotime($current_time)){
                            $pin_err = "PIN has expired. Please request a new one.";
                            error_log("PIN expired: " . $reset_token_expiry . " < " . $current_time);
                        } else {
                            // Verify the PIN
                            if(password_verify($pin, $reset_token)){
                                error_log("PIN verification SUCCESSFUL for user ID: " . $id);
                                // PIN is correct, update password
                                $update_sql = "UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?";
                                
                                if($update_stmt = mysqli_prepare($link, $update_sql)){
                                    mysqli_stmt_bind_param($update_stmt, "si", $param_password, $param_id);
                                    $param_password = password_hash($new_password, PASSWORD_DEFAULT);
                                    $param_id = $id;
                                    
                                    if(mysqli_stmt_execute($update_stmt)){
                                        $success_msg = "Password updated successfully. You can now login with your new password.";
                                        // Clear the session
                                        unset($_SESSION['reset_email']);
                                        error_log("Password reset SUCCESS for user ID: " . $id);
                                    } else {
                                        $error_msg = "Something went wrong. Please try again later.";
                                        error_log("UPDATE failed: " . mysqli_error($link));
                                    }
                                    mysqli_stmt_close($update_stmt);
                                } else {
                                    $error_msg = "Database error. Please try again later.";
                                    error_log("Prepare UPDATE failed: " . mysqli_error($link));
                                }
                            } else {
                                $pin_err = "Invalid PIN.";
                                error_log("PIN verification FAILED for user ID: " . $id);
                                error_log("Entered PIN: " . $pin);
                                error_log("Stored hash: " . $reset_token);
                            }
                        }
                    }
                } else {
                    $error_msg = "No reset request found for this email. Please request a new PIN.";
                    error_log("No user found with reset_token for email: " . $email);
                    error_log("Rows found: " . mysqli_stmt_num_rows($stmt));
                }
            } else {
                $error_msg = "Oops! Something went wrong. Please try again later.";
                error_log("Execute failed: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_msg = "Database error. Please try again later.";
            error_log("Prepare statement failed: " . mysqli_error($link));
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
    <title>Reset Password - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #4bb543;
            --error-color: #ff3860;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .reset-container {
            width: 100%;
            max-width: 450px;
        }
        
        .reset-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            padding: 40px 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            margin-bottom: 15px;
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .logo-icon i {
            font-size: 2rem;
            color: white;
        }
        
        .logo h2 {
            color: var(--secondary-color);
            font-weight: 700;
            margin-bottom: 5px;
            font-size: 1.8rem;
        }
        
        .logo p {
            color: #6c757d;
            font-size: 1rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
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
        
        .is-invalid {
            border-color: var(--error-color) !important;
        }
        
        .invalid-feedback {
            display: block;
            color: var(--error-color);
            font-size: 0.875rem;
            margin-top: 5px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
        }
        
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            border-radius: 10px;
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .back-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(75, 181, 67, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        
        .alert-danger {
            background-color: rgba(255, 56, 96, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }
        
        .pin-input {
            letter-spacing: 10px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
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
        
        @media (max-width: 576px) {
            .reset-card {
                padding: 30px 20px;
            }
            
            .back-btn {
                top: 10px;
                left: 10px;
                padding: 6px 12px;
                font-size: 0.9rem;
            }
            
            .logo-icon {
                width: 60px;
                height: 60px;
            }
            
            .logo-icon i {
                font-size: 1.8rem;
            }
            
            .logo h2 {
                font-size: 1.5rem;
            }
            
            .pin-input {
                letter-spacing: 5px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <a href="login.php" class="btn back-btn">
        <i class="fas fa-arrow-left me-1"></i> Back to Login
    </a>
    
    <div class="container d-flex justify-content-center">
        <div class="reset-container">
            <div class="reset-card">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2>Set New Password</h2>
                    <p>Enter your PIN and new password</p>
                </div>
                
                <?php 
                if(!empty($success_msg)){
                    echo '<div class="alert alert-success">' . $success_msg . '</div>';
                }
                if(!empty($error_msg)){
                    echo '<div class="alert alert-danger">' . $error_msg . '</div>';
                }
                ?>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-group mb-4">
                        <label class="form-label">6-Digit PIN</label>
                        <input type="text" name="pin" class="form-control pin-input <?php echo (!empty($pin_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $pin; ?>" placeholder="000000" maxlength="6" inputmode="numeric" required>
                        <span class="invalid-feedback"><?php echo $pin_err; ?></span>
                        <div class="form-text">Enter the 6-digit PIN sent to your email.</div>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label class="form-label">New Password</label>
                        <div class="password-input">
                            <input type="password" name="new_password" id="newPassword" class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $new_password; ?>" placeholder="Enter new password" required>
                            <button type="button" class="password-toggle" id="toggleNewPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="invalid-feedback"><?php echo $new_password_err; ?></span>
                        <div class="form-text">Password must be at least 6 characters long.</div>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <div class="password-input">
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $confirm_password; ?>" placeholder="Confirm new password" required>
                            <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p class="mb-2">Didn't receive the PIN? <a href="forgot_password.php" class="text-decoration-none fw-bold">Request a new one</a></p>
                    <p>Remember your password? <a href="login.php" class="text-decoration-none fw-bold">Sign in here</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('newPassword');
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
        
        // Auto-advance PIN input
        document.querySelector('input[name="pin"]').addEventListener('input', function() {
            // Remove non-numeric characters
            this.value = this.value.replace(/\D/g, '');
            
            // Limit to 6 digits
            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
        });
        
        // Show success message if password reset was successful
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                // Auto-redirect to login after 3 seconds
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 3000);
            }
        });
    </script>
</body>
</html>