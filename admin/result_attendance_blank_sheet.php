<?php
// admin/result_attendance_blank_sheet.php - Result Module > Result Reports > Exam Attendance Blank Sheet
// Session/Class/Group/Shift/Section/Term/Part/Room ফিল্টার দিয়ে সাবমিট করলে একটা প্রিন্টযোগ্য
// ফাঁকা attendance শিট আসে (Sl./ID/Name/Roll) — পরীক্ষার হলে হাতে টিক দেওয়ার জন্য।

function babv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$bab_err = $_SESSION['bab_err'] ?? '';
unset($_SESSION['bab_err']);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

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
$inst_phone = $branch['phone'] ?? ($institute['phone_number'] ?? '');

// -----------------------------------------------------------
// POST: ফর্ম সাবমিট -> ফিল্টার সেশনে রেখে output ভিউতে রিডাইরেক্ট
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_blank_sheet'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['bab_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_attendance_blank_sheet');
        exit();
    }

    $f_session_id = (int) ($_POST['session_id'] ?? 0);
    $f_class_id   = (int) ($_POST['class_id'] ?? 0);
    $f_shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $f_section_id = (int) ($_POST['section_id'] ?? 0);
    $f_term_id    = (int) ($_POST['term_id'] ?? 0);

    if (!$f_session_id || !$f_class_id || !$f_shift_id || !$f_section_id || !$f_term_id) {
        $_SESSION['bab_err'] = 'Session, Class, Group, Shift, Section এবং Term আবশ্যক।';
        header('Location: index.php?page=result_attendance_blank_sheet');
        exit();
    }

    $_SESSION['bab_generate'] = [
        'session_id' => $f_session_id,
        'class_id'   => $f_class_id,
        'group_id'   => (int) ($_POST['group_id'] ?? 0),
        'shift_id'   => $f_shift_id,
        'section_id' => $f_section_id,
        'term_id'    => $f_term_id,
        'part_id'    => (int) ($_POST['part_id'] ?? 0),
        'room_no'    => trim($_POST['room_no'] ?? ''),
    ];
    header('Location: index.php?page=result_attendance_blank_sheet&view=output');
    exit();
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name, has_group FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts WHERE status = 'Active' ORDER BY shift_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$groups   = $pdo->query("SELECT id, group_name, class_id FROM academic_groups WHERE status = 'Active' ORDER BY group_name ASC")->fetchAll();
$terms    = $current_session_id
    ? $pdo->prepare("SELECT id, term_name FROM exam_terms WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($terms) { $terms->execute([$current_session_id]); $terms = $terms->fetchAll(); } else { $terms = []; }
$parts = $current_session_id
    ? $pdo->prepare("SELECT id, part_name FROM exam_parts WHERE session_id = ? AND status = 'Active' ORDER BY rank ASC")
    : null;
if ($parts) { $parts->execute([$current_session_id]); $parts = $parts->fetchAll(); } else { $parts = []; }

$view = $_GET['view'] ?? 'config';
?>

<?php if ($view === 'output'): ?>
    <?php
    // ================= OUTPUT VIEW: ফাঁকা attendance শিট =================
    $g = $_SESSION['bab_generate'] ?? [];
    unset($_SESSION['bab_generate']);

    if (!$g) {
        header('Location: index.php?page=result_attendance_blank_sheet');
        exit();
    }

    $where = ["students.status = 'Active'", 'students.session_id = ?', 'students.class_id = ?', 'students.shift_id = ?', 'students.section_id = ?'];
    $params = [$g['session_id'], $g['class_id'], $g['shift_id'], $g['section_id']];
    if (!empty($g['group_id'])) { $where[] = 'students.group_id = ?'; $params[] = $g['group_id']; }

    $sql = "SELECT students.student_id, students.roll_no, students.name_en,
                   academic_classes.class_name, academic_shifts.shift_name, academic_sections.section_name
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (students.roll_no + 0) ASC, students.roll_no ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    $head = $students[0] ?? null;
    $class_name   = $head['class_name'] ?? '-';
    $shift_name   = $head['shift_name'] ?? '-';
    $section_name = $head['section_name'] ?? '-';

    if (!$head) {
        // কোনো স্টুডেন্ট না পেলেও ফিল্টার-লেবেল দেখানোর জন্য নাম আলাদাভাবে লুকআপ
        $cs = $pdo->prepare("SELECT class_name FROM academic_classes WHERE id = ?"); $cs->execute([$g['class_id']]);
        $class_name = $cs->fetchColumn() ?: '-';
        $ss = $pdo->prepare("SELECT shift_name FROM academic_shifts WHERE id = ?"); $ss->execute([$g['shift_id']]);
        $shift_name = $ss->fetchColumn() ?: '-';
        $secs = $pdo->prepare("SELECT section_name FROM academic_sections WHERE id = ?"); $secs->execute([$g['section_id']]);
        $section_name = $secs->fetchColumn() ?: '-';
    }

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
    $sheet_session_name = '';
    $sess_s = $pdo->prepare("SELECT session_name FROM academic_sessions WHERE id = ?");
    $sess_s->execute([$g['session_id']]);
    $sheet_session_name = $sess_s->fetchColumn() ?: date('Y');
    $room_no = trim((string) ($g['room_no'] ?? ''));
    ?>
    <?php require_once __DIR__ . '/employee_attendance_common.php'; ea_print_css('portrait'); ?>
    <style>
        
        .bab-header { text-align: center; margin-bottom: 10px; }
        .bab-header h4 { font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
        .bab-header .addr { color: #444; font-size: 13px; margin-bottom: 2px; }
        .bab-header .phone { font-size: 13px; margin-bottom: 2px; }
        .bab-header .web { color: #2563eb; font-size: 13px; margin-bottom: 8px; }
        .bab-header .meta { font-size: 14px; margin-bottom: 10px; }
        .bab-badge {
            display: inline-block; font-weight: bold; font-size: 14px; border: 1.5px solid #333;
            border-radius: 3px; padding: 5px 20px; margin-bottom: 14px;
        }
    .bab-sign-cell { min-width: 150px; width: 28%; }
        .bab-sign-cell:not(th) { height: 30px; }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=result_attendance_blank_sheet" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <?php ea_print_controls('portrait', false); ?>
    </div>

    <div class="card-custom">
        <div class="bab-header">
            <h4><?php echo babv($inst_display_name); ?></h4>
            <?php if ($addr_line !== ''): ?><p class="addr">(<?php echo babv($addr_line); ?>)</p><?php endif; ?>
            <?php if ($inst_phone !== ''): ?><p class="phone">Phone: <?php echo babv($inst_phone); ?></p><?php endif; ?>
            <?php if (!empty($institute['website'])): ?><p class="web"><?php echo htmlspecialchars((string) $institute['website']); ?></p><?php endif; ?>
            <p class="meta">
                Class : <?php echo babv($class_name); ?>, Shift : <?php echo babv($shift_name); ?>, Section : <?php echo babv($section_name); ?>
                <?php if ($room_no !== ''): ?>, Room : <?php echo babv($room_no); ?><?php endif; ?>
            </p>
            <div class="bab-badge">
                Exam Attendance Blank Sheet : <?php echo babv($term_name); ?><?php echo $part_name !== '' ? ' (' . babv($part_name) . ')' : ''; ?> - <?php echo babv($sheet_session_name); ?>
            </div>
        </div>

        <?php if (!$students): ?>
            <p class="text-muted text-center mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle m-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">Sl.</th>
                            <th style="width:150px;">ID</th>
                            <th>Name</th>
                            <th style="width:90px;">Roll</th>
                            <th class="bab-sign-cell text-center">Signature</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $srl = 1; foreach ($students as $stu): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="text-primary fw-bold"><?php echo babv($stu['student_id']); ?></td>
                                <td><?php echo babv($stu['name_en']); ?></td>
                                <td><?php echo babv($stu['roll_no']); ?></td>
                                <td class="bab-sign-cell">&nbsp;</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ================= CONFIG VIEW ================= -->
    <div class="card-custom">
        <?php if ($bab_err): ?><div class="alert alert-danger py-2"><?php echo babv($bab_err); ?></div><?php endif; ?>

        <form method="POST" action="index.php?page=result_attendance_blank_sheet">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-3">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="session_id" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo (int) $s['id'] === $current_session_id ? 'selected' : ''; ?>><?php echo babv($s['session_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Section <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="section_id" id="babSectionSelect" class="form-select" required disabled>
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo babv($sec['section_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="class_id" id="babClassSelect" class="form-select" required onchange="filterBabDependents()">
                                <option value="">Select</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo babv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="term_id" class="form-select" required>
                                <option value="">Select</option>
                                <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo babv($t['term_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Group <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="group_id" id="babGroupSelect" class="form-select" required>
                                <option value="0">All Group</option>
                                <?php foreach ($groups as $g): ?><option value="<?php echo (int) $g['id']; ?>" data-class="<?php echo (int) $g['class_id']; ?>"><?php echo babv($g['group_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Part</label>
                        <div class="col-sm-7">
                            <select name="part_id" class="form-select">
                                <option value="">Select Exam Part</option>
                                <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo babv($p['part_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Shift <span class="text-danger">*</span></label>
                        <div class="col-sm-7">
                            <select name="shift_id" id="babShiftSelect" class="form-select" required onchange="filterBabDependents()">
                                <option value="">Select</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>"><?php echo babv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-5 col-form-label fw-bold text-end">Room No.</label>
                        <div class="col-sm-7"><input type="text" name="room_no" class="form-control" placeholder="i.e : 114"></div>
                    </div>
                </div>
            </div>

            <div class="text-end">
                <button type="submit" name="generate_blank_sheet" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <?php if (!$terms): ?>
        <div class="alert alert-warning mt-3">
            এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। ব্ল্যাংক শিট জেনারেট করার আগে
            <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
        </div>
    <?php endif; ?>

    <script>
        function filterBabDependents() {
            const classId = document.getElementById('babClassSelect').value;
            const shiftId = document.getElementById('babShiftSelect').value;

            const secSelect = document.getElementById('babSectionSelect');
            secSelect.disabled = !classId;
            Array.from(secSelect.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const classOk = !!classId && opt.dataset.class === classId;
                const shiftOk = !shiftId || !opt.dataset.shift || opt.dataset.shift === '0' || opt.dataset.shift === shiftId;
                opt.hidden = !(classOk && shiftOk);
            });
            secSelect.options[0].textContent = classId ? 'Select' : 'Select Class first';
            if (secSelect.value && Array.from(secSelect.options).find(o => o.value === secSelect.value)?.hidden) {
                secSelect.value = '';
            }

            const grpSelect = document.getElementById('babGroupSelect');
            Array.from(grpSelect.options).forEach(opt => {
                if (!opt.value || opt.value === '0') { opt.hidden = false; return; }
                opt.hidden = !!classId && opt.dataset.class !== classId;
            });
            if (grpSelect.value && Array.from(grpSelect.options).find(o => o.value === grpSelect.value)?.hidden) {
                grpSelect.value = '0';
            }
        }
        filterBabDependents();
    </script>
<?php endif; ?>
