<?php
require_once __DIR__ . '/../config/portal_access.php';
$result_portal_role = erp_portal_controller_role(['admin']);
erp_portal_require_role($pdo, $result_portal_role, 'result_formative_report');
// admin/result_formative_report.php - Result Module > Exam Procedure > Formative / Class Test Report
// Class + Shift + Section + Term + Subject অনুযায়ী প্রতিটা ছাত্রের Component/Class Test-ভিত্তিক মার্ক ও মোট Formative মার্ক,
// প্রিন্টসহ (প্রতিষ্ঠানের হেডার, Portrait/Landscape, নিচে স্বাক্ষর)।
require_once __DIR__ . '/../config/formative_helpers.php';
require_once __DIR__ . '/employee_attendance_common.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS formative_scores (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, term_id INT NOT NULL, subject_id INT NOT NULL, student_id INT NOT NULL, kind VARCHAR(12) NOT NULL, ref_id INT NOT NULL DEFAULT 0, score DECIMAL(6,2) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_score (term_id, subject_id, student_id, kind, ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_settings (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, term_id INT NOT NULL DEFAULT 0, part_id INT NOT NULL DEFAULT 0, mode VARCHAR(20) NOT NULL DEFAULT 'Component', full_mark DECIMAL(6,2) NOT NULL DEFAULT 10, aggregate_method VARCHAR(20) NOT NULL DEFAULT 'Average', best_n INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'Active', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_scope (session_id, class_id, subject_id, term_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_components (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, name VARCHAR(100) NOT NULL, weight_percent DECIMAL(6,2) NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'Manual', rank INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'Active', KEY idx_scope (session_id, class_id, subject_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_tests (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, test_name VARCHAR(100) NOT NULL, max_score DECIMAL(6,2) NOT NULL DEFAULT 10, status VARCHAR(20) NOT NULL DEFAULT 'Active', KEY idx_scope (session_id, class_id, subject_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_test_terms (test_id INT NOT NULL, term_id INT NOT NULL, PRIMARY KEY (test_id, term_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$sid = $current_session ? (int) $current_session['id'] : 0;
function frv($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$class_id   = (int) ($_GET['class_id'] ?? 0);
$shift_id   = (int) ($_GET['shift_id'] ?? 0);
$section_id = (int) ($_GET['section_id'] ?? 0);
$term_id    = (int) ($_GET['term_id'] ?? 0);
$subject_id = (int) ($_GET['subject_id'] ?? 0);
$all_selected = $class_id && $shift_id && $section_id && $term_id && $subject_id;

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status='Active' ORDER BY shift_name ASC")->fetchAll();
$sections = [];
if ($class_id && $shift_id) {
    $q = $pdo->prepare("SELECT id, section_name FROM academic_sections WHERE class_id=? AND shift_id=? AND status='Active' ORDER BY section_name ASC");
    $q->execute([$class_id, $shift_id]); $sections = $q->fetchAll();
}
$tq = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$tq->execute([$sid]); $terms = $tq->fetchAll();
$subjects = [];
if ($class_id) {
    $q = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
    $q->execute([$class_id]); $subjects = $q->fetchAll();
}
$nm = function (array $rows, int $id, string $col): string { foreach ($rows as $r) { if ((int) $r['id'] === $id) { return (string) $r[$col]; } } return ''; };

$setting = null; $components = []; $tests = []; $rows = []; $problem = '';
if ($all_selected) {
    [$setting, $components, $tests] = fe_resolve($pdo, $sid, $class_id, $subject_id, $term_id);
    if (!$setting) {
        $problem = 'এই Class/Subject/Term-এর জন্য Formative সেটিং করা নেই।';
    } else {
        $where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
        $params = [$class_id, $shift_id, $section_id];
        branch_apply_filter($where, $params, 's.branch_id');
        $st = $pdo->prepare("SELECT s.id, s.student_id, s.roll_no, s.name_en FROM students s WHERE " . implode(' AND ', $where) . " ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC");
        $st->execute($params);
        $students = $st->fetchAll();
        $sq = $pdo->prepare("SELECT student_id, kind, ref_id, score FROM formative_scores WHERE term_id=? AND subject_id=?");
        $sq->execute([$term_id, $subject_id]);
        $saved = [];
        foreach ($sq->fetchAll() as $r) { $saved[(int) $r['student_id']][$r['kind']][(int) $r['ref_id']] = (float) $r['score']; }
        foreach ($students as $s) {
            $sv = $saved[(int) $s['id']] ?? [];
            $in = ['c' => $sv['component'] ?? [], 't' => $sv['test'] ?? [], 'd' => $sv['direct'][0] ?? null];
            $total = ($sv ? fe_compute($setting, $components, $tests, $in) : null);
            $rows[] = ['s' => $s, 'in' => $in, 'total' => $total];
        }
    }
}
$by_tests = $setting && $setting['mode'] !== 'Component' && $tests;
$title = 'Formative / Class Test Report : ' . $nm($terms, $term_id, 'term_name') . ' - ' . ($current_session['session_name'] ?? '');
?>
<?php ea_print_css('portrait'); ?>
<div class="card shadow-sm border-0 mb-4 no-print"><div class="card-body">
    <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-table-list"></i> Formative / Class Test Report</h4>
    <form method="GET" action="index.php" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="result_formative_report">
        <div class="col-md-2"><label class="form-label">Class</label><select name="class_id" class="form-select" onchange="this.form.submit()"><option value="0">—</option><?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo frv($c['class_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Shift</label><select name="shift_id" class="form-select" onchange="this.form.submit()"><option value="0">—</option><?php foreach ($shifts as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $shift_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo frv($c['shift_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Section</label><select name="section_id" class="form-select"><option value="0">—</option><?php foreach ($sections as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $section_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo frv($c['section_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Exam Term</label><select name="term_id" class="form-select"><option value="0">—</option><?php foreach ($terms as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $term_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo frv($c['term_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Subject</label><select name="subject_id" class="form-select"><option value="0">—</option><?php foreach ($subjects as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $subject_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo frv($c['subject_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">দেখুন</button></div>
    </form>
</div></div>

<?php if (!$all_selected): ?>
    <div class="text-center text-muted py-4 no-print">উপরে Class, Shift, Section, Exam Term ও Subject সিলেক্ট করুন।</div>
<?php elseif ($problem): ?>
    <div class="alert alert-warning no-print"><?php echo frv($problem); ?></div>
<?php else: ?>
    <div class="d-flex justify-content-end mb-2 no-print"><?php ea_print_controls('portrait', false); ?></div>
    <div class="card shadow-sm border-0"><div class="card-body">
        <?php ea_print_header($pdo, $title, false); ?>
        <div class="mb-2 small">
            Class : <strong><?php echo frv($nm($classes, $class_id, 'class_name')); ?></strong>,
            Shift : <strong><?php echo frv($nm($shifts, $shift_id, 'shift_name')); ?></strong>,
            Section : <strong><?php echo frv($nm($sections, $section_id, 'section_name')); ?></strong>,
            Subject : <strong><?php echo frv($nm($subjects, $subject_id, 'subject_name')); ?></strong>,
            Full Mark : <strong><?php echo fe_num($setting['full_mark']); ?></strong>
            <?php if ($by_tests): ?>(<?php echo frv($setting['aggregate_method']); ?><?php echo $setting['aggregate_method'] === 'Best N' ? ' ' . (int) $setting['best_n'] : ''; ?>)<?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-bordered table-sm align-middle">
            <thead class="table-light"><tr><th>Sl.</th><th>ID</th><th>Name</th><th>Roll</th>
                <?php if ($setting['mode'] === 'Component'): foreach ($components as $c): ?><th class="text-center"><?php echo frv($c['name']); ?><br><small>(<?php echo fe_num($c['weight_percent']); ?>%)</small></th><?php endforeach;
                elseif ($by_tests): foreach ($tests as $t): ?><th class="text-center"><?php echo frv($t['test_name']); ?><br><small>(<?php echo fe_num($t['max_score']); ?>)</small></th><?php endforeach;
                else: ?><th class="text-center">Marks</th><?php endif; ?>
                <th class="text-center">Total<br><small>(<?php echo fe_num($setting['full_mark']); ?>)</small></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): $s = $r['s']; $in = $r['in']; ?>
                <tr><td><?php echo $i + 1; ?></td><td><?php echo frv($s['student_id']); ?></td><td><?php echo frv($s['name_en']); ?></td><td><?php echo frv($s['roll_no']); ?></td>
                <?php if ($setting['mode'] === 'Component'): foreach ($components as $c): $v = $in['c'][$c['id']] ?? null; ?><td class="text-center"><?php echo $v === null ? '' : fe_num($v); ?></td><?php endforeach;
                elseif ($by_tests): foreach ($tests as $t): $v = $in['t'][$t['id']] ?? null; ?><td class="text-center"><?php echo $v === null ? '' : fe_num($v); ?></td><?php endforeach;
                else: ?><td class="text-center"><?php echo $in['d'] === null ? '' : fe_num($in['d']); ?></td><?php endif; ?>
                <td class="text-center fw-bold"><?php echo $r['total'] === null ? '—' : fe_num($r['total']); ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="20" class="text-center text-muted">কোনো শিক্ষার্থী পাওয়া যায়নি।</td></tr><?php endif; ?>
            </tbody></table></div>
        <div class="d-flex justify-content-between mt-5 pt-4" style="font-size:13px;">
            <div style="border-top:1px solid #333;min-width:160px;text-align:center;">Subject Teacher</div>
            <div style="border-top:1px solid #333;min-width:160px;text-align:center;">Class Teacher</div>
            <div style="border-top:1px solid #333;min-width:160px;text-align:center;">Principal / Head</div>
        </div>
    </div></div>
<?php endif; ?>
