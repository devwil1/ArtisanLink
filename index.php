<?php
// Start session to check if user is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config file
require_once "config.php";

// Get popular services (highest rated)
$popular_services = [];
$sql = "SELECT s.*, u.username, u.profile_picture, pr.profession, AVG(s.rating) as avg_rating 
        FROM services s 
        JOIN users u ON s.professional_id = u.id 
        JOIN professional_requests pr ON u.id = pr.user_id 
        WHERE pr.status = 'approved'
        GROUP BY s.id 
        ORDER BY s.rating DESC, s.total_ratings DESC 
        LIMIT 8";
        
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $popular_services[] = $row;
    }
    mysqli_free_result($result);
}

// Get all unique categories for the category section
$categories = [];
$category_sql = "SELECT DISTINCT category FROM services ORDER BY category";
if ($result = mysqli_query($link, $category_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row['category'];
    }
    mysqli_free_result($result);
}

// Get real feedback data from the database
$testimonials = [];
$feedback_sql = "SELECT f.*, u.full_name, u.profile_picture, u.municipality, s.title as service_title
                 FROM feedback f
                 JOIN users u ON f.customer_id = u.id
                 JOIN bookings b ON f.booking_id = b.id
                 JOIN services s ON b.service_id = s.id
                 ORDER BY f.created_at DESC 
                 LIMIT 3";
                 
if ($result = mysqli_query($link, $feedback_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $testimonials[] = $row;
    }
    mysqli_free_result($result);
}

// If no real feedback exists, use some placeholder testimonials
if (count($testimonials) === 0) {
    $testimonials = [
        [
            'full_name' => 'Maria Santos',
            'profile_picture' => null,
            'municipality' => 'Baler',
            'service_title' => 'Plumbing Service',
            'rating' => 5,
            'comment' => 'Found an excellent plumber through Artisan Link. Fixed my leak quickly and professionally. Will definitely use again!',
            'created_at' => '2025-09-20 14:30:00'
        ],
        [
            'full_name' => 'John Reyes',
            'profile_picture' => null,
            'municipality' => 'San Luis',
            'service_title' => 'Electrical Repair',
            'rating' => 5,
            'comment' => 'The electrician I hired was professional and knowledgeable. He explained everything clearly and did a great job with our wiring.',
            'created_at' => '2025-09-18 10:15:00'
        ],
        [
            'full_name' => 'Anna Lopez',
            'profile_picture' => null,
            'municipality' => 'Maria Aurora',
            'service_title' => 'Tailoring Service',
            'rating' => 4,
            'comment' => 'I needed a tailor for my wedding dress alterations. Found a skilled professional who did an amazing job. Highly recommend!',
            'created_at' => '2025-09-15 16:45:00'
        ]
    ];
}

// Close connection
mysqli_close($link);

// Category icons mapping
$category_icons = [
    'Plumbing' => 'fa-faucet',
    'Electrical' => 'fa-bolt',
    'Carpentry' => 'fa-hammer',
    'Painting' => 'fa-paint-roller',
    'Cleaning' => 'fa-broom',
    'Masonry' => 'fa-trowel',
    'Mechanic' => 'fa-tools',
    'Tailoring' => 'fa-tshirt',
    'Tutoring' => 'fa-book',
    'Gardening' => 'fa-leaf',
    'AC Technician' => 'fa-fan',
    'Beautician' => 'fa-spa',
    'Cook' => 'fa-utensils',
    'Driver' => 'fa-car',
    'Photographer' => 'fa-camera',
    'Vulcanizing' => 'fa-cog',
    'Appliance Repair' => 'fa-wrench',
    'Computer Technician' => 'fa-laptop',
    'Interior Designer' => 'fa-couch',
    'Welder' => 'fa-fire',
    'printing expert' => 'fa-print'
];

// Default icon for categories without specific mapping
$default_icon = 'fa-briefcase';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Artisan Link - Connect with Skilled Professionals</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary-color: #4361ee;
      --secondary-color: #3a0ca3;
      --accent-color: #4cc9f0;
      --light-color: #f8f9fa;
      --dark-color: #212529;
      --success-color: #4bb543;
    }
    
    /* Gradient header */
    .navbar {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .navbar-brand, .nav-link, .btn-nav {
      color: white !important;
    }
    .navbar-brand {
      font-weight: 700;
      font-size: 1.5rem;
    }
    
    .product-card {
      border: none;
      border-radius: 15px;
      overflow: hidden;
      background: #fff;
      transition: transform 0.3s, box-shadow 0.3s;
      height: 100%;
      box-shadow: 0 5px 15px rgba(0,0,0,0.05);
      position: relative;
    }
    .product-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 15px 30px rgba(0,0,0,0.1);
    }
    .product-img-placeholder {
      height: 180px;
      object-fit: cover;
      width: 100%;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .product-img-placeholder i {
      color: white;
      font-size: 3rem;
    }
    .btn-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
      border: none;
      padding: 12px 30px;
      border-radius: 50px;
      font-weight: 600;
      transition: all 0.3s;
      box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
    }
    .btn-gradient:hover {
      transform: translateY(-2px);
      box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
      color: white;
    }
    .hero-section {
      background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?ixlib=rb-4.0.3&auto=format&fit=crop&w=1350&q=80');
      background-size: cover;
      background-position: center;
      color: white;
      padding: 100px 0;
      text-align: center;
    }
    .hero-section h1 {
      font-size: 3rem;
      font-weight: 700;
      margin-bottom: 1rem;
    }
    .hero-section p {
      font-size: 1.2rem;
      margin-bottom: 2rem;
    }
    .category-btn {
      transition: all 0.3s;
      height: 100%;
      border: none;
      border-radius: 10px;
      padding: 20px 10px;
      background: white;
      box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }
    .category-btn:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 25px rgba(0,0,0,0.1);
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
    }
    .category-btn:hover .service-icon {
      color: white !important;
    }
    footer {
      background: var(--dark-color);
      color: white;
      padding: 50px 0 20px;
    }
    .service-icon {
      font-size: 2rem;
      margin-bottom: 15px;
      color: var(--primary-color);
    }
    .rating {
      color: #ffc107;
    }
    .testimonial-card {
      border: none;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      transition: transform 0.3s;
      height: 100%;
    }
    .testimonial-card:hover {
      transform: translateY(-5px);
    }
    .profile-avatar {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid var(--primary-color);
    }
    .profile-placeholder {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.5rem;
      border: 3px solid var(--primary-color);
    }
    .section-title {
      font-weight: 700;
      margin-bottom: 3rem;
      position: relative;
      display: inline-block;
    }
    .section-title:after {
      content: '';
      position: absolute;
      width: 50%;
      height: 4px;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      bottom: -10px;
      left: 25%;
      border-radius: 2px;
    }
    .how-it-works-section {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }
    .step-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
      color: white;
      font-size: 2rem;
    }
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
    }
    .share-btn:hover {
      background: white;
      transform: scale(1.1);
    }
    .share-btn i {
      color: var(--primary-color);
    }
    @media (max-width: 768px) {
      .hero-section {
        padding: 60px 0;
      }
      .hero-section h1 {
        font-size: 2rem;
      }
      .hero-section p {
        font-size: 1rem;
      }
    }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
  <div class="container">
    <a class="navbar-brand" href="index.php">
      <i class="fas fa-hands-helping me-2"></i>Artisan Link
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarContent">
      <form class="d-flex ms-auto my-2 my-lg-0 me-3" role="search" action="services.php" method="get">
        <input class="form-control me-2" type="search" name="search" placeholder="Search services..." aria-label="Search">
        <button class="btn btn-light" type="submit"><i class="fas fa-search"></i></button>
      </form>
      <ul class="navbar-nav">
        <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) { ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <?php 
              $profile_img = isset($_SESSION["profile_picture"]) && !empty($_SESSION["profile_picture"]) ? 
                htmlspecialchars($_SESSION["profile_picture"]) : null;
              ?>
              <?php if ($profile_img): ?>
                <img src="<?php echo $profile_img; ?>" class="profile-avatar me-2" alt="Profile">
              <?php else: ?>
                <div class="profile-placeholder me-2">
                  <i class="fas fa-user"></i>
                </div>
              <?php endif; ?>
              <span><?php echo htmlspecialchars($_SESSION["username"]); ?></span>
            </a>
            <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
              <li><a class="dropdown-item" href="welcome.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
              <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
          </li>
        <?php } else { ?>
          <li class="nav-item"><a class="nav-link" href="login.php"><i class="fas fa-sign-in-alt me-1"></i> Login</a></li>
          <li class="nav-item"><a class="nav-link" href="register.php"><i class="fas fa-user-plus me-1"></i> Sign Up</a></li>
        <?php } ?>
      </ul>
    </div>
  </div>
</nav>

<!-- Hero Banner -->
<section class="hero-section">
  <div class="container">
    <h1 class="fw-bold">Welcome to Artisan Link</h1>
    <p class="lead">Connect with skilled professionals or offer your services in Aurora Province</p>
    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) { ?>
      <a href="welcome.php" class="btn btn-gradient btn-lg">Browse <i class="fas fa-arrow-right ms-2"></i></a>
    <?php } else { ?>
      <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
        <a href="register.php" class="btn btn-gradient btn-lg">Get Started <i class="fas fa-arrow-right ms-2"></i></a>  
        <a href="services.php" class="btn btn-outline-light btn-lg">Browse Services</a>
      </div>
    <?php } ?>
  </div>
</section>

<!-- Featured Services -->
<div class="container my-5 py-5">
  <h2 class="text-center section-title">Popular Services</h2>
  <div class="row g-4">
    <?php if (count($popular_services) > 0) : ?>
      <?php foreach ($popular_services as $service) : ?>
        <div class="col-md-3 col-sm-6">
          <div class="product-card">
            <div class="product-img-placeholder">
              <i class="fas <?php echo isset($category_icons[$service['category']]) ? $category_icons[$service['category']] : $default_icon; ?>"></i>
            </div>
            <button class="share-btn" onclick="shareService(<?php echo $service['id']; ?>)">
              <i class="fas fa-share-alt"></i>
            </button>
            <div class="p-3">
              <h5 class="fw-bold"><?php echo htmlspecialchars($service['title']); ?></h5>
              <div class="d-flex align-items-center my-2">
                <?php if (!empty($service['profile_picture'])): ?>
                  <img src="<?php echo htmlspecialchars($service['profile_picture']); ?>" class="profile-avatar me-2" alt="Professional">
                <?php else: ?>
                  <div class="profile-placeholder me-2">
                    <i class="fas fa-user"></i>
                  </div>
                <?php endif; ?>
                <div>
                  <p class="mb-0 fw-bold"><?php echo htmlspecialchars($service['username']); ?></p>
                  <small class="text-muted"><?php echo htmlspecialchars($service['profession']); ?></small>
                </div>
              </div>
              <div class="mt-2">
                <span class="badge bg-success"><i class="fas fa-star me-1"></i><?php echo number_format($service['rating'], 1); ?></span>
                <small class="text-muted ms-2">(<?php echo $service['total_ratings']; ?> reviews)</small>
              </div>
              <a href="service-details.php?id=<?php echo $service['id']; ?>" class="btn btn-outline-primary w-100 mt-3">View Details</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else : ?>
      <div class="col-12 text-center py-5">
        <i class="fas fa-tools fa-3x text-muted mb-3"></i>
        <h4>No services available yet</h4>
        <p>Check back later for professional services.</p>
      </div>
    <?php endif; ?>
  </div>
  
  <?php if (count($popular_services) > 0) : ?>
    <div class="text-center mt-4">
      <a href="services.php" class="btn btn-gradient">View All Services</a>
    </div>
  <?php endif; ?>
</div>

<!-- Categories -->
<div class="container my-5 py-5">
  <h2 class="text-center section-title">Browse Categories</h2>
  <div class="row g-3">
    <?php if (count($categories) > 0) : ?>
      <?php foreach ($categories as $category) : ?>
        <div class="col-md-2 col-6">
          <a href="services.php?category=<?php echo urlencode($category); ?>" class="btn btn-outline-primary w-100 category-btn py-3 text-decoration-none">
            <i class="fas <?php echo isset($category_icons[$category]) ? $category_icons[$category] : $default_icon; ?> service-icon d-block"></i>
            <?php echo htmlspecialchars($category); ?>
          </a>
        </div>
      <?php endforeach; ?>
    <?php else : ?>
      <div class="col-12 text-center py-4">
        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
        <h4>No categories available yet</h4>
        <p>Categories will appear as professionals add services.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- How it Works Section -->
<section class="how-it-works-section py-5">
  <div class="container">
    <h2 class="text-center section-title mb-5">How It Works</h2>
    <div class="row text-center">
      <div class="col-md-4 mb-4">
        <div class="bg-white p-4 rounded shadow-sm h-100">
          <div class="step-icon">
            <i class="fas fa-search"></i>
          </div>
          <h4 class="my-3">1. Search</h4>
          <p>Find the service you need from our trusted professionals in Aurora Province</p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="bg-white p-4 rounded shadow-sm h-100">
          <div class="step-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
          <h4 class="my-3">2. Book</h4>
          <p>Schedule a time that works best for you and discuss your requirements</p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="bg-white p-4 rounded shadow-sm h-100">
          <div class="step-icon">
            <i class="fas fa-thumbs-up"></i>
          </div>
          <h4 class="my-3">3. Enjoy</h4>
          <p>Relax while professionals handle your needs with expertise</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Testimonials Section -->
<section class="py-5 my-5">
  <div class="container">
    <h2 class="text-center section-title mb-5">What Our Customers Say</h2>
    <div class="row">
      <?php foreach ($testimonials as $testimonial) : ?>
        <div class="col-md-4 mb-4">
          <div class="testimonial-card p-4 h-100">
            <div class="rating mb-3">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="fas fa-star<?php echo $i <= $testimonial['rating'] ? '' : '-half-alt'; ?>"></i>
              <?php endfor; ?>
            </div>
            <p class="card-text fst-italic">"<?php echo htmlspecialchars($testimonial['comment']); ?>"</p>
            <div class="d-flex align-items-center mt-4">
              <?php if (!empty($testimonial['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($testimonial['profile_picture']); ?>" class="profile-avatar me-3" alt="Customer">
              <?php else: ?>
                <div class="profile-placeholder me-3">
                  <i class="fas fa-user"></i>
                </div>
              <?php endif; ?>
              <div>
                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($testimonial['full_name']); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($testimonial['municipality']); ?></small>
                <br>
                <small class="text-primary"><?php echo htmlspecialchars($testimonial['service_title']); ?></small>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="mt-5">
  <div class="container">
    <div class="row">
      <div class="col-md-4 mb-4">
        <h5><i class="fas fa-hands-helping me-2"></i>Artisan Link</h5>

        <div class="d-flex mt-3">
          <a href="#" class="text-white me-3"><i class="fab fa-facebook-f"></i></a>
          <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
          <a href="#" class="text-white me-3"><i class="fab fa-instagram"></i></a>
          <a href="#" class="text-white"><i class="fab fa-linkedin-in"></i></a>
        </div>
      </div>
      <div class="col-md-2 mb-4">
        <h5>Links</h5>
        <ul class="list-unstyled">
          <li><a href="index.php" class="text-white text-decoration-none">Home</a></li>
          <li><a href="#" class="text-white text-decoration-none">About</a></li>
          <li><a href="services.php" class="text-white text-decoration-none">Services</a></li>
          <li><a href="#" class="text-white text-decoration-none">Contact</a></li>
        </ul>
      </div>
      <div class="col-md-2 mb-4">
        <h5>Services</h5>
        <ul class="list-unstyled">
          <li><a href="services.php?category=Plumbing" class="text-white text-decoration-none">Plumbing</a></li>
          <li><a href="services.php?category=Electrical" class="text-white text-decoration-none">Electrical</a></li>
          <li><a href="services.php?category=Cleaning" class="text-white text-decoration-none">Cleaning</a></li>
          <li><a href="services.php?category=Painting" class="text-white text-decoration-none">More...</a></li>
        </ul>
      </div>
      <div class="col-md-4 mb-4">

      </div>
    </div>
    <hr class="my-4">
    <div class="text-center">
      <p class="mb-0">&copy; 2025 Artisan Link. All rights reserved.</p>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function shareService(serviceId) {
    const url = `http://localhost/ArtisanLink/service-details.php?id=${serviceId}`;
    
    if (navigator.share) {
        navigator.share({
            title: 'Artisan Link Service',
            text: 'Check out this service on Artisan Link',
            url: url
        })
        .then(() => console.log('Successful share'))
        .catch((error) => console.log('Error sharing:', error));
    } else {
        // Fallback: Copy to clipboard
        navigator.clipboard.writeText(url).then(() => {
            alert('Service link copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy: ', err);
            alert('Failed to copy link. Please copy the URL manually.');
        });
    }
}
</script>
</body>
</html>