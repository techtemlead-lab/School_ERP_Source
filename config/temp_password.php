<?php
/**
 * config/temp_password.php
 * Admin থেকে Student/Employee-কে দেওয়ার জন্য র‍্যান্ডম অস্থায়ী পাসওয়ার্ড।
 * (Production-এ "Student/Employee ID = পাসওয়ার্ড" ডিফল্ট লগইন বন্ধ থাকে, তাই reset/SMS-এ
 * এই পাসওয়ার্ড তৈরি হয়; প্রথম লগইনের পর ইউজারকে নিজের পাসওয়ার্ড বদলাতে হয়।)
 */
if (!function_exists('erp_temp_password')) {
    function erp_temp_password(int $len = 10): string
    {
        // দেখতে মিলে যায় এমন অক্ষর (0/O, 1/l/I) বাদ
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) { $out .= $chars[random_int(0, $max)]; }
        return $out;
    }
}
