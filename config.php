<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'chicken_farm_inventory');

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to handle special characters
$conn->set_charset("utf8mb4");

// Start session
session_start();

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to get current user role
function getUserRole() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : null;
}

// Helper function to get current user branch
function getUserBranch() {
    return isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : null;
}

// Helper function to check if user has access to a page
function checkAccess($allowedRoles) {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
    
    if (!in_array(getUserRole(), $allowedRoles)) {
        $_SESSION['error'] = "Access Denied! You don't have permission to access this page.";
        header("Location: dashboard.php");
        exit();
    }
}

// Helper function to redirect with message
function redirectWithMessage($url, $type, $message) {
    $_SESSION[$type] = $message;
    header("Location: " . $url);
    exit();
}

// Helper function to show alert messages
function showAlert() {
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['success']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['error']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        unset($_SESSION['error']);
    }
}

// Helper function to sanitize input
function sanitize($conn, $input) {
    return htmlspecialchars(strip_tags(trim($conn->real_escape_string($input))));
}

// Check for low stock and create alerts automatically
function checkLowStock($conn) {
    $sql = "SELECT s.*, p.name as product_name, b.name as branch_name 
            FROM stock s 
            JOIN products p ON s.product_id = p.id 
            JOIN branches b ON s.branch_id = b.id 
            WHERE s.quantity <= s.reorder_level";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        // Check if alert already exists
        $check_sql = "SELECT id FROM alerts 
                      WHERE product_id = ? AND branch_id = ? AND type = 'low_stock' AND is_read = 0";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $row['product_id'], $row['branch_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            // Create alert
            $message = $row['product_name'] . " stock is low (" . $row['quantity'] . " " . 
                      getProductUnit($conn, $row['product_id']) . ") in " . $row['branch_name'];
            
            $alert_sql = "INSERT INTO alerts (product_id, branch_id, type, message) VALUES (?, ?, 'low_stock', ?)";
            $alert_stmt = $conn->prepare($alert_sql);
            $alert_stmt->bind_param("iis", $row['product_id'], $row['branch_id'], $message);
            $alert_stmt->execute();
            
            // Create reorder request
            $requested_qty = $row['reorder_level'] * 2;
            $reorder_sql = "INSERT INTO reorder_requests (branch_id, product_id, current_stock, requested_quantity) 
                           VALUES (?, ?, ?, ?)";
            $reorder_stmt = $conn->prepare($reorder_sql);
            $reorder_stmt->bind_param("iidd", $row['branch_id'], $row['product_id'], $row['quantity'], $requested_qty);
            $reorder_stmt->execute();
        }
    }
}

// Helper function to get product unit
function getProductUnit($conn, $product_id) {
    $sql = "SELECT unit FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['unit'] : 'piece';
}

// Helper function to get unread alerts count
function getUnreadAlertsCount($conn) {
    $branch_filter = "";
    $params = [];
    $types = "";
    
    if (getUserRole() == 'manager') {
        $branch_filter = " AND branch_id = ?";
        $params[] = getUserBranch();
        $types .= "i";
    }
    
    $sql = "SELECT COUNT(*) as count FROM alerts WHERE is_read = 0" . $branch_filter;
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Format currency
function formatCurrency($amount) {
    return "LKR " . number_format($amount, 2);
}

// Format date
function formatDate($date) {
    return date('Y-m-d H:i', strtotime($date));
}
