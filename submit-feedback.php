<?php
// Initialize the session
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

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate inputs
    $booking_id = trim($_POST["booking_id"]);
    $professional_id = trim($_POST["professional_id"]);
    $rating = trim($_POST["rating"]);
    $comment = trim($_POST["comment"]);
    
    // Insert feedback into database
    $sql = "INSERT INTO feedback (booking_id, customer_id, professional_id, rating, comment) VALUES (?, ?, ?, ?, ?)";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "iiiss", $booking_id, $_SESSION["id"], $professional_id, $rating, $comment);
        
        if (mysqli_stmt_execute($stmt)) {
            // Update the service rating (average)
            $update_sql = "UPDATE services s
                           JOIN bookings b ON s.professional_id = b.professional_id
                           SET s.rating = (SELECT AVG(rating) FROM feedback WHERE professional_id = ?),
                               s.total_ratings = (SELECT COUNT(*) FROM feedback WHERE professional_id = ?)
                           WHERE b.professional_id = ?";
            
            if ($update_stmt = mysqli_prepare($link, $update_sql)) {
                mysqli_stmt_bind_param($update_stmt, "iii", $professional_id, $professional_id, $professional_id);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
            }
            
            // Redirect back to dashboard with success message
            $_SESSION["feedback_success"] = "Thank you for your feedback!";
            header("location: welcome.php");
            exit;
        } else {
            echo "Oops! Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
    
    // Close connection
    mysqli_close($link);
} else {
    // If not a POST request, redirect to dashboard
    header("location: welcome.php");
    exit;
}
?>