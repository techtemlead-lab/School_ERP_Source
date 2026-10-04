<?php
declare(strict_types=1);
require_once __DIR__.'/../config/provisioning.php';
if(PHP_SAPI!=='cli')exit(1);
$once=in_array('--once',$argv,true);
do {
 try{
  $host=erp_env('ERP_PROVISION_HOST',erp_env('MASTER_DB_HOST','db'));
  $root=new PDO('mysql:host='.$host.';charset=utf8mb4',erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $tenantHost=erp_env('ERP_TENANT_PROVISION_HOST',$host);
  $schoolRoot=$tenantHost===$host?$root:new PDO('mysql:host='.$tenantHost.';charset=utf8mb4',erp_env('ERP_TENANT_PROVISION_USER'),erp_env('ERP_TENANT_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $master=erp_env('MASTER_DB_NAME','school_master_db');$root->exec('USE '.demo_ident($master));erp_provision_schema($root);
  if((int)$root->query("SELECT GET_LOCK('school_erp_provisioner',0)")->fetchColumn()!==1)throw new RuntimeException('Provisioner already running');
  try {
   $job=$root->query("SELECT * FROM provision_jobs WHERE status IN ('queued','running') ORDER BY id LIMIT 1")->fetch();
   if($job){
    $data=json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);
    $db=$data['db_name'];$user=$data['db_user'];
    if(!preg_match('/^sc_[a-z][a-z0-9]{2,29}$/',$db)||!preg_match('/^su_[a-f0-9]{20}$/',$user)||$data['db_host']!==erp_env('ERP_TENANT_DB_HOST',$host))throw new RuntimeException('Invalid provisioning job scope');
    $root->prepare("UPDATE provision_jobs SET status='running',error=NULL WHERE id=?")->execute([$job['id']]);
    $cleanup=function()use($root,$schoolRoot,$master,$job,$db,$user):void{
     $root->exec('USE '.demo_ident($master));$q=$root->prepare('SELECT COUNT(*) FROM tenants WHERE db_name=?');$q->execute([$db]);if((int)$q->fetchColumn()>0)return;
     $q=$root->prepare('SELECT created_db,created_user FROM provision_jobs WHERE id=?');$q->execute([$job['id']]);$flags=$q->fetch();
     if($flags['created_db'])$schoolRoot->exec('DROP DATABASE IF EXISTS '.demo_ident($db));
     if($flags['created_user'])$schoolRoot->exec('DROP USER IF EXISTS '.$schoolRoot->quote($user)."@'%'");
     $root->prepare('UPDATE provision_jobs SET created_db=0,created_user=0 WHERE id=?')->execute([$job['id']]);
    };
    try {
     // Restarted jobs may own an incomplete database; registered tenants are never deleted.
     if($job['status']==='running')$cleanup();
     $q=$schoolRoot->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$db]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Database already exists');
     $schoolRoot->exec('CREATE DATABASE '.demo_ident($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
     $root->prepare('UPDATE provision_jobs SET created_db=1 WHERE id=?')->execute([$job['id']]);
     $schoolRoot->exec('USE '.demo_ident($db));erp_install_empty_school($schoolRoot,$data);$root->exec('USE '.demo_ident($master));
     $schoolRoot->exec('CREATE USER '.$schoolRoot->quote($user)."@'%' IDENTIFIED BY ".$schoolRoot->quote($data['db_pass']));
     $root->prepare('UPDATE provision_jobs SET created_user=1 WHERE id=?')->execute([$job['id']]);
     $schoolRoot->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON '.demo_ident($db).'.* TO '.$schoolRoot->quote($user)."@'%'");
     $root->beginTransaction();erp_register_school($root,$data);
     $root->prepare("UPDATE provision_jobs SET status='complete',payload='{}' WHERE id=?")->execute([$job['id']]);$root->commit();
     echo 'Provisioned job '.(int)$job['id'].PHP_EOL;
    }catch(Throwable $e){
     if($root->inTransaction())$root->rollBack();$cleanup();
     $root->prepare("UPDATE provision_jobs SET status='failed',error=?,payload='{}' WHERE id=?")->execute(['School setup failed safely; check database availability and retry with a new request.',$job['id']]);
     error_log('Provisioning job '.(int)$job['id'].' failed: '.get_class($e));
    }
   }
   $root->exec("INSERT INTO worker_health(worker,last_success) VALUES('provisioner',NOW()) ON DUPLICATE KEY UPDATE last_success=NOW()");
  }finally{$root->query("SELECT RELEASE_LOCK('school_erp_provisioner')");}
 }catch(Throwable $e){error_log('Provisioning worker unavailable: '.get_class($e));if($once)exit(1);}
 if(!$once)sleep(5);
}while(!$once);
