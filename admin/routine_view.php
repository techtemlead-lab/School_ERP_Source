<?php
// admin/routine_view.php - View / Print Routine (আগের ভাঙা ভার্সন ঠিক করে নতুন করে বানানো হলো)
function rvv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_time_rv($t): string { return $t ? date('h:i A', strtotime($t)) : ''; }

// টেবিল না থাকলে অটো তৈরি (routine_general.php ও routine_dynamic.php একই টেবিল শেয়ার করে)
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

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();

$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$routine_type = ($_GET['routine_type'] ?? 'top') === 'inner' ? 'inner' : 'top';
$is_searched = isset($_GET['view_routine']);

$day_entries = [];
$class_name = $shift_name = $section_name = '';

// শিফট Add/Edit Routine ফর্মে optional (routine_general.php-তে required নয়, তাই অনেক রুটিন
// shift_id = NULL নিয়েই সেভ হয়) — তাই এখানে Shift বাছাই না করলেও (বা "All Shift" রাখলেও)
// সার্চ চলবে, শুধু Class + Section লাগবে। Shift বাছাই করা থাকলে সেটা দিয়েও ফিল্টার হবে,
// কিন্তু shift_id = NULL থাকা রুটিনগুলোও (কোনো নির্দিষ্ট শিফট ছাড়া সেভ হওয়া) তখন দেখানো হবে,
// যাতে "Shift না বাছাই করে Save করা রুটিন" View পেজে কখনো অদৃশ্য হয়ে না যায়।
if ($is_searched && $sel_class && $sel_section) {
    $stmt = $pdo->prepare(
        "SELECT routines.*, academic_subjects.subject_name, teachers.name AS teacher_name,
                academic_classes.class_name, academic_shifts.shift_name, academic_sections.section_name
         FROM routines
         LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
         LEFT JOIN employees teachers ON teachers.id = routines.teacher_id
         LEFT JOIN academic_classes ON academic_classes.id = routines.class_id
         LEFT JOIN academic_shifts ON academic_shifts.id = routines.shift_id
         LEFT JOIN academic_sections ON academic_sections.id = routines.section_id
         WHERE routines.class_id = ? AND routines.section_id = ? AND routines.status = 'Active'" .
        ($sel_shift ? " AND (routines.shift_id = ? OR routines.shift_id IS NULL)" : "") .
        (branch_current_id() !== null ? " AND (routines.branch_id = ?)" : "")
    );
    $rv_params = [$sel_class, $sel_section];
    if ($sel_shift) { $rv_params[] = $sel_shift; }
    if (branch_current_id() !== null) { $rv_params[] = branch_current_id(); }
    $stmt->execute($rv_params);
    $all_rows = $stmt->fetchAll();

    foreach ($week_days as $day) { $day_entries[$day] = []; }
    foreach ($all_rows as $row) {
        if ($class_name === '') { $class_name = $row['class_name']; $shift_name = $row['shift_name']; $section_name = $row['section_name']; }
        $row_days = explode(',', (string) $row['routine_days']);
        foreach ($row_days as $rd) {
            $rd = trim($rd);
            if (isset($day_entries[$rd])) $day_entries[$rd][] = $row;
        }
    }
    // প্রতিটা দিনের পিরিয়ডগুলো Start Time অনুযায়ী সাজানো
    foreach ($day_entries as &$entries) {
        usort($entries, fn($a, $b) => strcmp((string) $a['start_time'], (string) $b['start_time']));
    }
    unset($entries);
}

$inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: '';
?>

<style>
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        .no-print { display: none !important; }
        .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar { display: none !important; }
        .main-wrapper { margin-left: 0 !important; }
        .main-wrapper.expanded { margin-left: 0 !important; }
        body { background: #fff !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin-bottom: 0 !important; }
        .table-responsive { overflow: visible !important; }
        table.table-bordered, table.table-bordered th, table.table-bordered td { border: 1px solid #000 !important; }
        thead.table-dark, thead.table-dark th { background: #e2e8f0 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table tr { page-break-inside: avoid; }
    }
</style>

<div class="card-custom no-print">
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="routine_view">
        <div class="col-md-3">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="class_id" id="rvClassSelect" class="form-select" required onchange="filterRvSections()">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo rvv($c['class_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Shift</label>
            <select name="shift_id" class="form-select">
                <option value="">All Shift</option>
                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" <?php echo $sel_shift === (int) $sh['id'] ? 'selected' : ''; ?>><?php echo rvv($sh['shift_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">Section <span class="text-danger">*</span></label>
            <select name="section_id" id="rvSectionSelect" class="form-select" required disabled>
                <option value="">Select Class first</option>
                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" <?php echo $sel_section === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo rvv($sec['section_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold d-block">Routine Type <span class="text-danger">*</span></label>
            <div class="d-flex gap-3 pt-2">
                <div class="form-check"><input class="form-check-input" type="radio" name="routine_type" id="rvTypeTop" value="top" <?php echo $routine_type === 'top' ? 'checked' : ''; ?>><label class="form-check-label" for="rvTypeTop">Time Slot (Top)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="routine_type" id="rvTypeInner" value="inner" <?php echo $routine_type === 'inner' ? 'checked' : ''; ?>><label class="form-check-label" for="rvTypeInner">Time Slot (Inner)</label></div>
            </div>
        </div>
        <div class="col-12">
            <button type="submit" name="view_routine" value="1" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;">View Routine</button>
            <?php if ($is_searched): ?><button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button><?php endif; ?>
            <a href="index.php?page=routine_view" class="btn btn-danger">Cancel</a>
        </div>
    </form>
</div>

<div class="card-custom mt-3">
    <?php if (!$is_searched): ?>
        <p class="text-muted text-center py-4 mb-0">উপরে Class, Shift, Section বাছাই করে "View Routine" চাপুন।</p>
    <?php elseif (!$day_entries || array_sum(array_map('count', $day_entries)) === 0): ?>
        <h5 class="text-muted text-center py-4 mb-0">No routine exists !</h5>
    <?php else: ?>
        <div class="text-center mb-3">
            <h5 class="fw-bold mb-0"><?php echo rvv($inst_name); ?></h5>
            <p class="text-muted mb-0">Class: <?php echo rvv($class_name); ?> &nbsp;|&nbsp; Shift: <?php echo rvv($shift_name); ?> &nbsp;|&nbsp; Section: <?php echo rvv($section_name); ?></p>
        </div>

        <?php if ($routine_type === 'top'): ?>
            <!-- ============ Time Slot (Top) — কলামে সময়, রো-তে দিন ============ -->
            <?php
            // সব ইউনিক টাইম-স্লট বের করা (শুরু-শেষ সময় দিয়ে), কলাম হিসেবে ব্যবহারের জন্য
            $slots = [];
            foreach ($day_entries as $entries) {
                foreach ($entries as $e) {
                    $key = ($e['start_time'] ?? '') . '-' . ($e['end_time'] ?? '');
                    if (!isset($slots[$key])) $slots[$key] = ['start' => $e['start_time'], 'end' => $e['end_time']];
                }
            }
            usort($slots, fn($a, $b) => strcmp((string) $a['start'], (string) $b['start']));
            ?>
            <div class="table-responsive">
                <table class="table table-bordered text-center align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Day</th>
                            <?php foreach ($slots as $s): ?>
                                <th class="small"><?php echo fmt_time_rv($s['start']); ?><br>-<br><?php echo fmt_time_rv($s['end']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($week_days as $day): if (!$day_entries[$day]) continue; ?>
                            <tr>
                                <td class="fw-bold"><?php echo $day; ?></td>
                                <?php foreach ($slots as $s):
                                    $match = null;
                                    foreach ($day_entries[$day] as $e) {
                                        if ($e['start_time'] === $s['start'] && $e['end_time'] === $s['end']) { $match = $e; break; }
                                    }
                                ?>
                                    <td class="small">
                                        <?php if ($match): ?>
                                            <strong><?php echo rvv($match['subject_name'] ?? '-'); ?></strong><br>
                                            <span class="text-muted"><?php echo rvv($match['teacher_name'] ?? '-'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- ============ Time Slot (Inner) — প্রতিটা সেলের ভেতরেই সময় দেখানো ============ -->
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-dark"><tr><th style="width:150px;">Day</th><th>Periods</th></tr></thead>
                    <tbody>
                        <?php foreach ($week_days as $day): if (!$day_entries[$day]) continue; ?>
                            <tr>
                                <td class="fw-bold"><?php echo $day; ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($day_entries[$day] as $e): ?>
                                            <div class="border rounded px-2 py-1 small" style="background:#f8fafc;">
                                                <strong><?php echo rvv($e['subject_name'] ?? '-'); ?></strong> — <?php echo rvv($e['teacher_name'] ?? '-'); ?>
                                                <?php if ($e['start_time']): ?><br><span class="text-muted"><?php echo fmt_time_rv($e['start_time']); ?> - <?php echo fmt_time_rv($e['end_time']); ?></span><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function filterRvSections() {
        const classId = document.getElementById('rvClassSelect').value;
        const select = document.getElementById('rvSectionSelect');
        select.disabled = !classId;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
        select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
        if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
            select.value = '';
        }
    }
    filterRvSections();
</script>
