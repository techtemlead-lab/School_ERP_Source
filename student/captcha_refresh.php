<?php
// student/captcha_refresh.php - লগইন পেজের "রিফ্রেশ" বাটন এখান থেকে AJAX কল করে
// নতুন ভেরিফিকেশন কোড আনে, পুরো পেজ রিলোড করা লাগে না
session_start();
require_once __DIR__ . '/../config/captcha.php';

header('Content-Type: application/json; charset=utf-8');

$code = captcha_new('captcha_student');
echo json_encode(['svg' => captcha_svg_markup($code)]);
