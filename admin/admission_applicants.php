<?php
// admin/admission_applicants.php - Admission Module > Admission Test/Lottery > Applied Student List
// একটা Session+Class নির্বাচন করলে সেই ক্লাসে যারা Apply করেছে (status='Pending') তাদের পুরো লিস্ট দেখা যায়।

function adapp_v($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// পুরনো ইনস্টলেশনে students.test_marks / admission_result কলাম না থাকলে নিরাপদে যোগ করে দেওয়া হচ্ছে
function adapp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition"); }
}
adapp_ensure_column($pdo, 'students', 'test_marks', "DECIMAL(6,2) NULL");
adapp_ensure_column($pdo, 'students', 'admission_result', "VARCHAR(20) NULL");

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$class_names = [];
foreach ($classes as $c) { $class_names[(int) $c['id']] = $c['class_name']; }
$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();

$f_session = (int) ($_GET['f_session'] ?? ($current_session['id'] ?? 0));
$f_class   = (int) ($_GET['f_class'] ?? 0);
$f_search  = trim($_GET['q'] ?? '');

$rows = [];
if ($f_session) {
    $where = ['session_id = ?', "status = 'Pending'"];
    $params = [$f_session];
    if ($f_class) { $where[] = 'class_id = ?'; $params[] = $f_class; }
    if ($f_search !== '') {
        $where[] = '(name_en LIKE ? OR name_bn LIKE ? OR student_id LIKE ? OR mobile LIKE ? OR guardian_phone LIKE ?)';
        $like = '%' . $f_search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql = "SELECT id, student_id, name_en, name_bn, class_id, roll_no, mobile, guardian_phone, test_marks, admission_result
            FROM students WHERE " . implode(' AND ', $where) . " ORDER BY class_id ASC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$badge = ['Selected' => 'success', 'Waitlisted' => 'warning', 'Rejected' => 'danger'];

// "View" বাটনে ক্লিক করলে (view=details) নির্দিষ্ট Applicant-এর পুরো তথ্য এখানেই Read-only দেখানো হয়
$view = $_GET['view'] ?? 'list';
$detail = null;
if ($view === 'details') {
    $detail_id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT s.*, c.class_name, sh.shift_name, sec.section_name, ses.session_name, g.group_name
         FROM students s
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         LEFT JOIN academic_sessions ses ON ses.id = s.session_id
         LEFT JOIN academic_groups g ON g.id = s.group_id
         WHERE s.id = ?"
    );
    $stmt->execute([$detail_id]);
    $detail = $stmt->fetch();
}
?>
<style>
    @media print { .no-print { display: none !important; } body { background: #fff; } }
</style>
    <?php require_once __DIR__ . '/employee_attendance_common.php'; ea_print_css('landscape', 9); ?>

<div class="card-custom mb-3 no-print">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_settings" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-sliders"></i> Settings</a>
        <a href="index.php?page=admission_applicants" class="btn btn-primary btn-sm"><i class="fa-solid fa-list-check"></i> Applied Student List</a>
        <a href="index.php?page=admission_mark_entry" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Mark Entry</a>
        <a href="index.php?page=admission_publish" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-bullhorn"></i> Result / Publish</a>
        <a href="index.php?page=admission_seat_plan" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-chair"></i> Seat Plan</a>
    </div>
</div>

<?php if ($view === 'details'): ?>
    <div class="card-custom">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="index.php?page=admission_applicants&f_session=<?php echo (int) $f_session; ?>" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Applicant List</a>
            <span class="text-muted small">Read Only — তথ্য পরিবর্তন করতে Edit বাটন ব্যবহার করুন</span>
        </div>
        <?php if (!$detail): ?>
            <p class="text-muted">এই Applicant খুঁজে পাওয়া যায়নি।</p>
        <?php else: ?>
            <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><?php echo adapp_v($detail['name_en']); ?> <?php if ($detail['name_bn']): ?><small class="text-muted">(<?php echo adapp_v($detail['name_bn']); ?>)</small><?php endif; ?></h6>
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><td class="fw-bold" style="width:45%;">Applicant ID</td><td><?php echo adapp_v($detail['student_id']); ?></td></tr>
                        <tr><td class="fw-bold">Session</td><td><?php echo adapp_v($detail['session_name']); ?></td></tr>
                        <tr><td class="fw-bold">Class</td><td><?php echo adapp_v($detail['class_name']); ?></td></tr>
                        <tr><td class="fw-bold">Section</td><td><?php echo adapp_v($detail['section_name']); ?></td></tr>
                        <tr><td class="fw-bold">Shift</td><td><?php echo adapp_v($detail['shift_name']); ?></td></tr>
                        <tr><td class="fw-bold">Group</td><td><?php echo adapp_v($detail['group_name'] ?: '-'); ?></td></tr>
                        <tr><td class="fw-bold">App. No (Roll)</td><td><?php echo $detail['roll_no'] !== '' ? adapp_v($detail['roll_no']) : '<span class="text-muted">Test/Lottery-এর পর নির্ধারিত হবে</span>'; ?></td></tr>
                        <tr><td class="fw-bold">Gender</td><td><?php echo adapp_v($detail['gender']); ?></td></tr>
                        <tr><td class="fw-bold">Date of Birth</td><td><?php echo adapp_v($detail['dob']); ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><td class="fw-bold" style="width:45%;">Mobile</td><td><?php echo adapp_v($detail['mobile']); ?></td></tr>
                        <tr><td class="fw-bold">Email</td><td><?php echo adapp_v($detail['email']); ?></td></tr>
                        <tr><td class="fw-bold">Father's Name</td><td><?php echo adapp_v($detail['father_name']); ?></td></tr>
                        <tr><td class="fw-bold">Mother's Name</td><td><?php echo adapp_v($detail['mother_name']); ?></td></tr>
                        <tr><td class="fw-bold">Guardian's Name</td><td><?php echo adapp_v($detail['guardian_name']); ?></td></tr>
                        <tr><td class="fw-bold">Guardian's Phone</td><td><?php echo adapp_v($detail['guardian_phone']); ?></td></tr>
                        <tr><td class="fw-bold">Present Address</td><td><?php echo adapp_v($detail['present_address']); ?></td></tr>
                        <tr><td class="fw-bold">Test Marks</td><td><?php echo $detail['test_marks'] !== null ? adapp_v($detail['test_marks']) : '-'; ?></td></tr>
                        <tr><td class="fw-bold">Result</td><td><span class="badge bg-<?php echo $badge[$detail['admission_result']] ?? 'secondary'; ?>"><?php echo adapp_v($detail['admission_result'] ?: 'Not Processed'); ?></span></td></tr>
                    </table>
                </div>
            </div>
            <div class="mt-3 no-print">
                <a href="index.php?page=student_admission&view=edit&id=<?php echo (int) $detail['id']; ?>&from=applicants" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
                <a href="index.php?page=print_admission_slip&id=<?php echo (int) $detail['id']; ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-print"></i> Print Admission Slip</a>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="card-custom">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-secondary mb-0">Applied Student List (Class Wise)</h6>
        <?php if ($rows): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <?php endif; ?>
    </div>

    <form method="GET" action="index.php" class="row g-3 align-items-end mb-3 no-print">
        <input type="hidden" name="page" value="admission_applicants">
        <div class="col-sm-3">
            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
            <select name="f_session" class="form-select" required onchange="this.form.submit()">
                <option value="">Select Session</option>
                <?php foreach ($sessions as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $f_session === (int) $s['id'] ? 'selected' : ''; ?>><?php echo adapp_v($s['session_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-bold">Class</label>
            <select name="f_class" class="form-select" onchange="this.form.submit()">
                <option value="">-- সব Class --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $f_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo adapp_v($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-4">
            <label class="form-label fw-bold">খুঁজুন (নাম / আইডি / মোবাইল)</label>
            <input type="text" name="q" class="form-control" value="<?php echo adapp_v($f_search); ?>" placeholder="যেমন: Rahim / 26010012 / 017...">
        </div>
        <div class="col-sm-2">
            <button type="submit" class="btn btn-primary w-100">Search</button>
        </div>
    </form>

    <?php if (!$f_session): ?>
        <p class="text-muted">প্রথমে একটা Session সিলেক্ট করুন।</p>
    <?php elseif (!$rows): ?>
        <p class="text-muted">এই ফিল্টারে কোনো Pending আবেদনকারী পাওয়া যায়নি।</p>
    <?php else: ?>
        <p class="text-muted small">মোট <strong><?php echo count($rows); ?></strong> জন আবেদনকারী পাওয়া গেছে।</p>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr><th>#</th><th>Applicant ID</th><th>Name</th><th>Class</th><th>App. No</th><th>Mobile</th><th>Test Marks</th><th>Result</th><th class="no-print">Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $row): $rb = $badge[$row['admission_result']] ?? 'secondary'; ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo adapp_v($row['student_id']); ?></td>
                            <td><strong><?php echo adapp_v($row['name_en']); ?></strong><?php if ($row['name_bn']): ?><br><small class="text-muted"><?php echo adapp_v($row['name_bn']); ?></small><?php endif; ?></td>
                            <td><?php echo adapp_v($class_names[(int) $row['class_id']] ?? '-'); ?></td>
                            <td><?php echo $row['roll_no'] !== '' ? adapp_v($row['roll_no']) : '<span class="text-muted">Test/Lottery-এর পর</span>'; ?></td>
                            <td><?php echo adapp_v($row['mobile'] ?: $row['guardian_phone']); ?></td>
                            <td><?php echo $row['test_marks'] !== null ? adapp_v($row['test_marks']) : '<span class="text-muted">-</span>'; ?></td>
                            <td><span class="badge bg-<?php echo $rb; ?>"><?php echo adapp_v($row['admission_result'] ?: 'Not Processed'); ?></span></td>
                            <td class="text-nowrap no-print">
                                <a href="index.php?page=admission_applicants&view=details&id=<?php echo (int) $row['id']; ?>&f_session=<?php echo (int) $f_session; ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a href="index.php?page=student_admission&view=edit&id=<?php echo (int) $row['id']; ?>&from=applicants" class="btn btn-sm btn-outline-primary" title="Edit (শুধু তথ্য)"><i class="fa-solid fa-pen"></i></a>
                                <a href="index.php?page=print_admission_slip&id=<?php echo (int) $row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Print Admission Slip"><i class="fa-solid fa-print"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
