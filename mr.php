<?php
/**
 * mr.php - money_receipt.php-এর ছোট URL alias, শুধু SMS-এ ক্যারেক্টার (তাই খরচ) বাঁচাতে।
 * SMS-এ পাঠানো লিংক এখন এই ফর্মেটে যায়ঃ  mr.php?t=xxxxxxxxxxxxxxxxxx
 * ?t= কে ?token= হিসেবে money_receipt.php-তে পাস করে দেওয়া হয় — আসল লজিক সব ওখানেই।
 */
$_GET['token'] = $_GET['t'] ?? ($_GET['token'] ?? '');
require __DIR__ . '/money_receipt.php';
