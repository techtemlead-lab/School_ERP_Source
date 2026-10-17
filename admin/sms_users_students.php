<?php
// admin/sms_users_students.php - User Management > SMS User (Students)
// Session/Class/Section/Shift/Student ID দিয়ে ছাত্রছাত্রী খুঁজে তাদের Login Password
// রিসেট (ডিফল্ট = Student ID) করা এবং সেই তথ্য অভিভাবকের নাম্বারে (guardian_phone) SMS
// করে পাঠানো যাবে। ডিজাইন রেফারেন্স: অন্য একটা সফটওয়্যারের স্ক্রিনশট থেকে অনুপ্রাণিত।
// Super Admin এবং system_users এর 'Admin' টাইপ — দুজনেই এই পেজ ব্যবহার করতে পারবে।

require_once __DIR__ . '/../config/sms.php'; // send_sms(), sms_get_gateway_settings()

$is_super_admin = $_SESSION['is_super_admin'] ?? true;
$is_admin_type  = ($_SESSION['user_role'] ?? '') === 'Admin';
if (!$is_super_admin && !$is_admin_type) {
    echo '<div class="card-custom"><div class="alert alert-danger mb-0"><i class="fa-solid fa-lock me-2"></i>শুধুমাত্র <b>Super Admin</b> অথবা <b>Admin</b> এই পেজ অ্যাক্সেস করতে পারবেন।</div></div>';
    return;
}

// students.password / must_change_password কলাম না থাকলে (কখনো কোনো স্টুডেন্ট পোর্টাল
// ব্যবহার না করলে থাকবে না) নিরাপদে যোগ করা হচ্ছে — student/_bootstrap.php তেও একই ensure আছে
function ssv_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
ssv_ensure_column($pdo, 'students', 'password', 'VARCHAR(255) NULL');
ssv_ensure_column($pdo, 'students', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 1');

function ssv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$ss_err = $_SESSION['ss_err'] ?? '';
$ss_msg = $_SESSION['ss_msg'] ?? '';
unset($_SESSION['ss_err'], $_SESSION['ss_msg']);

// -----------------------------------------------------------
// POST: Password Reset (একজন) / SMS পাঠানো (একজন বা বাল্ক)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ss_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=sms_users_students&' . ($_POST['redirect_qs'] ?? ''));
        exit();
    }

    if (isset($_POST['reset_password'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $reset_stu_name = $pdo->prepare("SELECT name_en FROM students WHERE id = ?");
        $reset_stu_name->execute([$id]);
        $reset_stu_name_val = $reset_stu_name->fetchColumn();
        require_once __DIR__ . '/../config/default_password.php';
        $pdo->prepare("UPDATE students SET password = NULL, must_change_password = 1 WHERE id = ?")->execute([$id]);
        $_SESSION['ss_msg'] = 'পাসওয়ার্ড ডিফল্টে (' . erp_default_password() . ') রিসেট করা হয়েছে। এখন এই পাসওয়ার্ড দিয়ে লগইন করা যাবে; লগইনের পর পাসওয়ার্ড বদলাতে হবে।';
        sec_log_activity($pdo, 'SMS', 'Update', "Student পাসওয়ার্ড রিসেট করা হয়েছে: " . ($reset_stu_name_val ?: "#$id"));
    } elseif (isset($_POST['send_sms']) || isset($_POST['send_sms_all'])) {
        if (isset($_POST['send_sms_all'])) {
            // ফিল্টারে থাকা সবাইকে পাঠানো হবে — সেশন/ক্লাস আবশ্যক
            $p_session = (int) ($_POST['p_session'] ?? 0);
            $p_class   = (int) ($_POST['p_class'] ?? 0);
            $p_section = (int) ($_POST['p_section'] ?? 0);
            $p_shift   = (int) ($_POST['p_shift'] ?? 0);
            $p_sid     = trim($_POST['p_sid'] ?? '');

            $where = ['students.session_id = ?', 'students.class_id = ?', "students.status = 'Active'"];
            $params = [$p_session, $p_class];
            if ($p_section > 0) { $where[] = 'students.section_id = ?'; $params[] = $p_section; }
            if ($p_shift > 0)   { $where[] = 'students.shift_id = ?'; $params[] = $p_shift; }
            if ($p_sid !== '')  { $where[] = '(students.student_id LIKE ? OR students.name_en LIKE ?)'; $params[] = "%$p_sid%"; $params[] = "%$p_sid%"; }
            branch_apply_filter($where, $params, 'students.branch_id');

            $stmt = $pdo->prepare("SELECT id FROM students WHERE " . implode(' AND ', $where) . " LIMIT 500");
            $stmt->execute($params);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $ids = $_POST['ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
        }

        if (empty($ids)) {
            $_SESSION['ss_err'] = isset($_POST['send_sms_all'])
                ? 'পাঠানোর জন্য এই ফিল্টারে কোনো স্টুডেন্ট পাওয়া যায়নি।'
                : 'কমপক্ষে একজন স্টুডেন্ট সিলেক্ট করুন (চেকবক্স টিক দিয়ে)।';
        } else {
            $inst = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch();
            $inst_name = $inst['institute_name'] ?? ($GLOBALS['tenant']['school_name'] ?? 'School');

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, student_id, name_en, guardian_phone, password, must_change_password FROM students WHERE id IN ($in)");
            $stmt->execute($ids);
            $students = $stmt->fetchAll();

            require_once __DIR__ . '/../config/default_password.php';
            $sent = 0; $failed = 0; $skipped = 0;
            foreach ($students as $s) {
                if (empty($s['guardian_phone'])) { $failed++; continue; }
                // যে ইউজার নিজে পাসওয়ার্ড বদলে ফেলেছে তার পাসওয়ার্ড বদলানো হবে না (Reset বাটন ব্যবহার করুন)
                if (!empty($s['password']) && empty($s['must_change_password'])) { $skipped++; continue; }
                // নিজের পাসওয়ার্ড সেট না করা ইউজার (বা আগে অস্থায়ী পাসওয়ার্ড পাওয়া) → ডিফল্টে ফিরিয়ে SMS
                $pdo->prepare("UPDATE students SET password = NULL, must_change_password = 1 WHERE id = ?")->execute([(int) $s['id']]);
                $temp_pw = erp_default_password();
                $message = "{$inst_name}: প্রিয় অভিভাবক, {$s['name_en']} (ID: {$s['student_id']})-এর লগইন তথ্য — Student ID: {$s['student_id']}, Password: {$temp_pw}। প্রথমবার লগইন করে পাসওয়ার্ড বদলে নিন।";
                $res = send_sms($pdo, $s['guardian_phone'], $message);
                if ($res['success']) { $sent++; } else { $failed++; }
            }
            $_SESSION['ss_msg'] = "{$sent} জনকে SMS পাঠানো হয়েছে।" . ($failed > 0 ? " {$failed} জনের ক্ষেত্রে ব্যর্থ হয়েছে (নম্বর নাই বা গেটওয়ে সমস্যা)।" : '') . ($skipped > 0 ? " {$skipped} জন নিজের পাসওয়ার্ড সেট করে ফেলেছেন, তাই বাদ গেছে।" : '');
            sec_log_activity($pdo, 'SMS', 'Send', "{$sent} জন Student এর অভিভাবককে Login SMS পাঠানো হয়েছে" . ($failed > 0 ? ", {$failed} জন ব্যর্থ" : ''));
        }
    }
    header('Location: index.php?page=sms_users_students&' . ($_POST['redirect_qs'] ?? ''));
    exit();
}

// -----------------------------------------------------------
// ফিল্টার অপশন লোড
// -----------------------------------------------------------
$sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$classes  = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();
$sections = $pdo->query("SELECT id, section_name, class_id FROM academic_sections ORDER BY section_name ASC")->fetchAll();
$shifts   = $pdo->query("SELECT id, shift_name FROM academic_shifts ORDER BY shift_name ASC")->fetchAll();

$f_session = $_GET['f_session'] ?? '';
$f_class   = $_GET['f_class'] ?? '';
$f_shift   = $_GET['f_shift'] ?? '';
$f_section = $_GET['f_section'] ?? '';
$f_sid     = trim($_GET['f_sid'] ?? '');
$searched  = isset($_GET['do_search']);

$rows = [];
$session_name = $class_name = $section_name = '';
if ($searched && $f_session !== '' && $f_class !== '') {
    $where = ['students.session_id = ?', 'students.class_id = ?', "students.status = 'Active'"];
    $params = [(int) $f_session, (int) $f_class];
    if ($f_section !== '') { $where[] = 'students.section_id = ?'; $params[] = (int) $f_section; }
    if ($f_shift !== '')   { $where[] = 'students.shift_id = ?'; $params[] = (int) $f_shift; }
    if ($f_sid !== '')     { $where[] = '(students.student_id LIKE ? OR students.name_en LIKE ?)'; $params[] = "%$f_sid%"; $params[] = "%$f_sid%"; }
    branch_apply_filter($where, $params, 'students.branch_id');

    $sql = "SELECT students.*, academic_sections.section_name
            FROM students
            LEFT JOIN academic_sections ON academic_sections.id = students.section_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY students.roll_no ASC, students.name_en ASC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($sessions as $s) { if ((int) $s['id'] === (int) $f_session) { $session_name = $s['session_name']; break; } }
    foreach ($classes as $c) { if ((int) $c['id'] === (int) $f_class) { $class_name = $c['class_name']; break; } }
    if ($f_section !== '') { foreach ($sections as $sec) { if ((int) $sec['id'] === (int) $f_section) { $section_name = $sec['section_name']; break; } } }
}

$inst = $pdo->query("SELECT institute_name, phone_number FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '']; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$site_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
$csrf = htmlspecialchars(csrf_token());
$redirect_qs_raw = http_build_query($_GET);
$redirect_qs = ssv($redirect_qs_raw);
?>

<div class="card-custom mb-3">
    <?php if ($ss_msg): ?><div class="alert alert-success py-2"><?php echo ssv($ss_msg); ?></div><?php endif; ?>
    <?php if ($ss_err): ?><div class="alert alert-danger py-2"><?php echo ssv($ss_err); ?></div><?php endif; ?>

    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="sms_users_students">
        <input type="hidden" name="do_search" value="1">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                <select name="f_session" class="form-select" required>
                    <option value="">Select Session</option>
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php echo (string) $f_session === (string) $s['id'] ? 'selected' : ''; ?>><?php echo ssv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                <select name="f_class" class="form-select" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_class === (string) $c['id'] ? 'selected' : ''; ?>><?php echo ssv($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Shift</label>
                <select name="f_shift" class="form-select">
                    <option value="">Select Shift</option>
                    <?php foreach ($shifts as $sh): ?>
                        <option value="<?php echo (int) $sh['id']; ?>" <?php echo (string) $f_shift === (string) $sh['id'] ? 'selected' : ''; ?>><?php echo ssv($sh['shift_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Section</label>
                <select name="f_section" class="form-select">
                    <option value="">Select Section</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int) $sec['id']; ?>" <?php echo (string) $f_section === (string) $sec['id'] ? 'selected' : ''; ?>><?php echo ssv($sec['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Student ID / Name</label>
                <input type="text" name="f_sid" class="form-control" value="<?php echo ssv($f_sid); ?>" placeholder="Student ID অথবা নাম দিয়ে খুঁজুন">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Search</button>
            </div>
        </div>
    </form>
</div>

<?php if ($searched): ?>
    <?php if ($f_session === '' || $f_class === ''): ?>
        <div class="card-custom"><div class="alert alert-warning mb-0">Session ও Class সিলেক্ট করা আবশ্যক।</div></div>
    <?php else: ?>
        <div class="card-custom">
            <div class="text-center mb-3 pb-3 border-bottom">
                <h5 class="fw-bold text-primary mb-1"><?php echo ssv($inst['institute_name']); ?></h5>
                <?php if ($branch && !empty($branch['address'])): ?>
                    <div class="small text-muted">(<?php echo ssv($branch['address']); ?><?php echo $branch['city'] ? ', ' . ssv($branch['city']) : ''; ?>)</div>
                <?php endif; ?>
                <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
                    <div class="small">Phone: <?php echo ssv($inst['phone_number'] ?: $branch['phone']); ?></div>
                <?php endif; ?>
                <div class="small"><a href="<?php echo ssv($site_url); ?>" target="_blank"><?php echo ssv($site_url); ?></a></div>
                <h6 class="fw-bold mt-3 mb-0">Student's List for SMS Password of Session <?php echo ssv($session_name); ?></h6>
                <div class="text-muted small"><?php echo ssv($class_name); ?><?php echo $section_name !== '' ? ' - ' . ssv($section_name) : ' (Combined)'; ?></div>
            </div>

            <!-- Change Password / Send SMS বাটন দুটো আগে "form" attribute দিয়ে টেবিলের ভেতরে বসানো
                 নিজস্ব ছোট্ট &lt;form id="ssResetFormX"&gt;/&lt;form id="ssSmsOneFormX"&gt; কে
                 টার্গেট করত। কিন্তু সেই ফর্মগুলো এই বড় smsStudentsForm-এর ভেতরেই বসানো ছিল —
                 ফর্মের ভেতরে ফর্ম, HTML-এ যা বৈধ না। ব্রাউজার নেস্টেড ফর্মটা বাতিল করে দিত, ফলে
                 ওই id-টাই আসলে DOM-এ থাকত না, আর বাটনের form="..." রেফারেন্স কোথাও পৌঁছাত না —
                 ক্লিক করলে কিছুই হতো না। সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, smsStudentsForm-এর
                 বাইরে। -->
            <form method="POST" id="ssRowActionForm" style="display:none">
                <input type="hidden" name="csrf_token" id="ssRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            </form>
            <script>
            function ssRowAction(url, fields, confirmMsg) {
                if (confirmMsg && !window.confirm(confirmMsg)) return false;
                var f = document.getElementById('ssRowActionForm');
                f.setAttribute('action', url);
                var old = f.querySelectorAll('input[data-dyn-field]');
                old.forEach(function (el) { el.parentNode.removeChild(el); });
                Object.keys(fields).forEach(function (k) {
                    var el = document.createElement('input');
                    el.type = 'hidden'; el.name = k; el.value = fields[k]; el.setAttribute('data-dyn-field', '1');
                    f.appendChild(el);
                });
                f.submit();
                return false;
            }
            </script>

            <form method="POST" action="index.php?page=sms_users_students" id="smsStudentsForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="redirect_qs" value="<?php echo $redirect_qs; ?>">
                <input type="hidden" name="p_session" value="<?php echo (int) $f_session; ?>">
                <input type="hidden" name="p_class" value="<?php echo (int) $f_class; ?>">
                <input type="hidden" name="p_section" value="<?php echo (int) $f_section; ?>">
                <input type="hidden" name="p_shift" value="<?php echo (int) $f_shift; ?>">
                <input type="hidden" name="p_sid" value="<?php echo ssv($f_sid); ?>">
                <div class="d-flex justify-content-end gap-2 mb-2">
                    <button type="submit" name="send_sms" class="btn btn-outline-success btn-sm fw-bold" onclick="return confirm('সিলেক্ট করা স্টুডেন্টদের SMS পাঠানো হবে, নিশ্চিত?');">
                        <i class="fa-solid fa-check-double me-1"></i> Send SMS (Selected)
                    </button>
                    <button type="submit" name="send_sms_all" class="btn btn-success btn-sm fw-bold" onclick="return confirm('এই তালিকার সবাইকে SMS পাঠানো হবে, নিশ্চিত?');">
                        <i class="fa-solid fa-paper-plane me-1"></i> Send SMS (All)
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle" style="font-size:13px;">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.ss-chk').forEach(c=>c.checked=this.checked)"></th>
                                <th>ID / Username</th>
                                <th>Name</th>
                                <th>Roll No.</th>
                                <th style="width:230px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): foreach ($rows as $row): ?>
                                <tr>
                                    <td><input type="checkbox" class="ss-chk" name="ids[]" value="<?php echo (int) $row['id']; ?>" form="smsStudentsForm"></td>
                                    <td><?php echo ssv($row['student_id']); ?></td>
                                    <td><?php echo ssv($row['name_en']); ?></td>
                                    <td><?php echo ssv($row['roll_no']); ?></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="return ssRowAction('index.php?page=sms_users_students', {id: <?php echo (int) $row['id']; ?>, redirect_qs: <?php echo htmlspecialchars(json_encode($redirect_qs_raw), ENT_QUOTES, 'UTF-8'); ?>, reset_password: '1'}, <?php echo htmlspecialchars(json_encode($row['name_en'] . '-এর পাসওয়ার্ড ডিফল্টে (123456) রিসেট হবে, নিশ্চিত?'), ENT_QUOTES, 'UTF-8'); ?>);">Change Password</button>
                                        <button type="button" class="btn btn-sm btn-success" onclick="return ssRowAction('index.php?page=sms_users_students', {'ids[]': <?php echo (int) $row['id']; ?>, redirect_qs: <?php echo htmlspecialchars(json_encode($redirect_qs_raw), ENT_QUOTES, 'UTF-8'); ?>, send_sms: '1'}, null);">Send SMS</button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">কোনো ছাত্রছাত্রী পাওয়া যায়নি</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>
