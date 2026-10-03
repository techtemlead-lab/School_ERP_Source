<?php
require_once __DIR__ . '/../config/demo.php';
if (!erp_is_demo()) erp_stop(404,'Not found.');
header('Content-Type: application/json');header('Cache-Control: no-store');
echo json_encode(['server_time'=>time(),'reset_at'=>demo_reset_at(),'hour'=>demo_hour()]);
