<?php
require_once __DIR__.'/runtime.php';
function erp_login_limit(PDO $pdo,string $scope): void {
 if(erp_is_demo()||($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
 $pdo->exec("CREATE TABLE IF NOT EXISTS auth_limits(bucket CHAR(64) PRIMARY KEY,window_start BIGINT NOT NULL,attempts INT NOT NULL DEFAULT 0) ENGINE=InnoDB");
 $secret=erp_env('LEAD_REQUEST_SALT',erp_env('MASTER_DB_PASS'));
 if(strlen($secret)<32)throw new RuntimeException('Authentication rate-limit secret is not configured');
 $ip=$_SERVER['REMOTE_ADDR']??'unknown';$window=intdiv(time(),900)*900;
 $bucket=hash_hmac('sha256',$scope.'|'.$ip,$secret);
 $pdo->beginTransaction();
 try{
  $pdo->prepare('INSERT IGNORE INTO auth_limits(bucket,window_start,attempts) VALUES(?,?,0)')->execute([$bucket,$window]);
  $q=$pdo->prepare('SELECT window_start,attempts FROM auth_limits WHERE bucket=? FOR UPDATE');$q->execute([$bucket]);$row=$q->fetch(PDO::FETCH_ASSOC);
  $attempts=(int)$row['window_start']===$window?(int)$row['attempts']:0;
  if($attempts>=30){$pdo->commit();header('Retry-After: '.($window+900-time()));erp_stop(429,'Too many login attempts. Try again in a few minutes.');}
  $pdo->prepare('UPDATE auth_limits SET window_start=?,attempts=? WHERE bucket=?')->execute([$window,$attempts+1,$bucket]);$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
