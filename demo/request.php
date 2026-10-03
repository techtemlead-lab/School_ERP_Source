<?php
require_once __DIR__.'/../config/runtime.php';
if (erp_host()!==erp_primary_host()) erp_stop(404,'Not found.');
erp_session_start();require_once __DIR__.'/../config/csrf.php';
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { header('Location: /demo/#request');exit; }
$data=[];foreach(['name','school','email','phone','students','plan','needs'] as $key) $data[$key]=is_scalar($_POST[$key]??'')?trim((string)($_POST[$key]??'')):'';
function request_finish(bool $ok,string $message,array $data=[]): never {
    $_SESSION['demo_request_flash']=['ok'=>$ok,'message'=>$message];
    if (!$ok) $_SESSION['demo_request_old']=$data;
    header('Location: /demo/#request',true,303);exit;
}
if (!csrf_verify($_POST['csrf_token']??null)) request_finish(false,'Please reload the page and submit again.',$data);
if (!empty($_POST['website'])) request_finish(true,'Thank you. Your request has been received.');
if (($data['name']==='' || mb_strlen($data['name'])>120) || ($data['school']==='' || mb_strlen($data['school'])>200) || !filter_var($data['email'],FILTER_VALIDATE_EMAIL) || strlen($data['email'])>150 || !preg_match('/^[+0-9 ()-]{7,30}$/',$data['phone']) || !ctype_digit($data['students']) || (int)$data['students']<1 || (int)$data['students']>100000 || mb_strlen($data['needs'])>2000 || !in_array($data['plan'],['','Basic','Standard','Premium'],true) || ($_POST['consent']??'')!=='1') request_finish(false,'Check your name, institution, email, phone and student count, then accept the privacy policy.',$data);
require_once __DIR__.'/../config/master_db.php';
require_once __DIR__.'/../config/demo_requests.php';
try {
    $salt=erp_env('LEAD_REQUEST_SALT');
    if (strlen($salt)<32) throw new RuntimeException('Lead request salt not configured');
    $ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',$salt);
    $pdo_master->beginTransaction();
    $pdo_master->prepare('INSERT IGNORE INTO demo_request_limits (ip_hash,window_start,request_count) VALUES (?,NOW(),0)')->execute([$ip]);
    $stmt=$pdo_master->prepare('SELECT *,TIMESTAMPDIFF(SECOND,window_start,NOW()) AS elapsed FROM demo_request_limits WHERE ip_hash=? FOR UPDATE');$stmt->execute([$ip]);$limit=$stmt->fetch();
    if ((int)$limit['elapsed']>=3600) { $pdo_master->prepare('UPDATE demo_request_limits SET request_count=0,window_start=NOW() WHERE ip_hash=?')->execute([$ip]);$limit['request_count']=0; }
    if ((int)$limit['request_count']>=3) { $pdo_master->rollBack();request_finish(false,'This connection has already sent three requests this hour. Please try again later.',$data); }
    $pdo_master->prepare('INSERT INTO demo_requests (contact_name,school_name,email,phone,student_count,plan,requirements,consented_at) VALUES (?,?,?,?,?,?,?,NOW())')->execute([$data['name'],$data['school'],$data['email'],$data['phone'],(int)$data['students'],$data['plan'],$data['needs']]);
    $pdo_master->prepare('UPDATE demo_request_limits SET request_count=request_count+1 WHERE ip_hash=?')->execute([$ip]);
    $pdo_master->commit();request_finish(true,'Thank you. Your dedicated demo request has been saved for the ERP team to review.');
} catch(Throwable $e) { if($pdo_master->inTransaction()) $pdo_master->rollBack();error_log('Demo request failed: '.$e->getMessage());request_finish(false,'Your request could not be saved. Please try again shortly.',$data); }
