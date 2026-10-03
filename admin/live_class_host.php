<?php
// admin/live_class_host.php - E-Learning Module > Live Class > Host
require_once __DIR__ . '/../config/live_class_100ms.php';

if (!function_exists('lch_e')) {
    function lch_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$is_teacher_ctx = !empty($_SESSION['teacher_logged']);
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

$lc_id = (int) ($_GET['id'] ?? 0);
$sql = "SELECT * FROM elearning_live_classes WHERE id = ?";
$params = [$lc_id];
if ($is_teacher_ctx) { $sql .= " AND teacher_id = ?"; $params[] = $me_teacher_id; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lc_class = $stmt->fetch();

if (!$lc_class) {
    echo '<div class="alert alert-danger">লাইভ ক্লাসটি পাওয়া যায়নি।</div>';
    return;
}
if (in_array($lc_class['status'], ['Ended', 'Cancelled'], true)) {
    echo '<div class="alert alert-warning">এই ক্লাসটি ইতিমধ্যে ' . ($lc_class['status'] === 'Ended' ? 'শেষ' : 'বাতিল') . ' হয়ে গেছে।</div>';
    return;
}

// রুম তৈরি না থাকলে (আগে ব্যর্থ হয়েছিল) এখন আবার চেষ্টা
if (empty($lc_class['room_id'])) {
    $room = lc_create_room($pdo, $lc_class);
    if ($room['ok']) {
        $pdo->prepare("UPDATE elearning_live_classes SET room_id=?, room_error=NULL WHERE id=?")->execute([$room['room_id'], $lc_id]);
        $lc_class['room_id'] = $room['room_id'];
    } else {
        $pdo->prepare("UPDATE elearning_live_classes SET room_error=? WHERE id=?")->execute([$room['error'], $lc_id]);
        echo '<div class="alert alert-danger">রুম তৈরি করা যায়নি: ' . lch_e($room['error']) . '</div>';
        return;
    }
}

// Host ঢুকলেই ক্লাসকে "Live" মার্ক করে দেওয়া হচ্ছে
if ($lc_class['status'] !== 'Live') {
    $pdo->prepare("UPDATE elearning_live_classes SET status='Live' WHERE id=?")->execute([$lc_id]);
    $lc_class['status'] = 'Live';
}

$host_name = $is_teacher_ctx
    ? trim((string) ($pdo->query("SELECT name FROM employees WHERE id = " . (int) $me_teacher_id)->fetchColumn() ?: 'Teacher'))
    : trim((string) ($_SESSION['admin_user'] ?? 'Admin'));

$lc_token_result = lc_generate_token($pdo, $lc_class, $host_name, 'host');
$lc_is_host = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rec_action'])) {
    if (csrf_verify($_POST['csrf_token'] ?? null)) {
        if ($_POST['rec_action'] === 'start') {
            $r = lc_start_recording($pdo, $lc_class['room_id']);
        } else {
            $r = lc_stop_recording($pdo, $lc_class['room_id']);
        }
        $_SESSION['lch_rec_msg'] = $r['ok'] ? 'রেকর্ডিং ' . ($_POST['rec_action'] === 'start' ? 'শুরু' : 'বন্ধ') . ' হয়েছে।' : $r['error'];
    }
    header('Location: index.php?page=live_class_host&id=' . $lc_id);
    exit();
}
$lch_rec_msg = $_SESSION['lch_rec_msg'] ?? '';
unset($_SESSION['lch_rec_msg']);
?>
<div class="card-custom" style="padding:0; overflow:hidden;">
    <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <strong><?php echo lch_e($lc_class['title']); ?></strong>
            <span class="small text-muted"> — হোস্ট হিসেবে জয়েন করছেন</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="rec_action" value="start">
                <button type="submit" class="btn btn-sm btn-outline-secondary">⏺ Start Recording</button>
            </form>
            <form method="POST" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="rec_action" value="stop">
                <button type="submit" class="btn btn-sm btn-outline-secondary">⏹ Stop Recording</button>
            </form>
            <form method="POST" action="index.php?page=live_class" onsubmit="return confirm('আপনি কি নিশ্চিত ক্লাসটি শেষ করতে চান?');" style="display:inline;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="lc_id" value="<?php echo (int) $lc_class['id']; ?>">
                <input type="hidden" name="status" value="Ended">
                <button type="submit" name="set_status" class="btn btn-sm btn-danger">ক্লাস শেষ করুন</button>
            </form>
            <a href="index.php?page=live_class" class="btn btn-sm btn-secondary">লিস্টে ফিরুন</a>
        </div>
    </div>

    <?php if ($lch_rec_msg): ?><div class="alert alert-info py-2 mb-0 rounded-0"><?php echo lch_e($lch_rec_msg); ?></div><?php endif; ?>

    <div style="padding: 12px;">
        <?php if (!$lc_token_result['ok']): ?>
            <div class="alert alert-danger mb-0">জয়েন টোকেন তৈরি করা যায়নি: <?php echo lch_e($lc_token_result['error']); ?></div>
        <?php else: ?>
            <?php require __DIR__ . '/partials/live_class_embed.php'; ?>
        <?php endif; ?>
    </div>
</div>
