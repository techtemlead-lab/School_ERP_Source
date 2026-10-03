<?php
/**
 * youtube_oauth_callback.php - YouTube OAuth এর জন্য একটাই fixed redirect URI
 * (Google Cloud Console-এ এই একটা URL-ই রেজিস্টার করা থাকবে, প্রতিটা স্কুলের জন্য না)।
 *
 * কোন স্কুল থেকে অনুরোধ এসেছিল সেটা বোঝা হয় 'state' প্যারামিটার থেকে (HMAC-সাইন করা,
 * config/youtube_upload.php এর yt_oauth_make_state()/yt_oauth_verify_state() দেখুন) —
 * তারপর master DB থেকে সেই স্কুলের DB credential এনে সরাসরি কানেক্ট করা হয়
 * (এই রিকোয়েস্টটা যে ডোমেইন থেকে এসেছে সেটা দিয়ে না, তাই config/db.php ব্যবহার হচ্ছে না)।
 */
require_once __DIR__ . '/config/tenant_direct.php'; // $pdo_master
require_once __DIR__ . '/config/live_class_100ms.php'; // lc_http_request(), lc_ensure_settings_table()
require_once __DIR__ . '/config/youtube_upload.php';

header('Content-Type: text/html; charset=utf-8');

function yoc_page(string $title, string $message, bool $success): void
{
    $color = $success ? '#16a34a' : '#dc2626';
    echo "<!doctype html><html lang='bn'><head><meta charset='utf-8'><title>{$title}</title></head>
    <body style='font-family:sans-serif; max-width:480px; margin:60px auto; text-align:center; color:#1f2937;'>
        <h2 style='color:{$color};'>{$title}</h2>
        <p>{$message}</p>
        <p style='color:#6b7280; font-size:14px;'>এই ট্যাবটা বন্ধ করে আপনার স্কুলের অ্যাডমিন প্যানেলে ফিরে যান।</p>
    </body></html>";
    exit();
}

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error !== '') {
    yoc_page('কানেক্ট করা বাতিল হয়েছে', 'আপনি Google Consent স্ক্রিন থেকে অনুমতি দেননি।', false);
}

$school_code = yt_oauth_verify_state($state);
if (!$school_code) {
    yoc_page('লিংকের মেয়াদ শেষ', 'এই কানেক্ট লিংকটির মেয়াদ শেষ হয়ে গেছে বা এটি সঠিক না। অ্যাডমিন প্যানেল থেকে আবার চেষ্টা করুন।', false);
}

$tenant = get_tenant_by_code($pdo_master, $school_code);
if (!$tenant) {
    yoc_page('স্কুল খুঁজে পাওয়া যায়নি', 'এই স্কুলের তথ্য খুঁজে পাওয়া যায়নি।', false);
}

try {
    $pdo = connect_tenant_db($tenant);
} catch (Throwable $e) {
    yoc_page('সংযোগে সমস্যা', 'স্কুলের ডাটাবেজে সংযোগ করা যায়নি।', false);
}

$result = yt_oauth_exchange_code($code);
if (!$result['ok']) {
    yoc_page('কানেক্ট করা যায়নি', 'ত্রুটি: ' . htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8'), false);
}

yt_ensure_settings_columns($pdo);
$channel_title = null;
$access_token = yt_access_token(['youtube_refresh_token' => $result['refresh_token']]);
if ($access_token) { $channel_title = yt_fetch_channel_title($access_token); }

$pdo->prepare(
    "UPDATE elearning_live_settings SET youtube_refresh_token = ?, youtube_channel_title = ? WHERE id = 1"
)->execute([$result['refresh_token'], $channel_title]);

yoc_page(
    'কানেক্ট সফল হয়েছে ✅',
    $channel_title
        ? 'আপনার YouTube চ্যানেল <b>' . htmlspecialchars($channel_title, ENT_QUOTES, 'UTF-8') . '</b> কানেক্ট হয়ে গেছে। এখন থেকে Live Class এর রেকর্ডিং অটোমেটিক এই চ্যানেলে Unlisted হিসেবে আপলোড হবে।'
        : 'আপনার YouTube চ্যানেল কানেক্ট হয়ে গেছে।',
    true
);
