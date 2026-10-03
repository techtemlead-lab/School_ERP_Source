<?php
require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
require_once __DIR__ . '/../config/master_db.php'; // $pdo_master
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/theme.php';   // থিম ইঞ্জিন + ব্র্যান্ডিং
require_once __DIR__ . '/_access.php'; // Users/Roles + Access Control + Activity Log
require_once __DIR__ . '/sms_helper.php'; // SMS Gateway হেল্পার

ensureMasterUsersTable($pdo_master);

if (empty($_SESSION['master_user_id'])) {
    header('Location: login.php');
    exit();
}

// ইউজার এখনো Active আছে কিনা চেক (admin কাউকে ডিঅ্যাক্টিভ করলে সাথে সাথে লগ-আউট)
$_mu = $pdo_master->prepare("SELECT * FROM master_users WHERE id=?");
$_mu->execute([$_SESSION['master_user_id']]);
$_mu = $_mu->fetch();
if (!$_mu || !$_mu['is_active']) {
    session_destroy();
    header('Location: login.php');
    exit();
}
$_SESSION['master_name'] = $_mu['name'];
$_SESSION['master_role'] = $_mu['role'];

require_once __DIR__.'/_route_permissions.php';
