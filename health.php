<?php
require_once __DIR__.'/config/runtime.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try{
 if(erp_is_demo()){
  require_once __DIR__.'/config/demo.php';$pdo=demo_control();
  $q=$pdo->query('SELECT last_run_hour,UNIX_TIMESTAMP(last_success) AS stamp FROM demo_allocator WHERE id=1');$row=$q->fetch();
  if(!$row||time()-(int)$row['stamp']>660)throw new RuntimeException('Demo worker unavailable');
 }elseif(erp_env('ERP_ROLE')==='tenant'){
  require_once __DIR__.'/config/db.php';$pdo->query('SELECT 1')->fetch();
 }elseif(erp_host()===erp_primary_host()){
  require_once __DIR__.'/config/master_db.php';$pdo_master->query('SELECT 1')->fetch();
 }else{http_response_code(404);echo '{"ok":false}';exit;}
 echo '{"ok":true}';
}catch(Throwable $e){http_response_code(503);echo '{"ok":false}';}
