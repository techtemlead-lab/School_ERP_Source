<?php
require_once __DIR__.'/../config/db.php';
if(!erp_is_demo())erp_stop(404,'Not found.');
if(empty($_SESSION['admin_logged'])&&empty($_SESSION['teacher_logged'])&&empty($_SESSION['student_logged']))erp_stop(403,'Enter a demo portal before exporting.');
$queries=[];
if(($_SESSION['demo_active_role']??'')==='admin' && !empty($_SESSION['admin_logged'])){
 foreach(['students','employees','admissions','academic_classes','academic_sections','academic_subjects','routines','student_daily_attendance','finance_fee_collections','finance_student_monthly_fees','elearning_homework','exam_marks','library_books','library_issues','inventory_items','transport_routes','transport_student_assign','transport_fee_invoices','scholarship_awards','website_events','website_news','notices','institute_settings'] as $table)$queries[$table]=['SELECT * FROM '.chr(96).$table.chr(96),[]];
}elseif(($_SESSION['demo_active_role']??'')==='teacher' && !empty($_SESSION['teacher_logged'])){
 $id=(int)$_SESSION['teacher_id'];$queries=['teacher_profile'=>['SELECT * FROM employees WHERE id=?',[$id]],'my_routines'=>['SELECT * FROM routines WHERE teacher_id=?',[$id]],'my_homework'=>['SELECT * FROM elearning_homework WHERE teacher_id=?',[$id]]];
}elseif(!empty($_SESSION['student_logged'])){
 $id=(int)$_SESSION['student_pk'];$queries=['student_profile'=>['SELECT * FROM students WHERE id=?',[$id]],'my_attendance'=>['SELECT * FROM student_daily_attendance WHERE student_id=?',[$id]],'my_payments'=>['SELECT * FROM finance_fee_collections WHERE student_id=?',[$id]]];
}
$file=tempnam(sys_get_temp_dir(),'demo_export_');$zip=new ZipArchive();
if($zip->open($file,ZipArchive::OVERWRITE)!==true)erp_stop(500,'Export could not be created.');
try{
 foreach($queries as $name=>[$sql,$params]){
  $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
  foreach($rows as &$row)foreach(array_keys($row) as $key)if(preg_match('/password|token|secret|api_key/i',$key))unset($row[$key]);unset($row);
  $zip->addFromString($name.'.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
  if($rows){$csv=fopen('php://temp','r+');fputcsv($csv,array_keys($rows[0]),',','"','');foreach($rows as $row){$values=array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,array_values($row));fputcsv($csv,$values,',','"','');}rewind($csv);$zip->addFromString($name.'.csv',stream_get_contents($csv));fclose($csv);}
 }
 $zip->addFromString('README.txt',"School ERP private demo export.\nFictional records plus your sandbox edits.\nGenerated ".date('c')."\nExpires at ".date('c',demo_reset_at())."\nThis download stays on your device. Passwords and secrets are excluded.\n");
 $zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="school-erp-demo-'.date('Ymd-His').'.zip"');header('Content-Length: '.filesize($file));readfile($file);
}finally{if(is_file($file))unlink($file);}
