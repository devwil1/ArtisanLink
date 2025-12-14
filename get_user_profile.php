<?php
session_start();
require_once "config.php";

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || 
   ($_SESSION["user_type"] !== 'admin' && $_SESSION["user_type"] !== 'super_admin')){
    die("Unauthorized access");
}

if(isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
    
    // Get user basic info
    $user_sql = "SELECT * FROM users WHERE id = ?";
    $user_stmt = mysqli_prepare($link, $user_sql);
    mysqli_stmt_bind_param($user_stmt, "i", $user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user = mysqli_fetch_assoc($user_result);
    
    if($user) {
        echo '<div class="row">';
        echo '<div class="col-md-4 text-center">';
        
        // Profile picture
        if(!empty($user['profile_picture'])) {
            echo '<img src="' . htmlspecialchars($user['profile_picture']) . '" class="profile-img-lg mb-3">';
        } else {
            echo '<div class="bg-light rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px;">';
            echo '<i class="fas fa-user fa-3x text-muted"></i>';
            echo '</div>';
        }
        
        echo '<h4>' . htmlspecialchars($user['full_name'] ?? $user['username']) . '</h4>';
        echo '<p class="text-muted">@' . htmlspecialchars($user['username']) . '</p>';
        
        // User type badge
        $badge_color = 'secondary';
        switch($user['user_type']) {
            case 'admin': $badge_color = 'primary'; break;
            case 'super_admin': $badge_color = 'danger'; break;
            case 'professional': $badge_color = 'success'; break;
        }
        echo '<span class="badge bg-' . $badge_color . ' mb-3">' . ucfirst(str_replace('_', ' ', $user['user_type'])) . '</span>';
        
        echo '</div>';
        echo '<div class="col-md-8">';
        
        // Basic Information
        echo '<div class="detail-section">';
        echo '<h5><i class="fas fa-info-circle me-2"></i>Basic Information</h5>';
        echo '<div class="row">';
        echo '<div class="col-md-6"><strong>Email:</strong><br>' . htmlspecialchars($user['email']) . '</div>';
        echo '<div class="col-md-6"><strong>User Type:</strong><br>' . ucfirst(str_replace('_', ' ', $user['user_type'])) . '</div>';
        echo '</div>';
        if($user['birthdate']) {
            echo '<div class="row mt-2">';
            echo '<div class="col-md-6"><strong>Birthdate:</strong><br>' . htmlspecialchars($user['birthdate']) . '</div>';
            echo '</div>';
        }
        if($user['municipality']) {
            echo '<div class="row mt-2">';
            echo '<div class="col-md-6"><strong>Location:</strong><br>' . htmlspecialchars($user['municipality']) . ', ' . htmlspecialchars($user['barangay']) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        
        // Professional Information (if professional)
        if($user['user_type'] === 'professional') {
            $prof_sql = "SELECT * FROM professional_requests WHERE user_id = ? AND status = 'approved'";
            $prof_stmt = mysqli_prepare($link, $prof_sql);
            mysqli_stmt_bind_param($prof_stmt, "i", $user_id);
            mysqli_stmt_execute($prof_stmt);
            $prof_result = mysqli_stmt_get_result($prof_stmt);
            $professional = mysqli_fetch_assoc($prof_result);
            
            if($professional) {
                echo '<div class="detail-section">';
                echo '<h5><i class="fas fa-briefcase me-2"></i>Professional Information</h5>';
                echo '<div class="row">';
                echo '<div class="col-md-6"><strong>Profession:</strong><br>' . htmlspecialchars($professional['profession']) . '</div>';
                echo '<div class="col-md-6"><strong>Age:</strong><br>' . ($professional['age'] ?: 'N/A') . '</div>';
                echo '</div>';
                echo '<div class="row mt-2">';
                echo '<div class="col-md-6"><strong>Phone:</strong><br>' . htmlspecialchars($professional['phone']) . '</div>';
                echo '<div class="col-md-6"><strong>Pricing Type:</strong><br>' . ucfirst(str_replace('_', ' ', $professional['pricing_type'])) . '</div>';
                echo '</div>';
                echo '<div class="row mt-2">';
                echo '<div class="col-12"><strong>Address:</strong><br>' . htmlspecialchars($professional['address']) . '</div>';
                echo '</div>';
                
                // Skills
                if(!empty($professional['skills'])) {
                    echo '<div class="row mt-2">';
                    echo '<div class="col-12"><strong>Skills:</strong><br>' . htmlspecialchars($professional['skills']) . '</div>';
                    echo '</div>';
                }
                
                // Experience
                if(!empty($professional['experience'])) {
                    echo '<div class="row mt-2">';
                    echo '<div class="col-12"><strong>Experience:</strong><br>' . nl2br(htmlspecialchars($professional['experience'])) . '</div>';
                    echo '</div>';
                }
                
                // Portfolio URL
                if(!empty($professional['portfolio_url'])) {
                    echo '<div class="row mt-2">';
                    echo '<div class="col-12"><strong>Portfolio:</strong><br><a href="' . htmlspecialchars($professional['portfolio_url']) . '" target="_blank">' . htmlspecialchars($professional['portfolio_url']) . '</a></div>';
                    echo '</div>';
                }
                echo '</div>';
                
                // Get professional services
                $services_sql = "SELECT * FROM services WHERE professional_id = ?";
                $services_stmt = mysqli_prepare($link, $services_sql);
                mysqli_stmt_bind_param($services_stmt, "i", $user_id);
                mysqli_stmt_execute($services_stmt);
                $services_result = mysqli_stmt_get_result($services_stmt);
                $services = mysqli_fetch_all($services_result, MYSQLI_ASSOC);
                
                if($services) {
                    echo '<div class="detail-section">';
                    echo '<h5><i class="fas fa-concierge-bell me-2"></i>Services Offered</h5>';
                    foreach($services as $service) {
                        echo '<div class="card mb-2">';
                        echo '<div class="card-body py-2">';
                        echo '<h6 class="card-title mb-1">' . htmlspecialchars($service['title']) . '</h6>';
                        echo '<p class="card-text mb-1">' . htmlspecialchars($service['description']) . '</p>';
                        echo '<p class="card-text mb-0">Price: ₱' . number_format($service['price'], 2) . '</p>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                
                // Get portfolio images
                $portfolio_sql = "SELECT * FROM portfolio_images WHERE professional_id = ?";
                $portfolio_stmt = mysqli_prepare($link, $portfolio_sql);
                mysqli_stmt_bind_param($portfolio_stmt, "i", $user_id);
                mysqli_stmt_execute($portfolio_stmt);
                $portfolio_result = mysqli_stmt_get_result($portfolio_stmt);
                $portfolio_images = mysqli_fetch_all($portfolio_result, MYSQLI_ASSOC);
                
                if($portfolio_images) {
                    echo '<div class="detail-section">';
                    echo '<h5><i class="fas fa-images me-2"></i>Portfolio</h5>';
                    echo '<div class="row">';
                    foreach($portfolio_images as $image) {
                        echo '<div class="col-md-4 portfolio-item">';
                        echo '<img src="' . htmlspecialchars($image['image_path']) . '" class="portfolio-img" data-bs-toggle="modal" data-bs-target="#imageModal" data-image="' . htmlspecialchars($image['image_path']) . '" data-caption="' . htmlspecialchars($image['caption'] ?? '') . '">';
                        if($image['caption']) {
                            echo '<p class="small text-muted mt-1">' . htmlspecialchars($image['caption']) . '</p>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
                
                // Get certifications
                $certs_sql = "SELECT * FROM professional_certifications WHERE professional_id = ?";
                $certs_stmt = mysqli_prepare($link, $certs_sql);
                mysqli_stmt_bind_param($certs_stmt, "i", $user_id);
                mysqli_stmt_execute($certs_stmt);
                $certs_result = mysqli_stmt_get_result($certs_stmt);
                $certifications = mysqli_fetch_all($certs_result, MYSQLI_ASSOC);
                
                if($certifications) {
                    echo '<div class="detail-section">';
                    echo '<h5><i class="fas fa-certificate me-2"></i>Certifications</h5>';
                    foreach($certifications as $cert) {
                        echo '<div class="card mb-2">';
                        echo '<div class="card-body">';
                        echo '<h6 class="card-title">' . htmlspecialchars($cert['certificate_name']) . '</h6>';
                        echo '<p class="card-text mb-1">Issued by: ' . htmlspecialchars($cert['issuing_organization']) . '</p>';
                        if($cert['issue_date']) {
                            echo '<p class="card-text mb-1">Issued: ' . $cert['issue_date'] . '</p>';
                        }
                        if($cert['expiry_date']) {
                            echo '<p class="card-text mb-1">Expires: ' . $cert['expiry_date'] . '</p>';
                        }
                        if(!empty($cert['certificate_image'])) {
                            echo '<img src="' . htmlspecialchars($cert['certificate_image']) . '" class="certificate-img mt-2">';
                        }
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
            }
        }
        
        echo '</div>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-danger">User not found</div>';
    }
    
    mysqli_close($link);
} else {
    echo '<div class="alert alert-danger">User ID not provided</div>';
}
?>