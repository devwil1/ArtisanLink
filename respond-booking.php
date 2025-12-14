<?php
// Initialize the session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and is a professional
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] !== 'professional'){
    header("location: login.php");
    exit;
}

// Check if booking ID and action are provided
if(!isset($_GET['id']) || !isset($_GET['action'])){
    header("location: profile.php");
    exit;
}

$booking_id = trim($_GET['id']);
$action = trim($_GET['action']);

// Validate action
if(!in_array($action, ['accept', 'reject'])){
    header("location: profile.php");
    exit;
}

// Include config file
require_once "config.php";

// Get booking details to verify ownership
$booking_sql = "SELECT b.*, u.username as customer_name, s.title as service_title 
                FROM bookings b
                JOIN users u ON b.customer_id = u.id
                JOIN services s ON b.service_id = s.id
                WHERE b.id = ? AND b.professional_id = ?";
                
if ($stmt = mysqli_prepare($link, $booking_sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if(mysqli_num_rows($result) != 1){
        // Booking doesn't exist or doesn't belong to this professional
        header("location: profile.php");
        exit;
    }
    
    $booking = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Process the response
$response = ($action == 'accept') ? 'accepted' : 'rejected';
$status = ($action == 'accept') ? 'confirmed' : 'cancelled';

$update_sql = "UPDATE bookings 
               SET professional_response = ?, status = ?, response_message = ?
               WHERE id = ?";
               
if ($stmt = mysqli_prepare($link, $update_sql)) {
    $response_message = "Professional " . $response . " the booking request";
    mysqli_stmt_bind_param($stmt, "sssi", $response, $status, $response_message, $booking_id);
    
    if(mysqli_stmt_execute($stmt)){
        // Create notification for customer
        $notification_msg = "Your booking for '" . $booking['service_title'] . "' has been " . $response . " by the professional";
        $notif_sql = "INSERT INTO notifications (user_id, message, type, related_id) VALUES (?, ?, 'response', ?)";
        
        if ($notif_stmt = mysqli_prepare($link, $notif_sql)) {
            mysqli_stmt_bind_param($notif_stmt, "isi", $booking['customer_id'], $notification_msg, $booking_id);
            mysqli_stmt_execute($notif_stmt);
            mysqli_stmt_close($notif_stmt);
        }
        
        $_SESSION['booking_response'] = "Booking " . $response . " successfully!";
    } else {
        $_SESSION['booking_response'] = "Error updating booking. Please try again.";
    }
    mysqli_stmt_close($stmt);
}

// Close connection
mysqli_close($link);

header("location: profile.php");
exit;