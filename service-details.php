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

// Check if service ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])){
    header("location: services.php");
    exit;
}

$service_id = trim($_GET['id']);

// Get service details with enhanced professional information
$service = [];
$sql = "SELECT s.*, u.username, u.email, u.profile_picture, pr.profession, pr.full_name, pr.phone, 
               pr.municipality, pr.barangay, pr.latitude, pr.longitude, pr.pricing_type,
               COALESCE(AVG(f.rating), 0) as avg_rating, 
               COUNT(DISTINCT f.id) as review_count,
               COUNT(DISTINCT b.id) as completed_jobs
        FROM services s
        JOIN users u ON s.professional_id = u.id
        JOIN professional_requests pr ON u.id = pr.user_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        LEFT JOIN bookings b ON u.id = b.professional_id AND b.status = 'completed'
        WHERE s.id = ? AND pr.status = 'approved'
        GROUP BY s.id, u.id, pr.id";
        
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($result) == 1){
            $service = mysqli_fetch_assoc($result);
        } else {
            // Service doesn't exist
            header("location: services.php");
            exit;
        }
    } else {
        echo "Oops! Something went wrong. Please try again later.";
    }
    mysqli_stmt_close($stmt);
}

// Get reviews for this service with customer information
$reviews = [];
$review_sql = "SELECT f.*, u.username as customer_name, u.full_name as customer_full_name,
                      b.booking_date
               FROM feedback f
               JOIN bookings b ON f.booking_id = b.id
               JOIN users u ON f.customer_id = u.id
               WHERE b.service_id = ?
               ORDER BY f.created_at DESC
               LIMIT 10";
               
if($stmt = mysqli_prepare($link, $review_sql)){
    mysqli_stmt_bind_param($stmt, "i", $service_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $reviews[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get other services from the same professional
$other_services = [];
$other_sql = "SELECT s.*, COALESCE(AVG(f.rating), 0) as avg_rating, 
                     COUNT(DISTINCT f.id) as review_count
              FROM services s
              LEFT JOIN bookings b ON s.id = b.service_id
              LEFT JOIN feedback f ON b.id = f.booking_id
              WHERE s.professional_id = ? AND s.id != ?
              GROUP BY s.id
              ORDER BY s.created_at DESC
              LIMIT 4";
              
if($stmt = mysqli_prepare($link, $other_sql)){
    mysqli_stmt_bind_param($stmt, "ii", $service['professional_id'], $service_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $other_services[] = $row;
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
    <title><?php echo htmlspecialchars($service['title']); ?> - Service Details - Artisan Link</title>
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
        
        .service-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 40px 0;
            position: relative;
            overflow: hidden;
        }
        
        .service-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.1);
        }
        
        .professional-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            background: white;
        }
        
        .service-image-placeholder {
            height: 400px;
            border-radius: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .rating {
            color: var(--warning);
        }
        
        .review-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            background: white;
        }
        
        .other-service-card {
            border: none;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            background: white;
        }
        
        .other-service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .price-tag {
            background: linear-gradient(135deg, var(--success), #00a085);
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: bold;
            font-size: 1.2em;
        }
        
        .service-meta {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .service-feature {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        
        .service-feature:hover {
            background: var(--primary);
            color: white;
            transform: translateX(5px);
        }
        
        .service-feature:hover .feature-icon {
            background: white;
            color: var(--primary);
        }
        
        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2em;
            transition: all 0.3s ease;
        }
        
        .professional-avatar {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .professional-avatar-placeholder {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .verified-badge {
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .service-map {
            height: 250px;
            border-radius: 12px;
            margin-bottom: 20px;
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
        
        .booking-cta {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin: 30px 0;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .service-image-placeholder {
                height: 250px;
            }
            
            .service-header {
                padding: 30px 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <!-- Service Header -->
    <div class="service-header">
        <div class="container">
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="services.php" class="text-white-50">Services</a></li>
                    <li class="breadcrumb-item"><a href="services.php?category=<?php echo urlencode($service['category']); ?>" class="text-white-50"><?php echo htmlspecialchars($service['category']); ?></a></li>
                    <li class="breadcrumb-item active text-white" aria-current="page"><?php echo htmlspecialchars($service['title']); ?></li>
                </ol>
            </nav>
            
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-5 fw-bold mb-3"><?php echo htmlspecialchars($service['title']); ?></h1>
                    <div class="d-flex align-items-center flex-wrap mb-3">
                        <span class="rating me-3 mb-2">
                            <i class="fas fa-star"></i> 
                            <?php echo number_format($service['avg_rating'], 1); ?>
                            <small class="ms-1">(<?php echo $service['review_count']; ?> reviews)</small>
                        </span>
                        <span class="mx-2 mb-2">•</span>
                        <span class="mb-2"><i class="fas fa-map-marker-alt me-1"></i> 
                            <?php 
                            if (!empty($service['municipality'])) {
                                echo htmlspecialchars($service['municipality'] . ', ' . $service['barangay']);
                            } else {
                                echo 'Central Aurora';
                            }
                            ?>
                        </span>
                        <span class="mx-2 mb-2">•</span>
                        <span class="verified-badge mb-2">
                            <i class="fas fa-check-circle me-1"></i>Verified Professional
                        </span>
                    </div>
                    <p class="lead mb-4"><?php echo htmlspecialchars($service['description']); ?></p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="price-tag d-inline-block mb-3">
                        ₱<?php echo number_format($service['price'], 2); ?>
                    </div>
                    <div class="d-grid gap-2">
                        <a href="book-service.php?service_id=<?php echo $service['id']; ?>" class="btn btn-light btn-lg fw-bold py-3">
                            <i class="fas fa-calendar-check me-2"></i>Book This Service
                        </a>
                        <a href="professional-profile.php?id=<?php echo $service['professional_id']; ?>" class="btn btn-outline-light btn-lg py-3">
                            <i class="fas fa-user-tie me-2"></i>View Professional Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-5">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Service Image & Description -->
                <div class="service-image-placeholder">
                    <div class="text-center">
                        <i class="fas fa-tools fa-5x mb-3"></i>
                        <h3 class="mb-2"><?php echo htmlspecialchars($service['title']); ?></h3>
                        <p class="mb-0">Professional Service by <?php echo htmlspecialchars($service['full_name']); ?></p>
                    </div>
                </div>

                <!-- Service Features -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="section-title">Service Features</h3>
                        
                        <div class="service-feature">
                            <div class="feature-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Quality Guaranteed</h5>
                                <p class="mb-0">Professional service with attention to detail and quality workmanship.</p>
                            </div>
                        </div>
                        
                        <div class="service-feature">
                            <div class="feature-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Pricing: <?php echo htmlspecialchars($service['pricing_type'] ?? 'Per Job'); ?></h5>
                                <p class="mb-0">Transparent pricing with no hidden fees.</p>
                            </div>
                        </div>
                        
                        <div class="service-feature">
                            <div class="feature-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Verified Professional</h5>
                                <p class="mb-0">Background checked and approved by Artisan Link.</p>
                            </div>
                        </div>
                        
                        <div class="service-feature">
                            <div class="feature-icon">
                                <i class="fas fa-star"></i>
                            </div>
                            <div>
                                <h5 class="mb-1">Customer Reviews</h5>
                                <p class="mb-0">Rated <?php echo number_format($service['avg_rating'], 1); ?> by <?php echo $service['review_count']; ?> customers.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Description -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h3 class="section-title">Service Details</h3>
                        <div class="fs-6 text-dark">
                            <?php echo nl2br(htmlspecialchars($service['description'])); ?>
                        </div>
                    </div>
                </div>

                <!-- Booking CTA -->
                <div class="booking-cta">
                    <h3 class="mb-3">Ready to Get Started?</h3>
                    <p class="mb-4">Book this service now and experience professional quality work.</p>
                    <a href="book-service.php?service_id=<?php echo $service['id']; ?>" class="btn btn-light btn-lg px-5 py-3 fw-bold">
                        <i class="fas fa-rocket me-2"></i>Book Service Now
                    </a>
                </div>

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
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-clock me-1"></i><?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                        </p>
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
                                <?php if (!empty($review['comment'])) { ?>
                                <p class="mb-0 fs-6">"<?php echo htmlspecialchars($review['comment']); ?>"</p>
                                <?php } ?>
                            </div>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="text-center py-5">
                                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                                <h5>No Reviews Yet</h5>
                                <p class="text-muted">Be the first to review this service!</p>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Professional Card -->
                <div class="professional-card">
                    <div class="text-center mb-4">
                        <?php if (!empty($service['profile_picture'])) { ?>
                            <img src="<?php echo htmlspecialchars($service['profile_picture']); ?>" class="professional-avatar mb-3" alt="Professional Avatar">
                        <?php } else { ?>
                            <div class="professional-avatar-placeholder mb-3">
                                <i class="fas fa-user-tie fa-2x"></i>
                            </div>
                        <?php } ?>
                        <h4 class="mb-2"><?php echo htmlspecialchars($service['full_name']); ?></h4>
                        <p class="text-muted mb-3"><?php echo htmlspecialchars($service['profession']); ?></p>
                        
                        <div class="text-center mb-4">
                            <span class="rating">
                                <i class="fas fa-star"></i> 
                                <?php echo number_format($service['avg_rating'], 1); ?>
                                <small class="text-muted">(<?php echo $service['review_count']; ?> reviews)</small>
                            </span>
                        </div>
                        
                        <div class="d-grid gap-2 mb-4">
                            <a href="professional-profile.php?id=<?php echo $service['professional_id']; ?>" class="btn btn-outline-primary py-3">
                                <i class="fas fa-user-tie me-2"></i>View Full Profile
                            </a>
                            <a href="book-service.php?service_id=<?php echo $service['id']; ?>" class="btn btn-primary py-3 fw-bold">
                                <i class="fas fa-calendar-plus me-2"></i>Book This Service
                            </a>
                        </div>
                        
                        <hr>
                        
                        <div class="professional-info">
                            <?php if (!empty($service['phone'])) { ?>
                            <p class="mb-2">
                                <i class="fas fa-phone me-2 text-primary"></i> 
                                <?php echo htmlspecialchars($service['phone']); ?>
                            </p>
                            <?php } ?>
                            <p class="mb-2">
                                <i class="fas fa-envelope me-2 text-primary"></i> 
                                <?php echo htmlspecialchars($service['email']); ?>
                            </p>
                            <?php if (!empty($service['municipality'])) { ?>
                            <p class="mb-2">
                                <i class="fas fa-map-marker-alt me-2 text-primary"></i> 
                                <?php echo htmlspecialchars($service['municipality'] . ', ' . $service['barangay']); ?>
                            </p>
                            <?php } ?>
                            <p class="mb-0">
                                <i class="fas fa-briefcase me-2 text-primary"></i> 
                                <?php echo $service['completed_jobs'] ?: '0'; ?> jobs completed
                            </p>
                        </div>

                        <!-- Professional Location Map -->
                        <?php if (!empty($service['latitude']) && !empty($service['longitude'])): ?>
                        <div class="mt-4">
                            <h6 class="fw-bold mb-3">Service Location</h6>
                            <div id="serviceMap" class="service-map"></div>
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Service available in this area
                            </small>
                        </div>
                        <?php endif; ?>

                        <!-- Report Button -->
                        <div class="text-center mt-4">
                            <a href="report-profile.php?id=<?php echo $service['professional_id']; ?>" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-flag me-1"></i>Report Professional
                            </a>
                            <small class="d-block text-muted mt-1">Report suspicious activity</small>
                        </div>
                    </div>
                </div>

                <!-- Other Services -->
                <?php if (count($other_services) > 0) { ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="section-title mb-4">More Services by <?php echo htmlspecialchars(explode(' ', $service['full_name'])[0]); ?></h5>
                        
                        <?php foreach ($other_services as $other_service) { ?>
                        <div class="other-service-card">
                            <h6 class="text-primary mb-2"><?php echo htmlspecialchars($other_service['title']); ?></h6>
                            <p class="text-muted small mb-2"><?php echo htmlspecialchars($other_service['category']); ?></p>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-bold text-dark">₱<?php echo number_format($other_service['price'], 2); ?></span>
                                <?php if ($other_service['avg_rating'] > 0): ?>
                                <span class="rating small">
                                    <i class="fas fa-star"></i> 
                                    <?php echo number_format($other_service['avg_rating'], 1); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-grid gap-2">
                                <a href="service-details.php?id=<?php echo $other_service['id']; ?>" class="btn btn-outline-primary btn-sm">View Details</a>
                                <a href="book-service.php?service_id=<?php echo $other_service['id']; ?>" class="btn btn-primary btn-sm">Book Now</a>
                            </div>
                        </div>
                        <?php } ?>
                        
                        <a href="professional-profile.php?id=<?php echo $service['professional_id']; ?>" class="btn btn-outline-primary w-100 mt-3 py-2">
                            <i class="fas fa-list me-2"></i>View All Services
                        </a>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize service location map
        <?php if (!empty($service['latitude']) && !empty($service['longitude'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const proLat = <?php echo $service['latitude']; ?>;
            const proLng = <?php echo $service['longitude']; ?>;
            
            const map = L.map('serviceMap').setView([proLat, proLng], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Create custom marker
            const customIcon = L.divIcon({
                className: 'service-marker',
                html: `
                    <div style="
                        background: linear-gradient(135deg, #6c5ce7, #a29bfe);
                        color: white;
                        width: 40px;
                        height: 40px;
                        border-radius: 50%;
                        border: 3px solid white;
                        box-shadow: 0 3px 10px rgba(0,0,0,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-weight: bold;
                    ">
                        <i class="fas fa-map-marker-alt" style="font-size: 16px;"></i>
                    </div>
                `,
                iconSize: [40, 40],
                iconAnchor: [20, 20]
            });

            // Add professional marker
            L.marker([proLat, proLng], { icon: customIcon })
                .addTo(map)
                .bindPopup(`
                    <div class="text-center">
                        <strong>Service Location</strong><br>
                        <em><?php echo htmlspecialchars($service['full_name']); ?></em><br>
                        <small><?php echo htmlspecialchars($service['municipality'] . ', ' . $service['barangay']); ?></small>
                    </div>
                `)
                .openPopup();
        });
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>