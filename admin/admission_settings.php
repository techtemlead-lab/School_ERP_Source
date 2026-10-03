<?php
// admin/admission_settings.php - Admission Module > Admission Test/Lottery > Settings
// Session+Class wise Total Seats / Full Marks / Pass Marks সেট করা হয়। Method (Test/Lottery/None)
// Global Settings > Admission Settings থেকে আসে (institute_settings.admission_type)।

function adset_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------
// টেবিল/কলাম না থাকলে অটো তৈরি (admission_fee কলাম সহ — Online Payment ফিচারের জন্য)
// ---------------------------------------------------------------
require_once __DIR__ . '/../config/admission_fee_helpers.php';
af_ensure_columns($pdo);
function adset_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}
adset_ensure_column($pdo, 'students', 'test_marks', "DECIMAL(6,2) NULL");
adset_ensure_column($pdo, 'students', 'admission_result', "VARCHAR(20) NULL");
adset_ensure_column($pdo, 'students', 'result_published_at', "TIMESTAMP NULL");
adset_ensure_column($pdo, 'institute_settings', 'admission_type', "VARCHAR(20) NOT NULL DEFAULT 'General'");

// Global Settings > Admission Settings এ যা সেট করা আছে সেটাই এখানকার সিলেকশন পদ্ধতি ঠিক করে দেয়
$global_admission_type = $pdo->query("SELECT admission_type FROM institute_settings WHERE id = 1")->fetch()['admission_type'] ?? 'General';
$method = ['General' => 'None', 'Admission Test' => 'Test', 'Lottery' => 'Lottery'][$global_admission_type] ?? 'None';
$method_label = ['None' => 'General (কোনো Test/Lottery নেই)', 'Test' => 'Admission Test', 'Lottery' => 'Lottery'][$method];

$as_msg = $_SESSION['as_msg'] ?? '';
$as_err = $_SESSION['as_err'] ?? '';
unset($_SESSION['as_msg'], $_SESSION['as_err']);

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);

function adset_redirect(int $session_id, int $class_id): void
{
    header('Location: index.php?page=admission_settings&f_session=' . $session_id . '&f_class=' . $class_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['as_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        adset_redirect($f_session, $f_class);
    }

    $p_session = (int) ($_POST['session_id'] ?? 0);
    $p_class   = (int) ($_POST['class_id'] ?? 0);

    // ---- সেটিংস সেভ (Total Seats / Full Marks / Pass Marks) ----
    if (isset($_POST['save_settings'])) {
        $total_seats = (int) ($_POST['total_seats'] ?? 0) ?: null;
        $full_marks = (int) ($_POST['full_marks'] ?? 0) ?: null;
        $pass_marks = (int) ($_POST['pass_marks'] ?? 0) ?: null;
        $admission_fee = (float) ($_POST['admission_fee'] ?? 0) ?: null;

        $stmt = $pdo->prepare(
            "INSERT INTO admission_settings (session_id, class_id, method, total_seats, full_marks, pass_marks, admission_fee)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE method = VALUES(method), total_seats = VALUES(total_seats), full_marks = VALUES(full_marks), pass_marks = VALUES(pass_marks), admission_fee = VALUES(admission_fee)"
        );
        $stmt->execute([$p_session, $p_class, $method, $total_seats, $full_marks, $pass_marks, $admission_fee]);
        sec_log_activity($pdo, 'Admission', 'Update', "Admission Settings সেভ করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        $_SESSION['as_msg'] = 'সিলেকশন সেটিংস সেভ করা হয়েছে।';
        adset_redirect($p_session, $p_class);
    }

    // ---- একাধিক Class-এ একসাথে সেটিংস প্রয়োগ ----
    if (isset($_POST['bulk_apply_settings'])) {
        $class_ids = array_filter(array_map('intval', (array) ($_POST['bulk_class_ids'] ?? [])));
        $total_seats = (int) ($_POST['bulk_total_seats'] ?? 0) ?: null;
        $full_marks = (int) ($_POST['bulk_full_marks'] ?? 0) ?: null;
        $pass_marks = (int) ($_POST['bulk_pass_marks'] ?? 0) ?: null;
        $bulk_admission_fee = (float) ($_POST['bulk_admission_fee'] ?? 0) ?: null;

        if (!$class_ids) {
            $_SESSION['as_err'] = 'কমপক্ষে একটা Class সিলেক্ট করুন।';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO admission_settings (session_id, class_id, method, total_seats, full_marks, pass_marks, admission_fee)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE method = VALUES(method), total_seats = VALUES(total_seats), full_marks = VALUES(full_marks), pass_marks = VALUES(pass_marks), admission_fee = VALUES(admission_fee)"
            );
            foreach ($class_ids as $cid) {
                $stmt->execute([$p_session, $cid, $method, $total_seats, $full_marks, $pass_marks, $bulk_admission_fee]);
            }
            sec_log_activity($pdo, 'Admission', 'Update', count($class_ids) . 'টা Class-এ Admission Settings একসাথে প্রয়োগ করা হয়েছে (Session: ' . $p_session . ')');
            $_SESSION['as_msg'] = count($class_ids) . 'টা Class-এ একসাথে সেটিংস প্রয়োগ করা হয়েছে।';
        }
        header('Location: index.php?page=admission_settings&f_session=' . $p_session . '&f_class=' . $f_class);
        exit();
    }

    // ---- রিসেট (মার্ক + রেজাল্ট মুছে আবার নতুন করে শুরু) ----
    if (isset($_POST['reset_selection'])) {
        $pdo->prepare("UPDATE students SET test_marks = NULL, admission_result = NULL, result_published_at = NULL WHERE session_id = ? AND class_id = ? AND status = 'Pending'")
            ->execute([$p_session, $p_class]);
        sec_log_activity($pdo, 'Admission', 'Update', "Admission Selection রিসেট করা হয়েছে (Session: {$p_session}, Class: {$p_class})");
        $_SESSION['as_msg'] = 'সিলেকশন রিসেট করা হয়েছে — সবাই আবার আনপ্রসেসড অবস্থায় ফিরে গেছে।';
        adset_redirect($p_session, $p_class);
    }
}

// -----------------------------------------------------------
// ডেটা লোড (ভিউ)
// -----------------------------------------------------------
$setting = null;
if ($f_session && $f_class) {
    $s = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ? AND class_id = ?");
    $s->execute([$f_session, $f_class]);
    $setting = $s->fetch();
}

$csrf = htmlspecialchars(csrf_token());

// এই Session-এ প্রতিটা Class-এর বর্তমান সেটিংস + কতজন Pending Apply করেছে (বাল্ক অ্যাপ্লাই ও ওভারভিউ দেখানোর জন্য)
$existing_class_settings = [];
$applied_counts = [];
if ($f_session) {
    $es = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ?");
    $es->execute([$f_session]);
    foreach ($es->fetchAll() as $row) { $existing_class_settings[(int) $row['class_id']] = $row; }

    $ac = $pdo->prepare("SELECT class_id, COUNT(*) AS cnt FROM students WHERE session_id = ? AND status = 'Pending' GROUP BY class_id");
    $ac->execute([$f_session]);
    foreach ($ac->fetchAll() as $row) { $applied_counts[(int) $row['class_id']] = (int) $row['cnt']; }
}
?>
<div class="card-custom mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_settings" class="btn btn-primary btn-sm"><i class="fa-solid fa-sliders"></i> Settings</a>
        <a href="index.php?page=admission_applicants" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-list-check"></i> Applied Student List</a>
        <a href="index.php?page=admission_mark_entry" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Mark Entry</a>
        <a href="index.php?page=admission_publish" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-bullhorn"></i> Result / Publish</a>
        <a href="index.php?page=admission_seat_plan" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chair"></i> Seat Plan</a>
        <a href="index.php?page=admission_payment_settings" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-credit-card"></i> Online Payment Settings</a>
        <a href="index.php?page=admission_fee_claims" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-receipt"></i> Fee Claims / Payments</a>
    </div>
</div>

<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">Admission Test / Lottery — Settings</h6>
    <?php if ($as_msg): ?><div class="alert alert-success py-2"><?php echo adset_v($as_msg); ?></div><?php endif; ?>
    <?php if ($as_err): ?><div class="alert alert-danger py-2"><?php echo adset_v($as_err); ?></div><?php endif; ?>
    <div class="alert alert-secondary py-2 small mb-3">
        বর্তমান Admission Type: <strong><?php echo adset_v($method_label); ?></strong>
        <a href="index.php?page=institute" class="ms-2">(বদলাতে চাইলে Global Settings &raquo; Admission Settings)</a>
    </div>

    <form method="GET" action="index.php" class="row g-3">
        <input type="hidden" name="page" value="admission_settings">
        <div class="col-sm-4">
            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
            <select name="f_session" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adset_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="f_class" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo adset_v($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($f_session && $method !== 'None'): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-1">একাধিক Class-এ একসাথে সেটিংস প্রয়োগ করুন</h6>
    <p class="text-muted small mb-3">যেসব Class-এর Total Seats/Full Marks/Pass Marks একই রকম, সেগুলো একসাথে টিক দিয়ে একবারে সেভ করুন — প্রতিটার জন্য আলাদা করে করতে হবে না।</p>
    <form method="POST" action="index.php?page=admission_settings" class="row g-3 align-items-end">
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
                            <?php echo adset_v($c['class_name']); ?>
                            <?php if ($existing && $existing['total_seats']): ?>
                                <br><small class="text-muted">বর্তমান: Seats <?php echo adset_v($existing['total_seats']); ?><?php echo $existing['full_marks'] ? ', Full Marks ' . adset_v($existing['full_marks']) : ''; ?></small>
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
        <div class="col-sm-3">
            <label class="form-label fw-bold">Admission Fee (ঐচ্ছিক)</label>
            <input type="number" min="0" step="0.01" name="bulk_admission_fee" class="form-control" placeholder="0 = অনলাইন পেমেন্ট লাগবে না">
        </div>
        <div class="col-12">
            <button type="submit" name="bulk_apply_settings" class="btn btn-success px-4">Apply to Selected Classes</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($f_session && $f_class): ?>
<div class="card-custom mb-3">
    <h6 class="fw-bold text-secondary mb-3">এই Class-এর সেটিংস</h6>
    <form method="POST" action="index.php?page=admission_settings" class="row g-3 align-items-end">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $f_session; ?>">
        <input type="hidden" name="class_id" value="<?php echo $f_class; ?>">
        <div class="col-sm-3">
            <label class="form-label fw-bold">Total Seats</label>
            <input type="number" min="0" name="total_seats" class="form-control" value="<?php echo adset_v($setting['total_seats'] ?? ''); ?>">
        </div>
        <?php if ($method === 'Test'): ?>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Full Marks</label>
            <input type="number" min="0" name="full_marks" class="form-control" value="<?php echo adset_v($setting['full_marks'] ?? ''); ?>">
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Pass Marks (ঐচ্ছিক)</label>
            <input type="number" min="0" name="pass_marks" class="form-control" value="<?php echo adset_v($setting['pass_marks'] ?? ''); ?>">
        </div>
        <?php endif; ?>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Admission Fee (ঐচ্ছিক)</label>
            <input type="number" min="0" step="0.01" name="admission_fee" class="form-control" value="<?php echo adset_v($setting['admission_fee'] ?? ''); ?>" placeholder="0 = অনলাইন পেমেন্ট লাগবে না">
            <small class="text-muted">খালি/０ রাখলে Online Admission Form-এ এই ক্লাসের জন্য কোনো Payment ধাপ দেখাবে না — <a href="index.php?page=admission_payment_settings">Payment Method কনফিগার করুন</a></small>
        </div>
        <div class="col-12">
            <button type="submit" name="save_settings" class="btn btn-primary px-4">Save Settings</button>
            <button type="submit" name="reset_selection" class="btn btn-outline-danger px-4" onclick="return confirm('সব মার্ক ও রেজাল্ট মুছে রিসেট করা হবে। নিশ্চিত?');">Reset Selection</button>
        </div>
    </form>
</div>
<?php if ($method === 'None'): ?>
    <div class="alert alert-info">কোনো Test/Lottery সেট করা নেই — Applied Student List থেকে Pending স্টুডেন্টদের দেখে সরাসরি Result/Publish পেজ থেকে Selected মার্ক করে Confirm করতে পারবেন।</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($f_session): ?>
<div class="card-custom">
    <h6 class="fw-bold text-secondary mb-3">এই Session-এর সব Class — সংক্ষিপ্ত ওভারভিউ</h6>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr><th>Class</th><th>Total Seats</th><th>Full Marks</th><th>Pass Marks</th><th>Admission Fee</th><th>Applied (Pending)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $c): $cid = (int) $c['id']; $es = $existing_class_settings[$cid] ?? null; ?>
                    <tr>
                        <td><?php echo adset_v($c['class_name']); ?></td>
                        <td><?php echo adset_v($es['total_seats'] ?? '—'); ?></td>
                        <td><?php echo $method === 'Test' ? adset_v($es['full_marks'] ?? '—') : '—'; ?></td>
                        <td><?php echo $method === 'Test' ? adset_v($es['pass_marks'] ?? '—') : '—'; ?></td>
                        <td><?php echo !empty($es['admission_fee']) ? number_format((float) $es['admission_fee'], 2) : '—'; ?></td>
                        <td><?php echo (int) ($applied_counts[$cid] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
