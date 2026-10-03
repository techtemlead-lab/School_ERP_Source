<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/provisioning.php';
require_once __DIR__.'/../config/demo_requests.php';
$host=erp_env('ERP_PROVISION_HOST','main-db');$db=erp_env('MASTER_DB_NAME','school_master_db');
$pdo=new PDO('mysql:host='.$host.';charset=utf8mb4',erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS '.demo_ident($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.demo_ident($db));
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0)demo_sql_file($pdo,__DIR__.'/../sql/master_schema.sql');
require_once __DIR__.'/../master/_access.php';require_once __DIR__.'/../master/theme.php';require_once __DIR__.'/../master/_features.php';
ensureMasterUsersTable($pdo);ensureUserMenuPermissionsTable($pdo);ensureActivityLogTable($pdo);ensureSaasSettingsTable($pdo);ensureBillingTables($pdo);ensureTenantTrashColumns($pdo);ensureTenantBlockedStatus($pdo);demo_requests_install($pdo);erp_provision_schema($pdo);
$count=(int)$pdo->query('SELECT COUNT(*) FROM master_users')->fetchColumn();
if($count===0){
 $password=erp_env('BOOTSTRAP_ADMIN_PASSWORD');if(strlen($password)<16)throw new RuntimeException('Configure a strong bootstrap admin password');
 $pdo->prepare("INSERT INTO master_users(name,username,password,role,is_active) VALUES(?,?,?,'superadmin',1)")->execute(['Owner',erp_env('BOOTSTRAP_ADMIN_USER','owner'),password_hash($password,PASSWORD_DEFAULT)]);
}
$user=erp_env('MASTER_DB_USER','master_web');$pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($user)."@'%' IDENTIFIED BY ".$pdo->quote(erp_env('MASTER_DB_PASS')));
$pdo->exec('GRANT ALL PRIVILEGES ON '.demo_ident($db).'.* TO '.$pdo->quote($user)."@'%'");
// Demo runs on a different database engine and network.
$demo=new PDO('mysql:host='.erp_env('DEMO_DB_HOST','demo-db').';charset=utf8mb4',erp_env('DEMO_PROVISION_USER'),erp_env('DEMO_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$control=erp_env('DEMO_CONTROL_DB','school_demo_control');$demo->exec('CREATE DATABASE IF NOT EXISTS '.demo_ident($control).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$du=erp_env('DEMO_CONTROL_USER','demo_web');$demo->exec('CREATE USER IF NOT EXISTS '.$demo->quote($du)."@'%' IDENTIFIED BY ".$demo->quote(erp_env('DEMO_CONTROL_PASS')));
$demo->exec('GRANT SELECT,UPDATE ON '.demo_ident($control).'.* TO '.$demo->quote($du)."@'%'");
echo "Master initialized without overwriting existing accounts; isolated demo control access installed.\n";
