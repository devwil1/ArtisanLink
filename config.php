<?php
// Backup settings
define('BACKUP_DIR', 'admin/backup/');
define('MAX_BACKUP_SIZE', 100 * 1024 * 1024); // 100MB
define('BACKUP_RETENTION_DAYS', 30);

// Upload settings
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Report settings
define('MAX_REPORTS_PER_DAY', 3);
define('REPORT_COOLDOWN_HOURS', 24);

// System settings
define('SITE_NAME', 'ArtisanLink');
define('ADMIN_EMAIL', 'admin@artisanlink.com');
define('SUPPORT_EMAIL', 'support@artisanlink.com');

// Database configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'artisan_link');

// Email configuration - Gmail SMTP
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'williangonzales04@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'vakg jhkg dorf sqvu'); // Your app password
define('SMTP_FROM_EMAIL', 'williangonzales04@gmail.com'); // Use Gmail address here too
define('SMTP_FROM_NAME', 'Artisan Link');

// Attempt to connect to MySQL database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// Include PHPMailer if exists
$phpmailer_path = __DIR__ . '/PHPMailer/src/PHPMailer.php';
if (file_exists($phpmailer_path)) {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
    $phpmailer_available = true;
} else {
    $phpmailer_available = false;
}

// Function to calculate distance between two coordinates
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // in kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

// Function to create DB backup
function backupDatabase($backupDir = "backups") {
    $dumpPath = "C:\\xampp\\mysql\\bin\\mysqldump.exe"; // adjust if needed
    $fileName = $backupDir . "/artisan_link_" . date("Y-m-d_H-i-s") . ".sql";

    // Ensure backup folder exists
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    $command = "\"$dumpPath\" -h " . DB_SERVER . " -u " . DB_USERNAME .
               (DB_PASSWORD ? " -p" . DB_PASSWORD : "") .
               " " . DB_NAME . " > \"$fileName\"";

    system($command, $return_var);

    return ($return_var === 0) ? $fileName : false;
}

// Function to send email
function sendEmail($to, $subject, $body) {
    global $phpmailer_available;
    
    if ($phpmailer_available) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            
            // Debug settings (comment out in production)
            $mail->SMTPDebug = 0; // 0 = off, 1 = client messages, 2 = client and server messages
            $mail->Debugoutput = 'error_log';

            // Recipients
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body); // Plain text version

            $mail->send();
            error_log("Email sent successfully to: $to");
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            
            // For now, use test mode as fallback
            return sendEmailTest($to, $subject, $body);
        }
    } else {
        // PHPMailer not available, use test mode
        error_log("PHPMailer not available, using test mode");
        return sendEmailTest($to, $subject, $body);
    }
}

// Test function to save emails to file
function sendEmailTest($to, $subject, $body) {
    $logDir = __DIR__ . '/email_logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $filename = $logDir . '/email_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.html';
    $content = "<!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { background: #f0f0f0; padding: 20px; border-radius: 5px; }
            .content { margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            .pin { font-size: 24px; font-weight: bold; color: #4361ee; padding: 10px; background: #f8f9fa; text-align: center; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h2>TEST EMAIL - NOT ACTUALLY SENT</h2>
            <p><strong>To:</strong> $to</p>
            <p><strong>Subject:</strong> $subject</p>
            <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>File:</strong> $filename</p>
        </div>
        <hr>
        <div class='content'>$body</div>
        <hr>
        <p><em>This email was saved to a file for testing purposes. In production, this would be sent via SMTP.</em></p>
    </body>
    </html>";
    
    if (file_put_contents($filename, $content)) {
        error_log("Test email saved to: $filename");
        
        // Also output PIN if it's in the body (for password reset)
        if (preg_match('/\b(\d{6})\b/', $body, $matches)) {
            error_log("PIN for password reset: " . $matches[1]);
        }
        
        return true;
    }
    
    return false;
}

if(!class_exists('ZipArchive')) {
    die("ZipArchive extension is required for backup functionality. Please enable it in your PHP configuration.");
}
?>