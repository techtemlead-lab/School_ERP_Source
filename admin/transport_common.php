<?php
// admin/transport_common.php
// ---------------------------------------------------------------------
// Transport Module এর সব ফাইলে require হয় — টেবিল অটো-ক্রিয়েশন ও
// কমন হেল্পার ফাংশন এক জায়গায় রাখা হয়েছে যাতে ৮-৯টা টেবিল বারবার
// প্রতিটা ফাইলে ডুপ্লিকেট না হয়।
// ---------------------------------------------------------------------

if (!function_exists('lv')) {
    function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// -----------------------------------------------------------
// Core tables (Vehicle / Route / Stop / Assignments)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_no VARCHAR(50) NOT NULL,
        bus_no VARCHAR(20) NULL,
        vehicle_name VARCHAR(100) NULL,
        vehicle_type ENUM('Bus','Microbus','Car','Van','Other') NOT NULL DEFAULT 'Bus',
        capacity INT NOT NULL DEFAULT 0,
        driver_id INT NULL,
        helper_id INT NULL,
        gps_device_identifier VARCHAR(150) NULL,
        gps_device_ref_id VARCHAR(100) NULL,
        fitness_expiry DATE NULL,
        insurance_expiry DATE NULL,
        tax_token_expiry DATE NULL,
        status ENUM('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_vehicle_no (vehicle_no),
        INDEX idx_gps_device (gps_device_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// পুরনো ইনস্টলেশনে bus_no কলাম না থাকলে যোগ করা (আগে যারা প্রথম ভার্সন বসিয়েছেন)
$vcols = $pdo->query("SHOW COLUMNS FROM transport_vehicles")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('bus_no', $vcols, true)) { $pdo->exec("ALTER TABLE transport_vehicles ADD COLUMN bus_no VARCHAR(20) NULL AFTER vehicle_no"); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_no VARCHAR(20) NULL,
        route_name VARCHAR(150) NOT NULL,
        route_category ENUM('Student','Staff','Mixed') NOT NULL DEFAULT 'Student',
        vehicle_id INT NULL,
        monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$rcols = $pdo->query("SHOW COLUMNS FROM transport_routes")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('route_no', $rcols, true)) { $pdo->exec("ALTER TABLE transport_routes ADD COLUMN route_no VARCHAR(20) NULL AFTER id"); }
if (!in_array('route_category', $rcols, true)) { $pdo->exec("ALTER TABLE transport_routes ADD COLUMN route_category ENUM('Student','Staff','Mixed') NOT NULL DEFAULT 'Student' AFTER route_name"); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_stops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        stop_name VARCHAR(150) NOT NULL,
        stop_order INT NOT NULL DEFAULT 0,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        radius_m INT NOT NULL DEFAULT 100,
        pickup_time TIME NULL,
        drop_time TIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_route_order (route_id, stop_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_student_assign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        route_id INT NOT NULL,
        stop_id INT NOT NULL,
        fee_override DECIMAL(10,2) NULL,
        tracking_token VARCHAR(64) NULL,
        assigned_date DATE NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        remarks VARCHAR(255) NULL,
        INDEX idx_student (student_id), INDEX idx_route (route_id),
        UNIQUE KEY uniq_token (tracking_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$sacols = $pdo->query("SHOW COLUMNS FROM transport_student_assign")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array('tracking_token', $sacols, true)) { $pdo->exec("ALTER TABLE transport_student_assign ADD COLUMN tracking_token VARCHAR(64) NULL, ADD UNIQUE KEY uniq_token (tracking_token)"); }

// Staff (employees) assignment — Student assign এর হুবহু কাঠামো, শুধু employee_id
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_staff_assign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        route_id INT NOT NULL,
        stop_id INT NOT NULL,
        fee_override DECIMAL(10,2) NULL,
        assigned_date DATE NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        remarks VARCHAR(255) NULL,
        INDEX idx_employee (employee_id), INDEX idx_route2 (route_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// Fee Invoices
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_fee_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        route_id INT NOT NULL,
        month_year VARCHAR(7) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid',
        payment_date DATE NULL,
        payment_method VARCHAR(50) NULL,
        trx_id VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_month (student_id, month_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// GPS Settings / Logs / Live Cache / Events (Traccar প্রোভাইডার ধরে)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_gps_settings (
        id INT PRIMARY KEY DEFAULT 1,
        provider_name VARCHAR(50) NOT NULL DEFAULT 'traccar',
        base_url VARCHAR(255) NULL,
        username VARCHAR(150) NULL,
        password VARCHAR(255) NULL,
        poll_interval_sec INT NOT NULL DEFAULT 20,
        school_lat DECIMAL(10,7) NULL,
        school_lng DECIMAL(10,7) NULL,
        geofence_radius_m INT NOT NULL DEFAULT 200,
        connection_status ENUM('Not Tested','Connected','Failed') NOT NULL DEFAULT 'Not Tested',
        last_tested_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if (!$pdo->query("SELECT id FROM transport_gps_settings WHERE id=1")->fetch()) {
    $pdo->exec("INSERT INTO transport_gps_settings (id) VALUES (1)");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_gps_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        lat DECIMAL(10,7) NOT NULL,
        lng DECIMAL(10,7) NOT NULL,
        speed_kmh DECIMAL(6,2) NOT NULL DEFAULT 0,
        course_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
        ignition TINYINT(1) NOT NULL DEFAULT 0,
        device_time DATETIME NOT NULL,
        received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vehicle_time (vehicle_id, device_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_vehicle_live (
        vehicle_id INT PRIMARY KEY,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        speed_kmh DECIMAL(6,2) NOT NULL DEFAULT 0,
        course_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
        ignition TINYINT(1) NOT NULL DEFAULT 0,
        device_time DATETIME NULL,
        connection_status ENUM('Online','Offline','Unknown') NOT NULL DEFAULT 'Unknown',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        event_type ENUM('Arrived School','Departed School','Stop Reached','Offline Alert') NOT NULL,
        stop_id INT NULL,
        event_time DATETIME NOT NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        sms_sent TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vehicle_event_time (vehicle_id, event_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);


// -----------------------------------------------------------
// Branch scope কলাম (গাড়ি/রুট: NULL = শেয়ার্ড/পুরনো রেকর্ড) + Fee Payment history
// -----------------------------------------------------------
foreach (['transport_vehicles', 'transport_routes'] as $__t) {
    $__c = $pdo->query("SHOW COLUMNS FROM `$__t`")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('branch_id', $__c, true)) { $pdo->exec("ALTER TABLE `$__t` ADD COLUMN branch_id INT NULL"); }
}
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS transport_fee_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50) NULL,
        trx_id VARCHAR(100) NULL,
        collected_by VARCHAR(100) NULL,
        paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_invoice (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// Helpers: validation + branch scope (শুধু পেজ থেকে কল হয়; cron এ লাগে না)
// -----------------------------------------------------------
if (!function_exists('tr_valid_date')) {
    function tr_valid_date($d): bool
    {
        $d = (string) $d;
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }
}
if (!function_exists('tr_valid_month')) {
    function tr_valid_month($m): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $m);
    }
}
if (!function_exists('tr_branch_or_shared')) {
    /** গাড়ি/রুট: বর্তমান branch অথবা NULL (শেয়ার্ড) */
    function tr_branch_or_shared(array &$where, array &$params, string $col = 'branch_id'): void
    {
        $bid = branch_current_id();
        if ($bid === null) { return; }
        $where[] = "($col = ? OR $col IS NULL)";
        $params[] = $bid;
    }
}
if (!function_exists('tr_scoped')) {
    /** গাড়ি/রুট row (branch scope মেনে); $lock হলে FOR UPDATE */
    function tr_scoped(PDO $pdo, string $table, int $id, bool $lock = false)
    {
        $where = ['id = ?']; $params = [$id];
        tr_branch_or_shared($where, $params);
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE " . implode(' AND ', $where) . ($lock ? ' FOR UPDATE' : ''));
        $st->execute($params);
        return $st->fetch();
    }
}
if (!function_exists('tr_student_scoped')) {
    function tr_student_scoped(PDO $pdo, int $id, bool $lock = false)
    {
        $where = ['id = ?']; $params = [$id];
        branch_apply_filter($where, $params, 'branch_id');
        $st = $pdo->prepare("SELECT * FROM students WHERE " . implode(' AND ', $where) . ($lock ? ' FOR UPDATE' : ''));
        $st->execute($params);
        return $st->fetch();
    }
}
if (!function_exists('tr_employee_scoped')) {
    function tr_employee_scoped(PDO $pdo, int $id, bool $lock = false)
    {
        $where = ['id = ?']; $params = [$id];
        branch_apply_filter($where, $params, 'branch_id');
        $st = $pdo->prepare("SELECT * FROM employees WHERE " . implode(' AND ', $where) . ($lock ? ' FOR UPDATE' : ''));
        $st->execute($params);
        return $st->fetch();
    }
}

// -----------------------------------------------------------
// Helper: দূরত্ব হিসাব (Haversine, মিটারে) — Geofence চেক করার জন্য
// -----------------------------------------------------------
if (!function_exists('gps_distance_meters')) {
    function gps_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth_radius = 6371000; // মিটার
        $d_lat = deg2rad($lat2 - $lat1);
        $d_lng = deg2rad($lng2 - $lng1);
        $a = sin($d_lat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius * $c;
    }
}

// Route লেবেল সুন্দর করে দেখানো: "Route-1 — Bus No-01 (Jamuna)"
if (!function_exists('transport_route_label')) {
    function transport_route_label(array $route): string
    {
        $parts = [];
        if (!empty($route['route_no'])) $parts[] = $route['route_no'];
        elseif (!empty($route['route_name'])) $parts[] = $route['route_name'];
        $bus = trim(($route['bus_no'] ?? '' ? 'Bus No-' . $route['bus_no'] : '') . (($route['vehicle_name'] ?? '') ? ' (' . $route['vehicle_name'] . ')' : ''));
        if ($bus !== '') $parts[] = $bus;
        return implode(' — ', $parts) ?: ($route['route_name'] ?? '');
    }
}
