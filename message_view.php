<?php
// message_view.php - পাবলিক পেজ: একটা বাণীর সম্পূর্ণ লেখা (হোমপেজ / সকল বাণী পেজের "Read More" এখানে আসে)
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';
require_once __DIR__ . '/config/home_messages.php';

// শুধু Active + যে পদবি Home Page Settings-এ On আছে — Off করা পদবির বাণী সরাসরি লিংকেও খুলবে না
$msg = hm_get_message($pdo, (int) ($_GET['id'] ?? 0));

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
function mv_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $msg ? htmlspecialchars($msg['name']) . ' - ' : ''; ?><?php echo mv_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .mv-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 22px; font-weight: 700; letter-spacing: 1px; }
    .mv-wrap { max-width: 800px; margin: -20px auto 50px; padding: 0 15px; }
    .mv-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 30px; }
    .mv-head { display: flex; gap: 20px; align-items: center; margin-bottom: 22px; padding-bottom: 20px; border-bottom: 1px solid #eef2f7; }
    .mv-head img, .mv-photo-empty { width: 120px; height: 140px; object-fit: cover; border-radius: 8px; border: 3px solid var(--accent); flex-shrink: 0; }
    .mv-photo-empty { background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 34px; }
    .mv-name { font-size: 21px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
    .mv-desg { font-size: 14px; font-weight: 700; color: var(--accent); }
    .mv-body { font-size: 15.5px; line-height: 1.95; color: #334155; }
    .mv-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .mv-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
    @media (max-width: 600px) { .mv-head { flex-direction: column; text-align: center; } }
</style>
</head>
<body>
    <?php $current_page = 'messages'; require __DIR__ . '/site_header.php'; ?>
    <div class="mv-banner"><?php echo $msg ? htmlspecialchars($msg['designation']) : t('message_page_label'); ?></div>

    <div class="mv-wrap">
        <?php if (!$msg): ?>
            <div class="mv-card"><div class="mv-empty"><?php echo t('message_not_found'); ?></div></div>
        <?php else: ?>
            <div class="mv-card">
                <div class="mv-head">
                    <?php if (!empty($msg['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($msg['photo_path']); ?>" alt="<?php echo htmlspecialchars($msg['name']); ?>">
                    <?php else: ?>
                        <div class="mv-photo-empty"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="mv-name"><?php echo htmlspecialchars($msg['name']); ?></div>
                        <div class="mv-desg"><?php echo htmlspecialchars($msg['designation']); ?></div>
                    </div>
                </div>
                <div class="mv-body"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
            </div>
        <?php endif; ?>
        <a href="messages.php" class="mv-back">&larr; <?php echo t('back_to_all_messages'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo mv_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="messages.php"><?php echo t('all_messages'); ?></a></div>
    </footer>
</body>
</html>
