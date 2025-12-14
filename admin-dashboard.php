<?php
// Initialize the session
session_start();

// Check if the user is logged in and is an admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || 
   ($_SESSION["user_type"] !== 'admin' && $_SESSION["user_type"] !== 'super_admin')){
    header("location: admin-login.php");
    exit;
}

// Include config file
require_once "config.php";

// Initialize variables
$message = "";
$message_type = "";

// Handle AJAX backup request
if(isset($_GET['ajax']) && $_GET['ajax'] === 'backup' && $_SERVER["REQUEST_METHOD"] == "POST" && $_SESSION["user_type"] === 'super_admin'){
    $backup_password = $_POST['backup_password'] ?? '';
    $backup_type = $_POST['backup_type'] ?? 'both';
    
    // Create backup directory if it doesn't exist
    if (!file_exists('backup')) {
        mkdir('backup', 0777, true);
    }
    
    // Generate unique backup name
    $backup_name = 'artisanlink_backup_' . date("Y-m-d_H-i-s");
    $temp_dir = sys_get_temp_dir() . '/' . $backup_name;
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    try {
        // Backup database if requested
        if($backup_type == 'database' || $backup_type == 'both') {
            $db_backup_file = $temp_dir . '/database_backup.sql';
            
            // Get all tables
            $tables = [];
            $result = mysqli_query($link, 'SHOW TABLES');
            while($row = mysqli_fetch_row($result)) {
                $tables[] = $row[0];
            }
            
            // Create backup content
            $output = "/* ArtisanLink Database Backup */\n";
            $output .= "/* Generated: " . date('Y-m-d H:i:s') . " */\n";
            $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
            
            foreach($tables as $table) {
                // Drop table if exists
                $output .= "DROP TABLE IF EXISTS `$table`;\n";
                
                // Create table structure
                $result = mysqli_query($link, "SHOW CREATE TABLE `$table`");
                $row = mysqli_fetch_row($result);
                $output .= $row[1] . ";\n\n";
                
                // Get table data
                $result = mysqli_query($link, "SELECT * FROM `$table`");
                $num_fields = mysqli_num_fields($result);
                
                if($num_fields > 0) {
                    $output .= "/* Dumping data for table `$table` */\n";
                    while($row = mysqli_fetch_row($result)) {
                        $output .= "INSERT INTO `$table` VALUES(";
                        for($i = 0; $i < $num_fields; $i++) {
                            if(isset($row[$i])) {
                                $row[$i] = addslashes($row[$i]);
                                $row[$i] = str_replace("\n", "\\n", $row[$i]);
                                $output .= '"' . $row[$i] . '"';
                            } else {
                                $output .= 'NULL';
                            }
                            if($i < ($num_fields - 1)) {
                                $output .= ',';
                            }
                        }
                        $output .= ");\n";
                    }
                    $output .= "\n";
                }
            }
            
            $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
            
            // Save database backup file
            if(file_put_contents($db_backup_file, $output) === false) {
                throw new Exception("Failed to write database backup file");
            }
        }
        
        // Backup files if requested
        if($backup_type == 'files' || $backup_type == 'both') {
            $folders_to_backup = ['uploads', 'includes', 'css', 'js'];
            
            foreach($folders_to_backup as $folder) {
                if(file_exists($folder) && $folder !== 'backup') {
                    $source = realpath($folder);
                    $dest = $temp_dir . '/' . $folder;
                    
                    if(is_dir($source)) {
                        // Create directory iterator
                        $dir_iterator = new RecursiveDirectoryIterator(
                            $source, 
                            RecursiveDirectoryIterator::SKIP_DOTS
                        );
                        $iterator = new RecursiveIteratorIterator(
                            $dir_iterator,
                            RecursiveIteratorIterator::SELF_FIRST
                        );
                        
                        foreach($iterator as $item) {
                            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
                            
                            if ($item->isDir()) {
                                if (!file_exists($target)) {
                                    mkdir($target, 0777, true);
                                }
                            } else {
                                // Ensure parent directory exists
                                $target_dir = dirname($target);
                                if (!file_exists($target_dir)) {
                                    mkdir($target_dir, 0777, true);
                                }
                                copy($item->getPathname(), $target);
                            }
                        }
                    }
                }
            }
            
            // Backup important files
            $important_files = ['config.php', 'index.php', '.htaccess'];
            foreach($important_files as $file) {
                if(file_exists($file)) {
                    copy($file, $temp_dir . '/' . $file);
                }
            }
        }
        
        // Create zip file
        $zip_file = 'backup/' . $backup_name . '.zip';
        
        if(class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
                // Add all files from temp directory to zip
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($temp_dir),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach($files as $file) {
                    if(!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($temp_dir) + 1);
                        
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                
                // Set password if provided
                if(!empty($backup_password)) {
                    if(!$zip->setPassword($backup_password)) {
                        throw new Exception("Failed to set password on zip file");
                    }
                    
                    // Set encryption method for all files
                    for($i = 0; $i < $zip->numFiles; $i++) {
                        if(!$zip->setEncryptionIndex($i, ZipArchive::EM_AES_256)) {
                            throw new Exception("Failed to encrypt file at index $i");
                        }
                    }
                }
                
                if(!$zip->close()) {
                    throw new Exception("Failed to close zip file");
                }
                
                // Clean up temp directory
                deleteDirectory($temp_dir);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Backup created successfully!',
                    'file' => $backup_name . '.zip',
                    'url' => 'backup/' . $backup_name . '.zip'
                ]);
                exit;
                
            } else {
                throw new Exception("Failed to create zip file");
            }
        } else {
            throw new Exception("ZipArchive extension is not available");
        }
        
    } catch (Exception $e) {
        // Clean up on error
        if(file_exists($temp_dir)) {
            deleteDirectory($temp_dir);
        }
        
        echo json_encode([
            'success' => false,
            'message' => 'Error creating backup: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle database restore
if(isset($_POST['restore_database']) && $_SESSION["user_type"] === 'super_admin'){
    if(isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['sql_file']['tmp_name'];
        
        // Read SQL file
        $sql_content = file_get_contents($tmp_name);
        if($sql_content === false) {
            $message = "Failed to read SQL file";
            $message_type = "danger";
        } else {
            // Disable foreign key checks temporarily
            mysqli_query($link, "SET FOREIGN_KEY_CHECKS=0");
            
            // Split by semicolon to get individual queries
            $queries = explode(';', $sql_content);
            $success = true;
            $error_msg = '';
            
            foreach($queries as $query) {
                $query = trim($query);
                if(!empty($query)) {
                    // Skip comments
                    if(strpos($query, '/*') === 0) continue;
                    if(strpos($query, '--') === 0) continue;
                    
                    if(!mysqli_query($link, $query)) {
                        $success = false;
                        $error_msg = mysqli_error($link);
                        break;
                    }
                }
            }
            
            // Re-enable foreign key checks
            mysqli_query($link, "SET FOREIGN_KEY_CHECKS=1");
            
            if($success) {
                $message = "Database restored successfully!";
                $message_type = "success";
            } else {
                $message = "Error restoring database: " . $error_msg;
                $message_type = "danger";
            }
        }
    } else {
        $message = "Please select a valid SQL file";
        $message_type = "danger";
    }
}

// Handle regular POST actions (existing code continues...)
if($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_GET['ajax'])){
    // Handle report actions
    if(isset($_POST['action']) && isset($_POST['report_id'])){
        $report_id = $_POST['report_id'];
        $action = $_POST['action'];
        $admin_id = $_SESSION["id"];
        
        if($action == 'reviewed' || $action == 'resolved' || $action == 'dismissed'){
            $status = $action;
            
            $sql = "UPDATE reports SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?";
            
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "sii", $status, $admin_id, $report_id);
                
                if(mysqli_stmt_execute($stmt)){
                    $message = "Report marked as " . $status . " successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error updating report: " . mysqli_error($link);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    // Handle professional request actions WITH REASON
    if(isset($_POST['action']) && isset($_POST['request_id'])){
        $request_id = $_POST['request_id'];
        $action = $_POST['action'];
        $admin_id = $_SESSION["id"];
        $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';
        
        if($action == 'approve' || $action == 'reject'){
            $status = $action == 'approve' ? 'approved' : 'rejected';
            
            if($action == 'reject' && empty($rejection_reason)){
                $message = "Please provide a reason for rejection.";
                $message_type = "danger";
            } else {
                $sql = "UPDATE professional_requests SET status = ?, reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ? WHERE id = ?";
                
                if($stmt = mysqli_prepare($link, $sql)){
                    mysqli_stmt_bind_param($stmt, "sisi", $status, $admin_id, $rejection_reason, $request_id);
                    
                    if(mysqli_stmt_execute($stmt)){
                        // If approved, update user type to professional
                        if($action == 'approve'){
                            $user_sql = "UPDATE users SET user_type = 'professional' WHERE id = (SELECT user_id FROM professional_requests WHERE id = ?)";
                            if($user_stmt = mysqli_prepare($link, $user_sql)){
                                mysqli_stmt_bind_param($user_stmt, "i", $request_id);
                                mysqli_stmt_execute($user_stmt);
                                mysqli_stmt_close($user_stmt);
                            }
                        }
                        
                        $message = "Request " . $status . " successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error updating request: " . mysqli_error($link);
                        $message_type = "danger";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
    
    // Handle user deletion
    if(isset($_POST['delete_user'])){
        $user_id = $_POST['user_id'];
        
        // Begin transaction
        mysqli_begin_transaction($link);
        
        try {
            // Delete user related records from all tables that reference the user
            $sql1 = "DELETE FROM bookings WHERE customer_id = ? OR professional_id = ?";
            if($stmt = mysqli_prepare($link, $sql1)){
                mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql2 = "DELETE FROM feedback WHERE customer_id = ? OR professional_id = ?";
            if($stmt = mysqli_prepare($link, $sql2)){
                mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql3 = "DELETE FROM professional_requests WHERE user_id = ?";
            if($stmt = mysqli_prepare($link, $sql3)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql4 = "DELETE FROM services WHERE professional_id = ?";
            if($stmt = mysqli_prepare($link, $sql4)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql5 = "DELETE FROM notifications WHERE user_id = ?";
            if($stmt = mysqli_prepare($link, $sql5)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql6 = "DELETE FROM reports WHERE reporter_id = ? OR reported_user_id = ?";
            if($stmt = mysqli_prepare($link, $sql6)){
                mysqli_stmt_bind_param($stmt, "ii", $user_id, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            $sql7 = "DELETE FROM professional_contacts WHERE professional_id = ?";
            if($stmt = mysqli_prepare($link, $sql7)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            // Delete the user
            $sql8 = "DELETE FROM users WHERE id = ?";
            if($stmt = mysqli_prepare($link, $sql8)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            
            // Commit transaction
            mysqli_commit($link);
            
            $message = "User deleted successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            // Rollback transaction on error
            mysqli_rollback($link);
            $message = "Error deleting user: " . $e->getMessage();
            $message_type = "danger";
        }
    }
    
    // Handle user suspension WITH REASON
    if(isset($_POST['suspend_user'])){
        $user_id = $_POST['user_id'];
        $duration = $_POST['duration'];
        $reason = $_POST['reason'];
        $suspended_by = $_SESSION["id"];
        
        if(empty($reason)){
            $message = "Please provide a reason for suspension.";
            $message_type = "danger";
        } else {
            $sql = "INSERT INTO user_suspensions (user_id, action, reason, duration, suspended_by) VALUES (?, 'suspended', ?, ?, ?)";
            
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "issi", $user_id, $reason, $duration, $suspended_by);
                
                if(mysqli_stmt_execute($stmt)){
                    $message = "User suspended successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error suspending user: " . mysqli_error($link);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    // Handle user unsuspension WITH REASON
    if(isset($_POST['unsuspend_user'])){
        $suspension_id = $_POST['suspension_id'];
        $unsuspended_by = $_SESSION["id"];
        $revocation_reason = isset($_POST['revocation_reason']) ? trim($_POST['revocation_reason']) : '';
        
        if(empty($revocation_reason)){
            $message = "Please provide a reason for unsuspension.";
            $message_type = "danger";
        } else {
            $sql = "UPDATE user_suspensions SET unsuspended_at = NOW(), unsuspended_by = ?, revocation_reason = ? WHERE id = ?";
            
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "isi", $unsuspended_by, $revocation_reason, $suspension_id);
                
                if(mysqli_stmt_execute($stmt)){
                    $message = "User unsuspended successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error unsuspending user: " . mysqli_error($link);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    // Handle feedback deletion
    if(isset($_POST['delete_feedback'])){
        $feedback_id = $_POST['feedback_id'];
        
        $sql = "DELETE FROM feedback WHERE id = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $feedback_id);
            
            if(mysqli_stmt_execute($stmt)){
                $message = "Feedback deleted successfully!";
                $message_type = "success";
            } else {
                $message = "Error deleting feedback: " . mysqli_error($link);
                $message_type = "danger";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle feedback update
    if(isset($_POST['update_feedback'])){
        $feedback_id = $_POST['feedback_id'];
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];
        
        $sql = "UPDATE feedback SET rating = ?, comment = ? WHERE id = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "isi", $rating, $comment, $feedback_id);
            
            if(mysqli_stmt_execute($stmt)){
                $message = "Feedback updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating feedback: " . mysqli_error($link);
                $message_type = "danger";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle revoke professional status WITH REASON
    if(isset($_POST['revoke_professional'])){
        $user_id = $_POST['user_id'];
        $revocation_reason = isset($_POST['revocation_reason']) ? trim($_POST['revocation_reason']) : '';
        
        if(empty($revocation_reason)){
            $message = "Please provide a reason for revocation.";
            $message_type = "danger";
        } else {
            $sql = "UPDATE users SET user_type = 'customer' WHERE id = ?";
            
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                
                if(mysqli_stmt_execute($stmt)){
                    // Also update professional request status with reason
                    $update_sql = "UPDATE professional_requests SET status = 'rejected', rejection_reason = ? WHERE user_id = ?";
                    if($update_stmt = mysqli_prepare($link, $update_sql)){
                        mysqli_stmt_bind_param($update_stmt, "si", $revocation_reason, $user_id);
                        mysqli_stmt_execute($update_stmt);
                        mysqli_stmt_close($update_stmt);
                    }
                    
                    $message = "Professional status revoked successfully!";
                    $message_type = "success";
                } else {
                    $message = "Error revoking professional status: " . mysqli_error($link);
                    $message_type = "danger";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    // Handle profile update
    if(isset($_POST['update_profile'])){
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $admin_id = $_SESSION["id"];
        
        $sql = "UPDATE users SET full_name = ?, email = ? WHERE id = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ssi", $full_name, $email, $admin_id);
            
            if(mysqli_stmt_execute($stmt)){
                $message = "Profile updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating profile: " . mysqli_error($link);
                $message_type = "danger";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle profile picture upload
    if(isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK){
        $admin_id = $_SESSION["id"];
        $upload_dir = "uploads/profiles/";
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $filename = "profile_" . $admin_id . "_" . time() . "." . $file_extension;
        $target_file = $upload_dir . $filename;
        
        // Validate file type
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        if(in_array(strtolower($file_extension), $allowed_types)){
            // Move uploaded file
            if(move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)){
                // Update database
                $sql = "UPDATE users SET profile_picture = ? WHERE id = ?";
                if($stmt = mysqli_prepare($link, $sql)){
                    mysqli_stmt_bind_param($stmt, "si", $target_file, $admin_id);
                    if(mysqli_stmt_execute($stmt)){
                        $message = "Profile picture updated successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Error updating profile picture: " . mysqli_error($link);
                        $message_type = "danger";
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $message = "Error uploading file";
                $message_type = "danger";
            }
        } else {
            $message = "Invalid file type. Please upload JPG, JPEG, PNG, or GIF files.";
            $message_type = "danger";
        }
    }
    
    // Handle user profile update by admin
    if(isset($_POST['update_user_profile'])){
        $user_id = $_POST['user_id'];
        $username = $_POST['username'];
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $user_type = $_POST['user_type'];
        
        // Start with basic update
        $sql = "UPDATE users SET username = ?, full_name = ?, email = ?, user_type = ? WHERE id = ?";
        
        // Handle profile picture upload for super admins
        if($_SESSION["user_type"] === 'super_admin' && isset($_FILES['user_profile_picture']) && $_FILES['user_profile_picture']['error'] === UPLOAD_ERR_OK){
            $upload_dir = "uploads/profiles/";
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['user_profile_picture']['name'], PATHINFO_EXTENSION);
            $filename = "profile_" . $user_id . "_" . time() . "." . $file_extension;
            $target_file = $upload_dir . $filename;
            
            // Validate file type
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            if(in_array(strtolower($file_extension), $allowed_types)){
                // Move uploaded file
                if(move_uploaded_file($_FILES['user_profile_picture']['tmp_name'], $target_file)){
                    // Update SQL to include profile picture
                    $sql = "UPDATE users SET username = ?, full_name = ?, email = ?, user_type = ?, profile_picture = ? WHERE id = ?";
                }
            }
        }
        
        if($stmt = mysqli_prepare($link, $sql)){
            if(isset($target_file)) {
                mysqli_stmt_bind_param($stmt, "sssssi", $username, $full_name, $email, $user_type, $target_file, $user_id);
            } else {
                mysqli_stmt_bind_param($stmt, "ssssi", $username, $full_name, $email, $user_type, $user_id);
            }
            
            if(mysqli_stmt_execute($stmt)){
                $message = "User profile updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating user profile: " . mysqli_error($link);
                $message_type = "danger";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Handle backup deletion
    if(isset($_POST['delete_backup']) && $_SESSION["user_type"] === 'super_admin'){
        $backup_name = basename($_POST['delete_backup']);
        $backup_path = 'backup/' . $backup_name;
        
        // Additional security check
        $allowed_extensions = ['sql', 'zip'];
        $file_extension = pathinfo($backup_name, PATHINFO_EXTENSION);
        
        if(!in_array($file_extension, $allowed_extensions)) {
            $message = "Invalid file type";
            $message_type = "danger";
        } elseif(file_exists($backup_path) && unlink($backup_path)){
            $message = "Backup file deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting backup file";
            $message_type = "danger";
        }
    }
}

// Function to delete directory recursively
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}

// Function to get rating category from database
function getRatingCategory($rating, $link) {
    $sql = "SELECT * FROM rating_categories WHERE ? BETWEEN min_rating AND max_rating LIMIT 1";
    
    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $rating);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if($row = mysqli_fetch_assoc($result)){
            mysqli_stmt_close($stmt);
            return [
                'name' => $row['category_name'],
                'color' => $row['color'],
                'icon' => $row['icon'] ?? getDefaultRatingIcon($rating),
                'description' => $row['description']
            ];
        }
        mysqli_stmt_close($stmt);
    }
    
    // Default fallback
    return getDefaultRatingCategory($rating);
}

// Function to get default rating icon based on rating
function getDefaultRatingIcon($rating) {
    if ($rating <= 2) {
        return 'fa-frown';
    } elseif ($rating == 3) {
        return 'fa-meh';
    } elseif ($rating == 4) {
        return 'fa-smile';
    } else {
        return 'fa-grin-stars';
    }
}

// Function to get default rating category (fallback)
function getDefaultRatingCategory($rating) {
    if ($rating <= 2) {
        return [
            'name' => 'Poor',
            'color' => '#dc3545',
            'icon' => 'fa-frown',
            'description' => 'Needs improvement'
        ];
    } elseif ($rating == 3) {
        return [
            'name' => 'Average',
            'color' => '#ffc107',
            'icon' => 'fa-meh',
            'description' => 'Satisfactory'
        ];
    } elseif ($rating == 4) {
        return [
            'name' => 'Good',
            'color' => '#28a745',
            'icon' => 'fa-smile',
            'description' => 'Good service'
        ];
    } else {
        return [
            'name' => 'Excellent',
            'color' => '#198754',
            'icon' => 'fa-grin-stars',
            'description' => 'Outstanding service'
        ];
    }
}

// Get admin profile
$admin_profile = [];
$profile_sql = "SELECT username, full_name, email, profile_picture FROM users WHERE id = ?";
if($stmt = mysqli_prepare($link, $profile_sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $admin_profile = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Get pending professional requests
$pending_requests = [];
$requests_sql = "SELECT pr.*, u.username, u.email 
                 FROM professional_requests pr 
                 JOIN users u ON pr.user_id = u.id 
                 WHERE pr.status = 'pending' 
                 ORDER BY pr.created_at DESC";
                 
if($result = mysqli_query($link, $requests_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $pending_requests[] = $row;
    }
    mysqli_free_result($result);
}

// Get all users
$all_users = [];
$users_sql = "SELECT id, username, full_name, email, user_type, profile_picture, created_at FROM users ORDER BY created_at DESC";
if($result = mysqli_query($link, $users_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $all_users[] = $row;
    }
    mysqli_free_result($result);
}

// Get all bookings
$all_bookings = [];
$bookings_sql = "SELECT b.*, u.username as customer_name, s.title as service_title 
                 FROM bookings b 
                 JOIN users u ON b.customer_id = u.id 
                 JOIN services s ON b.service_id = s.id 
                 ORDER BY b.created_at DESC";
                 
if($result = mysqli_query($link, $bookings_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $all_bookings[] = $row;
    }
    mysqli_free_result($result);
}

// Get all reports WITH MEDIA
$all_reports = [];
$reports_sql = "SELECT r.*, 
                       u1.username as reporter_name, 
                       u1.full_name as reporter_full_name,
                       u2.username as reported_user_name,
                       u2.full_name as reported_user_full_name,
                       u2.email as reported_user_email,
                       u2.user_type as reported_user_type,
                       a.username as reviewed_by_name
                FROM reports r 
                JOIN users u1 ON r.reporter_id = u1.id 
                JOIN users u2 ON r.reported_user_id = u2.id 
                LEFT JOIN users a ON r.reviewed_by = a.id
                ORDER BY r.created_at DESC";
                
if($result = mysqli_query($link, $reports_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $all_reports[] = $row;
    }
    mysqli_free_result($result);
}

// Get all feedback and ratings with categories from database
$all_feedback = [];
$feedback_sql = "SELECT f.*, u.username as customer_name, p.full_name as professional_name, s.title as service_title 
                 FROM feedback f 
                 JOIN users u ON f.customer_id = u.id 
                 JOIN users p ON f.professional_id = p.id
                 JOIN bookings b ON f.booking_id = b.id 
                 JOIN services s ON b.service_id = s.id 
                 ORDER BY f.created_at DESC";
                 
if($result = mysqli_query($link, $feedback_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $category = getRatingCategory($row['rating'], $link);
        $row['rating_category'] = $category['name'];
        $row['rating_color'] = $category['color'];
        $row['rating_icon'] = $category['icon'];
        $row['rating_description'] = $category['description'];
        $all_feedback[] = $row;
    }
    mysqli_free_result($result);
}

// Get user suspensions
$user_suspensions = [];
$suspensions_sql = "SELECT us.*, u.username as user_name, a.username as suspended_by_name, 
                    b.username as unsuspended_by_name
                    FROM user_suspensions us
                    JOIN users u ON us.user_id = u.id
                    JOIN users a ON us.suspended_by = a.id
                    LEFT JOIN users b ON us.unsuspended_by = b.id
                    ORDER BY us.suspended_at DESC";
                    
if($result = mysqli_query($link, $suspensions_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $user_suspensions[] = $row;
    }
    mysqli_free_result($result);
}

// Get professionals
$professionals = [];
$professionals_sql = "SELECT u.id, u.username, u.full_name, u.email, u.user_type, u.profile_picture, pr.profession, pr.status as professional_status, pr.rejection_reason 
                      FROM users u 
                      JOIN professional_requests pr ON u.id = pr.user_id 
                      WHERE pr.status = 'approved' 
                      ORDER BY u.created_at DESC";
                      
if($result = mysqli_query($link, $professionals_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $professionals[] = $row;
    }
    mysqli_free_result($result);
}

// Get rejected professionals with reasons
$rejected_professionals = [];
$rejected_sql = "SELECT pr.*, u.username, u.email, u.profile_picture 
                 FROM professional_requests pr 
                 JOIN users u ON pr.user_id = u.id 
                 WHERE pr.status = 'rejected' 
                 ORDER BY pr.reviewed_at DESC";
                 
if($result = mysqli_query($link, $rejected_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $rejected_professionals[] = $row;
    }
    mysqli_free_result($result);
}

// Get statistics
$stats = [];
$stats_sql = array(
    'total_users' => "SELECT COUNT(*) as count FROM users",
    'total_professionals' => "SELECT COUNT(*) as count FROM professional_requests WHERE status = 'approved'",
    'pending_requests' => "SELECT COUNT(*) as count FROM professional_requests WHERE status = 'pending'",
    'rejected_requests' => "SELECT COUNT(*) as count FROM professional_requests WHERE status = 'rejected'",
    'total_services' => "SELECT COUNT(*) as count FROM services",
    'total_bookings' => "SELECT COUNT(*) as count FROM bookings",
    'completed_bookings' => "SELECT COUNT(*) as count FROM bookings WHERE status = 'completed'",
    'total_reports' => "SELECT COUNT(*) as count FROM reports",
    'total_feedback' => "SELECT COUNT(*) as count FROM feedback",
    'avg_rating' => "SELECT AVG(rating) as avg FROM feedback"
);

foreach($stats_sql as $key => $sql){
    if($result = mysqli_query($link, $sql)){
        $row = mysqli_fetch_assoc($result);
        $stats[$key] = $row['count'] ?? $row['avg'] ?? 0;
        mysqli_free_result($result);
    }
}

// Get rating statistics by category from database
$rating_stats = [];
$rating_categories_db = [];
$rating_stats_sql = "SELECT rc.*, COUNT(f.id) as count 
                     FROM rating_categories rc 
                     LEFT JOIN feedback f ON f.rating BETWEEN rc.min_rating AND rc.max_rating 
                     GROUP BY rc.id 
                     ORDER BY rc.min_rating";

if($result = mysqli_query($link, $rating_stats_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $rating_stats[strtolower($row['category_name'])] = $row['count'];
        $rating_categories_db[] = $row;
    }
    mysqli_free_result($result);
} else {
    // Fallback if rating_categories table doesn't exist
    $rating_stats = [
        'poor' => 0,
        'average' => 0,
        'good' => 0,
        'excellent' => 0
    ];
    
    $rating_stats_sql = "SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating";
    if($result = mysqli_query($link, $rating_stats_sql)){
        while($row = mysqli_fetch_assoc($result)){
            $rating = $row['rating'];
            if($rating <= 2){
                $rating_stats['poor'] += $row['count'];
            } elseif($rating == 3){
                $rating_stats['average'] += $row['count'];
            } elseif($rating == 4){
                $rating_stats['good'] += $row['count'];
            } else {
                $rating_stats['excellent'] += $row['count'];
            }
        }
        mysqli_free_result($result);
    }
}

// Get available backups (both SQL and ZIP)
$backups = [];
if(file_exists('backup') && is_dir('backup')){
    $files = scandir('backup', SCANDIR_SORT_DESCENDING);
    foreach($files as $file){
        if($file !== '.' && $file !== '..' && 
           (pathinfo($file, PATHINFO_EXTENSION) === 'sql' || 
            pathinfo($file, PATHINFO_EXTENSION) === 'zip')){
            $file_path = 'backup/' . $file;
            if(file_exists($file_path)) {
                $file_size = filesize($file_path);
                $file_type = pathinfo($file, PATHINFO_EXTENSION);
                $backups[] = [
                    'name' => $file,
                    'size' => $file_size,
                    'date' => date('F j, Y, g:i a', filemtime($file_path)),
                    'type' => $file_type,
                    'icon' => $file_type === 'zip' ? 'fa-file-archive' : 'fa-database',
                    'path' => $file_path
                ];
            }
        }
    }
}

// Get report statistics
$report_stats = [
    'pending' => 0,
    'reviewed' => 0,
    'resolved' => 0,
    'dismissed' => 0,
    'total' => count($all_reports)
];

foreach($all_reports as $report) {
    if(isset($report_stats[$report['status']])) {
        $report_stats[$report['status']]++;
    }
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ArtisanLink</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #a29bfe;
            --success: #00b894;
            --danger: #d63031;
            --warning: #fdcb6e;
            --info: #0984e3;
            --dark: #2d3436;
            --light: #f8f9fa;
        }
        
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.9);
            padding: 0.8rem 1rem;
            border-radius: 8px;
            margin: 2px 0;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255, 255, 255, 0.2);
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .dashboard-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .dashboard-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card {
            text-align: center;
            padding: 25px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .report-card {
            border: none;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid var(--warning);
        }
        
        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .report-card.resolved {
            border-left-color: var(--success);
            opacity: 0.8;
        }
        
        .report-card.dismissed {
            border-left-color: var(--secondary);
            opacity: 0.7;
        }
        
        .report-card.reviewed {
            border-left-color: var(--info);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8em;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--dark);
            font-weight: 500;
            padding: 12px 20px;
        }
        
        .nav-tabs .nav-link.active {
            background: none;
            border-bottom: 3px solid var(--primary);
            color: var(--primary);
            font-weight: 600;
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }
        
        .table td {
            padding: 12px 15px;
            vertical-align: middle;
        }
        
        .profile-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e9ecef;
        }
        
        .profile-img-lg {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .action-buttons .btn {
            margin: 2px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .report-description {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            border-left: 3px solid var(--primary);
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
        }
        
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .quick-stat-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .quick-stat-number {
            font-size: 1.8em;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .quick-stat-label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 15px 15px 0 0;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 92, 231, 0.4);
        }
        
        .search-box {
            background: white;
            border-radius: 25px;
            padding: 8px 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 2px solid #e9ecef;
        }
        
        .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 3px 15px rgba(108, 92, 231, 0.2);
        }
        
        .certificate-img {
            max-width: 100%;
            max-height: 200px;
            object-fit: contain;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 5px;
        }
        
        .portfolio-item {
            position: relative;
            margin-bottom: 15px;
        }
        
        .portfolio-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .portfolio-img:hover {
            transform: scale(1.05);
        }
        
        .detail-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dark);
        }
        
        .detail-value {
            color: #6c757d;
        }
        
        .rating-category-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75em;
            margin-left: 10px;
        }
        
        .rating-poor {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        .rating-average {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .rating-good {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .rating-excellent {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
            border: 1px solid rgba(25, 135, 84, 0.3);
        }
        
        .rejection-reason {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        
        .rating-stats-bar {
            height: 10px;
            border-radius: 5px;
            margin: 5px 0;
        }
        
        .rating-stats-label {
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 3px;
        }
        
        .rating-stats-count {
            font-weight: 600;
            color: var(--dark);
        }
        
        .report-media {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .report-media img, .report-media video {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .media-thumbnail {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .media-thumbnail:hover {
            transform: scale(1.1);
        }
        
        .full-media-modal .modal-body img,
        .full-media-modal .modal-body video {
            max-width: 100%;
            max-height: 70vh;
        }
        
        .backup-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .backup-database {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .backup-zip {
            background-color: rgba(108, 92, 231, 0.1);
            color: #6c5ce7;
            border: 1px solid rgba(108, 92, 231, 0.3);
        }
        
        .backup-size {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        .backup-icon {
            font-size: 1.5em;
            margin-right: 10px;
        }
        
        .backup-progress {
            height: 25px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-4">
                    <h4 class="text-center mb-4 fw-bold">ArtisanLink Admin</h4>
                    <div class="text-center mb-4">
                        <?php if(!empty($admin_profile['profile_picture'])): ?>
                            <img src="<?php echo htmlspecialchars($admin_profile['profile_picture']); ?>" class="profile-img-lg mb-3">
                        <?php else: ?>
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px;">
                                <i class="fas fa-user-tie fa-3x" style="color: var(--primary);"></i>
                            </div>
                        <?php endif; ?>
                        <p class="mb-1 fw-semibold"><?php echo $_SESSION["username"]; ?></p>
                        <p class="mb-0">
                            <span class="badge bg-<?php echo $_SESSION["user_type"] === 'super_admin' ? 'danger' : 'primary'; ?> px-3 py-2">
                                <i class="fas fa-shield-alt me-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $_SESSION["user_type"])); ?>
                            </span>
                        </p>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#dashboard" data-bs-toggle="tab">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#reports" data-bs-toggle="tab">
                                <i class="fas fa-flag me-2"></i> Reports
                                <?php if($report_stats['pending'] > 0): ?>
                                <span class="badge bg-danger float-end"><?php echo $report_stats['pending']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#users" data-bs-toggle="tab">
                                <i class="fas fa-users me-2"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#professionals" data-bs-toggle="tab">
                                <i class="fas fa-briefcase me-2"></i> Professionals
                                <?php if($stats['pending_requests'] > 0): ?>
                                <span class="badge bg-warning float-end"><?php echo $stats['pending_requests']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#bookings" data-bs-toggle="tab">
                                <i class="fas fa-calendar-check me-2"></i> Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#feedback" data-bs-toggle="tab">
                                <i class="fas fa-star me-2"></i> Feedback
                                <?php if(count($all_feedback) > 0): ?>
                                <span class="badge bg-warning float-end"><?php echo count($all_feedback); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#suspensions" data-bs-toggle="tab">
                                <i class="fas fa-ban me-2"></i> Suspensions
                            </a>
                        </li>
                        <?php if($_SESSION["user_type"] === 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="#backup" data-bs-toggle="tab">
                                <i class="fas fa-database me-2"></i> Backup/Restore
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="#profile" data-bs-toggle="tab">
                                <i class="fas fa-user-cog me-2"></i> Profile
                            </a>
                        </li>
                        <li class="nav-item mt-4">
                            <a class="nav-link text-warning" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="col-md-10 p-4" style="background: #f8f9fa;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0" style="color: var(--dark);">Admin Dashboard</h2>
                    <div class="search-box">
                        <i class="fas fa-search text-muted me-2"></i>
                        <input type="text" class="border-0 w-75" placeholder="Search..." id="globalSearch">
                    </div>
                </div>
                
                <?php if(!empty($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <div><?php echo $message; ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="tab-content">
                    <!-- Dashboard Tab -->
                    <div class="tab-pane fade show active" id="dashboard">
                        <!-- Quick Stats -->
                        <div class="quick-stats">
                            <div class="quick-stat-item">
                                <div class="quick-stat-number"><?php echo $stats['total_users']; ?></div>
                                <div class="quick-stat-label">Total Users</div>
                            </div>
                            <div class="quick-stat-item">
                                <div class="quick-stat-number"><?php echo $stats['total_professionals']; ?></div>
                                <div class="quick-stat-label">Professionals</div>
                            </div>
                            <div class="quick-stat-item">
                                <div class="quick-stat-number"><?php echo $report_stats['pending']; ?></div>
                                <div class="quick-stat-label">Pending Reports</div>
                            </div>
                            <div class="quick-stat-item">
                                <div class="quick-stat-number"><?php echo $stats['pending_requests']; ?></div>
                                <div class="quick-stat-label">Pending Requests</div>
                            </div>
                            <div class="quick-stat-item">
                                <div class="quick-stat-number"><?php echo number_format($stats['avg_rating'], 1); ?></div>
                                <div class="quick-stat-label">Avg Rating</div>
                            </div>
                        </div>

                        <!-- Main Stats Cards -->
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="dashboard-card stat-card" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                                    <div class="stat-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h3><?php echo $stats['total_users']; ?></h3>
                                    <p>Total Users</p>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="dashboard-card stat-card" style="background: linear-gradient(135deg, var(--success), #00a085);">
                                    <div class="stat-icon">
                                        <i class="fas fa-briefcase"></i>
                                    </div>
                                    <h3><?php echo $stats['total_professionals']; ?></h3>
                                    <p>Professionals</p>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="dashboard-card stat-card" style="background: linear-gradient(135deg, var(--warning), #f9a825);">
                                    <div class="stat-icon">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <h3><?php echo number_format($stats['avg_rating'], 1); ?></h3>
                                    <p>Avg Rating</p>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="dashboard-card stat-card" style="background: linear-gradient(135deg, var(--info), #0767b1);">
                                    <div class="stat-icon">
                                        <i class="fas fa-concierge-bell"></i>
                                    </div>
                                    <h3><?php echo $stats['total_services']; ?></h3>
                                    <p>Services</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2 text-primary"></i> Platform Overview</h5>
                                    </div>
                                    <div class="card-body">
                                        <p>Total Bookings: <strong><?php echo $stats['total_bookings']; ?></strong></p>
                                        <p>Completed Bookings: <strong><?php echo $stats['completed_bookings']; ?></strong></p>
                                        <p>Total Feedback: <strong><?php echo $stats['total_feedback']; ?></strong></p>
                                        <p>Active Reports: <strong><?php echo $stats['total_reports']; ?></strong></p>
                                        <p>Pending Requests: <strong><?php echo $stats['pending_requests']; ?></strong></p>
                                        <p>Rejected Requests: <strong><?php echo $stats['rejected_requests']; ?></strong></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-star me-2 text-warning"></i> Rating Distribution</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php 
                                        $total_feedback_count = $stats['total_feedback'];
                                        if(isset($rating_categories_db) && !empty($rating_categories_db)): 
                                            foreach($rating_categories_db as $category): 
                                                $category_count = $rating_stats[strtolower($category['category_name'])] ?? 0;
                                                $percentage = $total_feedback_count > 0 ? ($category_count / $total_feedback_count) * 100 : 0;
                                        ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="rating-stats-label">
                                                    <?php echo $category['category_name']; ?> 
                                                    (<?php echo $category['min_rating']; ?>-<?php echo $category['max_rating']; ?>)
                                                    <span style="color: <?php echo $category['color']; ?>;">
                                                        <i class="fas <?php echo getDefaultRatingIcon($category['min_rating']); ?>"></i>
                                                    </span>
                                                </span>
                                                <span class="rating-stats-count"><?php echo $category_count; ?></span>
                                            </div>
                                            <div class="rating-stats-bar" style="background: linear-gradient(90deg, <?php echo $category['color']; ?> <?php echo $percentage; ?>%, #e9ecef <?php echo $percentage; ?>%);"></div>
                                            <small class="text-muted"><?php echo $category['description']; ?></small>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="rating-stats-label">Excellent (5)</span>
                                                <span class="rating-stats-count"><?php echo $rating_stats['excellent']; ?></span>
                                            </div>
                                            <div class="rating-stats-bar" style="background: linear-gradient(90deg, #198754 <?php echo ($rating_stats['excellent']/$total_feedback_count)*100; ?>%, #e9ecef <?php echo ($rating_stats['excellent']/$total_feedback_count)*100; ?>%);"></div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="rating-stats-label">Good (4)</span>
                                                <span class="rating-stats-count"><?php echo $rating_stats['good']; ?></span>
                                            </div>
                                            <div class="rating-stats-bar" style="background: linear-gradient(90deg, #28a745 <?php echo ($rating_stats['good']/$total_feedback_count)*100; ?>%, #e9ecef <?php echo ($rating_stats['good']/$total_feedback_count)*100; ?>%);"></div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="rating-stats-label">Average (3)</span>
                                                <span class="rating-stats-count"><?php echo $rating_stats['average']; ?></span>
                                            </div>
                                            <div class="rating-stats-bar" style="background: linear-gradient(90deg, #ffc107 <?php echo ($rating_stats['average']/$total_feedback_count)*100; ?>%, #e9ecef <?php echo ($rating_stats['average']/$total_feedback_count)*100; ?>%);"></div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="rating-stats-label">Poor (1-2)</span>
                                                <span class="rating-stats-count"><?php echo $rating_stats['poor']; ?></span>
                                            </div>
                                            <div class="rating-stats-bar" style="background: linear-gradient(90deg, #dc3545 <?php echo ($rating_stats['poor']/$total_feedback_count)*100; ?>%, #e9ecef <?php echo ($rating_stats['poor']/$total_feedback_count)*100; ?>%);"></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reports Tab -->
                    <div class="tab-pane fade" id="reports">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-flag me-2 text-danger"></i> User Reports</h5>
                                <div class="d-flex">
                                    <span class="badge bg-warning me-2">Pending: <?php echo $report_stats['pending']; ?></span>
                                    <span class="badge bg-info me-2">Reviewed: <?php echo $report_stats['reviewed']; ?></span>
                                    <span class="badge bg-success me-2">Resolved: <?php echo $report_stats['resolved']; ?></span>
                                    <span class="badge bg-secondary">Total: <?php echo $report_stats['total']; ?></span>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(count($all_reports) > 0): ?>
                                    <?php foreach($all_reports as $report): ?>
                                    <div class="report-card <?php echo $report['status']; ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="card-title mb-1">
                                                        Report #<?php echo $report['id']; ?> - 
                                                        <span class="text-capitalize"><?php echo $report['report_type']; ?></span>
                                                    </h5>
                                                    <p class="card-text mb-1">
                                                        <strong>Reporter:</strong> 
                                                        <?php echo htmlspecialchars($report['reporter_name']); ?>
                                                        (<?php echo htmlspecialchars($report['reporter_full_name']); ?>)
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <strong>Reported User:</strong> 
                                                        <?php echo htmlspecialchars($report['reported_user_name']); ?>
                                                        (<?php echo htmlspecialchars($report['reported_user_full_name']); ?>)
                                                        - <span class="badge bg-<?php echo $report['reported_user_type'] === 'professional' ? 'success' : 'primary'; ?>">
                                                            <?php echo ucfirst($report['reported_user_type']); ?>
                                                        </span>
                                                    </p>
                                                    <p class="card-text mb-1">
                                                        <strong>Reason:</strong> <?php echo htmlspecialchars($report['reason']); ?>
                                                    </p>
                                                    <p class="card-text mb-0">
                                                        <strong>Status:</strong> 
                                                        <span class="status-badge bg-<?php 
                                                            switch($report['status']) {
                                                                case 'pending': echo 'warning'; break;
                                                                case 'reviewed': echo 'info'; break;
                                                                case 'resolved': echo 'success'; break;
                                                                case 'dismissed': echo 'secondary'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo ucfirst($report['status']); ?>
                                                        </span>
                                                    </p>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted">
                                                        Reported: <?php echo date('M j, Y g:i A', strtotime($report['created_at'])); ?>
                                                    </small>
                                                    <?php if($report['reviewed_at']): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            Reviewed: <?php echo date('M j, Y g:i A', strtotime($report['reviewed_at'])); ?>
                                                            <?php if($report['reviewed_by_name']): ?>
                                                                by <?php echo htmlspecialchars($report['reviewed_by_name']); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="report-description">
                                                <strong>Description:</strong>
                                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                                            </div>
                                            
                                            <!-- Report Media Display -->
                                            <?php if(!empty($report['report_media_path']) || !empty($report['report_image'])): ?>
                                            <div class="report-media">
                                                <strong>Attached Media:</strong>
                                                <div class="mt-2">
                                                    <?php 
                                                    $media_path = !empty($report['report_media_path']) ? $report['report_media_path'] : $report['report_image'];
                                                    $media_type = !empty($report['report_media_type']) ? $report['report_media_type'] : 'image';
                                                    $thumb_path = !empty($report['report_media_thumb']) ? $report['report_media_thumb'] : $media_path;
                                                    ?>
                                                    
                                                    <?php if($media_type == 'image'): ?>
                                                        <a href="<?php echo htmlspecialchars($media_path); ?>" data-bs-toggle="modal" data-bs-target="#mediaModal" data-media-type="image" data-media-path="<?php echo htmlspecialchars($media_path); ?>">
                                                            <img src="<?php echo htmlspecialchars($thumb_path); ?>" class="media-thumbnail" alt="Report Media">
                                                        </a>
                                                    <?php elseif($media_type == 'video'): ?>
                                                        <a href="<?php echo htmlspecialchars($media_path); ?>" data-bs-toggle="modal" data-bs-target="#mediaModal" data-media-type="video" data-media-path="<?php echo htmlspecialchars($media_path); ?>">
                                                            <div class="position-relative">
                                                                <img src="<?php echo htmlspecialchars($thumb_path); ?>" class="media-thumbnail" alt="Video Thumbnail">
                                                                <div class="position-absolute top-50 start-50 translate-middle">
                                                                    <i class="fas fa-play-circle fa-3x text-white" style="opacity: 0.8;"></i>
                                                                </div>
                                                            </div>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="<?php echo htmlspecialchars($media_path); ?>" target="_blank" class="btn btn-outline-secondary">
                                                            <i class="fas fa-file-download me-2"></i>Download Document
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="action-buttons d-flex justify-content-between align-items-center mt-3">
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-profile-btn" 
                                                            data-user-id="<?php echo $report['reported_user_id']; ?>"
                                                            data-bs-toggle="modal" data-bs-target="#userProfileModal">
                                                        <i class="fas fa-eye me-1"></i> View User Profile
                                                    </button>
                                                    
                                                    <?php if($report['reported_user_type'] === 'professional'): ?>
                                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#revokeProfessionalModal" 
                                                            data-user-id="<?php echo $report['reported_user_id']; ?>" 
                                                            data-username="<?php echo htmlspecialchars($report['reported_user_name']); ?>">
                                                        <i class="fas fa-times-circle me-1"></i> Revoke Professional
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                        <input type="hidden" name="user_id" value="<?php echo $report['reported_user_id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash me-1"></i> Delete User
                                                        </button>
                                                    </form>
                                                </div>
                                                
                                                <div>
                                                    <?php if($report['status'] === 'pending'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <button type="submit" name="action" value="reviewed" class="btn btn-sm btn-info">
                                                            <i class="fas fa-check me-1"></i> Mark Reviewed
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if($report['status'] !== 'resolved'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <button type="submit" name="action" value="resolved" class="btn btn-sm btn-success">
                                                            <i class="fas fa-check-double me-1"></i> Mark Resolved
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if($report['status'] !== 'dismissed'): ?>
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <button type="submit" name="action" value="dismissed" class="btn btn-sm btn-secondary">
                                                            <i class="fas fa-times me-1"></i> Dismiss
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-flag fa-4x text-muted mb-3"></i>
                                        <h5 class="text-muted">No Reports Found</h5>
                                        <p class="text-muted">There are no user reports to display at this time.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Users Tab -->
                    <div class="tab-pane fade" id="users">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-users me-2 text-primary"></i> User Management</h5>
                                <span class="badge bg-primary">Total: <?php echo count($all_users); ?> users</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Profile</th>
                                                <th>ID</th>
                                                <th>Username</th>
                                                <th>Full Name</th>
                                                <th>Email</th>
                                                <th>Type</th>
                                                <th>Joined</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($all_users as $user): ?>
                                            <tr>
                                                <td>
                                                    <?php if(!empty($user['profile_picture'])): ?>
                                                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="profile-img">
                                                    <?php else: ?>
                                                        <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                            <i class="fas fa-user text-light"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $user['id']; ?></td>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($user['user_type']) {
                                                            case 'admin': echo 'primary'; break;
                                                            case 'super_admin': echo 'danger'; break;
                                                            case 'professional': echo 'success'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editUserModal" data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>" data-full-name="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" data-email="<?php echo htmlspecialchars($user['email']); ?>" data-user-type="<?php echo $user['user_type']; ?>">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#suspendUserModal" data-user-id="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                                            <i class="fas fa-ban"></i> Suspend
                                                        </button>
                                                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" name="delete_user" class="btn btn-sm btn-danger">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Professionals Tab -->
                    <div class="tab-pane fade" id="professionals">
                        <ul class="nav nav-tabs mb-4">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#pendingRequests">Pending Requests (<?php echo count($pending_requests); ?>)</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#approvedProfessionals">Approved (<?php echo count($professionals); ?>)</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#rejectedProfessionals">Rejected (<?php echo count($rejected_professionals); ?>)</a>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Pending Requests Tab -->
                            <div class="tab-pane fade show active" id="pendingRequests">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-clock me-2 text-warning"></i> Pending Professional Requests</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if(count($pending_requests) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Profile</th>
                                                            <th>Name</th>
                                                            <th>Profession</th>
                                                            <th>Age</th>
                                                            <th>Phone</th>
                                                            <th>Applied</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($pending_requests as $request): ?>
                                                        <tr>
                                                            <td>
                                                                <?php if(!empty($request['profile_picture'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($request['profile_picture']); ?>" class="profile-img">
                                                                <?php else: ?>
                                                                    <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                        <i class="fas fa-user text-light"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($request['full_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($request['profession']); ?></td>
                                                            <td><?php echo $request['age'] ?: 'N/A'; ?></td>
                                                            <td><?php echo htmlspecialchars($request['phone']); ?></td>
                                                            <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                                            <td>
                                                                <div class="btn-group">
                                                                    <button type="button" class="btn btn-sm btn-outline-primary view-request-btn" 
                                                                            data-request-id="<?php echo $request['id']; ?>"
                                                                            data-bs-toggle="modal" data-bs-target="#professionalRequestModal">
                                                                        <i class="fas fa-eye"></i> View Details
                                                                    </button>
                                                                    <form method="post" class="d-inline">
                                                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">
                                                                            <i class="fas fa-check"></i> Approve
                                                                        </button>
                                                                    </form>
                                                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectRequestModal" 
                                                                            data-request-id="<?php echo $request['id']; ?>" 
                                                                            data-request-name="<?php echo htmlspecialchars($request['full_name']); ?>">
                                                                        <i class="fas fa-times"></i> Reject
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-center text-muted">No pending professional requests</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Approved Professionals Tab -->
                            <div class="tab-pane fade" id="approvedProfessionals">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-check-circle me-2 text-success"></i> Approved Professionals</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if(count($professionals) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Profile</th>
                                                            <th>ID</th>
                                                            <th>Username</th>
                                                            <th>Full Name</th>
                                                            <th>Email</th>
                                                            <th>Profession</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($professionals as $professional): ?>
                                                        <tr>
                                                            <td>
                                                                <?php if(!empty($professional['profile_picture'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($professional['profile_picture']); ?>" class="profile-img">
                                                                <?php else: ?>
                                                                    <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                        <i class="fas fa-user text-light"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo $professional['id']; ?></td>
                                                            <td><?php echo htmlspecialchars($professional['username']); ?></td>
                                                            <td><?php echo htmlspecialchars($professional['full_name'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($professional['email']); ?></td>
                                                            <td><?php echo htmlspecialchars($professional['profession']); ?></td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#revokeProfessionalModal" 
                                                                        data-user-id="<?php echo $professional['id']; ?>" 
                                                                        data-username="<?php echo htmlspecialchars($professional['username']); ?>">
                                                                    <i class="fas fa-times-circle"></i> Revoke
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-center text-muted">No approved professionals</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Rejected Professionals Tab -->
                            <div class="tab-pane fade" id="rejectedProfessionals">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-times-circle me-2 text-danger"></i> Rejected Professional Requests</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if(count($rejected_professionals) > 0): ?>
                                            <div class="table-responsive">
                                                <table class="table table-striped table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Profile</th>
                                                            <th>Name</th>
                                                            <th>Profession</th>
                                                            <th>Rejection Reason</th>
                                                            <th>Rejected On</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($rejected_professionals as $rejected): ?>
                                                        <tr>
                                                            <td>
                                                                <?php if(!empty($rejected['profile_picture'])): ?>
                                                                    <img src="<?php echo htmlspecialchars($rejected['profile_picture']); ?>" class="profile-img">
                                                                <?php else: ?>
                                                                    <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                        <i class="fas fa-user text-light"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($rejected['full_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($rejected['profession']); ?></td>
                                                            <td>
                                                                <?php if(!empty($rejected['rejection_reason'])): ?>
                                                                    <div class="rejection-reason">
                                                                        <i class="fas fa-comment-alt me-1"></i>
                                                                        <?php echo htmlspecialchars($rejected['rejection_reason']); ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No reason provided</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo $rejected['reviewed_at'] ? date('M j, Y', strtotime($rejected['reviewed_at'])) : 'N/A'; ?></td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-outline-primary view-request-btn" 
                                                                        data-request-id="<?php echo $rejected['id']; ?>"
                                                                        data-bs-toggle="modal" data-bs-target="#professionalRequestModal">
                                                                    <i class="fas fa-eye"></i> View
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-center text-muted">No rejected professional requests</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Bookings Tab -->
                    <div class="tab-pane fade" id="bookings">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-calendar-check me-2 text-info"></i> All Bookings</h5>
                                <span class="badge bg-info">Total: <?php echo count($all_bookings); ?> bookings</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Service</th>
                                                <th>Customer</th>
                                                <th>Date & Time</th>
                                                <th>Status</th>
                                                <th>Price</th>
                                                <th>Booked On</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($all_bookings as $booking): ?>
                                            <tr>
                                                <td><?php echo $booking['id']; ?></td>
                                                <td><?php echo htmlspecialchars($booking['service_title']); ?></td>
                                                <td><?php echo htmlspecialchars($booking['customer_name']); ?></td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($booking['booking_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        switch($booking['status']) {
                                                            case 'confirmed': echo 'success'; break;
                                                            case 'pending': echo 'warning'; break;
                                                            case 'completed': echo 'secondary'; break;
                                                            case 'cancelled': echo 'danger'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                </td>
                                                <td>₱<?php echo number_format($booking['total_price'], 2); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($booking['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Feedback Tab -->
                    <div class="tab-pane fade" id="feedback">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-star me-2 text-warning"></i> Feedback & Ratings Management</h5>
                                <span class="badge bg-warning">Total: <?php echo count($all_feedback); ?> feedback entries</span>
                            </div>
                            <div class="card-body">
                                <!-- Rating Statistics -->
                                <div class="row mb-4">
                                    <?php if(isset($rating_categories_db) && !empty($rating_categories_db)): ?>
                                        <?php foreach($rating_categories_db as $category): ?>
                                        <div class="col-md-3 mb-3">
                                            <div class="text-center p-3 border rounded" style="border-left: 4px solid <?php echo $category['color']; ?> !important;">
                                                <div class="rating-category-badge mb-2" style="background-color: <?php echo $category['color']; ?>20; color: <?php echo $category['color']; ?>; border-color: <?php echo $category['color']; ?>30;">
                                                    <?php echo $category['category_name']; ?>
                                                </div>
                                                <h3 class="mb-1"><?php echo $rating_stats[strtolower($category['category_name'])] ?? 0; ?></h3>
                                                <p class="text-muted mb-0"><?php echo $category['min_rating']; ?>-<?php echo $category['max_rating']; ?> Star Ratings</p>
                                                <small class="text-muted"><?php echo $category['description']; ?></small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <div class="rating-excellent rating-category-badge mb-2">Excellent</div>
                                            <h3 class="mb-1"><?php echo $rating_stats['excellent']; ?></h3>
                                            <p class="text-muted mb-0">5 Star Ratings</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <div class="rating-good rating-category-badge mb-2">Good</div>
                                            <h3 class="mb-1"><?php echo $rating_stats['good']; ?></h3>
                                            <p class="text-muted mb-0">4 Star Ratings</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <div class="rating-average rating-category-badge mb-2">Average</div>
                                            <h3 class="mb-1"><?php echo $rating_stats['average']; ?></h3>
                                            <p class="text-muted mb-0">3 Star Ratings</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <div class="rating-poor rating-category-badge mb-2">Poor</div>
                                            <h3 class="mb-1"><?php echo $rating_stats['poor']; ?></h3>
                                            <p class="text-muted mb-0">1-2 Star Ratings</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if(count($all_feedback) > 0): ?>
                                    <?php foreach($all_feedback as $feedback): ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="card-title">Feedback for: <?php echo htmlspecialchars($feedback['service_title']); ?></h6>
                                                    <p class="card-text mb-1"><strong>Customer:</strong> <?php echo htmlspecialchars($feedback['customer_name']); ?></p>
                                                    <p class="card-text mb-1"><strong>Professional:</strong> <?php echo htmlspecialchars($feedback['professional_name']); ?></p>
                                                    <p class="card-text mb-1">
                                                        <strong>Rating:</strong> 
                                                        <span class="rating" style="color: <?php echo $feedback['rating_color']; ?>;">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <?php if ($i <= $feedback['rating']): ?>
                                                                    <i class="fas fa-star"></i>
                                                                <?php else: ?>
                                                                    <i class="far fa-star"></i>
                                                                <?php endif; ?>
                                                            <?php endfor; ?>
                                                            (<?php echo $feedback['rating']; ?>/5)
                                                            <span class="rating-category-badge" style="background-color: <?php echo $feedback['rating_color']; ?>20; color: <?php echo $feedback['rating_color']; ?>; border-color: <?php echo $feedback['rating_color']; ?>30;">
                                                                <i class="fas <?php echo $feedback['rating_icon']; ?> me-1"></i>
                                                                <?php echo $feedback['rating_category']; ?> - <?php echo $feedback['rating_description']; ?>
                                                            </span>
                                                        </span>
                                                    </p>
                                                    <?php if (!empty($feedback['comment'])): ?>
                                                    <p class="card-text mb-1"><strong>Comment:</strong> <?php echo nl2br(htmlspecialchars($feedback['comment'])); ?></p>
                                                    <?php endif; ?>
                                                    <p class="card-text"><small class="text-muted">Submitted on: <?php echo date('F j, Y, g:i a', strtotime($feedback['created_at'])); ?></small></p>
                                                </div>
                                                <div class="btn-group ms-3">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editFeedbackModal" 
                                                            data-feedback-id="<?php echo $feedback['id']; ?>" 
                                                            data-rating="<?php echo $feedback['rating']; ?>" 
                                                            data-comment="<?php echo htmlspecialchars($feedback['comment']); ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this feedback?');">
                                                        <input type="hidden" name="feedback_id" value="<?php echo $feedback['id']; ?>">
                                                        <button type="submit" name="delete_feedback" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-center text-muted">No feedback available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Suspensions Tab -->
                    <div class="tab-pane fade" id="suspensions">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-ban me-2 text-danger"></i> User Suspensions</h5>
                            </div>
                            <div class="card-body">
                                <?php if(count($user_suspensions) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Action</th>
                                                    <th>Reason</th>
                                                    <th>Duration</th>
                                                    <th>Suspended By</th>
                                                    <th>Suspended At</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($user_suspensions as $suspension): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($suspension['user_name']); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $suspension['action'] === 'suspended' ? 'warning' : 'danger'; ?>">
                                                            <?php echo ucfirst($suspension['action']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($suspension['reason']); ?></td>
                                                    <td><?php echo htmlspecialchars($suspension['duration']); ?></td>
                                                    <td><?php echo htmlspecialchars($suspension['suspended_by_name']); ?></td>
                                                    <td><?php echo date('M j, Y, g:i a', strtotime($suspension['suspended_at'])); ?></td>
                                                    <td>
                                                        <?php if($suspension['unsuspended_at']): ?>
                                                            <span class="badge bg-success">Unsuspended</span>
                                                            <?php if(!empty($suspension['revocation_reason'])): ?>
                                                                <br><small class="text-muted">Reason: <?php echo htmlspecialchars($suspension['revocation_reason']); ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning">Active</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if(!$suspension['unsuspended_at']): ?>
                                                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#unsuspendUserModal" 
                                                                data-suspension-id="<?php echo $suspension['id']; ?>" 
                                                                data-username="<?php echo htmlspecialchars($suspension['user_name']); ?>">
                                                            <i class="fas fa-check"></i> Unsuspend
                                                        </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted">No user suspensions found</p>
                                <?php endif; ?>
                                </div>
                        </div>
                    </div>
                    
                    <!-- Backup/Restore Tab (Super Admin only) -->
                    <?php if($_SESSION["user_type"] === 'super_admin'): ?>
                    <div class="tab-pane fade" id="backup">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-server me-2 text-primary"></i> Full System Backup</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            Creates a password-protected ZIP file containing database and important files.
                                        </div>
                                        <form id="backupForm">
                                            <div class="mb-3">
                                                <label for="backup_type" class="form-label">Backup Type</label>
                                                <select name="backup_type" id="backup_type" class="form-select" required>
                                                    <option value="both">Database + Files (Complete System)</option>
                                                    <option value="database">Database Only</option>
                                                    <option value="files">Files Only</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label for="backup_password" class="form-label">Zip File Password (optional)</label>
                                                <input type="password" class="form-control" id="backup_password" name="backup_password" placeholder="Leave blank for no password">
                                                <small class="text-muted">If provided, the ZIP file will be encrypted with this password.</small>
                                            </div>
                                            <button type="button" id="createBackupBtn" class="btn btn-primary">
                                                <i class="fas fa-download me-2"></i> Create System Backup
                                            </button>
                                            <div id="backupProgress" class="mt-3" style="display: none;">
                                                <div class="progress backup-progress">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                                                </div>
                                                <small class="text-muted">Creating backup... Please wait.</small>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-4">
                                <div class="card dashboard-card">
                                    <div class="card-header bg-white border-0 py-3">
                                        <h5 class="mb-0 fw-bold"><i class="fas fa-file-import me-2 text-info"></i> Database Restore</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-danger">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Warning:</strong> This will overwrite your current database. Always backup first!
                                        </div>
                                        <form method="post" enctype="multipart/form-data">
                                            <div class="mb-3">
                                                <label for="sql_file" class="form-label">Upload SQL Backup File</label>
                                                <input type="file" class="form-control" id="sql_file" name="sql_file" accept=".sql" required>
                                                <small class="text-muted">Only .sql files created by the backup system</small>
                                            </div>
                                            <button type="submit" name="restore_database" class="btn btn-warning" onclick="return confirm('WARNING: This will overwrite your current database. Are you sure?')">
                                                <i class="fas fa-upload me-2"></i> Restore Database
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Backup History -->
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-info"></i> Backup History</h5>
                            </div>
                            <div class="card-body">
                                <?php if(!empty($backups)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>File Name</th>
                                                    <th>Size</th>
                                                    <th>Date Created</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($backups as $backup): ?>
                                                <tr>
                                                    <td>
                                                        <span class="backup-type-badge backup-<?php echo $backup['type']; ?>">
                                                            <i class="fas <?php echo $backup['icon']; ?> me-1"></i>
                                                            <?php echo strtoupper($backup['type']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <i class="fas <?php echo $backup['icon']; ?> backup-icon"></i>
                                                        <?php echo $backup['name']; ?>
                                                    </td>
                                                    <td class="backup-size">
                                                        <?php 
                                                        if($backup['size'] < 1024) {
                                                            echo $backup['size'] . ' B';
                                                        } elseif($backup['size'] < 1048576) {
                                                            echo round($backup['size'] / 1024, 2) . ' KB';
                                                        } else {
                                                            echo round($backup['size'] / 1048576, 2) . ' MB';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo $backup['date']; ?></td>
                                                    <td>
                                                        <a href="backup/<?php echo $backup['name']; ?>" download class="btn btn-sm btn-outline-primary me-2">
                                                            <i class="fas fa-download"></i> Download
                                                        </a>
                                                        <?php if($backup['type'] === 'sql'): ?>
                                                        <a href="backup/<?php echo $backup['name']; ?>" class="btn btn-sm btn-outline-info me-2" target="_blank">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                        <?php endif; ?>
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this backup?');">
                                                            <input type="hidden" name="delete_backup" value="<?php echo $backup['name']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-center text-muted">No backup files found</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Profile Tab -->
                    <div class="tab-pane fade" id="profile">
                        <div class="card dashboard-card">
                            <div class="card-header bg-white border-0 py-3">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-user-cog me-2 text-primary"></i> Admin Profile</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3 text-center mb-4">
                                        <?php if(!empty($admin_profile['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($admin_profile['profile_picture']); ?>" class="profile-img-lg mb-3">
                                        <?php else: ?>
                                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px;">
                                                <i class="fas fa-user-tie fa-3x" style="color: var(--primary);"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <form method="post" enctype="multipart/form-data">
                                            <div class="mb-3">
                                                <label for="profilePicture" class="form-label">Change Profile Picture</label>
                                                <input type="file" class="form-control" id="profilePicture" name="profile_picture" accept="image/*">
                                            </div>
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Upload Picture</button>
                                        </form>
                                    </div>
                                    <div class="col-md-9">
                                        <form method="post">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Username</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin_profile['username']); ?>" disabled>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">User Type</label>
                                                    <input type="text" class="form-control" value="<?php echo ucfirst(str_replace('_', ' ', $_SESSION["user_type"])); ?>" disabled>
                                                </div>
                                            </div>
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Full Name</label>
                                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($admin_profile['full_name'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Email</label>
                                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin_profile['email']); ?>">
                                                </div>
                                            </div>
                                            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Profile Modal -->
    <div class="modal fade" id="userProfileModal" tabindex="-1" aria-labelledby="userProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userProfileModalLabel">User Profile Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="profileLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading user profile...</p>
                    </div>
                    <div id="profileContent" style="display: none;">
                        <!-- Profile content will be loaded here via AJAX -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Media View Modal -->
    <div class="modal fade full-media-modal" id="mediaModal" tabindex="-1" aria-labelledby="mediaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mediaModalLabel">Report Media</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="mediaContent">
                        <!-- Media content will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="mediaDownloadLink" class="btn btn-outline-primary" download>
                        <i class="fas fa-download me-1"></i> Download
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Professional Request Details Modal -->
    <div class="modal fade" id="professionalRequestModal" tabindex="-1" aria-labelledby="professionalRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="professionalRequestModalLabel">Professional Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="requestLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading request details...</p>
                    </div>
                    <div id="requestContent" style="display: none;">
                        <!-- Request content will be loaded here via AJAX -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <div id="requestActions">
                        <!-- Action buttons will be loaded here via AJAX -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Request Modal -->
    <div class="modal fade" id="rejectRequestModal" tabindex="-1" aria-labelledby="rejectRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectRequestModalLabel">Reject Professional Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="request_id" id="reject_request_id">
                        <div class="mb-3">
                            <label class="form-label">Applicant Name</label>
                            <input type="text" id="reject_request_name" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Reason for Rejection *</label>
                            <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="4" required placeholder="Please provide a reason for rejecting this professional request..."></textarea>
                            <small class="text-muted">This reason will be visible to the applicant.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="action" value="reject" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Revoke Professional Modal -->
    <div class="modal fade" id="revokeProfessionalModal" tabindex="-1" aria-labelledby="revokeProfessionalModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="revokeProfessionalModalLabel">Revoke Professional Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="revoke_user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" id="revoke_username" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="revocation_reason" class="form-label">Reason for Revocation *</label>
                            <textarea name="revocation_reason" id="revocation_reason" class="form-control" rows="4" required placeholder="Please provide a reason for revoking professional status..."></textarea>
                            <small class="text-muted">This reason will be recorded and may be visible to the user.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="revoke_professional" class="btn btn-warning">Revoke Professional Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" id="edit_username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">User Type</label>
                                <select name="user_type" id="edit_user_type" class="form-select" required>
                                    <option value="customer">Customer</option>
                                    <option value="professional">Professional</option>
                                    <option value="admin">Admin</option>
                                    <?php if($_SESSION["user_type"] === 'super_admin'): ?>
                                    <option value="super_admin">Super Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" id="edit_full_name" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control" required>
                            </div>
                        </div>
                        <?php if($_SESSION["user_type"] === 'super_admin'): ?>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture</label>
                            <input type="file" name="user_profile_picture" class="form-control" accept="image/*">
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_user_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Suspend User Modal -->
    <div class="modal fade" id="suspendUserModal" tabindex="-1" aria-labelledby="suspendUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="suspendUserModalLabel">Suspend User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="suspend_user_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" id="suspend_username" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration</label>
                            <select name="duration" class="form-select" required>
                                <option value="1 day">1 Day</option>
                                <option value="3 days">3 Days</option>
                                <option value="1 week">1 Week</option>
                                <option value="2 weeks">2 Weeks</option>
                                <option value="1 month">1 Month</option>
                                <option value="permanent">Permanent</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason for Suspension *</label>
                            <textarea name="reason" id="reason" class="form-control" rows="3" required placeholder="Enter reason for suspension"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="suspend_user" class="btn btn-warning">Suspend User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Unsuspend User Modal -->
    <div class="modal fade" id="unsuspendUserModal" tabindex="-1" aria-labelledby="unsuspendUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unsuspendUserModalLabel">Unsuspend User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="suspension_id" id="unsuspend_suspension_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" id="unsuspend_username" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="revocation_reason" class="form-label">Reason for Unsuspension *</label>
                            <textarea name="revocation_reason" id="unsuspend_revocation_reason" class="form-control" rows="3" required placeholder="Enter reason for unsuspending this user..."></textarea>
                            <small class="text-muted">This reason will be recorded in the suspension history.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="unsuspend_user" class="btn btn-success">Unsuspend User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Feedback Modal -->
    <div class="modal fade" id="editFeedbackModal" tabindex="-1" aria-labelledby="editFeedbackModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFeedbackModalLabel">Edit Feedback</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="feedback_id" id="edit_feedback_id">
                        <div class="mb-3">
                            <label class="form-label">Rating</label>
                            <select name="rating" id="edit_rating" class="form-select" required>
                                <option value="1">1 Star (Poor)</option>
                                <option value="2">2 Stars (Poor)</option>
                                <option value="3">3 Stars (Average)</option>
                                <option value="4">4 Stars (Good)</option>
                                <option value="5">5 Stars (Excellent)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comment</label>
                            <textarea name="comment" id="edit_comment" class="form-control" rows="4" placeholder="Enter feedback comment"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_feedback" class="btn btn-primary">Update Feedback</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Backup Modal -->
    <div class="modal fade" id="deleteBackupModal" tabindex="-1" aria-labelledby="deleteBackupModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteBackupModalLabel">Delete Backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this backup file?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBackup">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Backup functionality
            $('#createBackupBtn').click(function() {
                const formData = new FormData();
                formData.append('backup_type', $('#backup_type').val());
                formData.append('backup_password', $('#backup_password').val());
                
                if(!confirm('Creating a backup may take several minutes. Continue?')) {
                    return;
                }
                
                // Show progress bar
                $('#backupProgress').show();
                $('#createBackupBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Creating Backup...');
                
                $.ajax({
                    url: '?ajax=backup',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        try {
                            const result = JSON.parse(response);
                            if(result.success) {
                                alert(result.message);
                                // Trigger download
                                if(result.url) {
                                    window.location.href = result.url;
                                }
                                // Refresh the page to show new backup
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                alert('Error: ' + result.message);
                                $('#backupProgress').hide();
                                $('#createBackupBtn').prop('disabled', false).html('<i class="fas fa-download me-2"></i> Create System Backup');
                            }
                        } catch(e) {
                            alert('Error parsing response');
                            $('#backupProgress').hide();
                            $('#createBackupBtn').prop('disabled', false).html('<i class="fas fa-download me-2"></i> Create System Backup');
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error creating backup: ' + error);
                        $('#backupProgress').hide();
                        $('#createBackupBtn').prop('disabled', false).html('<i class="fas fa-download me-2"></i> Create System Backup');
                    }
                });
            });
            
            // Tab functionality
            if(window.location.hash) {
                const triggerEl = document.querySelector('a[href="' + window.location.hash + '"]');
                if(triggerEl) {
                    const tab = new bootstrap.Tab(triggerEl);
                    tab.show();
                }
            }

            // Update URL hash when tab changes
            const tabLinks = document.querySelectorAll('a[data-bs-toggle="tab"]');
            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    window.location.hash = this.getAttribute('href');
                });
            });

            // Global search functionality
            const globalSearch = document.getElementById('globalSearch');
            if(globalSearch) {
                globalSearch.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    
                    // Search in current active tab
                    const activeTab = document.querySelector('.tab-pane.active');
                    if(activeTab) {
                        const rows = activeTab.querySelectorAll('tbody tr, .card.mb-3, .report-card');
                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            if(text.includes(searchTerm)) {
                                row.style.display = '';
                            } else {
                                row.style.display = 'none';
                            }
                        });
                    }
                });
            }
            
            // Media Modal
            const mediaModal = document.getElementById('mediaModal');
            if (mediaModal) {
                mediaModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const mediaType = button.getAttribute('data-media-type');
                    const mediaPath = button.getAttribute('data-media-path');
                    
                    const modal = this;
                    const mediaContent = modal.querySelector('#mediaContent');
                    const downloadLink = modal.querySelector('#mediaDownloadLink');
                    
                    // Set download link
                    downloadLink.href = mediaPath;
                    downloadLink.download = mediaPath.split('/').pop();
                    
                    // Clear previous content
                    mediaContent.innerHTML = '';
                    
                    // Load media based on type
                    if(mediaType === 'image') {
                        mediaContent.innerHTML = `<img src="${mediaPath}" class="img-fluid" alt="Report Image">`;
                    } else if(mediaType === 'video') {
                        mediaContent.innerHTML = `
                            <video controls class="img-fluid">
                                <source src="${mediaPath}" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        `;
                    }
                });
            }

            // Edit User Modal
            const editUserModal = document.getElementById('editUserModal');
            if (editUserModal) {
                editUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const username = button.getAttribute('data-username');
                    const fullName = button.getAttribute('data-full-name');
                    const email = button.getAttribute('data-email');
                    const userType = button.getAttribute('data-user-type');
                    
                    const modal = this;
                    modal.querySelector('#edit_user_id').value = userId;
                    modal.querySelector('#edit_username').value = username;
                    modal.querySelector('#edit_full_name').value = fullName;
                    modal.querySelector('#edit_email').value = email;
                    modal.querySelector('#edit_user_type').value = userType;
                });
            }

            // Suspend User Modal
            const suspendUserModal = document.getElementById('suspendUserModal');
            if (suspendUserModal) {
                suspendUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const username = button.getAttribute('data-username');
                    
                    const modal = this;
                    modal.querySelector('#suspend_user_id').value = userId;
                    modal.querySelector('#suspend_username').value = username;
                });
            }

            // Unsuspend User Modal
            const unsuspendUserModal = document.getElementById('unsuspendUserModal');
            if (unsuspendUserModal) {
                unsuspendUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const suspensionId = button.getAttribute('data-suspension-id');
                    const username = button.getAttribute('data-username');
                    
                    const modal = this;
                    modal.querySelector('#unsuspend_suspension_id').value = suspensionId;
                    modal.querySelector('#unsuspend_username').value = username;
                });
            }

            // Reject Request Modal
            const rejectRequestModal = document.getElementById('rejectRequestModal');
            if (rejectRequestModal) {
                rejectRequestModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const requestId = button.getAttribute('data-request-id');
                    const requestName = button.getAttribute('data-request-name');
                    
                    const modal = this;
                    modal.querySelector('#reject_request_id').value = requestId;
                    modal.querySelector('#reject_request_name').value = requestName;
                });
            }

            // Revoke Professional Modal
            const revokeProfessionalModal = document.getElementById('revokeProfessionalModal');
            if (revokeProfessionalModal) {
                revokeProfessionalModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const username = button.getAttribute('data-username');
                    
                    const modal = this;
                    modal.querySelector('#revoke_user_id').value = userId;
                    modal.querySelector('#revoke_username').value = username;
                });
            }

            // Edit Feedback Modal
            const editFeedbackModal = document.getElementById('editFeedbackModal');
            if (editFeedbackModal) {
                editFeedbackModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const feedbackId = button.getAttribute('data-feedback-id');
                    const rating = button.getAttribute('data-rating');
                    const comment = button.getAttribute('data-comment');
                    
                    const modal = this;
                    modal.querySelector('#edit_feedback_id').value = feedbackId;
                    modal.querySelector('#edit_rating').value = rating;
                    modal.querySelector('#edit_comment').value = comment;
                });
            }

            // User Profile Modal - AJAX loading
            const userProfileModal = document.getElementById('userProfileModal');
            if (userProfileModal) {
                userProfileModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    
                    // Show loading, hide content
                    document.getElementById('profileLoading').style.display = 'block';
                    document.getElementById('profileContent').style.display = 'none';
                    
                    // Fetch user profile data
                    fetch('get_user_profile.php?user_id=' + userId)
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('profileContent').innerHTML = data;
                            document.getElementById('profileLoading').style.display = 'none';
                            document.getElementById('profileContent').style.display = 'block';
                        })
                        .catch(error => {
                            document.getElementById('profileContent').innerHTML = 
                                '<div class="alert alert-danger">Error loading user profile: ' + error + '</div>';
                            document.getElementById('profileLoading').style.display = 'none';
                            document.getElementById('profileContent').style.display = 'block';
                        });
                });
            }

            // Professional Request Modal - AJAX loading
            const professionalRequestModal = document.getElementById('professionalRequestModal');
            if (professionalRequestModal) {
                professionalRequestModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const requestId = button.getAttribute('data-request-id');
                    
                    // Show loading, hide content
                    document.getElementById('requestLoading').style.display = 'block';
                    document.getElementById('requestContent').style.display = 'none';
                    
                    // Fetch professional request data
                    fetch('get_professional_request.php?request_id=' + requestId)
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('requestContent').innerHTML = data;
                            document.getElementById('requestLoading').style.display = 'none';
                            document.getElementById('requestContent').style.display = 'block';
                            
                            // Add action buttons
                            document.getElementById('requestActions').innerHTML = `
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="request_id" value="${requestId}">
                                    <button type="submit" name="action" value="approve" class="btn btn-success">
                                        <i class="fas fa-check me-1"></i> Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectRequestModal" 
                                        data-request-id="${requestId}" 
                                        data-request-name="${document.getElementById('requestContent').querySelector('.request-name')?.textContent || 'Applicant'}">
                                    <i class="fas fa-times me-1"></i> Reject
                                </button>
                            `;
                        })
                        .catch(error => {
                            document.getElementById('requestContent').innerHTML = 
                                '<div class="alert alert-danger">Error loading request details: ' + error + '</div>';
                            document.getElementById('requestLoading').style.display = 'none';
                            document.getElementById('requestContent').style.display = 'block';
                        });
                });
            }

            // View profile buttons in reports
            document.querySelectorAll('.view-profile-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-user-id');
                    // This will be handled by the modal show event above
                });
            });

            // View request buttons in professionals tab
            document.querySelectorAll('.view-request-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const requestId = this.getAttribute('data-request-id');
                    // This will be handled by the modal show event above
                });
            });

            // Form validation for reject/revoke modals
            const rejectForm = document.querySelector('#rejectRequestModal form');
            if (rejectForm) {
                rejectForm.addEventListener('submit', function(e) {
                    const reason = this.querySelector('#rejection_reason').value.trim();
                    if (!reason) {
                        e.preventDefault();
                        alert('Please provide a reason for rejection.');
                    }
                });
            }

            const revokeForm = document.querySelector('#revokeProfessionalModal form');
            if (revokeForm) {
                revokeForm.addEventListener('submit', function(e) {
                    const reason = this.querySelector('#revocation_reason').value.trim();
                    if (!reason) {
                        e.preventDefault();
                        alert('Please provide a reason for revocation.');
                    }
                });
            }

            const unsuspendForm = document.querySelector('#unsuspendUserModal form');
            if (unsuspendForm) {
                unsuspendForm.addEventListener('submit', function(e) {
                    const reason = this.querySelector('#unsuspend_revocation_reason').value.trim();
                    if (!reason) {
                        e.preventDefault();
                        alert('Please provide a reason for unsuspension.');
                    }
                });
            }
        });
    </script>
</body>
</html>