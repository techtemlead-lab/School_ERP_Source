<?php
// student/recorded_class.php - My Recorded Class
// $student, $pdo — index.php থেকে available

if (!function_exists('src_e')) {
    function src_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('src_to_embed_url')) {
    function src_to_embed_url(string $url, string $type): string
    {
        $url = trim($url);
        if ($type === 'youtube') {
            if (preg_match('~youtu\.be/([A-Za-z0-9_-]+)~', $url, $m)
                || preg_match('~[?&]v=([A-Za-z0-9_-]+)~', $url, $m)
                || preg_match('~youtube\.com/embed/([A-Za-z0-9_-]+)~', $url, $m)) {
                return 'https://www.youtube.com/embed/' . $m[1];
            }
            return $url;
        }
        if ($type === 'vimeo') {
            if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
                return 'https://player.vimeo.com/video/' . $m[1];
            }
            return $url;
        }
        return $url;
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_recorded_classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        video_type ENUM('youtube','vimeo') NOT NULL DEFAULT 'youtube',
        video_url VARCHAR(500) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$src_watch_id = (int) ($_GET['watch'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT r.*, sub.subject_name
     FROM elearning_recorded_classes r
     LEFT JOIN academic_subjects sub ON sub.id = r.subject_id
     WHERE r.status = 'Active' AND r.class_id = ?
       AND (r.section_id IS NULL OR r.section_id = ?)
     ORDER BY r.created_at DESC"
);
$stmt->execute([$student['class_id'], $student['section_id']]);
$src_list = $stmt->fetchAll();

$src_watch = null;
if ($src_watch_id) {
    foreach ($src_list as $r) {
        if ((int) $r['id'] === $src_watch_id) { $src_watch = $r; break; }
    }
}
?>

<?php if ($src_watch): ?>
    <div class="card-custom mb-3" style="padding:0; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <strong><?php echo src_e($src_watch['title']); ?></strong>
            <a href="index.php?page=recorded_class" class="btn btn-sm btn-secondary">লিস্টে ফিরুন</a>
        </div>
        <div class="ratio ratio-16x9">
            <iframe src="<?php echo src_e(src_to_embed_url($src_watch['video_url'], $src_watch['video_type'])); ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
        </div>
        <?php if ($src_watch['description']): ?>
            <div class="p-3 small"><?php echo nl2br(src_e($src_watch['description'])); ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$src_list): ?>
    <div class="card-custom text-center py-5">
        <i class="fa-solid fa-film mb-3" style="font-size: 48px; color: #14b8a6;"></i>
        <h5 class="fw-bold text-dark mb-2">এই মুহূর্তে কোনো রেকর্ডেড ক্লাস নেই</h5>
        <p class="text-muted mb-0">নতুন ক্লাস যোগ হলে এখানে দেখা যাবে।</p>
    </div>
<?php endif; ?>

<div class="row">
    <?php foreach ($src_list as $r): ?>
        <div class="col-md-4 mb-3">
            <div class="card-custom h-100 d-flex flex-column">
                <h6 class="fw-bold mb-1"><?php echo src_e($r['title']); ?></h6>
                <div class="small text-muted mb-2">Subject: <?php echo src_e($r['subject_name'] ?? '—'); ?></div>
                <a href="index.php?page=recorded_class&watch=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-primary mt-auto">
                    <i class="fa-solid fa-play me-1"></i> দেখুন
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
