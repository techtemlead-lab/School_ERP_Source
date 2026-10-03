<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='local')exit(1);
require_once __DIR__.'/../config/demo_worker.php';
$pdo=demo_provisioner();
$rows=$pdo->query('SELECT db_name FROM school_demo_control.demo_sandboxes WHERE ready=1')->fetchAll();
foreach($rows as $row){$pdo->exec('USE '.demo_ident($row['db_name']));$pdo->exec("UPDATE institute_settings SET banner_path='' WHERE id=1");$pdo->exec("UPDATE message_corner SET role_key='principal',photo_path='assets/demo-avatar.svg'");$pdo->exec("UPDATE employees SET photo_path='assets/demo-avatar.svg'");$pdo->exec("UPDATE students SET photo_path='assets/demo-avatar.svg'");}
echo 'Local fictional school presentation updated.'.PHP_EOL;
