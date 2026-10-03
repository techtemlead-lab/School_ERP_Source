<?php
// admin/admission_seat_plan.php - Admission Module > Admission Test/Lottery > Seat Plan
// দুইটা অংশ:
//  ১) Seat Summary Report — Session-এর প্রতিটা Class-এর Total Seats vs Applied/Selected/Waitlisted/Vacant (Print/Download)
//  ২) Exam Room Seat Plan — Class(+Section)+Room দিয়ে প্রার্থীদের জন্য প্রিন্টযোগ্য সিট কার্ড (result module এর Exam Seat Plan এর প্যাটার্ন অনুসরণ করে)

function adsp_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admission_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        class_id INT NOT NULL,
        method ENUM('None','Test','Lottery') NOT NULL DEFAULT 'None',
        total_seats INT NULL,
        full_marks INT NULL,
        pass_marks INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_session_class (session_id, class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$institute = $pdo->query("SELECT institute_name, logo_path FROM institute_settings WHERE id = 1")->fetch();
$sessions  = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections  = $pdo->query("SELECT id, section_name, class_id, shift_id FROM academic_sections WHERE status = 'Active' ORDER BY section_name ASC")->fetchAll();
$current_session = $pdo->query("SELECT id, session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$sp_err = $_SESSION['sp_err'] ?? '';
unset($_SESSION['sp_err']);

// ---------------------------------------------------------------
// Exam Room Seat Plan ফর্ম সাবমিট -> সেশনে রেখে output ভিউতে রিডাইরেক্ট
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_seat_plan'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sp_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=admission_seat_plan');
        exit();
    }
    $f_session_id = (int) ($_POST['session_id'] ?? 0);
    $f_class_id   = (int) ($_POST['class_id'] ?? 0);

    if ($f_session_id <= 0 || $f_class_id <= 0) {
        $_SESSION['sp_err'] = 'Session এবং Class আবশ্যক।';
        header('Location: index.php?page=admission_seat_plan');
        exit();
    }

    $_SESSION['adsp_generate'] = [
        'session_id' => $f_session_id,
        'class_id'   => $f_class_id,
        'section_id' => (int) ($_POST['section_id'] ?? 0),
        'room'       => trim($_POST['room'] ?? ''),
        'exam_date'  => trim($_POST['exam_date'] ?? ''),
    ];
    header('Location: index.php?page=admission_seat_plan&view=cards');
    exit();
}

$view = $_GET['view'] ?? 'plan';

// =================================================================
// VIEW: exam room seat কার্ড আউটপুট
// =================================================================
if ($view === 'cards'):
    $g = $_SESSION['adsp_generate'] ?? [];
    unset($_SESSION['adsp_generate']);
    if (!$g) { header('Location: index.php?page=admission_seat_plan'); exit(); }

    $where = ["students.session_id = ?", "students.class_id = ?", "students.status = 'Pending'", "students.admission_result IS NULL"];
    $params = [$g['session_id'], $g['class_id']];
    if (!empty($g['section_id'])) { $where[] = 'students.section_id = ?'; $params[] = $g['section_id']; }

    $sql = "SELECT students.*, academic_classes.class_name, academic_sections.section_name
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (students.roll_no + 0) ASC, students.roll_no ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cand_students = $stmt->fetchAll();
    $room = trim((string) ($g['room'] ?? ''));
    $exam_date = trim((string) ($g['exam_date'] ?? ''));
    ?>
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        .seatcard {
            width: 330px; border: 1px solid #333; border-radius: 4px; padding: 12px 14px;
            display: inline-block; vertical-align: top; margin: 6px; font-family: Arial, sans-serif;
            page-break-inside: avoid; background: #fff;
        }
        .seatcard .sc-top { display: flex; justify-content: space-between; align-items: flex-start; }
        .seatcard .sc-qr { width: 60px; height: 60px; }
        .seatcard .sc-photo { width: 55px; height: 65px; object-fit: cover; border: 1px solid #999; border-radius: 3px; background: #eee; }
        .seatcard .sc-inst { flex: 1; text-align: center; font-size: 13px; font-weight: bold; color: #1e3a8a; padding: 0 6px; }
        .seatcard .sc-inst small { display: block; font-weight: normal; color: #2563eb; font-size: 10.5px; }
        .seatcard .sc-title { text-align: center; font-weight: bold; font-size: 13px; margin-top: 6px; }
        .seatcard .sc-badge {
            display: block; text-align: center; font-weight: bold; font-size: 12px; margin: 6px auto 8px;
            background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 3px; padding: 3px 0; width: 80%;
        }
        .seatcard .sc-info { font-size: 12.5px; line-height: 1.9; }
        .seatcard .sc-info .row2 { display: flex; justify-content: space-between; }
    </style>

    <div class="no-print d-flex justify-content-between align-items-center mb-3">
        <a href="index.php?page=admission_seat_plan" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-download"></i> Print / Download PDF</button>
    </div>

    <?php if (!$cand_students): ?>
        <div class="card-custom"><p class="text-muted mb-0">নির্বাচিত ফিল্টারে কোনো Pending আবেদনকারী পাওয়া যায়নি।</p></div>
    <?php else: ?>
        <div class="text-center">
            <?php foreach ($cand_students as $i => $st): ?>
                <div class="seatcard">
                    <div class="sc-top">
                        <div class="sc-qr" id="adspqr-<?php echo (int) $i; ?>"></div>
                        <div class="sc-inst">
                            <?php echo adsp_v($institute['institute_name'] ?? ''); ?>
                            <?php if ($room !== ''): ?><small>Room : <?php echo adsp_v($room); ?></small><?php endif; ?>
                        </div>
                        <?php if (!empty($st['photo_path'])): ?>
                            <img class="sc-photo" src="../<?php echo adsp_v($st['photo_path']); ?>">
                        <?php else: ?>
                            <div class="sc-photo d-flex align-items-center justify-content-center text-muted" style="font-size:9px;">Photo</div>
                        <?php endif; ?>
                    </div>

                    <div class="sc-title">Admission Test<?php echo $exam_date !== '' ? ' — ' . adsp_v($exam_date) : ''; ?></div>
                    <div class="sc-badge">Exam Seat Layout</div>

                    <div class="sc-info">
                        <div><strong>Name :</strong> <?php echo adsp_v($st['name_en']); ?></div>
                        <div class="row2">
                            <span><strong>Class :</strong> <?php echo adsp_v($st['class_name'] ?? '-'); ?></span>
                            <span><strong>Section :</strong> <?php echo adsp_v($st['section_name'] ?? '-'); ?></span>
                        </div>
                        <div class="row2">
                            <span><strong>App. No :</strong> <?php echo adsp_v($st['roll_no']); ?></span>
                            <span><strong>Applicant ID :</strong> <?php echo adsp_v($st['student_id']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            <?php foreach ($cand_students as $i => $st): ?>
                (function () {
                    var el = document.getElementById('adspqr-<?php echo (int) $i; ?>');
                    if (el && window.QRCode) {
                        new QRCode(el, { text: <?php echo json_encode((string) $st['student_id']); ?>, width: 60, height: 60 });
                    }
                })();
            <?php endforeach; ?>
        </script>
    <?php endif; ?>

<?php else: ?>
<!-- =================================================================
     VIEW: প্ল্যান পেজ (Summary Report + Room Card Generator ফর্ম)
     ================================================================= -->
<div class="card-custom mb-3 no-print">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_settings" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-sliders"></i> Settings</a>
        <a href="index.php?page=admission_applicants" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-list-check"></i> Applied Student List</a>
        <a href="index.php?page=admission_mark_entry" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Mark Entry</a>
        <a href="index.php?page=admission_publish" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-bullhorn"></i> Result / Publish</a>
        <a href="index.php?page=admission_seat_plan" class="btn btn-primary btn-sm"><i class="fa-solid fa-chair"></i> Seat Plan</a>
    </div>
</div>

<?php
// ---- Seat Summary Report ডেটা ----
$sr_session_id = (int) ($_GET['sr_session'] ?? ($current_session['id'] ?? 0));
$summary_rows = [];
if ($sr_session_id) {
    $es = $pdo->prepare("SELECT * FROM admission_settings WHERE session_id = ?");
    $es->execute([$sr_session_id]);
    $settings_by_class = [];
    foreach ($es->fetchAll() as $row) { $settings_by_class[(int) $row['class_id']] = $row; }

    // 'admission_result IS NOT NULL' দিয়ে Confirm (Active) হয়ে যাওয়া প্রার্থীরাও ধরা পড়বে,
    // কারণ Confirm করলে শুধু status Active হয়, admission_result='Selected' রয়েই যায়।
    $cs = $pdo->prepare(
        "SELECT class_id,
            SUM(CASE WHEN status = 'Pending' AND admission_result IS NULL THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN admission_result = 'Selected' THEN 1 ELSE 0 END) AS selected_cnt,
            SUM(CASE WHEN admission_result = 'Waitlisted' THEN 1 ELSE 0 END) AS waitlisted_cnt,
            SUM(CASE WHEN admission_result = 'Rejected' THEN 1 ELSE 0 END) AS rejected_cnt
         FROM students WHERE session_id = ? AND (status = 'Pending' OR admission_result IS NOT NULL)
         GROUP BY class_id"
    );
    $cs->execute([$sr_session_id]);
    $counts_by_class = [];
    foreach ($cs->fetchAll() as $row) { $counts_by_class[(int) $row['class_id']] = $row; }

    foreach ($classes as $c) {
        $cid = (int) $c['id'];
        $set = $settings_by_class[$cid] ?? null;
        $cnt = $counts_by_class[$cid] ?? null;
        $total_seats = $set['total_seats'] ?? null;
        $selected = (int) ($cnt['selected_cnt'] ?? 0);
        $summary_rows[] = [
            'class_name' => $c['class_name'],
            'total_seats' => $total_seats,
            'applied' => (int) ($cnt['pending_cnt'] ?? 0) + $selected + (int) ($cnt['waitlisted_cnt'] ?? 0) + (int) ($cnt['rejected_cnt'] ?? 0),
            'selected' => $selected,
            'waitlisted' => (int) ($cnt['waitlisted_cnt'] ?? 0),
            'vacant' => $total_seats !== null ? max(0, (int) $total_seats - $selected) : null,
        ];
    }
}
?>

<div class="card-custom mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-secondary mb-0">১) Seat Summary Report</h6>
        <?php if ($summary_rows): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="fa-solid fa-download"></i> Print / Download</button>
        <?php endif; ?>
    </div>
    <form method="GET" action="index.php" class="row g-3 align-items-end mb-3 no-print">
        <input type="hidden" name="page" value="admission_seat_plan">
        <div class="col-sm-4">
            <label class="form-label fw-bold">Session</label>
            <select name="sr_session" class="form-select" onchange="this.form.submit()">
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $sr_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adsp_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if (!$summary_rows): ?>
        <p class="text-muted mb-0">এই Session-এর জন্য কোনো ডেটা পাওয়া যায়নি।</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr><th>Class</th><th>Total Seats</th><th>Applied</th><th>Selected</th><th>Waitlisted</th><th>Vacant Seats</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($summary_rows as $r): ?>
                        <tr>
                            <td><?php echo adsp_v($r['class_name']); ?></td>
                            <td><?php echo adsp_v($r['total_seats'] ?? '—'); ?></td>
                            <td><?php echo adsp_v($r['applied']); ?></td>
                            <td><?php echo adsp_v($r['selected']); ?></td>
                            <td><?php echo adsp_v($r['waitlisted']); ?></td>
                            <td><?php echo $r['vacant'] === null ? '—' : ('<span class="badge ' . ($r['vacant'] > 0 ? 'bg-success' : 'bg-secondary') . '">' . $r['vacant'] . '</span>'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card-custom no-print">
    <h6 class="fw-bold text-secondary mb-3">২) Exam Room Seat Plan (প্রিন্টযোগ্য সিট কার্ড)</h6>
    <?php if ($sp_err): ?><div class="alert alert-danger py-2"><?php echo adsp_v($sp_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=admission_seat_plan">
        <?php echo csrf_field(); ?>
        <div class="row">
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="session_id" class="form-select" required>
                            <option value="">Select Session</option>
                            <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo (int) ($current_session['id'] ?? 0) === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adsp_v($s['session_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                    <div class="col-sm-8">
                        <select name="class_id" id="adspClassSelect" class="form-select" required onchange="adspFilterSections()">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo adsp_v($c['class_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Section</label>
                    <div class="col-sm-8">
                        <select name="section_id" id="adspSectionSelect" class="form-select" disabled>
                            <option value="">Select Class first</option>
                            <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" data-class="<?php echo (int) $sec['class_id']; ?>" hidden><?php echo adsp_v($sec['section_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Exam Date</label>
                    <div class="col-sm-8"><input type="date" name="exam_date" class="form-control"></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-4 col-form-label fw-bold text-end">Room</label>
                    <div class="col-sm-8"><input type="text" name="room" class="form-control" placeholder="যেমন: 101"></div>
                </div>
            </div>
        </div>
        <p class="text-muted small">শুধু ঐ Session+Class-এর <strong>Pending</strong> আবেদনকারীদের জন্য কার্ড তৈরি হবে (এখনো ফলাফল/ভর্তি নিশ্চিত হয়নি এমন সবাই)।</p>
        <hr>
        <div class="text-end">
            <button type="submit" name="generate_seat_plan" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;">Generate Seat Cards</button>
        </div>
    </form>
</div>

<script>
    function adspFilterSections() {
        const classId = document.getElementById('adspClassSelect').value;
        const select = document.getElementById('adspSectionSelect');
        select.disabled = !classId;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !!classId && opt.dataset.class !== classId;
        });
        select.options[0].textContent = classId ? '-- সব Section --' : 'Select Class first';
        if (select.value && Array.from(select.options).find(o => o.value === select.value)?.hidden) {
            select.value = '';
        }
    }
    adspFilterSections();
</script>
<?php endif; ?>
