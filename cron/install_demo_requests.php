<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/master_db.php';
require_once __DIR__.'/../config/demo_requests.php';
demo_requests_install($pdo_master);
echo 'Dedicated demo request tables installed without removing existing rows.'.PHP_EOL;
