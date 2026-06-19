<?php
/**
 * checkout.php — Order Processing & Confirmation
 * -----------------------------------------------------------------------
 * WHAT THIS PAGE DOES:
 *   1. SECURITY: Immediately redirects to cart.php if the user is not logged in.
 *   2. DISPLAY: Shows the user's cart contents as an order review before
 *      they place the order.
 *   3. PROCESSING: When "Place Order" is clicked (POST), it:
 *        a. Re-validates all cart items against the DB (current stock/prices).
 *        b. Inserts ONE row into the Orders table.
 *        c. Loops through the cart and inserts ONE row per item into Order_Items.
 *        d. All DB writes are wrapped in a TRANSACTION — if anything fails,
 *           the entire operation is rolled back so we never get orphaned data.
 *        e. Decrements stock quantities in the Products table.
 *        f. Clears the session cart.
 *        g. Displays a success page with the order ID.
 *
 * KEY CONCEPTS DEMONSTRATED:
 *   - Database TRANSACTIONS (BEGIN, COMMIT, ROLLBACK) for data integrity
 *   - price_at_purchase: why we snapshot prices at time of order
 *   - Authentication gate before processing sensitive operations
 *   - Loop-based multi-row INSERT with prepared statements
 * -----------------------------------------------------------------------
 */

session_start();
require_once 'db_connect.php';

// -----------------------------------------------------------------------
// SECURITY GATE: Must be logged in to access checkout.
//
// We check BEFORE doing anything else. If not logged in, we redirect to
// cart.php which will prompt them to log in via the modal.
// -----------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    // Store a flash message so cart.php explains why they were sent back
    $_SESSION['flash_message'] = "Please sign in to complete your purchase.";
    $_SESSION['flash_type']    = 'error';
    $_SESSION['open_modal']    = 'login'; // auto-open the login tab
    header("Location: cart.php");
    exit;
}

// -----------------------------------------------------------------------
// CART VALIDATION: If the cart is empty, there's nothing to check out.
// -----------------------------------------------------------------------
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    $_SESSION['flash_message'] = "Your cart is empty.";
    $_SESSION['flash_type']    = 'error';
    header("Location: index.php");
    exit;
}

// -----------------------------------------------------------------------
// VARIABLES to hold page state.
// $order_success will hold the completed order's ID after processing.
// $order_errors collects any problems found during validation.
// -----------------------------------------------------------------------
$order_success = null; // Will be set to the new order_id on success
$order_errors  = [];

// -----------------------------------------------------------------------
// HANDLE POST — "Place Order" button clicked.
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $user_id = (int) $_SESSION['user_id'];

    // --- Step 1: Re-validate each cart item against the live database ---
    //
    // WHY re-validate? The user might have left the cart sitting for a while
    // and another customer bought the last item. We must recheck stock NOW,
    // right before writing to the database.
    //
    // We also re-read prices from the DB here. This is a secondary check —
    // our cart was already populated from the DB back in cart.php, but it's
    // good practice to confirm before charging.
    $validated_items = [];
    $total_amount    = 0.0;

    foreach ($cart as $product_id => $cart_item) {
        $pid = (int) $product_id;
        $stmt = $conn->prepare("SELECT name, price, stock_quantity FROM Products WHERE product_id = ?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $db_product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$db_product) {
            $order_errors[] = "'{$cart_item['name']}' is no longer available. Please remove it from your cart.";
            continue; // Skip to next item
        }

        if ($db_product['stock_quantity'] < $cart_item['quantity']) {
            $available = $db_product['stock_quantity'];
            $order_errors[] = "Only $available unit(s) of '{$db_product['name']}' are available.";
            continue;
        }

        // Item is valid — store with the CURRENT DB price (not session price)
        $validated_items[$pid] = [
            'name'     => $db_product['name'],
            'price'    => (float) $db_product['price'],
            'quantity' => (int) $cart_item['quantity'],
        ];
        $total_amount += $db_product['price'] * $cart_item['quantity'];
    }

    // If any item had a problem, abort and show the errors
    if (!empty($order_errors)) {
        // Fall through to render the page with error messages
    } else {
        // ---------------------------------------------------------------
        // --- Step 2: DATABASE TRANSACTION ---
        //
        // A TRANSACTION groups multiple SQL statements into one atomic unit.
        //
        //   BEGIN TRANSACTION
        //     → INSERT into Orders          (creates the order record)
        //     → INSERT into Order_Items ×N  (one row per product)
        //     → UPDATE Products stock ×N    (decrement available units)
        //   COMMIT (all succeed) — OR — ROLLBACK (any one fails → undo all)
        //
        // WHY? Consider what happens WITHOUT a transaction:
        //   Orders INSERT succeeds ✓
        //   Order_Items INSERT #1 succeeds ✓
        //   Order_Items INSERT #2 FAILS  ✗  (DB crashes, network drops, etc.)
        //   → We now have an order with only SOME items. Data is corrupted.
        //
        // With a transaction, partial failures are impossible — either
        // everything is written or nothing is.
        // ---------------------------------------------------------------
        $conn->begin_transaction(); // MySQLi transaction start

        try {
            // --- 2a: INSERT into Orders ---
            $order_stmt = $conn->prepare(
                "INSERT INTO orders (user_id, total_amount, status)
                 VALUES (?, ?, 'pending')"
            );
            $order_stmt->bind_param("id", $user_id, $total_amount);
            // "id" = integer, double (PHP float maps to MySQL DECIMAL via "d")
            $order_stmt->execute();

            // insert_id gives us the AUTO_INCREMENT primary key of the row just inserted.
            // We need this order_id to use as a foreign key in Order_Items.
            $new_order_id = $conn->insert_id;
            $order_stmt->close();

            if (!$new_order_id) {
                throw new Exception("Failed to create the order record.");
            }

            // --- 2b: INSERT one Order_Items row per product ---
            //
            // price_at_purchase is the CRITICAL field here.
            // We store the price THE ITEM COSTS RIGHT NOW, not a reference to
            // the Products table. Why? If an admin changes a product's price
            // tomorrow, the historical order receipt must still show what the
            // customer ACTUALLY PAID. Storing it as a snapshot solves this.
            $item_stmt = $conn->prepare(
                "INSERT INTO Order_Items (order_id, product_id, quantity, price_at_purchase)
                 VALUES (?, ?, ?, ?)"
            );

            foreach ($validated_items as $product_id => $item) {
                $pid      = (int) $product_id;
                $qty      = $item['quantity'];
                $price_snap = $item['price']; // The snapshot price

                $item_stmt->bind_param("iiid", $new_order_id, $pid, $qty, $price_snap);
                $item_stmt->execute();

                if ($item_stmt->affected_rows < 1) {
                    throw new Exception("Failed to save item: {$item['name']}");
                }
            }
            $item_stmt->close();

            // --- 2c: Decrement stock quantities ---
            //
            // We use stock_quantity - ? with a prepared statement.
            // We also add "AND stock_quantity >= ?" as a safety guard —
            // this prevents stock going negative if somehow two orders
            // slip through at the same moment (race condition protection).
            $stock_stmt = $conn->prepare(
                "UPDATE Products
                 SET stock_quantity = stock_quantity - ?
                 WHERE product_id = ? AND stock_quantity >= ?"
            );

            foreach ($validated_items as $product_id => $item) {
                $pid = (int) $product_id;
                $qty = $item['quantity'];
                $stock_stmt->bind_param("iii", $qty, $pid, $qty);
                $stock_stmt->execute();

                if ($stock_stmt->affected_rows < 1) {
                    // Another order beat us to the last item — rollback everything
                    throw new Exception("Insufficient stock for '{$item['name']}'. Please update your cart.");
                }
            }
            $stock_stmt->close();

            // --- All statements succeeded — COMMIT the transaction ---
            $conn->commit();

            // --- 2d: Clear the cart from the session ---
            // The order is placed — the cart is now "empty" from the user's perspective.
            unset($_SESSION['cart']);

            // Store the new order ID so we can display it in the success message
            $order_success = $new_order_id;

        } catch (Exception $e) {
            // Something went wrong — UNDO all database changes made in this transaction
            $conn->rollback();
            $order_errors[] = "Order failed: " . $e->getMessage();
            // In production you'd log $e to a file; never expose raw exceptions to users.
        }
    }
}

// Refresh the cart variable (it may have just been cleared)
$cart = $_SESSION['cart'] ?? [];

// Recalculate totals for the review page display
$display_total = 0.0;
foreach ($cart as $item) {
    $display_total += $item['price'] * $item['quantity'];
}

$cart_count = array_sum(array_column($cart, 'quantity'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $order_success ? 'Order Confirmed' : 'Checkout' ?> — Lumière Beauty</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream:        #faf7f2;
            --warm-white:   #f5f0e8;
            --blush:        #e8c4b0;
            --terracotta:   #c07a5a;
            --deep-brown:   #3d2314;
            --charcoal:     #2a2a2a;
            --text-muted:   #8a7a72;
            --border:       #e8ddd5;
            --success:      #5a8a6a;
            --danger:       #c0392b;
            --font-display: 'Cormorant Garamond', serif;
            --font-body:    'DM Sans', sans-serif;
            --shadow-soft:  0 4px 24px rgba(61,35,20,0.08);
            --radius:       12px;
            --transition:   0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--cream); color: var(--charcoal); font-family: var(--font-body); font-weight: 300; line-height: 1.7; }

        nav { background: rgba(250,247,242,0.92); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; padding: 0 5%; }
        .nav-inner { max-width: 1280px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; height: 70px; }
        .nav-logo { font-family: var(--font-display); font-size: 1.7rem; font-weight: 600; color: var(--deep-brown); text-decoration: none; }
        .nav-logo span { color: var(--terracotta); font-style: italic; }
        .nav-links { display: flex; align-items: center; gap: 2rem; }
        .nav-links a { font-size: 0.85rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--charcoal); text-decoration: none; transition: color var(--transition); }
        .nav-links a:hover { color: var(--terracotta); }
        .cart-btn { background: var(--deep-brown); color: #fff !important; padding: 0.5rem 1.2rem; border-radius: 50px; }

        /* ---- PAGE LAYOUT ---- */
        .page-wrap { max-width: 1000px; margin: 0 auto; padding: 50px 5% 80px; }

        /* ---- CHECKOUT STEPS INDICATOR ---- */
        .steps { display: flex; align-items: center; gap: 0; margin-bottom: 50px; }
        .step { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; letter-spacing: 0.06em; text-transform: uppercase; }
        .step-num { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 500; }
        .step.done   .step-num { background: var(--success); color: #fff; }
        .step.active .step-num { background: var(--deep-brown); color: #fff; }
        .step.future .step-num { background: var(--border); color: var(--text-muted); }
        .step.done   .step-label { color: var(--text-muted); }
        .step.active .step-label { color: var(--deep-brown); font-weight: 500; }
        .step.future .step-label { color: var(--text-muted); }
        .step-line { flex: 1; height: 1px; background: var(--border); margin: 0 12px; }

        /* ---- TWO-COLUMN CHECKOUT LAYOUT ---- */
        .checkout-layout { display: grid; grid-template-columns: 1fr 360px; gap: 40px; align-items: start; }
        @media (max-width: 768px) { .checkout-layout { grid-template-columns: 1fr; } }
        @media (max-width: 850px) {
        html, body { overflow-x: hidden; max-width: 100vw; }
        nav { padding: 0 10px; }
        .nav-inner { flex-wrap: wrap; height: auto; padding: 15px 0; gap: 15px; }
        .nav-links { flex-wrap: wrap; gap: 15px; justify-content: center; width: 100%; }
        .nav-search-form { order: 5; width: 100%; margin: 0; justify-content: center; margin-top: 5px; }
        .nav-search-input { width: 100%; max-width: 250px; }
        .nav-search-input:focus { width: 100%; max-width: 280px; }
        }

        /* ---- ORDER REVIEW (left column) ---- */
        .section-card { background: #fff; border-radius: var(--radius); padding: 28px 30px; box-shadow: var(--shadow-soft); margin-bottom: 20px; }
        .section-card-title { font-family: var(--font-display); font-size: 1.3rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }

        /* Order items table */
        .order-review-item { display: grid; grid-template-columns: 1fr auto; gap: 16px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .order-review-item:last-child { border: none; }
        .ori-name { font-weight: 500; font-size: 0.9rem; color: var(--deep-brown); margin-bottom: 2px; }
        .ori-qty  { font-size: 0.8rem; color: var(--text-muted); }
        .ori-price { font-family: var(--font-display); font-size: 1.1rem; font-weight: 600; color: var(--deep-brown); white-space: nowrap; }

        /* Customer info section */
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 0.88rem; border-bottom: 1px solid var(--border); }
        .info-row:last-child { border: none; }
        .info-label { color: var(--text-muted); }
        .info-value { font-weight: 500; color: var(--charcoal); }

        /* ---- ORDER SUMMARY SIDEBAR ---- */
        .order-summary { background: #fff; border-radius: var(--radius); padding: 28px; box-shadow: var(--shadow-soft); position: sticky; top: 90px; }
        .summary-title { font-family: var(--font-display); font-size: 1.4rem; font-weight: 600; color: var(--deep-brown); margin-bottom: 20px; }
        .summary-row { display: flex; justify-content: space-between; font-size: 0.88rem; padding: 8px 0; border-bottom: 1px solid var(--border); color: var(--text-muted); }
        .summary-row:last-of-type { border: none; }
        .summary-total { display: flex; justify-content: space-between; font-family: var(--font-display); font-size: 1.5rem; font-weight: 600; color: var(--deep-brown); margin: 16px 0 24px; }
        .btn-place-order {
            width: 100%; padding: 15px;
            background: var(--deep-brown); color: #fff;
            border: none; border-radius: 50px;
            font-family: var(--font-body); font-size: 0.9rem;
            font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase;
            cursor: pointer; transition: all var(--transition);
        }
        .btn-place-order:hover { background: var(--terracotta); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(192,122,90,0.4); }
        .secure-badges { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; flex-wrap: wrap; }
        .secure-badge { font-size: 0.72rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }

        /* ---- ERROR MESSAGES ---- */
        .error-box { background: #fdecea; border-left: 4px solid var(--danger); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 28px; }
        .error-box p { font-size: 0.88rem; color: #7b1c1c; margin-bottom: 4px; }
        .error-box p:last-child { margin: 0; }
        .error-box .error-title { font-weight: 500; font-size: 0.95rem; margin-bottom: 8px; }

        /* ---- SUCCESS PAGE ---- */
        .success-wrap { text-align: center; padding: 60px 20px; max-width: 600px; margin: 0 auto; }
        .success-icon {
            width: 90px; height: 90px;
            background: linear-gradient(135deg, #e8f5ec, #c8e6d0);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 28px;
            /* Subtle pulse animation to draw attention */
            animation: pulse-ring 2s ease-out 1;
        }
        @keyframes pulse-ring {
            0%   { box-shadow: 0 0 0 0 rgba(90,138,106,0.4); }
            70%  { box-shadow: 0 0 0 24px rgba(90,138,106,0); }
            100% { box-shadow: 0 0 0 0 rgba(90,138,106,0); }
        }
        .success-eyebrow { font-size: 0.75rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--success); margin-bottom: 10px; }
        .success-heading { font-family: var(--font-display); font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 300; color: var(--deep-brown); margin-bottom: 16px; line-height: 1.15; }
        .success-heading em { font-style: italic; font-weight: 600; }
        .success-body { font-size: 0.95rem; color: var(--text-muted); margin-bottom: 36px; line-height: 1.8; }
        .order-id-badge {
            display: inline-block;
            background: var(--warm-white); border: 1px solid var(--border);
            border-radius: 12px; padding: 16px 32px; margin-bottom: 40px;
        }
        .order-id-label { font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
        .order-id-value { font-family: var(--font-display); font-size: 2rem; font-weight: 600; color: var(--deep-brown); }
        .success-actions { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
        .btn-primary { display: inline-block; padding: 13px 28px; background: var(--deep-brown); color: #fff; border-radius: 50px; text-decoration: none; font-size: 0.85rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; transition: background var(--transition); }
        .btn-primary:hover { background: var(--terracotta); }
        .btn-secondary { display: inline-block; padding: 13px 28px; background: transparent; border: 1.5px solid var(--border); color: var(--charcoal); border-radius: 50px; text-decoration: none; font-size: 0.85rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; transition: all var(--transition); }
        .btn-secondary:hover { border-color: var(--terracotta); color: var(--terracotta); }

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
                <a href="logout.php">Logout</a>
            <?php endif; ?>
            <a href="cart.php" class="cart-btn">
                🛒 Cart
                <?php if ($cart_count > 0): ?>
                    <span style="margin-left:4px;background:var(--terracotta);color:#fff;font-size:.65rem;padding:2px 6px;border-radius:50px;"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</nav>

<div class="page-wrap">

    <?php if ($order_success): ?>
    <!-- ================================================================
         SUCCESS STATE — Order was placed successfully
         PHP set $order_success = the new order_id from insert_id.
    ================================================================ -->
    <div class="success-wrap">
        <div class="success-icon">✓</div>
        <p class="success-eyebrow">Order Confirmed</p>
        <h1 class="success-heading">Thank you,<br><em><?= htmlspecialchars($_SESSION['first_name']) ?>!</em></h1>
        <p class="success-body">
            Your order has been placed successfully and is now being prepared.<br>
            We'll have it ready for you as soon as possible.
        </p>
        <div class="order-id-badge">
            <div class="order-id-label">Order Number</div>
            <div class="order-id-value">#<?= str_pad($order_success, 5, '0', STR_PAD_LEFT) ?></div>
            <!--
                str_pad() formats the order_id with leading zeros.
                order_id 7 → "#00007". This is cosmetic only; the DB still
                stores the plain integer 7.
            -->
        </div>
        <div class="success-actions">
            <a href="index.php" class="btn-primary">Continue Shopping</a>
            <a href="index.php" class="btn-secondary">View Catalog</a>
        </div>
    </div>

    <?php else: ?>
    <!-- ================================================================
         CHECKOUT REVIEW STATE — User reviewing order before placing it
    ================================================================ -->

    <!-- Progress steps indicator -->
    <div class="steps">
        <div class="step done">
            <span class="step-num">✓</span>
            <span class="step-label">Cart</span>
        </div>
        <div class="step-line"></div>
        <div class="step active">
            <span class="step-num">2</span>
            <span class="step-label">Review & Pay</span>
        </div>
        <div class="step-line"></div>
        <div class="step future">
            <span class="step-num">3</span>
            <span class="step-label">Confirmation</span>
        </div>
    </div>

    <!-- Errors from validation or DB failure -->
    <?php if (!empty($order_errors)): ?>
        <div class="error-box">
            <p class="error-title">⚠ Please resolve the following before placing your order:</p>
            <?php foreach ($order_errors as $err): ?>
                <p>• <?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="checkout-layout">

        <!-- LEFT COLUMN: Order review + Customer info -->
        <div>

            <!-- Items in this order -->
            <div class="section-card">
                <div class="section-card-title">📦 Order Review</div>
                <?php foreach ($cart as $product_id => $item): ?>
                    <div class="order-review-item">
                        <div>
                            <div class="ori-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="ori-qty">Quantity: <?= $item['quantity'] ?></div>
                        </div>
                        <div class="ori-price">
                            ₺<?= number_format($item['price'] * $item['quantity'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Customer information (read from session, populated at login/registration) -->
            <div class="section-card">
                <div class="section-card-title">👤 Customer Details</div>
                <?php
                // Fetch fresh user details from the DB for display.
                // We read from DB rather than session because the session only
                // stores first_name and role — we also want the email.
                $uid = (int) $_SESSION['user_id'];
                $user_stmt = $conn->prepare("SELECT first_name, last_name, email FROM Users WHERE user_id = ?");
                $user_stmt->bind_param("i", $uid);
                $user_stmt->execute();
                $user_info = $user_stmt->get_result()->fetch_assoc();
                $user_stmt->close();
                ?>
                <div class="info-row">
                    <span class="info-label">Full Name</span>
                    <span class="info-value">
                        <?= htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']) ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?= htmlspecialchars($user_info['email']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Since</span>
                    <span class="info-value">
                        <?php
                        // Fetch created_at for the "member since" display
                        $date_stmt = $conn->prepare("SELECT created_at FROM Users WHERE user_id = ?");
                        $date_stmt->bind_param("i", $uid);
                        $date_stmt->execute();
                        $date_res = $date_stmt->get_result()->fetch_assoc();
                        echo date('F Y', strtotime($date_res['created_at']));
                        $date_stmt->close();
                        ?>
                    </span>
                </div>
            </div>

        </div><!-- /left column -->

        <!-- RIGHT COLUMN: Totals + Place Order -->
        <aside>
            <div class="order-summary">
                <div class="summary-title">Order Total</div>

                <?php foreach ($cart as $item): ?>
                    <div class="summary-row">
                        <span><?= htmlspecialchars($item['name']) ?> ×<?= $item['quantity'] ?></span>
                        <span>₺<?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>₺<?= number_format($display_total, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Shipping</span>
                    <span style="color:var(--success);font-weight:500;">Free</span>
                </div>

                <div class="summary-total">
                    <span>Total</span>
                    <span>₺<?= number_format($display_total, 2) ?></span>
                </div>

                <!--
                    THE PLACE ORDER BUTTON
                    This form POSTs to the same page (checkout.php).
                    The hidden 'action' field tells the PHP at the top which
                    block to execute. On success, the page re-renders in the
                    $order_success state above — no redirect needed here
                    because we want to show the confirmation on the same URL.
                    (We do NOT use PRG here intentionally: we want the
                    confirmation page accessible via back button.)
                -->
                <form method="POST" action="checkout.php">
                    <input type="hidden" name="action" value="place_order">
                    <button type="submit" class="btn-place-order">
                        ✓ Place Order — ₺<?= number_format($display_total, 2) ?>
                    </button>
                </form>

                <div class="secure-badges">
                    <span class="secure-badge">🔒 Secure checkout</span>
                    <span class="secure-badge">📦 Free returns</span>
                    <span class="secure-badge">✉ Email confirmation</span>
                </div>
            </div>
        </aside>

    </div><!-- /.checkout-layout -->
    <?php endif; ?>

</div><!-- /.page-wrap -->

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
