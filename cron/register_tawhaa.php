<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/provisioning.php';
$pdo=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST','main-db').';charset=utf8mb4',erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=erp_env('TAWHAA_DB_NAME','sc_tawhaa');if(!in_array($db,['sc_tawhaa','school_tawhaa'],true))throw new RuntimeException('Unexpected Tawhaa database name');$code='tawhaaschool';$pdo->exec('USE '.demo_ident($db));
if((int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn()<1)throw new RuntimeException('Restore a populated Tawhaa database first');
$name=$pdo->query('SELECT institute_name FROM institute_settings WHERE id=1')->fetchColumn()?:'Tawhaa School';
foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
 foreach($pdo->query('SHOW COLUMNS FROM '.demo_ident($table))->fetchAll() as $col){
  $column=$col['Field'];if(!str_ends_with($column,'_path'))continue;
  $q=$pdo->prepare('UPDATE '.demo_ident($table).' SET '.demo_ident($column).'=CONCAT(?,SUBSTRING('.demo_ident($column).',9)) WHERE '.demo_ident($column).' LIKE ? AND '.demo_ident($column).' NOT LIKE ?');
  $q->execute(['uploads/'.$code.'/','uploads/%','uploads/'.$code.'/%']);
 }
}
$pass=erp_env('TAWHAA_DB_PASS');if(strlen($pass)<32)throw new RuntimeException('Configure the Tawhaa SQL account password');
$user='su_tawhaa';$pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($user)."@'%' IDENTIFIED BY ".$pdo->quote($pass));$pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON '.demo_ident($db).'.* TO '.$pdo->quote($user)."@'%'");
$pdo->exec('USE '.demo_ident(erp_env('MASTER_DB_NAME','school_master_db')));
$q=$pdo->prepare('SELECT id FROM tenants WHERE school_code=?');$q->execute([$code]);
$existing=$q->fetchColumn();if($existing)$pdo->prepare('UPDATE tenants SET db_host=?,db_name=?,db_user=?,db_pass=?,subdomain=?,custom_domain=? WHERE id=?')->execute([erp_env('ERP_TENANT_DB_HOST','main-db'),$db,$user,$pass,'tawhaa','tawhaa.schoolmng.techteamlead.com',$existing]);
else $pdo->prepare("INSERT INTO tenants(school_name,school_code,subdomain,custom_domain,db_host,db_name,db_user,db_pass,plan,status) VALUES(?,?,?,?,?,?,?,?,'Standard','Active')")->execute([$name,$code,'tawhaa','tawhaa.schoolmng.techteamlead.com',erp_env('ERP_TENANT_DB_HOST','main-db'),$db,$user,$pass]);
$pdo->exec("UPDATE tenants SET deleted_at=COALESCE(deleted_at,NOW()),status='Suspended' WHERE subdomain='demo' AND db_name='school_demo'");
echo "Tawhaa registered; existing business records and login passwords preserved.\n";
