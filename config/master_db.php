<?php
/**
 * config/master_db.php
 * -----------------------------------------------------------
 * Master DB কানেকশন — শুধু "tenants" ও "super_admin" টেবিলের জন্য।
 * এইটা প্রতিটা স্কুলের আলাদা DB (config/db.php যেটা বানায়) থেকে
 * সম্পূর্ণ আলাদা কানেকশন।
 *
 * ⚠️ প্রোডাকশনে আসল cPanel credential দিয়ে বদলাও।
 */

$master_db_host = 'localhost';
$master_db_user = 'YOUR_DB_USER';
$master_db_pass = 'YOUR_DB_PASS';
$master_db_name = 'YOUR_DB_NAME';

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
