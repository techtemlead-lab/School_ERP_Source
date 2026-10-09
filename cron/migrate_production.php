<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/provisioning.php';require_once __DIR__.'/../config/lesson_plan_helpers.php';require_once __DIR__.'/../config/demo_requests.php';
$pdo=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST','main-db').';dbname='.erp_env('MASTER_DB_NAME','school_master_db').';charset=utf8mb4',erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require_once __DIR__.'/../master/_access.php';require_once __DIR__.'/../master/theme.php';require_once __DIR__.'/../master/_features.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(80) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
if((int)$pdo->query("SELECT GET_LOCK('school_erp_migrations',0)")->fetchColumn()!==1)throw new RuntimeException('Migration already running');
try{
 $version='20261004_master_security_provisioning';
 $q=$pdo->prepare('SELECT version FROM schema_migrations WHERE version=?');$q->execute([$version]);
 if(!$q->fetch()){
  ensureMasterUsersTable($pdo);ensureUserMenuPermissionsTable($pdo);ensureActivityLogTable($pdo);ensureSaasSettingsTable($pdo);ensureBillingTables($pdo);ensureTenantTrashColumns($pdo);ensureTenantBlockedStatus($pdo);demo_requests_install($pdo);erp_provision_schema($pdo);
  $pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$version]);
 }
 foreach($pdo->query('SELECT db_host,db_name FROM tenants WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $tenant){
  if($tenant['db_host']!==erp_env('ERP_TENANT_PROVISION_HOST',erp_env('ERP_PROVISION_HOST','main-db')))throw new RuntimeException('Review external tenant migration separately');
  $school=new PDO('mysql:host='.$tenant['db_host'].';dbname='.$tenant['db_name'].';charset=utf8mb4',erp_env('ERP_TENANT_PROVISION_USER',erp_env('ERP_PROVISION_USER')),erp_env('ERP_TENANT_PROVISION_PASS',erp_env('ERP_PROVISION_PASS')),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $school->exec("CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(80) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
  $modules='20261004_missing_module_tables';$m=$school->prepare('SELECT version FROM schema_migrations WHERE version=?');$m->execute([$modules]);if(!$m->fetch()){demo_sql_file($school,__DIR__.'/../sql/demo_modules.sql');$school->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$modules]);}
  lp_ensure_tables($school);
  require_once __DIR__.'/../config/scholarship_application_helpers.php';sa_ensure_tables($school);
  require_once __DIR__.'/../config/teacher_question_helpers.php';tq_ensure_tables($school);
  $ver='20261004_portal_columns';$q=$school->prepare('SELECT version FROM schema_migrations WHERE version=?');$q->execute([$ver]);
  if(!$q->fetch()){
   foreach(['students','employees'] as $table){
    $cols=$school->query('SHOW COLUMNS FROM '.demo_ident($table))->fetchAll(PDO::FETCH_COLUMN);
    foreach(['rfid_card_no'=>'VARCHAR(100) NULL','tracking_id'=>'VARCHAR(100) NULL','device_serial'=>'VARCHAR(100) NULL','password'=>'VARCHAR(255) NULL','must_change_password'=>'TINYINT NOT NULL DEFAULT 1','branch_id'=>'INT NULL'] as $column=>$definition)if(!in_array($column,$cols,true))$school->exec('ALTER TABLE '.demo_ident($table).' ADD COLUMN '.demo_ident($column).' '.$definition);
   }
   $school->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$ver]);
  }
 }
 echo "Additive master and tenant migrations verified; school data was not initialized or reseeded.\n";
}finally{$pdo->query("SELECT RELEASE_LOCK('school_erp_migrations')");}
