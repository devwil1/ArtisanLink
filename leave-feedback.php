<?php
// Initialize the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
// Check if the user is logged in as a customer
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] != "customer"){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Initialize variables
$booking_id = $service_title = $professional_name = $booking_date = "";
$rating = $comment = "";
$error_message = $success_message = "";

// Check if booking_id is provided
if (!isset($_GET['booking_id']) || empty(trim($_GET['booking_id']))) {
    header("location: welcome.php");
    exit;
}

$booking_id = trim($_GET['booking_id']);

// Get booking details
$booking_sql = "SELECT b.*, u.username as professional_name, u.full_name as professional_full_name, 
                       u.id as professional_id, s.title as service_title, s.id as service_id
                FROM bookings b
                JOIN users u ON b.professional_id = u.id
                JOIN services s ON b.service_id = s.id
                WHERE b.id = ? AND b.customer_id = ? AND b.status = 'completed'";
                
if ($stmt = mysqli_prepare($link, $booking_sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $booking_id, $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $service_title = $row['service_title'];
        $professional_name = $row['professional_full_name'] ?: $row['professional_name'];
        $booking_date = $row['booking_date'];
        $professional_id = $row['professional_id'];
        $service_id = $row['service_id'];
    } else {
        $error_message = "Booking not found or you don't have permission to leave feedback for this booking.";
    }
    mysqli_stmt_close($stmt);
} else {
    $error_message = "Database error. Please try again.";
}

// Check if feedback already exists for this booking
$existing_feedback_sql = "SELECT id FROM feedback WHERE booking_id = ?";
if ($stmt = mysqli_prepare($link, $existing_feedback_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $booking_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    if (mysqli_stmt_num_rows($stmt) > 0) {
        $error_message = "You have already left feedback for this booking.";
    }
    mysqli_stmt_close($stmt);
}

// Process form data when submitted
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error_message)) {
    // Validate rating
    if (empty(trim($_POST["rating"]))) {
        $error_message = "Please select a rating.";
    } else {
        $rating = trim($_POST["rating"]);
        if ($rating < 1 || $rating > 5) {
            $error_message = "Rating must be between 1 and 5.";
        }
    }
    
    // Validate comment
    if (empty(trim($_POST["comment"]))) {
        $error_message = "Please enter a comment.";
    } else {
        $comment = trim($_POST["comment"]);
        if (strlen($comment) < 10) {
            $error_message = "Comment must be at least 10 characters long.";
        }
    }
    
    // Insert feedback if no errors
    if (empty($error_message)) {
        $insert_sql = "INSERT INTO feedback (booking_id, customer_id, professional_id, rating, comment, created_at) 
                       VALUES (?, ?, ?, ?, ?, NOW())";
        
        if ($stmt = mysqli_prepare($link, $insert_sql)) {
            mysqli_stmt_bind_param($stmt, "iiiis", $booking_id, $_SESSION["id"], $professional_id, $rating, $comment);
            
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Thank you for your feedback!";
                
                // Update service rating
                updateServiceRating($link, $service_id);
                
                // Redirect after 2 seconds
                header("refresh:2;url=welcome.php");
            } else {
                $error_message = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Function to update service rating
function updateServiceRating($link, $service_id) {
    // Calculate average rating for the service
    $rating_sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings 
                   FROM feedback f 
                   JOIN bookings b ON f.booking_id = b.id 
                   WHERE b.service_id = ?";
    
    if ($stmt = mysqli_prepare($link, $rating_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $service_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $avg_rating, $total_ratings);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        
        // Update service table
        $update_sql = "UPDATE services SET rating = ?, total_ratings = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $update_sql)) {
            mysqli_stmt_bind_param($stmt, "dii", $avg_rating, $total_ratings, $service_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Leave Feedback - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            overflow: hidden;
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
        
        .rating-stars {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .rating-stars .star {
            margin: 0 2px;
        }
        
        .rating-stars .star:hover,
        .rating-stars .star.active {
            color: var(--warning);
        }
        
        .booking-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(108, 92, 231, 0.25);
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-star me-2"></i>Leave Feedback</h4>
                    </div>
                    <div class="card-body p-4">
                        
                        <!-- Success Message -->
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $success_message; ?>
                                <p class="mb-0 mt-2">Redirecting you back to dashboard...</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Error Message -->
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <div class="text-center mt-3">
                                <a href="welcome.php" class="btn btn-primary">Back to Dashboard</a>
                            </div>
                        <?php else: ?>
                        
                        <!-- Booking Information -->
                        <div class="booking-info">
                            <h5>Booking Details</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Service:</strong> <?php echo htmlspecialchars($service_title); ?></p>
                                    <p class="mb-2"><strong>Professional:</strong> <?php echo htmlspecialchars($professional_name); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Booking Date:</strong> <?php echo date('F j, Y', strtotime($booking_date)); ?></p>
                                    <p class="mb-0"><strong>Status:</strong> <span class="badge bg-success">Completed</span></p>
                                </div>
                            </div>
                        </div>

                        <!-- Feedback Form -->
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . '?booking_id=' . $booking_id; ?>" method="post">
                            <!-- Rating -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Rating <span class="text-danger">*</span></label>
                                <div class="rating-stars mb-2" id="ratingStars">
                                    <span class="star" data-value="1"><i class="fas fa-star"></i></span>
                                    <span class="star" data-value="2"><i class="fas fa-star"></i></span>
                                    <span class="star" data-value="3"><i class="fas fa-star"></i></span>
                                    <span class="star" data-value="4"><i class="fas fa-star"></i></span>
                                    <span class="star" data-value="5"><i class="fas fa-star"></i></span>
                                </div>
                                <input type="hidden" name="rating" id="ratingInput" required>
                                <small class="text-muted">Click on the stars to rate your experience (1 = Poor, 5 = Excellent)</small>
                            </div>

                            <!-- Comment -->
                            <div class="mb-4">
                                <label for="comment" class="form-label fw-bold">Comment <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="comment" name="comment" rows="5" 
                                          placeholder="Please share your experience with this professional. What did you like? What could be improved?"
                                          required minlength="10"><?php echo htmlspecialchars($comment); ?></textarea>
                                <small class="text-muted">Minimum 10 characters. Your feedback helps improve our community.</small>
                            </div>

                            <!-- Buttons -->
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="welcome.php" class="btn btn-outline-secondary me-md-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Submit Feedback</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Star rating functionality
        document.addEventListener('DOMContentLoaded', function() {
            const stars = document.querySelectorAll('.rating-stars .star');
            const ratingInput = document.getElementById('ratingInput');
            
            stars.forEach(star => {
                star.addEventListener('click', function() {
                    const value = parseInt(this.getAttribute('data-value'));
                    ratingInput.value = value;
                    
                    // Update star display
                    stars.forEach(s => {
                        if (parseInt(s.getAttribute('data-value')) <= value) {
                            s.classList.add('active');
                        } else {
                            s.classList.remove('active');
                        }
                    });
                });
                
                // Hover effect
                star.addEventListener('mouseover', function() {
                    const value = parseInt(this.getAttribute('data-value'));
                    stars.forEach(s => {
                        if (parseInt(s.getAttribute('data-value')) <= value) {
                            s.style.color = '#fdcb6e';
                        } else {
                            s.style.color = '#ddd';
                        }
                    });
                });
                
                star.addEventListener('mouseout', function() {
                    const currentRating = ratingInput.value ? parseInt(ratingInput.value) : 0;
                    stars.forEach(s => {
                        if (parseInt(s.getAttribute('data-value')) <= currentRating) {
                            s.style.color = '#fdcb6e';
                        } else {
                            s.style.color = '#ddd';
                        }
                    });
                });
            });
            
            // Form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const rating = ratingInput.value;
                const comment = document.getElementById('comment').value.trim();
                
                if (!rating) {
                    e.preventDefault();
                    alert('Please select a rating by clicking on the stars.');
                    return false;
                }
                
                if (comment.length < 10) {
                    e.preventDefault();
                    alert('Please write a comment of at least 10 characters.');
                    return false;
                }
            });
        });
    </script>
</body>
</html>