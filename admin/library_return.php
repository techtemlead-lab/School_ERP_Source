<?php
// admin/library_return.php - Library Module > Return Book & Fine Collection
$lr_err = $_SESSION['lr_err'] ?? '';
$lr_msg = $_SESSION['lr_msg'] ?? '';
unset($_SESSION['lr_err'], $_SESSION['lr_msg']);

if (!$pdo->query("SELECT id FROM library_settings WHERE id=1")->fetch()) {
    $pdo->exec("INSERT INTO library_settings (id, fine_per_day, max_days_allowed, max_books_per_student) VALUES (1, 5.00, 14, 2)");
}
$settings = $pdo->query("SELECT * FROM library_settings WHERE id=1")->fetch();

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST: Return Book / Mark Fine Paid / Mark Lost
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lr_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=library_return');
        exit();
    }

    $issue_id = (int) ($_POST['issue_id'] ?? 0);

    if (isset($_POST['return_book'])) {
        $return_date = trim($_POST['return_date'] ?? date('Y-m-d'));

        $stmt = $pdo->prepare("SELECT li.*, lb.title, lb.book_code, s.name_en FROM library_issues li LEFT JOIN library_books lb ON lb.id = li.book_id LEFT JOIN students s ON s.id = li.student_id WHERE li.id = ? AND li.status = 'Issued'");
        $stmt->execute([$issue_id]);
        $issue = $stmt->fetch();

        if (!$issue) {
            $_SESSION['lr_err'] = 'রেকর্ড খুঁজে পাওয়া যায়নি অথবা ইতিমধ্যে ফেরত দেওয়া হয়েছে।';
        } else {
            $days_late = (int) floor((strtotime($return_date) - strtotime($issue['due_date'])) / 86400);
            $fine = $days_late > 0 ? round($days_late * (float) $settings['fine_per_day'], 2) : 0;

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE library_issues SET return_date=?, fine_amount=?, status='Returned' WHERE id=?")
                ->execute([$return_date, $fine, $issue_id]);
            $pdo->prepare("UPDATE library_books SET available_copies = available_copies + 1 WHERE id = ?")
                ->execute([$issue['book_id']]);
            $pdo->commit();
            sec_log_activity($pdo, 'Library', 'Update', "বই ফেরত নেওয়া হয়েছে: {$issue['title']} ({$issue['book_code']}) - {$issue['name_en']}" . ($fine > 0 ? ", জরিমানা: ৳" . number_format($fine, 2) : ''));

            $_SESSION['lr_msg'] = 'বই ফেরত নেওয়া হয়েছে।' . ($fine > 0 ? ' জরিমানা: ৳' . number_format($fine, 2) . ' (' . $days_late . ' দিন দেরি)' : '');
        }
    } elseif (isset($_POST['mark_fine_paid'])) {
        $fp_stmt = $pdo->prepare("SELECT li.fine_amount, lb.title, s.name_en FROM library_issues li LEFT JOIN library_books lb ON lb.id = li.book_id LEFT JOIN students s ON s.id = li.student_id WHERE li.id = ?");
        $fp_stmt->execute([$issue_id]);
        $fp_row = $fp_stmt->fetch();
        $pdo->prepare("UPDATE library_issues SET fine_paid = 1 WHERE id = ?")->execute([$issue_id]);
        if ($fp_row) {
            sec_log_activity($pdo, 'Library', 'Update', "জরিমানা আদায় হয়েছে: {$fp_row['title']} - {$fp_row['name_en']}, ৳" . number_format((float) $fp_row['fine_amount'], 2));
        }
        $_SESSION['lr_msg'] = 'জরিমানা আদায় হিসেবে চিহ্নিত করা হয়েছে।';
    } elseif (isset($_POST['mark_lost'])) {
        $stmt = $pdo->prepare("SELECT li.*, lb.title, lb.book_code, s.name_en FROM library_issues li LEFT JOIN library_books lb ON lb.id = li.book_id LEFT JOIN students s ON s.id = li.student_id WHERE li.id = ? AND li.status = 'Issued'");
        $stmt->execute([$issue_id]);
        $issue = $stmt->fetch();
        if ($issue) {
            $pdo->prepare("UPDATE library_issues SET status='Lost' WHERE id=?")->execute([$issue_id]);
            // হারিয়ে যাওয়া কপি স্টক থেকে কমিয়ে দেওয়া হচ্ছে (available_copies এ ফেরত আসবে না)
            $pdo->prepare("UPDATE library_books SET total_copies = GREATEST(total_copies - 1, 0) WHERE id = ?")->execute([$issue['book_id']]);
            sec_log_activity($pdo, 'Library', 'Update', "বই হারিয়ে গেছে হিসেবে চিহ্নিত করা হয়েছে: {$issue['title']} ({$issue['book_code']}) - {$issue['name_en']}");
            $_SESSION['lr_msg'] = 'বইটি হারিয়ে গেছে হিসেবে চিহ্নিত করা হয়েছে এবং মোট কপি সংখ্যা থেকে বাদ দেওয়া হয়েছে।';
        }
    }

    header('Location: index.php?page=library_return');
    exit();
}

$q = trim($_GET['q'] ?? '');
$where = ["li.status = 'Issued'"];
$params = [];
if ($q !== '') {
    $where[] = '(students.name_en LIKE ? OR students.student_id LIKE ? OR lb.title LIKE ? OR lb.book_code LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
branch_apply_filter($where, $params, 'students.branch_id');

$sql = "SELECT li.*, lb.title, lb.book_code, students.student_id AS stu_code, students.name_en, students.roll_no,
               academic_classes.class_name, academic_sections.section_name
        FROM library_issues li
        JOIN library_books lb ON lb.id = li.book_id
        JOIN students ON students.id = li.student_id
        LEFT JOIN academic_classes ON academic_classes.id = students.class_id
        LEFT JOIN academic_sections ON academic_sections.id = students.section_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY li.due_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$today = date('Y-m-d');
$overdue_count = 0;
foreach ($rows as $r) { if (strtotime($r['due_date']) < strtotime($today)) $overdue_count++; }

// সম্প্রতি ফেরত দেওয়া (শেষ ১৫টি) — যাদের জরিমানা এখনো আদায় হয়নি তাদের জন্য
$unpaid_stmt = $pdo->query("SELECT li.*, lb.title, lb.book_code, students.student_id AS stu_code, students.name_en
                             FROM library_issues li
                             JOIN library_books lb ON lb.id = li.book_id
                             JOIN students ON students.id = li.student_id
                             WHERE li.status = 'Returned' AND li.fine_amount > 0 AND li.fine_paid = 0
                             ORDER BY li.return_date DESC LIMIT 20");
$unpaid_rows = $unpaid_stmt->fetchAll();
?>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="top-stat-box" style="background:#0284c7;">
            <div><h3><?php echo count($rows); ?></h3><p>মোট ইস্যুকৃত বই</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-book-open-reader"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="top-stat-box" style="background:#dc2626;">
            <div><h3><?php echo $overdue_count; ?></h3><p>মেয়াদ উত্তীর্ণ (Overdue)</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="top-stat-box" style="background:#f59e0b;">
            <div><h3><?php echo count($unpaid_rows); ?></h3><p>অনাদায়ী জরিমানা</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-coins"></i></div>
        </div>
    </div>
</div>

<div class="card-custom mb-3">
    <?php if ($lr_msg): ?><div class="alert alert-success py-2"><?php echo lv($lr_msg); ?></div><?php endif; ?>
    <?php if ($lr_err): ?><div class="alert alert-danger py-2"><?php echo lv($lr_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-normal text-dark m-0">বর্তমানে ইস্যুকৃত সকল বই</h5>
        <span class="text-muted small">ফাইন প্রতি দিন: ৳<?php echo number_format((float) $settings['fine_per_day'], 2); ?></span>
    </div>

    <form method="GET" action="index.php" class="row g-2 mb-3">
        <input type="hidden" name="page" value="library_return">
        <div class="col-md-9"><input type="text" name="q" class="form-control" placeholder="Student Name / Student ID / Book Title / Book Code" value="<?php echo lv($q); ?>"></div>
        <div class="col-md-3"><button type="submit" class="btn btn-outline-primary w-100"><i class="fa-solid fa-search"></i> Search</button></div>
    </form>

    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Student</th><th>Class/Roll</th><th>Book</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th>Fine Est.</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token());
                    $overdue = strtotime($row['due_date']) < strtotime($today);
                    $days_late = $overdue ? (int) floor((strtotime($today) - strtotime($row['due_date'])) / 86400) : 0;
                    $est_fine = $days_late * (float) $settings['fine_per_day'];
                ?>
                    <tr class="<?php echo $overdue ? 'table-danger' : ''; ?>">
                        <td>
                            <div class="fw-bold"><?php echo lv($row['name_en']); ?></div>
                            <div class="text-muted small"><?php echo lv($row['stu_code']); ?></div>
                        </td>
                        <td><?php echo lv($row['class_name']); ?> <?php echo $row['section_name'] ? '/ ' . lv($row['section_name']) : ''; ?><br><span class="text-muted small">Roll: <?php echo lv($row['roll_no']); ?></span></td>
                        <td><?php echo lv($row['title']); ?><br><span class="text-muted small"><?php echo lv($row['book_code']); ?></span></td>
                        <td><?php echo lv($row['issue_date']); ?></td>
                        <td><?php echo lv($row['due_date']); ?></td>
                        <td><?php echo $overdue ? '<span class="badge bg-danger">' . $days_late . ' দিন দেরি</span>' : '<span class="badge bg-success">সময়মতো</span>'; ?></td>
                        <td><?php echo $est_fine > 0 ? '৳' . number_format($est_fine, 2) : '-'; ?></td>
                        <td class="text-nowrap">
                            <form method="POST" action="index.php?page=library_return" class="d-inline-flex gap-1 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="date" name="return_date" class="form-control form-control-sm" style="width:135px;" value="<?php echo $today; ?>">
                                <button type="submit" name="return_book" class="btn btn-sm btn-success" title="Return"><i class="fa-solid fa-rotate-left"></i></button>
                            </form>
                            <form method="POST" action="index.php?page=library_return" class="d-inline" onsubmit="return confirm('বইটি হারিয়ে গেছে হিসেবে চিহ্নিত করবেন? এটি স্টক থেকে বাদ যাবে।');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int) $row['id']; ?>">
                                <button type="submit" name="mark_lost" class="btn btn-sm btn-outline-danger" title="Mark as Lost"><i class="fa-solid fa-triangle-exclamation"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">এই মুহূর্তে কোনো বই ইস্যু অবস্থায় নেই</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($unpaid_rows): ?>
<div class="card-custom">
    <h5 class="fw-normal text-dark mb-3">অনাদায়ী জরিমানা (ফেরত দেওয়া হয়েছে কিন্তু জরিমানা বাকি)</h5>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr><th>Student</th><th>Book</th><th>Return Date</th><th>Fine</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($unpaid_rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                    <tr>
                        <td><?php echo lv($row['name_en']); ?> <span class="text-muted small">(<?php echo lv($row['stu_code']); ?>)</span></td>
                        <td><?php echo lv($row['title']); ?></td>
                        <td><?php echo lv($row['return_date']); ?></td>
                        <td class="fw-bold text-danger">৳<?php echo number_format((float) $row['fine_amount'], 2); ?></td>
                        <td>
                            <form method="POST" action="index.php?page=library_return">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="issue_id" value="<?php echo (int) $row['id']; ?>">
                                <button type="submit" name="mark_fine_paid" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-check"></i> Mark Paid</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
