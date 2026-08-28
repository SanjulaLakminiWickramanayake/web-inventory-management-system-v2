<?php
ob_start();
require_once 'config.php';
checkAccess(['admin', 'manager']);

$page_title = "Products Management";
include 'header.php';

// Handle Add Product
if (isset($_POST['add_product'])) {
    $name = sanitize($conn, $_POST['name']);
    $category_input = $_POST['category_id'] ?? '';
    $new_category_name = trim(sanitize($conn, $_POST['new_category_name'] ?? ''));
    $category_id = 0;
    $category_error = null;

    if ($category_input === 'new') {
        if (empty($new_category_name)) {
            $category_error = "Please enter a new category name.";
        } else {
            $check_sql = "SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt === false) {
                $category_error = "SQL Error: " . $conn->error;
            } else {
                $check_stmt->bind_param("s", $new_category_name);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $existing_category = $check_result->fetch_assoc();
                    $category_id = intval($existing_category['id']);
                } else {
                    $insert_sql = "INSERT INTO categories (name) VALUES (?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    if ($insert_stmt === false) {
                        $category_error = "SQL Error: " . $conn->error;
                    } else {
                        $insert_stmt->bind_param("s", $new_category_name);
                        if ($insert_stmt->execute()) {
                            $category_id = $insert_stmt->insert_id;
                        } else {
                            $category_error = "Error creating category: " . $insert_stmt->error;
                        }
                    }
                }
            }
        }
    } else {
        $category_id = intval($category_input);
    }

    $cost_price = floatval($_POST['cost_price']);
    $selling_price = floatval($_POST['selling_price']);
    $unit = sanitize($conn, $_POST['unit']);
    $reorder_level = floatval($_POST['reorder_level']);
    $sku = trim(sanitize($conn, $_POST['sku']));
    if ($sku === '') {
        $sku = 'PRD-' . time() . '-' . rand(1000, 9999);
    }
    
    // Image Upload Handle
    $image_name = NULL;
    if(isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0){
        $target_dir = "uploads/products/";
        if(!is_dir($target_dir)){ mkdir($target_dir, 0777, true); }
        
        $file_ext = pathinfo($_FILES["product_image"]["name"], PATHINFO_EXTENSION);
        $image_name = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $file_ext;
        $target_file = $target_dir . $image_name;
        
        // Allow only images
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if(!in_array(strtolower($file_ext), $allowed)){
            $_SESSION['error'] = "Only JPG, JPEG, PNG, GIF, WEBP files allowed.";
            header("Location: products.php");
            exit();
        }
        
        move_uploaded_file($_FILES["product_image"]["tmp_name"], $target_file);
    }
    
    if ($category_error) {
        $_SESSION['error'] = $category_error;
    } elseif (empty($name) || $category_id <= 0 || $selling_price < 0) {
        $_SESSION['error'] = "Please fill all required fields with valid data.";
    } else {
        $sql = "INSERT INTO products (name, category_id, sku, unit, cost_price, selling_price, reorder_level, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            $_SESSION['error'] = "SQL Error: " . $conn->error;
        } else {
            $stmt->bind_param("sisssdds", $name, $category_id, $sku, $unit, $cost_price, $selling_price, $reorder_level, $image_name);
            
            if ($stmt->execute()) {
                $product_id = $stmt->insert_id;
                
                // Add stock entries for all branches
                $branches_sql = "SELECT id FROM branches";
                $branches_result = $conn->query($branches_sql);
                
                while ($branch = $branches_result->fetch_assoc()) {
                    $stock_sql = "INSERT INTO stock (product_id, branch_id, total_quantity, reorder_level) VALUES (?, ?, 0, ?)";
                    $stock_stmt = $conn->prepare($stock_sql);
                    if ($stock_stmt !== false) {
                        $stock_stmt->bind_param("iid", $product_id, $branch['id'], $reorder_level);
                        $stock_stmt->execute();
                    }
                }
                
                $_SESSION['success'] = "Product added successfully!";
            } else {
                $_SESSION['error'] = "Error adding product: " . $stmt->error;
            }
        }
    }
    header("Location: products.php");
    exit();
}

// Handle Edit Product
if (isset($_POST['edit_product'])) {
    $id = intval($_POST['id']);
    $name = sanitize($conn, $_POST['name']);
    $category_id = intval($_POST['category_id']);
    $cost_price = floatval($_POST['cost_price']);
    $selling_price = floatval($_POST['selling_price']);
    $unit = sanitize($conn, $_POST['unit']);
    $reorder_level = floatval($_POST['reorder_level']);
    $sku = trim(sanitize($conn, $_POST['sku']));
    if ($sku === '') {
        $sku = 'PRD-' . time() . '-' . rand(1000, 9999);
    }
    $old_image = sanitize($conn, $_POST['old_image']);
    
    // Image Upload Handle for Edit
    $image_name = $old_image;
    if(isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0){
        $target_dir = "uploads/products/";
        if(!is_dir($target_dir)){ mkdir($target_dir, 0777, true); }
        
        $file_ext = pathinfo($_FILES["product_image"]["name"], PATHINFO_EXTENSION);
        $image_name = 'prod_' . time() . '_' . rand(1000,9999) . '.' . $file_ext;
        $target_file = $target_dir . $image_name;
        
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if(in_array(strtolower($file_ext), $allowed)){
            move_uploaded_file($_FILES["product_image"]["tmp_name"], $target_file);
            // Delete old image
            if($old_image && file_exists($target_dir . $old_image)){
                unlink($target_dir . $old_image);
            }
        } else {
            $image_name = $old_image;
        }
    }
    
    if (empty($name) || $category_id <= 0 || $selling_price < 0) {
        $_SESSION['error'] = "Please fill all required fields with valid data.";
    } else {
        $sql = "UPDATE products SET name = ?, category_id = ?, sku = ?, unit = ?, cost_price = ?, selling_price = ?, reorder_level = ?, image = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            $_SESSION['error'] = "SQL Error: " . $conn->error;
        } else {
            $stmt->bind_param("sisssddsi", $name, $category_id, $sku, $unit, $cost_price, $selling_price, $reorder_level, $image_name, $id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Product updated successfully!";
            } else {
                $_SESSION['error'] = "Error updating product: " . $stmt->error;
            }
        }
    }
    header("Location: products.php");
    exit();
}

// Handle Delete Product
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Get image name to delete file
    $img_sql = "SELECT image FROM products WHERE id = ?";
    $img_stmt = $conn->prepare($img_sql);
    $img_stmt->bind_param("i", $id);
    $img_stmt->execute();
    $img_result = $img_stmt->get_result();
    $img_row = $img_result->fetch_assoc();
    
    $sql = "DELETE FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        $_SESSION['error'] = "SQL Error: " . $conn->error;
    } else {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            // Delete image file
            if($img_row['image'] && file_exists("uploads/products/" . $img_row['image'])){
                unlink("uploads/products/" . $img_row['image']);
            }
            $_SESSION['success'] = "Product deleted successfully!";
        } else {
            $_SESSION['error'] = "Error deleting product: " . $stmt->error;
        }
    }
    header("Location: products.php");
    exit();
}

// Get all products with categories - ID පිලිවෙලට
$products_sql = "SELECT p.id, p.name, p.sku, p.unit, p.cost_price, p.selling_price, 
                        p.reorder_level, p.image, p.created_at, p.category_id, c.name as category_name
                 FROM products p
                 JOIN categories c ON p.category_id = c.id
                 ORDER BY p.id ASC";
$products_result = $conn->query($products_sql);
$products_array = [];
if ($products_result) {
    while ($product = $products_result->fetch_assoc()) {
        $products_array[] = $product;
    }
} else {
    $_SESSION['error'] = "Error loading products: " . $conn->error;
}

// Get categories for dropdown
$categories = $conn->query("SELECT * FROM categories ORDER BY name");
$categories_array = [];
if ($categories) {
    while ($cat = $categories->fetch_assoc()) {
        $categories_array[] = $cat;
    }
}
?>

<div class="container">
    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
</div>

<?php
ob_end_flush();
?>

<div class="row fade-in">
    <div class="col-12">
        <div class="table-container">
            <div class="table-header">
                <h5><i class="fas fa-boxes me-2"></i>Products</h5>
                <?php if (getUserRole() == 'admin'): ?>
                <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-1"></i>Add Product
                </button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Cost Price</th>
                            <th>Selling Price</th>
                            <th>Unit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products_array as $product): ?>
                        <?php
                            $productImage = !empty($product['image']) ? $product['image'] : 'no-image.png';
                            $productImagePath = __DIR__ . '/uploads/products/' . $productImage;
                            $productImageSrc = file_exists($productImagePath)
                                ? 'uploads/products/' . rawurlencode($productImage)
                                : 'uploads/products/no-image.png';
                        ?>
                        <tr>
                            <td>
                                <img src="<?php echo htmlspecialchars($productImageSrc); ?>" 
                                     width="50" height="50" 
                                     style="object-fit: cover; border-radius: 6px; border: 1px solid #ddd;"
                                     onerror="this.onerror=null;this.src='uploads/products/no-image.png'">
                            </td>
                            <td><?php echo $product['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($product['name']); ?></strong><br><small class="text-muted"><?php echo $product['sku']; ?></small></td>
                            <td><span class="badge bg-info"><?php echo $product['category_name']; ?></span></td>
                            <td><?php echo formatCurrency($product['cost_price']); ?></td>
                            <td><?php echo formatCurrency($product['selling_price']); ?></td>
                            <td><?php echo $product['unit']; ?></td>
                            <td>
                                <?php if (getUserRole() == 'admin'): ?>
                                <button class="btn btn-action btn-edit" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $product['id']; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-action btn-delete" onclick="confirmDelete('products.php?delete=<?php echo $product['id']; ?>', '<?php echo htmlspecialchars($product['name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted">View Only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (getUserRole() == 'admin'): ?>
<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" class="form-control" name="name" required placeholder="Enter product name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SKU</label>
                            <input type="text" class="form-control" name="sku" placeholder="Enter SKU">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select class="form-select" name="category_id" id="addCategorySelect" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories_array as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                                <option value="new">Create Category</option>
                            </select>
                            <div id="newCategoryField" class="mt-2" style="display:none;">
                                <label class="form-label">New Category Name</label>
                                <input type="text" class="form-control" name="new_category_name" placeholder="Enter new category name">
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit">
                                <option value="kg">Kg</option>
                                <option value="pcs">Piece</option>
                                <option value="pack">Pack</option>
                                <option value="tray">Tray</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost Price (LKR) *</label>
                            <input type="number" class="form-control" name="cost_price" step="0.01" min="0" required placeholder="Enter cost price">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Selling Price (LKR) *</label>
                            <input type="number" class="form-control" name="selling_price" step="0.01" min="0" required placeholder="Enter selling price">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" class="form-control" name="reorder_level" step="0.01" min="0" value="10.00" placeholder="Enter reorder level">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" class="form-control" name="product_image" accept="image/*">
                            <small class="text-muted">JPG, PNG, GIF, WEBP only</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_product" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modals -->
<?php foreach ($products_array as $product): ?>
<div class="modal fade" id="editProductModal<?php echo $product['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                <input type="hidden" name="old_image" value="<?php echo isset($product['image']) ? htmlspecialchars($product['image']) : ''; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Name *</label>
                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">SKU</label>
                            <input type="text" class="form-control" name="sku" value="<?php echo htmlspecialchars($product['sku']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Category *</label>
                            <select class="form-select" name="category_id" required>
                                <?php foreach ($categories_array as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo $product['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo $cat['name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Unit</label>
                            <select class="form-select" name="unit">
                                <option value="kg" <?php echo $product['unit'] == 'kg' ? 'selected' : ''; ?>>Kg</option>
                                <option value="pcs" <?php echo $product['unit'] == 'pcs' ? 'selected' : ''; ?>>Piece</option>
                                <option value="pack" <?php echo $product['unit'] == 'pack' ? 'selected' : ''; ?>>Pack</option>
                                <option value="tray" <?php echo $product['unit'] == 'tray' ? 'selected' : ''; ?>>Tray</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Cost Price (LKR) *</label>
                            <input type="number" class="form-control" name="cost_price" step="0.01" min="0" value="<?php echo $product['cost_price']; ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Selling Price (LKR) *</label>
                            <input type="number" class="form-control" name="selling_price" step="0.01" min="0" value="<?php echo $product['selling_price']; ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" class="form-control" name="reorder_level" step="0.01" min="0" value="<?php echo $product['reorder_level']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Product Image</label>
                            <input type="file" class="form-control" name="product_image" accept="image/*">
                            <?php if (!empty($product['image'])):
                                $editImagePath = __DIR__ . '/uploads/products/' . $product['image'];
                                $editImageSrc = file_exists($editImagePath)
                                    ? 'uploads/products/' . rawurlencode($product['image'])
                                    : 'uploads/products/no-image.png';
                            ?>
                            <small class="text-muted">Current: <?php echo htmlspecialchars($product['image']); ?></small><br>
                            <img src="<?php echo htmlspecialchars($editImageSrc); ?>" width="60" class="mt-1" style="border-radius: 4px;">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_product" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const categorySelect = document.getElementById('addCategorySelect');
    const newCategoryField = document.getElementById('newCategoryField');

    if (categorySelect && newCategoryField) {
        const toggleNewCategoryField = () => {
            const show = categorySelect.value === 'new';
            newCategoryField.style.display = show ? 'block' : 'none';
            const input = newCategoryField.querySelector('input');
            if (input) {
                input.required = show;
            }
        };

        categorySelect.addEventListener('change', toggleNewCategoryField);
        toggleNewCategoryField();
    }
});
</script>

<?php include 'footer.php'; ?>