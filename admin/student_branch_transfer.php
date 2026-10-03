<?php
// admin/student_branch_transfer.php - Student Module > Student Branch Transfer
//
// একজন স্টুডেন্টকে এক Branch থেকে আরেক Branch-এ সরানোর জন্য (যেমন কোনো ভুল
// Branch-এ ভর্তি হয়ে গিয়ে থাকলে, বা সত্যিকারের এক শাখা থেকে আরেক শাখায়
// ট্রান্সফার হলে)। শুধু Super Admin-ই এই কাজ করতে পারবে — সাধারণ (branch-locked)
// ইউজারের নিজের branch-এর বাইরে কোনো অ্যাক্সেসই নেই, তাই তাকে এই ফিচার দেখানো
// বা ব্যবহার করতে দেওয়া নিরাপদ না।
$is_super_admin = $_SESSION['is_super_admin'] ?? true;

if (!$is_super_admin) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock"></i> এই ফিচার শুধু Super Admin-ই ব্যবহার করতে পারবে।</div></div>';
    return;
}

// student_branch_transfer_logs টেবিল — কবে, কে, কোন স্টুডেন্টকে কোন branch
// থেকে কোন branch-এ সরিয়েছে তার হিস্ট্রি রাখার জন্য (self-healing migration)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_branch_transfer_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        from_branch_id INT NULL,
        to_branch_id INT NOT NULL,
        transferred_by VARCHAR(100) NULL,
        transferred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$sbt_msg = $_SESSION['sbt_msg'] ?? '';
$sbt_err = $_SESSION['sbt_err'] ?? '';
unset($_SESSION['sbt_msg'], $_SESSION['sbt_err']);

$branches = branch_list_active($pdo);

function sbtv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// Transfer সম্পন্ন করা (POST)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_transfer'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['sbt_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=student_branch_transfer');
        exit();
    }

    $student_id = (int) ($_POST['student_id'] ?? 0);
    $to_branch_id = (int) ($_POST['to_branch_id'] ?? 0);
    $valid_branch_ids = array_column($branches, 'id');

    $stu_stmt = $pdo->prepare("SELECT id, name_en, student_id, branch_id FROM students WHERE id = ?");
    $stu_stmt->execute([$student_id]);
    $student_row = $stu_stmt->fetch();

    if (!$student_row) {
        $_SESSION['sbt_err'] = 'স্টুডেন্ট খুঁজে পাওয়া যায়নি।';
    } elseif ($to_branch_id <= 0 || !in_array($to_branch_id, $valid_branch_ids, true)) {
        $_SESSION['sbt_err'] = 'সঠিক Branch সিলেক্ট করুন।';
    } elseif ((int) $student_row['branch_id'] === $to_branch_id) {
        $_SESSION['sbt_err'] = 'স্টুডেন্ট আগে থেকেই এই Branch-এ আছে।';
    } else {
        $from_branch_id = $student_row['branch_id'] !== null ? (int) $student_row['branch_id'] : null;

        $pdo->prepare("UPDATE students SET branch_id = ? WHERE id = ?")->execute([$to_branch_id, $student_id]);

        $log_stmt = $pdo->prepare(
            "INSERT INTO student_branch_transfer_logs (student_id, from_branch_id, to_branch_id, transferred_by) VALUES (?, ?, ?, ?)"
        );
        $log_stmt->execute([$student_id, $from_branch_id, $to_branch_id, $_SESSION['admin_user'] ?? null]);

        $to_branch_name = '';
        foreach ($branches as $b) { if ((int) $b['id'] === $to_branch_id) { $to_branch_name = $b['branch_name']; break; } }
        $_SESSION['sbt_msg'] = htmlspecialchars($student_row['name_en']) . ' (' . htmlspecialchars($student_row['student_id']) . ') কে সফলভাবে "' . htmlspecialchars($to_branch_name) . '" Branch-এ ট্রান্সফার করা হয়েছে।';
        sec_log_activity($pdo, 'Student', 'Update', "{$student_row['name_en']} ({$student_row['student_id']}) কে \"{$to_branch_name}\" Branch-এ ট্রান্সফার করা হয়েছে।");
    }

    header('Location: index.php?page=student_branch_transfer');
    exit();
}

// -----------------------------------------------------------
// স্টুডেন্ট সার্চ — ইচ্ছাকৃতভাবে branch দিয়ে ফিল্টার করা হচ্ছে না, কারণ Super
// Admin-কে হয়তো ভুল/অন্য branch-এ থাকা স্টুডেন্টকেও খুঁজে বের করে ঠিক করতে হতে পারে
// -----------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$search_rows = [];
if ($q !== '') {
    $stmt = $pdo->prepare(
        "SELECT students.id, students.student_id, students.name_en, students.branch_id, students.status,
                academic_classes.class_name, academic_sections.section_name, branches.branch_name
         FROM students
         LEFT JOIN academic_classes ON academic_classes.id = students.class_id
         LEFT JOIN academic_sections ON academic_sections.id = students.section_id
         LEFT JOIN branches ON branches.id = students.branch_id
         WHERE students.is_current = 1 AND (students.name_en LIKE ? OR students.student_id LIKE ?)
         ORDER BY students.id DESC LIMIT 30"
    );
    $like = "%$q%";
    $stmt->execute([$like, $like]);
    $search_rows = $stmt->fetchAll();
}

// Transfer ফর্ম (নির্দিষ্ট একজন স্টুডেন্টের জন্য) দেখানো হবে কিনা
$transfer_id = (int) ($_GET['transfer_id'] ?? 0);
$transfer_student = null;
if ($transfer_id > 0) {
    $ts_stmt = $pdo->prepare(
        "SELECT students.id, students.student_id, students.name_en, students.branch_id,
                academic_classes.class_name, academic_sections.section_name, branches.branch_name
         FROM students
         LEFT JOIN academic_classes ON academic_classes.id = students.class_id
         LEFT JOIN academic_sections ON academic_sections.id = students.section_id
         LEFT JOIN branches ON branches.id = students.branch_id
         WHERE students.id = ?"
    );
    $ts_stmt->execute([$transfer_id]);
    $transfer_student = $ts_stmt->fetch();
}
?>

<div class="card-custom mb-3">
    <h5 class="fw-normal text-dark mb-4"><i class="fa-solid fa-right-left"></i> Student Branch Transfer</h5>

    <?php if ($sbt_msg): ?><div class="alert alert-success py-2"><?php echo $sbt_msg; /* আগেই htmlspecialchars করা */ ?></div><?php endif; ?>
    <?php if ($sbt_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($sbt_err); ?></div><?php endif; ?>

    <?php if (!$branches || count($branches) < 2): ?>
        <p class="text-muted mb-0">ট্রান্সফার করার জন্য অন্তত ২টা Active Branch থাকা দরকার। Global Settings &gt; Branch Settings থেকে আরও Branch যোগ করুন।</p>
    <?php else: ?>

    <form method="GET" action="index.php" class="row g-2 align-items-center mb-2">
        <input type="hidden" name="page" value="student_branch_transfer">
        <div class="col-sm-6">
            <input type="text" name="q" class="form-control" placeholder="Student ID বা Name দিয়ে খুঁজুন" value="<?php echo sbtv($q); ?>">
        </div>
        <div class="col-sm-3">
            <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
    </form>
    <small class="text-muted">এই সার্চ ইচ্ছাকৃতভাবে সব Branch মিলিয়েই খোঁজে (কোনো নির্দিষ্ট Branch-এ আটকে রাখে না), যাতে ভুল Branch-এ থাকা স্টুডেন্টকেও খুঁজে বের করা যায়।</small>

    <?php if ($q !== ''): ?>
        <hr>
        <?php if (!$search_rows): ?>
            <p class="text-muted mb-0">কোনো স্টুডেন্ট পাওয়া যায়নি।</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr><th>Student ID</th><th>Name</th><th>Class</th><th>Section</th><th>বর্তমান Branch</th><th>Status</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_rows as $row): ?>
                            <tr class="<?php echo (int) $row['id'] === $transfer_id ? 'table-warning' : ''; ?>">
                                <td><?php echo sbtv($row['student_id']); ?></td>
                                <td><?php echo sbtv($row['name_en']); ?></td>
                                <td><?php echo sbtv($row['class_name'] ?? '-'); ?></td>
                                <td><?php echo sbtv($row['section_name'] ?? '-'); ?></td>
                                <td><?php echo $row['branch_name'] ? sbtv($row['branch_name']) : '<span class="text-danger fst-italic">কোনো Branch সেট নেই</span>'; ?></td>
                                <td><?php echo sbtv($row['status']); ?></td>
                                <td>
                                    <a href="index.php?page=student_branch_transfer&q=<?php echo urlencode($q); ?>&transfer_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-right-left"></i> Transfer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($transfer_student): ?>
        <hr>
        <div class="card p-3 bg-light">
            <h6 class="fw-bold mb-3">ট্রান্সফার নিশ্চিত করুন</h6>
            <p class="mb-1"><b>Student:</b> <?php echo sbtv($transfer_student['name_en']); ?> (<?php echo sbtv($transfer_student['student_id']); ?>)</p>
            <p class="mb-3"><b>বর্তমান Branch:</b> <?php echo $transfer_student['branch_name'] ? sbtv($transfer_student['branch_name']) : '<span class="text-danger fst-italic">কোনো Branch সেট নেই</span>'; ?></p>

            <form method="POST" action="index.php?page=student_branch_transfer" class="row g-2 align-items-center">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="student_id" value="<?php echo (int) $transfer_student['id']; ?>">
                <div class="col-sm-5">
                    <label class="form-label fw-bold small mb-1">নতুন Branch</label>
                    <select name="to_branch_id" class="form-select" required>
                        <option value="">Select Branch</option>
                        <?php foreach ($branches as $b): ?>
                            <?php if ((int) $b['id'] === (int) $transfer_student['branch_id']) continue; // বর্তমান branch অপশনে দেখানোর দরকার নেই ?>
                            <option value="<?php echo (int) $b['id']; ?>"><?php echo sbtv($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 pt-4">
                    <button type="submit" name="confirm_transfer" class="btn btn-success fw-bold" onclick="return confirm('আপনি কি নিশ্চিত এই স্টুডেন্টকে নতুন Branch-এ ট্রান্সফার করতে চান?');">
                        <i class="fa-solid fa-check"></i> Confirm Transfer
                    </button>
                    <a href="index.php?page=student_branch_transfer&q=<?php echo urlencode($q); ?>" class="btn btn-outline-secondary">বাতিল</a>
                </div>
            </form>
            <small class="text-muted d-block mt-2">ট্রান্সফার করলে এই স্টুডেন্টের সব ফি, লেজার, অ্যাটেন্ডেন্স রিপোর্ট নতুন Branch-এর হিসেবেই দেখাবে (এগুলো সবই স্টুডেন্টের branch অনুসরণ করে) — Roll No/Section আগেরটাই থাকবে, প্রয়োজনে Student Admission &gt; Edit থেকে সেটা আলাদাভাবে বদলে নিতে হবে।</small>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
