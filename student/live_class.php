<?php
// student/live_class.php - My Live Class
// $student, $pdo — index.php থেকে available
require_once __DIR__ . '/../config/live_class_100ms.php';

if (!function_exists('slc_e')) {
    function slc_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_live_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        scheduled_at DATETIME NOT NULL,
        duration_minutes INT NOT NULL DEFAULT 40,
        room_id VARCHAR(255) NULL,
        room_error VARCHAR(500) NULL,
        status ENUM('Scheduled','Live','Ended','Cancelled') NOT NULL DEFAULT 'Scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$slc_action = $_GET['action'] ?? 'list';
$slc_id = (int) ($_GET['id'] ?? 0);

// -----------------------------------------------------------------
// action=join — Guest হিসেবে জয়েন
// -----------------------------------------------------------------
if ($slc_action === 'join' && $slc_id) {
    $stmt = $pdo->prepare(
        "SELECT * FROM elearning_live_classes WHERE id = ? AND class_id = ?
         AND (section_id IS NULL OR section_id = ?)"
    );
    $stmt->execute([$slc_id, $student['class_id'], $student['section_id']]);
    $lc_class = $stmt->fetch();

    if (!$lc_class) {
        echo '<div class="alert alert-danger">ক্লাসটি পাওয়া যায়নি অথবা এটা আপনার জন্য প্রযোজ্য না।</div>';
        return;
    }
    if ($lc_class['status'] !== 'Live') {
        echo '<div class="alert alert-warning">এই ক্লাসটি এখনো লাইভ শুরু হয়নি। শিক্ষক শুরু করলে "Join" বাটন সক্রিয় হবে।</div>';
        return;
    }
    if (empty($lc_class['room_id'])) {
        echo '<div class="alert alert-danger">এই ক্লাসের রুম তৈরি হয়নি — শিক্ষককে জানান।</div>';
        return;
    }

    $lc_token_result = lc_generate_token($pdo, $lc_class, $student['name_en'] ?: 'Student', 'guest');
    $lc_is_host = false;
    $lc_status_poll_url = 'live_class_status.php?id=' . $slc_id;
    ?>
    <div class="card-custom" style="padding:0; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <strong><?php echo slc_e($lc_class['title']); ?></strong>
            <a href="index.php?page=live_class" class="btn btn-sm btn-secondary">লিস্টে ফিরুন</a>
        </div>
        <div style="padding: 12px;">
            <?php if (!$lc_token_result['ok']): ?>
                <div class="alert alert-danger mb-0">জয়েন করা যায়নি: <?php echo slc_e($lc_token_result['error']); ?></div>
            <?php else: ?>
                <?php require __DIR__ . '/../admin/partials/live_class_embed.php'; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return;
}

// -----------------------------------------------------------------
// লিস্ট — নিজের Class/Section এর সব Scheduled/Live ক্লাস
// -----------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT l.*, sub.subject_name
     FROM elearning_live_classes l
     LEFT JOIN academic_subjects sub ON sub.id = l.subject_id
     WHERE l.class_id = ? AND (l.section_id IS NULL OR l.section_id = ?)
       AND l.status IN ('Scheduled','Live')
     ORDER BY l.scheduled_at ASC"
);
$stmt->execute([$student['class_id'], $student['section_id']]);
$slc_list = $stmt->fetchAll();
?>

<?php if (!$slc_list): ?>
    <div class="card-custom text-center py-5">
        <i class="fa-solid fa-video mb-3" style="font-size: 48px; color: #14b8a6;"></i>
        <h5 class="fw-bold text-dark mb-2">এই মুহূর্তে কোনো লাইভ ক্লাস নেই</h5>
        <p class="text-muted mb-0">নতুন ক্লাস শিডিউল হলে এখানে দেখা যাবে।</p>
    </div>
<?php endif; ?>

<?php foreach ($slc_list as $l): ?>
    <div class="card-custom mb-3 d-flex flex-row justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="fw-bold mb-1"><?php echo slc_e($l['title']); ?></h6>
            <div class="small text-muted">
                Subject: <?php echo slc_e($l['subject_name'] ?? '—'); ?> |
                <?php echo slc_e(date('d M Y, h:i A', strtotime($l['scheduled_at']))); ?>
                (<?php echo (int) $l['duration_minutes']; ?> মিনিট)
            </div>
        </div>
        <div>
            <?php if ($l['status'] === 'Live'): ?>
                <a href="index.php?page=live_class&action=join&id=<?php echo (int) $l['id']; ?>" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-video me-1"></i> এখন Join করুন
                </a>
            <?php else: ?>
                <span class="badge bg-secondary">শিডিউল হয়েছে</span>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
