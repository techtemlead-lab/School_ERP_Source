<?php
/**
 * teacher/routine_view.php - Teacher Portal > Routine Module > My Class Routine
 * -----------------------------------------------------------
 * আগে এখানে admin/routine_view.php সরাসরি include করা হতো — সেখানে ক্লাস/সেকশন বাছাই করে
 * পুরো ক্লাসের রুটিন দেখতে হতো, যেটা টিচারের জন্য দরকারি না। এখন এই পেজ সরাসরি লগইন করা
 * টিচারের নিজের রুটিনই দেখায় — কোন দিনে কোন পিরিয়ডে কোন ক্লাসে কোন সাবজেক্ট:
 *
 *      Period 1 › One (Tista) › Bangla
 *
 * Class/Section/Day — তিনটাই অপশনাল ফিল্টার (কিছু না বাছাই করলে পুরো সপ্তাহের রুটিন)।
 * উপরে দৈনিক ও সাপ্তাহিক সামারি (মোট কত ক্লাস, কতটা সাবজেক্ট) দেখানো হয়।
 *
 * Period নাম্বার: routines টেবিলে আলাদা কোনো period কলাম নেই, তাই প্রতিটা
 * Class+Section+Shift এর সেই দিনের সব রুটিনের Start Time অনুযায়ী ক্রম করে পিরিয়ড
 * নাম্বার বের করা হয় — অর্থাৎ "Period 2" মানে ওই ক্লাসের সেদিনের ২য় পিরিয়ড
 * (শুধু এই টিচারের ২য় ক্লাস না)।
 */

function trv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function trv_time($t): string { return $t ? date('h:i A', strtotime($t)) : ''; }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NULL,
        shift_id INT NULL,
        section_id INT NOT NULL,
        routine_days VARCHAR(100) NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        has_break TINYINT(1) NOT NULL DEFAULT 0,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_lookup (class_id, shift_id, section_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$today_day = date('l');

$teacher_pk = (int) $teacher['id'];
$inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: '';

// ---------- অপশনাল ফিল্টার ----------
$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$sel_day     = in_array($_GET['day'] ?? '', $week_days, true) ? $_GET['day'] : '';

// ---------- এই টিচারের সব রুটিন ----------
$where = ['routines.teacher_id = ?', "routines.status = 'Active'"];
$params = [$teacher_pk];
if ($sel_class)   { $where[] = 'routines.class_id = ?';   $params[] = $sel_class; }
if ($sel_section) { $where[] = 'routines.section_id = ?'; $params[] = $sel_section; }
if (branch_current_id() !== null) { $where[] = 'routines.branch_id = ?'; $params[] = branch_current_id(); }

$stmt = $pdo->prepare(
    "SELECT routines.*, academic_subjects.subject_name, academic_classes.class_name,
            academic_shifts.shift_name, academic_sections.section_name
     FROM routines
     LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
     LEFT JOIN academic_classes ON academic_classes.id = routines.class_id
     LEFT JOIN academic_shifts ON academic_shifts.id = routines.shift_id
     LEFT JOIN academic_sections ON academic_sections.id = routines.section_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY routines.start_time ASC"
);
$stmt->execute($params);
$my_rows = $stmt->fetchAll();

// ---------- Period নাম্বার বের করা ----------
// এই টিচার যেসব Class+Section+Shift এ ক্লাস নেন, সেগুলোর সব (সব টিচারের) রুটিন নিয়ে
// দিন অনুযায়ী Start Time ক্রমে সাজিয়ে পিরিয়ড নাম্বার ঠিক করা হয়।
$combo_keys = [];
foreach ($my_rows as $r) {
    $combo_keys[(int) $r['class_id'] . '|' . (int) $r['section_id'] . '|' . (int) $r['shift_id']] = [
        'class_id' => (int) $r['class_id'], 'section_id' => (int) $r['section_id'], 'shift_id' => (int) $r['shift_id'],
    ];
}

$period_map = []; // [day][comboKey][start_time] = period no
if ($combo_keys) {
    $all_stmt = $pdo->query("SELECT class_id, section_id, shift_id, routine_days, start_time FROM routines WHERE status = 'Active'");
    $slot_bag = []; // [day][comboKey] = [start_time, ...]
    foreach ($all_stmt->fetchAll() as $r) {
        $key = (int) $r['class_id'] . '|' . (int) $r['section_id'] . '|' . (int) $r['shift_id'];
        if (!isset($combo_keys[$key])) { continue; }
        foreach (explode(',', (string) $r['routine_days']) as $d) {
            $d = trim($d);
            if (!in_array($d, $week_days, true)) { continue; }
            $slot_bag[$d][$key][] = (string) $r['start_time'];
        }
    }
    foreach ($slot_bag as $day => $combos) {
        foreach ($combos as $key => $times) {
            $times = array_values(array_unique($times));
            sort($times);
            foreach ($times as $i => $t) { $period_map[$day][$key][$t] = $i + 1; }
        }
    }
}

// ---------- দিন অনুযায়ী সাজানো ----------
$day_entries = [];
foreach ($week_days as $d) { $day_entries[$d] = []; }

foreach ($my_rows as $r) {
    $key = (int) $r['class_id'] . '|' . (int) $r['section_id'] . '|' . (int) $r['shift_id'];
    foreach (explode(',', (string) $r['routine_days']) as $d) {
        $d = trim($d);
        if (!isset($day_entries[$d])) { continue; }
        $row = $r;
        $row['period_no'] = $period_map[$d][$key][(string) $r['start_time']] ?? 0;
        $day_entries[$d][] = $row;
    }
}
foreach ($day_entries as $d => $list) {
    usort($list, fn($a, $b) => ($a['period_no'] <=> $b['period_no']) ?: strcmp((string) $a['start_time'], (string) $b['start_time']));
    $day_entries[$d] = $list;
}

// ---------- সামারি ----------
$weekly_total = 0;
$weekly_subjects = [];
$weekly_classes = [];
$day_stats = [];
foreach ($week_days as $d) {
    $subs = [];
    $cls = [];
    foreach ($day_entries[$d] as $e) {
        $subs[(int) $e['subject_id']] = true;
        $cls[(int) $e['class_id'] . '-' . (int) $e['section_id']] = true;
        $weekly_subjects[(int) $e['subject_id']] = true;
        $weekly_classes[(int) $e['class_id'] . '-' . (int) $e['section_id']] = true;
    }
    $day_stats[$d] = ['classes' => count($day_entries[$d]), 'subjects' => count($subs), 'sections' => count($cls)];
    $weekly_total += count($day_entries[$d]);
}
$today_total = $day_stats[$today_day]['classes'] ?? 0;

// Day ফিল্টার শুধু নিচের টেবিলে কাজ করে — উপরের সামারি কার্ড সবসময় পুরো সপ্তাহের হিসাব দেখায়
$shown_total = $sel_day !== '' ? ($day_stats[$sel_day]['classes'] ?? 0) : $weekly_total;

// ---------- ফিল্টারের ড্রপডাউন (শুধু এই টিচারের ক্লাস/সেকশনগুলোই) ----------
$my_classes = [];
$my_sections = [];
$all_stmt2 = $pdo->prepare(
    "SELECT DISTINCT routines.class_id, routines.section_id, academic_classes.class_name, academic_sections.section_name
     FROM routines
     LEFT JOIN academic_classes ON academic_classes.id = routines.class_id
     LEFT JOIN academic_sections ON academic_sections.id = routines.section_id
     WHERE routines.teacher_id = ? AND routines.status = 'Active'"
);
$all_stmt2->execute([$teacher_pk]);
foreach ($all_stmt2->fetchAll() as $r) {
    if ($r['class_id']) { $my_classes[(int) $r['class_id']] = $r['class_name']; }
    if ($r['section_id']) { $my_sections[(int) $r['section_id']] = ['name' => $r['section_name'], 'class_id' => (int) $r['class_id']]; }
}
asort($my_classes);
?>
<style>
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        .no-print { display: none !important; }
        .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
        body { background: #fff !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin-bottom: 10px !important; }
        .table-responsive { overflow: visible !important; }
        table.table-bordered, table.table-bordered th, table.table-bordered td { border: 1px solid #000 !important; }
        thead.table-dark, thead.table-dark th { background: #e2e8f0 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table tr { page-break-inside: avoid; }
    }
    .trv-period { border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; background:#f8fafc; }
    .trv-period .pno { display:inline-block; min-width:74px; font-weight:700; color:#1e3a8a; }
    .trv-today { background:#ecfdf5 !important; }
</style>

<!-- ================= সামারি ================= -->
<div class="row g-3 mb-3">
    <div class="col-lg-3 col-6">
        <div class="top-stat-box" style="background:#1e3a8a;">
            <div><h3 style="font-size:20px;"><?php echo $today_total; ?></h3><p>আজকের ক্লাস (<?php echo $today_day; ?>)</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="top-stat-box" style="background:#0e7490;">
            <div><h3 style="font-size:20px;"><?php echo $weekly_total; ?></h3><p>সাপ্তাহিক মোট ক্লাস</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-calendar-week"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="top-stat-box" style="background:#10b981;">
            <div><h3 style="font-size:20px;"><?php echo count($weekly_subjects); ?></h3><p>মোট সাবজেক্ট</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-book"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="top-stat-box" style="background:#1e293b;">
            <div><h3 style="font-size:20px;"><?php echo count($weekly_classes); ?></h3><p>মোট ক্লাস / সেকশন</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-chalkboard"></i></div>
        </div>
    </div>
</div>

<!-- ================= ফিল্টার (সব অপশনাল) ================= -->
<div class="card-custom no-print">
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="routine_view">
        <div class="col-md-3">
            <label class="form-label fw-bold">Class <span class="text-muted fw-normal" style="font-size:11.5px;">(ঐচ্ছিক)</span></label>
            <select name="class_id" id="trvClass" class="form-select" onchange="trvFilterSections()">
                <option value="">All Class</option>
                <?php foreach ($my_classes as $cid => $cname): ?>
                    <option value="<?php echo $cid; ?>" <?php echo $sel_class === $cid ? 'selected' : ''; ?>><?php echo trv($cname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Section <span class="text-muted fw-normal" style="font-size:11.5px;">(ঐচ্ছিক)</span></label>
            <select name="section_id" id="trvSection" class="form-select">
                <option value="">All Section</option>
                <?php foreach ($my_sections as $sid => $s): ?>
                    <option value="<?php echo $sid; ?>" data-class="<?php echo $s['class_id']; ?>" <?php echo $sel_section === $sid ? 'selected' : ''; ?>><?php echo trv($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Day <span class="text-muted fw-normal" style="font-size:11.5px;">(ঐচ্ছিক)</span></label>
            <select name="day" class="form-select">
                <option value="">All Day</option>
                <?php foreach ($week_days as $d): ?>
                    <option value="<?php echo $d; ?>" <?php echo $sel_day === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;"><i class="fa-solid fa-filter me-1"></i> View</button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <?php if ($sel_class || $sel_section || $sel_day !== ''): ?>
                <a href="index.php?page=routine_view" class="btn btn-danger">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ================= রুটিন ================= -->
<div class="card-custom mt-3">
    <div class="text-center mb-3">
        <h5 class="fw-bold mb-0"><?php echo trv($inst_name); ?></h5>
        <p class="text-muted mb-0">
            My Class Routine — <?php echo trv($teacher['name']); ?>
            <?php if ($teacher['employee_id']): ?> (ID: <?php echo trv($teacher['employee_id']); ?>)<?php endif; ?>
        </p>
    </div>

    <?php if ($shown_total === 0): ?>
        <h6 class="text-muted text-center py-4 mb-0">
            <?php echo ($sel_class || $sel_section || $sel_day !== '') ? 'এই ফিল্টারে কোনো রুটিন পাওয়া যায়নি।' : 'আপনার নামে এখনো কোনো ক্লাস রুটিন সেট করা হয়নি।'; ?>
        </h6>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead class="table-dark">
                    <tr><th style="width:150px;">Day</th><th>Periods</th><th style="width:110px;" class="text-center">Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($week_days as $day): ?>
                        <?php if ($sel_day !== '' && $day !== $sel_day) { continue; } ?>
                        <?php if (!$day_entries[$day]) { continue; } ?>
                        <tr class="<?php echo $day === $today_day ? 'trv-today' : ''; ?>">
                            <td class="fw-bold">
                                <?php echo $day; ?>
                                <?php if ($day === $today_day): ?><br><span class="badge bg-success">আজ</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($day_entries[$day] as $e): ?>
                                        <div class="trv-period">
                                            <span class="pno">Period <?php echo $e['period_no'] ?: '—'; ?></span>
                                            <span class="text-muted">›</span>
                                            <strong><?php echo trv($e['class_name'] ?: '—'); ?></strong><?php if ($e['section_name']): ?> <span class="text-muted">(<?php echo trv($e['section_name']); ?>)</span><?php endif; ?>
                                            <span class="text-muted">›</span>
                                            <strong style="color:#0f766e;"><?php echo trv($e['subject_name'] ?: '—'); ?></strong>
                                            <span class="text-muted ms-2" style="font-size:12.5px;">
                                                <i class="fa-regular fa-clock"></i> <?php echo trv_time($e['start_time']); ?> - <?php echo trv_time($e['end_time']); ?>
                                                <?php if (!empty($e['shift_name'])): ?> · <?php echo trv($e['shift_name']); ?><?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?php echo $day_stats[$day]['classes']; ?> ক্লাস</span><br>
                                <span class="badge bg-secondary mt-1"><?php echo $day_stats[$day]['subjects']; ?> সাবজেক্ট</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ================= সাপ্তাহিক সামারি টেবিল ================= -->
<?php if ($weekly_total > 0): ?>
<div class="card-custom mt-3">
    <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-simple me-2 text-primary"></i>সামারি (দৈনিক ও সাপ্তাহিক)</h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr><th>Day</th><th class="text-center">মোট ক্লাস</th><th class="text-center">সাবজেক্ট</th><th class="text-center">ক্লাস/সেকশন</th></tr>
            </thead>
            <tbody>
                <?php foreach ($week_days as $day): ?>
                    <tr class="<?php echo $day === $today_day ? 'trv-today' : ''; ?>">
                        <td class="fw-bold"><?php echo $day; ?><?php echo $day === $today_day ? ' <span class="badge bg-success">আজ</span>' : ''; ?></td>
                        <td class="text-center"><?php echo $day_stats[$day]['classes']; ?></td>
                        <td class="text-center"><?php echo $day_stats[$day]['subjects']; ?></td>
                        <td class="text-center"><?php echo $day_stats[$day]['sections']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td>সাপ্তাহিক মোট</td>
                    <td class="text-center"><?php echo $weekly_total; ?></td>
                    <td class="text-center"><?php echo count($weekly_subjects); ?></td>
                    <td class="text-center"><?php echo count($weekly_classes); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="text-muted mt-2 mb-0" style="font-size:12px;">
        সাপ্তাহিক "সাবজেক্ট" ও "ক্লাস/সেকশন" — একই সাবজেক্ট/সেকশন সপ্তাহে একাধিকবার থাকলেও একবারই গোনা হয়েছে।
    </p>
</div>
<?php endif; ?>

<script>
// Class বাছাই করলে শুধু সেই ক্লাসের সেকশনগুলোই দেখাবে (কিছু না বাছাই করলে সব)
function trvFilterSections() {
    var cls = document.getElementById('trvClass').value;
    var sec = document.getElementById('trvSection');
    for (var i = 0; i < sec.options.length; i++) {
        var opt = sec.options[i];
        if (!opt.value) { continue; }
        var show = !cls || opt.getAttribute('data-class') === cls;
        opt.hidden = !show;
        if (!show && opt.selected) { sec.value = ''; }
    }
}
trvFilterSections();
</script>
