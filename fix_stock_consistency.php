<?php
/**
 * Database Consistency Fix Script
 * 1. Synchronizes stock.total_quantity with sum of stock_batches.remaining_quantity
 * 2. Creates missing batches for products with stock but no batches
 * 3. Removes zero-quantity batches
 */

require_once 'config.php';
checkAccess(['admin']);

$page_title = "Fix Stock Consistency";
include 'header.php';

$fixed_count = 0;
$issues_found = [];
$created_batches = [];
$removed_batches = 0;

echo '<div class="alert alert-info"><strong>Running stock consistency checks...</strong></div>';

// ===== STEP 1: Remove batches with zero remaining_quantity =====
$remove_sql = "DELETE FROM stock_batches WHERE remaining_quantity <= 0 AND quantity > 0";
$conn->query($remove_sql);
$removed_batches = $conn->affected_rows;

if ($removed_batches > 0) {
    echo '<div class="alert alert-warning">Removed ' . $removed_batches . ' empty batches.</div>';
}

// ===== STEP 2: Find stocks with no batches and create default batches =====
$no_batch_sql = "SELECT s.id, s.product_id, s.branch_id, s.total_quantity, p.name
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.total_quantity > 0
                AND NOT EXISTS (SELECT 1 FROM stock_batches WHERE product_id = s.product_id AND branch_id = s.branch_id)";

$result = $conn->query($no_batch_sql);

if ($result && $result->num_rows > 0) {
    echo '<div class="alert alert-warning">Found ' . $result->num_rows . ' stock records with no batches. Creating default batches...</div>';
    
    while ($row = $result->fetch_assoc()) {
        $product_id = $row['product_id'];
        $branch_id = $row['branch_id'];
        $quantity = $row['total_quantity'];
        $product_name = $row['name'];
        
        // Create a default batch for this stock
        $batch_sql = "INSERT INTO stock_batches (product_id, branch_id, batch_no, quantity, remaining_quantity, cost_price, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, NOW())";
        $batch_stmt = $conn->prepare($batch_sql);
        if ($batch_stmt) {
            $batch_no = "AUTO-" . date('YmdHi') . "-" . $product_id . "-" . $branch_id;
            $batch_stmt->bind_param("iisdd", $product_id, $branch_id, $batch_no, $quantity, $quantity);
            if ($batch_stmt->execute()) {
                $created_batches[] = [
                    'product_name' => $product_name,
                    'quantity' => $quantity,
                    'batch_no' => $batch_no
                ];
            }
        }
    }
    
    if (!empty($created_batches)) {
        echo '<div class="alert alert-success">Created ' . count($created_batches) . ' missing batches.</div>';
    }
}

// ===== STEP 3: Fix inconsistencies between stock.total_quantity and batches =====
$check_sql = "SELECT s.id, s.product_id, s.branch_id, s.total_quantity, p.name,
             COALESCE(SUM(sb.remaining_quantity), 0) as batch_total
             FROM stock s
             LEFT JOIN stock_batches sb ON s.product_id = sb.product_id AND s.branch_id = sb.branch_id
             LEFT JOIN products p ON s.product_id = p.id
             GROUP BY s.id, s.product_id, s.branch_id, s.total_quantity, p.name
             HAVING s.total_quantity != batch_total";

$result = $conn->query($check_sql);

if ($result && $result->num_rows > 0) {
    echo '<div class="alert alert-warning"><strong>Found ' . $result->num_rows . ' inconsistencies. Fixing...</strong></div>';
    
    while ($row = $result->fetch_assoc()) {
        $stock_id = $row['id'];
        $product_id = $row['product_id'];
        $branch_id = $row['branch_id'];
        $old_qty = $row['total_quantity'];
        $correct_qty = $row['batch_total'];
        $product_name = $row['name'];
        
        // Update stock.total_quantity to match batch sum
        $update_sql = "UPDATE stock SET total_quantity = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("di", $correct_qty, $stock_id);
        
        if ($stmt->execute()) {
            $fixed_count++;
            $issues_found[] = [
                'product_name' => $product_name,
                'old_qty' => $old_qty,
                'new_qty' => $correct_qty
            ];
        }
    }
    
    echo '<div class="alert alert-success"><strong>Fixed ' . $fixed_count . ' stock records.</strong></div>';
} else {
    echo '<div class="alert alert-info"><strong>No stock-batch inconsistencies found.</strong></div>';
}

// Display all fixes
if (!empty($issues_found) || !empty($created_batches)) {
    echo '<h5 class="mt-4">Summary of Changes:</h5>';
    
    if (!empty($issues_found)) {
        echo '<h6>Stock Quantity Corrections:</h6>';
        echo '<table class="table table-sm table-bordered">';
        echo '<thead><tr><th>Product</th><th>Old Quantity</th><th>New Quantity</th></tr></thead>';
        echo '<tbody>';
        foreach ($issues_found as $issue) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($issue['product_name']) . '</td>';
            echo '<td>' . htmlspecialchars($issue['old_qty']) . '</td>';
            echo '<td>' . htmlspecialchars($issue['new_qty']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    
    if (!empty($created_batches)) {
        echo '<h6>Created Batches:</h6>';
        echo '<table class="table table-sm table-bordered">';
        echo '<thead><tr><th>Product</th><th>Batch No</th><th>Quantity</th></tr></thead>';
        echo '<tbody>';
        foreach ($created_batches as $batch) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($batch['product_name']) . '</td>';
            echo '<td>' . htmlspecialchars($batch['batch_no']) . '</td>';
            echo '<td>' . htmlspecialchars($batch['quantity']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
} else {
    echo '<div class="alert alert-info"><strong>Database is fully consistent. No changes needed.</strong></div>';
}

echo '<div class="mt-4">';
echo '<a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>';
echo '</div>';

include 'footer.php';
?>

