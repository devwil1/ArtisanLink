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

$reported_user_id = trim($_GET['id']);

// Prevent users from reporting themselves
if(isset($_SESSION["id"]) && $_SESSION["id"] == $reported_user_id) {
    $_SESSION["error"] = "You cannot report your own profile.";
    header("location: profile-view.php?id=" . $reported_user_id);
    exit;
}

// Get reported user details
$reported_user = [];
$sql = "SELECT id, username, full_name, profile_picture, user_type FROM users WHERE id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $reported_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $reported_user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    // Check if user exists
    if(!$reported_user) {
        $_SESSION["error"] = "User not found.";
        header("location: services.php");
        exit;
    }
}

// Initialize variables
$report_type = "profile";
$reason = "";
$description = "";
$description_err = "";
$reason_err = "";
$image_err = "";
$submit_err = "";

// Upload directory for report images
$upload_dir = "uploads/reports/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate reason
    if (empty(trim($_POST["reason"]))) {
        $reason_err = "Please select a reason for your report.";
    } else {
        $reason = trim($_POST["reason"]);
    }
    
    // Validate description
    if (empty(trim($_POST["description"]))) {
        $description_err = "Please describe the reason for your report.";
    } else if (strlen(trim($_POST["description"])) < 10) {
        $description_err = "Please provide more details (at least 10 characters).";
    } else {
        $description = trim($_POST["description"]);
    }
    
    $report_type = $_POST["report_type"];
    
    // Handle image upload
    $image_path = null;
    $image_thumb = null;
    
    if(isset($_FILES["report_image"]) && $_FILES["report_image"]["error"] == UPLOAD_ERR_OK) {
        $file_name = basename($_FILES["report_image"]["name"]);
        $file_tmp = $_FILES["report_image"]["tmp_name"];
        $file_size = $_FILES["report_image"]["size"];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file types
        $allowed_ext = ["jpg", "jpeg", "png", "gif", "webp"];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        // Validate file
        if(!in_array($file_ext, $allowed_ext)) {
            $image_err = "Only JPG, JPEG, PNG, GIF & WEBP files are allowed.";
        } elseif($file_size > $max_size) {
            $image_err = "File size must be less than 5MB.";
        } else {
            // Generate unique filename
            $new_filename = "report_" . $reported_user_id . "_" . time() . "." . $file_ext;
            $target_file = $upload_dir . $new_filename;
            
            // Create thumbnail
            $thumb_file = $upload_dir . "thumb_" . $new_filename;
            
            if(move_uploaded_file($file_tmp, $target_file)) {
                $image_path = $target_file;
                
                // Create thumbnail (150x150)
                if(create_thumbnail($target_file, $thumb_file, 150, 150)) {
                    $image_thumb = $thumb_file;
                } else {
                    $image_thumb = $target_file; // Use original if thumbnail fails
                }
            } else {
                $image_err = "Sorry, there was an error uploading your file.";
            }
        }
    } elseif($_FILES["report_image"]["error"] == UPLOAD_ERR_INI_SIZE || 
             $_FILES["report_image"]["error"] == UPLOAD_ERR_FORM_SIZE) {
        $image_err = "File size exceeds limit (5MB).";
    }
    
    // Check input errors before inserting in database
    if (empty($description_err) && empty($reason_err) && empty($image_err)) {
        // Check if user has already reported this profile recently (prevent spam)
        $check_sql = "SELECT id FROM reports WHERE reporter_id = ? AND reported_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        if ($check_stmt = mysqli_prepare($link, $check_sql)) {
            mysqli_stmt_bind_param($check_stmt, "ii", $_SESSION["id"], $reported_user_id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            
            if(mysqli_stmt_num_rows($check_stmt) > 0) {
                $submit_err = "You have already reported this profile recently. Please wait 24 hours before submitting another report.";
            } else {
                mysqli_stmt_close($check_stmt);
                
                // Prepare an insert statement
                if($image_path) {
                    $sql = "INSERT INTO reports (reporter_id, reported_user_id, report_type, reason, description, report_image, report_image_thumb) VALUES (?, ?, ?, ?, ?, ?, ?)";
                } else {
                    $sql = "INSERT INTO reports (reporter_id, reported_user_id, report_type, reason, description) VALUES (?, ?, ?, ?, ?)";
                }
                 
                if ($stmt = mysqli_prepare($link, $sql)) {
                    if($image_path) {
                        mysqli_stmt_bind_param($stmt, "iisssss", $_SESSION["id"], $reported_user_id, $report_type, $reason, $description, $image_path, $image_thumb);
                    } else {
                        mysqli_stmt_bind_param($stmt, "iisss", $_SESSION["id"], $reported_user_id, $report_type, $reason, $description);
                    }
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION["report_success"] = "Thank you for your report. We will review it shortly.";
                        header("location: profile-view.php?id=" . $reported_user_id);
                        exit;
                    } else {
                        $submit_err = "Oops! Something went wrong. Please try again later.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

// Function to create thumbnail
function create_thumbnail($source, $destination, $width, $height) {
    $info = getimagesize($source);
    if(!$info) return false;
    
    list($orig_width, $orig_height, $type) = $info;
    
    switch($type) {
        case IMAGETYPE_JPEG:
            $source_image = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $source_image = imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $source_image = imagecreatefromgif($source);
            break;
        case IMAGETYPE_WEBP:
            $source_image = imagecreatefromwebp($source);
            break;
        default:
            return false;
    }
    
    // Calculate aspect ratio
    $aspect_ratio = $orig_width / $orig_height;
    if ($width / $height > $aspect_ratio) {
        $new_width = $height * $aspect_ratio;
        $new_height = $height;
    } else {
        $new_height = $width / $aspect_ratio;
        $new_width = $width;
    }
    
    // Create thumbnail
    $thumb = imagecreatetruecolor($width, $height);
    
    // Preserve transparency for PNG and GIF
    if($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
        imagefilledrectangle($thumb, 0, 0, $width, $height, $transparent);
    }
    
    // Resize image
    $dst_x = ($width - $new_width) / 2;
    $dst_y = ($height - $new_height) / 2;
    imagecopyresampled($thumb, $source_image, $dst_x, $dst_y, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
    
    // Save thumbnail
    switch($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($thumb, $destination, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($thumb, $destination, 9);
            break;
        case IMAGETYPE_GIF:
            imagegif($thumb, $destination);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($thumb, $destination, 85);
            break;
    }
    
    imagedestroy($source_image);
    imagedestroy($thumb);
    
    return file_exists($destination);
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Profile - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --danger: #e74c3c;
            --danger-dark: #c0392b;
        }
        
        .report-header {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            color: white;
        }
        
        .user-info-card {
            border-left: 4px solid var(--danger);
            background: linear-gradient(135deg, #fff5f5, #ffeaea);
        }
        
        .btn-report {
            background: linear-gradient(135deg, var(--danger), var(--danger-dark));
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-report:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
        }
        
        .image-preview-container {
            border: 2px dashed #ddd;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .image-preview-container:hover {
            border-color: var(--primary);
            background: #f0f7ff;
        }
        
        .image-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            display: none;
        }
        
        .preview-actions {
            margin-top: 10px;
            display: none;
        }
        
        .dropzone {
            border: 2px dashed #007bff !important;
            border-radius: 10px !important;
            background: #f8f9fa !important;
            min-height: 150px !important;
        }
        
        .dropzone .dz-message {
            font-size: 1.1em;
            color: #6c757d;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 2px;
            background: #e9ecef;
            z-index: 1;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }
        
        .step.active {
            background: var(--danger);
            color: white;
            box-shadow: 0 4px 10px rgba(231, 76, 60, 0.3);
        }
        
        .step.completed {
            background: #28a745;
            color: white;
        }
        
        .form-section {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .form-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .reason-option {
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .reason-option:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }
        
        .reason-option.selected {
            border-color: var(--danger);
            background: #ffeaea;
        }
        
        .floating-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.5s ease;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .character-counter {
            font-size: 0.85em;
            color: #6c757d;
            text-align: right;
            margin-top: 5px;
        }
        
        .character-counter.warning {
            color: #ffc107;
        }
        
        .character-counter.danger {
            color: var(--danger);
        }
        
        .required-star {
            color: var(--danger);
        }
        
        .evidence-note {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .user-avatar-lg {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--danger);
        }
        
        .file-upload-area {
            position: relative;
            overflow: hidden;
        }
        
        .file-upload-area input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" data-step="1">1</div>
                    <div class="step" data-step="2">2</div>
                    <div class="step" data-step="3">3</div>
                </div>

                <div class="card shadow-lg border-0">
                    <div class="card-header report-header py-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-flag me-3 fs-2"></i>
                            <div>
                                <h4 class="mb-1 fw-bold">Report Profile</h4>
                                <p class="mb-0 opacity-90">Help us maintain a safe and trustworthy community</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-4 p-md-5">
                        <?php if(!empty($submit_err)): ?>
                            <div class="alert alert-danger d-flex align-items-center" role="alert">
                                <i class="fas fa-exclamation-triangle me-3 fs-4"></i>
                                <div><?php echo $submit_err; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- User Being Reported -->
                        <div class="card user-info-card mb-4 border-0">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center">
                                    <?php if(!empty($reported_user['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($reported_user['profile_picture']); ?>" 
                                             alt="Profile" class="user-avatar-lg me-4">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-4" 
                                             style="width: 80px; height: 80px;">
                                            <i class="fas fa-user text-white fs-3"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2 fw-bold">
                                            <?php 
                                            // Display the correct user name
                                            if (!empty($reported_user['full_name'])) {
                                                echo htmlspecialchars($reported_user['full_name']);
                                            } else if (!empty($reported_user['username'])) {
                                                echo htmlspecialchars($reported_user['username']);
                                            } else {
                                                echo "User #" . htmlspecialchars($reported_user['id']);
                                            }
                                            ?>
                                        </h5>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="badge bg-primary px-3 py-2">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo ucfirst($reported_user['user_type']); ?>
                                            </span>
                                            <span class="badge bg-secondary px-3 py-2">
                                                <i class="fas fa-hashtag me-1"></i>
                                                ID: <?php echo htmlspecialchars($reported_user['id']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <a href="profile-view.php?id=<?php echo $reported_user_id; ?>" 
                                           class="btn btn-outline-primary">
                                            <i class="fas fa-external-link-alt me-2"></i>View Profile
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="post" enctype="multipart/form-data" novalidate id="reportForm">
                            <input type="hidden" name="report_type" value="profile">
                            
                            <!-- Step 1: Reason Selection -->
                            <div class="form-section active" id="step1">
                                <h5 class="mb-4 fw-bold text-dark">Step 1: Select Reason for Report <span class="required-star">*</span></h5>
                                
                                <div class="mb-4">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="suspicious">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-user-secret fa-2x text-warning"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Suspicious Activity</h6>
                                                        <p class="mb-0 text-muted small">Fake identity, scam attempts, or suspicious behavior</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="fake">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-ghost fa-2x text-danger"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Fake Profile</h6>
                                                        <p class="mb-0 text-muted small">Profile appears to be fake or impersonating someone</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="behavior">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-ban fa-2x text-danger"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Inappropriate Behavior</h6>
                                                        <p class="mb-0 text-muted small">Harassment, threats, or offensive behavior</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="quality">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-star-half-alt fa-2x text-warning"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Poor Service Quality</h6>
                                                        <p class="mb-0 text-muted small">Substandard work or professional misconduct</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="spam">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-bullhorn fa-2x text-info"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Spam or Advertising</h6>
                                                        <p class="mb-0 text-muted small">Unsolicited advertising or promotional content</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="reason-option" data-reason="other">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <i class="fas fa-ellipsis-h fa-2x text-secondary"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-bold">Other Issue</h6>
                                                        <p class="mb-0 text-muted small">Any other issue not listed above</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" name="reason" id="selectedReason" value="<?php echo htmlspecialchars($reason); ?>">
                                    <span class="text-danger"><?php echo $reason_err; ?></span>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-5">
                                    <button type="button" class="btn btn-outline-secondary px-4" disabled>
                                        <i class="fas fa-arrow-left me-2"></i>Previous
                                    </button>
                                    <button type="button" class="btn btn-primary px-4" onclick="nextStep(2)">
                                        Next <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Step 2: Description & Evidence -->
                            <div class="form-section" id="step2">
                                <h5 class="mb-4 fw-bold text-dark">Step 2: Provide Details & Evidence</h5>
                                
                                <!-- Description -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Describe the issue in detail <span class="required-star">*</span></label>
                                    <textarea name="description" class="form-control <?php echo (!empty($description_err)) ? 'is-invalid' : ''; ?>" 
                                              rows="6" placeholder="Please describe what happened, when it happened, and any other relevant details..." 
                                              required id="descriptionTextarea"><?php echo htmlspecialchars($description); ?></textarea>
                                    <div class="character-counter" id="charCounter">0/1000 characters</div>
                                    <span class="invalid-feedback"><?php echo $description_err; ?></span>
                                    <div class="form-text mt-2">
                                        <i class="fas fa-lightbulb text-warning me-1"></i>
                                        Provide specific details including dates, conversations, and any other relevant information.
                                    </div>
                                </div>
                                
                                <!-- Image Upload -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Upload Evidence (Optional but recommended)</label>
                                    <p class="text-muted small mb-3">Upload screenshots or images that support your report (Max: 5MB, JPG, PNG, GIF, WEBP)</p>
                                    
                                    <div class="image-preview-container mb-3" id="imagePreviewContainer">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                        <h5 class="mb-2">Upload Evidence Image</h5>
                                        <p class="text-muted small mb-4">Click to browse or drag & drop your image here</p>
                                        <input type="file" name="report_image" id="reportImage" accept="image/*" class="form-control">
                                        
                                        <img src="#" alt="Preview" class="image-preview mb-3" id="imagePreview">
                                        <div class="preview-actions" id="previewActions">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeImage()">
                                                <i class="fas fa-trash me-1"></i>Remove
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <?php if(!empty($image_err)): ?>
                                        <div class="alert alert-danger py-2">
                                            <i class="fas fa-exclamation-circle me-2"></i>
                                            <?php echo $image_err; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="evidence-note">
                                        <h6 class="fw-bold mb-2"><i class="fas fa-shield-alt me-2"></i>Why upload evidence?</h6>
                                        <p class="mb-2 small">Providing evidence helps our admin team review your report more effectively.</p>
                                        <ul class="small mb-0">
                                            <li>Include screenshots of conversations</li>
                                            <li>Show photos of poor work quality</li>
                                            <li>Evidence of fake credentials or information</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-5">
                                    <button type="button" class="btn btn-outline-secondary px-4" onclick="prevStep(1)">
                                        <i class="fas fa-arrow-left me-2"></i>Previous
                                    </button>
                                    <button type="button" class="btn btn-primary px-4" onclick="nextStep(3)">
                                        Next <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Step 3: Review & Submit -->
                            <div class="form-section" id="step3">
                                <h5 class="mb-4 fw-bold text-dark">Step 3: Review & Submit Report</h5>
                                
                                <div class="card border-0 bg-light mb-4">
                                    <div class="card-body">
                                        <h6 class="fw-bold mb-3"><i class="fas fa-file-alt me-2"></i>Report Summary</h6>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <small class="text-muted d-block">Reported User</small>
                                                <strong id="reviewUserName">
                                                    <?php 
                                                    if (!empty($reported_user['full_name'])) {
                                                        echo htmlspecialchars($reported_user['full_name']);
                                                    } else if (!empty($reported_user['username'])) {
                                                        echo htmlspecialchars($reported_user['username']);
                                                    } else {
                                                        echo "User #" . htmlspecialchars($reported_user['id']);
                                                    }
                                                    ?>
                                                </strong>
                                            </div>
                                            <div class="col-md-6">
                                                <small class="text-muted d-block">Reason</small>
                                                <strong id="reviewReason">Not selected</strong>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted d-block mb-1">Description Preview</small>
                                            <div class="bg-white p-3 rounded border" id="reviewDescription">
                                                <em class="text-muted">No description provided</em>
                                            </div>
                                        </div>
                                        
                                        <div id="reviewImageSection">
                                            <small class="text-muted d-block mb-2">Evidence</small>
                                            <div class="bg-white p-3 rounded border text-center" id="reviewImage">
                                                <i class="fas fa-image text-muted fa-2x mb-2"></i>
                                                <p class="mb-0 small text-muted">No image uploaded</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <div class="d-flex">
                                        <i class="fas fa-exclamation-triangle fa-2x me-3 mt-1"></i>
                                        <div>
                                            <h6 class="fw-bold mb-2">Important Notice</h6>
                                            <p class="mb-2">By submitting this report, you agree that:</p>
                                            <ul class="mb-0 small">
                                                <li>You are reporting a genuine violation of our terms</li>
                                                <li>False reports may result in account restrictions</li>
                                                <li>Our admin team will review your report within 24-48 hours</li>
                                                <li>All reports are confidential</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-check mb-4">
                                    <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                                    <label class="form-check-label" for="agreeTerms">
                                        I confirm that the information provided is accurate to the best of my knowledge
                                    </label>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-5">
                                    <button type="button" class="btn btn-outline-secondary px-4" onclick="prevStep(2)">
                                        <i class="fas fa-arrow-left me-2"></i>Previous
                                    </button>
                                    <button type="submit" class="btn btn-report px-5" id="submitBtn">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Report
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
    <script>
        // Step navigation
        let currentStep = 1;
        
        function nextStep(step) {
            if(step === 2 && !validateStep1()) return;
            if(step === 3 && !validateStep2()) return;
            
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelector(`#step${step}`).classList.add('active');
            
            document.querySelectorAll('.step').forEach(stepEl => {
                stepEl.classList.remove('active');
                if(parseInt(stepEl.dataset.step) < step) {
                    stepEl.classList.add('completed');
                } else if(parseInt(stepEl.dataset.step) === step) {
                    stepEl.classList.add('active');
                }
            });
            
            if(step === 3) {
                updateReview();
            }
            
            currentStep = step;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function prevStep(step) {
            nextStep(step);
        }
        
        // Step 1 Validation
        function validateStep1() {
            const reason = document.getElementById('selectedReason').value;
            if(!reason) {
                alert('Please select a reason for your report.');
                return false;
            }
            return true;
        }
        
        // Step 2 Validation
        function validateStep2() {
            const description = document.getElementById('descriptionTextarea').value.trim();
            if(!description || description.length < 10) {
                alert('Please provide a detailed description (at least 10 characters).');
                document.getElementById('descriptionTextarea').focus();
                return false;
            }
            return true;
        }
        
        // Reason selection
        document.querySelectorAll('.reason-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.reason-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                document.getElementById('selectedReason').value = this.dataset.reason;
            });
        });
        
        // Character counter
        const textarea = document.getElementById('descriptionTextarea');
        const charCounter = document.getElementById('charCounter');
        
        textarea.addEventListener('input', function() {
            const length = this.value.length;
            charCounter.textContent = `${length}/1000 characters`;
            
            if(length > 950) {
                charCounter.classList.add('warning');
                charCounter.classList.remove('danger');
            } else if(length > 990) {
                charCounter.classList.remove('warning');
                charCounter.classList.add('danger');
            } else {
                charCounter.classList.remove('warning', 'danger');
            }
        });
        
        // Image preview
        const imageInput = document.getElementById('reportImage');
        const imagePreview = document.getElementById('imagePreview');
        const previewActions = document.getElementById('previewActions');
        const previewContainer = document.getElementById('imagePreviewContainer');
        
        imageInput.addEventListener('change', function() {
            if(this.files && this.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreview.style.display = 'block';
                    previewActions.style.display = 'block';
                    previewContainer.style.borderColor = '#28a745';
                    previewContainer.style.background = '#f0fff4';
                }
                
                reader.readAsDataURL(this.files[0]);
            }
        });
        
        function removeImage() {
            imageInput.value = '';
            imagePreview.style.display = 'none';
            previewActions.style.display = 'none';
            previewContainer.style.borderColor = '#ddd';
            previewContainer.style.background = '#f8f9fa';
        }
        
        // Update review section
        function updateReview() {
            // Update reason
            const reason = document.getElementById('selectedReason').value;
            const reasonMap = {
                'suspicious': 'Suspicious Activity',
                'fake': 'Fake Profile',
                'behavior': 'Inappropriate Behavior',
                'quality': 'Poor Service Quality',
                'spam': 'Spam or Advertising',
                'other': 'Other Issue'
            };
            document.getElementById('reviewReason').textContent = reasonMap[reason] || 'Not selected';
            
            // Update description
            const description = document.getElementById('descriptionTextarea').value;
            if(description) {
                const preview = description.length > 200 ? description.substring(0, 200) + '...' : description;
                document.getElementById('reviewDescription').innerHTML = `<p class="mb-0">${preview.replace(/\n/g, '<br>')}</p>`;
            }
            
            // Update image
            if(imageInput.files && imageInput.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('reviewImage').innerHTML = `
                        <img src="${e.target.result}" alt="Evidence" class="img-fluid rounded" style="max-height: 200px;">
                        <p class="mt-2 mb-0 small text-muted">${imageInput.files[0].name}</p>
                    `;
                };
                reader.readAsDataURL(imageInput.files[0]);
            }
        }
        
        // Form submission
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            if(!validateStep1() || !validateStep2()) {
                e.preventDefault();
                nextStep(1);
                return;
            }
            
            if(!document.getElementById('agreeTerms').checked) {
                e.preventDefault();
                alert('Please confirm that the information provided is accurate.');
                return;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
            submitBtn.disabled = true;
        });
        
        // Initialize with selected reason if exists
        <?php if($reason): ?>
            document.querySelector(`.reason-option[data-reason="<?php echo $reason; ?>"]`).classList.add('selected');
        <?php endif; ?>
        
        // Initialize character counter
        textarea.dispatchEvent(new Event('input'));
    </script>
</body>
</html>