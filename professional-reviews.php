<?php
// Initialize the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
// Check if the user is logged in and is a professional
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] != "professional"){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Get professional's reviews
$reviews = [];
$sql = "SELECT f.*, u.username as customer_name, u.full_name as customer_full_name,
               b.booking_date, s.title as service_title
        FROM feedback f
        JOIN users u ON f.customer_id = u.id
        JOIN bookings b ON f.booking_id = b.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE f.professional_id = ?
        ORDER BY f.created_at DESC";
        
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $reviews[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Calculate average rating
$average_rating = 0;
$total_reviews = count($reviews);
if ($total_reviews > 0) {
    $total_rating = 0;
    foreach ($reviews as $review) {
        $total_rating += $review['rating'];
    }
    $average_rating = round($total_rating / $total_reviews, 1);
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Reviews - Artisan Link</title>
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
        
        .rating {
            color: var(--warning);
        }
        
        .review-card {
            border-left: 4px solid var(--warning);
            transition: all 0.3s;
        }
        
        .review-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 20px;
        }
        
        .stats-card.rating { background: linear-gradient(135deg, #e17055, #d63031); }
        .stats-card.reviews { background: linear-gradient(135deg, #0984e3, #0767b1); }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12 mb-4">
                <h2 class="section-title">My Reviews</h2>
                <p class="text-muted">See what clients are saying about your services.</p>
            </div>
        </div>

        <!-- Review Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stats-card rating">
                    <h3><?php echo $average_rating; ?>/5</h3>
                    <p class="mb-0">Average Rating</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stats-card reviews">
                    <h3><?php echo $total_reviews; ?></h3>
                    <p class="mb-0">Total Reviews</p>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $review): ?>
                    <div class="card review-card mb-4">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="rating me-3">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star<?php echo $i <= $review['rating'] ? '' : '-empty'; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="ms-2 fw-bold"><?php echo $review['rating']; ?>/5</span>
                                        </div>
                                        <span class="text-muted"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></span>
                                    </div>
                                    
                                    <h5 class="card-title">Service: <?php echo htmlspecialchars($review['service_title']); ?></h5>
                                    <p class="card-text"><?php echo htmlspecialchars($review['comment']); ?></p>
                                    
                                    <p class="text-muted mb-0">
                                        <i class="fas fa-user"></i> 
                                        <?php echo htmlspecialchars($review['customer_full_name'] ?: $review['customer_name']); ?>
                                        • 
                                        <i class="fas fa-calendar"></i> 
                                        Booked on <?php echo date('F j, Y', strtotime($review['booking_date'])); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="card text-center py-5">
                        <i class="fas fa-star fa-3x text-muted mb-3"></i>
                        <h4>No reviews yet</h4>
                        <p>When clients leave feedback for your services, they will appear here.</p>
                        <a href="professional-bookings.php" class="btn btn-primary">View Your Bookings</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>