<?php
require_once 'config.php';
checkAccess(['admin']);

$page_title = "Add New User";

// Initialize form data
$form_data = [
    'name' => '',
    'username' => '',
    'role' => 'cashier',
    'branch_id' => ''
];

$errors = [];

// Handle form submission
if (isset($_POST['add_user'])) {
    // Sanitize and validate inputs
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = trim($_POST['role'] ?? 'cashier');
    $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : null;

    // Store form data for re-display
    $form_data['name'] = htmlspecialchars($name);
    $form_data['username'] = htmlspecialchars($username);
    $form_data['role'] = htmlspecialchars($role);
    $form_data['branch_id'] = $branch_id;

    // Validate required fields
    if (empty($name)) {
        $errors[] = "Full Name is required.";
    }
    if (empty($username)) {
        $errors[] = "Username is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }
    if (empty($confirm_password)) {
        $errors[] = "Confirm Password is required.";
    }
    if (empty($role) || !in_array($role, ['admin', 'manager', 'cashier'])) {
        $errors[] = "Invalid Role selected.";
    }

    // Validate password
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    if (!empty($password) && $password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Validate branch for non-admin users
    if ($role !== 'admin' && empty($branch_id)) {
        $errors[] = "Branch is required for manager and cashier roles.";
    }

    // Check if username already exists
    if (!empty($username) && empty($errors)) {
        $check_username_sql = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_username_sql);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $errors[] = "Username already exists. Please choose a different username.";
        }
    }

    // If no errors, insert the user
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $final_branch_id = ($role === 'admin') ? null : $branch_id;

        $insert_sql = "INSERT INTO users (name, username, password, role, branch_id) VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ssssi", $name, $username, $hashed_password, $role, $final_branch_id);

        if ($insert_stmt->execute()) {
            $_SESSION['success'] = "User created successfully!";
            header("Location: manage_users.php");
            exit();
        } else {
            $errors[] = "Error creating user: " . $conn->error;
        }
    }
}

include 'header.php';

// Fetch branches for dropdown
$branches_sql = "SELECT id, name FROM branches ORDER BY name";
$branches_result = $conn->query($branches_sql);
$branches = [];
while ($branch = $branches_result->fetch_assoc()) {
    $branches[] = $branch;
}
?>

<div class="container-fluid fade-in">
    <div class="row mb-4">
        <div class="col-md-8">
            <h2><i class="fas fa-user-plus me-2 text-primary"></i>Add New User</h2>
        </div>
    </div>

    <?php
    // Display error messages
    if (!empty($errors)):
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        foreach ($errors as $error) {
            echo '<div><i class="fas fa-exclamation-circle me-2"></i>' . htmlspecialchars($error) . '</div>';
        }
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    endif;
    ?>

    <div class="chart-container" style="max-width: 600px;">
        <form method="POST" action="" id="addUserForm">
            <div class="mb-3">
                <label for="name" class="form-label">Full Name *</label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo $form_data['name']; ?>" required>
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username *</label>
                <input type="text" class="form-control" id="username" name="username" value="<?php echo $form_data['username']; ?>" required>
                <small class="text-muted">Must be unique. Used for login.</small>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password *</label>
                <input type="password" class="form-control" id="password" name="password" required>
                <small class="text-muted">Minimum 6 characters.</small>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password *</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="mb-3">
                <label for="role" class="form-label">Role *</label>
                <select class="form-select" id="role" name="role" onchange="handleRoleChange()" required>
                    <option value="cashier" <?php echo $form_data['role'] === 'cashier' ? 'selected' : ''; ?>>Cashier</option>
                    <option value="manager" <?php echo $form_data['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                    <option value="admin" <?php echo $form_data['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>

            <div class="mb-3" id="branchContainer">
                <label for="branch_id" class="form-label">Branch *</label>
                <select class="form-select" id="branch_id" name="branch_id" required>
                    <option value="">Select Branch</option>
                    <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo $form_data['branch_id'] == $branch['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                <button type="submit" name="add_user" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Create User
                </button>
                <a href="manage_users.php" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function handleRoleChange() {
    var role = document.getElementById('role').value;
    var branchContainer = document.getElementById('branchContainer');
    var branchSelect = document.getElementById('branch_id');

    if (role === 'admin') {
        // Hide and disable branch dropdown for admin
        branchContainer.style.display = 'none';
        branchSelect.disabled = true;
        branchSelect.removeAttribute('required');
    } else {
        // Show and enable branch dropdown for manager/cashier
        branchContainer.style.display = 'block';
        branchSelect.disabled = false;
        branchSelect.setAttribute('required', 'required');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    handleRoleChange();
});
</script>

<?php include 'footer.php'; ?>
