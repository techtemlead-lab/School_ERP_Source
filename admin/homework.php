<?php
// admin/homework.php - E-Learning Module > Homework
// -----------------------------------------------------------------
// এই একই ফাইল Admin Panel আর Teacher Portal দুই জায়গা থেকেই include হয়
// (result_marks_entry_subject.php যেভাবে করে সেই একই প্যাটার্নে)।
// Admin context এ সব ক্লাসের হোমওয়ার্ক দেখা/এডিট করা যাবে, Teacher context এ
// শুধু নিজের Assign করা Class+Subject এর হোমওয়ার্ক (নিজে যা বানিয়েছে তা) দেখা যাবে।
//
// এই সিস্টেমের একাডেমিক হায়ারার্কি (academic_classes -> academic_sections ->
// academic_subjects, students.class_id/section_id) এর উপরেই এই মডিউল বসানো —
// আলাদা কোনো "কোর্স/ব্যাচ কেনা" কনসেপ্ট নেই, স্টুডেন্ট তার নিজের Class/Section
// এর হোমওয়ার্ক এমনিতেই দেখতে পাবে।

if (!function_exists('hw_e')) {
    function hw_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('hw_ensure_column')) {
    function hw_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        $chk = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->fetch();
        if (!$chk) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

// -----------------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি (self-healing migration)
// -----------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        section_id INT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        deadline DATETIME NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_class_section (class_id, section_id),
        KEY idx_subject (subject_id),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS elearning_homework_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        homework_id INT NOT NULL,
        student_id INT NOT NULL,
        submission_text TEXT NULL,
        attachment_path VARCHAR(255) NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        marks DECIMAL(5,2) NULL,
        feedback TEXT NULL,
        status ENUM('Submitted','Checked') NOT NULL DEFAULT 'Submitted',
        UNIQUE KEY uniq_submission (homework_id, student_id),
        KEY idx_homework (homework_id),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// -----------------------------------------------------------------
// Context: Admin নাকি Teacher পোর্টাল থেকে এসেছি
// -----------------------------------------------------------------
$is_teacher_ctx = !empty($_SESSION['teacher_logged']);
$me_teacher_id  = $is_teacher_ctx ? (int) ($_SESSION['teacher_id'] ?? 0) : 0;

// টিচারের নিজের Assign করা Class -> Subject[] লিস্ট (teacher_subject_assignments থেকে, যেটা
// result_assign_subject_teacher.php তে আগে থেকেই ব্যবহৃত হচ্ছে)
function hw_teacher_class_subjects(PDO $pdo, int $teacher_id): array
{
    $stmt = $pdo->prepare(
        // Routine-এ যে ক্লাস/সাবজেক্টে টিচারকে দেওয়া আছে সেগুলোও ধরা হয় — অর্থাৎ
        // Result > Assign Subject Teacher পেজে আলাদা করে এসাইন না করলেও, Routine-এ
        // টিচার সেট করা থাকলেই এখানে সেই ক্লাস/সাবজেক্ট পাওয়া যাবে
        // (result_marks_entry_subject.php-ও ঠিক এই একই নিয়মে চলে)।
        "SELECT class_id, subject_id FROM teacher_subject_assignments WHERE employee_id = ?
         UNION
         SELECT class_id, subject_id FROM routines WHERE teacher_id = ? AND status = 'Active' AND class_id IS NOT NULL"
    );
    $stmt->execute([$teacher_id, $teacher_id]);
    $map = []; // class_id => [subject_id, subject_id...]
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['class_id']][] = (int) $row['subject_id'];
    }
    return $map;
}
$hw_allowed_map = $is_teacher_ctx ? hw_teacher_class_subjects($pdo, $me_teacher_id) : [];
$hw_allowed_class_ids = array_keys($hw_allowed_map);

// Teacher context-এ টিচার কোন কোন হোমওয়ার্ক দেখতে পাবে তার SQL শর্ত —
// নিজের বানানো সবগুলো, আর সাথে Admin-এর বানানো (teacher_id ফাঁকা) হোমওয়ার্কের মধ্যে
// যেগুলো তার নিজের ক্লাসের। এতে Admin হোমওয়ার্ক দিলেও টিচার ছাত্রদের সাবমিশন দেখতে পান।
function hw_teacher_scope_sql(int $teacher_id, array $allowed_class_ids, array &$params): string
{
    $sql = 'h.teacher_id = ?';
    $params[] = $teacher_id;
    if ($allowed_class_ids) {
        $in = implode(',', array_map('intval', $allowed_class_ids));
        $sql .= " OR (h.teacher_id IS NULL AND h.class_id IN ($in))";
    }
    return '(' . $sql . ')';
}

// -----------------------------------------------------------------
// Homework attachment আপলোড হেল্পার (এই সিস্টেমের tenant_upload_abs/rel প্যাটার্ন অনুযায়ী)
// -----------------------------------------------------------------
function hw_handle_upload(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null]; // ফাইল দেয়নি, সমস্যা নাই
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ 10MB এর বেশি হওয়া যাবে না।'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'এই ধরনের ফাইল সাপোর্টেড না। (pdf/doc/ppt/xls/jpg/png/zip)'];
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'homework');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('homework') . '/' . $random_name, 'error' => null];
}

$hw_err = $_SESSION['hw_err'] ?? '';
$hw_msg = $_SESSION['hw_msg'] ?? '';
unset($_SESSION['hw_err'], $_SESSION['hw_msg']);

// -----------------------------------------------------------------
// POST হ্যান্ডলার
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['hw_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=homework');
        exit();
    }

    // --- হোমওয়ার্ক তৈরি/আপডেট ---
    if (isset($_POST['save_homework'])) {
        $hw_id       = (int) ($_POST['hw_id'] ?? 0);
        $class_id    = (int) ($_POST['class_id'] ?? 0);
        $section_id  = (int) ($_POST['section_id'] ?? 0) ?: null;
        $subject_id  = (int) ($_POST['subject_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $deadline    = trim($_POST['deadline'] ?? '') ?: null;

        $valid = $class_id && $subject_id && $title !== '';

        // Teacher context হলে, class/subject তার নিজের Assign এর মধ্যেই আছে কিনা যাচাই
        if ($valid && $is_teacher_ctx) {
            if (!isset($hw_allowed_map[$class_id]) || !in_array($subject_id, $hw_allowed_map[$class_id], true)) {
                $valid = false;
                $_SESSION['hw_err'] = 'আপনি এই Class/Subject এর জন্য Assign করা নন।';
            }
        }

        if (!$valid) {
            if (empty($_SESSION['hw_err'])) {
                $_SESSION['hw_err'] = 'Class, Subject ও Title অবশ্যই দিতে হবে।';
            }
        } else {
            $upload = hw_handle_upload($_FILES['attachment'] ?? []);
            if (!$upload['ok']) {
                $_SESSION['hw_err'] = $upload['error'];
            } else {
                $teacher_id_to_save = $is_teacher_ctx ? $me_teacher_id : ((int) ($_POST['teacher_id'] ?? 0) ?: null);

                if ($hw_id) {
                    // এডিট — Teacher context হলে শুধু নিজের বানানো হোমওয়ার্কই এডিট করতে পারবে
                    $own_check = '';
                    $params = [$class_id, $section_id, $subject_id, $title, $description, $deadline];
                    $sql = "UPDATE elearning_homework SET class_id=?, section_id=?, subject_id=?, title=?, description=?, deadline=?";
                    if ($upload['path']) {
                        $sql .= ", attachment_path=?";
                        $params[] = $upload['path'];
                    }
                    $sql .= " WHERE id=?";
                    $params[] = $hw_id;
                    if ($is_teacher_ctx) {
                        $sql .= " AND teacher_id=?";
                        $params[] = $me_teacher_id;
                    }
                    $pdo->prepare($sql)->execute($params);
                    $_SESSION['hw_msg'] = 'হোমওয়ার্ক আপডেট হয়েছে।';
                    sec_log_activity($pdo, 'Homework', 'Update', "হোমওয়ার্ক আপডেট করা হয়েছে: {$title}");
                } else {
                    $pdo->prepare(
                        "INSERT INTO elearning_homework (class_id, section_id, subject_id, teacher_id, title, description, attachment_path, deadline)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([$class_id, $section_id, $subject_id, $teacher_id_to_save, $title, $description, $upload['path'], $deadline]);
                    $_SESSION['hw_msg'] = 'হোমওয়ার্ক তৈরি হয়েছে।';
                    sec_log_activity($pdo, 'Homework', 'Create', "নতুন হোমওয়ার্ক তৈরি করা হয়েছে: {$title}");
                }
            }
        }
        header('Location: index.php?page=homework');
        exit();
    }

    // --- হোমওয়ার্ক ডিলিট ---
    if (isset($_POST['delete_homework'])) {
        $hw_id = (int) ($_POST['hw_id'] ?? 0);
        $del_hw_title_row = $pdo->prepare("SELECT title FROM elearning_homework WHERE id = ?");
        $del_hw_title_row->execute([$hw_id]);
        $del_hw_title = $del_hw_title_row->fetchColumn();
        $sql = "DELETE FROM elearning_homework WHERE id=?";
        $params = [$hw_id];
        if ($is_teacher_ctx) { $sql .= " AND teacher_id=?"; $params[] = $me_teacher_id; }
        $pdo->prepare($sql)->execute($params);
        $pdo->prepare("DELETE FROM elearning_homework_submissions WHERE homework_id=?")->execute([$hw_id]);
        $_SESSION['hw_msg'] = 'হোমওয়ার্ক মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Homework', 'Delete', "হোমওয়ার্ক মুছে ফেলা হয়েছে: " . ($del_hw_title ?: "#$hw_id"));
        header('Location: index.php?page=homework');
        exit();
    }

    // --- সাবমিশনে মার্কস/ফিডব্যাক দেওয়া ---
    if (isset($_POST['save_submission_review'])) {
        $sub_id   = (int) ($_POST['submission_id'] ?? 0);
        $hw_id    = (int) ($_POST['hw_id'] ?? 0);
        $marks    = ($_POST['marks'] ?? '') !== '' ? (float) $_POST['marks'] : null;
        $feedback = trim($_POST['feedback'] ?? '');
        $pdo->prepare(
            "UPDATE elearning_homework_submissions SET marks=?, feedback=?, status='Checked' WHERE id=?"
        )->execute([$marks, $feedback, $sub_id]);
        $_SESSION['hw_msg'] = 'মূল্যায়ন সেভ হয়েছে।';
        header('Location: index.php?page=homework&action=view&id=' . $hw_id);
        exit();
    }
}

// -----------------------------------------------------------------
// ড্রপডাউন ডাটা
// -----------------------------------------------------------------
if ($is_teacher_ctx) {
    if ($hw_allowed_class_ids) {
        $in = implode(',', array_fill(0, count($hw_allowed_class_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, class_name FROM academic_classes WHERE id IN ($in) AND status='Active' ORDER BY rank");
        $stmt->execute($hw_allowed_class_ids);
        $hw_classes = $stmt->fetchAll();
    } else {
        $hw_classes = [];
    }
} else {
    $hw_classes = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status='Active' ORDER BY rank")->fetchAll();
}
$hw_teachers = $is_teacher_ctx ? [] : $pdo->query("SELECT id, name FROM employees WHERE type='Teachers' AND status='Active' ORDER BY name")->fetchAll();

// -----------------------------------------------------------------
// action=view — একটা নির্দিষ্ট হোমওয়ার্কের সাবমিশন লিস্ট
// -----------------------------------------------------------------
$hw_action = $_GET['action'] ?? 'list';
$hw_view_id = (int) ($_GET['id'] ?? 0);

if ($hw_action === 'view' && $hw_view_id) {
    $sql = "SELECT h.*, c.class_name, s.section_name, sub.subject_name, e.name AS teacher_name
            FROM elearning_homework h
            LEFT JOIN academic_classes c ON c.id = h.class_id
            LEFT JOIN academic_sections s ON s.id = h.section_id
            LEFT JOIN academic_subjects sub ON sub.id = h.subject_id
            LEFT JOIN employees e ON e.id = h.teacher_id
            WHERE h.id = ?";
    $params = [$hw_view_id];
    if ($is_teacher_ctx) {
        $sql .= ' AND ' . hw_teacher_scope_sql($me_teacher_id, $hw_allowed_class_ids, $params);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $hw_item = $stmt->fetch();

    if (!$hw_item) {
        echo '<div class="alert alert-danger">হোমওয়ার্কটি পাওয়া যায়নি।</div>';
    } else {
        // এই ক্লাস/সেকশনের সব একটিভ স্টুডেন্ট + তাদের সাবমিশন (থাকলে) — LEFT JOIN দিয়ে
        $stu_sql = "SELECT st.id, st.student_id, st.name_en, st.roll_no,
                           sub2.id AS submission_id, sub2.submission_text, sub2.attachment_path,
                           sub2.submitted_at, sub2.marks, sub2.feedback, sub2.status AS submission_status
                    FROM students st
                    LEFT JOIN elearning_homework_submissions sub2
                           ON sub2.student_id = st.id AND sub2.homework_id = ?
                    WHERE st.status = 'Active' AND st.class_id = ?";
        $stu_params = [$hw_view_id, $hw_item['class_id']];
        if ($hw_item['section_id']) {
            $stu_sql .= " AND st.section_id = ?";
            $stu_params[] = $hw_item['section_id'];
        }
        $hw_bf_where = []; $hw_bf_params = [];
        branch_apply_filter($hw_bf_where, $hw_bf_params, 'st.branch_id');
        if ($hw_bf_where) { $stu_sql .= ' AND ' . implode(' AND ', $hw_bf_where); $stu_params = array_merge($stu_params, $hw_bf_params); }
        $stu_sql .= " ORDER BY (st.roll_no + 0), st.roll_no";
        $stmt2 = $pdo->prepare($stu_sql);
        $stmt2->execute($stu_params);
        $hw_students = $stmt2->fetchAll();
        ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0"><?php echo hw_e($hw_item['title']); ?></h5>
            <a href="index.php?page=homework" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-left me-1"></i> ফিরে যান</a>
        </div>

        <?php if ($hw_msg): ?><div class="alert alert-success py-2"><?php echo hw_e($hw_msg); ?></div><?php endif; ?>

        <div class="card-custom mb-3">
            <div class="row">
                <div class="col-md-3"><b>Class:</b> <?php echo hw_e($hw_item['class_name']); ?></div>
                <div class="col-md-3"><b>Section:</b> <?php echo hw_e($hw_item['section_name'] ?? 'সব সেকশন'); ?></div>
                <div class="col-md-3"><b>Subject:</b> <?php echo hw_e($hw_item['subject_name']); ?></div>
                <div class="col-md-3"><b>Deadline:</b> <?php echo hw_e($hw_item['deadline'] ?? '—'); ?></div>
            </div>
            <?php if ($hw_item['description']): ?><p class="mt-2 mb-0 text-muted"><?php echo nl2br(hw_e($hw_item['description'])); ?></p><?php endif; ?>
        </div>

        <div class="card-custom">
            <h6 class="fw-bold mb-3">স্টুডেন্ট সাবমিশন (<?php echo count(array_filter($hw_students, fn($s) => $s['submission_id'])); ?> / <?php echo count($hw_students); ?>)</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Roll</th><th>Student</th><th>Status</th><th>Submitted File</th><th>Marks</th><th>Feedback</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hw_students as $s): ?>
                        <tr>
                            <td><?php echo hw_e($s['roll_no']); ?></td>
                            <td><?php echo hw_e($s['name_en']); ?></td>
                            <td>
                                <?php if (!$s['submission_id']): ?>
                                    <span class="badge bg-secondary">জমা দেয়নি</span>
                                <?php elseif ($s['submission_status'] === 'Checked'): ?>
                                    <span class="badge bg-success">চেক করা হয়েছে</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">জমা দিয়েছে</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['attachment_path']): ?>
                                    <a href="../<?php echo hw_e($s['attachment_path']); ?>" target="_blank">ফাইল দেখুন</a>
                                <?php elseif ($s['submission_text']): ?>
                                    <span class="small text-muted"><?php echo hw_e(mb_substr($s['submission_text'], 0, 40)); ?>…</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <?php if ($s['submission_id']): ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="submission_id" value="<?php echo (int) $s['submission_id']; ?>">
                                <input type="hidden" name="hw_id" value="<?php echo (int) $hw_view_id; ?>">
                                <td><input type="number" step="0.5" name="marks" value="<?php echo hw_e($s['marks']); ?>" class="form-control form-control-sm" style="width:80px;"></td>
                                <td><input type="text" name="feedback" value="<?php echo hw_e($s['feedback']); ?>" class="form-control form-control-sm"></td>
                                <td><button type="submit" name="save_submission_review" class="btn btn-sm btn-primary">Save</button></td>
                            </form>
                            <?php else: ?>
                                <td colspan="3" class="text-muted small">এখনো জমা দেয়নি</td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$hw_students): ?>
                            <tr><td colspan="7" class="text-center text-muted">এই Class/Section এ কোনো একটিভ স্টুডেন্ট নেই।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    return;
}

// -----------------------------------------------------------------
// LIST + CREATE/EDIT ফর্ম (ডিফল্ট view)
// -----------------------------------------------------------------
$hw_edit_id = (int) ($_GET['edit'] ?? 0);
$hw_edit = null;
if ($hw_edit_id) {
    $sql = "SELECT * FROM elearning_homework WHERE id = ?";
    $params = [$hw_edit_id];
    if ($is_teacher_ctx) { $sql .= " AND teacher_id = ?"; $params[] = $me_teacher_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $hw_edit = $stmt->fetch();
}

$list_sql = "SELECT h.*, c.class_name, s.section_name, sub.subject_name,
                    (SELECT COUNT(*) FROM elearning_homework_submissions WHERE homework_id = h.id) AS submitted_count,
                    (SELECT COUNT(*) FROM students st WHERE st.status='Active' AND st.class_id = h.class_id
                        AND (h.section_id IS NULL OR st.section_id = h.section_id)
                        AND (? IS NULL OR st.branch_id = ? OR st.branch_id IS NULL)) AS total_count
             FROM elearning_homework h
             LEFT JOIN academic_classes c ON c.id = h.class_id
             LEFT JOIN academic_sections s ON s.id = h.section_id
             LEFT JOIN academic_subjects sub ON sub.id = h.subject_id";
$list_params = [branch_current_id(), branch_current_id()];
if ($is_teacher_ctx) {
    $list_sql .= ' WHERE ' . hw_teacher_scope_sql($me_teacher_id, $hw_allowed_class_ids, $list_params);
}
$list_sql .= " ORDER BY h.created_at DESC";
$stmt = $pdo->prepare($list_sql);
$stmt->execute($list_params);
$hw_list = $stmt->fetchAll();
?>

<?php if ($hw_msg): ?><div class="alert alert-success py-2"><?php echo hw_e($hw_msg); ?></div><?php endif; ?>
<?php if ($hw_err): ?><div class="alert alert-danger py-2"><?php echo hw_e($hw_err); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3"><?php echo $hw_edit ? 'হোমওয়ার্ক এডিট করুন' : 'নতুন হোমওয়ার্ক দিন'; ?></h6>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="hw_id" value="<?php echo (int) ($hw_edit['id'] ?? 0); ?>">

                <div class="mb-2">
                    <label class="form-label small fw-bold">Class</label>
                    <select name="class_id" id="hwClassSelect" class="form-select form-select-sm" required>
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($hw_classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (($hw_edit['class_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo hw_e($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Section (খালি রাখলে সব সেকশন)</label>
                    <select name="section_id" id="hwSectionSelect" class="form-select form-select-sm">
                        <option value="">-- সব সেকশন --</option>
                        <?php
                        // সব Section-ই রেন্ডার করা হচ্ছে data-class সহ, JS দিয়ে সিলেক্টেড Class অনুযায়ী
                        // ফিল্টার হবে (student_admission.php এর filterSections() এর মতো একই প্যাটার্ন)
                        $all_sections = $pdo->query("SELECT id, class_id, section_name FROM academic_sections WHERE status='Active'")->fetchAll();
                        foreach ($all_sections as $sec) {
                            if ($is_teacher_ctx && !in_array((int) $sec['class_id'], $hw_allowed_class_ids, true)) { continue; }
                            $sel = ($hw_edit['section_id'] ?? null) == $sec['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sec['id'] . '" data-class="' . (int) $sec['class_id'] . '" ' . $sel . '>' . hw_e($sec['section_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Subject</label>
                    <select name="subject_id" id="hwSubjectSelect" class="form-select form-select-sm" required>
                        <option value="">-- আগে Class সিলেক্ট করুন --</option>
                        <?php
                        // Admin context: সেই ক্লাসের সব সাবজেক্ট। Teacher context: শুধু তার Assign করা সাবজেক্ট।
                        if ($is_teacher_ctx) {
                            $all_sub_ids = $hw_allowed_map ? array_unique(array_merge(...array_values($hw_allowed_map))) : [];
                            $subs = [];
                            if ($all_sub_ids) {
                                $in = implode(',', array_fill(0, count($all_sub_ids), '?'));
                                $sub_stmt = $pdo->prepare("SELECT id, class_id, subject_name FROM academic_subjects WHERE id IN ($in)");
                                $sub_stmt->execute($all_sub_ids);
                                $subs = $sub_stmt->fetchAll();
                            }
                        } else {
                            $subs = $pdo->query("SELECT id, class_id, subject_name FROM academic_subjects WHERE status='Active'")->fetchAll();
                        }
                        foreach ($subs as $sub) {
                            // Teacher context এ একই subject একাধিক class এ assign থাকতে পারে বলে class_id ধরে
                            // সঠিক জোড়াটাই (allowed_map[class_id] তে subject_id আছে কিনা) চেক করা হচ্ছে
                            if ($is_teacher_ctx && !in_array((int) $sub['id'], $hw_allowed_map[(int) $sub['class_id']] ?? [], true)) { continue; }
                            $sel = ($hw_edit['subject_id'] ?? null) == $sub['id'] ? 'selected' : '';
                            echo '<option value="' . (int) $sub['id'] . '" data-class="' . (int) $sub['class_id'] . '" ' . $sel . '>' . hw_e($sub['subject_name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <?php if (!$is_teacher_ctx): ?>
                <div class="mb-2">
                    <label class="form-label small fw-bold">Assigned Teacher (ঐচ্ছিক)</label>
                    <select name="teacher_id" class="form-select form-select-sm">
                        <option value="">-- নির্বাচন করুন --</option>
                        <?php foreach ($hw_teachers as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo (($hw_edit['teacher_id'] ?? 0) == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo hw_e($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Title</label>
                    <input type="text" name="title" class="form-control form-control-sm" required value="<?php echo hw_e($hw_edit['title'] ?? ''); ?>">
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">বিবরণ</label>
                    <textarea name="description" rows="3" class="form-control form-control-sm"><?php echo hw_e($hw_edit['description'] ?? ''); ?></textarea>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Deadline</label>
                    <input type="datetime-local" name="deadline" class="form-control form-control-sm"
                           value="<?php echo $hw_edit && $hw_edit['deadline'] ? date('Y-m-d\TH:i', strtotime($hw_edit['deadline'])) : ''; ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">ফাইল অ্যাটাচমেন্ট (ঐচ্ছিক)</label>
                    <input type="file" name="attachment" class="form-control form-control-sm">
                    <?php if (!empty($hw_edit['attachment_path'])): ?>
                        <div class="small text-muted mt-1">বর্তমান ফাইল: <a href="../<?php echo hw_e($hw_edit['attachment_path']); ?>" target="_blank">দেখুন</a> (নতুন দিলে পাল্টে যাবে)</div>
                    <?php endif; ?>
                </div>

                <button type="submit" name="save_homework" class="btn btn-sm btn-primary w-100">
                    <?php echo $hw_edit ? 'আপডেট করুন' : 'হোমওয়ার্ক তৈরি করুন'; ?>
                </button>
                <?php if ($hw_edit): ?>
                    <a href="index.php?page=homework" class="btn btn-sm btn-secondary w-100 mt-1">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8 mb-3">
        <div class="card-custom">
            <h6 class="fw-bold mb-3">হোমওয়ার্ক লিস্ট</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th><th>Class/Section</th><th>Subject</th><th>Deadline</th><th>Submission</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hw_list as $h): ?>
                        <tr>
                            <td><?php echo hw_e($h['title']); ?></td>
                            <td><?php echo hw_e($h['class_name']); ?><?php echo $h['section_name'] ? ' / ' . hw_e($h['section_name']) : ' (সব সেকশন)'; ?></td>
                            <td><?php echo hw_e($h['subject_name']); ?></td>
                            <td><?php echo $h['deadline'] ? hw_e(date('d M, h:i A', strtotime($h['deadline']))) : '—'; ?></td>
                            <td>
                                <a href="index.php?page=homework&action=view&id=<?php echo (int) $h['id']; ?>"
                                   class="btn btn-sm <?php echo (int) $h['submitted_count'] > 0 ? 'btn-info text-white' : 'btn-outline-secondary'; ?>"
                                   title="কে কে জমা দিয়েছে দেখুন / নম্বর দিন">
                                    <i class="fa-solid fa-inbox me-1"></i>
                                    <?php echo (int) $h['submitted_count']; ?> / <?php echo (int) $h['total_count']; ?> জমা
                                </a>
                            </td>
                            <td class="text-nowrap">
                                <a href="index.php?page=homework&edit=<?php echo (int) $h['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('আপনি কি নিশ্চিত এটা ডিলিট করতে চান?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="hw_id" value="<?php echo (int) $h['id']; ?>">
                                    <button type="submit" name="delete_homework" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$hw_list): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এখনো কোনো হোমওয়ার্ক তৈরি হয়নি।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Class সিলেক্ট করলে Section ও Subject dropdown-এ শুধু সেই Class-এর অপশনগুলোই দেখানো হয়
// (student_admission.php-এর filterSections()-এর মতোই একই পদ্ধতি — সব option আগে থেকেই DOM-এ
// আছে, শুধু data-class মিলিয়ে hidden টগল করা হয়, তাই কোনো AJAX/reload লাগে না)
function hwFilterByClass() {
    const classId = document.getElementById('hwClassSelect').value;
    ['hwSectionSelect', 'hwSubjectSelect'].forEach(function (selId) {
        const select = document.getElementById(selId);
        select.disabled = !classId;
        Array.from(select.options).forEach(function (opt) {
            if (!opt.value) { opt.hidden = false; return; }
            opt.hidden = !classId || opt.dataset.class !== classId;
        });
    });
    // Class পাল্টালে আগের Section/Subject সিলেকশন আর প্রযোজ্য না হলে রিসেট করা
    const secSel = document.getElementById('hwSectionSelect');
    if (secSel.selectedOptions[0] && secSel.selectedOptions[0].hidden) { secSel.value = ''; }
    const subSel = document.getElementById('hwSubjectSelect');
    if (subSel.selectedOptions[0] && subSel.selectedOptions[0].hidden) { subSel.value = ''; }
}
document.getElementById('hwClassSelect')?.addEventListener('change', hwFilterByClass);
hwFilterByClass(); // পেজ লোডেই (এডিট মোডে আগে থেকে সিলেক্ট করা Class থাকলে) একবার চালানো
</script>
