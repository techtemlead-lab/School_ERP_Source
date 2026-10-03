<?php
// admin/admission_result.php - Admission Workflow: Step 3 - Exam Result Entry
// 'Exam Scheduled' আবেদনকারীদের নম্বর বসিয়ে 'Result Published' স্ট্যাটাসে নেওয়া হয়।
$ar_msg = $_SESSION['ar_msg'] ?? '';
$ar_err = $_SESSION['ar_err'] ?? '';
unset($_SESSION['ar_msg'], $_SESSION['ar_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ar_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $marks = $_POST['marks'] ?? []; // [id => marks]
        $upd = $pdo->prepare(
            "UPDATE admissions SET exam_marks = ?, status = 'Result Published'
             WHERE id = ? AND status IN ('Exam Scheduled', 'Result Published')"
        );
        $done = 0;
        foreach ($marks as $id => $val) {
            $val = trim((string) $val);
            if ($val === '' || !is_numeric($val)) continue;
            $upd->execute([(float) $val, (int) $id]);
            $done++;
        }
        if ($done > 0) {
            sec_log_activity($pdo, 'Admission', 'Update', "{$done} জন আবেদনকারীর Exam Result সেভ করা হয়েছে (Result Published)");
        }
        $_SESSION['ar_msg'] = "{$done} জন আবেদনকারীর নম্বর সেভ করা হয়েছে।";
    }
    header('Location: index.php?page=admission_result' . (isset($_POST['apply_class']) ? '&apply_class=' . urlencode($_POST['apply_class']) : ''));
    exit();
}

$class_options = $pdo->query("SELECT DISTINCT apply_class FROM admissions WHERE exam_roll IS NOT NULL ORDER BY apply_class ASC")->fetchAll(PDO::FETCH_COLUMN);
$class_filter = $_GET['apply_class'] ?? '';

$sql = "SELECT * FROM admissions WHERE status IN ('Exam Scheduled', 'Result Published')";
$params = [];
if ($class_filter !== '') {
    $sql .= " AND apply_class = ?";
    $params[] = $class_filter;
}
$sql .= " ORDER BY exam_roll ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$csrf = htmlspecialchars(csrf_token());
?>

<div class="card-custom">
    <h4 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-pen-to-square"></i> Admission — Exam Result Entry</h4>

    <?php if ($ar_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($ar_msg); ?></div><?php endif; ?>
    <?php if ($ar_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($ar_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="page" value="admission_result">
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

    <?php if ($rows): ?>
    <form method="POST" action="index.php?page=admission_result">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <?php if ($class_filter !== ''): ?><input type="hidden" name="apply_class" value="<?php echo htmlspecialchars($class_filter); ?>"><?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr><th>Roll</th><th>Student Name</th><th>Class & Group</th><th>Status</th><th style="width:150px">Marks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): $id = (int) $row['id']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['exam_roll']); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['student_name_bn']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($row['student_name_en']); ?></small></td>
                            <td><?php echo htmlspecialchars($row['apply_class']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['apply_group']); ?></small></td>
                            <td><span class="badge <?php echo $row['status'] === 'Result Published' ? 'bg-primary' : 'bg-info text-dark'; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                            <td><input type="number" step="0.01" name="marks[<?php echo $id; ?>]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($row['exam_marks'] ?? ''); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> নম্বর সেভ করুন</button>
    </form>
    <?php else: ?>
        <p class="text-muted">Exam Schedule হওয়া কোনো আবেদন নেই।</p>
    <?php endif; ?>
</div>
