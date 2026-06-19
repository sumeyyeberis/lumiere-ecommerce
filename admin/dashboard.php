<?php
/**
 * admin/dashboard.php — The CRUD Panel
 * -----------------------------------------------------------------------
 * WHAT THIS PAGE DOES:
 * 1. SECURITY: Checks if the logged-in user is an 'admin'.
 * 2. CREATE: Form to add new products to the database.
 * 3. READ: Displays all products and all customer orders.
 * 4. UPDATE: Allows inline updating of product stock/price and order status.
 * 5. DELETE: Secure POST-based deletion of products.
 * -----------------------------------------------------------------------
 */

session_start();
// Since this is in the /admin folder, we need to go up one level (../)
require_once '../db_connect.php';

// --- SECURITY GATE: Admin Only ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash_message'] = "Access Denied. Admin privileges required.";
    $_SESSION['flash_type'] = "error";
    header("Location: ../index.php");
    exit;
}

// -----------------------------------------------------------------------
// HANDLE CRUD ACTIONS (POST REQUESTS)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- CREATE: Add New Product ---
    if ($action === 'add_product') {
        $name = trim($_POST['name']);
        $cat  = trim($_POST['category']);
        $desc = trim($_POST['description']);
        $price = (float) $_POST['price'];
        $qty  = (int) $_POST['stock_quantity'];
        $img  = trim($_POST['image_url']);

        $stmt = $conn->prepare("INSERT INTO Products (name, category, description, price, stock_quantity, image_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdss", $name, $cat, $desc, $price, $qty, $img);
        
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = "Product added successfully!";
        } else {
            $_SESSION['flash_message'] = "Error adding product.";
            $_SESSION['flash_type'] = "error";
        }
        $stmt->close();
        header("Location: dashboard.php");
        exit;
    }

    // --- UPDATE: Edit Product Price/Stock ---
    if ($action === 'edit_product') {
        $pid   = (int) $_POST['product_id'];
        $price = (float) $_POST['price'];
        $qty   = (int) $_POST['stock_quantity'];

        $stmt = $conn->prepare("UPDATE Products SET price = ?, stock_quantity = ? WHERE product_id = ?");
        $stmt->bind_param("dii", $price, $qty, $pid);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['flash_message'] = "Product updated!";
        header("Location: dashboard.php");
        exit;
    }

    // --- DELETE: Remove Product ---
    if ($action === 'delete_product') {
        $pid = (int) $_POST['product_id'];
        
        $stmt = $conn->prepare("DELETE FROM Products WHERE product_id = ?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash_message'] = "Product deleted securely.";
        header("Location: dashboard.php");
        exit;
    }

    // --- UPDATE: Change Order Status ---
    if ($action === 'update_order') {
        $oid    = (int) $_POST['order_id'];
        $status = $_POST['status'];

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt->bind_param("si", $status, $oid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash_message'] = "Order #$oid status changed to $status.";
        header("Location: dashboard.php");
        exit;
    }
}

// -----------------------------------------------------------------------
// FETCH DATA FOR DISPLAY
// -----------------------------------------------------------------------
// Fetch all products
$products_res = $conn->query("SELECT * FROM Products ORDER BY created_at DESC");

// Fetch all orders with user details (JOIN)
$orders_res = $conn->query("
    SELECT o.order_id, o.total_amount, o.status, o.created_at, 
           u.first_name, u.last_name, u.email 
    FROM orders o 
    JOIN Users u ON o.user_id = u.user_id 
    ORDER BY o.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Lumière Beauty</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream: #faf7f2; --warm-white: #f5f0e8; --blush: #e8c4b0;
            --terracotta: #c07a5a; --deep-brown: #3d2314; --charcoal: #2a2a2a;
            --text-muted: #8a7a72; --border: #e8ddd5; --success: #5a8a6a;
            --danger: #c0392b; --font-display: 'Cormorant Garamond', serif;
            --font-body: 'DM Sans', sans-serif; --radius: 12px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--cream); color: var(--charcoal); font-family: var(--font-body); font-weight: 300; }
        
        /* Nav */
        nav { background: var(--deep-brown); color: white; padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; }
        nav a { color: white; text-decoration: none; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .nav-logo { font-family: var(--font-display); font-size: 1.5rem; }
        
        .container { max-width: 1200px; margin: 40px auto; padding: 0 5%; }
        h1 { font-family: var(--font-display); font-size: 2.5rem; color: var(--deep-brown); margin-bottom: 30px; }
        h2 { font-family: var(--font-display); font-size: 1.8rem; border-bottom: 2px solid var(--border); padding-bottom: 10px; margin-bottom: 20px; }
        
        /* Flash Message */
        .flash { padding: 15px; border-radius: var(--radius); margin-bottom: 20px; font-weight: 500; }
        .flash.success { background: #e8f5ec; color: var(--success); }
        .flash.error { background: #fdecea; color: var(--danger); }

        /* Dashboard Sections */
        .section-card { background: white; padding: 30px; border-radius: var(--radius); box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 40px; }
        
        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: var(--font-body); }
        button { background: var(--deep-brown); color: white; border: none; padding: 10px 20px; border-radius: 50px; cursor: pointer; transition: 0.3s; }
        button:hover { background: var(--terracotta); }
        .btn-danger { background: var(--danger); }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        th { color: var(--text-muted); font-weight: 500; text-transform: uppercase; font-size: 0.8rem; }
        .inline-form { display: flex; gap: 5px; align-items: center; }
        .inline-input { width: 70px; padding: 5px; }
    </style>
</head>
<body>

<nav>
    <div class="nav-logo">Lumière Admin</div>
    <div>
        <a href="../index.php" style="margin-right: 20px;">Back to Store</a>
        <a href="../logout.php">Logout</a>
    </div>
</nav>

<div class="container">
    <h1>Dashboard</h1>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="flash <?= $_SESSION['flash_type'] ?? 'success' ?>">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="section-card">
        <h2>Add New Product</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_product">
            <div class="form-grid">
                <input type="text" name="name" placeholder="Product Name" required>
                <select name="category" required>
                    <option value="">Select Category...</option>
                    <option value="Skincare">Skincare</option>
                    <option value="Haircare">Haircare</option>
                    <option value="Makeup">Makeup</option>
                </select>
                <input type="number" step="0.01" name="price" placeholder="Price (₺)" required>
                <input type="number" name="stock_quantity" placeholder="Initial Stock Quantity" required>
            </div>
            <textarea name="description" placeholder="Product Description..." rows="3" required style="margin-bottom:15px;"></textarea>
            <input type="text" name="image_url" placeholder="Image URL (Optional)" style="margin-bottom:15px;">
            <button type="submit">Create Product</button>
        </form>
    </div>

    <div class="section-card">
        <h2>Inventory Management</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Price / Stock</th>
                <th>Actions</th>
            </tr>
            <?php while($prod = $products_res->fetch_assoc()): ?>
            <tr>
                <td>#<?= $prod['product_id'] ?></td>
                <td><strong><?= htmlspecialchars($prod['name']) ?></strong><br><small><?= $prod['category'] ?></small></td>
                
                <td>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="edit_product">
                        <input type="hidden" name="product_id" value="<?= $prod['product_id'] ?>">
                        ₺<input type="number" step="0.01" name="price" class="inline-input" value="<?= $prod['price'] ?>">
                        Qty: <input type="number" name="stock_quantity" class="inline-input" value="<?= $prod['stock_quantity'] ?>">
                        <button type="submit" style="padding: 5px 10px; font-size: 0.8rem;">Save</button>
                    </form>
                </td>

                <td>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this product?');">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="product_id" value="<?= $prod['product_id'] ?>">
                        <button type="submit" class="btn-danger" style="padding: 5px 10px; font-size: 0.8rem;">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

    <div class="section-card">
        <h2>Customer Orders</h2>
        <table>
            <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Date</th>
                <th>Status</th>
            </tr>
            <?php while($order = $orders_res->fetch_assoc()): ?>
            <tr>
                <td>#<?= str_pad($order['order_id'], 5, '0', STR_PAD_LEFT) ?></td>
                <td><?= htmlspecialchars($order['first_name'] . ' ' . $order['last_name']) ?><br><small><?= htmlspecialchars($order['email']) ?></small></td>
                <td>₺<?= number_format($order['total_amount'], 2) ?></td>
                <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                
                <td>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="update_order">
                        <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                        <select name="status" style="width:100px; padding:5px;">
                            <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                            <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        </select>
                        <button type="submit" style="padding: 5px 10px; font-size: 0.8rem;">Update</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

</div>

</body>
</html>