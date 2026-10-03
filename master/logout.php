<?php
require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
session_destroy();
$idle = (($_GET['idle'] ?? '') === '1') ? '?idle=1' : '';
header('Location: login.php' . $idle);
exit();
