<?php
// admin/admission_selection.php - Student Module > Admission Test / Lottery Selection
// একটা Session+Class এর জন্য সিলেকশন মেথড (None/Test/Lottery) সেট করা যায়, তারপর
// সেই অনুযায়ী Pending স্টুডেন্টদের মধ্যে থেকে Test মার্ক দিয়ে মেধাক্রম বা Lottery ড্র
// চালিয়ে Selected/Waitlisted ঠিক করা যায়, আর Selected-দের এক ক্লিকে Active (ভর্তি নিশ্চিত) করা যায়।

function asel_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------
// টেবিল/কলাম না থাকলে অটো তৈরি
// ---------------------------------------------------------------
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
function asel_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}
asel_ensure_column($pdo, 'students', 'test_marks', "DECIMAL(6,2) NULL");
asel_ensure_column($pdo, 'students', 'admission_result', "VARCHAR(20) NULL");
asel_ensure_column($pdo, 'students', 'result_published_at', "TIMESTAMP NULL");
asel_ensure_column($pdo, 'institute_settings', 'admission_type', "VARCHAR(20) NOT NULL DEFAULT 'General'");

// Global Settings > Admission Settings এ যা সেট করা আছে সেটাই এখানকার সিলেকশন পদ্ধতি ঠিক করে দেয়
$global_admission_type = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch()['admission_type'] ?? 'General';
$method = ['General' => 'None', 'Admission Test' => 'Test', 'Lottery' => 'Lottery'][$global_admission_type] ?? 'None';

$as_msg = $_SESSION['as_msg'] ?? '';
$as_err = $_SESSION['as_err'] ?? '';
unset($_SESSION['as_msg'], $_SESSION['as_err']);

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
function asel_redirect(int $session_id, int $class_id): void
{
    header('Location: index.php?page=admission_selection&f_session=' . $session_id . '&f_class=' . $class_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['as_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        asel_redirect($f_session, $f_class);
    }

    $p_session = (int) ($_POST['session_id'] ?? 0);
    $p_class   = (int) ($_POST['class_id'] ?? 0);

    // ---- সেটিংস সেভ (Total Seats / Full Marks / Pass Marks — পদ্ধতি এখন Global Settings থেকে আসে) ----
    if (isset($_POST['save_settings'])) {
        $total_seats = (int) ($_POST['total_seats'] ?? 0) ?: null;
        $full_marks = (int) ($_POST['full_marks'] ?? 0) ?: null;
        $pass_marks = (int) ($_POST['pass_marks'] ?? 0) ?: null;

        $stmt = $pdo->prepare(
            "INSERT INTO admission_settings (session_id, class_id, method, total_seats, full_marks, pass_marks)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE method = VALUES(method), total_seats = VALUES(total_seats), full_marks = VALUES(full_marks), pass_marks = VALUES(pass_marks)"
        );
        $stmt->execute([$p_session, $p_class, $method, $total_seats, $full_marks, $pass_marks]);
        sec_log_activity($pdo, 'Admission', 'Update', "Admission Selection সেটিংস সেভ করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        $_SESSION['as_msg'] = 'সিলেকশন সেটিংস সেভ করা হয়েছে।';
        asel_redirect($p_session, $p_class);
    }

    // ---- একাধিক Class-এ একসাথে সেটিংস প্রয়োগ (Total Seats/Full Marks/Pass Marks একই হলে বারবার আলাদা করে লাগবে না) ----
    if (isset($_POST['bulk_apply_settings'])) {
        $class_ids = array_filter(array_map('intval', (array) ($_POST['bulk_class_ids'] ?? [])));
        $total_seats = (int) ($_POST['bulk_total_seats'] ?? 0) ?: null;
        $full_marks = (int) ($_POST['bulk_full_marks'] ?? 0) ?: null;
        $pass_marks = (int) ($_POST['bulk_pass_marks'] ?? 0) ?: null;

        if (!$class_ids) {
            $_SESSION['as_err'] = 'কমপক্ষে একটা Class সিলেক্ট করুন।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO admission_settings (session_id, class_id, method, total_seats, full_marks, pass_marks)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE method = VALUES(method), total_seats = VALUES(total_seats), full_marks = VALUES(full_marks), pass_marks = VALUES(pass_marks)"
            );
            foreach ($class_ids as $cid) {
                $stmt->execute([$p_session, $cid, $method, $total_seats, $full_marks, $pass_marks]);
            }
            sec_log_activity($pdo, 'Admission', 'Update', count($class_ids) . 'টা Class-এ Admission Selection সেটিংস একসাথে প্রয়োগ করা হয়েছে (Session: ' . $p_session . ')');
            $_SESSION['as_msg'] = count($class_ids) . 'টা Class-এ একসাথে সেটিংস প্রয়োগ করা হয়েছে।';
        }
        header('Location: index.php?page=admission_selection&f_session=' . $p_session . '&f_class=' . $f_class);
        exit();
    }

    // ---- Test Marks সেভ ----
    if (isset($_POST['save_marks'])) {
        $marks = $_POST['marks'] ?? [];
        $upd = $pdo->prepare("UPDATE students SET test_marks = ? WHERE id = ? AND session_id = ? AND class_id = ?");
        foreach ($marks as $sid => $mark) {
            $sid = (int) $sid;
            $mark = trim((string) $mark);
            $upd->execute([$mark === '' ? null : (float) $mark, $sid, $p_session, $p_class]);
        }
        sec_log_activity($pdo, 'Admission', 'Update', "Admission Test Marks সেভ করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        $_SESSION['as_msg'] = 'মার্ক সেভ করা হয়েছে।';
        asel_redirect($p_session, $p_class);
    }

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
        $_SESSION['as_msg'] = 'মেধাক্রম অনুযায়ী রেজাল্ট পাবলিশ করা হয়েছে (' . count($ranked) . " জন) — Selected-দের মেধাক্রম অনুযায়ী নতুন Roll No বসানো হয়েছে।";
        asel_redirect($p_session, $p_class);
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
        $_SESSION['as_msg'] = 'Lottery Draw সম্পন্ন হয়েছে — ' . count($selected_ids) . ' জন Selected (Roll No বসানো হয়েছে), ' . count($waitlisted_ids) . ' জন Waitlisted।';
        asel_redirect($p_session, $p_class);
    }

    // ---- Waitlisted কে Selected এ প্রমোট করা (সিট খালি হলে) ----
    if (isset($_POST['promote_waitlist'])) {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE session_id = ? AND class_id = ? AND admission_result = 'Selected'");
        $cnt->execute([$p_session, $p_class]);
        $next_roll = (int) $cnt->fetchColumn() + 1;
        $pdo->prepare("UPDATE students SET admission_result = 'Selected', roll_no = ? WHERE id = ?")->execute([(string) $next_roll, $sid]);
        sec_log_activity($pdo, 'Admission', 'Update', "Student #{$sid} Waitlist থেকে Selected এ প্রমোট করা হয়েছে (Roll No {$next_roll})");
        $_SESSION['as_msg'] = 'Waitlist থেকে Selected এ প্রমোট করা হয়েছে (Roll No ' . $next_roll . ' বসানো হয়েছে)।';
        asel_redirect($p_session, $p_class);
    }

    // ---- রিসেট (মার্ক + রেজাল্ট মুছে আবার নতুন করে শুরু) ----
    if (isset($_POST['reset_selection'])) {
        $pdo->prepare("UPDATE students SET test_marks = NULL, admission_result = NULL, result_published_at = NULL WHERE session_id = ? AND class_id = ? AND status = 'Pending'")
            ->execute([$p_session, $p_class]);
        sec_log_activity($pdo, 'Admission', 'Update', "Admission Selection রিসেট করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        $_SESSION['as_msg'] = 'সিলেকশন রিসেট করা হয়েছে — সবাই আবার আনপ্রসেসড অবস্থায় ফিরে গেছে।';
        asel_redirect($p_session, $p_class);
    }

    // ---- Selected-দের বাল্ক Confirm (Status = Active) ----
    if (isset($_POST['confirm_admission'])) {
        $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE students SET status = 'Active' WHERE id IN ($in) AND admission_result = 'Selected'")->execute($ids);
            sec_log_activity($pdo, 'Admission', 'Update', count($ids) . ' জন শিক্ষার্থীর Admission Confirm করা হয়েছে (Active) — IDs: ' . implode(',', $ids));
            $_SESSION['as_msg'] = count($ids) . ' জন শিক্ষার্থীর ভর্তি নিশ্চিত করা হয়েছে (Active)।';
        } else {
            $_SESSION['as_err'] = 'কোনো শিক্ষার্থী সিলেক্ট করা হয়নি।';
        }
        asel_redirect($p_session, $p_class);
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

// এই Session-এ প্রতিটা Class-এর বর্তমান সেটিংস (বাল্ক অ্যাপ্লাই সেকশনে পাশে দেখানোর জন্য)
$existing_class_settings = [];
if ($f_session) {
    $es = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ?");
    $es->execute([$f_session]);
    foreach ($es->fetchAll() as $row) { $existing_class_settings[(int) $row['class_id']] = $row; }
}
?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Admission Test / Lottery Selection</h6>
    <?php if ($as_msg): ?><div class="alert alert-success py-2"><?php echo asel_v($as_msg); ?></div><?php endif; ?>
    <?php if ($as_err): ?><div class="alert alert-danger py-2"><?php echo asel_v($as_err); ?></div><?php endif; ?>
    <div class="alert alert-secondary py-2 small mb-3">
        বর্তমান Admission Type: <strong><?php echo asel_v($method_label); ?></strong>
        <a href="index.php?page=institute" class="ms-2">(বদলাতে চাইলে Global Settings &raquo; Admission Settings)</a>
    </div>

    <form method="GET" action="index.php" class="row g-3">
        <input type="hidden" name="page" value="admission_selection">
        <div class="col-sm-4">
            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
            <select name="f_session" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo asel_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="f_class" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo asel_v($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($f_session && $method !== 'None'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-1">একাধিক Class-এ একসাথে সেটিংস প্রয়োগ করুন</h6>
    <p class="text-muted small mb-3">যেসব Class-এর Total Seats/Full Marks/Pass Marks একই রকম, সেগুলো একসাথে টিক দিয়ে একবারে সেভ করুন — প্রতিটার জন্য আলাদা করে করতে হবে না।</p>
    <form method="POST" action="index.php?page=admission_selection" class="row g-3 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <div class="col-12">
            <label class="form-label fw-bold d-block">Class সিলেক্ট করুন</label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ($classes as $c):
                    $cid = (int) $c['id'];
                    $existing = $existing_class_settings[$cid] ?? null;
                ?>
                    <div class="form-check border rounded px-3 py-2">
                        <input class="form-check-input" type="checkbox" name="bulk_class_ids[]" value="<?php echo $cid; ?>" id="bulkClass<?php echo $cid; ?>">
                        <label class="form-check-label" for="bulkClass<?php echo $cid; ?>">
                            <?php echo asel_v($c['class_name']); ?>
                            <?php if ($existing && $existing['total_seats']): ?>
                                <br><small class="text-muted">বর্তমান: Seats <?php echo asel_v($existing['total_seats']); ?><?php echo $existing['full_marks'] ? ', Full Marks ' . asel_v($existing['full_marks']) : ''; ?></small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Total Seats</label>
            <input type="number" min="0" name="bulk_total_seats" class="form-control">
        </div>
        <?php if ($method === 'Test'): ?>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Full Marks</label>
            <input type="number" min="0" name="bulk_full_marks" class="form-control">
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Pass Marks (ঐচ্ছিক)</label>
            <input type="number" min="0" name="bulk_pass_marks" class="form-control">
        </div>
        <?php endif; ?>
        <div class="col-12">
            <button type="submit" name="bulk_apply_settings" class="btn btn-success px-4">Apply to Selected Classes</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($f_session && $f_class): ?>

<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">সিলেকশন সেটিংস</h6>
    <form method="POST" action="index.php?page=admission_selection" class="row g-3 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <div class="col-sm-3">
            <label class="form-label fw-bold">Total Seats</label>
            <input type="number" min="0" name="total_seats" class="form-control" value="<?php echo asel_v($setting['total_seats'] ?? ''); ?>">
        </div>
        <div class="col-sm-3 asel-test-field" style="<?php echo $method === 'Test' ? '' : 'display:none;'; ?>">
            <label class="form-label fw-bold">Full Marks</label>
            <input type="number" min="0" name="full_marks" class="form-control" value="<?php echo asel_v($setting['full_marks'] ?? ''); ?>">
        </div>
        <div class="col-sm-3 asel-test-field" style="<?php echo $method === 'Test' ? '' : 'display:none;'; ?>">
            <label class="form-label fw-bold">Pass Marks (ঐচ্ছিক)</label>
            <input type="number" min="0" name="pass_marks" class="form-control" value="<?php echo asel_v($setting['pass_marks'] ?? ''); ?>">
        </div>
        <div class="col-12">
            <button type="submit" name="save_settings" class="btn btn-primary px-4">Save Settings</button>
            <button type="submit" name="reset_selection" class="btn btn-outline-danger px-4" onclick="return confirm('সব মার্ক ও রেজাল্ট মুছে রিসেট করা হবে। নিশ্চিত?');">Reset Selection</button>
        </div>
    </form>
</div>

<?php if ($method === 'None'): ?>
    <div class="alert alert-info">কোনো Test/Lottery সেট করা নেই — নিচে থেকে সরাসরি Pending স্টুডেন্টদের Selected মার্ক করে Confirm করতে পারেন।</div>
<?php endif; ?>

<?php if ($method === 'Test'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Test Marks Entry (Full Marks: <?php echo asel_v($setting['full_marks'] ?? '—'); ?>)</h6>
    <form method="POST" action="index.php?page=admission_selection">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th>ID</th><th>Name</th><th>Roll</th><th style="width:140px;">Marks</th><th>Result</th></tr></thead>
                <tbody>
                    <?php if ($pending_students): foreach ($pending_students as $st): ?>
                        <tr>
                            <td><?php echo asel_v($st['student_id']); ?></td>
                            <td><?php echo asel_v($st['name_en']); ?></td>
                            <td><?php echo asel_v($st['roll_no']); ?></td>
                            <td><input type="number" step="0.01" min="0" name="marks[<?php echo (int) $st['id']; ?>]" class="form-control form-control-sm" value="<?php echo asel_v($st['test_marks']); ?>"></td>
                            <td><?php echo asel_v($st['admission_result'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">এই Session+Class এ কোনো Pending স্টুডেন্ট নেই।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pending_students): ?>
            <button type="submit" name="save_marks" class="btn btn-primary px-4">Save Marks</button>
            <button type="submit" name="publish_test_result" class="btn btn-success px-4" onclick="return confirm('মেধাক্রম অনুযায়ী Selected/Waitlisted রেজাল্ট পাবলিশ করা হবে। নিশ্চিত?');">Publish Result (মেধাক্রম)</button>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<?php if ($method === 'Lottery'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Lottery Draw</h6>
    <?php
        $unprocessed = array_filter($pending_students, fn($s) => $s['admission_result'] === null);
    ?>
    <p class="mb-3">মোট Pending: <strong><?php echo count($pending_students); ?></strong> জন | আনপ্রসেসড (এখনো ড্র হয়নি): <strong><?php echo count($unprocessed); ?></strong> জন | Total Seats: <strong><?php echo asel_v($setting['total_seats'] ?? '—'); ?></strong></p>
    <form method="POST" action="index.php?page=admission_selection">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <button type="submit" name="run_lottery" class="btn btn-success px-4" onclick="return confirm('র‍্যান্ডম Lottery Draw চালানো হবে — আনপ্রসেসড সবার মধ্যে থেকে সিট অনুযায়ী Selected/Waitlisted ঠিক হবে। নিশ্চিত?');" <?php echo count($unprocessed) === 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-dice"></i> Run Lottery Draw</button>
    </form>
</div>
<?php endif; ?>

<div class="card-custom">
    <h6 class="fw-bold text-secondary mb-3">Pending Students — ফলাফল</h6>
    <form method="POST" action="index.php?page=admission_selection">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th><input type="checkbox" id="aselCheckAll"></th><th>ID</th><th>Name</th><th>Roll</th><th>Marks</th><th>Result</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if ($pending_students): foreach ($pending_students as $st):
                        $badge = ['Selected' => 'success', 'Waitlisted' => 'warning', 'Rejected' => 'danger'][$st['admission_result']] ?? 'secondary';
                    ?>
                        <tr>
                            <td><?php if ($st['admission_result'] === 'Selected'): ?><input type="checkbox" name="student_ids[]" value="<?php echo (int) $st['id']; ?>" class="asel-row-check"><?php endif; ?></td>
                            <td><?php echo asel_v($st['student_id']); ?></td>
                            <td><?php echo asel_v($st['name_en']); ?></td>
                            <td><?php echo asel_v($st['roll_no']); ?></td>
                            <td><?php echo asel_v($st['test_marks'] ?? '—'); ?></td>
                            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo asel_v($st['admission_result'] ?: 'Not Processed'); ?></span></td>
                            <td>
                                <?php if ($st['admission_result'] === 'Waitlisted'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="aselPromote(<?php echo (int) $st['id']; ?>)">Promote</button>
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
function aselPromote(studentId) {
    if (!confirm('এই শিক্ষার্থীকে Waitlist থেকে Selected এ প্রমোট করবেন?')) { return; }
    document.getElementById('aselPromoteStudentId').value = studentId;
    document.getElementById('aselPromoteForm').submit();
}
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('aselCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.asel-row-check').forEach(cb => cb.checked = checkAll.checked);
        });
    }
});
</script>

<!-- আলাদা ছোট্ট ফর্ম শুধু Promote অ্যাকশনের জন্য — মূল Confirm ফর্মের সাথে কনফ্লিক্ট এড়াতে -->
<form method="POST" action="index.php?page=admission_selection" id="aselPromoteForm" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
    <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
    <input type="hidden" name="student_id" id="aselPromoteStudentId" value="">
    <input type="hidden" name="promote_waitlist" value="1">
</form>

<?php endif; ?>
