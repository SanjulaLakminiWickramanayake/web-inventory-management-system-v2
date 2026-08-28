<?php
ob_start(); // Output buffering start - මේක මුලටම දාපන්
require_once 'config.php';
checkAccess(['admin']);

$page_title = "Branches Management";
include 'header.php';

// Handle Add Branch
if (isset($_POST['add_branch'])) {
    $name = sanitize($conn, $_POST['name']);
    $location = sanitize($conn, $_POST['location']);
    $type = sanitize($conn, $_POST['type']);
    
    if (empty($name) || empty($location)) {
        $_SESSION['error'] = "Name and location are required.";
    } else {
        $sql = "INSERT INTO branches (name, location, type) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $name, $location, $type);
        
        if ($stmt->execute()) {
            // Add stock entries for new branch for all products
            $branch_id = $stmt->insert_id;
            $products_sql = "SELECT id FROM products";
            $products_result = $conn->query($products_sql);
            
            while ($product = $products_result->fetch_assoc()) {
                $stock_sql = "INSERT INTO stock (product_id, branch_id, quantity, reorder_level) VALUES (?, ?, 0, 10)";
                $stock_stmt = $conn->prepare($stock_sql);
                $stock_stmt->bind_param("ii", $product['id'], $branch_id);
                $stock_stmt->execute();
            }
            
            $_SESSION['success'] = "Branch added successfully!";
        } else {
            $_SESSION['error'] = "Error adding branch: " . $stmt->error;
        }
    }
    header("Location: branches.php");
    exit();
}

// Handle Edit Branch
if (isset($_POST['edit_branch'])) {
    $id = intval($_POST['id']);
    $name = sanitize($conn, $_POST['name']);
    $location = sanitize($conn, $_POST['location']);
    $type = sanitize($conn, $_POST['type']);
    
    if (empty($name) || empty($location)) {
        $_SESSION['error'] = "Name and location are required.";
    } else {
        $sql = "UPDATE branches SET name = ?, location = ?, type = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $name, $location, $type, $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Branch updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating branch: " . $stmt->error;
        }
    }
    header("Location: branches.php");
    exit();
}

// Handle Delete Branch
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if branch has sales or transfers
    $check_sql = "SELECT COUNT(*) as count FROM sales WHERE branch_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $has_sales = $check_stmt->get_result()->fetch_assoc()['count'];
    
    if ($has_sales > 0) {
        $_SESSION['error'] = "Cannot delete branch with existing sales records.";
    } else {
        $sql = "DELETE FROM branches WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Branch deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting branch: " . $stmt->error;
        }
    }
    header("Location: branches.php");
    exit();
}

// Get all branches
$branches = $conn->query("SELECT * FROM branches ORDER BY type DESC, name ASC");
?>

<!-- මෙතනින් පහලට උඹේ HTML code එක තියෙනවා -->
<div class="container">
    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <!-- Table, Form වගේ දේවල් මෙතන -->
</div>

<?php
ob_end_flush(); // Output buffering end - file එකේ අන්තිමටම දාපන්
?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-building me-2"></i>Branches</h5>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                    <i class="fas fa-plus me-1"></i>Add Branch
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Type</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($branch = $branches->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $branch['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($branch['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($branch['location']); ?></td>
                            <td>
                                <span class="badge <?php echo $branch['type'] == 'main' ? 'bg-primary' : 'bg-info'; ?>">
                                    <?php echo ucfirst($branch['type']); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($branch['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editBranchModal<?php echo $branch['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-action btn-delete" onclick="confirmDelete('branches.php?delete=<?php echo $branch['id']; ?>', '<?php echo htmlspecialchars($branch['name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Branch Modal -->
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch Name *</label>
                        <input type="text" class="form-control" name="name" required placeholder="Enter branch name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location *</label>
                        <input type="text" class="form-control" name="location" required placeholder="Enter location">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch Type</label>
                        <select class="form-select" name="type">
                            <option value="outlet">Outlet</option>
                            <option value="main">Main Branch</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_branch" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Branch Modals -->
<?php 
$branches->data_seek(0);
while ($branch = $branches->fetch_assoc()): 
?>
<div class="modal fade" id="editBranchModal<?php echo $branch['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id" value="<?php echo $branch['id']; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch Name *</label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($branch['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location *</label>
                        <input type="text" class="form-control" name="location" value="<?php echo htmlspecialchars($branch['location']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch Type</label>
                        <select class="form-select" name="type">
                            <option value="outlet" <?php echo $branch['type'] == 'outlet' ? 'selected' : ''; ?>>Outlet</option>
                            <option value="main" <?php echo $branch['type'] == 'main' ? 'selected' : ''; ?>>Main Branch</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_branch" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

<?php include 'footer.php'; ?>
