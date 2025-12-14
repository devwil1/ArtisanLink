<?php
session_start();
require_once "config.php";

$username = $password = "";
$username_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }
    
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    if (empty($username_err) && empty($password_err)) {
        $sql = "SELECT id, username, password, user_type, profile_picture FROM users WHERE username = ? AND (user_type = 'admin' OR user_type = 'super_admin')";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password, $user_type, $profile_picture);
                    if (mysqli_stmt_fetch($stmt)) {
                        if (password_verify($password, $hashed_password)) {
                            session_start();
                            
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["user_type"] = $user_type;
                            $_SESSION["profile_picture"] = $profile_picture;
                            
                            // Record login activity
                            $log_sql = "INSERT INTO admin_activity (admin_id, activity_type, description) VALUES (?, 'login', 'Admin logged in')";
                            if ($log_stmt = mysqli_prepare($link, $log_sql)) {
                                mysqli_stmt_bind_param($log_stmt, "i", $id);
                                mysqli_stmt_execute($log_stmt);
                                mysqli_stmt_close($log_stmt);
                            }
                            
                            header("location: admin-dashboard.php");
                            exit;
                        } else {
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            } else {
                $login_err = "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - ArtisanLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --accent-color: #4cc9f0;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --success-color: #38b000;
            --danger-color: #e63946;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 20px auto;
            animation: fadeIn 0.8s ease-out;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 40px 20px 30px;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--accent-color);
        }
        
        .logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .logo i {
            font-size: 36px;
            color: var(--primary-color);
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }
        
        .input-group-text {
            background-color: white;
            border: 2px solid #e9ecef;
            border-left: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .input-group-text:hover {
            background-color: #f8f9fa;
        }
        
        .input-group .form-control {
            border-right: none;
        }
        
        .input-group:focus-within .form-control,
        .input-group:focus-within .input-group-text {
            border-color: var(--primary-color);
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(67, 97, 238, 0.3);
        }
        
        .footer-links {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        .footer-links a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-links a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        
        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0;
            background: var(--danger-color);
            transition: width 0.3s, background 0.3s;
        }
        
        .security-notice {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            border-left: 4px solid var(--accent-color);
        }
        
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
        
        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            animation: float 15s infinite linear;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes float {
            0% { transform: translateY(100vh) rotate(0deg); }
            100% { transform: translateY(-100px) rotate(360deg); }
        }
        
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .theme-toggle-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .theme-toggle-btn:hover {
            transform: rotate(30deg);
        }
        
        .language-selector {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        
        .language-btn {
            background: white;
            border: none;
            border-radius: 25px;
            padding: 8px 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .language-btn:hover {
            transform: translateY(-2px);
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        @media (max-width: 576px) {
            .login-container {
                margin: 20px;
            }
            
            .login-body {
                padding: 20px;
            }
            
            .theme-toggle, .language-selector {
                position: absolute;
                top: 10px;
            }
            
            .theme-toggle {
                right: 10px;
            }
            
            .language-selector {
                left: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Theme Toggle -->
    <div class="theme-toggle">
        <button class="theme-toggle-btn" id="themeToggle">
            <i class="fas fa-moon"></i>
        </button>
    </div>
    
    <!-- Language Selector -->
    <div class="language-selector">
        <button class="language-btn" id="languageToggle">
            <i class="fas fa-globe"></i> EN
        </button>
    </div>
    
    <!-- Animated Background Particles -->
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <div class="logo">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h2>ArtisanLink</h2>
                    <p class="mb-0">Administrator Portal</p>
                </div>
                
                <div class="login-body">
                    <?php 
                    if (!empty($login_err)) {
                        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                ' . $login_err . '
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                              </div>';
                    }
                    
                    if (isset($_GET['session_expired'])) {
                        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                                Your session has expired. Please login again.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                              </div>';
                    }
                    ?>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm">
                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">
                                <i class="fas fa-user me-2"></i>Username
                            </label>
                            <input type="text" 
                                   name="username" 
                                   id="username"
                                   class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($username); ?>"
                                   placeholder="Enter your username"
                                   autocomplete="username"
                                   autofocus>
                            <span class="invalid-feedback"><?php echo $username_err; ?></span>
                        </div>
                        
                        <div class="mb-4">
                            <label for="password" class="form-label fw-semibold">
                                <i class="fas fa-lock me-2"></i>Password
                            </label>
                            <div class="input-group">
                                <input type="password" 
                                       name="password" 
                                       id="password"
                                       class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                                       placeholder="Enter your password"
                                       autocomplete="current-password">
                                <span class="input-group-text" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </span>
                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                            </div>
                            <div class="password-strength mt-2">
                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                            </div>
                            <small class="text-muted mt-1 d-block">Password must be at least 8 characters long</small>
                        </div>
                        
                        <div class="mb-4 d-flex justify-content-between align-items-center">
                            <div class="remember-me">
                                <input type="checkbox" id="rememberMe" name="rememberMe">
                                <label for="rememberMe" class="form-check-label">Remember me</label>
                            </div>
                            <a href="forgot-password.php" class="text-decoration-none">Forgot password?</a>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-login">
                                <i class="fas fa-sign-in-alt me-2"></i>Login to Dashboard
                            </button>
                        </div>
                    </form>
                    
                    <div class="security-notice mt-4">
                        <h6><i class="fas fa-shield-alt me-2"></i>Security Notice</h6>
                        <p class="mb-0 small">This is a restricted access area. All login attempts are logged and monitored.</p>
                    </div>
                    
                    <div class="footer-links">
                        <p class="small mb-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Need help? <a href="mailto:support@artisanlink.com">Contact Support</a>
                        </p>
                        <p class="small text-muted mb-0">
                            &copy; <?php echo date('Y'); ?> ArtisanLink. All rights reserved.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password visibility toggle
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const passwordStrengthBar = document.getElementById('passwordStrengthBar');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });
            
            // Password strength indicator
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 8) strength += 25;
                if (/[A-Z]/.test(password)) strength += 25;
                if (/[0-9]/.test(password)) strength += 25;
                if (/[^A-Za-z0-9]/.test(password)) strength += 25;
                
                passwordStrengthBar.style.width = strength + '%';
                
                if (strength < 50) {
                    passwordStrengthBar.style.backgroundColor = 'var(--danger-color)';
                } else if (strength < 75) {
                    passwordStrengthBar.style.backgroundColor = '#ffc107';
                } else {
                    passwordStrengthBar.style.backgroundColor = 'var(--success-color)';
                }
            });
            
            // Theme toggle
            const themeToggle = document.getElementById('themeToggle');
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-theme');
                const icon = this.querySelector('i');
                if (document.body.classList.contains('dark-theme')) {
                    document.body.style.background = 'linear-gradient(135deg, #2d3748 0%, #4a5568 100%)';
                    icon.className = 'fas fa-sun';
                } else {
                    document.body.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                    icon.className = 'fas fa-moon';
                }
            });
            
            // Create animated particles
            function createParticles() {
                const particlesContainer = document.getElementById('particles');
                const particleCount = 15;
                
                for (let i = 0; i < particleCount; i++) {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    
                    // Random size between 5px and 20px
                    const size = Math.random() * 15 + 5;
                    particle.style.width = size + 'px';
                    particle.style.height = size + 'px';
                    
                    // Random position
                    particle.style.left = Math.random() * 100 + 'vw';
                    
                    // Random animation delay and duration
                    const delay = Math.random() * 5;
                    const duration = Math.random() * 10 + 15;
                    particle.style.animationDelay = delay + 's';
                    particle.style.animationDuration = duration + 's';
                    
                    // Random opacity
                    particle.style.opacity = Math.random() * 0.3 + 0.1;
                    
                    particlesContainer.appendChild(particle);
                }
            }
            
            createParticles();
            
            // Form submission animation
            const loginForm = document.getElementById('loginForm');
            loginForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Authenticating...';
                submitBtn.disabled = true;
            });
            
            // Auto-focus username field if empty
            if (!document.getElementById('username').value) {
                document.getElementById('username').focus();
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+Alt+L focuses login form
                if (e.ctrlKey && e.altKey && e.key === 'l') {
                    e.preventDefault();
                    document.getElementById('username').focus();
                }
                
                // Enter key submits form when focus is in password field
                if (e.key === 'Enter' && document.activeElement.id === 'password') {
                    document.getElementById('loginForm').requestSubmit();
                }
            });
            
            // Add dark theme styles dynamically
            const style = document.createElement('style');
            style.textContent = `
                body.dark-theme .login-card {
                    background: rgba(45, 55, 72, 0.95);
                    color: #e2e8f0;
                }
                
                body.dark-theme .form-control {
                    background-color: #2d3748;
                    border-color: #4a5568;
                    color: #e2e8f0;
                }
                
                body.dark-theme .form-control:focus {
                    background-color: #2d3748;
                    border-color: var(--primary-color);
                }
                
                body.dark-theme .input-group-text {
                    background-color: #2d3748;
                    border-color: #4a5568;
                    color: #e2e8f0;
                }
                
                body.dark-theme .security-notice {
                    background-color: #2d3748;
                    border-color: #4a5568;
                }
                
                body.dark-theme .footer-links {
                    border-top-color: #4a5568;
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>