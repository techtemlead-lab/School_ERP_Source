<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/demo_seed.php';
function erp_provision_schema(PDO $pdo): void {
 $pdo->exec("CREATE TABLE IF NOT EXISTS provision_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,school_code VARCHAR(30) NOT NULL,payload LONGTEXT NOT NULL,status ENUM('queued','running','complete','failed') NOT NULL DEFAULT 'queued',created_db TINYINT NOT NULL DEFAULT 0,created_user TINYINT NOT NULL DEFAULT 0,error VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $pdo->exec("CREATE TABLE IF NOT EXISTS provision_lock(id INT PRIMARY KEY) ENGINE=InnoDB");$pdo->exec('INSERT IGNORE INTO provision_lock(id) VALUES(1)');
 $pdo->exec("CREATE TABLE IF NOT EXISTS worker_health(worker VARCHAR(40) PRIMARY KEY,last_success TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
}
function erp_tenant_payload(array $data): array {
 $code=strtolower(trim((string)($data['school_code']??'')));
 if(!preg_match('/^[a-z][a-z0-9]{2,29}$/',$code)||in_array($code,['demo','www','master','admin'],true))throw new RuntimeException('Use a unique school code with 3-30 letters and digits, starting with a letter.');
 $data['school_code']=$code;
 if(strlen(trim((string)($data['school_name']??'')))<2||strlen($data['school_name'])>200)throw new RuntimeException('Enter a school name up to 200 characters.');
 if(!in_array($data['plan']??'', ['Trial','Basic','Standard','Premium'],true))throw new RuntimeException('Choose a valid plan.');
 if(strlen((string)($data['super_admin_password']??''))<12)throw new RuntimeException('Use a school admin password of at least 12 characters.');
 if(strlen(trim((string)($data['super_admin_username']??'')))<3)throw new RuntimeException('Enter a school admin username.');
 $domain=strtolower(trim((string)($data['custom_domain']??'')));
 if($domain!=='' && (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$domain)||in_array($domain,[erp_primary_host(),erp_demo_host()],true)))throw new RuntimeException('Enter a valid school domain.');
 $data['custom_domain']=$domain?:null;
 return $data;
}
function erp_queue_tenant(PDO $master,array $data): int {
 $data=erp_tenant_payload($data);erp_provision_schema($master);
 $data['db_host']=erp_env('ERP_TENANT_DB_HOST',erp_env('MASTER_DB_HOST','db'));
 $data['db_name']='sc_'.$data['school_code'];$data['db_user']='su_'.bin2hex(random_bytes(10));$data['db_pass']=bin2hex(random_bytes(24));
 $data['admin_hash']=password_hash($data['super_admin_password'],PASSWORD_DEFAULT);unset($data['super_admin_password']);
 $master->beginTransaction();
 try {
  $master->query('SELECT id FROM provision_lock WHERE id=1 FOR UPDATE')->fetch();
  $q=$master->prepare("SELECT id FROM tenants WHERE school_code=? OR custom_domain=? UNION SELECT id FROM provision_jobs WHERE school_code=? AND status IN ('queued','running','complete')");
  $q->execute([$data['school_code'],$data['custom_domain'],$data['school_code']]);
  if($q->fetch())throw new RuntimeException('This school code or domain is already registered or being provisioned.');
  $master->prepare('INSERT INTO provision_jobs(school_code,payload) VALUES(?,?)')->execute([$data['school_code'],json_encode($data,JSON_THROW_ON_ERROR)]);
  $id=(int)$master->lastInsertId();$master->commit();return $id;
 }catch(Throwable $e){$master->rollBack();throw $e;}
}
function erp_install_empty_school(PDO $school,array $data): void {
 if((int)$school->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn()!==0)throw new RuntimeException('The database must be empty. Existing schools require the migration workflow.');
 $root=dirname(__DIR__);demo_sql_file($school,$root.'/sql/schema.sql');demo_sql_file($school,$root.'/sql/full_sync.sql',true);demo_sql_file($school,$root.'/sql/demo_modules.sql');
 foreach(['students','employees'] as $table){
  $columns=$school->query('SHOW COLUMNS FROM '.demo_ident($table))->fetchAll(PDO::FETCH_COLUMN);
  foreach(['rfid_card_no'=>'VARCHAR(100) NULL','tracking_id'=>'VARCHAR(100) NULL','device_serial'=>'VARCHAR(100) NULL','password'=>'VARCHAR(255) NULL','must_change_password'=>'TINYINT NOT NULL DEFAULT 0','branch_id'=>'INT NULL'] as $column=>$definition)if(!in_array($column,$columns,true))$school->exec('ALTER TABLE '.demo_ident($table).' ADD COLUMN '.demo_ident($column).' '.$definition);
 }
 require_once __DIR__.'/lesson_plan_helpers.php';lp_ensure_tables($school);
 require_once __DIR__.'/scholarship_application_helpers.php';sa_ensure_tables($school);
 require_once __DIR__.'/teacher_question_helpers.php';tq_ensure_tables($school);
 $school->beginTransaction();
 try{
  $school->exec('DELETE FROM admin');
  $school->prepare('INSERT INTO admin(username,password) VALUES(?,?)')->execute([$data['super_admin_username'],$data['admin_hash']??password_hash($data['super_admin_password'],PASSWORD_DEFAULT)]);
  $school->prepare('UPDATE institute_settings SET institute_name=?,phone_number=?,email=? WHERE id=1')->execute([$data['school_name'],$data['contact_phone']??'',$data['contact_email']??'']);
  $school->prepare('UPDATE branches SET branch_name=?,phone=?,email=? WHERE id=1')->execute([$data['school_name'],$data['contact_phone']??'',$data['contact_email']??'']);
  $school->commit();
 }catch(Throwable $e){$school->rollBack();throw $e;}
}
function erp_register_school(PDO $master,array $data): int {
 $master->prepare("INSERT INTO tenants(school_name,school_code,subdomain,custom_domain,db_host,db_name,db_user,db_pass,plan,status,contact_name,contact_phone,contact_email,trial_ends_at) VALUES(?,?,?,?,?,?,?,?,?,'Active',?,?,?,?)")->execute([$data['school_name'],$data['school_code'],$data['school_code'],$data['custom_domain']?:null,$data['db_host'],$data['db_name'],$data['db_user'],$data['db_pass'],$data['plan'],$data['contact_name']??'',$data['contact_phone']??'',$data['contact_email']??'',($data['plan']==='Trial'?date('Y-m-d',strtotime('+14 days')):null)]);
 return (int)$master->lastInsertId();
}
