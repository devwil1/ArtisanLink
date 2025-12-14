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
$search = $category = $min_rating = $location = $municipality = "";
$professionals = [];

// Municipalities of Aurora
$municipalities = ['Baler', 'San Luis', 'Maria Aurora', 'Dipaculao'];

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

// Process filter form
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    $search = isset($_GET["search"]) ? trim($_GET["search"]) : "";
    $category = isset($_GET["category"]) ? $_GET["category"] : "";
    $min_rating = isset($_GET["min_rating"]) ? $_GET["min_rating"] : "";
    $municipality = isset($_GET["municipality"]) ? $_GET["municipality"] : "";
    $location = isset($_GET["location"]) ? $_GET["location"] : "";
    
    // Build query to get professionals based on services from services table
    $sql = "SELECT u.id, u.username, u.full_name, u.municipality, u.barangay, u.profile_picture, u.latitude, u.longitude,
                   pr.profession, pr.pricing_type,
                   COALESCE(AVG(f.rating), 0) as avg_rating,
                   COUNT(DISTINCT f.id) as review_count,
                   COUNT(DISTINCT s.id) as service_count,
                   GROUP_CONCAT(DISTINCT s.title SEPARATOR ', ') as service_titles
            FROM users u 
            JOIN professional_requests pr ON u.id = pr.user_id 
            LEFT JOIN services s ON u.id = s.professional_id
            LEFT JOIN feedback f ON u.id = f.professional_id
            WHERE pr.status = 'approved' AND u.user_type = 'professional'";
    
    $params = [];
    $types = "";
    
    // Search by services or profession
    if (!empty($search)) {
        $sql .= " AND (s.title LIKE ? OR pr.profession LIKE ? OR u.full_name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    if (!empty($category)) {
        $sql .= " AND pr.profession = ?";
        $params[] = $category;
        $types .= "s";
    }
    
    if (!empty($municipality)) {
        $sql .= " AND u.municipality = ?";
        $params[] = $municipality;
        $types .= "s";
    }
    
    if (!empty($location)) {
        $sql .= " AND (u.barangay LIKE ? OR u.municipality LIKE ?)";
        $location_param = "%$location%";
        $params[] = $location_param;
        $params[] = $location_param;
        $types .= "ss";
    }
    
    $sql .= " GROUP BY u.id, u.username, u.full_name, u.municipality, u.barangay, u.profile_picture, 
                     pr.profession, pr.pricing_type";
    
    // Add rating filter after grouping
    if (!empty($min_rating)) {
        $sql .= " HAVING avg_rating >= ?";
        $params[] = $min_rating;
        $types .= "d";
    }
    
    $sql .= " ORDER BY avg_rating DESC, review_count DESC";
    
    // Prepare and execute statement
    if ($stmt = mysqli_prepare($link, $sql)) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            
            while ($row = mysqli_fetch_assoc($result)) {
                // Parse service titles
                if (!empty($row['service_titles'])) {
                    $row['services_list'] = array_slice(explode(', ', $row['service_titles']), 0, 3);
                } else {
                    $row['services_list'] = [];
                }
                
                $professionals[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Get all unique professions for category filter
$professions = [];
$profession_sql = "SELECT DISTINCT profession FROM professional_requests WHERE status = 'approved' ORDER BY profession";
if ($result = mysqli_query($link, $profession_sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $professions[] = $row['profession'];
    }
    mysqli_free_result($result);
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Find Professionals - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .professional-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.2s;
            height: 100%;
            padding: 20px;
        }
        .professional-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .profile-img {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            display: block;
            border: 3px solid #f0f0f0;
        }
        .price-range {
            color: #ff5722;
            font-weight: bold;
        }
        .rating {
            color: #ffc107;
        }
        .filter-sidebar {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            position: sticky;
            top: 20px;
        }
        #map {
            height: 300px;
            border-radius: 10px;
            margin-bottom: 20px;
            z-index: 1;
        }
        .location-badge {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8em;
        }
        .leaflet-popup-content {
            margin: 12px;
        }
        .map-popup {
            max-width: 250px;
        }
        .map-popup img {
            border-radius: 5px;
            margin-bottom: 8px;
        }
        .no-reviews {
            color: #6c757d;
            font-style: italic;
        }
        .service-count {
            background-color: #e9ecef;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.85em;
        }
        .services-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
            justify-content: center;
        }
        .service-tag {
            background-color: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75em;
        }
        .search-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        .location-options {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <h1 class="mb-4">Find Professionals in Central Aurora</h1>
        
        <!-- Search Section -->
        <div class="search-section">
            <h2 class="mb-4">Find Professionals</h2>
            <form action="services.php" method="get" id="searchForm">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <input type="text" name="search" class="form-control form-control-lg" 
                               placeholder="What service do you need? (e.g., plumbing, electrician, cleaning)"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-search me-2"></i> Search
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <select name="category" class="form-select">
                            <option value="">All Professions</option>
                            <?php foreach ($professions as $prof): ?>
                            <option value="<?php echo $prof; ?>" <?php echo ($category == $prof) ? 'selected' : ''; ?>>
                                <?php echo $prof; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <select name="min_rating" class="form-select">
                            <option value="">Any Rating</option>
                            <option value="4" <?php echo ($min_rating == "4") ? 'selected' : ''; ?>>4+ Stars</option>
                            <option value="3" <?php echo ($min_rating == "3") ? 'selected' : ''; ?>>3+ Stars</option>
                            <option value="2" <?php echo ($min_rating == "2") ? 'selected' : ''; ?>>2+ Stars</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <!-- Location selection -->
                        <input type="hidden" name="latitude" id="searchLatitude">
                        <input type="hidden" name="longitude" id="searchLongitude">
                        <div class="location-options">
                            <label class="form-label fw-bold">Select Your Location:</label>
                            <div class="d-flex gap-2">
                                <button type="button" id="useCurrentLocation" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-location-arrow"></i> Current Location
                                </button>
                                <button type="button" id="pickOnMap" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-map-marker-alt"></i> Pick on Map
                                </button>
                            </div>
                            <small class="text-muted" id="locationStatus">
                                <?php echo $user_municipality ? "Using: {$user_municipality}, {$user_barangay}" : "Location not set"; ?>
                            </small>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="row">
            <!-- Filters Sidebar -->
            <div class="col-md-3">
                <div class="filter-sidebar">
                    <h4>Refine Search</h4>
                    <form method="get" action="services.php">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Municipality</label>
                            <select name="municipality" class="form-select">
                                <option value="">All Municipalities</option>
                                <?php foreach ($municipalities as $mun): ?>
                                <option value="<?php echo $mun; ?>" <?php echo ($municipality == $mun) ? 'selected' : ''; ?>>
                                    <?php echo $mun; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location Search</label>
                            <input type="text" name="location" class="form-control" placeholder="Barangay or Area" value="<?php echo htmlspecialchars($location); ?>">
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                        <a href="services.php" class="btn btn-outline-secondary w-100 mt-2">Clear Filters</a>
                    </form>
                </div>

                <!-- Map Section -->
                <div class="mt-4">
                    <h5>Professionals Map</h5>
                    <div id="map"></div>
                    <p class="text-muted small">View professionals near your location</p>
                </div>
            </div>
            
            <!-- Professionals List -->
            <div class="col-md-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Available Professionals</h2>
                    <span class="badge bg-primary"><?php echo count($professionals); ?> professionals found</span>
                </div>

                <div class="row">
                    <?php if (count($professionals) > 0) : ?>
                        <?php foreach ($professionals as $professional) : 
                            $avg_rating = $professional['avg_rating'] ? number_format($professional['avg_rating'], 1) : 0;
                            $review_count = $professional['review_count'] ? $professional['review_count'] : 0;
                            $service_count = $professional['service_count'] ? $professional['service_count'] : 0;
                            $profile_picture = $professional['profile_picture'] ? $professional['profile_picture'] : 'uploads/profiles/default-profile.jpg';
                            $services_list = $professional['services_list'] ?: [];
                        ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="professional-card text-center">
                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Profile Picture" class="profile-img" 
                                     onerror="this.src='https://via.placeholder.com/100x100?text=<?php echo urlencode(substr($professional['full_name'] ?? $professional['username'], 0, 1)); ?>'">
                                
                                <h5><?php echo htmlspecialchars($professional['full_name'] ?? $professional['username']); ?></h5>
                                <p class="text-muted mb-2"><?php echo htmlspecialchars($professional['profession']); ?></p>
                                
                                <div class="d-flex align-items-center justify-content-center mb-2">
                                    <span class="location-badge me-2">
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?php echo htmlspecialchars($professional['municipality']); ?>
                                    </span>
                                    <?php if (!empty($professional['barangay'])) : ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($professional['barangay']); ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if ($review_count > 0) : ?>
                                        <span class="rating">
                                            <i class="fas fa-star"></i> <?php echo $avg_rating; ?>
                                            <small class="text-muted">(<?php echo $review_count; ?>)</small>
                                        </span>
                                    <?php else : ?>
                                        <span class="no-reviews">No reviews yet</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($professional['pricing_type'])) : ?>
                                        <span class="price-range"><?php echo htmlspecialchars($professional['pricing_type']); ?></span>
                                    <?php else : ?>
                                        <span class="price-range">Price varies</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <span class="service-count"><?php echo $service_count; ?> services offered</span>
                                </div>
                                
                                <!-- Services Tags - These are the services the professional offers -->
                                <?php if (!empty($services_list)) : ?>
                                <div class="services-tags">
                                    <?php foreach ($services_list as $service): ?>
                                        <span class="service-tag"><?php echo htmlspecialchars(trim($service)); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($services_list) > 3): ?>
                                        <span class="service-tag">+<?php echo count($services_list) - 3; ?> more</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <a href="professional-profile.php?id=<?php echo $professional['id']; ?>" class="btn btn-outline-primary w-100">View Profile</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-search fa-3x text-muted mb-3"></i>
                            <h3>No professionals found</h3>
                            <p>Try adjusting your search terms or filters to find more results.</p>
                            <a href="services.php" class="btn btn-primary">Clear Filters</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Aurora municipalities coordinates (approximate centers)
        const auroraLocations = {
            'Baler': { lat: 15.7583, lng: 121.5625 },
            'San Luis': { lat: 15.7200, lng: 121.5175 },
            'Maria Aurora': { lat: 15.7967, lng: 121.4733 },
            'Dipaculao': { lat: 15.9433, lng: 121.6319 }
        };

        // User coordinates
        const userCoords = {
            lat: <?php echo $user_latitude ?: '15.7583'; ?>,
            lng: <?php echo $user_longitude ?: '121.5625'; ?>,
            municipality: '<?php echo $user_municipality ?: "Baler"; ?>',
            barangay: '<?php echo $user_barangay ?: ""; ?>'
        };

        // Professionals data
        const professionals = <?php echo json_encode($professionals); ?>;

        // Initialize map
        function initMap() {
            // Create a map centered on user location or Baler
            const map = L.map('map').setView([userCoords.lat, userCoords.lng], 10);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add user location marker
            if (userCoords.lat && userCoords.lng) {
                L.marker([userCoords.lat, userCoords.lng])
                    .addTo(map)
                    .bindPopup(`
                        <div class="text-center">
                            <strong>Your Location</strong><br>
                            ${userCoords.municipality}, ${userCoords.barangay}
                        </div>
                    `)
                    .openPopup();
            }
            
            // Add markers for each professional
            professionals.forEach((pro) => {
                if (pro.latitude && pro.longitude) {
                    const marker = L.marker([parseFloat(pro.latitude), parseFloat(pro.longitude)])
                        .addTo(map)
                        .bindPopup(`
                            <div class="map-popup">
                                <h6>${pro.full_name || pro.username}</h6>
                                <p class="mb-1">${pro.profession}</p>
                                <p class="mb-1">${pro.municipality}, ${pro.barangay}</p>
                                <a href="professional-profile.php?id=${pro.id}" class="btn btn-sm btn-primary mt-1">View Profile</a>
                            </div>
                        `);
                }
            });
            
            // Add municipality labels
            Object.entries(auroraLocations).forEach(([municipality, coords]) => {
                L.marker([coords.lat, coords.lng], {
                    icon: L.divIcon({
                        html: `<div style="background-color: #1e3c72; color: white; padding: 5px 10px; border-radius: 15px; font-weight: bold;">${municipality}</div>`,
                        className: 'municipality-label',
                        iconSize: [100, 30]
                    })
                }).addTo(map);
            });
        }

        // Location functionality
        document.getElementById('useCurrentLocation').addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        userCoords.lat = position.coords.latitude;
                        userCoords.lng = position.coords.longitude;
                        
                        // Update search form
                        document.getElementById('searchLatitude').value = userCoords.lat;
                        document.getElementById('searchLongitude').value = userCoords.lng;
                        
                        // Reverse geocode to get address
                        getUserAddress(userCoords.lat, userCoords.lng);
                        
                        initMap();
                    },
                    function(error) {
                        alert('Unable to get your location. Please enable location services.');
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        });

        // Pick location on map
        document.getElementById('pickOnMap').addEventListener('click', function() {
            const mapElement = document.getElementById('map');
            const mapRect = mapElement.getBoundingClientRect();
            const mapTop = mapRect.top + window.scrollY;
            
            window.scrollTo({
                top: mapTop - 20,
                behavior: 'smooth'
            });
            
            alert('Click on the map above to set your location.');
        });

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
                        document.getElementById('locationStatus').textContent = 
                            `Using: ${userCoords.municipality}, ${userCoords.barangay}`;
                    }
                })
                .catch(() => {
                    document.getElementById('locationStatus').textContent = 
                        `Using: Custom location (${userCoords.lat.toFixed(4)}, ${userCoords.lng.toFixed(4)})`;
                });
        }

        // Initialize the map when the page loads
        document.addEventListener('DOMContentLoaded', initMap);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>