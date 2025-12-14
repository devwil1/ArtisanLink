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

// Check if professional ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])){
    header("location: services.php");
    exit;
}

$professional_id = trim($_GET['id']);

// Get professional details
$professional = [];
$sql = "SELECT u.*, pr.*, AVG(s.rating) as avg_rating, 
               COUNT(s.id) as service_count, COUNT(DISTINCT f.id) as review_count
        FROM users u
        JOIN professional_requests pr ON u.id = pr.user_id
        LEFT JOIN services s ON u.id = s.professional_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        WHERE u.id = ? AND pr.status = 'approved'
        GROUP BY u.id";
        
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($result) == 1){
            $professional = mysqli_fetch_assoc($result);
        } else {
            // Professional doesn't exist or not approved
            header("location: services.php");
            exit;
        }
    } else {
        echo "Oops! Something went wrong. Please try again later.";
    }
    mysqli_stmt_close($stmt);
}

// Get portfolio images
$portfolio_images = [];
$portfolio_sql = "SELECT * FROM portfolio_images WHERE professional_id = ?";
if ($stmt = mysqli_prepare($link, $portfolio_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $portfolio_images[] = $row;
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
    <title><?php echo htmlspecialchars($professional['full_name']); ?> - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8">
                <!-- Professional Profile Card -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-4">
                            <?php if (!empty($professional['profile_picture'])) : ?>
                                <img src="<?php echo htmlspecialchars($professional['profile_picture']); ?>" class="rounded-circle me-3" width="80" height="80" alt="Profile Picture">
                            <?php else : ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 80px; height: 80px;">
                                    <i class="fas fa-user-tie fa-2x"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h3><?php echo htmlspecialchars($professional['full_name']); ?></h3>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($professional['profession']); ?></p>
                                <span class="location-badge">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo htmlspecialchars($professional['municipality']); ?>, 
                                    <?php echo htmlspecialchars($professional['barangay']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>About</h5>
                                <p><?php echo nl2br(htmlspecialchars($professional['experience'])); ?></p>
                                
                                <h5>Skills</h5>
                                <div class="d-flex flex-wrap">
                                    <?php 
                                    $skills = explode(',', $professional['skills']);
                                    foreach ($skills as $skill) {
                                        echo '<span class="badge bg-primary me-2 mb-2">' . trim(htmlspecialchars($skill)) . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5>Pricing</h5>
                                <p><strong>Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $professional['pricing_type'])); ?></p>
                                <p><strong>Range:</strong> <?php echo htmlspecialchars($professional['price_range']); ?></p>
                                
                                <h5>Service Area</h5>
                                <p><?php echo htmlspecialchars($professional['municipality']); ?>, <?php echo htmlspecialchars($professional['barangay']); ?></p>
                            </div>
                        </div>

                        <!-- Portfolio Images -->
                        <?php if (!empty($portfolio_images)) : ?>
                        <h5>Portfolio</h5>
                        <div class="row">
                            <?php foreach ($portfolio_images as $image) : ?>
                            <div class="col-md-4 mb-3">
                                <img src="<?php echo htmlspecialchars($image['image_path']); ?>" class="img-fluid rounded" alt="Portfolio Image">
                                <?php if (!empty($image['caption'])) : ?>
                                <p class="text-muted small mt-1"><?php echo htmlspecialchars($image['caption']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Contact & Report Card -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Contact Information</h5>
                        <p><i class="fas fa-phone me-2"></i> <?php echo htmlspecialchars($professional['phone']); ?></p>
                        <p><i class="fas fa-envelope me-2"></i> <?php echo htmlspecialchars($professional['email']); ?></p>
                        
                        <hr>
                        
                        <h5 class="card-title">Location</h5>
                        <p>
                            <i class="fas fa-map-marker-alt me-2"></i> 
                            <?php echo htmlspecialchars($professional['barangay']); ?>, 
                            <?php echo htmlspecialchars($professional['municipality']); ?><br>
                            <small class="text-muted">Central Aurora, Philippines</small>
                        </p>
                        
                        <hr>
                        
                        <!-- Report Button -->
                        <div class="text-center">
                            <a href="report-profile.php?id=<?php echo $professional['id']; ?>" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-flag me-1"></i>Report Profile
                            </a>
                            <small class="d-block text-muted mt-1">Report suspicious activity or violations</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>