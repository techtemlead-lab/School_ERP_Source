<?php
// admin/finance_students_ledger.php - Finance Module > Finance Reports > Students Ledger
// একটা Class (+ ঐচ্ছিক Shift/Section/Student ID) সিলেক্ট করলে প্রতিটা স্টুডেন্টের
// Admission + মাস-ওয়াইজ (Jan-Dec) এসেসড/Payable ফি (Waiver বাদ দিয়ে, কিন্তু Paid/Due নির্বিশেষে)
// একটা ওয়াইড লেজার টেবিল আকারে দেখায়।

require_once __DIR__ . '/finance_due_report_common.php';

function slv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sl_money($n): string { return number_format((float) $n, 2); }

dr_ensure_tables($pdo);
$MONTHS = dr_months();

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

$searched  = isset($_GET['search']);
$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);
$f_shift   = (int) ($_GET['f_shift'] ?? 0);
$f_section = (int) ($_GET['f_section'] ?? 0);
$f_student_id = trim($_GET['f_student_id'] ?? '');
// কোন অ্যামাউন্ট দেখাবে: paid = Collected (ডিফল্ট), payable = Payable (Waiver বাদে), due = Due
$view_labels = ['paid' => 'Collected', 'payable' => 'Payable', 'due' => 'Due'];
$f_view = $_GET['f_view'] ?? 'paid';
if (!isset($view_labels[$f_view])) { $f_view = 'paid'; }
$view_key = $f_view === 'due' ? 'dues' : $f_view; // dr_resolve_period আইটেমের কী: paid / payable / dues

$class_name = '';
$shift_name = '';
$section_name = '';
$session_name = '';
$rows = [];

if ($searched && $f_session > 0 && $f_class > 0) {
    foreach ($classes as $cl) { if ((int) $cl['id'] === $f_class) { $class_name = $cl['class_name']; break; } }
    if ($f_shift > 0) { foreach ($shifts as $sh) { if ((int) $sh['id'] === $f_shift) { $shift_name = $sh['shift_name']; break; } } }
    if ($f_section > 0) { foreach ($sections as $se) { if ((int) $se['id'] === $f_section) { $section_name = $se['section_name']; break; } } }
    foreach ($sessions as $ss) { if ((int) $ss['id'] === $f_session) { $session_name = $ss['session_name']; break; } }

    $where = ['s.session_id = ?', 's.class_id = ?', "s.status = 'Active'"];
    $params = [$f_session, $f_class];
    if ($f_shift > 0) { $where[] = 's.shift_id = ?'; $params[] = $f_shift; }
    if ($f_section > 0) { $where[] = 's.section_id = ?'; $params[] = $f_section; }
    if ($f_student_id !== '') { $where[] = 's.student_id LIKE ?'; $params[] = '%' . $f_student_id . '%'; }
    branch_apply_filter($where, $params, 's.branch_id');
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT s.id, s.student_id AS student_code, s.name_en, s.roll_no, s.class_id, sec.section_name
         FROM students s
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         WHERE $where_sql
         ORDER BY CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC"
    );
    $stmt->execute($params);
    $student_list = $stmt->fetchAll();

    foreach ($student_list as $stu) {
        $adm_items = dr_resolve_period($pdo, (int) $stu['id'], $f_session, (int) $stu['class_id'], 0, $fee_heads);
        $admission_payable = array_sum(array_column($adm_items, $view_key));

        $month_payables = [];
        $total = $admission_payable;
        foreach ($MONTHS as $m => $mname) {
            $items = dr_resolve_period($pdo, (int) $stu['id'], $f_session, (int) $stu['class_id'], $m, $fee_heads);
            $payable = array_sum(array_column($items, $view_key));
            $month_payables[$m] = $payable;
            $total += $payable;
        }

        $rows[] = [
            'student_code' => $stu['student_code'], 'name' => $stu['name_en'], 'section' => $stu['section_name'],
            'roll' => $stu['roll_no'], 'admission' => $admission_payable, 'months' => $month_payables, 'total' => $total,
        ];
    }
}

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => '', 'phone_number' => '', 'logo_path' => null]; }
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
        @page { size: A4 landscape; margin: 8mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar,
        .sl-filter-card, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .table-responsive { overflow: visible !important; }
        .sl-scroll-table { width: 100% !important; }
        .sl-scroll-table th, .sl-scroll-table td { font-size: 9.5px !important; padding: 3px 4px !important; }
        .sl-scroll-table a { color: #000 !important; text-decoration: none !important; }
        .sl-scroll-table thead { display: table-header-group; }
        .sl-scroll-table tr { page-break-inside: avoid; }
    }
    .sl-scroll-table th, .sl-scroll-table td { white-space: nowrap; font-size: 12.5px; }
</style>

<div class="card-custom mb-3 sl-filter-card">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-secondary">Student's Ledger Report</h6>
        <div>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#slFilterBody"><i class="fa-solid fa-chevron-up"></i></button>
            <a href="index.php?page=finance_students_ledger" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></a>
        </div>
    </div>

    <div id="slFilterBody" class="collapse show">
        <form method="GET" action="index.php" class="row g-3">
            <input type="hidden" name="page" value="finance_students_ledger">
            <input type="hidden" name="search" value="1">
            <div class="col-sm-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="f_session" class="form-select" required>
                    <option value="">Select Session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo slv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="f_class" id="slClassSelect" class="form-select" required onchange="slFilterSection()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?php echo (int) $cl['id']; ?>" <?php echo $f_class === (int) $cl['id'] ? 'selected' : ''; ?>><?php echo slv($cl['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="0">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo $f_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo slv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Section</label>
                <select name="f_section" id="slSectionSelect" class="form-select" <?php echo $f_class ? '' : 'disabled'; ?>>
                    <option value="0"><?php echo $f_class ? 'Select Section' : 'Select Class first'; ?></option>
                    <?php foreach ($sections as $se): ?>
                        <option value="<?php echo (int) $se['id']; ?>" data-class="<?php echo (int) $se['class_id']; ?>" <?php echo $f_section === (int) $se['id'] ? 'selected' : ''; ?> <?php echo (!$f_class || (int) $se['class_id'] !== $f_class) ? 'hidden' : ''; ?>><?php echo slv($se['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-bold">Student ID</label>
                <input type="text" name="f_student_id" class="form-control" value="<?php echo slv($f_student_id); ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label fw-bold">Show</label>
                <select name="f_view" class="form-select">
                    <?php foreach ($view_labels as $vk => $vl): ?>
                        <option value="<?php echo slv($vk); ?>" <?php echo $f_view === $vk ? 'selected' : ''; ?>><?php echo slv($vl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-9 text-end align-self-end">
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<script>
function slFilterSection() {
    const classId = document.getElementById('slClassSelect').value;
    const select = document.getElementById('slSectionSelect');
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
</script>

<?php if ($searched): ?>
<div class="card-custom">
    <div class="d-flex justify-content-end mb-3 no-print">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>

    <div class="text-center mb-3">
        <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo slv($inst['logo_path']); ?>" style="max-width:60px;max-height:60px;"><br><?php endif; ?>
        <h4 class="fw-bold text-primary mb-1"><?php echo slv($inst['institute_name']); ?></h4>
        <?php if ($branch && !empty($branch['address'])): ?>
            <div class="small text-muted">(<?php echo slv($branch['address']); ?><?php echo $branch['city'] ? ', ' . slv($branch['city']) : ''; ?><?php echo $branch['zip_code'] ? '-' . slv($branch['zip_code']) : ''; ?>)</div>
        <?php endif; ?>
        <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
            <div class="small">Phone: <?php echo slv($inst['phone_number'] ?: $branch['phone']); ?></div>
        <?php endif; ?>
        <h5 class="text-secondary mt-3 mb-1">Student's Ledger Report<?php echo $session_name !== '' ? ' - ' . slv($session_name) : ''; ?> (<?php echo slv($view_labels[$f_view]); ?>)</h5>
        <h6 class="fw-bold mb-2">
            <u>Class : <?php echo slv($class_name); ?> &middot; <?php echo $f_section > 0 ? 'Section : ' . slv($section_name) : 'All Sections'; ?></u>
        </h6>
        <?php if ($f_shift > 0): ?><h6 class="text-muted"><u>Shift : <?php echo slv($shift_name); ?></u></h6><?php endif; ?>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered align-middle sl-scroll-table">
            <thead class="table-light">
                <tr>
                    <th>Sl.</th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Section</th>
                    <th>Roll</th>
                    <th>Admission</th>
                    <?php foreach ($MONTHS as $mname): ?><th><?php echo slv($mname); ?></th><?php endforeach; ?>
                    <th class="fw-bold">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): $srl = 1; $col_totals = array_fill(0, 12, 0.0); $adm_total = 0.0; $grand_total = 0.0; foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td class="fw-bold text-primary"><?php echo slv($r['student_code']); ?></td>
                        <td><?php echo slv($r['name']); ?></td>
                        <td><?php echo slv($r['section']); ?></td>
                        <td><?php echo slv($r['roll']); ?></td>
                        <td><?php echo sl_money($r['admission']); ?></td>
                        <?php $mi = 0; foreach ($MONTHS as $m => $mname): ?>
                            <td><?php echo sl_money($r['months'][$m]); ?></td>
                        <?php $col_totals[$mi] += $r['months'][$m]; $mi++; endforeach; ?>
                        <td class="fw-bold"><?php echo sl_money($r['total']); ?></td>
                    </tr>
                    <?php $adm_total += $r['admission']; $grand_total += $r['total']; ?>
                <?php endforeach; else: ?>
                    <tr><td colspan="19" class="text-center text-muted py-3">কোনো শিক্ষার্থী পাওয়া যায়নি।</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot>
                    <tr class="fw-bold table-light">
                        <td colspan="5" class="text-end">Total :</td>
                        <td><?php echo sl_money($adm_total); ?></td>
                        <?php foreach ($col_totals as $ct): ?><td><?php echo sl_money($ct); ?></td><?php endforeach; ?>
                        <td><?php echo sl_money($grand_total); ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>
