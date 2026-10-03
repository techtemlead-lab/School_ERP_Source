<?php
/**
 * master/index.php
 * -----------------------------------------------------------
 * master/ ফোল্ডারে সরাসরি কেউ ঢুকলে (যেমন .../master/) এখানে আসবে।
 * এই ফোল্ডারের আসল "হোমপেজ" বলে কিছু নেই — লগইন করা থাকলে dashboard-এ,
 * না থাকলে login.php-তে পাঠিয়ে দেওয়া হচ্ছে।
 *
 * এই ফাইলটা না থাকলে Apache directory listing (Index of /master/)
 * দেখিয়ে দিত, যেটা নিরাপত্তার দিক থেকেও সমস্যা।
 */

require_once __DIR__ . '/../config/runtime.php';
erp_session_start();

if (!empty($_SESSION['master_user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
