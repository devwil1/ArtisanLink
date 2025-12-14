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

// Get professional details with enhanced data
$professional = [];
$sql = "SELECT u.*, pr.*, 
               COALESCE(AVG(f.rating), 0) as avg_rating, 
               COUNT(DISTINCT s.id) as service_count, 
               COUNT(DISTINCT f.id) as review_count,
               COUNT(DISTINCT b.id) as completed_jobs,
               GROUP_CONCAT(DISTINCT s.title SEPARATOR '|') as service_titles
        FROM users u
        JOIN professional_requests pr ON u.id = pr.user_id
        LEFT JOIN services s ON u.id = s.professional_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        LEFT JOIN bookings b ON u.id = b.professional_id AND b.status = 'completed'
        WHERE u.id = ? AND pr.status = 'approved'
        GROUP BY u.id, pr.id";
        
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($result) == 1){
            $professional = mysqli_fetch_assoc($result);
            
            // Parse service titles
            if (!empty($professional['service_titles'])) {
                $professional['services_list'] = array_slice(explode('|', $professional['service_titles']), 0, 5);
            } else {
                $professional['services_list'] = [];
            }
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

// Get services offered by this professional
$services = [];
$services_sql = "SELECT * FROM services WHERE professional_id = ? ORDER BY created_at DESC";
               
if($stmt = mysqli_prepare($link, $services_sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $services[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get first service ID for booking (if available)
$first_service_id = 0;
$has_services = (count($services) > 0);
if ($has_services) {
    $first_service_id = $services[0]['id'];
}

// Get reviews for this professional with enhanced data
$reviews = [];
$review_sql = "SELECT f.*, u.username as customer_name, u.full_name as customer_full_name,
                      s.title as service_title, b.booking_date
               FROM feedback f
               JOIN users u ON f.customer_id = u.id
               JOIN bookings b ON f.booking_id = b.id
               LEFT JOIN services s ON b.service_id = s.id
               WHERE f.professional_id = ?
               ORDER BY f.created_at DESC
               LIMIT 10";
               
if($stmt = mysqli_prepare($link, $review_sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $reviews[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get professional's contact information
$contacts = [];
$contact_sql = "SELECT * FROM professional_contacts WHERE professional_id = ? ORDER BY is_primary DESC";
if($stmt = mysqli_prepare($link, $contact_sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $contacts[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Check if professional has skills (can be hired)
$has_skills = false;
$skills_list = [];
if (!empty($professional['skills'])) {
    if ($professional['skills'][0] == '[') {
        $skills_data = json_decode($professional['skills'], true);
        if (is_array($skills_data)) {
            $skills_list = array_column($skills_data, 'value');
        } else {
            $skills_list = explode(',', $professional['skills']);
        }
    } else {
        $skills_list = explode(',', $professional['skills']);
    }
    $has_skills = (count(array_filter($skills_list)) > 0);
}

// Get portfolio images
$portfolio_images = [];
$portfolio_sql = "SELECT * FROM portfolio_images WHERE professional_id = ? ORDER BY created_at DESC LIMIT 6";
if($stmt = mysqli_prepare($link, $portfolio_sql)){
    mysqli_stmt_bind_param($stmt, "i", $professional_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $portfolio_images[] = $row;
        }
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
    <title><?php echo htmlspecialchars($professional['full_name']); ?> - Professional Profile - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 50px 0;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.1);
        }
        
        .profile-image {
            width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            position: relative;
            z-index: 2;
        }
        
        .profile-placeholder {
            width: 180px;
            height: 180px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            border: 5px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            position: relative;
            z-index: 2;
        }
        
        .rating {
            color: var(--warning);
        }
        
        .service-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            background: white;
        }
        
        .service-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.15);
        }
        
        .review-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            background: white;
        }
        
        .stats-card {
            text-align: center;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-card.services { background: linear-gradient(135deg, var(--success), #00a085); }
        .stats-card.rating { background: linear-gradient(135deg, var(--warning), #f9a825); }
        .stats-card.jobs { background: linear-gradient(135deg, var(--info), #0767b1); }
        .stats-card.reviews { background: linear-gradient(135deg, #e17055, var(--danger)); }
        
        #professionalMap {
            height: 300px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .no-reviews {
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 40px;
        }
        
        .hire-section {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(108, 92, 231, 0.3);
        }
        
        .skill-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            margin: 5px;
            display: inline-block;
            font-size: 0.9em;
            font-weight: 500;
        }
        
        .service-badge {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary);
            padding: 6px 12px;
            border-radius: 15px;
            margin: 3px;
            display: inline-block;
            font-size: 0.8em;
            font-weight: 500;
        }
        
        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .portfolio-item {
            border-radius: 10px;
            overflow: hidden;
            height: 150px;
            position: relative;
        }
        
        .portfolio-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .portfolio-item:hover img {
            transform: scale(1.1);
        }
        
        .contact-method {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .contact-method:hover {
            background: var(--primary);
            color: white;
            transform: translateX(5px);
        }
        
        .contact-method:hover .contact-icon {
            background: white;
            color: var(--primary);
        }
        
        .contact-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            transition: all 0.3s ease;
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
        
        .verified-badge {
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .pricing-badge {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .profile-image, .profile-placeholder {
                width: 120px;
                height: 120px;
            }
            
            .hire-section {
                padding: 25px;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-2 text-center">
                    <?php if (!empty($professional['profile_picture'])) { ?>
                        <img src="<?php echo htmlspecialchars($professional['profile_picture']); ?>" class="profile-image" alt="Profile Picture">
                    <?php } else { ?>
                        <div class="profile-placeholder">
                            <i class="fas fa-user-tie fa-4x text-white"></i>
                        </div>
                    <?php } ?>
                </div>
                <div class="col-md-7">
                    <div class="position-relative">
                        <h1 class="display-5 fw-bold mb-2"><?php echo htmlspecialchars($professional['full_name']); ?></h1>
                        <div class="d-flex align-items-center mb-3">
                            <span class="fs-5 me-3"><?php echo htmlspecialchars($professional['profession']); ?></span>
                            <span class="verified-badge">
                                <i class="fas fa-check-circle me-1"></i>Verified
                            </span>
                        </div>
                        <div class="d-flex align-items-center flex-wrap">
                            <span class="rating me-3 mb-2">
                                <i class="fas fa-star"></i> 
                                <?php 
                                if ($professional['review_count'] > 0) {
                                    echo number_format($professional['avg_rating'], 1);
                                    echo '<small class="ms-1">(' . $professional['review_count'] . ' reviews)</small>';
                                } else {
                                    echo '<span>No reviews yet</span>';
                                }
                                ?>
                            </span>
                            <span class="mx-2 mb-2">•</span>
                            <span class="mb-2"><i class="fas fa-map-marker-alt me-1"></i> 
                                <?php 
                                if (!empty($professional['municipality'])) {
                                    echo htmlspecialchars($professional['municipality'] . ', ' . $professional['barangay']);
                                } else {
                                    echo 'Central Aurora';
                                }
                                ?>
                            </span>
                            <span class="mx-2 mb-2">•</span>
                            <span class="pricing-badge mb-2">
                                <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($professional['pricing_type'] ?? 'Price varies'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <?php if ($has_skills && $has_services) { ?>
                        <a href="book-service.php?service_id=<?php echo $first_service_id; ?>" class="btn btn-light btn-lg px-4 py-3 fw-bold">
                            <i class="fas fa-calendar-check me-2"></i>Book Now
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-5">
        <!-- Professional Location Map -->
        <?php if (!empty($professional['latitude']) && !empty($professional['longitude'])): ?>
        <div class="row mb-5">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h3 class="section-title">
                            <i class="fas fa-map-marker-alt text-danger me-2"></i>Service Location
                        </h3>
                        <div id="professionalMap"></div>
                        <div class="mt-3">
                            <p class="mb-1">
                                <i class="fas fa-info-circle me-2 text-primary"></i>
                                <strong>Service Area:</strong> 
                                <?php 
                                if (!empty($professional['municipality'])) {
                                    echo htmlspecialchars($professional['municipality'] . ', ' . $professional['barangay']);
                                } else {
                                    echo 'Central Aurora Region';
                                }
                                ?>
                            </p>
                            <?php if (!empty($professional['address'])): ?>
                                <p class="mb-0 text-muted">
                                    <i class="fas fa-home me-2"></i>
                                    <?php echo htmlspecialchars($professional['address']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hire Section -->
        <?php if ($has_skills && $has_services): ?>
        <div class="hire-section">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-3">Ready to Work With <?php echo htmlspecialchars(explode(' ', $professional['full_name'])[0]); ?>?</h2>
                    <p class="mb-0 fs-5">Professional <?php echo htmlspecialchars($professional['profession']); ?> with expertise in <?php echo htmlspecialchars(implode(', ', array_slice($skills_list, 0, 3))); ?>. Book now to get started!</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="book-service.php?service_id=<?php echo $first_service_id; ?>" class="btn btn-light btn-lg px-5 py-3 fw-bold">
                        <i class="fas fa-rocket me-2"></i>Get Started
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- About Section -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="section-title">About Me</h3>
                        <div class="mb-4">
                            <p class="fs-6 text-dark"><?php echo nl2br(htmlspecialchars($professional['experience'] ?: 'Experienced professional dedicated to providing high-quality services with attention to detail and customer satisfaction.')); ?></p>
                        </div>
                        
                        <h5 class="fw-bold mb-3">Skills & Expertise</h5>
                        <div class="mb-4">
                            <?php 
                            if ($has_skills) {
                                foreach ($skills_list as $skill) {
                                    if (!empty(trim($skill))) {
                                        echo '<span class="skill-badge">' . htmlspecialchars(trim($skill)) . '</span>';
                                    }
                                }
                            } else {
                                echo '<p class="text-muted">No specific skills listed yet.</p>';
                            }
                            ?>
                        </div>

                        <!-- Portfolio Section -->
                        <?php if (count($portfolio_images) > 0): ?>
                        <div class="mt-4">
                            <h5 class="fw-bold mb-3">Portfolio Gallery</h5>
                            <div class="portfolio-grid">
                                <?php foreach ($portfolio_images as $image): ?>
                                <div class="portfolio-item">
                                    <img src="<?php echo htmlspecialchars($image['image_path']); ?>" alt="<?php echo htmlspecialchars($image['caption'] ?? 'Portfolio Image'); ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Services Section -->
                <?php if ($has_services): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="section-title">Available Services</h3>
                        
                        <?php foreach ($services as $service) { ?>
                        <div class="service-card">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h4 class="text-primary mb-2"><?php echo htmlspecialchars($service['title']); ?></h4>
                                    <p class="text-muted mb-3"><?php echo htmlspecialchars($service['category']); ?></p>
                                    <p class="mb-3"><?php echo nl2br(htmlspecialchars($service['description'])); ?></p>
                                    
                                    <div class="d-flex align-items-center flex-wrap">
                                        <span class="service-badge">
                                            <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($service['pricing_type'] ?? 'per job'); ?>
                                        </span>
                                        <?php if (isset($service['rating']) && $service['rating'] > 0): ?>
                                        <span class="rating ms-3">
                                            <i class="fas fa-star"></i> 
                                            <?php echo number_format($service['rating'], 1); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <div class="mb-3">
                                        <span class="h3 text-dark">₱<?php echo number_format($service['price'], 2); ?></span>
                                    </div>
                                    <a href="book-service.php?service_id=<?php echo $service['id']; ?>" class="btn btn-primary btn-lg px-4">
                                        <i class="fas fa-calendar-plus me-2"></i>Book Service
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Reviews Section -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="section-title">Customer Reviews</h3>
                        
                        <?php if (count($reviews) > 0) { ?>
                            <?php foreach ($reviews as $review) { ?>
                            <div class="review-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($review['customer_full_name'] ?: $review['customer_name']); ?></h5>
                                        <?php if (!empty($review['service_title'])): ?>
                                        <p class="text-muted small mb-0">Service: <?php echo htmlspecialchars($review['service_title']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <span class="rating">
                                        <?php 
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $review['rating']) {
                                                echo '<i class="fas fa-star"></i>';
                                            } else {
                                                echo '<i class="far fa-star"></i>';
                                            }
                                        }
                                        ?>
                                    </span>
                                </div>
                                <p class="text-muted small mb-3">
                                    <i class="fas fa-clock me-1"></i><?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                </p>
                                <?php if (!empty($review['comment'])) { ?>
                                <p class="mb-0 fs-6">"<?php echo htmlspecialchars($review['comment']); ?>"</p>
                                <?php } ?>
                            </div>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="no-reviews">
                                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                                <h5>No Reviews Yet</h5>
                                <p class="text-muted">Be the first to review this professional!</p>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Contact Information -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="section-title mb-4">Contact Information</h5>
                        
                        <?php if (count($contacts) > 0): ?>
                            <?php foreach ($contacts as $contact): ?>
                            <div class="contact-method">
                                <div class="contact-icon">
                                    <?php 
                                    $icons = [
                                        'phone' => 'fa-phone',
                                        'email' => 'fa-envelope',
                                        'whatsapp' => 'fa-whatsapp',
                                        'facebook' => 'fa-facebook',
                                        'viber' => 'fa-comment',
                                        'telegram' => 'fa-telegram'
                                    ];
                                    $icon = $icons[$contact['contact_type']] ?? 'fa-phone';
                                    ?>
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-capitalize"><?php echo $contact['contact_type']; ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($contact['contact_value']); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Fallback to basic contact info -->
                            <?php if (!empty($professional['phone'])): ?>
                            <div class="contact-method">
                                <div class="contact-icon">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div>
                                    <div class="fw-bold">Phone</div>
                                    <div class="text-muted"><?php echo htmlspecialchars($professional['phone']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="contact-method">
                                <div class="contact-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div>
                                    <div class="fw-bold">Email</div>
                                    <div class="text-muted"><?php echo htmlspecialchars($professional['email']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($professional['portfolio_url'])): ?>
                        <div class="contact-method">
                            <div class="contact-icon">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div>
                                <div class="fw-bold">Portfolio</div>
                                <a href="<?php echo htmlspecialchars($professional['portfolio_url']); ?>" target="_blank" class="text-muted text-decoration-none">View Website</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-6">
                        <div class="stats-card services">
                            <i class="fas fa-briefcase fa-2x mb-3"></i>
                            <h3><?php echo count($services); ?></h3>
                            <p class="mb-0">Services</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stats-card rating">
                            <i class="fas fa-star fa-2x mb-3"></i>
                            <h3><?php echo $professional['review_count'] > 0 ? number_format($professional['avg_rating'], 1) : 'N/A'; ?></h3>
                            <p class="mb-0">Rating</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stats-card jobs">
                            <i class="fas fa-check-circle fa-2x mb-3"></i>
                            <h3><?php echo $professional['completed_jobs'] ?: 0; ?></h3>
                            <p class="mb-0">Jobs Done</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stats-card reviews">
                            <i class="fas fa-comments fa-2x mb-3"></i>
                            <h3><?php echo $professional['review_count'] ?: 0; ?></h3>
                            <p class="mb-0">Reviews</p>
                        </div>
                    </div>
                </div>

<!-- Quick Actions -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-4">
                            <h5 class="section-title mb-4">Quick Actions</h5>
                            <div class="d-grid gap-3">
                                <?php if ($has_skills && $has_services): ?>
                                    <a href="book-service.php?service_id=<?php echo $first_service_id; ?>" class="btn btn-primary btn-lg py-3 fw-bold">
                                        <i class="fas fa-calendar-check me-2"></i>Book Service
                                    </a>
                                <?php endif; ?>
                                
                                <a href="services.php?professional=<?php echo $professional_id; ?>" class="btn btn-outline-primary py-3">
                                    <i class="fas fa-list me-2"></i>View All Services
                                </a>
                                
                                <!-- FIXED LINE: Changed $professional['id'] to $professional_id -->
                                <a href="report-profile.php?id=<?php echo $professional_id; ?>" class="btn btn-outline-danger py-3">
                                    <i class="fas fa-flag me-2"></i>Report Profile
                                </a>
                            </div>
                        </div>
                    </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize professional location map
        <?php if (!empty($professional['latitude']) && !empty($professional['longitude'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const proLat = <?php echo $professional['latitude']; ?>;
            const proLng = <?php echo $professional['longitude']; ?>;
            
            const map = L.map('professionalMap').setView([proLat, proLng], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Create custom marker
            const customIcon = L.divIcon({
                className: 'professional-marker',
                html: `
                    <div style="
                        background: linear-gradient(135deg, #6c5ce7, #a29bfe);
                        color: white;
                        width: 50px;
                        height: 50px;
                        border-radius: 50%;
                        border: 3px solid white;
                        box-shadow: 0 3px 10px rgba(0,0,0,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                    ">
                        <i class="fas fa-briefcase" style="font-size: 18px;"></i>
                    </div>
                `,
                iconSize: [50, 50],
                iconAnchor: [25, 25]
            });

            // Add professional marker
            L.marker([proLat, proLng], { icon: customIcon })
                .addTo(map)
                .bindPopup(`
                    <div class="text-center">
                        <strong><?php echo htmlspecialchars($professional['full_name']); ?></strong><br>
                        <em><?php echo htmlspecialchars($professional['profession']); ?></em><br>
                        <small>Professional Location</small>
                    </div>
                `)
                .openPopup();
        });
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>