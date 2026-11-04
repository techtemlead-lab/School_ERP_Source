<?php
// admin/admission_mark_entry.php - Admission Module > Admission Test/Lottery > Mark Entry
// Session+Class সিলেক্ট করে শুধু Test Marks বসানো হয় (Admission Type = Admission Test হলেই কাজ করবে)।

function adme_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admission_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        method ENUM('None','Test','Lottery') NOT NULL DEFAULT 'None',
        total_seats INT NULL,
        full_marks INT NULL,
        pass_marks INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_session_class (session_id, class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

try {
    $admTypeColumn = $pdo->query("SHOW COLUMNS FROM institute_settings LIKE 'admission_type'")->fetch();
    if (!$admTypeColumn) {
        $pdo->exec("ALTER TABLE institute_settings ADD COLUMN admission_type VARCHAR(20) NOT NULL DEFAULT 'General'");
    }
} catch (Throwable $e) {
}
$am_msg = $_SESSION['am_msg'] ?? '';
$am_err = $_SESSION['am_err'] ?? '';
unset($_SESSION['am_msg'], $_SESSION['am_err']);

$global_admission_type = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch()['admission_type'] ?? 'General';
$method = ['General' => 'None', 'Admission Test' => 'Test', 'Lottery' => 'Lottery'][$global_admission_type] ?? 'None';

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['am_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $p_session = (int) ($_POST['session_id'] ?? 0);
        $p_class   = (int) ($_POST['class_id'] ?? 0);
        $marks = $_POST['marks'] ?? [];
        $upd = $pdo->prepare("UPDATE students SET test_marks = ? WHERE id = ? AND session_id = ? AND class_id = ?");
        $done = 0;
        foreach ($marks as $sid => $mark) {
            $sid = (int) $sid;
            $mark = trim((string) $mark);
            $upd->execute([$mark === '' ? null : (float) $mark, $sid, $p_session, $p_class]);
            $done++;
        }
        if ($done > 0) {
            sec_log_activity($pdo, 'Admission', 'Update', "{$done} জন শিক্ষার্থীর Admission Test Marks সেভ করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        }
        $_SESSION['am_msg'] = "{$done} জন শিক্ষার্থীর মার্ক সেভ করা হয়েছে।";
        header('Location: index.php?page=admission_mark_entry&f_session=' . $p_session . '&f_class=' . $p_class);
        exit();
    }
}

$setting = null;
$pending_students = [];
if ($f_session && $f_class) {
    $s = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ? AND class_id = ?");
    $s->execute([$f_session, $f_class]);
    $setting = $s->fetch();

    $stmt = $pdo->prepare(
        "SELECT id, student_id, name_en, roll_no, test_marks, admission_result
         FROM students WHERE session_id = ? AND class_id = ? AND status = 'Pending'
         ORDER BY (test_marks IS NULL) ASC, test_marks DESC, id ASC"
    );
    $stmt->execute([$f_session, $f_class]);
    $pending_students = $stmt->fetchAll();
}
$csrf = htmlspecialchars(csrf_token());
?>
<div class="card-custom mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_settings" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-sliders"></i> Settings</a>
        <a href="index.php?page=admission_applicants" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-list-check"></i> Applied Student List</a>
        <a href="index.php?page=admission_mark_entry" class="btn btn-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Mark Entry</a>
        <a href="index.php?page=admission_publish" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-bullhorn"></i> Result / Publish</a>
        <a href="index.php?page=admission_seat_plan" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chair"></i> Seat Plan</a>
    </div>
</div>

<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Admission — Mark Entry (Class Wise)</h6>
    <?php if ($am_msg): ?><div class="alert alert-success py-2"><?php echo adme_v($am_msg); ?></div><?php endif; ?>
    <?php if ($am_err): ?><div class="alert alert-danger py-2"><?php echo adme_v($am_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" class="row g-3">
        <input type="hidden" name="page" value="admission_mark_entry">
        <div class="col-sm-4">
            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
            <select name="f_session" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adme_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="f_class" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo adme_v($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($f_session && $f_class): ?>
    <?php if ($method !== 'Test'): ?>
        <div class="alert alert-info">বর্তমান Admission Type <strong>"<?php echo adme_v($method === 'Lottery' ? 'Lottery' : 'General'); ?>"</strong> — Mark Entry শুধু <strong>Admission Test</strong> টাইপের জন্য প্রযোজ্য। টাইপ বদলাতে <a href="index.php?page=institute">Global Settings &raquo; Admission Settings</a> এ যান।</div>
    <?php else: ?>
    <div class="card-custom">
        <h6 class="fw-bold text-secondary mb-3">Test Marks Entry (Full Marks: <?php echo adme_v($setting['full_marks'] ?? '—'); ?>)</h6>
        <form method="POST" action="index.php?page=admission_mark_entry">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
            <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light"><tr><th>ID</th><th>Name</th><th>App. No</th><th style="width:160px;">Marks</th><th>Result</th></tr></thead>
                    <tbody>
                        <?php if ($pending_students): foreach ($pending_students as $st): ?>
                            <tr>
                                <td><?php echo adme_v($st['student_id']); ?></td>
                                <td><?php echo adme_v($st['name_en']); ?></td>
                                <td><?php echo adme_v($st['roll_no']); ?></td>
                                <td><input type="number" step="0.01" min="0" name="marks[<?php echo (int) $st['id']; ?>]" class="form-control form-control-sm" value="<?php echo adme_v($st['test_marks']); ?>"></td>
                                <td><?php echo adme_v($st['admission_result'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">এই Session+Class এ কোনো Pending স্টুডেন্ট নেই।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pending_students): ?>
                <button type="submit" name="save_marks" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk"></i> Save Marks</button>
                <a href="index.php?page=admission_publish&f_session=<?php echo $f_session; ?>&f_class=<?php echo $f_class; ?>" class="btn btn-outline-success px-4">পরবর্তী ধাপ: Result Publish &raquo;</a>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>
<?php else: ?>
    <p class="text-muted">Mark Entry শুরু করতে প্রথমে Session ও Class সিলেক্ট করুন।</p>
<?php endif; ?>
