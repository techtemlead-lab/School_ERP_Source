<?php
// admin/canteen_common.php
// Canteen মডিউলের সবগুলো সাব-পেজ (Raw Materials, Purchase, Menu Items, POS,
// Due Collection, Stock Adjustment, Reports) এই ফাইলটা require_once করে —
// টেবিল তৈরি ও পারমিশন-চেক এক জায়গায়, বারবার লেখা লাগে না।
// প্যাটার্ন inventory_common.php-এর সাথে সংগতিপূর্ণ রাখা হয়েছে।

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_settings (
    id INT PRIMARY KEY,
    allow_due_sale TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT IGNORE INTO canteen_settings (id, allow_due_sale) VALUES (1, 1)");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT INTO canteen_units (name) SELECT * FROM (SELECT 'kg' AS n) t WHERE NOT EXISTS (SELECT 1 FROM canteen_units)");
$pdo->exec("INSERT INTO canteen_units (name) SELECT 'gram' WHERE (SELECT COUNT(*) FROM canteen_units) = 1");
$pdo->exec("INSERT INTO canteen_units (name) SELECT 'liter' WHERE (SELECT COUNT(*) FROM canteen_units) = 2");
$pdo->exec("INSERT INTO canteen_units (name) SELECT 'piece' WHERE (SELECT COUNT(*) FROM canteen_units) = 3");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_raw_materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    unit_id INT NULL,
    current_stock DECIMAL(10,3) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(10,3) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function canteen_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}
canteen_ensure_column($pdo, 'canteen_raw_materials', 'branch_id', 'INT NULL'); // প্রতিটা branch নিজের আলাদা raw material স্টক রাখবে

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(40) NOT NULL,
    supplier_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
canteen_ensure_column($pdo, 'canteen_purchases', 'branch_id', 'INT NULL');

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    raw_material_id INT NOT NULL,
    qty DECIMAL(10,3) NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    photo_path VARCHAR(255) NULL,
    description VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
canteen_ensure_column($pdo, 'canteen_menu_items', 'subcategory_id', 'INT NULL');
canteen_ensure_column($pdo, 'canteen_menu_items', 'unit_id',        'INT NULL');
canteen_ensure_column($pdo, 'canteen_menu_items', 'item_code',      'VARCHAR(50) NULL');
canteen_ensure_column($pdo, 'canteen_menu_items', 'branch_id',      'INT NULL');

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_recipe_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    raw_material_id INT NOT NULL,
    qty_required DECIMAL(10,3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// গুরুত্বপূর্ণ: canteen_sales/canteen_sale_items কখনো Fee/Finance Ledger-এর কোনো
// টেবিলে insert হয় না — Store Due-এর মতোই এটা সম্পূর্ণ আলাদা "Canteen Due" হিসেবে থাকে।
$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) NOT NULL,
    buyer_type VARCHAR(10) NOT NULL DEFAULT 'walkin',
    student_pk INT NULL,
    student_code VARCHAR(50) NULL,
    employee_pk INT NULL,
    employee_code VARCHAR(50) NULL,
    customer_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
canteen_ensure_column($pdo, 'canteen_sales', 'branch_id', 'INT NULL');

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS canteen_stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    raw_material_id INT NOT NULL,
    adj_type VARCHAR(20) NOT NULL,
    qty DECIMAL(10,3) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
canteen_ensure_column($pdo, 'canteen_stock_adjustments', 'branch_id', 'INT NULL');

/**
 * বর্তমান Canteen Settings রিড করার হেল্পার
 */
function canteen_get_settings(PDO $pdo): array
{
    $row = $pdo->query("SELECT * FROM canteen_settings WHERE id = 1")->fetch();
    return $row ?: ['allow_due_sale' => 1];
}

/**
 * পারমিশন: Setup পেজগুলো (Raw Materials, Menu/Recipe, Purchase, Stock
 * Adjustment) শুধু Super Admin বা 'Admin' role চালাতে পারবে। 'Canteen Staff'
 * role শুধু POS আর Due Collection চালাবে — ভুলে সরাসরি URL দিয়ে setup পেজে
 * ঢুকতে চাইলে POS-এ ফেরত পাঠিয়ে দেওয়া হবে।
 */
function canteen_can_manage(): bool
{
    $is_super_admin = $_SESSION['is_super_admin'] ?? true;
    $user_role      = $_SESSION['user_role'] ?? '';
    return $is_super_admin || $user_role === 'Admin';
}

function canteen_require_manage(): void
{
    if (!canteen_can_manage()) {
        $_SESSION['canteen_err'] = 'এই পেজে ঢোকার অনুমতি শুধু Super Admin/Admin-এর আছে।';
        header('Location: index.php?page=canteen_pos');
        exit();
    }
}

function cv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
