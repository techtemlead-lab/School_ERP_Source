<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/demo_worker.php';
try{demo_maintain(in_array('--replenish',$argv,true));}catch(Throwable $e){fwrite(STDERR,'Demo worker failed: '.$e->getMessage().PHP_EOL);exit(1);}
