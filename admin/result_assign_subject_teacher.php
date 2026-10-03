<?php
// admin/result_assign_subject_teacher.php - Result Module > Exam Settings > Assign Subject to Teacher
// মার্কস এন্ট্রির অ্যাক্সেস মূলত Class Routine থেকেই অটো তৈরি হয় (routine_general.php / routine_dynamic.php
// এ যে টিচারকে যে Class+Subject-এ বসানো হয়, সে সাথে সাথেই ঐ Class/Subject-এর মার্কস এন্ট্রি করতে পারবে)।
// এই পেজটা শুধু ব্যতিক্রম/এক্সট্রা কেসের জন্য — কোনো Subject Routine-এ না থাকলেও কোনো টিচারকে
// ম্যানুয়ালি এসাইন করতে চাইলে, বা কিছুদিন পর মার্কস এন্ট্রি শেষে ম্যানুয়াল অ্যাক্সেস সরিয়ে নিতে চাইলে।
// Routine থেকে আসা অ্যাক্সেস সরাতে হলে routine_general.php/routine_dynamic.php থেকে ঐ পিরিয়ডটা
// Inactive/Delete করতে হবে — এখান থেকে না।
$ast_err = $_SESSION['ast_err'] ?? '';
$ast_msg = $_SESSION['ast_msg'] ?? '';
unset($_SESSION['ast_err'], $_SESSION['ast_msg']);

if (!function_exists('lv')) {
    function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ---------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS teacher_subject_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_assign (employee_id, class_id, subject_id),
        KEY idx_employee (employee_id),
        KEY idx_class_subject (class_id, subject_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------
// POST Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ast_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_assign_subject_teacher');
        exit();
    }

    if (isset($_POST['save_assign'])) {
        $employee_pk = (int) ($_POST['employee_pk'] ?? 0);
        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $subject_ids = array_map('intval', (array) ($_POST['subject_ids'] ?? []));

        if (!$employee_pk || !$class_id) {
            $_SESSION['ast_err'] = 'Teacher ও Class নির্বাচন করুন।';
        } else {
            // এই টিচার + এই Class এর জন্য পুরনো এসাইনমেন্ট মুছে নতুন করে সেভ (delete-then-insert)
            $del = $pdo->prepare("DELETE FROM teacher_subject_assignments WHERE employee_id = ? AND class_id = ?");
            $del->execute([$employee_pk, $class_id]);

            if ($subject_ids) {
                $ins = $pdo->prepare("INSERT INTO teacher_subject_assignments (employee_id, class_id, subject_id) VALUES (?, ?, ?)");
                foreach (array_unique($subject_ids) as $sid) {
                    if ($sid > 0) { $ins->execute([$employee_pk, $class_id, $sid]); }
                }
            }
            sec_log_activity($pdo, 'Result', 'Update', "Employee ID {$employee_pk}, Class ID {$class_id} এর জন্য Subject Teacher এসাইন করা হয়েছে");
            $_SESSION['ast_msg'] = count($subject_ids) . ' টা সাবজেক্ট এই টিচারের জন্য এসাইন করা হয়েছে।';
        }

        header('Location: index.php?page=result_assign_subject_teacher&employee_pk=' . $employee_pk . '&class_id=' . $class_id);
        exit();
    } elseif (isset($_POST['remove_class'])) {
        $employee_pk = (int) ($_POST['employee_pk'] ?? 0);
        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $pdo->prepare("DELETE FROM teacher_subject_assignments WHERE employee_id = ? AND class_id = ?")->execute([$employee_pk, $class_id]);
        sec_log_activity($pdo, 'Result', 'Delete', "Employee ID {$employee_pk}, Class ID {$class_id} এর Subject Teacher এসাইনমেন্ট মুছে ফেলা হয়েছে");
        $_SESSION['ast_msg'] = 'এই Class-এর সব এসাইনমেন্ট রিমুভ করা হয়েছে।';
        header('Location: index.php?page=result_assign_subject_teacher&employee_pk=' . $employee_pk);
        exit();
    }
}

// -----------------------------------------------------------
// Teacher (Employee, type = Teachers) সার্চ
// -----------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$search_rows = [];
if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.employee_id, e.name, e.mobile, d.title AS designation_title,
                (SELECT COUNT(DISTINCT class_id) FROM teacher_subject_assignments tsa WHERE tsa.employee_id = e.id) AS assigned_classes
         FROM employees e
         LEFT JOIN designations d ON d.id = e.designation_id
         WHERE e.type = 'Teachers' AND e.status = 'Active' AND e.is_current = 1
           AND (e.name LIKE ? OR e.employee_id LIKE ? OR e.mobile LIKE ?)
         ORDER BY e.name ASC LIMIT 50"
    );
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $search_rows = $stmt->fetchAll();
}

// -----------------------------------------------------------
// Selected Teacher
// -----------------------------------------------------------
$employee_pk = (int) ($_GET['employee_pk'] ?? 0);
$selected_teacher = null;
$assigned_summary = [];
if ($employee_pk > 0) {
    $stmt = $pdo->prepare("SELECT e.*, d.title AS designation_title FROM employees e LEFT JOIN designations d ON d.id = e.designation_id WHERE e.id = ? AND e.type = 'Teachers'");
    $stmt->execute([$employee_pk]);
    $selected_teacher = $stmt->fetch();

    if ($selected_teacher) {
        $sum_stmt = $pdo->prepare(
            "SELECT c.id AS class_id, c.class_name, GROUP_CONCAT(sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') AS subject_names
             FROM teacher_subject_assignments tsa
             JOIN academic_classes c ON c.id = tsa.class_id
             JOIN academic_subjects sub ON sub.id = tsa.subject_id
             WHERE tsa.employee_id = ?
             GROUP BY c.id, c.class_name
             ORDER BY c.rank ASC, c.class_name ASC"
        );
        $sum_stmt->execute([$employee_pk]);
        $assigned_summary = $sum_stmt->fetchAll();
    }
}

// Routine থেকে অটো-পাওয়া অ্যাক্সেস (রিড-অনলি — এখান থেকে বদলানো যাবে না, Routine পেজ থেকে বদলাতে হবে)
$routine_summary = [];
if ($selected_teacher) {
    $rt_stmt = $pdo->prepare(
        "SELECT c.id AS class_id, c.class_name, GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name SEPARATOR ', ') AS subject_names
         FROM routines r
         JOIN academic_classes c ON c.id = r.class_id
         JOIN academic_subjects sub ON sub.id = r.subject_id
         WHERE r.teacher_id = ? AND r.status = 'Active' AND r.class_id IS NOT NULL
         GROUP BY c.id, c.class_name
         ORDER BY c.rank ASC, c.class_name ASC"
    );
    $rt_stmt->execute([$employee_pk]);
    $routine_summary = $rt_stmt->fetchAll();
}

$classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank ASC, class_name ASC")->fetchAll();

$sel_class = (int) ($_GET['class_id'] ?? 0);
$class_subjects = [];
$assigned_subject_ids = [];
$routine_subject_ids = [];
if ($selected_teacher && $sel_class > 0) {
    $subj_stmt = $pdo->prepare("SELECT id, subject_name FROM academic_subjects WHERE class_id = ? AND status = 'Active' ORDER BY subject_name ASC");
    $subj_stmt->execute([$sel_class]);
    $class_subjects = $subj_stmt->fetchAll();

    $as_stmt = $pdo->prepare("SELECT subject_id FROM teacher_subject_assignments WHERE employee_id = ? AND class_id = ?");
    $as_stmt->execute([$employee_pk, $sel_class]);
    $assigned_subject_ids = array_map('intval', array_column($as_stmt->fetchAll(), 'subject_id'));

    $rs_stmt = $pdo->prepare("SELECT DISTINCT subject_id FROM routines WHERE teacher_id = ? AND class_id = ? AND status = 'Active'");
    $rs_stmt->execute([$employee_pk, $sel_class]);
    $routine_subject_ids = array_map('intval', array_column($rs_stmt->fetchAll(), 'subject_id'));
}
?>

<div class="card-custom mb-3">
    <?php if ($ast_msg): ?><div class="alert alert-success py-2"><?php echo lv($ast_msg); ?></div><?php endif; ?>
    <?php if ($ast_err): ?><div class="alert alert-danger py-2"><?php echo lv($ast_err); ?></div><?php endif; ?>

    <h5 class="fw-normal text-dark mb-3"><i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Teacher খুঁজুন</h5>
    <form method="GET" action="index.php" class="row g-2">
        <input type="hidden" name="page" value="result_assign_subject_teacher">
        <div class="col-md-9"><input type="text" name="q" class="form-control" placeholder="Employee ID / Name / Mobile" value="<?php echo lv($q); ?>"></div>
        <div class="col-md-3"><button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-search"></i> Search</button></div>
    </form>

    <?php if ($search_rows): ?>
        <div class="table-responsive mt-3">
            <table class="table table-bordered align-middle table-sm">
                <thead class="table-light"><tr><th>Employee ID</th><th>Name</th><th>Designation</th><th>Assigned Classes</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($search_rows as $e): ?>
                        <tr class="<?php echo $e['id'] == $employee_pk ? 'table-success' : ''; ?>">
                            <td><?php echo lv($e['employee_id']); ?></td>
                            <td><?php echo lv($e['name']); ?></td>
                            <td><?php echo lv($e['designation_title']); ?></td>
                            <td><?php echo (int) $e['assigned_classes'] > 0 ? '<span class="badge bg-success">' . (int) $e['assigned_classes'] . ' Class</span>' : '<span class="badge bg-secondary">Not Assigned</span>'; ?></td>
                            <td><a href="index.php?page=result_assign_subject_teacher&employee_pk=<?php echo (int) $e['id']; ?>" class="btn btn-sm btn-success">Select <i class="fa-solid fa-arrow-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($q !== ''): ?>
        <p class="text-muted mt-3 mb-0">কোনো টিচার পাওয়া যায়নি।</p>
    <?php endif; ?>
</div>

<?php if ($selected_teacher): ?>
<div class="row">
    <div class="col-md-4">
        <div class="card-custom mb-3">
            <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-id-badge me-1"></i> Selected Teacher</h6>
            <table class="table table-borderless table-sm mb-0">
                <tr><td class="text-muted" style="width:40%;">Employee ID</td><td class="fw-bold"><?php echo lv($selected_teacher['employee_id']); ?></td></tr>
                <tr><td class="text-muted">Name</td><td class="fw-bold"><?php echo lv($selected_teacher['name']); ?></td></tr>
                <tr><td class="text-muted">Designation</td><td><?php echo lv($selected_teacher['designation_title']); ?></td></tr>
                <tr><td class="text-muted">Mobile</td><td><?php echo lv($selected_teacher['mobile']); ?></td></tr>
            </table>
        </div>

        <div class="card-custom mb-3">
            <h6 class="fw-bold text-info mb-3"><i class="fa-solid fa-calendar-days me-1"></i> Routine থেকে অটো-অ্যাক্সেস</h6>
            <?php if (!$routine_summary): ?>
                <p class="text-muted small mb-0">Class Routine-এ এখনো এই টিচারকে কোনো Class/Subject-এ বসানো হয়নি।</p>
            <?php else: ?>
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light"><tr><th>Class</th><th>Subjects</th></tr></thead>
                    <tbody>
                        <?php foreach ($routine_summary as $row): ?>
                            <tr>
                                <td><?php echo lv($row['class_name']); ?></td>
                                <td class="small"><?php echo lv($row['subject_names']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="text-muted small mt-2 mb-0">এগুলো এখান থেকে রিমুভ করা যাবে না — বদলাতে হলে Class Routine পেজে গিয়ে ঐ পিরিয়ডটা Inactive/Delete করতে হবে।</p>
            <?php endif; ?>
        </div>

        <div class="card-custom">
            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-list-check me-1"></i> ম্যানুয়াল এসাইনমেন্ট (Extra)</h6>
            <?php if (!$assigned_summary): ?>
                <p class="text-muted small mb-0">এখনো কোনো Class/Subject ম্যানুয়ালি এসাইন করা হয়নি।</p>
            <?php else: ?>
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light"><tr><th>Class</th><th>Subjects</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($assigned_summary as $row): ?>
                            <tr class="<?php echo $sel_class === (int) $row['class_id'] ? 'table-success' : ''; ?>">
                                <td><a href="index.php?page=result_assign_subject_teacher&employee_pk=<?php echo $employee_pk; ?>&class_id=<?php echo (int) $row['class_id']; ?>"><?php echo lv($row['class_name']); ?></a></td>
                                <td class="small"><?php echo lv($row['subject_names']); ?></td>
                                <td>
                                    <form method="POST" action="index.php?page=result_assign_subject_teacher" onsubmit="return confirm('এই Class-এর সব ম্যানুয়াল এসাইনমেন্ট রিমুভ করবেন?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="employee_pk" value="<?php echo $employee_pk; ?>">
                                        <input type="hidden" name="class_id" value="<?php echo (int) $row['class_id']; ?>">
                                        <button type="submit" name="remove_class" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card-custom">
            <h6 class="fw-bold text-success mb-3"><i class="fa-solid fa-chalkboard-user me-1"></i> Assign Subjects (Extra / Routine-এ নেই এমন সাবজেক্টের জন্য)</h6>

            <form method="GET" action="index.php" class="row g-2 mb-3">
                <input type="hidden" name="page" value="result_assign_subject_teacher">
                <input type="hidden" name="employee_pk" value="<?php echo $employee_pk; ?>">
                <div class="col-md-6">
                    <select name="class_id" id="ast_class" class="form-select">
                        <option value="0">-- Class নির্বাচন করুন --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo $sel_class === (int) $c['id'] ? 'selected' : ''; ?>><?php echo lv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div id="ast_result_wrap">
            <?php if ($sel_class <= 0): ?>
                <div class="text-center text-muted py-4">উপরে একটা Class সিলেক্ট করুন — তাহলে সেই Class-এর সাবজেক্ট লিস্ট এখানে দেখাবে।</div>
            <?php elseif (!$class_subjects): ?>
                <div class="text-center text-muted py-4">এই Class-এর জন্য এখনো কোনো Subject তৈরি করা হয়নি। আগে <a href="index.php?page=academic_subject">Subjects</a> পেজ থেকে সাবজেক্ট যোগ করুন।</div>
            <?php else: ?>
                <form method="POST" action="index.php?page=result_assign_subject_teacher">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="employee_pk" value="<?php echo $employee_pk; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">

                    <div class="mb-2">
                        <a href="#" class="small me-3" onclick="document.querySelectorAll('.subjChk').forEach(c=>c.checked=true); return false;">সব সিলেক্ট</a>
                        <a href="#" class="small" onclick="document.querySelectorAll('.subjChk').forEach(c=>c.checked=false); return false;">সব বাদ দিন</a>
                    </div>

                    <div class="row">
                        <?php foreach ($class_subjects as $sub): ?>
                            <?php $via_routine = in_array((int) $sub['id'], $routine_subject_ids, true); ?>
                            <div class="col-md-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input subjChk" type="checkbox" name="subject_ids[]" value="<?php echo (int) $sub['id']; ?>" id="subj<?php echo (int) $sub['id']; ?>"
                                        <?php echo in_array((int) $sub['id'], $assigned_subject_ids, true) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="subj<?php echo (int) $sub['id']; ?>"><?php echo lv($sub['subject_name']); ?></label>
                                    <?php if ($via_routine): ?><span class="badge bg-info-subtle text-info-emphasis ms-1">Routine-এ আছে</span><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted small mt-2">"Routine-এ আছে" ট্যাগওয়ালা সাবজেক্টে টিচারের অ্যাক্সেস এমনিতেই আছে — চেক না করলেও চলবে।</p>

                    <button type="submit" name="save_assign" class="btn btn-success px-4 fw-bold mt-3"><i class="fa-solid fa-check"></i> Save Assignment</button>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var elClass = document.getElementById('ast_class');
    var elWrap  = document.getElementById('ast_result_wrap');
    if (!elClass || !elWrap) return; // Teacher select না করা থাকলে এই ফর্মটাই থাকে না

    var employeePk = elClass.form.querySelector('input[name="employee_pk"]').value;

    function astAjaxUrl(action, extra) {
        var p = new URLSearchParams(extra || {});
        return 'ajax/result_assign_subject_teacher_ajax.php?action=' + action + '&' + p.toString();
    }

    // Class বদলালে Subject checkbox লিস্ট AJAX দিয়ে রিলোড হবে (পেজ রিলোড ছাড়াই)
    function loadTable() {
        var classId = elClass.value;
        if (classId == '0') {
            elWrap.innerHTML = '<div class="text-center text-muted py-4">উপরে একটা Class সিলেক্ট করুন — তাহলে সেই Class-এর সাবজেক্ট লিস্ট এখানে দেখাবে।</div>';
            return;
        }

        elWrap.innerHTML = '<div class="text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-2"></i>লোড হচ্ছে...</div>';

        fetch(astAjaxUrl('table', { employee_pk: employeePk, class_id: classId }))
            .then(function (r) { return r.text(); })
            .then(function (html) { elWrap.innerHTML = html; })
            .catch(function () {
                elWrap.innerHTML = '<div class="text-center text-danger py-4">ডাটা লোড করতে সমস্যা হয়েছে, আবার চেষ্টা করুন।</div>';
            });
    }

    elClass.addEventListener('change', loadTable);
})();
</script>
