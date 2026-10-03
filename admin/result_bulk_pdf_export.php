<?php
// admin/result_bulk_pdf_export.php - Result Module > Result Reports > Bulk Result PDF Export
// Class + Term (+ Part) সিলেক্ট করে একসাথে ঐ ক্লাসের সব Active স্টুডেন্টের রেজাল্ট শিট PDF
// আকারে জেনারেট করে একটা ZIP ফাইলে ডাউনলোড করা যায় — প্রতিটা স্টুডেন্টের জন্য আলাদা PDF,
// ফাইলের নাম Roll No + নাম অনুযায়ী, যাতে সহজে খুঁজে বের করা যায়।
//
// PDF তৈরির জন্য mPDF লাইব্রেরি ব্যবহার হচ্ছে (config/pdf_lib/ এ ভেন্ডর করা, Composer লাগে
// না)। প্রথমে Dompdf দিয়ে ট্রাই করা হয়েছিল, কিন্তু Dompdf বাংলা যুক্তাক্ষর ঠিকভাবে আঁকতে
// পারে না বলে mPDF-এ পরিবর্তন করা হয়েছে (FreeSerif ফন্ট, যেটা mPDF নিজেই বাংলার জন্য
// রেকমেন্ড করে ও যেটাতে বাংলার Complex Script শেপিং ইঞ্জিন কাজ করে) — real-tested & confirmed।

function bpev($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/../config/report_card_helpers.php';
require_once __DIR__ . '/../config/pdf_autoload.php';

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$f_session_id = (int) ($_GET['session_id'] ?? $current_session_id);
$f_class_id   = (int) ($_GET['class_id'] ?? 0);
$f_term_id    = (int) ($_GET['term_id'] ?? 0);
$f_part_id    = (int) ($_GET['part_id'] ?? 0);
$do_export    = isset($_GET['export']) && $_GET['export'] == '1';

$terms = [];
if ($f_session_id) {
    $t = $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC");
    $t->execute([$f_session_id]);
    $terms = $t->fetchAll();
}
$parts = [];
if ($f_term_id && $f_class_id) {
    // exam_parts টেবিল Term-ভিত্তিক না, Session-ভিত্তিক — একটা নির্দিষ্ট Term-এ আসলে কোন
    // কোন Part অ্যাসাইন করা আছে সেটা exam_assign_subjects থেকে DISTINCT করে বের করতে হয়
    // (result_marks_entry_exam.php-এর মতো একই প্যাটার্ন)
    $p = $pdo->prepare(
        "SELECT DISTINCT ep.id, ep.part_name FROM exam_assign_subjects eas
         JOIN exam_parts ep ON ep.id = eas.part_id
         WHERE eas.class_id = ? AND eas.term_id = ? AND eas.status = 'Active'
         ORDER BY ep.rank ASC, ep.id ASC"
    );
    $p->execute([$f_class_id, $f_term_id]);
    $parts = $p->fetchAll();
}

// grade bands (remarks কলামসহ — Auto Remark ফিচারের জন্য দরকার)
$grade_bands = [];
if ($f_session_id) {
    $gb = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to, remarks FROM exam_grade_points WHERE session_id = ? ORDER BY mark_from DESC");
    $gb->execute([$f_session_id]);
    $grade_bands = $gb->fetchAll();
}

$institute = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
$fr_bid = function_exists('branch_current_id') ? branch_current_id() : null;
if ($fr_bid !== null) {
    $bs = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $bs->execute([$fr_bid]);
    $branch = $bs->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$inst_name = ($branch['branch_name'] ?? null) ?: ($institute['institute_name'] ?? 'School');
$inst_addr = $branch ? trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . '-' . ($branch['zip_code'] ?? ''), ', -') : '';

// -----------------------------------------------------------------
// একটা স্টুডেন্টের জন্য সাধারণ, পরিষ্কার রেজাল্ট শিট HTML — মূল Report Card পেজের মতো
// পুরোপুরি কাস্টমাইজড (ওয়াটারমার্ক/QR/স্বাক্ষর ইত্যাদি) না, কিন্তু দরকারি সবকিছু (নাম,
// রোল, ক্লাস, সাবজেক্ট-ভিত্তিক মার্ক/GPA, ফলাফল, Remarks) স্পষ্টভাবে দেখায়।
// -----------------------------------------------------------------
function bpe_build_html(array $student, array $result, string $inst_name, string $inst_addr, string $term_name, string $session_name, string $class_name): string
{
    $rows = '';
    foreach ($result['subject_rows'] as $sr) {
        $status = $sr['fail'] ? '<span style="color:#c0392b;font-weight:bold;">Fail</span>' : '<span style="color:#1e7e34;">Pass</span>';
        $rows .= '<tr>'
            . '<td>' . bpev($sr['sl']) . '</td>'
            . '<td style="text-align:left;">' . bpev($sr['name']) . '</td>'
            . '<td>' . bpev(number_format($sr['obtained'], 2)) . ' / ' . bpev(number_format($sr['full'], 2)) . '</td>'
            . '<td>' . bpev($sr['gl']) . '</td>'
            . '<td>' . bpev(number_format($sr['gp'], 2)) . '</td>'
            . '<td>' . $status . '</td>'
            . '</tr>';
    }

    $overall_color = $result['overall_fail'] ? '#c0392b' : '#1e7e34';
    $overall_text  = $result['overall_fail'] ? 'Fail' : 'Pass';
    $remark_text = trim((string) ($result['overall_remarks'] ?? ''));
    if ($remark_text === '') {
        $pct = $result['percentage'];
        if ($result['overall_fail']) { $remark_text = 'উন্নতি প্রয়োজন'; }
        elseif ($pct >= 80) { $remark_text = 'চমৎকার ফলাফল'; }
        elseif ($pct >= 60) { $remark_text = 'ভালো ফলাফল'; }
        else { $remark_text = 'সন্তোষজনক'; }
    }

    return '<html><head><meta charset="utf-8"><style>
        body { font-family: freeserif; font-size: 11pt; color: #222; }
        .header { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 6px; margin-bottom: 10px; }
        .inst-name { font-size: 16pt; font-weight: bold; color: #1e3a8a; }
        .inst-addr { font-size: 9pt; color: #555; }
        .title { text-align:center; font-size: 12pt; font-weight: bold; margin: 6px 0 10px 0; text-decoration: underline; }
        table.info td { padding: 2px 4px; font-size: 10pt; }
        table.marks { border-collapse: collapse; width: 100%; margin-top: 8px; }
        table.marks th, table.marks td { border: 1px solid #444; padding: 4px 6px; font-size: 10pt; text-align: center; }
        table.marks th { background: #eef2ff; }
        .summary { margin-top: 10px; font-size: 10.5pt; }
        .summary td { padding: 3px 6px; }
        .remarks-box { margin-top: 12px; border: 1px dashed #888; padding: 6px 10px; font-size: 10pt; }
        .footer { margin-top: 40px; }
        .sign { display:inline-block; width:30%; text-align:center; border-top: 1px solid #333; padding-top:4px; font-size:9pt; }
    </style></head><body>
        <div class="header">
            <div class="inst-name">' . bpev($inst_name) . '</div>
            <div class="inst-addr">' . bpev($inst_addr) . '</div>
        </div>
        <div class="title">একাডেমিক ফলাফল বিবরণী / Academic Result Sheet — ' . bpev($term_name) . ' (' . bpev($session_name) . ')</div>
        <table class="info" width="100%">
            <tr>
                <td width="50%"><b>Student Name:</b> ' . bpev($student['name_en']) . '</td>
                <td width="50%"><b>ID:</b> ' . bpev($student['student_id']) . '</td>
            </tr>
            <tr>
                <td><b>Class:</b> ' . bpev($class_name) . '</td>
                <td><b>Roll No:</b> ' . bpev($student['roll_no']) . '</td>
            </tr>
        </table>
        <table class="marks">
            <tr><th>SL</th><th>Subject</th><th>Marks (Obtained/Full)</th><th>Grade</th><th>GP</th><th>Status</th></tr>
            ' . $rows . '
        </table>
        <table class="summary" width="100%">
            <tr>
                <td><b>Total:</b> ' . bpev(number_format($result['obtained_total'], 2)) . ' / ' . bpev(number_format($result['full_total'], 2)) . '</td>
                <td><b>Percentage:</b> ' . bpev(number_format($result['percentage'], 2)) . '%</td>
                <td><b>GPA:</b> ' . bpev(number_format($result['overall_gpa'], 2)) . ' (' . bpev($result['overall_gl']) . ')</td>
                <td><b>Result:</b> <span style="color:' . $overall_color . ';font-weight:bold;">' . $overall_text . '</span></td>
            </tr>
        </table>
        <div class="remarks-box"><b>মন্তব্য / Remarks:</b> ' . bpev($remark_text) . '</div>
        <div class="footer">
            <table width="100%"><tr>
                <td class="sign">Class Teacher</td>
                <td class="sign">Guardian</td>
                <td class="sign">Head Teacher</td>
            </tr></table>
        </div>
    </body></html>';
}

// -----------------------------------------------------------------
// Export চাপলে — Class-এর সব Active স্টুডেন্টের PDF জেনারেট করে ZIP বানিয়ে ডাউনলোড
// -----------------------------------------------------------------
if ($do_export && $f_session_id && $f_class_id && $f_term_id) {
    $class_row = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?");
    $class_row->execute([$f_class_id]);
    $class_name = $class_row->fetchColumn() ?: '';
    $term_row = $pdo->prepare("SELECT term_name FROM exam_terms WHERE id = ?");
    $term_row->execute([$f_term_id]);
    $term_name = $term_row->fetchColumn() ?: '';
    $session_name = $current_session['session_name'] ?? '';

    $stu_where = ["status = 'Active'", "class_id = ?"];
    $stu_params = [$f_class_id];
    if (function_exists('branch_apply_filter')) { branch_apply_filter($stu_where, $stu_params); }
    $stu_stmt = $pdo->prepare("SELECT id, student_id, name_en, roll_no FROM students WHERE " . implode(' AND ', $stu_where) . " ORDER BY CAST(roll_no AS UNSIGNED) ASC, name_en ASC");
    $stu_stmt->execute($stu_params);
    $students = $stu_stmt->fetchAll();

    if (!$students) {
        $_SESSION['bpe_err'] = 'এই ক্লাসে কোনো Active স্টুডেন্ট পাওয়া যায়নি।';
        header('Location: index.php?page=result_bulk_pdf_export');
        exit();
    }

    $defs = rc_get_subject_defs($pdo, $f_class_id, $f_term_id, $f_part_id);
    if (!$defs) {
        $_SESSION['bpe_err'] = 'এই Class/Term/Part-এ কোনো Subject Assign করা নেই।';
        header('Location: index.php?page=result_bulk_pdf_export');
        exit();
    }
    $subject_ids = array_keys($defs);
    $in_subj = implode(',', array_fill(0, count($subject_ids), '?'));

    rc_pdf_autoload_register();

    $zip_path = sys_get_temp_dir() . '/result_pdf_export_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $used_names = [];
    foreach ($students as $student) {
        $sid = (int) $student['id'];

        $mk = $pdo->prepare("SELECT subject_id, part_id, marks_obtained FROM exam_marks WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)" . ($f_part_id ? " AND part_id = ?" : ''));
        $mk_params = array_merge([$f_term_id, $sid], $subject_ids);
        if ($f_part_id) { $mk_params[] = $f_part_id; }
        $mk->execute($mk_params);
        $marks_map = [];
        foreach ($mk->fetchAll() as $r) { $marks_map[$r['subject_id']][$r['part_id']] = $r['marks_obtained']; }

        $at = $pdo->prepare("SELECT subject_id, part_id, status FROM exam_attendance_subject WHERE term_id = ? AND student_id = ? AND subject_id IN ($in_subj)" . ($f_part_id ? " AND part_id = ?" : ''));
        $at_params = array_merge([$f_term_id, $sid], $subject_ids);
        if ($f_part_id) { $at_params[] = $f_part_id; }
        $at->execute($at_params);
        $att_map = [];
        foreach ($at->fetchAll() as $r) { $att_map[$r['subject_id']][$r['part_id']] = $r['status']; }

        $result = rc_student_result($defs, $marks_map, $att_map, $grade_bands);
        $html = bpe_build_html($student, $result, $inst_name, $inst_addr, $term_name, $session_name, $class_name);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'freeserif',
            'tempDir' => erp_pdf_temp_dir(),
            'fontDir' => [__DIR__ . '/../config/pdf_lib/mpdf_ttfonts'],
            'margin_top' => 12, 'margin_bottom' => 12, 'margin_left' => 14, 'margin_right' => 14,
        ]);
        $mpdf->WriteHTML($html);
        $pdf_bytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

        $safe_name = preg_replace('/[^A-Za-z0-9\x{0980}-\x{09FF}\- ]/u', '', $student['name_en']);
        $file_name = ($student['roll_no'] ?: $sid) . '_' . trim($safe_name) . '.pdf';
        // ডুপ্লিকেট নাম এড়াতে (একই রোল/নাম একাধিকবার থাকলে)
        if (isset($used_names[$file_name])) {
            $used_names[$file_name]++;
            $file_name = pathinfo($file_name, PATHINFO_FILENAME) . '_' . $used_names[$file_name] . '.pdf';
        } else {
            $used_names[$file_name] = 1;
        }

        $zip->addFromString($file_name, $pdf_bytes);
    }
    $zip->close();

    $zip_download_name = 'result_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $class_name) . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $term_name) . '.zip';

    // admin/index.php-এর একদম শুরুতে পুরো পেজের জন্য একটা ob_start() করা আছে (redirect/header()
    // যেন পেজের মাঝখান থেকেও কাজ করে তার জন্য) — কিন্তু তার মানে এই মুহূর্তের আগে যা কিছু
    // echo হয়েছে (হেডার/সাইডবার HTML) সেটাও একই বাফারে জমা আছে। সেটা ছাড়া ফাইল ডাউনলোড
    // করালে ZIP-এর আগে HTML চাংক জুড়ে গিয়ে ফাইলটা করাপ্ট হয়ে যায় — তাই এখানে ইচ্ছাকৃতভাবে
    // সব বাফার মুছে ফেলে (ob_end_clean লুপে) শুধু ZIP বাইটগুলোই আউটপুট করা হচ্ছে।
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_download_name . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    @unlink($zip_path);
    exit();
}

$bpe_err = $_SESSION['bpe_err'] ?? '';
unset($_SESSION['bpe_err']);
?>
<style>
    .bpe-header h4 { color: #1e3a8a; font-weight: bold; }
</style>

<?php if ($bpe_err): ?><div class="alert alert-danger py-2"><?php echo bpev($bpe_err); ?></div><?php endif; ?>

<div class="card-custom">
    <div class="bpe-header mb-2"><h4><i class="fa-solid fa-file-zipper"></i> Bulk Result PDF Export</h4></div>
    <p class="text-muted">একটা ক্লাসের সব Active স্টুডেন্টের রেজাল্ট শিট একসাথে PDF আকারে জেনারেট করে একটা ZIP ফাইলে ডাউনলোড করতে পারবেন — প্রতিটা স্টুডেন্টের জন্য আলাদা PDF ফাইল থাকবে (Roll No + নাম অনুযায়ী)।</p>

    <form method="GET" action="index.php" id="bpeForm">
        <input type="hidden" name="page" value="result_bulk_pdf_export">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session</label>
                <select name="session_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo bpev($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class</label>
                <select name="class_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class_id === (int) $c['id'] ? 'selected' : ''; ?>><?php echo bpev($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Term</label>
                <select name="term_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select --</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>" <?php echo $f_term_id === (int) $t['id'] ? 'selected' : ''; ?>><?php echo bpev($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Part <small class="text-muted">(না দিলে Combined)</small></label>
                <select name="part_id" class="form-select">
                    <option value="">-- Combined (সব Part) --</option>
                    <?php foreach ($parts as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo $f_part_id === (int) $p['id'] ? 'selected' : ''; ?>><?php echo bpev($p['part_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <?php if ($f_session_id && $f_class_id && $f_term_id): ?>
        <div class="mt-3">
            <a href="index.php?page=result_bulk_pdf_export&session_id=<?php echo (int) $f_session_id; ?>&class_id=<?php echo (int) $f_class_id; ?>&term_id=<?php echo (int) $f_term_id; ?>&part_id=<?php echo (int) $f_part_id; ?>&export=1" class="btn btn-primary fw-bold">
                <i class="fa-solid fa-download"></i> সব স্টুডেন্টের PDF ZIP ডাউনলোড করুন
            </a>
            <div class="form-text">ক্লাসে অনেক স্টুডেন্ট থাকলে জেনারেট হতে কিছুটা সময় লাগতে পারে — বাটনে ক্লিক করার পর পেজ লোড হতে থাকা অবস্থায় অপেক্ষা করুন।</div>
        </div>
    <?php else: ?>
        <div class="alert alert-info mt-3">Session, Class ও Term সিলেক্ট করুন।</div>
    <?php endif; ?>
</div>
