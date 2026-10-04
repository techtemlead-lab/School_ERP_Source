<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/provisioning.php';
[$script,$school,$role,$id]=array_pad($argv,4,'');
if(!preg_match('/^[a-z][a-z0-9]{2,29}$/',$school)||!in_array($role,['admin','teacher','student'],true)||!ctype_digit($id))throw new RuntimeException('Usage: php cron/reset_school_login.php SCHOOL_CODE admin|teacher|student NUMERIC_ID < private-password-file');
$password=trim(stream_get_contents(STDIN));if(strlen($password)<12)throw new RuntimeException('Use at least 12 characters');
$pdo=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST','main-db').';dbname='.erp_env('MASTER_DB_NAME','school_master_db'),erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$q=$pdo->prepare('SELECT * FROM tenants WHERE school_code=? AND deleted_at IS NULL');$q->execute([$school]);$tenant=$q->fetch();if(!$tenant)throw new RuntimeException('School not found');
$pdo=new PDO('mysql:host='.$tenant['db_host'].';dbname='.$tenant['db_name'].';charset=utf8mb4',erp_env('ERP_TENANT_PROVISION_USER',erp_env('ERP_PROVISION_USER')),erp_env('ERP_TENANT_PROVISION_PASS',erp_env('ERP_PROVISION_PASS')),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$table=['admin'=>'admin','teacher'=>'employees','student'=>'students'][$role];
$q=$pdo->prepare('UPDATE '.demo_ident($table).' SET password=?'.($role==='admin'?'':',must_change_password=1').' WHERE id=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),(int)$id]);if($q->rowCount()!==1)throw new RuntimeException('Account not found');
echo "Password issued to one school account. Deliver it privately and require change at first login.\n";
