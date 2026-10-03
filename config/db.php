<?php
/**
 * config/db.php  (SaaS version)
 * -----------------------------------------------------------
 * আগে এই ফাইলে একটা স্কুলের DB credential হার্ডকোড করা ছিল।
 * এখন থেকে এই ফাইল প্রথমে বুঝবে request টা কোন স্কুলের (subdomain/
 * custom domain দেখে), তারপর সেই স্কুলের নিজের DB-তে কানেক্ট করবে।
 *
 * বাকি পুরো অ্যাপ (admin/index.php সহ সব module file) আগের মতোই
 * $pdo ব্যবহার করে চলবে — কোনো module file change করা লাগবে না।
 */

// PHP-র timezone আগে কোথাও সেট করা ছিল না, তাই সার্ভারের ডিফল্ট (সাধারণত UTC)
// ব্যবহার হচ্ছিল — বাংলাদেশ টাইম থেকে ৬ ঘণ্টা পিছিয়ে। এর ফলে মধ্যরাতের পর কয়েক
// ঘণ্টা date('Y-m-d')-এর মতো সব জায়গায় এখনো "গতকাল"-ই দেখাত (attendance,
// fees, admission — যেখানেই আজকের তারিখ হিসাব হয়) — এখন পুরো অ্যাপের জন্য
// একবারেই বাংলাদেশ টাইমজোনে ফিক্স করে দেওয়া হলো
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/tenant.php'; // resolve_tenant(), $pdo_master

$tenant = resolve_tenant($pdo_master);

// অন্য ফাইল থেকে দরকার হতে পারে (institute name override, uploads path prefix ইত্যাদি)
$GLOBALS['tenant'] = $tenant;
$_SESSION['tenant_school_code'] = $tenant['school_code']; // uploads path আলাদা রাখতে কাজে লাগবে (Phase 2 নোট দ্রষ্টব্য)

$tenant_host = $tenant['db_host'];
if (($tenant_host === 'localhost' || $tenant_host === '127.0.0.1') && gethostbyname('school-erp-mariadb') !== 'school-erp-mariadb') {
    $tenant_host = 'school-erp-mariadb';
} elseif ($tenant_host === 'school-erp-mariadb' && gethostbyname('school-erp-mariadb') === 'school-erp-mariadb') {
    $tenant_host = '127.0.0.1';
}

try {
    $pdo = new PDO(
        "mysql:host={$tenant_host};dbname={$tenant['db_name']};charset=utf8mb4",
        $tenant['db_user'],
        $tenant['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Tenant DB Connection Failed [' . $tenant['school_code'] . ']: ' . $e->getMessage());
    http_response_code(500);
    die('Database error!');
}

// PHP-র timezone উপরে Asia/Dhaka করা হয়েছে, কিন্তু MySQL সার্ভার নিজে NOW()/CURRENT_TIMESTAMP
// এর জন্য নিজের সিস্টেম টাইমজোন (সাধারণত UTC) ব্যবহার করে — PHP-র সেটিং এখানে কোনো প্রভাব
// ফেলে না। তাই collected_at (DEFAULT CURRENT_TIMESTAMP) এর মতো কলামগুলো UTC অনুযায়ী সেভ
// হচ্ছিল, ফলে রাত ১২টা থেকে ভোর ৬টা (বাংলাদেশ টাইম) পর্যন্ত করা কালেকশন MySQL-এর হিসেবে এখনো
// "আগের দিন" পড়ত এবং Daily Collection-এর মতো তারিখ-ভিত্তিক রিপোর্টে "আজকের" তালিকায় দেখাত না।
// mysql.time_zone টেবিল (নামভিত্তিক টাইমজোন যেমন 'Asia/Dhaka') অনেক শেয়ার্ড হোস্টিং-এ লোড করা
// থাকে না, তাই নিরাপদ ফিক্সড অফসেট ব্যবহার করা হলো (বাংলাদেশে কোনো DST নেই, তাই +06:00 সবসময় ঠিক)
$pdo->exec("SET time_zone = '+06:00'");

require_once __DIR__ . '/uploads.php'; // tenant_upload_rel() / tenant_upload_abs() — SaaS per-school file isolation
