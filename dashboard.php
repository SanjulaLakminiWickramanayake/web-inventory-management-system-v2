<?php
require_once 'config.php';

// Check access based on role
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$page_title = "Dashboard";
include 'header.php';

$user_role = getUserRole();
$user_branch_id = getUserBranch(); // මේක ID එක return කරනවා කියලා හිතනවා

// --- එකම Branch Filter Logic එක හැමතැනටම ---
$branch_filter_where = ""; // WHERE clause එකේ පලවෙනි එකට
$branch_filter_and = "";   // WHERE එකේ AND දාලා එකතු කරන්න
$types = "";
$params = []; // හැම වෙලේම Array එකක්

if ($user_role != 'admin' && !empty($user_branch_id)) {
    $branch_filter_where = " WHERE branch_id = ?";
    $branch_filter_and = " AND branch_id = ?";
    $types = "i";
    $params = [$user_branch_id]; // Array එකක්
}

// Total Sales
$sales_sql = "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales" . $branch_filter_where;
$sales_stmt = $conn->prepare($sales_sql);
if ($types) $sales_stmt->bind_param($types, ...$params);
$sales_stmt->execute();
$total_sales = $sales_stmt->get_result()->fetch_assoc()['total'];

// Total Products
$products_sql = "SELECT COUNT(*) as total FROM products";
$total_products = $conn->query($products_sql)->fetch_assoc()['total'];

// Total Branches (admin only)
$total_branches = 0;
if ($user_role == 'admin') {
    $branches_sql = "SELECT COUNT(*) as total FROM branches";
    $total_branches = $conn->query($branches_sql)->fetch_assoc()['total'];
}

// Low Stock Count - stock table එකෙන් quantity <= 10
$low_stock_sql = "SELECT COUNT(*) as total FROM stock s WHERE s.total_quantity <= 10" . $branch_filter_and;
$low_stock_stmt = $conn->prepare($low_stock_sql);

if ($types) $low_stock_stmt->bind_param($types, ...$params);
$low_stock_stmt->execute();
$low_stock_count = $low_stock_stmt->get_result()->fetch_assoc()['total'];

// Recent Sales
$recent_sales_sql = "SELECT s.*, b.name as branch_name FROM sales s JOIN branches b ON s.branch_id = b.id WHERE 1=1" . 
                    $branch_filter_and . " ORDER BY s.sale_date DESC LIMIT 5";
$recent_sales_stmt = $conn->prepare($recent_sales_sql);
if ($types) $recent_sales_stmt->bind_param($types, ...$params);
$recent_sales_stmt->execute();
$recent_sales = $recent_sales_stmt->get_result();

// Sales data for chart (last 7 days)
$chart_dates = [];
$chart_values = [];

for ($i = 6; $i >= 0; $i--) {
    $date_key = date('Y-m-d', strtotime("-$i days"));
    $date_label = date('M d', strtotime("-$i days"));
    $chart_dates[] = $date_label;
    $chart_values[$date_key] = 0; 
}

$chart_sql = "SELECT DATE(sale_date) as date, SUM(total_amount) as total FROM sales WHERE sale_date >= DATE(NOW()) - INTERVAL 6 DAY";
$chart_sql .= $branch_filter_and; // adminට මේක හිස්, cashierට AND branch_id = ?
$chart_sql .= " GROUP BY DATE(sale_date)";

$chart_stmt = $conn->prepare($chart_sql);
if ($types) $chart_stmt->bind_param($types, ...$params);
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();

while ($row = $chart_result->fetch_assoc()) {
    $db_date = $row['date'];
    if (isset($chart_values[$db_date])) {
        $chart_values[$db_date] = (float)$row['total'];
    }
}

$chart_values = array_values($chart_values);

// Sales by branch (admin only)
$branch_sales = [];
if ($user_role == 'admin') {
    $branch_sales_sql = "SELECT b.name, COALESCE(SUM(s.total_amount), 0) as total 
                        FROM branches b 
                        LEFT JOIN sales s ON b.id = s.branch_id 
                        GROUP BY b.id, b.name 
                        ORDER BY total DESC";
    $branch_sales_result = $conn->query($branch_sales_sql);
    while ($row = $branch_sales_result->fetch_assoc()) {
        $branch_sales[] = $row;
    }
}

// Recent Alerts - stock table එකෙන්ම ගන්නවා
$alerts_sql = "SELECT 'Low Stock' as type,
               CONCAT(p.name, ' is running low. Only ', s.total_quantity, ' units left.') as message,
               NOW() as created_at, p.name as product_name, b.name as branch_name
               FROM stock s
               JOIN products p ON s.product_id = p.id
               JOIN branches b ON s.branch_id = b.id
               WHERE s.total_quantity <= 10"; 

if ($user_role != 'admin' && !empty($user_branch_id)) {
    $alerts_sql .= " AND s.branch_id = " . intval($user_branch_id);
}

$alerts_sql .= " ORDER BY s.total_quantity ASC LIMIT 5";
$recent_alerts = $conn->query($alerts_sql);

?>

<div class="row fade-in">
    <!-- Dashboard Cards -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card primary position-relative">
            <i class="fas fa-dollar-sign card-icon text-primary"></i>
            <div class="card-title">Total Sales</div>
            <div class="card-value"><?php echo formatCurrency($total_sales); ?></div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card success position-relative">
            <i class="fas fa-boxes card-icon text-success"></i>
            <div class="card-title">Total Products</div>
            <div class="card-value"><?php echo $total_products; ?></div>
        </div>
    </div>
    
    <?php if ($user_role == 'admin'): ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card info position-relative">
            <i class="fas fa-building card-icon text-info"></i>
            <div class="card-title">Total Branches</div>
            <div class="card-value"><?php echo $total_branches; ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card info position-relative">
            <i class="fas fa-shopping-cart card-icon text-info"></i>
            <div class="card-title">Today's Sales</div>
            <div class="card-value">
                <?php 
                $today_sql = "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales WHERE DATE(sale_date) = CURDATE()" . 
                            ($user_branch_id ? " AND branch_id = " . $user_branch_id : "");
                echo formatCurrency($conn->query($today_sql)->fetch_assoc()['total']);
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card warning position-relative">
            <i class="fas fa-exclamation-triangle card-icon text-warning"></i>
            <div class="card-title">Low Stock Items</div>
            <div class="card-value"><?php echo $low_stock_count; ?></div>
        </div>
    </div>
</div>

<div class="row fade-in">
    <!-- Sales Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm p-3" style="overflow: hidden:" >
            <h5 class="mb-4"><i class="fas fa-chart-line me-2 text-primary"></i>Sales Overview (Last 7 Days)</h5>
            <div class="chart-container" style="position: relative; height: 320px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Recent Alerts -->
    <div class="col-lg-4 mb-4">
        <div class="chart-container">
            <h5 class="mb-4"><i class="fas fa-bell me-2 text-warning"></i>Recent Alerts</h5>
            <?php if ($recent_alerts->num_rows > 0): ?>
                <div class="list-group list-group-flush">
                    <?php while ($alert = $recent_alerts->fetch_assoc()): ?>
                    <div class="list-group-item px-0 py-2 border-0 border-bottom">
                        <div class="d-flex w-100 justify-content-between">
                            <small class="text-muted"><?php echo formatDate($alert['created_at']); ?></small>
                            <span class="badge <?php echo $alert['type'] == 'low_stock' ? 'bg-warning' : 'bg-danger'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $alert['type'])); ?>
                            </span>
                        </div>
                        <p class="mb-1" style="font-size: 13px;"><?php echo htmlspecialchars($alert['message']); ?></p>
                        <small class="text-muted"><?php echo $alert['branch_name']; ?></small>
                    </div>
                    <?php endwhile; ?>
                </div>
                <div class="text-center mt-3">
                    <a href="alerts.php" class="btn btn-sm btn-outline-primary">View All Alerts</a>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                    <p class="text-muted">No alerts at the moment!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($user_role == 'admin'): ?>
<div class="row fade-in">
    <!-- Sales by Branch Chart -->
    <div class="col-lg-6 mb-4">
        <div class="chart-container" style="position: relative; height: 450px; padding-bottom: 70px;">
            <h5 class="mb-4"><i class="fas fa-chart-pie me-2 text-success"></i>Sales by Branch</h5>
            <canvas id="branchChart"></canvas>
        </div>
    </div>
    
    <!-- Recent Sales Table -->
    <div class="col-lg-6 mb-4">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-shopping-cart me-2 text-primary"></i>Recent Sales</h5>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Branch</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sale = $recent_sales->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $sale['id']; ?></td>
                            <td><?php echo $sale['branch_name']; ?></td>
                            <td><strong><?php echo formatCurrency($sale['total_amount']); ?></strong></td>
                            <td><?php echo formatDate($sale['sale_date']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row fade-in">
    <div class="col-12 mb-4">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-shopping-cart me-2 text-primary"></i>Recent Sales</h5>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sale = $recent_sales->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $sale['id']; ?></td>
                            <td><strong><?php echo formatCurrency($sale['total_amount']); ?></strong></td>
                            <td><?php echo formatDate($sale['sale_date']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Sales Overview Chart
const salesCtx = document.getElementById('salesChart').getContext('2d');
new Chart(salesCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_dates); ?>,
        datasets: [{
            label: 'Sales (LKR)',
            data: <?php echo json_encode($chart_values); ?>,
            borderColor: '#4e73df',
            backgroundColor: 'rgba(78, 115, 223, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#4e73df',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

<?php if ($user_role == 'admin'): ?>
// Sales by Branch Chart
const branchCtx = document.getElementById('branchChart').getContext('2d');
new Chart(branchCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($branch_sales, 'name')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($branch_sales, 'total')); ?>,
            backgroundColor: [
                '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                '#6f42c1', '#fd7e14', '#20c997', '#6c757d', '#d63384', '#6610f2'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '50%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth:10, padding: 15, font: { size: 12 } }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
