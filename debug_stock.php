<?php
/**
 * Debug Stock Status
 * Shows current stock and batch information for all products
 */

require_once 'config.php';
checkAccess(['admin']);

$page_title = "Stock Debug Info";
include 'header.php';

echo '<div class="table-responsive">';
echo '<table class="table table-bordered table-sm">';
echo '<thead class="table-dark"><tr>';
echo '<th>Product</th><th>Branch</th><th>Stock Total</th><th>Batch Count</th><th>Batch Total Remaining</th><th>Difference</th>';
echo '</tr></thead><tbody>';

$sql = "SELECT 
        p.id as product_id,
        p.name,
        s.branch_id,
        b.name as branch_name,
        s.total_quantity,
        COUNT(sb.id) as batch_count,
        COALESCE(SUM(sb.remaining_quantity), 0) as batch_total_remaining
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN stock_batches sb ON p.id = sb.product_id AND s.branch_id = sb.branch_id
        WHERE s.id IS NOT NULL
        GROUP BY p.id, p.name, s.branch_id, b.name, s.total_quantity
        ORDER BY p.name, s.branch_id";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $diff = floatval($row['total_quantity']) - floatval($row['batch_total_remaining']);
    $diff_class = $diff !== 0 ? 'table-warning' : '';
    
    echo '<tr ' . ($diff !== 0 ? 'class="' . $diff_class . '"' : '') . '>';
    echo '<td><strong>' . htmlspecialchars($row['name']) . '</strong></td>';
    echo '<td>' . htmlspecialchars($row['branch_name']) . '</td>';
    echo '<td>' . number_format($row['total_quantity'], 2) . '</td>';
    echo '<td>' . $row['batch_count'] . '</td>';
    echo '<td>' . number_format($row['batch_total_remaining'], 2) . '</td>';
    echo '<td>' . ($diff !== 0 ? '<span class="badge bg-danger">' . number_format($diff, 2) . '</span>' : '✓') . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';

echo '<div class="mt-4">';
echo '<p><small>Highlighted rows show differences between stock.total_quantity and batch remaining quantities.</small></p>';
echo '<a href="fix_stock_consistency.php" class="btn btn-warning">Run Stock Fix</a> ';
echo '<a href="dashboard.php" class="btn btn-secondary">Back</a>';
echo '</div>';

include 'footer.php';
?>
