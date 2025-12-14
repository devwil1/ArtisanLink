<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once "config.php";

// Check if token is provided
if(isset($_GET['token']) && !empty($_GET['token'])){
    $token = trim($_GET['token']);
    
    // Prepare a select statement to check token validity
    $sql = "SELECT id, email, verification_token_expiry FROM users WHERE verification_token = ? AND email_verified = 0";
    
    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "s", $param_token);
        $param_token = $token;
        
        if(mysqli_stmt_execute($stmt)){
            mysqli_stmt_store_result($stmt);
            
            if(mysqli_stmt_num_rows($stmt) == 1){
                // Bind result variables
                mysqli_stmt_bind_result($stmt, $id, $email, $expiry);
                if(mysqli_stmt_fetch($stmt)){
                    // Check if token is expired
                    $current_time = date('Y-m-d H:i:s');
                    if($expiry > $current_time){
                        // Token is valid and not expired
                        $update_sql = "UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expiry = NULL WHERE id = ?";
                        
                        if($update_stmt = mysqli_prepare($link, $update_sql)){
                            mysqli_stmt_bind_param($update_stmt, "i", $param_id);
                            $param_id = $id;
                            
                            if(mysqli_stmt_execute($update_stmt)){
                                $verification_success = true;
                                $_SESSION['success_message'] = "Your email has been verified successfully!";
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    } else {
                        $verification_error = "Verification link has expired.";
                    }
                }
            } else {
                $verification_error = "Invalid verification link.";
            }
        } else {
            $verification_error = "Oops! Something went wrong. Please try again later.";
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $verification_error = "No verification token provided.";
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Artisan Link</title>
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
        
        .verification-container {
            max-width: 500px;
            width: 100%;
        }
        
        .verification-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            padding: 40px 30px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .verification-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            font-size: 2.5rem;
            color: white;
        }
        
        .success-icon {
            background: linear-gradient(135deg, var(--success-color), #3a9c3a);
            box-shadow: 0 5px 15px rgba(75, 181, 67, 0.3);
        }
        
        .error-icon {
            background: linear-gradient(135deg, var(--error-color), #cc2a2a);
            box-shadow: 0 5px 15px rgba(255, 56, 96, 0.3);
        }
        
        .verification-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--secondary-color);
        }
        
        .verification-message {
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
        }
        
        @media (max-width: 576px) {
            .verification-card {
                padding: 30px 20px;
            }
            
            .verification-icon {
                width: 70px;
                height: 70px;
                font-size: 2rem;
            }
            
            .verification-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="verification-card">
            <?php if(isset($verification_success) && $verification_success): ?>
                <div class="verification-icon success-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h2 class="verification-title">Email Verified!</h2>
                <p class="verification-message">
                    Congratulations! Your email has been successfully verified. 
                    You can now log in to your Artisan Link account.
                </p>
                <a href="login.php" class="btn btn-primary">Go to Login</a>
            <?php elseif(isset($verification_error)): ?>
                <div class="verification-icon error-icon">
                    <i class="fas fa-times"></i>
                </div>
                <h2 class="verification-title">Verification Failed</h2>
                <p class="verification-message">
                    <?php echo $verification_error; ?>
                </p>
                <a href="register.php" class="btn btn-primary">Back to Registration</a>
            <?php else: ?>
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h2 class="verification-title mt-3">Verifying...</h2>
                <p class="verification-message">
                    Please wait while we verify your email address.
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>