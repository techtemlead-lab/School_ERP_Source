<?php
// admin/result_download_admit_card.php - Result Module > Result Reports > Download Admit Card
// General (নিয়মিত পরীক্ষা) ও Admission (ভর্তি পরীক্ষা) — দুই ধরনের অ্যাডমিট কার্ড জেনারেশন।
// লেআউট ও কাস্টমাইজেশন Customize Admit Card থেকে নিয়ন্ত্রিত হয়; এখানে পেজ টাইপ, মার্জিন ও কালার/গ্রেস্কেল নির্বাচন করা যায়।

require_once __DIR__ . '/../config/seat_card_helper.php';
function dacv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function dac_print_fields(string $paper, string $margin, string $color_mode, bool $auto_submit = false): void
{
    $on_chg_pm  = $auto_submit ? ' onchange="this.form.submit()"' : '';
    $on_chg_col = $auto_submit ? ' onchange="dacToggleColorMode(this.value);this.form.submit()"' : '';
    $papers = ['A4' => 'A4', 'Legal' => 'Legal', 'Letter' => 'Letter'];
    $margins = [
        'normal' => 'Normal (স্বাভাবিক)',
        'narrow' => 'Narrow (চিকন)',
        'wide'   => 'Wide (প্রশস্ত)',
        'none'   => 'None (শূন্য)',
    ];
    echo '<label class="small fw-bold mb-0">Page Type:</label>'
       . '<select name="paper_size" data-ss="off" class="form-select form-select-sm" style="width:auto;" title="Paper Size"' . $on_chg_pm . '>';
    foreach ($papers as $k => $lbl) {
        echo '<option value="' . $k . '"' . ($paper === $k ? ' selected' : '') . '>' . dacv($lbl) . '</option>';
    }
    echo '</select>'
       . '<label class="small fw-bold mb-0 ms-1">Margin:</label>'
       . '<select name="page_margin" data-ss="off" class="form-select form-select-sm" style="width:auto;" title="Page Margin"' . $on_chg_pm . '>';
    foreach ($margins as $k => $lbl) {
        echo '<option value="' . $k . '"' . ($margin === $k ? ' selected' : '') . '>' . dacv($lbl) . '</option>';
    }
    echo '</select>'
       . '<label class="small fw-bold mb-0 ms-1">Mode:</label>'
       . '<select name="color_mode" id="dacColorMode" data-ss="off" class="form-select form-select-sm" style="width:auto;" title="Print Color Mode"' . $on_chg_col . '>'
       . '<option value="color"' . ($color_mode === 'color' ? ' selected' : '') . '>Color</option>'
       . '<option value="grayscale"' . ($color_mode === 'grayscale' ? ' selected' : '') . '>Grayscale (B&amp;W)</option>'
       . '</select>'
       . '<script>function dacToggleColorMode(v){var p=document.getElementById("dacPages");if(p){p.classList.toggle("ac-gray",v==="grayscale");}}</script>';
}

function dac_routine_block_url(array $g): string
{
    $qs = ['page' => 'result_exam_routine'];
    foreach (['class_id', 'shift_id', 'section_id', 'term_id', 'part_id'] as $k) {
        if (!empty($g[$k]) && (int) $g[$k] > 0) {
            $qs[$k] = (int) $g[$k];
        }
    }
    return 'index.php?' . http_build_query($qs);
}

function dac_fetch_routine_rows(PDO $pdo, array $g): array
{
    $cid = (int) ($g['class_id'] ?? 0);
    $tid = (int) ($g['term_id'] ?? 0);
    if ($cid <= 0 || $tid <= 0) {
        return [];
    }
    try {
        $er_where = [
            'er.class_id = ?',
            'er.term_id = ?',
            "er.status = 'Active'",
            '(er.exam_date IS NOT NULL OR er.start_time IS NOT NULL)',
        ];
        $er_params = [$cid, $tid];
        if (!empty($g['shift_id']))   { $er_where[] = 'er.shift_id = ?';   $er_params[] = (int) $g['shift_id']; }
        if (!empty($g['section_id'])) { $er_where[] = 'er.section_id = ?'; $er_params[] = (int) $g['section_id']; }
        if (!empty($g['part_id']))    { $er_where[] = 'er.part_id = ?';    $er_params[] = (int) $g['part_id']; }
        $er_stmt = $pdo->prepare(
            "SELECT er.shift_id, er.section_id, er.part_id, er.subject_id,
                    er.exam_date, er.start_time, er.end_time,
                    s.subject_name, s.full_marks
             FROM exam_routines er
             LEFT JOIN academic_subjects s ON s.id = er.subject_id
             WHERE " . implode(' AND ', $er_where) . "
             ORDER BY (er.exam_date IS NULL) ASC, er.exam_date ASC, er.start_time ASC"
        );
        $er_stmt->execute($er_params);
        $rows = [];
        foreach ($er_stmt->fetchAll(PDO::FETCH_ASSOC) as $er_row) {
            $st_t = !empty($er_row['start_time']) ? strtotime($er_row['start_time']) : false;
            $en_t = !empty($er_row['end_time']) ? strtotime($er_row['end_time']) : false;
            $t_str = '';
            if ($st_t !== false && $en_t !== false) {
                $t_str = date('g:i A', $st_t) . ' - ' . date('g:i A', $en_t);
            } elseif ($st_t !== false) {
                $t_str = date('g:i A', $st_t);
            }
            $d_raw = trim((string) ($er_row['exam_date'] ?? ''));
            $d_disp = ($d_raw !== '' && $d_raw !== '0000-00-00' && strtotime($d_raw) !== false)
                ? date('d M Y (D)', strtotime($d_raw))
                : '—';
            $rows[] = [
                'shift_id'   => (int) ($er_row['shift_id'] ?? 0),
                'section_id' => (int) ($er_row['section_id'] ?? 0),
                'part_id'    => (int) ($er_row['part_id'] ?? 0),
                'subject_id' => (int) ($er_row['subject_id'] ?? 0),
                'raw_date'   => $d_raw,
                'date'       => $d_disp,
                'time'       => $t_str ?: '—',
                'subject'    => trim((string) ($er_row['subject_name'] ?? '')) ?: '—',
                'full_marks' => isset($er_row['full_marks']) && $er_row['full_marks'] !== null ? (string) $er_row['full_marks'] : '',
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dac_student_routine(array $all_rows, array $st): array
{
    if (!$all_rows) {
        return [];
    }
    $st_shift   = (int) ($st['shift_id'] ?? 0);
    $st_section = (int) ($st['section_id'] ?? 0);
    $picked = [];
    if ($st_shift > 0 && $st_section > 0) {
        $picked = array_values(array_filter(
            $all_rows,
            static fn($r) => (int) $r['shift_id'] === $st_shift && (int) $r['section_id'] === $st_section
        ));
    }
    if (!$picked && $st_shift > 0) {
        $picked = array_values(array_filter(
            $all_rows,
            static fn($r) => (int) $r['shift_id'] === $st_shift
        ));
    }
    if (!$picked) {
        $picked = $all_rows;
    }
    $seen = [];
    $out = [];
    foreach ($picked as $r) {
        $key = ((int) $r['subject_id']) . '|' . ($r['raw_date'] ?? '') . '|' . ($r['time'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $r;
    }
    return $out;
}

function dac_render_routine_block_alert(string $cls_name, string $trm_name, string $routine_url, bool $can_routine, bool $can_customize): void
{
    $scope = implode(' — ', array_filter([$cls_name, $trm_name], static fn($v) => $v !== ''));
    echo '<div class="no-print alert alert-warning border-warning shadow-sm mb-3">'
       . '<div class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation text-danger me-1"></i> Exam Routine না থাকায় Admit Card জেনারেট ব্লক করা হয়েছে</div>'
       . '<div class="small mb-2">';
    if ($scope !== '') {
        echo '<strong>' . dacv($scope) . '</strong>-এর জন্য ';
    }
    echo '<strong>Result Module &gt; Exam Procedure &gt; Exam Routine</strong> মডিউলে এখনো কোনো Exam Routine যোগ করা হয়নি। অ্যাডমিট কার্ড জেনারেট করতে চাইলে ওখানে গিয়ে আগে এই শ্রেণি/পরীক্ষার Exam Routine যোগ করে আসুন, অথবা Customize Admit Card সেটিংস থেকে <strong>Exam Routine</strong> অপশনটি অফ (OFF) করে দিন।</div>'
       . '<div class="d-flex gap-2 flex-wrap">';
    if ($can_routine) {
        echo '<a href="' . dacv($routine_url) . '" class="btn btn-sm btn-primary fw-semibold"><i class="fa-solid fa-calendar-plus me-1"></i> Exam Routine যোগ করুন</a>';
    }
    if ($can_customize) {
        echo '<a href="index.php?page=result_admit_card_customize&admit_type=general#dacRoutineSection" class="btn btn-sm btn-outline-secondary fw-semibold"><i class="fa-solid fa-sliders me-1"></i> Customize Admit Card-এ রুটিন অফ করুন</a>';
    }
    echo '</div></div>';
}

$dac_err = $_SESSION['dac_err'] ?? '';
$dac_msg = $_SESSION['dac_msg'] ?? '';
$dac_routine_block = $_SESSION['dac_routine_block'] ?? null;
unset($_SESSION['dac_err'], $_SESSION['dac_msg'], $_SESSION['dac_routine_block']);

// -----------------------------------------------------------
// বর্তমান একাডেমিক সেশন + প্রতিষ্ঠানের তথ্য
// -----------------------------------------------------------
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

$institute = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$addr_line = $branch ? ($branch['address'] . ', ' . $branch['city'] . '-' . $branch['zip_code']) : '';
$inst_display_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? '');
$instr_branch_id = $fr_bid2;
$can_customize = !function_exists('perm_page_allowed') || perm_page_allowed($pdo, 'result_admit_card_customize');
$can_routine   = !function_exists('perm_page_allowed') || perm_page_allowed($pdo, 'result_exam_routine');

$view = $_GET['view'] ?? 'config';
if ($view === 'output' && !empty($_SESSION['dac_generate']['admit_type'])) {
    $admit_type = ac_normalize_type((string) $_SESSION['dac_generate']['admit_type']);
} elseif (isset($_POST['admit_type'])) {
    $admit_type = ac_normalize_type((string) $_POST['admit_type']);
} elseif (isset($_GET['admit_type'])) {
    $admit_type = ac_normalize_type((string) $_GET['admit_type']);
} else {
    $admit_type = ac_normalize_type((string) ($_SESSION['dac_generate']['admit_type'] ?? 'general'));
}

// -----------------------------------------------------------
// POST: ফর্ম সাবমিট -> পেজ/মার্জিন/কালার সংরক্ষণ + রুটিন যাচাই -> output ভিউ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_admit_cards'])) {
    $post_type = ac_normalize_type($_POST['admit_type'] ?? 'general');
    $back_url = 'index.php?page=result_download_admit_card&admit_type=' . $post_type;

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dac_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $back_url);
        exit();
    }

    $f_class_id = (int) ($_POST['class_id'] ?? 0);
    $f_term_id  = (int) ($_POST['term_id'] ?? 0);

    if ($f_class_id <= 0 || ($post_type === 'general' && $f_term_id <= 0)) {
        $_SESSION['dac_err'] = $post_type === 'admission'
            ? 'Class নির্বাচন করা আবশ্যক।'
            : 'Class এবং Term আবশ্যক।';
        header('Location: ' . $back_url);
        exit();
    }

    $p_paper = in_array($_POST['paper_size'] ?? 'A4', ['A4', 'Letter', 'Legal'], true) ? (string) $_POST['paper_size'] : 'A4';
    $p_margin = in_array($_POST['page_margin'] ?? 'normal', ['normal', 'narrow', 'wide', 'none'], true) ? (string) $_POST['page_margin'] : 'normal';
    $p_color = (($_POST['color_mode'] ?? 'color') === 'grayscale') ? 'grayscale' : 'color';
    $_SESSION['dac_paper'] = $p_paper;
    $_SESSION['dac_margin'] = $p_margin;
    $_SESSION['dac_color_' . $post_type] = $p_color;

    $post_g = [
        'admit_type'            => $post_type,
        'class_id'              => $f_class_id,
        'shift_id'              => (int) ($_POST['shift_id'] ?? 0),
        'section_id'            => (int) ($_POST['section_id'] ?? 0),
        'term_id'               => $post_type === 'general' ? $f_term_id : 0,
        'part_id'               => $post_type === 'general' ? (int) ($_POST['part_id'] ?? 0) : 0,
        'student_id_row'        => (int) ($_POST['student_id_row'] ?? 0),
        'admission_title'       => mb_substr(trim((string) ($_POST['admission_title'] ?? 'Admission Test')), 0, 120) ?: 'Admission Test',
        'admission_exam_date'   => mb_substr(trim((string) ($_POST['admission_exam_date'] ?? '')), 0, 150),
        'admission_venue'       => mb_substr(trim((string) ($_POST['admission_venue'] ?? '')), 0, 180),
        'admission_result_date' => mb_substr(trim((string) ($_POST['admission_result_date'] ?? '')), 0, 150),
    ];

    $post_ac_settings = ac_load_settings($pdo, $instr_branch_id, $post_type);
    $post_preset = ac_normalize_layout_key((string) ($post_ac_settings['default_layout'] ?? '1full_modern'), (string) ($post_ac_settings['full_template'] ?? 'modern'));
    $routine_required = ($post_type === 'general')
        && !empty($post_ac_settings['show_routine'])
        && ($post_preset === '1full_modern');

    if ($routine_required && !dac_fetch_routine_rows($pdo, $post_g)) {
        unset($_SESSION['dac_generate']);
        $cls_q = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
        $cls_q->execute([$f_class_id]);
        $trm_q = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?");
        $trm_q->execute([$f_term_id]);
        $_SESSION['dac_routine_block'] = [
            'class_name'  => trim((string) ($cls_q->fetchColumn() ?: '')),
            'term_name'   => trim((string) ($trm_q->fetchColumn() ?: '')),
            'routine_url' => dac_routine_block_url($post_g),
        ];
        header('Location: ' . $back_url);
        exit();
    }

    $_SESSION['dac_generate'] = $post_g;
    header('Location: index.php?page=result_download_admit_card&view=output&admit_type=' . $post_type);
    exit();
}

$ac_settings      = ac_load_settings($pdo, $instr_branch_id, $admit_type);
$dac_instructions = ac_load_instructions($pdo, $instr_branch_id, $admit_type);

// পেজ সাইজ, মার্জিন এবং কালার মোড (GET > SESSION > কাস্টমাইজেশন ডিফল্ট)
$dac_paper = in_array($_GET['paper_size'] ?? ($_SESSION['dac_paper'] ?? 'A4'), ['A4', 'Letter', 'Legal'], true)
    ? (string) ($_GET['paper_size'] ?? ($_SESSION['dac_paper'] ?? 'A4'))
    : 'A4';
$dac_margin = in_array($_GET['page_margin'] ?? ($_SESSION['dac_margin'] ?? 'normal'), ['normal', 'narrow', 'wide', 'none'], true)
    ? (string) ($_GET['page_margin'] ?? ($_SESSION['dac_margin'] ?? 'normal'))
    : 'normal';

if (isset($_GET['color_mode'])) {
    $dac_color = ($_GET['color_mode'] === 'grayscale') ? 'grayscale' : 'color';
} elseif (isset($_SESSION['dac_color_' . $admit_type])) {
    $dac_color = ($_SESSION['dac_color_' . $admit_type] === 'grayscale') ? 'grayscale' : 'color';
} else {
    $dac_color = (($ac_settings['default_color_mode'] ?? 'color') === 'grayscale') ? 'grayscale' : 'color';
}
$_SESSION['dac_paper'] = $dac_paper;
$_SESSION['dac_margin'] = $dac_margin;
$_SESSION['dac_color_' . $admit_type] = $dac_color;

// লেআউট সর্বদা Customize Admit Card থেকে আসে
$dac_layout = ac_layout([
    'preset'        => $ac_settings['default_layout'] ?? '1full_modern',
    'full_template' => $ac_settings['full_template'] ?? 'modern',
    'ori'           => $ac_settings['default_ori'] ?? 'portrait',
    'w'             => $ac_settings['default_w'] ?? 94,
    'h'             => $ac_settings['default_h'] ?? 135,
    'gap'           => $ac_settings['default_gap'] ?? 4,
    'paper'         => $dac_paper,
    'margin'        => $dac_margin,
]);
$layout_labels = ac_layout_labels();
$active_layout_label = $layout_labels[$dac_layout['preset']] ?? $dac_layout['preset'];

$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$terms    = $current_session_id
    ? $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($terms) { $terms->execute([$current_session_id]); $terms = $terms->fetchAll(); } else { $terms = []; }
$parts = $current_session_id
    ? $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($parts) { $parts->execute([$current_session_id]); $parts = $parts->fetchAll(); } else { $parts = []; }

$target_stu_status = ($admit_type === 'admission') ? 'Pending' : 'Active';
$rdac_where = ["status = ?"];
$rdac_params = [$target_stu_status];
branch_apply_filter($rdac_where, $rdac_params);
$rdac_stmt = $pdo->prepare("SELECT id, name_en, student_id FROM students WHERE " . implode(' AND ', $rdac_where) . " ORDER BY name_en ASC LIMIT 300");
$rdac_stmt->execute($rdac_params);
$all_students = $rdac_stmt->fetchAll();
?>

<?php if ($view === 'output'): ?>
    <?php
    // ================= OUTPUT VIEW: অ্যাডমিট কার্ড জেনারেট =================
    $g = $_SESSION['dac_generate'] ?? [];
    if (!$g) {
        header('Location: index.php?page=result_download_admit_card&admit_type=' . $admit_type);
        exit();
    }

    $out_stu_status = ($admit_type === 'admission') ? 'Pending' : 'Active';
    $where = ["students.status = ?"];
    $params = [$out_stu_status];
    if (!empty($g['class_id']))       { $where[] = 'students.class_id = ?'; $params[] = $g['class_id']; }
    if (!empty($g['shift_id']))       { $where[] = 'students.shift_id = ?'; $params[] = $g['shift_id']; }
    if (!empty($g['section_id']))     { $where[] = 'students.section_id = ?'; $params[] = $g['section_id']; }
    if (!empty($g['student_id_row'])) { $where[] = 'students.id = ?'; $params[] = $g['student_id_row']; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT students.*,
                   academic_classes.class_name,
                   academic_mediums.medium_name AS class_medium_name,
                   academic_sections.section_name,
                   academic_shifts.shift_name,
                   academic_groups.group_name,
                   academic_sessions.session_name,
                   branches.branch_name AS student_branch_name,
                   branches.short_name AS student_branch_short,
                   branches.address AS student_branch_address,
                   branches.city AS student_branch_city,
                   branches.zip_code AS student_branch_zip,
                   branches.phone AS student_branch_phone,
                   branches.email AS student_branch_email,
                   branches.signature_path AS student_branch_sig
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_mediums ON academic_mediums.id = academic_classes.medium_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
            LEFT JOIN academic_groups ON academic_groups.id = students.group_id
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN branches ON branches.id = students.branch_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (students.roll_no + 0) ASC, students.roll_no ASC, students.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $school_version = 'All';
    try {
        $med_list = $pdo->query("SELECT DISTINCT medium_name FROM academic_mediums WHERE status = 'Active' AND medium_name IS NOT NULL AND medium_name != '' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (count($med_list) === 1) {
            $school_version = trim((string) $med_list[0]);
        } elseif (count($med_list) > 1) {
            $school_version = implode(' & ', array_map('trim', $med_list));
        }
    } catch (Throwable $e) {
        $school_version = 'All';
    }

    $term_name = '';
    $part_name = '';
    if ($admit_type === 'admission') {
        $term_name = trim((string) ($g['admission_title'] ?? 'Admission Test')) ?: 'Admission Test';
    } else {
        if (!empty($g['term_id'])) {
            $ts = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?");
            $ts->execute([$g['term_id']]);
            $term_name = $ts->fetchColumn() ?: '';
        }
        if (!empty($g['part_id'])) {
            $ps = $pdo->prepare("SELECT part_name FROM exam_parts WHERE id = ?");
            $ps->execute([$g['part_id']]);
            $part_name = $ps->fetchColumn() ?: '';
        }
    }

    $sig_left_label = 'Class Teacher';
    $sig_right_label = 'Head of Institute';
    try {
        $ers_row = $pdo->query("SELECT left_signature, right_signature FROM exam_result_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($ers_row) {
            if (!empty($ers_row['left_signature']))  { $sig_left_label  = trim((string) $ers_row['left_signature']); }
            if (!empty($ers_row['right_signature'])) { $sig_right_label = trim((string) $ers_row['right_signature']); }
        }
    } catch (Throwable $e) {
        // exam_result_settings না থাকলে ডিফল্ট লেবেল থাকবে
    }

    $all_routine_rows = ($admit_type === 'general') ? dac_fetch_routine_rows($pdo, $g) : [];
    $exam_date_str = '';
    $exam_time_str = '';
    foreach ($all_routine_rows as $er_item) {
        if (!empty($er_item['raw_date'])) {
            $exam_date_str = ac_fmt_date($er_item['raw_date'], '');
            $exam_time_str = (string) ($er_item['time'] ?? '');
            break;
        }
    }
    if ($admit_type === 'admission' && !empty($g['admission_exam_date'])) {
        $exam_date_str = (string) $g['admission_exam_date'];
        $exam_time_str = trim((string) ($g['admission_venue'] ?? '')) ?: '—';
    }

    $routine_required = ($admit_type === 'general')
        && !empty($ac_settings['show_routine'])
        && ($dac_layout['preset'] === '1full_modern');
    $output_routine_blocked = $routine_required && !$all_routine_rows;

    $admit_year = date('Y');
    $issue_date = date('d/m/Y');
    ?>
    <?php echo ac_css($dac_layout); ?>

    <div class="no-print d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="index.php?page=result_download_admit_card&admit_type=<?php echo dacv($admit_type); ?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
            <?php if ($can_customize): ?>
                <a href="index.php?page=result_admit_card_customize&admit_type=<?php echo dacv($admit_type); ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                    <i class="fa-solid fa-sliders me-1"></i> Customize Admit Card
                </a>
            <?php endif; ?>
            <span class="badge bg-light text-dark border"><?php echo dacv($active_layout_label); ?></span>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="result_download_admit_card">
            <input type="hidden" name="view" value="output">
            <input type="hidden" name="admit_type" value="<?php echo dacv($admit_type); ?>">
            <?php dac_print_fields($dac_paper, $dac_margin, $dac_color, true); ?>
            <?php if (!$output_routine_blocked): ?>
                <button type="button" class="btn btn-primary fw-bold ms-1" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($dac_msg): ?><div class="no-print alert alert-success py-2 mb-3"><?php echo dacv($dac_msg); ?></div><?php endif; ?>
    <?php if ($dac_err): ?><div class="no-print alert alert-danger py-2 mb-3"><?php echo dacv($dac_err); ?></div><?php endif; ?>

    <?php if ($output_routine_blocked): ?>
        <?php
        $blk_cs = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
        $blk_cs->execute([(int) ($g['class_id'] ?? 0)]);
        $blk_class_name = trim((string) ($blk_cs->fetchColumn() ?: ''));
        dac_render_routine_block_alert($blk_class_name, $term_name, dac_routine_block_url($g), $can_routine, $can_customize);
        ?>
    <?php elseif (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="ac-pages<?php echo $dac_color === 'grayscale' ? ' ac-gray' : ''; ?>" id="dacPages">
        <?php foreach (array_chunk($students, $dac_layout['per'], true) as $page_students): ?>
        <div class="ac-page">
            <?php foreach ($page_students as $i => $st): ?>
                <?php
                $st_b_parts = array_filter([
                    trim((string) ($st['student_branch_address'] ?? '')),
                    trim((string) ($st['student_branch_city'] ?? '')),
                ], static fn($v) => $v !== '');
                $st_branch_addr = implode(', ', $st_b_parts);
                $st_b_zip = trim((string) ($st['student_branch_zip'] ?? ''));
                if ($st_branch_addr !== '' && $st_b_zip !== '') { $st_branch_addr .= '-' . $st_b_zip; }
                if ($st_branch_addr === '') { $st_branch_addr = $addr_line; }
                $st_branch_name = trim((string) ($st['student_branch_name'] ?? '')) ?: trim((string) ($branch['branch_name'] ?? ''));
                $st_branch_short = trim((string) ($st['student_branch_short'] ?? '')) ?: trim((string) ($branch['short_name'] ?? ''));
                $st_sig = trim((string) ($st['student_branch_sig'] ?? '')) ?: trim((string) ($branch['signature_path'] ?? ''));

                $resolved_slogan = trim((string) ($institute['slogan'] ?? ''));

                $resolved_phone = '';
                foreach ([$institute['phone_number'] ?? '', $st['student_branch_phone'] ?? '', $branch['phone'] ?? ''] as $cand_phone) {
                    $cand_phone = trim((string) $cand_phone);
                    if ($cand_phone !== '') { $resolved_phone = $cand_phone; break; }
                }

                $resolved_email = '';
                foreach ([$institute['email'] ?? '', $st['student_branch_email'] ?? '', $branch['email'] ?? ''] as $cand_email) {
                    $cand_email = trim((string) $cand_email);
                    if ($cand_email !== '') { $resolved_email = $cand_email; break; }
                }
                ?>
                <?php echo ac_card([
                    'admit_type'            => $admit_type,
                    'institute'             => $inst_display_name,
                    'logo'                  => $institute['logo_path'] ?? '',
                    'slogan'                => $resolved_slogan,
                    'phone'                 => $resolved_phone,
                    'email'                 => $resolved_email,
                    'eiin'                  => trim((string) ($institute['eiin_no'] ?? '')),
                    'address'               => $addr_line,
                    'website'               => trim((string) ($institute['website'] ?? '')),
                    'branch_name'           => $st_branch_name,
                    'branch_short'          => $st_branch_short,
                    'branch_address'        => $st_branch_addr,
                    'branch_signature'      => $st_sig,
                    'term'                  => $term_name,
                    'part'                  => $part_name,
                    'year'                  => $admit_year,
                    'issue_date'            => $issue_date,
                    'exam_date'             => $exam_date_str,
                    'exam_time'             => $exam_time_str,
                    'admission_exam_date'   => $g['admission_exam_date'] ?? '',
                    'admission_venue'       => $g['admission_venue'] ?? '',
                    'admission_result_date' => $g['admission_result_date'] ?? '',
                    'sig_left_label'        => $sig_left_label,
                    'sig_right_label'       => $sig_right_label,
                    'session'               => trim((string) ($st['session_name'] ?? '')) ?: $current_session_name,
                    'name'                  => $st['name_en'],
                    'father_name'           => $st['father_name'] ?? '',
                    'mother_name'           => $st['mother_name'] ?? '',
                    'dob'                   => $st['dob'] ?? '',
                    'birth_reg_no'          => $st['birth_reg_no'] ?? '',
                    'student_mobile'        => trim((string) (($st['guardian_phone'] ?? '') ?: ($st['mobile'] ?? ''))),
                    'gender'                => $st['gender'] ?? '',
                    'religion'              => $st['religion'] ?? '',
                    'blood_group'           => $st['blood_group'] ?? '',
                    'present_address'       => $st['present_address'] ?? '',
                    'permanent_address'     => $st['permanent_address'] ?? '',
                    'class'                 => $st['class_name'] ?? '',
                    'version'               => trim((string) ($st['class_medium_name'] ?? '')) ?: $school_version,
                    'group'                 => $st['group_name'] ?? '',
                    'shift'                 => $st['shift_name'] ?? '',
                    'section'               => $st['section_name'] ?? '',
                    'roll'                  => $st['roll_no'],
                    'student_id'            => $st['student_id'],
                    'photo'                 => $st['photo_path'] ?? '',
                    'qr_id'                 => 'acqr-' . (int) $i,
                    'layout'                => $dac_layout,
                    'routine_rows'          => ($admit_type === 'general') ? dac_student_routine($all_routine_rows, $st) : [],
                    'instructions'          => $dac_instructions,
                    'settings'              => $ac_settings,
                ]); ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php
        $needs_qr = !empty($ac_settings['show_qr']) && !in_array($dac_layout['preset'], ['2half', '1full_classic'], true);
        if ($needs_qr) { echo sc_qr_script(); }
        ?>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= CONFIG VIEW ================= -->
    <div class="card-custom">
        <?php if ($dac_msg): ?><div class="alert alert-success py-2"><?php echo dacv($dac_msg); ?></div><?php endif; ?>
        <?php if ($dac_err): ?><div class="alert alert-danger py-2"><?php echo dacv($dac_err); ?></div><?php endif; ?>
        <?php if ($dac_routine_block): dac_render_routine_block_alert((string) ($dac_routine_block['class_name'] ?? ''), (string) ($dac_routine_block['term_name'] ?? ''), (string) ($dac_routine_block['routine_url'] ?? 'index.php?page=result_exam_routine'), $can_routine, $can_customize); endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h6 class="text-muted mb-1">Generate Admit Card for Session : <?php echo dacv($current_session_name); ?></h6>
                <div class="small text-muted">
                    সক্রিয় লেআউট (Customize থেকে নির্ধারিত): <span class="badge bg-light text-primary border"><?php echo dacv($active_layout_label); ?></span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="btn-group btn-group-sm" role="group" aria-label="Admit Card Type">
                    <a href="index.php?page=result_download_admit_card&admit_type=general"
                       class="btn fw-semibold <?php echo $admit_type === 'general' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fa-solid fa-graduation-cap me-1"></i> General Exam
                    </a>
                    <a href="index.php?page=result_download_admit_card&admit_type=admission"
                       class="btn fw-semibold <?php echo $admit_type === 'admission' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="fa-solid fa-user-plus me-1"></i> Admission Exam
                    </a>
                </div>
                <?php if ($can_customize): ?>
                    <a href="index.php?page=result_admit_card_customize&admit_type=<?php echo dacv($admit_type); ?>" class="btn btn-outline-primary btn-sm fw-semibold">
                        <i class="fa-solid fa-sliders me-1"></i> Customize Admit Card
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="index.php?page=result_download_admit_card&admit_type=<?php echo dacv($admit_type); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="admit_type" value="<?php echo dacv($admit_type); ?>">
            <div class="row">
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="class_id" id="dacClassSelect" class="form-select" required onchange="filterDacSections()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo dacv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($admit_type === 'general'): ?>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="term_id" class="form-select" required>
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo dacv($t['term_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Exam Title</label>
                        <div class="col-sm-8">
                            <input type="text" name="admission_title" maxlength="120" class="form-control" value="Admission Test" placeholder="Admission Test">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shift</label>
                        <div class="col-sm-8">
                            <select name="shift_id" id="dacShiftSelect" class="form-select" onchange="filterDacSections()">
                                <option value="">Select Shift</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>"><?php echo dacv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($admit_type === 'general'): ?>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Part</label>
                        <div class="col-sm-8">
                            <select name="part_id" class="form-select">
                                <option value="">Select Exam Part</option>
                                <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo dacv($p['part_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Exam Date</label>
                        <div class="col-sm-8">
                            <input type="text" name="admission_exam_date" maxlength="150" class="form-control" placeholder="যেমন: 25 Dec 2026, 10:00 AM">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                        <div class="col-sm-8">
                            <select name="section_id" id="dacSectionSelect" class="form-select" disabled>
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo dacv($sec['section_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end"><?php echo $admit_type === 'admission' ? 'Applicant' : 'Student'; ?></label>
                        <div class="col-sm-8">
                            <select name="student_id_row" class="form-select">
                                <option value="">সবাই (Bulk)</option>
                                <?php foreach ($all_students as $st): ?>
                                    <option value="<?php echo (int) $st['id']; ?>"><?php echo dacv($st['name_en']); ?> (<?php echo dacv($st['student_id']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($admit_type === 'admission'): ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Venue / Centre</label>
                        <div class="col-sm-8">
                            <input type="text" name="admission_venue" maxlength="180" class="form-control" placeholder="যেমন: Main Campus, Room 101">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Result Date</label>
                        <div class="col-sm-8">
                            <input type="text" name="admission_result_date" maxlength="150" class="form-control" placeholder="ঐচ্ছিক — যেমন: 28 Dec 2026">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <hr>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php dac_print_fields($dac_paper, $dac_margin, $dac_color, false); ?>
                </div>
                <button type="submit" name="generate_admit_cards" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <?php if ($admit_type === 'general' && !$terms): ?>
        <div class="alert alert-warning mt-3">
            এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। অ্যাডমিট কার্ড জেনারেট করার আগে
            <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
        </div>
    <?php endif; ?>

    <script>
        function filterDacSections() {
            const classId = document.getElementById('dacClassSelect').value;
            const shiftId = document.getElementById('dacShiftSelect').value;
            const select = document.getElementById('dacSectionSelect');
            select.disabled = !classId;
            Array.from(select.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classOk = !!classId && opt.dataset.class === classId;
                const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                opt.hidden = !(classOk && shiftOk);
            });
            select.options[0].textContent = classId ? 'Select Section' : 'Select Class first';
            if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
                select.value = '';
            }
        }
        filterDacSections();
    </script>
<?php endif; ?>
