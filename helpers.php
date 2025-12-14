<?php
// helpers.php

/**
 * Capitalize first letter of each word in a string
 */
function capitalizeWords($string) {
    return ucwords(strtolower($string));
}

/**
 * Format name as "Last Name, First Name MI"
 */
function formatName($fullName) {
    $nameParts = explode(' ', $fullName);
    if (count($nameParts) >= 2) {
        $lastName = array_pop($nameParts);
        $firstName = implode(' ', $nameParts);
        return capitalizeWords($lastName . ', ' . $firstName);
    }
    return capitalizeWords($fullName);
}

/**
 * Capitalize first letter of sentences
 */
function capitalizeSentences($text) {
    $sentences = preg_split('/([.?!]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';
    
    for ($i = 0; $i < count($sentences); $i += 2) {
        $sentence = trim($sentences[$i]);
        $punctuation = $sentences[$i + 1] ?? '';
        
        if (!empty($sentence)) {
            $sentence = ucfirst(strtolower($sentence));
            $result .= $sentence . $punctuation . ' ';
        }
    }
    
    return trim($result);
}
?>
<?php
session_start();
require_once "config.php";

if(!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo '<div class="alert alert-danger">Invalid user ID</div>';
    exit;
}

$user_id = $_GET['user_id'];

// Get user details
$sql = "SELECT u.*, 
               pr.profession, pr.status as professional_status, pr.skills, pr.experience,
               COUNT(DISTINCT b.id) as total_bookings,
               COUNT(DISTINCT f.id) as total_feedback,
               AVG(f.rating) as avg_rating
        FROM users u
        LEFT JOIN professional_requests pr ON u.id = pr.user_id
        LEFT JOIN bookings b ON u.id = b.customer_id OR u.id = b.professional_id
        LEFT JOIN feedback f ON u.id = f.professional_id
        WHERE u.id = ?
        GROUP BY u.id";

if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if(!$user) {
    echo '<div class="alert alert-danger">User not found</div>';
    exit;
}
?>

<div class="row">
    <div class="col-md-4 text-center">
        <?php if(!empty($user['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" class="img-fluid rounded mb-3" style="max-height: 200px;">
        <?php else: ?>
            <div class="bg-secondary rounded d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 200px; height: 200px;">
                <i class="fas fa-user fa-4x text-light"></i>
            </div>
        <?php endif; ?>
        
        <h4><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h4>
        <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
        
        <div class="mb-3">
            <span class="badge bg-<?php 
                switch($user['user_type']) {
                    case 'admin': echo 'primary'; break;
                    case 'super_admin': echo 'danger'; break;
                    case 'professional': echo 'success'; break;
                    default: echo 'secondary';
                }
            ?> fs-6">
                <?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?>
            </span>
        </div>
        
        <?php if($user['user_type'] === 'professional'): ?>
        <div class="alert alert-info">
            <h6>Professional Info</h6>
            <p class="mb-1"><strong>Profession:</strong> <?php echo htmlspecialchars($user['profession'] ?? 'N/A'); ?></p>
            <p class="mb-0"><strong>Status:</strong> <?php echo htmlspecialchars($user['professional_status'] ?? 'N/A'); ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-8">
        <div class="row mb-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h5 class="card-title"><?php echo $user['total_bookings'] ?? 0; ?></h5>
                        <p class="card-text text-muted">Total Bookings</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h5 class="card-title"><?php echo number_format($user['avg_rating'] ?? 0, 1); ?></h5>
                        <p class="card-text text-muted">Average Rating</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Contact Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($user['municipality'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($user['barangay'] ?? 'N/A'); ?></p>
                <?php if($user['latitude'] && $user['longitude']): ?>
                <p><strong>Coordinates:</strong> <?php echo $user['latitude']; ?>, <?php echo $user['longitude']; ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Account Information</h5>
            </div>
            <div class="card-body">
                <p><strong>Member Since:</strong> <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                <p><strong>Email Verified:</strong> <?php echo $user['email_verified'] ? 'Yes' : 'No'; ?></p>
                <p><strong>Bio:</strong> <?php echo nl2br(htmlspecialchars($user['bio'] ?? 'No bio provided')); ?></p>
            </div>
        </div>
        
        <?php if($user['user_type'] === 'professional' && !empty($user['skills'])): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Professional Skills</h5>
            </div>
            <div class="card-body">
                <?php 
                $skills = json_decode($user['skills'], true);
                if($skills && is_array($skills)):
                    foreach($skills as $skill): ?>
                    <span class="badge bg-primary me-2 mb-2"><?php echo htmlspecialchars($skill['name'] ?? ''); ?> - ₱<?php echo number_format($skill['price'] ?? 0, 2); ?></span>
                <?php endforeach; endif; ?>
                
                <?php if(!empty($user['experience'])): ?>
                <div class="mt-3">
                    <strong>Experience:</strong>
                    <p><?php echo nl2br(htmlspecialchars($user['experience'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>