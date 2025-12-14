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
$full_name = $birthdate = $bio = $profile_picture = $phone_number = $municipality = $barangay = $latitude = $longitude = "";
$full_name_err = $birthdate_err = $phone_number_err = $municipality_err = $barangay_err = $password_err = $confirm_password_err = "";
$current_password_err = "";

// Get user details
$user = [];
$sql = "SELECT * FROM users WHERE id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    // Populate variables with current user data
    $full_name = $user['full_name'] ?? '';
    $birthdate = $user['birthdate'] ?? '';
    $bio = $user['bio'] ?? '';
    $profile_picture = $user['profile_picture'] ?? '';
    $phone_number = $user['phone_number'] ?? '';
    $municipality = $user['municipality'] ?? '';
    $barangay = $user['barangay'] ?? '';
    $latitude = $user['latitude'] ?? '';
    $longitude = $user['longitude'] ?? '';
}

// Check if user already has a professional application
$has_application = false;
$application_status = '';
$application = [];
$sql = "SELECT pr.* FROM professional_requests pr WHERE pr.user_id = ? ORDER BY pr.created_at DESC LIMIT 1";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $has_application = true;
        $application = mysqli_fetch_assoc($result);
        $application_status = $application['status'];
    }
    mysqli_stmt_close($stmt);
}

// Get professional details for professional users
$professional_details = [];
$professional_services = [];
$professional_certifications = [];
$professional_contacts = [];

if ($_SESSION["user_type"] == 'professional') {
    // Get professional request details
    $professional_sql = "SELECT * FROM professional_requests WHERE user_id = ?";
    if ($stmt = mysqli_prepare($link, $professional_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $professional_details = mysqli_fetch_assoc($result) ?: [];
        mysqli_stmt_close($stmt);
    }
    
    // Get professional services
    $services_sql = "SELECT * FROM services WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $services_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_services[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get professional certifications
    $certs_sql = "SELECT * FROM professional_certifications WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $certs_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_certifications[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get professional contacts
    $contacts_sql = "SELECT * FROM professional_contacts WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $contacts_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_contacts[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get portfolio images (work samples)
    $portfolio_sql = "SELECT * FROM portfolio_images WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $portfolio_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $portfolio_images = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    }
}

// Get customer bookings
$customer_bookings = [];
$customer_booking_count = 0;

if ($_SESSION["user_type"] == 'customer') {
    // Get customer booking count
    $customer_sql = "SELECT COUNT(*) as booking_count FROM bookings WHERE customer_id = ?";
    if ($stmt = mysqli_prepare($link, $customer_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $customer_booking_count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    // Get customer bookings with professional details
    $bookings_sql = "SELECT b.*, 
                            u.username as professional_username, 
                            u.full_name as professional_name,
                            u.profile_picture as professional_profile,
                            s.title as service_title, 
                            s.price as service_price,
                            s.category as service_category,
                            pc.contact_value as professional_phone
                     FROM bookings b
                     JOIN users u ON b.professional_id = u.id
                     JOIN services s ON b.service_id = s.id
                     LEFT JOIN professional_contacts pc ON b.professional_id = pc.professional_id AND pc.is_primary = 1
                     WHERE b.customer_id = ?
                     ORDER BY b.booking_date DESC";
    if ($stmt = mysqli_prepare($link, $bookings_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $customer_bookings[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
}

// Get professional profile details if user is a professional
$professional_profile = [];
$business_info = [];
$recent_bookings = [];
$booking_stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0
];

if ($_SESSION["user_type"] == 'professional') {
    // Get professional profile
    $profile_sql = "SELECT * FROM professional_profile WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $profile_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $professional_profile = mysqli_fetch_assoc($result) ?: [];
        mysqli_stmt_close($stmt);
    }
    
    // Get recent bookings
    $bookings_sql = "SELECT b.*, u.username as customer_name, u.full_name as customer_full_name, 
                            s.title as service_title, s.price as service_price
                     FROM bookings b
                     JOIN users u ON b.customer_id = u.id
                     JOIN services s ON b.service_id = s.id
                     WHERE b.professional_id = ?
                     ORDER BY b.created_at DESC
                     LIMIT 5";
    if ($stmt = mysqli_prepare($link, $bookings_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $recent_bookings[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get booking statistics
    $stats_sql = "SELECT status, COUNT(*) as count 
                  FROM bookings 
                  WHERE professional_id = ? 
                  GROUP BY status";
    if ($stmt = mysqli_prepare($link, $stats_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $booking_stats[$row['status']] = $row['count'];
            $booking_stats['total'] += $row['count'];
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get average rating for professional - FIXED: Using feedback table instead of reviews
    $average_rating = 0;
    $rating_sql = "SELECT AVG(rating) as avg_rating FROM feedback WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $rating_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $avg_rating);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        $average_rating = $avg_rating ? round($avg_rating, 1) : 0;
    }
}

// Process form data when form is submitted - PROFILE UPDATE
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_profile"])) {
    // Validate full name
    if (empty(trim($_POST["full_name"]))) {
        $full_name_err = "Please enter your full name.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }
    
    // Validate birthdate
    if (!empty($_POST["birthdate"])) {
        $birthdate = trim($_POST["birthdate"]);
        // Validate date format
        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $birthdate)) {
            $birthdate_err = "Please enter a valid date format (YYYY-MM-DD).";
        }
    }
    
    // Validate phone number
    if (!empty($_POST["phone_number"])) {
        $phone_number = trim($_POST["phone_number"]);
        if (!preg_match("/^\+?[0-9]{10,15}$/", $phone_number)) {
            $phone_number_err = "Please enter a valid phone number.";
        }
    }
    
    // Validate municipality
    if (!empty($_POST["municipality"])) {
        $municipality = trim($_POST["municipality"]);
    }
    
    // Validate barangay
    if (!empty($_POST["barangay"])) {
        $barangay = trim($_POST["barangay"]);
    }
    
    // Get bio
    $bio = trim($_POST["bio"]);
    
    // Get coordinates
    $latitude = !empty($_POST["latitude"]) ? trim($_POST["latitude"]) : null;
    $longitude = !empty($_POST["longitude"]) ? trim($_POST["longitude"]) : null;
    
    // Handle profile picture upload
    $profile_picture_err = "";
    if (!empty($_FILES["profile_picture"]["name"])) {
        $target_dir = "uploads/profiles/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
        $new_filename = "profile_" . $_SESSION["id"] . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Check if image file is a actual image
        $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
        if ($check !== false) {
            // Check file size (5MB max)
            if ($_FILES["profile_picture"]["size"] > 5000000) {
                $profile_picture_err = "Sorry, your file is too large. Maximum size is 5MB.";
            } else {
                // Allow certain file formats
                $allowed_extensions = ["jpg", "jpeg", "png", "gif"];
                if (in_array($file_extension, $allowed_extensions)) {
                    if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                        $profile_picture = $target_file;
                        // Delete old profile picture if exists
                        if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                            unlink($user['profile_picture']);
                        }
                    } else {
                        $profile_picture_err = "Sorry, there was an error uploading your file.";
                    }
                } else {
                    $profile_picture_err = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                }
            }
        } else {
            $profile_picture_err = "File is not an image.";
        }
    }
    
    // Check input errors before updating database
    if (empty($full_name_err) && empty($birthdate_err) && empty($profile_picture_err) && empty($phone_number_err)) {
        // Prepare an update statement
        $sql = "UPDATE users SET full_name = ?, birthdate = ?, bio = ?, phone_number = ?, municipality = ?, barangay = ?, latitude = ?, longitude = ?";
        $params = [$full_name, $birthdate, $bio, $phone_number, $municipality, $barangay, $latitude, $longitude];
        $types = "ssssssss";
        
        // Add profile picture to query if uploaded
        if (!empty($profile_picture)) {
            $sql .= ", profile_picture = ?";
            $params[] = $profile_picture;
            $types .= "s";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $_SESSION["id"];
        $types .= "i";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            
            if (mysqli_stmt_execute($stmt)) {
                // Profile updated successfully
                $_SESSION["profile_update"] = "Profile updated successfully!";
                header("location: profile.php");
                exit;
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Process password change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change_password"])) {
    // Validate current password
    if (empty(trim($_POST["current_password"]))) {
        $current_password_err = "Please enter your current password.";
    } else {
        $current_password = trim($_POST["current_password"]);
        
        // Verify current password
        $sql = "SELECT password FROM users WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            mysqli_stmt_bind_result($stmt, $hashed_password);
            mysqli_stmt_fetch($stmt);
            
            if (!password_verify($current_password, $hashed_password)) {
                $current_password_err = "Current password is incorrect.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate new password
    if (empty(trim($_POST["new_password"]))) {
        $password_err = "Please enter a new password.";     
    } elseif (strlen(trim($_POST["new_password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $new_password = trim($_POST["new_password"]);
    }
    
    // Validate confirm password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm the password.";     
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($new_password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before updating the database
    if (empty($current_password_err) && empty($password_err) && empty($confirm_password_err)) {
        // Prepare an update statement
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            // Set parameters
            $new_password_param = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "si", $new_password_param, $_SESSION["id"]);
            
            // Attempt to execute the prepared statement
            if (mysqli_stmt_execute($stmt)) {
                // Password updated successfully
                $_SESSION["password_update"] = "Password updated successfully!";
                header("location: profile.php");
                exit;
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            
            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
}

// Handle booking cancellation
if (isset($_GET['cancel_booking']) && isset($_GET['booking_id'])) {
    $booking_id = $_GET['booking_id'];
    
    // Check if booking belongs to current user and is cancellable
    $check_sql = "SELECT * FROM bookings WHERE id = ? AND customer_id = ? AND status IN ('pending', 'confirmed')";
    if ($stmt = mysqli_prepare($link, $check_sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $booking_id, $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $booking = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if ($booking) {
            // Cancel the booking
            $cancel_sql = "UPDATE bookings SET status = 'cancelled' WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $cancel_sql)) {
                mysqli_stmt_bind_param($stmt, "i", $booking_id);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION["booking_cancelled"] = "Booking cancelled successfully!";
                } else {
                    $_SESSION["booking_cancelled"] = "Error cancelling booking. Please try again.";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $_SESSION["booking_cancelled"] = "Booking not found or cannot be cancelled.";
        }
    }
    
    header("location: profile.php");
    exit;
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body {
            background-color: #f3f2ef;
            font-family: -apple-system, system-ui, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        }
        .profile-header {
            background-color: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 20px 0;
        }
        .profile-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.15);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .profile-image {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 0 0 1px rgba(0,0,0,0.1);
        }
        .stats-card {
            text-align: center;
            padding: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .stats-card:last-child {
            border-bottom: none;
        }
        .nav-pills .nav-link {
            color: #666;
            font-weight: 500;
            border-radius: 0;
            padding: 12px 16px;
            border-bottom: 2px solid transparent;
        }
        .nav-pills .nav-link.active {
            background: transparent;
            color: #0a66c2;
            border-bottom: 2px solid #0a66c2;
        }
        .portfolio-image {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }
        .image-preview {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            display: none;
        }
        .booking-status-badge {
            font-size: 0.8em;
            padding: 0.35em 0.65em;
        }
        .btn-primary {
            background-color: #0a66c2;
            border-color: #0a66c2;
        }
        .btn-primary:hover {
            background-color: #004182;
            border-color: #004182;
        }
        .profile-banner {
            background: white;
            border-radius: 10px 10px 0 0;
            padding: 24px 24px 0;
        }
        .profile-content {
            padding: 24px;
        }
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: rgba(0,0,0,0.9);
            margin-bottom: 16px;
        }
        .info-item {
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: rgba(0,0,0,0.9);
            margin-bottom: 4px;
        }
        .info-value {
            color: rgba(0,0,0,0.6);
        }
        .stats-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: rgba(0,0,0,0.9);
        }
        .stats-label {
            color: rgba(0,0,0,0.6);
            font-size: 0.875rem;
        }
        .profile-actions {
            border-top: 1px solid #e0e0e0;
            padding: 16px 24px;
            background: #fafafa;
        }
        .booking-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .booking-row:hover {
            background-color: #f8f9fa;
        }
        .professional-avatar {
            width: 40px;
            height: 40px;
            object-fit: cover;
        }
        .professional-detail-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .professional-detail-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #333;
        }
        .professional-detail-body {
            padding: 20px;
        }
        .contact-badge {
            background: #e9ecef;
            color: #495057;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            margin-right: 8px;
            margin-bottom: 8px;
            display: inline-block;
        }
        .contact-badge.primary {
            background: #007bff;
            color: white;
        }
        .certification-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .service-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .service-price {
            font-size: 1.25rem;
            font-weight: 600;
            color: #28a745;
        }
        .work-sample-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        #map {
            height: 300px;
            width: 100%;
            border-radius: 8px;
            margin-top: 10px;
        }
        .map-container {
            position: relative;
        }
        .address-search {
            position: absolute;
            top: 10px;
            left: 50px;
            right: 50px;
            z-index: 1000;
        }
        .coordinates-display {
            background: rgba(255, 255, 255, 0.9);
            padding: 8px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 0.9em;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 8px;
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
        .blink {
            animation: blink 1s infinite;
        }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        .application-notification {
            position: relative;
            animation: pulse 2s infinite;
            border: 2px solid #dc3545;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        .reapply-btn {
            background: linear-gradient(45deg, #0a66c2, #004182);
            border: none;
            padding: 10px 20px;
            font-weight: 600;
        }
        .reapply-btn:hover {
            background: linear-gradient(45deg, #004182, #00254d);
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php if (!empty($user['profile_picture'])) : ?>
                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="profile-image rounded-circle" alt="Profile Picture">
                    <?php else : ?>
                        <div class="bg-light text-secondary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px;">
                            <i class="fas fa-user fa-3x"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h1 class="h2 mb-1"><?php echo !empty($user['full_name']) ? htmlspecialchars($user['full_name']) : htmlspecialchars($_SESSION["username"]); ?></h1>
                    <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email']); ?></p>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-<?php echo ($_SESSION["user_type"] == 'professional') ? 'success' : 'secondary'; ?> me-2">
                            <?php echo ucfirst($_SESSION["user_type"]); ?>
                        </span>
                        <?php if ($_SESSION["user_type"] == 'professional' && $average_rating > 0) : ?>
                            <span class="text-warning me-1">
                                <i class="fas fa-star"></i>
                            </span>
                            <span class="text-muted"><?php echo $average_rating; ?> • <?php echo $booking_stats['total']; ?> bookings</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <!-- Success Message -->
        <?php if (isset($_SESSION["profile_update"])) : ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION["profile_update"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION["profile_update"]); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION["password_update"])) : ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION["password_update"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION["password_update"]); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION["booking_cancelled"])) : ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo $_SESSION["booking_cancelled"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION["booking_cancelled"]); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION["booking_response"])) : ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo $_SESSION["booking_response"]; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION["booking_response"]); ?>
        <?php endif; ?>

        <!-- Application Status Notification -->
        <?php if ($_SESSION["user_type"] == 'customer' && $has_application && $application_status == 'rejected' && isset($_SESSION['has_pending_rejection_notification'])) : ?>
            <div class="alert alert-danger alert-dismissible fade show application-notification" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1">Application Update Required!</h5>
                        <p class="mb-0">Your professional application has been reviewed. Please check the status and reapply with improvements.</p>
                    </div>
                </div>
                <div class="mt-3">
                    <a href="application-status.php" class="btn btn-danger me-2">
                        <i class="fas fa-file-alt me-1"></i>View Details
                    </a>
                    <a href="apply-professional.php?reapply=true" class="btn btn-outline-danger">
                        <i class="fas fa-redo me-1"></i>Reapply Now
                    </a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" onclick="clearRejectionNotification()"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <!-- Profile Card -->
                <div class="profile-card">
                    <div class="profile-banner">
                        <!-- Professional Application Status -->
                        <?php if ($_SESSION["user_type"] == 'customer') : ?>
                            <?php if ($has_application) : ?>
                                <?php if ($application_status == 'pending') : ?>
                                    <div class="alert alert-warning mb-3">
                                        <i class="fas fa-clock me-2"></i>
                                        Your professional application is under review.
                                    </div>
                                    <a href="application-status.php" class="btn btn-outline-primary w-100 mb-3 position-relative">
                                        <i class="fas fa-eye me-2"></i>Check Application Status
                                        <?php if (isset($_SESSION['application_updated_notification'])) : ?>
                                            <span class="notification-badge"></span>
                                        <?php endif; ?>
                                    </a>
                                <?php elseif ($application_status == 'approved') : ?>
                                    <div class="alert alert-success mb-3">
                                        <i class="fas fa-check-circle me-2"></i>
                                        Your professional application has been approved!
                                    </div>
                                    <a href="welcome.php?selected_role=professional" class="btn btn-success w-100 mb-3">
                                        <i class="fas fa-briefcase me-2"></i>Go to Professional Dashboard
                                    </a>
                                <?php else : ?>
                                    <div class="alert alert-danger mb-3">
                                        <i class="fas fa-times-circle me-2"></i>
                                        Your application was not approved.
                                        <?php if (isset($_SESSION['has_pending_rejection_notification'])) : ?>
                                            <span class="badge bg-danger blink ms-2">NEW</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($application['rejection_reason']) || !empty($application['response_message'])) : ?>
                                        <div class="alert alert-warning small mb-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Feedback Available:</strong> Check the status page for details.
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-grid gap-2 mb-3">
                                        <a href="application-status.php" class="btn btn-outline-danger position-relative">
                                            <i class="fas fa-file-alt me-2"></i>View Details & Reason
                                            <?php if (isset($_SESSION['has_pending_rejection_notification'])) : ?>
                                                <span class="notification-badge"></span>
                                            <?php endif; ?>
                                        </a>
                                        <a href="apply-professional.php?reapply=true" class="btn btn-primary reapply-btn">
                                            <i class="fas fa-redo me-2"></i>Reapply Now
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <a href="apply-professional.php" class="btn btn-primary w-100 mb-3">
                                    <i class="fas fa-briefcase me-2"></i>Apply as Professional
                                </a>
                                <p class="text-muted small text-center">Offer your skills and services to customers</p>
                            <?php endif; ?>
                        <?php elseif ($_SESSION["user_type"] == 'professional') : ?>
                            <div class="alert alert-success mb-3">
                                <i class="fas fa-check-circle me-2"></i>
                                You are a verified professional!
                            </div>
                            <a href="welcome.php?selected_role=professional" class="btn btn-outline-primary w-100 mb-3">
                                Go to Professional Dashboard
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Statistics -->
                    <div class="stats-card">
                        <div class="stats-number">
                            <?php if ($_SESSION["user_type"] == 'professional') : ?>
                                <?php echo $booking_stats['total']; ?>
                            <?php else : ?>
                                <?php echo $customer_booking_count; ?>
                            <?php endif; ?>
                        </div>
                        <div class="stats-label">Total Bookings</div>
                    </div>

                    <?php if ($_SESSION["user_type"] == 'professional' && $average_rating > 0) : ?>
                    <div class="stats-card">
                        <div class="stats-number">
                            <?php echo $average_rating; ?>
                        </div>
                        <div class="stats-label">Average Rating</div>
                    </div>
                    <?php endif; ?>

                    <!-- Report Button -->
                    <?php if ($_SESSION["user_type"] == 'professional') : ?>
                    <div class="profile-actions text-center">
                        <h6>See something wrong with this profile?</h6>
                        <p class="text-muted small mb-2">If this profile appears suspicious or violates our terms, you can report it.</p>
                        <a href="report-profile.php?id=<?php echo $_SESSION['id']; ?>" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-flag me-1"></i>Report Profile
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-8">
                <!-- Navigation Tabs -->
                <div class="profile-card mb-4">
                    <ul class="nav nav-pills" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button" role="tab">
                                <i class="fas fa-user me-2"></i>Profile Info
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab">
                                <i class="fas fa-lock me-2"></i>Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bookings-tab" data-bs-toggle="pill" data-bs-target="#bookings" type="button" role="tab">
                                <i class="fas fa-calendar me-2"></i>My Bookings
                            </button>
                        </li>
                        <?php if ($_SESSION["user_type"] == 'professional') : ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="professional-tab" data-bs-toggle="pill" data-bs-target="#professional" type="button" role="tab">
                                <i class="fas fa-briefcase me-2"></i>Professional Profile
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="management-tab" data-bs-toggle="pill" data-bs-target="#management" type="button" role="tab">
                                <i class="fas fa-tasks me-2"></i>Booking Management
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Tab Content -->
                <div class="tab-content" id="profileTabsContent">
                    <!-- Profile Info Tab -->
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-content">
                                <h5 class="section-title">Personal Information</h5>
                                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="update_profile" value="1">
                                    
                                    <div class="form-section">
                                        <h6 class="form-section-title">Basic Information</h6>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Username</div>
                                                    <div class="info-value">
                                                        <input type="text" class="form-control border-0 p-0 bg-transparent" value="<?php echo htmlspecialchars($_SESSION["username"]); ?>" disabled>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Email</div>
                                                    <div class="info-value">
                                                        <input type="email" class="form-control border-0 p-0 bg-transparent" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Full Name *</div>
                                                    <div class="info-value">
                                                        <input type="text" name="full_name" class="form-control <?php echo (!empty($full_name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($full_name); ?>" required>
                                                        <span class="invalid-feedback"><?php echo $full_name_err; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Birthdate</div>
                                                    <div class="info-value">
                                                        <input type="date" name="birthdate" class="form-control <?php echo (!empty($birthdate_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($birthdate); ?>">
                                                        <span class="invalid-feedback"><?php echo $birthdate_err; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Bio</div>
                                            <div class="info-value">
                                                <textarea name="bio" class="form-control" rows="3" placeholder="Tell us about yourself"><?php echo htmlspecialchars($bio); ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Phone Number</div>
                                            <div class="info-value">
                                                <input type="text" name="phone_number" class="form-control <?php echo (!empty($phone_number_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($phone_number); ?>" placeholder="+63 XXX XXX XXXX">
                                                <span class="invalid-feedback"><?php echo $phone_number_err; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h6 class="form-section-title">Location Information</h6>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Municipality</div>
                                                    <div class="info-value">
                                                        <select name="municipality" class="form-control <?php echo (!empty($municipality_err)) ? 'is-invalid' : ''; ?>">
                                                            <option value="">Select Municipality</option>
                                                            <option value="Baler" <?php echo ($municipality == 'Baler') ? 'selected' : ''; ?>>Baler</option>
                                                            <option value="San Luis" <?php echo ($municipality == 'San Luis') ? 'selected' : ''; ?>>San Luis</option>
                                                            <option value="Maria Aurora" <?php echo ($municipality == 'Maria Aurora') ? 'selected' : ''; ?>>Maria Aurora</option>
                                                            <option value="Dipaculao" <?php echo ($municipality == 'Dipaculao') ? 'selected' : ''; ?>>Dipaculao</option>
                                                        </select>
                                                        <span class="invalid-feedback"><?php echo $municipality_err; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Barangay</div>
                                                    <div class="info-value">
                                                        <input type="text" name="barangay" class="form-control <?php echo (!empty($barangay_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($barangay); ?>" placeholder="Enter your barangay">
                                                        <span class="invalid-feedback"><?php echo $barangay_err; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Location Map</div>
                                            <div class="info-value">
                                                <p class="text-muted small mb-2">Click on the map to set your location coordinates</p>
                                                <div class="map-container">
                                                    <div id="map"></div>
                                                </div>
                                                <div class="coordinates-display mt-2">
                                                    <strong>Coordinates:</strong> 
                                                    <span id="coordinatesDisplay">
                                                        <?php if (!empty($latitude) && !empty($longitude)) : ?>
                                                            <?php echo htmlspecialchars($latitude); ?>, <?php echo htmlspecialchars($longitude); ?>
                                                        <?php else : ?>
                                                            Not set
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <input type="hidden" name="latitude" id="latitudeInput" value="<?php echo htmlspecialchars($latitude); ?>">
                                                <input type="hidden" name="longitude" id="longitudeInput" value="<?php echo htmlspecialchars($longitude); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h6 class="form-section-title">Profile Picture</h6>
                                        <div class="info-item">
                                            <div class="info-label">Profile Picture</div>
                                            <div class="info-value">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <?php if (!empty($user['profile_picture'])) : ?>
                                                            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="rounded-circle" alt="Current Profile Picture" style="width: 80px; height: 80px; object-fit: cover;">
                                                        <?php else : ?>
                                                            <div class="bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <input type="file" name="profile_picture" class="form-control" accept="image/*" onchange="previewImage(this)">
                                                        <div class="form-text">Max size: 5MB. Allowed formats: JPG, PNG, GIF</div>
                                                        <?php if (!empty($profile_picture_err)) : ?>
                                                            <div class="text-danger"><?php echo $profile_picture_err; ?></div>
                                                        <?php endif; ?>
                                                        <img id="imagePreview" class="image-preview mt-2" alt="Image Preview">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                        <a href="profile.php" class="btn btn-outline-secondary">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div class="tab-pane fade" id="security" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-content">
                                <h5 class="section-title">Change Password</h5>
                                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                    <input type="hidden" name="change_password" value="1">
                                    
                                    <div class="form-section">
                                        <div class="info-item">
                                            <div class="info-label">Current Password *</div>
                                            <div class="info-value">
                                                <input type="password" name="current_password" class="form-control <?php echo (!empty($current_password_err)) ? 'is-invalid' : ''; ?>" required>
                                                <span class="invalid-feedback"><?php echo $current_password_err; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">New Password *</div>
                                            <div class="info-value">
                                                <input type="password" name="new_password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" required>
                                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Confirm New Password *</div>
                                            <div class="info-value">
                                                <input type="password" name="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" required>
                                                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-primary">Change Password</button>
                                        <a href="profile.php" class="btn btn-outline-secondary">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Bookings Tab -->
                    <div class="tab-pane fade" id="bookings" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-content">
                                <h5 class="section-title">Booking History</h5>
                                
                                <?php if (count($customer_bookings) > 0) : ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Professional</th>
                                                    <th>Service</th>
                                                    <th>Date & Time</th>
                                                    <th>Status</th>
                                                    <th>Amount</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($customer_bookings as $booking) : ?>
                                                <tr class="booking-row" data-bs-toggle="modal" data-bs-target="#bookingModal" 
                                                    data-booking-id="<?php echo $booking['id']; ?>"
                                                    data-professional-name="<?php echo htmlspecialchars($booking['professional_name'] ?? $booking['professional_username']); ?>"
                                                    data-professional-phone="<?php echo htmlspecialchars($booking['professional_phone'] ?? 'Not available'); ?>"
                                                    data-service-title="<?php echo htmlspecialchars($booking['service_title']); ?>"
                                                    data-booking-date="<?php echo date('M j, Y g:i A', strtotime($booking['booking_date'])); ?>"
                                                    data-booking-address="<?php echo htmlspecialchars($booking['address']); ?>"
                                                    data-booking-notes="<?php echo htmlspecialchars($booking['notes']); ?>"
                                                    data-booking-status="<?php echo $booking['status']; ?>"
                                                    data-professional-profile="<?php echo htmlspecialchars($booking['professional_profile'] ?? ''); ?>"
                                                    data-service-price="<?php echo number_format($booking['service_price'], 2); ?>">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if (!empty($booking['professional_profile'])) : ?>
                                                                <img src="<?php echo htmlspecialchars($booking['professional_profile']); ?>" class="professional-avatar rounded-circle me-2" alt="Professional">
                                                            <?php else : ?>
                                                                <div class="professional-avatar bg-light text-secondary rounded-circle d-flex align-items-center justify-content-center me-2">
                                                                    <i class="fas fa-user"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($booking['professional_name'] ?? $booking['professional_username']); ?></strong>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($booking['service_title']); ?></td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                        <small><?php echo date('g:i A', strtotime($booking['booking_date'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            switch($booking['status']) {
                                                                case 'pending': echo 'warning'; break;
                                                                case 'confirmed': echo 'success'; break;
                                                                case 'completed': echo 'info'; break;
                                                                case 'cancelled': echo 'danger'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?> booking-status-badge">
                                                            <?php echo ucfirst($booking['status']); ?>
                                                        </span>
                                                        <?php if ($booking['professional_response'] == 'accepted') : ?>
                                                            <br><small class="text-success">Accepted by professional</small>
                                                        <?php elseif ($booking['professional_response'] == 'rejected') : ?>
                                                            <br><small class="text-danger">Rejected by professional</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>₱<?php echo number_format($booking['service_price'], 2); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else : ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-calendar fa-3x text-muted mb-3"></i>
                                        <h5>No bookings yet</h5>
                                        <p class="text-muted">When you book services, they will appear here.</p>
                                        <a href="services.php" class="btn btn-primary">Find Services</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($_SESSION["user_type"] == 'professional') : ?>
                    <!-- Professional Profile Tab - UPDATED -->
                    <div class="tab-pane fade" id="professional" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-content">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="section-title mb-0">Professional Profile</h5>
                                    <div>
                                        <a href="edit-professional-profile.php" class="btn btn-primary">
                                            <i class="fas fa-edit me-2"></i>Edit Profile
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Personal Information -->
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-user me-2"></i>Personal Information
                                    </div>
                                    <div class="professional-detail-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Full Name</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($professional_details['full_name'] ?? 'Not specified'); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Age</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($professional_details['age'] ?? 'Not specified'); ?> years old</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Profession</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($professional_details['profession'] ?? 'Not specified'); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Pricing Type</div>
                                                    <div class="info-value">
                                                        <?php 
                                                        $pricing_type = $professional_details['pricing_type'] ?? '';
                                                        switch($pricing_type) {
                                                            case 'per_job': echo 'Per Job'; break;
                                                            case 'daily': echo 'Daily Rate'; break;
                                                            case 'both': echo 'Both'; break;
                                                            default: echo 'Not specified';
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contact Information -->
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-phone me-2"></i>Contact Information
                                    </div>
                                    <div class="professional-detail-body">
                                        <div class="info-item">
                                            <div class="info-label">Primary Phone</div>
                                            <div class="info-value"><?php echo htmlspecialchars($professional_details['phone'] ?? 'Not specified'); ?></div>
                                        </div>
                                        
                                        <?php if (!empty($professional_contacts)) : ?>
                                            <div class="info-item">
                                                <div class="info-label">Additional Contact Methods</div>
                                                <div class="info-value">
                                                    <?php foreach ($professional_contacts as $contact) : ?>
                                                        <?php if (!$contact['is_primary']) : ?>
                                                            <span class="contact-badge">
                                                                <i class="fas fa-<?php echo $contact['contact_type']; ?> me-1"></i>
                                                                <?php echo ucfirst($contact['contact_type']); ?>: <?php echo htmlspecialchars($contact['contact_value']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Location Information -->
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-map-marker-alt me-2"></i>Location Information
                                    </div>
                                    <div class="professional-detail-body">
                                        <div class="info-item">
                                            <div class="info-label">Complete Address</div>
                                            <div class="info-value"><?php echo htmlspecialchars($professional_details['address'] ?? 'Not specified'); ?></div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Municipality</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($professional_details['municipality'] ?? 'Not specified'); ?></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="info-item">
                                                    <div class="info-label">Barangay</div>
                                                    <div class="info-value"><?php echo htmlspecialchars($professional_details['barangay'] ?? 'Not specified'); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($professional_details['latitude']) && !empty($professional_details['longitude'])) : ?>
                                        <div class="info-item">
                                            <div class="info-label">Coordinates</div>
                                            <div class="info-value">
                                                Latitude: <?php echo htmlspecialchars($professional_details['latitude']); ?>, 
                                                Longitude: <?php echo htmlspecialchars($professional_details['longitude']); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Professional Description -->
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-file-alt me-2"></i>Professional Description
                                    </div>
                                    <div class="professional-detail-body">
                                        <div class="info-item">
                                            <div class="info-label">About Me & Experience</div>
                                            <div class="info-value"><?php echo htmlspecialchars($professional_details['experience'] ?? 'Not specified'); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Certifications & Licenses -->
                                <?php if (!empty($professional_certifications)) : ?>
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-award me-2"></i>Certifications & Licenses
                                    </div>
                                    <div class="professional-detail-body">
                                        <?php foreach ($professional_certifications as $cert) : ?>
                                            <div class="certification-item">
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <h6 class="mb-2"><?php echo htmlspecialchars($cert['certificate_name']); ?></h6>
                                                        <p class="text-muted mb-2">Issued by: <?php echo htmlspecialchars($cert['issuing_organization']); ?></p>
                                                        <div class="row">
                                                            <?php if (!empty($cert['issue_date'])) : ?>
                                                            <div class="col-md-6">
                                                                <small class="text-muted">Issue Date: <?php echo date('M j, Y', strtotime($cert['issue_date'])); ?></small>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($cert['expiry_date'])) : ?>
                                                            <div class="col-md-6">
                                                                <small class="text-muted">Expiry Date: <?php echo date('M j, Y', strtotime($cert['expiry_date'])); ?></small>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 text-center">
                                                        <?php if (!empty($cert['certificate_image'])) : ?>
                                                            <img src="<?php echo htmlspecialchars($cert['certificate_image']); ?>" class="work-sample-image" alt="Certificate Image">
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Work Samples -->
                                <?php if (!empty($portfolio_images)) : ?>
                                <div class="professional-detail-card">
                                    <div class="professional-detail-header">
                                        <i class="fas fa-images me-2"></i>Work Samples
                                    </div>
                                    <div class="professional-detail-body">
                                        <div class="row">
                                            <?php foreach ($portfolio_images as $image) : ?>
                                                <div class="col-md-6 mb-3">
                                                    <img src="<?php echo htmlspecialchars($image['image_path']); ?>" class="work-sample-image" alt="Work Sample">
                                                    <?php if (!empty($image['caption'])) : ?>
                                                        <p class="text-muted mt-2"><?php echo htmlspecialchars($image['caption']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Management Tab -->
                    <div class="tab-pane fade" id="management" role="tabpanel">
                        <div class="profile-card">
                            <div class="profile-content">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="section-title mb-0">Booking Management</h5>
                                    <a href="professional-bookings.php" class="btn btn-outline-primary">View All Bookings</a>
                                </div>
                                
                                <!-- Booking Statistics -->
                                <div class="row mb-4">
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="stats-card text-center">
                                            <div class="stats-number"><?php echo $booking_stats['total']; ?></div>
                                            <div class="stats-label">Total Bookings</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="stats-card text-center">
                                            <div class="stats-number"><?php echo $booking_stats['pending']; ?></div>
                                            <div class="stats-label">Pending</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="stats-card text-center">
                                            <div class="stats-number"><?php echo $booking_stats['confirmed']; ?></div>
                                            <div class="stats-label">Confirmed</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="stats-card text-center">
                                            <div class="stats-number"><?php echo $booking_stats['completed']; ?></div>
                                            <div class="stats-label">Completed</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-6 mb-3">
                                        <div class="stats-card text-center">
                                            <div class="stats-number"><?php echo $booking_stats['cancelled']; ?></div>
                                            <div class="stats-label">Cancelled</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Bookings -->
                                <h6 class="mb-3">Recent Bookings</h6>
                                <?php if (count($recent_bookings) > 0) : ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Customer</th>
                                                    <th>Service</th>
                                                    <th>Date & Time</th>
                                                    <th>Status</th>
                                                    <th>Amount</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_bookings as $booking) : ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($booking['customer_full_name'] ?? $booking['customer_name']); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($booking['service_title']); ?></td>
                                                    <td>
                                                        <?php echo date('M j, Y', strtotime($booking['booking_date'])); ?><br>
                                                        <small><?php echo date('g:i A', strtotime($booking['booking_date'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            switch($booking['status']) {
                                                                case 'pending': echo 'warning'; break;
                                                                case 'confirmed': echo 'success'; break;
                                                                case 'completed': echo 'info'; break;
                                                                case 'cancelled': echo 'danger'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?> booking-status-badge">
                                                            <?php echo ucfirst($booking['status']); ?>
                                                        </span>
                                                        <?php if ($booking['professional_response'] == 'pending') : ?>
                                                            <br><small class="text-warning">Awaiting response</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>₱<?php echo number_format($booking['service_price'], 2); ?></td>
                                                    <td>
                                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if ($booking['status'] == 'pending' && $booking['professional_response'] == 'pending') : ?>
                                                            <a href="respond-booking.php?id=<?php echo $booking['id']; ?>&action=accept" class="btn btn-sm btn-success">
                                                                <i class="fas fa-check"></i>
                                                            </a>
                                                            <a href="respond-booking.php?id=<?php echo $booking['id']; ?>&action=reject" class="btn btn-sm btn-danger">
                                                                <i class="fas fa-times"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else : ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                        <h5>No bookings yet</h5>
                                        <p class="text-muted">You'll see booking requests here when customers book your services.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingModalLabel">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <img id="modalProfessionalProfile" src="" class="professional-avatar rounded-circle mb-2" alt="Professional" style="width: 80px; height: 80px; object-fit: cover;">
                            <h6 id="modalProfessionalName"></h6>
                            <div class="text-muted small" id="modalProfessionalPhone"></div>
                        </div>
                        <div class="col-md-8">
                            <div class="row mb-3">
                                <div class="col-6">
                                    <strong>Service:</strong>
                                    <div id="modalServiceTitle"></div>
                                </div>
                                <div class="col-6">
                                    <strong>Price:</strong>
                                    <div id="modalServicePrice"></div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <strong>Booking Date:</strong>
                                    <div id="modalBookingDate"></div>
                                </div>
                                <div class="col-6">
                                    <strong>Status:</strong>
                                    <div>
                                        <span id="modalBookingStatus" class="badge"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <strong>Service Address:</strong>
                                <div id="modalBookingAddress"></div>
                            </div>
                            <div class="mb-3">
                                <strong>Additional Notes:</strong>
                                <div id="modalBookingNotes"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a id="cancelBookingBtn" href="#" class="btn btn-danger">Cancel Booking</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Initialize Leaflet Map
        let map, marker;
        
        function initMap() {
            // Default coordinates for Aurora, Philippines
            const defaultLat = 15.7500;
            const defaultLng = 121.5000;
            
            // Use user's coordinates if available, otherwise use default
            const userLat = <?php echo !empty($latitude) ? $latitude : 'defaultLat'; ?>;
            const userLng = <?php echo !empty($longitude) ? $longitude : 'defaultLng'; ?>;
            
            // Initialize map
            map = L.map('map').setView([userLat, userLng], 12);
            
            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add marker if user has coordinates
            if (<?php echo !empty($latitude) && !empty($longitude) ? 'true' : 'false'; ?>) {
                marker = L.marker([userLat, userLng]).addTo(map);
            }
            
            // Add click event to map
            map.on('click', function(e) {
                const lat = e.latlng.lat;
                const lng = e.latlng.lng;
                
                // Update coordinates display
                document.getElementById('coordinatesDisplay').textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
                
                // Update hidden inputs
                document.getElementById('latitudeInput').value = lat;
                document.getElementById('longitudeInput').value = lng;
                
                // Add or update marker
                if (marker) {
                    marker.setLatLng(e.latlng);
                } else {
                    marker = L.marker(e.latlng).addTo(map);
                }
                
                // Reverse geocode to get address (optional)
                // reverseGeocode(lat, lng);
            });
        }
        
        // Function to reverse geocode coordinates (optional)
        function reverseGeocode(lat, lng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.display_name) {
                        console.log('Address:', data.display_name);
                        // You could update address fields here if needed
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            // Booking Modal functionality
            const bookingModal = document.getElementById('bookingModal');
            if (bookingModal) {
                bookingModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    // Extract booking data from data attributes
                    const bookingId = button.getAttribute('data-booking-id');
                    const professionalName = button.getAttribute('data-professional-name');
                    const professionalPhone = button.getAttribute('data-professional-phone');
                    const serviceTitle = button.getAttribute('data-service-title');
                    const bookingDate = button.getAttribute('data-booking-date');
                    const bookingAddress = button.getAttribute('data-booking-address');
                    const bookingNotes = button.getAttribute('data-booking-notes');
                    const bookingStatus = button.getAttribute('data-booking-status');
                    const professionalProfile = button.getAttribute('data-professional-profile');
                    const servicePrice = button.getAttribute('data-service-price');
                    
                    // Update modal content
                    document.getElementById('modalProfessionalName').textContent = professionalName;
                    document.getElementById('modalProfessionalPhone').textContent = professionalPhone;
                    document.getElementById('modalServiceTitle').textContent = serviceTitle;
                    document.getElementById('modalServicePrice').textContent = '₱' + servicePrice;
                    document.getElementById('modalBookingDate').textContent = bookingDate;
                    document.getElementById('modalBookingAddress').textContent = bookingAddress || 'No address specified';
                    document.getElementById('modalBookingNotes').textContent = bookingNotes || 'No additional notes';
                    
                    // Set professional profile image
                    const profileImg = document.getElementById('modalProfessionalProfile');
                    if (professionalProfile) {
                        profileImg.src = professionalProfile;
                    } else {
                        profileImg.src = ''; // You can set a default avatar here
                    }
                    
                    // Set booking status with appropriate badge color
                    const statusBadge = document.getElementById('modalBookingStatus');
                    statusBadge.textContent = bookingStatus.charAt(0).toUpperCase() + bookingStatus.slice(1);
                    statusBadge.className = 'badge bg-' + getStatusColor(bookingStatus);
                    
                    // Set up cancel booking button
                    const cancelBtn = document.getElementById('cancelBookingBtn');
                    if (bookingStatus === 'pending' || bookingStatus === 'confirmed') {
                        cancelBtn.style.display = 'block';
                        cancelBtn.href = 'profile.php?cancel_booking=true&booking_id=' + bookingId;
                    } else {
                        cancelBtn.style.display = 'none';
                    }
                });
            }
            
            function getStatusColor(status) {
                switch(status) {
                    case 'pending': return 'warning';
                    case 'confirmed': return 'success';
                    case 'completed': return 'info';
                    case 'cancelled': return 'danger';
                    default: return 'secondary';
                }
            }
        });

        // Function to clear rejection notification
        function clearRejectionNotification() {
            // You can implement an AJAX call here to mark notification as read in database
            // For now, we'll just remove it from session via page refresh
            window.location.href = 'profile.php?clear_rejection_notification=true';
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>