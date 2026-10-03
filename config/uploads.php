<?php
/**
 * config/uploads.php
 * -----------------------------------------------------------
 * SaaS multi-tenant হওয়ার পর প্রতিটা স্কুলের uploaded ফাইল
 * (student photo, institute logo, ID card template ইত্যাদি)
 * আলাদা রাখার জন্য। প্রতিটা tenant এর জন্য uploads/{school_code}/...
 * ফোল্ডারে ফাইল যাবে, যাতে এক স্কুলের ছবি আরেক স্কুলের সাথে মিক্স
 * না হয়ে যায়।
 *
 * এই ফাইলটা config/db.php এর পরে লোড হতে হবে (কারণ $GLOBALS['tenant']
 * ততক্ষণে সেট হয়ে গেছে config/db.php থেকে)।
 */

/**
 * তেমন কোনো module folder নাম (যেমন 'students', 'institute') দিলে
 * সেই স্কুলের জন্য 'uploads/{school_code}/students' রিটার্ন করে।
 * SaaS না থাকলে (tenant সেট না থাকলে) পুরনো আচরণ ধরে রাখতে 'legacy' ফোল্ডার ব্যবহার হয়।
 */
function tenant_upload_rel(string $sub = ''): string
{
    $code = $GLOBALS['tenant']['school_code'] ?? 'legacy';
    $sub = trim($sub, '/');
    return 'uploads/' . (function_exists('erp_is_demo') && erp_is_demo() ? 'demo/' : '') . $code . ($sub !== '' ? '/' . $sub : '');
}

/**
 * উপরেরটার filesystem absolute path ভার্সন। $base_dir হলো সেই ফাইলটার
 * __DIR__ যেখান থেকে কল হচ্ছে project root পর্যন্ত কত ধাপ (../) লাগবে সেটা
 * আগে থেকেই মিলিয়ে পাঠাতে হবে — যেমন admin/ ফোল্ডারের কোনো ফাইল থেকে
 * কল করলে $project_root_dir = __DIR__ . '/..'
 */
function tenant_upload_abs(string $project_root_dir, string $sub = ''): string
{
    return rtrim($project_root_dir, '/') . '/' . tenant_upload_rel($sub) . '/';
}
