<?php
if(PHP_SAPI!=='cli')exit(1);
umask(0077);require_once __DIR__.'/../config/provisioning.php';
$school=$argv[1]??'';if(!preg_match('/^[a-z][a-z0-9]{2,29}$/',$school))throw new RuntimeException('Pass one real school code');
$p=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST','main-db').';dbname='.erp_env('MASTER_DB_NAME','school_master_db').';charset=utf8mb4',erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$q=$p->prepare('SELECT db_name FROM tenants WHERE school_code=? AND deleted_at IS NULL');$q->execute([$school]);$db=$q->fetchColumn();if(!$db||str_starts_with($db,'ds_'))throw new RuntimeException('Real school not found');
$p->exec('USE '.demo_ident($db));$rows=[];
foreach(['teacher'=>'employees','student'=>'students'] as $role=>$table)foreach($p->query('SELECT id,'.($role==='teacher'?'employee_id':'student_id').' AS identifier FROM '.demo_ident($table)." WHERE status='Active' AND (password IS NULL OR password='')")->fetchAll() as $row)$rows[]=[$role,$table,$row];
if(!$rows){echo "No missing passwords; existing credentials remain unchanged.\n";exit;}
$dir='/credentials';if(!is_dir($dir))throw new RuntimeException('Mount a private credential directory in this CLI runtime');
$path=$dir.'/'.$school.'-initial-passwords-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.csv';$file=fopen($path,'x');if(!$file)throw new RuntimeException('Cannot save private roster');chmod($path,0600);
$p->beginTransaction();
try{
 fputcsv($file,['Role','Record ID','Login ID','Initial password'],',','"','\\');
 foreach($rows as [$role,$table,$row]){
  $password=bin2hex(random_bytes(16));
  $q=$p->prepare('UPDATE '.demo_ident($table)." SET password=?,must_change_password=1,updated_at=updated_at WHERE id=? AND (password IS NULL OR password='')");$q->execute([password_hash($password,PASSWORD_DEFAULT),$row['id']]);if($q->rowCount()!==1)throw new RuntimeException('Concurrent password initialization');
  fputcsv($file,[$role,$row['id'],$row['identifier'],$password],',','"','\\');
 }
 fflush($file);if(function_exists('fsync'))fsync($file);$p->commit();fclose($file);
 echo count($rows)." missing portal passwords issued; private roster saved outside web storage. Existing passwords were not changed.\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();fclose($file);unlink($path);throw $e;}
