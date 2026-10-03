<?php
session_start();
session_destroy();
$idle = (($_GET['idle'] ?? '') === '1') ? '?idle=1' : '';
header('Location: login.php' . $idle);
exit();
