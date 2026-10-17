<?php
// admin/inventory_common.php
// Inventory & Sales মডিউলের সবগুলো সাব-পেজ (Items, Purchase, Sales, Due Collection,
// Supplier Payment, Stock Adjustment, Reports) এই ফাইলটা require_once করে —
// টেবিল/কলাম তৈরির কাজ এক জায়গায়, বারবার লেখা লাগে না।

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_settings (
    id INT PRIMARY KEY,
    allow_due_sale TINYINT(1) NOT NULL DEFAULT 1,
    send_sms_on_sale TINYINT(1) NOT NULL DEFAULT 1,
    logo_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT IGNORE INTO inv_settings (id, allow_due_sale, send_sms_on_sale) VALUES (1, 1, 1)");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_sale_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    return_no VARCHAR(40) NOT NULL,
    return_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_sale_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/**
 * বর্তমান Inventory & Sales Settings রিড করার হেল্পার — সব পেজ থেকে একইভাবে ব্যবহার হবে
 */
function inv_get_settings(PDO $pdo): array
{
    $row = $pdo->query("SELECT * FROM inv_settings WHERE id = 1")->fetch();
    return $row ?: ['allow_due_sale' => 1, 'send_sms_on_sale' => 1, 'logo_path' => null];
}

function inv_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// পুরনো inventory_items টেবিল (name, stock_qty) রেখেই নতুন কলাম যোগ করা হচ্ছে
$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    stock_qty INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
inv_ensure_column($pdo, 'inventory_items', 'category_id',    'INT NULL');
inv_ensure_column($pdo, 'inventory_items', 'subcategory_id', 'INT NULL');
inv_ensure_column($pdo, 'inventory_items', 'unit_id',        'INT NULL');
inv_ensure_column($pdo, 'inventory_items', 'item_code',      'VARCHAR(50) NULL');
inv_ensure_column($pdo, 'inventory_items', 'cost_price',     "DECIMAL(10,2) NOT NULL DEFAULT 0");
inv_ensure_column($pdo, 'inventory_items', 'sale_price',     "DECIMAL(10,2) NOT NULL DEFAULT 0");
inv_ensure_column($pdo, 'inventory_items', 'reorder_level',  "INT NOT NULL DEFAULT 0");
inv_ensure_column($pdo, 'inventory_items', 'status',         "VARCHAR(20) NOT NULL DEFAULT 'Active'");
inv_ensure_column($pdo, 'inventory_items', 'branch_id',      'INT NULL'); // প্রতিটা branch নিজের আলাদা Item/Stock রাখবে

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_no VARCHAR(40) NOT NULL,
    supplier_name VARCHAR(150) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_supplier_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// পুরনো sales_invoices টেবিল রেখেই নতুন কলাম (student লিংক, buyer_type, discount) যোগ করা হচ্ছে
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    invoice_no VARCHAR(40) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    due_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
inv_ensure_column($pdo, 'sales_invoices', 'buyer_type',      "VARCHAR(10) NOT NULL DEFAULT 'walkin'");
inv_ensure_column($pdo, 'sales_invoices', 'student_pk',      'INT NULL');
inv_ensure_column($pdo, 'sales_invoices', 'student_code',    'VARCHAR(50) NULL');
inv_ensure_column($pdo, 'sales_invoices', 'discount_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0");
inv_ensure_column($pdo, 'sales_invoices', 'branch_id',        'INT NULL');
inv_ensure_column($pdo, 'inv_purchases', 'branch_id',         'INT NULL');

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    item_id INT NOT NULL,
    qty INT NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS inv_stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    adj_type VARCHAR(20) NOT NULL,
    qty INT NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
inv_ensure_column($pdo, 'inv_stock_adjustments', 'branch_id', 'INT NULL');

inv_ensure_column($pdo, 'inv_sale_returns', 'refund_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0");

/**
 * Branch scope মেনে একটা রো লক করে (SELECT ... FOR UPDATE) আনে — transaction এর ভেতরে ডাকতে হবে।
 * অন্য branch-এর রো হলে false ফেরত দেয়। $table অবশ্যই কোডের ভেতরের হার্ডকোডেড নাম হবে (ইউজার ইনপুট নয়)।
 */
function inv_lock_row(PDO $pdo, string $table, int $id, string $cols = '*')
{
    $where = ['id = ?']; $params = [$id];
    branch_apply_filter($where, $params);
    $st = $pdo->prepare("SELECT $cols FROM `$table` WHERE " . implode(' AND ', $where) . " FOR UPDATE");
    $st->execute($params);
    return $st->fetch();
}

/** Branch scope মেনে (লক ছাড়া) একটা রো আনে। */
function inv_scoped_row(PDO $pdo, string $table, int $id, string $cols = '*')
{
    $where = ['id = ?']; $params = [$id];
    branch_apply_filter($where, $params);
    $st = $pdo->prepare("SELECT $cols FROM `$table` WHERE " . implode(' AND ', $where));
    $st->execute($params);
    return $st->fetch();
}

/** ইউনিক ডকুমেন্ট নম্বর (INV-/PUR-/RET-) — DB-তে আছে কিনা দেখে নেয়, ডুপ্লিকেট হয় না। */
function inv_unique_no(PDO $pdo, string $prefix, string $table, string $column): string
{
    $chk = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
    for ($try = 0; $try < 30; $try++) {
        $no = $prefix . '-' . date('Ymd') . '-' . random_int(100000, 999999);
        $chk->execute([$no]);
        if (!$chk->fetchColumn()) { return $no; }
    }
    return $prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
}

/** একই Item একাধিক লাইনে থাকলে qty যোগ করে একটা লাইন বানায়: [item_id => qty] */
function inv_merge_lines(array $item_ids, array $qtys): array
{
    $out = [];
    foreach ($item_ids as $i => $iid) {
        $iid = (int) $iid; $q = (int) ($qtys[$i] ?? 0);
        if ($iid > 0 && $q > 0) { $out[$iid] = ($out[$iid] ?? 0) + $q; }
    }
    return $out;
}
