<?php
// admin/scholarship_award.php - Finance Module > Scholarship & Welfare > Assign Scholarship
// একজন নির্দিষ্ট ছাত্র/ছাত্রীকে একটা স্কলারশিপে কত টাকা এসেছে/এসেছে বলে নিশ্চিত হয়েছে সেটা এখানে
// এন্ট্রি করা হয় (status='Pending')। টাকাটা আসলে কীভাবে ব্যবহার হবে (বকেয়ার সাথে সমন্বয় /
// অভিভাবককে হাতে দেওয়া) সেটা পরের ধাপ — Process Disbursement পেজে করা হয়।
require_once __DIR__ . '/../config/scholarship_helpers.php';
sch_ensure_tables($pdo);

function sav($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function sa_money($n): string { return number_format((float) $n, 2); }

$sa_msg = $_SESSION['sa_msg'] ?? '';
$sa_err = $_SESSION['sa_err'] ?? '';
unset($_SESSION['sa_msg'], $_SESSION['sa_err']);

$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sa_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=scholarship_award');
        exit();
    }

    if (isset($_POST['save_award'])) {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        $scholarship_type_id = (int) ($_POST['scholarship_type_id'] ?? 0);
        $session_id = (int) ($_POST['session_id'] ?? 0);
        $class_id = (int) ($_POST['class_id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');

        if (!$student_id || !$scholarship_type_id || !$session_id || !$class_id || $amount <= 0) {
            $_SESSION['sa_err'] = 'Student, Scholarship Type, Session, Class ও সঠিক Amount আবশ্যক।';
        } else {
            $pdo->prepare(
                "INSERT INTO scholarship_awards (student_id, scholarship_type_id, session_id, class_id, amount, status, remarks, created_by)
                 VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)"
            )->execute([$student_id, $scholarship_type_id, $session_id, $class_id, $amount, $remarks ?: null, $_SESSION['admin_user'] ?? '']);
            $_SESSION['sa_msg'] = 'স্কলারশিপ Award এন্ট্রি করা হয়েছে — এখন "Process Disbursement" থেকে টাকা বণ্টন করুন।';
            $sa_student_row = $pdo->prepare("SELECT name_en, roll_no FROM students WHERE id = ?");
            $sa_student_row->execute([$student_id]);
            $sa_student = $sa_student_row->fetch();
            $sa_type_row = $pdo->prepare("SELECT name FROM scholarship_types WHERE id = ?");
            $sa_type_row->execute([$scholarship_type_id]);
            $sa_type_name = $sa_type_row->fetchColumn();
            sec_log_activity($pdo, 'Scholarship', 'Create', "স্কলারশিপ Award এন্ট্রি করা হয়েছে: {$sa_student['name_en']} (Roll: {$sa_student['roll_no']}) - {$sa_type_name} - ৳" . sa_money($amount));
        }
    } elseif (isset($_POST['cancel_award'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $cancel_row = $pdo->prepare(
            "SELECT a.amount, st.name_en, st.roll_no FROM scholarship_awards a LEFT JOIN students st ON st.id = a.student_id WHERE a.id = ?"
        );
        $cancel_row->execute([$id]);
        $cancel_info = $cancel_row->fetch();
        $pdo->prepare("UPDATE scholarship_awards SET status = 'Cancelled' WHERE id = ? AND status = 'Pending'")->execute([$id]);
        $_SESSION['sa_msg'] = 'Award বাতিল করা হয়েছে।';
        if ($cancel_info) {
            sec_log_activity($pdo, 'Scholarship', 'Update', "স্কলারশিপ Award বাতিল করা হয়েছে: {$cancel_info['name_en']} (Roll: {$cancel_info['roll_no']}) - ৳" . sa_money($cancel_info['amount']));
        }
    }
    header('Location: index.php?page=scholarship_award');
    exit();
}

$scholarship_types = $pdo->query("SELECT id, name, category FROM scholarship_types WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$fsw_where = ["status = 'Active'"]; $fsw_params = [];
branch_apply_filter($fsw_where, $fsw_params);
$fsw_stmt = $pdo->prepare("SELECT id, student_id, roll_no, name_en, gender, session_id, class_id FROM students WHERE " . implode(' AND ', $fsw_where) . " ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC");
$fsw_stmt->execute($fsw_params);
$students = $fsw_stmt->fetchAll();

$view = $_GET['view'] ?? 'list';
?>

<?php if (empty($scholarship_types)): ?>
    <div class="alert alert-warning">
        কোনো Active স্কলারশিপ টাইপ পাওয়া যায়নি। আগে <a href="index.php?page=scholarship_types&view=add">Scholarship Types</a> থেকে অন্তত একটা টাইপ তৈরি করুন।
    </div>
<?php endif; ?>

<?php if ($view === 'add'): ?>
    <div class="card-custom">
        <?php if ($sa_err): ?><div class="alert alert-danger py-2"><?php echo sav($sa_err); ?></div><?php endif; ?>
        <form method="POST" action="index.php?page=scholarship_award" id="saForm">
            <?php echo csrf_field(); ?>

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Scholarship Type <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="scholarship_type_id" class="form-select" required>
                        <option value="">Select</option>
                        <?php foreach ($scholarship_types as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>"><?php echo sav($t['name'] . ' (' . sch_category_label($t['category']) . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Session <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="session_id" id="saSession" class="form-select" required onchange="saFilterStudents()">
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $current_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo sav($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Class <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="class_id" id="saClass" class="form-select" required onchange="saFilterStudents()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?php echo (int) $cl['id']; ?>"><?php echo sav($cl['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Student <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="student_id" id="saStudent" class="form-select" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" data-session="<?php echo (int) $s['session_id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>">
                                <?php echo sav($s['student_id'] . ' - ' . $s['name_en'] . ' - ' . $s['roll_no'] . ' (' . $s['gender'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Amount <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="number" step="0.01" min="0" name="amount" class="form-control" required placeholder="যত টাকা এসেছে/আসবে বলে নিশ্চিত হয়েছে">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Remarks (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <input type="text" name="remarks" class="form-control" placeholder="যেমন: ২০২৬ প্রথম কিস্তি">
                </div>
            </div>
            <hr>
            <div class="text-center">
                <button type="submit" name="save_award" class="btn btn-primary px-4 fw-bold">Save (Pending)</button>
                <a href="index.php?page=scholarship_award" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>
    <script>
        function saFilterStudents() {
            const sessionId = document.getElementById('saSession').value;
            const classId = document.getElementById('saClass').value;
            const studentSelect = document.getElementById('saStudent');
            Array.from(studentSelect.options).forEach(opt => {
                if (!opt.value) { opt.hidden = false; return; }
                const sessionOk = !sessionId || opt.dataset.session === sessionId;
                const classOk = !classId || opt.dataset.class === classId;
                opt.hidden = !(sessionOk && classOk);
            });
        }
        saFilterStudents();
    </script>
<?php else: ?>
    <?php
    $sql = "SELECT a.*, t.name AS type_name, t.category, s.session_name, c.class_name,
                   st.name_en, st.student_id AS student_code, st.roll_no, st.gender
            FROM scholarship_awards a
            LEFT JOIN scholarship_types t ON t.id = a.scholarship_type_id
            LEFT JOIN academic_sessions s ON s.id = a.session_id
            LEFT JOIN academic_classes c ON c.id = a.class_id
            LEFT JOIN students st ON st.id = a.student_id
            ORDER BY a.id DESC";
    $rows = $pdo->query($sql)->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($sa_msg): ?><div class="alert alert-success py-2"><?php echo sav($sa_msg); ?></div><?php endif; ?>
        <?php if ($sa_err): ?><div class="alert alert-danger py-2"><?php echo sav($sa_err); ?></div><?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=scholarship_award&view=add" class="btn btn-success" <?php echo empty($scholarship_types) ? 'onclick="return false;" style="pointer-events:none;opacity:.6;"' : ''; ?>><i class="fa-solid fa-plus"></i> Assign Scholarship</a>
            <div class="d-flex gap-2">
                <a href="index.php?page=scholarship_disbursement" class="btn btn-outline-primary"><i class="fa-solid fa-hand-holding-dollar"></i> Process Disbursement</a>
                <a href="index.php?page=scholarship_report" class="btn btn-outline-secondary"><i class="fa-solid fa-chart-column"></i> Report</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th>Srl.</th><th>Student</th><th>Scholarship</th><th>Session/Class</th><th>Amount</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo sav(($row['student_code'] ?? '') . ' - ' . ($row['name_en'] ?? '—') . ' - ' . ($row['roll_no'] ?? '')); ?> <span class="text-muted small">(<?php echo sav($row['gender']); ?>)</span></td>
                            <td><?php echo sav($row['type_name']); ?></td>
                            <td><?php echo sav($row['session_name'] . ' / ' . $row['class_name']); ?></td>
                            <td class="fw-bold"><?php echo sa_money($row['amount']); ?></td>
                            <td>
                                <?php $badge = ['Pending' => 'warning', 'Disbursed' => 'success', 'Cancelled' => 'secondary'][$row['status']] ?? 'secondary'; ?>
                                <span class="badge bg-<?php echo $badge; ?>"><?php echo sav($row['status']); ?></span>
                            </td>
                            <td class="text-muted small"><?php echo sav($row['remarks']); ?></td>
                            <td class="text-nowrap">
                                <?php if ($row['status'] === 'Pending'): ?>
                                    <a href="index.php?page=scholarship_disbursement&award_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-success" title="Disburse"><i class="fa-solid fa-hand-holding-dollar"></i> Disburse</a>
                                    <form method="POST" action="index.php?page=scholarship_award" class="d-inline" onsubmit="return confirm('এই Award বাতিল করবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button type="submit" name="cancel_award" class="btn btn-sm btn-outline-danger" title="Cancel"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">কোনো Award এন্ট্রি নেই।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
