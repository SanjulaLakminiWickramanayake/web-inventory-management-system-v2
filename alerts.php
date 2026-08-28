<?php
ob_start();
require_once 'config.php';

// All logged-in users can view alerts
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$page_title = "Alerts & Notifications";
include 'header.php';

$user_role = getUserRole();
$user_branch = getUserBranch();
$can_manage_alerts = in_array($user_role, ['admin', 'manager'], true);

// Handle Mark as Read
if (isset($_GET['mark_read'])) {
    if (!$can_manage_alerts) {
        $_SESSION['error'] = "Only admin and manager can mark alerts as read.";
        header("Location: alerts.php");
        exit();
    }
    $alert_id = intval($_GET['mark_read']);
    $sql = "UPDATE alerts SET is_read = 1 WHERE id = ?";
    $params = [$alert_id];
    $types = "i";

    if ($user_role != 'admin') {
        $sql .= " AND branch_id = ?";
        $params[] = $user_branch;
        $types .= "i";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    header("Location: alerts.php");
    exit();
}

// Handle Mark All as Read
if (isset($_GET['mark_all_read'])) {
    if (!$can_manage_alerts) {
        $_SESSION['error'] = "Only admin and manager can mark alerts as read.";
        header("Location: alerts.php");
        exit();
    }

    $sql = "UPDATE alerts SET is_read = 1";
    if ($user_role != 'admin') {
        $sql .= " WHERE branch_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_branch);
        $stmt->execute();
    } else {
        $conn->query($sql);
    }
    header("Location: alerts.php");
    exit();
}

// Handle Delete Alert
if (isset($_GET['delete'])) {
    if (!in_array($user_role, ['admin', 'manager'], true)) {
        $_SESSION['error'] = "Only admin and manager can delete restock alerts.";
        header("Location: alerts.php");
        exit();
    }

    $alert_id = intval($_GET['delete']);
    $sql = "DELETE FROM alerts WHERE id = ?";
    $params = [$alert_id];
    $types = "i";

    if ($user_role != 'admin') {
        $sql .= " AND branch_id = ?";
        $params[] = $user_branch;
        $types .= "i";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    header("Location: alerts.php");
    exit();
}

// Get alerts - Low Stock items as alerts
$branch_filter_alerts = "";
$types_alerts = "";
$params_alerts = [];

if ($user_role != 'admin' && !empty($user_branch)) {
    $branch_filter_alerts = " AND s.branch_id = ? ";
    $types_alerts = "i";
    $params_alerts = [$user_branch];
}

$alerts_sql = "SELECT 
                s.id as id,
                'Low Stock' as type,
                CONCAT(p.name, ' is running low. Only ', s.total_quantity, ' units left.') as message,
                p.name as product_name,
                b.name as branch_name,
                NOW() as created_at,
                0 as is_read
               FROM stock s 
               JOIN products p ON s.product_id = p.id
               JOIN branches b ON s.branch_id = b.id
               WHERE s.total_quantity <= 10 " . $branch_filter_alerts . "
               ORDER BY s.total_quantity ASC";

$stmt = $conn->prepare($alerts_sql);
if($stmt === false) { 
    die("<b>SQL Error:</b> " . $conn->error . "<br><b>Query:</b> " . $alerts_sql); 
}
if ($types_alerts) $stmt->bind_param($types_alerts, ...$params_alerts);
$stmt->execute();
$alerts = $stmt->get_result();

// Count unread 
$unread_sql = "SELECT COUNT(*) as count FROM stock s JOIN products p ON s.product_id = p.id WHERE s.total_quantity <= 10 " . $branch_filter_alerts;
$stmt_unread = $conn->prepare($unread_sql);
if ($types_alerts) $stmt_unread->bind_param($types_alerts, ...$params_alerts);
$stmt_unread->execute();
$unread_count = $stmt_unread->get_result()->fetch_assoc()['count'];
?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-bell me-2"></i>Alerts & Notifications</h5>
                <?php if ($can_manage_alerts && $unread_count > 0): ?>
                <a href="alerts.php?mark_all_read=1" class="btn btn-outline-primary">
                    <i class="fas fa-check-double me-1"></i>Mark All as Read
                </a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Product</th>
                            <th>Branch</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($alert = $alerts->fetch_assoc()): ?>
                        <tr class="<?php echo $alert['is_read'] ? '' : 'table-warning'; ?>">
                            <td>
                                <?php if ($alert['is_read']): ?>
                                    <span class="badge bg-secondary"><i class="fas fa-check me-1"></i>Read</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-exclamation me-1"></i>New</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $alert['type'] == 'low_stock' ? 'bg-warning text-dark' : 'bg-danger'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $alert['type'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($alert['message']); ?></td>
                            <td><?php echo $alert['product_name']; ?></td>
                            <td><?php echo $alert['branch_name']; ?></td>
                            <td><?php echo formatDate($alert['created_at']); ?></td>
                            <td>
                                <?php if ($can_manage_alerts && !$alert['is_read']): ?>
                                <a href="alerts.php?mark_read=<?php echo $alert['id']; ?>" class="btn btn-success btn-sm" title="Mark as Read">
                                    <i class="fas fa-check"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($can_manage_alerts): ?>
                                <a href="alerts.php?delete=<?php echo $alert['id']; ?>" class="btn btn-danger btn-sm" title="Delete" onclick="return confirm('Are you sure you want to delete this alert?')">
                                    <i class="fas fa-trash"></i>
                                </a>
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

<?php include 'footer.php'; ?>
