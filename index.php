<?php
require_once __DIR__ . '/config/runtime.php';
if (erp_host() === erp_primary_host()) { require __DIR__ . '/landing/index.php'; exit; }

require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/sms.php';
require_once __DIR__ . '/config/teacher_display.php';
require_once __DIR__ . '/config/website_facilities.php';
require_once __DIR__ . '/config/lang.php';
require_once __DIR__ . '/config/map_embed.php';
require_once __DIR__ . '/config/home_messages.php';
sms_ensure_schema($pdo);
hm_ensure_schema($pdo);

// Website Module থেকে ডাইনামিক ডাটা — Slider / Notice / Gallery
$site_sliders = $pdo->query("SELECT * FROM sliders WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// Self-heal: notices টেবিল আগে তৈরি না থাকলে বানানো, আর branch_id কলাম
// (পুরনো ইনস্টলেশনে ছিল না) না থাকলে অ্যাড করা — নাহলে নিচের JOIN-এ
// "Unknown column notices.branch_id" Fatal error হতো (পাবলিক হোমপেজ ক্র্যাশ)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        notice_date DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$notices_cols = $pdo->query("SHOW COLUMNS FROM notices")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('branch_id', $notices_cols, true)) {
    $pdo->exec("ALTER TABLE notices ADD COLUMN branch_id INT NULL AFTER notice_date");
}

$site_notices = $pdo->query("SELECT notices.*, branches.short_name AS branch_short_name FROM notices LEFT JOIN branches ON branches.id = notices.branch_id WHERE notices.is_active = 1 ORDER BY notice_date DESC, notices.id DESC LIMIT 6")->fetchAll();
$site_gallery = $pdo->query("SELECT * FROM gallery_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// Website Module থেকে বাকি ডাইনামিক ডাটা — Why Choose / Principal Message / Events /
// Useful Links / Social Media / Board Result (আগে এগুলো Admin Panel-এ সেভ হলেও পাবলিক
// সাইটে কখনো দেখানো হতো না — টেমপ্লেটে হার্ডকোডেড ডেমো কনটেন্ট ছিল, DB Query-ই ছিল না)
function site_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return $cache[$table] = true;
    } catch (PDOException $e) {
        return $cache[$table] = false;
    }
}
$site_why_choose = site_table_exists($pdo, 'website_why_choose')
    ? $pdo->query("SELECT * FROM website_why_choose WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll()
    : [];
// বাণী — Home Page Settings-এ On থাকা পদবির Active বাণী, পদবির Order অনুযায়ী; হোমপেজে সর্বোচ্চ ৩টা
$site_messages_all = hm_get_messages($pdo);
$site_messages     = array_slice($site_messages_all, 0, 3);
$site_message      = $site_messages[0] ?? false; // Govt Theme-1 (একটাই বাণী দেখায়) এটা ব্যবহার করে
$site_events = site_table_exists($pdo, 'website_events')
    ? $pdo->query("SELECT * FROM website_events WHERE is_active = 1 ORDER BY event_date ASC LIMIT 3")->fetchAll()
    : [];
$site_facilities = wf_get_active($pdo); // Website Module > Facilities List (প্রথমবার ডিফল্ট ৬টা অটো বসে)
$site_useful_links = site_table_exists($pdo, 'website_useful_links')
    ? $pdo->query("SELECT * FROM website_useful_links WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll()
    : [];
$site_social_media = site_table_exists($pdo, 'website_social_media')
    ? $pdo->query("SELECT * FROM website_social_media WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll()
    : [];
$site_board_results = site_table_exists($pdo, 'website_board_results')
    ? $pdo->query("SELECT * FROM website_board_results WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll()
    : [];
// Website Module > News / Career / Album / About Us Content — এই ৫টা ফিচারের জন্য হোমপেজে
// আগে কোনো UI সেকশনই ছিল না (Admin এ সেভ হতো, কিন্তু কোথাও দেখানো হতো না)
$site_news = site_table_exists($pdo, 'website_news')
    ? $pdo->query("SELECT * FROM website_news WHERE is_active = 1 ORDER BY news_date DESC, id DESC LIMIT 6")->fetchAll()
    : [];
$site_careers = site_table_exists($pdo, 'website_careers')
    ? $pdo->query("SELECT * FROM website_careers WHERE is_active = 1 ORDER BY id DESC")->fetchAll()
    : [];
$site_albums = site_table_exists($pdo, 'website_albums')
    ? $pdo->query("SELECT * FROM website_albums WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll()
    : [];
// শুধু কোনো মেনুর সাথে যুক্ত নয় (menu_id = 0) এমন কন্টেন্টই হোমপেজে দেখাবে — মেনু/সাব-মেনুর কন্টেন্ট page.php তে যায়
// (menu_id কলাম এখনো তৈরি না হয়ে থাকলে — মেনু সিস্টেম প্রথমবার লোড হওয়ার আগে — পুরনো কোয়েরিই চলবে)
$site_about_content = [];
if (site_table_exists($pdo, 'website_contents')) {
    try {
        $site_about_content = $pdo->query("SELECT * FROM website_contents WHERE is_active = 1 AND menu_id = 0 ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (PDOException $e) {
        $site_about_content = $pdo->query("SELECT * FROM website_contents WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    }
}
function site_social_icon_svg($platform)
{
    $icons = [
        'Facebook'  => '<svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'Instagram' => '<svg viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
        'YouTube'   => '<svg viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'WhatsApp'  => '<svg viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>',
    ];
    return $icons[$platform] ?? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
}
function site_social_class($platform)
{
    $map = ['Facebook' => 'fb', 'Instagram' => 'ig', 'YouTube' => 'yt', 'WhatsApp' => 'wa'];
    return $map[$platform] ?? 'fb';
}

// সব একটিভ Branch-এর তথ্য — Contact ফুটারে প্রতিটা branch আলাদা করে দেখানোর জন্য
$site_branches = [];
try {
    $site_branches = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    $site_branches = []; // branches টেবিল এখনো তৈরি না হয়ে থাকলে চুপচাপ বাদ দাও
}

// Institute Settings — সাইটের নাম/লোগো/যোগাযোগ/সোশ্যাল লিংক/বিবরণ এখানে থেকে আসে
$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = [
        'institute_name' => 'Your School Name', 'phone_number' => '', 'email' => '',
        'facebook_page' => '', 'logo_path' => '', 'favicon_path' => '', 'banner_path' => '', 'short_description' => '', 'why_choose_institute' => '',
    ];
}

// SMS Settings (On/Off) — "Show Teachers On Home Page" ইত্যাদি টগলগুলো এখান থেকে রিড হয়
$sms_settings = $pdo->query("SELECT show_teachers_home, show_section_filter, show_teachers_mobile, show_teachers_address, show_teachers_email, show_count_home, show_video_home, show_gallery_home, show_notices_home, show_events_home FROM sms_settings WHERE id = 1")->fetch();
if (!$sms_settings) {
    $sms_settings = ['show_teachers_home' => 1, 'show_section_filter' => 0, 'show_teachers_mobile' => 1, 'show_teachers_address' => 1, 'show_teachers_email' => 1, 'show_count_home' => 1, 'show_video_home' => 1, 'show_gallery_home' => 1, 'show_notices_home' => 1, 'show_events_home' => 1];
}
$show_teachers_home    = (bool) $sms_settings['show_teachers_home'];
$show_section_filter   = (bool) ($sms_settings['show_section_filter'] ?? 0);
$show_teachers_mobile   = (bool) $sms_settings['show_teachers_mobile'];
$show_teachers_address  = (bool) $sms_settings['show_teachers_address'];
$show_teachers_email    = (bool) $sms_settings['show_teachers_email'];
$show_count_home        = (bool) $sms_settings['show_count_home'];
$show_video_home        = (bool) $sms_settings['show_video_home'];
$show_gallery_home      = (bool) $sms_settings['show_gallery_home'];
$show_notices_home      = (bool) $sms_settings['show_notices_home'];
$show_events_home       = (bool) $sms_settings['show_events_home'];

// Teachers & Staffs — "Show Teachers On Home Page" চালু থাকলেই শুধু ডাটাবেজ থেকে রিয়েল Employee লিস্ট আনা হয়
// নোট: employees/designations টেবিল admin > HR মডিউল প্রথমবার ভিজিট করলে তৈরি হয় (lazy migration)।
// নতুন tenant-এ কেউ এখনো HR পেজ ভিজিট না করলেও পাবলিক সাইট যেন ভেঙে না পড়ে, তাই try-catch দিয়ে সেফ ফলব্যাক।
// Teacher-এর School/College সেকশন কলাম (HR পেজ ভিজিট না করা থাকলেও পাবলিক সাইট যেন ভেঙে না পড়ে)
try {
    if ($pdo->query("SHOW TABLES LIKE 'employees'")->fetch() && !$pdo->query("SHOW COLUMNS FROM employees LIKE 'section_level'")->fetch()) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN section_level VARCHAR(10) NULL");
    }
} catch (PDOException $e) { /* employees টেবিল এখনো নেই */ }

$site_teachers = [];
if ($show_teachers_home) {
    try {
        $site_teachers = $pdo->query(
            "SELECT e.name, e.mobile, e.email, e.present_address, e.photo_path, e.type, e.section_level, d.title AS designation_title
             FROM employees e LEFT JOIN designations d ON d.id = e.designation_id
             WHERE e.status = 'Active' AND e.is_current = 1 AND e.type IN ('Teachers','Staffs')
             ORDER BY " . TD_ORDER_BY
        )->fetchAll();
    } catch (PDOException $e) {
        $site_teachers = []; // employees/designations টেবিল এখনো তৈরি হয়নি
    }
}

// "OUR ACHIEVEMENTS" স্ট্যাটস কাউন্টার সেকশনের জন্য — সবসময় রিয়েল সংখ্যা গণনা করা হয় (Teachers Section On/Off থেকে স্বাধীন)
try {
    $teacher_count = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE type = 'Teachers' AND status = 'Active' AND is_current = 1")->fetchColumn();
    $staff_count   = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE type = 'Staffs' AND status = 'Active' AND is_current = 1")->fetchColumn();
} catch (PDOException $e) {
    $teacher_count = 0;
    $staff_count   = 0;
}
$student_count = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'Active'")->fetchColumn();
function site_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }

// Website Theme = "Govt Theme-1" হলে আলাদা টেমপ্লেট রেন্ডার হয়ে exit করবে
// (উপরের সব ডাটা fetch এই ফাইলে fetch হয়ে যাওয়ার পরই এটা চেক হচ্ছে, তাই
// website_theme_govt1.php এর ভেতরে $inst/$site_notices ইত্যাদি সবই available)
if (($inst['website_theme'] ?? 'E-Learning') === 'Govt Theme-1') {
    include __DIR__ . '/website_theme_govt1.php';
    exit();
}

// এই দুটো ফাংশন ভাষা-সচেতন — current_lang() ডিফাইন হয় config/lang.php তে (site_header.php
// দিয়ে পরে লোড হয়), কিন্তু PHP-তে ফাংশনের বডি কল হওয়ার সময় রান হয়, ডিফাইন হওয়ার সময় না —
// তাই এখানে আগে থেকে লেখা থাকলেও নিচে (site_header.php লোড হওয়ার পরে) কল হলে ঠিকই কাজ করবে।
function site_bn_date(string $ymd): string
{
    $months_bn = ['', 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
    $months_en = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $bn_digits = ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯'];
    $t = strtotime($ymd);
    if (!$t) return htmlspecialchars($ymd);
    $is_en = function_exists('current_lang') && current_lang() === 'en';
    $day = $is_en ? (string)(int)date('d', $t) : strtr((string)(int)date('d', $t), $bn_digits);
    $year = $is_en ? date('Y', $t) : strtr(date('Y', $t), $bn_digits);
    $month = $is_en ? $months_en[(int)date('n', $t)] : $months_bn[(int)date('n', $t)];
    return $day . ' ' . $month . ', ' . $year;
}
function site_bn_day_month(string $ymd): array
{
    $months_bn = ['', 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
    $months_en = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $bn_digits = ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯'];
    $t = strtotime($ymd);
    if (!$t) return ['', ''];
    $is_en = function_exists('current_lang') && current_lang() === 'en';
    $day = $is_en ? (string)(int)date('d', $t) : strtr((string)(int)date('d', $t), $bn_digits);
    $month = $is_en ? $months_en[(int)date('n', $t)] : $months_bn[(int)date('n', $t)];
    return [$day, $month];
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_val($inst['institute_name'], 'Your School Name'); ?></title>
    <?php if (!empty($inst['favicon_path'])):
        $fav_ext = strtolower(pathinfo($inst['favicon_path'], PATHINFO_EXTENSION));
        $fav_mime = ['ico' => 'image/x-icon', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$fav_ext] ?? 'image/x-icon';
        $fav_ver = @filemtime(__DIR__ . '/' . $inst['favicon_path']) ?: time();
    ?>
        <link rel="icon" type="<?php echo $fav_mime; ?>" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>?v=<?php echo $fav_ver; ?>">
    <?php endif; ?>
    
    <style>
        /* --- CSS STYLES & UNIQUE THEME --- */
        :root {
            --primary: #0a2540;      /* Deep Navy */
            --accent: #10b981;       /* Emerald Green */
            --secondary: #f8fafc;    /* Soft Gray */
            --text-dark: #1e293b;
            --text-light: #64748b;
            --btn-blue: #3b82f6;
            --teal-card: #00bcd4;     /* Teal/Cyan color */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--secondary);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* HERO SLIDER SECTION */
        .hero-slider-container {
            position: relative;
            width: 100%;
            height: 500px;
            overflow: hidden;
        }

        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 0.8s ease-in-out;
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .slide.active {
            opacity: 1;
        }

        /* BRIGHTER IMAGE OVERLAY */
        .slide-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(rgba(10, 37, 64, 0.30), rgba(10, 37, 64, 0.45));
        }

        .slide-content {
            position: relative;
            z-index: 2;
            color: white;
            text-align: center;
            padding: 0 5%;
            max-width: 850px;
        }

        .slide-content h2 {
            font-size: 38px;
            margin-bottom: 18px;
            font-weight: 800;
            text-shadow: 0 3px 10px rgba(0, 0, 0, 0.85);
        }

        .slide-content p {
            font-size: 18px;
            margin-bottom: 30px;
            color: #ffffff;
            line-height: 1.6;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.85);
        }

        .btn-primary {
            background: var(--accent);
            color: white;
            padding: 14px 32px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: bold;
            font-size: 15px;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
            transition: transform 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
        }

        /* SLIDER CONTROLS */
        .slider-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.25);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            z-index: 10;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .slider-btn:hover {
            background: var(--accent);
        }

        .slider-btn.prev { left: 20px; }
        .slider-btn.next { right: 20px; }

        .slider-dots {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 10;
        }

        .dot {
            width: 12px;
            height: 12px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dot.active {
            background: var(--accent);
            width: 28px;
            border-radius: 10px;
        }

        /* Notice Ticker Bar */
        .notice-bar {
            background: #1e293b;
            color: white;
            padding: 10px 5%;
            display: flex;
            align-items: center;
        }

        .notice-title {
            background: #ef4444;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
            margin-right: 15px;
            white-space: nowrap;
        }

        /* Main Content Layout */
        .main-container {
            padding: 40px 5% 20px;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            align-items: stretch;
        }

        .left-column {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .right-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .section-title {
            font-size: 22px;
            color: var(--primary);
            margin-bottom: 20px;
            border-bottom: 3px solid var(--accent);
            display: inline-block;
            padding-bottom: 5px;
        }

        .sub-tag {
            font-size: 13px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1px;
            display: block;
            margin-bottom: 3px;
        }

        /* Headmaster Message Card */
        .principal-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            display: flex;
            gap: 20px;
        }

        .principal-card img {
            width: 120px;
            height: 140px;
            object-fit: cover;
            border-radius: 8px;
            border: 3px solid var(--accent);
            flex-shrink: 0;
        }

        /* WHY CHOOSE US SECTION */
        .why-choose-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .why-choose-card .intro-text {
            font-size: 14px;
            color: #475569;
            margin-bottom: 20px;
            line-height: 1.7;
        }

        .why-choose-card .highlight-name {
            color: #dc2626;
            font-weight: bold;
        }

        .feature-list {
            list-style: none;
        }

        .feature-list li {
            position: relative;
            padding-left: 28px;
            margin-bottom: 12px;
            font-size: 14px;
            color: #334155;
            line-height: 1.5;
        }

        .feature-list li::before {
            content: "★";
            position: absolute;
            left: 0;
            top: 0;
            color: var(--accent);
            font-size: 16px;
        }

        /* Sidebar & Notice List */
        .sidebar-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .notice-list {
            list-style: none;
        }

        .notice-list li {
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .notice-list li:last-child {
            border-bottom: none;
        }

        .notice-list li a {
            text-decoration: none;
            color: var(--text-dark);
            font-size: 13.5px;
            font-weight: 600;
        }

        .notice-date {
            font-size: 11px;
            color: var(--text-light);
            display: block;
            margin-top: 2px;
        }

        /* UPCOMING EVENTS SECTION STYLING */
        .events-list {
            list-style: none;
        }

        .event-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .event-item:last-child {
            border-bottom: none;
        }

        .event-date-box {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 6px 10px;
            text-align: center;
            min-width: 55px;
            flex-shrink: 0;
        }

        .event-date-box .day {
            font-size: 16px;
            font-weight: 800;
            color: var(--accent);
            line-height: 1;
        }

        .event-date-box .month {
            font-size: 10px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .event-info h5 {
            font-size: 13.5px;
            color: var(--text-dark);
            margin-bottom: 2px;
            font-weight: 700;
        }

        .event-info p {
            font-size: 11.5px;
            color: var(--text-light);
        }

        /* BOTTOM RIGHT BUTTON STYLING */
        .card-bottom-action {
            display: flex;
            justify-content: flex-end;
            margin-top: 15px;
            padding-top: 8px;
        }

        .btn-view-all {
            background: var(--secondary);
            color: var(--primary);
            border: 1px solid #cbd5e1;
            padding: 4px 14px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .btn-view-all:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        /* FULL WIDTH "আমাদের সুবিধাসমূহ" SECTION */
        .features-full-section {
            padding: 10px 5% 40px;
        }

        .features-grid-3col {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .feature-card-img {
            position: relative;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: flex-end;
        }

        .feature-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            z-index: 1;
        }

        .feature-overlay {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 15px;
            background: linear-gradient(to top, rgba(10, 37, 64, 0.95), rgba(10, 37, 64, 0.4), transparent);
            color: white;
        }

        .feature-overlay h4 {
            font-size: 18px;
            color: #34d399;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .feature-overlay p {
            font-size: 12px;
            color: #e2e8f0;
            line-height: 1.4;
        }

        /* OUR TEACHERS AND STAFFS SECTION */
        .teachers-section {
            padding: 20px 5% 40px;
        }

        .teachers-header-wrapper {
            position: relative;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .teachers-section .center-heading {
            text-align: center;
            font-size: 24px;
            color: #0284c7;
            font-weight: 800;
            margin: 0;
        }

        .sec-badge { display: inline-block; font-size: 10px; padding: 1px 8px; border-radius: 9px; background: #ecfdf5; color: #047857; font-weight: 700; }
        .teacher-filter-btns {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            gap: 8px;
        }

        .filter-btn {
            background: #ffffff;
            color: var(--text-dark);
            border: 1px solid #cbd5e1;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }

        .filter-btn:hover, .filter-btn.active {
            background: #0284c7;
            color: white;
            border-color: #0284c7;
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.3);
        }

        .teachers-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .teacher-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            padding: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .teacher-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .teacher-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .teacher-card h5 {
            font-size: 15px;
            color: var(--text-dark);
            font-weight: 700;
            margin-bottom: 2px;
        }

        .teacher-card .designation {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
        }

        .teacher-card .teacher-meta {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
            word-break: break-word;
        }

        /* EDUCATION SUPPORT LINKS SECTION */
        .education-support-section {
            padding: 40px 5%;
            background: #ffffff;
            margin-bottom: 30px;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .support-container {
            display: grid;
            grid-template-columns: 1fr 1.8fr;
            gap: 30px;
            align-items: center;
        }

        .support-text h2 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .support-text p {
            font-size: 14px;
            color: #475569;
            line-height: 1.7;
        }

        .support-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .support-card {
            background: linear-gradient(145deg, #00d2ff, #0083b0);
            border-radius: 12px;
            padding: 15px 8px;
            text-align: center;
            color: white;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 131, 176, 0.2);
        }

        .support-icon-circle {
            width: 50px;
            height: 50px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            font-size: 24px;
        }

        .support-card h4 {
            font-size: 12px;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.2;
        }

        /* STATS COUNTER SECTION */
        .full-width-stats {
            padding: 40px 5%;
            background: #ffffff;
            margin-bottom: 30px;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .stats-box {
            background: transparent;
            color: var(--text-dark);
            padding: 10px 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .stat-card {
            background: linear-gradient(145deg, #00d2ff, #0083b0);
            padding: 18px 10px;
            border-radius: 12px;
            text-align: center;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 131, 176, 0.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .stat-icon-wrapper {
            width: 50px;
            height: 50px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-icon { font-size: 22px; }

        .stat-number {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 12px;
            color: #f1f5f9;
            margin-top: 2px;
            font-weight: 600;
        }

        /* FILTERABLE CLASS VIDEOS SECTION */
        .video-tutorials-section {
            padding: 40px 5%;
            background: #f1f5f9;
        }

        .video-section-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .video-section-header h2 {
            font-size: 26px;
            color: var(--primary);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .video-filter-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 25px;
            background: #ffffff;
            padding: 12px 20px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .filter-select, .filter-search-input {
            padding: 10px 16px;
            border-radius: 25px;
            border: 1px solid #00bcd4;
            font-size: 14px;
            outline: none;
            width: 100%;
            max-width: 250px;
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .video-card {
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
        }

        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            background: #000;
        }

        .video-wrapper iframe {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            border: 0;
        }

        .video-info {
            padding: 12px;
        }

        .video-badge {
            background: #e0f2fe;
            color: #0284c7;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 10px;
        }

        .video-info h4 {
            font-size: 13px;
            color: var(--primary);
            margin: 6px 0 4px;
            line-height: 1.3;
        }

        .video-info p {
            font-size: 11px;
            color: var(--text-light);
        }

        .youtube-channel-btn-container {
            text-align: center;
            margin-top: 25px;
        }

        .btn-youtube-channel {
            background: #ff0000;
            color: white;
            padding: 12px 28px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(255, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .btn-youtube-channel:hover {
            background: #cc0000;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 0, 0, 0.4);
        }

        /* PHOTO GALLERY SECTION */
        .gallery-section {
            padding: 30px 5% 40px;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .gallery-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            height: 160px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.05);
        }

        .gallery-caption {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            background: rgba(10, 37, 64, 0.8);
            color: white;
            padding: 6px 10px;
            font-size: 12px;
            text-align: center;
            font-weight: 600;
        }

        /* NEWS / CAREER / ALBUM SECTIONS */
        .news-section, .career-section, .album-section { padding: 30px 5% 10px; }
        .news-grid, .album-grid-section { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .news-card, .album-card2 { background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.07); }
        .news-card img, .album-card2 img { width: 100%; height: 150px; object-fit: cover; }
        .news-card-body, .album-card2-body { padding: 14px; }
        .news-card-body h4 { font-size: 15px; color: var(--primary); margin-bottom: 6px; }
        .news-card-date { font-size: 12px; color: var(--accent); font-weight: 600; margin-bottom: 6px; display: block; }
        .news-card-body p { font-size: 13px; color: #64748b; line-height: 1.6; }
        .album-card2-body h4 { font-size: 15px; color: var(--primary); margin-bottom: 4px; }
        .album-card2-body p { font-size: 12px; color: #64748b; }
        .career-list { display: flex; flex-direction: column; gap: 12px; }
        .career-item { background: #fff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.07); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .career-item h4 { font-size: 16px; color: var(--primary); margin-bottom: 4px; }
        .career-item .career-dept { font-size: 13px; color: #64748b; }
        .career-item .career-deadline { font-size: 12px; color: #dc2626; font-weight: 600; }
        .btn-apply-career { background: var(--accent); color: #fff; padding: 8px 18px; border-radius: 20px; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; }
        @media (max-width: 768px) { .news-grid, .album-grid-section { grid-template-columns: 1fr; } }

        /* FOOTER */
        footer {
            background: var(--primary);
            color: white;
            padding: 40px 5% 20px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr 1fr 1.3fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .footer-col h4 {
            color: var(--accent);
            margin-bottom: 15px;
            font-size: 16px;
        }

        .useful-links-list {
            list-style: none;
        }

        .useful-links-list li {
            margin-bottom: 8px;
        }

        .useful-links-list li a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s;
        }

        .useful-links-list li a:hover {
            color: var(--accent);
        }

        .social-links-grid {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .social-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .social-btn svg {
            width: 18px;
            height: 18px;
            fill: currentColor;
        }

        .social-btn.fb:hover { background: #1877f2; border-color: #1877f2; transform: translateY(-3px); }
        .social-btn.yt:hover { background: #ff0000; border-color: #ff0000; transform: translateY(-3px); }
        .social-btn.wa:hover { background: #25d366; border-color: #25d366; transform: translateY(-3px); }
        .social-btn.ig:hover { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); border-color: #dc2743; transform: translateY(-3px); }

        .map-container {
            width: 100%;
            height: 160px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--accent);
        }

        .map-container iframe {
            width: 100%;
            height: 100%;
            border: 0;
        }

        .footer-bottom {
            text-align: center;
            border-top: 1px solid #334155;
            padding-top: 18px;
            font-size: 12px;
            color: #94a3b8;
        }

        /* RESPONSIVE MEDIA QUERIES */
        @media (max-width: 992px) {
            .hero-slider-container { height: 420px; }
            .slide-content h2 { font-size: 28px; }
            .slide-content p { font-size: 15px; }
            .teachers-grid, .video-grid, .gallery-grid { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            .hero-slider-container { height: 350px; }
            .slide-content h2 { font-size: 22px; margin-bottom: 10px; }
            .slide-content p { font-size: 13px; margin-bottom: 15px; }
            .btn-primary { padding: 10px 20px; font-size: 13px; }
            .slider-btn { width: 35px; height: 35px; font-size: 16px; }

            .top-bar { justify-content: center; text-align: center; }
            .header-brand { flex-direction: column; text-align: center; }
            .logo-container { flex-direction: column; }
            .header-actions { width: 100%; justify-content: center; }
            .main-container { grid-template-columns: 1fr; padding: 20px 4%; }
            .principal-card { flex-direction: column; text-align: center; align-items: center; }
            .features-grid-3col { grid-template-columns: 1fr; }
            .support-container { grid-template-columns: 1fr; }
            .support-cards-grid { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .support-card { padding: 10px 4px; }
            .support-icon-circle { width: 38px; height: 38px; font-size: 18px; margin-bottom: 6px; }
            .support-card h4 { font-size: 10px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 12px 6px; border-radius: 12px; }
            .stat-icon-wrapper { width: 38px; height: 38px; margin-bottom: 6px; }
            .stat-icon { font-size: 18px; }
            .stat-number { font-size: 18px; }
            .stat-label { font-size: 10px; }

            .teachers-header-wrapper {
                flex-direction: column;
                gap: 15px;
            }
            .teacher-filter-btns {
                position: static;
                transform: none;
                justify-content: center;
            }

            .video-grid, .gallery-grid, .footer-grid { grid-template-columns: 1fr; }
            .filter-select, .filter-search-input { max-width: 100%; }

            /* মোবাইলে Teacher কার্ড ১টার বদলে ২টা করে ছোট আকারে পাশাপাশি দেখানোর জন্য */
            .teachers-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .teacher-card { padding: 8px; border-radius: 6px; }
            .teacher-card img { height: 90px; margin-bottom: 8px; }
            .teacher-card h5 { font-size: 12px; margin-bottom: 1px; }
            .teacher-card .designation { font-size: 10px; }
            .teacher-card .teacher-meta { font-size: 9px; margin-top: 2px; }
            .teacher-card .teacher-meta i { margin-right: 2px; }
        }
    </style>
</head>
<body>
<?php if (erp_is_demo()) require __DIR__ . '/demo/banner.php'; ?>

    <?php $current_page = 'home'; require __DIR__ . '/site_header.php'; ?>

    <!-- HERO SLIDER SECTION -->
    <section class="hero-slider-container">
        <?php if ($site_sliders): ?>
            <?php foreach ($site_sliders as $i => $sl): ?>
                <div class="slide<?php echo $i === 0 ? ' active' : ''; ?>" style="background-image: url('<?php echo htmlspecialchars($sl['image_path']); ?>');">
                    <div class="slide-overlay"></div>
                    <div class="slide-content">
                        <h2><?php echo htmlspecialchars($sl['title']); ?></h2>
                        <?php if (!empty($sl['subtitle'])): ?><p><?php echo htmlspecialchars($sl['subtitle']); ?></p><?php endif; ?>
                        <?php if (!empty($sl['button_text'])): ?>
                            <a href="<?php echo htmlspecialchars($sl['button_link'] ?: '#'); ?>" class="btn-primary"><?php echo htmlspecialchars($sl['button_text']); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="slide active" style="background-image: url('https://images.unsplash.com/photo-1562774053-701939374585?q=80&w=1400');">
                <div class="slide-overlay"></div>
                <div class="slide-content">
                    <h2><?php echo t('hero_default_title'); ?></h2>
                    <p><?php echo t('hero_default_desc'); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <button class="slider-btn prev" onclick="moveSlide(-1)">❮</button>
        <button class="slider-btn next" onclick="moveSlide(1)">❯</button>

        <div class="slider-dots">
            <?php $slide_count = $site_sliders ? count($site_sliders) : 1; ?>
            <?php for ($i = 0; $i < $slide_count; $i++): ?>
                <span class="dot<?php echo $i === 0 ? ' active' : ''; ?>" onclick="currentSlide(<?php echo $i; ?>)"></span>
            <?php endfor; ?>
        </div>
    </section>

    <!-- BREAKING NEWS TICKER — শুধু "হিরো সেকশনের নিচে" সিলেক্ট করা থাকলেই এখানে দেখাবে,
         "উপরে" সিলেক্ট করা থাকলে site_header.php ইতিমধ্যে হিরোর আগেই দেখিয়ে দিয়েছে -->
    <?php if (($hdr_breaking_news_position ?? 'above_hero') === 'below_hero'): site_render_breaking_news_bar($hdr_breaking_news ?? []); endif; ?>

    <!-- NOTICE TICKER BAR -->
    <?php if ($show_notices_home && $site_notices): ?>
    <div class="notice-bar">
        <span class="notice-title"><?php echo t('urgent_notice_label'); ?></span>
        <marquee behavior="scroll" direction="left">
            <?php echo implode('    •    ', array_map(fn($n) => htmlspecialchars($n['title']) . (!empty($n['branch_short_name']) ? ' [' . htmlspecialchars($n['branch_short_name']) . ']' : ''), $site_notices)); ?>
        </marquee>
    </div>
    <?php endif; ?>

    <!-- MAIN CONTENT SECTION -->
    <div class="main-container" id="about"<?php if (!$show_notices_home && !$show_events_home): ?> style="grid-template-columns: 1fr;"<?php endif; ?>>
        
        <!-- LEFT COLUMN -->
        <div class="left-column">
            <!-- বাণী (Message Corner) — সর্বোচ্চ ৩টা, প্রতিটায় Read More; শেষে "সকল বাণী" বাটন -->
            <div>
                <h3 class="section-title"><?php echo $site_messages ? t('messages_title') : t('principal_message'); ?></h3>
                <?php if ($site_messages): foreach ($site_messages as $msg): ?>
                <div class="principal-card" style="margin-bottom: 16px;">
                    <img src="<?php echo !empty($msg['photo_path']) ? htmlspecialchars($msg['photo_path']) : 'https://images.unsplash.com/photo-1560250097-0b93528c311a?q=80&w=300'; ?>" alt="<?php echo htmlspecialchars($msg['name']); ?>">
                    <div style="flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h4><?php echo htmlspecialchars($msg['name']); ?></h4>
                            <p style="font-size: 12px; color: var(--accent); font-weight: bold; margin-bottom: 8px;"><?php echo htmlspecialchars($msg['designation']); ?></p>
                            <div style="font-size: 14px; color: #475569; line-height: 1.7;">
                                <?php echo nl2br(htmlspecialchars(hm_excerpt($msg['message'], 260))); ?>
                            </div>
                        </div>
                        <div class="card-bottom-action">
                            <a href="message_view.php?id=<?php echo (int) $msg['id']; ?>" class="btn-view-all"><?php echo t('read_more'); ?></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="card-bottom-action" style="margin-top: 0;">
                    <a href="messages.php" class="btn-view-all"><?php echo t('all_messages'); ?> ➔</a>
                </div>
                <?php else: ?>
                <div class="principal-card">
                    <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?q=80&w=300" alt="Principal">
                    <div style="flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <h4><?php echo t('principal_default_name'); ?></h4>
                            <?php $principal_desg_fallback_inst = current_lang() === 'en' ? 'Your School Name' : 'আপনার স্কুলের নাম'; ?>
                            <p style="font-size: 12px; color: var(--accent); font-weight: bold; margin-bottom: 8px;"><?php echo t('principal_default_desg') . site_val($inst['institute_name'], $principal_desg_fallback_inst); ?></p>
                            <div style="font-size: 14px; color: #475569;">
                                <?php echo !empty($inst['short_description']) ? $inst['short_description'] : t('principal_default_msg'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ABOUT US CONTENT (Website Module > About Us Content — আগে কোথাও দেখানো হতো না) -->
            <?php if ($site_about_content): ?>
            <div class="why-choose-card" style="margin-top: 20px;">
                <span class="sub-tag">ABOUT US</span>
                <h3 class="section-title" style="margin-bottom: 15px;"><?php echo t('about_institution'); ?></h3>
                <?php foreach ($site_about_content as $ac): ?>
                <div style="margin-bottom: 16px;">
                    <h4 style="font-size: 16px; color: var(--primary); margin-bottom: 6px;"><?php echo htmlspecialchars($ac['title']); ?></h4>
                    <div style="font-size: 14px; color: #475569; line-height: 1.7;"><?php echo nl2br(htmlspecialchars($ac['content'])); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- WHY CHOOSE US SECTION -->
            <div id="academic" class="why-choose-card">
                <div>
                    <span class="sub-tag">WHY CHOOSE US</span>
                    <h3 class="section-title" style="margin-bottom: 15px;"><?php echo t('why_choose_us'); ?></h3>

                    <?php if (!empty($inst['why_choose_institute'])): ?>
                    <div class="why-choose-content" style="font-size: 14px; color: #475569; line-height: 1.8;">
                        <?php echo $inst['why_choose_institute']; ?>
                    </div>
                    <?php else: ?>
                    <p class="intro-text">
                        <span class="highlight-name"><?php echo site_val($inst['institute_name'], t('why_choose_default_institution')); ?></span><?php echo t('why_choose_intro'); ?>
                    </p>
                    <ul class="feature-list">
                        <li><?php echo t('why_li_1'); ?></li>
                        <li><?php echo t('why_li_2'); ?></li>
                        <li><?php echo t('why_li_3'); ?></li>
                        <li><?php echo t('why_li_4'); ?></li>
                        <li><?php echo t('why_li_5'); ?></li>
                        <li><?php echo t('why_li_6'); ?></li>
                        <li><?php echo t('why_li_7'); ?></li>
                    </ul>
                    <?php endif; ?>
                </div>

                <div class="card-bottom-action">
                    <a href="why_choose.php" class="btn-view-all"><?php echo t('read_more'); ?></a>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDEBAR — Notice ও Event দুটোই Off থাকলে পুরো সাইডবারই বাদ, বাম কলাম ফুল-উইডথ -->
        <?php if ($show_notices_home || $show_events_home): ?>
        <div class="right-sidebar">
            
            <?php if ($show_notices_home): ?>
            <!-- Latest Notices -->
            <div class="sidebar-card" id="notices">
                <div>
                    <h3 class="section-title"><?php echo t('latest_notices'); ?></h3>
                    <ul class="notice-list">
                        <?php if ($site_notices): foreach (array_slice($site_notices, 0, 3) as $n): ?>
                        <li>
                            <a href="notice_view.php?id=<?php echo (int) $n['id']; ?>"><?php echo htmlspecialchars($n['title']); ?><?php if (!empty($n['branch_short_name'])): ?> <span class="notice-branch-badge" style="font-size:11px;padding:1px 6px;border-radius:8px;background:#eef2ff;color:#3730a3;"><?php echo htmlspecialchars($n['branch_short_name']); ?></span><?php endif; ?></a>
                            <span class="notice-date"><?php echo site_bn_date($n['notice_date']); ?></span>
                        </li>
                        <?php endforeach; else: ?>
                        <li><span class="notice-date"><?php echo t('no_notice'); ?></span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-bottom-action">
                    <a href="notices.php" class="btn-view-all"><?php echo t('view_all'); ?> ➔</a>
                </div>
            </div>

            <?php endif; ?>

            <?php if ($show_events_home): ?>
            <!-- UPCOMING EVENTS CARD -->
            <div class="sidebar-card">
                <div>
                    <h3 class="section-title"><?php echo t('upcoming_events2'); ?></h3>
                    <ul class="events-list">
                        <?php if ($site_events): foreach ($site_events as $ev): [$ev_day, $ev_month] = site_bn_day_month($ev['event_date']); ?>
                        <li class="event-item">
                            <div class="event-date-box">
                                <div class="day"><?php echo $ev_day; ?></div>
                                <div class="month"><?php echo $ev_month; ?></div>
                            </div>
                            <div class="event-info">
                                <h5><?php echo htmlspecialchars($ev['title']); ?></h5>
                                <?php if (!empty($ev['location'])): ?><p>📍 <?php echo htmlspecialchars($ev['location']); ?></p><?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; else: ?>
                        <li class="event-item"><div class="event-info"><p style="color:#94a3b8;"><?php echo t('no_event_added'); ?></p></div></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-bottom-action">
                    <a href="#" onclick="alert('<?php echo t('all_events_alert'); ?>')" class="btn-view-all"><?php echo t('view_all'); ?> ➔</a>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div>

    <!-- FULL WIDTH "আমাদের সুবিধাসমূহ" SECTION — Admin > Website Module > Facilities List থেকে ডাইনামিক -->
    <?php if ($site_facilities): ?>
    <section class="features-full-section">
        <h3 class="section-title"><?php echo t('our_facilities'); ?></h3>
        <div class="features-grid-3col">
            <?php foreach ($site_facilities as $fc): $fc_img = wf_image_src($fc['image_path']); ?>
                <div class="feature-card-img">
                    <div class="feature-bg" style="<?php echo $fc_img !== '' ? "background-image: url('" . $fc_img . "');" : 'background:linear-gradient(135deg,#1e3a8a,#3b82f6);'; ?>"></div>
                    <div class="feature-overlay">
                        <h4><?php echo htmlspecialchars(wf_text($fc, 'title', current_lang())); ?></h4>
                        <?php $fc_desc = wf_text($fc, 'description', current_lang()); if ($fc_desc !== ''): ?><p><?php echo htmlspecialchars($fc_desc); ?></p><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- OUR TEACHERS AND STAFFS SECTION -->
    <?php if ($show_teachers_home): ?>
    <div id="teachers" class="teachers-section">
        <div class="teachers-header-wrapper">
            <?php
            $td_opts = ['section_mode' => $show_section_filter, 'init_section' => 'all', 'limit' => 8,
                        'show_mobile' => $show_teachers_mobile, 'show_email' => $show_teachers_email, 'show_address' => $show_teachers_address];
            echo td_filters($td_opts);
            ?>

            <h3 class="center-heading"><?php echo t('our_teachers_staffs'); ?></h3>
        </div>

        <?php echo td_grids($site_teachers, $td_opts); ?>
        <?php if (count($site_teachers) > 8 || $show_section_filter): ?>
            <div class="card-bottom-action">
                <a href="teachers.php" id="tdSeeAll" class="btn-view-all"><?php echo t('see_all'); ?></a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- EDUCATION SUPPORT LINKS SECTION -->
    <section class="education-support-section">
        <div class="support-container">
            <div class="support-text">
                <h2><?php echo t('edu_support_links'); ?></h2>
                <p><?php echo t('edu_support_desc'); ?></p>
            </div>

            <div class="support-cards-grid">
                <a href="admission_form.php" class="support-card">
                    <div class="support-icon-circle">📝</div>
                    <h4><?php echo t('admission_form_lbl'); ?></h4>
                </a>
                <a href="#" class="support-card">
                    <div class="support-icon-circle">📖</div>
                    <h4><?php echo t('syllabus_lbl'); ?></h4>
                </a>
                <a href="#" class="support-card">
                    <div class="support-icon-circle">🕒</div>
                    <h4><?php echo t('class_routine_lbl'); ?></h4>
                </a>
                <a href="#" class="support-card">
                    <div class="support-icon-circle">📋</div>
                    <h4><?php echo t('exam_routine_lbl'); ?></h4>
                </a>
                <a href="#" class="support-card">
                    <div class="support-icon-circle">📅</div>
                    <h4><?php echo t('academic_calendar_lbl'); ?></h4>
                </a>
                <a href="#" class="support-card">
                    <div class="support-icon-circle">🔗</div>
                    <h4><?php echo t('important_links_lbl'); ?></h4>
                </a>
            </div>
        </div>
    </section>

    <!-- STATS COUNTER SECTION -->
    <?php if ($show_count_home): ?>
    <div class="full-width-stats">
        <div style="text-align: center; margin-bottom: 25px;">
            <span class="sub-tag">OUR ACHIEVEMENTS</span>
            <h3 class="section-title" style="margin-bottom: 8px;"><?php echo t('stats_heading'); ?></h3>
            <p style="font-size: 14px; color: #64748b;"><?php echo t('stats_subheading'); ?></p>
        </div>

        <div class="stats-box">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon-wrapper"><span class="stat-icon">🎓</span></div>
                    <div class="stat-number"><?php echo number_format($student_count); ?>+</div>
                    <div class="stat-label"><?php echo t('stat_total_students'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrapper"><span class="stat-icon">👨‍🏫</span></div>
                    <div class="stat-number"><?php echo number_format($teacher_count); ?>+</div>
                    <div class="stat-label"><?php echo t('stat_teachers_staff'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrapper"><span class="stat-icon">👔</span></div>
                    <div class="stat-number"><?php echo number_format($staff_count); ?>+</div>
                    <div class="stat-label"><?php echo t('stat_support_staff'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon-wrapper"><span class="stat-icon">🏆</span></div>
                    <div class="stat-number">৯৮.৫%</div>
                    <div class="stat-label"><?php echo t('stat_pass_rate'); ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- FILTERABLE CLASS VIDEOS SECTION -->
    <?php if ($show_video_home): ?>
    <section class="video-tutorials-section" id="videos">
        <div class="video-section-header">
            <h2><?php echo t('video_section_title'); ?></h2>
        </div>

        <div class="video-filter-bar">
            <select id="classDropdown" class="filter-select" onchange="filterVideos()">
                <option value="all"><?php echo t('all_classes'); ?></option>
                <option value="play-kg"><?php echo t('class_play_kg'); ?></option>
                <option value="class1-5"><?php echo t('class_1_5'); ?></option>
                <option value="class6-8"><?php echo t('class_6_8'); ?></option>
                <option value="class9-10"><?php echo t('class_9_10'); ?></option>
                <option value="class11-12"><?php echo t('class_11_12'); ?></option>
            </select>

            <input type="text" id="videoSearchInput" class="filter-search-input" placeholder="<?php echo t('search_video_ph'); ?>" onkeyup="filterVideos()">
        </div>

        <div class="video-grid" id="videoGrid">
            <div class="video-card" data-category="play-kg" data-keywords="প্লে কেজি ছড়া বর্ণমালা">
                <div class="video-wrapper">
                    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="প্লে ও কেজি ক্লাস" allowfullscreen></iframe>
                </div>
                <div class="video-info">
                    <span class="video-badge"><?php echo t('class_play_kg'); ?></span>
                    <h4><?php echo t('video1_title'); ?></h4>
                    <p><?php echo t('video1_teacher'); ?></p>
                </div>
            </div>

            <div class="video-card" data-category="class1-5" data-keywords="১ম ৫ম ইংরেজি গ্রামার">
                <div class="video-wrapper">
                    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="ইংরেজি ক্লাস" allowfullscreen></iframe>
                </div>
                <div class="video-info">
                    <span class="video-badge"><?php echo t('class_1_5'); ?></span>
                    <h4>Basic English Grammar Rules</h4>
                    <p><?php echo t('video2_teacher'); ?></p>
                </div>
            </div>

            <div class="video-card" data-category="class6-8" data-keywords="৬ষ্ঠ ৮TM বিজ্ঞান ল্যাব">
                <div class="video-wrapper">
                    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="বিজ্ঞান ক্লাস" allowfullscreen></iframe>
                </div>
                <div class="video-info">
                    <span class="video-badge"><?php echo t('class_6_8'); ?></span>
                    <h4><?php echo t('video3_title'); ?></h4>
                    <p><?php echo t('video3_teacher'); ?></p>
                </div>
            </div>

            <div class="video-card" data-category="class9-10" data-keywords="৯ম ১০ম এসএসসি বীজগণিত">
                <div class="video-wrapper">
                    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="গণিত টিউটোরিয়াল" allowfullscreen></iframe>
                </div>
                <div class="video-info">
                    <span class="video-badge"><?php echo t('class_9_10'); ?></span>
                    <h4><?php echo t('video4_title'); ?></h4>
                    <p><?php echo t('video4_teacher'); ?></p>
                </div>
            </div>
        </div>

        <!-- DIRECT YOUTUBE CHANNEL BUTTON -->
        <div class="youtube-channel-btn-container">
            <a href="" target="_blank" class="btn-youtube-channel">
                <?php echo t('visit_youtube'); ?>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <!-- NEWS / ANNOUNCEMENTS SECTION (Website Module > News — আগে কোথাও দেখানো হতো না) -->
    <?php if ($site_news): ?>
    <section class="news-section" id="news">
        <h3 class="section-title"><?php echo t('recent_news'); ?></h3>
        <div class="news-grid">
            <?php foreach ($site_news as $nw): ?>
            <div class="news-card">
                <?php if (!empty($nw['image_path'])): ?><img src="<?php echo htmlspecialchars($nw['image_path']); ?>" alt="<?php echo htmlspecialchars($nw['title']); ?>"><?php endif; ?>
                <div class="news-card-body">
                    <span class="news-card-date">📅 <?php echo site_bn_date($nw['news_date']); ?></span>
                    <h4><?php echo htmlspecialchars($nw['title']); ?></h4>
                    <?php if (!empty($nw['short_desc'])): ?><p><?php echo htmlspecialchars($nw['short_desc']); ?></p><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- CAREER SECTION (Website Module > Career — আগে কোথাও দেখানো হতো না) -->
    <?php if ($site_careers): ?>
    <section class="career-section" id="career">
        <h3 class="section-title"><?php echo t('career_notice'); ?></h3>
        <div class="career-list">
            <?php foreach ($site_careers as $cr): ?>
            <div class="career-item">
                <div>
                    <h4><?php echo htmlspecialchars($cr['position_title']); ?></h4>
                    <?php if (!empty($cr['department'])): ?><span class="career-dept"><?php echo htmlspecialchars($cr['department']); ?></span><?php endif; ?>
                    <?php if (!empty($cr['deadline_date'])): ?><div class="career-deadline"><?php echo t('deadline_label'); ?> <?php echo site_bn_date($cr['deadline_date']); ?></div><?php endif; ?>
                </div>
                <?php if (!empty($cr['apply_link'])): ?><a href="<?php echo htmlspecialchars($cr['apply_link']); ?>" target="_blank" rel="noopener" class="btn-apply-career"><?php echo t('apply_now'); ?></a><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- PHOTO ALBUM SECTION (Website Module > Album — আগে কোথাও দেখানো হতো না) -->
    <?php if ($site_albums): ?>
    <section class="album-section" id="albums">
        <h3 class="section-title"><?php echo t('photo_album'); ?></h3>
        <div class="album-grid-section">
            <?php foreach ($site_albums as $al): ?>
            <div class="album-card2">
                <img src="<?php echo htmlspecialchars($al['cover_image']); ?>" alt="<?php echo htmlspecialchars($al['title']); ?>">
                <div class="album-card2-body">
                    <h4><?php echo htmlspecialchars($al['title']); ?></h4>
                    <?php if (!empty($al['description'])): ?><p><?php echo htmlspecialchars($al['description']); ?></p><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- PHOTO GALLERY SECTION -->
    <?php if ($show_gallery_home): ?>
    <section class="gallery-section" id="gallery">
        <h3 class="section-title"><?php echo t('photo_gallery'); ?></h3>
        <div class="gallery-grid">
            <?php if ($site_gallery): foreach (array_slice($site_gallery, 0, 8) as $g): ?>
            <div class="gallery-item">
                <img src="<?php echo htmlspecialchars($g['image_path']); ?>" alt="<?php echo htmlspecialchars($g['caption']); ?>">
                <div class="gallery-caption"><?php echo htmlspecialchars($g['caption']); ?></div>
            </div>
            <?php endforeach; else: ?>
            <p class="text-muted"><?php echo t('no_gallery_photo'); ?></p>
            <?php endif; ?>
        </div>
        <?php if (count($site_gallery) > 8): ?>
            <div class="card-bottom-action">
                <a href="gallery.php" class="btn-view-all"><?php echo t('see_all'); ?></a>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- FOOTER -->
    <footer id="contact">
        <div class="footer-grid">
            <div class="footer-col">
                <h4><?php echo t('important_links_lbl'); ?></h4>
                <ul class="useful-links-list">
                    <?php if ($site_useful_links): foreach ($site_useful_links as $ul): ?>
                    <li><a href="<?php echo htmlspecialchars($ul['url']); ?>" target="_blank">➔ <?php echo htmlspecialchars($ul['title']); ?></a></li>
                    <?php endforeach; else: ?>
                    <li><a href="https://moedu.gov.bd" target="_blank">➔ <?php echo t('ministry_of_edu'); ?></a></li>
                    <li><a href="https://www.dshe.gov.bd" target="_blank">➔ <?php echo t('dshe_link'); ?></a></li>
                    <li><a href="https://www.nctb.gov.bd" target="_blank">➔ <?php echo t('nctb_link'); ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="footer-col">
                <h4><?php echo t('address_heading'); ?></h4>
                <?php
                    // "ঠিকানা" কলামে সবসময় Main branch (short_name = MAIN) দেখানো হবে,
                    // একাধিক branch থাকলেও এখানে সব branch লিস্ট করা হবে না — সব branch-এর
                    // তথ্য দেখতে নিচের লিংক দিয়ে আলাদা পেজে যেতে হবে।
                    $main_branch = null;
                    foreach ($site_branches as $sb_find) {
                        if (strcasecmp(trim((string) ($sb_find['short_name'] ?? '')), 'MAIN') === 0) {
                            $main_branch = $sb_find;
                            break;
                        }
                    }
                    if (!$main_branch && $site_branches) {
                        $main_branch = $site_branches[0]; // MAIN না পেলে প্রথম branch-ই fallback
                    }
                ?>
                <?php if ($main_branch): ?>
                    <p style="font-size: 13px; line-height: 1.8;">
                        <?php echo site_val($inst['institute_name'], 'Your School Name'); ?><br>
                        <?php echo htmlspecialchars($main_branch['address']); ?><?php echo $main_branch['city'] ? ', ' . htmlspecialchars($main_branch['city']) : ''; ?><br>
                        <?php echo t('helpline_colon'); ?> <?php echo $main_branch['phone'] ?: site_val($inst['phone_number'], '+৮৮০ ১৭০০-০০০০০০'); ?><br>
                        <?php echo t('email_colon'); ?> <?php echo $main_branch['email'] ?: site_val($inst['email'], 'info@school.edu.bd'); ?>
                    </p>
                <?php else: ?>
                    <p style="font-size: 13px; line-height: 1.8;">
                        <?php echo site_val($inst['institute_name'], 'Your School Name'); ?><br>
                        <?php echo t('default_address'); ?><br>
                        <?php echo t('helpline_colon'); ?> <?php echo site_val($inst['phone_number'], '+৮৮০ ১৭০০-০০০০০০'); ?><br>
                        <?php echo t('email_colon'); ?> <?php echo site_val($inst['email'], 'info@school.edu.bd'); ?>
                    </p>
                <?php endif; ?>
                <?php if (count($site_branches) > 1): ?>
                    <a href="branches.php" target="_blank" class="btn-view-all" style="display:inline-block;margin-top:4px;"><?php echo t('all_branch_address'); ?></a>
                <?php endif; ?>
            </div>
            
            <div class="footer-col">
                <h4><?php echo t('social_media'); ?></h4>
                <p style="font-size: 13px; margin-bottom: 8px;"><?php echo t('follow_our_page'); ?></p>
                <div class="social-links-grid">
                    <?php if ($site_social_media): foreach ($site_social_media as $sm): ?>
                    <a href="<?php echo htmlspecialchars($sm['url']); ?>" target="_blank" class="social-btn <?php echo site_social_class($sm['platform']); ?>" title="<?php echo htmlspecialchars($sm['platform']); ?>">
                        <?php echo site_social_icon_svg($sm['platform']); ?>
                    </a>
                    <?php endforeach; else: ?>
                    <a href="<?php echo site_val($inst['facebook_page'], 'https://www.facebook.com'); ?>" target="_blank" class="social-btn fb" title="Facebook">
                        <?php echo site_social_icon_svg('Facebook'); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="footer-col">
                <h4><?php echo t('our_location_map'); ?></h4>
                <div class="map-container">
                    <?php
                    // Main branch এর Branch Settings > Map IFrame থেকে ম্যাপ; সেট করা না থাকলে (বা ভুল ফরম্যাট হলে) আগের ডিফল্ট ম্যাপ
                    $footer_map = !empty($main_branch['map_iframe']) ? map_embed_html($main_branch['map_iframe'], 'Location Map') : '';
                    echo $footer_map !== '' ? $footer_map : '';
                    ?>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            &copy; <?php echo date('Y'); ?> <?php echo site_val($inst['institute_name'], 'Your School Name'); ?>. All Rights Reserved.
        </div>
    </footer>

    <!-- JAVASCRIPT FOR SLIDER, STAFF FILTER & VIDEO FILTER -->
    <script>
        // Hero Slider Logic
        let slideIndex = 0;
        let slideInterval;

        function showSlide(index) {
            const slides = document.querySelectorAll('.slide');
            const dots = document.querySelectorAll('.dot');
            
            if (index >= slides.length) slideIndex = 0;
            if (index < 0) slideIndex = slides.length - 1;

            slides.forEach(slide => slide.classList.remove('active'));
            dots.forEach(dot => dot.classList.remove('active'));

            slides[slideIndex].classList.add('active');
            dots[slideIndex].classList.add('active');
        }

        function moveSlide(step) {
            slideIndex += step;
            showSlide(slideIndex);
            resetTimer();
        }

        function currentSlide(index) {
            slideIndex = index;
            showSlide(slideIndex);
            resetTimer();
        }

        function autoSlide() {
            slideIndex++;
            showSlide(slideIndex);
        }

        function resetTimer() {
            clearInterval(slideInterval);
            slideInterval = setInterval(autoSlide, 5000);
        }

        slideInterval = setInterval(autoSlide, 5000);

        // Video Filter Logic
        function filterVideos() {
            const selectedCategory = document.getElementById('classDropdown').value;
            const searchQuery = document.getElementById('videoSearchInput').value.toLowerCase().trim();
            const videoCards = document.querySelectorAll('.video-card');

            videoCards.forEach(card => {
                const category = card.getAttribute('data-category');
                const titleText = card.querySelector('h4').textContent.toLowerCase();
                const keywords = card.getAttribute('data-keywords') ? card.getAttribute('data-keywords').toLowerCase() : '';

                const matchesCategory = (selectedCategory === 'all' || category === selectedCategory);
                const matchesSearch = (searchQuery === '' || titleText.includes(searchQuery) || keywords.includes(searchQuery));

                if (matchesCategory && matchesSearch) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>

</body>
</html>