<?php
require_once __DIR__ . '/../config/portal_access.php';
$result_portal_role = erp_portal_controller_role(['admin', 'teacher']);
erp_portal_require_role($pdo, $result_portal_role, 'result_formative_entry');
// admin/result_formative_entry.php - Result Module > Exam Procedure > Formative / Class Test Marks Entry
// Settings (result_formative_settings.php) অনুযায়ী প্রতিটা ছাত্রের Formative মার্ক বের করে নির্বাচিত Exam Part-এর
// exam_marks-এ সেভ করে — ফলে মার্কশিট/রেজাল্ট আলাদা কোড ছাড়াই এই মার্ক পায়।
//   Component মোড : প্রতিটা Component (০–১০০) × ওজন% → মোট % → × Formative Full Mark
//   Direct মোড    : Class Test assign করা থাকলে সেগুলো Average/Best N/Sum দিয়ে মিলিয়ে %, না থাকলে সরাসরি একটাই মার্ক
// ফলাফল কখনো Formative Full Mark-এর বেশি হয় না।
require_once __DIR__ . '/../config/report_card_helpers.php';

$fe_err = $_SESSION['fe_err'] ?? '';
$fe_msg = $_SESSION['fe_msg'] ?? '';
unset($_SESSION['fe_err'], $_SESSION['fe_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS formative_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_id INT NOT NULL,
        subject_id INT NOT NULL,
        student_id INT NOT NULL,
        kind VARCHAR(12) NOT NULL,
        ref_id INT NOT NULL DEFAULT 0,
        score DECIMAL(6,2) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_score (term_id, subject_id, student_id, kind, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// Settings পেজ কখনো না খুললে টেবিল না থাকতে পারে — তাই এখানেও একই গঠনে তৈরি করা হচ্ছে
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_settings (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, term_id INT NOT NULL DEFAULT 0, part_id INT NOT NULL DEFAULT 0, mode VARCHAR(20) NOT NULL DEFAULT 'Component', full_mark DECIMAL(6,2) NOT NULL DEFAULT 10, aggregate_method VARCHAR(20) NOT NULL DEFAULT 'Average', best_n INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'Active', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_scope (session_id, class_id, subject_id, term_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_components (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, name VARCHAR(100) NOT NULL, weight_percent DECIMAL(6,2) NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'Manual', rank INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'Active', KEY idx_scope (session_id, class_id, subject_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_tests (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, class_id INT NOT NULL, subject_id INT NOT NULL DEFAULT 0, test_name VARCHAR(100) NOT NULL, max_score DECIMAL(6,2) NOT NULL DEFAULT 10, status VARCHAR(20) NOT NULL DEFAULT 'Active', KEY idx_scope (session_id, class_id, subject_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS formative_test_terms (test_id INT NOT NULL, term_id INT NOT NULL, PRIMARY KEY (test_id, term_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS exam_marks (id INT AUTO_INCREMENT PRIMARY KEY, session_id INT NOT NULL, term_id INT NOT NULL, part_id INT NOT NULL, subject_id INT NOT NULL, class_id INT NOT NULL, shift_id INT NOT NULL, section_id INT NOT NULL, student_id INT NOT NULL, marks_obtained DECIMAL(6,2) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_mark (term_id, part_id, subject_id, student_id), KEY idx_lookup (class_id, shift_id, section_id, term_id, part_id, subject_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS student_daily_attendance (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, class_id INT NULL, section_id INT NULL, shift_id INT NULL, attendance_date DATE NOT NULL, status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent', marked_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_student_date (student_id, attendance_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$is_teacher_ctx = $result_portal_role === 'teacher';
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$sid = $current_session ? (int) $current_session['id'] : 0;

function fev($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fe_num($v): string { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

function fe_teacher_pairs(PDO $pdo, int $teacher_id): array {
    $stmt = $pdo->prepare(
        "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
         UNION
         SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL"
    );
    try { $stmt->execute([$teacher_id, $teacher_id]); return $stmt->fetchAll(); } catch (Throwable $e) { return []; }
}
function fe_teacher_allowed(PDO $pdo, int $teacher_id, int $class_id, int $subject_id): bool {
    foreach (fe_teacher_pairs($pdo, $teacher_id) as $a) {
        if ((int) $a['class_id'] === $class_id && (int) $a['subject_id'] === $subject_id) { return true; }
    }
    return false;
}

// Setting (subject+term > subject+সব term > সব subject+term > সব subject+সব term)
function fe_resolve(PDO $pdo, int $sid, int $class_id, int $subject_id, int $term_id): array {
    $setting = null;
    $q = $pdo->prepare("SELECT * FROM formative_settings WHERE session_id=? AND class_id=? AND subject_id=? AND term_id=? AND status='Active' LIMIT 1");
    foreach ([[$subject_id, $term_id], [$subject_id, 0], [0, $term_id], [0, 0]] as [$s, $t]) {
        $q->execute([$sid, $class_id, $s, $t]);
        $row = $q->fetch();
        if ($row) { $setting = $row; break; }
    }
    $components = [];
    $cq = $pdo->prepare("SELECT * FROM formative_components WHERE session_id=? AND class_id=? AND subject_id=? AND status='Active' ORDER BY rank ASC, id ASC");
    foreach ([$subject_id, 0] as $s) {
        $cq->execute([$sid, $class_id, $s]);
        $components = $cq->fetchAll();
        if ($components) { break; }
    }
    $tests = [];
    $tq = $pdo->prepare("SELECT t.* FROM formative_tests t JOIN formative_test_terms x ON x.test_id=t.id AND x.term_id=? WHERE t.session_id=? AND t.class_id=? AND t.subject_id=? AND t.status='Active' ORDER BY t.id ASC");
    foreach ([$subject_id, 0] as $s) {
        $tq->execute([$term_id, $sid, $class_id, $s]);
        $tests = $tq->fetchAll();
        if ($tests) { break; }
    }
    return [$setting, $components, $tests];
}

// একজন ছাত্রের মোট Formative মার্ক। $in = ['c'=>[compId=>score], 't'=>[testId=>score], 'd'=>score|null]। null = কিছু দেওয়া হয়নি।
function fe_compute(array $setting, array $components, array $tests, array $in): ?float {
    $full = (float) $setting['full_mark'];
    $pct = null; // ০–১০০
    if ($setting['mode'] === 'Component') {
        $sum = 0.0; $any = false;
        foreach ($components as $c) {
            $v = $in['c'][$c['id']] ?? null;
            if ($v === null) { continue; }
            $any = true;
            $sum += min(100.0, max(0.0, (float) $v)) * (float) $c['weight_percent'] / 100.0;
        }
        $pct = $any ? $sum : null;
    } elseif ($tests) {
        $ratios = []; $got = 0.0; $max_all = 0.0;
        foreach ($tests as $t) {
            $max_all += (float) $t['max_score'];
            $v = $in['t'][$t['id']] ?? null;
            if ($v === null) { continue; }
            $got += min((float) $t['max_score'], max(0.0, (float) $v));
            $ratios[] = min(1.0, max(0.0, (float) $v / (float) $t['max_score']));
        }
        if ($ratios) {
            if ($setting['aggregate_method'] === 'Sum') {
                $pct = $max_all > 0 ? $got / $max_all * 100 : null;
            } elseif ($setting['aggregate_method'] === 'Best N') {
                rsort($ratios);
                $top = array_slice($ratios, 0, max(1, (int) $setting['best_n']));
                $pct = array_sum($top) / count($top) * 100;
            } else {
                $pct = array_sum($ratios) / count($ratios) * 100;
            }
        }
    } else {
        $v = $in['d'] ?? null;
        $pct = ($v === null || $full <= 0) ? null : min($full, max(0.0, (float) $v)) / $full * 100;
    }
    if ($pct === null) { return null; }
    return round(min($full, $pct / 100.0 * $full), 2);
}

function fe_att_percent(PDO $pdo, int $student_id, string $from, string $to): ?float {
    $q = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='Present') AS present FROM student_daily_attendance WHERE student_id=? AND attendance_date BETWEEN ? AND ?");
    $q->execute([$student_id, $from, $to]);
    $r = $q->fetch();
    if (!$r || (int) $r['total'] === 0) { return null; }
    return round((int) $r['present'] / (int) $r['total'] * 100, 2);
}

$class_id   = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
$shift_id   = (int) ($_POST['shift_id'] ?? $_GET['shift_id'] ?? 0);
$section_id = (int) ($_POST['section_id'] ?? $_GET['section_id'] ?? 0);
$term_id    = (int) ($_POST['term_id'] ?? $_GET['term_id'] ?? 0);
$subject_id = (int) ($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
$att_from   = (string) ($_POST['att_from'] ?? $_GET['att_from'] ?? date('Y-01-01'));
$att_to     = (string) ($_POST['att_to'] ?? $_GET['att_to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_from)) { $att_from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_to)) { $att_to = date('Y-m-d'); }
$self = 'index.php?page=result_formative_entry';
$back = $self . '&class_id=' . $class_id . '&shift_id=' . $shift_id . '&section_id=' . $section_id . '&term_id=' . $term_id . '&subject_id=' . $subject_id . '&att_from=' . $att_from . '&att_to=' . $att_to;
$all_selected = $class_id && $shift_id && $section_id && $term_id && $subject_id;

function fe_students(PDO $pdo, int $class_id, int $shift_id, int $section_id): array {
    $where = ['s.class_id=?', 's.shift_id=?', 's.section_id=?', "s.status='Active'"];
    $params = [$class_id, $shift_id, $section_id];
    branch_apply_filter($where, $params, 's.branch_id');
    $st = $pdo->prepare("SELECT s.id, s.student_id, s.roll_no, s.name_en FROM students s WHERE " . implode(' AND ', $where) . " ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC");
    $st->execute($params);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_formative'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['fe_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (!$all_selected) {
        $_SESSION['fe_err'] = 'Class, Shift, Section, Exam Term ও Subject সিলেক্ট করুন।';
    } elseif ($is_teacher_ctx && !fe_teacher_allowed($pdo, $me_teacher_id, $class_id, $subject_id)) {
        $_SESSION['fe_err'] = 'এই Class/Subject আপনাকে এসাইন করা হয়নি, তাই মার্ক সেভ করা যায়নি।';
    } elseif (rc_is_result_locked($pdo, $sid, $class_id, $term_id)) {
        $_SESSION['fe_err'] = 'এই Class-এর এই Term-এর রেজাল্ট Lock করা আছে, তাই মার্ক এন্ট্রি/এডিট করা যাবে না।';
    } else {
        [$setting, $components, $tests] = fe_resolve($pdo, $sid, $class_id, $subject_id, $term_id);
        if (!$setting || (int) $setting['part_id'] <= 0) {
            $_SESSION['fe_err'] = 'এই Class/Subject/Term-এর জন্য Formative সেটিং করা নেই।';
        } elseif ($setting['mode'] === 'Component' && !$components) {
            $_SESSION['fe_err'] = 'Component মোডে কোনো সক্রিয় Component নেই।';
        } else {
            $wsum = 0.0; foreach ($components as $c) { $wsum += (float) $c['weight_percent']; }
            if ($setting['mode'] === 'Component' && abs($wsum - 100) > 0.0001) {
                $_SESSION['fe_err'] = 'Component-এর ওজনের যোগফল ' . fe_num($wsum) . '% — ১০০% হতে হবে (Settings পেজে ঠিক করুন)।';
            } else {
                $part_id = (int) $setting['part_id'];
                $allowed_ids = array_flip(array_map(function ($s) { return (int) $s['id']; }, fe_students($pdo, $class_id, $shift_id, $section_id)));
                $del = $pdo->prepare("DELETE FROM formative_scores WHERE term_id=? AND subject_id=? AND student_id=? AND kind=? AND ref_id=?");
                $ins = $pdo->prepare("INSERT INTO formative_scores (session_id, term_id, subject_id, student_id, kind, ref_id, score) VALUES (?,?,?,?,?,?,?)");
                $mk  = $pdo->prepare("INSERT INTO exam_marks (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, marks_obtained) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE marks_obtained=VALUES(marks_obtained)");
                $saved = 0; $bad = 0;
                $parse = function ($raw, float $max) use (&$bad) {
                    $raw = trim((string) $raw);
                    if ($raw === '') { return null; }
                    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $max + 0.0001) { $bad++; return false; }
                    return (float) $raw;
                };
                foreach ((array) ($_POST['s'] ?? []) as $stid => $row) {
                    $stid = (int) $stid;
                    if (!isset($allowed_ids[$stid]) || !is_array($row)) { continue; }
                    $in = ['c' => [], 't' => [], 'd' => null];
                    $writes = []; $skip = false;
                    if ($setting['mode'] === 'Component') {
                        foreach ($components as $c) {
                            $v = $parse($row['c' . $c['id']] ?? '', 100.0);
                            if ($v === false) { $skip = true; break; }
                            $in['c'][$c['id']] = $v;
                            $writes[] = ['component', (int) $c['id'], $v];
                        }
                    } elseif ($tests) {
                        foreach ($tests as $t) {
                            $v = $parse($row['t' . $t['id']] ?? '', (float) $t['max_score']);
                            if ($v === false) { $skip = true; break; }
                            $in['t'][$t['id']] = $v;
                            $writes[] = ['test', (int) $t['id'], $v];
                        }
                    } else {
                        $v = $parse($row['d'] ?? '', (float) $setting['full_mark']);
                        if ($v === false) { $skip = true; }
                        $in['d'] = $v;
                        $writes[] = ['direct', 0, $v];
                    }
                    if ($skip) { continue; }
                    foreach ($writes as [$kind, $ref, $v]) {
                        $del->execute([$term_id, $subject_id, $stid, $kind, $ref]);
                        if ($v !== null) { $ins->execute([$sid, $term_id, $subject_id, $stid, $kind, $ref, $v]); }
                    }
                    $total = fe_compute($setting, $components, $tests, $in);
                    $mk->execute([$sid, $term_id, $part_id, $subject_id, $class_id, $shift_id, $section_id, $stid, $total]);
                    $saved++;
                }
                $_SESSION['fe_msg'] = $saved . ' জনের Formative মার্ক সেভ হয়েছে।' . ($bad ? " ($bad টা ঘরে সীমার বাইরের মান ছিল, সেই ছাত্রদের সেভ হয়নি)" : '');
                if (function_exists('sec_log_activity')) { sec_log_activity($pdo, 'Result', 'Formative Marks', "Class {$class_id}, Subject {$subject_id}, Term {$term_id}: {$saved} students"); }
            }
        }
    }
    header('Location: ' . $back); exit();
}

// ---------------- ডিসপ্লে ----------------
if ($is_teacher_ctx) {
    $pairs = fe_teacher_pairs($pdo, $me_teacher_id);
    $cls_ids = array_values(array_unique(array_map(function ($a) { return (int) $a['class_id']; }, $pairs)));
    if ($cls_ids) {
        $in_ph = implode(',', array_fill(0, count($cls_ids), '?'));
        $cq = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE status='Active' AND id IN ($in_ph) ORDER BY rank ASC, class_name ASC");
        $cq->execute($cls_ids); $classes = $cq->fetchAll();
    } else { $classes = []; }
} else {
    $classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
}
$shifts = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status='Active' ORDER BY shift_name ASC")->fetchAll();
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
    if ($is_teacher_ctx) {
        $ok = [];
        foreach ($pairs as $a) { if ((int) $a['class_id'] === $class_id) { $ok[(int) $a['subject_id']] = true; } }
        $subjects = array_values(array_filter($subjects, function ($s) use ($ok) { return isset($ok[(int) $s['id']]); }));
    }
}

$setting = null; $components = []; $tests = []; $students = []; $saved = []; $att_auto = []; $problem = '';
if ($all_selected) {
    if ($is_teacher_ctx && !fe_teacher_allowed($pdo, $me_teacher_id, $class_id, $subject_id)) {
        $problem = 'এই Class/Subject আপনাকে এসাইন করা হয়নি।';
    } else {
        [$setting, $components, $tests] = fe_resolve($pdo, $sid, $class_id, $subject_id, $term_id);
        if (!$setting) {
            $problem = 'এই Class/Subject/Term-এর জন্য Formative সেটিং করা নেই। আগে Exam Settings > Formative / Class Test পেজে সেটিং করুন।';
        } elseif ($setting['mode'] === 'Component' && !$components) {
            $problem = 'Component মোডে কোনো সক্রিয় Component নেই (Settings পেজে যোগ করুন)।';
        } else {
            $students = fe_students($pdo, $class_id, $shift_id, $section_id);
            $sq = $pdo->prepare("SELECT student_id, kind, ref_id, score FROM formative_scores WHERE term_id=? AND subject_id=?");
            $sq->execute([$term_id, $subject_id]);
            foreach ($sq->fetchAll() as $r) { $saved[(int) $r['student_id']][$r['kind'] . ':' . $r['ref_id']] = $r['score']; }
            $has_att = false;
            foreach ($components as $c) { if ($c['source'] === 'Attendance') { $has_att = true; } }
            if ($has_att) { foreach ($students as $s) { $att_auto[(int) $s['id']] = fe_att_percent($pdo, (int) $s['id'], $att_from, $att_to); } }
        }
    }
}
?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <h4 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-pen-to-square"></i> Formative / Class Test Marks Entry</h4>
    <p class="text-muted small mb-3">Settings অনুযায়ী মার্ক বের হয়ে নির্ধারিত Exam Part-এ সেভ হবে। মোট মার্ক কখনো Formative Full Mark-এর বেশি হবে না।</p>
    <?php if ($fe_err): ?><div class="alert alert-danger py-2"><?php echo fev($fe_err); ?></div><?php endif; ?>
    <?php if ($fe_msg): ?><div class="alert alert-success py-2"><?php echo fev($fe_msg); ?></div><?php endif; ?>
    <form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="page" value="result_formative_entry">
        <div class="col-md-2"><label class="form-label">Class</label><select name="class_id" class="form-select" onchange="this.form.submit()"><option value="0">—</option><?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fev($c['class_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Shift</label><select name="shift_id" class="form-select" onchange="this.form.submit()"><option value="0">—</option><?php foreach ($shifts as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $shift_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fev($c['shift_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Section</label><select name="section_id" class="form-select"><option value="0">—</option><?php foreach ($sections as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $section_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fev($c['section_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Exam Term</label><select name="term_id" class="form-select"><option value="0">—</option><?php foreach ($terms as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $term_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fev($c['term_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Subject</label><select name="subject_id" class="form-select"><option value="0">—</option><?php foreach ($subjects as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $subject_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo fev($c['subject_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">দেখুন</button></div>
        <div class="col-md-3"><label class="form-label small">উপস্থিতি হিসাবের তারিখ: শুরু</label><input type="date" name="att_from" class="form-control form-control-sm" value="<?php echo fev($att_from); ?>"></div>
        <div class="col-md-3"><label class="form-label small">শেষ</label><input type="date" name="att_to" class="form-control form-control-sm" value="<?php echo fev($att_to); ?>"></div>
    </form>

    <?php if (!$all_selected): ?>
        <div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term ও Subject সিলেক্ট করুন।</div>
    <?php elseif ($problem): ?>
        <div class="alert alert-warning"><?php echo fev($problem); ?></div>
    <?php elseif (!$students): ?>
        <div class="text-center text-muted py-4">এই Class/Shift/Section-এ কোনো Active Student পাওয়া যায়নি।</div>
    <?php else:
        $mode_direct_tests = $setting['mode'] !== 'Component' && $tests;
        $mode_direct_single = $setting['mode'] !== 'Component' && !$tests; ?>
        <div class="alert alert-info py-2 small">
            Formative Full Mark: <strong><?php echo fe_num($setting['full_mark']); ?></strong> ·
            <?php if ($setting['mode'] === 'Component'): ?>মোড: Component (প্রতিটা ঘর ০–১০০, ওজন অনুযায়ী মিলে যাবে)
            <?php elseif ($tests): ?>মোড: Class Test — <?php echo fev($setting['aggregate_method']); ?><?php echo $setting['aggregate_method'] === 'Best N' ? ' (' . (int) $setting['best_n'] . ')' : ''; ?>
            <?php else: ?>মোড: Direct (সরাসরি মার্ক, সর্বোচ্চ <?php echo fe_num($setting['full_mark']); ?>)<?php endif; ?>
        </div>
        <form method="POST" action="<?php echo fev($self); ?>" id="feForm">
            <?php echo csrf_field(); ?><input type="hidden" name="save_formative" value="1">
            <input type="hidden" name="class_id" value="<?php echo $class_id; ?>"><input type="hidden" name="shift_id" value="<?php echo $shift_id; ?>"><input type="hidden" name="section_id" value="<?php echo $section_id; ?>"><input type="hidden" name="term_id" value="<?php echo $term_id; ?>"><input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
            <input type="hidden" name="att_from" value="<?php echo fev($att_from); ?>"><input type="hidden" name="att_to" value="<?php echo fev($att_to); ?>">
            <div class="table-responsive"><table class="table table-bordered table-sm align-middle" id="feTable">
                <thead class="table-light"><tr><th>Sl.</th><th>ID</th><th>Name</th><th>Roll</th>
                    <?php if ($setting['mode'] === 'Component'): foreach ($components as $c): ?><th class="text-center"><?php echo fev($c['name']); ?><br><small class="text-muted"><?php echo fe_num($c['weight_percent']); ?>%<?php echo $c['source'] === 'Attendance' ? ' · অটো' : ''; ?></small></th><?php endforeach;
                    elseif ($tests): foreach ($tests as $t): ?><th class="text-center"><?php echo fev($t['test_name']); ?><br><small class="text-muted">/ <?php echo fe_num($t['max_score']); ?></small></th><?php endforeach;
                    else: ?><th class="text-center">Formative<br><small class="text-muted">/ <?php echo fe_num($setting['full_mark']); ?></small></th><?php endif; ?>
                    <th class="text-center">মোট<br><small class="text-muted">/ <?php echo fe_num($setting['full_mark']); ?></small></th></tr></thead>
                <tbody>
                <?php foreach ($students as $i => $s): $stid = (int) $s['id']; $sv = $saved[$stid] ?? []; ?>
                    <tr data-st="<?php echo $stid; ?>"><td><?php echo $i + 1; ?></td><td><?php echo fev($s['student_id']); ?></td><td><?php echo fev($s['name_en']); ?></td><td><?php echo fev($s['roll_no']); ?></td>
                    <?php if ($setting['mode'] === 'Component'): foreach ($components as $c):
                        $val = $sv['component:' . $c['id']] ?? null;
                        if ($val === null && $c['source'] === 'Attendance') { $val = $att_auto[$stid] ?? null; } ?>
                        <td><input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm fe-in" data-w="<?php echo fev($c['weight_percent']); ?>" data-max="100" name="s[<?php echo $stid; ?>][c<?php echo (int) $c['id']; ?>]" value="<?php echo $val === null ? '' : fev(fe_num($val)); ?>"></td>
                    <?php endforeach; elseif ($tests): foreach ($tests as $t): $val = $sv['test:' . $t['id']] ?? null; ?>
                        <td><input type="number" step="0.01" min="0" max="<?php echo fev($t['max_score']); ?>" class="form-control form-control-sm fe-in" data-max="<?php echo fev($t['max_score']); ?>" name="s[<?php echo $stid; ?>][t<?php echo (int) $t['id']; ?>]" value="<?php echo $val === null ? '' : fev(fe_num($val)); ?>"></td>
                    <?php endforeach; else: $val = $sv['direct:0'] ?? null; ?>
                        <td><input type="number" step="0.01" min="0" max="<?php echo fev($setting['full_mark']); ?>" class="form-control form-control-sm fe-in" data-max="<?php echo fev($setting['full_mark']); ?>" name="s[<?php echo $stid; ?>][d]" value="<?php echo $val === null ? '' : fev(fe_num($val)); ?>"></td>
                    <?php endif; ?>
                        <td class="text-center fw-bold fe-total">—</td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div class="text-end"><button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk"></i> সব সেভ করুন</button></div>
        </form>
        <script>
        (function () {
            var S = <?php echo json_encode([
                'mode' => $setting['mode'], 'full' => (float) $setting['full_mark'], 'agg' => $setting['aggregate_method'], 'n' => (int) $setting['best_n'],
                'direct_tests' => (bool) $mode_direct_tests,
            ]); ?>;
            function calc(tr) {
                var ins = tr.querySelectorAll('.fe-in'), vals = [], pct = null, i;
                if (S.mode === 'Component') {
                    var sum = 0, any = false;
                    for (i = 0; i < ins.length; i++) { if (ins[i].value === '') continue; any = true; sum += Math.min(100, Math.max(0, +ins[i].value)) * (+ins[i].dataset.w) / 100; }
                    pct = any ? sum : null;
                } else if (S.direct_tests) {
                    var ratios = [], got = 0, mx = 0;
                    for (i = 0; i < ins.length; i++) { var m = +ins[i].dataset.max; mx += m; if (ins[i].value === '') continue; var v = Math.min(m, Math.max(0, +ins[i].value)); got += v; ratios.push(v / m); }
                    if (ratios.length) {
                        if (S.agg === 'Sum') pct = mx > 0 ? got / mx * 100 : null;
                        else if (S.agg === 'Best N') { ratios.sort(function (a, b) { return b - a; }); var top = ratios.slice(0, Math.max(1, S.n)); pct = top.reduce(function (a, b) { return a + b; }, 0) / top.length * 100; }
                        else pct = ratios.reduce(function (a, b) { return a + b; }, 0) / ratios.length * 100;
                    }
                } else {
                    if (ins[0].value !== '') pct = Math.min(S.full, Math.max(0, +ins[0].value)) / S.full * 100;
                }
                return pct === null ? null : Math.round(Math.min(S.full, pct / 100 * S.full) * 100) / 100;
            }
            function refresh(tr) { var t = calc(tr); tr.querySelector('.fe-total').textContent = t === null ? '—' : t; }
            document.querySelectorAll('#feTable tbody tr').forEach(function (tr) {
                refresh(tr);
                tr.addEventListener('input', function () { refresh(tr); });
            });
        })();
        </script>
    <?php endif; ?>
</div></div>
