<?php
// admin/finance_students_dues.php - Finance Module > Finance Reports > Students Dues
// ফিল্টার (Session, Class, Shift, Section, Student ID) দিয়ে সার্চ করলে প্রতিটা স্টুডেন্টের
// Admission + মাস-ওয়াইজ (Jan-Dec) Due, Till Current, Future Payable, Total Collection (গ্রস) দেখায়।
// প্রতি রোতে Details (Level 2 তে যায়) আর Send SMS বাটন, উপরে Send SMS(All) বাল্ক অ্যাকশন।

require_once __DIR__ . '/finance_due_report_common.php';
require_once __DIR__ . '/../config/sms.php';

function sdv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sd_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$MONTHS = dr_months();
$current_month = (int) date('n');

$sd_msg = $_SESSION['sd_msg'] ?? '';
$sd_err = $_SESSION['sd_err'] ?? '';
unset($_SESSION['sd_msg'], $_SESSION['sd_err']);

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

// -----------------------------------------------------------
// Send SMS Handler (Selected হলে সিলেক্টেড, নাহলে যা লিস্টে দেখানো হয়েছে সবাইকে)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_due_sms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sd_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_students_dues');
        exit();
    }

    $session_id = (int) ($_POST['session_id'] ?? 0);
    $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));

    // কোনো চেকবক্স সিলেক্ট করা না থাকলে "Send SMS(All)" চাপলে ফিল্টারে যা লিস্টে দেখানো হয়েছে সবাইকে পাঠানো হয়
    if (!$ids && $session_id > 0) {
        $bc = (int) ($_POST['back_class'] ?? 0);
        $bs = (int) ($_POST['back_shift'] ?? 0);
        $bsec = (int) ($_POST['back_section'] ?? 0);
        $bsid = trim($_POST['back_student_id'] ?? '');
        if ($bc > 0) {
            $bwhere = ['s.session_id = ?', 's.class_id = ?', "s.status = 'Active'"];
            $bparams = [$session_id, $bc];
            if ($bs > 0) { $bwhere[] = 's.shift_id = ?'; $bparams[] = $bs; }
            if ($bsec > 0) { $bwhere[] = 's.section_id = ?'; $bparams[] = $bsec; }
            if ($bsid !== '') { $bwhere[] = 's.student_id LIKE ?'; $bparams[] = '%' . $bsid . '%'; }
            branch_apply_filter($bwhere, $bparams, 's.branch_id');
            $bstmt = $pdo->prepare("SELECT s.id FROM students s WHERE " . implode(' AND ', $bwhere));
            $bstmt->execute($bparams);
            $ids = array_map('intval', $bstmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    if (!$session_id || !$ids) {
        $_SESSION['sd_err'] = 'Session এবং কমপক্ষে একজন শিক্ষার্থী দরকার।';
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT s.id, s.student_id AS student_code, s.name_en, s.guardian_phone, s.roll_no, s.class_id, c.class_name
             FROM students s LEFT JOIN academic_classes c ON c.id = s.class_id
             WHERE s.id IN ($in)"
        );
        $stmt->execute($ids);
        $target_students = $stmt->fetchAll();

        $tpl_row = $pdo->query("SELECT content_due FROM sms_settings WHERE id = 1")->fetch();
        $template = $tpl_row['content_due'] ?? 'Dear guardian, {FIRST_NAME} {LAST_NAME} (Class: {CLASS}, Roll: {ROLL}) has a due amount of {DUE_AMOUNT} at {SCHOOL_NAME}. Please clear the dues soon.';
        $school = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';

        $log_stmt = $pdo->prepare(
            "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'due', ?, ?, ?, ?)"
        );
        $sent_by = $_SESSION['admin_user'] ?? 'admin';
        $sent = 0; $failed = 0;

        foreach ($target_students as $stu) {
            if (empty($stu['guardian_phone'])) { $failed++; continue; }
            $summary = dr_student_summary($pdo, $stu, $session_id, $fee_heads, $current_month);
            // SMS-এ পুরো বছরের নয়, শুধু আজকের মাস পর্যন্ত Due (Admission Due + Jan..চলতি মাস)
            $sms_due = (float) $summary['admission_due'] + (float) $summary['till_current'];
            $name_parts = explode(' ', trim($stu['name_en']), 2);
            $message = sms_apply_template($template, [
                'FIRST_NAME'  => $name_parts[0] ?? '',
                'LAST_NAME'   => $name_parts[1] ?? '',
                'SCHOOL_NAME' => $school,
                'CLASS'       => $stu['class_name'] ?? '',
                'ROLL'        => $stu['roll_no'] ?? '',
                'DUE_AMOUNT'  => sd_money($sms_due),
                // DB-র ডিফল্ট টেমপ্লেটে {AMOUNT} ও {NAME} আছে — সেগুলোও রিপ্লেস হবে
                'AMOUNT'      => sd_money($sms_due) . ' (till ' . date('M/Y') . ')',
                'TILL_MONTH'  => date('M/Y'),
                'NAME'        => trim($stu['name_en']),
            ]);
            $result = send_sms($pdo, $stu['guardian_phone'], $message);
            $log_stmt->execute([$stu['id'], $stu['guardian_phone'], $message, $result['success'] ? 'Sent' : 'Failed', $result['response'], $sent_by]);
            $result['success'] ? $sent++ : $failed++;
        }

        if ($sent > 0) {
            $_SESSION['sd_msg'] = "{$sent} জন অভিভাবককে Due SMS পাঠানো হয়েছে।" . ($failed ? " {$failed} জনকে পাঠানো যায়নি।" : '');
        } else {
            $_SESSION['sd_err'] = "কোনো SMS পাঠানো যায়নি ({$failed} জন ফেইল)। SMS Settings পেজে গেটওয়ে ঠিকমতো কনফিগার করা আছে কিনা চেক করুন।";
        }
    }

    header('Location: index.php?page=finance_students_dues&' . http_build_query(array_filter([
        'search' => 1, 'f_session' => $session_id,
        'f_class' => $_POST['back_class'] ?? '', 'f_shift' => $_POST['back_shift'] ?? '',
        'f_section' => $_POST['back_section'] ?? '', 'f_student_id' => $_POST['back_student_id'] ?? '',
    ])));
    exit();
}

// -----------------------------------------------------------
// ফিল্টার (GET)
// -----------------------------------------------------------
$searched   = isset($_GET['search']);
$f_session  = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class    = (int) ($_GET['f_class'] ?? 0);
$f_shift    = (int) ($_GET['f_shift'] ?? 0);
$f_section  = (int) ($_GET['f_section'] ?? 0);
$f_student_id = trim($_GET['f_student_id'] ?? '');

$students = [];
if ($searched && $f_session > 0 && $f_class > 0) {
    $where = ['s.session_id = ?', 's.class_id = ?', "s.status = 'Active'"];
    $params = [$f_session, $f_class];
    if ($f_shift > 0) { $where[] = 's.shift_id = ?'; $params[] = $f_shift; }
    if ($f_section > 0) { $where[] = 's.section_id = ?'; $params[] = $f_section; }
    if ($f_student_id !== '') { $where[] = 's.student_id LIKE ?'; $params[] = '%' . $f_student_id . '%'; }
    branch_apply_filter($where, $params, 's.branch_id');
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_id AS student_code, s.name_en, s.name_bn, s.guardian_phone, s.roll_no, s.class_id, s.photo_path,
                c.class_name, sh.shift_name, sec.section_name
         FROM students s
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         WHERE $where_sql
         ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC"
    );
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

$csrf = htmlspecialchars(csrf_token());
?>
<style>
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar,
        .rp-filter-card, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .card-custom table { width: 100% !important; font-size: 9.5px; }
        .card-custom table th, .card-custom table td { padding: 3px 4px !important; }
        .card-custom table a { color: #000 !important; text-decoration: none !important; }
        .card-custom table thead { display: table-header-group; }
        .card-custom table tr { page-break-inside: avoid; }
    }
    .sd-scroll-table th, .sd-scroll-table td { white-space: nowrap; font-size: 12.5px; }
</style>

<?php if ($sd_msg): ?><div class="alert alert-success py-2"><?php echo sdv($sd_msg); ?></div><?php endif; ?>
<?php if ($sd_err): ?><div class="alert alert-danger py-2"><?php echo sdv($sd_err); ?></div><?php endif; ?>

<div class="card-custom mb-3 rp-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Student's Due Report</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#sdFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_students_dues" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="sdFilterBody" class="collapse show">
        <form method="GET" action="index.php" class="row g-3">
            <input type="hidden" name="page" value="finance_students_dues">
            <input type="hidden" name="search" value="1">
            <div class="col-sm-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="f_session" class="form-select" required>
                    <option value="">Select Session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sdv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="f_class" id="sdClassSelect" class="form-select" required onchange="sdFilterSection()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo sdv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="0">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo sdv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Section</label>
                <select name="f_section" id="sdSectionSelect" class="form-select" <?php echo $f_class ? '' : 'disabled'; ?>>
                    <option value="0"><?php echo $f_class ? 'Select Section' : 'Select Class first'; ?></option>
                    <?php foreach ($sections as $se): ?>
                        <option value="<?php echo (int) $se['id']; ?>" data-class="<?php echo (int) $se['class_id']; ?>" <?php echo $f_section === (int) $se['id'] ? 'selected' : ''; ?> <?php echo (!$f_class || (int) $se['class_id'] !== $f_class) ? 'hidden' : ''; ?>><?php echo sdv($se['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Student ID</label>
                <input type="text" name="f_student_id" class="form-control" value="<?php echo sdv($f_student_id); ?>">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <span class="text-muted small">মোট <?php echo count($students); ?> জন শিক্ষার্থী</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <form method="POST" action="index.php?page=finance_students_dues" id="sdSmsForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo (int) $f_session; ?>">
        <input type="hidden" name="back_class" value="<?php echo (int) $f_class; ?>">
        <input type="hidden" name="back_shift" value="<?php echo (int) $f_shift; ?>">
        <input type="hidden" name="back_section" value="<?php echo (int) $f_section; ?>">
        <input type="hidden" name="back_student_id" value="<?php echo sdv($f_student_id); ?>">

        <div class="d-flex justify-content-end mb-2 no-print">
            <button type="submit" name="send_due_sms" class="btn btn-success btn-sm" onclick="return confirm('সিলেক্টেড (অথবা সবাইকে, কিছু সিলেক্ট না করা থাকলে) Due SMS পাঠানো হবে। আগাবেন?');"><i class="fa-solid fa-paper-plane"></i> Send SMS(All)</button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle sd-scroll-table">
                <thead class="table-light">
                    <tr>
                        <th><input type="checkbox" id="sdCheckAll"></th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Guardian Phone</th>
                        <th>Class</th>
                        <th>Shift</th>
                        <th>Section</th>
                        <th>Roll</th>
                        <th>Admission</th>
                        <?php foreach ($MONTHS as $mname): ?><th><?php echo sdv($mname); ?></th><?php endforeach; ?>
                        <th class="text-success">Till Current<br><small>(Jan To <?php echo sdv($MONTHS[$current_month] ?? ''); ?>)</small></th>
                        <th class="text-danger">Future Payable</th>
                        <th class="text-primary">Total Collection</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students): foreach ($students as $stu): $summary = dr_student_summary($pdo, $stu, $f_session, $fee_heads, $current_month); ?>
                        <tr>
                            <td><input type="checkbox" name="student_ids[]" value="<?php echo (int) $stu['id']; ?>" class="sd-row-check"></td>
                            <td class="fw-bold text-primary"><?php echo sdv($stu['student_code']); ?></td>
                            <td><?php echo sdv($stu['name_en']); ?></td>
                            <td><?php echo sdv($stu['guardian_phone']); ?></td>
                            <td><?php echo sdv($stu['class_name']); ?></td>
                            <td><?php echo sdv($stu['shift_name']); ?></td>
                            <td><?php echo sdv($stu['section_name']); ?></td>
                            <td><?php echo sdv($stu['roll_no']); ?></td>
                            <td class="<?php echo $summary['admission_due'] > 0 ? 'text-danger fw-bold' : ''; ?>"><?php echo sd_money($summary['admission_due']); ?></td>
                            <?php foreach ($MONTHS as $mnum => $mname): ?>
                                <td class="<?php echo $summary['month_dues'][$mnum] > 0 ? 'text-danger fw-bold' : ''; ?>"><?php echo sd_money($summary['month_dues'][$mnum]); ?></td>
                            <?php endforeach; ?>
                            <td class="fw-bold text-success"><?php echo sd_money($summary['till_current']); ?></td>
                            <td class="fw-bold text-danger"><?php echo sd_money($summary['future_payable']); ?></td>
                            <td class="fw-bold text-primary"><?php echo sd_money($summary['total_collection']); ?></td>
                            <td>
                                <a href="index.php?page=finance_students_due_details&student_id=<?php echo (int) $stu['id']; ?>&session_id=<?php echo (int) $f_session; ?>" class="btn btn-sm btn-primary d-block mb-1">Details</a>
                                <button type="submit" name="send_due_sms" formnovalidate onclick="return sdSendSingle(this, <?php echo (int) $stu['id']; ?>);" class="btn btn-sm btn-success d-block">Send SMS</button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="24" class="text-center text-muted py-3">কোনো শিক্ষার্থী পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
function sdFilterSection() {
    const classId = document.getElementById('sdClassSelect').value;
    const select = document.getElementById('sdSectionSelect');
    select.disabled = !classId;
    Array.from(select.options).forEach(opt => {
        if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
        opt.hidden = !classId || opt.dataset.class !== classId;
    });
    select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
    if (select.value && select.value !== '0' && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
        select.value = '0';
    }
}
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('sdCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.sd-row-check').forEach(cb => cb.checked = checkAll.checked);
        });
    }
});
// একটা নির্দিষ্ট রো-এর "Send SMS" চাপলে শুধু ওই স্টুডেন্টের চেকবক্স টেম্পোরারি চেক করে ফর্ম সাবমিট করা হয়
function sdSendSingle(btn, studentId) {
    if (!confirm('এই অভিভাবককে Due SMS পাঠাবেন?')) { return false; }
    document.querySelectorAll('.sd-row-check').forEach(cb => cb.checked = false);
    const form = document.getElementById('sdSmsForm');
    const cb = form.querySelector('.sd-row-check[value="' + studentId + '"]');
    if (cb) { cb.checked = true; }
    return true;
}
</script>
