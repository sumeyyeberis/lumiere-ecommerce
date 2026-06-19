<?php
/**
 * cart.php — Shopping Cart + Registration/Login Modal
 * -----------------------------------------------------------------------
 * WHAT THIS PAGE DOES:
 *   1. Handles "add to cart", "update quantity", and "remove" actions via
 *      PHP POST, storing everything in $_SESSION['cart'].
 *   2. Displays the current cart contents.
 *   3. When a GUEST clicks "Proceed to Checkout", a JS-powered modal
 *      overlay appears with a Register / Login form — no page redirect.
 *   4. Processes registration (INSERT into Users) and login (SELECT +
 *      password_verify) from the modal form submissions.
 *
 * SESSION CART STRUCTURE:
 *   $_SESSION['cart'] = [
 *       product_id => ['name' => ..., 'price' => ..., 'quantity' => ...],
 *       ...
 *   ]
 *   Using product_id as the key automatically prevents duplicates and
 *   makes quantity updates simple.
 * -----------------------------------------------------------------------
 */

session_start();
require_once 'db_connect.php';

// -----------------------------------------------------------------------
// SECTION A: Handle POST actions BEFORE any HTML output.
//
// We use POST (not GET) for all cart mutations because:
//   - GET requests should be safe/idempotent (no side effects).
//   - POST protects against accidental re-adds via browser back button.
//   - Cart data could contain sensitive info (prices) we don't want in URLs.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

// --- A1: Add item to cart ---
    if ($action === 'add') {
        $product_id = (int) ($_POST['product_id'] ?? 0); 

        if ($product_id > 0) {
            $stmt = $conn->prepare("SELECT name, price, stock_quantity FROM Products WHERE product_id = ?");
            $stmt->bind_param("i", $product_id); 
            $stmt->execute();
            $res = $stmt->get_result();

            if ($product = $res->fetch_assoc()) {
                $current_qty = isset($_SESSION['cart'][$product_id]) ? $_SESSION['cart'][$product_id]['quantity'] : 0;

                if ($current_qty < $product['stock_quantity']) {
                    if (isset($_SESSION['cart'][$product_id])) {
                        $_SESSION['cart'][$product_id]['quantity']++;
                    } else {
                        $_SESSION['cart'][$product_id] = [
                            'name'     => $product['name'],
                            'price'    => $product['price'],
                            'quantity' => 1,
                        ];
                    }
                } else {
                    $_SESSION['flash_message'] = "Sorry, not enough stock for '{$product['name']}'.";
                    $_SESSION['flash_type']    = 'error';
                }
            }
            $stmt->close();
        }
        
        // Inside your 'add' logic in cart.php
        $_SESSION['toast_msg'] = "Added to cart!";

        // THE FIX: Smart Redirect Back to exactly where they came from!
        $return_url = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header("Location: $return_url");
        exit;
    }

    // --- A2: Update cart item quantity ---
    if ($action === 'update') {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $quantity   = (int) ($_POST['quantity']   ?? 0);

        if (isset($_SESSION['cart'][$product_id])) {
            if ($quantity <= 0) {
                unset($_SESSION['cart'][$product_id]);
            } else {
                $_SESSION['cart'][$product_id]['quantity'] = $quantity;
            }
        }
        
        // THE FIX: Smart Redirect
        $return_url = $_SERVER['HTTP_REFERER'] ?? 'cart.php';
        header("Location: $return_url");
        exit;
    }

    // --- A3: Remove item from cart ---
    if ($action === 'remove') {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        unset($_SESSION['cart'][$product_id]);
        
        // THE FIX: Smart Redirect
        $return_url = $_SERVER['HTTP_REFERER'] ?? 'cart.php';
        header("Location: $return_url");
        exit;
    }

    // --- A4: Handle Registration from modal ---
    if ($action === 'register') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $email      = trim($_POST['reg_email']  ?? '');
        $password   = $_POST['reg_password']    ?? '';
        $errors     = [];

        // Server-side validation (never rely solely on HTML5 required attributes)
        if (empty($first_name)) $errors[] = "First name is required.";
        if (empty($last_name))  $errors[] = "Last name is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";

        if (empty($errors)) {
            // Check if email already exists (UNIQUE constraint, but we check first for a friendly message)
            $check = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $errors[] = "An account with this email already exists.";
            } else {
                // PASSWORD HASHING — the most critical security step.
                // password_hash() uses bcrypt by default (PASSWORD_DEFAULT).
                // bcrypt automatically salts and stretches the hash, making it
                // computationally expensive for attackers to crack.
                // NEVER store plain-text passwords.
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $conn->prepare(
                    "INSERT INTO Users (first_name, last_name, email, password_hash, role)
                     VALUES (?, ?, ?, ?, 'customer')"
                );
                $insert->bind_param("ssss", $first_name, $last_name, $email, $password_hash);

                if ($insert->execute()) {
                    // Log the user in immediately after registration
                    $_SESSION['user_id']    = $conn->insert_id; // The new user's auto-generated ID
                    $_SESSION['first_name'] = $first_name;
                    $_SESSION['role']       = 'customer';
                    $_SESSION['flash_message'] = "Welcome, $first_name! Your account has been created.";
                    $_SESSION['flash_type']    = 'success';
                    header("Location: checkout.php");
                    exit;
                } else {
                    $errors[] = "Registration failed. Please try again.";
                }
            }
        }
        // Store errors in session to display in the modal after redirect
        $_SESSION['modal_errors']  = $errors;
        $_SESSION['open_modal']    = 'register';
        header("Location: cart.php");
        exit;
    }

    // --- A5: Handle Login from modal ---
    if ($action === 'login') {
        $email    = trim($_POST['login_email']    ?? '');
        $password = $_POST['login_password'] ?? '';
        $errors   = [];

        if (empty($email) || empty($password)) {
            $errors[] = "Please enter both email and password.";
        } else {
            // Fetch the stored hash by email
            $stmt = $conn->prepare("SELECT user_id, first_name, role, password_hash FROM Users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            // password_verify() compares the plain-text input against the
            // stored bcrypt hash. It's timing-safe (prevents timing attacks).
            if ($user && password_verify($password, $user['password_hash'])) {
                // Login success — save minimal info in session
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['role']       = $user['role'];

                header("Location: checkout.php");
                exit;
            } else {
                // Deliberately vague error — don't tell attacker whether email exists
                $errors[] = "Invalid email or password.";
            }
        }
        $_SESSION['modal_errors'] = $errors;
        $_SESSION['open_modal']   = 'login';
        header("Location: cart.php");
        exit;
    }
}

// -----------------------------------------------------------------------
// SECTION B: Calculate cart totals for display.
// -----------------------------------------------------------------------
$cart       = $_SESSION['cart'] ?? [];
$cart_total = 0.0;
foreach ($cart as $item) {
    $cart_total += $item['price'] * $item['quantity'];
}

// Retrieve and clear any errors / modal state stored from a POST redirect
$modal_errors = $_SESSION['modal_errors'] ?? [];
$open_modal   = $_SESSION['open_modal']   ?? '';
unset($_SESSION['modal_errors'], $_SESSION['open_modal']);

// Cart item count for nav badge
$cart_count = array_sum(array_column($cart, 'quantity'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Cart — Lumière Beauty</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream:       #faf7f2;
            --warm-white:  #f5f0e8;
            --blush:       #e8c4b0;
            --terracotta:  #c07a5a;
            --deep-brown:  #3d2314;
            --charcoal:    #2a2a2a;
            --text-muted:  #8a7a72;
            --border:      #e8ddd5;
            --success:     #5a8a6a;
            --danger:      #c0392b;
            --font-display:'Cormorant Garamond', serif;
            --font-body:   'DM Sans', sans-serif;
            --shadow-soft: 0 4px 24px rgba(61,35,20,0.08);
            --radius:      12px;
            --transition:  0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--cream);
            color: var(--charcoal);
            font-family: var(--font-body);
            font-weight: 300;
            line-height: 1.6;
        }

        /* --- NAV (same as index.php) --- */
        nav {
            background: rgba(250,247,242,0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 100;
            padding: 0 5%;
        }
        .nav-inner {
            max-width: 1280px; margin: 0 auto;
            display: flex; align-items: center; justify-content: space-between;
            height: 70px;
        }
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

        /* --- PAGE LAYOUT --- */
        .page-wrap { max-width: 1100px; margin: 0 auto; padding: 50px 5%; }
        .page-title { font-family: var(--font-display); font-size: 2.5rem; font-weight: 300; color: var(--deep-brown); margin-bottom: 8px; }
        .page-subtitle { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 40px; }

        /* --- TWO-COLUMN LAYOUT: items list + summary sidebar --- */
        .cart-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 40px;
            align-items: start;
        }
        @media (max-width: 768px) {
            .cart-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 850px) {
        html, body { overflow-x: hidden; max-width: 100vw; }
        nav { padding: 0 10px; }
        .nav-inner { flex-wrap: wrap; height: auto; padding: 15px 0; gap: 15px; }
        .nav-links { flex-wrap: wrap; gap: 15px; justify-content: center; width: 100%; }
        .nav-search-form { order: 5; width: 100%; margin: 0; justify-content: center; margin-top: 5px; }
        .nav-search-input { width: 100%; max-width: 250px; }
        .nav-search-input:focus { width: 100%; max-width: 280px; }
        }

        /* --- CART ITEM ROW --- */
        .cart-items-list { display: flex; flex-direction: column; gap: 16px; }
        .cart-item {
            background: #fff;
            border-radius: var(--radius);
            padding: 20px;
            display: grid;
            grid-template-columns: 80px 1fr auto;
            gap: 16px;
            align-items: center;
            box-shadow: var(--shadow-soft);
        }
        .item-thumb {
            width: 80px; height: 80px;
            border-radius: 8px;
            background: var(--warm-white);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            overflow: hidden;
        }
        .item-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
        .item-name { font-family: var(--font-display); font-size: 1.1rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 4px; }
        .item-unit-price { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 10px; }
        .item-controls { display: flex; align-items: center; gap: 10px; }
        .qty-form { display: flex; align-items: center; gap: 8px; }
        .qty-input {
            width: 52px; padding: 6px 8px; text-align: center;
            border: 1px solid var(--border); border-radius: 8px;
            font-family: var(--font-body); font-size: 0.9rem; color: var(--charcoal);
            background: var(--cream);
        }
        .btn-qty-update, .btn-remove {
            border: none; border-radius: 8px; cursor: pointer;
            font-family: var(--font-body); font-size: 0.78rem; font-weight: 500;
            padding: 6px 12px; transition: all var(--transition);
        }
        .btn-qty-update { background: var(--warm-white); color: var(--charcoal); }
        .btn-qty-update:hover { background: var(--blush); }
        .btn-remove { background: #fdecea; color: var(--danger); }
        .btn-remove:hover { background: var(--danger); color: #fff; }
        .item-line-total { font-family: var(--font-display); font-size: 1.3rem; font-weight: 600; color: var(--deep-brown); white-space: nowrap; }

        /* --- ORDER SUMMARY SIDEBAR --- */
        .order-summary {
            background: #fff;
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow-soft);
            position: sticky;
            top: 90px; /* sticks below the nav */
        }
        .summary-title { font-family: var(--font-display); font-size: 1.5rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 20px; }
        .summary-row { display: flex; justify-content: space-between; font-size: 0.9rem; padding: 8px 0; border-bottom: 1px solid var(--border); }
        .summary-row:last-of-type { border: none; }
        .summary-total { display: flex; justify-content: space-between; font-family: var(--font-display); font-size: 1.4rem; font-weight: 600; color: var(--deep-brown); margin: 16px 0 24px; }
        .btn-checkout {
            width: 100%; padding: 14px;
            background: var(--deep-brown); color: #fff;
            border: none; border-radius: 50px;
            font-family: var(--font-body); font-size: 0.9rem;
            font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase;
            cursor: pointer; transition: all var(--transition);
        }
        .btn-checkout:hover { background: var(--terracotta); }
        .secure-note { text-align: center; font-size: 0.75rem; color: var(--text-muted); margin-top: 12px; }

        /* --- EMPTY CART STATE --- */
        .empty-cart { text-align: center; padding: 80px 20px; }
        .empty-cart .icon { font-size: 4rem; margin-bottom: 1rem; }
        .empty-cart h3 { font-family: var(--font-display); font-size: 1.8rem; color: var(--deep-brown); margin-bottom: 0.5rem; }
        .empty-cart p { color: var(--text-muted); margin-bottom: 1.5rem; }
        .btn-shop { display: inline-block; padding: 12px 28px; background: var(--deep-brown); color: #fff; border-radius: 50px; text-decoration: none; font-size: 0.85rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; transition: background var(--transition); }
        .btn-shop:hover { background: var(--terracotta); }

        /* --- FLASH MESSAGE --- */
        .flash { padding: 14px 20px; border-radius: var(--radius); margin-bottom: 28px; font-size: 0.9rem; }
        .flash.success { background: #e8f5ec; border-left: 4px solid var(--success); color: #2d5a3a; }
        .flash.error   { background: #fdecea; border-left: 4px solid var(--danger); color: #7b1c1c; }

        /* ================================================================
           MODAL OVERLAY — Registration / Login
           ================================================================
           The overlay sits at fixed position covering the entire viewport.
           It starts hidden (opacity:0, pointer-events:none) and is made
           visible by adding the .is-open class via JavaScript.
           Using CSS transitions gives us smooth fade-in/out.
        ================================================================ */
        .modal-overlay {
            position: fixed;
            inset: 0; /* shorthand for top/right/bottom/left: 0 */
            background: rgba(30, 15, 5, 0.6);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
            /* Hidden by default */
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.35s ease;
        }
        .modal-overlay.is-open {
            opacity: 1;
            pointer-events: auto; /* re-enable clicks when open */
        }

        .modal-box {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 460px;
            padding: 36px 40px;
            box-shadow: 0 24px 80px rgba(0,0,0,0.25);
            transform: translateY(20px) scale(0.97);
            transition: transform 0.35s ease;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-overlay.is-open .modal-box {
            transform: translateY(0) scale(1);
        }

        .modal-close {
            position: absolute; top: 18px; right: 20px;
            background: none; border: none; cursor: pointer;
            font-size: 1.4rem; color: var(--text-muted);
            line-height: 1; padding: 4px 8px; border-radius: 6px;
            transition: background var(--transition);
        }
        .modal-close:hover { background: var(--warm-white); }

        /* The modal-box needs relative positioning for the close button */
        .modal-box { position: relative; }

        .modal-eyebrow { font-size: 0.7rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--terracotta); margin-bottom: 6px; }
        .modal-title { font-family: var(--font-display); font-size: 1.9rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 4px; }
        .modal-subtitle { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 24px; }

        /* Tab switcher (Register ↔ Login) */
        .modal-tabs { display: flex; gap: 4px; background: var(--warm-white); border-radius: 50px; padding: 4px; margin-bottom: 28px; }
        .tab-btn {
            flex: 1; padding: 8px; text-align: center;
            border: none; border-radius: 50px; background: transparent;
            font-family: var(--font-body); font-size: 0.85rem; font-weight: 500;
            cursor: pointer; transition: all var(--transition); color: var(--text-muted);
        }
        .tab-btn.active { background: #fff; color: var(--deep-brown); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

        /* Form tab panes — only the active one is visible */
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* Form fields */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.78rem; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .form-group input {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--border); border-radius: 10px;
            font-family: var(--font-body); font-size: 0.9rem; color: var(--charcoal);
            background: #fff; transition: border-color var(--transition);
            outline: none;
        }
        .form-group input:focus { border-color: var(--terracotta); }

        .btn-submit-modal {
            width: 100%; padding: 13px;
            background: var(--deep-brown); color: #fff;
            border: none; border-radius: 50px;
            font-family: var(--font-body); font-size: 0.88rem; font-weight: 500;
            letter-spacing: 0.08em; text-transform: uppercase;
            cursor: pointer; margin-top: 8px;
            transition: background var(--transition);
        }
        .btn-submit-modal:hover { background: var(--terracotta); }

        /* Error list inside modal */
        .modal-errors {
            background: #fdecea;
            border-left: 4px solid var(--danger);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .modal-errors p { font-size: 0.83rem; color: #7b1c1c; margin-bottom: 2px; }
        .modal-errors p:last-child { margin: 0; }

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

<!-- ===== NAVIGATION ===================================================== -->
<nav>
    <div class="nav-inner">
        <a href="index.php" class="nav-logo">Lumi<span>ère</span></a>
        
        <div class="nav-links">
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

<!-- ===== PAGE CONTENT =================================================== -->
<div class="page-wrap">
    <h1 class="page-title">Your Cart</h1>
    <p class="page-subtitle">Review your items before checking out.</p>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="flash <?= $_SESSION['flash_type'] ?? 'success' ?>">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <?php if (!empty($cart)): ?>
        <div class="cart-layout">

            <!-- === CART ITEMS LIST === -->
            <div class="cart-items-list">
                <?php foreach ($cart as $product_id => $item): ?>
                    <div class="cart-item">
                        <!-- Thumbnail -->
                        <div class="item-thumb">
                            <?php
                            // Try to get image from DB for the thumbnail
                            $thumb_stmt = $conn->prepare("SELECT image_url FROM Products WHERE product_id = ?");
                            $thumb_stmt->bind_param("i", $product_id);
                            $thumb_stmt->execute();
                            $thumb = $thumb_stmt->get_result()->fetch_assoc();
                            if (!empty($thumb['image_url'])):
                            ?>
                                <img src="<?= htmlspecialchars($thumb['image_url']) ?>" alt="">
                            <?php else: ?>
                                ✨
                            <?php endif; ?>
                        </div>

                        <!-- Name + price + controls -->
                        <div>
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="item-unit-price">₺<?= number_format($item['price'], 2) ?> each</div>
                            <div class="item-controls">
                                <!-- Update quantity form -->
                                <form method="POST" class="qty-form">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                    <input type="number" name="quantity" class="qty-input"
                                           value="<?= $item['quantity'] ?>" min="0" max="99">
                                    <button type="submit" class="btn-qty-update">Update</button>
                                </form>
                                <!-- Remove item form -->
                                <form method="POST">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                    <button type="submit" class="btn-remove">✕ Remove</button>
                                </form>
                            </div>
                        </div>

                        <!-- Line total -->
                        <div class="item-line-total">
                            ₺<?= number_format($item['price'] * $item['quantity'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- === ORDER SUMMARY SIDEBAR === -->
            <aside class="order-summary">
                <div class="summary-title">Order Summary</div>
                <?php foreach ($cart as $item): ?>
                    <div class="summary-row">
                        <span><?= htmlspecialchars($item['name']) ?> × <?= $item['quantity'] ?></span>
                        <span>₺<?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="summary-total">
                    <span>Total</span>
                    <span>₺<?= number_format($cart_total, 2) ?></span>
                </div>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- User is already logged in — go straight to checkout -->
                    <a href="checkout.php" style="display:block;text-align:center;" class="btn-checkout">Proceed to Checkout</a>
                <?php else: ?>
                    <!--
                        GUEST USER: This button does NOT submit a form.
                        Its onclick calls openModal('register') defined in our
                        JavaScript below, which reveals the overlay modal.
                    -->
                    <button class="btn-checkout" onclick="openModal('register')">
                        Proceed to Checkout
                    </button>
                    <p class="secure-note">🔒 Sign in or register to complete your order</p>
                <?php endif; ?>
            </aside>

        </div>

    <?php else: ?>
        <!-- Empty cart -->
        <div class="empty-cart">
            <div class="icon">🛒</div>
            <h3>Your cart is empty</h3>
            <p>Looks like you haven't added anything yet.</p>
            <a href="index.php" class="btn-shop">Continue Shopping</a>
        </div>
    <?php endif; ?>
</div>

<!-- ================================================================
     MODAL OVERLAY — Registration & Login
     ================================================================
     This div is always in the DOM but invisible until .is-open is added.
     Clicking the dark backdrop (overlay) closes it.
================================================================ -->
<div class="modal-overlay" id="authModal" onclick="handleOverlayClick(event)">
    <div class="modal-box" role="dialog" aria-modal="true" aria-label="Sign In or Register">

        <!-- Close button (×) -->
        <button class="modal-close" onclick="closeModal()" aria-label="Close">&times;</button>

        <p class="modal-eyebrow">Lumière Beauty</p>
        <h2 class="modal-title">Welcome</h2>
        <p class="modal-subtitle">Create an account or sign in to complete your purchase.</p>

        <!-- Tab switcher: clicking tabs swaps which form is visible -->
        <div class="modal-tabs">
            <button class="tab-btn active" id="tab-register" onclick="switchTab('register')">Register</button>
            <button class="tab-btn"        id="tab-login"    onclick="switchTab('login')">Login</button>
        </div>

        <?php
        // If there were errors from a POST submission, display them.
        // We show them inside the correct tab pane.
        if (!empty($modal_errors)):
        ?>
            <div class="modal-errors" id="formErrors">
                <?php foreach ($modal_errors as $err): ?>
                    <p>• <?= htmlspecialchars($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ---- REGISTER TAB PANE ---- -->
        <div class="tab-pane active" id="pane-register">
            <!--
                action="" means submit to the current page (cart.php).
                method="POST" sends data in the request body (not the URL).
                hidden 'action' field tells our PHP which block to run.
            -->
            <form method="POST" action="cart.php">
                <input type="hidden" name="action" value="register">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name"
                               placeholder="Name" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name"
                               placeholder="Surname" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reg_email">Email Address</label>
                    <input type="email" id="reg_email" name="reg_email"
                           placeholder="name@example.com" required>
                </div>
                <div class="form-group">
                    <label for="reg_password">Password <span style="font-weight:300;text-transform:none;">(min. 8 chars)</span></label>
                    <input type="password" id="reg_password" name="reg_password"
                           placeholder="••••••••" minlength="8" required>
                </div>
                <button type="submit" class="btn-submit-modal">Create Account & Continue</button>
            </form>
        </div>

        <!-- ---- LOGIN TAB PANE ---- -->
        <div class="tab-pane" id="pane-login">
            <form method="POST" action="cart.php">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="login_email">Email Address</label>
                    <input type="email" id="login_email" name="login_email"
                           placeholder="name@example.com" required>
                </div>
                <div class="form-group">
                    <label for="login_password">Password</label>
                    <input type="password" id="login_password" name="login_password"
                           placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-submit-modal">Sign In & Continue</button>
            </form>
        </div>

    </div><!-- /.modal-box -->
</div><!-- /.modal-overlay -->

<!-- ================================================================
     JAVASCRIPT — Modal & Tab Logic
     ================================================================
     All vanilla JS. No libraries. Three responsibilities:
       1. openModal(tab) — show the overlay and switch to the right tab.
       2. closeModal()   — hide the overlay.
       3. switchTab(tab) — swap the active tab/pane without re-opening.
       4. handleOverlayClick(e) — close modal when clicking backdrop.
       5. Auto-open the modal on page load if PHP set $open_modal
          (happens after a failed form submission via PRG redirect).
================================================================ -->
<script>
/**
 * openModal(tab)
 * Shows the overlay by adding the .is-open CSS class.
 * Then switches to the specified tab ('register' or 'login').
 */
function openModal(tab) {
    document.getElementById('authModal').classList.add('is-open');
    switchTab(tab);
    // Trap focus inside modal for accessibility (simple approach)
    document.getElementById('authModal').querySelector('input').focus();
}

/**
 * closeModal()
 * Hides the overlay by removing the .is-open CSS class.
 * The CSS transition on .modal-overlay handles the smooth fade-out.
 */
function closeModal() {
    document.getElementById('authModal').classList.remove('is-open');
}

/**
 * switchTab(tab)
 * Makes the chosen tab button active and shows the matching form pane.
 * We simply toggle the 'active' class — CSS handles the show/hide.
 */
function switchTab(tab) {
    // Deactivate all tab buttons and panes
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));

    // Activate the chosen ones
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('pane-' + tab).classList.add('active');
}

/**
 * handleOverlayClick(event)
 * Closes the modal if the user clicked directly on the dark backdrop,
 * but NOT if they clicked inside the white modal-box.
 * event.target is the element that was clicked.
 * event.currentTarget is the overlay div itself.
 */
function handleOverlayClick(event) {
    if (event.target === event.currentTarget) {
        closeModal();
    }
}

// Keyboard accessibility: close modal on Escape key press
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// --- Wire up the "Login" link in the nav ---
const loginLink = document.getElementById('open-login-link');
if (loginLink) {
    loginLink.addEventListener('click', function(e) {
        e.preventDefault(); // Don't follow the # href
        openModal('login');
    });
}

<?php
// If a POST submission failed and we redirected back here,
// PHP will have set $open_modal to 'register' or 'login'.
// We auto-open the modal on page load so the user sees their errors.
if (!empty($open_modal)):
?>
    document.addEventListener('DOMContentLoaded', function() {
        openModal('<?= $open_modal ?>');
    });
<?php endif; ?>
</script>

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

</body>
</html>
<?php $conn->close(); ?>
