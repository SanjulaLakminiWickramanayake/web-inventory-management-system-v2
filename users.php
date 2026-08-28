<?php
ob_start();
require_once 'config.php';
checkAccess(['admin']);

$page_title = "User Management";
include 'header.php';

// Handle Add User
if (isset($_POST['add_user'])) {
    $username = sanitize($conn, $_POST['username']);
    $password = $_POST['password'];
    $role = sanitize($conn, $_POST['role']);
    $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
    
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required.";
    } elseif (strlen($password) < 6) {
        $_SESSION['error'] = "Password must be at least 6 characters.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (username, password, role, branch_id) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $username, $hashed_password, $role, $branch_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "User added successfully!";
        } else {
            $_SESSION['error'] = "Error adding user: " . $stmt->error;
        }
    }
    header("Location: users.php");
    exit();
}

// Handle Edit User
if (isset($_POST['edit_user'])) {
    $id = intval($_POST['id']);
    $username = sanitize($conn, $_POST['username']);
    $role = sanitize($conn, $_POST['role']);
    $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
    $password = $_POST['password'];

    if (empty($username)) {
        $_SESSION['error'] = "Username is required.";
    } else {
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $_SESSION['error'] = "Password must be at least 6 characters.";
                header("Location: users.php");
                exit();
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET username = ?, password = ?, role = ?, branch_id = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssii", $username, $hashed_password, $role, $branch_id, $id);
        } else {
            $sql = "UPDATE users SET username = ?, role = ?, branch_id = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssii", $username, $role, $branch_id, $id);
        }

        if ($stmt->execute()) {
            $_SESSION['success'] = "User updated successfully!";
        } else {
            $_SESSION['error'] = "Error updating user: " . $stmt->error;
        }
    }
    header("Location: users.php");
    exit();
}

// Handle Delete User
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Prevent deleting own account
    if ($id == $_SESSION['user_id']) {
        $_SESSION['error'] = "You cannot delete your own account.";
    } else {
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "User deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting user: " . $stmt->error;
        }
    }
    header("Location: users.php");
    exit();
}

// Get all users with branch info
$users_sql = "SELECT u.*, b.name as branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.id 
              ORDER BY u.role, u.username";
$users = $conn->query($users_sql);

// Get branches for dropdown
$branches = $conn->query("SELECT * FROM branches ORDER BY name");
$branches_array = [];
while ($b = $branches->fetch_assoc()) {
    $branches_array[] = $b;
}
?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-users me-2"></i>Users</h5>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-1"></i>Add User
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Branch</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            <td>
                                <span class="badge <?php 
                                    echo $user['role'] == 'admin' ? 'bg-danger' : 
                                         ($user['role'] == 'manager' ? 'bg-warning' : 'bg-info'); 
                                ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo $user['branch_name'] ? $user['branch_name'] : '<span class="text-muted">All Branches</span>'; ?></td>
                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button class="btn btn-action btn-delete" onclick="confirmDelete('users.php?delete=<?php echo $user['id']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" name="username" required placeholder="Enter username">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" class="form-control" name="password" required placeholder="Enter password (min 6 chars)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select class="form-select" name="role" required>
                            <option value="cashier">Cashier</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select class="form-select" name="branch_id">
                            <option value="">Select Branch</option>
                            <?php foreach ($branches_array as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo $b['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Required for Manager and Cashier roles</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-success"><i class="fas fa-save me-1"></i>Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modals -->
<?php 
$users->data_seek(0);
while ($user = $users->fetch_assoc()): 
?>
<div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control" name="password" placeholder="Enter new password (min 6 chars)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select class="form-select" name="role" required>
                            <option value="cashier" <?php echo $user['role'] == 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                            <option value="manager" <?php echo $user['role'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                            <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select class="form-select" name="branch_id">
                            <option value="">Select Branch</option>
                            <?php foreach ($branches_array as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $user['branch_id'] == $b['id'] ? 'selected' : ''; ?>><?php echo $b['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_user" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endwhile; ?>

<?php include 'footer.php'; ?>
