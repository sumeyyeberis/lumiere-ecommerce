<?php
session_start();
require_once 'db_connect.php';

// --- HOMEPAGE AUTH LOGIC (LOGIN & REGISTER) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. Handle Login
    if ($_POST['action'] === 'login') {
        $email = trim($_POST['login_email'] ?? '');
        $password = $_POST['login_password'] ?? '';
        
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, password_hash, role FROM Users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Success! Set session and refresh
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit;
        } else {
            // Fail: Send error back to the modal
            $_SESSION['modal_errors'] = ["Invalid email or password."];
            $_SESSION['open_modal'] = 'login';
            header("Location: index.php");
            exit;
        }
    }
    
    // 2. Handle Registration
    if ($_POST['action'] === 'register') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['reg_email'] ?? '');
        $password   = $_POST['reg_password'] ?? '';
        $errors     = [];
        
        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
        
        $check = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) $errors[] = "An account with this email already exists.";
        $check->close();
        
        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare("INSERT INTO Users (first_name, last_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'customer')");
            $insert->bind_param("ssss", $first_name, $last_name, $email, $hash);
            
            if ($insert->execute()) {
                // Success! Log them in instantly
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $_SESSION['role'] = 'customer';
                header("Location: index.php");
                exit;
            } else {
                $errors[] = "Registration failed. Please try again.";
            }
        }
        
        // Fail: Send errors back to the modal
        if (!empty($errors)) {
            $_SESSION['modal_errors'] = $errors;
            $_SESSION['open_modal'] = 'register';
            header("Location: index.php");
            exit;
        }
    }
}

// 3. Fetch categories for the filter buttons
$cat_result = $conn->query("SELECT DISTINCT category FROM Products ORDER BY category ASC");
$categories = [];
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

$cart_count = isset($_SESSION['cart']) ? array_sum(array_column($_SESSION['cart'], 'quantity')) : 0;

// Helper function to draw stars
function renderMiniStars($rating) {
    $html = '<div style="color: #d4854a; font-size: 0.9rem; letter-spacing: 1px;">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= ($i <= round($rating)) ? '★' : '<span style="color:#e8ddd5;">★</span>';
    }
    $html .= '</div>';
    return $html;
}

// 1. Get filter parameters
$filter_category = isset($_GET['category']) ? htmlspecialchars(trim($_GET['category'])) : '';
$search_query    = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search'])) : '';
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (float)$_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (float)$_GET['max_price']) : 999999;;

// 2. Updated SQL Logic
$sql = "SELECT p.product_id, p.name, p.category, p.description, p.price, p.stock_quantity, p.image_url,
               AVG(r.rating) as avg_rating, COUNT(r.review_id) as review_count
        FROM Products p
        LEFT JOIN Reviews r ON p.product_id = r.product_id
        WHERE 1=1 ";

$params = [];
$types  = "";

if ($filter_category !== '') {
    $sql .= " AND p.category = ? ";
    $params[] = $filter_category;
    $types .= "s";
}

if ($search_query !== '') {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ?) ";
    $searchTerm = "%" . $search_query . "%"; 
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

// Add Price Filter Logic
$sql .= " AND p.price >= ? AND p.price <= ? ";
$params[] = $min_price;
$params[] = $max_price;
$types .= "dd";

// If the toggle is "on", filter by stock
if (isset($_GET['in_stock']) && $_GET['in_stock'] == '1') {
    $sql .= " AND p.stock_quantity > 0 ";
}

// 1. First, stop the GROUP BY from having an ORDER BY attached to it
$sql .= " GROUP BY p.product_id "; 

// 2. Now add your sorting logic
$sort = $_GET['sort'] ?? 'newest';
if ($sort == 'price_asc') $sql .= " ORDER BY p.price ASC ";
elseif ($sort == 'price_desc') $sql .= " ORDER BY p.price DESC ";
else $sql .= " ORDER BY p.created_at DESC ";

// 3. Now prepare the statement
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lumière Beauty — Personal Care Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>

        :root {
            --cream: #faf7f2; --warm-white: #f5f0e8; --blush: #e8c4b0;
            --terracotta: #c07a5a; --deep-brown: #3d2314; --charcoal: #2a2a2a;
            --text-muted: #8a7a72; --border: #e8ddd5; --success: #5a8a6a;
            --font-display: 'Cormorant Garamond', serif; --font-body: 'DM Sans', sans-serif;
            --shadow-soft: 0 4px 24px rgba(61,35,20,0.08); --radius: 12px; --transition: 0.3s;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: var(--cream); color: var(--charcoal); font-family: var(--font-body); font-weight: 300; line-height: 1.7; }

        /* Nav & Hero (unchanged) */
        nav { background: rgba(250,247,242,0.92); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; padding: 0 5%; }
        .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; height: 70px; }
        .nav-logo { font-family: var(--font-display); font-size: 1.7rem; font-weight: 600; color: var(--deep-brown); text-decoration: none; }
        .nav-logo span { color: var(--terracotta); font-style: italic; }
        .nav-links { display: flex; align-items: center; gap: 2rem; }
        .nav-links a { font-size: 0.85rem; font-weight: 400; letter-spacing: 0.08em; text-transform: uppercase; color: var(--charcoal); text-decoration: none; transition: color var(--transition); }
        .nav-links a:hover { color: var(--terracotta); }
        .cart-btn { position: relative; background: var(--deep-brown); color: #fff !important; padding: 0.5rem 1.2rem; border-radius: 50px; }
        .cart-badge { position: absolute; top: -6px; right: -6px; background: var(--terracotta); color: #fff; font-size: .65rem; font-weight: 500; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .cart-wrapper { position: relative; display: flex; align-items: center; }

         /* Navbar Search Bar */
        .nav-search-form { display: flex; align-items: center; margin-right: 15px; }
        .nav-search-input { padding: 6px 14px; border: 1px solid var(--border); border-radius: 50px 0 0 50px; outline: none; font-family: var(--font-body); width: 200px; font-size: 0.85rem; background: rgba(255,255,255,0.5); transition: 0.3s; }
        .nav-search-input:focus { border-color: var(--terracotta); background: #fff; width: 240px; }
        .nav-search-btn { padding: 6px 16px; background: var(--deep-brown); color: white; border: none; border-radius: 0 50px 50px 0; cursor: pointer; font-family: var(--font-body); font-size: 0.85rem; transition: 0.3s; }
        .nav-search-btn:hover { background: var(--terracotta); }

        .toast { position: absolute; top: 100%; right: 0; margin-top: 10px; background: #3d2314; color: #fff; padding: 8px 16px; border-radius: 50px; font-size: 0.75rem; opacity: 0; transition: opacity 0.3s ease; z-index: 999; pointer-events: none; white-space: nowrap; display: none; } 
        .toast.show { opacity: 1; display: block; }
                
        .hero { background: linear-gradient(135deg, var(--warm-white) 0%, var(--blush) 60%, #d4956f 100%); padding: 80px 5% 60px; text-align: center; }
        .hero h1 { font-family: var(--font-display); font-size: 3.5rem; color: var(--deep-brown); margin-bottom: 1rem; line-height: 1.1; }

        .main-container { max-width: 1280px; margin: 0 auto; padding: 50px 5%; }

        /* ---- NEW: FILTER & SEARCH BAR LAYOUT ---- */
        .controls-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; padding-bottom: 24px; border-bottom: 1px solid var(--border);}
        .filter-row { display: flex; align-items: center; gap: 25px; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .filter-btn { padding: 0.45rem 1.1rem; border: 1px solid var(--border); border-radius: 50px; font-size: 0.82rem; color: var(--charcoal); text-decoration: none; background: #fff; transition: 0.3s; }
        .filter-btn:hover, .filter-btn.active { background: var(--deep-brown); color: #fff; border-color: var(--deep-brown); }
        
        .switch-group { display: flex; align-items: center; gap: 10px; font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; }
        .switch { position: relative; display: inline-block; width: 42px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 22px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--deep-brown); }
        input:checked + .slider:before { transform: translateX(20px); }

        .sort-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 50px; width: 180px; font-family: var(--font-body); font-size: 0.8rem; }
        .price-form { display: flex; align-items: center; gap: 10px; }
        .price-input { padding: 8px 14px; border: 1px solid var(--border); border-radius: 50px; width: 120px; font-family: var(--font-body); font-size: 0,8rem; }
        .search-form { display: flex; align-items: center; }
        .search-input { padding: 10px 15px; border: 1px solid var(--border); border-radius: 50px 0 0 50px; outline: none; font-family: var(--font-body); width: 250px; }
        .search-btn { padding: 10px 20px; background: var(--deep-brown); color: white; border: none; border-radius: 0 50px 50px 0; cursor: pointer; font-family: var(--font-body); }
        .search-btn:hover { background: var(--terracotta); }

        /* Grid & Cards */
        .section-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 28px; }
        .section-header h2 { font-family: var(--font-display); font-size: 1.8rem; color: var(--deep-brown); }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 30px; align-items: start;}
        
        .product-card { position: relative; background: #fff; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-soft); transition: transform 0.3s; display: flex; flex-direction: column; }
        .product-card:hover { transform: translateY(-6px); }
        .card-img-wrap { aspect-ratio: 1 / 1; background: var(--warm-white); position: relative; display: flex; align-items: center; justify-content: center; font-size: 3rem; }
        .category-tag { position: absolute; top: 12px; left: 12px; background: rgba(250,247,242,0.9); font-size: 0.68rem; text-transform: uppercase; color: var(--terracotta); padding: 4px 10px; border-radius: 50px; font-weight: 500; }
        
        .card-body { padding: 18px 20px 20px; display: flex; flex-direction: column; flex: 1; }
        .card-name { font-family: var(--font-display); font-size: 1.15rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 4px; }
        
        /* NEW: Mini Review display on card */
        .card-reviews { display: flex; align-items: center; gap: 6px; margin-bottom: 12px; font-size: 0.75rem; color: var(--text-muted); }
        .card-link-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1; }
        .card-desc { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 16px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1; }
        .card-footer { display: flex; justify-content: space-between; align-items: center; }
        .card-price { font-family: var(--font-display); font-size: 1.4rem; font-weight: 600; color: var(--deep-brown); }
        .btn-view, .btn-add-cart { padding: 0.5rem 1rem; border-radius: 50px; font-size: 0.78rem; font-weight: 500; text-transform: uppercase; cursor: pointer; text-decoration: none; border: none; }
        .btn-view { border: 1px solid var(--border); color: var(--charcoal); background: transparent; }
        .btn-add-cart { background: var(--deep-brown); color: #fff; }
        
        /* Sidebar Button */
        .filter-toggle-btn { background: #fff; border: 1px solid var(--border); padding: 8px 16px; border-radius: 50px; cursor: pointer; font-family: var(--font-body); display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .filter-toggle-btn:hover { border-color: var(--deep-brown); background: var(--warm-white); }

        /* Sidebar Panel */
        .sidebar-menu { position: fixed; top: 0; left: -300px; width: 300px; height: 100%; background: #fff; z-index: 2000; box-shadow: 2px 0 10px rgba(0,0,0,0.1); transition: 0.4s ease; padding: 30px; overflow-y: auto; }
        .sidebar-menu.open { left: 0; }
        .sidebar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .close-sidebar { border: none; background: none; font-size: 2rem; cursor: pointer; color: var(--deep-brown); }

        /* Dimmed overlay */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1500; backdrop-filter: blur(2px); }
        .sidebar-overlay.open { display: block; }

        .nav-left { display: flex; align-items: center; gap: 20px; }
        .filter-toggle-btn { background: none; border: 1px solid var(--border); padding: 6px 14px; border-radius: 50px; cursor: pointer; font-family: var(--font-body); font-size: 0.8rem; display: flex; align-items: center; gap: 6px; transition: 0.3s; }
        .filter-toggle-btn:hover { background: var(--warm-white); border-color: var(--deep-brown); }

        /* Footer */
        footer { background: #fff; border-top: 1px solid var(--border); padding: 60px 5% 30px; margin-top: 80px; }
        .footer-inner { max-width: 1280px; margin: 0 auto 40px; display: grid; grid-template-columns: 2fr 1fr 1.5fr; gap: 50px; }
        .footer-col h3 { font-family: var(--font-display); font-size: 1.3rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 18px; }
        .footer-col p { font-size: 0.88rem; color: var(--text-muted); line-height: 1.8; }
        .footer-col ul { list-style: none; }
        .footer-col ul li { margin-bottom: 10px; }
        .footer-col ul li a { font-size: 0.85rem; color: var(--text-muted); text-decoration: none; text-transform: uppercase; letter-spacing: 0.05em; transition: color var(--transition); }
        .footer-col ul li a:hover { color: var(--terracotta); }
        .contact-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; font-size: 0.88rem; color: var(--text-muted); }
        .contact-icon { color: var(--terracotta); font-weight: bold; }
        .footer-bottom { max-width: 1280px; margin: 0 auto; border-top: 1px solid var(--border); padding-top: 24px; text-align: center; font-size: 0.8rem; color: var(--text-muted); }

        .contact-item p a { color: var(--text-muted); text-decoration: none; transition: color var(--transition); }
        .contact-item p a:hover { color: var(--terracotta); }
        
    </style>
</head>
<body>

<nav>
    <div class="nav-inner">
        <div class="nav-left">
            <button class="filter-toggle-btn" onclick="toggleSidebar()">
                <span>☰</span>
            </button>
            <a href="index.php" class="nav-logo">Lumi<span>ère</span></a>
        </div>
        
        <div class="nav-links">
            <form method="GET" action="index.php" class="nav-search-form">
            <input type="text" name="search" class="nav-search-input" placeholder="Search In Shop..." value="<?= htmlspecialchars($search_query) ?>">
            <button type="submit" class="nav-search-btn">Search</button>
            </form>

            <a href="index.php">Shop</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="account.php">Hello, <?= htmlspecialchars($_SESSION['first_name']) ?></a>
                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                    <a href="admin/dashboard.php">Admin</a>
                <?php endif; ?>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="#" id="open-login-link">Login</a>
            <?php endif; ?>
            
            <div class="cart-wrapper">
                <a href="cart.php" class="cart-btn">
                    🛒 Cart
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-badge"><?= $cart_count ?></span>
                    <?php endif; ?>
                </a>

                <?php if (isset($_SESSION['toast_msg'])): ?>
                    <div id="toast" class="toast show">Added to cart!</div>
                    <script>
                        setTimeout(() => {
                            const t = document.getElementById('toast');
                            if (t) t.classList.remove('show');
                        }, 3000);
                    </script>
                    <?php unset($_SESSION['toast_msg']); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<section class="hero">
    <h1>Beauty <em>Elevated</em><br>by Nature</h1>
    <p style="color: var(--text-muted);">Discover our collection of skincare, haircare, and makeup.</p>
</section>

<main class="main-container">
    <div class="controls-bar">
        <div class="filter-group">
            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Category:</span>
            <a href="index.php" class="filter-btn <?= $filter_category === '' ? 'active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="index.php?category=<?= urlencode($cat) ?>" class="filter-btn <?= $filter_category === $cat ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat) ?>
                </a>
            <?php endforeach; ?>
        </div>

<form method="GET" action="index.php" style="display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
    <?php if ($filter_category !== ''): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($filter_category) ?>">
    <?php endif; ?>
    
    <?php if ($search_query !== ''): ?>
        <input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>">
    <?php endif; ?>
    
    <input type="number" name="min_price" class="price-input" placeholder="Min ₺" value="<?= $min_price > 0 ? $min_price : '' ?>" min="0" oninput="if(this.value < 0) this.value = Math.abs(this.value);">
    <input type="number" name="max_price" class="price-input" placeholder="Max ₺" value="<?= $max_price < 999999 ? $max_price : '' ?>" min="0" oninput="if(this.value < 0) this.value = Math.abs(this.value);">

    <button type="submit" class="filter-btn" style="background: var(--deep-brown); color: white; border: none; cursor: pointer; padding: 8px 16px;">Apply</button>
    
    <label class="switch-group" style="margin: 0 10px;">
        <label class="switch">
            <input type="checkbox" name="in_stock" value="1" <?= isset($_GET['in_stock']) ? 'checked' : '' ?> onchange="this.form.submit()">
            <span class="slider"></span>
        </label>
        In Stock
    </label>

    <select name="sort" onchange="this.form.submit()" class="sort-select">
        <option value="newest" <?= ($_GET['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>Newest First</option>
        <option value="price_asc" <?= ($_GET['sort'] ?? '') === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
        <option value="price_desc" <?= ($_GET['sort'] ?? '') === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
    </select>
</form>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="product-grid">
        <?php while ($product = $result->fetch_assoc()): ?>
            <article class="product-card">
                <a href="product.php?id=<?= $product['product_id'] ?>" class="card-link-overlay"></a>
                <div class="card-img-wrap">
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <?= ['Skincare'=>'🧴','Haircare'=>'💆','Makeup'=>'💄'][$product['category']] ?? '✨' ?>
                    <?php endif; ?>
                    <span class="category-tag" style="z-index: 2;"><?= htmlspecialchars($product['category']) ?></span>
                </div>
                
                <div class="card-body">
                    <h3 class="card-name"><?= htmlspecialchars($product['name']) ?></h3>
                    
                    <div class="card-reviews">
                        <?= renderMiniStars((float)$product['avg_rating']) ?>
                        <span>(<?= $product['review_count'] ?>)</span>
                    </div>

                    <p class="card-desc"><?= htmlspecialchars($product['description']) ?></p>
                    
                    <div class="card-footer" style="position: relative; z-index: 2;">
                        <div class="card-price"><span style="font-size:0.9rem;">₺</span><?= number_format($product['price'], 2) ?></div>
                        
                        <form method="POST" action="cart.php" style="margin:0;">
                            <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                            <input type="hidden" name="action" value="add">
                            <button type="submit" class="btn-add-cart" <?= $product['stock_quantity'] <= 0 ? 'disabled' : '' ?>>
                                <?= $product['stock_quantity'] <= 0 ? 'Sold Out' : 'Add to Cart' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 80px 20px; color: var(--text-muted);">
            <h3>No products found.</h3>
            <a href="index.php" style="color: var(--terracotta);">Clear search and try again</a>
        </div>
    <?php endif; ?>
</main>

<footer>
    <div class="footer-inner">
        <div class="footer-col">
            <h3 style="font-family: var(--font-display); font-size: 1.5rem;">Lumi<span>ère</span></h3>
            <p style="max-width: 380px; margin-top: 10px;">
                Carefully crafted personal care routines designed to elevate your natural essence. 
                Sourced by nature, backed by science, and curated with intention.
            </p>
        </div>

        <div class="footer-col">
            <h3>Explore</h3>
            <ul>
                <li><a href="index.php">Shop Catalog</a></li>
                <li><a href="cart.php">Shopping Cart</a></li>
                <li><a href="account.php">My Account</a></li>
            </ul>
        </div>

        <div class="footer-col">
            <h3>Contact Us</h3>
            
            <div class="contact-item">
                <span class="contact-icon">✉</span>
                <p><a href="mailto:support@lumierebeauty.com">support@lumierebeauty.com</a></p>
            </div>
            
            <div class="contact-item">
                <span class="contact-icon">📞</span>
                <p><a href="tel:+902124444342">+90 (212) 444 43 42</a></p>
            </div>
            
            <div class="contact-item">
                <span class="contact-icon">📍</span>
                <p>
                <a href="https://maps.google.com/?cid=17249335108429657811&g_mp=Cidnb29nbGUubWFwcy5wbGFjZXMudjEuUGxhY2VzLlNlYXJjaFRleHQ" target="_blank">
                    Göztepe, Kavacık Kavşağı, <br> 34810 Beykoz/İstanbul
                </a>
                </p>
            </div>
        </div>
    </div>

    <div class="footer-bottom">
        <p>© <?= date('Y') ?> Lumière Beauty &nbsp;·&nbsp; Istanbul Medipol University — Web Programming Final Project</p>
    </div>
</footer>

<?php
// Retrieve any errors if the form fails and redirects back here
$modal_errors = $_SESSION['modal_errors'] ?? [];
$open_modal   = $_SESSION['open_modal']   ?? '';
unset($_SESSION['modal_errors'], $_SESSION['open_modal']);
?>

<style>
    /* ONE perfectly sized modal style block */
    .modal-overlay { position: fixed; inset: 0; background: rgba(30, 15, 5, 0.6); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.35s ease; }
    .modal-overlay.is-open { opacity: 1; pointer-events: auto; }
    .modal-box { background: #fff; border-radius: 20px; width: 100%; max-width: 460px; min-width: 460px; flex-shrink: 0; padding: 36px 40px; box-sizing: border-box; box-shadow: 0 24px 80px rgba(0,0,0,0.25); transform: translateY(20px) scale(0.97); transition: transform 0.35s ease; max-height: 90vh; overflow-y: auto; position: relative; }
    .modal-overlay.is-open .modal-box { transform: translateY(0) scale(1); }
    .modal-close { position: absolute; top: 18px; right: 20px; background: none; border: none; cursor: pointer; font-size: 1.4rem; color: var(--text-muted); line-height: 1; padding: 4px 8px; border-radius: 6px; transition: background var(--transition); }
    .modal-close:hover { background: var(--warm-white); }
    .modal-eyebrow { font-size: 0.7rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--terracotta); margin-bottom: 6px; }
    .modal-title { font-family: var(--font-display); font-size: 1.9rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 4px; }
    .modal-subtitle { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px; }
    .modal-tabs { display: flex; gap: 4px; background: var(--warm-white); border-radius: 50px; padding: 4px; margin-bottom: 28px; }
    .tab-btn { flex: 1; padding: 8px; text-align: center; border: none; border-radius: 50px; background: transparent; font-family: var(--font-body); font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: all var(--transition); color: var(--text-muted); }
    .tab-btn.active { background: #fff; color: var(--deep-brown); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-group { margin-bottom: 16px; text-align: left;}
    .form-group label { display: block; font-size: 0.78rem; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
    .form-group input { width: 100%; box-sizing: border-box; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-family: var(--font-body); font-size: 0.9rem; color: var(--charcoal); background: #fff; transition: border-color var(--transition); outline: none; }
    .form-group input:focus { border-color: var(--terracotta); }
    .btn-submit-modal { width: 100%; padding: 13px; background: var(--deep-brown); color: #fff; border: none; border-radius: 50px; font-family: var(--font-body); font-size: 0.88rem; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; margin-top: 8px; transition: background var(--transition); }
    .btn-submit-modal:hover { background: var(--terracotta); }
    .modal-errors { background: #fdecea; border-left: 4px solid var(--danger); border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
    .modal-errors p { font-size: 0.83rem; color: #7b1c1c; margin-bottom: 2px; }
    .modal-errors p:last-child { margin: 0; }
    /*MOBILE RESPONSIVENESS*/
    @media (max-width: 850px) {
        html, body { overflow-x: hidden; max-width: 100vw; }
        .nav-inner { flex-wrap: wrap; height: auto; padding: 15px 0; gap: 15px; }
        .nav-links { flex-wrap: wrap; gap: 15px; justify-content: center; width: 100%; }
        .nav-search-form { order: 5; width: 100%; margin: 0; justify-content: center; margin-top: 5px; }
        .nav-search-input { width: 100%; max-width: 250px; }
        .nav-search-input:focus { width: 100%; max-width: 280px; }
        .hero h1 { font-size: 2.5rem; }
        .controls-bar { flex-direction: column; align-items: center; gap: 20px; }
        .filter-group { justify-content: center; }
        .footer-inner { grid-template-columns: 1fr; gap: 35px; text-align: center; }
        .contact-item { justify-content: center; }
    }
</style>

<div class="modal-overlay" id="authModal" onclick="handleOverlayClick(event)">
    <div class="modal-box" role="dialog" aria-modal="true" aria-label="Sign In or Register">
        <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>
        <p class="modal-eyebrow">Lumière Beauty</p>
        <h2 class="modal-title">Welcome</h2>
        <p class="modal-subtitle">Create an account or sign in to complete your purchase.</p>

        <div class="modal-tabs">
            <button class="tab-btn active" id="tab-register" onclick="switchTab('register')">Register</button>
            <button class="tab-btn"        id="tab-login"    onclick="switchTab('login')">Login</button>
        </div>

        <?php if (!empty($modal_errors)): ?>
            <div class="modal-errors" id="formErrors">
                <?php foreach ($modal_errors as $err): ?>
                    <p>• <?= htmlspecialchars($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="tab-pane active" id="pane-register">
            <form method="POST" action="">
                <input type="hidden" name="action" value="register">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" placeholder="Name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" placeholder="Surname" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reg_email">Email Address</label>
                    <input type="email" id="reg_email" name="reg_email" placeholder="name@example.com" required>
                </div>
                <div class="form-group">
                    <label for="reg_password">Password <span style="font-weight:300;text-transform:none;">(min. 8 chars)</span></label>
                    <input type="password" id="reg_password" name="reg_password" placeholder="••••••••" minlength="8" required>
                </div>
                <button type="submit" class="btn-submit-modal">Create Account & Continue</button>
            </form>
        </div>

        <div class="tab-pane" id="pane-login">
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="login_email">Email Address</label>
                    <input type="email" id="login_email" name="login_email" placeholder="name@example.com" required>
                </div>
                <div class="form-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="login_password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-submit-modal">Sign In & Continue</button>
            </form>
        </div>
    </div>
</div>

<script>
    function openModal(tab) {
        document.getElementById('authModal').classList.add('is-open');
        switchTab(tab);
        document.getElementById('authModal').querySelector('input').focus();
    }
    function closeModal() {
        document.getElementById('authModal').classList.remove('is-open');
    }
    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        document.getElementById('pane-' + tab).classList.add('active');
    }
    function handleOverlayClick(event) {
        if (event.target === event.currentTarget) closeModal();
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    const loginLink = document.getElementById('open-login-link');
    if (loginLink) {
        loginLink.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('login');
        });
    }

    // Scroll Memory Logic
    window.addEventListener('beforeunload', function() {
        sessionStorage.setItem('scrollPos', window.scrollY);
        sessionStorage.setItem('scrollUrl', window.location.href);
    });

    document.addEventListener("DOMContentLoaded", function() {
        let savedUrl = sessionStorage.getItem('scrollUrl');
        let scrollPos = sessionStorage.getItem('scrollPos');
        if (savedUrl === window.location.href && scrollPos !== null) {
            window.scrollTo(0, parseInt(scrollPos));
        }
    });

    <?php if (!empty($open_modal)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openModal('<?= $open_modal ?>');
        });
    <?php endif; ?>

    function toggleSidebar() {
        const sidebar = document.getElementById('filterSidebar');
        const overlay = document.getElementById('filterOverlay');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    }

</script>

<div id="filterOverlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
<div id="filterSidebar" class="sidebar-menu">
    <div class="sidebar-header">
        <h3>Filters</h3>
        <button class="close-sidebar" onclick="toggleSidebar()">&times;</button>
    </div>
    <div class="sidebar-content" style="display: flex; flex-direction: column; gap: 15px;">
        <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Category</span>
        <a href="index.php" class="filter-btn <?= $filter_category === '' ? 'active' : '' ?>">All Products</a>
        <?php foreach ($categories as $cat): ?>
            <a href="index.php?category=<?= urlencode($cat) ?>" class="filter-btn <?= $filter_category === $cat ? 'active' : '' ?>">
                <?= htmlspecialchars($cat) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
<?php $conn->close(); ?>