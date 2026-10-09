<?php
// student/scholarship_apply.php - শিক্ষাবৃত্তির জন্য আবেদন (Student/Guardian Portal)
// অভিভাবক এখান থেকে "শিক্ষাবৃত্তি আবেদন ফরম" পূরণ করে জমা দেন। Admin যাচাই করে অনুমোদন করলে
// বৃত্তির টাকা বকেয়া ফি-র সাথে সমন্বয় হয় অথবা হাতে নগদ দেওয়া হয় — তার অবস্থা এই পেজেই দেখা যায়।
// $student, $pdo — index.php থেকে available

require_once __DIR__ . '/../config/portal_access.php';
erp_portal_controller_role(['student']);
$student = erp_portal_require_role($pdo, 'student', 'scholarship_applications');
require_once __DIR__ . '/../config/scholarship_application_helpers.php';
sa_ensure_tables($pdo);

$ssa_err = $_SESSION['ssa_err'] ?? '';
$ssa_msg = $_SESSION['ssa_msg'] ?? '';
$ssa_old = $_SESSION['ssa_old'] ?? null;
unset($_SESSION['ssa_err'], $_SESSION['ssa_msg'], $_SESSION['ssa_old']);

$ssa_session_id = !empty($student['session_id']) ? (int) $student['session_id'] : null;
if (!$ssa_session_id) {
    $cs = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetchColumn();
    $ssa_session_id = $cs ? (int) $cs : null;
}
$ssa_base = 'index.php?page=scholarship_apply';

// -----------------------------------------------------------------
// POST
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ssa_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $ssa_base);
        exit();
    }

    // --- আবেদন প্রত্যাহার (শুধু নিজের Pending আবেদন) ---
    if (isset($_POST['withdraw'])) {
        $id = (int) ($_POST['app_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM scholarship_applications WHERE id = ? AND student_id = ? AND status = 'Pending'");
        $stmt->execute([$id, (int) $student['id']]);
        $_SESSION[$stmt->rowCount() ? 'ssa_msg' : 'ssa_err'] = $stmt->rowCount() ? 'আবেদন প্রত্যাহার করা হয়েছে।' : 'আবেদনটি প্রত্যাহার করা যায়নি।';
        header('Location: ' . $ssa_base);
        exit();
    }

    // --- নতুন আবেদন জমা ---
    if (isset($_POST['submit_application'])) {
        $name     = mb_substr(trim($_POST['applicant_name'] ?? ''), 0, 150);
        $relation = in_array($_POST['applicant_relation'] ?? '', ['Father', 'Mother', 'Guardian'], true) ? $_POST['applicant_relation'] : 'Father';
        $order    = (int) ($_POST['child_order'] ?? 1);
        $order    = ($order >= 1 && $order <= 4) ? $order : 1;
        $occupation = mb_substr(trim($_POST['occupation'] ?? ''), 0, 150);
        $income   = (float) ($_POST['monthly_income'] ?? -1);
        $members  = (int) ($_POST['family_members'] ?? 0);
        $earning  = (int) ($_POST['earning_members'] ?? -1);
        $present  = mb_substr(trim($_POST['present_address'] ?? ''), 0, 500);
        $permanent = mb_substr(trim($_POST['permanent_address'] ?? ''), 0, 500);
        $mobile   = preg_replace('/[^0-9+]/', '', (string) ($_POST['mobile'] ?? ''));
        $whatsapp = preg_replace('/[^0-9+]/', '', (string) ($_POST['whatsapp'] ?? ''));
        $email    = trim($_POST['email'] ?? '');
        $reason   = mb_substr(trim($_POST['reason'] ?? ''), 0, 2000);
        $children = sa_clean_children($_POST);

        $err = '';
        if ($name === '') { $err = 'আবেদনকারীর (বাবা/মা) নাম দিন।'; }
        elseif (!is_finite($income) || $income < 0 || $income > 9999999999.99) { $err = 'মাসিক আয় সঠিকভাবে দিন (না থাকলে ০ দিন)।'; }
        elseif ($members < 1 || $members > 50) { $err = 'পরিবারের মোট সদস্য সংখ্যা দিন।'; }
        elseif ($earning < 0 || $earning > $members) { $err = 'উপার্জনক্ষম সদস্য সংখ্যা মোট সদস্যের বেশি হতে পারে না।'; }
        elseif ($present === '' || $permanent === '') { $err = 'বর্তমান ও স্থায়ী ঠিকানা দিন।'; }
        elseif (strlen($mobile) < 10) { $err = 'সঠিক মোবাইল নম্বর দিন।'; }
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'ইমেইল ঠিকানা সঠিক নয়।'; }
        elseif (sa_has_active_application($pdo, (int) $student['id'], $ssa_session_id)) {
            $err = 'এই শিক্ষাবর্ষে আপনার একটি আবেদন ইতিমধ্যে চলমান বা অনুমোদিত আছে।';
        }

        if ($err !== '') {
            $_SESSION['ssa_err'] = $err;
            $_SESSION['ssa_old'] = $_POST;
            header('Location: ' . $ssa_base . '&view=form');
            exit();
        }

        $pdo->beginTransaction();
        try {
        $lock = $pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');
        $lock->execute([(int) $student['id']]);
        if (!$lock->fetchColumn() || sa_has_active_application($pdo, (int) $student['id'], $ssa_session_id)) {
            throw new RuntimeException('An application already exists for this session.');
        }
        $pdo->prepare(
            "INSERT INTO scholarship_applications
                (student_id, session_id, class_id, applicant_name, applicant_relation, child_order, occupation, monthly_income,
                 family_members, earning_members, children_json, present_address, permanent_address, mobile, whatsapp, email, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')"
        )->execute([
            (int) $student['id'], $ssa_session_id, $student['class_id'] ?: null, $name, $relation, $order, $occupation ?: null, $income,
            $members, $earning, $children ? json_encode($children, JSON_UNESCAPED_UNICODE) : null,
            $present, $permanent, $mobile, $whatsapp ?: null, $email ?: null, $reason ?: null,
        ]);
        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['ssa_err'] = 'Application could not be submitted. Check for an existing application and try again.';
            header('Location: ' . $ssa_base); exit;
        }
        sec_log_activity($pdo, 'Scholarship', 'Create', "শিক্ষাবৃত্তির আবেদন জমা: {$student['name_en']} (Roll: {$student['roll_no']})");
        $_SESSION['ssa_msg'] = 'আপনার শিক্ষাবৃত্তির আবেদন জমা হয়েছে। স্কুল কর্তৃপক্ষ যাচাই করে সিদ্ধান্ত জানাবে।';
        header('Location: ' . $ssa_base);
        exit();
    }
}

$ssa_view = $_GET['view'] ?? 'list';

// =================================================================
// ফর্ম
// =================================================================
if ($ssa_view === 'form') {
    if (sa_has_active_application($pdo, (int) $student['id'], $ssa_session_id)) {
        $_SESSION['ssa_err'] = 'এই শিক্ষাবর্ষে আপনার একটি আবেদন ইতিমধ্যে চলমান বা অনুমোদিত আছে।';
        header('Location: ' . $ssa_base);
        exit();
    }
    $cls = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?"); $cls->execute([$student['class_id']]);
    $ses = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?"); $ses->execute([$ssa_session_id]);
    $shf = $pdo->prepare("SELECT shift_name FROM academic_shifts WHERE id = ?"); $shf->execute([$student['shift_id'] ?? 0]);
    $class_name = $cls->fetchColumn() ?: '—'; $session_name = $ses->fetchColumn() ?: '—'; $shift_name = $shf->fetchColumn() ?: '—';

    $o = $ssa_old ?: [];
    $val = function (string $k, $default = '') use ($o) { return sa_e($o[$k] ?? $default); };
    $old_rel   = $o['applicant_relation'] ?? 'Father';
    $old_order = (int) ($o['child_order'] ?? 1);
    $def_name  = $old_rel === 'Mother' ? ($student['mother_name'] ?? '') : ($student['father_name'] ?? '');
    $def_occ   = $student['father_profession'] ?? '';
    $def_mob   = $student['father_phone'] ?: ($student['mother_phone'] ?: ($student['mobile'] ?? ''));
    $rows = [];
    if (!empty($o['child_gender'])) {
        foreach ($o['child_gender'] as $k => $_) {
            $rows[] = ['gender' => $o['child_gender'][$k] ?? '', 'age' => $o['child_age'][$k] ?? '', 'inst' => $o['child_inst'][$k] ?? '', 'class' => $o['child_class'][$k] ?? ''];
        }
    }
    if (!$rows) {
        $rows[] = ['gender' => ($student['gender'] ?? ''), 'age' => '', 'inst' => $GLOBALS['tenant']['school_name'] ?? '', 'class' => $class_name];
        $rows[] = ['gender' => '', 'age' => '', 'inst' => '', 'class' => ''];
        $rows[] = ['gender' => '', 'age' => '', 'inst' => '', 'class' => ''];
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:#14b8a6;"></i>শিক্ষাবৃত্তি আবেদন ফরম</h5>
        <a href="<?php echo $ssa_base; ?>" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
    </div>
    <?php if ($ssa_err): ?><div class="alert alert-danger py-2"><?php echo sa_e($ssa_err); ?></div><?php endif; ?>

    <form method="POST" class="card-custom">
        <?php echo csrf_field(); ?>

        <h6 class="fw-bold border-bottom pb-1">আবেদনকারী ও শিক্ষার্থীর তথ্য</h6>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold">আবেদনকারীর নাম <span class="text-danger">*</span></label>
                <input type="text" name="applicant_name" class="form-control form-control-sm" required value="<?php echo $val('applicant_name', $def_name); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">সম্পর্ক</label>
                <select name="applicant_relation" class="form-select form-select-sm">
                    <?php foreach (['Father' => 'বাবা', 'Mother' => 'মা', 'Guardian' => 'অভিভাবক'] as $k => $l): ?>
                        <option value="<?php echo $k; ?>" <?php echo $old_rel === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">আমার কততম সন্তান</label>
                <select name="child_order" class="form-select form-select-sm">
                    <?php foreach ([1 => '১ম', 2 => '২য়', 3 => '৩য়', 4 => '৪র্থ বা তার পরে'] as $k => $l): ?>
                        <option value="<?php echo $k; ?>" <?php echo $old_order === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">পেশা</label>
                <input type="text" name="occupation" class="form-control form-control-sm" value="<?php echo $val('occupation', $def_occ); ?>">
            </div>
            <div class="col-12">
                <div class="small text-muted">শিক্ষার্থী: <b><?php echo sa_e($student['name_en']); ?></b> · শিক্ষাবর্ষ: <b><?php echo sa_e($session_name); ?></b> · শ্রেণি: <b><?php echo sa_e($class_name); ?></b> · শিফট: <b><?php echo sa_e($shift_name); ?></b> · রোল: <b><?php echo sa_e($student['roll_no']); ?></b></div>
            </div>
        </div>

        <h6 class="fw-bold border-bottom pb-1">সন্তান/সন্ততির তথ্য</h6>
        <div class="table-responsive mb-2">
            <table class="table table-bordered table-sm align-middle mb-1" id="ssaChildTable">
                <thead class="table-light"><tr><th style="width:140px;">লিঙ্গ</th><th style="width:90px;">বয়স</th><th>অধ্যয়নরত প্রতিষ্ঠানের নাম</th><th style="width:170px;">অধ্যয়নরত শ্রেণি</th><th style="width:40px;"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><select name="child_gender[]" class="form-select form-select-sm">
                            <option value=""></option>
                            <?php foreach (['Male' => 'ছেলে', 'Female' => 'মেয়ে'] as $gk => $gl): ?>
                                <option value="<?php echo $gk; ?>" <?php echo (strcasecmp((string) $r['gender'], $gk) === 0) ? 'selected' : ''; ?>><?php echo $gl; ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="child_age[]" maxlength="10" class="form-control form-control-sm" value="<?php echo sa_e($r['age']); ?>"></td>
                        <td><input type="text" name="child_inst[]" maxlength="150" class="form-control form-control-sm" value="<?php echo sa_e($r['inst']); ?>"></td>
                        <td><input type="text" name="child_class[]" maxlength="60" class="form-control form-control-sm" value="<?php echo sa_e($r['class']); ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="ssaDelRow(this)" title="সারি মুছুন">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ssaAddRow()"><i class="fa-solid fa-plus me-1"></i> আরেকটি সন্তান যোগ করুন</button>
        </div>

        <h6 class="fw-bold border-bottom pb-1 mt-3">আয় ও পারিবারিক তথ্য</h6>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold">মাসিক আয় (টাকা) <span class="text-danger">*</span></label>
                <input type="number" name="monthly_income" min="0" step="1" class="form-control form-control-sm" required value="<?php echo $val('monthly_income'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">পরিবারের মোট সদস্য (জন) <span class="text-danger">*</span></label>
                <input type="number" name="family_members" min="1" max="50" class="form-control form-control-sm" required value="<?php echo $val('family_members'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">উপার্জনক্ষম সদস্য (জন) <span class="text-danger">*</span></label>
                <input type="number" name="earning_members" min="0" max="50" class="form-control form-control-sm" required value="<?php echo $val('earning_members'); ?>">
            </div>
        </div>

        <h6 class="fw-bold border-bottom pb-1">ঠিকানা ও যোগাযোগ</h6>
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label small fw-bold">বর্তমান ঠিকানা <span class="text-danger">*</span></label>
                <textarea name="present_address" rows="2" class="form-control form-control-sm" required><?php echo $val('present_address', $student['present_address'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">স্থায়ী ঠিকানা <span class="text-danger">*</span></label>
                <textarea name="permanent_address" rows="2" class="form-control form-control-sm" required><?php echo $val('permanent_address', $student['permanent_address'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">মোবাইল <span class="text-danger">*</span></label>
                <input type="text" name="mobile" class="form-control form-control-sm" required value="<?php echo $val('mobile', $def_mob); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">হোয়াটসঅ্যাপ</label>
                <input type="text" name="whatsapp" class="form-control form-control-sm" value="<?php echo $val('whatsapp'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">ইমেইল</label>
                <input type="email" name="email" class="form-control form-control-sm" value="<?php echo $val('email', $student['email'] ?? ''); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-bold">অতিরিক্ত বক্তব্য (ঐচ্ছিক) — কেন শিক্ষাবৃত্তি প্রয়োজন</label>
            <textarea name="reason" rows="3" maxlength="2000" class="form-control form-control-sm"><?php echo $val('reason'); ?></textarea>
        </div>

        <div class="alert alert-light border small">
            আমি ঘোষণা করছি যে উপরের সব তথ্য সত্য। মিথ্যা তথ্য প্রমাণিত হলে আবেদন বাতিল হতে পারে।
        </div>

        <button type="submit" name="submit_application" class="btn btn-primary" onclick="return confirm('আবেদন জমা দিলে আর এডিট করা যাবে না (প্রত্যাহার করে নতুন করে দেওয়া যাবে)। নিশ্চিত?');">
            <i class="fa-solid fa-paper-plane me-1"></i> আবেদন জমা দিন
        </button>
    </form>

    <script>
    function ssaAddRow() {
        var tb = document.querySelector('#ssaChildTable tbody');
        if (tb.rows.length >= 6) { alert('সর্বোচ্চ ৬টি সারি যোগ করা যাবে।'); return; }
        var tr = tb.rows[0].cloneNode(true);
        Array.from(tr.querySelectorAll('input')).forEach(function (i) { i.value = ''; });
        Array.from(tr.querySelectorAll('select')).forEach(function (s) { s.value = ''; });
        tb.appendChild(tr);
    }
    function ssaDelRow(btn) {
        var tb = document.querySelector('#ssaChildTable tbody');
        if (tb.rows.length <= 1) { return; }
        btn.closest('tr').remove();
    }
    </script>
    <?php
    return;
}

// =================================================================
// তালিকা (ডিফল্ট)
// =================================================================
$stmt = $pdo->prepare(sa_select_sql() . " WHERE a.student_id = ? ORDER BY a.id DESC");
$stmt->execute([(int) $student['id']]);
$ssa_list = $stmt->fetchAll();
$ssa_can_apply = !sa_has_active_application($pdo, (int) $student['id'], $ssa_session_id);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:#14b8a6;"></i>শিক্ষাবৃত্তির আবেদন</h5>
    <?php if ($ssa_can_apply): ?>
        <a href="<?php echo $ssa_base; ?>&view=form" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-1"></i> নতুন আবেদন করুন</a>
    <?php endif; ?>
</div>

<?php if ($ssa_msg): ?><div class="alert alert-success py-2"><?php echo sa_e($ssa_msg); ?></div><?php endif; ?>
<?php if ($ssa_err): ?><div class="alert alert-danger py-2"><?php echo sa_e($ssa_err); ?></div><?php endif; ?>

<?php if (!$ssa_list): ?>
    <div class="card-custom text-center py-5">
        <div class="mb-3"><i class="fa-solid fa-hand-holding-dollar" style="font-size:48px;color:#14b8a6;"></i></div>
        <p class="text-muted mb-1">আপনি এখনো কোনো শিক্ষাবৃত্তির আবেদন করেননি।</p>
        <p class="text-muted small mb-0">উপরের "নতুন আবেদন করুন" বাটন চেপে ফর্ম পূরণ করুন।</p>
    </div>
<?php else: foreach ($ssa_list as $a): $m = sa_status_meta($a); ?>
    <div class="card-custom mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div>
                <div class="fw-bold"><?php echo sa_e(sa_app_no($a)); ?> <span class="badge <?php echo $m['badge']; ?> ms-2"><?php echo sa_e($m['label']); ?></span></div>
                <div class="small text-muted">আবেদনের তারিখ: <?php echo sa_e(date('d M Y', strtotime($a['applied_at']))); ?> · শিক্ষাবর্ষ: <?php echo sa_e($a['session_name'] ?? '—'); ?> · শ্রেণি: <?php echo sa_e($a['class_name'] ?? '—'); ?></div>
            </div>
            <div class="d-flex gap-2 align-items-start">
                <a href="../scholarship_application_print.php?portal=student&amp;id=<?php echo (int) $a['id']; ?>" target="_blank" class="btn btn-sm btn-dark"><i class="fa-solid fa-print me-1"></i> প্রিন্ট</a>
                <?php if ($a['status'] === 'Pending'): ?>
                <form method="POST" onsubmit="return confirm('আবেদনটি প্রত্যাহার করতে চান?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="app_id" value="<?php echo (int) $a['id']; ?>">
                    <button type="submit" name="withdraw" class="btn btn-sm btn-outline-danger">প্রত্যাহার</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($a['status'] === 'Rejected' && trim((string) $a['admin_remarks']) !== ''): ?>
            <div class="alert alert-danger py-2 mt-3 mb-0 small"><b>কর্তৃপক্ষের মন্তব্য:</b> <?php echo nl2br(sa_e($a['admin_remarks'])); ?></div>
        <?php elseif ($a['status'] === 'Approved'): ?>
            <div class="alert alert-info py-2 mt-3 mb-0 small">
                <b><?php echo sa_e($a['type_name'] ?? 'শিক্ষাবৃত্তি'); ?></b> — অনুমোদিত পরিমাণ: <b>৳<?php echo number_format((float) $a['approved_amount'], 2); ?></b>
                · প্রদানের ধরন: <b><?php echo sa_e(sa_mode_label($a['settle_mode'])); ?></b>
                <?php if (($a['award_status'] ?? '') === 'Disbursed'): ?>
                    <br>বকেয়া ফি-র সাথে সমন্বয়: <b>৳<?php echo number_format((float) $a['adjusted_amount'], 2); ?></b>
                    · হাতে নগদ: <b>৳<?php echo number_format((float) $a['cash_amount'], 2); ?></b>
                    <?php if ($a['disb_receipt']): ?> · রিসিপ্ট: <b><?php echo sa_e($a['disb_receipt']); ?></b>
                        <a href="../scholarship_receipt_print.php?portal=student&amp;app=<?php echo (int) $a['id']; ?>" target="_blank" class="btn btn-sm btn-success ms-1 py-0"><i class="fa-solid fa-receipt me-1"></i> রসিদ প্রিন্ট</a><?php endif; ?>
                <?php endif; ?>
                <?php if (trim((string) $a['admin_remarks']) !== ''): ?><br><b>মন্তব্য:</b> <?php echo nl2br(sa_e($a['admin_remarks'])); ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>
