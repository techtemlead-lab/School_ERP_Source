<?php
// student/home_work.php - My Home Work (স্টুডেন্টের নিজের Class/Section এর হোমওয়ার্ক + সাবমিশন)
// $student, $pdo — index.php থেকে available (bootstrap-এ লোড হয়ে গেছে)

if (!function_exists('shw_e')) {
    function shw_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// admin/homework.php-এ টেবিল তৈরি হয়ে যায়, কিন্তু Admin panel না খুলেই কোনো ছাত্র সরাসরি
// এই পোর্টালে ঢুকলে টেবিল যেন তখনও self-healing ভাবে থাকে
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        deadline DATETIME NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_homework_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        homework_id INT NOT NULL,
        student_id INT NOT NULL,
        submission_text TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        marks DECIMAL(5,2) NULL,
        feedback TEXT NULL,
        status ENUM('Submitted','Checked') NOT NULL DEFAULT 'Submitted',
        UNIQUE KEY uniq_submission (homework_id, student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$shw_err = $_SESSION['shw_err'] ?? '';
$shw_msg = $_SESSION['shw_msg'] ?? '';
unset($_SESSION['shw_err'], $_SESSION['shw_msg']);

// -----------------------------------------------------------------
// সাবমিশন আপলোড/সেভ হ্যান্ডলার
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_homework'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['shw_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $hw_id = (int) ($_POST['hw_id'] ?? 0);
        $text  = trim($_POST['submission_text'] ?? '');
        $path  = null;

        $file = $_FILES['attachment'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > 10 * 1024 * 1024) {
                $_SESSION['shw_err'] = 'ফাইলের সাইজ 10MB এর বেশি হওয়া যাবে না।';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];
                if (!in_array($ext, $allowed, true)) {
                    $_SESSION['shw_err'] = 'এই ধরনের ফাইল সাপোর্টেড না। (pdf/doc/ppt/xls/jpg/png/zip)';
                } else {
                    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'homework_submissions');
                    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
                        $path = tenant_upload_rel('homework_submissions') . '/' . $random_name;
                    } else {
                        $_SESSION['shw_err'] = 'ফাইল সেভ করা যায়নি।';
                    }
                }
            }
        }

        if (empty($_SESSION['shw_err']) && $hw_id && ($text !== '' || $path)) {
            // এই স্টুডেন্ট এই হোমওয়ার্কের জন্য যোগ্য কিনা (তার Class/Section এর) যাচাই
            $chk = $pdo->prepare(
                "SELECT id FROM elearning_homework WHERE id = ? AND class_id = ?
                 AND (section_id IS NULL OR section_id = ?)"
            );
            $chk->execute([$hw_id, $student['class_id'], $student['section_id']]);
            if ($chk->fetch()) {
                $pdo->prepare(
                    "INSERT INTO elearning_homework_submissions (homework_id, student_id, submission_text, attachment_path, status)
                     VALUES (?, ?, ?, ?, 'Submitted')
                     ON DUPLICATE KEY UPDATE submission_text = VALUES(submission_text),
                        attachment_path = COALESCE(VALUES(attachment_path), attachment_path),
                        submitted_at = CURRENT_TIMESTAMP, status = 'Submitted'"
                )->execute([$hw_id, $student['id'], $text ?: null, $path]);
                $_SESSION['shw_msg'] = 'হোমওয়ার্ক জমা দেওয়া হয়েছে।';
            } else {
                $_SESSION['shw_err'] = 'এই হোমওয়ার্কটি আপনার জন্য প্রযোজ্য না।';
            }
        } elseif (empty($_SESSION['shw_err'])) {
            $_SESSION['shw_err'] = 'কিছু একটা লিখুন অথবা ফাইল দিন।';
        }
    }
    header('Location: index.php?page=home_work');
    exit();
}

// -----------------------------------------------------------------
// লিস্ট — নিজের Class/Section এর সব Active হোমওয়ার্ক + নিজের সাবমিশন (থাকলে)
// -----------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT h.*, sub.subject_name,
            s.id AS submission_id, s.submission_text, s.attachment_path AS my_attachment,
            s.marks, s.feedback, s.status AS submission_status
     FROM elearning_homework h
     LEFT JOIN academic_subjects sub ON sub.id = h.subject_id
     LEFT JOIN elearning_homework_submissions s ON s.homework_id = h.id AND s.student_id = ?
     WHERE h.status = 'Active' AND h.class_id = ?
       AND (h.section_id IS NULL OR h.section_id = ?)
     ORDER BY h.deadline IS NULL, h.deadline ASC, h.created_at DESC"
);
$stmt->execute([$student['id'], $student['class_id'], $student['section_id']]);
$shw_list = $stmt->fetchAll();
?>

<?php if ($shw_msg): ?><div class="alert alert-success py-2"><?php echo shw_e($shw_msg); ?></div><?php endif; ?>
<?php if ($shw_err): ?><div class="alert alert-danger py-2"><?php echo shw_e($shw_err); ?></div><?php endif; ?>

<?php if (!$shw_list): ?>
    <div class="card-custom text-center py-5">
        <i class="fa-solid fa-laptop mb-3" style="font-size: 48px; color: #14b8a6;"></i>
        <h5 class="fw-bold text-dark mb-2">এই মুহূর্তে কোনো হোমওয়ার্ক নেই</h5>
        <p class="text-muted mb-0">নতুন হোমওয়ার্ক দিলে এখানে দেখা যাবে।</p>
    </div>
<?php endif; ?>

<?php foreach ($shw_list as $h): ?>
    <div class="card-custom mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-1"><?php echo shw_e($h['title']); ?></h6>
                <div class="small text-muted">
                    Subject: <?php echo shw_e($h['subject_name'] ?? '—'); ?>
                    <?php if ($h['deadline']): ?> | Deadline: <?php echo shw_e(date('d M Y, h:i A', strtotime($h['deadline']))); ?><?php endif; ?>
                </div>
            </div>
            <div>
                <?php if (!$h['submission_id']): ?>
                    <span class="badge bg-secondary">এখনো জমা দেননি</span>
                <?php elseif ($h['submission_status'] === 'Checked'): ?>
                    <span class="badge bg-success">চেক করা হয়েছে <?php echo $h['marks'] !== null ? '(' . shw_e($h['marks']) . ' নম্বর)' : ''; ?></span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">জমা দেওয়া হয়েছে</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($h['description']): ?><p class="mt-2 mb-1 small"><?php echo nl2br(shw_e($h['description'])); ?></p><?php endif; ?>
        <?php if ($h['attachment_path']): ?>
            <a href="../<?php echo shw_e($h['attachment_path']); ?>" target="_blank" class="small"><i class="fa-solid fa-paperclip me-1"></i>টিচারের ফাইল দেখুন</a>
        <?php endif; ?>

        <?php if ($h['submission_status'] === 'Checked' && $h['feedback']): ?>
            <div class="alert alert-light border mt-2 mb-0 py-2 small"><b>ফিডব্যাক:</b> <?php echo shw_e($h['feedback']); ?></div>
        <?php endif; ?>

        <hr class="my-2">
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hw_id" value="<?php echo (int) $h['id']; ?>">
            <div class="row g-2">
                <div class="col-md-8">
                    <textarea name="submission_text" rows="2" class="form-control form-control-sm" placeholder="উত্তর লিখুন (ঐচ্ছিক)"><?php echo shw_e($h['submission_text'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-3">
                    <input type="file" name="attachment" class="form-control form-control-sm">
                    <?php if ($h['my_attachment']): ?>
                        <div class="small text-muted mt-1">আগের ফাইল: <a href="../<?php echo shw_e($h['my_attachment']); ?>" target="_blank">দেখুন</a></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-1">
                    <button type="submit" name="submit_homework" class="btn btn-sm btn-primary w-100">
                        <?php echo $h['submission_id'] ? 'আপডেট' : 'জমা দিন'; ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
<?php endforeach; ?>
