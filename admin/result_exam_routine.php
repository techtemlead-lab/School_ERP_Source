<?php
// admin/result_exam_routine.php - Result Module > Exam Procedure > Exam Routine
// Class + Shift + Section + Exam Term + Exam Part সিলেক্ট করলে ঐ ক্লাসের সব Subject
// লিস্ট আসবে, প্রতিটার জন্য Date, Start Time, End Time সেট করে "Save Routine" দিয়ে
// একসাথে সেভ করা যাবে। রো এর পাশের (X) বাটনে ক্লিক করলে ঐ সাবজেক্টটা লিস্ট থেকে বাদ
// হয়ে যাবে (Save Routine চাপার আগ পর্যন্ত), এবং Save করার সময় বাদ দেয়া সাবজেক্টগুলো
// রুটিন থেকে মুছে যাবে।
$er_err = $_SESSION['er_err'] ?? '';
$er_msg = $_SESSION['er_msg'] ?? '';
unset($_SESSION['er_err'], $_SESSION['er_msg']);

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_routines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        shift_id INT NOT NULL,
        section_id INT NOT NULL,
        term_id INT NOT NULL,
        part_id INT NOT NULL,
        subject_id INT NOT NULL,
        exam_date DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_routine (class_id, shift_id, section_id, term_id, part_id, subject_id),
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

function erv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handler - একসাথে সব Subject এর রুটিন সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_routine'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['er_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_exam_routine');
        exit();
    }

    $class_id   = (int) ($_POST['class_id'] ?? 0);
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $section_id = (int) ($_POST['section_id'] ?? 0);
    $term_id    = (int) ($_POST['term_id'] ?? 0);
    $part_id    = (int) ($_POST['part_id'] ?? 0);
    $subject_ids = $_POST['subject_id'] ?? [];

    if ($class_id <= 0 || $shift_id <= 0 || $section_id <= 0 || $term_id <= 0 || $part_id <= 0) {
        $_SESSION['er_err'] = 'Class, Shift, Section, Exam Term এবং Exam Part সবগুলো সিলেক্ট করুন।';
    } else {
        // ছুটির দিনে (সাপ্তাহিক ছুটি বা Academic Calendar-এর ছুটি) নতুন/বদলানো তারিখ: নিশ্চিত না করলে সেভ হবে না
        $er_block = [];
        if (empty($_POST['force_holiday'])) {
            try {
                require_once __DIR__ . '/../config/academic_calendar_helpers.php';
                $er_w = ac_weekly_holidays($pdo);
                $er_h = ac_holiday_ranges($pdo, '2000-01-01', '2100-12-31');
                $old_q = $pdo->prepare("SELECT subject_id, exam_date FROM exam_routines WHERE class_id=? AND shift_id=? AND section_id=? AND term_id=? AND part_id=?");
                $old_q->execute([$class_id, $shift_id, $section_id, $term_id, $part_id]);
                $old_dates = [];
                foreach ($old_q->fetchAll() as $o) { $old_dates[(int) $o['subject_id']] = (string) $o['exam_date']; }
                foreach ($subject_ids as $sid) {
                    $sid = (int) $sid;
                    $d = trim($_POST['exam_date'][$sid] ?? '');
                    if ($d === '' || (($old_dates[$sid] ?? '') === $d)) { continue; }
                    $reasons = [];
                    $dn = date('l', strtotime($d));
                    if (in_array($dn, $er_w, true)) { $reasons[] = 'সাপ্তাহিক ছুটি (' . $dn . ')'; }
                    foreach ($er_h as $h) { if ($d >= $h['start_date'] && $d <= $h['end_date']) { $reasons[] = $h['holiday_name']; } }
                    if ($reasons) { $er_block[] = $d . ' — ' . implode(', ', $reasons); }
                }
            } catch (Throwable $e) { /* টেবিল না থাকলে চেক বন্ধ */ }
        }
        if ($er_block) {
            $_SESSION['er_err'] = 'এই তারিখগুলো ছুটির দিন: ' . implode(' | ', $er_block) . '। ছুটির দিনে পরীক্ষা রাখতে চাইলে Save দিয়ে নিশ্চিত করুন।';
            header('Location: index.php?page=result_exam_routine&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id);
            exit();
        }

        $kept_ids = [];
        $stmt = $pdo->prepare(
            "INSERT INTO exam_routines
                (session_id, class_id, shift_id, section_id, term_id, part_id, subject_id, exam_date, start_time, end_time, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
             ON DUPLICATE KEY UPDATE
                exam_date=VALUES(exam_date), start_time=VALUES(start_time), end_time=VALUES(end_time), status='Active'"
        );
        foreach ($subject_ids as $sid) {
            $sid = (int) $sid;
            $exam_date  = trim($_POST['exam_date'][$sid] ?? '') ?: null;
            $start_time = trim($_POST['start_time'][$sid] ?? '') ?: null;
            $end_time   = trim($_POST['end_time'][$sid] ?? '') ?: null;
            $stmt->execute([
                $current_session_id, $class_id, $shift_id, $section_id, $term_id, $part_id, $sid,
                $exam_date, $start_time, $end_time,
            ]);
            $kept_ids[] = $sid;
        }

        // Save করার সময় (X দিয়ে) বাদ দেয়া সাবজেক্টগুলো রুটিন থেকে মুছে ফেলা
        if ($kept_ids) {
            $placeholders = implode(',', array_fill(0, count($kept_ids), '?'));
            $del = $pdo->prepare(
                "DELETE FROM exam_routines WHERE class_id=? AND shift_id=? AND section_id=? AND term_id=? AND part_id=? AND subject_id NOT IN ($placeholders)"
            );
            $del->execute(array_merge([$class_id, $shift_id, $section_id, $term_id, $part_id], $kept_ids));
        } else {
            $pdo->prepare("DELETE FROM exam_routines WHERE class_id=? AND shift_id=? AND section_id=? AND term_id=? AND part_id=?")
                ->execute([$class_id, $shift_id, $section_id, $term_id, $part_id]);
        }

        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$class_id}, টার্ম আইডি {$term_id}, পার্ট আইডি {$part_id} এর Exam Routine সেভ করা হয়েছে");
        $_SESSION['er_msg'] = 'Exam Routine সেভ করা হয়েছে।';
    }

    header('Location: index.php?page=result_exam_routine&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&part_id=' . $part_id);
    exit();
}

// -----------------------------------------------------------
// Filter dropdown ডাটা
// -----------------------------------------------------------
$sel_class   = (int) ($_GET['class_id'] ?? 0);
$sel_shift   = (int) ($_GET['shift_id'] ?? 0);
$sel_section = (int) ($_GET['section_id'] ?? 0);
$sel_term    = (int) ($_GET['term_id'] ?? 0);
$sel_part    = (int) ($_GET['part_id'] ?? 0);

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts  = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status='Active' ORDER BY shift_name ASC")->fetchAll();

$sections = [];
if ($sel_class > 0 && $sel_shift > 0) {
    $sec_stmt = $pdo->prepare("SELECT id, section_name FROM academic_sections WHERE class_id=? AND shift_id=? AND status='Active' ORDER BY section_name ASC");
    $sec_stmt->execute([$sel_class, $sel_shift]);
    $sections = $sec_stmt->fetchAll();
}

$terms_stmt = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$terms_stmt->execute([$current_session_id]);
$terms = $terms_stmt->fetchAll();

$parts_stmt = $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id=? AND status='Active' ORDER BY rank ASC");
$parts_stmt->execute([$current_session_id]);
$parts = $parts_stmt->fetchAll();

// -----------------------------------------------------------
// সিলেক্টেড কম্বিনেশনের জন্য Subject লিস্ট + বিদ্যমান রুটিন ডাটা
// -----------------------------------------------------------
$all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0);
$subjects = [];
$existing = [];
if ($all_selected) {
    $sub_stmt = $pdo->prepare("SELECT id, subject_name, subject_code FROM academic_subjects WHERE class_id=? AND status='Active' ORDER BY sort_order ASC, id ASC");
    $sub_stmt->execute([$sel_class]);
    $subjects = $sub_stmt->fetchAll();

    $ex_stmt = $pdo->prepare("SELECT * FROM exam_routines WHERE class_id=? AND shift_id=? AND section_id=? AND term_id=? AND part_id=?");
    $ex_stmt->execute([$sel_class, $sel_shift, $sel_section, $sel_term, $sel_part]);
    foreach ($ex_stmt->fetchAll() as $er) { $existing[(int) $er['subject_id']] = $er; }
}

$institute = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
$institute_name = $institute['institute_name'] ?? 'School Name';

$sel_class_name = '';
foreach ($classes as $c) { if ((int) $c['id'] === $sel_class) { $sel_class_name = $c['class_name']; break; } }
$sel_term_name = '';
foreach ($terms as $t) { if ((int) $t['id'] === $sel_term) { $sel_term_name = $t['term_name']; break; } }
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card-custom { border: none !important; box-shadow: none !important; }
}
.er-print-head { display: none; }
@media print {
    .er-print-head { display: block; text-align: center; margin-bottom: 16px; }
    @page { size: A4 portrait; margin: 10mm; }
    #routineTable { width: 100% !important; font-size: 14px; }
    #routineTable th, #routineTable td { padding: 6px 8px !important; }
    #routineTable input.er-hide-print { display: none !important; }
    .er-print-val { display: inline !important; font-weight: 600; }
}
.er-print-val { display: none; }
</style>

<div class="card-custom no-print">
    <?php if ($er_msg): ?><div class="alert alert-success py-2"><?php echo erv($er_msg); ?></div><?php endif; ?>
    <?php if ($er_err): ?><div class="alert alert-danger py-2"><?php echo erv($er_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" id="filterForm">
        <input type="hidden" name="page" value="result_exam_routine">

        <div class="row mb-3 align-items-center">
            <label class="col-sm-2 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="class_id" id="err_class" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo erv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="col-sm-2 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="shift_id" id="err_shift" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($shifts as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_shift === (int) $s['id'] ? 'selected' : ''; ?>><?php echo erv($s['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-2 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="section_id" id="err_section" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $sel_section === (int) $s['id'] ? 'selected' : ''; ?>><?php echo erv($s['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="err_section_hint" class="text-danger small" <?php echo ($sel_class > 0 && $sel_shift > 0) ? 'style="display:none;"' : ''; ?>>আগে Class ও Shift সিলেক্ট করুন</span>
            </div>

            <label class="col-sm-2 col-form-label fw-bold text-end">Exam Term <span class="text-danger">*</span></label>
            <div class="col-sm-3">
                <select name="term_id" id="err_term" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $sel_term === (int) $t['id'] ? 'selected' : ''; ?>><?php echo erv($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-2 col-form-label fw-bold text-end">Exam Part</label>
            <div class="col-sm-3">
                <select name="part_id" id="err_part" class="form-select">
                    <option value="0">-- Select --</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $sel_part === (int) $p['id'] ? 'selected' : ''; ?>><?php echo erv($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-4 offset-sm-2 text-end">
                <button type="submit" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Submit</button>
                <button type="button" class="btn btn-success px-4 fw-bold" id="err_print_btn" onclick="window.print()" <?php echo $all_selected ? '' : 'style="display:none;"'; ?>><i class="fa-solid fa-print"></i> Print</button>
            </div>
        </div>
    </form>
</div>

<div class="card-custom mt-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="fa-solid fa-calendar-days text-primary"></i>
        <h6 class="mb-0">Exam Routine</h6>
    </div>

    <div id="err_result_wrap">
        <div class="er-print-head">
            <h5 class="mb-0"><?php echo erv($institute_name); ?></h5>
            <div><?php echo erv($sel_class_name); ?> - <?php echo erv($sel_term_name); ?> — Exam Routine</div>
            <div class="small">Session : <?php echo erv($current_session_name); ?></div>
        </div>

        <?php if (!$all_selected): ?>
            <div class="text-center text-muted py-4 no-print">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject এখানে দেখাবে।</div>
        <?php elseif (!$subjects): ?>
            <div class="text-center text-muted py-4 no-print">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>
        <?php else: ?>
            <form method="POST" action="index.php?page=result_exam_routine" id="routineForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
                <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
                <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
                <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
                <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle" id="routineTable">
                        <thead class="table-light">
                            <tr>
                                <th>Subjects</th>
                                <th style="width:160px;">Date</th>
                                <th style="width:160px;">Start Time</th>
                                <th style="width:160px;">End Time</th>
                                <th class="no-print" style="width:70px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $sub):
                                $sid = (int) $sub['id'];
                                $ex = $existing[$sid] ?? null;
                                $exam_date  = $ex['exam_date']  ?? '';
                                $start_time = $ex ? substr($ex['start_time'], 0, 5) : '11:00';
                                $end_time   = $ex ? substr($ex['end_time'], 0, 5) : '13:00';
                            ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="subject_id[]" value="<?php echo $sid; ?>">
                                        <?php echo erv($sub['subject_name']); ?>
                                        <?php if ($sub['subject_code']): ?><span class="text-muted small">(<?php echo erv($sub['subject_code']); ?>)</span><?php endif; ?>
                                    </td>
                                    <td><input type="date" name="exam_date[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($exam_date); ?>"></td>
                                    <td><input type="time" name="start_time[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($start_time); ?>"></td>
                                    <td><input type="time" name="end_time[<?php echo $sid; ?>]" class="form-control form-control-sm" value="<?php echo erv($end_time); ?>"></td>
                                    <td class="text-center no-print">
                                        <button type="button" class="btn btn-sm btn-danger rounded-circle" title="Remove" onclick="removeRoutineRow(this)"><i class="fa-solid fa-xmark"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr class="no-print">
                <div class="text-center no-print">
                    <button type="submit" name="save_routine" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Routine</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
// বন্ধের দিন চেক — Branch-এর Weekly Holiday (যেমন Friday) + Academic Calendar-এর সক্রিয় ছুটি
$er_weekly = [];
$er_holidays = [];
try {
    $er_b = $pdo->query("SELECT weekly_holidays FROM branches WHERE status='Active' ORDER BY id ASC LIMIT 1")->fetch();
    $er_weekly = array_values(array_filter(array_map('trim', explode(',', $er_b['weekly_holidays'] ?? 'Friday'))));
    // শুধু "ছুটি ধরা হবে" Category-র এন্ট্রি (Exam/Event Category বাদ)
    require_once __DIR__ . '/../config/academic_calendar_helpers.php';
    $er_weekly = ac_weekly_holidays($pdo);
    $er_holidays = ac_holiday_ranges($pdo, '2000-01-01', '2100-12-31');
} catch (Throwable $e) { /* টেবিল/কলাম না থাকলে শুধু চেক বন্ধ থাকবে */ }
?>
<script>
// তারিখ বাছাই করলে সেটা বন্ধের দিন (Friday/Weekly Holiday বা Academic Holiday) কি না চেক করে জিজ্ঞেস করে —
// OK (Yes) = এই দিনেই পরীক্ষা নেবেন, Cancel (No) = তারিখ মুছে যাবে
var ER_WEEKLY = <?php echo json_encode($er_weekly, JSON_UNESCAPED_UNICODE); ?>;
var ER_HOLIDAYS = <?php echo json_encode($er_holidays, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var ER_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
function erHolidayReason(ymd) {
    if (!ymd) return '';
    var p = ymd.split('-');
    var dayName = ER_DAYS[new Date(+p[0], +p[1] - 1, +p[2]).getDay()];
    var reasons = [];
    if (ER_WEEKLY.indexOf(dayName) !== -1) reasons.push('সাপ্তাহিক ছুটি (' + dayName + ')');
    ER_HOLIDAYS.forEach(function (h) {
        if (ymd >= h.start_date && ymd <= h.end_date) reasons.push(h.holiday_name);
    });
    return reasons.join(', ');
}
document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.matches || !el.matches('input[type="date"][name^="exam_date"]')) return;
    var reason = erHolidayReason(el.value);
    if (!reason) { el.dataset.okHoliday = ''; return; }
    if (el.dataset.okHoliday === el.value) return;
    if (confirm('⚠ ' + el.value + ' তারিখটি বন্ধের দিন — ' + reason + '।\n\nএই দিনে কি পরীক্ষা নিতে ইচ্ছুক?\n\nOK = Yes (এই দিনেই রাখুন)   |   Cancel = No (তারিখ মুছে ফেলুন)')) {
        el.dataset.okHoliday = el.value;
    } else {
        el.value = '';
        el.dataset.okHoliday = '';
    }
});

// Save-এর সময়: ছুটির দিনে তারিখ নিশ্চিত (Yes) করা থাকলে সার্ভারকে জানানো
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.querySelector || !form.querySelector('#routineTable')) return;
    var needForce = false;
    form.querySelectorAll('input[type="date"][name^="exam_date"]').forEach(function (inp) {
        if (inp.value && erHolidayReason(inp.value)) {
            if (inp.dataset.okHoliday === inp.value) { needForce = true; }
        }
    });
    var f = form.querySelector('input[name="force_holiday"]');
    if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = 'force_holiday'; form.appendChild(f); }
    f.value = needForce ? '1' : '';
}, true);

function removeRoutineRow(btn) {
    var row = btn.closest('tr');
    var table = document.getElementById('routineTable');
    if (table.querySelectorAll('tbody tr').length <= 1) {
        alert('অন্তত একটা Subject থাকতে হবে।');
        return;
    }
    if (confirm('এই Subject টা রুটিন থেকে বাদ দিতে চান?')) {
        row.remove();
    }
}

(function () {
    var elClass   = document.getElementById('err_class');
    var elShift   = document.getElementById('err_shift');
    var elSection = document.getElementById('err_section');
    var elTerm    = document.getElementById('err_term');
    var elPart    = document.getElementById('err_part');
    var elHint    = document.getElementById('err_section_hint');
    var elWrap    = document.getElementById('err_result_wrap');
    var elPrintBtn = document.getElementById('err_print_btn');

    function errAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return '<?php echo !empty($_SESSION['teacher_logged']) ? '../admin/ajax/' : 'ajax/'; ?>result_exam_routine_ajax.php?action=' + action + '&' + p.toString();
    }

    function clearTable() {
        elWrap.innerHTML = '<div class="text-center text-muted py-4 no-print">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Subject এখানে দেখাবে।</div>';
        if (elPrintBtn) { elPrintBtn.style.display = 'none'; }
    }

    // Class/Shift বদলালে Section ড্রপডাউন AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadSections() {
        var classId = elClass.value, shiftId = elShift.value;
        elSection.innerHTML = '<option value="0">-- Select --</option>';
        clearTable();

        if (classId == '0' || shiftId == '0') {
            elHint.style.display = '';
            return;
        }
        elHint.style.display = 'none';

        fetch(errAjaxUrl('sections', { class_id: classId, shift_id: shiftId }))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                data.sections.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name;
                    elSection.appendChild(opt);
                });
            })
            .catch(function () { /* নেটওয়ার্ক সমস্যা হলে চুপচাপ থেমে যাওয়া, ইউজার আবার সিলেক্ট করলে রিট্রাই হবে */ });
    }

    // সবগুলো ফিল্ড সিলেক্ট হলে রুটিন টেবিল AJAX দিয়ে লোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value, shiftId = elShift.value, sectionId = elSection.value, termId = elTerm.value, partId = elPart.value;
        if (classId == '0' || shiftId == '0' || sectionId == '0' || termId == '0' || partId == '0') {
            clearTable();
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4 no-print"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(errAjaxUrl('table', { class_id: classId, shift_id: shiftId, section_id: sectionId, term_id: termId, part_id: partId }))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                elWrap.innerHTML = html;
                if (elPrintBtn) { elPrintBtn.style.display = ''; }
            })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4 no-print">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
                if (elPrintBtn) { elPrintBtn.style.display = 'none'; }
            });
    }

    elClass.addEventListener('change', loadSections);
    elShift.addEventListener('change', loadSections);
    elSection.addEventListener('change', loadTable);
    elTerm.addEventListener('change', loadTable);
    elPart.addEventListener('change', loadTable);
})();
</script>

<script>
// প্রিন্টে date/time ইনপুট বক্স না দেখিয়ে সুন্দর টেক্সট (তারিখ + বার, AM/PM) দেখানো
(function () {
    var DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    function fmt(inp) {
        var v = inp.value;
        if (!v) { return ''; }
        if (inp.type === 'date') {
            var p = v.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2]);
            return p[2] + '-' + p[1] + '-' + p[0] + ' (' + DAYS[d.getDay()] + ')';
        }
        if (inp.type === 'time') {
            var t = v.split(':'); var h = +t[0]; var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
            return (h < 10 ? '0' : '') + h + ':' + t[1] + ' ' + ap;
        }
        return v;
    }
    window.addEventListener('beforeprint', function () {
        document.querySelectorAll('#routineTable .er-print-val').forEach(function (n) { n.remove(); });
        document.querySelectorAll('#routineTable input[type="date"], #routineTable input[type="time"]').forEach(function (inp) {
            var sp = document.createElement('span'); sp.className = 'er-print-val'; sp.textContent = fmt(inp);
            inp.classList.add('er-hide-print'); inp.parentNode.insertBefore(sp, inp);
        });
    });
    window.addEventListener('afterprint', function () {
        document.querySelectorAll('#routineTable .er-print-val').forEach(function (n) { n.remove(); });
        document.querySelectorAll('#routineTable input.er-hide-print').forEach(function (n) { n.classList.remove('er-hide-print'); });
    });
})();
</script>
