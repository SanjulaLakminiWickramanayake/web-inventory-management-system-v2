<?php
require_once 'config.php';
checkAccess(['admin']);

$page_title = "Reports & Analytics";
include 'header.php';

// Get date range filter
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Sales Summary
$sales_summary_sql = "SELECT 
                        COUNT(*) as total_sales,
                        COALESCE(SUM(total_amount), 0) as total_revenue,
                        COALESCE(AVG(total_amount), 0) as avg_sale
                      FROM sales 
                      WHERE DATE(sale_date) BETWEEN ? AND ?";
$sales_summary_stmt = $conn->prepare($sales_summary_sql);
$sales_summary_stmt->bind_param("ss", $date_from, $date_to);
$sales_summary_stmt->execute();
$sales_summary = $sales_summary_stmt->get_result()->fetch_assoc();

// Sales by Branch
$branch_sales_sql = "SELECT b.name, COALESCE(SUM(s.total_amount), 0) as total, COUNT(s.id) as count
                     FROM branches b
                     LEFT JOIN sales s ON b.id = s.branch_id AND DATE(s.sale_date) BETWEEN ? AND ?
                     GROUP BY b.id, b.name
                     ORDER BY total DESC";
$branch_sales_stmt = $conn->prepare($branch_sales_sql);
$branch_sales_stmt->bind_param("ss", $date_from, $date_to);
$branch_sales_stmt->execute();
$branch_sales = $branch_sales_stmt->get_result();

// Top Products
$top_products_sql = "SELECT p.name, c.name as category, SUM(si.quantity) as total_qty, SUM(si.subtotal) as total_revenue
                     FROM sale_items si
                     JOIN products p ON si.product_id = p.id
                     JOIN categories c ON p.category_id = c.id
                     JOIN sales s ON si.sale_id = s.id
                     WHERE DATE(s.sale_date) BETWEEN ? AND ?
                     GROUP BY p.id, p.name, c.name
                     ORDER BY total_revenue DESC
                     LIMIT 10";
$top_products_stmt = $conn->prepare($top_products_sql);
$top_products_stmt->bind_param("ss", $date_from, $date_to);
$top_products_stmt->execute();
$top_products = $top_products_stmt->get_result();

// Daily Sales Trend
$daily_sales_sql = "SELECT DATE(sale_date) as date, SUM(total_amount) as total, COUNT(*) as count
                    FROM sales
                    WHERE DATE(sale_date) BETWEEN ? AND ?
                    GROUP BY DATE(sale_date)
                    ORDER BY date";
$daily_sales_stmt = $conn->prepare($daily_sales_sql);
$daily_sales_stmt->bind_param("ss", $date_from, $date_to);
$daily_sales_stmt->execute();
$daily_sales = $daily_sales_stmt->get_result();

$daily_dates = [];
$daily_values = [];
$daily_counts = [];
while ($row = $daily_sales->fetch_assoc()) {
    $daily_dates[] = date('M d', strtotime($row['date']));
    $daily_values[] = $row['total'];
    $daily_counts[] = $row['count'];
}

// Low Stock Report
$low_stock_sql = "SELECT s.*, p.name as product_name, p.unit, p.selling_price, b.name as branch_name
                  FROM stock s
                  JOIN products p ON s.product_id = p.id
                  JOIN branches b ON s.branch_id = b.id
                  WHERE s.total_quantity <= s.reorder_level
                  ORDER BY b.name, p.name";
$low_stock = $conn->query($low_stock_sql);

// Stock Value by Category
$stock_value_sql = "SELECT c.name as category, 
                           SUM(s.total_quantity * p.selling_price) as total_value,
                           SUM(s.total_quantity) as total_qty
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    JOIN categories c ON p.category_id = c.id
                    GROUP BY c.id, c.name";
$stock_value = $conn->query($stock_value_sql);

$category_names = [];
$category_values = [];
while ($row = $stock_value->fetch_assoc()) {
    $category_names[] = $row['category'];
    $category_values[] = $row['total_value'];
}
?>

<div class="row fade-in">
    <!-- Date Filter -->
    <div class="col-12 mb-4">
        <div class="chart-container">
            <form method="GET" action="" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row fade-in">
    <!-- Summary Cards -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card primary position-relative">
            <i class="fas fa-shopping-cart card-icon text-primary"></i>
            <div class="card-title">Total Sales</div>
            <div class="card-value"><?php echo $sales_summary['total_sales']; ?></div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card success position-relative">
            <i class="fas fa-dollar-sign card-icon text-success"></i>
            <div class="card-title">Total Revenue</div>
            <div class="card-value"><?php echo formatCurrency($sales_summary['total_revenue']); ?></div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card info position-relative">
            <i class="fas fa-chart-line card-icon text-info"></i>
            <div class="card-title">Average Sale</div>
            <div class="card-value"><?php echo formatCurrency($sales_summary['avg_sale']); ?></div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="dashboard-card warning position-relative">
            <i class="fas fa-exclamation-triangle card-icon text-warning"></i>
            <div class="card-title">Low Stock Items</div>
            <div class="card-value"><?php echo $low_stock->num_rows; ?></div>
        </div>
    </div>
</div>

<div class="row fade-in">
    <!-- Sales Trend Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm p-3">
            <h5 class="mb-4"><i class="fas fa-chart-line me-2 text-primary"></i>Sales Trend</h5>
            <div class="chart-container" style="position: relative; height: 300px;">
                <canvas id="salesTrendChart" style="position: relative; height: 400px">
            </div>
        </div>
    </div>
    
    <!-- Stock Value by Category -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm p-3">
            <h5 class="mb-4"><i class="fas fa-chart-pie me-2 text-success"></i>Stock Value by Category</h5>
            <div class="chart-container" style="position: relative; height: 300px;">
                <canvas id="stockValueChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row fade-in">
    <!-- Sales by Branch -->
    <div class="col-lg-6 mb-4">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-building me-2 text-primary"></i>Sales by Branch</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Sales Count</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($branch = $branch_sales->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo $branch['name']; ?></strong></td>
                            <td><?php echo $branch['count']; ?></td>
                            <td><?php echo formatCurrency($branch['total']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Top Products -->
    <div class="col-lg-6 mb-4">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-boxes me-2 text-success"></i>Top Products</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Qty Sold</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($product = $top_products->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo $product['name']; ?></strong></td>
                            <td><span class="badge bg-info"><?php echo $product['category']; ?></span></td>
                            <td><?php echo number_format($product['total_qty'], 2); ?></td>
                            <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row fade-in">
    <!-- Low Stock Report -->
    <div class="col-12 mb-4">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Low Stock Report</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Branch</th>
                            <th>Current Stock</th>
                            <th>Reorder Level</th>
                            <th>Unit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $low_stock->data_seek(0);
                        while ($item = $low_stock->fetch_assoc()): 
                        ?>
                        <tr class="table-warning">
                            <td><strong><?php echo $item['product_name']; ?></strong></td>
                            <td><?php echo $item['branch_name']; ?></td>
                            <td><?php echo number_format($item['quantity'], 2); ?></td>
                            <td><?php echo number_format($item['reorder_level'], 2); ?></td>
                            <td><?php echo $item['unit']; ?></td>
                            <td><span class="badge bg-danger">Low Stock</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Sales Trend Chart
const trendCtx = document.getElementById('salesTrendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($daily_dates); ?>,
        datasets: [{
            label: 'Revenue (LKR)',
            data: <?php echo json_encode($daily_values); ?>,
            borderColor: '#4e73df',
            backgroundColor: 'rgba(78, 115, 223, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#4e73df',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5
        }, {
            label: 'Number of Sales',
            data: <?php echo json_encode($daily_counts); ?>,
            borderColor: '#1cc88a',
            backgroundColor: 'rgba(28, 200, 138, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#1cc88a',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,

        plugins: {
            title:{
                display : true,
                font: { size: 16, weigth: 'bold'},
                padding: {top: 10, bottom: 15}
            },
            legend: {
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)' },
                ticks: { callback: function(value) { return 'LKR ' + value.toLocaleString(); } }
            },
            y1: {
                type: 'linear',
                display: true,
                min: 0,
                position: 'right',
                grid: { display: false },
                beginAtZero: true
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Stock Value Chart
const stockCtx = document.getElementById('stockValueChart').getContext('2d');
new Chart(stockCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($category_names); ?>,
        datasets: [{
            data: <?php echo json_encode($category_values); ?>,
            backgroundColor: ['#4e73df', '#1cc88a', '#f6c23e', '#e74a3b', '#36b9cc'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 15, font: { size: 12 } }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'LKR ' + context.parsed.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

<?php include 'footer.php'; ?>
