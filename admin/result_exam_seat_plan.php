<?php
// admin/result_exam_seat_plan.php - Result Module > Exam Settings > Exam Seat Plan
// Class/Shift/Section/Term/Part/Room ফিল্টার দিয়ে সাবমিট করলে প্রতিটি শিক্ষার্থীর জন্য
// প্রিন্টযোগ্য "Seat Layout" কার্ড (Color/Grayscale এবং Photo On/Off সহ) জেনারেট হয়।
require_once __DIR__ . '/../config/exam_fee_helpers.php';
require_once __DIR__ . '/../config/seat_card_helper.php';
ef_ensure_tables($pdo);

$sp_err = $_SESSION['sp_err'] ?? '';
unset($_SESSION['sp_err']);

function spv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------
// exam_terms টেবিল এখনো তৈরি না হয়ে থাকতে পারে (Result Module > Exam Settings > Exam Terms
// পেজ কখনো ভিজিট না করা হলে) — অন্য কোনো Result পেজ থেকে সরাসরি এলেও যেন এরর না হয়,
// তাই এখানেও নিরাপদে auto-create করা হচ্ছে (result_exam_terms.php এর সাথে একই স্ট্রাকচার)
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// বর্তমান একাডেমিক সেশন
// -----------------------------------------------------------
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$current_session_name = $current_session['session_name'] ?? '—';

$institute = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
$sp_bid = branch_current_id();
if ($sp_bid !== null) {
    $sp_br_stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ? LIMIT 1");
    $sp_br_stmt->execute([$sp_bid]);
    $sp_branch = $sp_br_stmt->fetch();
} else {
    $sp_branch = $pdo->query("SELECT branch_name FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$sp_ac_settings = ac_load_settings($pdo, $sp_bid);
$sp_inst_name = trim((string) ($sp_ac_settings['custom_institute_name'] ?? ''))
    ?: (trim((string) ($sp_branch['branch_name'] ?? '')) ?: trim((string) ($institute['institute_name'] ?? '')));
$sp_logo_path = trim((string) ($sp_ac_settings['logo_path'] ?? ''))
    ?: trim((string) ($institute['logo_path'] ?? ''));

// -----------------------------------------------------------
// POST: ফর্ম সাবমিট -> ফিল্টার সেশনে রেখে output ভিউতে রিডাইরেক্ট
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_seat_plan'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_exam_seat_plan');
        exit();
    }

    $f_class_id = (int) ($_POST['class_id'] ?? 0);
    $f_term_id  = (int) ($_POST['term_id'] ?? 0);

    if ($f_class_id <= 0 || $f_term_id <= 0) {
        $_SESSION['sp_err'] = 'Class এবং Term আবশ্যক।';
        header('Location: index.php?page=result_exam_seat_plan');
        exit();
    }

    $_SESSION['sp_generate'] = [
        'class_id'   => $f_class_id,
        'shift_id'   => (int) ($_POST['shift_id'] ?? 0),
        'section_id' => (int) ($_POST['section_id'] ?? 0),
        'term_id'    => $f_term_id,
        'part_id'    => (int) ($_POST['part_id'] ?? 0),
        'room'       => trim($_POST['room'] ?? ''),
    ];
    $_SESSION['sp_opts'] = [
        'color_mode' => (($_POST['color_mode'] ?? 'color') === 'grayscale') ? 'grayscale' : 'color',
        'show_photo' => !empty($_POST['show_photo']) ? 1 : 0,
    ];

    // Room তথ্য সেভ করা হচ্ছে (থাকলে) — যাতে Student পোর্টাল থেকে পরে ঠিক একই Room দেখা যায়
    if ($_SESSION['sp_generate']['room'] !== '') {
        $room_stmt = $pdo->prepare(
            "INSERT INTO exam_seat_rooms (session_id, term_id, part_id, class_id, shift_id, section_id, room)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE room = VALUES(room)"
        );
        $room_stmt->execute([
            $current_session_id,
            $f_term_id,
            (int) ($_POST['part_id'] ?? 0),
            $f_class_id,
            (int) ($_POST['shift_id'] ?? 0),
            (int) ($_POST['section_id'] ?? 0),
            $_SESSION['sp_generate']['room'],
        ]);
        sec_log_activity($pdo, 'Result', 'Update', "ক্লাস আইডি {$f_class_id}, টার্ম আইডি {$f_term_id} এর Exam Seat Plan Room ({$_SESSION['sp_generate']['room']}) সংরক্ষণ করা হয়েছে");
    }

    header('Location: index.php?page=result_exam_seat_plan&view=output');
    exit();
}

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

$sp_opts = is_array($_SESSION['sp_opts'] ?? null) ? $_SESSION['sp_opts'] : [];
$sp_color = isset($_GET['color_mode'])
    ? (($_GET['color_mode'] === 'grayscale') ? 'grayscale' : 'color')
    : ((($sp_opts['color_mode'] ?? 'color') === 'grayscale') ? 'grayscale' : 'color');
$sp_photo = isset($_GET['show_photo'])
    ? (!empty($_GET['show_photo']) ? 1 : 0)
    : (!empty($sp_opts['show_photo']) ? 1 : 0);
$_SESSION['sp_opts'] = ['color_mode' => $sp_color, 'show_photo' => $sp_photo];

$view = $_GET['view'] ?? 'config';
?>

<?php if ($view === 'output'): ?>
    <?php
    // ================= OUTPUT VIEW: সিট লেআউট কার্ড জেনারেট =================
    $g = $_SESSION['sp_generate'] ?? [];

    if (!$g) {
        header('Location: index.php?page=result_exam_seat_plan');
        exit();
    }

    $where = ["students.status = 'Active'"];
    $params = [];
    if (!empty($g['class_id']))   { $where[] = 'students.class_id = ?'; $params[] = $g['class_id']; }
    if (!empty($g['shift_id']))   { $where[] = 'students.shift_id = ?'; $params[] = $g['shift_id']; }
    if (!empty($g['section_id'])) { $where[] = 'students.section_id = ?'; $params[] = $g['section_id']; }
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
    $part_name = '';
    if (!empty($g['part_id'])) {
        $ps = $pdo->prepare("SELECT part_name FROM exam_parts WHERE id = ?");
        $ps->execute([$g['part_id']]);
        $part_name = $ps->fetchColumn() ?: '';
    }
    $room = trim((string) ($g['room'] ?? ''));
    $seat_year = date('Y');
    ?>
    <?php echo sc_css(); ?>

    <div class="no-print d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <a href="index.php?page=result_exam_seat_plan" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="result_exam_seat_plan">
            <input type="hidden" name="view" value="output">
            <select name="color_mode" data-ss="off" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()" title="Print Color Mode">
                <option value="color"<?php echo $sp_color === 'color' ? ' selected' : ''; ?>>Color</option>
                <option value="grayscale"<?php echo $sp_color === 'grayscale' ? ' selected' : ''; ?>>Grayscale (B&amp;W)</option>
            </select>
            <select name="show_photo" data-ss="off" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()" title="Student Photo">
                <option value="0"<?php echo $sp_photo === 0 ? ' selected' : ''; ?>>Without Photo</option>
                <option value="1"<?php echo $sp_photo === 1 ? ' selected' : ''; ?>>With Photo</option>
            </select>
            <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </form>
    </div>

    <?php if (!$students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো শিক্ষার্থী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="sc-grid<?php echo $sp_color === 'grayscale' ? ' sc-gray' : ''; ?>">
            <?php foreach ($students as $st): ?>
                <?php echo sc_card([
                    'institute' => $sp_inst_name, 'logo' => $sp_logo_path,
                    'room' => $room, 'term' => $term_name, 'part' => $part_name, 'year' => $seat_year,
                    'name' => $st['name_en'], 'class' => $st['class_name'] ?? '', 'shift' => $st['shift_name'] ?? '',
                    'section' => $st['section_name'] ?? '', 'roll' => $st['roll_no'], 'student_id' => $st['student_id'],
                    'photo' => $st['photo_path'] ?? '', 'show_photo' => ($sp_photo === 1),
                ]); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ================= CONFIG VIEW ================= -->
    <div class="card-custom">
        <?php if ($sp_err): ?><div class="alert alert-danger py-2"><?php echo spv($sp_err); ?></div><?php endif; ?>

        <h6 class="text-muted mb-4">Generate Exam Seat Plan for Session : <?php echo spv($current_session_name); ?></h6>

        <form method="POST" action="index.php?page=result_exam_seat_plan">
            <?php echo csrf_field(); ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="class_id" id="spClassSelect" class="form-select" required onchange="filterSpSections()">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo spv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Term <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="term_id" class="form-select" required>
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo spv($t['term_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shift</label>
                        <div class="col-sm-8">
                            <select name="shift_id" id="spShiftSelect" class="form-select" onchange="filterSpSections()">
                                <option value="">Select Shift</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>"><?php echo spv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Part</label>
                        <div class="col-sm-8">
                            <select name="part_id" class="form-select">
                                <option value="">Select Exam Part</option>
                                <?php foreach ($parts as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo spv($p['part_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                        <div class="col-sm-8">
                            <select name="section_id" id="spSectionSelect" class="form-select" disabled>
                                <option value="">Select Class first</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" data-shift="<?php echo (int) $sec['shift_id']; ?>" hidden><?php echo spv($sec['section_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Room</label>
                        <div class="col-sm-8"><input type="text" name="room" class="form-control" placeholder="i.e : 101"></div>
                    </div>
                </div>
            </div>

            <hr>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="d-flex align-items-center gap-2">
                        <label class="fw-bold mb-0 small">Mode:</label>
                        <select name="color_mode" data-ss="off" class="form-select form-select-sm" style="width:auto;">
                            <option value="color"<?php echo $sp_color === 'color' ? ' selected' : ''; ?>>Color</option>
                            <option value="grayscale"<?php echo $sp_color === 'grayscale' ? ' selected' : ''; ?>>Grayscale (B&amp;W)</option>
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="fw-bold mb-0 small">Photo:</label>
                        <select name="show_photo" data-ss="off" class="form-select form-select-sm" style="width:auto;">
                            <option value="0"<?php echo $sp_photo === 0 ? ' selected' : ''; ?>>Without Photo</option>
                            <option value="1"<?php echo $sp_photo === 1 ? ' selected' : ''; ?>>With Photo</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="generate_seat_plan" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </form>
    </div>

    <?php if (!$terms): ?>
        <div class="alert alert-warning mt-3">
            এই সেশনের জন্য কোনো <strong>Exam Term</strong> সেট করা নেই। সিট প্ল্যান জেনারেট করার আগে
            <a href="index.php?page=result_exam_terms">Exam Terms</a> পেজ থেকে একটা Term যোগ করুন।
        </div>
    <?php endif; ?>

    <script>
        function filterSpSections() {
            const classId = document.getElementById('spClassSelect').value;
            const shiftId = document.getElementById('spShiftSelect').value;
            const select = document.getElementById('spSectionSelect');
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
        filterSpSections();
    </script>
<?php endif; ?>
