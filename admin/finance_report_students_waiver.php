<?php
// admin/finance_report_students_waiver.php - Finance Module > Finance Reports > Students Waiver
// Session (আবশ্যক) + ঐচ্ছিক Class/Shift/Section/Student/Month/Fee Type দিয়ে ফিল্টার করে
// finance_student_waivers টেবিল থেকে Active waiver-গুলোর একটা প্রিন্টযোগ্য লিস্ট দেখায়।

require_once __DIR__ . '/finance_due_report_common.php';

function wrv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function wr_amt($n): string
{
    $n = (float) $n;
    return (fmod($n, 1.0) === 0.0) ? number_format($n, 0) : number_format($n, 2);
}

dr_ensure_tables($pdo);
$MONTHS = dr_months();

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (finance_student_waiver.php এর সাথে শেয়ার্ড)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_student_waivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        month TINYINT NOT NULL,
        ac_head_id INT NOT NULL,
        waiver_type ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Fixed',
        waiver_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lookup (student_id, session_id, month, ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// পুরনো ইনস্টলেশনে folio_no কলাম না থাকলে যোগ করে দেওয়া (fees collection পেজগুলোর সাথে শেয়ার্ড)
$wr_sw_cols = $pdo->query("SHOW COLUMNS FROM finance_student_waivers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('folio_no', $wr_sw_cols, true)) {
    $pdo->exec("ALTER TABLE finance_student_waivers ADD COLUMN folio_no VARCHAR(50) NULL AFTER waiver_value");
}

$sessions  = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts    = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections  = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();
$frw_where = ["status = 'Active'"]; $frw_params = [];
branch_apply_filter($frw_where, $frw_params);
$frw_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en, session_id, class_id, shift_id, section_id FROM students WHERE " . implode(' AND ', $frw_where) . " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC");
$frw_stmt->execute($frw_params);
$students  = $frw_stmt->fetchAll();

$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

// -----------------------------------------------------------
// ফিল্টার
// -----------------------------------------------------------
$searched   = isset($_GET['search']);
$f_session  = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class    = (int) ($_GET['f_class'] ?? 0);
$f_shift    = (int) ($_GET['f_shift'] ?? 0);
$f_section  = (int) ($_GET['f_section'] ?? 0);
$f_student  = (int) ($_GET['f_student'] ?? 0);
$f_month    = (int) ($_GET['f_month'] ?? 0);
$f_fee_type = (int) ($_GET['f_fee_type'] ?? 0);

$rows = [];
$session_name = '';
$grand_total = 0.0;

if ($searched && $f_session > 0) {
    foreach ($sessions as $s) { if ((int) $s['id'] === $f_session) { $session_name = $s['session_name']; break; } }

    $where  = ['w.session_id = ?', "w.status = 'Active'"];
    $params = [$f_session];

    if ($f_class > 0)    { $where[] = 'w.class_id = ?';   $params[] = $f_class; }
    if ($f_shift > 0)    { $where[] = 's.shift_id = ?';   $params[] = $f_shift; }
    if ($f_section > 0)  { $where[] = 's.section_id = ?'; $params[] = $f_section; }
    if ($f_student > 0)  { $where[] = 'w.student_id = ?'; $params[] = $f_student; }
    if ($f_month > 0)    { $where[] = 'w.month = ?';      $params[] = $f_month; }
    if ($f_fee_type > 0) { $where[] = 'w.ac_head_id = ?'; $params[] = $f_fee_type; }
    branch_apply_filter($where, $params, 's.branch_id');

    $where_sql = implode(' AND ', $where);

    $sql = "SELECT w.*, s.student_id AS student_code, s.name_en, s.roll_no, s.photo_path,
                   c.class_name, sh.shift_name, sec.section_name,
                   fh.ac_code, fh.ac_head
            FROM finance_student_waivers w
            JOIN students s ON s.id = w.student_id
            LEFT JOIN academic_classes c ON c.id = w.class_id
            LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
            LEFT JOIN academic_sections sec ON sec.id = s.section_id
            LEFT JOIN finance_account_heads fh ON fh.id = w.ac_head_id
            WHERE $where_sql
            ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
?>
<style>
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar,
        .wr-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .wr-report-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
        .wr-report-wrap .table-responsive { overflow: visible !important; }
        .wr-report-wrap table { width: 100% !important; font-size: 11px; }
        .wr-report-wrap th, .wr-report-wrap td { padding: 3px 5px !important; }
        .wr-report-wrap a, .wr-report-wrap .btn { color: #000 !important; text-decoration: none !important; border: none !important; padding: 0 !important; }
        .wr-avatar, .wr-avatar-fallback { display: none !important; }
        .wr-report-wrap thead { display: table-header-group; }
        .wr-report-wrap tr { page-break-inside: avoid; }
    }
    .wr-avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
    .wr-avatar-fallback { width: 32px; height: 32px; border-radius: 50%; background: #e9ecef; display: inline-flex; align-items: center; justify-content: center; color: #6c757d; }
</style>

<div class="card-custom mb-3 wr-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Waived Student List</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#wrFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_report_students_waiver" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="wrFilterBody" class="collapse show">
        <form method="GET" action="index.php" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="finance_report_students_waiver">
            <input type="hidden" name="search" value="1">

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="f_session" id="wrSession" class="form-select" required>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo wrv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Class Name</label>
                <div class="col-sm-6">
                    <select name="f_class" id="wrClass" class="form-select" onchange="wrFilterSection(); wrFilterStudents();">
                        <option value="0">Select Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo wrv($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Shift</label>
                <div class="col-sm-6">
                    <select name="f_shift" id="wrShift" class="form-select" onchange="wrFilterStudents()">
                        <option value="0">Select Shift</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo wrv($sh['shift_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Section Name</label>
                <div class="col-sm-6">
                    <select name="f_section" id="wrSection" class="form-select" <?php echo $f_class ? '' : 'disabled'; ?> onchange="wrFilterStudents()">
                        <option value="0"><?php echo $f_class ? 'Select Section' : 'Select Class first'; ?></option>
                        <?php foreach ($sections as $se): ?>
                            <option value="<?php echo (int) $se['id']; ?>" data-class="<?php echo (int) $se['class_id']; ?>" data-shift="<?php echo (int) $se['shift_id']; ?>" <?php echo $f_section === (int) $se['id'] ? 'selected' : ''; ?> <?php echo (!$f_class || (int) $se['class_id'] !== $f_class) ? 'hidden' : ''; ?>><?php echo wrv($se['section_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Student Name</label>
                <div class="col-sm-6">
                    <select name="f_student" id="wrStudent" class="form-select">
                        <option value="0">Select Student</option>
                        <?php foreach ($students as $st): ?>
                            <option value="<?php echo (int) $st['id']; ?>"
                                data-session="<?php echo (int) $st['session_id']; ?>"
                                data-class="<?php echo (int) $st['class_id']; ?>"
                                data-shift="<?php echo (int) $st['shift_id']; ?>"
                                data-section="<?php echo (int) $st['section_id']; ?>"
                                <?php echo $f_student === (int) $st['id'] ? 'selected' : ''; ?>>
                                <?php echo wrv($st['student_id'] . ' - ' . $st['name_en'] . ' - Roll ' . $st['roll_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-2 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Month</label>
                <div class="col-sm-6">
                    <select name="f_month" class="form-select">
                        <option value="0">Select Month</option>
                        <?php foreach ($MONTHS as $mid => $mname): ?>
                            <option value="<?php echo (int) $mid; ?>" <?php echo $f_month === (int) $mid ? 'selected' : ''; ?>><?php echo wrv($mname); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-sm-end">Fee Type</label>
                <div class="col-sm-6">
                    <select name="f_fee_type" class="form-select">
                        <option value="0">Select Fee Type</option>
                        <?php foreach ($fee_heads as $fh): ?>
                            <option value="<?php echo (int) $fh['id']; ?>" <?php echo $f_fee_type === (int) $fh['id'] ? 'selected' : ''; ?>><?php echo wrv(trim($fh['ac_code'] . ' ' . $fh['ac_head'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-9 offset-sm-3 text-sm-start">
                    <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function wrFilterSection() {
        const classId = document.getElementById('wrClass').value;
        const select = document.getElementById('wrSection');
        select.disabled = (classId === '0' || !classId);
        Array.from(select.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            opt.hidden = (classId === '0' || !classId) || opt.dataset.class !== classId;
        });
        select.options[0].textContent = (classId === '0' || !classId) ? 'Select Class first' : 'Select Section';
        if (select.value && select.value !== '0' && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
            select.value = '0';
        }
    }
    function wrFilterStudents() {
        const classId = document.getElementById('wrClass').value;
        const shiftId = document.getElementById('wrShift').value;
        const sectionId = document.getElementById('wrSection').value;
        const studentSelect = document.getElementById('wrStudent');
        const currentVal = studentSelect.value;
        let stillVisible = false;
        Array.from(studentSelect.options).forEach(opt => {
            if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
            const classOk = (classId === '0' || !classId) || opt.dataset.class === classId;
            const shiftOk = (shiftId === '0' || !shiftId) || opt.dataset.shift === shiftId;
            const sectionOk = (sectionId === '0' || !sectionId) || opt.dataset.section === sectionId;
            opt.hidden = !(classOk && shiftOk && sectionOk);
            if (opt.value === currentVal && !opt.hidden) { stillVisible = true; }
        });
        if (!stillVisible) { studentSelect.value = '0'; }
    }
    wrFilterSection();
    wrFilterStudents();
</script>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="wr-report-wrap" style="max-width:1000px;margin:0 auto;">
        <div class="text-center mb-3">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo wrv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
            <h4 class="fw-bold text-primary mb-1"><?php echo wrv($inst['institute_name']); ?></h4>
            <?php if ($branch && !empty($branch['address'])): ?>
                <div class="small text-muted">(<?php echo wrv($branch['address']); ?><?php echo $branch['city'] ? ', ' . wrv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . wrv($branch['zip_code']) : ''; ?>)</div>
            <?php endif; ?>
            <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                <div class="small">Phone: <?php echo wrv($inst['phone_number'] ?: $branch['phone']); ?></div>
            <?php endif; ?>
            <h5 class="text-secondary mt-3 mb-1">Waived Student list of session - <?php echo wrv($session_name); ?></h5>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Roll</th>
                        <th>Shift</th>
                        <th>Section</th>
                        <th>Month</th>
                        <th>Fees Type</th>
                        <th>Waiver Type</th>
                        <th class="text-end">Amount</th>
                        <th class="no-print">Folio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="fw-bold text-primary"><?php echo wrv($r['student_code']); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (!empty($r['photo_path'])): ?>
                                        <img src="../<?php echo wrv($r['photo_path']); ?>" class="wr-avatar" alt="">
                                    <?php else: ?>
                                        <span class="wr-avatar-fallback"><i class="fa-solid fa-user"></i></span>
                                    <?php endif; ?>
                                    <span><?php echo wrv($r['name_en']); ?></span>
                                </div>
                            </td>
                            <td><?php echo wrv($r['class_name'] ?: '—'); ?></td>
                            <td><?php echo wrv($r['roll_no']); ?></td>
                            <td><?php echo wrv($r['shift_name'] ?: '—'); ?></td>
                            <td><?php echo wrv($r['section_name'] ?: '—'); ?></td>
                            <td><?php echo wrv($MONTHS[(int) $r['month']] ?? '—'); ?></td>
                            <td><?php echo wrv(trim(($r['ac_code'] ?? '') . ' ' . ($r['ac_head'] ?? '—'))); ?></td>
                            <td><?php echo wrv($r['waiver_type']); ?></td>
                            <td class="text-end fw-bold"><?php echo wr_amt($r['waiver_value']); ?></td>
                            <td class="no-print">
                                <?php if (!empty($r['folio_no'])): ?>
                                    <a href="print_money_receipt.php?folio_no=<?php echo urlencode($r['folio_no']); ?>&back=finance_report_students_waiver" target="_blank" class="btn btn-sm btn-outline-primary" title="Money Receipt দেখুন"><?php echo wrv($r['folio_no']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php $grand_total += (float) $r['waiver_value']; ?>
                    <?php endforeach; else: ?>
                        <tr><td colspan="12" class="text-center text-muted py-3">কোনো Waiver পাওয়া যায়নি।</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="10" class="text-end">Total :</td>
                            <td class="text-end"><?php echo wr_amt($grand_total); ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
