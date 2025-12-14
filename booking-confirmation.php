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

// Check if booking was successful (you can set this session variable in book-service.php)
if(!isset($_SESSION["booking_success"]) || $_SESSION["booking_success"] !== true){
    header("location: services.php");
    exit;
}

// Get the last booking details for this user
$booking_details = [];
$sql = "SELECT b.*, s.title as service_title, s.price, u.full_name as professional_name, 
               u.profile_picture as professional_photo, pr.profession,
               DATE_FORMAT(b.booking_date, '%W, %M %e, %Y') as formatted_date,
               DATE_FORMAT(b.booking_date, '%h:%i %p') as formatted_time
        FROM bookings b
        JOIN services s ON b.service_id = s.id
        JOIN users u ON b.professional_id = u.id
        JOIN professional_requests pr ON u.id = pr.user_id
        WHERE b.customer_id = ?
        ORDER BY b.created_at DESC
        LIMIT 1";
        
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($result) == 1){
            $booking_details = mysqli_fetch_assoc($result);
        }
    }
    mysqli_stmt_close($stmt);
}

// Get professional contact details
$professional_contacts = [];
$contact_sql = "SELECT contact_type, contact_value, is_primary 
                FROM professional_contacts 
                WHERE professional_id = ? 
                ORDER BY is_primary DESC, contact_type ASC";
                
if($stmt = mysqli_prepare($link, $contact_sql)){
    mysqli_stmt_bind_param($stmt, "i", $booking_details['professional_id']);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $professional_contacts[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Clear the success session variable to prevent re-access
unset($_SESSION["booking_success"]);

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Confirmation - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .confirmation-header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 60px 0;
            text-align: center;
        }
        .confirmation-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-top: -50px;
            position: relative;
            z-index: 1;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #28a745;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: -40px auto 20px;
            color: white;
            font-size: 2rem;
        }
        .professional-photo {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #0d6efd;
        }
        .booking-details {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .status-badge {
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .action-buttons .btn {
            margin: 5px;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
            margin: 30px 0;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #0d6efd;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -23px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid white;
        }
        .timeline-item.current::before {
            background: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.3);
        }
        .contact-item {
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #0d6efd;
        }
        .contact-primary {
            background-color: #e7f3ff;
            border-left-color: #0d6efd;
        }
        .contact-phone {
            border-left-color: #28a745;
        }
        .contact-email {
            border-left-color: #6f42c1;
        }
        .contact-whatsapp {
            border-left-color: #25d366;
        }
        .contact-facebook {
            border-left-color: #1877f2;
        }
        .contact-link {
            border-left-color: #ff6b35;
        }
        .modal-header {
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="confirmation-header">
        <div class="container">
            <h1 class="display-4">Booking Confirmed!</h1>
            <p class="lead">Your service request has been sent to the professional</p>
        </div>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card confirmation-card">
                    <div class="card-body p-5">
                        <div class="success-icon">
                            <i class="fas fa-check"></i>
                        </div>
                        
                        <h2 class="text-center mb-4">Thank You for Your Booking!</h2>
                        <p class="text-center text-muted mb-4">
                            Your booking request has been successfully sent to the professional. 
                            They will respond to your request within 24 hours.
                        </p>

                        <!-- Professional Information -->
                        <div class="row align-items-center mb-4">
                            <div class="col-auto">
                                <?php if (!empty($booking_details['professional_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($booking_details['professional_photo']); ?>" 
                                         class="professional-photo" alt="Professional Photo">
                                <?php else: ?>
                                    <div class="professional-photo bg-primary d-flex align-items-center justify-content-center">
                                        <i class="fas fa-user-tie fa-2x text-white"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col">
                                <h4 class="mb-1"><?php echo htmlspecialchars($booking_details['professional_name']); ?></h4>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars($booking_details['profession']); ?></p>
                                <span class="status-badge">Pending Response</span>
                            </div>
                        </div>

                        <!-- Booking Details -->
                        <div class="booking-details mb-4">
                            <h5 class="mb-3"><i class="fas fa-calendar-check me-2"></i>Booking Details</h5>
                            <div class="detail-item">
                                <strong>Service:</strong>
                                <span><?php echo htmlspecialchars($booking_details['service_title']); ?></span>
                            </div>
                            <div class="detail-item">
                                <strong>Date:</strong>
                                <span><?php echo $booking_details['formatted_date']; ?></span>
                            </div>
                            <div class="detail-item">
                                <strong>Time:</strong>
                                <span><?php echo $booking_details['formatted_time']; ?></span>
                            </div>
                            <div class="detail-item">
                                <strong>Total Amount:</strong>
                                <span class="fw-bold text-primary">₱<?php echo number_format($booking_details['total_price'], 2); ?></span>
                            </div>
                            <?php if (!empty($booking_details['address'])): ?>
                            <div class="detail-item">
                                <strong>Service Address:</strong>
                                <span class="text-end"><?php echo nl2br(htmlspecialchars($booking_details['address'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($booking_details['notes'])): ?>
                            <div class="detail-item">
                                <strong>Special Instructions:</strong>
                                <span class="text-end"><?php echo nl2br(htmlspecialchars($booking_details['notes'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Booking Timeline -->
                        <div class="timeline">
                            <h5 class="mb-3"><i class="fas fa-list-alt me-2"></i>Booking Status</h5>
                            <div class="timeline-item current">
                                <h6 class="text-success">Booking Request Sent</h6>
                                <p class="text-muted mb-0">Your request has been sent to the professional</p>
                                <small class="text-muted"><?php echo date('M j, Y g:i A'); ?></small>
                            </div>
                            <div class="timeline-item">
                                <h6>Professional Response</h6>
                                <p class="text-muted mb-0">Waiting for professional to accept or decline</p>
                            </div>
                            <div class="timeline-item">
                                <h6>Service Confirmation</h6>
                                <p class="text-muted mb-0">Schedule will be finalized</p>
                            </div>
                            <div class="timeline-item">
                                <h6>Service Completion</h6>
                                <p class="text-muted mb-0">Service will be provided as scheduled</p>
                            </div>
                        </div>

                        <!-- Next Steps -->
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>What happens next?</h6>
                            <ul class="mb-0">
                                <li>The professional will review your booking request</li>
                                <li>You'll receive a notification when they respond</li>
                                <li>You can track your booking status in "My Bookings"</li>
                                <li>For urgent inquiries, contact the professional directly</li>
                            </ul>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons text-center">
                            <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#contactModal">
                                <i class="fas fa-phone me-2"></i>View Professional Contacts
                            </button>
                            <a href="services.php" class="btn btn-outline-primary btn-lg">
                                <i class="fas fa-search me-2"></i>Browse More Services
                            </a>
                            <a href="welcome.php" class="btn btn-outline-secondary">
                                <i class="fas fa-home me-2"></i>Back to Home
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card mt-4">
                    <div class="card-body">
                        <h5><i class="fas fa-lightbulb me-2 text-warning"></i>Quick Tips</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <i class="fas fa-phone text-primary me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Be Available</h6>
                                        <p class="text-muted mb-0">Keep your phone nearby for updates</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <i class="fas fa-clock text-primary me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Be Punctual</h6>
                                        <p class="text-muted mb-0">Ensure you're available at the scheduled time</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <i class="fas fa-comments text-primary me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Communicate</h6>
                                        <p class="text-muted mb-0">Discuss any special requirements in advance</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex mb-3">
                                    <i class="fas fa-star text-primary me-3 mt-1"></i>
                                    <div>
                                        <h6 class="mb-1">Leave Feedback</h6>
                                        <p class="text-muted mb-0">Rate your experience after service completion</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Professional Contacts Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel">
                        <i class="fas fa-address-book me-2"></i>
                        Professional Contact Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Professional Info -->
                    <div class="row align-items-center mb-4">
                        <div class="col-auto">
                            <?php if (!empty($booking_details['professional_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($booking_details['professional_photo']); ?>" 
                                     class="professional-photo" alt="Professional Photo">
                            <?php else: ?>
                                <div class="professional-photo bg-primary d-flex align-items-center justify-content-center">
                                    <i class="fas fa-user-tie fa-2x text-white"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <h4 class="mb-1"><?php echo htmlspecialchars($booking_details['professional_name']); ?></h4>
                            <p class="text-muted mb-1"><?php echo htmlspecialchars($booking_details['profession']); ?></p>
                            <span class="badge bg-primary">Professional</span>
                        </div>
                    </div>

                    <!-- Contact Methods -->
                    <div class="mb-4">
                        <h5 class="mb-3"><i class="fas fa-phone me-2 text-success"></i>Contact Methods</h5>
                        
                        <?php if (!empty($professional_contacts)): ?>
                            <?php foreach($professional_contacts as $contact): ?>
                                <?php 
                                $contact_class = 'contact-item ';
                                $contact_icon = 'fas fa-phone';
                                $contact_label = 'Phone';
                                
                                switch($contact['contact_type']) {
                                    case 'phone':
                                        $contact_class .= 'contact-phone';
                                        $contact_icon = 'fas fa-mobile-alt';
                                        $contact_label = 'Mobile';
                                        break;
                                    case 'email':
                                        $contact_class .= 'contact-email';
                                        $contact_icon = 'fas fa-envelope';
                                        $contact_label = 'Email';
                                        break;
                                    case 'whatsapp':
                                        $contact_class .= 'contact-whatsapp';
                                        $contact_icon = 'fab fa-whatsapp';
                                        $contact_label = 'WhatsApp';
                                        break;
                                    case 'facebook':
                                        $contact_class .= 'contact-facebook';
                                        $contact_icon = 'fab fa-facebook';
                                        $contact_label = 'Facebook';
                                        break;
                                    case 'viber':
                                        $contact_class .= 'contact-primary';
                                        $contact_icon = 'fab fa-viber';
                                        $contact_label = 'Viber';
                                        break;
                                    case 'link':
                                        $contact_class .= 'contact-link';
                                        $contact_icon = 'fas fa-link';
                                        $contact_label = 'Website/Link';
                                        break;
                                    default:
                                        $contact_class .= 'contact-primary';
                                }
                                
                                if ($contact['is_primary']) {
                                    $contact_class .= ' contact-primary';
                                }
                                ?>
                                
                                <div class="<?php echo $contact_class; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <i class="<?php echo $contact_icon; ?> me-3 fa-lg"></i>
                                            <div>
                                                <h6 class="mb-1">
                                                    <?php echo $contact_label; ?>
                                                    <?php if ($contact['is_primary']): ?>
                                                        <span class="badge bg-primary ms-2">Primary</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <p class="mb-0 text-muted"><?php echo htmlspecialchars($contact['contact_value']); ?></p>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($contact['contact_type'] == 'phone'): ?>
                                                <a href="tel:<?php echo htmlspecialchars($contact['contact_value']); ?>" 
                                                   class="btn btn-success btn-sm">
                                                    <i class="fas fa-phone me-1"></i>Call
                                                </a>
                                            <?php elseif ($contact['contact_type'] == 'email'): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($contact['contact_value']); ?>" 
                                                   class="btn btn-primary btn-sm">
                                                    <i class="fas fa-envelope me-1"></i>Email
                                                </a>
                                            <?php elseif ($contact['contact_type'] == 'whatsapp'): ?>
                                                <a href="https://wa.me/<?php echo htmlspecialchars($contact['contact_value']); ?>" 
                                                   target="_blank" class="btn btn-success btn-sm" style="background-color: #25d366; border-color: #25d366;">
                                                    <i class="fab fa-whatsapp me-1"></i>Chat
                                                </a>
                                            <?php elseif ($contact['contact_type'] == 'facebook'): ?>
                                                <a href="<?php echo htmlspecialchars($contact['contact_value']); ?>" 
                                                   target="_blank" class="btn btn-primary btn-sm" style="background-color: #1877f2; border-color: #1877f2;">
                                                    <i class="fab fa-facebook me-1"></i>Message
                                                </a>
                                            <?php elseif ($contact['contact_type'] == 'link'): ?>
                                                <a href="<?php echo htmlspecialchars($contact['contact_value']); ?>" 
                                                   target="_blank" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-external-link-alt me-1"></i>Visit
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Available</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No contact information available for this professional yet.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Contact Instructions -->
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Contact Instructions</h6>
                        <ul class="mb-0">
                            <li>Use the primary contact method for urgent matters</li>
                            <li>Be respectful of the professional's time</li>
                            <li>Identify yourself and mention your booking when contacting</li>
                            <li>Keep communications professional and clear</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="welcome.php" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Animate the success icon
            const successIcon = document.querySelector('.success-icon');
            successIcon.style.transform = 'scale(0)';
            setTimeout(() => {
                successIcon.style.transition = 'transform 0.5s ease-out';
                successIcon.style.transform = 'scale(1)';
            }, 100);

            // Automatically show the contact modal
            const contactModal = new bootstrap.Modal(document.getElementById('contactModal'));
            setTimeout(() => {
                contactModal.show();
            }, 1000);

            // Add confetti effect (simple version)
            function createConfetti() {
                const colors = ['#28a745', '#0d6efd', '#ffc107', '#dc3545'];
                for (let i = 0; i < 20; i++) {
                    setTimeout(() => {
                        const confetti = document.createElement('div');
                        confetti.innerHTML = '🎉';
                        confetti.style.position = 'fixed';
                        confetti.style.left = Math.random() * 100 + 'vw';
                        confetti.style.top = '-50px';
                        confetti.style.fontSize = (Math.random() * 20 + 10) + 'px';
                        confetti.style.opacity = '0.8';
                        confetti.style.zIndex = '9999';
                        confetti.style.pointerEvents = 'none';
                        document.body.appendChild(confetti);

                        // Animate confetti
                        const animation = confetti.animate([
                            { top: '-50px', transform: 'rotate(0deg)' },
                            { top: '100vh', transform: 'rotate(360deg)' }
                        ], {
                            duration: Math.random() * 3000 + 2000,
                            easing: 'cubic-bezier(0.1, 0.8, 0.1, 1)'
                        });

                        animation.onfinish = () => confetti.remove();
                    }, i * 100);
                }
            }

            // Trigger confetti on page load
            createConfetti();
        });
    </script>
</body>
</html>