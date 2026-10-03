<?php
declare(strict_types=1);
require_once __DIR__.'/demo.php';require_once __DIR__.'/demo_seed.php';
function demo_provisioner(): PDO {
 if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
 return new PDO('mysql:host='.erp_env('DEMO_DB_HOST','db').';charset=utf8mb4',erp_env('DEMO_PROVISION_USER'),erp_env('DEMO_PROVISION_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function demo_remove_tree(string $path): void {
 if(is_link($path)){unlink($path);return;}if(!is_dir($path))return;
 foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $item){if($item->isDir()&&!$item->isLink())demo_remove_tree($item->getPathname());else unlink($item->getPathname());}rmdir($path);
}
function demo_maintain(bool $replenish=false,bool $prewarm=false): void {
 $lock=fopen(erp_env('DEMO_WORKER_LOCK','/tmp/school-erp-demo-worker.lock'),'c');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another demo worker is running');
 try {
  $pdo=demo_provisioner();if((int)$pdo->query("SELECT GET_LOCK('school_erp_demo_worker',0)")->fetchColumn()!==1)throw new RuntimeException('Another demo worker is running');$control=erp_env('DEMO_CONTROL_DB','school_demo_control');
  $pdo->exec('CREATE DATABASE IF NOT EXISTS '.demo_ident($control).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.demo_ident($control));
  $pdo->exec("CREATE TABLE IF NOT EXISTS demo_sandboxes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,hour_key BIGINT NOT NULL,db_name VARCHAR(64) NOT NULL UNIQUE,db_user VARCHAR(64) NOT NULL,db_pass VARCHAR(100) NOT NULL,token_hash CHAR(64) NULL UNIQUE,ip_hash CHAR(64) NULL,ready TINYINT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(hour_key,ready),INDEX(ip_hash,hour_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS demo_allocator(id INT PRIMARY KEY,last_run_hour BIGINT NULL) ENGINE=InnoDB");$pdo->exec("INSERT IGNORE INTO demo_allocator(id) VALUES(1)");
  $cols=$pdo->query('SHOW COLUMNS FROM demo_allocator')->fetchAll(PDO::FETCH_COLUMN);if(!in_array('last_success',$cols,true))$pdo->exec('ALTER TABLE demo_allocator ADD COLUMN last_success TIMESTAMP NULL');
  $current=demo_hour();$hour=$prewarm?$current+1:$current;$last=$pdo->query('SELECT last_run_hour FROM demo_allocator WHERE id=1')->fetchColumn();
  if($replenish&&(int)$last!==$current)throw new RuntimeException('Hourly reset has not run. Replenishment refuses to bypass it.');
  if(!$replenish && !$prewarm){
   foreach(glob('/var/lib/php/sessions/sess_*') ?: [] as $sessionFile) {
    if(is_file($sessionFile) && !is_link($sessionFile) && filemtime($sessionFile)<$current*3600)unlink($sessionFile);
   }
   foreach($pdo->query('SELECT * FROM demo_sandboxes WHERE hour_key<'.(int)$hour.' OR ready=0')->fetchAll() as $row){
    if(!preg_match('/^ds_[a-f0-9]{32}$/',$row['db_name'])||!preg_match('/^du_[a-f0-9]{24}$/',$row['db_user']))throw new RuntimeException('Unsafe sandbox name');
    $pdo->exec('DROP DATABASE IF EXISTS '.demo_ident($row['db_name']));$pdo->exec('DROP USER IF EXISTS '.$pdo->quote($row['db_user'])."@'%'");
    demo_remove_tree(dirname(__DIR__).'/uploads/demo/'.$row['db_name']);$pdo->prepare('DELETE FROM demo_sandboxes WHERE id=?')->execute([$row['id']]);
   }
   $pdo->prepare('UPDATE demo_allocator SET last_run_hour=? WHERE id=1')->execute([$hour]);
  }
  $parent=dirname(__DIR__).'/uploads/demo';
  if(!is_dir($parent))mkdir($parent,0770,true);
  chmod($parent,0770);chown($parent,33);chgrp($parent,33);
  $max=max(1,min(200,(int)erp_env('DEMO_MAX_SANDBOXES','60')));$pool=max(1,min($max,(int)erp_env('DEMO_POOL_SIZE','5')));
  $total=(int)$pdo->query('SELECT COUNT(*) FROM demo_sandboxes WHERE hour_key='.(int)$hour)->fetchColumn();
  $free=(int)$pdo->query('SELECT COUNT(*) FROM demo_sandboxes WHERE hour_key='.(int)$hour.' AND token_hash IS NULL AND ready=1')->fetchColumn();
  $create=min($pool-$free,$max-$total);
  for($i=0;$i<$create;$i++){
   $id=bin2hex(random_bytes(16));$db='ds_'.$id;$user='du_'.substr($id,0,24);$pass=bin2hex(random_bytes(24));
   $pdo->prepare('INSERT INTO demo_sandboxes(hour_key,db_name,db_user,db_pass) VALUES(?,?,?,?)')->execute([$hour,$db,$user,$pass]);
   $pdo->exec('CREATE DATABASE '.demo_ident($db).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
   $pdo->exec('USE '.demo_ident($db));demo_seed_database($pdo,$hour*3600);$pdo->exec('USE '.demo_ident($control));
   $pdo->exec('CREATE USER '.$pdo->quote($user)."@'%' IDENTIFIED BY ".$pdo->quote($pass));
   $pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON '.demo_ident($db).'.* TO '.$pdo->quote($user)."@'%'");
   $dir=dirname(__DIR__).'/uploads/demo/'.$db;if(!is_dir($dir))mkdir($dir,0770,true);chmod($dir,0770);chown($dir,33);chgrp($dir,33);
   $pdo->prepare('UPDATE demo_sandboxes SET ready=1 WHERE db_name=?')->execute([$db]);
  }
  $pdo->exec('UPDATE demo_allocator SET last_success=NOW() WHERE id=1');
  echo date('c').' demo maintenance complete; hour='.$hour.' created='.max(0,$create).PHP_EOL;
 } finally {if(isset($pdo))$pdo->query("SELECT RELEASE_LOCK('school_erp_demo_worker')");flock($lock,LOCK_UN);fclose($lock);}
}
