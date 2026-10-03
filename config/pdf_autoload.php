<?php
// config/pdf_autoload.php
// -----------------------------------------------------------------
// Bulk Result PDF Export ফিচারের জন্য mPDF (এবং তার কয়েকটা নির্ভরতা) লোড করার একটা
// ছোট, Composer-মুক্ত PSR-4 অটোলোডার। এই প্রজেক্টে Composer ব্যবহার হয় না (শেয়ার্ড
// হোস্টিং, zip আপলোড করেই ডিপ্লয় হয়), তাই পুরো লাইব্রেরির সোর্স কোড সরাসরি
// config/pdf_lib/ ফোল্ডারে রাখা হয়েছে এবং এখানে ম্যানুয়ালি namespace ম্যাপ করে দেওয়া
// হলো — কোনো `composer install` লাগবে না, zip এক্সট্র্যাক্ট করলেই কাজ করবে।
//
// note: প্রথমে Dompdf দিয়ে চেষ্টা করা হয়েছিল, কিন্তু Dompdf বাংলা যুক্তাক্ষর/জটিল
// শেপিং (conjuncts) ঠিকভাবে আঁকতে পারে না (আলাদা আলাদা box-এর মতো দেখায়) — তাই বাংলা
// script-এর জন্য বিশেষভাবে তৈরি OTL শেপিং ইঞ্জিনসহ mPDF ব্যবহার করা হচ্ছে, যেটা বাংলা
// প্রপারলি সাপোর্ট করে (FreeSerif ফন্ট দিয়ে, যেটা mPDF নিজেই বাংলার জন্য রেকমেন্ড করে)।
// -----------------------------------------------------------------

if (!function_exists('rc_pdf_autoload_register')) {
function rc_pdf_autoload_register(): void
{
    static $registered = false;
    if ($registered) return;
    $registered = true;

    $base = __DIR__ . '/pdf_lib';
    $map = [
        'Mpdf\\PsrHttpMessageShim\\' => $base . '/PsrHttpMessageShim',
        'Mpdf\\PsrLogAwareTrait\\'   => $base . '/PsrLogAwareTrait',
        'Mpdf\\'                     => $base . '/Mpdf',
        'DeepCopy\\'                 => $base . '/DeepCopy',
        'Psr\\Http\\Message\\'       => $base . '/Psr/Http/Message',
        'Psr\\Log\\'                 => $base . '/Psr/Log',
        'setasign\\Fpdi\\'           => $base . '/Fpdi',
    ];

    spl_autoload_register(function ($class) use ($map) {
        foreach ($map as $prefix => $dir) {
            if (strncmp($prefix, $class, strlen($prefix)) === 0) {
                $relative = substr($class, strlen($prefix));
                $file = $dir . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                    return;
                }
            }
        }
    });

    // composer-এর "autoload.files" এন্ট্রিগুলো (namespace ছাড়া গ্লোবাল ফাংশন ফাইল) —
    // এগুলো ম্যানুয়ালি require করে দেওয়া হলো যেহেতু composer নেই।
    require_once $base . '/Mpdf/functions.php';
    require_once $base . '/DeepCopy/deep_copy.php';
    if (!function_exists('random_bytes')) {
        // PHP 7+ এ random_bytes() বিল্ট-ইন, তাই সাধারণত এই ফাইলটা লাগবে না — শুধু
        // খুব পুরনো PHP এর জন্য fallback হিসেবে রাখা হলো (paragonie/random_compat)।
        require_once $base . '/mpdf_random_compat.php';
    }
}
}
