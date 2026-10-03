<?php
// messages.php - পাবলিক পেজ: হোমপেজের "সকল বাণী" বাটন এখানে আসে।
// Home Page Settings-এ On থাকা পদবির সব Active বাণী দেখায় — পদবির Order অনুযায়ী (হোমপেজে সর্বোচ্চ ৩টা, বাকিগুলো এখানে)।
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';
require_once __DIR__ . '/config/home_messages.php';

$messages = hm_get_messages($pdo);

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
function ml_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('all_messages'); ?> - <?php echo ml_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .ml-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .ml-wrap { max-width: 900px; margin: -20px auto 50px; padding: 0 15px; }
    .ml-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 22px; display: flex; gap: 20px; margin-bottom: 18px; }
    .ml-card img { width: 120px; height: 140px; object-fit: cover; border-radius: 8px; border: 3px solid var(--accent); flex-shrink: 0; }
    .ml-photo-empty { width: 120px; height: 140px; border-radius: 8px; border: 3px solid var(--accent); background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 34px; flex-shrink: 0; }
    .ml-name { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 2px; }
    .ml-desg { font-size: 13px; font-weight: 700; color: var(--accent); margin-bottom: 10px; }
    .ml-text { font-size: 14.5px; line-height: 1.8; color: #475569; }
    .ml-more { display: inline-block; margin-top: 10px; background: #f1f5f9; color: var(--navy); border: 1px solid #cbd5e1; padding: 4px 14px; border-radius: 15px; font-size: 12px; font-weight: 700; text-decoration: none; }
    .ml-more:hover { background: var(--accent); color: #fff; border-color: var(--accent); }
    .ml-empty { text-align: center; padding: 50px 0; color: #64748b; background: #fff; border-radius: 12px; }
    .ml-back { display: inline-block; margin-top: 8px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
    @media (max-width: 600px) { .ml-card { flex-direction: column; align-items: center; text-align: center; } }
</style>
</head>
<body>
    <?php $current_page = 'messages'; require __DIR__ . '/site_header.php'; ?>
    <div class="ml-banner"><?php echo t('all_messages'); ?></div>

    <div class="ml-wrap">
        <?php if (!$messages): ?>
            <div class="ml-empty"><?php echo t('no_message_found'); ?></div>
        <?php else: foreach ($messages as $m): ?>
            <div class="ml-card">
                <?php if (!empty($m['photo_path'])): ?>
                    <img src="<?php echo htmlspecialchars($m['photo_path']); ?>" alt="<?php echo htmlspecialchars($m['name']); ?>">
                <?php else: ?>
                    <div class="ml-photo-empty"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div>
                    <div class="ml-name"><?php echo htmlspecialchars($m['name']); ?></div>
                    <div class="ml-desg"><?php echo htmlspecialchars($m['designation']); ?></div>
                    <div class="ml-text"><?php echo nl2br(htmlspecialchars(hm_excerpt($m['message'], 420))); ?></div>
                    <a class="ml-more" href="message_view.php?id=<?php echo (int) $m['id']; ?>"><?php echo t('read_more'); ?></a>
                </div>
            </div>
        <?php endforeach; endif; ?>
        <a href="index.php" class="ml-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo ml_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="messages.php"><?php echo t('all_messages'); ?></a></div>
    </footer>
</body>
</html>
