<?php
// config/tenant_direct.php - নির্দিষ্ট কোনো tenant (school) এর DB-তে সরাসরি কানেক্ট করার হেল্পার
// -----------------------------------------------------------------
// সাধারণত config/db.php রিকোয়েস্টের ডোমেইন দেখে বুঝে নেয় কোন স্কুল। কিন্তু দুই জায়গায়
// সেটা কাজ করে না — ডোমেইনের বাইরে থেকে নির্দিষ্ট tenant চেনা লাগে:
//   ১) cron/process_recorded_uploads.php — CLI থেকে চলে, কোনো ডোমেইন/রিকোয়েস্ট থাকে না,
//      তাই এক এক করে সব স্কুলের DB-তে ঢুকে কাজ করতে হয়
//   ২) youtube_oauth_callback.php — Google Cloud Console-এ একটাই fixed redirect URI
//      রেজিস্টার করা যায় (প্রতিটা স্কুলের ডোমেইনের জন্য আলাদা করে যোগ করা সম্ভব না),
//      তাই কোন স্কুল থেকে অনুরোধ এসেছে সেটা 'state' প্যারামিটার দিয়ে বোঝা লাগে

require_once __DIR__ . '/master_db.php'; // $pdo_master

if (!function_exists('connect_tenant_db')) {
    function connect_tenant_db(array $tenant_row): PDO
    {
        return new PDO(
            "mysql:host={$tenant_row['db_host']};dbname={$tenant_row['db_name']};charset=utf8mb4",
            $tenant_row['db_user'],
            $tenant_row['db_pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}
if (!function_exists('get_tenant_by_code')) {
    function get_tenant_by_code(PDO $pdo_master, string $school_code): ?array
    {
        $stmt = $pdo_master->prepare('SELECT * FROM tenants WHERE school_code = ? LIMIT 1');
        $stmt->execute([$school_code]);
        return $stmt->fetch() ?: null;
    }
}
if (!function_exists('get_all_active_tenants')) {
    function get_all_active_tenants(PDO $pdo_master): array
    {
        return $pdo_master->query("SELECT * FROM tenants WHERE status = 'Active'")->fetchAll();
    }
}
