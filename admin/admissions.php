<?php
// Action Handler for Admissions — এখন POST + CSRF token বাধ্যতামূলক
$admissions_msg = '';
$admissions_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admission_action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $admissions_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $action = $_POST['admission_action'];
        $id = (int) ($_POST['id'] ?? 0);

        // "Admitted" এখানে সেট হয় না — Enroll করলে (student_admission.php থেকে Save করলে)
        // status স্বয়ংক্রিয়ভাবে Admitted হয়ে যায়, তাই এখানে শুধু reject/delete থাকবে
        if ($id > 0 && in_array($action, ['reject', 'delete'], true)) {
            $name_stmt = $pdo->prepare("SELECT student_name_bn FROM admissions WHERE id = ?");
            $name_stmt->execute([$id]);
            $admission_name = $name_stmt->fetchColumn();

            if ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE admissions SET status = 'Rejected' WHERE id = ?");
                $stmt->execute([$id]);
                sec_log_activity($pdo, 'Admission', 'Update', "আবেদন Reject করা হয়েছে: " . ($admission_name ?: "#$id") . " (#$id)");
                $admissions_msg = "আবেদন #$id Reject করা হয়েছে।";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM admissions WHERE id = ?");
                $stmt->execute([$id]);
                sec_log_activity($pdo, 'Admission', 'Delete', "আবেদন মুছে ফেলা হয়েছে: " . ($admission_name ?: "#$id") . " (#$id)");
                $admissions_msg = "আবেদন #$id মুছে ফেলা হয়েছে।";
            }
        }
    }
    // Post/Redirect/Get প্যাটার্ন — refresh করলে আবার action রিপিট হবে না
    header("Location: index.php?page=admissions" . (($_POST['status_filter'] ?? '') !== '' ? '&status=' . urlencode($_POST['status_filter']) : ''));
    exit();
}

// কোন কোন admission_id ইতিমধ্যে students টেবিলে enroll হয়ে গেছে সেটা জানা দরকার,
// যাতে একই আবেদন থেকে দুইবার student তৈরি না হয় (ডুপ্লিকেট প্রতিরোধ)
$enrolled_map = [];
$enrolled_rows = $pdo->query("SELECT id, admission_id FROM students WHERE admission_id IS NOT NULL")->fetchAll();
foreach ($enrolled_rows as $er) {
    $enrolled_map[(int) $er['admission_id']] = (int) $er['id'];
}

// Admission Workflow: Pending -> Exam Scheduled -> Result Published -> Merit List / Waiting List -> Admitted (বা Rejected)
$all_statuses = ['Pending', 'Exam Scheduled', 'Result Published', 'Merit List', 'Waiting List', 'Admitted', 'Rejected'];
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, $all_statuses, true)) {
    $status_filter = '';
}

if ($status_filter !== '') {
    $stmt = $pdo->prepare("SELECT * FROM admissions WHERE status = ? ORDER BY id DESC");
    $stmt->execute([$status_filter]);
} else {
    $stmt = $pdo->query("SELECT * FROM admissions ORDER BY id DESC");
}
$rows = $stmt->fetchAll();

$badge_colors = [
    'Pending'          => 'bg-warning text-dark',
    'Exam Scheduled'   => 'bg-info text-dark',
    'Result Published' => 'bg-primary',
    'Merit List'        => 'bg-success',
    'Waiting List'      => 'bg-secondary',
    'Admitted'          => 'bg-success',
    'Rejected'          => 'bg-danger',
];
?>

<div class="card-custom mb-3">
    <div class="d-flex flex-wrap gap-2">
        <a href="index.php?page=admission_exam" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-calendar-check"></i> Schedule Exam</a>
        <a href="index.php?page=admission_result" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen-to-square"></i> Exam Result Entry</a>
        <a href="index.php?page=admission_merit" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-ranking-star"></i> Merit & Waiting List</a>
    </div>
</div>

<div class="card-custom">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users-rectangle"></i> All Student Admission Requests</h4>
        <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
            <input type="hidden" name="page" value="admissions">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">-- সব স্ট্যাটাস --</option>
                <?php foreach ($all_statuses as $st): ?>
                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $status_filter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($admissions_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($admissions_msg); ?></div>
    <?php endif; ?>
    <?php if ($admissions_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($admissions_err); ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-dark">
                <tr><th>ID</th><th>Student Name</th><th>Class & Group</th><th>Exam Roll</th><th>Marks</th><th>Mobile</th><th>Status</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
                <?php
                if ($rows) {
                    foreach ($rows as $row) {
                        $status = $row['status'];
                        $badge_class = $badge_colors[$status] ?? 'bg-secondary';
                        $status_badge = '<span class="badge ' . $badge_class . '">' . htmlspecialchars($status) . '</span>';

                        $id = (int) $row['id'];
                        $csrf = htmlspecialchars(csrf_token());
                        $already_enrolled = $enrolled_map[$id] ?? null;

                        if ($already_enrolled) {
                            $enroll_btn = "<a href='index.php?page=student_admission&view=edit&id={$already_enrolled}' class='btn btn-outline-secondary btn-sm' title='View'><i class=\"fa-solid fa-eye\"></i></a>
                               <a href='index.php?page=student_admission&view=edit&id={$already_enrolled}' class='btn btn-info btn-sm text-white' title='Edit'><i class=\"fa-solid fa-pen\"></i> Edit</a>";
                        } elseif (in_array($status, ['Merit List', 'Waiting List'], true)) {
                            // মেরিট বা ওয়েটিং লিস্টে সিলেক্ট হলেই কেবল সরাসরি Enroll করা যাবে
                            $enroll_btn = "<a href='index.php?page=student_admission&view=add&from_admission={$id}' class='btn btn-success btn-sm'>Enroll</a>";
                        } else {
                            $enroll_btn = '';
                        }

                        $roll_cell = $row['exam_roll'] ? htmlspecialchars($row['exam_roll']) : '<span class="text-muted">-</span>';
                        $marks_cell = $row['exam_marks'] !== null ? htmlspecialchars($row['exam_marks']) . ($row['merit_rank'] ? ' <small class="text-muted">(Rank ' . (int) $row['merit_rank'] . ')</small>' : '') : '<span class="text-muted">-</span>';

                        echo "<tr>
                            <td>#{$id}</td>
                            <td><strong>" . htmlspecialchars($row['student_name_bn']) . "</strong><br><small class='text-muted'>" . htmlspecialchars($row['student_name_en']) . "</small></td>
                            <td>" . htmlspecialchars($row['apply_class']) . "<br><small class='text-muted'>" . htmlspecialchars($row['apply_group']) . "</small></td>
                            <td>{$roll_cell}</td>
                            <td>{$marks_cell}</td>
                            <td>" . htmlspecialchars($row['guardian_phone']) . "</td>
                            <td>{$status_badge}</td>
                            <td class='text-end'>
                                {$enroll_btn}
                                <form method='POST' action='index.php?page=admissions' class='d-inline' onsubmit=\"return confirm('আপনি কি নিশ্চিত?');\">
                                    <input type='hidden' name='csrf_token' value='{$csrf}'>
                                    <input type='hidden' name='id' value='{$id}'>
                                    <button type='submit' name='admission_action' value='reject' class='btn btn-warning btn-sm text-dark'>Reject</button>
                                    <button type='submit' name='admission_action' value='delete' class='btn btn-danger btn-sm'>Delete</button>
                                </form>
                            </td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='text-center text-muted py-4'>No data found</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
