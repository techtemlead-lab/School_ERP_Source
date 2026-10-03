<?php
// admin/admission_exam.php - Admission Workflow: Step 2 - Schedule Exam
// Pending আবেদনকারীদের ক্লাস অনুযায়ী বেছে exam roll/date/venue বসিয়ে
// bulk-এ 'Exam Scheduled' স্ট্যাটাসে নেওয়া হয়।
$ae_msg = $_SESSION['ae_msg'] ?? '';
$ae_err = $_SESSION['ae_err'] ?? '';
unset($_SESSION['ae_msg'], $_SESSION['ae_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ae_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $ids        = array_map('intval', $_POST['ids'] ?? []);
        $exam_date  = trim($_POST['exam_date'] ?? '');
        $exam_time  = trim($_POST['exam_time'] ?? '');
        $exam_venue = trim($_POST['exam_venue'] ?? '');
        $roll_prefix = trim($_POST['roll_prefix'] ?? 'ADM');

        if (!$ids) {
            $_SESSION['ae_err'] = 'অন্তত একজন আবেদনকারী নির্বাচন করুন।';
        } elseif ($exam_date === '' || !DateTime::createFromFormat('Y-m-d', $exam_date)) {
            $_SESSION['ae_err'] = 'সঠিক পরীক্ষার তারিখ দিন।';
        } elseif ($exam_venue === '') {
            $_SESSION['ae_err'] = 'পরীক্ষার ভেন্যু আবশ্যক।';
        } else {
            // পরের ক্রমিক roll নম্বর বের করা হচ্ছে (এই prefix দিয়ে যতগুলো roll আগে দেওয়া হয়েছে তার পরেরটা থেকে)
            $cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE exam_roll LIKE ?");
            $cnt_stmt->execute([$roll_prefix . '-%']);
            $next_seq = (int) $cnt_stmt->fetchColumn() + 1;

            $upd = $pdo->prepare(
                "UPDATE admissions SET exam_roll = ?, exam_date = ?, exam_time = ?, exam_venue = ?, status = 'Exam Scheduled'
                 WHERE id = ? AND status = 'Pending'"
            );
            $done = 0;
            foreach ($ids as $id) {
                $roll = $roll_prefix . '-' . str_pad((string) $next_seq, 4, '0', STR_PAD_LEFT);
                $upd->execute([$roll, $exam_date, $exam_time ?: null, $exam_venue, $id]);
                if ($upd->rowCount() > 0) {
                    $next_seq++;
                    $done++;
                }
            }
            if ($done > 0) {
                sec_log_activity($pdo, 'Admission', 'Update', "{$done} জন আবেদনকারীর Exam Schedule করা হয়েছে (Venue: {$exam_venue}, Date: {$exam_date})");
            }
            $_SESSION['ae_msg'] = "{$done} জন আবেদনকারীর পরীক্ষা সিডিউল করা হয়েছে।";
        }
    }
    header('Location: index.php?page=admission_exam' . (isset($_POST['apply_class']) ? '&apply_class=' . urlencode($_POST['apply_class']) : ''));
    exit();
}

// distinct class group গুলো dropdown-এ দেখানোর জন্য
$class_options = $pdo->query("SELECT DISTINCT apply_class FROM admissions ORDER BY apply_class ASC")->fetchAll(PDO::FETCH_COLUMN);
$class_filter = $_GET['apply_class'] ?? '';

$sql = "SELECT * FROM admissions WHERE status = 'Pending'";
$params = [];
if ($class_filter !== '') {
    $sql .= " AND apply_class = ?";
    $params[] = $class_filter;
}
$sql .= " ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pending_rows = $stmt->fetchAll();

// ইতিমধ্যে যাদের exam schedule করা হয়েছে (নজরে রাখার জন্য)
$sched_sql = "SELECT * FROM admissions WHERE status != 'Pending' AND exam_roll IS NOT NULL";
$sched_params = [];
if ($class_filter !== '') {
    $sched_sql .= " AND apply_class = ?";
    $sched_params[] = $class_filter;
}
$sched_sql .= " ORDER BY exam_roll ASC";
$sched_stmt = $pdo->prepare($sched_sql);
$sched_stmt->execute($sched_params);
$scheduled_rows = $sched_stmt->fetchAll();

$csrf = htmlspecialchars(csrf_token());
?>

<div class="card-custom mb-3">
    <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-calendar-check"></i> Admission — Schedule Exam</h4>

    <?php if ($ae_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($ae_msg); ?></div><?php endif; ?>
    <?php if ($ae_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($ae_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="page" value="admission_exam">
        <div class="col-auto">
            <label class="form-label mb-1">শ্রেণি ফিল্টার</label>
            <select name="apply_class" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- সব শ্রেণি --</option>
                <?php foreach ($class_options as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $class_filter === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($pending_rows): ?>
    <form method="POST" action="index.php?page=admission_exam">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <div class="row g-2 mb-3">
            <div class="col-md-3">
                <label class="form-label mb-1">পরীক্ষার তারিখ *</label>
                <input type="date" name="exam_date" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">সময়</label>
                <input type="text" name="exam_time" class="form-control form-control-sm" placeholder="যেমন: 10:00 AM">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">ভেন্যু *</label>
                <input type="text" name="exam_venue" class="form-control form-control-sm" required placeholder="যেমন: Main Campus, Room-101">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Roll Prefix</label>
                <input type="text" name="roll_prefix" class="form-control form-control-sm" value="ADM">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th><input type="checkbox" onclick="document.querySelectorAll('.ae-chk').forEach(c=>c.checked=this.checked)"></th>
                        <th>ID</th><th>Student Name</th><th>Class & Group</th><th>Mobile</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_rows as $row): $id = (int) $row['id']; ?>
                        <tr>
                            <td><input type="checkbox" class="ae-chk" name="ids[]" value="<?php echo $id; ?>"></td>
                            <td>#<?php echo $id; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['student_name_bn']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($row['student_name_en']); ?></small></td>
                            <td><?php echo htmlspecialchars($row['apply_class']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['apply_group']); ?></small></td>
                            <td><?php echo htmlspecialchars($row['guardian_phone']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-calendar-check"></i> নির্বাচিতদের Exam Schedule করুন</button>
    </form>
    <?php else: ?>
        <p class="text-muted">কোনো Pending আবেদন নেই।</p>
    <?php endif; ?>
</div>

<div class="card-custom">
    <h5 class="fw-bold mb-3 text-dark">যাদের Exam ইতিমধ্যে Schedule হয়েছে</h5>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr><th>Roll</th><th>Student Name</th><th>Class</th><th>Date</th><th>Time</th><th>Venue</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if ($scheduled_rows): foreach ($scheduled_rows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['exam_roll']); ?></td>
                        <td><?php echo htmlspecialchars($row['student_name_bn']); ?></td>
                        <td><?php echo htmlspecialchars($row['apply_class']); ?></td>
                        <td><?php echo htmlspecialchars($row['exam_date']); ?></td>
                        <td><?php echo htmlspecialchars($row['exam_time']); ?></td>
                        <td><?php echo htmlspecialchars($row['exam_venue']); ?></td>
                        <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
