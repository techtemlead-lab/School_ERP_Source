<?php
// admin/admission_publish.php - Admission Module > Admission Test/Lottery > Result / Publish
// Session+Class সিলেক্ট করে Lottery Draw / মেধাক্রম অনুযায়ী Result Publish, Waitlist Promote,
// এবং Selected-দের বাল্ক Confirm (Active) করা হয়।

function adpub_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

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

$global_admission_type = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch()['admission_type'] ?? 'General';
$method = ['General' => 'None', 'Admission Test' => 'Test', 'Lottery' => 'Lottery'][$global_admission_type] ?? 'None';

$ap_msg = $_SESSION['ap_msg'] ?? '';
$ap_err = $_SESSION['ap_err'] ?? '';
unset($_SESSION['ap_msg'], $_SESSION['ap_err']);

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);

function adpub_redirect(int $session_id, int $class_id): void
{
    header('Location: index.php?page=admission_publish&f_session=' . $session_id . '&f_class=' . $class_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ap_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        adpub_redirect($f_session, $f_class);
    }

    $p_session = (int) ($_POST['session_id'] ?? 0);
    $p_class   = (int) ($_POST['class_id'] ?? 0);

    // ---- মেধাক্রম অনুযায়ী রেজাল্ট পাবলিশ (Test) ----
    if (isset($_POST['publish_test_result'])) {
        $setting = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ? AND class_id = ?");
        $setting->execute([$p_session, $p_class]);
        $setting = $setting->fetch();
        $seats = (int) ($setting['total_seats'] ?? 0);
        $pass_marks = $setting['pass_marks'] !== null ? (float) $setting['pass_marks'] : null;

        $stmt = $pdo->prepare(
            "SELECT id, test_marks FROM students WHERE session_id = ? AND class_id = ? AND status = 'Pending' AND test_marks IS NOT NULL ORDER BY test_marks DESC, id ASC"
        );
        $stmt->execute([$p_session, $p_class]);
        $ranked = $stmt->fetchAll();

        $upd = $pdo->prepare("UPDATE students SET admission_result = ?, result_published_at = NOW() WHERE id = ?");
        $roll_upd = $pdo->prepare("UPDATE students SET roll_no = ? WHERE id = ?");
        $rank = 0;
        $selected_rank = 0;
        foreach ($ranked as $r) {
            $rank++;
            if ($pass_marks !== null && (float) $r['test_marks'] < $pass_marks) {
                $upd->execute(['Rejected', $r['id']]);
            } elseif ($seats > 0 && $rank <= $seats) {
                $upd->execute(['Selected', $r['id']]);
                $selected_rank++;
                $roll_upd->execute([(string) $selected_rank, $r['id']]); // মেধাক্রম অনুযায়ী নতুন Roll No
            } else {
                $upd->execute(['Waitlisted', $r['id']]);
            }
        }
        sec_log_activity($pdo, 'Admission', 'Update', 'মেধাক্রম অনুযায়ী Admission Result Publish করা হয়েছে (' . count($ranked) . ' জন, Session: ' . $p_session . ', Class: ' . $p_class . ')');
        $_SESSION['ap_msg'] = 'মেধাক্রম অনুযায়ী রেজাল্ট পাবলিশ করা হয়েছে (' . count($ranked) . " জন) — Selected-দের মেধাক্রম অনুযায়ী নতুন Roll No বসানো হয়েছে।";
        adpub_redirect($p_session, $p_class);
    }

    // ---- Lottery Draw চালানো ----
    if (isset($_POST['run_lottery'])) {
        $setting = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ? AND class_id = ?");
        $setting->execute([$p_session, $p_class]);
        $setting = $setting->fetch();
        $seats = (int) ($setting['total_seats'] ?? 0);

        $stmt = $pdo->prepare("SELECT id FROM students WHERE session_id = ? AND class_id = ? AND status = 'Pending' AND admission_result IS NULL");
        $stmt->execute([$p_session, $p_class]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        shuffle($ids);

        $selected_ids = $seats > 0 ? array_slice($ids, 0, $seats) : $ids;
        $waitlisted_ids = $seats > 0 ? array_slice($ids, $seats) : [];

        $upd = $pdo->prepare("UPDATE students SET admission_result = ?, result_published_at = NOW() WHERE id = ?");
        $roll_upd = $pdo->prepare("UPDATE students SET roll_no = ? WHERE id = ?");
        $roll = 0;
        foreach ($selected_ids as $sid) {
            $upd->execute(['Selected', $sid]);
            $roll++;
            $roll_upd->execute([(string) $roll, $sid]); // ড্র হওয়া ক্রম অনুযায়ী নতুন Roll No
        }
        foreach ($waitlisted_ids as $sid) { $upd->execute(['Waitlisted', $sid]); }

        sec_log_activity($pdo, 'Admission', 'Update', 'Admission Lottery Draw সম্পন্ন হয়েছে (Session: ' . $p_session . ', Class: ' . $p_class . ') — Selected: ' . count($selected_ids) . ', Waitlisted: ' . count($waitlisted_ids));
        $_SESSION['ap_msg'] = 'Lottery Draw সম্পন্ন হয়েছে — ' . count($selected_ids) . ' জন Selected (Roll No বসানো হয়েছে), ' . count($waitlisted_ids) . ' জন Waitlisted।';
        adpub_redirect($p_session, $p_class);
    }

    // ---- Waitlisted কে Selected এ প্রমোট করা (সিট খালি হলে) ----
    if (isset($_POST['promote_waitlist'])) {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE session_id = ? AND class_id = ? AND admission_result = 'Selected'");
        $cnt->execute([$p_session, $p_class]);
        $next_roll = (int) $cnt->fetchColumn() + 1;
        $pdo->prepare("UPDATE students SET admission_result = 'Selected', roll_no = ? WHERE id = ?")->execute([(string) $next_roll, $sid]);
        sec_log_activity($pdo, 'Admission', 'Update', "Student #{$sid} Waitlist থেকে Selected এ প্রমোট করা হয়েছে (Roll No {$next_roll})");
        $_SESSION['ap_msg'] = 'Waitlist থেকে Selected এ প্রমোট করা হয়েছে (Roll No ' . $next_roll . ' বসানো হয়েছে)।';
        adpub_redirect($p_session, $p_class);
    }

    // ---- Selected-দের বাল্ক Confirm (Status = Active) ----
    if (isset($_POST['confirm_admission'])) {
        $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE students SET status = 'Active' WHERE id IN ($in) AND admission_result = 'Selected'")->execute($ids);
            sec_log_activity($pdo, 'Admission', 'Update', count($ids) . ' জন শিক্ষার্থীর Admission Confirm করা হয়েছে (Active) — IDs: ' . implode(',', $ids));
            $_SESSION['ap_msg'] = count($ids) . ' জন শিক্ষার্থীর ভর্তি নিশ্চিত করা হয়েছে (Active)।';
        } else {
            $_SESSION['ap_err'] = 'কোনো শিক্ষার্থী সিলেক্ট করা হয়নি।';
        }
        adpub_redirect($p_session, $p_class);
    }
}

// -----------------------------------------------------------
// ডেটা লোড (ভিউ)
// -----------------------------------------------------------
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
$method_label = ['None' => 'General (কোনো Test/Lottery নেই)', 'Test' => 'Admission Test', 'Lottery' => 'Lottery'][$method];
$csrf = htmlspecialchars(csrf_token());
?>
<div class="card-custom mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_settings" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-sliders"></i> Settings</a>
        <a href="index.php?page=admission_applicants" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-list-check"></i> Applied Student List</a>
        <a href="index.php?page=admission_mark_entry" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Mark Entry</a>
        <a href="index.php?page=admission_publish" class="btn btn-primary btn-sm"><i class="fa-solid fa-bullhorn"></i> Result / Publish</a>
        <a href="index.php?page=admission_seat_plan" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chair"></i> Seat Plan</a>
    </div>
</div>

<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Admission — Result / Publish</h6>
    <?php if ($ap_msg): ?><div class="alert alert-success py-2"><?php echo adpub_v($ap_msg); ?></div><?php endif; ?>
    <?php if ($ap_err): ?><div class="alert alert-danger py-2"><?php echo adpub_v($ap_err); ?></div><?php endif; ?>
    <div class="alert alert-secondary py-2 small mb-3">বর্তমান Admission Type: <strong><?php echo adpub_v($method_label); ?></strong></div>

    <form method="GET" action="index.php" class="row g-3">
        <input type="hidden" name="page" value="admission_publish">
        <div class="col-sm-4">
            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
            <select name="f_session" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adpub_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="f_class" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo adpub_v($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($f_session && $f_class): ?>

<?php if ($method === 'Test'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-2">মেধাক্রম অনুযায়ী রেজাল্ট পাবলিশ</h6>
    <p class="text-muted small mb-3">Mark Entry পেজ থেকে মার্ক বসানো হয়ে থাকলে, নিচের বাটনে ক্লিক করলে Total Seats অনুযায়ী মেধাক্রমের ভিত্তিতে Selected/Waitlisted/Rejected ঠিক হয়ে যাবে।</p>
    <form method="POST" action="index.php?page=admission_publish">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <button type="submit" name="publish_test_result" class="btn btn-success px-4" onclick="return confirm('মেধাক্রম অনুযায়ী Selected/Waitlisted রেজাল্ট পাবলিশ করা হবে। নিশ্চিত?');"><i class="fa-solid fa-bullhorn"></i> Publish Result (মেধাক্রম)</button>
    </form>
</div>
<?php endif; ?>

<?php if ($method === 'Lottery'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Lottery Draw</h6>
    <?php $unprocessed = array_filter($pending_students, fn($s) => $s['admission_result'] === null); ?>
    <p class="mb-3">মোট Pending: <strong><?php echo count($pending_students); ?></strong> জন | আনপ্রসেসড (এখনো ড্র হয়নি): <strong><?php echo count($unprocessed); ?></strong> জন | Total Seats: <strong><?php echo adpub_v($setting['total_seats'] ?? '—'); ?></strong></p>
    <form method="POST" action="index.php?page=admission_publish">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <button type="submit" name="run_lottery" class="btn btn-success px-4" onclick="return confirm('র‍্যান্ডম Lottery Draw চালানো হবে — আনপ্রসেসড সবার মধ্যে থেকে সিট অনুযায়ী Selected/Waitlisted ঠিক হবে। নিশ্চিত?');" <?php echo count($unprocessed) === 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-dice"></i> Run Lottery Draw</button>
    </form>
</div>
<?php endif; ?>

<?php if ($method === 'None'): ?>
    <div class="alert alert-info">কোনো Test/Lottery সেট করা নেই — নিচে থেকে সরাসরি Pending স্টুডেন্টদের Selected মার্ক করে Confirm করতে পারেন। (নিচের লিস্টে সরাসরি চেকবক্স মার্ক করে Confirm Admission চাপুন — তার আগে দরকার হলে Applied Student List থেকে যাচাই করে নিন।)</div>
<?php endif; ?>

<div class="card-custom">
    <h6 class="fw-bold text-secondary mb-3">Pending Students — ফলাফল</h6>
    <form method="POST" action="index.php?page=admission_publish">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th><input type="checkbox" id="apCheckAll"></th><th>ID</th><th>Name</th><th>App. No</th><th>Marks</th><th>Result</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if ($pending_students): foreach ($pending_students as $st):
                        $rb = ['Selected' => 'success', 'Waitlisted' => 'warning', 'Rejected' => 'danger'][$st['admission_result']] ?? 'secondary';
                    ?>
                        <tr>
                            <td><?php if ($st['admission_result'] === 'Selected'): ?><input type="checkbox" name="student_ids[]" value="<?php echo (int) $st['id']; ?>" class="ap-row-check"><?php endif; ?></td>
                            <td><?php echo adpub_v($st['student_id']); ?></td>
                            <td><?php echo adpub_v($st['name_en']); ?></td>
                            <td><?php echo adpub_v($st['roll_no']); ?></td>
                            <td><?php echo adpub_v($st['test_marks'] ?? '—'); ?></td>
                            <td><span class="badge bg-<?php echo $rb; ?>"><?php echo adpub_v($st['admission_result'] ?: 'Not Processed'); ?></span></td>
                            <td>
                                <?php if ($st['admission_result'] === 'Waitlisted'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="apPromote(<?php echo (int) $st['id']; ?>)">Promote</button>
                                <?php elseif ($method === 'None' && $st['admission_result'] === null): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="apMarkSelected(<?php echo (int) $st['id']; ?>)">Mark Selected</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">এই Session+Class এ কোনো Pending স্টুডেন্ট নেই।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pending_students): ?>
            <button type="submit" name="confirm_admission" class="btn btn-primary px-4"><i class="fa-solid fa-check"></i> Confirm Admission (Selected → Active)</button>
        <?php endif; ?>
    </form>
</div>

<script>
function apPromote(studentId) {
    if (!confirm('এই শিক্ষার্থীকে Waitlist থেকে Selected এ প্রমোট করবেন?')) { return; }
    document.getElementById('apPromoteStudentId').value = studentId;
    document.getElementById('apPromoteForm').submit();
}
function apMarkSelected(studentId) {
    if (!confirm('এই শিক্ষার্থীকে সরাসরি Selected মার্ক করবেন?')) { return; }
    document.getElementById('apPromoteStudentId').value = studentId;
    document.getElementById('apPromoteForm').submit();
}
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('apCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.ap-row-check').forEach(cb => cb.checked = checkAll.checked);
        });
    }
});
</script>

<!-- আলাদা ছোট্ট ফর্ম শুধু Promote/Mark Selected অ্যাকশনের জন্য — মূল Confirm ফর্মের সাথে কনফ্লিক্ট এড়াতে -->
<form method="POST" action="index.php?page=admission_publish" id="apPromoteForm" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
    <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
    <input type="hidden" name="student_id" id="apPromoteStudentId" value="">
    <input type="hidden" name="promote_waitlist" value="1">
</form>

<?php else: ?>
    <p class="text-muted">Result/Publish দেখতে প্রথমে Session ও Class সিলেক্ট করুন।</p>
<?php endif; ?>
