<?php
// page.php - পাবলিক পেজ: Website Module > Menu / Sub Menu এর কন্টেন্ট পেজ (page.php?id=মেনু_id)
// মেনু/সাব-মেনুর লিংক ফাঁকা রাখলে এই পেজেই আসে। মেনুর নিচের Active কন্টেন্টগুলো ক্রমানুসারে দেখায়,
// আর সাব-মেনু থাকলে সেগুলোর তালিকাও দেখায়।
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';
require_once __DIR__ . '/config/website_menu.php';

wm_ensure_schema($pdo);

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
function pg_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }

$menu_id = (int) ($_GET['id'] ?? 0);
$menu = null; $parent = null; $children = []; $contents = [];
if ($menu_id > 0) {
    $st = $pdo->prepare("SELECT * FROM website_menu_items WHERE id = ? AND is_active = 1");
    $st->execute([$menu_id]);
    $menu = $st->fetch() ?: null;
    if ($menu && (int) $menu['parent_id'] > 0) {
        $ps = $pdo->prepare("SELECT * FROM website_menu_items WHERE id = ? AND is_active = 1");
        $ps->execute([(int) $menu['parent_id']]);
        $parent = $ps->fetch() ?: null;
        if (!$parent) $menu = null; // parent হিডেন/মুছে গেছে — সাব-মেনুও দেখানো হবে না
    }
}
if ($menu) {
    $cs = $pdo->prepare("SELECT * FROM website_menu_items WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $cs->execute([$menu_id]);
    $children = $cs->fetchAll();
    $ct = $pdo->prepare("SELECT * FROM website_contents WHERE menu_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $ct->execute([$menu_id]);
    $contents = $ct->fetchAll();
}
if (!$menu) http_response_code(404);

$pg_title = $menu ? (!empty($menu['title_key']) ? t($menu['title_key']) : $menu['title']) : t('nav_home');
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pg_title); ?> - <?php echo pg_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .pg-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .pg-crumb { font-size: 13px; font-weight: 400; opacity: .85; margin-top: 6px; letter-spacing: 0; }
    .pg-crumb a { color: #fff; text-decoration: none; }
    .pg-wrap { max-width: 900px; margin: -20px auto 50px; padding: 0 15px; }
    .pg-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 30px; margin-bottom: 18px; }
    .pg-card h2 { font-size: 19px; color: var(--navy); margin: 0 0 10px; }
    .pg-body { font-size: 15px; color: #475569; line-height: 1.9; }
    .pg-sub { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
    .pg-sub a { display: block; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #1e293b; font-weight: 600; font-size: 14.5px; }
    .pg-sub a:hover { border-color: var(--accent); color: var(--accent); }
    .pg-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .pg-back { display: inline-block; margin-top: 4px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>
    <?php $current_page = $menu ? 'page_' . (int) $menu['id'] : 'page'; require __DIR__ . '/site_header.php'; ?>
    <div class="pg-banner">
        <?php echo htmlspecialchars($pg_title); ?>
        <?php if ($parent): ?>
        <div class="pg-crumb"><a href="<?php echo htmlspecialchars(wm_url($parent)); ?>"><?php echo htmlspecialchars(!empty($parent['title_key']) ? t($parent['title_key']) : $parent['title']); ?></a> &rsaquo; <?php echo htmlspecialchars($pg_title); ?></div>
        <?php endif; ?>
    </div>

    <div class="pg-wrap">
        <?php if (!$menu): ?>
            <div class="pg-card"><div class="pg-empty"><i class="fa-regular fa-file-lines" style="font-size:34px;"></i><p>পেজটি পাওয়া যায়নি।</p></div></div>
        <?php else: ?>
            <?php foreach ($contents as $c): ?>
            <div class="pg-card">
                <h2><?php echo htmlspecialchars($c['title']); ?></h2>
                <div class="pg-body"><?php echo nl2br(htmlspecialchars($c['content'])); ?></div>
            </div>
            <?php endforeach; ?>

            <?php if ($children): ?>
            <div class="pg-card">
                <div class="pg-sub">
                    <?php foreach ($children as $ch): ?>
                    <a href="<?php echo htmlspecialchars(wm_url($ch)); ?>"<?php echo !empty($ch['open_new_tab']) ? ' target="_blank" rel="noopener"' : ''; ?>><i class="fa-solid fa-angle-right"></i> <?php echo htmlspecialchars($ch['title']); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$contents && !$children): ?>
            <div class="pg-card"><div class="pg-empty"><i class="fa-regular fa-file-lines" style="font-size:34px;"></i><p>এই পেজে এখনো কোনো কন্টেন্ট যোগ করা হয়নি।</p></div></div>
            <?php endif; ?>
        <?php endif; ?>
        <a href="index.php" class="pg-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo pg_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a></div>
    </footer>
</body>
</html>
