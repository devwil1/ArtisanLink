<?php
// Initialize the session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in, if not then redirect him to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Initialize variables
$status = "none";
$application = [];
$created_at = "";
$reviewed_at = "";
$rejection_reason = "";
$has_unseen_rejection = false;

// Get application status
$sql = "SELECT pr.*, u.username, u.email 
        FROM professional_requests pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.user_id = ? 
        ORDER BY pr.created_at DESC 
        LIMIT 1";
        
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $application = mysqli_fetch_assoc($result);
        $status = $application['status'];
        $created_at = $application['created_at'];
        $reviewed_at = $application['reviewed_at'];
        $rejection_reason = $application['rejection_reason'] ?:'';
        
        // Check if this rejection has been seen by user
        if ($status == "rejected" && !isset($_SESSION['rejection_seen_' . $application['id']])) {
            $has_unseen_rejection = true;
            // Mark as seen in this session
            $_SESSION['rejection_seen_' . $application['id']] = true;
            
            // Also store in session for profile page notification
            $_SESSION['has_pending_rejection_notification'] = true;
        }
        
        // Clear the notification flag when user views the page
        if ($status == "rejected" && isset($_SESSION['has_pending_rejection_notification'])) {
            unset($_SESSION['has_pending_rejection_notification']);
        }
    }
    mysqli_stmt_close($stmt);
} else {
    echo "Oops! Something went wrong. Please try again later.";
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Application Status - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }
        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .status-card {
            text-align: center;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .approved {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .rejected {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .none {
            background-color: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }
        .application-details {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .rejection-reason-box {
            background-color: #fff;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin-top: 15px;
            border-radius: 5px;
        }
        .blink {
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .notification-alert {
            position: relative;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="wrapper">
            <div class="logo">
                <i class="fas fa-briefcase fa-3x text-primary mb-2"></i>
                <h2>Application Status</h2>
            </div>
            
            <?php if ($has_unseen_rejection) : ?>
            <div class="alert alert-danger notification-alert d-flex align-items-center mb-4">
                <i class="fas fa-bell fa-2x me-3"></i>
                <div>
                    <h5 class="alert-heading mb-1">Important Update!</h5>
                    <p class="mb-0">Your professional application has been reviewed. Please check the details below.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="status-card <?php echo $status; ?>">
                <?php if ($status == "pending") { ?>
                    <i class="fas fa-clock fa-4x mb-3"></i>
                    <h3>Application Pending Review</h3>
                    <p>Your application is under review. We'll notify you once a decision has been made.</p>
                    <p><strong>Submitted on:</strong> <?php echo date('F j, Y, g:i a', strtotime($created_at)); ?></p>
                    
                <?php } elseif ($status == "approved") { ?>
                    <i class="fas fa-check-circle fa-4x mb-3"></i>
                    <h3>Application Approved!</h3>
                    <p>Congratulations! Your application has been approved. You can now offer your services as a professional.</p>
                    <p><strong>Approved on:</strong> <?php echo date('F j, Y, g:i a', strtotime($reviewed_at)); ?></p>
                    <a href="welcome.php" class="btn btn-success">Go to Professional Dashboard</a>
                    
                <?php } elseif ($status == "rejected") { ?>
                    <i class="fas fa-times-circle fa-4x mb-3"></i>
                    <h3>Application Not Approved</h3>
                    <p>We're sorry, but your application was not approved at this time.</p>
                    
                    <?php if (!empty($rejection_reason)) : ?>
                        <div class="rejection-reason-box">
                            <strong><i class="fas fa-comment me-2"></i>Reason for Rejection:</strong>
                            <p class="mt-2 mb-0"><?php echo nl2br(htmlspecialchars($rejection_reason)); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            No specific feedback was provided. You may reapply with improved details.
                        </div>
                    <?php endif; ?>
                    
                    <p class="mt-3"><strong>Reviewed on:</strong> <?php echo date('F j, Y, g:i a', strtotime($reviewed_at)); ?></p>
                    
                    <div class="d-grid gap-2 d-md-block mt-3">
                        <a href="apply-professional.php?reapply=true" class="btn btn-primary btn-lg">
                            <i class="fas fa-redo me-2"></i>Reapply with Improvements
                        </a>
                        <a href="profile.php" class="btn btn-outline-secondary btn-lg">
                            <i class="fas fa-user me-2"></i>Go to Profile
                        </a>
                    </div>
                    
                <?php } else { ?>
                    <i class="fas fa-exclamation-circle fa-4x mb-3"></i>
                    <h3>No Application Found</h3>
                    <p>You haven't submitted an application to become a professional yet.</p>
                    <a href="apply-professional.php" class="btn btn-primary">Apply Now</a>
                <?php } ?>
            </div>
            
            <?php if ($status != "none" && !empty($application)) : ?>
            <div class="application-details">
                <h5>Application Details</h5>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($application['full_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($application['phone']); ?></p>
                        <p><strong>Profession:</strong> <?php echo htmlspecialchars($application['profession']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Pricing Type:</strong> <?php echo htmlspecialchars($application['pricing_type']); ?></p>
                        <p><strong>Service Area:</strong> <?php echo htmlspecialchars($application['service_area'] ?? 'Not specified'); ?></p>
                    </div>
                </div>
                
                <!-- Fixed Skills Display -->
                <p><strong>Skills/Services:</strong> 
                    <?php
                    $skills = $application['skills'];
                    
                    // Check if skills is JSON format (from Tagify)
                    if (strpos($skills, '[{') === 0) {
                        // It's JSON format - decode and extract values
                        $skills_array = json_decode($skills, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($skills_array)) {
                            $skill_values = [];
                            foreach ($skills_array as $skill) {
                                if (isset($skill['value'])) {
                                    $skill_values[] = htmlspecialchars($skill['value']);
                                }
                            }
                            echo implode(', ', $skill_values);
                        } else {
                            // Fallback: display raw content if JSON decoding fails
                            echo htmlspecialchars($skills);
                        }
                    } else {
                        // It's plain text or comma-separated
                        echo htmlspecialchars($skills);
                    }
                    ?>
                </p>
                
                <p><strong>Experience:</strong> <?php echo nl2br(htmlspecialchars($application['experience'])); ?></p>
                <?php if (!empty($application['portfolio_url'])) : ?>
                    <p><strong>Portfolio:</strong> <a href="<?php echo htmlspecialchars($application['portfolio_url']); ?>" target="_blank">View Portfolio</a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <p class="text-center mt-3"><a href="welcome.php">Back to Dashboard</a></p>
        </div>
    </div>    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>