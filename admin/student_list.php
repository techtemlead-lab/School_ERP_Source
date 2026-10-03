<?php
// admin/student_list.php - Current + Archive Student List (একই ফাইল, is_current দিয়ে আলাদা হয়)
// current_student_list  => is_current = 1 (এই মুহূর্তে যে ক্লাসে পড়ছে)
// archive_student_list  => is_current = 0 (প্রমোশনের পর আগের ক্লাসের ইতিহাস)

// পুরনো ইনস্টলেশনে students.is_current কলাম না থাকলে নিরাপদে যোগ করে দেওয়া হচ্ছে
// (সব বিদ্যমান রেকর্ড ডিফল্টভাবে Current ধরা হয়)
$stl_ic_col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'is_current'"
)->fetchColumn();
if (!$stl_ic_col) {
    $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
}

$is_archive = ($page === 'archive_student_list');
$list_page_slug = $is_archive ? 'archive_student_list' : 'current_student_list';

$stl_err = $_SESSION['stl_err'] ?? '';
$stl_msg = $_SESSION['stl_msg'] ?? '';
unset($_SESSION['stl_err'], $_SESSION['stl_msg']);

// -----------------------------------------------------------
// Delete Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['stl_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $del_stu_row = $pdo->prepare("SELECT name_en, student_id, roll_no FROM students WHERE id = ?");
        $del_stu_row->execute([$id]);
        $del_stu = $del_stu_row->fetch();
        $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
        $_SESSION['stl_msg'] = 'শিক্ষার্থীর তথ্য মুছে ফেলা হয়েছে।';
        if ($del_stu) {
            sec_log_activity($pdo, 'Student', 'Delete', "শিক্ষার্থী মুছে ফেলা হয়েছে: {$del_stu['name_en']} ({$del_stu['student_id']}, Roll: {$del_stu['roll_no']})");
        }
    }
    header('Location: index.php?page=' . $list_page_slug);
    exit();
}

$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
// Group প্রতিটা Class-এর জন্য আলাদা রেকর্ড (Nine-এর Arts, Ten-এর Arts...) — তাই ফিল্টার ড্রপডাউনে নাম ধরে একবারই দেখানো হয়
$groups   = $pdo->query("SELECT MIN(id) AS id, TRIM(group_name) AS group_name FROM academic_groups GROUP BY TRIM(group_name) ORDER BY group_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();
$sections_all = $pdo->query(
    "SELECT academic_sections.id, academic_sections.section_name, academic_sections.class_id, academic_classes.class_name
     FROM academic_sections
     LEFT JOIN academic_classes ON academic_classes.id = academic_sections.class_id
     ORDER BY academic_sections.section_name ASC"
)->fetchAll();

function slv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// Filters
// -----------------------------------------------------------
$f_session = $_GET['f_session'] ?? 'All';
$f_class   = $_GET['f_class'] ?? 'All';
$f_group   = $_GET['f_group'] ?? 'All';
$f_shift   = $_GET['f_shift'] ?? 'All';
$f_section = $_GET['f_section'] ?? 'All';
$f_name    = trim($_GET['f_name'] ?? '');
$f_sid     = trim($_GET['f_sid'] ?? '');
$f_roll    = trim($_GET['f_roll'] ?? '');
$f_device  = trim($_GET['f_device'] ?? '');
$f_finger  = trim($_GET['f_finger'] ?? '');
$f_phone   = trim($_GET['f_phone'] ?? '');
$f_dob     = trim($_GET['f_dob'] ?? '');
$f_status  = $_GET['f_status'] ?? 'All';

// Section ড্রপডাউন — academic_sections-এ প্রতিটা Class-এর জন্য আলাদা রো থাকে,
// তাই একই নামের Section (যেমন "Tista") একাধিক Class-এ থাকলে আগে ড্রপডাউনে
// অনেকবার একই নামে দেখাত, কোনটা কোন Class বোঝার উপায় ছিল না। এখন থেকে:
// একটা নির্দিষ্ট Class ফিল্টার করা থাকলে শুধু সেই Class-এর Section-গুলোই
// দেখাবে (তখন ডুপ্লিকেট নামও আসবে না); "All" থাকলে প্রতিটার পাশে Class-এর
// নাম বসিয়ে দেওয়া হয়, যাতে একই নাম হলেও আলাদা করে চেনা যায়।
if ($f_class !== 'All') {
    $sections = array_values(array_filter($sections_all, fn($s) => (string) $s['class_id'] === (string) $f_class));
} else {
    $sections = $sections_all;
}

$where = [];
$params = [];
$where[] = 'students.is_current = ?';
$params[] = $is_archive ? 0 : 1;
if ($f_session !== 'All') { $where[] = 'students.session_id = ?'; $params[] = (int) $f_session; }
if ($f_class !== 'All')   { $where[] = 'students.class_id = ?'; $params[] = (int) $f_class; }
if ($f_group !== 'All') {
    // f_group = গ্রুপের নাম (আগের লিংকে সংখ্যা/id থাকলে সেটা থেকে নাম বের করা হয়) — ওই নামের সব Class-এর গ্রুপ id ধরে ফিল্টার
    $fg_name = $f_group;
    if (ctype_digit((string) $f_group)) {
        $fg_q = $pdo->prepare("SELECT TRIM(group_name) FROM academic_groups WHERE id = ?");
        $fg_q->execute([(int) $f_group]);
        $fg_name = (string) ($fg_q->fetchColumn() ?: '');
    }
    $fg_ids = $pdo->prepare("SELECT id FROM academic_groups WHERE TRIM(group_name) = ?");
    $fg_ids->execute([$fg_name]);
    $fg_ids = array_map('intval', $fg_ids->fetchAll(PDO::FETCH_COLUMN));
    if ($fg_ids) {
        $where[] = 'students.group_id IN (' . implode(',', $fg_ids) . ')';
    } else {
        $where[] = '1 = 0';
    }
    $f_group = $fg_name; // ড্রপডাউনে সিলেক্টেড দেখানোর জন্য
}
if ($f_shift !== 'All')   { $where[] = 'students.shift_id = ?'; $params[] = (int) $f_shift; }
if ($f_section !== 'All') { $where[] = 'students.section_id = ?'; $params[] = (int) $f_section; }
if ($f_name !== '')   { $where[] = 'students.name_en LIKE ?'; $params[] = "%$f_name%"; }
if ($f_sid !== '')    { $where[] = 'students.student_id LIKE ?'; $params[] = "%$f_sid%"; }
if ($f_roll !== '')   { $where[] = 'students.roll_no LIKE ?'; $params[] = "%$f_roll%"; }
if ($f_device !== '') { $where[] = 'students.device_serial LIKE ?'; $params[] = "%$f_device%"; }
if ($f_finger !== '') { $where[] = 'students.tracking_id LIKE ?'; $params[] = "%$f_finger%"; }
if ($f_phone !== '')  { $where[] = 'students.guardian_phone LIKE ?'; $params[] = "%$f_phone%"; }
if ($f_dob !== '')    { $where[] = 'students.dob = ?'; $params[] = $f_dob; }
if ($f_status !== 'All') { $where[] = 'students.status = ?'; $params[] = $f_status; }
branch_apply_filter($where, $params, 'students.branch_id'); // লগইন করা branch অনুযায়ী স্টুডেন্ট ফিল্টার

$sql = "SELECT students.*, academic_sessions.session_name, academic_classes.class_name, academic_groups.group_name,
               academic_shifts.shift_name, academic_sections.section_name
        FROM students
        LEFT JOIN academic_sessions ON academic_sessions.id = students.session_id
        LEFT JOIN academic_classes ON academic_classes.id = students.class_id
        LEFT JOIN academic_groups ON academic_groups.id = students.group_id
        LEFT JOIN academic_shifts ON academic_shifts.id = students.shift_id
        LEFT JOIN academic_sections ON academic_sections.id = students.section_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
// আগে "সর্বশেষ যোগ করা স্টুডেন্ট আগে" (students.id DESC) সাজানো হতো, তাই Roll No
// কলামে ১, ২, ৩... ক্রমানুসারে না এসে এলোমেলো দেখাত। এখন Class > Section > Roll No
// (সংখ্যা হিসেবে, টেক্সট হিসেবে না — নাহলে "10" আসত "2"-এর আগে) অনুযায়ী সাজানো হয়,
// যাতে প্রতিটা Class/Section-এর মধ্যে Roll ক্রমানুসারে দেখা যায়।
$sql .= " ORDER BY students.class_id ASC, students.section_id ASC, CAST(students.roll_no AS UNSIGNED) ASC, students.id ASC";

// Pagination — আগে শুধু প্রথম ৫০ জন দেখাত, পরের পেজে যাওয়ার উপায় ছিল না
$per_page_opts = [25, 50, 100, 200];
$per_page = (int) ($_GET['per'] ?? 50);
if (!in_array($per_page, $per_page_opts, true)) $per_page = 50;
$cur_page = max(1, (int) ($_GET['p'] ?? 1));

// টোটাল গণনা (একই ফিল্টার সহ, কিন্তু LIMIT ছাড়া)
$count_sql = "SELECT
        SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) AS male_count,
        SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) AS female_count,
        COUNT(*) AS total_count
    FROM students";
if ($where) $count_sql .= " WHERE " . implode(' AND ', $where);
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$counts = $count_stmt->fetch();
$total_count = (int) ($counts['total_count'] ?? 0);
$total_pages = max(1, (int) ceil($total_count / $per_page));
if ($cur_page > $total_pages) $cur_page = $total_pages;
$offset = ($cur_page - 1) * $per_page;

$stmt = $pdo->prepare($sql . " LIMIT " . (int) $per_page . " OFFSET " . (int) $offset);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// পেজ লিংকে বর্তমান ফিল্টারগুলো ধরে রাখা
function stl_page_url(int $pg): string {
    $q = $_GET;
    $q['p'] = $pg;
    return 'index.php?' . htmlspecialchars(http_build_query($q), ENT_QUOTES, 'UTF-8');
}
?>

<div class="card-custom">
    <?php if ($stl_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($stl_msg); ?></div><?php endif; ?>
    <?php if ($stl_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($stl_err); ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <?php if (!$is_archive): ?>
            <a href="index.php?page=student_admission" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <div class="d-flex gap-4">
            <span class="text-info fw-bold"><i class="fa-solid fa-person"></i> Total Male : <?php echo (int) ($counts['male_count'] ?? 0); ?></span>
            <span class="text-success fw-bold"><i class="fa-solid fa-person-dress"></i> Total Female : <?php echo (int) ($counts['female_count'] ?? 0); ?></span>
        </div>
        <span class="text-muted small">Showing <?php echo $total_count ? ($offset + 1) : 0; ?>-<?php echo $offset + count($rows); ?> of <?php echo $total_count; ?> items.</span>
    </div>

        <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
<form method="GET" action="index.php">
        <input type="hidden" name="page" value="<?php echo $list_page_slug; ?>">
        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size: 13px;">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Session</th>
                        <th>Class</th>
                        <th>Group</th>
                        <th>Shift</th>
                        <th>Section</th>
                        <th>Optional Subjects</th>
                        <th>Name</th>
                        <th>Student ID</th>
                        <th>Roll No.</th>
                        <th>Device Serial / MAC</th>
                        <th>Finger ID</th>
                        <th>Guardian's Phone</th>
                        <th>Date of Birth</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    <tr>
                        <th></th>
                        <th>
                            <select name="f_session" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($sessions as $s): ?><option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $f_session === (string) $s['id'] ? 'selected' : ''; ?>><?php echo slv($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_class" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo slv($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_group" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($groups as $g): ?><option value="<?php echo slv($g['group_name']); ?>" <?php echo (string) $f_group === (string) $g['group_name'] ? 'selected' : ''; ?>><?php echo slv($g['group_name']); ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_shift" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($shifts as $sh): ?><option value="<?php echo (int) $sh['id']; ?>" <?php echo (string) $f_shift === (string) $sh['id'] ? 'selected' : ''; ?>><?php echo slv($sh['shift_name']); ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select name="f_section" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All">All</option>
                                <?php foreach ($sections as $sec): ?><option value="<?php echo (int) $sec['id']; ?>" <?php echo (string) $f_section === (string) $sec['id'] ? 'selected' : ''; ?>><?php echo slv($sec['section_name']); ?><?php echo $f_class === 'All' && $sec['class_name'] ? ' (' . slv($sec['class_name']) . ')' : ''; ?></option><?php endforeach; ?>
                            </select>
                        </th>
                        <th></th>
                        <th><input type="text" name="f_name" class="form-control form-control-sm" value="<?php echo slv($f_name); ?>"></th>
                        <th><input type="text" name="f_sid" class="form-control form-control-sm" value="<?php echo slv($f_sid); ?>"></th>
                        <th><input type="text" name="f_roll" class="form-control form-control-sm" value="<?php echo slv($f_roll); ?>"></th>
                        <th><input type="text" name="f_device" class="form-control form-control-sm" value="<?php echo slv($f_device); ?>"></th>
                        <th><input type="text" name="f_finger" class="form-control form-control-sm" value="<?php echo slv($f_finger); ?>"></th>
                        <th><input type="tel" name="f_phone" class="form-control form-control-sm phone-11" inputmode="numeric" maxlength="11" value="<?php echo slv($f_phone); ?>"></th>
                        <th><input type="text" name="f_dob" id="listDobFilter" class="form-control form-control-sm" placeholder="yyyy-mm-dd" autocomplete="off" value="<?php echo slv($f_dob); ?>"></th>
                        <th>
                            <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                <option value="Pending" <?php echo $f_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Transfer" <?php echo $f_status === 'Transfer' ? 'selected' : ''; ?>>Transfer</option>
                            </select>
                        </th>
                        <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = $offset + 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo slv($row['session_name'] ?? '-'); ?></td>
                            <td><?php echo slv($row['class_name'] ?? '-'); ?></td>
                            <td><?php echo $row['group_name'] ? slv($row['group_name']) : 'N/A'; ?></td>
                            <td><?php echo slv($row['shift_name'] ?? '-'); ?></td>
                            <td><?php echo slv($row['section_name'] ?? '-'); ?></td>
                            <td></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (!empty($row['photo_path'])): ?>
                                        <img src="../<?php echo slv($row['photo_path']); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-user text-secondary" style="font-size:22px;"></i>
                                    <?php endif; ?>
                                    <span><?php echo slv($row['name_en']); ?></span>
                                </div>
                            </td>
                            <td><?php echo slv($row['student_id']); ?></td>
                            <td><?php echo slv($row['roll_no']); ?></td>
                            <td><?php echo slv($row['device_serial']); ?></td>
                            <td><?php echo $row['tracking_id'] ? slv($row['tracking_id']) : '<span class="text-danger fst-italic">(not set)</span>'; ?></td>
                            <td><?php echo slv($row['guardian_phone']); ?></td>
                            <td><?php echo slv($row['dob']); ?></td>
                            <td><?php
                                $status_badges = ['Active' => 'success', 'Pending' => 'warning', 'Transfer' => 'info', 'Inactive' => 'secondary'];
                                $badge_class = $status_badges[$row['status']] ?? 'secondary';
                                echo '<span class="badge bg-' . $badge_class . '">' . slv($row['status']) . '</span>';
                            ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=print_admission_form&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Print"><i class="fa-solid fa-print"></i></a>
                                <a href="index.php?page=student_admission&view=view&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fa-solid fa-eye"></i></a>
                                <a href="index.php?page=student_admission&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=<?php echo $list_page_slug; ?>', 'delete_student', <?php echo (int) $row['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="16" class="text-center text-muted py-3">No students found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
        <form method="GET" action="index.php" class="d-flex align-items-center gap-2 mb-0">
            <?php foreach ($_GET as $gk => $gv): if (in_array($gk, ['per', 'p'], true) || is_array($gv)) continue; ?>
                <input type="hidden" name="<?php echo slv($gk); ?>" value="<?php echo slv($gv); ?>">
            <?php endforeach; ?>
            <label class="small text-muted mb-0">Per page</label>
            <select name="per" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <?php foreach ($per_page_opts as $po): ?><option value="<?php echo $po; ?>" <?php echo $po === $per_page ? 'selected' : ''; ?>><?php echo $po; ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if ($total_pages > 1):
            $win_s = max(1, $cur_page - 2); $win_e = min($total_pages, $cur_page + 2); ?>
        <nav aria-label="Student pages">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $cur_page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo stl_page_url(1); ?>">&laquo;</a></li>
                <li class="page-item <?php echo $cur_page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo stl_page_url(max(1, $cur_page - 1)); ?>">Previous</a></li>
                <?php if ($win_s > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php for ($i = $win_s; $i <= $win_e; $i++): ?>
                    <li class="page-item <?php echo $i === $cur_page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo stl_page_url($i); ?>"><?php echo $i; ?></a></li>
                <?php endfor; ?>
                <?php if ($win_e < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item <?php echo $cur_page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo stl_page_url(min($total_pages, $cur_page + 1)); ?>">Next</a></li>
                <li class="page-item <?php echo $cur_page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo stl_page_url($total_pages); ?>">&raquo;</a></li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.6.0/air-datepicker.js"></script>
<script>
    // Date of Birth ফিল্টারেও একই ভালো ক্যালেন্ডার (Student Admission ফর্মের
    // মতোই) — ব্রাউজারের ডিফল্ট date picker-এর বদলে
    (function () {
        const el = document.getElementById('listDobFilter');
        if (!el || typeof AirDatepicker === 'undefined') return;
        const enLocale = {
            days: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            daysShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            daysMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            monthsShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            today: 'Today', clear: 'Clear', dateFormat: 'yyyy-MM-dd', timeFormat: 'hh:mm aa', firstDay: 0,
        };
        new AirDatepicker('#listDobFilter', {
            locale: enLocale,
            dateFormat: 'yyyy-MM-dd',
            maxDate: new Date(),
            startDate: el.value ? new Date(el.value) : new Date(2015, 0, 1),
            autoClose: true,
        });
    })();

// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না,
// ১১ ডিজিটের বেশি হলে কেটে দেয়, ১১ ডিজিট না হওয়া পর্যন্ত নিচে লাল মেসেজ দেখায় (রিলোড ছাড়া),
// ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে সরে যায়
document.querySelectorAll('.phone-11').forEach(function (input) {
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }
    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
