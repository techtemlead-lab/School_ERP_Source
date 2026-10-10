<?php
// admin/routine_view.php - View / Print Routine (আগের ভাঙা ভার্সন ঠিক করে নতুন করে বানানো হলো)
function rvv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmt_time_rv($t): string { return $t ? date('h:i A', strtotime($t)) : ''; }
require_once __DIR__ . '/../config/routine_grid.php';

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

$sel_class_raw = (string) ($_GET['class_id'] ?? '');
$all_classes = ($sel_class_raw === 'all');                 // সব ক্লাসের রুটিন একসাথে
$sel_class   = $all_classes ? 0 : (int) $sel_class_raw;
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$routine_type = ($_GET['routine_type'] ?? 'grid') === 'top' ? 'top' : 'grid';   // grid = 1st/2nd Class কলাম, top = সময়-স্লট কলাম
$is_searched = isset($_GET['view_routine']);

// ফলাফল ব্লকের তালিকা: প্রতিটা ব্লক = একটা Class + Shift + Section-এর সাপ্তাহিক রুটিন
$blocks = [];

// শিফট Add/Edit Routine ফর্মে optional (routine_general.php-তে required নয়, তাই অনেক রুটিন
// shift_id = NULL নিয়েই সেভ হয়) — তাই Shift বাছাই না করলেও সার্চ চলবে। Shift বাছাই থাকলে
// shift_id = NULL রুটিনগুলোও দেখানো হয়, যাতে "Shift না বাছাই করে Save করা রুটিন" অদৃশ্য না হয়।
if ($is_searched && ($all_classes || ($sel_class && $sel_section))) {
    require_once __DIR__ . '/../config/subject_religion.php'; sr_ensure($pdo);
    $where = ["routines.status = 'Active'"]; $rv_params = [];
    if (!$all_classes) { $where[] = 'routines.class_id = ?'; $rv_params[] = $sel_class; $where[] = 'routines.section_id = ?'; $rv_params[] = $sel_section; }
    if ($sel_shift) { $where[] = '(routines.shift_id = ? OR routines.shift_id IS NULL)'; $rv_params[] = $sel_shift; }
    if (branch_current_id() !== null) { $where[] = '(routines.branch_id = ? OR routines.branch_id IS NULL)'; $rv_params[] = branch_current_id(); }
    $stmt = $pdo->prepare(
        "SELECT routines.*, academic_subjects.subject_name, academic_subjects.religion AS subject_religion, teachers.name AS teacher_name,
                academic_classes.class_name, academic_shifts.shift_name, academic_sections.section_name
         FROM routines
         LEFT JOIN academic_subjects ON academic_subjects.id = routines.subject_id
         LEFT JOIN employees teachers ON teachers.id = routines.teacher_id
         LEFT JOIN academic_classes ON academic_classes.id = routines.class_id
         LEFT JOIN academic_shifts ON academic_shifts.id = routines.shift_id
         LEFT JOIN academic_sections ON academic_sections.id = routines.section_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY academic_classes.rank ASC, routines.class_id ASC, academic_sections.section_name ASC, routines.shift_id ASC, routines.start_time ASC"
    );
    $stmt->execute($rv_params);
    foreach ($stmt->fetchAll() as $row) {
        $bk = (int) $row['class_id'] . '|' . (int) $row['section_id'] . '|' . (int) $row['shift_id'];
        if (!isset($blocks[$bk])) {
            $blocks[$bk] = ['class' => $row['class_name'], 'shift' => $row['shift_name'], 'section' => $row['section_name'], 'days' => array_fill_keys($week_days, [])];
        }
        foreach (explode(',', (string) $row['routine_days']) as $rd) {
            $rd = trim($rd);
            if (isset($blocks[$bk]['days'][$rd])) { $blocks[$bk]['days'][$rd][] = $row; }
        }
    }
    foreach ($blocks as $bk => $b) {   // দিনের ভেতরে Start Time অনুযায়ী সাজানো; রুটিন-ছাড়া ব্লক বাদ
        foreach ($b['days'] as $d => $list) { usort($list, fn($x, $y) => strcmp((string) $x['start_time'], (string) $y['start_time'])); $blocks[$bk]['days'][$d] = $list; }
        if (!array_sum(array_map('count', $b['days']))) { unset($blocks[$bk]); }
    }
}

/** পুরনো "Time Slot (Top)" টেবিল — কলামে সময়-স্লট, রো-তে দিন */
function rv_top_html(array $week_days, array $day_entries): string
{
    $slots = [];
    foreach ($day_entries as $entries) {
        foreach ($entries as $e) {
            $key = ($e['start_time'] ?? '') . '-' . ($e['end_time'] ?? '');
            if (!isset($slots[$key])) $slots[$key] = ['start' => $e['start_time'], 'end' => $e['end_time']];
        }
    }
    usort($slots, fn($a, $b) => strcmp((string) $a['start'], (string) $b['start']));
    $h = '<div class="table-responsive"><table class="table table-bordered text-center align-middle"><thead class="table-dark"><tr><th>Day</th>';
    foreach ($slots as $s) { $h .= '<th class="small">' . rvv(fmt_time_rv($s['start'])) . '<br>-<br>' . rvv(fmt_time_rv($s['end'])) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($week_days as $day) {
        if (empty($day_entries[$day])) continue;
        $h .= '<tr><td class="fw-bold">' . $day . '</td>';
        foreach ($slots as $s) {
            $matches = [];
            foreach ($day_entries[$day] as $e) { if ($e['start_time'] === $s['start'] && $e['end_time'] === $s['end']) { $matches[] = $e; } }
            $h .= '<td class="small">';
            foreach ($matches as $mi => $match) { // Parallel Period: একই সময়ে একাধিক (ধর্মভেদে) Subject/শিক্ষক
                if ($mi > 0) { $h .= '<hr class="my-1">'; }
                if (!empty($match['has_break']) && empty($match['subject_name'])) { $h .= '<strong>Break / Tiffin</strong>'; }
                else {
                    $rl = sr_norm($match['subject_religion'] ?? '');
                    $h .= '<strong>' . rvv($match['subject_name'] ?? '-') . '</strong>' . ($rl !== '' ? ' <span class="text-info">(' . rvv($rl) . ')</span>' : '') . '<br><span class="text-muted">' . rvv($match['teacher_name'] ?? '-') . '</span>';
                }
            }
            $h .= '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

$inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: '';
echo rg_css();
?>

<div class="card-custom no-print">
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <input type="hidden" name="page" value="routine_view">
        <div class="col-md-3">
            <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
            <select name="class_id" id="rvClassSelect" class="form-select" required onchange="filterRvSections()">
                <option value="">Select Class</option>
                <option value="all" <?php echo $all_classes ? 'selected' : ''; ?>>★ All Classes (সব ক্লাস একসাথে)</option>
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
            <label class="form-label fw-bold">Section <span class="text-danger" id="rvSecStar">*</span></label>
            <select name="section_id" id="rvSectionSelect" class="form-select" required disabled>
                <option value="">Select Class first</option>
                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" <?php echo $sel_section === (int) $sec['id'] ? 'selected' : ''; ?> hidden><?php echo rvv($sec['section_name']); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold d-block">Routine Type <span class="text-danger">*</span></label>
            <div class="d-flex gap-3 pt-2">
                <div class="form-check"><input class="form-check-input" type="radio" name="routine_type" id="rvTypeGrid" value="grid" <?php echo $routine_type === 'grid' ? 'checked' : ''; ?>><label class="form-check-label" for="rvTypeGrid">Class-wise (1st, 2nd…)</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="routine_type" id="rvTypeTop" value="top" <?php echo $routine_type === 'top' ? 'checked' : ''; ?>><label class="form-check-label" for="rvTypeTop">Time Slot</label></div>
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
        <p class="text-muted text-center py-4 mb-0">উপরে Class, Shift, Section বাছাই করে "View Routine" চাপুন। সব ক্লাসের রুটিন একসাথে দেখতে Class-এ "All Classes" বাছুন।</p>
    <?php elseif (!$blocks): ?>
        <h5 class="text-muted text-center py-4 mb-0">No routine exists !</h5>
    <?php else: ?>
        <?php foreach ($blocks as $b): ?>
            <div class="rg-block mb-4">
                <div class="rg-title">
                    <h5><?php echo rvv($inst_name); ?></h5>
                    <p>Class: <?php echo rvv($b['class']); ?> &nbsp;|&nbsp; Shift: <?php echo rvv($b['shift'] ?: 'All'); ?> &nbsp;|&nbsp; Section: <?php echo rvv($b['section']); ?></p>
                </div>
                <?php echo $routine_type === 'top' ? rv_top_html($week_days, $b['days']) : rg_grid_html($week_days, $b['days']); ?>
            </div>
        <?php endforeach; ?>
        <?php if ($all_classes): ?><p class="text-muted small no-print mb-0">মোট <?php echo count($blocks); ?> টি Class/Section-এর রুটিন দেখানো হচ্ছে। Print চাপলে প্রতিটা আলাদা পাতায় প্রিন্ট হবে।</p><?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function filterRvSections() {
        const classId = document.getElementById('rvClassSelect').value;
        const isAll = classId === 'all';
        const select = document.getElementById('rvSectionSelect');
        select.disabled = !classId || isAll;      // All Classes হলে Section লাগে না
        select.required = !isAll;
        document.getElementById('rvSecStar').style.display = isAll ? 'none' : '';
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || isAll || opt.dataset.class !== classId;
        });
        select.options[0].textContent = isAll ? 'সব Section' : (classId ? 'Select Section' : 'Select Class first');
        if (isAll) { select.value = ''; }
        else if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
            select.value = '';
        }
    }
    filterRvSections();
</script>
