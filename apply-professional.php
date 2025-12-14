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

// Check if user is already a professional
if ($_SESSION["user_type"] == "professional" || $_SESSION["user_type"] == "admin" || $_SESSION["user_type"] == "super_admin") {
    header("location: welcome.php");
    exit;
}

// Include config file
require_once "config.php";

// Function to capitalize first letter of each word
function capitalizeWords($string) {
    return ucwords(strtolower($string));
}

// Function to extract municipality from address
function extractMunicipality($address) {
    $municipalities = ['Baler', 'San Luis', 'Dipaculao', 'Maria Aurora'];
    $address_lower = strtolower($address);
    
    foreach ($municipalities as $municipality) {
        if (strpos($address_lower, strtolower($municipality)) !== false) {
            return $municipality;
        }
    }
    return '';
}

// Function to extract barangay from address (simplified approach)
function extractBarangay($address) {
    // This is a simplified approach - you might need to adjust based on your address format
    $address_parts = explode(',', $address);
    if (count($address_parts) > 1) {
        return trim($address_parts[0]);
    }
    return '';
}

// Define variables and initialize with empty values
$first_name = $middle_name = $last_name = "";
$full_name = $phone = $address = $profession = $skills = $description = "";
$municipality = $barangay = $latitude = $longitude = "";
$age = $pricing_type = "";
$first_name_err = $middle_name_err = $last_name_err = "";
$phone_err = $address_err = $profession_err = $skills_err = $description_err = "";
$municipality_err = $barangay_err = $location_err = $age_err = $pricing_type_err = "";
$profile_picture_err = $certificate_err = $work_sample_err = "";
$contact_methods = array(
    array('type' => 'phone', 'value' => '', 'primary' => true)
);
$certifications = array();
$services = array(array('name' => '', 'price' => ''));

// Check if user is reapplying after rejection
$reapplying = isset($_GET['reapply']) && $_GET['reapply'] == 'true';

// Check if user already has a pending or approved application
$has_pending_application = false;
$has_rejected_application = false;
$current_application_id = null;
$current_application_status = '';

$sql = "SELECT id, status FROM professional_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $param_user_id);
    $param_user_id = $_SESSION["id"];
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_store_result($stmt);
        
        if (mysqli_stmt_num_rows($stmt) == 1) {
            mysqli_stmt_bind_result($stmt, $app_id, $app_status);
            mysqli_stmt_fetch($stmt);
            
            $current_application_id = $app_id;
            $current_application_status = $app_status;
            
            // Check application status
            if ($app_status == 'pending') {
                $has_pending_application = true;
                // If already has a pending application, redirect to status page
                header("location: application-status.php");
                exit;
            } elseif ($app_status == 'approved') {
                // User is already approved - shouldn't be here due to user_type check above
                header("location: welcome.php");
                exit;
            } elseif ($app_status == 'rejected') {
                $has_rejected_application = true;
                
                // If not reapplying, redirect to status page
                if (!$reapplying) {
                    header("location: application-status.php");
                    exit;
                }
                // If reapplying, allow them to continue
            }
        }
    }
    mysqli_stmt_close($stmt);
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate first name
    if (empty(trim($_POST["first_name"]))) {
        $first_name_err = "Please enter your first name.";
    } else {
        $first_name = capitalizeWords(trim($_POST["first_name"]));
    }
    
    // Validate last name
    if (empty(trim($_POST["last_name"]))) {
        $last_name_err = "Please enter your last name.";
    } else {
        $last_name = capitalizeWords(trim($_POST["last_name"]));
    }
    
    // Validate middle name (optional)
    if (!empty(trim($_POST["middle_name"]))) {
        $middle_name = capitalizeWords(trim($_POST["middle_name"]));
    }
    
    // Combine names to create full name
    $full_name = trim($last_name . ", " . $first_name . (!empty($middle_name) ? " " . $middle_name : ""));
    
    // Validate phone
    $primary_phone = "";
    if (!empty($_POST["contact_methods"])) {
        foreach ($_POST["contact_methods"] as $contact) {
            if ($contact["type"] == "phone" && !empty($contact["value"]) && empty($primary_phone)) {
                $primary_phone = trim($contact["value"]);
                // Basic phone validation
                if (!preg_match("/^[0-9+() -]{10,20}$/", $primary_phone)) {
                    $phone_err = "Please enter a valid phone number.";
                }
                break;
            }
        }
    }
    
    if (empty($primary_phone) && empty($phone_err)) {
        $phone_err = "Please enter at least one phone number.";
    } else {
        $phone = $primary_phone;
    }
    
    // Validate address
    if (empty(trim($_POST["address"]))) {
        $address_err = "Please enter your address.";
    } else {
        $address = capitalizeWords(trim($_POST["address"]));
        
        // Extract municipality and barangay from address
        $extracted_municipality = extractMunicipality($address);
        $extracted_barangay = extractBarangay($address);
        
        if (!empty($extracted_municipality)) {
            $municipality = $extracted_municipality;
        }
        
        if (!empty($extracted_barangay)) {
            $barangay = $extracted_barangay;
        }
    }
    
    // Validate municipality (from hidden field or extracted)
    $submitted_municipality = trim($_POST["municipality"]);
    if (!empty($submitted_municipality)) {
        $municipality = $submitted_municipality;
    }
    
    if (empty($municipality)) {
        $municipality_err = "Please set your location using the map or ensure your address contains your municipality (Baler, San Luis, Dipaculao, or Maria Aurora).";
    }
    
    // Validate barangay (from hidden field or extracted)
    $submitted_barangay = trim($_POST["barangay"]);
    if (!empty($submitted_barangay)) {
        $barangay = $submitted_barangay;
    }
    
    if (empty($barangay)) {
        $barangay_err = "Please set your location using the map or ensure your address contains your barangay.";
    }
    
    // Validate location coordinates
    if (empty(trim($_POST["latitude"])) || empty(trim($_POST["longitude"]))) {
        $location_err = "Please set your location using the map.";
    } else {
        $latitude = trim($_POST["latitude"]);
        $longitude = trim($_POST["longitude"]);
    }
    
    // Validate profession
    if (empty(trim($_POST["profession"]))) {
        $profession_err = "Please enter your profession.";
    } else {
        $profession = capitalizeWords(trim($_POST["profession"]));
    }
    
    // Validate services
    $services = array();
    if (!empty($_POST["services"])) {
        foreach ($_POST["services"] as $service) {
            if (!empty(trim($service["name"])) && !empty(trim($service["price"]))) {
                $services[] = array(
                    'name' => capitalizeWords(trim($service["name"])),
                    'price' => trim($service["price"])
                );
            }
        }
    }
    
    if (count($services) == 0) {
        $skills_err = "Please enter at least one service with price.";
    }
    
    // Validate description
    if (empty(trim($_POST["description"]))) {
        $description_err = "Please describe yourself.";
    } else {
        $description = capitalizeWords(trim($_POST["description"]));
    }
    
    // Validate age
    if (empty(trim($_POST["age"]))) {
        $age_err = "Please enter your age.";
    } else {
        $age = trim($_POST["age"]);
        if (!is_numeric($age) || $age < 18 || $age > 100) {
            $age_err = "Please enter a valid age (18-100).";
        }
    }
    
    // Validate pricing type
    if (empty(trim($_POST["pricing_type"]))) {
        $pricing_type_err = "Please select a pricing type.";
    } else {
        $pricing_type = trim($_POST["pricing_type"]);
    }
    
    // Process contact methods
    $contact_methods = array();
    if (!empty($_POST["contact_methods"])) {
        foreach ($_POST["contact_methods"] as $index => $contact) {
            if (!empty(trim($contact["value"]))) {
                $contact_methods[] = array(
                    'type' => $contact["type"],
                    'value' => trim($contact["value"]),
                    'primary' => isset($contact["primary"]) && $contact["primary"] == "on"
                );
            }
        }
    }
    
    // Process certifications
    $certifications = array();
    if (!empty($_POST["certifications"])) {
        foreach ($_POST["certifications"] as $index => $cert) {
            if (!empty(trim($cert["name"])) && !empty($_FILES["certifications"]["name"][$index]["image"])) {
                $certifications[] = array(
                    'name' => capitalizeWords(trim($cert["name"])),
                    'organization' => !empty($cert["organization"]) ? capitalizeWords(trim($cert["organization"])) : '',
                    'issue_date' => !empty($cert["issue_date"]) ? trim($cert["issue_date"]) : '',
                    'expiry_date' => !empty($cert["expiry_date"]) ? trim($cert["expiry_date"]) : ''
                );
            }
        }
    }
    
    // Handle file uploads
    $profile_picture_path = "";
    $certificate_files = array();
    $work_sample_path = "";
    
    // Profile picture upload
    if (!empty($_FILES["profile_picture"]["name"])) {
        $target_dir = "uploads/profiles/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
        $new_filename = "profile_" . $_SESSION["id"] . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Check if image file is a actual image
        $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
        if ($check === false) {
            $profile_picture_err = "File is not an image.";
        }
        
        // Check file size (5MB max)
        if ($_FILES["profile_picture"]["size"] > 5000000) {
            $profile_picture_err = "Sorry, your file is too large. Max 5MB allowed.";
        }
        
        // Allow certain file formats
        if (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
            $profile_picture_err = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
        }
        
        if (empty($profile_picture_err)) {
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                $profile_picture_path = $target_file;
            } else {
                $profile_picture_err = "Sorry, there was an error uploading your file.";
            }
        }
    } else {
        $profile_picture_err = "Please upload a professional profile picture.";
    }
    
    // Work sample upload (Optional)
    if (!empty($_FILES["work_sample"]["name"])) {
        $target_dir = "uploads/work_samples/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["work_sample"]["name"], PATHINFO_EXTENSION));
        $new_filename = "work_sample_" . $_SESSION["id"] . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Check if image file is a actual image
        $check = getimagesize($_FILES["work_sample"]["tmp_name"]);
        if ($check === false) {
            $work_sample_err = "File is not an image.";
        }
        
        // Check file size (5MB max)
        if ($_FILES["work_sample"]["size"] > 5000000) {
            $work_sample_err = "Sorry, your file is too large. Max 5MB allowed.";
        }
        
        // Allow certain file formats
        if (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
            $work_sample_err = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
        }
        
        if (empty($work_sample_err)) {
            if (move_uploaded_file($_FILES["work_sample"]["tmp_name"], $target_file)) {
                $work_sample_path = $target_file;
            } else {
                $work_sample_err = "Sorry, there was an error uploading your work sample.";
            }
        }
    }
    // No error if work sample is not provided since it's optional
    
    // Certificate files upload
    if (!empty($_FILES["certifications"])) {
        $target_dir = "uploads/certificates/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        foreach ($_FILES["certifications"]["name"] as $index => $name) {
            if (!empty($name["image"])) {
                $file_extension = strtolower(pathinfo($name["image"], PATHINFO_EXTENSION));
                $new_filename = "certificate_" . $_SESSION["id"] . "_" . $index . "_" . time() . "." . $file_extension;
                $target_file = $target_dir . $new_filename;
                
                // Check if image file is a actual image
                $check = getimagesize($_FILES["certifications"]["tmp_name"][$index]["image"]);
                if ($check === false) {
                    $certificate_err = "File is not an image for certificate " . ($index + 1) . ".";
                    break;
                }
                
                // Check file size (5MB max)
                if ($_FILES["certifications"]["size"][$index]["image"] > 5000000) {
                    $certificate_err = "Sorry, your file is too large for certificate " . ($index + 1) . ". Max 5MB allowed.";
                    break;
                }
                
                // Allow certain file formats
                if (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
                    $certificate_err = "Sorry, only JPG, JPEG, PNG & GIF files are allowed for certificate " . ($index + 1) . ".";
                    break;
                }
                
                if (empty($certificate_err)) {
                    if (move_uploaded_file($_FILES["certifications"]["tmp_name"][$index]["image"], $target_file)) {
                        $certificate_files[$index] = $target_file;
                    } else {
                        $certificate_err = "Sorry, there was an error uploading certificate file " . ($index + 1) . ".";
                        break;
                    }
                }
            }
        }
    }
    
    // Check input errors before inserting in database
    if (empty($first_name_err) && empty($last_name_err) && empty($phone_err) && empty($address_err) && 
        empty($municipality_err) && empty($barangay_err) && empty($location_err) &&
        empty($profession_err) && empty($skills_err) && empty($description_err) && 
        empty($age_err) && empty($pricing_type_err) && empty($profile_picture_err) && 
        empty($certificate_err) && empty($work_sample_err)) {
        
        // Start transaction
        mysqli_begin_transaction($link);
        
        try {
            // Update user profile with location, profile picture, and name details
            $update_sql = "UPDATE users SET 
                          first_name = ?, middle_name = ?, last_name = ?, full_name = ?, 
                          profile_picture = ?, municipality = ?, barangay = ?, 
                          latitude = ?, longitude = ? WHERE id = ?";
            if ($update_stmt = mysqli_prepare($link, $update_sql)) {
                mysqli_stmt_bind_param($update_stmt, "sssssssddi", 
                                      $param_first_name, $param_middle_name, $param_last_name, $param_full_name,
                                      $param_profile_picture, $param_municipality, $param_barangay, 
                                      $param_latitude, $param_longitude, $param_id);
                
                $param_first_name = $first_name;
                $param_middle_name = $middle_name;
                $param_last_name = $last_name;
                $param_full_name = $full_name;
                $param_profile_picture = $profile_picture_path;
                $param_municipality = $municipality;
                $param_barangay = $barangay;
                $param_latitude = $latitude;
                $param_longitude = $longitude;
                $param_id = $_SESSION["id"];
                
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
            }
                    
        // If reapplying after rejection, update the existing rejected application
        if ($reapplying && $has_rejected_application && $current_application_id) {
            $sql = "UPDATE professional_requests SET 
                    first_name = ?, middle_name = ?, last_name = ?, full_name = ?,
                    phone = ?, address = ?, municipality = ?, barangay = ?, latitude = ?, longitude = ?, 
                    profession = ?, skills = ?, experience = ?, pricing_type = ?, age = ?, profile_picture = ?,
                    status = 'pending', created_at = NOW(), reviewed_at = NULL, rejection_reason = NULL
                    WHERE id = ?";
            
            if ($stmt = mysqli_prepare($link, $sql)) {
                // Bind variables to the prepared statement as parameters
                mysqli_stmt_bind_param($stmt, "sssssssssddssssii",
                    $param_first_name, $param_middle_name, $param_last_name, $param_full_name,
                    $param_phone, $param_address, $param_municipality, $param_barangay,
                    $param_latitude, $param_longitude, $param_profession, $param_skills,
                    $param_experience, $param_pricing_type, $param_age, $param_profile_picture,
                    $current_application_id);
                
                // Set parameters
                $param_first_name = $first_name;
                $param_middle_name = $middle_name;
                $param_last_name = $last_name;
                $param_full_name = $full_name;
                $param_phone = $phone;
                $param_address = $address;
                $param_municipality = $municipality;
                $param_barangay = $barangay;
                $param_latitude = $latitude;
                $param_longitude = $longitude;
                $param_profession = $profession;
                $param_skills = json_encode($services);
                $param_experience = $description;
                $param_pricing_type = $pricing_type;
                $param_age = (int)$age;
                $param_profile_picture = $profile_picture_path;
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error updating professional request: " . mysqli_error($link));
                }
                
                $professional_request_id = $current_application_id;
                mysqli_stmt_close($stmt);
                
                // Delete existing services, contacts, certifications, and portfolio for this professional
                $delete_sqls = [
                    "DELETE FROM services WHERE professional_id = ?",
                    "DELETE FROM professional_contacts WHERE professional_id = ?",
                    "DELETE FROM professional_certifications WHERE professional_id = ?",
                    "DELETE FROM portfolio_images WHERE professional_id = ?"
                ];
                
                foreach ($delete_sqls as $delete_sql) {
                    if ($delete_stmt = mysqli_prepare($link, $delete_sql)) {
                        mysqli_stmt_bind_param($delete_stmt, "i", $_SESSION["id"]);
                        mysqli_stmt_execute($delete_stmt);
                        mysqli_stmt_close($delete_stmt);
                    }
                }
            } else {
                throw new Exception("Failed to prepare update statement: " . mysqli_error($link));
            }
        } else {
            // THIS IS WHERE THE ELSE GOES - for NEW applications (not reapplying)
            // Prepare an insert statement for new professional request
            $sql = "INSERT INTO professional_requests (user_id, first_name, middle_name, last_name, full_name, 
                    phone, address, municipality, barangay, latitude, longitude, profession, skills, 
                    experience, pricing_type, age, profile_picture) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($stmt = mysqli_prepare($link, $sql)) {
                // Bind variables to the prepared statement as parameters
                mysqli_stmt_bind_param($stmt, "issssssssddssssis", 
                    $param_user_id, $param_first_name, $param_middle_name, $param_last_name, $param_full_name,
                    $param_phone, $param_address, $param_municipality, $param_barangay, 
                    $param_latitude, $param_longitude, $param_profession, $param_skills, 
                    $param_experience, $param_pricing_type, $param_age, $param_profile_picture);
                
                // Set parameters
                $param_user_id = $_SESSION["id"];
                $param_first_name = $first_name;
                $param_middle_name = $middle_name;
                $param_last_name = $last_name;
                $param_full_name = $full_name;
                $param_phone = $phone;
                $param_address = $address;
                $param_municipality = $municipality;
                $param_barangay = $barangay;
                $param_latitude = $latitude;
                $param_longitude = $longitude;
                $param_profession = $profession;
                $param_skills = json_encode($services);
                $param_experience = $description;
                $param_pricing_type = $pricing_type;
                $param_age = (int)$age;
                $param_profile_picture = $profile_picture_path;
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error inserting professional request: " . mysqli_error($link));
                }
                
                $professional_request_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt);
            } else {
                throw new Exception("Failed to prepare insert statement: " . mysqli_error($link));
            }
        }
            // Insert services into services table
            if (!empty($services)) {
                $service_sql = "INSERT INTO services (professional_id, professional_name, title, description, category, price, pricing_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                if ($service_stmt = mysqli_prepare($link, $service_sql)) {
                    foreach ($services as $service) {
                        mysqli_stmt_bind_param($service_stmt, "issssds", $param_prof_id, $param_prof_name, $param_title, $param_desc, $param_category, $param_price, $param_pricing_type);
                        
                        $param_prof_id = $_SESSION["id"];
                        $param_prof_name = $full_name;
                        $param_title = $service['name'] . " Service";
                        $param_desc = "Professional " . $profession . " service. Skills: " . $service['name'];
                        $param_category = $profession;
                        $param_price = $service['price'];
                        $param_pricing_type = $pricing_type;
                        
                        if (!mysqli_stmt_execute($service_stmt)) {
                            throw new Exception("Error inserting service: " . mysqli_error($link));
                        }
                    }
                    mysqli_stmt_close($service_stmt);
                }
            }
            
            // Insert contact methods
            if (!empty($contact_methods)) {
                $contact_sql = "INSERT INTO professional_contacts (professional_id, contact_type, contact_value, is_primary) VALUES (?, ?, ?, ?)";
                if ($contact_stmt = mysqli_prepare($link, $contact_sql)) {
                    foreach ($contact_methods as $contact) {
                        mysqli_stmt_bind_param($contact_stmt, "issi", $param_prof_id, $param_contact_type, $param_contact_value, $param_is_primary);
                        $param_prof_id = $_SESSION["id"];
                        $param_contact_type = $contact['type'];
                        $param_contact_value = $contact['value'];
                        $param_is_primary = $contact['primary'] ? 1 : 0;
                        
                        if (!mysqli_stmt_execute($contact_stmt)) {
                            throw new Exception("Error inserting contact method: " . mysqli_error($link));
                        }
                    }
                    mysqli_stmt_close($contact_stmt);
                }
            }
            
            // Insert certifications
            if (!empty($certifications)) {
                $cert_sql = "INSERT INTO professional_certifications (professional_id, certificate_name, issuing_organization, issue_date, expiry_date, certificate_image) VALUES (?, ?, ?, ?, ?, ?)";
                if ($cert_stmt = mysqli_prepare($link, $cert_sql)) {
                    foreach ($certifications as $index => $cert) {
                        if (isset($certificate_files[$index])) {
                            mysqli_stmt_bind_param($cert_stmt, "isssss", $param_prof_id, $param_cert_name, $param_org, $param_issue_date, $param_expiry_date, $param_cert_image);
                            $param_prof_id = $professional_request_id;
                            $param_cert_name = $cert['name'];
                            $param_org = $cert['organization'];
                            $param_issue_date = !empty($cert['issue_date']) ? $cert['issue_date'] : NULL;
                            $param_expiry_date = !empty($cert['expiry_date']) ? $cert['expiry_date'] : NULL;
                            $param_cert_image = $certificate_files[$index];
                            
                            if (!mysqli_stmt_execute($cert_stmt)) {
                                throw new Exception("Error inserting certification: " . mysqli_error($link));
                            }
                        }
                    }
                    mysqli_stmt_close($cert_stmt);
                }
            }
            
            // Insert work sample into portfolio_images (only if provided)
            if (!empty($work_sample_path)) {
                $portfolio_sql = "INSERT INTO portfolio_images (professional_id, image_path, caption, created_at) VALUES (?, ?, ?, NOW())";
                if ($portfolio_stmt = mysqli_prepare($link, $portfolio_sql)) {
                    mysqli_stmt_bind_param($portfolio_stmt, "iss", $param_prof_id, $param_image_path, $param_caption);
                    $param_prof_id = $_SESSION["id"];
                    $param_image_path = $work_sample_path;
                    $param_caption = "Work sample - " . $profession;
                    
                    if (!mysqli_stmt_execute($portfolio_stmt)) {
                        throw new Exception("Error inserting work sample: " . mysqli_error($link));
                    }
                    mysqli_stmt_close($portfolio_stmt);
                }
            }
            
            // Commit transaction
            mysqli_commit($link);
            
            // Clear rejection notification session if exists
            if (isset($_SESSION['has_pending_rejection_notification'])) {
                unset($_SESSION['has_pending_rejection_notification']);
            }
            
            // Redirect to application status page
            header("location: application-status.php");
            exit;
        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($link);
            $submit_err = "Oops! Something went wrong. Please try again later. Error: " . $e->getMessage();
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
    <title><?php echo $reapplying ? 'Reapply as Professional' : 'Apply as Professional'; ?> - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
            padding: 20px 0;
        }
        .wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .form-label {
            font-weight: 600;
        }
        .back-btn {
            margin-bottom: 20px;
        }
        #map {
            height: 300px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .location-buttons {
            margin-bottom: 15px;
        }
        .preview-image {
            max-width: 200px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 5px;
        }
        .contact-row, .certificate-row, .service-row {
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            background-color: #f8f9fa;
        }
        .contact-actions, .certificate-actions, .service-actions {
            display: flex;
            align-items: center;
        }
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .services-table {
            width: 100%;
        }
        .services-table th {
            background-color: #e9ecef;
            font-weight: 600;
        }
        .modal-content {
            border-radius: 10px;
        }
        .location-info {
            background-color: #e9f7ef;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
        .name-display {
            background-color: #e7f3ff;
            border: 1px solid #b8d4ff;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .reapply-notice {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="wrapper">
            <a href="welcome.php" class="btn btn-outline-secondary back-btn">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            
            <div class="logo">
                <i class="fas fa-briefcase fa-3x text-primary mb-2"></i>
                <h2><?php echo $reapplying ? 'Reapply as a Professional' : 'Apply as a Professional'; ?></h2>
                <p>Share your skills and start offering services</p>
            </div>

            <?php if ($reapplying && $has_rejected_application) : ?>
                <div class="reapply-notice">
                    <h5><i class="fas fa-redo me-2"></i>Reapplying After Rejection</h5>
                    <p class="mb-0">Your previous application was not approved. Please improve your application based on the feedback provided and submit again.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($submit_err)) : ?>
                <div class="alert alert-danger"><?php echo $submit_err; ?></div>
            <?php endif; ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . ($reapplying ? '?reapply=true' : ''); ?>" method="post" enctype="multipart/form-data" id="applicationForm">
                <!-- Name Section -->
                <div class="name-display">
                    <strong>Full Name Preview:</strong> 
                    <span id="full-name-preview">
                        <?php 
                        if (!empty($last_name) && !empty($first_name)) {
                            echo htmlspecialchars($last_name . ", " . $first_name . (!empty($middle_name) ? " " . $middle_name : ""));
                        } else {
                            echo "Enter your name below";
                        }
                        ?>
                    </span>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control <?php echo (!empty($last_name_err)) ? 'is-invalid' : ''; ?>" 
                               value="<?php echo htmlspecialchars($last_name); ?>" placeholder="Gonzales" id="last-name">
                        <span class="invalid-feedback"><?php echo $last_name_err; ?></span>
                        <div class="form-text">Your family name</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control <?php echo (!empty($first_name_err)) ? 'is-invalid' : ''; ?>" 
                               value="<?php echo htmlspecialchars($first_name); ?>" placeholder="Willian" id="first-name">
                        <span class="invalid-feedback"><?php echo $first_name_err; ?></span>
                        <div class="form-text">Your given name</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control <?php echo (!empty($middle_name_err)) ? 'is-invalid' : ''; ?>" 
                               value="<?php echo htmlspecialchars($middle_name); ?>" placeholder="T." id="middle-name">
                        <span class="invalid-feedback"><?php echo $middle_name_err; ?></span>
                        <div class="form-text">Optional - Middle initial or name</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Age *</label>
                        <input type="number" name="age" class="form-control <?php echo (!empty($age_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($age); ?>">
                        <span class="invalid-feedback"><?php echo $age_err; ?></span>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Primary Phone *</label>
                        <input type="text" name="contact_methods[0][value]" class="form-control <?php echo (!empty($phone_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($phone); ?>">
                        <input type="hidden" name="contact_methods[0][type]" value="phone">
                        <input type="hidden" name="contact_methods[0][primary]" value="on">
                        <span class="invalid-feedback"><?php echo $phone_err; ?></span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Profile Picture *</label>
                    <input type="file" name="profile_picture" class="form-control <?php echo (!empty($profile_picture_err)) ? 'is-invalid' : ''; ?>" accept="image/*">
                    <span class="invalid-feedback"><?php echo $profile_picture_err; ?></span>
                    <div class="form-text">Upload a professional profile picture (JPG, PNG, GIF, max 5MB)</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Address *</label>
                    <textarea name="address" class="form-control <?php echo (!empty($address_err)) ? 'is-invalid' : ''; ?>" rows="3" id="address"><?php echo htmlspecialchars($address); ?></textarea>
                    <span class="invalid-feedback"><?php echo $address_err; ?></span>
                    <div class="form-text">Your complete address including street, barangay, and municipality</div>
                </div>

                <!-- Location Information Display -->
                <div class="location-info mb-3">
                    <h6><i class="fas fa-map-marker-alt me-2"></i>Detected Location Information</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Municipality:</strong> <span id="municipality-display"><?php echo htmlspecialchars($municipality); ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Barangay:</strong> <span id="barangay-display"><?php echo htmlspecialchars($barangay); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Hidden fields for municipality and barangay -->
                <input type="hidden" name="municipality" id="municipality" value="<?php echo htmlspecialchars($municipality); ?>">
                <input type="hidden" name="barangay" id="barangay" value="<?php echo htmlspecialchars($barangay); ?>">

                <div class="mb-3">
                    <label class="form-label">Set Your Location *</label>
                    <div class="location-buttons mb-2">
                        <button type="button" id="use-current-location" class="btn btn-outline-primary btn-sm me-2">
                            <i class="fas fa-location-arrow me-1"></i>Use Current Location
                        </button>
                        <button type="button" id="search-location" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-search me-1"></i>Search Location
                        </button>
                    </div>
                    <div id="map"></div>
                    <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars($latitude); ?>">
                    <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars($longitude); ?>">
                    <span class="invalid-feedback d-block"><?php echo $location_err; ?></span>
                    <div class="form-text">Click on the map to set your location. The address will be automatically filled.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Profession *</label>
                        <input type="text" name="profession" class="form-control <?php echo (!empty($profession_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($profession); ?>" placeholder="e.g., Carpenter, Electrician, Plumber">
                        <span class="invalid-feedback"><?php echo $profession_err; ?></span>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Pricing Type *</label>
                        <select name="pricing_type" class="form-control <?php echo (!empty($pricing_type_err)) ? 'is-invalid' : ''; ?>">
                            <option value="">Select Pricing Type</option>
                            <option value="per_job" <?php echo ($pricing_type == 'per_job') ? 'selected' : ''; ?>>Per Job</option>
                            <option value="daily" <?php echo ($pricing_type == 'daily') ? 'selected' : ''; ?>>Daily Rate</option>
                            <option value="both" <?php echo ($pricing_type == 'both') ? 'selected' : ''; ?>>Both</option>
                        </select>
                        <span class="invalid-feedback"><?php echo $pricing_type_err; ?></span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Services Offered *</label>
                    <div class="table-responsive">
                        <table class="table table-bordered services-table">
                            <thead>
                                <tr>
                                    <th width="60%">Service Name</th>
                                    <th width="30%">Price (₱)</th>
                                    <th width="10%">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="services-container">
                                <?php foreach ($services as $index => $service): ?>
                                <tr class="service-row">
                                    <td>
                                        <input type="text" name="services[<?php echo $index; ?>][name]" class="form-control" value="<?php echo htmlspecialchars($service['name']); ?>" placeholder="Service name">
                                    </td>
                                    <td>
                                        <input type="number" name="services[<?php echo $index; ?>][price]" class="form-control" value="<?php echo htmlspecialchars($service['price']); ?>" placeholder="0.00" step="0.01" min="0">
                                    </td>
                                    <td class="service-actions">
                                        <button type="button" class="btn btn-danger btn-sm remove-service">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" id="add-service" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-plus me-1"></i>Add Service Offered
                    </button>
                    <span class="invalid-feedback d-block"><?php echo $skills_err; ?></span>
                    <div class="form-text">List the services you offer with their prices</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Professional Description *</label>
                    <textarea name="description" class="form-control <?php echo (!empty($description_err)) ? 'is-invalid' : ''; ?>" rows="4" placeholder="Describe your experience, skills, and what makes you a great professional..."><?php echo htmlspecialchars($description); ?></textarea>
                    <span class="invalid-feedback"><?php echo $description_err; ?></span>
                </div>

                <div class="mb-3">
                    <label class="form-label">Additional Contact Methods</label>
                    <div id="contact-methods">
                        <?php foreach ($contact_methods as $index => $contact): ?>
                            <?php if ($index > 0): ?>
                                <div class="contact-row">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <select name="contact_methods[<?php echo $index; ?>][type]" class="form-control">
                                                <option value="phone" <?php echo ($contact['type'] == 'phone') ? 'selected' : ''; ?>>Phone</option>
                                                <option value="email" <?php echo ($contact['type'] == 'email') ? 'selected' : ''; ?>>Email</option>
                                                <option value="whatsapp" <?php echo ($contact['type'] == 'whatsapp') ? 'selected' : ''; ?>>WhatsApp</option>
                                                <option value="telegram" <?php echo ($contact['type'] == 'telegram') ? 'selected' : ''; ?>>Telegram</option>
                                                <option value="viber" <?php echo ($contact['type'] == 'viber') ? 'selected' : ''; ?>>Viber</option>
                                                <option value="facebook" <?php echo ($contact['type'] == 'facebook') ? 'selected' : ''; ?>>Facebook</option>
                                                <option value="other" <?php echo ($contact['type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <input type="text" name="contact_methods[<?php echo $index; ?>][value]" class="form-control" value="<?php echo htmlspecialchars($contact['value']); ?>" placeholder="Contact information">
                                        </div>
                                        <div class="col-md-2 contact-actions">
                                            <button type="button" class="btn btn-danger btn-sm remove-contact">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-contact" class="btn btn-outline-primary btn-sm mt-2">
                        <i class="fas fa-plus me-1"></i>Add Contact Method
                    </button>
                </div>

                <div class="mb-3">
                    <label class="form-label">Certifications & Licenses</label>
                    <div id="certifications">
                        <?php foreach ($certifications as $index => $cert): ?>
                            <div class="certificate-row">
                                <div class="row">
                                    <div class="col-md-3 mb-2">
                                        <input type="text" name="certifications[<?php echo $index; ?>][name]" class="form-control" value="<?php echo htmlspecialchars($cert['name']); ?>" placeholder="Certificate Name">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="text" name="certifications[<?php echo $index; ?>][organization]" class="form-control" value="<?php echo htmlspecialchars($cert['organization']); ?>" placeholder="Issuing Organization">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="date" name="certifications[<?php echo $index; ?>][issue_date]" class="form-control" value="<?php echo htmlspecialchars($cert['issue_date']); ?>" placeholder="Issue Date">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="date" name="certifications[<?php echo $index; ?>][expiry_date]" class="form-control" value="<?php echo htmlspecialchars($cert['expiry_date']); ?>" placeholder="Expiry Date">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="file" name="certifications[<?php echo $index; ?>][image]" class="form-control" accept="image/*">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-10">
                                        <small class="form-text">Upload image of certificate (JPG, PNG, GIF, max 5MB)</small>
                                    </div>
                                    <div class="col-md-2 certificate-actions">
                                        <button type="button" class="btn btn-danger btn-sm remove-certificate">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-certificate" class="btn btn-outline-primary btn-sm mt-2">
                            <i class="fas fa-plus me-1"></i>Add Certificate
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Work Sample (Optional)</label>
                        <input type="file" name="work_sample" class="form-control <?php echo (!empty($work_sample_err)) ? 'is-invalid' : ''; ?>" accept="image/*">
                        <?php if (!empty($work_sample_err)): ?>
                            <span class="invalid-feedback"><?php echo $work_sample_err; ?></span>
                        <?php endif; ?>
                        <div class="form-text">Upload a picture of your previous work (JPG, PNG, GIF, max 5MB) - Optional</div>
                    </div>

                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms" required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a> and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
                            </label>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <i class="fas fa-paper-plane me-2"></i><?php echo $reapplying ? 'Resubmit Application' : 'Submit Application'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Terms of Service Modal -->
        <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="termsModalLabel">Terms of Service</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h6>1. Acceptance of Terms</h6>
                        <p>By applying as a professional on Artisan Link, you agree to be bound by these Terms of Service and our Privacy Policy.</p>
                        
                        <h6>2. Professional Conduct</h6>
                        <p>As a professional service provider, you agree to:</p>
                        <ul>
                            <li>Provide accurate information about your skills, experience, and qualifications</li>
                            <li>Maintain professional behavior with all clients</li>
                            <li>Complete services as agreed with clients</li>
                            <li>Respond to booking requests in a timely manner</li>
                            <li>Maintain the quality of your work</li>
                        </ul>
                        
                        <h6>3. Service Standards</h6>
                        <p>You are responsible for:</p>
                        <ul>
                            <li>Arriving on time for scheduled appointments</li>
                            <li>Bringing necessary tools and equipment</li>
                            <li>Providing quality workmanship</li>
                            <li>Cleaning up after completing your work</li>
                        </ul>
                        
                        <h6>4. Payment and Fees</h6>
                        <p>Artisan Link charges a 10% service fee on all completed bookings. This fee helps maintain our platform and provide customer support.</p>
                        
                        <h6>5. Client Relationships</h6>
                        <p>You agree not to solicit clients outside the Artisan Link platform for 6 months after your last interaction with them through our service.</p>
                        
                        <h6>6. Termination</h6>
                        <p>Artisan Link reserves the right to suspend or terminate your account for violations of these terms or for providing poor service quality.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Privacy Policy Modal -->
        <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="privacyModalLabel">Privacy Policy</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h6>1. Information We Collect</h6>
                        <p>We collect information you provide when you apply as a professional, including:</p>
                        <ul>
                            <li>Personal identification information (name, email, phone number)</li>
                            <li>Professional qualifications and certifications</li>
                            <li>Location data</li>
                            <li>Profile pictures and work samples</li>
                            <li>Service descriptions and pricing</li>
                        </ul>
                        
                        <h6>2. How We Use Your Information</h6>
                        <p>We use your information to:</p>
                        <ul>
                            <li>Create and maintain your professional profile</li>
                            <li>Connect you with potential clients</li>
                            <li>Process payments for services rendered</li>
                            <li>Improve our platform and services</li>
                            <li>Communicate important updates and notifications</li>
                        </ul>
                        
                        <h6>3. Information Sharing</h6>
                        <p>We share your professional profile information with potential clients to help them find and book your services. We do not sell your personal information to third parties.</p>
                        
                        <h6>4. Data Security</h6>
                        <p>We implement appropriate security measures to protect your personal information from unauthorized access, alteration, or disclosure.</p>
                        
                        <h6>5. Your Rights</h6>
                        <p>You have the right to:</p>
                        <ul>
                            <li>Access and update your personal information</li>
                            <li>Request deletion of your account and data</li>
                            <li>Opt-out of promotional communications</li>
                        </ul>
                        
                        <h6>6. Contact Us</h6>
                        <p>If you have questions about this Privacy Policy, please contact us at privacy@artisanlink.com.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            // Dynamic form fields functionality
            document.addEventListener('DOMContentLoaded', function() {
                // Name preview functionality
                function updateNamePreview() {
                    const lastName = document.getElementById('last-name').value.trim();
                    const firstName = document.getElementById('first-name').value.trim();
                    const middleName = document.getElementById('middle-name').value.trim();
                    
                    let fullName = '';
                    if (lastName && firstName) {
                        fullName = lastName + ', ' + firstName;
                        if (middleName) {
                            fullName += ' ' + middleName;
                        }
                    } else {
                        fullName = 'Enter your name below';
                    }
                    
                    document.getElementById('full-name-preview').textContent = fullName;
                }
                
                // Add event listeners for name fields
                document.getElementById('last-name').addEventListener('input', updateNamePreview);
                document.getElementById('first-name').addEventListener('input', updateNamePreview);
                document.getElementById('middle-name').addEventListener('input', updateNamePreview);
                
                // Services functionality
                let serviceCount = <?php echo count($services); ?>;
                
                document.getElementById('add-service').addEventListener('click', function() {
                    const container = document.getElementById('services-container');
                    const newRow = document.createElement('tr');
                    newRow.className = 'service-row';
                    newRow.innerHTML = `
                        <td>
                            <input type="text" name="services[${serviceCount}][name]" class="form-control" placeholder="Service name">
                        </td>
                        <td>
                            <input type="number" name="services[${serviceCount}][price]" class="form-control" placeholder="0.00" step="0.01" min="0">
                        </td>
                        <td class="service-actions">
                            <button type="button" class="btn btn-danger btn-sm remove-service">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    `;
                    container.appendChild(newRow);
                    serviceCount++;
                });

                // Contact methods functionality
                let contactCount = <?php echo count($contact_methods); ?>;
                
                document.getElementById('add-contact').addEventListener('click', function() {
                    const container = document.getElementById('contact-methods');
                    const newRow = document.createElement('div');
                    newRow.className = 'contact-row';
                    newRow.innerHTML = `
                        <div class="row">
                            <div class="col-md-4">
                                <select name="contact_methods[${contactCount}][type]" class="form-control">
                                    <option value="phone">Phone</option>
                                    <option value="email">Email</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="telegram">Telegram</option>
                                    <option value="viber">Viber</option>
                                    <option value="facebook">Facebook</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="contact_methods[${contactCount}][value]" class="form-control" placeholder="Contact information">
                            </div>
                            <div class="col-md-2 contact-actions">
                                <button type="button" class="btn btn-danger btn-sm remove-contact">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                    container.appendChild(newRow);
                    contactCount++;
                });

                // Certifications functionality
                let certificateCount = <?php echo count($certifications); ?>;
                
                document.getElementById('add-certificate').addEventListener('click', function() {
                    const container = document.getElementById('certifications');
                    const newRow = document.createElement('div');
                    newRow.className = 'certificate-row';
                    newRow.innerHTML = `
                        <div class="row">
                            <div class="col-md-3 mb-2">
                                <input type="text" name="certifications[${certificateCount}][name]" class="form-control" placeholder="Certificate Name">
                            </div>
                            <div class="col-md-3 mb-2">
                                <input type="text" name="certifications[${certificateCount}][organization]" class="form-control" placeholder="Issuing Organization">
                            </div>
                            <div class="col-md-2 mb-2">
                                <input type="date" name="certifications[${certificateCount}][issue_date]" class="form-control" placeholder="Issue Date">
                            </div>
                            <div class="col-md-2 mb-2">
                                <input type="date" name="certifications[${certificateCount}][expiry_date]" class="form-control" placeholder="Expiry Date">
                            </div>
                            <div class="col-md-2 mb-2">
                                <input type="file" name="certifications[${certificateCount}][image]" class="form-control" accept="image/*">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-10">
                                <small class="form-text">Upload image of certificate (JPG, PNG, GIF, max 5MB)</small>
                            </div>
                            <div class="col-md-2 certificate-actions">
                                <button type="button" class="btn btn-danger btn-sm remove-certificate">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                    container.appendChild(newRow);
                    certificateCount++;
                });

                // Remove functionality using event delegation
                document.addEventListener('click', function(e) {
                    // Remove service
                    if (e.target.closest('.remove-service')) {
                        const row = e.target.closest('.service-row');
                        if (document.querySelectorAll('.service-row').length > 1) {
                            row.remove();
                        } else {
                            alert('You must have at least one service.');
                        }
                    }
                    
                    // Remove contact
                    if (e.target.closest('.remove-contact')) {
                        const row = e.target.closest('.contact-row');
                        row.remove();
                    }
                    
                    // Remove certificate
                    if (e.target.closest('.remove-certificate')) {
                        const row = e.target.closest('.certificate-row');
                        row.remove();
                    }
                });

                // Form validation
                document.getElementById('applicationForm').addEventListener('submit', function(e) {
                    const termsCheckbox = document.getElementById('terms');
                    if (!termsCheckbox.checked) {
                        e.preventDefault();
                        alert('Please agree to the Terms of Service and Privacy Policy.');
                        termsCheckbox.focus();
                        return false;
                    }
                    
                    // Validate name fields
                    const firstName = document.getElementById('first-name').value.trim();
                    const lastName = document.getElementById('last-name').value.trim();
                    
                    if (!firstName) {
                        e.preventDefault();
                        alert('Please enter your first name.');
                        document.getElementById('first-name').focus();
                        return false;
                    }
                    
                    if (!lastName) {
                        e.preventDefault();
                        alert('Please enter your last name.');
                        document.getElementById('last-name').focus();
                        return false;
                    }
                    
                    // Validate at least one service
                    const serviceRows = document.querySelectorAll('.service-row');
                    let hasValidService = false;
                    
                    serviceRows.forEach(row => {
                        const nameInput = row.querySelector('input[name$="[name]"]');
                        const priceInput = row.querySelector('input[name$="[price]"]');
                        
                        if (nameInput.value.trim() && priceInput.value.trim()) {
                            hasValidService = true;
                        }
                    });
                    
                    if (!hasValidService) {
                        e.preventDefault();
                        alert('Please add at least one service with both name and price.');
                        return false;
                    }
                });
            });

            // Map initialization
            let map, marker;
            const defaultLat = 15.7699;
            const defaultLng = 121.0376; // Aurora province coordinates
            const allowedMunicipalities = ['Baler', 'San Luis', 'Dipaculao', 'Maria Aurora'];

            function initMap() {
                map = L.map('map').setView([defaultLat, defaultLng], 12);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(map);

                // Add click event to set location
                map.on('click', function(e) {
                    setLocation(e.latlng.lat, e.latlng.lng);
                    reverseGeocode(e.latlng.lat, e.latlng.lng);
                });

                // Initialize marker if we have coordinates
                if (document.getElementById('latitude').value && document.getElementById('longitude').value) {
                    const lat = parseFloat(document.getElementById('latitude').value);
                    const lng = parseFloat(document.getElementById('longitude').value);
                    setLocation(lat, lng);
                    map.setView([lat, lng], 15);
                } else {
                    // Try to get current location, otherwise use default
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition(
                            function(position) {
                                const lat = position.coords.latitude;
                                const lng = position.coords.longitude;
                                setLocation(lat, lng);
                                reverseGeocode(lat, lng);
                                map.setView([lat, lng], 15);
                            },
                            function() {
                                // If geolocation fails, use default location
                                setLocation(defaultLat, defaultLng);
                            }
                        );
                    } else {
                        setLocation(defaultLat, defaultLng);
                    }
                }

                // Initialize event listeners after map is ready
                initializeEventListeners();
            }

            function setLocation(lat, lng) {
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
                
                if (marker) {
                    map.removeLayer(marker);
                }
                
                marker = L.marker([lat, lng]).addTo(map)
                    .bindPopup('Your service location<br>Lat: ' + lat.toFixed(6) + '<br>Lng: ' + lng.toFixed(6))
                    .openPopup();
            }

            function extractMunicipalityAndBarangay(address) {
                let municipality = '';
                let barangay = '';
                
                // Check for each municipality in the address
                for (const muni of allowedMunicipalities) {
                    if (address.toLowerCase().includes(muni.toLowerCase())) {
                        municipality = muni;
                        break;
                    }
                }
                
                // Extract barangay - improved approach
                if (municipality) {
                    const muniIndex = address.toLowerCase().indexOf(municipality.toLowerCase());
                    if (muniIndex > 0) {
                        // Get the part before municipality (likely contains barangay)
                        const beforeMuni = address.substring(0, muniIndex).trim();
                        
                        // Split by commas and get the last non-empty part before municipality
                        const parts = beforeMuni.split(',').filter(part => part.trim() !== '');
                        if (parts.length > 0) {
                            barangay = parts[parts.length - 1].trim();
                            
                            // Clean up barangay name - remove common prefixes/suffixes
                            barangay = barangay.replace(/^(brgy\.?|barangay)\s*/gi, '').trim();
                        }
                    }
                }
                
                // Fallback: if no barangay found, try to extract from beginning of address
                if (!barangay) {
                    const addressParts = address.split(',');
                    if (addressParts.length > 1) {
                        barangay = addressParts[0].trim();
                        barangay = barangay.replace(/^(brgy\.?|barangay)\s*/gi, '').trim();
                    }
                }
                
                return { municipality, barangay };
            }

            function updateLocationDisplay(municipality, barangay) {
                const muniDisplay = document.getElementById('municipality-display');
                const brgyDisplay = document.getElementById('barangay-display');
                
                muniDisplay.textContent = municipality || 'Not detected';
                brgyDisplay.textContent = barangay || 'Not detected';
                
                document.getElementById('municipality').value = municipality || '';
                document.getElementById('barangay').value = barangay || '';
                
                // Update styling based on detection
                if (municipality && barangay) {
                    muniDisplay.style.color = 'green';
                    brgyDisplay.style.color = 'green';
                } else {
                    muniDisplay.style.color = 'red';
                    brgyDisplay.style.color = 'red';
                }
            }

            function reverseGeocode(lat, lng) {
                const addressField = document.getElementById('address');
                addressField.value = 'Getting address...';
                
                fetch('geocode.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ lat: lat, lng: lng })
                })
                .then(response => response.json())
                .then(data => {
                    if (data && data.display_name) {
                        addressField.value = data.display_name;
                        const locationInfo = extractMunicipalityAndBarangay(data.display_name);
                        updateLocationDisplay(locationInfo.municipality, locationInfo.barangay);
                    } else {
                        addressField.value = `Near coordinates: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                        updateLocationDisplay('', '');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    addressField.value = `Location at: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                    updateLocationDisplay('', '');
                });
            }

            function initializeEventListeners() {
                // Use current location button
                document.getElementById('use-current-location').addEventListener('click', function() {
                    if (navigator.geolocation) {
                        // Show loading state
                        const button = this;
                        const originalText = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Getting Location...';
                        button.disabled = true;

                        navigator.geolocation.getCurrentPosition(
                            function(position) {
                                const lat = position.coords.latitude;
                                const lng = position.coords.longitude;
                                setLocation(lat, lng);
                                reverseGeocode(lat, lng);
                                map.setView([lat, lng], 15);
                                
                                // Restore button
                                button.innerHTML = originalText;
                                button.disabled = false;
                            },
                            function(error) {
                                console.error('Geolocation error:', error);
                                
                                let errorMessage = 'Unable to get your current location. ';
                                switch(error.code) {
                                    case error.PERMISSION_DENIED:
                                        errorMessage += 'Please allow location access in your browser settings or set location manually on the map.';
                                        break;
                                    case error.POSITION_UNAVAILABLE:
                                        errorMessage += 'Location information is unavailable. Please set location manually on the map.';
                                        break;
                                    case error.TIMEOUT:
                                        errorMessage += 'Location request timed out. Please set location manually on the map.';
                                        break;
                                    default:
                                        errorMessage += 'Please set location manually on the map.';
                                        break;
                                }
                                
                                alert(errorMessage);
                                
                                // Restore button
                                button.innerHTML = originalText;
                                button.disabled = false;
                            },
                            {
                                enableHighAccuracy: true,
                                timeout: 15000,
                                maximumAge: 60000
                            }
                        );
                    } else {
                        alert('Geolocation is not supported by your browser. Please set location manually on the map.');
                    }
                });

                // Search location button
                document.getElementById('search-location').addEventListener('click', function() {
                    const address = document.getElementById('address').value.trim();
                    
                    if (!address) {
                        alert('Please enter an address to search.');
                        return;
                    }
                    
                    console.log('Searching for:', address);
                    
                    // Show loading state
                    const button = this;
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Searching...';
                    button.disabled = true;
                    
                    // Geocoding using Nominatim - this converts human address to coordinates
                    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address + ', Aurora, Philippines')}&limit=1`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('Geocode response:', data);
                            if (data && data.length > 0) {
                                const lat = parseFloat(data[0].lat);
                                const lng = parseFloat(data[0].lon);
                                setLocation(lat, lng);
                                
                                // Now use reverse geocoding to get a clean, human-readable address
                                reverseGeocode(lat, lng);
                                
                                map.setView([lat, lng], 15);
                            } else {
                                alert('Location not found. Please try a different address or set location manually on the map.');
                            }
                            
                            // Restore button
                            button.innerHTML = originalText;
                            button.disabled = false;
                        })
                        .catch(error => {
                            console.error('Error searching location:', error);
                            alert('Error searching location. Please try again or set location manually on the map.');
                            
                            // Restore button
                            button.innerHTML = originalText;
                            button.disabled = false;
                        });
                });

                // Monitor address field for changes
                document.getElementById('address').addEventListener('input', function() {
                    const address = this.value;
                    const locationInfo = extractMunicipalityAndBarangay(address);
                    updateLocationDisplay(locationInfo.municipality, locationInfo.barangay);
                });
            }

            // Initialize map when page loads
            document.addEventListener('DOMContentLoaded', initMap);
        </script>
    </body>
</html>