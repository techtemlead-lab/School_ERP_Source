<?php
// notices.php - পাবলিক পেজ: মেনুবার "নোটিশ" এবং হোমপেজ / govt থিমের "All / সকল" বাটন
// এখানে ক্লিক করলে আসে — সব Active নোটিশের সম্পূর্ণ তালিকা (সার্চ + Branch ফিল্টার + পেজিনেশনসহ)
require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/lang.php';

// notices টেবিল / নতুন কলাম self-heal — admin panel এ Website Module এখনো
// ওপেন করা না থাকলেও এই পাবলিক পেজ যেন ৫০০ এরর না দেয়
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        notice_date DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$nl_cols = $pdo->query("SHOW COLUMNS FROM notices")->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'branch_id'       => "ALTER TABLE notices ADD COLUMN branch_id INT NULL AFTER notice_date",
    'body'            => "ALTER TABLE notices ADD COLUMN body LONGTEXT NULL",
    'attachment_path' => "ALTER TABLE notices ADD COLUMN attachment_path VARCHAR(255) NULL",
    'attachment_name' => "ALTER TABLE notices ADD COLUMN attachment_name VARCHAR(255) NULL",
] as $col => $ddl) {
    if (!in_array($col, $nl_cols, true)) $pdo->exec($ddl);
}

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC")->fetchAll();

$f_title    = trim($_GET['q'] ?? '');
$f_branch   = (int) ($_GET['branch_id'] ?? 0);
$per_page   = 10;
$page_no    = max(1, (int) ($_GET['p'] ?? 1));

$where = ['notices.is_active = 1'];
$params = [];
if ($f_title !== '') { $where[] = 'notices.title LIKE ?'; $params[] = "%$f_title%"; }
if ($f_branch > 0) { $where[] = 'notices.branch_id = ?'; $params[] = $f_branch; }
$where_sql = implode(' AND ', $where);

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM notices WHERE $where_sql");
$count_stmt->execute($params);
$total = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total / $per_page));
$page_no = min($page_no, $total_pages);
$offset = ($page_no - 1) * $per_page;

$sql = "SELECT notices.*, branches.branch_name, branches.short_name AS branch_short_name
        FROM notices LEFT JOIN branches ON branches.id = notices.branch_id
        WHERE $where_sql ORDER BY notices.notice_date DESC, notices.id DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notices = $stmt->fetchAll();

function nl_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
function nl_qs(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) { if ($v === '' || $v === null) unset($params[$k]); }
    return htmlspecialchars('?' . http_build_query($params));
}
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo t('all_notices'); ?> - <?php echo nl_val($inst['institute_name'], 'Your School Name'); ?></title>
<?php if (!empty($inst['favicon_path'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
<?php endif; ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<style>
    :root { --accent: #10b981; --navy: #1e3a8a; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: #1f2937; }
    .nl-banner { background: #5b6478; color: #fff; text-align: center; padding: 28px 10px; font-size: 24px; font-weight: 700; letter-spacing: 1px; }
    .nl-wrap { max-width: 900px; margin: -20px auto 50px; padding: 0 15px; }
    .nl-card { background: #fff; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.08); padding: 24px; }
    .nl-filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
    .nl-filters input, .nl-filters select { flex: 1 1 180px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
    .nl-filters button { background: var(--navy); color: #fff; border: none; padding: 9px 22px; border-radius: 6px; font-weight: 600; cursor: pointer; }
    .nl-item { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 16px 0; border-bottom: 1px solid #eef2f7; }
    .nl-item:last-child { border-bottom: none; }
    .nl-item-title { font-size: 15.5px; font-weight: 600; color: #1e293b; text-decoration: none; }
    .nl-item-title:hover { color: var(--accent); }
    .nl-badge { font-size: 11px; padding: 2px 9px; border-radius: 9px; background: #eef2ff; color: #3730a3; margin-left: 6px; }
    .nl-clip { color: #94a3b8; margin-left: 4px; }
    .nl-date { font-size: 13px; color: #64748b; white-space: nowrap; }
    .nl-empty { text-align: center; padding: 50px 0; color: #64748b; }
    .nl-pager { display: flex; gap: 8px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }
    .nl-pager a, .nl-pager span { padding: 7px 13px; border-radius: 6px; border: 1px solid #e2e6ee; text-decoration: none; color: #1e293b; font-size: 14px; }
    .nl-pager .active { background: var(--navy); color: #fff; border-color: var(--navy); }
    .nl-back { display: inline-block; margin-top: 18px; color: var(--navy); text-decoration: none; font-weight: 600; font-size: 14px; }
    .site-footer { background: #0a2540; color: #cbd5e1; text-align: center; padding: 22px 15px; font-size: 13px; margin-top: 40px; }
    .site-footer a { color: #a5f3fc; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>
    <?php $current_page = 'notices'; require __DIR__ . '/site_header.php'; ?>
    <div class="nl-banner"><?php echo t('all_notices'); ?></div>

    <div class="nl-wrap">
        <div class="nl-card">
            <form method="GET" action="notices.php" class="nl-filters">
                <input type="text" name="q" placeholder="<?php echo t('search_notice_ph'); ?>" value="<?php echo nl_val($f_title); ?>">
                <?php if ($branches): ?>
                <select name="branch_id">
                    <option value="0"><?php echo t('all_branch'); ?></option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>" <?php echo $f_branch === (int) $b['id'] ? 'selected' : ''; ?>><?php echo nl_val($b['branch_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> <?php echo t('search_btn'); ?></button>
            </form>

            <?php if (!$notices): ?>
                <div class="nl-empty"><?php echo t('no_notice_found'); ?></div>
            <?php else: foreach ($notices as $n): ?>
                <div class="nl-item">
                    <div>
                        <a class="nl-item-title" href="notice_view.php?id=<?php echo (int) $n['id']; ?>"><?php echo nl_val($n['title']); ?></a>
                        <?php if (!empty($n['branch_short_name'])): ?><span class="nl-badge"><?php echo nl_val($n['branch_short_name']); ?></span><?php endif; ?>
                        <?php if (!empty($n['attachment_path'])): ?><i class="fa-solid fa-paperclip nl-clip" title="<?php echo t('has_attachment'); ?>"></i><?php endif; ?>
                    </div>
                    <div class="nl-date"><?php echo date('d M Y', strtotime($n['notice_date'])); ?></div>
                </div>
            <?php endforeach; endif; ?>

            <?php if ($total_pages > 1): ?>
            <div class="nl-pager">
                <?php if ($page_no > 1): ?><a href="<?php echo nl_qs(['p' => $page_no - 1]); ?>"><?php echo t('prev_page'); ?></a><?php endif; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i === $page_no): ?><span class="active"><?php echo $i; ?></span>
                    <?php else: ?><a href="<?php echo nl_qs(['p' => $i]); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page_no < $total_pages): ?><a href="<?php echo nl_qs(['p' => $page_no + 1]); ?>"><?php echo t('next_page'); ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <a href="index.php" class="nl-back">&larr; <?php echo t('back_to_home'); ?></a>
    </div>

    <footer class="site-footer">
        <div>&copy; <?php echo date('Y'); ?> <?php echo nl_val($inst['institute_name'], 'Your School Name'); ?> — <?php echo t('all_rights_reserved'); ?></div>
        <div style="margin-top:6px;"><a href="index.php"><?php echo t('home_label'); ?></a>|<a href="notices.php"><?php echo t('all_notices'); ?></a></div>
    </footer>
</body>
</html>
