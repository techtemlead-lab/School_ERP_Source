<?php
// admin/library_issue.php - Library Module > Issue Book (Student ID দিয়ে কাজ করে, আলাদা কোনো Member টেবিল নেই)
$li_err = $_SESSION['li_err'] ?? '';
$li_msg = $_SESSION['li_msg'] ?? '';
unset($_SESSION['li_err'], $_SESSION['li_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_books (
        id INT AUTO_INCREMENT PRIMARY KEY, book_code VARCHAR(50) NULL, isbn VARCHAR(30) NULL,
        title VARCHAR(255) NOT NULL, title_bn VARCHAR(255) NULL, author VARCHAR(150) NULL, publisher VARCHAR(150) NULL,
        category_id INT NULL, edition VARCHAR(50) NULL, language VARCHAR(30) NOT NULL DEFAULT 'Bengali',
        shelf_no VARCHAR(30) NULL, rack_no VARCHAR(30) NULL, price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_copies INT NOT NULL DEFAULT 1, available_copies INT NOT NULL DEFAULT 1,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_book_code (book_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_issues (
        id INT AUTO_INCREMENT PRIMARY KEY, book_id INT NOT NULL, student_id INT NOT NULL,
        issue_date DATE NOT NULL, due_date DATE NOT NULL, return_date DATE NULL,
        fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0, fine_paid TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Issued','Returned','Lost') NOT NULL DEFAULT 'Issued',
        remarks VARCHAR(255) NULL, issued_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_book (book_id), INDEX idx_student (student_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_settings (
        id INT PRIMARY KEY DEFAULT 1, fine_per_day DECIMAL(6,2) NOT NULL DEFAULT 5.00,
        max_days_allowed INT NOT NULL DEFAULT 14, max_books_per_student INT NOT NULL DEFAULT 2
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if (!$pdo->query("SELECT id FROM library_settings WHERE id=1")->fetch()) {
    $pdo->exec("INSERT INTO library_settings (id, fine_per_day, max_days_allowed, max_books_per_student) VALUES (1, 5.00, 14, 2)");
}
$settings = $pdo->query("SELECT * FROM library_settings WHERE id=1")->fetch();
require_once __DIR__ . '/library_common.php';

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST: Issue Book
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_book'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['li_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=library_issue');
        exit();
    }

    $student_pk = (int) ($_POST['student_pk'] ?? 0);
    $book_id    = (int) ($_POST['book_id'] ?? 0);
    $issue_date = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $due_date   = trim($_POST['due_date'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');

    try {
        if (!lib_valid_date($issue_date)) { throw new RuntimeException('ইস্যুর তারিখ সঠিক নয়।'); }
        if ($due_date !== '' && !lib_valid_date($due_date)) { throw new RuntimeException('ফেরতের তারিখ সঠিক নয়।'); }
        if ($due_date === '') {
            $due_date = date('Y-m-d', strtotime($issue_date . ' + ' . max(1, (int) $settings['max_days_allowed']) . ' days'));
        }
        if ($due_date < $issue_date) { throw new RuntimeException('ফেরতের তারিখ ইস্যুর তারিখের আগে হতে পারবে না।'); }

        $pdo->beginTransaction();
        // শিক্ষার্থী (branch scope) ও বই row লক: একই সময়ে দুজন শেষ কপি বা সীমা পার করতে পারবে না
        $student = lib_student_scoped($pdo, $student_pk, true);
        if (!$student) { throw new RuntimeException('শিক্ষার্থী খুঁজে পাওয়া যায়নি।'); }
        if (($student['status'] ?? 'Active') !== 'Active') { throw new RuntimeException('এই শিক্ষার্থী Active নয়।'); }
        $book_stmt = $pdo->prepare("SELECT * FROM library_books WHERE id = ? FOR UPDATE");
        $book_stmt->execute([$book_id]);
        $book = $book_stmt->fetch();
        if (!$book) { throw new RuntimeException('বই খুঁজে পাওয়া যায়নি।'); }
        if ($book['status'] !== 'Active') { throw new RuntimeException('এই বইটি Inactive, ইস্যু করা যাবে না।'); }
        if ((int) $book['available_copies'] <= 0) { throw new RuntimeException('এই বইয়ের কোনো কপি এই মুহূর্তে উপলব্ধ নেই।'); }

        $dupq = $pdo->prepare("SELECT COUNT(*) FROM library_issues WHERE student_id = ? AND book_id = ? AND status = 'Issued'");
        $dupq->execute([$student_pk, $book_id]);
        if ((int) $dupq->fetchColumn() > 0) { throw new RuntimeException('এই শিক্ষার্থীর কাছে এই বইটি ইতিমধ্যে ইস্যু করা আছে।'); }

        $count_stmt = $pdo->prepare("SELECT COUNT(*) c FROM library_issues WHERE student_id = ? AND status = 'Issued'");
        $count_stmt->execute([$student_pk]);
        $current_issued = (int) $count_stmt->fetch()['c'];
        if ($current_issued >= (int) $settings['max_books_per_student']) {
            throw new RuntimeException('এই শিক্ষার্থীর সর্বোচ্চ বই ধারণের সীমা (' . (int) $settings['max_books_per_student'] . 'টি) পূর্ণ হয়ে গেছে। আগে একটি বই ফেরত দিতে হবে।');
        }

        $ins = $pdo->prepare("INSERT INTO library_issues (book_id, student_id, issue_date, due_date, remarks, issued_by) VALUES (?,?,?,?,?,?)");
        $ins->execute([$book_id, $student_pk, $issue_date, $due_date, $remarks, $_SESSION['admin_user'] ?? '']);
        $pdo->prepare("UPDATE library_books SET available_copies = available_copies - 1 WHERE id = ?")->execute([$book_id]);
        $pdo->commit();
        sec_log_activity($pdo, 'Library', 'Create', "বই ইস্যু করা হয়েছে: {$book['title']} ({$book['book_code']}) - {$student['name_en']}, ফেরতের তারিখ: {$due_date}");
        $_SESSION['li_msg'] = lv($book['title']) . ' বইটি ' . lv($student['name_en']) . ' কে ইস্যু করা হয়েছে। ফেরতের তারিখ: ' . $due_date;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['li_err'] = $e->getMessage();
    }

    header('Location: index.php?page=library_issue&student_pk=' . $student_pk);
    exit();
}

// -----------------------------------------------------------
// Student Search
// -----------------------------------------------------------
$q         = trim($_GET['q'] ?? '');
$f_class   = $_GET['f_class'] ?? '';
$f_section = $_GET['f_section'] ?? '';
$classes   = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections  = $pdo->query("SELECT s.id, s.section_name, s.class_id, c.class_name FROM academic_sections s LEFT JOIN academic_classes c ON c.id = s.class_id ORDER BY s.section_name ASC")->fetchAll();

$search_rows = [];
if ($q !== '' || $f_class !== '' || $f_section !== '') {
    $where = ["students.status = 'Active'"];
    $params = [];
    if ($q !== '') {
        $where[] = '(students.name_en LIKE ? OR students.name_bn LIKE ? OR students.student_id LIKE ? OR students.roll_no LIKE ?)';
        $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
    }
    if ($f_class !== '')   { $where[] = 'students.class_id = ?'; $params[] = (int) $f_class; }
    if ($f_section !== '') { $where[] = 'students.section_id = ?'; $params[] = (int) $f_section; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT students.id, students.student_id, students.name_en, students.name_bn, students.roll_no, students.photo_path,
                   academic_classes.class_name, academic_sections.section_name
            FROM students
            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where) . " ORDER BY students.name_en ASC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_rows = $stmt->fetchAll();
}

// -----------------------------------------------------------
// Selected Student
// -----------------------------------------------------------
$student_pk = (int) ($_GET['student_pk'] ?? 0);
$selected_student = null;
$student_issues = [];
if ($student_pk > 0) {
    $stmt = $pdo->prepare("SELECT students.*, academic_classes.class_name, academic_sections.section_name
                            FROM students
                            LEFT JOIN academic_classes ON academic_classes.id = students.class_id
                            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
                            WHERE students.id = ?");
    $stmt->execute([$student_pk]);
    $selected_student = $stmt->fetch();
    if ($selected_student && !lib_student_scoped($pdo, $student_pk)) { $selected_student = null; }

    $istmt = $pdo->prepare("SELECT li.*, lb.title, lb.book_code FROM library_issues li
                             JOIN library_books lb ON lb.id = li.book_id
                             WHERE li.student_id = ? AND li.status = 'Issued' ORDER BY li.due_date ASC");
    $istmt->execute([$student_pk]);
    $student_issues = $istmt->fetchAll();
}

$preselect_book_id = (int) ($_GET['book_id'] ?? 0);
$available_books = $pdo->query("SELECT id, book_code, title, author, available_copies FROM library_books WHERE available_copies > 0 AND status='Active' ORDER BY title ASC")->fetchAll();
$default_due_date = date('Y-m-d', strtotime('+' . (int) $settings['max_days_allowed'] . ' days'));
?>

<div class="card-custom mb-3">
    <?php if ($li_msg): ?><div class="alert alert-success py-2"><?php echo lv($li_msg); ?></div><?php endif; ?>
    <?php if ($li_err): ?><div class="alert alert-danger py-2"><?php echo lv($li_err); ?></div><?php endif; ?>

    <h5 class="fw-normal text-dark mb-3"><i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Student খুঁজুন (Student ID / Name / Roll)</h5>
    <form method="GET" action="index.php" class="row g-2">
        <input type="hidden" name="page" value="library_issue">
        <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Student ID / Name / Roll No" value="<?php echo lv($q); ?>"></div>
        <div class="col-md-3">
            <select name="f_class" class="form-select" id="issueClassSelect">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo lv($c['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="f_section" class="form-select" id="issueSectionSelect">
                <option value="">All Sections</option>
                <?php foreach ($sections as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" data-class="<?php echo (int) $s['class_id']; ?>" <?php echo (string) $f_section === (string) $s['id'] ? 'selected' : ''; ?>><?php echo lv($s['section_name']); ?><?php echo !empty($s['class_name']) ? ' (' . lv($s['class_name']) . ')' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-search"></i> Search</button></div>
    </form>

    <?php if ($search_rows): ?>
        <div class="table-responsive mt-3">
            <table class="table table-bordered align-middle table-sm">
                <thead class="table-light">
                    <tr><th>Student ID</th><th>Name</th><th>Class / Section</th><th>Roll</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($search_rows as $s): ?>
                        <tr class="<?php echo $s['id'] == $student_pk ? 'table-success' : ''; ?>">
                            <td><?php echo lv($s['student_id']); ?></td>
                            <td><?php echo lv($s['name_en']); ?><?php echo $s['name_bn'] ? ' <span class="text-muted">(' . lv($s['name_bn']) . ')</span>' : ''; ?></td>
                            <td><?php echo lv($s['class_name']); ?> <?php echo $s['section_name'] ? '/ ' . lv($s['section_name']) : ''; ?></td>
                            <td><?php echo lv($s['roll_no']); ?></td>
                            <td><a href="index.php?page=library_issue&student_pk=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-success">Select <i class="fa-solid fa-arrow-right"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($q !== '' || $f_class !== '' || $f_section !== ''): ?>
        <p class="text-muted mt-3 mb-0">কোনো শিক্ষার্থী পাওয়া যায়নি।</p>
    <?php endif; ?>
</div>

<?php if ($selected_student): ?>
    <div class="row">
        <div class="col-md-5">
            <div class="card-custom">
                <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-id-card me-1"></i> Selected Student</h6>
                <table class="table table-borderless table-sm mb-0">
                    <tr><td class="text-muted" style="width:40%;">Student ID</td><td class="fw-bold"><?php echo lv($selected_student['student_id']); ?></td></tr>
                    <tr><td class="text-muted">Name</td><td class="fw-bold"><?php echo lv($selected_student['name_en']); ?></td></tr>
                    <tr><td class="text-muted">Class / Section</td><td><?php echo lv($selected_student['class_name']); ?> <?php echo $selected_student['section_name'] ? '/ ' . lv($selected_student['section_name']) : ''; ?></td></tr>
                    <tr><td class="text-muted">Roll No</td><td><?php echo lv($selected_student['roll_no']); ?></td></tr>
                    <tr><td class="text-muted">Guardian Phone</td><td><?php echo lv($selected_student['guardian_phone'] ?? ''); ?></td></tr>
                    <tr><td class="text-muted">Currently Holding</td><td><span class="badge bg-info text-dark"><?php echo count($student_issues); ?> / <?php echo (int) $settings['max_books_per_student']; ?></span></td></tr>
                </table>

                <?php if ($student_issues): ?>
                    <hr>
                    <h6 class="fw-bold small text-muted">এই মুহূর্তে যে বইগুলো তার কাছে আছে:</h6>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($student_issues as $iss): $overdue = strtotime($iss['due_date']) < strtotime(date('Y-m-d')); ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span><?php echo lv($iss['title']); ?> <span class="text-muted small">(<?php echo lv($iss['book_code']); ?>)</span></span>
                                <span class="badge <?php echo $overdue ? 'bg-danger' : 'bg-secondary'; ?>">Due: <?php echo lv($iss['due_date']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card-custom">
                <h6 class="fw-bold text-success mb-3"><i class="fa-solid fa-book-medical me-1"></i> Issue New Book</h6>

                <?php if (count($student_issues) >= (int) $settings['max_books_per_student']): ?>
                    <div class="alert alert-warning py-2">এই শিক্ষার্থীর সর্বোচ্চ বই ধারণের সীমা পূর্ণ। নতুন বই ইস্যু করতে হলে আগে একটি ফেরত নিন।</div>
                <?php else: ?>
                    <form method="POST" action="index.php?page=library_issue">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="student_pk" value="<?php echo (int) $selected_student['id']; ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Book <span class="text-danger">*</span></label>
                            <select name="book_id" class="form-select" required>
                                <option value="">-- বই নির্বাচন করুন --</option>
                                <?php foreach ($available_books as $b): ?>
                                    <option value="<?php echo (int) $b['id']; ?>" <?php echo $preselect_book_id === (int) $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo lv($b['book_code']); ?> - <?php echo lv($b['title']); ?><?php echo $b['author'] ? ' (' . lv($b['author']) . ')' : ''; ?> — Available: <?php echo (int) $b['available_copies']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Issue Date</label>
                                <input type="date" name="issue_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Due Date (ফেরতের তারিখ)</label>
                                <input type="date" name="due_date" class="form-control" value="<?php echo $default_due_date; ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Remarks (ঐচ্ছিক)</label>
                            <input type="text" name="remarks" class="form-control" placeholder="কোনো নোট থাকলে লিখুন">
                        </div>

                        <button type="submit" name="issue_book" class="btn btn-success px-4 fw-bold"><i class="fa-solid fa-check"></i> Issue Book</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.getElementById('issueClassSelect')?.addEventListener('change', function () {
    const classId = this.value;
    const sectionSelect = document.getElementById('issueSectionSelect');
    Array.from(sectionSelect.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = classId && opt.getAttribute('data-class') !== classId;
    });
    sectionSelect.value = '';
});
</script>
