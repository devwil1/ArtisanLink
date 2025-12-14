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

// Get notifications
$notifications = [];
$sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Mark all notifications as read
$update_sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
if ($stmt = mysqli_prepare($link, $update_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Notifications</h1>
            <a href="welcome.php" class="btn btn-outline-primary">Back to Dashboard</a>
        </div>
        
        <?php if (count($notifications) > 0) : ?>
            <div class="list-group">
                <?php foreach ($notifications as $notification) : ?>
                    <div class="list-group-item <?php echo $notification['is_read'] ? '' : 'list-group-item-primary'; ?>">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></h6>
                            <small><?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?></small>
                        </div>
                        <?php if ($notification['type'] == 'booking' && $notification['related_id']) : ?>
                            <div class="mt-2">
                                <a href="booking-details.php?id=<?php echo $notification['related_id']; ?>" class="btn btn-sm btn-outline-primary">View Booking</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-center py-5">
                <i class="fas fa-bell fa-3x text-muted mb-3"></i>
                <h4>No notifications</h4>
                <p class="text-muted">You'll be notified when you receive new booking requests.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>