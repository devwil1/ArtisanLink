<?php
session_start();
require_once "config.php";

if(!isset($_GET['request_id']) || !is_numeric($_GET['request_id'])) {
    echo '<div class="alert alert-danger">Invalid request ID</div>';
    exit;
}

$request_id = $_GET['request_id'];

// Get professional request details
$sql = "SELECT pr.*, u.username, u.email, u.profile_picture, 
               pc.contact_value as phone,
               pi.image_path as work_sample,
               pcert.certificate_name, pcert.issuing_organization
        FROM professional_requests pr
        LEFT JOIN users u ON pr.user_id = u.id
        LEFT JOIN professional_contacts pc ON pr.user_id = pc.professional_id AND pc.is_primary = 1
        LEFT JOIN portfolio_images pi ON pr.user_id = pi.professional_id
        LEFT JOIN professional_certifications pcert ON pr.user_id = pcert.professional_id
        WHERE pr.id = ?";

if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if(!$request) {
    echo '<div class="alert alert-danger">Request not found</div>';
    exit;
}

// Get additional details
$skills = json_decode($request['skills'], true);
?>

<div class="row">
    <div class="col-md-4 text-center">
        <?php if(!empty($request['profile_picture'])): ?>
            <img src="<?php echo htmlspecialchars($request['profile_picture']); ?>" class="img-fluid rounded mb-3" style="max-height: 200px;">
        <?php else: ?>
            <div class="bg-secondary rounded d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 200px; height: 200px;">
                <i class="fas fa-user-tie fa-4x text-light"></i>
            </div>
        <?php endif; ?>
        
        <h4 class="request-name"><?php echo htmlspecialchars($request['full_name']); ?></h4>
        <p class="text-muted">@<?php echo htmlspecialchars($request['username']); ?></p>
        
        <div class="mb-3">
            <span class="badge bg-<?php echo $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'approved' ? 'success' : 'danger'); ?> fs-6">
                <?php echo ucfirst($request['status']); ?>
            </span>
        </div>
        
        <?php if(!empty($request['work_sample'])): ?>
        <div class="mt-3">
            <h6>Work Sample</h6>
            <img src="<?php echo htmlspecialchars($request['work_sample']); ?>" class="img-thumbnail" style="max-height: 150px;">
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Application Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Profession:</strong> <?php echo htmlspecialchars($request['profession']); ?></p>
                        <p><strong>Age:</strong> <?php echo $request['age']; ?> years</p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($request['phone'] ?? $request['phone']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Pricing Type:</strong> <?php echo ucfirst(str_replace('_', ' ', $request['pricing_type'])); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($request['municipality'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($request['barangay'] ?? 'N/A'); ?></p>
                        <p><strong>Applied On:</strong> <?php echo date('F j, Y', strtotime($request['created_at'])); ?></p>
                    </div>
                </div>
                
                <p><strong>Address:</strong> <?php echo htmlspecialchars($request['address']); ?></p>
            </div>
        </div>
        
        <?php if(!empty($request['experience'])): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Experience</h5>
            </div>
            <div class="card-body">
                <p><?php echo nl2br(htmlspecialchars($request['experience'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if($skills && is_array($skills)): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Skills & Pricing</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Skill/Service</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($skills as $skill): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($skill['name'] ?? ''); ?></td>
                                <td>₱<?php echo number_format($skill['price'] ?? 0, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($request['certificate_name'])): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Certifications</h5>
            </div>
            <div class="card-body">
                <p><strong><?php echo htmlspecialchars($request['certificate_name']); ?></strong></p>
                <p class="mb-1">Issued by: <?php echo htmlspecialchars($request['issuing_organization']); ?></p>
                <?php if($request['issue_date']): ?>
                <p class="mb-0">Issued: <?php echo date('F j, Y', strtotime($request['issue_date'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if(!empty($request['portfolio_url'])): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Portfolio</h5>
            </div>
            <div class="card-body">
                <a href="<?php echo htmlspecialchars($request['portfolio_url']); ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt me-2"></i> View Portfolio
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>