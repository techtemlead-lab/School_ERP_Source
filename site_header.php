<?php
// site_header.php - সব পাবলিক পেজের কমন হেডার + মেনুবার (Top Bar, Logo, Navbar)
// ব্যবহারঃ প্রতিটা পাবলিক পেজে <body> ট্যাগের ঠিক পরে এটা require করুন। এতে সব পেজে
// একই হেডার/মেনু দেখাবে, আর এখানে একবার বদলালেই সব পেজে বদলে যাবে।
//
//   $current_page = 'home';              // অথবা 'admission' / 'academic_result' / 'result_statistics'
//   require __DIR__ . '/site_header.php';
//
// $pdo আগে থেকে সেট করা না থাকলে এই ফাইলই config/db.php লোড করে নেবে।

if (!isset($pdo)) {
    require_once __DIR__ . '/config/db.php';
}
require_once __DIR__ . '/config/sms.php';
sms_ensure_schema($pdo);

// Bangla/English ভাষা টগল সিস্টেম — যেহেতু site_header.php সব পাবলিক পেজেই require হয়,
// এখানে একবার লোড করলেই t() হেল্পার ফাংশন এবং current_lang() পুরো পেজে ব্যবহার করা যাবে
require_once __DIR__ . '/config/lang.php';

if (!isset($inst)) {
    $inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
    if (!$inst) {
        $inst = [
            'institute_name' => 'Your School Name', 'phone_number' => '', 'email' => '',
            'facebook_page' => '', 'logo_path' => '', 'favicon_path' => '', 'banner_path' => '', 'short_description' => '', 'eiin_no' => '', 'slogan' => '',
        ];
    }
}
// academic_result.php / result_statistics.php নিজের কাজের জন্য institute_settings থেকে মাত্র কয়েকটা কলাম
// আনে — ফলে ওই পেজগুলোর হেডারে স্লোগান/হেল্পলাইন/EIIN এর জায়গায় ডিফল্ট ডেমো লেখা দেখাত। যে কলাম
// নেই শুধু সেটুকু এখানে পূরণ করা হয় (পেজের নিজের আনা মান যেমন ছিল তেমনই থাকে)
if (is_array($inst) && array_diff(['institute_name', 'slogan', 'phone_number', 'eiin_no', 'logo_path', 'banner_path'], array_keys($inst))) {
    try {
        $hdr_inst_full = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
    } catch (PDOException $e) {
        $hdr_inst_full = false; // পড়া না গেলে পেজ আগের মতোই ডিফল্ট লেখা দিয়ে চলবে
    }
    if ($hdr_inst_full) {
        $inst = $inst + $hdr_inst_full;
    }
}

$sms_settings_hdr = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch();
$show_video_home_hdr   = $sms_settings_hdr ? (bool) $sms_settings_hdr['show_video_home'] : true;
$show_gallery_home_hdr = $sms_settings_hdr ? (bool) $sms_settings_hdr['show_gallery_home'] : true;
// Website Module > Home Page Settings > Breaking News Bar Position থেকে —
// হিরো/স্লাইডার সেকশনের উপরে না নিচে দেখাবে (ডিফল্ট: উপরে)। "SELECT *" ব্যবহার করা
// হচ্ছে যাতে sms_ensure_schema() দিয়ে কলাম self-heal হওয়ার সাথে সাথেই — কোনো নির্দিষ্ট
// কলাম-নাম বাদ পড়ে যাওয়ার (এবং চুপচাপ পুরনো ভ্যালু রয়ে যাওয়ার) সুযোগ না থাকে
$hdr_breaking_news_position = (isset($sms_settings_hdr['breaking_news_position']) && $sms_settings_hdr['breaking_news_position'] === 'below_hero')
    ? 'below_hero' : 'above_hero';

// Website Module > Board Result — Admin থেকে সেভ করা Result লিংক থাকলে সেটাই দেখানো হবে,
// না থাকলে ডিফল্ট eboardresults লিংক (আগে এটা সবসময় হার্ডকোডেড ছিল, Admin থেকে বদলানো যেত না)
function site_hdr_table_exists(PDO $pdo, string $table): bool
{
    try { $pdo->query("SELECT 1 FROM `$table` LIMIT 1"); return true; } catch (PDOException $e) { return false; }
}
$hdr_board_results = site_hdr_table_exists($pdo, 'website_board_results')
    ? $pdo->query("SELECT title, result_link FROM website_board_results WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll()
    : [];
// Website Module > Menu — মেনুবারের সব মেনু/সাব-মেনু এখন DB থেকে আসে (ডিফল্ট মেনুগুলো প্রথমবার
// অটো সিড হয়; Admin > Website Module > Menu List থেকে সাব-মেনু/কন্টেন্ট যোগ করা যায়)
require_once __DIR__ . '/config/website_menu.php';
try {
    $hdr_menu_tree = wm_public_tree($pdo);
} catch (PDOException $e) {
    $hdr_menu_tree = []; // DB সমস্যা হলেও পাবলিক পেজ যেন ভেঙে না পড়ে
}
// Website Module > Breaking News — স্ক্রলিং রেড টিকার হিসেবে সব পেজে দেখানো হবে (আগে কোথাও দেখানো হতো না)
$hdr_breaking_news = site_hdr_table_exists($pdo, 'website_breaking_news')
    ? $pdo->query("SELECT news_text, link FROM website_breaking_news WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll()
    : [];

if (!function_exists('site_val')) {
    function site_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
}

$current_page = $current_page ?? '';
$is_home_page = ($current_page === 'home');
// হোম পেজের সেকশন-লিংক (#about, #videos ইত্যাদি) — হোম পেজে থাকলে সরাসরি #anchor,
// অন্য পেজ থেকে হলে index.php#anchor (যাতে অন্য পেজ থেকেও কাজ করে)
$sec = function ($anchor) use ($is_home_page) { return $is_home_page ? "#$anchor" : "index.php#$anchor"; };

// হেডার, মোবাইল ড্রয়ার আর কুইক-অ্যাকশন সারিতে একই তথ্য একাধিকবার লাগে — তাই একবারেই তৈরি করে রাখা
$hdr_name          = site_val($inst['institute_name'] ?? '', 'Your School Name');
$hdr_logo          = !empty($inst['logo_path']) ? htmlspecialchars($inst['logo_path']) : 'logo.png';
$hdr_logo_fallback = "this.onerror=null;this.src='https://cdn-icons-png.flaticon.com/512/2997/2997295.png'";
$hdr_phone_link    = site_val($inst['phone_number'] ?? '', '');
$hdr_phone_text    = site_val($inst['phone_number'] ?? '', '+880 1700-000000');
$hdr_is_result_pg  = in_array($current_page, ['academic_result', 'result_statistics'], true);
$hdr_login_links   = [
    ['href' => erp_is_demo() ? '/student/' : 'student/login.php', 'label' => t('student_login'), 'short' => t('student_short'), 'icon' => 'fa-user-graduate'],
    ['href' => erp_is_demo() ? '/teacher/' : 'teacher/login.php', 'label' => t('teacher_login'), 'short' => t('teacher_short'), 'icon' => 'fa-chalkboard-user'],
    ['href' => 'admin/index.php',   'label' => t('admin_login'),   'short' => t('admin_short'),   'icon' => 'fa-user-shield'],
];
// "ভর্তি আবেদন" + "লগইন" বাটন — ডেস্কটপ/ট্যাবলেটে হেডারের ডান পাশে, ছোট মোবাইলে হেডারের ঠিক নিচে আলাদা সারিতে
$hdr_render_actions = function () use ($hdr_login_links) {
    ?>
        <a href="admission_form.php" class="btn-apply"><i class="fa-solid fa-file-signature" aria-hidden="true"></i><span><?php echo t('admission_apply_btn'); ?></span></a>
        <div class="login-dropdown">
            <button type="button" class="btn-header-login" aria-haspopup="true" aria-expanded="false">
                <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span><?php echo t('login_label'); ?></span><i class="fa-solid fa-chevron-down hdr-caret" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu">
                <?php foreach ($hdr_login_links as $ll): ?>
                <a href="<?php echo $ll['href']; ?>"><?php echo $ll['label']; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php
};
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root {
        --primary: #0a2540;
        --accent: #10b981;
        --secondary: #f8fafc;
        --text-dark: #1e293b;
        --text-light: #64748b;
        --btn-blue: #3b82f6;
        --teal-card: #00bcd4;
    }

    /* ================= TOP BAR ================= */
    .top-bar {
        background: var(--primary);
        color: #fff;
        padding: 8px 5%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 14px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .top-bar a { color: #fff; text-decoration: none; margin-left: 15px; }
    .top-bar .tb-ico { display: none; } /* আইকন শুধু মোবাইলে */

    /* BN/EN ভাষা টগল */
    .tb-lang-switch { display: inline-flex; align-items: center; margin-left: 15px; font-weight: 600; }
    .tb-lang-switch a { margin-left: 0; opacity: 0.7; }
    .tb-lang-switch a.active { opacity: 1; text-decoration: underline; }
    .tb-lang-switch span { margin: 0 6px; opacity: 0.6; }

    /* ================= MAIN HEADER (লোগো + নাম + বাটন) ================= */
    /* হেডারের ব্যাকগ্রাউন্ড সবসময় সাদা — ব্যানার আপলোড থাকলেও পুরো হেডার-এ ছড়াবে না,
       ব্যানারটা শুধু লোগো+নামের জায়গাটাতেই (মার্ক করা বক্সেই) একটা ছবি হিসেবে বসবে (নিচে HTML দ্রষ্টব্য) */
    .header-brand {
        background: #ffffff;
        padding: 12px 5%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #e2e8f0;
        position: relative;
        z-index: 1005;
        flex-wrap: wrap;
        gap: 15px;
    }
    .logo-container { display: flex; align-items: center; gap: 15px; text-decoration: none; min-width: 0; }
    /* ব্যানার ছবি আপলোড থাকলে লোগো+নামের বদলে ঠিক এই জায়গায় (একই উচ্চতায়) শুধু ব্যানার ছবিটা দেখাবে */
    .header-banner-img { height: 70px; width: auto; max-width: 100%; object-fit: contain; }
    .site-logo {
        width: 70px; height: 70px; object-fit: contain; flex-shrink: 0;
        border-radius: 50%; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); transition: transform 0.3s ease;
    }
    .site-logo:hover { transform: scale(1.05); }
    .school-name { min-width: 0; }
    .school-name h1 { font-size: 22px; color: var(--primary); line-height: 1.2; font-weight: 700; margin: 0; }
    .school-name p { font-size: 13px; color: var(--text-light); margin: 2px 0 0; line-height: 1.4; }

    .header-actions { display: flex; align-items: center; gap: 12px; }
    .btn-apply {
        background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; padding: 10px 20px; border-radius: 8px;
        text-decoration: none; font-weight: 700; font-size: 14px; line-height: 1.3; font-family: inherit; white-space: nowrap;
        display: flex; align-items: center; gap: 8px;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25); transition: transform 0.2s ease;
    }
    .btn-apply:hover { transform: translateY(-2px); }

    .login-dropdown { position: relative; }
    .btn-header-login {
        background: var(--accent); color: white; padding: 10px 20px; border-radius: 8px; border: none;
        cursor: pointer; font-weight: 700; font-size: 14px; line-height: 1.3; font-family: inherit; white-space: nowrap;
        display: flex; align-items: center; gap: 8px;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }
    .btn-header-login .hdr-caret { font-size: 10px; transition: transform 0.2s ease; }
    .login-dropdown.open .hdr-caret { transform: rotate(180deg); }
    .dropdown-menu {
        display: none; position: absolute; right: 0; top: 100%; margin-top: 5px; background: white;
        box-shadow: 0 10px 25px rgba(0,0,0,0.18); border-radius: 8px; min-width: 170px; overflow: hidden;
        z-index: 2000; border: 1px solid #e2e8f0; text-align: left;
    }
    .dropdown-menu a {
        display: block; padding: 12px 16px; color: var(--text-dark); border-bottom: 1px solid #f1f5f9;
        text-decoration: none; font-size: 13px; font-weight: 600; line-height: 1.4; white-space: nowrap;
    }
    .dropdown-menu a:last-child { border-bottom: none; }
    .dropdown-menu a:hover, .dropdown-menu a.active { background: #f8fafc; color: var(--accent); }
    /* ক্লিক/ট্যাপে খোলা (JS দিয়ে .open ক্লাস) */
    .login-dropdown.open .dropdown-menu,
    .nav-item-dropdown.open .dropdown-menu { display: block; }

    /* ================= MENU BAR (ডেস্কটপ) ================= */
    .nav-item-dropdown { position: relative; }
    .nav-item-dropdown > a .nav-caret { font-size: 11px; margin-left: 4px; transition: transform 0.2s ease; }
    .nav-item-dropdown .dropdown-menu { left: 0; right: auto; }

    .navbar {
        background: #ffffff; padding: 0 5%; box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        position: sticky; top: 0; z-index: 1000; border-bottom: 3px solid var(--accent);
    }
    /* মেনুতে #about, #academic ইত্যাদি ক্লিক করলে স্টিকি মেনুবারের নিচে সেকশনের টপ যেন ঢাকা না পড়ে,
       আর স্মূথ স্ক্রল থাকায় ক্লিকের পর যে পেজে গেছে সেটা স্পষ্ট বোঝা যাবে */
    html { scroll-behavior: smooth; }
    #about, #academic, #videos, #news, #career, #contact { scroll-margin-top: 70px; }
    /* row-gap 0 — মেনু দুই লাইনে গেলেও লাইনের মাঝে বড় ফাঁকা জায়গা থাকবে না */
    .nav-links { display: flex; list-style: none; column-gap: 20px; row-gap: 0; align-items: center; flex-wrap: wrap; margin: 0; padding: 0; }
    .nav-links > li > a { display: block; padding: 15px 0; text-decoration: none; color: var(--text-dark); font-weight: 600; font-size: 15px; line-height: 1.6; transition: color 0.3s; }
    .nav-links > li > a:hover, .nav-links > li > a.active { color: var(--accent); }

    /* শুধু মাউসওয়ালা ডেস্কটপে hover-এ ড্রপডাউন খুলবে। টাচ ফোনে hover "আটকে" থাকে বলে আগে
       ফলাফল/লগইন মেনু একবার খুললে আর বন্ধ হতো না — তাই মোবাইলে শুধু ট্যাপে খোলা/বন্ধ হবে */
    @media (hover: hover) and (min-width: 992px) {
        .login-dropdown:hover .dropdown-menu,
        .nav-item-dropdown:hover .dropdown-menu { display: block; }
        /* বাটন আর ড্রপডাউনের মাঝের ৫px ফাঁকে মাউস গেলে মেনু যেন বন্ধ না হয়ে যায় */
        .login-dropdown:hover::after,
        .nav-item-dropdown:hover::after { content: ""; position: absolute; left: 0; right: 0; top: 100%; height: 8px; }
    }
    @media (min-width: 992px) and (max-width: 1199.98px) {
        .nav-links { column-gap: 16px; }
        .nav-links > li > a { font-size: 14px; }
    }

    /* মোবাইল-অনলি অংশ — ডেস্কটপে লুকানো */
    .nav-toggle, .hdr-quick-actions, .nav-backdrop, .nav-drawer-head, .nav-drawer-foot, .nav-ico { display: none; }

    /* ================= BREAKING NEWS ================= */
    .breaking-news-bar {
        background: #dc2626;
        color: #fff;
        display: flex;
        align-items: center;
        padding: 6px 5%;
        font-size: 13px;
        gap: 12px;
    }
    .breaking-news-label {
        background: #7f1d1d;
        padding: 3px 10px;
        border-radius: 4px;
        font-weight: bold;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .breaking-news-bar a { color: #fff; text-decoration: underline; }
    /* <marquee> ট্যাগে থাকা লিংকে মোবাইলে ট্যাপ কাজ করছিল না (স্ক্রলিং টেক্সট ব্রাউজারের
       নিজস্ব রেন্ডারিং দিয়ে নড়ে, তাই আঙুল রাখার সময়েই লিংক সরে যাচ্ছিল এবং ট্যাপ মিস
       হয়ে যাচ্ছিল) — তাই <marquee> এর বদলে CSS animation দিয়ে নিজস্ব স্ক্রলার,
       যেখানে আসল <a> ট্যাগ ঠিকঠাক ক্লিক/ট্যাপযোগ্য থাকে
    */
    .bn-scroll-viewport { overflow: hidden; flex: 1; min-width: 0; }
    .bn-scroll-track { display: inline-flex; white-space: nowrap; animation: bn-scroll 28s linear infinite; }
    .bn-scroll-track:hover, .bn-scroll-track:active { animation-play-state: paused; }
    .bn-scroll-track span.bn-sep { margin: 0 22px; opacity: .7; }
    @keyframes bn-scroll {
        0%   { transform: translateX(0); }
        100% { transform: translateX(-50%); }
    }

    /* =====================================================================
       মোবাইল + ট্যাবলেট (≤ 991px)
       - টপ বার এক লাইনে
       - হেডার (লোগো + নাম + ☰) স্ক্রল করলেও উপরে আটকে থাকে (sticky)
       - মেনুবার আর পেজের জায়গা দখল করে না — ☰ চাপলে ডান পাশ থেকে ড্রয়ার হিসেবে খোলে
       (index.php-র নিজস্ব ≤768px হেডার রুলগুলো এই স্টাইল ব্লক পরে আসায় এখানেই ওভাররাইড হয়ে যায়)
       ===================================================================== */
    @media screen and (max-width: 991.98px) {
        /* #about, #contact ইত্যাদিতে জাম্প করলে সেকশনের শিরোনাম স্টিকি হেডারের নিচে চাপা পড়বে না */
        html { scroll-padding-top: 90px; }

        .top-bar {
            padding: 6px 16px; font-size: 12.5px; line-height: 1.4;
            justify-content: space-between; text-align: left; flex-wrap: nowrap; gap: 10px; overflow: hidden;
        }
        .top-bar > div { display: flex; align-items: center; min-width: 0; white-space: nowrap; }
        .top-bar a { margin-left: 14px; display: inline-flex; align-items: center; }
        .top-bar .tb-ico { display: inline-block; font-size: 11px; margin-right: 6px; opacity: .8; }

        .header-brand {
            position: -webkit-sticky; position: sticky; top: 0; z-index: 1010;
            flex-direction: row; flex-wrap: nowrap; justify-content: flex-start; text-align: left;
            gap: 12px; padding: 8px 16px;
            transition: box-shadow 0.25s ease;
        }
        .header-brand.is-stuck { box-shadow: 0 6px 20px rgba(10, 37, 64, 0.14); }
        .logo-container { flex: 1 1 auto; flex-direction: row; gap: 10px; }
        .site-logo { width: 50px; height: 50px; }
        .school-name h1 {
            font-size: 18px; line-height: 1.3;
            display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
        }
        .school-name p { font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .header-brand .header-actions { flex: 0 0 auto; width: auto; justify-content: flex-end; gap: 8px; }
        .header-brand .btn-apply,
        .header-brand .btn-header-login { padding: 9px 14px; font-size: 13.5px; }

        /* ☰ মেনু বাটন */
        .nav-toggle {
            display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto;
            width: 44px; height: 44px; padding: 0; margin: 0; border: 0; border-radius: 12px;
            background: var(--primary); color: #fff; cursor: pointer;
            box-shadow: 0 4px 12px rgba(10, 37, 64, 0.25);
            -webkit-tap-highlight-color: transparent; transition: transform 0.15s ease;
        }
        .nav-toggle:active { transform: scale(0.94); }
        .nav-toggle:focus-visible, .nav-close:focus-visible { outline: 3px solid rgba(16, 185, 129, 0.6); outline-offset: 2px; }
        .nav-toggle-bars { position: relative; display: block; width: 20px; height: 14px; }
        .nav-toggle-bars span { position: absolute; right: 0; height: 2px; border-radius: 2px; background: currentColor; }
        .nav-toggle-bars span:nth-child(1) { top: 0; width: 100%; }
        .nav-toggle-bars span:nth-child(2) { top: 6px; width: 70%; }
        .nav-toggle-bars span:nth-child(3) { top: 12px; width: 100%; }

        /* ---------- স্লাইড-ইন মেনু ড্রয়ার ---------- */
        .navbar {
            position: fixed; top: 0; right: 0; bottom: 0; left: auto;
            width: 86%; max-width: 340px;
            padding: 0; margin: 0; border: 0; border-radius: 0;
            background: #ffffff;
            display: flex; flex-direction: column;
            overflow-x: hidden; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
            z-index: 3001;
            transform: translateX(105%); visibility: hidden;
            box-shadow: -16px 0 40px rgba(2, 6, 23, 0.28);
            transition: transform 0.32s cubic-bezier(.4, 0, .2, 1), visibility 0s linear 0.32s;
        }
        .navbar.is-open {
            transform: translateX(0); visibility: visible;
            transition: transform 0.32s cubic-bezier(.4, 0, .2, 1), visibility 0s linear 0s;
        }
        .nav-drawer-head {
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
            position: sticky; top: 0; z-index: 2;
            padding: 16px 14px 16px 16px;
            background: linear-gradient(135deg, var(--primary) 0%, #134e6f 100%); color: #fff;
        }
        .drawer-brand { display: flex; align-items: center; gap: 10px; flex: 1 1 auto; min-width: 0; color: #fff; text-decoration: none; }
        .drawer-brand img {
            width: 42px; height: 42px; flex-shrink: 0; border-radius: 50%; object-fit: contain;
            background: #fff; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.25);
        }
        .drawer-brand span {
            font-size: 14.5px; font-weight: 700; line-height: 1.3;
            display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
        }
        .nav-close {
            position: relative; flex: 0 0 auto; width: 38px; height: 38px; padding: 0; border: 0; border-radius: 50%;
            background: rgba(255, 255, 255, 0.14); cursor: pointer; -webkit-tap-highlight-color: transparent;
        }
        .nav-close::before, .nav-close::after {
            content: ""; position: absolute; left: 50%; top: 50%; width: 16px; height: 2px; border-radius: 2px; background: #fff;
        }
        .nav-close::before { transform: translate(-50%, -50%) rotate(45deg); }
        .nav-close::after  { transform: translate(-50%, -50%) rotate(-45deg); }

        .navbar .nav-links { flex-direction: column; flex-wrap: nowrap; align-items: stretch; gap: 0; padding: 10px 10px 12px; }
        .navbar .nav-links > li > a {
            display: flex; align-items: center; gap: 12px;
            padding: 7px 10px; margin: 1px 0; border-radius: 10px;
            font-size: 15px; line-height: 1.4; color: var(--text-dark);
            transition: background 0.2s ease, color 0.2s ease;
        }
        .navbar .nav-links > li > a:hover { background: #f1f5f9; color: var(--text-dark); }
        .navbar .nav-links > li > a.active { background: #ecfdf5; color: #047857; }
        .nav-ico {
            display: inline-flex; align-items: center; justify-content: center; flex: 0 0 32px;
            width: 32px; height: 32px; border-radius: 9px;
            background: #f1f5f9; color: var(--primary); font-size: 14px;
        }
        .navbar .nav-links > li > a.active .nav-ico { background: var(--accent); color: #fff; }
        .nav-item-dropdown > a .nav-caret { margin-left: auto; font-size: 12px; color: #94a3b8; }
        .nav-item-dropdown.open > a .nav-caret { transform: rotate(180deg); }
        .navbar .nav-item-dropdown .dropdown-menu {
            position: static; display: none; min-width: 0; margin: 2px 0 6px 26px; padding: 2px 0 2px 14px;
            border: 0; border-left: 2px solid #e2e8f0; border-radius: 0; box-shadow: none; background: transparent; overflow: visible;
        }
        .navbar .nav-item-dropdown.open .dropdown-menu { display: block; }
        .navbar .nav-item-dropdown .dropdown-menu a {
            padding: 9px 12px; border: 0; border-radius: 8px; white-space: normal;
            font-size: 14px; font-weight: 500; color: #475569;
        }
        .navbar .nav-item-dropdown .dropdown-menu a:hover,
        .navbar .nav-item-dropdown .dropdown-menu a.active { background: #f1f5f9; color: #047857; }

        .nav-drawer-foot {
            display: block; margin-top: auto;
            padding: 16px 16px calc(18px + env(safe-area-inset-bottom, 0px));
            background: #f8fafc; border-top: 1px solid #e9eef5;
        }
        .drawer-cta {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 14px; border-radius: 10px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; text-decoration: none;
            font-size: 15px; font-weight: 700; line-height: 1.3;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }
        .drawer-label { display: block; margin: 14px 2px 8px; font-size: 12px; font-weight: 700; color: #64748b; }
        .drawer-login-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .drawer-login-grid a {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            padding: 10px 4px; border-radius: 10px; background: #fff; border: 1px solid #e2e8f0;
            color: var(--text-dark); text-decoration: none; text-align: center;
            font-size: 12.5px; font-weight: 600; line-height: 1.3;
        }
        .drawer-login-grid a i { font-size: 17px; color: var(--accent); }
        .drawer-call {
            display: flex; align-items: center; gap: 10px; margin-top: 14px;
            color: var(--primary); text-decoration: none; font-size: 13.5px; font-weight: 600;
        }
        .drawer-call i {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 50%; background: #e0f2fe; color: #0369a1; font-size: 13px;
        }

        .nav-backdrop {
            display: block; position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 3000;
            background: rgba(2, 6, 23, 0.5); opacity: 0; visibility: hidden;
            transition: opacity 0.3s ease, visibility 0s linear 0.3s; -webkit-tap-highlight-color: transparent;
        }
        .nav-backdrop.is-open { opacity: 1; visibility: visible; transition: opacity 0.3s ease, visibility 0s linear 0s; }
        body.site-nav-open { overflow: hidden; } /* ড্রয়ার খোলা থাকলে পেছনের পেজ স্ক্রল হবে না */
    }

    /* ছোট মোবাইল (≤ 575px): হেডারে শুধু লোগো + নাম + ☰, বাটন দুটো নিচে পুরো প্রস্থে */
    @media screen and (max-width: 575.98px) {
        html { scroll-padding-top: 80px; }
        .top-bar { padding: 6px 12px; }
        .top-bar .tb-label { display: none; }
        .header-brand { padding: 7px 12px; gap: 10px; }
        .site-logo { width: 46px; height: 46px; }
        .school-name h1 { font-size: 15.5px; line-height: 1.28; }
        .school-name p { font-size: 11.5px; margin-top: 1px; }
        .header-brand .header-actions { display: none; }

        .hdr-quick-actions {
            display: flex; gap: 10px; padding: 10px 12px;
            background: #fff; border-bottom: 1px solid #e2e8f0;
            position: relative; z-index: 1008;
        }
        .hdr-quick-actions .btn-apply { flex: 1 1 0; min-width: 0; justify-content: center; padding: 10px 8px; font-size: 14px; }
        .hdr-quick-actions .login-dropdown { flex: 1 1 0; min-width: 0; }
        .hdr-quick-actions .btn-header-login { width: 100%; justify-content: center; padding: 10px 8px; font-size: 14px; }
        .hdr-quick-actions .dropdown-menu { left: 0; right: 0; min-width: 0; }

        .breaking-news-bar { padding: 6px 12px; font-size: 12.5px; gap: 10px; }
        .breaking-news-label { padding: 2px 8px; font-size: 12px; }
    }
    @media screen and (max-width: 359.98px) {
        .top-bar .tb-portal { display: none; }
    }
    /* ফোন আড়াআড়ি (landscape) ধরলে স্ক্রিনের উচ্চতা কম — স্টিকি হেডার আরও ছোট রাখা */
    @media screen and (max-width: 991.98px) and (max-height: 500px) {
        html { scroll-padding-top: 70px; }
        .header-brand { padding-top: 5px; padding-bottom: 5px; }
        .site-logo { width: 40px; height: 40px; }
        .school-name h1 { font-size: 15.5px; }
        .school-name p { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .navbar, .nav-backdrop, .header-brand, .nav-toggle { transition: none !important; }
    }
    @media print {
        .nav-toggle, .hdr-quick-actions, .nav-backdrop, .nav-drawer-head, .nav-drawer-foot { display: none !important; }
    }
</style>

<!-- TOP HEADER -->
<div class="top-bar">
    <div class="tb-eiin"><i class="fa-solid fa-building-columns tb-ico" aria-hidden="true"></i><?php echo t('eiin_label'); ?>: <?php echo site_val($inst['eiin_no'] ?? '', '123456'); ?></div>
    <div class="tb-links">
        <a href="tel:<?php echo $hdr_phone_link; ?>" class="tb-phone"><i class="fa-solid fa-phone tb-ico" aria-hidden="true"></i><span class="tb-label"><?php echo t('helpline_label'); ?>&nbsp;</span><?php echo $hdr_phone_text; ?></a>
        <a href="#" class="tb-portal"><?php echo t('eiin_portal'); ?></a>
        <div class="tb-lang-switch">
            <a href="<?php echo lang_switch_url('bn'); ?>" class="<?php echo current_lang() === 'bn' ? 'active' : ''; ?>">বাংলা</a>
            <span>|</span>
            <a href="<?php echo lang_switch_url('en'); ?>" class="<?php echo current_lang() === 'en' ? 'active' : ''; ?>">English</a>
        </div>
    </div>
</div>

<!-- MAIN HEADER (লোগোতে ক্লিক করলে হোম পেজে যাবে) — মোবাইলে এটাই স্টিকি হেডার -->
<header class="header-brand" id="siteHeader">
    <a href="index.php" class="logo-container">
        <?php if (!empty($inst['banner_path'])): ?>
            <img src="<?php echo htmlspecialchars($inst['banner_path']); ?>" alt="<?php echo $hdr_name; ?>" class="header-banner-img">
        <?php else: ?>
            <img src="<?php echo $hdr_logo; ?>" alt="<?php echo $hdr_name; ?> Logo" class="site-logo" onerror="<?php echo $hdr_logo_fallback; ?>">
            <div class="school-name">
                <h1><?php echo $hdr_name; ?></h1>
                <p><?php echo site_val($inst['slogan'] ?? '', 'Your School Name'); ?></p>
            </div>
        <?php endif; ?>
    </a>

    <div class="header-actions">
        <?php $hdr_render_actions(); ?>
    </div>

    <button type="button" class="nav-toggle" id="siteNavToggle" aria-label="মেনু খুলুন" aria-controls="siteNav" aria-expanded="false">
        <span class="nav-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
    </button>
</header>

<!-- ছোট মোবাইলে "ভর্তি আবেদন" + "লগইন" এখানে পুরো প্রস্থে দেখায় (ডেস্কটপে লুকানো) -->
<div class="hdr-quick-actions">
    <?php $hdr_render_actions(); ?>
</div>

<?php
// ব্রেকিং নিউজ বারের মার্কআপ ফাংশন হিসেবে রাখা হয়েছে যাতে "হিরো সেকশনের নিচে"
// সেটিং করা থাকলে হোমপেজ (index.php) হিরো/স্লাইডারের পরে একই মার্কআপ দিয়ে
// আবার কল করতে পারে — কোড ডুপ্লিকেট না করে
if (!function_exists('site_render_breaking_news_bar')) {
    function site_render_breaking_news_bar(array $items): void
    {
        if (!$items) return;
        // seamless loop-এর জন্য একই লিস্ট দুইবার বসানো হচ্ছে — অ্যানিমেশন 50% পর্যন্ত
        // গিয়ে আবার শুরুতে ফিরলে দেখার সময় কোনো ফাঁক/লাফ বোঝা যাবে না
        $render_items = function (array $items) {
            foreach ($items as $i => $bn) {
                if (!empty($bn['link'])):
                    ?><a href="<?php echo htmlspecialchars($bn['link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($bn['news_text']); ?></a><?php
                else:
                    echo htmlspecialchars($bn['news_text']);
                endif;
                if ($i < count($items) - 1): ?><span class="bn-sep">•</span><?php endif;
            }
        };
        ?>
        <div class="breaking-news-bar">
            <span class="breaking-news-label"><?php echo t('breaking_news_label'); ?></span>
            <div class="bn-scroll-viewport">
                <div class="bn-scroll-track">
                    <?php $render_items($items); ?>
                    <span class="bn-sep">•</span>
                    <?php $render_items($items); // ডুপ্লিকেট — সিমলেস লুপের জন্য ?>
                </div>
            </div>
        </div>
        <?php
    }
}
?>

<!-- BREAKING NEWS TICKER — শুধু "হিরো সেকশনের উপরে" সিলেক্ট করা থাকলেই এখানে দেখাবে,
     "নিচে" সিলেক্ট করা থাকলে index.php হিরো/স্লাইডারের পরে আলাদাভাবে দেখাবে -->
<?php if ($hdr_breaking_news_position === 'above_hero'): site_render_breaking_news_bar($hdr_breaking_news); endif; ?>

<!-- SEPARATE MENU BAR — ডেস্কটপে আগের মতো স্টিকি মেনুবার, মোবাইলে ☰ চাপলে ডান পাশের ড্রয়ার -->
<nav class="navbar" id="siteNav" aria-label="প্রধান মেনু">
    <div class="nav-drawer-head">
        <a href="index.php" class="drawer-brand">
            <img src="<?php echo $hdr_logo; ?>" alt="" onerror="<?php echo $hdr_logo_fallback; ?>">
            <span><?php echo $hdr_name; ?></span>
        </a>
        <button type="button" class="nav-close" aria-label="মেনু বন্ধ করুন" data-nav-close></button>
    </div>

    <ul class="nav-links">
        <?php
        // কোন core মেনু কোন পেজে "active" দেখাবে
        $hdr_active_map = ['nav_home' => 'home', 'nav_about' => 'about', 'nav_teachers' => 'teachers', 'nav_notice' => 'notices', 'nav_gallery' => 'gallery'];
        // লিংক: '#anchor' হলে হোমপেজের সেকশন, ফাঁকা হলে page.php?id=..
        $hdr_href = function (array $m) use ($sec) {
            $l = wm_url($m);
            return (isset($l[0]) && $l[0] === '#' && strlen($l) > 1) ? $sec(substr($l, 1)) : $l;
        };
        foreach ($hdr_menu_tree as $mi):
            $key = $mi['title_key'] ?? '';
            if ($key === 'nav_video' && !$show_video_home_hdr) continue;
            if ($key === 'nav_gallery' && !$show_gallery_home_hdr) continue;
            $label = $key !== '' ? t($key) : htmlspecialchars($mi['title']);
            $ico   = htmlspecialchars($mi['icon'] ?: 'fa-link');
            $blank = !empty($mi['open_new_tab']) ? ' target="_blank" rel="noopener"' : '';
            $is_result = ($key === 'nav_result');
            $kids  = $mi['children'];
            $on = (isset($hdr_active_map[$key]) && $current_page === $hdr_active_map[$key])
                || ($is_result && $hdr_is_result_pg)
                || $current_page === 'page_' . $mi['id'];
            foreach ($kids as $k) { if ($current_page === 'page_' . $k['id']) $on = true; }
            if ($is_result || $kids):
        ?>
        <li class="nav-item-dropdown"<?php echo $is_result ? ' id="resultNavDropdown"' : ''; ?>>
            <a href="#" class="nav-dd-toggle <?php echo $on ? 'active' : ''; ?>" role="button" aria-haspopup="true" aria-expanded="false"><span class="nav-ico" aria-hidden="true"><i class="fa-solid <?php echo $ico; ?>"></i></span><?php echo $label; ?> <i class="fa-solid fa-chevron-down nav-caret" aria-hidden="true"></i></a>
            <div class="dropdown-menu">
                <?php if ($is_result): ?>
                <?php if ($hdr_board_results): foreach ($hdr_board_results as $br): ?>
                <a href="<?php echo htmlspecialchars($br['result_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($br['title']); ?></a>
                <?php endforeach; else: ?>
                <a href="https://www.educationboardresults.gov.bd/" target="_blank" rel="noopener"><?php echo t('nav_result_board'); ?></a>
                <?php endif; ?>
                <a href="academic_result.php" class="<?php echo $current_page === 'academic_result' ? 'active' : ''; ?>"><?php echo t('nav_result_academic'); ?></a>
                <a href="result_statistics.php" class="<?php echo $current_page === 'result_statistics' ? 'active' : ''; ?>"><?php echo t('nav_result_stats'); ?></a>
                <?php endif; ?>
                <?php if (!$is_result && trim((string) $mi['link']) !== '' && trim((string) $mi['link']) !== '#'): ?>
                <a href="<?php echo htmlspecialchars($hdr_href($mi)); ?>"<?php echo $blank; ?>><?php echo $label; ?></a>
                <?php endif; ?>
                <?php foreach ($kids as $k): ?>
                <a href="<?php echo htmlspecialchars($hdr_href($k)); ?>"<?php echo !empty($k['open_new_tab']) ? ' target="_blank" rel="noopener"' : ''; ?> class="<?php echo $current_page === 'page_' . $k['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($k['title']); ?></a>
                <?php endforeach; ?>
            </div>
        </li>
        <?php else: ?>
        <li><a href="<?php echo htmlspecialchars($hdr_href($mi)); ?>" class="<?php echo $on ? 'active' : ''; ?>"<?php echo $blank; ?>><span class="nav-ico" aria-hidden="true"><i class="fa-solid <?php echo $ico; ?>"></i></span><?php echo $label; ?></a></li>
        <?php endif; endforeach; ?>
    </ul>

    <div class="nav-drawer-foot">
        <a href="admission_form.php" class="drawer-cta"><i class="fa-solid fa-file-signature" aria-hidden="true"></i> <?php echo t('admission_apply_btn'); ?></a>
        <span class="drawer-label"><?php echo t('login_label'); ?></span>
        <div class="drawer-login-grid">
            <?php foreach ($hdr_login_links as $ll): ?>
            <a href="<?php echo $ll['href']; ?>"><i class="fa-solid <?php echo $ll['icon']; ?>" aria-hidden="true"></i><?php echo $ll['short']; ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($hdr_phone_link !== ''): ?>
        <a href="tel:<?php echo $hdr_phone_link; ?>" class="drawer-call"><i class="fa-solid fa-phone" aria-hidden="true"></i>হেল্পলাইন: <?php echo $hdr_phone_text; ?></a>
        <?php endif; ?>
    </div>
</nav>
<div class="nav-backdrop" id="siteNavBackdrop" data-nav-close aria-hidden="true"></div>
<noscript><style>
    @media screen and (max-width: 991.98px) {
        .navbar { position: static !important; transform: none !important; visibility: visible !important; width: auto !important; max-width: none !important; box-shadow: none !important; }
        .nav-toggle, .nav-drawer-head, .nav-drawer-foot { display: none !important; }
    }
</style></noscript>

<script>
(function () {
    // মোবাইল মেনু ড্রয়ার, ফলাফল/লগইন ড্রপডাউন আর স্টিকি হেডারের শ্যাডো — সব এখানে।
    // IIFE-র ভেতরে রাখা, যাতে index.php-র moveSlide/filterStaff ইত্যাদি ফাংশনের সাথে নাম না মেলে।
    var body = document.body;
    var header = document.getElementById('siteHeader');
    var nav = document.getElementById('siteNav');
    var toggle = document.getElementById('siteNavToggle');
    var backdrop = document.getElementById('siteNavBackdrop');
    if (!nav || !toggle) return;
    var mqDesktop = window.matchMedia('(min-width: 992px)');

    function setDropdown(dd, open) {
        dd.classList.toggle('open', open);
        var t = dd.querySelector('.btn-header-login, .nav-dd-toggle');
        if (t) t.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    // বাইরে ক্লিক করলে খোলা ড্রপডাউন বন্ধ — তবে মোবাইল ড্রয়ারের ভেতরের "ফলাফল" অ্যাকর্ডিয়ন
    // ইউজার নিজে বন্ধ না করা পর্যন্ত খোলা থাকবে
    function closeDropdowns(except, includeDrawer) {
        var open = document.querySelectorAll('.login-dropdown.open, .nav-item-dropdown.open');
        for (var i = 0; i < open.length; i++) {
            if (open[i] === except) continue;
            if (!includeDrawer && !mqDesktop.matches && nav.contains(open[i])) continue;
            setDropdown(open[i], false);
        }
    }

    function openNav() {
        closeDropdowns(null, false);
        nav.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
        body.classList.add('site-nav-open');
        toggle.setAttribute('aria-expanded', 'true');
        var closeBtn = nav.querySelector('.nav-close');
        if (closeBtn) setTimeout(function () { try { closeBtn.focus({ preventScroll: true }); } catch (e) { closeBtn.focus(); } }, 60);
    }
    function closeNav(restoreFocus) {
        if (!nav.classList.contains('is-open')) return;
        nav.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-open');
        body.classList.remove('site-nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        if (restoreFocus) { try { toggle.focus({ preventScroll: true }); } catch (e) { toggle.focus(); } }
    }

    toggle.addEventListener('click', function () {
        if (nav.classList.contains('is-open')) closeNav(true); else openNav();
    });

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;

        if (t.closest('[data-nav-close]')) { closeNav(true); return; }

        var loginBtn = t.closest('.btn-header-login');
        if (loginBtn) {
            var ld = loginBtn.closest('.login-dropdown');
            var openLogin = !ld.classList.contains('open');
            closeDropdowns(ld, false);
            setDropdown(ld, openLogin);
            return;
        }

        var ddToggle = t.closest('.nav-dd-toggle');
        if (ddToggle) {
            e.preventDefault();
            var li = ddToggle.closest('.nav-item-dropdown');
            var openLi = !li.classList.contains('open');
            closeDropdowns(li, false);
            setDropdown(li, openLi);
            return;
        }

        // ড্রয়ারের কোনো লিংকে ট্যাপ করলে ড্রয়ার বন্ধ (একই পেজের #about ইত্যাদিতে গেলে দরকার)
        var link = t.closest('a');
        if (link && nav.contains(link) && nav.classList.contains('is-open')) closeNav(false);

        closeDropdowns(t.closest('.login-dropdown, .nav-item-dropdown'), false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            closeDropdowns(null, true);
            closeNav(true);
            return;
        }
        // ড্রয়ার খোলা থাকলে Tab চাপলে ফোকাস ড্রয়ারের ভেতরেই ঘুরবে
        if (e.key === 'Tab' && nav.classList.contains('is-open')) {
            var items = Array.prototype.filter.call(nav.querySelectorAll('a[href], button'), function (el) {
                return el.getClientRects().length > 0;
            });
            if (!items.length) return;
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && (document.activeElement === first || !nav.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    // মোবাইল থেকে ডেস্কটপ সাইজে গেলে (রোটেট/রিসাইজ) ড্রয়ার ও ড্রপডাউন রিসেট
    function onBreakpointChange() {
        if (mqDesktop.matches) { closeNav(false); closeDropdowns(null, true); }
    }
    if (mqDesktop.addEventListener) mqDesktop.addEventListener('change', onBreakpointChange);
    else if (mqDesktop.addListener) mqDesktop.addListener(onBreakpointChange);

    // স্টিকি হেডার উপরে আটকে গেলে হালকা শ্যাডো (মোবাইলে)
    if (header) {
        var ticking = false;
        var updateStuck = function () {
            ticking = false;
            var stuck = header.getBoundingClientRect().top <= 0 && (window.pageYOffset || document.documentElement.scrollTop) > 0;
            header.classList.toggle('is-stuck', stuck);
        };
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; window.requestAnimationFrame(updateStuck); }
        }, { passive: true });
        updateStuck();
    }
})();
</script>
