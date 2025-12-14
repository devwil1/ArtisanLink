<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once "config.php";

// Define variables and initialize with empty values
$email = "";
$email_err = "";
$success_msg = "";
$error_msg = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter your email address.";
    } else{
        $email = trim($_POST["email"]);
    }
    
    // Check input errors before processing
    if(empty($email_err)){
        // Prepare a select statement
        $sql = "SELECT id, username FROM users WHERE email = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $id, $username);
                    if(mysqli_stmt_fetch($stmt)){
                        // Generate reset token (6-digit PIN)
                        $reset_pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $hashed_pin = password_hash($reset_pin, PASSWORD_DEFAULT);
                        $reset_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        
                        // Update user with reset token
                        $update_sql = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?";
                        
                        if($update_stmt = mysqli_prepare($link, $update_sql)){
                            mysqli_stmt_bind_param($update_stmt, "ssi", $hashed_pin, $reset_expiry, $id);
                            
                            if(mysqli_stmt_execute($update_stmt)){
                                // Send reset email
                                $email_subject = "Password Reset PIN - Artisan Link";
                                $email_body = "
                                    <h2>Password Reset Request</h2>
                                    <p>Hello $username,</p>
                                    <p>You have requested to reset your password. Use the following PIN to reset your password:</p>
                                    <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; margin: 20px 0;'>
                                        $reset_pin
                                    </div>
                                    <p>This PIN will expire in 1 hour.</p>
                                    <p>If you didn't request a password reset, please ignore this email.</p>
                                    <p>To reset your password, go to: http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?email=" . urlencode($email) . "</p>
                                ";
                                
                                if(sendEmail($email, $email_subject, $email_body)) {
                                    // Check if it was a test email (saved to file)
                                    $logDir = __DIR__ . '/email_logs';
                                    if (is_dir($logDir)) {
                                        $files = scandir($logDir, SCANDIR_SORT_DESCENDING);
                                        if (count($files) > 2) {
                                            $latestFile = $files[0];
                                            if ($latestFile != '.' && $latestFile != '..') {
                                                $fileContent = file_get_contents($logDir . '/' . $latestFile);
                                                // Extract PIN if exists
                                                if (preg_match('/\b(\d{6})\b/', $fileContent, $matches)) {
                                                    $pin = $matches[1];
                                                    $success_msg = "Password reset PIN: <strong>$pin</strong><br><br>Since we're in development mode, the PIN is shown here instead of email. Check the email_logs folder for the full email.";
                                                } else {
                                                    $success_msg = "Email saved to file. Check email_logs folder for details.";
                                                }
                                            }
                                        }
                                    } else {
                                        $success_msg = "A password reset PIN has been sent to your email. Please check your inbox (and spam folder).";
                                    }
                                    $_SESSION['reset_email'] = $email;
                                } else {
                                    $error_msg = "Failed to send email. Please try again later.";
                                }
                            } else {
                                $error_msg = "Something went wrong. Please try again later.";
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    }
                } else {
                    $error_msg = "No account found with that email address.";
                }
            } else {
                $error_msg = "Oops! Something went wrong. Please try again later.";
            }
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
    <title>Forgot Password - Artisan Link</title>
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
        
        .forgot-container {
            width: 100%;
            max-width: 450px;
        }
        
        .forgot-card {
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
        
        .instructions {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .instructions ul {
            padding-left: 20px;
            margin-bottom: 0;
        }
        
        .instructions li {
            margin-bottom: 5px;
        }
        
        @media (max-width: 576px) {
            .forgot-card {
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
        }
    </style>
</head>
<body>
    <a href="login.php" class="btn back-btn">
        <i class="fas fa-arrow-left me-1"></i> Back to Login
    </a>
    
    <div class="container d-flex justify-content-center">
        <div class="forgot-container">
            <div class="forgot-card">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <h2>Reset Password</h2>
                    <p>Enter your email to receive a reset PIN</p>
                </div>
                
                <?php 
                if(!empty($success_msg)){
                    echo '<div class="alert alert-success">' . $success_msg . '</div>';
                }
                if(!empty($error_msg)){
                    echo '<div class="alert alert-danger">' . $error_msg . '</div>';
                }
                ?>
                
                <div class="instructions">
                    <p><strong>Instructions:</strong></p>
                    <ul>
                        <li>Enter the email address associated with your account</li>
                        <li>You will receive a 6-digit PIN via email</li>
                        <li>Use the PIN to reset your password</li>
                        <li>The PIN will expire in 1 hour</li>
                    </ul>
                </div>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-group mb-4">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $email; ?>" placeholder="Enter your email address">
                        <span class="invalid-feedback"><?php echo $email_err; ?></span>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary w-100 py-2">Send Reset PIN</button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p class="mb-2">Remember your password? <a href="login.php" class="text-decoration-none fw-bold">Sign in here</a></p>
                    <p>Don't have an account? <a href="register.php" class="text-decoration-none fw-bold">Sign up now</a></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>