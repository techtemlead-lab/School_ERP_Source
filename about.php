<?php
// about.php - পাবলিক পেজ: মেনুবারের "আমাদের সম্পর্কে" ক্লিক করলে আসে —
// Global Settings > General Information (Institute Settings) এর Short Description ফিল্ডের
// সম্পূর্ণ কনটেন্ট দেখায়। (why_choose.php এর প্যাটার্ন অনুসরণ করে বানানো)
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => '', 'short_description' => ''];
}

function ab_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('nav_about'); ?> - <?php echo ab_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; --text-dark: #1f2937; --text-light: #64748b; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }

    .ab-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .ab-wrap { max-width: 900px; margin: -20px auto 50px; padding: 0 15px; }
    .ab-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 30px; }

    .ab-content { font-size: 15px; color: #475569; line-height: 1.9; }
    .ab-content ul { padding-left: 22px; }
    .ab-content li { margin-bottom: 6px; }
    .ab-empty { text-align: center; padding: 50px 0; color: #64748b; }

    .ab-back { display: inline-block; margin-top: 22px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>
    <?php $current_page = 'about'; require __DIR__ . '/site_header.php'; ?>
    <div class="ab-banner"><?php echo t('about_institution'); ?></div>

    <div class="ab-wrap">
        <div class="ab-card">
            <?php if (!empty($inst['short_description'])): ?>
                <div class="ab-content"><?php echo $inst['short_description']; ?></div>
            <?php else: ?>
                <div class="ab-empty"><?php echo t('about_empty'); ?></div>
            <?php endif; ?>
        </div>
        <a href="index.php" class="ab-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo ab_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="about.php"><?php echo t('nav_about'); ?></a></div>
    </footer>
</body>
</html>
