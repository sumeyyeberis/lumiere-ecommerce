<?php
/**
 * account.php — Customer Dashboard
 * -----------------------------------------------------------------------
 * WHAT THIS PAGE DOES:
 * 1. SECURITY: Checks if the user is logged in. If not, kicks them to login.
 * 2. USER DATA: Fetches the user's profile information.
 * 3. ORDER HISTORY: Fetches ONLY the orders belonging to this specific user.
 * -----------------------------------------------------------------------
 */

session_start();
require_once 'db_connect.php';

// --- 1. SECURITY GATE ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_message'] = "Please log in to view your account.";
    $_SESSION['flash_type']    = 'error';
    header("Location: cart.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// --- 2. FETCH USER PROFILE ---
$stmt = $conn->prepare("SELECT first_name, last_name, email, created_at FROM Users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// --- 3. FETCH ORDER HISTORY ---
// We only select orders where user_id matches the logged-in user!
$stmt_orders = $conn->prepare("
    SELECT order_id, total_amount, status, created_at 
    FROM orders 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt_orders->bind_param("i", $user_id);
$stmt_orders->execute();
$orders_result = $stmt_orders->get_result();
$stmt_orders->close();

$cart_count = isset($_SESSION['cart']) ? array_sum(array_column($_SESSION['cart'], 'quantity')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account — Lumière Beauty</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        /* Exact same CSS variables to keep the Lumière brand perfect */
        :root {
            --cream: #faf7f2; --warm-white: #f5f0e8; --blush: #e8c4b0;
            --terracotta: #c07a5a; --deep-brown: #3d2314; --charcoal: #2a2a2a;
            --text-muted: #8a7a72; --border: #e8ddd5; --success: #5a8a6a;
            --font-display: 'Cormorant Garamond', serif; --font-body: 'DM Sans', sans-serif;
            --shadow-soft: 0 4px 24px rgba(61,35,20,0.08); --radius: 12px; --transition: 0.3s;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background-color: var(--cream); color: var(--charcoal); font-family: var(--font-body); font-weight: 300; line-height: 1.7; }

        /* Nav */
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
        
        /* Layout */
        .page-wrap { max-width: 1000px; margin: 60px auto; padding: 0 5%; display: grid; grid-template-columns: 300px 1fr; gap: 40px; align-items: start; }
        @media (max-width: 768px) { .page-wrap { grid-template-columns: 1fr; } }
        @media (max-width: 850px) {
        html, body { overflow-x: hidden; max-width: 100vw; }
        nav { padding: 0 10px; }
        .nav-inner { flex-wrap: wrap; height: auto; padding: 15px 0; gap: 15px; }
        .nav-links { flex-wrap: wrap; gap: 15px; justify-content: center; width: 100%; }
        .nav-search-form { order: 5; width: 100%; margin: 0; justify-content: center; margin-top: 5px; }
        .nav-search-input { width: 100%; max-width: 250px; }
        .nav-search-input:focus { width: 100%; max-width: 280px; }
        }

        /* Sidebar Profile */
        .profile-card { background: #fff; border-radius: var(--radius); padding: 30px; box-shadow: var(--shadow-soft); text-align: center; }
        .profile-icon { width: 80px; height: 80px; background: var(--warm-white); color: var(--terracotta); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 15px; font-family: var(--font-display); }
        .profile-name { font-family: var(--font-display); font-size: 1.6rem; color: var(--deep-brown); margin-bottom: 5px; }
        .profile-email { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px; }
        .profile-date { font-size: 0.8rem; color: var(--text-muted); border-top: 1px solid var(--border); padding-top: 15px; }

        /* Main Content */
        .section-title { font-family: var(--font-display); font-size: 2rem; color: var(--deep-brown); margin-bottom: 20px; }
        .order-card { background: #fff; border-radius: var(--radius); padding: 25px; box-shadow: var(--shadow-soft); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        
        .order-info { display: flex; gap: 40px; flex-wrap: wrap; }
        .info-block { display: flex; flex-direction: column; }
        .info-label { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 4px; }
        .info-value { font-weight: 500; color: var(--charcoal); }
        .info-value.price { font-family: var(--font-display); font-size: 1.2rem; color: var(--deep-brown); }
        
        .status-badge { padding: 6px 16px; border-radius: 50px; font-size: 0.8rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-shipped { background: #d1ecf1; color: #0c5460; }
        .status-delivered { background: #d4edda; color: #155724; }

        .empty-state { background: #fff; border-radius: var(--radius); padding: 50px 20px; text-align: center; box-shadow: var(--shadow-soft); }
        .empty-state h3 { font-family: var(--font-display); font-size: 1.5rem; color: var(--deep-brown); margin-bottom: 10px; }
        .empty-state p { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px; }
        .btn-shop { display: inline-block; padding: 12px 24px; background: var(--deep-brown); color: #fff; text-decoration: none; border-radius: 50px; font-size: 0.85rem; text-transform: uppercase; transition: var(--transition); }
        .btn-shop:hover { background: var(--terracotta); }

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

        </div>
    </div>
</nav>

<div class="page-wrap">
    
    <aside>
        <div class="profile-card">
            <div class="profile-icon"><?= strtoupper(substr($user_info['first_name'], 0, 1)) ?></div>
            <h2 class="profile-name"><?= htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']) ?></h2>
            <div class="profile-email"><?= htmlspecialchars($user_info['email']) ?></div>
            <div class="profile-date">Member since <?= date('F Y', strtotime($user_info['created_at'])) ?></div>
        </div>
    </aside>

    <main>
        <h1 class="section-title">Order History</h1>

        <?php if ($orders_result->num_rows > 0): ?>
            
            <?php while ($order = $orders_result->fetch_assoc()): ?>
                <div class="order-card">
                    <div class="order-info">
                        <div class="info-block">
                            <span class="info-label">Order Number</span>
                            <span class="info-value">#<?= str_pad($order['order_id'], 5, '0', STR_PAD_LEFT) ?></span>
                        </div>
                        <div class="info-block">
                            <span class="info-label">Date Placed</span>
                            <span class="info-value"><?= date('M j, Y', strtotime($order['created_at'])) ?></span>
                        </div>
                        <div class="info-block">
                            <span class="info-label">Total Amount</span>
                            <span class="info-value price">₺<?= number_format($order['total_amount'], 2) ?></span>
                        </div>
                    </div>
                    
                    <div class="status-badge status-<?= strtolower($order['status']) ?>">
                        <?= htmlspecialchars($order['status']) ?>
                    </div>
                </div>
            <?php endwhile; ?>

        <?php else: ?>
            
            <div class="empty-state">
                <h3>No orders yet</h3>
                <p>When you place an order, your receipt and shipping status will appear here.</p>
                <a href="index.php" class="btn-shop">Start Shopping</a>
            </div>

        <?php endif; ?>

    </main>
</div>

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