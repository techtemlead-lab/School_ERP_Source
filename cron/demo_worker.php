<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/demo_worker.php';
$lastHour=null;$lastPool=null;
while(true){
 $hour=demo_hour();$pool=intdiv(time(),300);
 try{if($hour!==$lastHour){demo_maintain(false);if((int)date('i')>=55)demo_maintain(true,true);$lastHour=$hour;$lastPool=$pool;}elseif($pool!==$lastPool){demo_maintain(true);if((int)date('i')>=55)demo_maintain(true,true);$lastPool=$pool;}}
 catch(Throwable $e){fwrite(STDERR,date('c').' Demo worker failed: '.$e->getMessage().PHP_EOL);}
 sleep(max(1,min(30,($hour+1)*3600-time(),($pool+1)*300-time())));
}
