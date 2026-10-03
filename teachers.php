<?php
// teachers.php - পাবলিক পেজ: হোমপেজের "OUR TEACHERS AND STAFFS" সেকশনের "See All"
// এবং মেনুবারের "Teachers/Staff" লিংকে ক্লিক করলে আসে — সব Active Teacher/Staff-এর সম্পূর্ণ তালিকা।
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';
require_once __DIR__ . '/config/teacher_display.php';

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}

// হোমপেজের সাথে হুবহু মিলিয়ে একই Setting/Query — sms_settings টেবিল না থাকলে (নতুন tenant) সেফ ডিফল্ট
$sms_settings = $pdo->query("SELECT * FROM sms_settings WHERE id = 1")->fetch();
if (!$sms_settings) {
    $sms_settings = ['show_teachers_home' => 1, 'show_section_filter' => 0, 'show_teachers_mobile' => 1, 'show_teachers_address' => 1, 'show_teachers_email' => 1];
}
$show_teachers_home    = (bool) $sms_settings['show_teachers_home'];
$show_section_filter   = (bool) ($sms_settings['show_section_filter'] ?? 0);
$show_teachers_mobile  = (bool) $sms_settings['show_teachers_mobile'];
$show_teachers_address = (bool) $sms_settings['show_teachers_address'];
$show_teachers_email   = (bool) $sms_settings['show_teachers_email'];

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
             ORDER BY " . TD_ORDER_BY . ""
        )->fetchAll();
    } catch (PDOException $e) {
        $site_teachers = []; // employees/designations টেবিল এখনো তৈরি হয়নি
    }
}

$init_section = strtolower($_GET['section'] ?? 'all');
if (!in_array($init_section, ['school', 'college'], true)) $init_section = 'all';

function tl_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('all_teachers_staffs'); ?> - <?php echo tl_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; --text-dark: #1f2937; --text-light: #64748b; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }

    .tl-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .tl-wrap { max-width: 1180px; margin: -20px auto 50px; padding: 0 15px; }
    .tl-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 24px; }

    .teachers-header-wrapper { position: relative; margin-bottom: 25px; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 15px; }
    .teacher-filter-btns { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
    .sec-badge { display: inline-block; font-size: 10px; padding: 1px 8px; border-radius: 9px; background: #ecfdf5; color: #047857; font-weight: 700; }
    .filter-btn { background: #ffffff; color: var(--text-dark); border: 1px solid #cbd5e1; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
    .filter-btn:hover, .filter-btn.active { background: #0284c7; color: white; border-color: #0284c7; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.3); }

    .teachers-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
    .teacher-card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; text-align: center; padding: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .teacher-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
    .teacher-card img { width: 100%; height: 180px; object-fit: cover; border-radius: 6px; margin-bottom: 12px; }
    .teacher-card h5 { font-size: 15px; color: var(--text-dark); font-weight: 700; margin-bottom: 2px; }
    .teacher-card .designation { font-size: 12px; color: var(--text-light); font-weight: 500; }
    .teacher-card .teacher-meta { font-size: 11px; color: var(--text-light); margin-top: 4px; word-break: break-word; }

    .tl-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .tl-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }

    @media (max-width: 992px) {
        .teachers-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 768px) {
        .teachers-header-wrapper { flex-direction: column; }
        .teachers-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .teacher-card { padding: 8px; border-radius: 6px; }
        .teacher-card img { height: 90px; margin-bottom: 8px; }
        .teacher-card h5 { font-size: 12px; margin-bottom: 1px; }
        .teacher-card .designation { font-size: 10px; }
        .teacher-card .teacher-meta { font-size: 9px; margin-top: 2px; }
    }
</style>
</head>
<body>
    <?php $current_page = 'teachers'; require __DIR__ . '/site_header.php'; ?>
    <div class="tl-banner"><?php echo t('all_teachers_staffs'); ?></div>

    <div class="tl-wrap">
        <div class="tl-card">
            <div class="teachers-header-wrapper">
                <?php
                $td_opts = ['section_mode' => $show_section_filter, 'init_section' => $show_section_filter ? $init_section : 'all', 'limit' => 0,
                            'show_mobile' => $show_teachers_mobile, 'show_email' => $show_teachers_email, 'show_address' => $show_teachers_address];
                echo td_filters($td_opts);
                ?>
            </div>

            <?php echo td_grids($site_teachers, $td_opts); ?>
        </div>
        <a href="index.php" class="tl-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo tl_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="teachers.php"><?php echo t('all_teachers_staffs'); ?></a></div>
    </footer>

</body>
</html>
