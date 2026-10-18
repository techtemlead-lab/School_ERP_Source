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
    // fail-closed: session-এ key না থাকলে Super Admin ধরা হবে না (লগইনে দুই পথেই key সেট হয়)
    $is_super_admin = !empty($_SESSION['is_super_admin']);
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

/** POS / Due / Print / AJAX — Super Admin, Admin ও Canteen Staff */
function canteen_can_sell(): bool
{
    return !empty($_SESSION['is_super_admin']) || in_array($_SESSION['user_role'] ?? '', ['Admin', 'Canteen Staff'], true);
}

function cv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }


// ---------- নতুন কলাম / টেবিল ----------
canteen_ensure_column($pdo, 'canteen_settings', 'block_on_low_stock', "TINYINT(1) NOT NULL DEFAULT 0"); // ১ হলে কাঁচামাল কম থাকলে sale আটকাবে
canteen_ensure_column($pdo, 'canteen_categories',    'branch_id', 'INT NULL');
canteen_ensure_column($pdo, 'canteen_subcategories', 'branch_id', 'INT NULL');
canteen_ensure_column($pdo, 'canteen_units',         'branch_id', 'INT NULL');
canteen_ensure_column($pdo, 'canteen_suppliers',     'branch_id', 'INT NULL');
canteen_ensure_column($pdo, 'canteen_sale_payments',     'note', 'VARCHAR(30) NULL');
canteen_ensure_column($pdo, 'canteen_supplier_payments', 'note', 'VARCHAR(30) NULL');
canteen_ensure_column($pdo, 'canteen_raw_materials', 'status', "VARCHAR(20) NOT NULL DEFAULT 'Active'");

/** Branch scope মেনে রো লক করে (transaction এর ভেতরে) — অন্য branch-এর হলে false */
function canteen_lock_row(PDO $pdo, string $table, int $id, string $cols = '*')
{
    $where = ['id = ?']; $params = [$id];
    branch_apply_filter($where, $params);
    $st = $pdo->prepare("SELECT $cols FROM `$table` WHERE " . implode(' AND ', $where) . " FOR UPDATE");
    $st->execute($params);
    return $st->fetch();
}

/** Branch scope মেনে রো আনে (লক ছাড়া) */
function canteen_scoped_row(PDO $pdo, string $table, int $id, string $cols = '*')
{
    $where = ['id = ?']; $params = [$id];
    branch_apply_filter($where, $params);
    $st = $pdo->prepare("SELECT $cols FROM `$table` WHERE " . implode(' AND ', $where));
    $st->execute($params);
    return $st->fetch();
}

/** ইউনিক ডকুমেন্ট নম্বর */
function canteen_unique_no(PDO $pdo, string $prefix, string $table, string $column): string
{
    $chk = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
    for ($try = 0; $try < 30; $try++) {
        $no = $prefix . '-' . date('Ymd') . '-' . random_int(100000, 999999);
        $chk->execute([$no]);
        if (!$chk->fetchColumn()) { return $no; }
    }
    return $prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
}

/** master তালিকা (category/unit/supplier): বর্তমান branch অথবা NULL (শেয়ার্ড/পুরনো) */
function canteen_branch_or_shared(array &$where, array &$params, string $column = 'branch_id'): void
{
    $bid = branch_current_id();
    if ($bid === null) { return; }
    $where[] = "($column = ? OR $column IS NULL)";
    $params[] = $bid;
}

function canteen_master_id_in_scope(PDO $pdo, string $table, ?int $id): ?int
{
    if (!$id) { return null; }
    $where = ['id = ?']; $params = [$id];
    canteen_branch_or_shared($where, $params);
    $st = $pdo->prepare("SELECT id FROM `$table` WHERE " . implode(' AND ', $where));
    $st->execute($params);
    return $st->fetchColumn() ? $id : null;
}

function canteen_page_params(int $per = 50): array
{
    $page = max(1, (int) ($_GET['pg'] ?? 1));
    return [$page, $per, ($page - 1) * $per];
}

function canteen_pager_html(int $total, int $page, int $per): string
{
    $pages = (int) ceil($total / max(1, $per));
    if ($pages <= 1) { return ''; }
    $q = $_GET; unset($q['pg']);
    $html = '<nav class="mt-2"><ul class="pagination pagination-sm mb-0 flex-wrap">';
    for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++) {
        $url = '?' . http_build_query($q + ['pg' => $p]);
        $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $p . '</a></li>';
    }
    return $html . '</ul></nav><div class="small text-muted mt-1">মোট ' . $total . ' টি, পেজ ' . $page . '/' . $pages . '</div>';
}

/** একই Item একাধিক লাইনে থাকলে qty মিলিয়ে: [id => ['qty'=>..,'disc'=>..]] */
function canteen_merge_lines(array $ids, array $qtys, array $discs = []): array
{
    $out = [];
    foreach ($ids as $i => $iid) {
        $iid = (int) $iid; $q = (int) ($qtys[$i] ?? 0);
        if ($iid > 0 && $q > 0) {
            if (!isset($out[$iid])) { $out[$iid] = ['qty' => 0, 'disc' => 0.0]; }
            $out[$iid]['qty']  += $q;
            $out[$iid]['disc'] += max(0, (float) ($discs[$i] ?? 0));
        }
    }
    return $out;
}
