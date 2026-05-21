<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

try {
    $conn = get_auth_database_connection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo '<!doctype html><html><body style="font-family:Arial;padding:24px;"><h2>Database unavailable</h2><p>' . htmlspecialchars($exception->getMessage()) . '</p></body></html>';
    exit;
}
$products = [];

if ($conn) {
    $categoryCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
    if ($categoryCheck && $categoryCheck->num_rows === 0) {
        $conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'coffee' AFTER name");
    }

    $stockCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
    if ($stockCheck && $stockCheck->num_rows === 0) {
        $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
    }

    // Seed fallback menu items if products are empty.
    $productCountResult = $conn->query("SELECT COUNT(*) AS total FROM products");
    $productCountRow = $productCountResult ? $productCountResult->fetch_assoc() : ['total' => 0];
    if ((int)($productCountRow['total'] ?? 0) === 0) {
        $seedProducts = [
            ["Espresso", "coffee", "Strong and bold single-shot espresso.", 120.00, 30, "https://images.unsplash.com/photo-1510591509098-f4fdc6d0ff04?w=400&h=300&fit=crop"],
            ["Americano", "coffee", "Espresso topped with hot water.", 140.00, 25, "https://assets.beanbox.com/blog_images/AB7ud4YSE6nmOX0iGlgA.jpeg"],
            ["Latte", "coffee", "Creamy milk coffee with smooth texture.", 160.00, 20, "https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&h=300&fit=crop"],
            ["Cappuccino", "coffee", "Espresso with steamed milk and foam.", 165.00, 20, "https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&h=300&fit=crop"],
            ["Iced Tea Lemon", "non-coffee", "Refreshing brewed tea with lemon.", 110.00, 15, "https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=400&h=300&fit=crop"],
            ["Chocolate Muffin", "pastry", "Freshly baked chocolate muffin.", 85.00, 18, "https://images.unsplash.com/photo-1604882406195-d94d4f33b0a9?w=400&h=300&fit=crop"],
            ["Ham and Cheese Sandwich", "food", "Toasted sandwich with ham and cheese.", 180.00, 12, "https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=400&h=300&fit=crop"]
        ];

        $insertProduct = $conn->prepare("INSERT INTO products (name, category, description, price, stock_quantity, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($seedProducts as $product) {
            $productName = $product[0];
            $productCategory = $product[1];
            $productDescription = $product[2];
            $productPrice = $product[3];
            $productStock = (int)$product[4];
            $productImage = $product[5];
            $insertProduct->bind_param("sssdis", $productName, $productCategory, $productDescription, $productPrice, $productStock, $productImage);
            $insertProduct->execute();
        }
        $insertProduct->close();
    }

    $result = $conn->query("SELECT id, name, category, description, price, image_url, stock_quantity FROM products WHERE is_available = 1 ORDER BY category ASC, name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $result->close();
    }
    $conn->close();
}

$displayName = htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? '');
$isStoreManager = in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true);

$menuItemCount = count($products);
$categoryCount = count(array_unique(array_map(static function ($product) {
    return strtolower((string)($product['category'] ?? 'other'));
}, $products)));
$totalStockCount = array_reduce($products, static function ($carry, $product) {
    return $carry + max(0, (int)($product['stock_quantity'] ?? 0));
}, 0);
$lowStockCount = count(array_filter($products, static function ($product) {
    $qty = max(0, (int)($product['stock_quantity'] ?? 0));
    return $qty > 0 && $qty <= 5;
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Klint's Cafe - Order Menu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #0d1b2a;
            --darker-bg: #0a1218;
            --card-bg: #1a2a3a;
            --accent-gold: #c5a572;
            --accent-light: #e8dcc8;
            --text-light: #f5f1ed;
            --text-muted: #b8afa3;
            --border-color: rgba(197, 165, 114, 0.1);
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            scroll-behavior: smooth;
        }

        body {
            background: linear-gradient(135deg, var(--dark-bg) 0%, var(--darker-bg) 100%);
            color: var(--text-light);
            font-family: "Montserrat", "Segoe UI", sans-serif;
            min-height: 100vh;
        }

        .cafe-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0;
        }

        /* Header/Navigation */
        header {
            background: linear-gradient(135deg, rgba(13, 27, 42, 0.9) 0%, rgba(10, 18, 24, 0.9) 100%);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            padding: 18px 30px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-family: "Cormorant Garamond", serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent-gold);
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .logo span {
            font-weight: 600;
            opacity: 0.9;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-actions a, .header-actions button {
            background: transparent;
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .header-actions a:hover, .header-actions button:hover {
            background: rgba(197, 165, 114, 0.1);
            transform: translateY(-1px);
        }

        /* Hero Section */
        .hero {
            text-align: center;
            padding: 60px 30px;
            background: linear-gradient(135deg, rgba(197, 165, 114, 0.08) 0%, transparent 50%), linear-gradient(to right, transparent 0%, rgba(197, 165, 114, 0.05) 100%);
            border-bottom: 1px solid var(--border-color);
        }

        [data-animate] {
            opacity: 0;
            transform: translateY(22px);
            transition: opacity 0.7s ease, transform 0.7s ease;
            transition-delay: var(--delay, 0ms);
        }

        [data-animate].is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .hero h1 {
            font-family: "Cormorant Garamond", serif;
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--accent-gold);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .hero p {
            color: var(--text-muted);
            font-size: 1.1rem;
            margin: 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        .hero-actions {
            margin-top: 22px;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .hero-btn {
            border: 1px solid var(--accent-gold);
            border-radius: 8px;
            color: var(--accent-gold);
            text-decoration: none;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.25s ease;
            background: rgba(197, 165, 114, 0.08);
        }

        .hero-btn:hover {
            transform: translateY(-2px);
            background: rgba(197, 165, 114, 0.2);
            color: var(--accent-light);
        }

        .hero-btn.primary {
            background: var(--accent-gold);
            color: var(--dark-bg);
        }

        .hero-btn.primary:hover {
            background: var(--accent-light);
            color: var(--dark-bg);
        }

        .info-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin: 18px auto 0;
            max-width: 980px;
        }

        .info-chip {
            background: rgba(197, 165, 114, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            text-align: left;
            color: var(--text-light);
        }

        .info-chip strong {
            color: var(--accent-gold);
            font-size: 0.85rem;
            display: block;
            margin-bottom: 2px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .stats-section {
            padding: 18px 30px 0;
            max-width: 1400px;
            margin: 0 auto;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
        }

        .stat-card {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: linear-gradient(145deg, rgba(26, 42, 58, 0.7) 0%, rgba(26, 42, 58, 0.42) 100%);
            padding: 14px;
        }

        .stat-value {
            display: block;
            color: var(--accent-gold);
            font-family: "Cormorant Garamond", serif;
            font-weight: 700;
            font-size: 2rem;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Menu Section */
        .menu-section {
            padding: 50px 30px;
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .menu-header {
            grid-column: 1 / -1;
            margin-bottom: 10px;
        }

        .menu-header h2 {
            font-family: "Cormorant Garamond", serif;
            font-size: 2.2rem;
            color: var(--accent-gold);
            margin: 0 0 8px 0;
        }

        .menu-header p {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.95rem;
        }

        .menu-toolbar {
            margin-top: 16px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .category-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .category-chip {
            border: 1px solid var(--border-color);
            background: rgba(197, 165, 114, 0.08);
            color: var(--text-light);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .category-chip.active,
        .category-chip:hover {
            background: var(--accent-gold);
            color: var(--dark-bg);
            border-color: var(--accent-gold);
        }

        .menu-search {
            min-width: 220px;
            flex: 1;
            max-width: 320px;
            border: 1px solid var(--border-color);
            background: rgba(13, 27, 42, 0.6);
            color: var(--text-light);
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.88rem;
        }

        .menu-search::placeholder {
            color: var(--text-muted);
        }

        /* Product Grid */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 24px;
        }

        .menu-item {
            background: linear-gradient(135deg, rgba(26, 42, 58, 0.8) 0%, rgba(26, 42, 58, 0.5) 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.4s ease;
            display: flex;
            flex-direction: column;
            transform-style: preserve-3d;
            will-change: transform;
            position: relative;
        }

        .menu-item::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(circle at var(--mx, 50%) var(--my, 50%), rgba(232, 220, 200, 0.18), transparent 45%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .menu-item:hover {
            transform: translateY(-6px) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
            border-color: rgba(197, 165, 114, 0.3);
            box-shadow: 0 12px 32px rgba(197, 165, 114, 0.15);
            background: linear-gradient(135deg, rgba(26, 42, 58, 0.95) 0%, rgba(26, 42, 58, 0.7) 100%);
        }

        .menu-item:hover::after {
            opacity: 1;
        }

        .menu-item img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
            background: linear-gradient(100deg, rgba(255, 255, 255, 0.04) 25%, rgba(255, 255, 255, 0.14) 37%, rgba(255, 255, 255, 0.04) 63%);
            background-size: 200% 100%;
            animation: imageShimmer 1.1s linear infinite;
            opacity: 0.86;
            transition: opacity 0.4s ease;
        }

        .menu-item img.loaded {
            animation: none;
            opacity: 1;
            background: transparent;
        }

        @keyframes imageShimmer {
            from { background-position: 200% 0; }
            to { background-position: -200% 0; }
        }

        .menu-item > * {
            padding: 0 16px;
        }

        .menu-item h3 {
            font-weight: 600;
            margin: 16px 16px 8px 16px;
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .featured-tag {
            display: inline-block;
            margin-left: 16px;
            margin-top: 4px;
            margin-bottom: 6px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            letter-spacing: 0.5px;
            font-weight: 700;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.14);
            color: #ffe0ac;
        }

        .menu-item .category-badge {
            display: inline-block;
            background: rgba(197, 165, 114, 0.2);
            color: var(--accent-gold);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 16px;
            margin-bottom: 8px;
        }

        .stock-badge {
            display: inline-block;
            margin-left: 16px;
            margin-bottom: 8px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .stock-ok {
            background: rgba(93, 175, 120, 0.22);
            color: #98e3b0;
        }

        .stock-low {
            background: rgba(255, 193, 7, 0.22);
            color: #ffdd88;
        }

        .stock-out {
            background: rgba(220, 106, 100, 0.24);
            color: #ff9d96;
        }

        .menu-item p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin: 8px 16px 16px 16px;
            flex-grow: 1;
            line-height: 1.5;
        }

        .menu-item-footer {
            padding: 12px 16px !important;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .menu-price {
            font-family: "Montserrat", sans-serif;
            font-weight: 700;
            color: var(--accent-gold);
            font-size: 1.1rem;
        }

        .add-btn {
            background: var(--accent-gold);
            color: var(--dark-bg);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .add-btn:hover {
            background: var(--accent-light);
            transform: scale(1.05);
        }

        .add-btn:disabled {
            background: #7f7f7f;
            color: #d7d7d7;
            cursor: not-allowed;
            transform: none;
        }

        /* Sidebar/Cart */
        .order-sidebar {
            background: linear-gradient(135deg, rgba(26, 42, 58, 0.9) 0%, rgba(26, 42, 58, 0.7) 100%);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            height: fit-content;
            position: sticky;
            top: 90px;
        }

        .order-sidebar h2 {
            font-family: "Cormorant Garamond", serif;
            font-size: 1.5rem;
            color: var(--accent-gold);
            margin: 0 0 20px 0;
        }

        .customer-form {
            margin-bottom: 20px;
        }

        .customer-form label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .customer-form input, .customer-form textarea, .customer-form select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            background: rgba(13, 27, 42, 0.6);
            color: var(--text-light);
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 12px;
            font-family: inherit;
        }

        .customer-form input::placeholder, .customer-form textarea::placeholder {
            color: var(--text-muted);
        }

        /* Cart Items */
        .cart-items {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            max-height: 240px;
            overflow-y: auto;
            margin-bottom: 16px;
        }

        .cart-empty {
            padding: 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .cart-item {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .item-qty {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .item-price {
            color: var(--accent-gold);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .remove-btn {
            background: rgba(197, 165, 114, 0.2);
            color: var(--accent-gold);
            border: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .remove-btn:hover {
            background: var(--accent-gold);
            color: var(--dark-bg);
        }

        /* Order Summary */
        .order-summary {
            background: rgba(197, 165, 114, 0.08);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .summary-row.total {
            border-top: 1px solid var(--border-color);
            padding-top: 10px;
            margin-bottom: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--accent-gold);
        }

        .success-modal {
            position: fixed;
            inset: 0;
            background: rgba(4, 9, 14, 0.78);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 16px;
        }

        .success-modal.active {
            display: flex;
        }

        .success-card {
            width: min(520px, 100%);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: linear-gradient(145deg, rgba(26, 42, 58, 0.96) 0%, rgba(16, 31, 45, 0.96) 100%);
            padding: 20px;
            box-shadow: 0 14px 36px rgba(0, 0, 0, 0.38);
        }

        .success-card h3 {
            margin: 0 0 8px;
            color: var(--accent-gold);
            font-family: "Cormorant Garamond", serif;
            font-size: 2rem;
        }

        .order-code {
            display: inline-block;
            margin: 10px 0 14px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            padding: 7px 12px;
            letter-spacing: 0.8px;
            color: var(--accent-light);
            font-size: 0.88rem;
            font-weight: 700;
        }

        .success-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .success-actions a,
        .success-actions button {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 0.86rem;
            color: var(--text-light);
            background: rgba(197, 165, 114, 0.12);
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .map-wrap {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 12px;
            background: rgba(13, 27, 42, 0.55);
        }

        #deliveryMap {
            height: 220px;
            width: 100%;
        }

        .route-meta {
            padding: 8px 10px;
            border-top: 1px solid var(--border-color);
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .leaflet-container {
            background: #0e1b2b;
            color: #1b2a3a;
        }

        /* Buttons */
        .cart-buttons {
            display: grid;
            gap: 10px;
        }

        .checkout-btn, .clear-btn {
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .checkout-btn {
            background: var(--accent-gold);
            color: var(--dark-bg);
        }

        .checkout-btn:hover {
            background: var(--accent-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(197, 165, 114, 0.3);
        }

        .clear-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .clear-btn:hover {
            background: rgba(197, 165, 114, 0.1);
            border-color: var(--accent-gold);
        }

        /* Messages */
        .message-box {
            padding: 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
            border: 1px solid;
        }

        .message-box.success {
            background: rgba(93, 175, 120, 0.15);
            border-color: rgba(93, 175, 120, 0.3);
            color: #5daf78;
            display: block;
        }

        .message-box.error {
            background: rgba(220, 106, 100, 0.15);
            border-color: rgba(220, 106, 100, 0.3);
            color: #ff8a80;
            display: block;
        }

        .no-results {
            grid-column: 1/-1;
            text-align: center;
            color: var(--text-muted);
            border: 1px dashed var(--border-color);
            border-radius: 10px;
            padding: 28px 16px;
        }

        .testimonials {
            max-width: 1400px;
            margin: 0 auto;
            padding: 10px 30px 0;
        }

        .testimonials h2 {
            font-family: "Cormorant Garamond", serif;
            color: var(--accent-gold);
            font-size: 2rem;
            margin: 0 0 14px;
        }

        .testimonial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .testimonial-card {
            background: linear-gradient(135deg, rgba(26, 42, 58, 0.82) 0%, rgba(26, 42, 58, 0.62) 100%);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px;
        }

        .testimonial-card .stars {
            color: #ffd483;
            font-size: 0.86rem;
            margin-bottom: 8px;
            letter-spacing: 1.2px;
        }

        .testimonial-card p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .testimonial-card strong {
            display: block;
            margin-top: 10px;
            color: var(--text-light);
            font-size: 0.82rem;
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, rgba(13, 27, 42, 0.9) 0%, rgba(10, 18, 24, 0.9) 100%);
            border-top: 1px solid var(--border-color);
            padding: 40px 30px;
            margin-top: 60px;
            color: var(--text-muted);
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }

        .footer-section h3 {
            color: var(--accent-gold);
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 0.95rem;
        }

        .footer-section p {
            font-size: 0.85rem;
            line-height: 1.6;
            margin: 0;
        }

        /* Chatbot */
        zapier-interfaces-chatbot-embed[is-popup="true"] {
            position: fixed !important;
            right: 20px !important;
            bottom: 20px !important;
            z-index: 2147483000 !important;
            display: block !important;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .menu-section {
                grid-template-columns: 1fr;
            }

            .order-sidebar {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.4rem;
            }

            .header-content {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }

            .menu-grid {
                grid-template-columns: 1fr;
            }

            .menu-section {
                padding: 30px 16px;
                gap: 24px;
            }

            .testimonials {
                padding: 0 16px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation: none !important;
                transition: none !important;
            }

            [data-animate] {
                opacity: 1;
                transform: none;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <a href="cafe.php" class="logo">Klint's <span>Cafe</span></a>
            <div class="header-actions">
                <?php if ($isStoreManager): ?>
                    <a href="admin_dashboard.php">Manage Store</a>
                <?php endif; ?>
                <a href="track_order.php">Track Order</a>
                <?php if (!empty($_SESSION['username'])): ?>
                    <a href="my_orders.php">My Orders</a>
                    <form action="lagout.php" method="POST" style="margin: 0;">
                        <button type="submit">Logout</button>
                    </form>
                <?php else: ?>
                    <a href="lagin.html">Staff Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="cafe-container">
        <section class="hero">
            <h1 data-animate>Klint's Cafe</h1>
            <p data-animate style="--delay: 80ms;">Premium coffee and light meals crafted with care. Order fresh, enjoy every moment.</p>
            <div class="hero-actions" data-animate style="--delay: 140ms;">
                <a class="hero-btn primary" href="#menu-items">Order Now</a>
                <?php if (!empty($_SESSION['username'])): ?>
                    <a class="hero-btn" href="my_orders.php">Track My Orders</a>
                <?php else: ?>
                    <a class="hero-btn" href="track_order.php">Track Order</a>
                <?php endif; ?>
                <a class="hero-btn" href="#contact-info">Visit Us</a>
            </div>
            <div class="info-strip" data-animate style="--delay: 200ms;">
                <div class="info-chip"><strong>Open Today</strong>8:00 AM - 10:00 PM</div>
                <div class="info-chip"><strong>Pickup ETA</strong>15-25 minutes</div>
                <div class="info-chip"><strong>Contact</strong>+63 993 425 5910</div>
                <div class="info-chip"><strong>Location</strong>Cabadug, Loon, Bohol</div>
            </div>
        </section>

        <section class="stats-section">
            <div class="stats-grid">
                <article class="stat-card" data-animate>
                    <span class="stat-value" data-counter="<?php echo $menuItemCount; ?>">0</span>
                    <span class="stat-label">Menu Items</span>
                </article>
                <article class="stat-card" data-animate style="--delay: 70ms;">
                    <span class="stat-value" data-counter="<?php echo $categoryCount; ?>">0</span>
                    <span class="stat-label">Categories</span>
                </article>
                <article class="stat-card" data-animate style="--delay: 140ms;">
                    <span class="stat-value" data-counter="<?php echo $totalStockCount; ?>">0</span>
                    <span class="stat-label">Items In Stock</span>
                </article>
                <article class="stat-card" data-animate style="--delay: 210ms;">
                    <span class="stat-value" data-counter="<?php echo $lowStockCount; ?>">0</span>
                    <span class="stat-label">Low Stock Alerts</span>
                </article>
            </div>
        </section>

        <div class="menu-section">
            <div>
                <div class="menu-header" id="menu-items">
                    <h2>Our Menu</h2>
                    <p>Handpicked selection of quality beverages and delicious treats</p>
                    <div class="menu-toolbar">
                        <div class="category-filters" id="categoryFilters">
                            <button class="category-chip active" data-filter="all" type="button">All</button>
                            <button class="category-chip" data-filter="coffee" type="button">Coffee</button>
                            <button class="category-chip" data-filter="non-coffee" type="button">Non-Coffee</button>
                            <button class="category-chip" data-filter="food" type="button">Food</button>
                            <button class="category-chip" data-filter="pastry" type="button">Pastry</button>
                        </div>
                        <input type="text" id="menuSearch" class="menu-search" placeholder="Search menu...">
                    </div>
                </div>

                <div class="menu-grid" id="menuGrid">
                    <?php if (count($products) === 0): ?>
                        <p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 40px 20px;">No menu items available right now. Please check back later.</p>
                    <?php else: ?>
                        <?php $menuIndex = 0; ?>
                        <?php foreach ($products as $product): ?>
                            <?php $menuIndex++; ?>
                            <div class="menu-item" data-animate style="--delay: <?php echo ($menuIndex % 6) * 60; ?>ms;" data-category="<?php echo htmlspecialchars(strtolower((string)($product['category'] ?? 'coffee'))); ?>" data-name="<?php echo htmlspecialchars(strtolower((string)$product['name'])); ?>">
                                <?php $stockQty = max(0, (int)($product['stock_quantity'] ?? 0)); ?>
                                <img
                                    src="<?php echo htmlspecialchars($product['image_url'] ?: 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=400&h=300&fit=crop'); ?>"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>"
                                    onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1509440159596-0249088772ff?w=400&h=300&fit=crop';"
                                />
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <?php if ($menuIndex <= 3): ?>
                                    <span class="featured-tag">Best Seller</span>
                                <?php endif; ?>
                                <span class="category-badge"><?php echo htmlspecialchars((string)($product['category'] ?? 'coffee')); ?></span>
                                <?php if ($stockQty <= 0): ?>
                                    <span class="stock-badge stock-out">Out of stock</span>
                                <?php elseif ($stockQty <= 5): ?>
                                    <span class="stock-badge stock-low">Low stock: <?php echo $stockQty; ?></span>
                                <?php else: ?>
                                    <span class="stock-badge stock-ok">Stock: <?php echo $stockQty; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($product['description'])): ?>
                                    <p><?php echo htmlspecialchars($product['description']); ?></p>
                                <?php endif; ?>
                                <div class="menu-item-footer">
                                    <span class="menu-price">₱<?php echo number_format((float)$product['price'], 2); ?></span>
                                    <button class="add-btn" <?php echo $stockQty <= 0 ? 'disabled' : ''; ?> onclick="addToCart(<?php echo (int)$product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>', <?php echo (float)$product['price']; ?>)"><?php echo $stockQty <= 0 ? 'Unavailable' : 'Add'; ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="order-sidebar">
                <h2>Your Order</h2>
                <div id="messageBox" class="message-box"></div>

                <div class="customer-form">
                    <label for="customerName">Your Name*</label>
                    <input type="text" id="customerName" placeholder="John Doe" value="<?php echo $displayName; ?>" />

                    <label for="customerPhone">Phone</label>
                    <input type="text" id="customerPhone" placeholder="09xx xxx xxxx" />

                    <label for="orderType">Order Type</label>
                    <select id="orderType">
                        <option value="pickup">Pickup</option>
                        <option value="delivery">Delivery</option>
                    </select>

                    <div id="deliveryFields" style="display:none;">
                        <label for="deliveryAddress">Delivery Address*</label>
                        <textarea id="deliveryAddress" rows="2" placeholder="House no., street, barangay"></textarea>
                        <label for="deliverySearch">Pin Location (Map Search)</label>
                        <input type="text" id="deliverySearch" list="deliverySuggestions" placeholder="Search place or landmark" />
                        <datalist id="deliverySuggestions"></datalist>
                        <div class="map-wrap">
                            <div id="deliveryMap"></div>
                            <div class="route-meta" id="routeMeta">Pin your location to calculate route, ETA, and fee.</div>
                        </div>
                    </div>

                    <label for="paymentMethod">Payment Method</label>
                    <select id="paymentMethod">
                        <option value="cod">Cash on Delivery</option>
                        <option value="cash">Pay at Counter</option>
                        <option value="gcash">GCash</option>
                        <option value="maya">Maya</option>
                        <option value="card">Card</option>
                    </select>

                    <label for="customerNote">Special Notes</label>
                    <textarea id="customerNote" rows="2" placeholder="Any special requests?"></textarea>
                </div>

                <div class="cart-items" id="cartItems">
                    <div class="cart-empty">Cart is empty</div>
                </div>

                <div class="order-summary">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span>₱<span id="subtotal">0.00</span></span>
                    </div>
                    <div class="summary-row">
                        <span>Tax (10%):</span>
                        <span>₱<span id="tax">0.00</span></span>
                    </div>
                    <div class="summary-row">
                        <span>Delivery Fee:</span>
                        <span>₱<span id="deliveryFee">0.00</span></span>
                    </div>
                    <div class="summary-row">
                        <span>Service Fee:</span>
                        <span>₱<span id="serviceFee">0.00</span></span>
                    </div>
                    <div class="summary-row total">
                        <span>Total:</span>
                        <span>₱<span id="total">0.00</span></span>
                    </div>
                </div>

                <div class="cart-buttons">
                    <button class="checkout-btn" onclick="checkout()">Place Order</button>
                    <button class="clear-btn" onclick="clearCart()">Clear Cart</button>
                </div>
            </aside>
        </div>

        <section class="testimonials">
            <h2 data-animate>What Guests Say</h2>
            <div class="testimonial-grid">
                <article class="testimonial-card" data-animate>
                    <div class="stars">★★★★★</div>
                    <p>The latte quality is super consistent and the pickup is always fast. Perfect before work.</p>
                    <strong>- Mae, Regular Customer</strong>
                </article>
                <article class="testimonial-card" data-animate style="--delay: 70ms;">
                    <div class="stars">★★★★★</div>
                    <p>Cozy vibe, good coffee, and the pastry selection keeps getting better every month.</p>
                    <strong>- Carlo, Student</strong>
                </article>
                <article class="testimonial-card" data-animate style="--delay: 140ms;">
                    <div class="stars">★★★★★</div>
                    <p>I like that the menu is clear and ordering online is straightforward. Very convenient.</p>
                    <strong>- Anne, Office Staff</strong>
                </article>
            </div>
        </section>
    </div>

    <footer id="contact-info">
        <div class="footer-content">
            <div class="footer-section">
                <h3>Klint's Cafe</h3>
                <p>Serving premium coffee and delicious café-style meals with care and passion.</p>
            </div>
            <div class="footer-section">
                <h3>Hours</h3>
                <p>Monday - Friday: 8:00 AM - 9:00 PM<br>Saturday - Sunday: 8:00 AM - 10:00 PM</p>
            </div>
            <div class="footer-section">
                <h3>Contact</h3>
                <p>Phone: +63 993 425 5910<br>Email: klintpaul@bayarcalscafe.com<br>Address: Purok 1, Cabadug, Loon, Bohol</p>
            </div>
        </div>
    </footer>

    <div id="orderSuccessModal" class="success-modal">
        <div class="success-card">
            <h3>Order Confirmed</h3>
            <p id="successMessage" style="margin:0; color: var(--text-muted);"></p>
            <div id="successOrderCode" class="order-code"></div>
            <div id="successEta" style="color: var(--accent-light); font-size: 0.9rem;"></div>
            <div class="success-actions">
                <a id="receiptLink" href="#">Open Receipt</a>
                <a id="trackLink" href="track_order.php">Track Order</a>
                <?php if (isset($_SESSION['id'])): ?><a href="my_orders.php">My Orders / Reviews</a><?php endif; ?>
                <button type="button" onclick="closeOrderSuccessModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let deliveryMap = null;
        let deliveryMarker = null;
        let routeLine = null;
        let selectedDelivery = {
            lat: 0,
            lng: 0,
            distanceKm: 0,
            durationMin: 0,
            zone: 'near',
            fee: 0
        };
        const storeLocation = { lat: 9.7909, lng: 123.7936 };
        const productStockMap = {
            <?php foreach ($products as $product): ?>
                <?php echo (int)$product['id']; ?>: <?php echo max(0, (int)($product['stock_quantity'] ?? 0)); ?>,
            <?php endforeach; ?>
        };

        function showMessage(type, text, isHtml = false) {
            const box = document.getElementById('messageBox');
            box.className = 'message-box ' + type;
            if (isHtml) {
                box.innerHTML = text;
            } else {
                box.textContent = text;
            }
        }

        function addToCart(productId, itemName, price) {
            const maxStock = Number(productStockMap[productId] || 0);
            if (maxStock <= 0) {
                showMessage('error', itemName + ' is out of stock.');
                return;
            }

            const existingItem = cart.find(item => item.product_id === productId);
            if (existingItem) {
                if (existingItem.quantity >= maxStock) {
                    showMessage('error', itemName + ' stock limit reached (' + maxStock + ').');
                    return;
                }
                existingItem.quantity += 1;
            } else {
                cart.push({ product_id: productId, name: itemName, price: price, quantity: 1 });
            }
            updateCart();
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCart();
        }

        function updateCart() {
            const cartItemsDiv = document.getElementById('cartItems');
            if (cart.length === 0) {
                cartItemsDiv.innerHTML = '<div class="cart-empty">Cart is empty</div>';
            } else {
                cartItemsDiv.innerHTML = cart.map((item, index) => `
                    <div class="cart-item">
                        <div class="item-info">
                            <div class="item-name">${item.name}</div>
                            <div class="item-qty">x${item.quantity}</div>
                        </div>
                        <div class="item-price">
                            ₱${(item.price * item.quantity).toFixed(2)}
                            <button class="remove-btn" onclick="removeFromCart(${index})">×</button>
                        </div>
                    </div>
                `).join('');
            }
            updateTotals();
        }

        function updateTotals() {
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = subtotal * 0.10;
            const orderType = (document.getElementById('orderType')?.value || 'pickup');
            const deliveryFee = orderType === 'delivery' ? Number(selectedDelivery.fee || 0) : 0;
            const serviceFee = orderType === 'delivery' ? 8 : 0;
            const total = subtotal + tax + deliveryFee + serviceFee;

            document.getElementById('subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('tax').textContent = tax.toFixed(2);
            document.getElementById('deliveryFee').textContent = deliveryFee.toFixed(2);
            document.getElementById('serviceFee').textContent = serviceFee.toFixed(2);
            document.getElementById('total').textContent = total.toFixed(2);
        }

        function clearCart() {
            cart = [];
            updateCart();
        }

        function setupOrderTypeFields() {
            const orderTypeEl = document.getElementById('orderType');
            const deliveryFields = document.getElementById('deliveryFields');
            if (!orderTypeEl || !deliveryFields) {
                return;
            }

            const apply = () => {
                const isDelivery = orderTypeEl.value === 'delivery';
                deliveryFields.style.display = isDelivery ? '' : 'none';
                if (isDelivery && !deliveryMap) {
                    setupDeliveryMap();
                }
                updateTotals();
            };

            orderTypeEl.addEventListener('change', apply);
            apply();
        }

        function resolveZoneByDistance(distanceKm) {
            if (distanceKm <= 3) {
                return { zone: 'near', fee: 39 };
            }
            if (distanceKm <= 7) {
                return { zone: 'mid', fee: 59 };
            }
            if (distanceKm <= 12) {
                return { zone: 'far', fee: 89 };
            }
            return { zone: 'extended', fee: Math.round((119 + Math.max(0, (distanceKm - 12) * 5)) * 100) / 100 };
        }

        async function fetchRouteToDelivery(lat, lng) {
            const routeMeta = document.getElementById('routeMeta');
            try {
                const url = `https://router.project-osrm.org/route/v1/driving/${storeLocation.lng},${storeLocation.lat};${lng},${lat}?overview=full&geometries=geojson`;
                const response = await fetch(url);
                const data = await response.json();
                if (!data.routes || !data.routes[0]) {
                    throw new Error('Route unavailable');
                }

                const route = data.routes[0];
                const distanceKm = Number((route.distance / 1000).toFixed(2));
                const durationMin = Math.max(1, Math.round(route.duration / 60));
                const zoneData = resolveZoneByDistance(distanceKm);

                selectedDelivery = {
                    lat,
                    lng,
                    distanceKm,
                    durationMin,
                    zone: zoneData.zone,
                    fee: zoneData.fee
                };

                if (routeLine) {
                    deliveryMap.removeLayer(routeLine);
                }

                routeLine = L.geoJSON(route.geometry, {
                    style: { color: '#d3b17f', weight: 4, opacity: 0.9 }
                }).addTo(deliveryMap);

                deliveryMap.fitBounds(routeLine.getBounds(), { padding: [24, 24] });

                if (routeMeta) {
                    routeMeta.textContent = `Distance: ${distanceKm} km | ETA: ${durationMin} mins | Zone: ${zoneData.zone.toUpperCase()} | Delivery Fee: PHP ${zoneData.fee.toFixed(2)}`;
                }

                updateTotals();
            } catch (error) {
                if (routeMeta) {
                    routeMeta.textContent = 'Unable to calculate route right now. You can still continue with delivery details.';
                }
            }
        }

        async function geocodeAndPin(query) {
            if (!query) {
                return;
            }

            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`);
            const data = await response.json();
            if (!Array.isArray(data) || data.length === 0) {
                return;
            }

            const pick = data[0];
            const lat = Number(pick.lat);
            const lng = Number(pick.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }

            if (!deliveryMarker) {
                deliveryMarker = L.marker([lat, lng], { draggable: true }).addTo(deliveryMap);
                deliveryMarker.on('dragend', () => {
                    const pos = deliveryMarker.getLatLng();
                    fetchRouteToDelivery(pos.lat, pos.lng);
                });
            } else {
                deliveryMarker.setLatLng([lat, lng]);
            }

            fetchRouteToDelivery(lat, lng);
        }

        function setupDeliveryMap() {
            const mapEl = document.getElementById('deliveryMap');
            if (!mapEl || deliveryMap || typeof L === 'undefined') {
                return;
            }

            deliveryMap = L.map(mapEl).setView([storeLocation.lat, storeLocation.lng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(deliveryMap);

            L.marker([storeLocation.lat, storeLocation.lng]).addTo(deliveryMap).bindPopup('Klint\'s Cafe');

            const searchInput = document.getElementById('deliverySearch');
            const datalist = document.getElementById('deliverySuggestions');
            let searchTimer = null;

            if (searchInput && datalist) {
                searchInput.addEventListener('input', () => {
                    clearTimeout(searchTimer);
                    const term = searchInput.value.trim();
                    if (term.length < 3) {
                        datalist.innerHTML = '';
                        return;
                    }

                    searchTimer = setTimeout(async () => {
                        try {
                            const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(term)}&limit=5`);
                            const rows = await res.json();
                            datalist.innerHTML = '';
                            (rows || []).forEach((row) => {
                                const option = document.createElement('option');
                                option.value = row.display_name;
                                datalist.appendChild(option);
                            });
                        } catch (error) {
                            datalist.innerHTML = '';
                        }
                    }, 350);
                });

                searchInput.addEventListener('change', () => {
                    geocodeAndPin(searchInput.value.trim());
                });
            }
        }

        function closeOrderSuccessModal() {
            const modal = document.getElementById('orderSuccessModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function showOrderSuccessModal(payload) {
            const modal = document.getElementById('orderSuccessModal');
            if (!modal) {
                return;
            }

            const receiptUrl = `order_receipt.php?order_id=${encodeURIComponent(payload.order_id)}&copy=customer`;
            const message = document.getElementById('successMessage');
            const code = document.getElementById('successOrderCode');
            const eta = document.getElementById('successEta');
            const receiptLink = document.getElementById('receiptLink');
            const trackLink = document.getElementById('trackLink');
            const paymentMethod = (document.getElementById('paymentMethod')?.value || 'cod');

            if (message) {
                message.textContent = `Order #${payload.order_id} was placed successfully. Total: ₱${Number(payload.total).toFixed(2)}`;
            }
            if (code) {
                code.textContent = `Tracking Code: ${payload.order_code || ('KC-' + String(payload.order_id).padStart(6, '0'))}`;
            }
            if (eta) {
                eta.textContent = payload.estimated_minutes ? `Estimated time: ${payload.estimated_minutes} minutes` : '';
            }
            if (receiptLink) {
                receiptLink.href = receiptUrl;
            }
            if (trackLink) {
                const identity = encodeURIComponent(document.getElementById('customerPhone').value.trim() || document.getElementById('customerName').value.trim());
                trackLink.href = `track_order.php?order_id=${encodeURIComponent(payload.order_id)}&identity=${identity}&search=1`;
            }

            if (['gcash', 'maya', 'card'].includes(paymentMethod)) {
                fetch('initiate_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: payload.order_id, gateway: paymentMethod })
                })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success || !data.checkout_url || !message) {
                        return;
                    }
                    message.innerHTML = `${message.textContent}<br><a href="${data.checkout_url}" target="_blank" style="color:#ffe2ac;">Proceed to ${String(paymentMethod).toUpperCase()} checkout</a>`;
                })
                .catch(() => {
                    // If gateway stub is unavailable, customer can still use receipt/tracking.
                });
            }

            modal.classList.add('active');
        }

        function setupMenuFilter() {
            const chips = Array.from(document.querySelectorAll('.category-chip'));
            const searchInput = document.getElementById('menuSearch');
            const menuGrid = document.getElementById('menuGrid');
            const cards = Array.from(document.querySelectorAll('.menu-item'));
            let activeFilter = 'all';

            const applyFilter = () => {
                const term = (searchInput?.value || '').trim().toLowerCase();
                let visibleCount = 0;

                cards.forEach((card) => {
                    const category = card.getAttribute('data-category') || '';
                    const name = card.getAttribute('data-name') || '';
                    const matchCategory = activeFilter === 'all' || category === activeFilter;
                    const matchSearch = term === '' || name.includes(term);
                    const show = matchCategory && matchSearch;
                    card.style.display = show ? '' : 'none';
                    if (show) {
                        visibleCount += 1;
                    }
                });

                let noResults = document.getElementById('noResultsCard');
                if (visibleCount === 0) {
                    if (!noResults) {
                        noResults = document.createElement('div');
                        noResults.id = 'noResultsCard';
                        noResults.className = 'no-results';
                        noResults.textContent = 'No menu items matched your filter/search.';
                        menuGrid.appendChild(noResults);
                    }
                } else if (noResults) {
                    noResults.remove();
                }
            };

            chips.forEach((chip) => {
                chip.addEventListener('click', () => {
                    chips.forEach((c) => c.classList.remove('active'));
                    chip.classList.add('active');
                    activeFilter = chip.getAttribute('data-filter') || 'all';
                    applyFilter();
                });
            });

            if (searchInput) {
                searchInput.addEventListener('input', applyFilter);
            }

            applyFilter();
        }

        function setupScrollReveal() {
            const animatedElements = Array.from(document.querySelectorAll('[data-animate]'));
            if (animatedElements.length === 0) {
                return;
            }

            const observer = new IntersectionObserver((entries, currentObserver) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    entry.target.classList.add('is-visible');
                    currentObserver.unobserve(entry.target);
                });
            }, { threshold: 0.12 });

            animatedElements.forEach((element) => observer.observe(element));
        }

        function setupCounters() {
            const counters = Array.from(document.querySelectorAll('[data-counter]'));
            if (counters.length === 0) {
                return;
            }

            const animateCounter = (counter) => {
                const target = Number(counter.getAttribute('data-counter') || 0);
                const duration = 1200;
                const startTime = performance.now();

                const update = (currentTime) => {
                    const progress = Math.min((currentTime - startTime) / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    counter.textContent = Math.round(target * eased).toLocaleString();
                    if (progress < 1) {
                        requestAnimationFrame(update);
                    }
                };

                requestAnimationFrame(update);
            };

            const counterObserver = new IntersectionObserver((entries, currentObserver) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    animateCounter(entry.target);
                    currentObserver.unobserve(entry.target);
                });
            }, { threshold: 0.45 });

            counters.forEach((counter) => counterObserver.observe(counter));
        }

        function setupImageLoadingFx() {
            const menuImages = Array.from(document.querySelectorAll('.menu-item img'));
            menuImages.forEach((image) => {
                const markLoaded = () => image.classList.add('loaded');
                if (image.complete && image.naturalWidth > 0) {
                    markLoaded();
                } else {
                    image.addEventListener('load', markLoaded, { once: true });
                    image.addEventListener('error', markLoaded, { once: true });
                }
            });
        }

        function setupCardParallax() {
            const cards = Array.from(document.querySelectorAll('.menu-item'));
            cards.forEach((card) => {
                card.addEventListener('mousemove', (event) => {
                    const rect = card.getBoundingClientRect();
                    const x = event.clientX - rect.left;
                    const y = event.clientY - rect.top;
                    const px = x / rect.width;
                    const py = y / rect.height;
                    const rotateY = (px - 0.5) * 7;
                    const rotateX = (0.5 - py) * 7;

                    card.style.setProperty('--mx', `${Math.round(px * 100)}%`);
                    card.style.setProperty('--my', `${Math.round(py * 100)}%`);
                    card.style.setProperty('--ry', `${rotateY.toFixed(2)}deg`);
                    card.style.setProperty('--rx', `${rotateX.toFixed(2)}deg`);
                });

                card.addEventListener('mouseleave', () => {
                    card.style.setProperty('--ry', '0deg');
                    card.style.setProperty('--rx', '0deg');
                    card.style.setProperty('--mx', '50%');
                    card.style.setProperty('--my', '50%');
                });
            });
        }

        async function checkout() {
            const customerName = document.getElementById('customerName').value.trim();
            const customerPhone = document.getElementById('customerPhone').value.trim();
            const customerNote = document.getElementById('customerNote').value.trim();
            const orderType = (document.getElementById('orderType')?.value || 'pickup');
            const deliveryAddress = document.getElementById('deliveryAddress')?.value.trim() || '';
            const paymentMethod = (document.getElementById('paymentMethod')?.value || 'cod');
            const serviceFee = orderType === 'delivery' ? 8 : 0;
            const estimatedMinutes = orderType === 'delivery' ? Math.max(20, Number(selectedDelivery.durationMin || 45)) : 20;

            if (cart.length === 0) {
                showMessage('error', 'Cart is empty. Add items first.');
                return;
            }

            if (!customerName) {
                showMessage('error', 'Customer name is required.');
                return;
            }

            if (orderType === 'delivery' && !deliveryAddress) {
                showMessage('error', 'Delivery address is required for delivery orders.');
                return;
            }

            if (orderType === 'delivery' && (!selectedDelivery.lat || !selectedDelivery.lng)) {
                showMessage('error', 'Please pin your delivery location on the map.');
                return;
            }

            try {
                const response = await fetch('place_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        customer_name: customerName,
                        customer_phone: customerPhone,
                        order_type: orderType,
                        payment_method: paymentMethod,
                        delivery_address: deliveryAddress,
                        delivery_lat: Number(selectedDelivery.lat || 0),
                        delivery_lng: Number(selectedDelivery.lng || 0),
                        distance_km: Number(selectedDelivery.distanceKm || 0),
                        delivery_zone: selectedDelivery.zone || 'near',
                        delivery_fee: orderType === 'delivery' ? Number(selectedDelivery.fee || 0) : 0,
                        service_fee: serviceFee,
                        estimated_minutes: estimatedMinutes,
                        note: customerNote,
                        items: cart.map((item) => ({ product_id: item.product_id, quantity: item.quantity }))
                    })
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    showMessage('error', data.message || 'Unable to place order.');
                    return;
                }

                showMessage('success', `Order #${data.order_id} placed successfully.`, false);
                clearCart();
                document.getElementById('customerName').value = '';
                document.getElementById('customerPhone').value = '';
                if (document.getElementById('deliveryAddress')) {
                    document.getElementById('deliveryAddress').value = '';
                }
                const deliverySearch = document.getElementById('deliverySearch');
                if (deliverySearch) {
                    deliverySearch.value = '';
                }
                if (deliveryMarker) {
                    deliveryMap.removeLayer(deliveryMarker);
                    deliveryMarker = null;
                }
                if (routeLine) {
                    deliveryMap.removeLayer(routeLine);
                    routeLine = null;
                }
                selectedDelivery = { lat: 0, lng: 0, distanceKm: 0, durationMin: 0, zone: 'near', fee: 0 };
                document.getElementById('customerNote').value = '';
                showOrderSuccessModal(data);
            } catch (error) {
                showMessage('error', 'Connection error while placing order.');
            }
        }

        setupMenuFilter();
        setupOrderTypeFields();
        setupScrollReveal();
        setupCounters();
        setupImageLoadingFx();
        setupCardParallax();
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script type="module" src="https://interfaces.zapier.com/assets/web-components/zapier-interfaces/zapier-interfaces.esm.js"></script>
    <zapier-interfaces-chatbot-embed is-popup="true" chatbot-id="cmmeirig0003in9qllddk3y97"></zapier-interfaces-chatbot-embed>
</body>
</html>
