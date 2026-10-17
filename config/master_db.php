<?php
/**
 * config/master_db.php
 * -----------------------------------------------------------
 * Master DB কানেকশন — শুধু "tenants" ও "super_admin" টেবিলের জন্য।
 * এইটা প্রতিটা স্কুলের আলাদা DB (config/db.php যেটা বানায়) থেকে
 * সম্পূর্ণ আলাদা কানেকশন।
 *
 * ⚠️ প্রোডাকশনে আসল cPanel credential দিয়ে বদলাও (.env ফাইলে DB_HOST/DB_USER/DB_PASS/DB_NAME)।
 */

require_once __DIR__ . '/env.php';

$master_db_host = getenv('DB_HOST') ?: (gethostbyname('school-erp-mariadb') !== 'school-erp-mariadb' ? 'school-erp-mariadb' : '127.0.0.1');
$master_db_user = getenv('DB_USER') ?: 'root';
$master_db_pass = getenv('DB_PASS') ?: 'root';
$master_db_name = getenv('DB_NAME') ?: 'school_master_db';

try {
    $pdo_master = new PDO(
        "mysql:host={$master_db_host};dbname={$master_db_name};charset=utf8mb4",
        $master_db_user,
        $master_db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Master DB Connection Failed: ' . $e->getMessage());
    http_response_code(500);
    die('দুঃখিত, সিস্টেমটি এই মুহূর্তে পাওয়া যাচ্ছে না।');
}
