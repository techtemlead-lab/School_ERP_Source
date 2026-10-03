<?php
/**
 * config/payment_settings.php
 * -----------------------------------------------------------
 * তোমার নিজের bKash/Nagad নাম্বার আর প্ল্যানের দাম এখানে বসাও।
 * প্রতিটা স্কুল renewal পেজে এই তথ্যই দেখবে টাকা পাঠানোর জন্য।
 */

return [
    'bkash_number'   => '01XXXXXXXXX',   // তোমার bKash (Personal/Merchant) নাম্বার
    'bkash_type'     => 'Personal',       // 'Personal' অথবা 'Merchant'
    'nagad_number'   => '01XXXXXXXXX',   // তোমার Nagad নাম্বার
    'nagad_type'     => 'Personal',

    'support_phone'  => '01XXXXXXXXX',   // পেমেন্ট নিয়ে সমস্যা হলে যোগাযোগের নাম্বার
    'support_email'  => 'support@yourdomain.com',

    // প্ল্যান অনুযায়ী মাসিক দাম (টাকা) — renewal পেজে দেখানো হবে
    'plan_prices' => [
        'Trial'    => 0,
        'Basic'    => 1500,
        'Standard' => 3000,
        'Premium'  => 5000,
    ],

    // Trial period কতদিনের (master/create_tenant.php নতুন স্কুল বানানোর সময় এটা ব্যবহার করে)
    'trial_days' => 14,
];
