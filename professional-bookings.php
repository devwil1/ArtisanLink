<?php
// Initialize the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
// Check if the user is logged in and is a professional
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["user_type"] != "professional"){
    header("location: login.php");
    exit;
}

// Include config file
require_once "config.php";

// Get professional's bookings grouped by status
$bookings_by_status = [
    'pending' => [],
    'confirmed' => [],
    'completed' => [],
    'cancelled' => []
];

$sql = "SELECT b.*, u.username as customer_name, u.full_name as customer_full_name, 
               s.title as service_title, s.price as service_price
        FROM bookings b
        JOIN users u ON b.customer_id = u.id
        JOIN services s ON b.service_id = s.id
        WHERE b.professional_id = ?
        ORDER BY b.booking_date DESC";
        
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $bookings_by_status[$row['status']][] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Handle booking status update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_booking"])) {
    $booking_id = $_POST["booking_id"];
    $status = $_POST["status"];
    $response_message = $_POST["response_message"] ?? '';
    
    $update_sql = "UPDATE bookings SET status = ?, professional_response = ?, response_message = ? WHERE id = ? AND professional_id = ?";
    if ($stmt = mysqli_prepare($link, $update_sql)) {
        $professional_response = ($status == 'confirmed') ? 'accepted' : (($status == 'cancelled') ? 'rejected' : 'pending');
        mysqli_stmt_bind_param($stmt, "sssii", $status, $professional_response, $response_message, $booking_id, $_SESSION["id"]);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION["success_message"] = "Booking updated successfully!";
            header("location: professional-bookings.php");
            exit;
        } else {
            $_SESSION["error_message"] = "Error updating booking.";
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Bookings - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #a29bfe;
            --light: #f8f9fa;
            --dark: #2d3436;
            --success: #00b894;
            --danger: #d63031;
            --warning: #fdcb6e;
            --info: #0984e3;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            overflow: hidden;
        }
        
        .section-title {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 25px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 3px;
        }
        
        .booking-status {
            font-size: 0.8em;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .booking-card {
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }
        
        .booking-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .nav-pills .nav-link {
            color: var(--dark);
            font-weight: 500;
            border-radius: 10px;
            margin: 5px;
            padding: 10px 20px;
            transition: all 0.3s;
        }
        
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 10px rgba(108, 92, 231, 0.3);
        }
        
        .nav-pills .nav-link:hover:not(.active) {
            background-color: #e9ecef;
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        .status-badge {
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12 mb-4">
                <h2 class="section-title">My Bookings</h2>
                <p class="text-muted">Manage your client bookings and appointments.</p>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION["success_message"])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION["success_message"]; unset($_SESSION["success_message"]); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION["error_message"])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION["error_message"]; unset($_SESSION["error_message"]); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Status Tabs -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <ul class="nav nav-pills mb-4 justify-content-center" id="bookingTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending" type="button" role="tab">
                                    <i class="fas fa-clock me-2"></i>Pending
                                    <span class="status-badge bg-warning text-dark"><?php echo count($bookings_by_status['pending']); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="confirmed-tab" data-bs-toggle="pill" data-bs-target="#confirmed" type="button" role="tab">
                                    <i class="fas fa-check-circle me-2"></i>Confirmed
                                    <span class="status-badge bg-info text-white"><?php echo count($bookings_by_status['confirmed']); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="completed-tab" data-bs-toggle="pill" data-bs-target="#completed" type="button" role="tab">
                                    <i class="fas fa-check-double me-2"></i>Completed
                                    <span class="status-badge bg-success text-white"><?php echo count($bookings_by_status['completed']); ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="cancelled-tab" data-bs-toggle="pill" data-bs-target="#cancelled" type="button" role="tab">
                                    <i class="fas fa-times-circle me-2"></i>Cancelled
                                    <span class="status-badge bg-danger text-white"><?php echo count($bookings_by_status['cancelled']); ?></span>
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="bookingTabsContent">
                            <!-- Pending Tab -->
                            <div class="tab-pane fade show active" id="pending" role="tabpanel">
                                <?php if (count($bookings_by_status['pending']) > 0): ?>
                                    <?php foreach ($bookings_by_status['pending'] as $booking): ?>
                                    <div class="card booking-card mb-4">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($booking['service_title']); ?></h5>
                                                    <p class="mb-1"><strong>Client:</strong> <?php echo htmlspecialchars($booking['customer_full_name'] ?: $booking['customer_name']); ?></p>
                                                    <p class="mb-1"><strong>Date & Time:</strong> <?php echo date('F j, Y g:i A', strtotime($booking['booking_date'])); ?></p>
                                                    <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                    <p class="mb-1"><strong>Total Price:</strong> ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                                    <?php if (!empty($booking['notes'])): ?>
                                                        <p class="mb-1"><strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <span class="booking-status status-<?php echo $booking['status']; ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                    
                                                    <div class="mt-3">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                            <input type="hidden" name="status" value="confirmed">
                                                            <button type="submit" name="update_booking" class="btn btn-success btn-sm">Accept</button>
                                                        </form>
                                                        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $booking['id']; ?>">Reject</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Reject Modal -->
                                    <div class="modal fade" id="rejectModal<?php echo $booking['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reject Booking</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="post">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <div class="mb-3">
                                                            <label for="response_message" class="form-label">Reason for rejection (optional):</label>
                                                            <textarea class="form-control" id="response_message" name="response_message" rows="3"></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_booking" class="btn btn-danger">Confirm Rejection</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-clock"></i>
                                        <h4>No Pending Bookings</h4>
                                        <p>You don't have any pending booking requests at the moment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Confirmed Tab -->
                            <div class="tab-pane fade" id="confirmed" role="tabpanel">
                                <?php if (count($bookings_by_status['confirmed']) > 0): ?>
                                    <?php foreach ($bookings_by_status['confirmed'] as $booking): ?>
                                    <div class="card booking-card mb-4">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($booking['service_title']); ?></h5>
                                                    <p class="mb-1"><strong>Client:</strong> <?php echo htmlspecialchars($booking['customer_full_name'] ?: $booking['customer_name']); ?></p>
                                                    <p class="mb-1"><strong>Date & Time:</strong> <?php echo date('F j, Y g:i A', strtotime($booking['booking_date'])); ?></p>
                                                    <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                    <p class="mb-1"><strong>Total Price:</strong> ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                                    <?php if (!empty($booking['notes'])): ?>
                                                        <p class="mb-1"><strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <span class="booking-status status-<?php echo $booking['status']; ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                    
                                                    <div class="mt-3">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                            <input type="hidden" name="status" value="completed">
                                                            <button type="submit" name="update_booking" class="btn btn-primary btn-sm">Mark Complete</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-check-circle"></i>
                                        <h4>No Confirmed Bookings</h4>
                                        <p>You don't have any confirmed bookings at the moment.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Completed Tab -->
                            <div class="tab-pane fade" id="completed" role="tabpanel">
                                <?php if (count($bookings_by_status['completed']) > 0): ?>
                                    <?php foreach ($bookings_by_status['completed'] as $booking): ?>
                                    <div class="card booking-card mb-4">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($booking['service_title']); ?></h5>
                                                    <p class="mb-1"><strong>Client:</strong> <?php echo htmlspecialchars($booking['customer_full_name'] ?: $booking['customer_name']); ?></p>
                                                    <p class="mb-1"><strong>Date & Time:</strong> <?php echo date('F j, Y g:i A', strtotime($booking['booking_date'])); ?></p>
                                                    <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                    <p class="mb-1"><strong>Total Price:</strong> ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                                    <?php if (!empty($booking['notes'])): ?>
                                                        <p class="mb-1"><strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <span class="booking-status status-<?php echo $booking['status']; ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                    <div class="mt-3">
                                                        <span class="text-success"><i class="fas fa-check-double me-1"></i>Completed</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-check-double"></i>
                                        <h4>No Completed Bookings</h4>
                                        <p>You haven't completed any bookings yet.</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Cancelled Tab -->
                            <div class="tab-pane fade" id="cancelled" role="tabpanel">
                                <?php if (count($bookings_by_status['cancelled']) > 0): ?>
                                    <?php foreach ($bookings_by_status['cancelled'] as $booking): ?>
                                    <div class="card booking-card mb-4">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($booking['service_title']); ?></h5>
                                                    <p class="mb-1"><strong>Client:</strong> <?php echo htmlspecialchars($booking['customer_full_name'] ?: $booking['customer_name']); ?></p>
                                                    <p class="mb-1"><strong>Date & Time:</strong> <?php echo date('F j, Y g:i A', strtotime($booking['booking_date'])); ?></p>
                                                    <p class="mb-1"><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                    <p class="mb-1"><strong>Total Price:</strong> ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                                    <?php if (!empty($booking['notes'])): ?>
                                                        <p class="mb-1"><strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($booking['response_message'])): ?>
                                                        <p class="mb-1"><strong>Reason:</strong> <?php echo htmlspecialchars($booking['response_message']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <span class="booking-status status-<?php echo $booking['status']; ?>">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                    <div class="mt-3">
                                                        <span class="text-danger"><i class="fas fa-times-circle me-1"></i>Cancelled</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-times-circle"></i>
                                        <h4>No Cancelled Bookings</h4>
                                        <p>You haven't cancelled any bookings.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php 
        $total_bookings = count($bookings_by_status['pending']) + count($bookings_by_status['confirmed']) + 
                         count($bookings_by_status['completed']) + count($bookings_by_status['cancelled']);
        if ($total_bookings === 0): ?>
            <div class="card text-center py-5 mt-4">
                <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                <h4>No bookings yet</h4>
                <p>When clients book your services, they will appear here.</p>
                <a href="my-services.php" class="btn btn-primary">Manage Your Services</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>