<?php
// gallery.php - পাবলিক পেজ: হোমপেজের "ফটো গ্যালারি" সেকশনের "See All"
// এবং মেনুবারের "Gallery" লিংকে ক্লিক করলে আসে — সব Active গ্যালারি ছবির সম্পূর্ণ তালিকা।
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}

$site_gallery = $pdo->query("SELECT * FROM gallery_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

function gl_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('all_photo_gallery'); ?> - <?php echo gl_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }

    .gl-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .gl-wrap { max-width: 1180px; margin: -20px auto 50px; padding: 0 15px; }
    .gl-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 24px; }

    .gallery-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
    .gallery-item { position: relative; border-radius: 8px; overflow: hidden; height: 160px; box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
    .gallery-item img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s ease; }
    .gallery-item:hover img { transform: scale(1.08); }
    .gallery-caption { position: absolute; left: 0; right: 0; bottom: 0; background: linear-gradient(transparent, rgba(0,0,0,0.7)); color: #fff; font-size: 12px; padding: 18px 10px 8px; }

    .gl-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .gl-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }

    @media (max-width: 992px) { .gallery-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) { .gallery-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; } .gallery-item { height: 120px; } }
</style>
</head>
<body>
    <?php $current_page = 'gallery'; require __DIR__ . '/site_header.php'; ?>
    <div class="gl-banner"><?php echo t('all_photo_gallery'); ?></div>

    <div class="gl-wrap">
        <div class="gl-card">
            <div class="gallery-grid">
                <?php if ($site_gallery): foreach ($site_gallery as $g): ?>
                <div class="gallery-item">
                    <img src="<?php echo htmlspecialchars($g['image_path']); ?>" alt="<?php echo htmlspecialchars($g['caption']); ?>">
                    <div class="gallery-caption"><?php echo htmlspecialchars($g['caption']); ?></div>
                </div>
                <?php endforeach; else: ?>
                <div class="gl-empty" style="grid-column:1/-1;"><?php echo t('no_gallery_photo'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <a href="index.php" class="gl-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo gl_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="gallery.php"><?php echo t('all_photo_gallery'); ?></a></div>
    </footer>
</body>
</html>
