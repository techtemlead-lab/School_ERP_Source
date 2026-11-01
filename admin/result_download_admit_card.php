<?php
// admin/result_download_admit_card.php - Result Module > Result Reports > Download Admit Card
// Class/Shift/Section/Term/Part/Student ফিল্টার দিয়ে সাবমিট করলে প্রতিটা শিক্ষার্থীর জন্য
// একটা প্রিন্টযোগ্য Admit Card (QR কোড + ছবি + ঠিকানাসহ) জেনারেট হয়।

require_once __DIR__ . '/../config/seat_card_helper.php';
function dacv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

function dac_layout_in(array $src): array
{
    return [
        'preset' => (string) ($src['layout'] ?? ''), 'ori' => (string) ($src['ori'] ?? 'portrait'),
        'w' => $src['cw'] ?? $src['w'] ?? 94, 'h' => $src['ch'] ?? $src['h'] ?? 94,
    ];
}

function dac_layout_fields(array $L): void
{
    $opts = ['4rect' => '৪ টা / পাতা — Rectangular', '4sq' => '৪ টা / পাতা — Square', '3rect' => '৩ টা / পাতা — Rectangular', 'custom' => 'Custom (নিজে সাইজ বসাব)'];
    echo '<select name="layout" id="dacLayout" class="form-select form-select-sm" style="width:auto;" onchange="dacLayoutToggle()">';
    foreach ($opts as $k => $label) { echo '<option value="' . $k . '"' . ($L['preset'] === $k ? ' selected' : '') . '>' . dacv($label) . '</option>'; }
    echo '</select>';
    echo '<span id="dacCustom" class="d-inline-flex align-items-center gap-2" style="display:none;">'
       . '<select name="ori" id="dacOri" class="form-select form-select-sm" style="width:auto;" onchange="dacLayoutToggle()">'
       . '<option value="portrait"' . ($L['ori'] === 'portrait' ? ' selected' : '') . '>Portrait</option>'
       . '<option value="landscape"' . ($L['ori'] === 'landscape' ? ' selected' : '') . '>Landscape</option></select>'
       . '<label class="small mb-0">চওড়া</label><input type="number" name="cw" id="dacCw" class="form-control form-control-sm" style="width:80px;" min="50" max="287" step="0.5" value="' . dacv($L['w']) . '" oninput="dacLayoutToggle()">'
       . '<label class="small mb-0">উচ্চতা</label><input type="number" name="ch" id="dacCh" class="form-control form-control-sm" style="width:80px;" min="40" max="287" step="0.5" value="' . dacv($L['h']) . '" oninput="dacLayoutToggle()"> <span class="small">mm</span>'
       . '</span> <span id="dacPer" class="badge bg-secondary"></span>'
       . '<script>function dacLayoutToggle(){var c=document.getElementById("dacLayout").value==="custom";document.getElementById("dacCustom").style.display=c?"inline-flex":"none";'
       . 'var P={"4rect":[2,2],"4sq":[2,2],"3rect":[1,3]};var n;if(!c){n=P[document.getElementById("dacLayout").value];n=n[0]*n[1];}else{'
       . 'var ori=document.getElementById("dacOri").value,w=parseFloat(document.getElementById("dacCw").value)||94,h=parseFloat(document.getElementById("dacCh").value)||94;'
       . 'var pw=(ori==="landscape"?297:210)-16,ph=(ori==="landscape"?210:297)-16;w=Math.max(50,Math.min(pw,w));h=Math.max(40,Math.min(ph,h));'
       . 'n=Math.max(1,Math.floor((pw+4)/(w+4)))*Math.max(1,Math.floor((ph+4)/(h+4)));}'
       . 'document.getElementById("dacPer").textContent="প্রতি A4 পাতায় "+n+" টা";}dacLayoutToggle();</script>';
}

$dac_err = $_SESSION['dac_err'] ?? '';
unset($_SESSION['dac_err']);

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

    $sql = "SELECT students.*, academic_classes.class_name, academic_sections.section_name, academic_shifts.shift_name
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
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
    $admit_year = date('Y');
    ?>
    <?php echo ac_css($dac_layout); ?>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=result_download_admit_card" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="result_download_admit_card">
            <input type="hidden" name="view" value="output">
            <?php dac_layout_fields($dac_layout); ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </form>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="ac-pages">
        <?php foreach (array_chunk($students, $dac_layout['per'], true) as $page_students): ?>
        <div class="ac-page">
            <?php foreach ($page_students as $i => $st): ?>
                <?php echo ac_card([
                    'institute' => $inst_display_name, 'logo' => $institute['logo_path'] ?? '',
                    'address' => $addr_line, 'website' => trim((string) ($institute['website'] ?? '')),
                    'term' => $term_name, 'year' => $admit_year,
                    'name' => $st['name_en'], 'class' => $st['class_name'] ?? '', 'shift' => $st['shift_name'] ?? '',
                    'section' => $st['section_name'] ?? '', 'roll' => $st['roll_no'], 'student_id' => $st['student_id'],
                    'photo' => $st['photo_path'] ?? '', 'qr_id' => 'acqr-' . (int) $i, 'layout' => $dac_layout,
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
                <?php dac_layout_fields($dac_layout); ?>
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
