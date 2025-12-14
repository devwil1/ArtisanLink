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

// Get professional's services
$services = [];
$sql = "SELECT * FROM services WHERE professional_id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $services[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Handle service deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_service"])) {
    $service_id = $_POST["service_id"];
    
    $delete_sql = "DELETE FROM services WHERE id = ? AND professional_id = ?";
    if ($stmt = mysqli_prepare($link, $delete_sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $service_id, $_SESSION["id"]);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION["success_message"] = "Service deleted successfully!";
            header("location: my-services.php");
            exit;
        } else {
            $_SESSION["error_message"] = "Error deleting service.";
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
    <title>My Services - Artisan Link</title>
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
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
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
        
        .btn-primary {
            background: linear-gradient(135deg, var(--info), #74b9ff);
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(116, 185, 255, 0.4);
        }
        
        .service-badge {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            margin: 3px;
            display: inline-block;
            font-weight: 500;
        }
        
        .price-badge {
            background: linear-gradient(135deg, var(--success), #00a085);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .pricing-badge {
            background: rgba(108, 92, 231, 0.1);
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .rating-stars {
            color: var(--warning);
        }
        
        .btn-outline-primary {
            border: 1px solid var(--primary);
            color: var(--primary);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.875rem;
            transition: all 0.3s;
        }
        
        .btn-outline-primary:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-outline-danger {
            border: 1px solid var(--danger);
            color: var(--danger);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.875rem;
            transition: all 0.3s;
        }
        
        .btn-outline-danger:hover {
            background: var(--danger);
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12 mb-4">
                <h2 class="section-title">My Services</h2>
                <p class="text-muted">Manage your services and skills offered to clients.</p>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION["success_message"])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION["success_message"]; unset($_SESSION["success_message"]); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION["error_message"])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION["error_message"]; unset($_SESSION["error_message"]); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card text-center h-100">
                    <div class="card-body d-flex flex-column">
                        <i class="fas fa-plus-circle fa-3x text-primary mb-3"></i>
                        <h5 class="card-title">Add New Service</h5>
                        <p class="card-text flex-grow-1">Create a new service offering to attract more clients.</p>
                        <a href="add-service.php" class="btn btn-primary mt-auto">
                            <i class="fas fa-plus me-2"></i>Add Service
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>My Service List</h5>
                        <span class="badge bg-light text-dark"><?php echo count($services); ?> Services</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($services) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Service Title</th>
                                            <th>Category</th>
                                            <th>Price</th>
                                            <th>Pricing Type</th>
                                            <th>Rating</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($services as $service): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($service['title']); ?></strong>
                                                <?php if (!empty($service['description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars(substr($service['description'], 0, 50)); ?><?php echo strlen($service['description']) > 50 ? '...' : ''; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="service-badge"><?php echo htmlspecialchars($service['category']); ?></span>
                                            </td>
                                            <td>
                                                <span class="price-badge">₱<?php echo number_format($service['price'], 2); ?></span>
                                            </td>
                                            <td>
                                                <span class="pricing-badge">
                                                    <?php 
                                                    $pricing_type = $service['pricing_type'];
                                                    if ($pricing_type == 'per_job') {
                                                        echo 'Per Job';
                                                    } elseif ($pricing_type == 'daily') {
                                                        echo 'Daily';
                                                    } elseif ($pricing_type == 'negotiable') {
                                                        echo 'Negotiable';
                                                    } else {
                                                        echo ucfirst($pricing_type);
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="rating-stars">
                                                    <?php 
                                                    $rating = $service['rating'] ?? 0;
                                                    $total_ratings = $service['total_ratings'] ?? 0;
                                                    
                                                    for ($i = 1; $i <= 5; $i++): 
                                                        if ($i <= $rating): ?>
                                                            <i class="fas fa-star"></i>
                                                        <?php else: ?>
                                                            <i class="far fa-star"></i>
                                                        <?php endif;
                                                    endfor; ?>
                                                    
                                                    <?php if ($total_ratings > 0): ?>
                                                        <small class="text-muted ms-1">(<?php echo $total_ratings; ?>)</small>
                                                    <?php else: ?>
                                                        <small class="text-muted ms-1">No ratings</small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="edit-service.php?id=<?php echo $service['id']; ?>" class="btn btn-outline-primary btn-sm" title="Edit Service">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="post" style="display:inline;">
                                                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                                        <button type="submit" name="delete_service" class="btn btn-outline-danger btn-sm" title="Delete Service" onclick="return confirm('Are you sure you want to delete this service? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Service Statistics -->
                            <div class="row mt-4">
                                <div class="col-md-4 text-center">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h3 class="text-primary"><?php echo count($services); ?></h3>
                                            <p class="mb-0 text-muted">Total Services</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h3 class="text-success">
                                                <?php
                                                $total_ratings = array_sum(array_column($services, 'total_ratings'));
                                                echo $total_ratings;
                                                ?>
                                            </h3>
                                            <p class="mb-0 text-muted">Total Reviews</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h3 class="text-warning">
                                                <?php
                                                $services_with_ratings = array_filter($services, function($service) {
                                                    return ($service['total_ratings'] ?? 0) > 0;
                                                });
                                                
                                                if (count($services_with_ratings) > 0) {
                                                    $avg_rating = array_sum(array_column($services_with_ratings, 'rating')) / count($services_with_ratings);
                                                    echo number_format($avg_rating, 1);
                                                } else {
                                                    echo '0.0';
                                                }
                                                ?>
                                            </h3>
                                            <p class="mb-0 text-muted">Average Rating</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-briefcase fa-4x text-muted mb-3"></i>
                                <h4>No services added yet</h4>
                                <p class="text-muted mb-4">Start by adding your first service to attract clients and grow your business.</p>
                                <a href="add-service.php" class="btn btn-primary btn-lg">
                                    <i class="fas fa-plus me-2"></i>Add Your First Service
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <?php if (count($services) > 0): ?>
                <div class="card mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Quick Actions</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="add-service.php" class="btn btn-outline-primary">
                                <i class="fas fa-plus me-2"></i>Add Another Service
                            </a>
                            <a href="professional-profile.php" class="btn btn-outline-secondary">
                                <i class="fas fa-user me-2"></i>View My Profile
                            </a>
                            <a href="professional-bookings.php" class="btn btn-outline-success">
                                <i class="fas fa-calendar me-2"></i>View Bookings
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enhanced confirmation for delete
        document.addEventListener('DOMContentLoaded', function() {
            const deleteForms = document.querySelectorAll('form[action*="delete_service"]');
            deleteForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to delete this service? This action cannot be undone and will remove all associated data.')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>