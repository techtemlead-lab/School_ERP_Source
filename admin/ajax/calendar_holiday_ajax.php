<?php
// admin/ajax/calendar_holiday_ajax.php - Academic Calendar এর Dynamic (Drag & Drop) ভিউ এর জন্য AJAX endpoint
// Actions: list (GET), create (POST), update_dates (POST - ড্র্যাগ করে বাড়ানো/কমানো/সরানো), delete (POST)
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/csrf.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS academic_holidays (
        id INT AUTO_INCREMENT PRIMARY KEY,
        holiday_name VARCHAR(150) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        holiday_type VARCHAR(30) NOT NULL DEFAULT 'General',
        remarks VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$holiday_types = ['General', 'Government', 'Religious', 'Vacation', 'Emergency'];
$type_colors = [
    'General'    => '#1e3a8a',
    'Government' => '#0f766e',
    'Religious'  => '#a16207',
    'Vacation'   => '#7c3aed',
    'Emergency'  => '#dc2626',
];

$action = $_REQUEST['action'] ?? '';

function out(array $data): void { echo json_encode($data); exit(); }

if ($action === 'list') {
    // নির্দিষ্ট মাসের ক্যালেন্ডার গ্রিডে যেসব হলিডে ওভারল্যাপ করে (আগের/পরের মাসের কিছু দিন গ্রিডে দেখা যেতে পারে বলে সামান্য বাফার নেওয়া হলো)
    $year  = (int) ($_GET['year'] ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    $range_start = date('Y-m-d', strtotime("$year-$month-01 -7 days"));
    $range_end   = date('Y-m-d', strtotime("$year-$month-01 +1 month +7 days"));

    $stmt = $pdo->prepare("SELECT * FROM academic_holidays WHERE status='Active' AND start_date <= ? AND end_date >= ? ORDER BY start_date ASC");
    $stmt->execute([$range_end, $range_start]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['color'] = $type_colors[$r['holiday_type']] ?? '#1e3a8a';
    }
    out(['success' => true, 'holidays' => $rows, 'types' => $holiday_types]);
}

// নিচের সবগুলো action এ ডেটা পরিবর্তন হয় - CSRF ভেরিফাই বাধ্যতামূলক
if (in_array($action, ['save', 'update_dates', 'delete'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        out(['success' => false, 'message' => 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করুন।']);
    }
}

if ($action === 'save') {
    // Modal থেকে Add/Edit করার সময় ব্যবহার হয় (Name, Type, Dates সব একসাথে সেভ হয়)
    $id    = (int) ($_POST['id'] ?? 0);
    $name  = trim($_POST['holiday_name'] ?? '');
    $start = trim($_POST['start_date'] ?? '');
    $end   = trim($_POST['end_date'] ?? $start);
    $type  = in_array($_POST['holiday_type'] ?? '', $holiday_types, true) ? $_POST['holiday_type'] : 'General';

    if ($name === '' || $start === '' || strtotime($end) < strtotime($start)) {
        out(['success' => false, 'message' => 'Holiday Name ও সঠিক তারিখ দিন।']);
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE academic_holidays SET holiday_name=?, start_date=?, end_date=?, holiday_type=? WHERE id=?")
            ->execute([$name, $start, $end, $type, $id]);
        out(['success' => true, 'id' => $id, 'message' => 'Holiday আপডেট করা হয়েছে।']);
    }
    $stmt = $pdo->prepare("INSERT INTO academic_holidays (holiday_name, start_date, end_date, holiday_type, status) VALUES (?, ?, ?, ?, 'Active')");
    $stmt->execute([$name, $start, $end, $type]);
    out(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => 'Holiday যোগ করা হয়েছে।']);
}

if ($action === 'update_dates') {
    // ক্যালেন্ডারে ড্র্যাগ করে বার / এজ সরালে এখানে নতুন start_date / end_date সেভ হয়
    $id    = (int) ($_POST['id'] ?? 0);
    $start = trim($_POST['start_date'] ?? '');
    $end   = trim($_POST['end_date'] ?? '');

    if ($id <= 0 || $start === '' || $end === '' || strtotime($end) < strtotime($start)) {
        out(['success' => false, 'message' => 'তারিখ সঠিক নয়।']);
    }
    $pdo->prepare("UPDATE academic_holidays SET start_date=?, end_date=? WHERE id=?")->execute([$start, $end, $id]);
    out(['success' => true, 'message' => 'তারিখ আপডেট হয়েছে।']);
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM academic_holidays WHERE id = ?")->execute([$id]);
    out(['success' => true, 'message' => 'Holiday মুছে ফেলা হয়েছে।']);
}

out(['success' => false, 'message' => 'অজানা অ্যাকশন।']);
