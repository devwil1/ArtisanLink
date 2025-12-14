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

// Check if user has both customer and professional roles
$is_professional = ($_SESSION["user_type"] == "professional" || $_SESSION["user_type"] == "admin" || $_SESSION["user_type"] == "super_admin");

// Handle role selection if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["selected_role"])) {
    $_SESSION["current_role"] = $_POST["selected_role"];
    header("location: welcome.php");
    exit;
}

// Set default role if not set
if (!isset($_SESSION["current_role"])) {
    $_SESSION["current_role"] = $_SESSION["user_type"];
}

// Get user's location if available
$user_latitude = null;
$user_longitude = null;
$user_municipality = null;
$user_barangay = null;

$location_sql = "SELECT latitude, longitude, municipality, barangay FROM users WHERE id = ?";
if ($stmt = mysqli_prepare($link, $location_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $user_latitude, $user_longitude, $user_municipality, $user_barangay);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}

// Get professional's services for the dashboard
$professional_services = [];
$professional_bookings = [];
$professional_reviews = [];
$pending_bookings_count = 0;

if ($_SESSION["current_role"] == "professional") {
    // Get professional's services from services table
    $services_sql = "SELECT s.title, s.description, s.price, s.pricing_type 
                    FROM services s 
                    WHERE s.professional_id = ?";
    
    if ($stmt = mysqli_prepare($link, $services_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_services[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get professional's bookings
    $bookings_sql = "SELECT b.*, u.full_name as customer_full_name, 
                            s.title as service_title, b.status, b.booking_date, b.customer_phone, b.notes,
                            b.created_at, b.professional_response, b.address
                    FROM bookings b
                    JOIN users u ON b.customer_id = u.id
                    LEFT JOIN services s ON b.service_id = s.id
                    WHERE b.professional_id = ?
                    ORDER BY b.booking_date DESC
                    LIMIT 10";
                    
    if ($stmt = mysqli_prepare($link, $bookings_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_bookings[] = $row;
            if ($row['status'] == 'pending' && $row['professional_response'] == 'pending') {
                $pending_bookings_count++;
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get professional's reviews
    $reviews_sql = "SELECT f.*, u.full_name as customer_full_name,
                           b.booking_date, s.title as service_title
                    FROM feedback f
                    JOIN users u ON f.customer_id = u.id
                    JOIN bookings b ON f.booking_id = b.id
                    LEFT JOIN services s ON b.service_id = s.id
                    WHERE f.professional_id = ?
                    ORDER BY f.created_at DESC
                    LIMIT 5";
                    
    if ($stmt = mysqli_prepare($link, $reviews_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $professional_reviews[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // Get professional's blocked dates for calendar
    $blocked_dates = [];
    $blocked_sql = "SELECT blocked_date, reason FROM professional_blocked_dates WHERE professional_id = ?";
    if ($stmt = mysqli_prepare($link, $blocked_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $blocked_dates[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    // Handle block date request
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["block_date"])) {
        $block_date = trim($_POST["block_date"]);
        $reason = trim($_POST["reason"] ?? 'Busy');
        
        $insert_sql = "INSERT INTO professional_blocked_dates (professional_id, blocked_date, reason) VALUES (?, ?, ?)";
        if ($stmt = mysqli_prepare($link, $insert_sql)) {
            mysqli_stmt_bind_param($stmt, "iss", $_SESSION["id"], $block_date, $reason);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            header("location: welcome.php");
            exit;
        }
    }

    // Handle unblock date request
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["unblock_date"])) {
        $unblock_date = trim($_POST["unblock_date"]);
        
        $delete_sql = "DELETE FROM professional_blocked_dates WHERE professional_id = ? AND blocked_date = ?";
        if ($stmt = mysqli_prepare($link, $delete_sql)) {
            mysqli_stmt_bind_param($stmt, "is", $_SESSION["id"], $unblock_date);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            header("location: welcome.php");
            exit;
        }
    }

    // Handle booking response
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["booking_response"])) {
        $booking_id = trim($_POST["booking_id"]);
        $response = trim($_POST["response"]);
        
        $update_sql = "UPDATE bookings SET professional_response = ?, status = ? WHERE id = ? AND professional_id = ?";
        if ($stmt = mysqli_prepare($link, $update_sql)) {
            $new_status = $response == 'accepted' ? 'confirmed' : 'cancelled';
            mysqli_stmt_bind_param($stmt, "ssii", $response, $new_status, $booking_id, $_SESSION["id"]);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Create notification for customer
            $booking_info_sql = "SELECT customer_id, service_id FROM bookings WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $booking_info_sql)) {
                mysqli_stmt_bind_param($stmt, "i", $booking_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $customer_id, $service_id);
                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);

                $service_sql = "SELECT title FROM services WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $service_sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $service_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_bind_result($stmt, $service_title);
                    mysqli_stmt_fetch($stmt);
                    mysqli_stmt_close($stmt);

                    $notification_msg = "Your booking for " . $service_title . " has been " . 
                                      ($response == 'accepted' ? "confirmed" : "declined") . " by the professional";
                    
                    $notification_sql = "INSERT INTO notifications (user_id, message, type, related_id) VALUES (?, ?, 'booking_response', ?)";
                    if ($notif_stmt = mysqli_prepare($link, $notification_sql)) {
                        mysqli_stmt_bind_param($notif_stmt, "isi", $customer_id, $notification_msg, $booking_id);
                        mysqli_stmt_execute($notif_stmt);
                        mysqli_stmt_close($notif_stmt);
                    }
                }
            }

            header("location: welcome.php");
            exit;
        }
    }
}

// Check for expired bookings (auto-cancel after 24 hours)
$expired_sql = "UPDATE bookings 
               SET status = 'expired', professional_response = 'expired' 
               WHERE status = 'pending' 
               AND professional_response = 'pending' 
               AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
mysqli_query($link, $expired_sql);

// Get all categories from services table
$all_categories = [];
$categories_sql = "SELECT DISTINCT category FROM services WHERE category IS NOT NULL AND category != ''";
if ($result = mysqli_query($link, $categories_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        if (!empty($row['category']) && !in_array($row['category'], $all_categories)) {
            $all_categories[] = $row['category'];
        }
    }
    mysqli_free_result($result);
}
sort($all_categories);

// Get popular professionals (highest rated with most reviews) - FIXED: Using user_type instead of current_role
$popular_professionals = [];
$sql = "SELECT 
            u.id, 
            u.username, 
            u.full_name, 
            u.profile_picture,
            u.latitude, 
            u.longitude, 
            u.municipality, 
            u.barangay,
            pr.profession, 
            pr.pricing_type,
            COALESCE(AVG(f.rating), 0) as avg_rating, 
            COUNT(DISTINCT f.id) as review_count,
            COUNT(DISTINCT s.id) as service_count,
            GROUP_CONCAT(DISTINCT s.title SEPARATOR ', ') as service_titles,
            MIN(s.id) as first_service_id
        FROM users u
        JOIN professional_requests pr ON u.id = pr.user_id
        JOIN services s ON u.id = s.professional_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        WHERE u.user_type = 'professional' 
          AND pr.status = 'approved'
          AND (u.municipality IN ('Baler', 'San Luis', 'Maria Aurora', 'Dipaculao') OR u.municipality IS NULL)
        GROUP BY u.id, u.username, u.full_name, u.profile_picture, u.latitude, u.longitude, u.municipality, u.barangay, pr.profession, pr.pricing_type
        HAVING service_count > 0 AND avg_rating >= 4.0
        ORDER BY avg_rating DESC, review_count DESC
        LIMIT 20";
        
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Parse service titles
        if (!empty($row['service_titles'])) {
            $row['services_list'] = array_slice(explode(', ', $row['service_titles']), 0, 3);
        } else {
            $row['services_list'] = [];
        }
        
        $popular_professionals[] = $row;
    }
    mysqli_free_result($result);
}

// Get all professionals for the list - FIXED: Using user_type instead of current_role
// Modified to include current professional if they are viewing as customer
$all_professionals = [];
$all_sql = "SELECT 
            u.id, 
            u.username, 
            u.full_name, 
            u.profile_picture,
            u.latitude, 
            u.longitude, 
            u.municipality, 
            u.barangay,
            pr.profession, 
            pr.pricing_type,
            COALESCE(AVG(f.rating), 0) as avg_rating, 
            COUNT(DISTINCT f.id) as review_count,
            COUNT(DISTINCT s.id) as service_count,
            GROUP_CONCAT(DISTINCT s.title SEPARATOR ', ') as service_titles,
            MIN(s.id) as first_service_id
        FROM users u
        JOIN professional_requests pr ON u.id = pr.user_id
        JOIN services s ON u.id = s.professional_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        WHERE u.user_type = 'professional' 
          AND pr.status = 'approved'
          AND (u.municipality IN ('Baler', 'San Luis', 'Maria Aurora', 'Dipaculao') OR u.municipality IS NULL)
        GROUP BY u.id, u.username, u.full_name, u.profile_picture, u.latitude, u.longitude, u.municipality, u.barangay, pr.profession, pr.pricing_type
        HAVING service_count > 0
        ORDER BY avg_rating DESC, review_count DESC";
        
if ($result = mysqli_query($link, $all_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Parse service titles
        if (!empty($row['service_titles'])) {
            $row['services_list'] = array_slice(explode(', ', $row['service_titles']), 0, 3);
        } else {
            $row['services_list'] = [];
        }
        
        $all_professionals[] = $row;
    }
    mysqli_free_result($result);
}

// Get user's hiring history
$hiring_history = [];
$history_sql = "SELECT b.*, u.full_name as professional_full_name, u.id as professional_id, 
                       s.title as service_title, f.rating, f.comment, f.id as feedback_id
                FROM bookings b
                JOIN users u ON b.professional_id = u.id
                JOIN services s ON b.service_id = s.id
                LEFT JOIN feedback f ON b.id = f.booking_id
                WHERE b.customer_id = ?
                ORDER BY b.booking_date DESC
                LIMIT 5";
                
if ($stmt = mysqli_prepare($link, $history_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $history_result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($history_result)) {
        $hiring_history[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Close connection
mysqli_close($link);
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if ($_SESSION["current_role"] == "professional"): ?>
    <!-- FullCalendar CSS - Only load for professionals -->
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <?php endif; ?>
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #a29bfe;
            --light: #f8f9fa;
            --dark: #2d3436;
            --success: #00b894;
            --danger: #d63031;
            --warning: #fdcb6e;
            --info: #0984e3;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .professional-card {
            border: none;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            height: 100%;
            background: white;
            position: relative;
        }
        
        .professional-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
        }
        
        .rating {
            color: var(--warning);
        }
        
        .profile-image-container {
            width: 100px;
            height: 100px;
            margin: 0 auto;
            position: relative;
            margin-top: -50px;
            z-index: 2;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border: 4px solid white;
            border-radius: 50%;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .profile-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 4px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .services-list {
            font-size: 0.85em;
            color: #666;
        }
        
        .location-options {
            background: rgba(255, 255, 255, 0.2);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }
        
        .service-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            margin: 3px;
            display: inline-block;
            font-weight: 500;
        }
        
        .booking-status {
            font-size: 0.8em;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-expired { background: #e2e3e5; color: #383d41; }
        
        .search-section {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(108, 92, 231, 0.3);
        }
        
        .search-section h2 {
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        /* Fix text visibility in search form */
        .search-section .form-control, 
        .search-section .form-select {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.95);
            color: #333;
            transition: all 0.3s;
        }
        
        .search-section .form-control::placeholder {
            color: #666;
        }
        
        .search-section .form-control:focus, 
        .search-section .form-select:focus {
            background: white;
            border-color: rgba(255,255,255,0.8);
            box-shadow: 0 0 0 0.2rem rgba(255,255,255,0.3);
            color: #333;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--info), #74b9ff);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(116, 185, 255, 0.4);
        }
        
        .btn-outline-light {
            border: 2px solid rgba(255,255,255,0.8);
            color: white;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s;
            background: rgba(255,255,255,0.1);
        }
        
        .btn-outline-light:hover {
            background: rgba(255,255,255,0.2);
            color: white;
            transform: translateY(-2px);
            border-color: white;
        }
        
        .section-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 25px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 3px;
        }
        
        .role-selector {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .history-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            border-left: 4px solid var(--primary);
        }
        
        .history-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .professional-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            height: 120px;
            border-radius: 15px 15px 0 0;
        }
        
        .category-badge {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary);
            padding: 8px 15px;
            border-radius: 20px;
            margin: 5px;
            display: inline-block;
            font-size: 0.85em;
            font-weight: 500;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .category-badge:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        .category-badge.active {
            background: var(--primary);
            color: white;
        }
        
        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 20px;
        }
        
        .stats-card.services { background: linear-gradient(135deg, #00b894, #00a085); }
        .stats-card.bookings { background: linear-gradient(135deg, #0984e3, #0767b1); }
        .stats-card.reviews { background: linear-gradient(135deg, #fdcb6e, #f9a825); }
        .stats-card.rating { background: linear-gradient(135deg, #e17055, #d63031); }
        .stats-card.pending { background: linear-gradient(135deg, #fdcb6e, #e17055); }
        
        .location-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.8);
            color: white;
            border-radius: 8px;
            padding: 8px 15px;
            font-weight: 500;
            transition: all 0.3s;
            backdrop-filter: blur(5px);
        }
        
        .location-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            border-color: white;
        }

        /* Share button styles */
        .share-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            z-index: 10;
            cursor: pointer;
        }
        .share-btn:hover {
            background: white;
            transform: scale(1.1);
        }
        .share-btn i {
            color: var(--primary);
        }

        /* Calendar Styles */
        #professionalCalendar {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .fc-toolbar {
            padding: 15px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 10px 10px 0 0;
            margin: -20px -20px 20px -20px;
        }
        .fc-toolbar-title {
            color: white;
            font-weight: 600;
        }
        .fc-button {
            background: rgba(255,255,255,0.2) !important;
            border: 1px solid rgba(255,255,255,0.3) !important;
            color: white !important;
        }
        .fc-button:hover {
            background: rgba(255,255,255,0.3) !important;
        }
        .fc-daygrid-block-event {
            border-radius: 8px;
            border: none;
        }
        .fc-event {
            border-radius: 6px;
            font-size: 0.85em;
            padding: 2px 4px;
        }

        /* Notification Bell Styles */
        .notification-bell {
            position: relative;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--primary);
            transition: all 0.3s;
        }
        .notification-bell:hover {
            color: var(--primary-dark);
            transform: scale(1.1);
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .notification-dropdown {
            min-width: 400px;
            max-height: 500px;
            overflow-y: auto;
        }
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: all 0.3s;
        }
        .notification-item:hover {
            background-color: #f8f9fa;
        }
        .notification-item.unread {
            background-color: #e7f3ff;
            border-left: 4px solid var(--info);
        }
        .notification-time {
            font-size: 0.8em;
            color: #6c757d;
        }
        .booking-details {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-top: 8px;
            font-size: 0.9em;
        }
        .booking-details p {
            margin-bottom: 5px;
        }

        /* Block Date Modal */
        .block-date-modal .modal-content {
            border-radius: 15px;
            border: none;
        }
        .block-date-modal .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 15px 15px 0 0;
        }

        /* Booking Details Modal */
        .booking-modal .modal-content {
            border-radius: 15px;
            border: none;
        }
        .booking-modal .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 15px 15px 0 0;
        }
        .booking-detail-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .booking-detail-item:last-child {
            border-bottom: none;
        }

        /* Popular Professionals List Section */
        .popular-professionals-list-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        /* Location badge for professionals list */
        .location-badge {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            display: inline-block;
            margin-bottom: 8px;
        }
        
        /* Distance indicator */
        .distance-badge {
            background: linear-gradient(135deg, #00b894, #00a085);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            margin-left: 5px;
        }
        
        @media (max-width: 768px) {
            .search-section {
                padding: 20px;
            }
            
            .profile-image-container {
                width: 80px;
                height: 80px;
                margin-top: -40px;
            }
            
            .notification-dropdown {
                min-width: 300px;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        
        <!-- Role Selector -->
        <?php if ($is_professional && $_SESSION["user_type"] != "admin" && $_SESSION["user_type"] != "super_admin") { ?>
        <div class="role-selector">
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">View As:</label>
                    </div>
                    <div class="col-md-5">
                        <select name="selected_role" class="form-select" onchange="this.form.submit()">
                            <option value="customer" <?php echo ($_SESSION["current_role"] == "customer") ? "selected" : ""; ?>>Client</option>
                            <option value="professional" <?php echo ($_SESSION["current_role"] == "professional") ? "selected" : ""; ?>>Professional</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <span class="badge bg-info p-2">You have both client and professional access</span>
                    </div>
                </div>
            </form>
        </div>
        <?php } ?>

        <!-- Professional Dashboard -->
        <?php if ($_SESSION["current_role"] == "professional") { ?>
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="section-title">Professional Dashboard</h2>
                        <p class="text-muted">Manage your services, bookings, and calendar.</p>
                    </div>
                    <!-- Notifications Bell -->
                    <div class="dropdown">
                        <button class="btn notification-bell dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if ($pending_bookings_count > 0): ?>
                                <span class="notification-badge"><?php echo $pending_bookings_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <li><h6 class="dropdown-header">Booking Notifications</h6></li>
                            <?php if (count($professional_bookings) > 0): ?>
                                <?php foreach ($professional_bookings as $booking): ?>
                                    <?php if ($booking['status'] == 'pending' && $booking['professional_response'] == 'pending'): ?>
                                    <li>
                                        <div class="notification-item unread">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">New Booking Request</h6>
                                                    <p class="mb-1"><strong><?php echo htmlspecialchars($booking['customer_full_name']); ?></strong> wants to book your service</p>
                                                    <div class="booking-details">
                                                        <p class="mb-1"><strong>Service:</strong> <?php echo htmlspecialchars($booking['service_title']); ?></p>
                                                        <p class="mb-1"><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($booking['booking_date'])); ?></p>
                                                        <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($booking['customer_phone']); ?></p>
                                                        <?php if (!empty($booking['notes'])): ?>
                                                            <p class="mb-1"><strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?></p>
                                                        <?php endif; ?>
                                                        <p class="mb-0"><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                    </div>
                                                    <small class="notification-time">Received <?php echo date('M j g:i A', strtotime($booking['created_at'])); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li><div class="notification-item text-center py-3">No new notifications</div></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-center" href="professional-bookings.php">View All Bookings</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="stats-card services">
                        <h3><?php echo count($professional_services); ?></h3>
                        <p class="mb-0">Services</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-card bookings">
                        <h3><?php echo count($professional_bookings); ?></h3>
                        <p class="mb-0">Total Bookings</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-card reviews">
                        <h3><?php echo count($professional_reviews); ?></h3>
                        <p class="mb-0">Reviews</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-card rating">
                        <h3>
                            <?php 
                            $avg_rating = 0;
                            if (!empty($professional_reviews)) {
                                $total_rating = 0;
                                foreach ($professional_reviews as $review) {
                                    $total_rating += $review['rating'];
                                }
                                $avg_rating = round($total_rating / count($professional_reviews), 1);
                            }
                            echo $avg_rating;
                            ?>
                        </h3>
                        <p class="mb-0">Avg Rating</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-card pending">
                        <h3><?php echo $pending_bookings_count; ?></h3>
                        <p class="mb-0">Pending</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stats-card" style="background: linear-gradient(135deg, #6c5ce7, #a29bfe);">
                        <h3><?php echo count($blocked_dates); ?></h3>
                        <p class="mb-0">Blocked Days</p>
                    </div>
                </div>
            </div>
            
            <!-- Calendar and Quick Actions -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div id="professionalCalendar"></div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5 class="card-title">Quick Actions</h5>
                            <div class="d-grid gap-2">
                                <a href="my-services.php" class="btn btn-primary">
                                    <i class="fas fa-briefcase me-2"></i>Manage Services
                                </a>
                                <a href="professional-bookings.php" class="btn btn-outline-primary">
                                    <i class="fas fa-calendar-check me-2"></i>View All Bookings
                                </a>
                                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#blockDateModal">
                                    <i class="fas fa-calendar-times me-2"></i>Block Dates
                                </button>
                                <a href="professional-reviews.php" class="btn btn-outline-warning">
                                    <i class="fas fa-star me-2"></i>View Reviews
                                </a>
                            </div>
                            
                            <hr>
                            
                            <h6>Recent Bookings</h6>
                            <?php if (!empty($professional_bookings)): ?>
                                <?php foreach (array_slice($professional_bookings, 0, 3) as $booking): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                        <div class="text-start">
                                            <small class="fw-bold"><?php echo htmlspecialchars($booking['customer_full_name']); ?></small>
                                            <br>
                                            <small class="text-muted"><?php echo date('M j', strtotime($booking['booking_date'])); ?></small>
                                        </div>
                                        <span class="booking-status status-<?php echo $booking['status']; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted small">No recent bookings</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Services and Reviews -->
            <div class="row">
                <div class="col-md-6 mb-4">
                    <div class="card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <i class="fas fa-briefcase fa-3x text-primary mb-3"></i>
                            <h5 class="card-title">My Services</h5>
                            <p class="card-text flex-grow-1">
                                <?php if (!empty($professional_services)): ?>
                                    <?php foreach (array_slice($professional_services, 0, 5) as $service): ?>
                                        <span class="service-badge"><?php echo htmlspecialchars($service['title']); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($professional_services) > 5): ?>
                                        <span class="service-badge">+<?php echo count($professional_services) - 5; ?> more</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="text-muted">No services added yet.</p>
                                <?php endif; ?>
                            </p>
                            <div class="mt-auto">
                                <a href="my-services.php" class="btn btn-primary">Manage Services</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 mb-4">
                    <div class="card text-center h-100">
                        <div class="card-body d-flex flex-column">
                            <i class="fas fa-star fa-3x text-warning mb-3"></i>
                            <h5 class="card-title">Recent Reviews</h5>
                            <div class="flex-grow-1">
                                <?php if (!empty($professional_reviews)): ?>
                                    <?php foreach (array_slice($professional_reviews, 0, 3) as $review): ?>
                                        <div class="text-start mb-2 p-2 border rounded">
                                            <div class="rating mb-1">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $review['rating'] ? '' : '-empty'; ?>"></i>
                                                <?php endfor; ?>
                                        </div>
                                            <small class="text-muted">by <?php echo htmlspecialchars($review['customer_full_name']); ?></small>
                                            <p class="mb-0 small text-truncate"><?php echo htmlspecialchars($review['comment']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($professional_reviews) > 3): ?>
                                        <p class="text-muted small mt-2">+<?php echo count($professional_reviews) - 3; ?> more reviews</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="text-muted">No reviews yet.</p>
                                <?php endif; ?>
                            </div>
                            <div class="mt-auto">
                                <a href="professional-reviews.php" class="btn btn-outline-primary">View All Reviews</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- Client Dashboard -->
        <?php if ($_SESSION["current_role"] == "customer") { ?>
        
        <!-- Search Section -->
        <div class="search-section">
            <h2>Find Skilled Professionals</h2>
            <form action="services.php" method="get" id="searchForm">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <input type="text" name="search" class="form-control form-control-lg" placeholder="What service do you need? (e.g., plumbing, electrician, cleaning)">
                    </div>
                    <div class="col-md-4 mb-3">
                        <button type="submit" class="btn btn-light btn-lg w-100"><i class="fas fa-search me-2"></i> Search</button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <select name="category" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <select name="sort" class="form-select">
                            <option value="rating">Highest Rated</option>
                            <option value="price_low">Price: Low to High</option>
                            <option value="price_high">Price: High to Low</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <!-- Location selection will be handled by the form -->
                        <input type="hidden" name="latitude" id="searchLatitude">
                        <input type="hidden" name="longitude" id="searchLongitude">
                        <div class="location-options">
                            <label class="form-label fw-bold text-white">Select Your Location:</label>
                            <div class="d-flex gap-2">
                                <button type="button" id="useCurrentLocation" class="location-btn">
                                    <i class="fas fa-location-arrow"></i> Current Location
                                </button>
                                <button type="button" id="pickOnMap" class="location-btn" disabled>
                                    <i class="fas fa-map-marker-alt"></i> Pick on Map
                                </button>
                            </div>
                            <small class="text-white mt-2" id="locationStatus">
                                <?php echo $user_municipality ? "Using: {$user_municipality}, {$user_barangay}" : "Location not set"; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Popular Professionals List Section -->
        <div class="popular-professionals-list-section">
            <div class="row">
                <div class="col-12">
                    <h3 class="section-title">Popular Professionals Near You</h3>
                    <p class="text-muted mb-4">Discover top-rated professionals in your area</p>
                    
                    <?php if (count($popular_professionals) > 0) { ?>
                        <div class="row">
                            <?php foreach ($popular_professionals as $pro) { ?>
                            <div class="col-md-3 mb-4">
                                <div class="professional-card">
                                    <div class="professional-header"></div>
                                            <button class="share-btn" onclick="shareProfessional(<?php echo $pro['id']; ?>)">
                                                <i class="fas fa-share-alt"></i>
                                            </button>
                                                                                <div class="p-4 text-center position-relative">
                                        <!-- Profile Picture -->
                                        <div class="profile-image-container">
                                            <?php if (!empty($pro['profile_picture'])) { ?>
                                                <img src="<?php echo htmlspecialchars($pro['profile_picture']); ?>" class="profile-image" alt="Profile Picture">
                                            <?php } else { ?>
                                                <div class="profile-placeholder">
                                                    <i class="fas fa-user-tie fa-2x text-white"></i>
                                                </div>
                                            <?php } ?>
                                        </div>
                                        
                                        <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($pro['full_name'] ?: $pro['username']); ?></h4>
                                        <p class="text-muted mb-2"><?php echo htmlspecialchars($pro['profession']); ?></p>
                                        
                                        <!-- Rating - Only show if there are reviews -->
                                        <div class="mb-2">
                                            <span class="rating">
                                                <i class="fas fa-star"></i> 
                                                <?php 
                                                if ($pro['review_count'] > 0) {
                                                    echo number_format($pro['avg_rating'], 1);
                                                    echo '<small class="text-muted ms-1">(' . $pro['review_count'] . ' reviews)</small>';
                                                } else {
                                                    echo '<small class="text-muted">No reviews yet</small>';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        
                                    <p class="price mb-2 fw-bold text-primary">
                                        <?php echo !empty($pro['pricing_type']) ? htmlspecialchars($pro['pricing_type']) : 'Price varies'; ?>
                                    </p>

                                        <!-- Services -->
                                        <div class="mb-3">
                                            <?php if (!empty($pro['services_list'])): ?>
                                                <?php foreach ($pro['services_list'] as $service): ?>
                                                    <span class="service-badge"><?php echo htmlspecialchars($service); ?></span>
                                                <?php endforeach; ?>
                                                <?php if ($pro['service_count'] > 3): ?>
                                                    <span class="service-badge">+<?php echo $pro['service_count'] - 3; ?> more</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <small class="text-muted">No services listed</small>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <p class="text-muted small mb-3">
                                            <i class="fas fa-map-marker-alt"></i> 
                                            <?php 
                                            if ($pro['municipality']) {
                                                echo htmlspecialchars($pro['municipality']) . ', ' . htmlspecialchars($pro['barangay'] ?: 'Unknown area');
                                            } else {
                                                echo 'Location not specified';
                                            }
                                            ?>
                                        </p>
                                        
                                        <div class="mt-3">
                                            <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-outline-primary btn-sm">View Profile</a>
                                            <?php if (isset($pro['first_service_id']) && $pro['first_service_id'] > 0): ?>
                                                <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-primary btn-sm ms-2">Book</a>
                                            <?php else: ?>
                                                <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-primary btn-sm ms-2">View Profile to Book</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-user-tie fa-3x text-muted mb-3"></i>
                            <h4>No popular professionals available yet</h4>
                            <p>We're working on getting skilled professionals in your area.</p>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- All Professionals Section -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="section-title">All Professionals</h3>
                    <a href="services.php" class="btn btn-outline-primary">View All</a>
                </div>
            </div>
            
            <?php if (count($all_professionals) > 0) { ?>
                <?php foreach (array_slice($all_professionals, 0, 8) as $pro) { ?>
                <div class="col-md-3 mb-4">
                    <div class="professional-card">
                        <div class="professional-header"></div>
                        <button class="share-btn" onclick="shareProfessional(<?php echo $pro['id']; ?>)">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        <div class="p-4 text-center position-relative">
                            <!-- Profile Picture -->
                            <div class="profile-image-container">
                                <?php if (!empty($pro['profile_picture'])) { ?>
                                    <img src="<?php echo htmlspecialchars($pro['profile_picture']); ?>" class="profile-image" alt="Profile Picture">
                                <?php } else { ?>
                                    <div class="profile-placeholder">
                                        <i class="fas fa-user-tie fa-2x text-white"></i>
                                    </div>
                                <?php } ?>
                            </div>
                            
                            <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($pro['full_name'] ?: $pro['username']); ?></h4>
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($pro['profession']); ?></p>
                            
                            <!-- Rating - Only show if there are reviews -->
                            <div class="mb-2">
                                <span class="rating">
                                    <i class="fas fa-star"></i> 
                                    <?php 
                                    if ($pro['review_count'] > 0) {
                                        echo number_format($pro['avg_rating'], 1);
                                        echo '<small class="text-muted ms-1">(' . $pro['review_count'] . ' reviews)</small>';
                                    } else {
                                        echo '<small class="text-muted">No reviews yet</small>';
                                    }
                                    ?>
                                </span>
                            </div>
                            
                        <p class="price mb-2 fw-bold text-primary">
                            <?php echo !empty($pro['pricing_type']) ? htmlspecialchars($pro['pricing_type']) : 'Price varies'; ?>
                        </p>

                            <!-- Services -->
                            <div class="mb-3">
                                <?php if (!empty($pro['services_list'])): ?>
                                    <?php foreach ($pro['services_list'] as $service): ?>
                                        <span class="service-badge"><?php echo htmlspecialchars($service); ?></span>
                                    <?php endforeach; ?>
                                    <?php if ($pro['service_count'] > 3): ?>
                                        <span class="service-badge">+<?php echo $pro['service_count'] - 3; ?> more</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted">No services listed</small>
                                <?php endif; ?>
                            </div>
                            
                            <p class="text-muted small mb-3">
                                <i class="fas fa-map-marker-alt"></i> 
                                <?php 
                                if ($pro['municipality']) {
                                    echo htmlspecialchars($pro['municipality']) . ', ' . htmlspecialchars($pro['barangay'] ?: 'Unknown area');
                                } else {
                                    echo 'Location not specified';
                                }
                                ?>
                            </p>
                            
                            <div class="mt-3">
                                <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-outline-primary btn-sm">View Profile</a>
                                <?php if (isset($pro['first_service_id']) && $pro['first_service_id'] > 0): ?>
                                    <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-primary btn-sm ms-2">Book</a>
                                <?php else: ?>
                                    <a href="professional-profile.php?id=<?php echo $pro['id']; ?>" class="btn btn-primary btn-sm ms-2">View Profile to Book</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
            <?php } else { ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-user-tie fa-3x text-muted mb-3"></i>
                    <h4>No professionals available yet</h4>
                    <p>We're working on getting skilled professionals in your area.</p>
                </div>
            <?php } ?>
        </div>

        <!-- Hiring History Section -->
        <div class="row">
            <div class="col-12">
                <h3 class="section-title">Your Hiring History</h3>
                
                <?php if (count($hiring_history) > 0) { ?>
                    <?php foreach ($hiring_history as $booking) { ?>
                    <div class="history-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5><?php echo htmlspecialchars($booking['service_title']); ?></h5>
                                <p class="mb-1">with <a href="professional-profile.php?id=<?php echo $booking['professional_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($booking['professional_full_name']); ?></a></p>
                                <p class="mb-1 text-muted">Booked on: <?php echo date('F j, Y', strtotime($booking['booking_date'])); ?></p>
                                <p class="mb-1">Total: ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                <p class="mb-0">Status: 
                                    <span class="badge 
                                        <?php 
                                        if ($booking['status'] == 'completed') echo 'bg-success';
                                        elseif ($booking['status'] == 'confirmed') echo 'bg-primary';
                                        elseif ($booking['status'] == 'pending') echo 'bg-warning';
                                        else echo 'bg-danger';
                                        ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </p>
                            </div>
                             <div>
                                    <?php if ($booking['rating']): ?>
                                        <div class="rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i <= $booking['rating'] ? '' : '-empty'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                    <?php elseif ($booking['status'] == 'completed'): ?>
                                        <a href="leave-feedback.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-primary">Leave Feedback</a>
                                    <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                <?php } else { ?>
                    <div class="text-center py-5">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <h4>No hiring history yet</h4>
                        <p>When you hire professionals through our platform, your bookings will appear here.</p>
                        <a href="services.php" class="btn btn-primary">Find Professionals</a>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

    </div>

    <!-- Block Date Modal -->
    <div class="modal fade block-date-modal" id="blockDateModal" tabindex="-1" aria-labelledby="blockDateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="blockDateModalLabel">Block Unavailable Dates</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" id="blockDateForm">
                        <div class="mb-3">
                            <label for="blockDate" class="form-label">Select Date to Block</label>
                            <input type="date" class="form-control" id="blockDate" name="block_date" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="blockReason" class="form-label">Reason (Optional)</label>
                            <input type="text" class="form-control" id="blockReason" name="reason" placeholder="e.g., Vacation, Personal day">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" name="block_date_submit">Block Date</button>
                        </div>
                    </form>
                    
                    <?php if (!empty($blocked_dates)): ?>
                    <hr>
                    <h6>Currently Blocked Dates</h6>
                    <div class="mt-3">
                        <?php foreach ($blocked_dates as $blocked): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                <div>
                                    <span class="fw-bold"><?php echo date('M j, Y', strtotime($blocked['blocked_date'])); ?></span>
                                    <?php if (!empty($blocked['reason'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($blocked['reason']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="unblock_date" value="<?php echo $blocked['blocked_date']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Unblock Date">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Details Modal -->
    <div class="modal fade booking-modal" id="bookingDetailsModal" tabindex="-1" aria-labelledby="bookingDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingDetailsModalLabel">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="bookingDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($_SESSION["current_role"] == "professional"): ?>
    <!-- FullCalendar JS - Only load for professionals -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <?php endif; ?>

<script>
// Share professional function - defined at the top to ensure availability
function shareProfessional(professionalId) {
    const baseUrl = window.location.origin + window.location.pathname.split('/').slice(0, -1).join('/');
    const url = `${baseUrl}/professional-profile.php?id=${professionalId}`;
    
    console.log('Sharing professional:', professionalId, 'URL:', url);
    
    if (navigator.share) {
        navigator.share({
            title: 'Artisan Link Professional',
            text: 'Check out this professional on Artisan Link',
            url: url
        })
        .then(() => console.log('Successful share'))
        .catch((error) => {
            console.log('Error sharing:', error);
            fallbackShare(url);
        });
    } else {
        fallbackShare(url);
    }
}

function fallbackShare(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            alert('Professional profile link copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy: ', err);
            manualCopy(url);
        });
    } else {
        manualCopy(url);
    }
}

function manualCopy(url) {
    const textArea = document.createElement("textarea");
    textArea.value = url;
    textArea.style.position = "fixed";
    textArea.style.left = "-999999px";
    textArea.style.top = "-999999px";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        alert('Professional profile link copied to clipboard!');
    } catch (err) {
        console.error('Failed to copy: ', err);
        alert('Copy this link: ' + url);
    }
    document.body.removeChild(textArea);
}

// Professional calendar data
const professionalBookings = <?php echo json_encode($professional_bookings ?? []); ?>;
const blockedDates = <?php echo json_encode($blocked_dates ?? []); ?>;

// User coordinates - Will be updated with current location
let userCoords = {
    lat: <?php echo $user_latitude ?: '15.7583'; ?>,
    lng: <?php echo $user_longitude ?: '121.5625'; ?>,
    municipality: '<?php echo $user_municipality ?: "Baler"; ?>',
    barangay: '<?php echo $user_barangay ?: ""; ?>'
};

// Initialize functionality based on current role
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Initialize professional calendar if on professional dashboard
    if (document.getElementById('professionalCalendar')) {
        initProfessionalCalendar();
    }

    // Get current location for client dashboard
    if (document.getElementById('useCurrentLocation')) {
        getCurrentLocation().then(() => {
            console.log('Current location obtained:', userCoords);
        }).catch((error) => {
            console.error('Geolocation failed:', error);
            // Fallback to account location if geolocation fails
            console.log('Using fallback location:', userCoords);
        });
    }
});

// Get current location using geolocation API
function getCurrentLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error('Geolocation is not supported by this browser.'));
            return;
        }

        console.log('Requesting current location...');
        
        navigator.geolocation.getCurrentPosition(
            (position) => {
                userCoords.lat = position.coords.latitude;
                userCoords.lng = position.coords.longitude;
                
                console.log('Location obtained:', userCoords.lat, userCoords.lng);
                
                // Update search form
                if (document.getElementById('searchLatitude')) {
                    document.getElementById('searchLatitude').value = userCoords.lat;
                    document.getElementById('searchLongitude').value = userCoords.lng;
                }
                
                // Reverse geocode to get address
                getUserAddress(userCoords.lat, userCoords.lng);
                
                resolve();
            },
            (error) => {
                let errorMessage = 'Unable to get your location. ';
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage += 'User denied the request for Geolocation.';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage += 'Location information is unavailable.';
                        break;
                    case error.TIMEOUT:
                        errorMessage += 'The request to get user location timed out.';
                        break;
                    case error.UNKNOWN_ERROR:
                        errorMessage += 'An unknown error occurred.';
                        break;
                }
                console.error('Geolocation error:', errorMessage);
                reject(new Error(errorMessage));
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 60000
            }
        );
    });
}

function initProfessionalCalendar() {
    try {
        const calendarEl = document.getElementById('professionalCalendar');
        if (!calendarEl) return;

        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: [
                // Add bookings as events
                ...professionalBookings.map(booking => ({
                    title: `${booking.customer_full_name} - ${booking.service_title}`,
                    start: booking.booking_date,
                    backgroundColor: getStatusColor(booking.status),
                    borderColor: getStatusColor(booking.status),
                    extendedProps: {
                        type: 'booking',
                        status: booking.status,
                        customer_phone: booking.customer_phone,
                        notes: booking.notes,
                        address: booking.address,
                        customer_full_name: booking.customer_full_name,
                        service_title: booking.service_title,
                        booking_date: booking.booking_date
                    }
                })),
                // Add blocked dates as events
                ...blockedDates.map(blocked => ({
                    title: `Blocked - ${blocked.reason || 'Unavailable'}`,
                    start: blocked.blocked_date,
                    allDay: true,
                    backgroundColor: '#6c757d',
                    borderColor: '#6c757d',
                    extendedProps: {
                        type: 'blocked'
                    }
                }))
            ],
            eventClick: function(info) {
                const event = info.event;
                if (event.extendedProps.type === 'booking') {
                    showBookingDetailsModal(event.extendedProps);
                }
            },
            dateClick: function(info) {
                // When a date is clicked, set it in the block date modal
                document.getElementById('blockDate').value = info.dateStr;
                const modal = new bootstrap.Modal(document.getElementById('blockDateModal'));
                modal.show();
            },
            businessHours: {
                daysOfWeek: [1, 2, 3, 4, 5, 6],
                startTime: '08:00',
                endTime: '18:00',
            }
        });

        calendar.render();
        console.log('Calendar initialized successfully');
    } catch (error) {
        console.error('Error initializing calendar:', error);
    }
}

function showBookingDetailsModal(booking) {
    const modalContent = document.getElementById('bookingDetailsContent');
    const modal = new bootstrap.Modal(document.getElementById('bookingDetailsModal'));
    
    modalContent.innerHTML = `
        <div class="booking-detail-item">
            <strong>Customer:</strong> ${booking.customer_full_name}
        </div>
        <div class="booking-detail-item">
            <strong>Service:</strong> ${booking.service_title}
        </div>
        <div class="booking-detail-item">
            <strong>Date & Time:</strong> ${new Date(booking.booking_date).toLocaleString()}
        </div>
        <div class="booking-detail-item">
            <strong>Status:</strong> <span class="badge bg-${getStatusBadgeColor(booking.status)}">${booking.status}</span>
        </div>
        <div class="booking-detail-item">
            <strong>Phone:</strong> ${booking.customer_phone || 'N/A'}
        </div>
        ${booking.notes ? `<div class="booking-detail-item"><strong>Notes:</strong> ${booking.notes}</div>` : ''}
        <div class="booking-detail-item">
            <strong>Address:</strong> ${booking.address || 'N/A'}
        </div>
    `;
    
    modal.show();
}

function getStatusBadgeColor(status) {
    const colors = {
        'pending': 'warning',
        'confirmed': 'primary',
        'completed': 'success',
        'cancelled': 'danger',
        'expired': 'secondary'
    };
    return colors[status] || 'secondary';
}

function getStatusColor(status) {
    const colors = {
        'pending': '#ffc107',
        'confirmed': '#17a2b8',
        'completed': '#28a745',
        'cancelled': '#dc3545',
        'expired': '#6c757d'
    };
    return colors[status] || '#6c757d';
}

// Set minimum date for blocking to today
if (document.getElementById('blockDate')) {
    document.getElementById('blockDate').min = new Date().toISOString().split('T')[0];
}

// Location functionality
if (document.getElementById('useCurrentLocation')) {
    document.getElementById('useCurrentLocation').addEventListener('click', function() {
        getCurrentLocation().then(() => {
            updateUserLocation();
        }).catch((error) => {
            alert('Unable to get your location. Please enable location services and try again.');
            console.error('Location error:', error);
        });
    });
}

// Simple reverse geocoding
function getUserAddress(lat, lng) {
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
        .then(response => response.json())
        .then(data => {
            if (data.address) {
                const municipality = data.address.city || data.address.town || data.address.village;
                const barangay = data.address.suburb || data.address.neighbourhood;
                userCoords.municipality = municipality || 'Unknown area';
                userCoords.barangay = barangay || '';
                if (document.getElementById('locationStatus')) {
                    document.getElementById('locationStatus').textContent = 
                        `Using: ${userCoords.municipality}, ${userCoords.barangay}`;
                }
            }
        })
        .catch(() => {
            if (document.getElementById('locationStatus')) {
                document.getElementById('locationStatus').textContent = 
                    `Using: Custom location (${userCoords.lat.toFixed(4)}, ${userCoords.lng.toFixed(4)})`;
            }
        });
}

function updateUserLocation() {
    // Update the location status display
    if (document.getElementById('locationStatus')) {
        document.getElementById('locationStatus').textContent = 
            `Using: ${userCoords.municipality}, ${userCoords.barangay}`;
    }
}
</script>
</body>
</html>