<?php
require_once __DIR__ . '/../config/db.php';
if (!erp_is_demo()) erp_stop(404,'Not found.');
$path=(string)($_GET['path'] ?? '');
$prefix='uploads/demo/'.$GLOBALS['demo_sandbox']['db_name'].'/';
if (!str_starts_with($path,$prefix) || str_contains($path,'..') || str_contains($path,'\\') || str_contains($path,"\0")) erp_stop(404,'File not found.');
$base=realpath(dirname(__DIR__).'/'.$prefix);$file=realpath(dirname(__DIR__).'/'.$path);
$ext=strtolower(pathinfo($file ?: '',PATHINFO_EXTENSION));
if (!$base || !$file || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file) || !in_array($ext,['jpg','jpeg','png','webp','pdf','csv','txt','xlsx'],true)) erp_stop(404,'File not found.');
header('X-Content-Type-Options: nosniff');header('Content-Type: '.(new finfo(FILEINFO_MIME_TYPE))->file($file));
header('Content-Security-Policy: sandbox');
header('Content-Disposition: '.(in_array($ext,['jpg','jpeg','png','webp'],true) ? 'inline':'attachment').'; filename="'.str_replace('"','',basename($file)).'"');
header('Content-Length: '.filesize($file));readfile($file);
