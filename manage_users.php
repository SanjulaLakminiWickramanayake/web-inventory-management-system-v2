<?php
require_once 'config.php';
checkAccess(['admin']);

// Handle user deletion
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Prevent deleting own account
    if ($delete_id == $_SESSION['user_id']) {
        $_SESSION['error'] = "You cannot delete your own account!";
        header("Location: manage_users.php");
        exit();
    }
    
    // Prevent deleting main admin (user_id = 1)
    if ($delete_id == 1) {
        $_SESSION['error'] = "Cannot delete the main admin account!";
        header("Location: manage_users.php");
        exit();
    }
    
    // Delete the user
    $delete_sql = "DELETE FROM users WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $_SESSION['success'] = "User deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting user: " . $conn->error;
    }
    
    header("Location: manage_users.php");
    exit();
}

$page_title = "Manage Users";
include 'header.php';

// Fetch all users with their branch information
$users_sql = "SELECT u.id, u.name, u.username, u.role, b.name as branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.id 
              ORDER BY u.role, u.name";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->execute();
$users_result = $users_stmt->get_result();
?>

<div class="container-fluid fade-in">
    <div class="row mb-4">
        <div class="col-md-6">
            <h2><i class="fas fa-users me-2 text-primary"></i>Manage Users</h2>
        </div>
        <div class="col-md-6 text-end">
            <a href="add_user.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add New User</a>
        </div>
    </div>

    <?php showAlert(); ?>

    <div class="chart-container">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = 1;
                    while ($user = $users_result->fetch_assoc()):
                        // Determine role badge color
                        $role_badge_color = 'bg-secondary';
                        if ($user['role'] == 'admin') {
                            $role_badge_color = 'bg-danger';
                        } elseif ($user['role'] == 'manager') {
                            $role_badge_color = 'bg-warning';
                        } elseif ($user['role'] == 'cashier') {
                            $role_badge_color = 'bg-info';
                        }
                        
                        // Determine branch display
                        $branch_display = ($user['role'] == 'admin') ? 'All Branches' : ($user['branch_name'] ?? 'N/A');
                        
                        // Check if delete button should be disabled
                        $is_own_account = ($user['id'] == $_SESSION['user_id']);
                        $is_main_admin = ($user['id'] == 1);
                        $disable_delete = $is_own_account || $is_main_admin;
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><span class="badge <?php echo $role_badge_color; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                        <td><?php echo htmlspecialchars($branch_display); ?></td>
                        <td>
                            <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning">
                                <i class="fas fa-edit me-1"></i>Edit
                            </a>
                            <button class="btn btn-sm btn-danger" 
                                    onclick="confirmDelete('manage_users.php?delete_id=<?php echo $user['id']; ?>', '<?php echo htmlspecialchars($user['name']); ?>')"
                                    <?php echo $disable_delete ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
