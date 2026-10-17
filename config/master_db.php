<?php
require_once __DIR__ . '/runtime.php';
if (erp_env('ERP_ROLE')==='tenant') erp_stop(404,'The master database is unavailable in a school runtime.');
if (erp_is_demo()) erp_stop(404,'The real master panel is not part of the public demo.');
try {
    $pdo_master = new PDO('mysql:host='.erp_env('MASTER_DB_HOST','localhost').';dbname='.erp_env('MASTER_DB_NAME','school_master_db').';charset=utf8mb4',
        erp_env('MASTER_DB_USER'),erp_env('MASTER_DB_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo_master->exec("SET time_zone = '+06:00'");
} catch (PDOException $e) { error_log('Master DB connection failed'); erp_stop(503,'The ERP database is temporarily unavailable.'); }
