<?php
// notice_view.php - পাবলিক পেজ: একটা নির্দিষ্ট নোটিশের বিস্তারিত (বিস্তারিত লেখা + সংযুক্তি)
// academic_result.php / admission_form.php এর মতোই site_header.php (লোগো, মেনুবার) সহ রেন্ডার হয়,
// যাতে সাইটের বাকি সব পেজের মতোই দেখতে লাগে — আগে এই পেজের নিজস্ব আলাদা মিনি-হেডার ছিল, মেনু ছিল না
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';

// notices টেবিলের body/attachment কলাম self-heal — admin panel এ এখনো কেউ
// Website Module ওপেন না করে থাকলেও এই পাবলিক পেজ যেন ক্র্যাশ না করে
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        notice_date DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$nv_cols = $pdo->query("SHOW COLUMNS FROM notices")->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'branch_id'       => "ALTER TABLE notices ADD COLUMN branch_id INT NULL AFTER notice_date",
    'body'            => "ALTER TABLE notices ADD COLUMN body LONGTEXT NULL",
    'attachment_path' => "ALTER TABLE notices ADD COLUMN attachment_path VARCHAR(255) NULL",
    'attachment_name' => "ALTER TABLE notices ADD COLUMN attachment_name VARCHAR(255) NULL",
] as $col => $ddl) {
    if (!in_array($col, $nv_cols, true)) $pdo->exec($ddl);
}

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT notices.*, branches.short_name AS branch_short_name FROM notices LEFT JOIN branches ON branches.id = notices.branch_id WHERE notices.id = ? AND notices.is_active = 1");
$stmt->execute([$id]);
$notice = $stmt->fetch();

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
function nv_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
function nv_ext(?string $path): string { return strtoupper(pathinfo((string) $path, PATHINFO_EXTENSION)); }
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $notice ? htmlspecialchars($notice['title']) . ' - ' : ''; ?><?php echo nv_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .nv-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 22px; font-weight: 700; letter-spacing: 1px; }
    .nv-wrap { max-width: 800px; margin: -20px auto 50px; padding: 0 15px; }
    .nv-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 30px; }
    .nv-title { font-size: 21px; color: #0f172a; margin-bottom: 12px; line-height: 1.5; font-weight: 700; }
    .nv-meta { font-size: 13px; color: #64748b; margin-bottom: 20px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
    .nv-badge { font-size: 11px; padding: 2px 10px; border-radius: 10px; background: #eef2ff; color: #3730a3; }
    .nv-body { font-size: 15px; line-height: 1.9; color: #334155; border-top: 1px solid #eef2f7; padding-top: 20px; }
    .nv-body p { margin-bottom: 10px; }
    .nv-attachment { display: inline-flex; align-items: center; gap: 10px; margin-top: 24px; padding: 14px 18px; background: #f1f5f9; border-radius: 8px; text-decoration: none; color: var(--navy); font-size: 14px; font-weight: 600; }
    .nv-attachment:hover { background: #e2e8f0; }
    .nv-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .nv-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>
    <?php $current_page = 'notices'; require __DIR__ . '/site_header.php'; ?>
    <div class="nv-banner"><?php echo t('notice_page_label'); ?></div>

    <div class="nv-wrap">
        <?php if (!$notice): ?>
            <div class="nv-empty"><?php echo t('notice_not_found'); ?></div>
        <?php else: ?>
            <div class="nv-card">
                <div class="nv-title"><?php echo htmlspecialchars($notice['title']); ?></div>
                <div class="nv-meta">
                    <span><i class="fa-regular fa-calendar"></i> <?php echo date('d F Y', strtotime($notice['notice_date'])); ?></span>
                    <?php if (!empty($notice['branch_short_name'])): ?>
                        <span class="nv-badge"><?php echo htmlspecialchars($notice['branch_short_name']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($notice['body'])): ?>
                    <div class="nv-body"><?php echo $notice['body']; // admin/website.php তে notice_sanitize_richtext() দিয়ে সেভের আগেই পরিষ্কার করা হয়েছে ?></div>
                <?php endif; ?>
                <?php if (!empty($notice['attachment_path'])): ?>
                    <a class="nv-attachment" href="<?php echo htmlspecialchars($notice['attachment_path']); ?>" target="_blank" rel="noopener">
                        📎 <?php echo htmlspecialchars($notice['attachment_name'] ?: t('attached_file_default')); ?> <?php echo t('download_file'); ?> (<?php echo nv_ext($notice['attachment_path']); ?>)
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <a href="notices.php" class="nv-back">&larr; <?php echo t('back_to_all_notices'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo nv_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="notices.php"><?php echo t('all_notices'); ?></a></div>
    </footer>
</body>
</html>
