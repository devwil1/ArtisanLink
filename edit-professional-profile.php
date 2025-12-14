<?php
// Initialize the session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and is a professional
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] !== 'professional'){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Initialize variables
$business_name = $years_experience = $license_number = $insurance_info = $service_area = "";
$specialization = $certifications = $languages = $equipment_provided = "";
$emergency_service = $free_consultation = 0;
$travel_fee = 0;
$errors = [];

// Get current professional data
$professional_data = [];
$business_sql = "SELECT * FROM professional_requests WHERE user_id = ?";
$profile_sql = "SELECT * FROM professional_profile WHERE professional_id = ?";

if ($stmt = mysqli_prepare($link, $business_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $professional_data = mysqli_fetch_assoc($result) ?: [];
    mysqli_stmt_close($stmt);
    
    // Populate business fields
    $business_name = $professional_data['business_name'] ?? '';
    $years_experience = $professional_data['years_experience'] ?? '';
    $license_number = $professional_data['license_number'] ?? '';
    $insurance_info = $professional_data['insurance_info'] ?? '';
    $service_area = $professional_data['service_area'] ?? '';
}

if ($stmt = mysqli_prepare($link, $profile_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $profile_data = mysqli_fetch_assoc($result) ?: [];
    mysqli_stmt_close($stmt);
    
    // Populate profile fields
    $specialization = $profile_data['specialization'] ?? '';
    $certifications = $profile_data['certifications'] ?? '';
    $languages = $profile_data['languages'] ?? '';
    $equipment_provided = $profile_data['equipment_provided'] ?? '';
    $emergency_service = $profile_data['emergency_service'] ?? 0;
    $free_consultation = $profile_data['free_consultation'] ?? 0;
    $travel_fee = $profile_data['travel_fee'] ?? 0;
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate and sanitize input
    $business_name = trim($_POST['business_name'] ?? '');
    $years_experience = trim($_POST['years_experience'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $insurance_info = trim($_POST['insurance_info'] ?? '');
    $service_area = trim($_POST['service_area'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $certifications = trim($_POST['certifications'] ?? '');
    $languages = trim($_POST['languages'] ?? '');
    $equipment_provided = trim($_POST['equipment_provided'] ?? '');
    $emergency_service = isset($_POST['emergency_service']) ? 1 : 0;
    $free_consultation = isset($_POST['free_consultation']) ? 1 : 0;
    $travel_fee = floatval($_POST['travel_fee'] ?? 0);
    
    // Update professional_requests table
    $update_business_sql = "UPDATE professional_requests 
                           SET business_name = ?, years_experience = ?, license_number = ?, 
                               insurance_info = ?, service_area = ?
                           WHERE user_id = ?";
    
    if ($stmt = mysqli_prepare($link, $update_business_sql)) {
        mysqli_stmt_bind_param($stmt, "sisssi", $business_name, $years_experience, $license_number, 
                              $insurance_info, $service_area, $_SESSION["id"]);
        
        if (!mysqli_stmt_execute($stmt)) {
            $errors[] = "Error updating business information.";
        }
        mysqli_stmt_close($stmt);
    }
    
    // Update or insert professional_profile
    if (empty($profile_data)) {
        $insert_profile_sql = "INSERT INTO professional_profile 
                              (professional_id, specialization, certifications, languages, 
                               emergency_service, free_consultation, equipment_provided, travel_fee)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        if ($stmt = mysqli_prepare($link, $insert_profile_sql)) {
            mysqli_stmt_bind_param($stmt, "isssiisd", $_SESSION["id"], $specialization, $certifications, 
                                  $languages, $emergency_service, $free_consultation, $equipment_provided, $travel_fee);
            
            if (!mysqli_stmt_execute($stmt)) {
                $errors[] = "Error creating professional profile.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $update_profile_sql = "UPDATE professional_profile 
                              SET specialization = ?, certifications = ?, languages = ?, 
                                  emergency_service = ?, free_consultation = ?, 
                                  equipment_provided = ?, travel_fee = ?
                              WHERE professional_id = ?";
        
        if ($stmt = mysqli_prepare($link, $update_profile_sql)) {
            mysqli_stmt_bind_param($stmt, "sssiisdi", $specialization, $certifications, $languages, 
                                  $emergency_service, $free_consultation, $equipment_provided, $travel_fee, $_SESSION["id"]);
            
            if (!mysqli_stmt_execute($stmt)) {
                $errors[] = "Error updating professional profile.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    if (empty($errors)) {
        $_SESSION['profile_update'] = "Professional profile updated successfully!";
        header("location: profile.php");
        exit;
    }
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Professional Profile - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Edit Professional Profile</h1>
            <a href="profile.php" class="btn btn-outline-secondary">Back to Profile</a>
        </div>

        <?php if (!empty($errors)) : ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="needs-validation" novalidate>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Business Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="business_name" class="form-control" value="<?php echo htmlspecialchars($business_name); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Years of Experience</label>
                            <input type="number" name="years_experience" class="form-control" value="<?php echo htmlspecialchars($years_experience); ?>" min="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">License Number (if applicable)</label>
                            <input type="text" name="license_number" class="form-control" value="<?php echo htmlspecialchars($license_number); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Area</label>
                            <input type="text" name="service_area" class="form-control" value="<?php echo htmlspecialchars($service_area); ?>" placeholder="e.g., Metro Manila, Quezon City">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Insurance Information</label>
                        <textarea name="insurance_info" class="form-control" rows="3"><?php echo htmlspecialchars($insurance_info); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Professional Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Specialization</label>
                            <textarea name="specialization" class="form-control" rows="3" placeholder="List your areas of expertise"><?php echo htmlspecialchars($specialization); ?></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Certifications</label>
                            <textarea name="certifications" class="form-control" rows="3" placeholder="List your certifications and qualifications"><?php echo htmlspecialchars($certifications); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Languages Spoken</label>
                            <input type="text" name="languages" class="form-control" value="<?php echo htmlspecialchars($languages); ?>" placeholder="e.g., English, Tagalog, Spanish">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Equipment Provided</label>
                            <textarea name="equipment_provided" class="form-control" rows="2" placeholder="List equipment you provide"><?php echo htmlspecialchars($equipment_provided); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Service Features</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="emergency_service" id="emergency_service" <?php echo $emergency_service ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="emergency_service">Emergency Service Available</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="free_consultation" id="free_consultation" <?php echo $free_consultation ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="free_consultation">Free Consultation</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Travel Fee (₱)</label>
                            <input type="number" name="travel_fee" class="form-control" value="<?php echo htmlspecialchars($travel_fee); ?>" step="0.01" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="profile.php" class="btn btn-secondary me-md-2">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>