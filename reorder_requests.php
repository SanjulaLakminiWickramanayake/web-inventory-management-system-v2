<?php
ob_start();
require_once 'config.php';
checkAccess(['admin', 'manager']);

$page_title = "Reorder Requests";
include 'header.php';

$user_role = getUserRole();
$user_branch = getUserBranch();

// Handle Create Reorder Request (manual)
if (isset($_POST['create_reorder'])) {
    $branch_id = intval($_POST['branch_id']);
    $product_id = intval($_POST['product_id']);
    $requested_quantity = floatval($_POST['requested_quantity']);
    
    // Get current stock
    $stock_sql = "SELECT total_quantity FROM stock WHERE product_id = ? AND branch_id = ?";
    $stock_stmt = $conn->prepare($stock_sql);
    if($stock_stmt === false) {
        die("SQL Error: " . $conn->error);
    }
    $stock_stmt->bind_param("ii", $product_id, $branch_id);
    $stock_stmt->execute();
    $result = $stock_stmt->get_result()->fetch_assoc();
    $current_stock = $result ? $result['total_quantity'] : 0;
    
    $sql = "INSERT INTO reorder_requests (branch_id, product_id, current_stock, requested_quantity) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if($stmt === false) {
        die("SQL Error: " . $conn->error);
    }
    $stmt->bind_param("iidd", $branch_id, $product_id, $current_stock, $requested_quantity);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Reorder request created successfully!";
    } else {
        $_SESSION['error'] = "Error creating reorder request: " . $stmt->error;
    }
    header("Location: reorder_requests.php");
    exit();
}

// Handle Approve Reorder
if (isset($_GET['approve'])) {
    $request_id = intval($_GET['approve']);
    
    $conn->begin_transaction();
    try {
        // Get request details
        $request_sql = "SELECT * FROM reorder_requests WHERE id = ? AND status = 'pending'";
        $request_stmt = $conn->prepare($request_sql);
        if($request_stmt === false) {
            throw new Exception("SQL Error: " . $conn->error);
        }
        $request_stmt->bind_param("i", $request_id);
        $request_stmt->execute();
        $request = $request_stmt->get_result()->fetch_assoc();
        
        if ($request) {
            $branch_id = $request['branch_id'];
            $product_id = $request['product_id'];
            $requested_quantity = $request['requested_quantity'];
            
            // Add stock to branch
            $check_sql = "SELECT id FROM stock WHERE product_id = ? AND branch_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            if($check_stmt === false) {
                throw new Exception("SQL Error: " . $conn->error);
            }
            $check_stmt->bind_param("ii", $product_id, $branch_id);
            $check_stmt->execute();
            $stock_exists = $check_stmt->get_result()->num_rows > 0;
            
            if ($stock_exists) {
                $update_sql = "UPDATE stock SET total_quantity = total_quantity + ? WHERE product_id = ? AND branch_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if($update_stmt === false) {
                    throw new Exception("SQL Error: " . $conn->error);
                }
                $update_stmt->bind_param("dii", $requested_quantity, $product_id, $branch_id);
            } else {
                $update_sql = "INSERT INTO stock (product_id, branch_id, total_quantity, reorder_level) VALUES (?, ?, ?, 10)";
                $update_stmt = $conn->prepare($update_sql);
                if($update_stmt === false) {
                    throw new Exception("SQL Error: " . $conn->error);
                }
                $update_stmt->bind_param("iid", $product_id, $branch_id, $requested_quantity);
            }
            $update_stmt->execute();
            
            // Update request status
            $status_sql = "UPDATE reorder_requests SET status = 'approved', approved_by = ? WHERE id = ?";
            $status_stmt = $conn->prepare($status_sql);
            if($status_stmt === false) {
                throw new Exception("SQL Error: " . $conn->error);
            }
            $status_stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
            $status_stmt->execute();
            
            // Mark related alert as read
            $alert_sql = "UPDATE alerts SET is_read = 1 WHERE product_id = ? AND branch_id = ? AND alert_type = 'low_stock'";
            $alert_stmt = $conn->prepare($alert_sql);
            if($alert_stmt !== false) {
                $alert_stmt->bind_param("ii", $product_id, $branch_id);
                $alert_stmt->execute();
            }
            
            $conn->commit();
            $_SESSION['success'] = "Reorder request approved and stock updated!";
        } else {
            $_SESSION['error'] = "Request not found or already processed.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error approving request: " . $e->getMessage();
    }
    header("Location: reorder_requests.php");
    exit();
}

// Handle Reject Reorder
if (isset($_GET['reject'])) {
    $request_id = intval($_GET['reject']);
    
    $sql = "UPDATE reorder_requests SET status = 'rejected', approved_by = ? WHERE id = ? AND status = 'pending'";
    $stmt = $conn->prepare($sql);
    if($stmt === false) {
        die("SQL Error: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['success'] = "Reorder request rejected.";
    } else {
        $_SESSION['error'] = "Request not found or already processed.";
    }
    header("Location: reorder_requests.php");
    exit();
}

// Get products and branches for dropdowns
$products = $conn->query("SELECT * FROM products ORDER BY name");
$products_array = [];
while ($p = $products->fetch_assoc()) {
    $products_array[] = $p;
}

$branches_sql = "SELECT * FROM branches ORDER BY name";
if ($user_role == 'manager') {
    $branches_sql = "SELECT * FROM branches WHERE id = " . intval($user_branch);
}
$branches = $conn->query($branches_sql);
$branches_array = [];
while ($b = $branches->fetch_assoc()) {
    $branches_array[] = $b;
}

// Get reorder requests
$requests_sql = "SELECT r.*, 
                 p.name as product_name, p.unit,
                 b.name as branch_name,
                 u.username as approved_by_name
                 FROM reorder_requests r
                 JOIN products p ON r.product_id = p.id
                 JOIN branches b ON r.branch_id = b.id
                 LEFT JOIN users u ON r.approved_by = u.id";

if ($user_role == 'manager') {
    $requests_sql .= " WHERE r.branch_id = " . intval($user_branch);
}

$requests_sql .= " ORDER BY r.request_date DESC";
$requests = $conn->query($requests_sql);
?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-clipboard-list me-2"></i>Reorder Requests</h5>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#createReorderModal">
                    <i class="fas fa-plus me-1"></i>New Request
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Branch</th>
                            <th>Product</th>
                            <th>Current Stock</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($request = $requests->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $request['id']; ?></td>
                            <td><?php echo $request['branch_name']; ?></td>
                            <td><strong><?php echo $request['product_name']; ?></strong></td>
                            <td><?php echo number_format($request['current_stock'], 2); ?> <?php echo $request['unit']; ?></td>
                            <td><strong><?php echo number_format($request['requested_quantity'], 2); ?> <?php echo $request['unit']; ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($request['request_date']); ?></td>
                            <td>
                                <?php if ($request['status'] == 'pending' && $user_role == 'admin'): ?>
                                <a href="reorder_requests.php?approve=<?php echo $request['id']; ?>" class="btn btn-action btn-approve" onclick="return confirm('Approve this reorder request? Stock will be added to the branch.');">
                                    <i class="fas fa-check"></i>
                                </a>
                                <a href="reorder_requests.php?reject=<?php echo $request['id']; ?>" class="btn btn-action btn-reject" onclick="return confirm('Reject this reorder request?');">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php elseif ($request['status'] != 'pending'): ?>
                                <small class="text-muted">By <?php echo $request['approved_by_name']; ?></small>
                                <?php else: ?>
                                <span class="text-muted">Pending Approval</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Reorder Modal -->
<div class="modal fade" id="createReorderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Create Reorder Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch *</label>
                        <select class="form-select" name="branch_id" required>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches_array as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Product *</label>
                        <select class="form-select" name="product_id" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products_array as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo $p['name']; ?> (<?php echo $p['unit']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Requested Quantity *</label>
                        <input type="number" class="form-control" name="requested_quantity" step="0.01" min="0.01" required placeholder="Enter quantity needed">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_reorder" class="btn btn-success"><i class="fas fa-save me-1"></i>Create Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>