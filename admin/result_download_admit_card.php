<?php
// admin/result_download_admit_card.php - Result Module > Result Reports > Download Admit Card
// Class/Shift/Section/Term/Part/Student ফিল্টার দিয়ে সাবমিট করলে প্রতিটা শিক্ষার্থীর জন্য
// একটা প্রিন্টযোগ্য Admit Card (QR কোড + ছবি + ঠিকানাসহ) জেনারেট হয়।

require_once __DIR__ . '/../config/seat_card_helper.php';
function dacv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function dac_layout_in(array $src): array
{
    return [
        'preset' => (string) ($src['layout'] ?? $src['preset'] ?? ''), 'ori' => (string) ($src['ori'] ?? 'portrait'),
        'w' => $src['cw'] ?? $src['w'] ?? 94, 'h' => $src['ch'] ?? $src['h'] ?? 94,
    ];
}

function dac_layout_fields(array $L, array $opts_cfg = []): void
{
    $opts = [
        '4rect'  => '৪ টা / পাতা — Rectangular',
        '4sq'    => '৪ টা / পাতা — Square',
        '3rect'  => '৩ টা / পাতা — Rectangular',
        '2half'  => '২ টা / পাতা — Half Page',
        '1full'  => '১ টা / পাতা — Full Page',
        'custom' => 'Custom (নিজে সাইজ বসাব)',
    ];
    $can_edit = !empty($opts_cfg['can_edit_instr']);
    $is_custom_instr = !empty($opts_cfg['is_custom_instr']);

    echo '<select name="layout" id="dacLayout" data-ss="off" class="form-select form-select-sm" style="width:auto;" onchange="dacLayoutToggle()">';
    foreach ($opts as $k => $label) { echo '<option value="' . $k . '"' . ($L['preset'] === $k ? ' selected' : '') . '>' . dacv($label) . '</option>'; }
    echo '</select>';
    echo '<span id="dacCustom" class="align-items-center gap-2" style="display:none;">'
       . '<select name="ori" id="dacOri" data-ss="off" class="form-select form-select-sm" style="width:auto;" onchange="dacLayoutToggle()">'
       . '<option value="portrait"' . ($L['ori'] === 'portrait' ? ' selected' : '') . '>Portrait</option>'
       . '<option value="landscape"' . ($L['ori'] === 'landscape' ? ' selected' : '') . '>Landscape</option></select>'
       . '<label class="small mb-0">চওড়া</label><input type="number" name="cw" id="dacCw" class="form-control form-control-sm" style="width:80px;" min="50" max="287" step="0.5" value="' . dacv($L['w']) . '" oninput="dacLayoutToggle()">'
       . '<label class="small mb-0">উচ্চতা</label><input type="number" name="ch" id="dacCh" class="form-control form-control-sm" style="width:80px;" min="40" max="287" step="0.5" value="' . dacv($L['h']) . '" oninput="dacLayoutToggle()"> <span class="small">mm</span>'
       . '</span> <span id="dacPer" class="badge bg-secondary"></span>';
    if ($can_edit) {
        echo ' <button type="button" id="dacInstrBtn" class="btn btn-sm btn-outline-primary fw-semibold align-items-center gap-1" style="display:none;" data-bs-toggle="modal" data-bs-target="#dacInstrModal">'
           . '<i class="fa-solid fa-file-pen"></i> নিয়মকানুন এডিট করুন'
           . ($is_custom_instr ? ' <span class="badge bg-success">Custom</span>' : '')
           . '</button>';
    }
    echo '<script>function dacLayoutToggle(){var sel=document.getElementById("dacLayout");var val=sel?sel.value:"4rect";var c=val==="custom";'
       . 'var ce=document.getElementById("dacCustom");if(ce){ce.style.display=c?"inline-flex":"none";}'
       . 'var P={"4rect":[2,2],"4sq":[2,2],"3rect":[1,3],"2half":[1,2],"1full":[1,1]};var n;'
       . 'if(!c){var sz=P[val]||[2,2];n=sz[0]*sz[1];}else{'
       . 'var ori=document.getElementById("dacOri").value,w=parseFloat(document.getElementById("dacCw").value)||94,h=parseFloat(document.getElementById("dacCh").value)||94;'
       . 'var pw=(ori==="landscape"?297:210)-16,ph=(ori==="landscape"?210:297)-16;w=Math.max(50,Math.min(pw,w));h=Math.max(40,Math.min(ph,h));'
       . 'n=Math.max(1,Math.floor((pw+4)/(w+4)))*Math.max(1,Math.floor((ph+4)/(h+4)));}'
       . 'var pe=document.getElementById("dacPer");if(pe){pe.textContent="প্রতি A4 পাতায় "+n+" টা";}'
       . 'var ib=document.getElementById("dacInstrBtn");if(ib){ib.style.display=(val==="1full")?"inline-flex":"none";}'
       . '}dacLayoutToggle();</script>';
}

$dac_err = $_SESSION['dac_err'] ?? '';
$dac_msg = $_SESSION['dac_msg'] ?? '';
unset($_SESSION['dac_err'], $_SESSION['dac_msg']);

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
$can_edit_instr = !function_exists('perm_action_allowed') || perm_action_allowed($pdo, 'result_download_admit_card', 'edit');

// -----------------------------------------------------------
// POST: ফর্ম সাবমিট -> ফিল্টার সেশনে রেখে output ভিউতে রিডাইরেক্ট
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_admit_cards'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dac_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_download_admit_card');
        exit();
    }

    $f_class_id = (int) ($_POST['class_id'] ?? 0);
    $f_term_id  = (int) ($_POST['term_id'] ?? 0);

    if ($f_class_id <= 0 || $f_term_id <= 0) {
        $_SESSION['dac_err'] = 'Class এবং Term আবশ্যক।';
        header('Location: index.php?page=result_download_admit_card');
        exit();
    }

    $_SESSION['dac_generate'] = [
        'class_id'       => $f_class_id,
        'shift_id'       => (int) ($_POST['shift_id'] ?? 0),
        'section_id'     => (int) ($_POST['section_id'] ?? 0),
        'term_id'        => $f_term_id,
        'part_id'        => (int) ($_POST['part_id'] ?? 0),
        'student_id_row' => (int) ($_POST['student_id_row'] ?? 0),
    ];
    $_SESSION['dac_layout'] = ac_layout(dac_layout_in($_POST));
    header('Location: index.php?page=result_download_admit_card&view=output');
    exit();
}

// -----------------------------------------------------------
// POST: ১-পেজ অ্যাডমিট কার্ডের নিয়মকানুন সেভ / ডিফল্টে রিসেট (CMS)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_admit_instructions']) || isset($_POST['update_admit_instructions_reset']))) {
    $go_output = (($_POST['return_view'] ?? '') === 'output' && !empty($_SESSION['dac_generate']));
    $redir = $go_output
        ? 'index.php?page=result_download_admit_card&view=output&layout=1full'
        : 'index.php?page=result_download_admit_card';

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['dac_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: ' . $redir);
        exit();
    }
    if (!$can_edit_instr) {
        $_SESSION['dac_err'] = 'নিয়মকানুন পরিবর্তনের অনুমতি আপনার নেই।';
        header('Location: ' . $redir);
        exit();
    }

    $_SESSION['dac_layout'] = ac_layout(['preset' => '1full']);

    if (isset($_POST['update_admit_instructions_reset'])) {
        ac_reset_instructions($pdo, $instr_branch_id);
        $_SESSION['dac_msg'] = 'সরকারি ডিফল্ট নিয়মকানুন পুনরায় চালু করা হয়েছে।';
    } else {
        $note_in   = trim((string) ($_POST['note_text'] ?? ''));
        $head_in   = trim((string) ($_POST['heading'] ?? ''));
        $rules_in  = trim((string) ($_POST['rules_text'] ?? ''));
        $carry_in  = trim((string) ($_POST['carry_text'] ?? ''));
        $barred_in = trim((string) ($_POST['barred_text'] ?? ''));

        if ($note_in === '' && $head_in === '' && $rules_in === '' && $carry_in === '' && $barred_in === '') {
            $_SESSION['dac_err'] = 'অন্তত একটি নিয়ম বা নির্দেশনা লিখুন অথবা সরকারি ডিফল্টে ফিরিয়ে আনুন।';
        } else {
            ac_save_instructions($pdo, $instr_branch_id, [
                'note_text'   => $note_in,
                'heading'     => $head_in,
                'rules_text'  => $rules_in,
                'carry_text'  => $carry_in,
                'barred_text' => $barred_in,
            ]);
            $_SESSION['dac_msg'] = 'অ্যাডমিট কার্ডের নিয়মকানুন সফলভাবে সেভ হয়েছে।';
        }
    }
    header('Location: ' . $redir);
    exit();
}

$dac_instructions  = ac_load_instructions($pdo, $instr_branch_id);
$dac_govt_defaults = ac_default_instructions();

// সর্বশেষ বাছাই করা লেআউট (GET দিয়ে বদলানো যায়)
$dac_layout = ac_layout(isset($_GET['layout']) ? dac_layout_in($_GET) : (dac_layout_in($_SESSION['dac_layout'] ?? [])));
$_SESSION['dac_layout'] = $dac_layout;

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

$rdac_where = ["status = 'Active'"]; $rdac_params = [];
branch_apply_filter($rdac_where, $rdac_params);
$rdac_stmt = $pdo->prepare("SELECT id, name_en, student_id FROM students WHERE " . implode(' AND ', $rdac_where) . " ORDER BY name_en ASC LIMIT 300");
$rdac_stmt->execute($rdac_params);
$all_students = $rdac_stmt->fetchAll();

$view = $_GET['view'] ?? 'config';
$dac_layout_opts_cfg = [
    'can_edit_instr'  => $can_edit_instr,
    'is_custom_instr' => !empty($dac_instructions['is_custom']),
];
?>

<?php if ($view === 'output'): ?>
    <?php
    // ================= OUTPUT VIEW: অ্যাডমিট কার্ড জেনারেট =================
    $g = $_SESSION['dac_generate'] ?? [];

    if (!$g) {
        header('Location: index.php?page=result_download_admit_card');
        exit();
    }

    $where = ["students.status = 'Active'"];
    $params = [];
    if (!empty($g['class_id']))       { $where[] = 'students.class_id = ?'; $params[] = $g['class_id']; }
    if (!empty($g['shift_id']))       { $where[] = 'students.shift_id = ?'; $params[] = $g['shift_id']; }
    if (!empty($g['section_id']))     { $where[] = 'students.section_id = ?'; $params[] = $g['section_id']; }
    if (!empty($g['student_id_row'])) { $where[] = 'students.id = ?'; $params[] = $g['student_id_row']; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT students.*,
                   academic_classes.class_name,
                   academic_sections.section_name,
                   academic_shifts.shift_name,
                   academic_groups.group_name,
                   academic_sessions.session_name,
                   branches.branch_name AS student_branch_name,
                   branches.short_name AS student_branch_short,
                   branches.address AS student_branch_address,
                   branches.city AS student_branch_city,
                   branches.zip_code AS student_branch_zip,
                   branches.signature_path AS student_branch_sig
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
            LEFT JOIN academic_groups ON academic_groups.id = students.group_id
            LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
            LEFT JOIN branches ON branches.id = students.branch_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (students.roll_no + 0) ASC, students.roll_no ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $term_name = '';
    if (!empty($g['term_id'])) {
        $ts = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?");
        $ts->execute([$g['term_id']]);
        $term_name = $ts->fetchColumn() ?: '';
    }
    $part_name = '';
    if (!empty($g['part_id'])) {
        $ps = $pdo->prepare("SELECT part_name FROM exam_parts WHERE id = ?");
        $ps->execute([$g['part_id']]);
        $part_name = $ps->fetchColumn() ?: '';
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

    $exam_date_str = '';
    $exam_time_str = '';
    try {
        $er_where = ['class_id = ?', 'term_id = ?', "status = 'Active'", 'exam_date IS NOT NULL'];
        $er_params = [(int) $g['class_id'], (int) $g['term_id']];
        if (!empty($g['shift_id']))   { $er_where[] = 'shift_id = ?';   $er_params[] = (int) $g['shift_id']; }
        if (!empty($g['section_id'])) { $er_where[] = 'section_id = ?'; $er_params[] = (int) $g['section_id']; }
        if (!empty($g['part_id']))    { $er_where[] = 'part_id = ?';    $er_params[] = (int) $g['part_id']; }
        $er_stmt = $pdo->prepare("SELECT exam_date, start_time, end_time FROM exam_routines WHERE " . implode(' AND ', $er_where) . " ORDER BY exam_date ASC, start_time ASC LIMIT 1");
        $er_stmt->execute($er_params);
        if ($er_row = $er_stmt->fetch(PDO::FETCH_ASSOC)) {
            $exam_date_str = ac_fmt_date($er_row['exam_date'] ?? '', '');
            $st_t = !empty($er_row['start_time']) ? strtotime($er_row['start_time']) : false;
            $en_t = !empty($er_row['end_time']) ? strtotime($er_row['end_time']) : false;
            if ($st_t !== false && $en_t !== false) {
                $exam_time_str = date('g:i A', $st_t) . ' - ' . date('g:i A', $en_t);
            } elseif ($st_t !== false) {
                $exam_time_str = date('g:i A', $st_t);
            }
        }
    } catch (Throwable $e) {
        // exam_routines না থাকলে নিরাপদে স্কিপ করবে
    }

    $admit_year = date('Y');
    $issue_date = date('d/m/Y');
    ?>
    <?php echo ac_css($dac_layout); ?>

    <div class="no-print d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <a href="index.php?page=result_download_admit_card" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="result_download_admit_card">
            <input type="hidden" name="view" value="output">
            <?php dac_layout_fields($dac_layout, $dac_layout_opts_cfg); ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </form>
    </div>

    <?php if ($dac_msg): ?><div class="no-print alert alert-success py-2 mb-3"><?php echo dacv($dac_msg); ?></div><?php endif; ?>
    <?php if ($dac_err): ?><div class="no-print alert alert-danger py-2 mb-3"><?php echo dacv($dac_err); ?></div><?php endif; ?>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="ac-pages">
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
                ?>
                <?php echo ac_card([
                    'institute'         => $inst_display_name,
                    'logo'              => $institute['logo_path'] ?? '',
                    'slogan'            => trim((string) ($institute['slogan'] ?? '')),
                    'eiin'              => trim((string) ($institute['eiin_no'] ?? '')),
                    'address'           => $addr_line,
                    'website'           => trim((string) ($institute['website'] ?? '')),
                    'branch_name'       => $st_branch_name,
                    'branch_short'      => $st_branch_short,
                    'branch_address'    => $st_branch_addr,
                    'branch_signature'  => $st_sig,
                    'term'              => $term_name,
                    'part'              => $part_name,
                    'year'              => $admit_year,
                    'issue_date'        => $issue_date,
                    'exam_date'         => $exam_date_str,
                    'exam_time'         => $exam_time_str,
                    'sig_left_label'    => $sig_left_label,
                    'sig_right_label'   => $sig_right_label,
                    'session'           => trim((string) ($st['session_name'] ?? '')) ?: $current_session_name,
                    'name'              => $st['name_en'],
                    'father_name'       => $st['father_name'] ?? '',
                    'mother_name'       => $st['mother_name'] ?? '',
                    'dob'               => $st['dob'] ?? '',
                    'gender'            => $st['gender'] ?? '',
                    'religion'          => $st['religion'] ?? '',
                    'blood_group'       => $st['blood_group'] ?? '',
                    'present_address'   => $st['present_address'] ?? '',
                    'permanent_address' => $st['permanent_address'] ?? '',
                    'class'             => $st['class_name'] ?? '',
                    'group'             => $st['group_name'] ?? '',
                    'shift'             => $st['shift_name'] ?? '',
                    'section'           => $st['section_name'] ?? '',
                    'roll'              => $st['roll_no'],
                    'student_id'        => $st['student_id'],
                    'photo'             => $st['photo_path'] ?? '',
                    'qr_id'             => 'acqr-' . (int) $i,
                    'layout'            => $dac_layout,
                    'instructions'      => $dac_instructions,
                ]); ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php echo sc_qr_script(); ?>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= CONFIG VIEW ================= -->
    <div class="card-custom">
        <?php if ($dac_msg): ?><div class="alert alert-success py-2"><?php echo dacv($dac_msg); ?></div><?php endif; ?>
        <?php if ($dac_err): ?><div class="alert alert-danger py-2"><?php echo dacv($dac_err); ?></div><?php endif; ?>

        <h6 class="text-muted mb-4">Generate Admit Card for Session : <?php echo dacv($current_session_name); ?></h6>

        <form method="POST" action="index.php?page=result_download_admit_card">
            <?php echo csrf_field(); ?>
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
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="term_id" class="form-select" required>
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo dacv($t['term_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Part</label>
                        <div class="col-sm-8">
                            <select name="part_id" class="form-select">
                                <option value="">Select Exam Part</option>
                                <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo dacv($p['part_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
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
                        <label class="col-sm-4 col-form-label fw-bold text-end">Student</label>
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

            <hr>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                <label class="fw-bold mb-0">Card Layout:</label>
                <?php dac_layout_fields($dac_layout, $dac_layout_opts_cfg); ?>
            </div>
            <div class="text-end">
                <button type="submit" name="generate_admit_cards" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <?php if (!$terms): ?>
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

<?php if ($can_edit_instr): ?>
<div class="modal fade no-print" id="dacInstrModal" tabindex="-1" aria-labelledby="dacInstrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" action="index.php?page=result_download_admit_card" class="modal-content">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="return_view" value="<?php echo dacv($view === 'output' ? 'output' : 'config'); ?>">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fs-6 d-flex align-items-center gap-2 flex-wrap" id="dacInstrModalLabel">
                    <span><i class="fa-solid fa-file-pen me-1"></i> অ্যাডমিট কার্ডের নিয়মকানুন এডিট করুন (১ পেজ লেআউট)</span>
                    <?php if (!empty($dac_instructions['is_custom'])): ?>
                        <span class="badge bg-warning text-dark">কাস্টম নিয়ম চালু আছে</span>
                    <?php else: ?>
                        <span class="badge bg-light text-primary">সরকারি ডিফল্ট চালু আছে</span>
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3">
                    এখানে পরিবর্তন করে সেভ করলে ১-পেজ (Full Page) অ্যাডমিট কার্ডে ডিফল্ট সরকারি নিয়মকানুন বাদ দিয়ে আপনার লেখা নিয়মকানুন প্রদর্শিত হবে। কোনো অংশ ফাঁকা রাখলে কার্ডে ওই অংশটি দেখাবে না।
                </div>
                <div class="mb-3">
                    <label for="dacInstrNote" class="form-label fw-bold small mb-1">বিশেষ দ্রষ্টব্য / Warning Note (লাল অংশ)</label>
                    <textarea name="note_text" id="dacInstrNote" rows="2" class="form-control form-control-sm"><?php echo dacv($dac_instructions['note_text'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="dacInstrHeading" class="form-label fw-bold small mb-1">নির্দেশাবলীর শিরোনাম (Heading)</label>
                    <input type="text" name="heading" id="dacInstrHeading" maxlength="255" class="form-control form-control-sm" value="<?php echo dacv($dac_instructions['heading'] ?? ''); ?>">
                </div>
                <div class="mb-3">
                    <label for="dacInstrRules" class="form-label fw-bold small mb-1">পরীক্ষার নিয়মকানুন (প্রতি লাইনে একটি করে নিয়ম লিখুন)</label>
                    <textarea name="rules_text" id="dacInstrRules" rows="10" class="form-control form-control-sm"><?php echo dacv($dac_instructions['rules_text'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="dacInstrCarry" class="form-label fw-bold small mb-1">পরীক্ষার হলে যা সঙ্গে আনতে হবে (Must Carry)</label>
                    <textarea name="carry_text" id="dacInstrCarry" rows="2" class="form-control form-control-sm"><?php echo dacv($dac_instructions['carry_text'] ?? ''); ?></textarea>
                </div>
                <div class="mb-0">
                    <label for="dacInstrBarred" class="form-label fw-bold small mb-1">পরীক্ষার হলে নিষিদ্ধ সামগ্রী (Barred Items)</label>
                    <textarea name="barred_text" id="dacInstrBarred" rows="2" class="form-control form-control-sm"><?php echo dacv($dac_instructions['barred_text'] ?? ''); ?></textarea>
                </div>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="dacFillGovtDefaults()">
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i> সরকারি ডিফল্ট লোড করুন
                    </button>
                    <?php if (!empty($dac_instructions['is_custom']) && ($dac_instructions['scope_branch_id'] ?? 0) === (int) ($instr_branch_id ?? 0)): ?>
                        <button type="submit" name="update_admit_instructions_reset" value="1" class="btn btn-outline-danger btn-sm" onclick="return confirm('কাস্টম নিয়মকানুন মুছে সরকারি ডিফল্ট নিয়ম ফিরিয়ে আনবেন?')">
                            <i class="fa-solid fa-rotate-left me-1"></i> ডিফল্ট ফিরিয়ে আনুন
                        </button>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">বাতিল</button>
                    <button type="submit" name="update_admit_instructions" value="1" class="btn btn-primary btn-sm fw-bold">
                        <i class="fa-solid fa-floppy-disk me-1"></i> সেভ করুন
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function dacFillGovtDefaults() {
    var d = <?php echo json_encode($dac_govt_defaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    document.getElementById('dacInstrNote').value = d.note_text || '';
    document.getElementById('dacInstrHeading').value = d.heading || '';
    document.getElementById('dacInstrRules').value = d.rules_text || '';
    document.getElementById('dacInstrCarry').value = d.carry_text || '';
    document.getElementById('dacInstrBarred').value = d.barred_text || '';
}
</script>
<?php endif; ?>
