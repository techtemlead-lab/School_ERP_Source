<?php
declare(strict_types=1);
require_once __DIR__.'/../config/demo_worker.php';
if(erp_env('APP_ENV')!=='local'||PHP_SAPI!=='cli')throw new RuntimeException('Local CLI test only');
$pdo=demo_provisioner();$pdo->exec('USE school_demo_control');
function check(bool $result,string $name):void{if(!$result)throw new RuntimeException('FAILED: '.$name);echo 'PASS '.$name.PHP_EOL;}
$mode=$argv[1]??'assert';
if($mode==='prepare'){
 $pdo->exec('UPDATE demo_sandboxes SET ready=0 WHERE token_hash IS NULL');
 demo_maintain();exit;
}
$row=$pdo->query('SELECT * FROM demo_sandboxes WHERE ready=1 AND hour_key='.demo_hour().' ORDER BY id DESC LIMIT 1')->fetch();
check((bool)$row,'seeded sandbox available');
$db=$row['db_name'];$scoped=new PDO('mysql:host=db;dbname='.$db.';charset=utf8mb4',$row['db_user'],$row['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
check((int)$scoped->query('SELECT COUNT(*) FROM students')->fetchColumn()===240,'240 fictional students');
check((int)$scoped->query('SELECT COUNT(*) FROM exam_marks')->fetchColumn()===720,'sample exam marks');
check((int)$scoped->query("SELECT COUNT(*) FROM employees")->fetchColumn()===24,'24 fictional teachers');
check((int)$scoped->query("SELECT COUNT(*) FROM student_daily_attendance WHERE attendance_date='".date('Y-m-d')."'")->fetchColumn()===240,'sample attendance uses target reset day');
foreach(['admin','employees','students','system_users'] as $table){
 check((int)$scoped->query('SELECT COUNT(*) FROM '.demo_ident($table))->fetchColumn()>0,'password guard fixture '.$table);
 try{$scoped->exec('UPDATE '.demo_ident($table)." SET password='forbidden-change'");throw new RuntimeException('Password update permitted');}
 catch(PDOException $e){check(($e->errorInfo[1]??0)===1644,'password lock '.$table);}
}
try{$scoped->query('SELECT * FROM school_master_db.master_users');throw new RuntimeException('Master data exposed');}catch(PDOException $e){check(in_array($e->errorInfo[1]??0,[1044,1142],true),'master database inaccessible');}
$other=$pdo->query('SELECT db_name FROM demo_sandboxes WHERE ready=1 AND db_name<>'.$pdo->quote($db).' LIMIT 1')->fetchColumn();
check((bool)$other,'second sandbox exists');
try{$scoped->query('SELECT * FROM '.demo_ident($other).'.students');throw new RuntimeException('Other visitor data exposed');}catch(PDOException $e){check(in_array($e->errorInfo[1]??0,[1044,1142],true),'other visitor database inaccessible');}
try{$scoped->exec('DROP TRIGGER '.demo_ident('demo_lock_admin'));throw new RuntimeException('Password guard removable');}catch(PDOException $e){check(($e->errorInfo[1]??0)===1142,'password guard cannot be removed');}
check((int)$scoped->query('SELECT COUNT(*) FROM library_books')->fetchColumn()===60,'60 library books');
check((int)$scoped->query('SELECT COUNT(*) FROM library_issues')->fetchColumn()===120,'120 library loans');
check((int)$scoped->query('SELECT COUNT(*) FROM transport_student_assign')->fetchColumn()===36,'36 transport assignments');
check((int)$scoped->query('SELECT COUNT(*) FROM student_daily_attendance')->fetchColumn()===7200,'30 days of sample attendance');
check(demo_reset_at(3599)===3600&&demo_reset_at(3600)===7200,'reset follows clock hour');
$pdo->exec('USE '.demo_ident($db));
try{$pdo->query("SELECT s.id FROM student_daily_attendance sda JOIN students s ON s.id=sda.student_id LEFT JOIN device_attendance_logs dl ON dl.person_uid=s.rfid_card_no OR dl.person_uid=s.tracking_id OR dl.person_uid=s.device_serial LEFT JOIN attendance_devices zdev ON zdev.id=dl.device_id WHERE sda.status='Present' ORDER BY sda.updated_at DESC LIMIT 5");check(true,'dashboard attendance query');}catch(PDOException $e){throw new RuntimeException('Dashboard attendance: '.$e->getMessage());}
$pdo->exec('USE school_demo_control');
$count=(int)$pdo->query('SELECT COUNT(*) FROM school_master_db.demo_requests')->fetchColumn();
$expired=$pdo->query('SELECT * FROM demo_sandboxes WHERE token_hash IS NULL AND hour_key='.demo_hour().' LIMIT 1')->fetch();
check((bool)$expired,'unused sandbox available for expiry test');
$dir=dirname(__DIR__).'/uploads/demo/'.$expired['db_name'];file_put_contents($dir.'/expiry-test.txt','erase on reset');
$pdo->prepare('UPDATE demo_sandboxes SET hour_key=? WHERE id=?')->execute([demo_hour()-1,$expired['id']]);
$sessionFile='/var/lib/php/sessions/sess_clockexpiry'.bin2hex(random_bytes(12));file_put_contents($sessionFile,'fictional expired input');touch($sessionFile,(demo_hour()-1)*3600+1);
demo_maintain();
check(!is_dir($dir),'scheduled worker deletes expired uploads without HTTP traffic');
check(!is_file($sessionFile),'scheduled worker deletes expired demo sessions without HTTP traffic');
$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute([$expired['db_name']]);check((int)$q->fetchColumn()===0,'scheduled worker drops expired database');
$q=$pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?');$q->execute([$expired['db_user']]);check((int)$q->fetchColumn()===0,'scheduled worker revokes expired database user');
check((int)$pdo->query('SELECT COUNT(*) FROM school_master_db.demo_requests')->fetchColumn()===$count,'main requests survive demo cleanup');
echo 'Database and clock reset checks completed.'.PHP_EOL;
