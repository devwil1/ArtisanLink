<?php
// Script to download and install PHPMailer
$url = 'https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip';
$zipFile = 'PHPMailer-master.zip';
$extractPath = __DIR__;

echo "Downloading PHPMailer...<br>";
$zipContent = file_get_contents($url);
if ($zipContent !== false) {
    file_put_contents($zipFile, $zipContent);
    
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $zip->extractTo($extractPath);
        $zip->close();
        
        // Rename folder
        if (rename('PHPMailer-master', 'PHPMailer')) {
            echo "PHPMailer installed successfully!<br>";
        } else {
            echo "Failed to rename folder.<br>";
        }
        
        // Delete zip file
        unlink($zipFile);
        
        echo "PHPMailer is now ready to use.";
    } else {
        echo "Failed to extract ZIP file.<br>";
    }
} else {
    echo "Failed to download PHPMailer. Please download manually:<br>";
    echo "1. Go to: https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip<br>";
    echo "2. Download and extract to your project folder<br>";
    echo "3. Rename 'PHPMailer-master' to 'PHPMailer'<br>";
}
?>