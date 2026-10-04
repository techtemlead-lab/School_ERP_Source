<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='local')exit(1);
require_once __DIR__.'/../config/env.php';require_once __DIR__.'/../config/demo_seed.php';
$pdo=new PDO('mysql:host=db;charset=utf8mb4','root',erp_env('LOCAL_DB_ROOT_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS school_master_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo->exec("USE school_master_db");demo_sql_file($pdo,__DIR__.'/../sql/master_schema.sql');
require_once __DIR__.'/../config/demo_requests.php';demo_requests_install($pdo);
$hash=password_hash(erp_env('LOCAL_ADMIN_PASSWORD'),PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO super_admin(username,password) VALUES('owner',?) ON DUPLICATE KEY UPDATE username=VALUES(username)")->execute([$hash]);
foreach(['master_web'=>['LOCAL_MASTER_PASSWORD','school_master_db','ALL PRIVILEGES'],'demo_web'=>['LOCAL_CONTROL_PASSWORD','school_demo_control','SELECT,UPDATE']] as $user=>[$key,$db,$permissions]){
 $pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($user)."@'%' IDENTIFIED BY ".$pdo->quote(erp_env($key)));
 $pdo->exec('GRANT '.$permissions.' ON '.demo_ident($db).'.* TO '.$pdo->quote($user)."@'%'");
}
echo "Local master and web DB permissions installed.\n";

require_once __DIR__.'/../config/provisioning.php';
$pdo->exec('CREATE DATABASE IF NOT EXISTS sc_boundfixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE sc_boundfixture');
if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0)erp_install_empty_school($pdo,['school_name'=>'Fictional isolation fixture','super_admin_username'=>'bound_owner','super_admin_password'=>erp_env('LOCAL_ADMIN_PASSWORD')]);
$pdo->exec('CREATE USER IF NOT EXISTS '. $pdo->quote('bound_web')."@'%' IDENTIFIED BY ".$pdo->quote(erp_env('LOCAL_TENANT_PASSWORD')));
$pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON sc_boundfixture.* TO '. $pdo->quote('bound_web')."@'%'");
echo "Disposable bound school isolation fixture ready.\n";
