<?php
ob_start();
require_once 'config.php';
checkAccess(['admin', 'manager', 'cashier']);

$page_title = "Stock Management";
include 'header.php';

$user_role = getUserRole();
$user_branch = getUserBranch();

// Get selected branch for admin/manager filtering
$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

// Handle Update Stock - Admin + Manager Only
if (isset($_POST['update_stock']) && ($user_role == 'admin' || $user_role == 'manager')) {
    $id = intval($_POST['id']);
    $quantity = floatval($_POST['quantity']);
    $reorder_level = floatval($_POST['reorder_level']);
    
    if ($quantity < 0 || $reorder_level < 0) {
        $_SESSION['error'] = "Quantity and reorder level cannot be negative.";
    } else {
        $sql = "UPDATE stock SET total_quantity = ?, reorder_level = ?, last_updated = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ddi", $quantity, $reorder_level, $id);
        
        if ($stmt->execute()) {
            // Check if stock is now low
            $check_sql = "SELECT s.*, p.name as product_name, b.name as branch_name 
                         FROM stock s 
                         JOIN products p ON s.product_id = p.id 
                         JOIN branches b ON s.branch_id = b.id 
                         WHERE s.id = ? AND s.total_quantity <= s.reorder_level";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $low_stock = $check_result->fetch_assoc();
                $message = $low_stock['product_name'] . " stock is low (" . $quantity . " " . 
                          getProductUnit($conn, $low_stock['product_id']) . ") in " . $low_stock['branch_name'];
                
                $alert_sql = "INSERT INTO alerts (product_id, branch_id, alert_type, message) VALUES (?, ?, 'low_stock', ?)";
                $alert_stmt = $conn->prepare($alert_sql);
                $alert_stmt->bind_param("iis", $low_stock['product_id'], $low_stock['branch_id'], $message);
                $alert_stmt->execute();
                
                // Create reorder request if not exists
                $reorder_check = $conn->prepare("SELECT id FROM reorder_requests WHERE branch_id = ? AND product_id = ? AND status = 'pending'");
                $reorder_check->bind_param("ii", $low_stock['branch_id'], $low_stock['product_id']);
                $reorder_check->execute();
                
                if ($reorder_check->get_result()->num_rows == 0) {
                    $requested_qty = $reorder_level * 2;
                    $reorder_sql = "INSERT INTO reorder_requests (branch_id, product_id, current_stock, requested_quantity, requested_by) 
                                   VALUES (?, ?, ?, ?, ?)";
                    $reorder_stmt = $conn->prepare($reorder_sql);
                    $reorder_stmt->bind_param("iiddi", $low_stock['branch_id'], $low_stock['product_id'], $quantity, $requested_qty, $_SESSION['user_id']);
                    $reorder_stmt->execute();
                }
            }
            
            $_SESSION['success'] = "Stock updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating stock: " . $stmt->error;
        }
    }
    header("Location: stock.php");
    exit();
}

// Handle Add Stock Batch - Admin + Manager Only
if (isset($_POST['add_batch']) && ($user_role == 'admin' || $user_role == 'manager')) {
    $product_id = intval($_POST['product_id']);
    $branch_id = intval($_POST['branch_id']);
    $batch_no = sanitize($conn, $_POST['batch_no']);
    $quantity = floatval($_POST['quantity']);
    $mfd_date = $_POST['mfd_date'];
    $exp_date = $_POST['exp_date'];
    $cost_price = floatval($_POST['cost_price']);
    
    if (empty($batch_no) || $quantity <= 0 || empty($mfd_date) || empty($exp_date) || $cost_price <= 0) {
        $_SESSION['error'] = "All fields are required and must be valid.";
    } else {
        $conn->begin_transaction();
        try {
            $batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $batch_stmt = $conn->prepare($batch_sql);
            
            if ($batch_stmt === false) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $batch_stmt->bind_param("iissddds", $product_id, $branch_id, $batch_no, $mfd_date, $exp_date, $quantity, $quantity, $cost_price);
            $batch_stmt->execute();
            
            $update_sql = "INSERT INTO stock (product_id, branch_id, total_quantity) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE total_quantity = total_quantity + VALUES(total_quantity), last_updated = NOW()";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("iid", $product_id, $branch_id, $quantity);
            $update_stmt->execute();
            
            $conn->commit();
            $_SESSION['success'] = "Stock batch added successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error adding batch: " . $e->getMessage();
        }
    }
    header("Location: stock.php");
    exit();
}

// Get stock data based on role - Product ID + Image add කරා
$stock_sql = "SELECT s.*, 
              p.id as product_id,
              p.name as product_name, 
              p.unit, 
              p.selling_price,
              p.image,
              p.sku,
              c.name as category_name, 
              b.name as branch_name, 
              b.type as branch_type 
              FROM stock s 
              JOIN products p ON s.product_id = p.id 
              JOIN categories c ON p.category_id = c.id 
              JOIN branches b ON s.branch_id = b.id";

$types = "";
$params = [];
$where_clauses = [];

if ($user_role == 'admin' || $user_role == 'manager') {
    if ($selected_branch > 0) {
        $where_clauses[] = "s.branch_id = ?";
        $types = "i";
        $params = [$selected_branch];
    }
} elseif ($user_role == 'cashier') {
    $where_clauses[] = "s.branch_id = ?";
    $types = "i";
    $params = [$user_branch];
}

if (!empty($where_clauses)) {
    $stock_sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$stock_sql .= " ORDER BY b.name, c.name, p.name";

$stmt = $conn->prepare($stock_sql);
$stock_rows = [];
if ($stmt === false) {
    $_SESSION['error'] = "Error preparing stock query: " . $conn->error;
} else {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $stock_result = $stmt->get_result();
        while ($row = $stock_result->fetch_assoc()) {
            $stock_rows[] = $row;
        }
    } else {
        $_SESSION['error'] = "Error executing stock query: " . $stmt->error;
    }
}

// Get products and branches for dropdowns
$products = $conn->query("SELECT * FROM products ORDER BY name");
$branches = $conn->query("SELECT * FROM branches ORDER BY name");

$products_array = [];
while ($p = $products->fetch_assoc()) {
    $products_array[] = $p;
}

$branches_array = [];
while ($b = $branches->fetch_assoc()) {
    $branches_array[] = $b;
}

ob_end_flush();
?>

<style>
.badge-orange {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    color: white;
}
.badge-amber {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}
.product-img-thumb {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #f97316;
}
</style>

<!-- Cashier Alerts - Own Branch Only -->
<?php if ($user_role == 'cashier'): ?>
<div class="row fade-in mb-3">
    <div class="col-md-6">
        <?php
        $low_stock_sql = "SELECT COUNT(*) as cnt FROM stock WHERE branch_id = ? AND total_quantity <= reorder_level";
        $low_stmt = $conn->prepare($low_stock_sql);
        $low_stmt->bind_param("i", $user_branch);
        $low_stmt->execute();
        $low_count = $low_stmt->get_result()->fetch_assoc()['cnt'];
        ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?php echo $low_count; ?></strong> items are low on stock in your branch
        </div>
    </div>
    <div class="col-md-6">
        <?php
        $exp_sql = "SELECT COUNT(*) as cnt FROM stock_batches 
                    WHERE branch_id = ? AND remaining_quantity > 0 
                    AND exp_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $exp_stmt = $conn->prepare($exp_sql);
        $exp_stmt->bind_param("i", $user_branch);
        $exp_stmt->execute();
        $exp_count = $exp_stmt->get_result()->fetch_assoc()['cnt'];
        ?>
        <div class="alert alert-danger">
            <i class="fas fa-clock me-2"></i>
            <strong><?php echo $exp_count; ?></strong> batches expiring within 7 days
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Manager Alerts - All Branches Summary -->
<?php if ($user_role == 'manager'): ?>
<div class="row fade-in mb-3">
    <div class="col-md-6">
        <?php
        $low_stock_sql = "SELECT COUNT(*) as cnt FROM stock WHERE total_quantity <= reorder_level";
        $low_count = $conn->query($low_stock_sql)->fetch_assoc()['cnt'];
        ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?php echo $low_count; ?></strong> items are low on stock across all branches
        </div>
    </div>
    <div class="col-md-6">
        <?php
        $exp_sql = "SELECT COUNT(*) as cnt FROM stock_batches 
                    WHERE remaining_quantity > 0 
                    AND exp_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $exp_count = $conn->query($exp_sql)->fetch_assoc()['cnt'];
        ?>
        <div class="alert alert-danger">
            <i class="fas fa-clock me-2"></i>
            <strong><?php echo $exp_count; ?></strong> batches expiring within 7 days
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-cubes me-2"></i>Stock Management
                <?php if ($user_role == 'cashier'): ?>
                    - <?php echo htmlspecialchars($_SESSION['branch_name']); ?>
                <?php endif; ?>
                </h5>
                <?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
                <form method="GET" style="display: inline-block; margin-right: 10px;">
                    <select name="branch_id" onchange="this.form.submit()" class="form-select" style="width: auto;">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches_array as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#addBatchModal">
                    <i class="fas fa-plus me-1"></i>Add Stock Batch
                </button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th width="50">ID</th>
                            <th width="70">Image</th>
                            <th>Product</th>
                            <th>Category</th>
                            <?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
                            <th>Branch</th>
                            <?php endif; ?>
                            <th class="text-end">Quantity</th>
                            <th>Unit</th>
                            <th class="text-end">Reorder Level</th>
                            <th>Status</th>
                            <?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
                            <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_rows as $item): ?>
                        <?php $is_low = $item['total_quantity'] <= $item['reorder_level']; ?>
                        <tr class="<?= $is_low ? 'table-warning' : ''; ?>">
                            <td><span class="badge bg-secondary"><?= $item['product_id']; ?></span></td>
                            <td>
                                <?php
                                    $stockImage = !empty($item['image']) ? $item['image'] : '';
                                    $stockImagePath = 'uploads/products/' . $stockImage;
                                    $stockImageSrc = (!empty($stockImage) && file_exists($stockImagePath))
                                        ? 'uploads/products/' . rawurlencode($stockImage)
                                        : 'uploads/products/no-image.png';
                                ?>
                                <img src="<?= htmlspecialchars($stockImageSrc) ?>" class="product-img-thumb" alt="<?= htmlspecialchars($item['product_name']) ?>" onerror="this.src='uploads/products/no-image.png'">
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($item['product_name']); ?></strong><br>
                                <small class="text-muted">SKU: <?= htmlspecialchars($item['sku'] ?? 'N/A'); ?></small>
                            </td>
                            <td><span class="badge badge-orange"><?= $item['category_name']; ?></span></td>
                            <?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
                            <td><small><?= $item['branch_name']; ?></small></td>
                            <?php endif; ?>
                            <td class="text-end"><strong><?= number_format($item['total_quantity'], 2); ?></strong></td>
                            <td><?= $item['unit']; ?></td>
                            <td class="text-end"><?= number_format($item['reorder_level'], 0); ?></td>
                            <td>
                                <?php if ($is_low): ?>
                                    <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>Low Stock</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>OK</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
                            <td>
                                <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editStockModal<?php echo $item['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Expiring Batches for Cashier - Own Branch -->
<?php if ($user_role == 'cashier'): ?>
<div class="row fade-in mt-4">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-calendar-times me-2 text-danger"></i>Expiring Batches - Next 7 Days</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Batch No</th>
                            <th>Qty Left</th>
                            <th>Exp Date</th>
                            <th>Days Left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $exp_batches_sql = "SELECT sb.*, p.name as product_name, p.unit,
                                           DATEDIFF(sb.exp_date, CURDATE()) as days_left
                                           FROM stock_batches sb
                                           JOIN products p ON sb.product_id = p.id
                                           WHERE sb.branch_id = ? AND sb.remaining_quantity > 0
                                           AND sb.exp_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                           ORDER BY sb.exp_date ASC";
                        $exp_stmt = $conn->prepare($exp_batches_sql);
                        $exp_stmt->bind_param("i", $user_branch);
                        $exp_stmt->execute();
                        $exp_batches = $exp_stmt->get_result();
                        
                        while ($batch = $exp_batches->fetch_assoc()):
                            $row_class = $batch['days_left'] <= 3 ? 'table-danger' : 'table-warning';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><strong><?php echo htmlspecialchars($batch['product_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($batch['batch_no']); ?></td>
                            <td><?php echo number_format($batch['remaining_quantity'], 2); ?> <?php echo $batch['unit']; ?></td>
                            <td><?php echo date('Y-m-d', strtotime($batch['exp_date'])); ?></td>
                            <td><strong><?php echo $batch['days_left']; ?> days</strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Expiring Batches for Manager - All Branches -->
<?php if ($user_role == 'manager'): ?>
<div class="row fade-in mt-4">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-calendar-times me-2 text-danger"></i>Expiring Batches - Next 7 Days - All Branches</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Branch</th>
                            <th>Batch No</th>
                            <th>Qty Left</th>
                            <th>Exp Date</th>
                            <th>Days Left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $exp_batches_sql = "SELECT sb.*, p.name as product_name, p.unit, b.name as branch_name,
                                           DATEDIFF(sb.exp_date, CURDATE()) as days_left
                                           FROM stock_batches sb
                                           JOIN products p ON sb.product_id = p.id
                                           JOIN branches b ON sb.branch_id = b.id
                                           WHERE sb.remaining_quantity > 0
                                           AND sb.exp_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                           ORDER BY sb.exp_date ASC, b.name ASC";
                        $exp_batches = $conn->query($exp_batches_sql);
                        
                        while ($batch = $exp_batches->fetch_assoc()):
                            $row_class = $batch['days_left'] <= 3 ? 'table-danger' : 'table-warning';
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td><strong><?php echo htmlspecialchars($batch['product_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($batch['branch_name']); ?></td>
                            <td><?php echo htmlspecialchars($batch['batch_no']); ?></td>
                            <td><?php echo number_format($batch['remaining_quantity'], 2); ?> <?php echo $batch['unit']; ?></td>
                            <td><?php echo date('Y-m-d', strtotime($batch['exp_date'])); ?></td>
                            <td><strong><?php echo $batch['days_left']; ?> days</strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Batch Modal - Admin + Manager -->
<?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
<div class="modal fade" id="addBatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Stock Batch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product *</label>
                        <select class="form-select" name="product_id" id="batchProductSelect" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products_array as $p): ?>
                            <option value="<?php echo $p['id']; ?>"
                                data-cost-price="<?php echo htmlspecialchars($p['cost_price']); ?>"
                                data-product-code="<?php echo htmlspecialchars($p['sku'] ?: 'P' . $p['id']); ?>">
                                <?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['unit']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch *</label>
                        <select class="form-select" name="branch_id" required>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches_array as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Batch Number *</label>
                            <input type="text" class="form-control" name="batch_no" id="batchNoInput" required placeholder="e.g., BATCH-20260929">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" class="form-control" name="quantity" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cost Price per Unit *</label>
                        <input type="number" class="form-control" name="cost_price" id="costPriceInput" step="0.01" min="0.01" required placeholder="e.g., 750.00">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">MFD Date *</label>
                            <input type="date" class="form-control" name="mfd_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">EXP Date *</label>
                            <input type="date" class="form-control" name="exp_date" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_batch" class="btn btn-success"><i class="fas fa-save me-1"></i>Add Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Stock Modals - Admin/Manager Only -->
<?php if ($user_role == 'admin' || $user_role == 'manager'): ?>
<?php foreach ($stock_rows as $item): ?>
<div class="modal fade" id="editStockModal<?php echo $item['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($item['product_name']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($item['branch_name']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Quantity</label>
                        <input type="number" class="form-control" name="quantity" step="0.01" min="0" 
                        value="<?php echo $item['total_quantity']; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" class="form-control" name="reorder_level" step="0.01" min="0" 
                        value="<?php echo $item['reorder_level']; ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_stock" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('batchProductSelect');
    const batchNoInput = document.getElementById('batchNoInput');
    const costPriceInput = document.getElementById('costPriceInput');

    const formatDateCode = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${year}${month}${day}`;
    };

    const generateBatchNo = (productCode) => {
        const dateCode = formatDateCode();
        const suffix = Math.floor(1000 + Math.random() * 9000);
        return `BATCH-${productCode}-${dateCode}-${suffix}`;
    };

    const updateBatchFields = () => {
        if (!productSelect) return;

        const selectedOption = productSelect.options[productSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            batchNoInput.value = '';
            costPriceInput.value = '';
            return;
        }

        const costPrice = selectedOption.dataset.costPrice || '';
        const productCode = selectedOption.dataset.productCode || `P${selectedOption.value}`;

        if (costPriceInput && costPrice) {
            costPriceInput.value = parseFloat(costPrice).toFixed(2);
        }

        if (batchNoInput) {
            batchNoInput.value = generateBatchNo(productCode);
        }
    };

    if (productSelect) {
        productSelect.addEventListener('change', updateBatchFields);
        updateBatchFields();
    }
});
</script>

<?php include 'footer.php'; ?>