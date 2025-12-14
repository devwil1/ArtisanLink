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

// Initialize variables
$title = $description = $category = $price = $pricing_type = "";
$title_err = $description_err = $category_err = $price_err = "";

// Get professional's information
$professional_info = [];
$sql = "SELECT pr.profession, pr.skills, u.full_name FROM professional_requests pr 
        JOIN users u ON pr.user_id = u.id 
        WHERE pr.user_id = ? AND pr.status = 'approved'";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $professional_info = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Process form data when submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Validate title
    if (empty(trim($_POST["title"]))) {
        $title_err = "Please enter a service title.";
    } else {
        $title = trim($_POST["title"]);
    }
    
    // Validate description
    if (empty(trim($_POST["description"]))) {
        $description_err = "Please enter a service description.";
    } else {
        $description = trim($_POST["description"]);
    }
    
    // Validate category
    if (empty(trim($_POST["category"]))) {
        $category_err = "Please select a category.";
    } else {
        $category = trim($_POST["category"]);
    }
    
    // Validate price
    if (empty(trim($_POST["price"]))) {
        $price_err = "Please enter a price.";
    } elseif (!is_numeric(trim($_POST["price"]))) {
        $price_err = "Price must be a number.";
    } else {
        $price = trim($_POST["price"]);
    }
    
    // Get pricing type
    $pricing_type = trim($_POST["pricing_type"]);
    
    // Check input errors before inserting in database
    if (empty($title_err) && empty($description_err) && empty($category_err) && empty($price_err)) {
        
        $sql = "INSERT INTO services (professional_id, professional_name, title, description, category, price, pricing_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
         
        if ($stmt = mysqli_prepare($link, $sql)) {
            // Get professional name
            $professional_name = $professional_info['full_name'] ?? $_SESSION['username'];
            
            mysqli_stmt_bind_param($stmt, "issssds", $_SESSION["id"], $professional_name, $title, $description, $category, $price, $pricing_type);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION["success_message"] = "Service added successfully!";
                header("location: my-services.php");
                exit;
            } else {
                echo "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Service - Artisan Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6c5ce7;
            --primary-dark: #5649c0;
            --secondary: #a29bfe;
            --light: #f8f9fa;
            --dark: #2d3436;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 92, 231, 0.4);
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(108, 92, 231, 0.25);
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add New Service</h4>
                    </div>
                    <div class="card-body p-4">
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="title" class="form-label">Service Title *</label>
                                    <input type="text" id="title" name="title" class="form-control <?php echo (!empty($title_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $title; ?>" placeholder="e.g., Professional Plumbing Service">
                                    <div class="invalid-feedback"><?php echo $title_err; ?></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="category" class="form-label">Category *</label>
                                    <select id="category" name="category" class="form-select <?php echo (!empty($category_err)) ? 'is-invalid' : ''; ?>">
                                        <option value="">Select Category</option>
                                        <?php 
                                        // Get categories from professional's skills
                                        if (!empty($professional_info['skills'])) {
                                            $skills = json_decode($professional_info['skills'], true);
                                            if (is_array($skills)) {
                                                foreach ($skills as $skill) {
                                                    $skill_value = $skill['value'] ?? $skill;
                                                    echo '<option value="' . htmlspecialchars($skill_value) . '" ' . ($category == $skill_value ? 'selected' : '') . '>' . htmlspecialchars($skill_value) . '</option>';
                                                }
                                            }
                                        }
                                        // Add profession as category option
                                        if (!empty($professional_info['profession'])) {
                                            echo '<option value="' . htmlspecialchars($professional_info['profession']) . '" ' . ($category == $professional_info['profession'] ? 'selected' : '') . '>' . htmlspecialchars($professional_info['profession']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <div class="invalid-feedback"><?php echo $category_err; ?></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Service Description *</label>
                                <textarea id="description" name="description" class="form-control <?php echo (!empty($description_err)) ? 'is-invalid' : ''; ?>" rows="4" placeholder="Describe your service in detail..."><?php echo $description; ?></textarea>
                                <div class="invalid-feedback"><?php echo $description_err; ?></div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="price" class="form-label">Price (₱) *</label>
                                    <input type="number" id="price" name="price" step="0.01" min="0" class="form-control <?php echo (!empty($price_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $price; ?>" placeholder="0.00">
                                    <div class="invalid-feedback"><?php echo $price_err; ?></div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="pricing_type" class="form-label">Pricing Type</label>
                                    <select id="pricing_type" name="pricing_type" class="form-select">
                                        <option value="per_job" <?php echo $pricing_type == 'per_job' ? 'selected' : ''; ?>>Per Job</option>
                                        <option value="daily" <?php echo $pricing_type == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="negotiable" <?php echo $pricing_type == 'negotiable' ? 'selected' : ''; ?>>Negotiable</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                <a href="my-services.php" class="btn btn-secondary me-md-2">Cancel</a>
                                <button type="submit" class="btn btn-primary">Add Service</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>