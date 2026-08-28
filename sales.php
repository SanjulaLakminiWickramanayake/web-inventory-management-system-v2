<?php
ob_start();
require_once 'config.php';
checkAccess(['admin', 'manager', 'cashier']);

$user_role = getUserRole();
$user_branch = getUserBranch();
$user_id = $_SESSION['user_id'];

// ========== CASHIER ONLY - Handle Save Sale ==========
if (isset($_POST['save_sale']) && $user_role == 'cashier') {
    $branch_id = intval($_POST['branch_id']);
    $products_data = $_POST['products'];
    $quantities = $_POST['quantities'];
    $prices = $_POST['prices'];
    $total_amount = floatval($_POST['total_amount']);
    
    if (empty($products_data) || empty($quantities) || $total_amount <= 0) {
        $_SESSION['error'] = "Please add at least one product to the sale.";
        header("Location: sales.php");
        exit();
    }
    
    if ($branch_id != $user_branch) {
        $_SESSION['error'] = "You can only create sales for your assigned branch.";
        header("Location: sales.php");
        exit();
    }
    
    $conn->begin_transaction();
    try {
        $invoice_no = 'INV-' . date('YmdHis') . '-' . rand(100,999);
        
        $sale_sql = "INSERT INTO sales (branch_id, invoice_no, total_amount, created_by) VALUES (?, ?, ?, ?)";
        $sale_stmt = $conn->prepare($sale_sql);
        if ($sale_stmt === false) throw new Exception("Sales Prepare Error: " . $conn->error);
        
        $sale_stmt->bind_param("isdi", $branch_id, $invoice_no, $total_amount, $user_id);
        $sale_stmt->execute();
        $sale_id = $sale_stmt->insert_id;
        
        for ($i = 0; $i < count($products_data); $i++) {
            $product_id = intval($products_data[$i]);
            $quantity = floatval($quantities[$i]);
            $price = floatval($prices[$i]);
            $remaining_qty = $quantity;
            
            $stock_check = $conn->prepare("SELECT total_quantity FROM stock WHERE product_id = ? AND branch_id = ?");
            $stock_check->bind_param("ii", $product_id, $branch_id);
            $stock_check->execute();
            $stock_result = $stock_check->get_result()->fetch_assoc();

            if (!$stock_result) {
                $product_info_sql = $conn->prepare("SELECT reorder_level FROM products WHERE id = ?");
                $product_info_sql->bind_param("i", $product_id);
                $product_info_sql->execute();
                $product_info = $product_info_sql->get_result()->fetch_assoc();

                $create_stock_sql = "INSERT INTO stock (product_id, branch_id, total_quantity, reorder_level) VALUES (?, ?, 0, ?)";
                $create_stock_stmt = $conn->prepare($create_stock_sql);
                if ($create_stock_stmt === false) throw new Exception("Stock Init Error: " . $conn->error);
                $create_stock_stmt->bind_param("iid", $product_id, $branch_id, $product_info['reorder_level'] ?? 10);
                $create_stock_stmt->execute();
                $stock_result = ['total_quantity' => 0];
            }

            $batch_total_sql = "SELECT COALESCE(SUM(remaining_quantity), 0) as available_qty
                                FROM stock_batches
                                WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0";
            $batch_total_stmt = $conn->prepare($batch_total_sql);
            $batch_total_stmt->bind_param("ii", $product_id, $branch_id);
            $batch_total_stmt->execute();
            $batch_total_result = $batch_total_stmt->get_result()->fetch_assoc();
            $available_stock = (float)($batch_total_result['available_qty'] ?? 0);

            if ($available_stock <= 0) {
                $available_stock = (float)($stock_result['total_quantity'] ?? 0);
            }

            if ($available_stock < $quantity) {
                throw new Exception("Not enough stock for product. Available: " . $available_stock);
            }
            
            $batch_sql = "SELECT * FROM stock_batches 
                         WHERE product_id = ? AND branch_id = ? AND remaining_quantity > 0 
                         ORDER BY created_at ASC";
            $batch_stmt = $conn->prepare($batch_sql);
            $batch_stmt->bind_param("ii", $product_id, $branch_id);
            $batch_stmt->execute();
            $batches = $batch_stmt->get_result();

            if ($batches->num_rows === 0 && $available_stock > 0) {
                $stock_qty_for_batch = (float)($stock_result['total_quantity'] ?? 0);
                $reconcile_qty = max($stock_qty_for_batch - $available_stock, 0);
                if ($reconcile_qty > 0) {
                    $batch_no = 'AUTO-' . date('YmdHis') . '-' . $product_id . '-' . $branch_id;
                    $mfd_date = date('Y-m-d');
                    $exp_date = date('Y-m-d', strtotime('+1 year'));
                    $batch_quantity = (float)$reconcile_qty;
                    $remaining_batch_quantity = (float)$reconcile_qty;
                    $batch_cost = 0.00;
                    $insert_batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_batch_stmt = $conn->prepare($insert_batch_sql);
                    if ($insert_batch_stmt === false) throw new Exception("Batch Init Error: " . $conn->error);
                    $insert_batch_stmt->bind_param("iisssddd", $product_id, $branch_id, $batch_no, $mfd_date, $exp_date, $batch_quantity, $remaining_batch_quantity, $batch_cost);
                    $insert_batch_stmt->execute();
                } elseif ($stock_qty_for_batch > 0) {
                    $batch_no = 'AUTO-' . date('YmdHis') . '-' . $product_id . '-' . $branch_id;
                    $mfd_date = date('Y-m-d');
                    $exp_date = date('Y-m-d', strtotime('+1 year'));
                    $batch_quantity = (float)$stock_qty_for_batch;
                    $remaining_batch_quantity = (float)$stock_qty_for_batch;
                    $batch_cost = 0.00;
                    $insert_batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, mfd_date, exp_date, quantity, remaining_quantity, cost_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_batch_stmt = $conn->prepare($insert_batch_sql);
                    if ($insert_batch_stmt === false) throw new Exception("Batch Init Error: " . $conn->error);
                    $insert_batch_stmt->bind_param("iisssddd", $product_id, $branch_id, $batch_no, $mfd_date, $exp_date, $batch_quantity, $remaining_batch_quantity, $batch_cost);
                    $insert_batch_stmt->execute();
                }

                $batch_stmt = $conn->prepare($batch_sql);
                $batch_stmt->bind_param("ii", $product_id, $branch_id);
                $batch_stmt->execute();
                $batches = $batch_stmt->get_result();
            }
            
            while ($remaining_qty > 0 && $batch = $batches->fetch_assoc()) {
                $deduct = min($remaining_qty, $batch['remaining_quantity']);
                $batch_id = $batch['id'];
                $item_subtotal = $deduct * $price;
                
                $item_sql = "INSERT INTO sale_items (sale_id, product_id, batch_id, quantity, selling_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
                $item_stmt = $conn->prepare($item_sql);
                if ($item_stmt === false) throw new Exception("Sale Items Prepare Error: " . $conn->error);
                $item_stmt->bind_param("iiiddd", $sale_id, $product_id, $batch_id, $deduct, $price, $item_subtotal);
                $item_stmt->execute();
                
                $update_batch_sql = "UPDATE stock_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?";
                $update_batch_stmt = $conn->prepare($update_batch_sql);
                $update_batch_stmt->bind_param("di", $deduct, $batch_id);
                $update_batch_stmt->execute();
                
                $remaining_qty -= $deduct;
            }
            
            if ($remaining_qty > 0) {
                throw new Exception("Stock mismatch for product");
            }
            
            $update_stock_sql = "UPDATE stock SET total_quantity = total_quantity - ?, last_updated = NOW() WHERE product_id = ? AND branch_id = ?";
            $update_stock_stmt = $conn->prepare($update_stock_sql);
            if ($update_stock_stmt === false) throw new Exception("Stock Update Error: " . $conn->error);
            $update_stock_stmt->bind_param("dii", $quantity, $product_id, $branch_id);
            $update_stock_stmt->execute();

            $check_sql = "SELECT s.total_quantity, s.reorder_level, p.name as product_name, b.name as branch_name 
                         FROM stock s 
                         JOIN products p ON s.product_id = p.id 
                         JOIN branches b ON s.branch_id = b.id 
                         WHERE s.product_id = ? AND s.branch_id = ? AND s.total_quantity <= s.reorder_level";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $product_id, $branch_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $low_stock = $check_result->fetch_assoc();
                $message = $low_stock['product_name'] . " stock is low (" . $low_stock['total_quantity'] . ") in " . $low_stock['branch_name'];
                
                $alert_sql = "INSERT INTO alerts (product_id, branch_id, alert_type, message) VALUES (?, ?, 'low_stock', ?)";
                $alert_stmt = $conn->prepare($alert_sql);
                $alert_stmt->bind_param("iis", $product_id, $branch_id, $message);
                $alert_stmt->execute();
                
                $stock_qty = $low_stock['total_quantity'];
                $reorder_level = $low_stock['reorder_level'];
                $requested_qty = $reorder_level * 2;
                
                $reorder_check = $conn->prepare("SELECT id FROM reorder_requests WHERE branch_id = ? AND product_id = ? AND status = 'pending'");
                $reorder_check->bind_param("ii", $branch_id, $product_id);
                $reorder_check->execute();
                
                if ($reorder_check->get_result()->num_rows == 0) {
                    $reorder_sql = "INSERT INTO reorder_requests (branch_id, product_id, current_stock, requested_quantity) 
                                   VALUES (?, ?, ?, ?)";
                    $reorder_stmt = $conn->prepare($reorder_sql);
                    $reorder_stmt->bind_param("iidd", $branch_id, $product_id, $stock_qty, $requested_qty);
                    $reorder_stmt->execute();
                }
            }
        }
        
        $conn->commit();
        $_SESSION['success'] = "Sale completed! Invoice: " . $invoice_no;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error processing sale: " . $e->getMessage();
    }
    header("Location: sales.php");
    exit();
}

$page_title = ($user_role == 'admin' || $user_role == 'manager') ? "Sales Report" : "Sales (POS)";
include 'header.php';

// ========== ADMIN/MANAGER VIEW - SALES REPORT ==========
if ($user_role == 'admin' || $user_role == 'manager') {
    
    $filter_branch = isset($_GET['branch']) ? $_GET['branch'] : 'all';
    $filter_from = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
    $filter_to = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

    $branches_sql = "SELECT * FROM branches ORDER BY id ASC";
    $branches_result = $conn->query($branches_sql);

    $where = "WHERE DATE(s.sale_date) BETWEEN '$filter_from' AND '$filter_to'";
    if ($filter_branch != 'all') {
        $where .= " AND s.branch_id = " . intval($filter_branch);
    }

    $sales_sql = "SELECT s.*, b.name as branch_name, u.full_name as cashier_name 
                  FROM sales s 
                  JOIN branches b ON s.branch_id = b.id 
                  LEFT JOIN users u ON s.created_by = u.id 
                  $where ORDER BY s.sale_date DESC LIMIT 500";
    $sales_result = $conn->query($sales_sql);

    $summary_sql = "SELECT COUNT(s.id) as total_invoices, SUM(s.total_amount) as total_sales, COUNT(DISTINCT s.branch_id) as active_branches FROM sales s $where";
    $summary = $conn->query($summary_sql)->fetch_assoc();

    $branch_sales_sql = "SELECT b.name, COUNT(s.id) as invoice_count, SUM(s.total_amount) as branch_total 
                         FROM sales s JOIN branches b ON s.branch_id = b.id 
                         $where GROUP BY s.branch_id ORDER BY branch_total DESC";
    $branch_sales = $conn->query($branch_sales_sql);
?>

<style>
.report-card-orange {
    background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    color: white;
}
.report-card-amber {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}
.report-card-red {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}
</style>

<div class="row fade-in">
    <div class="col-12 mb-3">
        <div class="d-flex justify-content-between align-items-center">
            <h3><i class="fas fa-chart-line me-2"></i>Sales Report - All Branches</h3>
            <span class="badge bg-<?= $user_role == 'admin' ? 'danger' : 'warning' ?> fs-6"><?= strtoupper($user_role) ?></span>
        </div>
    </div>

    <div class="col-12 mb-3">
        <div class="chart-container">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Branch</label>
                    <select name="branch" class="form-select">
                        <option value="all">All 12 Branches</option>
                        <?php 
                        $branches_result->data_seek(0);
                        while($b = $branches_result->fetch_assoc()): ?>
                        <option value="<?= $b['id'] ?>" <?= $filter_branch == $b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" value="<?= $filter_from ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" value="<?= $filter_to ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label><br>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                    <a href="sales.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card report-card-orange">
            <div class="card-body">
                <h6 class="text-white-50">Total Sales</h6>
                <h2 class="mb-0">LKR <?= number_format($summary['total_sales'] ?? 0, 2) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card report-card-amber">
            <div class="card-body">
                <h6 class="text-white-50">Total Invoices</h6>
                <h2 class="mb-0"><?= $summary['total_invoices'] ?? 0 ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card report-card-red">
            <div class="card-body">
                <h6 class="text-white-50">Active Branches</h6>
                <h2 class="mb-0"><?= $summary['active_branches'] ?? 0 ?> / 12</h2>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-4">
        <div class="chart-container">
            <h5 class="mb-3"><i class="fas fa-store me-2"></i>Branch Wise Sales</h5>
            <div style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Branch</th><th>Invoices</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php while($bs = $branch_sales->fetch_assoc()): ?>
                        <tr>
                            <td><small><?= htmlspecialchars($bs['name']) ?></small></td>
                            <td><?= $bs['invoice_count'] ?></td>
                            <td class="text-end"><strong><?= number_format($bs['branch_total'], 2) ?></strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-4">
        <div class="chart-container">
            <h5 class="mb-3"><i class="fas fa-list me-2"></i>Sales Details - <?= date('M d', strtotime($filter_from)) ?> to <?= date('M d, Y', strtotime($filter_to)) ?></h5>
            <div class="table-responsive" style="max-height: 500px;">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr><th>Invoice</th><th>Branch</th><th>Cashier</th><th>Date</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php if($sales_result->num_rows > 0): ?>
                            <?php while($row = $sales_result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['invoice_no']) ?></td>
                                <td><small><?= htmlspecialchars($row['branch_name']) ?></small></td>
                                <td><small><?= htmlspecialchars($row['cashier_name'] ?? 'N/A') ?></small></td>
                                <td><?= date('M d, h:i A', strtotime($row['sale_date'])) ?></td>
                                <td class="text-end"><strong><?= formatCurrency($row['total_amount']) ?></strong></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4">No sales found for selected filters</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
} else {
    // ========== CASHIER VIEW - POS SYSTEM ==========
    
    $products_sql = "SELECT p.*, p.selling_price as price, c.name as category_name 
    FROM products p 
    JOIN categories c ON p.category_id = c.id 
    ORDER BY c.name, p.name";
    $products = $conn->query($products_sql);
    $products_array = [];
    while ($p = $products->fetch_assoc()) {
        $products_array[] = $p;
    }

    $branch_name_sql = $conn->prepare("SELECT name FROM branches WHERE id = ?");
    $branch_name_sql->bind_param("i", $user_branch);
    $branch_name_sql->execute();
    $branch_name = $branch_name_sql->get_result()->fetch_assoc()['name'];

    $recent_sales_sql = "SELECT s.*, b.name as branch_name FROM sales s JOIN branches b ON s.branch_id = b.id 
                         WHERE s.branch_id = ? ORDER BY s.sale_date DESC LIMIT 10";
    $stmt = $conn->prepare($recent_sales_sql);
    $stmt->bind_param("i", $user_branch);
    $stmt->execute();
    $recent_sales = $stmt->get_result();
?>

<div class="row fade-in">
    <div class="col-lg-8 mb-4">
        <div class="chart-container">
            <h5 class="mb-4"><i class="fas fa-shopping-cart me-2 text-primary"></i>Point of Sale - <?= htmlspecialchars($branch_name) ?></h5>
            
            <form method="POST" action="" id="saleForm">
                <input type="hidden" name="branch_id" value="<?= $user_branch ?>">
                
                <div id="productRows">
                    <div class="row product-row mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Product</label>
                            <select class="form-select product-select" name="products[]" required onchange="updatePrice(this)">
                                <option value="">Select Product</option>
                                <?php foreach ($products_array as $p): ?>
                                <option value="<?= $p['id']; ?>" data-price="<?= $p['price'] ?? 0; ?>" data-unit="<?= $p['unit']; ?>">
                                    <?= htmlspecialchars($p['name']); ?> (<?= $p['category_name']; ?>) - LKR <?= number_format($p['price'], 2); ?>/<?= $p['unit']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" class="form-control quantity-input" name="quantities[]" step="0.01" min="0.01" value="1" required onchange="calculateTotal()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price (LKR)</label>
                            <input type="number" class="form-control price-input" name="prices[]" step="0.01" min="0" readonly>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)" style="display:none;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <button type="button" class="btn btn-outline-primary" onclick="addProductRow()">
                        <i class="fas fa-plus me-1"></i>Add Another Product
                    </button>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6 offset-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">Total Amount:</h5>
                                    <h3 class="mb-0 text-primary">LKR <span id="totalAmount">0.00</span></h3>
                                </div>
                                <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-end">
                    <button type="submit" name="save_sale" class="btn btn-success btn-lg">
                        <i class="fas fa-save me-2"></i>Complete Sale
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="col-lg-4 mb-4">
        <div class="chart-container">
            <h5 class="mb-4"><i class="fas fa-history me-2 text-info"></i>Recent Sales - <?= htmlspecialchars($branch_name) ?></h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sale = $recent_sales->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($sale['invoice_no']); ?></td>
                            <td><strong><?= formatCurrency($sale['total_amount']); ?></strong></td>
                            <td><?= date('M d, H:i', strtotime($sale['sale_date'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function updatePrice(select) {
    const row = select.closest('.product-row');
    const option = select.options[select.selectedIndex];
    const priceInput = row.querySelector('.price-input');
    const quantityInput = row.querySelector('.quantity-input');
    
    if (option.value) {
        priceInput.value = option.getAttribute('data-price');
        quantityInput.value = 1;
        calculateTotal();
    } else {
        priceInput.value = '';
    }
}

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.product-row').forEach(row => {
        const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        total += quantity * price;
    });
    
    document.getElementById('totalAmount').textContent = total.toFixed(2);
    document.getElementById('total_amount_input').value = total.toFixed(2);
}

function addProductRow() {
    const container = document.getElementById('productRows');
    const newRow = container.querySelector('.product-row').cloneNode(true);
    
    newRow.querySelector('.product-select').value = '';
    newRow.querySelector('.quantity-input').value = 1;
    newRow.querySelector('.price-input').value = '';
    newRow.querySelector('.btn-danger').style.display = 'block';
    
    container.appendChild(newRow);
    calculateTotal();
}

function removeRow(button) {
    const row = button.closest('.product-row');
    if (document.querySelectorAll('.product-row').length > 1) {
        row.remove();
        calculateTotal();
    }
}

calculateTotal();
</script>

<?php } ?>

<?php include 'footer.php'; ob_end_flush(); ?>