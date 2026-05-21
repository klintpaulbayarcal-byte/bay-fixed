<?php
// Database setup script - creates the database and test user if they don't exist

require_once __DIR__ . '/auth_bootstrap.php';

try {
    $conn = get_auth_database_connection();
} catch (RuntimeException $exception) {
    die('Connection failed: ' . $exception->getMessage() . '<br>Make sure MySQL server is running!');
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "<br>Make sure MySQL server is running!");
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS web_system";
if ($conn->query($sql) === TRUE) {
    echo "Database created or already exists<br>";
} else {
    die("Error creating database: " . $conn->error);
}

// Select database
$conn->select_db("web_system");

// Create users table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0
)";

if ($conn->query($sql) === TRUE) {
    echo "Users table created or already exists<br>";
} else {
    die("Error creating table: " . $conn->error);
}

// Create products table for cafe menu management.
$sql = "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'coffee',
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    image_url VARCHAR(255) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Products table created or already exists<br>";
} else {
    die("Error creating products table: " . $conn->error);
}

// Add category column for older installations.
$categoryColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($categoryColumn && $categoryColumn->num_rows == 0) {
    if ($conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'coffee' AFTER name") === TRUE) {
        echo "Added category column to products table<br>";
    } else {
        die("Error adding category column: " . $conn->error);
    }
}

// Create orders table for customer checkout.
$sql = "CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    note VARCHAR(255) NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    tax DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Orders table created or already exists<br>";
} else {
    die("Error creating orders table: " . $conn->error);
}

// Create order_items table that stores line items per order.
$sql = "CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
)";

if ($conn->query($sql) === TRUE) {
    echo "Order items table created or already exists<br>";
} else {
    die("Error creating order_items table: " . $conn->error);
}

// Add force-change column for existing installations.
$columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
if ($columnCheck && $columnCheck->num_rows == 0) {
    if ($conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0") === TRUE) {
        echo "Added must_change_password column<br>";
    } else {
        die("Error adding must_change_password column: " . $conn->error);
    }
}

// Check if test user exists
$checkTest = $conn->prepare("SELECT id FROM users WHERE username = 'testuser'");
$checkTest->execute();
$checkTest->store_result();

// Add test user if it doesn't exist
if ($checkTest->num_rows == 0) {
    $hashedPassword = password_hash("password123", PASSWORD_DEFAULT);
    $insertStmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
    $insertStmt->bind_param("sssss", $fullname, $email, $username, $hashedPassword, $role);
    
    $fullname = "Test User";
    $email = "test@example.com";
    $username = "testuser";
    $role = "user";
    
    if ($insertStmt->execute()) {
        echo "Test user created successfully!<br>";
        echo "<strong>Test Credentials:</strong><br>";
        echo "Username: testuser<br>";
        echo "Password: password123<br>";
    } else {
        die("Error creating test user: " . $insertStmt->error);
    }
    $insertStmt->close();
} else {
    echo "Test user already exists<br>";
}

$checkTest->close();

// Add or fix admin user
$adminUsername = "jireh";
$adminPassword = "faith";
$adminRole = "admin";
$adminFullname = "Administrator";
$adminEmail = "admin@example.com";
$hashedAdminPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

$checkAdmin = $conn->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
$checkAdmin->bind_param("s", $adminUsername);
$checkAdmin->execute();
$checkAdmin->store_result();

if ($checkAdmin->num_rows == 0) {
    $legacyAdmin = $conn->prepare("SELECT id FROM users WHERE username = 'admin' AND role = 'admin' LIMIT 1");
    $legacyAdmin->execute();
    $legacyAdmin->store_result();

    if ($legacyAdmin->num_rows > 0) {
        $legacyAdmin->bind_result($legacyAdminId);
        $legacyAdmin->fetch();

        $updateAdmin = $conn->prepare("UPDATE users SET fullname = ?, email = ?, username = ?, password = ?, role = ? WHERE id = ?");
        $updateAdmin->bind_param("sssssi", $adminFullname, $adminEmail, $adminUsername, $hashedAdminPassword, $adminRole, $legacyAdminId);

        if ($updateAdmin->execute()) {
            echo "Admin user updated successfully!<br>";
            echo "<strong>Admin Credentials:</strong><br>";
            echo "Username: jireh<br>";
            echo "Password: faith<br>";
        }

        $updateAdmin->close();
    } else {
        $insertAdmin = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
        $insertAdmin->bind_param("sssss", $adminFullname, $adminEmail, $adminUsername, $hashedAdminPassword, $adminRole);

        if ($insertAdmin->execute()) {
            echo "Admin user created successfully!<br>";
            echo "<strong>Admin Credentials:</strong><br>";
            echo "Username: jireh<br>";
            echo "Password: faith<br>";
        }
        $insertAdmin->close();
    }

    $legacyAdmin->close();
} else {
    // admin already exists with the requested username
    echo "Admin user already exists<br>";
}

$checkAdmin->close();

// Seed default menu products if table is empty.
$countProducts = $conn->query("SELECT COUNT(*) AS total FROM products");
$productRow = $countProducts ? $countProducts->fetch_assoc() : ['total' => 0];

if ((int)($productRow['total'] ?? 0) === 0) {
    $seedProducts = [
        ["Espresso", "coffee", "Strong and bold single-shot espresso.", 120.00, "https://images.unsplash.com/photo-1510591509098-f4fdc6d0ff04?w=400&h=300&fit=crop"],
        ["Americano", "coffee", "Espresso topped with hot water.", 140.00, "https://assets.beanbox.com/blog_images/AB7ud4YSE6nmOX0iGlgA.jpeg"],
        ["Latte", "coffee", "Creamy milk coffee with smooth texture.", 160.00, "https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&h=300&fit=crop"],
        ["Cappuccino", "coffee", "Espresso with steamed milk and foam.", 165.00, "https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&h=300&fit=crop"],
        ["Mocha", "coffee", "Chocolate-infused espresso drink.", 175.00, "https://images.unsplash.com/photo-1578314675249-a6910f80cc4e?w=400&h=300&fit=crop"],
        ["Iced Tea Lemon", "non-coffee", "Refreshing brewed tea with lemon.", 110.00, "https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=400&h=300&fit=crop"],
        ["Chocolate Muffin", "pastry", "Freshly baked chocolate muffin.", 85.00, "https://images.unsplash.com/photo-1604882406195-d94d4f33b0a9?w=400&h=300&fit=crop"],
        ["Ham and Cheese Sandwich", "food", "Toasted sandwich with ham and cheese.", 180.00, "https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=400&h=300&fit=crop"]
    ];

    $insertProduct = $conn->prepare("INSERT INTO products (name, category, description, price, image_url, is_available) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($seedProducts as $product) {
        $productName = $product[0];
        $productCategory = $product[1];
        $productDescription = $product[2];
        $productPrice = $product[3];
        $productImage = $product[4];
        $insertProduct->bind_param("sssds", $productName, $productCategory, $productDescription, $productPrice, $productImage);
        $insertProduct->execute();
    }
    $insertProduct->close();
    echo "Default cafe menu items seeded<br>";
}

$conn->close();

echo "<br><strong>Setup completed! You can now <a href='lagin.html'>login here</a></strong>";
?>

<?php
// Ensure a default staff account exists (username: jai / password: 212121)
try {
    $c = get_auth_database_connection();
} catch (RuntimeException $exception) {
    $c = null;
}

if ($c && !$c->connect_error) {
    $checkStaff = $c->prepare("SELECT id FROM users WHERE username = 'jai'");
    $checkStaff->execute();
    $checkStaff->store_result();

    if ($checkStaff->num_rows == 0) {
        $hashed = password_hash("212121", PASSWORD_DEFAULT);
        $insert = $c->prepare("INSERT INTO users (fullname, email, username, password, role, must_change_password) VALUES (?, ?, ?, ?, 'staff', 0)");
        $fullname = "Jai";
        $email = "jai@example.com";
        $username = "jai";
        $insert->bind_param("ssss", $fullname, $email, $username, $hashed);
        $insert->execute();
        $insert->close();
        echo "<br>Default staff account created: Username: jai Password: 212121";
    } else {
        echo "<br>Staff user 'jai' already exists";
    }
    $checkStaff->close();
}
?>
