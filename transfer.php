<?php
ob_start();
require_once 'config.php';
checkAccess(['admin', 'manager']);

$page_title = "Stock Transfers";
include 'header.php';

$user_role = getUserRole();
$user_branch = getUserBranch();

// Handle Create Transfer
if (isset($_POST['create_transfer'])) {
    $from_branch = intval($_POST['from_branch']);
    $to_branch = intval($_POST['to_branch']);
    $products_data = $_POST['products'];
    $quantities = $_POST['quantities'];

    if ($from_branch == $to_branch) {
        $_SESSION['error'] = "Source and destination branches cannot be the same.";
    } elseif (empty($products_data) || empty($quantities)) {
        $_SESSION['error'] = "Please add at least one product to transfer.";
    } else {
        $conn->begin_transaction();
        try {
            $product_names = [];
            $products_query = $conn->query("SELECT id, name FROM products");
            while ($product = $products_query->fetch_assoc()) {
                $product_names[$product['id']] = $product['name'];
            }

            $valid_items = [];
            $skipped_items = [];

            // Validate each selected product and collect only the transferable ones
            for ($i = 0; $i < count($products_data); $i++) {
                $product_id = intval($products_data[$i]);
                $quantity = floatval($quantities[$i]);

                if ($quantity <= 0) {
                    $skipped_items[] = $product_names[$product_id] ?? "Product ID: " . $product_id;
                    continue;
                }

                // Check stock.total_quantity - this is the source of truth
                $stock_check = $conn->prepare("SELECT total_quantity FROM stock WHERE product_id = ? AND branch_id = ?");
                $stock_check->bind_param("ii", $product_id, $from_branch);
                $stock_check->execute();
                $stock_result = $stock_check->get_result()->fetch_assoc();
                $stock_available = $stock_result ? floatval($stock_result['total_quantity']) : 0;

                // Reject only if stock.total_quantity is truly insufficient
                if ($stock_available < $quantity) {
                    error_log("Transfer validation: Product $product_id insufficient stock. Available: $stock_available, Requested: $quantity");
                    $skipped_items[] = $product_names[$product_id] ?? "Product ID: " . $product_id;
                    continue;
                }

                // Allow item (batches will be handled during approval)
                $valid_items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity
                ];
            }

            if (empty($valid_items)) {
                $error_msg = "No products had enough stock to transfer.";
                if (!empty($skipped_items)) {
                    $error_msg .= " Skipped items: " . implode(", ", $skipped_items);
                }
                throw new Exception($error_msg);
            }

            // Insert transfer header
            $transfer_sql = "INSERT INTO transfers (from_branch_id, to_branch_id, created_by) VALUES (?,?,?)";
            $transfer_stmt = $conn->prepare($transfer_sql);
            if ($transfer_stmt === false) throw new Exception("Transfer Prepare Error: ". $conn->error);

            $user_id = $_SESSION['user_id'];
            $transfer_stmt->bind_param("iii", $from_branch, $to_branch, $user_id);
            $transfer_stmt->execute();
            $transfer_id = $transfer_stmt->insert_id;

            // Insert transfer items using FIFO batches for each valid item
            foreach ($valid_items as $item) {
                $product_id = intval($item['product_id']);
                $quantity = floatval($item['quantity']);
                $remaining_qty = $quantity;

                // Get batches with remaining quantity
                $batch_sql = "SELECT * FROM stock_batches
                             WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0
                             ORDER BY created_at ASC";
                $batch_stmt = $conn->prepare($batch_sql);
                $batch_stmt->bind_param("ii", $product_id, $from_branch);
                $batch_stmt->execute();
                $batches = $batch_stmt->get_result();

                // Try to allocate from existing batches
                while ($remaining_qty > 0 && $batch = $batches->fetch_assoc()) {
                    $deduct = min($remaining_qty, $batch['remaining_quantity']);
                    $batch_id = $batch['id'];

                    $item_sql = "INSERT INTO transfer_items (transfer_id, product_id, batch_id, quantity) VALUES (?,?,?,?)";
                    $item_stmt = $conn->prepare($item_sql);
                    if ($item_stmt === false) throw new Exception("Transfer Items Prepare Error: ". $conn->error);
                    $item_stmt->bind_param("iiid", $transfer_id, $product_id, $batch_id, $deduct);
                    $item_stmt->execute();

                    $remaining_qty -= $deduct;
                }

                // If remaining quantity couldn't be fulfilled from existing batches, create a temporary batch
                if ($remaining_qty > 0) {
                    error_log("Transfer: Creating temporary batch for product $product_id, qty: $remaining_qty");
                    
                    // Create a temporary batch for the remaining quantity
                    $temp_batch_no = "TRANSFER-" . date('YmdHis') . "-" . $product_id;
                    $temp_mfd = date('Y-m-d');
                    $temp_exp = date('Y-m-d', strtotime('+365 days'));
                    $temp_cost = 0;
                    
                    $create_batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $create_batch_stmt = $conn->prepare($create_batch_sql);
                    if ($create_batch_stmt === false) throw new Exception("Batch Create Error: ". $conn->error);
                    
                    $create_batch_stmt->bind_param("iisssddd", $product_id, $from_branch, $temp_batch_no, 
                                                   $temp_mfd, $temp_exp, $remaining_qty, $remaining_qty, $temp_cost);
                    $create_batch_stmt->execute();
                    $temp_batch_id = $create_batch_stmt->insert_id;
                    
                    // Add transfer item using this batch
                    $item_sql = "INSERT INTO transfer_items (transfer_id, product_id, batch_id, quantity) VALUES (?,?,?,?)";
                    $item_stmt = $conn->prepare($item_sql);
                    $item_stmt->bind_param("iiid", $transfer_id, $product_id, $temp_batch_id, $remaining_qty);
                    $item_stmt->execute();
                }
            }

            $conn->commit();
            $_SESSION['success'] = "Transfer request created! Transfer ID: #". $transfer_id;
            if (!empty($skipped_items)) {
                $_SESSION['success'] .= " Skipped " . count($skipped_items) . " item(s) with insufficient or invalid stock.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error creating transfer: ". $e->getMessage();
        }
    }
    header("Location: transfer.php");
    exit();
}

// Handle Approve Transfer
if (isset($_GET['approve'])) {
    $transfer_id = intval($_GET['approve']);

    $conn->begin_transaction();
    try {
        // Get transfer details - from_branch_id, to_branch_id
        $transfer_sql = "SELECT * FROM transfers WHERE id =? AND status = 'pending'";
        $transfer_stmt = $conn->prepare($transfer_sql);
        $transfer_stmt->bind_param("i", $transfer_id);
        $transfer_stmt->execute();
        $transfer = $transfer_stmt->get_result()->fetch_assoc();

        if ($transfer) {
            $from_branch = $transfer['from_branch_id'];
            $to_branch = $transfer['to_branch_id'];

            // Get transfer items with batch_id
            $items_sql = "SELECT * FROM transfer_items WHERE transfer_id =?";
            $items_stmt = $conn->prepare($items_sql);
            $items_stmt->bind_param("i", $transfer_id);
            $items_stmt->execute();
            $items = $items_stmt->get_result();

            while ($item = $items->fetch_assoc()) {
                $product_id = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                $batch_id = (int)$item['batch_id'];

                $stock_check = $conn->prepare("SELECT total_quantity FROM stock WHERE product_id = ? AND branch_id = ?");
                $stock_check->bind_param("ii", $product_id, $from_branch);
                $stock_check->execute();
                $stock_result = $stock_check->get_result()->fetch_assoc();
                $available_stock = (float)($stock_result['total_quantity'] ?? 0);

                $batch_total_sql = "SELECT COALESCE(SUM(remaining_quantity), 0) as total_remaining
                                   FROM stock_batches
                                   WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0";
                $batch_total_stmt = $conn->prepare($batch_total_sql);
                $batch_total_stmt->bind_param("ii", $product_id, $from_branch);
                $batch_total_stmt->execute();
                $batch_total_result = $batch_total_stmt->get_result()->fetch_assoc();
                $batch_total = (float)($batch_total_result['total_remaining'] ?? 0);

                $qty_to_move = min($quantity, $available_stock, $batch_total);
                if ($qty_to_move <= 0) {
                    $update_item_sql = "UPDATE transfer_items SET quantity = 0 WHERE id = ?";
                    $update_item_stmt = $conn->prepare($update_item_sql);
                    $update_item_stmt->bind_param("i", $item['id']);
                    $update_item_stmt->execute();
                    continue;
                }

                // 1. Deduct from source stock_batches
                $deduct_batch_sql = "UPDATE stock_batches SET remaining_quantity = GREATEST(remaining_quantity - ?, 0) WHERE id = ?";
                $deduct_batch_stmt = $conn->prepare($deduct_batch_sql);
                $deduct_batch_stmt->bind_param("di", $qty_to_move, $batch_id);
                $deduct_batch_stmt->execute();

                // 2. Deduct from source stock - total_quantity
                $deduct_sql = "UPDATE stock SET total_quantity = GREATEST(total_quantity - ?, 0), last_updated = NOW() WHERE product_id = ? AND branch_id = ?";
                $deduct_stmt = $conn->prepare($deduct_sql);
                $deduct_stmt->bind_param("dii", $qty_to_move, $product_id, $from_branch);
                $deduct_stmt->execute();

                // 3. Add to destination stock
                $check_dest_sql = "SELECT id FROM stock WHERE product_id = ? AND branch_id = ?";
                $check_dest_stmt = $conn->prepare($check_dest_sql);
                $check_dest_stmt->bind_param("ii", $product_id, $to_branch);
                $check_dest_stmt->execute();
                $dest_exists = $check_dest_stmt->get_result()->num_rows > 0;

                if ($dest_exists) {
                    $add_sql = "UPDATE stock SET total_quantity = total_quantity + ?, last_updated = NOW() WHERE product_id = ? AND branch_id = ?";
                    $add_stmt = $conn->prepare($add_sql);
                    $add_stmt->bind_param("dii", $qty_to_move, $product_id, $to_branch);
                } else {
                    $add_sql = "INSERT INTO stock (product_id, branch_id, total_quantity, reorder_level) VALUES (?,?,?, 10)";
                    $add_stmt = $conn->prepare($add_sql);
                    $add_stmt->bind_param("iid", $product_id, $to_branch, $qty_to_move);
                }
                $add_stmt->execute();

                // 4. Create batch in destination branch
                $batch_info = $conn->prepare("SELECT batch_no, mfd_date, exp_date, cost_price FROM stock_batches WHERE id = ?");
                $batch_info->bind_param("i", $batch_id);
                $batch_info->execute();
                $batch_data = $batch_info->get_result()->fetch_assoc();

                $new_batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price)
                                 VALUES (?,?,?,?,?,?,?,?)";
                $new_batch_stmt = $conn->prepare($new_batch_sql);
                $new_batch_stmt->bind_param("iisssddd", $product_id, $to_branch, $batch_data['batch_no'], $batch_data['mfd_date'], $batch_data['exp_date'], $qty_to_move, $qty_to_move, $batch_data['cost_price']);
                $new_batch_stmt->execute();

                $update_item_sql = "UPDATE transfer_items SET quantity = ? WHERE id = ?";
                $update_item_stmt = $conn->prepare($update_item_sql);
                $update_item_stmt->bind_param("di", $qty_to_move, $item['id']);
                $update_item_stmt->execute();
            }

            // Update transfer status
            $update_sql = "UPDATE transfers SET status = 'completed', approved_by =? WHERE id =?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $_SESSION['user_id'], $transfer_id);
            $update_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = "Transfer approved and stock moved successfully!";
        } else {
            $_SESSION['error'] = "Transfer not found or already processed.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error approving transfer: ". $e->getMessage();
    }
    header("Location: transfer.php");
    exit();
}

// Handle Reject Transfer
if (isset($_GET['reject'])) {
    $transfer_id = intval($_GET['reject']);

    $sql = "UPDATE transfers SET status = 'rejected', approved_by =? WHERE id =? AND status = 'pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $_SESSION['user_id'], $transfer_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['success'] = "Transfer rejected successfully.";
    } else {
        $_SESSION['error'] = "Transfer not found or already processed.";
    }
    header("Location: transfer.php");
    exit();
}

// Get products for dropdown
$products = $conn->query("SELECT * FROM products ORDER BY name");
$products_array = [];
while ($p = $products->fetch_assoc()) {
    $products_array[] = $p;
}

// Get branches for dropdown
$branches_sql = "SELECT * FROM branches ORDER BY name";
if ($user_role == 'manager') {
    $branches_sql = "SELECT * FROM branches WHERE id = ". $user_branch. " OR type = 'main' ORDER BY name";
}
$branches = $conn->query($branches_sql);
$branches_array = [];
while ($b = $branches->fetch_assoc()) {
    $branches_array[] = $b;
}

// Build stock availability map for transfer product selection by branch
$stock_availability = [];
foreach ($branches_array as $branch) {
    $stock_availability[(int)$branch['id']] = [];
}

$stock_map_sql = "SELECT s.branch_id, s.product_id, s.total_quantity, p.name, p.unit
                  FROM stock s
                  JOIN products p ON s.product_id = p.id
                  ORDER BY s.branch_id, p.name";
$stock_map_result = $conn->query($stock_map_sql);
while ($row = $stock_map_result->fetch_assoc()) {
    $branch_id = (int)$row['branch_id'];
    $stock_availability[$branch_id][] = [
        'id' => (int)$row['product_id'],
        'name' => $row['name'],
        'unit' => $row['unit'],
        'stock_quantity' => (float)$row['total_quantity']
    ];
}

$transfer_products_json = [];
foreach ($products_array as $product) {
    $product_id = (int)$product['id'];
    foreach ($branches_array as $branch) {
        $branch_id = (int)$branch['id'];
        $stock_quantity = 0;
        foreach ($stock_availability[$branch_id] ?? [] as $stock_item) {
            if ((int)$stock_item['id'] === $product_id) {
                $stock_quantity = (float)$stock_item['stock_quantity'];
                break;
            }
        }
        $transfer_products_json[$branch_id][] = [
            'id' => $product_id,
            'name' => $product['name'],
            'unit' => $product['unit'],
            'stock_quantity' => $stock_quantity
        ];
    }
}
$transfer_products_json = json_encode($transfer_products_json);

// Get transfers - from_branch_id, to_branch_id
$transfers_sql = "SELECT t.*,
                  fb.name as from_branch_name,
                  tb.name as to_branch_name,
                  u.username as created_by_name,
                  au.username as approved_by_name
                  FROM transfers t
                  JOIN branches fb ON t.from_branch_id = fb.id
                  JOIN branches tb ON t.to_branch_id = tb.id
                  LEFT JOIN users u ON t.created_by = u.id
                  LEFT JOIN users au ON t.approved_by = au.id";

if ($user_role == 'manager') {
    $transfers_sql.= " WHERE t.from_branch_id = ". $user_branch. " OR t.to_branch_id = ". $user_branch;
}

$transfers_sql.= " ORDER BY t.transfer_date DESC";
$transfers = $conn->query($transfers_sql);
ob_end_flush();
?>

<style>
/* Status Badge Styles - Orange Theme Match */
.status-badge {
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
    color: white!important;
    display: inline-block;
    min-width: 90px;
    text-align: center;
    text-transform: capitalize;
}

/* Status Colors */
.badge-pending {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); /* Amber */
}
.badge-in-transit,.badge-in_transit {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); /* Blue */
}
.badge-completed {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%); /* Green */
}
.badge-rejected,.badge-cancelled {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); /* Red */
}
.badge-approved {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); /* Purple */
}

/* Fix: Row hover කරාම badge සුදු වෙන එක නවත්තනවා */
.table-hover tbody tr:hover.status-badge,
.table tbody tr:active.status-badge,
.table tbody tr:focus.status-badge {
    color: white!important;
    opacity: 0.9;
}
</style>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-exchange-alt me-2"></i>Stock Transfers</h5>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#createTransferModal">
                    <i class="fas fa-plus me-1"></i>New Transfer
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($transfer = $transfers->fetch_assoc()):?>
                        <tr>
                            <td>#<?php echo $transfer['id'];?></td>
                            <td><?php echo htmlspecialchars($transfer['from_branch_name']);?></td>
                            <td><?php echo htmlspecialchars($transfer['to_branch_name']);?></td>
                            <td><?php echo formatDate($transfer['transfer_date']);?></td>
                            <td>
                                <?php
                                $status_class = 'badge-pending';
                                $status_lower = strtolower(str_replace([' ', '_'], '-', $transfer['status']));
                                switch($status_lower) {
                                    case 'pending':
                                        $status_class = 'badge-pending';
                                        break;
                                    case 'in-transit':
                                        $status_class = 'badge-in-transit';
                                        break;
                                    case 'completed':
                                        $status_class = 'badge-completed';
                                        break;
                                    case 'rejected':
                                    case 'cancelled':
                                        $status_class = 'badge-rejected';
                                        break;
                                    case 'approved':
                                        $status_class = 'badge-approved';
                                        break;
                                }
                               ?>
                                <span class="status-badge <?= $status_class;?>">
                                    <?php echo ucwords(str_replace(['_', '-'], ' ', $transfer['status']));?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($transfer['created_by_name']);?></td>
                            <td>
                                <button class="btn btn-action btn-view" data-bs-toggle="modal" data-bs-target="#viewTransferModal<?php echo $transfer['id'];?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($transfer['status'] == 'pending' && $user_role == 'admin'):?>
                                <a href="transfer.php?approve=<?php echo $transfer['id'];?>" class="btn btn-action btn-approve" onclick="return confirm('Approve this transfer? Stock will be moved from source to destination.');">
                                    <i class="fas fa-check"></i>
                                </a>
                                <a href="transfer.php?reject=<?php echo $transfer['id'];?>" class="btn btn-action btn-reject" onclick="return confirm('Reject this transfer?');">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif;?>
                            </td>
                        </tr>
                        <?php endwhile;?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Create Transfer Modal -->
<div class="modal fade" id="createTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Create Transfer Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">From Branch *</label>
                            <select class="form-select" name="from_branch" id="from_branch" required onchange="filterToBranch()">
                                <option value="">Select Source</option>
                                <?php foreach ($branches_array as $b):?>
                                <option value="<?php echo $b['id'];?>"><?php echo htmlspecialchars($b['name']);?> (<?php echo $b['type'];?>)</option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To Branch *</label>
                            <select class="form-select" name="to_branch" id="to_branch" required>
                                <option value="">Select Destination</option>
                                <?php foreach ($branches_array as $b):?>
                                <option value="<?php echo $b['id'];?>"><?php echo htmlspecialchars($b['name']);?> (<?php echo $b['type'];?>)</option>
                                <?php endforeach;?>
                            </select>
                        </div>
                    </div>

                    <div id="transferRows">
                        <div class="row transfer-row mb-3">
                            <div class="col-md-7">
                                <label class="form-label">Product</label>
                                <select class="form-select transfer-product-select" name="products[]" required>
                                    <option value="">Select Source Branch First</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" name="quantities[]" step="0.01" min="0.01" required placeholder="Enter quantity">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeTransferRow(this)" style="display:none;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-primary" onclick="addTransferRow()">
                        <i class="fas fa-plus me-1"></i>Add Another Product
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_transfer" class="btn btn-success"><i class="fas fa-save me-1"></i>Create Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Transfer Modals -->
<?php
$transfers->data_seek(0);
while ($transfer = $transfers->fetch_assoc()):
    $items_sql = "SELECT ti.*, p.name as product_name, p.unit
                  FROM transfer_items ti
                  JOIN products p ON ti.product_id = p.id
                  WHERE ti.transfer_id =?";
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $transfer['id']);
    $items_stmt->execute();
    $transfer_items = $items_stmt->get_result();
?>
<div class="modal fade" id="viewTransferModal<?php echo $transfer['id'];?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Transfer #<?php echo $transfer['id'];?> Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <strong>From:</strong> <?php echo htmlspecialchars($transfer['from_branch_name']);?><br>
                    <strong>To:</strong> <?php echo htmlspecialchars($transfer['to_branch_name']);?><br>
                    <strong>Status:</strong>
                    <?php
                    $status_class = 'badge-pending';
                    $status_lower = strtolower(str_replace([' ', '_'], '-', $transfer['status']));
                    switch($status_lower) {
                        case 'pending': $status_class = 'badge-pending'; break;
                        case 'in-transit': $status_class = 'badge-in-transit'; break;
                        case 'completed': $status_class = 'badge-completed'; break;
                        case 'rejected': case 'cancelled': $status_class = 'badge-rejected'; break;
                        case 'approved': $status_class = 'badge-approved'; break;
                    }
                   ?>
                    <span class="status-badge <?= $status_class;?>"><?php echo ucwords(str_replace(['_', '-'], ' ', $transfer['status']));?></span><br>
                    <strong>Date:</strong> <?php echo formatDate($transfer['transfer_date']);?><br>
                    <strong>Created By:</strong> <?php echo htmlspecialchars($transfer['created_by_name']);?><br>
                    <?php if ($transfer['approved_by_name']):?>
                    <strong>Processed By:</strong> <?php echo htmlspecialchars($transfer['approved_by_name']);?>
                    <?php endif;?>
                </div>
                <h6>Items:</h6>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($item = $transfer_items->fetch_assoc()):?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name']);?></td>
                            <td><?php echo number_format($item['quantity'], 2);?> <?php echo $item['unit'];?></td>
                        </tr>
                        <?php endwhile;?>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endwhile;?>

<script>
const transferProductsByBranch = <?php echo $transfer_products_json; ?>;

function filterToBranch() {
    const fromBranch = document.getElementById('from_branch').value;
    const toSelect = document.getElementById('to_branch');

    for (let i = 0; i < toSelect.options.length; i++) {
        if (toSelect.options[i].value == fromBranch && fromBranch != '') {
            toSelect.options[i].disabled = true;
        } else {
            toSelect.options[i].disabled = false;
        }
    }

    updateProductAvailability();
}

function updateProductAvailability() {
    const fromBranch = document.getElementById('from_branch').value;
    const productSelects = document.querySelectorAll('.transfer-product-select');

    productSelects.forEach((select) => {
        const selectedValue = select.value;
        select.innerHTML = '';

        if (!fromBranch) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select Source Branch First';
            select.appendChild(placeholder);
            return;
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select Product';
        select.appendChild(placeholder);

        const products = transferProductsByBranch[fromBranch] || [];
        products.forEach((product) => {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = product.stock_quantity > 0
                ? `${product.name} (${product.unit}) - ${product.stock_quantity}`
                : `${product.name} (${product.unit}) - Out of stock`;
            option.disabled = product.stock_quantity <= 0;
            if (String(product.id) === String(selectedValue)) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    });
}

function addTransferRow() {
    const container = document.getElementById('transferRows');
    const newRow = container.querySelector('.transfer-row').cloneNode(true);

    newRow.querySelector('select').value = '';
    newRow.querySelector('input').value = '';
    newRow.querySelector('.btn-danger').style.display = 'block';

    container.appendChild(newRow);
    updateProductAvailability();
}

function removeTransferRow(button) {
    const rows = document.querySelectorAll('.transfer-row');
    if (rows.length > 1) {
        button.closest('.transfer-row').remove();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updateProductAvailability();
});
</script>

<?php include 'footer.php';?>