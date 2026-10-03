<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../config/runtime.php';
try {
 if(($argv[1]??'')==='demo'){
  require_once __DIR__.'/../config/demo_worker.php';$pdo=demo_provisioner();$pdo->exec('USE '.demo_ident(erp_env('DEMO_CONTROL_DB','school_demo_control')));
  $stamp=$pdo->query('SELECT UNIX_TIMESTAMP(last_success) FROM demo_allocator WHERE id=1')->fetchColumn();$max=660;
 }else{
  $pdo=new PDO('mysql:host='.erp_env('ERP_PROVISION_HOST').';dbname='.erp_env('MASTER_DB_NAME','school_master_db'),erp_env('ERP_PROVISION_USER'),erp_env('ERP_PROVISION_PASS'));
  $stamp=$pdo->query("SELECT UNIX_TIMESTAMP(last_success) FROM worker_health WHERE worker='provisioner'")->fetchColumn();$max=120;
 }
 exit($stamp&&time()-(int)$stamp<$max?0:1);
}catch(Throwable $e){exit(1);}
