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

// Check if user has full name, if not set flag for modal
$user_full_name = "";
$show_name_modal = false;
$sql_user = "SELECT full_name FROM users WHERE id = ?";
if($stmt_user = mysqli_prepare($link, $sql_user)){
    mysqli_stmt_bind_param($stmt_user, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt_user);
    mysqli_stmt_bind_result($stmt_user, $user_full_name);
    mysqli_stmt_fetch($stmt_user);
    mysqli_stmt_close($stmt_user);

    if(empty(trim($user_full_name))){
        $show_name_modal = true;
    }
}

// Check if service ID is provided
if(!isset($_GET['service_id']) || empty($_GET['service_id'])){
    header("location: services.php");
    exit;
}

$service_id = trim($_GET['service_id']);

// Get service details with professional location from user_locations table
$service = [];
$sql = "SELECT s.*, u.username, u.full_name, u.profile_picture, u.phone_number as professional_phone, pr.profession, s.price, 
               ul.latitude, ul.longitude, ul.city, ul.state, ul.address as professional_address,
               COALESCE(AVG(f.rating), 0) as rating, 
               COUNT(f.id) as total_ratings
        FROM services s 
        JOIN users u ON s.professional_id = u.id 
        JOIN professional_requests pr ON u.id = pr.user_id 
        LEFT JOIN user_locations ul ON u.id = ul.user_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        WHERE s.id = ? AND pr.status = 'approved'
        GROUP BY s.id, u.id, ul.latitude, ul.longitude, ul.city, ul.state, ul.address";
        
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

// If no location found in user_locations, try to get from professional_requests
if (empty($service['latitude']) || empty($service['longitude'])) {
    $location_sql = "SELECT latitude, longitude, municipality as city, barangay, address 
                     FROM professional_requests 
                     WHERE user_id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL";
    
    if($stmt = mysqli_prepare($link, $location_sql)){
        mysqli_stmt_bind_param($stmt, "i", $service['professional_id']);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            
            if(mysqli_num_rows($result) == 1){
                $location_data = mysqli_fetch_assoc($result);
                // Merge the location data into service array
                $service = array_merge($service, $location_data);
                
                // If city is empty from professional_requests, use municipality
                if (empty($service['city']) && !empty($service['municipality'])) {
                    $service['city'] = $service['municipality'];
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Get user's phone number
$user_phone = "";
$phone_sql = "SELECT phone_number FROM users WHERE id = ?";
if($stmt = mysqli_prepare($link, $phone_sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $user_phone);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}

// Get already booked dates for this professional
$booked_dates = [];
$dates_sql = "SELECT booking_date FROM bookings 
              WHERE professional_id = ? AND status IN ('confirmed', 'pending') 
              AND professional_response IN ('accepted', 'pending')";
              
if($stmt = mysqli_prepare($link, $dates_sql)){
    mysqli_stmt_bind_param($stmt, "i", $service['professional_id']);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $booked_dates[] = date('Y-m-d', strtotime($row['booking_date']));
        }
    }
    mysqli_stmt_close($stmt);
}

// Get professional's blocked dates
$blocked_dates = [];
$blocked_sql = "SELECT blocked_date FROM professional_blocked_dates WHERE professional_id = ?";
if($stmt = mysqli_prepare($link, $blocked_sql)){
    mysqli_stmt_bind_param($stmt, "i", $service['professional_id']);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $blocked_dates[] = $row['blocked_date'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Combine booked and blocked dates
$unavailable_dates = array_merge($booked_dates, $blocked_dates);

// Initialize variables
$booking_date = $booking_time = $address = $notes = $phone = "";
$booking_date_err = $booking_time_err = $address_err = $phone_err = "";

// Process booking form only if user has full name
if($_SERVER["REQUEST_METHOD"] == "POST" && !$show_name_modal){

    // Validate booking date
    if(empty(trim($_POST["booking_date"]))){
        $booking_date_err = "Please select a date.";
    } else {
        $selected_date = trim($_POST["booking_date"]);
        $selected_date_formatted = date('Y-m-d', strtotime($selected_date));
        
        // Check if date is in the past
        if(strtotime($selected_date) < strtotime('today')){
            $booking_date_err = "Please select a future date.";
        } 
        // Check if date is already booked
        elseif(in_array($selected_date_formatted, $booked_dates)){
            $booking_date_err = "This date is already booked by another customer. Please choose another date.";
        }
        // Check if date is blocked by professional
        elseif(in_array($selected_date_formatted, $blocked_dates)){
            $booking_date_err = "This date is not available as the professional has marked it as busy. Please choose another date.";
        } else {
            $booking_date = $selected_date;
        }
    }
    
    // Validate booking time
    if(empty(trim($_POST["booking_time"]))){
        $booking_time_err = "Please select a time.";
    } else {
        $selected_time = trim($_POST["booking_time"]);
        
        // Check if booking is for today and time is in the past
        if(!empty($booking_date) && $booking_date == date('Y-m-d')){
            $current_time = date('H:i');
            if($selected_time < $current_time){
                $booking_time_err = "You cannot select a time that has already passed for today. Please choose a future time.";
            } else {
                $booking_time = $selected_time;
            }
        } else {
            $booking_time = $selected_time;
        }
    }
    
    // Validate address
    if(empty(trim($_POST["address"]))){
        $address_err = "Please enter your address.";
    } else {
        $address = trim($_POST["address"]);
        
        // Check if address is in coordinate format and convert to readable address
        $coordinate_pattern = '/^(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)$/';
        if (preg_match($coordinate_pattern, $address, $matches)) {
            $lat = floatval($matches[1]);
            $lng = floatval($matches[2]);
            
            // Use Nominatim API to convert coordinates to address
            $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=" . $lat . "&lon=" . $lng . "&zoom=18&addressdetails=1";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "ArtisanLink/1.0");
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['display_name'])) {
                    $address = $data['display_name'];
                }
            }
        }
    }
    
    // Validate phone
    if(empty(trim($_POST["phone"]))){
        $phone_err = "Please enter your phone number.";
    } else {
        $phone = trim($_POST["phone"]);
        // Basic phone validation - only numbers, 10-11 digits for Philippine numbers
        if (!preg_match('/^[0-9]{10,11}$/', $phone)) {
            $phone_err = "Please enter a valid 10-11 digit Philippine phone number.";
        }
    }
    
    // Get notes
    $notes = trim($_POST["notes"]);
    
    // Check input errors before inserting in database
    if(empty($booking_date_err) && empty($booking_time_err) && empty($address_err) && empty($phone_err)){
        // Combine date and time
        $booking_datetime = $booking_date . ' ' . $booking_time . ':00';
        
        // Prepare an insert statement
        $sql = "INSERT INTO bookings (customer_id, professional_id, service_id, booking_date, address, notes, total_price, customer_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
         
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "iiissdds", $_SESSION["id"], $service['professional_id'], $service_id, $booking_datetime, $address, $notes, $service['price'], $phone);
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                $booking_id = mysqli_insert_id($link); // Get the ID of the inserted booking
                
                // Create notification for professional
                $notification_sql = "INSERT INTO notifications (user_id, message, type, related_id) VALUES (?, ?, 'booking', ?)";
                $notification_msg = "New booking request from " . $_SESSION["username"] . " for " . $service['title'] . " on " . date('M j, Y', strtotime($booking_date)) . " at " . $booking_time;
                
                if($notif_stmt = mysqli_prepare($link, $notification_sql)){
                    mysqli_stmt_bind_param($notif_stmt, "isi", $service['professional_id'], $notification_msg, $booking_id);
                    mysqli_stmt_execute($notif_stmt);
                    mysqli_stmt_close($notif_stmt);
                }
                
                // Redirect to booking confirmation page
                $_SESSION["booking_success"] = true;
                header("location: booking-confirmation.php");
                exit;
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }

            // Close statement
            mysqli_stmt_close($stmt);
        }
    }
    
    // Close connection
    mysqli_close($link);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Service - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Leaflet Routing Machine CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <style>
        .service-header {
            background-color: #f8f9fa;
            padding: 30px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .booking-card {
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .booked-date {
            background-color: #ffcccc;
            border-radius: 5px;
            padding: 5px 10px;
            margin: 2px;
            font-size: 0.9em;
        }
        .blocked-date {
            background-color: #ffe6cc;
            border-radius: 5px;
            padding: 5px 10px;
            margin: 2px;
            font-size: 0.9em;
        }
        #location-map {
            height: 400px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .location-permission {
            background-color: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .distance-badge {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9em;
        }
        .route-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .professional-location-section {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .map-placeholder {
            background-color: #e9ecef;
            border-radius: 10px;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        .location-source {
            font-size: 0.8em;
            color: #6c757d;
            font-style: italic;
        }
        .profile-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
        }
        .leaflet-routing-container {
            background: white;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            max-width: 400px;
            max-height: 300px;
            overflow-y: auto;
        }
        .route-summary {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .routing-instructions {
            font-size: 0.9em;
            max-height: 200px;
            overflow-y: auto;
        }
        .address-picker-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .user-location-marker {
            background-color: #0d6efd;
            border: 3px solid white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        .location-status {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .location-active {
            background-color: #d1edff;
            border-left: 4px solid #0d6efd;
        }
        .location-pending {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .address-suggestion {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
            cursor: pointer;
        }
        .address-suggestion:hover {
            background-color: #d1e7ff;
        }
        .phone-input-group {
            position: relative;
        }
        .phone-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 5;
            color: #6c757d;
        }
        .phone-input {
            padding-left: 40px;
        }
        .modal-warning {
            border-left: 5px solid #ffc107;
        }
        .modal-danger {
            border-left: 5px solid #dc3545;
        }
        .time-option-past {
            color: #6c757d !important;
            background-color: #f8f9fa !important;
            text-decoration: line-through;
        }
        .coordinate-conversion {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
            display: none;
        }
        .coordinate-conversion.show {
            display: block;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <!-- Name Required Modal -->
    <div class="modal fade" id="nameRequiredModal" tabindex="-1" aria-labelledby="nameRequiredModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="nameRequiredModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Profile Incomplete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Please complete your profile by adding your full name before booking a service.</p>
                    <p class="text-muted">This helps professionals address you properly and creates a better experience for both parties.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="profile.php" class="btn btn-primary">Update Profile</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Already Booked Modal -->
    <div class="modal fade" id="dateBookedModal" tabindex="-1" aria-labelledby="dateBookedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-danger">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="dateBookedModalLabel"><i class="fas fa-calendar-times me-2"></i>Date Not Available</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-users-slash fa-3x text-danger mb-3"></i>
                        <h5 class="text-danger">This date is already booked</h5>
                    </div>
                    <p>Unfortunately, this date has already been booked by another customer for this professional.</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> Try selecting a different date or contact the professional for alternative arrangements.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Choose Different Date</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Blocked Modal -->
    <div class="modal fade" id="dateBlockedModal" tabindex="-1" aria-labelledby="dateBlockedModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-warning">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="dateBlockedModalLabel"><i class="fas fa-ban me-2"></i>Professional Unavailable</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-user-clock fa-3x text-warning mb-3"></i>
                        <h5 class="text-warning">Professional is Busy</h5>
                    </div>
                    <p>The professional has marked this date as unavailable. They may have personal commitments or other engagements.</p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Professionals can block dates when they're not available for bookings.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Choose Different Date</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Past Date/Time Modal -->
    <div class="modal fade" id="pastDateTimeModal" tabindex="-1" aria-labelledby="pastDateTimeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-danger">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="pastDateTimeModalLabel"><i class="fas fa-clock me-2"></i>Invalid Date/Time</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-history fa-3x text-danger mb-3"></i>
                        <h5 class="text-danger">Cannot Book in the Past</h5>
                    </div>
                    <p id="pastDateTimeMessage">You cannot book services for dates or times that have already passed.</p>
                    <div class="alert alert-info">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Please select a future date and time for your booking.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Select New Date/Time</button>
                </div>
            </div>
        </div>
    </div>

    <div class="service-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="services.php">Services</a></li>
                    <li class="breadcrumb-item"><a href="service-details.php?id=<?php echo $service_id; ?>"><?php echo htmlspecialchars($service['title']); ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Book Service</li>
                </ol>
            </nav>
            
            <h1>Book Service: <?php echo htmlspecialchars($service['title']); ?></h1>
            <p class="lead">with <?php echo htmlspecialchars($service['full_name'] ?? $service['username']); ?></p>
        </div>
    </div>

    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8">
                <div class="card booking-card">
                    <div class="card-body">
                        <h4 class="card-title">Booking Details</h4>
                        
                        <!-- Professional Location Section -->
                        <div class="professional-location-section">
                            <h5><i class="fas fa-map-marker-alt text-danger me-2"></i>Professional Location</h5>
                            <?php if (!empty($service['latitude']) && !empty($service['longitude'])): ?>
                                <div id="location-map">
                                    <!-- Map will be initialized here -->
                                </div>
                                <div class="mt-2">
                                    <?php if (!empty($service['professional_address'])): ?>
                                        <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($service['professional_address']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($service['city']) || !empty($service['state'])): ?>
                                        <p class="mb-1"><strong>Area:</strong> 
                                            <?php 
                                            if (!empty($service['city']) && !empty($service['state'])) {
                                                echo htmlspecialchars($service['city'] . ', ' . $service['state']);
                                            } elseif (!empty($service['municipality']) && !empty($service['barangay'])) {
                                                echo htmlspecialchars($service['municipality'] . ', ' . $service['barangay']);
                                            } else {
                                                echo 'Location information available';
                                            }
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                    <small class="text-muted">Coordinates: <?php echo $service['latitude']; ?>, <?php echo $service['longitude']; ?></small>
                                    <?php if (!empty($service['municipality'])): ?>
                                        <br><small class="location-source">Location from professional registration</small>
                                    <?php else: ?>
                                        <br><small class="location-source">Location from user profile</small>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="map-placeholder">
                                    <div class="text-center">
                                        <i class="fas fa-map-marker-alt fa-3x mb-3"></i>
                                        <p>Professional location not available</p>
                                        <small class="text-muted">The professional hasn't set their location yet.</small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Location Status -->
                        <div id="location-status" class="location-status location-pending d-none">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-info-circle me-2 text-warning"></i>
                                <div>
                                    <strong>Set Your Service Location</strong>
                                    <p class="mb-0">Click on the map or use your current location to set where the service should be performed</p>
                                </div>
                            </div>
                        </div>

                        <!-- Address Picker Section -->
                        <div class="address-picker-section">
                            <h5><i class="fas fa-crosshairs text-primary me-2"></i>Set Your Service Location</h5>
                            <p class="mb-2">Choose where you want the service to be performed:</p>
                            <div class="location-buttons mb-2">
                                <button type="button" id="use-current-location" class="btn btn-primary btn-sm me-2">
                                    <i class="fas fa-location-arrow me-1"></i>Use My Current Location
                                </button>
                                <button type="button" id="clear-address" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times me-1"></i>Clear Location
                                </button>
                            </div>
                            <div class="alert alert-info py-2">
                                <i class="fas fa-info-circle me-2"></i>
                                Click anywhere on the map above to set your service location, or use your current location. The address will be automatically filled.
                            </div>
                        </div>

                        <!-- Coordinate Conversion Notice -->
                        <div id="coordinate-conversion" class="coordinate-conversion">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-sync-alt me-2 text-primary"></i>
                                <div>
                                    <strong>Converting coordinates to address...</strong>
                                    <p class="mb-0" id="conversion-status">Detected coordinate format, converting to readable address</p>
                                </div>
                            </div>
                        </div>

                        <!-- Route Information -->
                        <?php if (!empty($service['latitude']) && !empty($service['longitude'])): ?>
                        <div id="route-info" class="route-info d-none">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-route me-2 text-primary"></i> Route Information</h6>
                                    <div id="distance-text" class="mb-2">Set your location to see distance</div>
                                    <div id="travel-time" class="mb-2">Set your location to see travel time</div>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-user-tie me-2 text-primary"></i> Professional</h6>
                                    <p class="mb-1"><?php echo htmlspecialchars($service['full_name'] ?? $service['username']); ?></p>
                                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($service['profession']); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?service_id=<?php echo $service_id; ?>" method="post" id="bookingForm">
                            <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Service</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($service['title']); ?>" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Price</label>
                                    <input type="text" class="form-control" value="₱<?php echo number_format($service['price'], 2); ?>" disabled>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Date *</label>
                                    <input type="date" name="booking_date" id="booking_date" class="form-control <?php echo (!empty($booking_date_err)) ? 'is-invalid' : ''; ?>" 
                                           min="<?php echo date('Y-m-d'); ?>" required
                                           value="<?php echo htmlspecialchars($booking_date); ?>">
                                    <span class="invalid-feedback"><?php echo $booking_date_err; ?></span>
                                    <div class="form-text">Professional is not available on: 
                                        <?php if (!empty($unavailable_dates)) : ?>
                                            <?php 
                                            $recent_dates = array_slice($unavailable_dates, 0, 3);
                                            foreach($recent_dates as $date): 
                                                $is_blocked = in_array($date, $blocked_dates);
                                            ?>
                                                <span class="<?php echo $is_blocked ? 'blocked-date' : 'booked-date'; ?>">
                                                    <?php echo date('M j', strtotime($date)); ?>
                                                    <?php if ($is_blocked) echo '*'; ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($unavailable_dates) > 3) : ?>
                                                <span>and <?php echo count($unavailable_dates) - 3; ?> more</span>
                                            <?php endif; ?>
                                            <?php if (!empty($blocked_dates)): ?>
                                                <br><small class="text-muted">* = Professional marked as busy</small>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span>No dates unavailable</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Time *</label>
                                    <select name="booking_time" id="booking_time" class="form-select <?php echo (!empty($booking_time_err)) ? 'is-invalid' : ''; ?>" required>
                                        <option value="">Select Time</option>
                                        <?php for ($hour = 8; $hour <= 18; $hour++): ?>
                                            <?php for ($minute = 0; $minute < 60; $minute += 30): ?>
                                                <?php $time = sprintf('%02d:%02d', $hour, $minute); ?>
                                                <option value="<?php echo $time; ?>" <?php echo ($booking_time == $time) ? 'selected' : ''; ?>>
                                                    <?php echo date('g:i A', strtotime($time)); ?>
                                                </option>
                                            <?php endfor; ?>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="invalid-feedback"><?php echo $booking_time_err; ?></span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Your Address *</label>
                                <textarea name="address" id="address" class="form-control <?php echo (!empty($address_err)) ? 'is-invalid' : ''; ?>" rows="3" required placeholder="Where should the professional come to provide the service?"><?php echo htmlspecialchars($address); ?></textarea>
                                <span class="invalid-feedback"><?php echo $address_err; ?></span>
                                <div class="form-text">Click on the map above or use "Use My Current Location" to automatically fill your address</div>
                                
                                <!-- Address Suggestions Container -->
                                <div id="address-suggestions" class="mt-2"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Your Phone Number *</label>
                                <div class="phone-input-group">
                                    <span class="phone-prefix">+63</span>
                                    <input type="tel" name="phone" class="form-control phone-input <?php echo (!empty($phone_err)) ? 'is-invalid' : ''; ?>" 
                                           value="<?php echo htmlspecialchars($phone ?: $user_phone); ?>" 
                                           placeholder="" required
                                           pattern="[0-9]{10,11}" 
                                           title="Please enter a valid 10-11 digit Philippine phone number">
                                </div>
                                <span class="invalid-feedback"><?php echo $phone_err; ?></span>
                                <div class="form-text">Enter your 10-11 digit Philippine phone number (e.g., 9123456789)</div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Additional Notes (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions or details about the service"><?php echo htmlspecialchars($notes); ?></textarea>
                                <div class="form-text">Include any specific requirements or details the professional should know</div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBooking">Confirm Booking</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Professional Information</h5>
                        <div class="d-flex align-items-center mb-3">
                            <?php if (!empty($service['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($service['profile_picture']); ?>" class="profile-image me-3" alt="Profile Picture">
                            <?php else: ?>
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;">
                                    <i class="fas fa-user-tie fa-2x"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h6 class="mb-0"><?php echo htmlspecialchars($service['full_name'] ?? $service['username']); ?></h6>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($service['profession']); ?></p>
                            </div>
                        </div>
                        
                        <div class="rating mb-3">
                            <i class="fas fa-star text-warning"></i>
                            <span><?php echo number_format($service['rating'], 1); ?></span>
                            <small class="text-muted">(<?php echo $service['total_ratings']; ?> reviews)</small>
                        </div>

                        <!-- Professional Contact -->
                        <?php if (!empty($service['professional_phone'])): ?>
                        <div class="mb-3">
                            <p class="mb-1"><strong>Professional's Phone:</strong></p>
                            <p class="mb-0">
                                <i class="fas fa-phone text-success me-1"></i>
                                <?php echo htmlspecialchars($service['professional_phone']); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Professional Location -->
                        <?php if (!empty($service['latitude']) && !empty($service['longitude'])): ?>
                        <div class="mb-3">
                            <p class="mb-1"><strong>Location:</strong></p>
                            <p class="mb-0">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?php 
                                if (!empty($service['city']) && !empty($service['state'])) {
                                    echo htmlspecialchars($service['city'] . ', ' . $service['state']);
                                } elseif (!empty($service['municipality']) && !empty($service['barangay'])) {
                                    echo htmlspecialchars($service['municipality'] . ', ' . $service['barangay']);
                                } else {
                                    echo 'Location available';
                                }
                                ?>
                            </p>
                            <?php if (!empty($service['professional_address'])): ?>
                            <p class="text-muted small mb-0"><?php echo htmlspecialchars($service['professional_address']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            The professional will respond to your booking request within 24 hours. If no response, the booking will be automatically cancelled.
                        </div>

                        <div class="alert alert-warning">
                            <i class="fas fa-clock me-2"></i>
                            Please ensure your phone number is correct. The professional will contact you to confirm details.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Leaflet Routing Machine JS -->
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script>
        let map = null;
        let professionalMarker = null;
        let userMarker = null;
        let routingControl = null;
        let userLocation = null;

        // Professional location from database
        const professionalLocation = {
            lat: <?php echo !empty($service['latitude']) ? $service['latitude'] : '0'; ?>,
            lng: <?php echo !empty($service['longitude']) ? $service['longitude'] : '0'; ?>,
            address: '<?php echo !empty($service['professional_address']) ? addslashes($service['professional_address']) : 'Professional Location'; ?>',
            name: '<?php echo addslashes($service['full_name'] ?? $service['username']); ?>',
            area: '<?php 
                if (!empty($service['city']) && !empty($service['state'])) {
                    echo addslashes($service['city'] . ', ' . $service['state']);
                } elseif (!empty($service['municipality']) && !empty($service['barangay'])) {
                    echo addslashes($service['municipality'] . ', ' . $service['barangay']);
                } else {
                    echo 'Professional Location';
                }
            ?>'
        };

        // Unavailable dates from PHP
        const bookedDates = <?php echo json_encode($booked_dates); ?>;
        const blockedDates = <?php echo json_encode($blocked_dates); ?>;
        const unavailableDates = <?php echo json_encode($unavailable_dates); ?>;

        // Function to convert coordinates to readable address
        function convertCoordinatesToAddress(lat, lng) {
            return new Promise((resolve, reject) => {
                const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;
                
                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.display_name) {
                            resolve(data.display_name);
                        } else {
                            reject(new Error('No address found for coordinates'));
                        }
                    })
                    .catch(error => {
                        reject(error);
                    });
            });
        }

        // Function to check if text is coordinates and convert if needed
        function checkAndConvertCoordinates(text) {
            const coordRegex = /^(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)$/;
            const match = text.match(coordRegex);
            
            if (match) {
                const lat = parseFloat(match[1]);
                const lng = parseFloat(match[2]);
                
                // Show conversion notice
                const conversionDiv = document.getElementById('coordinate-conversion');
                const conversionStatus = document.getElementById('conversion-status');
                conversionDiv.classList.add('show');
                conversionStatus.textContent = 'Converting coordinates to readable address...';
                
                // Convert coordinates to address
                convertCoordinatesToAddress(lat, lng)
                    .then(address => {
                        document.getElementById('address').value = address;
                        conversionStatus.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Successfully converted to address</span>`;
                        
                        // Hide conversion notice after 3 seconds
                        setTimeout(() => {
                            conversionDiv.classList.remove('show');
                        }, 3000);
                    })
                    .catch(error => {
                        console.error('Error converting coordinates:', error);
                        conversionStatus.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Could not convert coordinates. Using original format.</span>`;
                        
                        // Hide conversion notice after 5 seconds
                        setTimeout(() => {
                            conversionDiv.classList.remove('show');
                        }, 5000);
                    });
                
                return true;
            }
            return false;
        }

        // Initialize professional map
        function initProfessionalMap() {
            if (professionalLocation.lat === 0 || professionalLocation.lng === 0) {
                console.log('No valid coordinates available');
                return; // No valid coordinates
            }

            // Create map centered on professional location
            map = L.map('location-map').setView([professionalLocation.lat, professionalLocation.lng], 13);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add professional marker
            professionalMarker = L.marker([professionalLocation.lat, professionalLocation.lng]).addTo(map);
            professionalMarker.bindPopup(`
                <div class="text-center">
                    <strong>${professionalLocation.name}</strong><br>
                    <em>Professional Location</em><br>
                    <small>${professionalLocation.area}</small>
                </div>
            `).openPopup();

            // Add click event to map for setting user location
            map.on('click', function(e) {
                setUserLocation(e.latlng.lat, e.latlng.lng);
                reverseGeocode(e.latlng.lat, e.latlng.lng);
            });

            console.log('Map initialized with professional location:', professionalLocation.lat, professionalLocation.lng);
        }

        // Function to reverse geocode coordinates and fill address
        function reverseGeocode(lat, lng) {
            console.log('Reverse geocoding for:', lat, lng);
            
            // Show loading state
            const suggestionsContainer = document.getElementById('address-suggestions');
            suggestionsContainer.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm me-2"></div>Loading address...</div>';
            
            // Use OpenStreetMap Nominatim for geocoding
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Full geocoding response:', data);
                    
                    const suggestionsContainer = document.getElementById('address-suggestions');
                    suggestionsContainer.innerHTML = '';
                    
                    if (data && data.address) {
                        const addr = data.address;
                        
                        // Build different address formats
                        const addressFormats = [];
                        
                        // Format 1: Complete address with house number, street, barangay, city
                        if (addr.house_number || addr.road) {
                            let format1 = '';
                            if (addr.house_number) format1 += addr.house_number + ' ';
                            if (addr.road) format1 += addr.road;
                            if (addr.neighbourhood || addr.suburb || addr.city || addr.town) {
                                format1 += ', ';
                                if (addr.neighbourhood) format1 += addr.neighbourhood;
                                else if (addr.suburb) format1 += addr.suburb;
                                else if (addr.city) format1 += addr.city;
                                else if (addr.town) format1 += addr.town;
                            }
                            if (addr.city || addr.town || addr.municipality || addr.state) {
                                format1 += ', ';
                                if (addr.city) format1 += addr.city;
                                else if (addr.town) format1 += addr.town;
                                else if (addr.municipality) format1 += addr.municipality;
                                else if (addr.state) format1 += addr.state;
                            }
                            if (addr.postcode) format1 += ', ' + addr.postcode;
                            addressFormats.push(format1);
                        }
                        
                        // Format 2: Street, Barangay, City format (common in Philippines)
                        let format2 = '';
                        if (addr.road) format2 += addr.road + ', ';
                        if (addr.neighbourhood) format2 += addr.neighbourhood + ', ';
                        else if (addr.suburb) format2 += addr.suburb + ', ';
                        if (addr.city) format2 += addr.city;
                        else if (addr.town) format2 += addr.town;
                        else if (addr.municipality) format2 += addr.municipality;
                        if (addr.state && addr.state !== (addr.city || addr.town || addr.municipality)) {
                            format2 += ', ' + addr.state;
                        }
                        if (format2) addressFormats.push(format2);
                        
                        // Format 3: Simple location description
                        let format3 = '';
                        if (addr.neighbourhood || addr.suburb) {
                            format3 += (addr.neighbourhood || addr.suburb) + ', ';
                        }
                        if (addr.city || addr.town || addr.municipality) {
                            format3 += (addr.city || addr.town || addr.municipality);
                        }
                        if (format3) addressFormats.push(format3);
                        
                        // Format 4: Full display name (fallback)
                        if (data.display_name && addressFormats.length === 0) {
                            addressFormats.push(data.display_name);
                        }
                        
                        // Remove duplicates and empty formats
                        const uniqueFormats = [...new Set(addressFormats.filter(format => format && format.trim()))];
                        
                        // Display suggestions
                        if (uniqueFormats.length > 0) {
                            suggestionsContainer.innerHTML = '<p class="small text-muted mb-2">Select an address format:</p>';
                            
                            uniqueFormats.forEach((format, index) => {
                                const suggestionDiv = document.createElement('div');
                                suggestionDiv.className = 'address-suggestion mb-2';
                                suggestionDiv.innerHTML = `
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                            ${format}
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAddress('${format.replace(/'/g, "\\'")}')">
                                            Use This
                                        </button>
                                    </div>
                                `;
                                suggestionsContainer.appendChild(suggestionDiv);
                                
                                // Auto-select the first suggestion
                                if (index === 0) {
                                    setTimeout(() => {
                                        selectAddress(format);
                                    }, 100);
                                }
                            });
                        } else {
                            // Fallback to coordinates
                            const fallbackAddress = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                            document.getElementById('address').value = fallbackAddress;
                            suggestionsContainer.innerHTML = `<div class="alert alert-warning py-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Using coordinates as address: ${fallbackAddress}
                            </div>`;
                        }
                    } else {
                        // No address data found
                        const fallbackAddress = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                        document.getElementById('address').value = fallbackAddress;
                        suggestionsContainer.innerHTML = `<div class="alert alert-warning py-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No address found. Using coordinates: ${fallbackAddress}
                        </div>`;
                    }
                })
                .catch(error => {
                    console.error('Error with reverse geocoding:', error);
                    const suggestionsContainer = document.getElementById('address-suggestions');
                    const fallbackAddress = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                    document.getElementById('address').value = fallbackAddress;
                    suggestionsContainer.innerHTML = `<div class="alert alert-danger py-2">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error loading address. Using coordinates: ${fallbackAddress}
                    </div>`;
                });
        }

        // Function to select an address from suggestions
        function selectAddress(address) {
            document.getElementById('address').value = address;
            const suggestionsContainer = document.getElementById('address-suggestions');
            suggestionsContainer.innerHTML = `<div class="alert alert-success py-2">
                <i class="fas fa-check-circle me-2"></i>
                Address selected successfully
            </div>`;
            
            // Clear the success message after 2 seconds
            setTimeout(() => {
                suggestionsContainer.innerHTML = '';
            }, 2000);
        }

        // Function to set user location on map
        function setUserLocation(lat, lng) {
            userLocation = { lat: lat, lng: lng };
            
            // Remove existing user marker if any
            if (userMarker) {
                map.removeLayer(userMarker);
            }
            
            // Add user marker with custom blue icon
            userMarker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: 'user-location-marker',
                    html: '<div style="background-color: #0d6efd; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
                    iconSize: [20, 20],
                    iconAnchor: [10, 10]
                })
            }).addTo(map);
            
            userMarker.bindPopup(`
                <div class="text-center">
                    <strong>Your Service Location</strong><br>
                    <em>Click elsewhere on map to change</em><br>
                    <small>Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}</small>
                </div>
            `).openPopup();

            // Update location status
            updateLocationStatus(true);

            // Update route if professional location is available
            if (professionalLocation.lat !== 0 && professionalLocation.lng !== 0) {
                updateRoute(lat, lng);
            }

            console.log('User location set:', lat, lng);
        }

        // Function to update location status display
        function updateLocationStatus(isActive) {
            const locationStatus = document.getElementById('location-status');
            if (isActive) {
                locationStatus.classList.remove('location-pending', 'd-none');
                locationStatus.classList.add('location-active');
                locationStatus.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle me-2 text-success"></i>
                        <div>
                            <strong>Service Location Set</strong>
                            <p class="mb-0">Your service location has been set. Click anywhere else on the map to change it.</p>
                        </div>
                    </div>
                `;
            } else {
                locationStatus.classList.remove('location-active');
                locationStatus.classList.add('location-pending');
                locationStatus.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="fas fa-info-circle me-2 text-warning"></i>
                        <div>
                            <strong>Set Your Service Location</strong>
                            <p class="mb-0">Click on the map or use your current location to set where the service should be performed</p>
                        </div>
                    </div>
                `;
            }
            locationStatus.classList.remove('d-none');
        }

        // Function to request user location and show route
        function requestLocation() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser');
                return;
            }

            // Show loading state
            document.getElementById('route-info').classList.remove('d-none');
            document.getElementById('distance-text').innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Detecting your location...';
            document.getElementById('travel-time').innerHTML = '';

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success callback
                    const userLat = position.coords.latitude;
                    const userLng = position.coords.longitude;
                    
                    // Set user location on map
                    setUserLocation(userLat, userLng);
                    
                    // Reverse geocode to get address
                    reverseGeocode(userLat, userLng);
                    
                    // Center map on user location
                    map.setView([userLat, userLng], 15);
                    
                },
                function(error) {
                    // Error callback
                    let errorMessage = 'Unable to retrieve your location';
                    
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage = 'Location access denied. Please enable location services in your browser settings.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage = 'Location information is unavailable.';
                            break;
                        case error.TIMEOUT:
                            errorMessage = 'Location request timed out.';
                            break;
                    }
                    
                    document.getElementById('distance-text').innerHTML = `<span class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>${errorMessage}</span>`;
                    document.getElementById('travel-time').innerHTML = '';
                    
                    // Show error in location status
                    const locationStatus = document.getElementById('location-status');
                    locationStatus.classList.remove('location-pending', 'location-active');
                    locationStatus.classList.add('location-pending');
                    locationStatus.innerHTML = `
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
                            <div>
                                <strong>Location Error</strong>
                                <p class="mb-0">${errorMessage}</p>
                            </div>
                        </div>
                    `;
                    locationStatus.classList.remove('d-none');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 60000
                }
            );
        }

        // Update map with user location and routing
        function updateRoute(userLat, userLng) {
            if (!map) {
                console.log('Map not initialized yet');
                return;
            }

            // Remove existing routing if any
            if (routingControl) {
                map.removeControl(routingControl);
            }

            // Create routing control
            routingControl = L.Routing.control({
                waypoints: [
                    L.latLng(professionalLocation.lat, professionalLocation.lng),
                    L.latLng(userLat, userLng)
                ],
                routeWhileDragging: false,
                showAlternatives: false,
                lineOptions: {
                    styles: [
                        {color: '#0d6efd', opacity: 0.8, weight: 6},
                        {color: 'white', opacity: 0.8, weight: 3, dashArray: '10, 10'}
                    ]
                },
                createMarker: function() { return null; }, // Don't create default markers
                show: true,
                collapsible: true,
                fitSelectedRoutes: 'smart',
                router: L.Routing.osrmv1({
                    serviceUrl: 'https://router.project-osrm.org/route/v1'
                })
            }).addTo(map);
            
            // Show route information when route is found
            routingControl.on('routesfound', function(e) {
                const routes = e.routes;
                const summary = routes[0].summary;
                
                // Calculate distance in appropriate units
                let distanceText;
                if (summary.totalDistance < 1000) {
                    distanceText = `Distance: ${Math.round(summary.totalDistance)} meters`;
                } else {
                    distanceText = `Distance: ${(summary.totalDistance / 1000).toFixed(1)} km`;
                }
                
                // Calculate travel time
                const hours = Math.floor(summary.totalTime / 3600);
                const minutes = Math.round((summary.totalTime % 3600) / 60);
                let timeText;
                if (hours > 0) {
                    timeText = `Travel time: ${hours}h ${minutes}m`;
                } else {
                    timeText = `Travel time: ${minutes} minutes`;
                }
                
                // Update route info display
                document.getElementById('distance-text').innerHTML = `<i class="fas fa-route me-2 text-success"></i> ${distanceText}`;
                document.getElementById('travel-time').innerHTML = `<i class="fas fa-clock me-2 text-success"></i> ${timeText}`;
            });
            
            // Handle routing errors
            routingControl.on('routingerror', function(e) {
                console.error('Routing error:', e.error);
                // Fallback to straight line if routing fails
                document.getElementById('distance-text').innerHTML = `<i class="fas fa-route me-2 text-warning"></i> Routing service unavailable`;
                document.getElementById('travel-time').innerHTML = `<i class="fas fa-clock me-2 text-warning"></i> Could not calculate travel time`;
                
                // Draw straight line as fallback
                L.polyline([
                    [professionalLocation.lat, professionalLocation.lng],
                    [userLat, userLng]
                ], {
                    color: '#6f42c1',
                    weight: 3,
                    dashArray: '5, 10'
                }).addTo(map);
            });
            
            // Fit map to show the entire route
            const bounds = L.latLngBounds([userLat, userLng], [professionalLocation.lat, professionalLocation.lng]);
            map.fitBounds(bounds, { padding: [50, 50] });

            console.log('Routing added for user location:', userLat, userLng);
        }

        // Clear address function
        function clearAddress() {
            document.getElementById('address').value = '';
            document.getElementById('address-suggestions').innerHTML = '';
            if (userMarker) {
                map.removeLayer(userMarker);
                userMarker = null;
            }
            if (routingControl) {
                map.removeControl(routingControl);
                routingControl = null;
            }
            document.getElementById('route-info').classList.add('d-none');
            updateLocationStatus(false);
            userLocation = null;
        }

        // Check date availability
        function checkDateAvailability(date) {
            const selectedDate = new Date(date).toISOString().split('T')[0];
            const today = new Date().toISOString().split('T')[0];
            
            // Check if date is in the past
            if (selectedDate < today) {
                document.getElementById('pastDateTimeMessage').textContent = 'You cannot book services for dates that have already passed.';
                const pastModal = new bootstrap.Modal(document.getElementById('pastDateTimeModal'));
                pastModal.show();
                document.getElementById('booking_date').value = '';
                updateTimeOptions('');
                return false;
            }
            
            // Check if date is booked
            if (bookedDates.includes(selectedDate)) {
                const bookedModal = new bootstrap.Modal(document.getElementById('dateBookedModal'));
                bookedModal.show();
                document.getElementById('booking_date').value = '';
                updateTimeOptions('');
                return false;
            }
            
            // Check if date is blocked
            if (blockedDates.includes(selectedDate)) {
                const blockedModal = new bootstrap.Modal(document.getElementById('dateBlockedModal'));
                blockedModal.show();
                document.getElementById('booking_date').value = '';
                updateTimeOptions('');
                return false;
            }
            
            // Update time options based on selected date
            updateTimeOptions(selectedDate);
            return true;
        }

        // Update time options based on selected date
        function updateTimeOptions(selectedDate) {
            const timeSelect = document.getElementById('booking_time');
            const today = new Date().toISOString().split('T')[0];
            const currentTime = new Date();
            const currentHour = currentTime.getHours();
            const currentMinute = currentTime.getMinutes();
            
            // Reset all options first
            for (let i = 0; i < timeSelect.options.length; i++) {
                const option = timeSelect.options[i];
                option.disabled = false;
                option.classList.remove('time-option-past');
                
                if (option.value === '') continue;
                
                // If selected date is today, disable past times
                if (selectedDate === today) {
                    const [hour, minute] = option.value.split(':').map(Number);
                    
                    if (hour < currentHour || (hour === currentHour && minute < currentMinute)) {
                        option.disabled = true;
                        option.classList.add('time-option-past');
                    }
                }
            }
            
            // If no time is selected and it's today, select the next available time
            if (selectedDate === today && timeSelect.value === '') {
                for (let i = 0; i < timeSelect.options.length; i++) {
                    const option = timeSelect.options[i];
                    if (!option.disabled && option.value !== '') {
                        timeSelect.value = option.value;
                        break;
                    }
                }
            }
        }

        // Validate time selection
        function validateTimeSelection() {
            const selectedDate = document.getElementById('booking_date').value;
            const selectedTime = document.getElementById('booking_time').value;
            const today = new Date().toISOString().split('T')[0];
            
            if (!selectedDate || !selectedTime) return true;
            
            // Check if selected date is today and time is in the past
            if (selectedDate === today) {
                const currentTime = new Date();
                const currentHour = currentTime.getHours();
                const currentMinute = currentTime.getMinutes();
                const [selectedHour, selectedMinute] = selectedTime.split(':').map(Number);
                
                if (selectedHour < currentHour || (selectedHour === currentHour && selectedMinute < currentMinute)) {
                    document.getElementById('pastDateTimeMessage').textContent = 'You cannot select a time that has already passed for today. Please choose a future time.';
                    const pastModal = new bootstrap.Modal(document.getElementById('pastDateTimeModal'));
                    pastModal.show();
                    document.getElementById('booking_time').value = '';
                    updateTimeOptions(selectedDate);
                    return false;
                }
            }
            
            return true;
        }

        // Initialize professional map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            if (professionalLocation.lat !== 0 && professionalLocation.lng !== 0) {
                initProfessionalMap();
                // Show location status on load
                updateLocationStatus(false);
            } else {
                console.log('No valid professional location coordinates');
            }

            // Add event listeners
            document.getElementById('use-current-location').addEventListener('click', requestLocation);
            document.getElementById('clear-address').addEventListener('click', clearAddress);
            
            // Date and time validation
            document.getElementById('booking_date').addEventListener('change', function() {
                checkDateAvailability(this.value);
            });
            
            document.getElementById('booking_time').addEventListener('change', function() {
                validateTimeSelection();
            });

            // Check for coordinate format in address field on blur
            document.getElementById('address').addEventListener('blur', function() {
                const addressValue = this.value.trim();
                if (addressValue) {
                    checkAndConvertCoordinates(addressValue);
                }
            });

            // Also check when form is submitted
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                const addressValue = document.getElementById('address').value.trim();
                if (addressValue) {
                    // If it's coordinates, we need to wait for conversion
                    if (checkAndConvertCoordinates(addressValue)) {
                        e.preventDefault();
                        // Wait a bit for conversion then submit again
                        setTimeout(() => {
                            document.getElementById('bookingForm').submit();
                        }, 1000);
                        return false;
                    }
                }
            });

            // Initialize time options based on today's date
            updateTimeOptions(new Date().toISOString().split('T')[0]);

            // Show name modal if user doesn't have full name
            <?php if ($show_name_modal): ?>
            var nameModal = new bootstrap.Modal(document.getElementById('nameRequiredModal'), {
                backdrop: 'static',
                keyboard: false
            });
            nameModal.show();
            <?php endif; ?>

            // Prevent form submission if user doesn't have full name
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                <?php if ($show_name_modal): ?>
                e.preventDefault();
                var nameModal = new bootstrap.Modal(document.getElementById('nameRequiredModal'), {
                    backdrop: 'static',
                    keyboard: false
                });
                nameModal.show();
                <?php endif; ?>
                
                // Validate date and time before submission
                const selectedDate = document.getElementById('booking_date').value;
                const selectedTime = document.getElementById('booking_time').value;
                
                if (!checkDateAvailability(selectedDate) || !validateTimeSelection()) {
                    e.preventDefault();
                }
            });
        });

        // Set minimum date to today
        document.querySelector('input[name="booking_date"]').min = new Date().toISOString().split('T')[0];
        
        // Format phone number input - only allow numbers
        document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) {
                value = value.substring(0, 11);
            }
            e.target.value = value;
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>