<?php
// Initialize the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
// Check if the user is already logged in, if yes then redirect him to welcome page
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: welcome.php");
    exit;
}
 
// Include config file
require_once "config.php";
 
// Define variables and initialize with empty values
$username = $password = "";
$username_err = $password_err = $login_err = "";
 
// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Check if username is empty
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    // Check if password is empty
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($username_err) && empty($password_err)){
        // Prepare a select statement
        $sql = "SELECT id, username, password, user_type, email_verified FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            
            // Set parameters
            $param_username = $username;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Store result
                mysqli_stmt_store_result($stmt);
                
                // Check if username exists, if yes then verify password
                if(mysqli_stmt_num_rows($stmt) == 1){                    
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password, $user_type, $email_verified);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Check if user is admin or superadmin - prevent login
                            if($user_type == "admin" || $user_type == "super_admin"){
                                $login_err = "Invalid username or password.";
                            } else {
                                // Check if email is verified
                                if($email_verified != 1) {
                                    $login_err = "Please verify your email before logging in. Check your email for the verification link.";
                                } else {
                                    // Password is correct and user is allowed, so start a new session
                                    session_start();
                                    
                                    // Store data in session variables
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["id"] = $id;
                                    $_SESSION["username"] = $username;
                                    $_SESSION["user_type"] = $user_type;
                                    
                                    // Set flag for successful login popup
                                    $_SESSION["show_login_success"] = true;
                                    
                                    // Redirect user to welcome page
                                    header("location: welcome.php");
                                    exit;
                                }
                            }
                        } else{
                            // Password is not valid, display a generic error message
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else{
                    // Username doesn't exist, display a generic error message
                    $login_err = "Invalid username or password.";
                }
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
    <title>Login - Artisan Link</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS styles remain the same */
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #a29bfe;
            --light: #f8f9fa;
            --dark: #2d3436;
            --success: #00b894;
            --danger: #d63031;
            --warning: #fdcb6e;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="0.1" d="M0,224L48,218.7C96,213,192,203,288,197.3C384,192,480,192,576,181.3C672,171,768,149,864,154.7C960,160,1056,192,1152,192C1248,192,1344,160,1392,144L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>');
            background-size: cover;
            background-position: bottom;
            opacity: 0.1;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            text-align: center;
            padding: 30px 20px;
            border-bottom: none;
        }
        
        .logo {
            font-size: 3.5rem;
            margin-bottom: 10px;
            filter: drop-shadow(0 2px 5px rgba(0,0,0,0.2));
        }
        
        .card-header h2 {
            font-weight: 700;
            margin-bottom: 5px;
            font-size: 1.8rem;
        }
        
        .card-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .form-label i {
            margin-right: 8px;
            color: var(--primary);
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(108, 92, 231, 0.25);
        }
        
        .form-control.is-invalid {
            border-color: var(--danger);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.3);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 92, 231, 0.4);
        }
        
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 10;
            border-radius: 50px;
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(0, 0, 0, 0.15);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 15px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background-color: rgba(214, 48, 49, 0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .invalid-feedback {
            display: block;
            margin-top: 5px;
            font-size: 0.875rem;
            color: var(--danger);
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            color: var(--dark);
        }
        
        .register-link a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .register-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
        }
        
        .shape {
            position: absolute;
            opacity: 0.1;
            border-radius: 50%;
        }
        
        .shape-1 {
            width: 200px;
            height: 200px;
            background: var(--warning);
            top: 10%;
            left: 5%;
            animation: float 15s infinite ease-in-out;
        }
        
        .shape-2 {
            width: 150px;
            height: 150px;
            background: var(--success);
            bottom: 10%;
            right: 5%;
            animation: float 12s infinite ease-in-out reverse;
        }
        
        .shape-3 {
            width: 100px;
            height: 100px;
            background: var(--danger);
            top: 50%;
            right: 15%;
            animation: float 10s infinite ease-in-out;
        }
        
        /* Password Toggle Styles */
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
            padding: 5px;
        }
        
        .password-toggle:hover {
            color: var(--primary);
        }
        
        .password-input {
            position: relative;
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
            padding: 20px;
            box-sizing: border-box;
        }
        
        .success-popup.show {
            opacity: 1;
            visibility: visible;
        }
        
        .popup-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 400px;
            width: 100%;
            transform: scale(0.7);
            transition: transform 0.3s ease;
            position: relative;
        }
        
        .success-popup.show .popup-content {
            transform: scale(1);
        }
        
        .popup-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--success), #00a085);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 5px 15px rgba(0, 184, 148, 0.3);
        }
        
        .popup-icon i {
            font-size: 2.5rem;
            color: white;
        }
        
        .popup-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .popup-message {
            color: #6c757d;
            margin-bottom: 25px;
            line-height: 1.5;
            font-size: 1.1rem;
        }
        
        .popup-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 1rem;
            box-shadow: 0 4px 15px rgba(108, 92, 231, 0.3);
        }
        
        .popup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 92, 231, 0.4);
            color: white;
        }
        
        .loading-bar {
            height: 4px;
            background: linear-gradient(135deg, var(--success), #00a085);
            border-radius: 2px;
            margin-top: 20px;
            animation: loading 2s ease-in-out;
            transform-origin: left;
        }
        
        .forgot-password-link {
            text-align: center;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        
        .forgot-password-link a {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.95rem;
        }
        
        .forgot-password-link a:hover {
            text-decoration: underline;
        }
        
        @keyframes loading {
            0% {
                transform: scaleX(0);
            }
            100% {
                transform: scaleX(1);
            }
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            50% {
                transform: translateY(-20px) rotate(10deg);
            }
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 0 15px;
            }
            
            .back-btn {
                top: 10px;
                left: 10px;
                padding: 6px 12px;
                font-size: 0.9rem;
            }
            
            .card-header {
                padding: 25px 15px;
            }
            
            .logo {
                font-size: 3rem;
            }
            
            .card-header h2 {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 25px 20px;
            }
            
            .success-popup {
                padding: 15px;
            }
            
            .popup-content {
                padding: 25px 20px;
                border-radius: 15px;
            }
            
            .popup-icon {
                width: 70px;
                height: 70px;
            }
            
            .popup-icon i {
                font-size: 2rem;
            }
            
            .popup-title {
                font-size: 1.5rem;
            }
            
            .popup-message {
                font-size: 1rem;
            }
            
            .popup-btn {
                padding: 10px 25px;
                font-size: 0.95rem;
            }
        }
        
        @media (max-width: 380px) {
            .popup-content {
                padding: 20px 15px;
            }
            
            .popup-icon {
                width: 60px;
                height: 60px;
                margin-bottom: 15px;
            }
            
            .popup-icon i {
                font-size: 1.8rem;
            }
            
            .popup-title {
                font-size: 1.3rem;
            }
            
            .popup-message {
                font-size: 0.95rem;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>
    
    <a href="index.php" class="btn btn-outline-secondary back-btn">
        <i class="fas fa-arrow-left me-1"></i> Back to Home
    </a>
    
    <!-- Success Login Popup -->
    <div class="success-popup" id="successPopup">
        <div class="popup-content">
            <div class="popup-icon">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="popup-title">Welcome Back!</h3>
            <p class="popup-message">You have successfully logged in to your Artisan Link account.</p>
            <a href="welcome.php" class="popup-btn">Continue to Dashboard</a>
            <div class="loading-bar"></div>
        </div>
    </div>
    
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="card-header">
                    <div class="logo">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h2>Artisan Link</h2>
                    <p>Sign in to your account</p>
                </div>
                <div class="card-body">
                    <?php 
                    if(!empty($login_err)){
                        echo '<div class="alert alert-danger">' . $login_err . '</div>';
                    }        
                    ?>

                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-user"></i> Username</label>
                            <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>">
                            <span class="invalid-feedback"><?php echo $username_err; ?></span>
                        </div>    
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-lock"></i> Password</label>
                            <div class="password-input">
                                <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                            </div>
                        </div>
                        
                        <div class="forgot-password-link">
                            <a href="forgot_password.php">Forgot your password?</a>
                        </div>
                        
                        <div class="form-group">
                            <input type="submit" class="btn btn-primary btn-login w-100" value="Login" id="loginBtn">
                        </div>
                        <div class="register-link">
                            <p>Don't have an account? <a href="register.php">Sign up now</a>.</p>
                        </div>
                    </form>
                </div>
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

        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('.form-control');
            
            inputs.forEach(input => {
                // Add focus effect
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    if (this.value === '') {
                        this.parentElement.classList.remove('focused');
                    }
                });
                
                // Check if input has value on page load (for browser autofill)
                if (input.value !== '') {
                    input.parentElement.classList.add('focused');
                }
            });
            
            // Show success popup if redirected from successful login
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('login') === 'success') {
                showSuccessPopup();
            }
            
            // Handle form submission
            const loginForm = document.getElementById('loginForm');
            loginForm.addEventListener('submit', function(e) {
                const loginBtn = document.getElementById('loginBtn');
                const username = document.querySelector('input[name="username"]').value;
                const password = document.querySelector('input[name="password"]').value;
                
                // Simple validation
                if (username && password) {
                    // Show loading state
                    loginBtn.value = 'Logging in...';
                    loginBtn.disabled = true;
                    
                    // In a real application, this would be handled by the PHP
                    // We're simulating a successful login for demo
                    setTimeout(() => {
                        // This would normally redirect, but for demo we show popup
                        // Remove this in production - let PHP handle the redirect
                        if (!document.querySelector('.alert-danger')) {
                            showSuccessPopup();
                            // Re-enable button after showing popup
                            setTimeout(() => {
                                loginBtn.value = 'Login';
                                loginBtn.disabled = false;
                            }, 100);
                        } else {
                            loginBtn.value = 'Login';
                            loginBtn.disabled = false;
                        }
                    }, 1500);
                }
            });
        });
        
        function showSuccessPopup() {
            const popup = document.getElementById('successPopup');
            popup.classList.add('show');
            
            // Auto-redirect after 3 seconds
            setTimeout(() => {
                window.location.href = 'welcome.php';
            }, 3000);
            
            // Prevent closing by clicking outside (optional)
            popup.addEventListener('click', function(e) {
                if (e.target === popup) {
                    // Uncomment if you want to allow closing by clicking outside
                    // popup.classList.remove('show');
                }
            });
        }
    </script>
</body>
</html>