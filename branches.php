<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/map_embed.php';

// সব Active branch — প্রতিটার contact info + নিজের map_iframe (admin > Branch Settings থেকে সেট করা)
$branches = [];
try {
    $branches = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    $branches = [];
}

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'Your School Name', 'favicon_path' => '', 'logo_path' => ''];
}
function bc_val($v, $fallback = '') { return !empty($v) ? htmlspecialchars($v) : $fallback; }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>সব শাখার ঠিকানা ও যোগাযোগ - <?php echo bc_val($inst['institute_name'], 'Your School Name'); ?></title>
    <?php if (!empty($inst['favicon_path'])): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($inst['favicon_path']); ?>">
    <?php endif; ?>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background:#f8fafc; color:#1e293b; }
        .bc-header { background:#0a2540; color:#fff; padding:28px 5%; }
        .bc-header a { color:#a5f3fc; text-decoration:none; font-size:14px; }
        .bc-header h1 { font-size:24px; margin-top:8px; }
        .bc-wrap { max-width:1100px; margin:0 auto; padding:30px 5% 60px; }
        .bc-card { background:#fff; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,0.06); margin-bottom:26px; overflow:hidden; display:flex; flex-wrap:wrap; }
        .bc-info { flex:1 1 300px; padding:26px; }
        .bc-info h2 { font-size:19px; color:#0a2540; margin-bottom:4px; }
        .bc-info .bc-branch-name { font-size:15px; font-weight:600; color:#10b981; margin-bottom:14px; }
        .bc-info p { font-size:14px; line-height:1.9; color:#334155; }
        .bc-map { flex:1 1 320px; min-height:260px; }
        .bc-map iframe { width:100%; height:100%; min-height:260px; border:0; display:block; }
        .bc-map .bc-map-empty { width:100%; height:100%; min-height:260px; display:flex; align-items:center; justify-content:center; background:#eef2f7; color:#94a3b8; font-size:13px; }
        .bc-empty { text-align:center; padding:60px 0; color:#64748b; }
        @media (max-width:640px) { .bc-card { flex-direction:column; } }
    </style>
</head>
<body>
    <div class="bc-header">
        <a href="index.php">&larr; হোমে ফিরে যান</a>
        <h1>সব শাখার ঠিকানা ও যোগাযোগ</h1>
    </div>

    <div class="bc-wrap">
        <?php if (!$branches): ?>
            <div class="bc-empty">কোনো Branch পাওয়া যায়নি।</div>
        <?php else: foreach ($branches as $bi => $b): ?>
            <div class="bc-card">
                <div class="bc-info">
                    <div class="bc-branch-name">Branch <?php echo $bi + 1; ?><?php echo $b['short_name'] ? ' — ' . htmlspecialchars($b['short_name']) : ''; ?></div>
                    <h2><?php echo htmlspecialchars($b['branch_name']); ?></h2>
                    <p>
                        <?php echo nl2br(htmlspecialchars($b['address'])); ?><?php echo $b['city'] ? ', ' . htmlspecialchars($b['city']) : ''; ?><?php echo $b['zip_code'] ? ' - ' . htmlspecialchars($b['zip_code']) : ''; ?><br>
                        <?php if ($b['phone']): ?>হেল্পলাইন: <?php echo htmlspecialchars($b['phone']); ?><br><?php endif; ?>
                        <?php if (!empty($b['fax'])): ?>ফ্যাক্স: <?php echo htmlspecialchars($b['fax']); ?><br><?php endif; ?>
                        <?php if ($b['email']): ?>ইমেইল: <?php echo htmlspecialchars($b['email']); ?><br><?php endif; ?>
                        সাপ্তাহিক ছুটি: <?php echo htmlspecialchars($b['weekly_holidays']); ?>
                    </p>
                </div>
                <div class="bc-map">
                    <?php $b_map = !empty($b['map_iframe']) ? map_embed_html($b['map_iframe'], $b['branch_name']) : ''; ?>
                    <?php if ($b_map !== ''): ?>
                        <?php echo $b_map; // শুধু যাচাই করা https src থেকে বানানো iframe ?>
                    <?php else: ?>
                        <div class="bc-map-empty">এই শাখার জন্য এখনো ম্যাপ যোগ করা হয়নি</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</body>
</html>
