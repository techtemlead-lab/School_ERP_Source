<?php
// admin/attendance_absent_list.php - Attendance Module > Student Attendance > Absent Student List (স্ক্রিনশট অনুযায়ী)
// একটা স্টুডেন্ট "Absent" ধরা হয় যদি সেদিন তার কোনো ডিভাইস IN পাঞ্চ না থাকে
// এবং ম্যানুয়াল অ্যাটেন্ডেন্সেও তাকে Present মার্ক করা না হয়ে থাকে।

require_once __DIR__ . '/../config/sms.php';
sms_ensure_schema($pdo);

function al_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// student_daily_attendance টেবিল না থাকলে (Manual Attendance পেজ কখনো ভিজিট করা না হলে) তৈরি করে নেওয়া
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_daily_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NULL,
        section_id INT NULL,
        shift_id INT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('Present','Absent') NOT NULL DEFAULT 'Absent',
        marked_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_date (student_id, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// SMS পাঠানোর লগ রাখার টেবিল
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        phone VARCHAR(20) NOT NULL,
        purpose VARCHAR(50) NOT NULL DEFAULT 'absent',
        message TEXT NULL,
        status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
        response TEXT NULL,
        sent_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$al_msg = $_SESSION['al_msg'] ?? '';
$al_err = $_SESSION['al_err'] ?? '';
unset($_SESSION['al_msg'], $_SESSION['al_err']);

// -----------------------------------------------------------
// Send SMS Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['al_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $ids = array_filter(array_map('intval', (array) ($_POST['student_ids'] ?? [])));
        if (!$ids) {
            $_SESSION['al_err'] = 'কোনো শিক্ষার্থী সিলেক্ট করা হয়নি।';
        } else {
            $tpl_row = $pdo->query("SELECT content_absent FROM sms_settings WHERE id = 1")->fetch();
            $template = $tpl_row['content_absent'] ?? 'Dear guardian, {FIRST_NAME} {LAST_NAME} is absent on {SCHOOL_NAME} today.';
            $school = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetch()['institute_name'] ?? '';

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name_en, guardian_phone FROM students WHERE id IN ($in)");
            $stmt->execute($ids);
            $target_students = $stmt->fetchAll();

            $log_stmt = $pdo->prepare(
                "INSERT INTO sms_logs (student_id, phone, purpose, message, status, response, sent_by) VALUES (?, ?, 'absent', ?, ?, ?, ?)"
            );
            $sent_by = $_SESSION['admin_user'] ?? 'admin';
            $sent = 0; $failed = 0;

            foreach ($target_students as $stu) {
                if (empty($stu['guardian_phone'])) { $failed++; continue; }
                $name_parts = explode(' ', trim($stu['name_en']), 2);
                $message = sms_apply_template($template, [
                    'FIRST_NAME'  => $name_parts[0] ?? '',
                    'LAST_NAME'   => $name_parts[1] ?? '',
                    'SCHOOL_NAME' => $school,
                ]);
                $result = send_sms($pdo, $stu['guardian_phone'], $message);
                $log_stmt->execute([$stu['id'], $stu['guardian_phone'], $message, $result['success'] ? 'Sent' : 'Failed', $result['response'], $sent_by]);
                $result['success'] ? $sent++ : $failed++;
            }

            if ($sent > 0) {
                sec_log_activity($pdo, 'Attendance', 'Create', "Absent শিক্ষার্থীদের অভিভাবকদের SMS পাঠানো হয়েছে: {$sent} জন সফল" . ($failed ? ", {$failed} জন ব্যর্থ" : ''));
                $_SESSION['al_msg'] = "{$sent} জনকে SMS পাঠানো হয়েছে।" . ($failed ? " {$failed} জনকে পাঠানো যায়নি।" : '');
            } else {
                $_SESSION['al_err'] = "কোনো SMS পাঠানো যায়নি। ({$failed} জন ফেইল) — SMS গেটওয়ে কনফিগার করা আছে কিনা config/sms.php ফাইলে চেক করুন।";
            }
        }
    }
    header('Location: index.php?page=attendance_absent_list' . (isset($_GET['class_id']) ? '&class_id=' . (int) $_GET['class_id'] : ''));
    exit();
}

// -----------------------------------------------------------
// Data: আজকের Absent স্টুডেন্ট লিস্ট, প্রতি ক্লাসের কাউন্টসহ
// -----------------------------------------------------------
$today = date('Y-m-d');
$f_class = (int) ($_GET['class_id'] ?? 0);
$f_q = trim($_GET['q'] ?? '');

// একটা স্টুডেন্ট "Present" ধরা হবে যদি আজকের ডিভাইস IN পাঞ্চ থাকে অথবা ম্যানুয়ালি Present মার্ক করা থাকে
$present_condition = "
    NOT EXISTS (
        SELECT 1 FROM device_attendance_logs dl
        WHERE dl.person_type = 'student' AND dl.punch_type = 'in' AND DATE(dl.punch_time) = ?
          AND (dl.person_uid = s.rfid_card_no OR dl.person_uid = s.student_id OR dl.person_uid = s.tracking_id OR dl.person_uid = s.device_serial)
    )
    AND NOT EXISTS (
        SELECT 1 FROM student_daily_attendance sda
        WHERE sda.student_id = s.id AND sda.attendance_date = ? AND sda.status = 'Present'
    )
";

// প্রতি ক্লাসের Absent কাউন্ট (বাম পাশের সাইডবারের জন্য)
$class_counts = [];
try {
    $bf_where = []; $bf_params = [];
    branch_apply_filter($bf_where, $bf_params, 's.branch_id');
    $branch_join_cond = $bf_where ? (' AND ' . implode(' AND ', $bf_where)) : '';
    $sql_counts = "SELECT ac.id, ac.class_name, COUNT(s.id) AS absent_count
        FROM academic_classes ac
        LEFT JOIN students s ON s.class_id = ac.id AND s.is_current = 1 AND $present_condition$branch_join_cond
        WHERE ac.status = 'Active'
        GROUP BY ac.id, ac.class_name
        ORDER BY ac.rank ASC, ac.class_name ASC";
    $stmt = $pdo->prepare($sql_counts);
    $stmt->execute(array_merge([$today, $today], $bf_params));
    $class_counts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('attendance_absent_list class_counts failed: ' . $e->getMessage());
}

$grand_total = array_sum(array_column($class_counts, 'absent_count'));

// সিলেক্টেড ক্লাসের (অথবা প্রথম ক্লাসের) Absent লিস্ট
if ($f_class <= 0 && $class_counts) { $f_class = (int) $class_counts[0]['id']; }

$absent_students = [];
if ($f_class > 0) {
    try {
        $sql = "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, s.guardian_phone,
                       ash.shift_name, asec.section_name
                FROM students s
                LEFT JOIN academic_shifts ash ON ash.id = s.shift_id
                LEFT JOIN academic_sections asec ON asec.id = s.section_id
                WHERE s.class_id = ? AND s.is_current = 1 AND $present_condition";
        $params = [$f_class, $today, $today];
        $al_bf_where = []; $al_bf_params = [];
        branch_apply_filter($al_bf_where, $al_bf_params, 's.branch_id');
        if ($al_bf_where) { $sql .= ' AND ' . implode(' AND ', $al_bf_where); $params = array_merge($params, $al_bf_params); }
        if ($f_q !== '') {
            $sql .= " AND (s.name_en LIKE ? OR s.roll_no LIKE ? OR s.student_id LIKE ?)";
            $like = '%' . $f_q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $sql .= " ORDER BY (s.roll_no + 0) ASC, s.roll_no ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $absent_students = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('attendance_absent_list students failed: ' . $e->getMessage());
    }
}

$display_date = date('F j, Y', strtotime($today));
?>
<style>
    .absent-class-item {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 16px; border-left: 4px solid transparent; cursor: pointer;
        text-decoration: none; color: #334155; border-bottom: 1px solid #f1f5f9;
    }
    .absent-class-item:hover { background: #f8fafc; }
    .absent-class-item.active { border-left-color: #ef4444; background: #fef2f2; color: #0f172a; font-weight: 700; }
    .absent-count-badge {
        background: #ef4444; color: #fff; font-size: 11.5px; font-weight: 700;
        border-radius: 20px; padding: 2px 9px; min-width: 26px; text-align: center;
    }
    .absent-count-badge.zero { background: #cbd5e1; color: #475569; }
</style>

<?php if ($al_msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-check-circle me-2"></i> <?php echo al_esc($al_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($al_err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo al_esc($al_err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold text-dark m-0">
            <i class="fa-solid fa-user-xmark text-danger"></i>
            Today Students Absent <span class="text-danger">(<?php echo al_esc($display_date); ?>)</span>
        </h6>
        <form method="GET" class="flex-grow-1 mx-3" style="max-width:400px;">
            <input type="hidden" name="page" value="attendance_absent_list">
            <input type="hidden" name="class_id" value="<?php echo (int) $f_class; ?>">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by ID, Roll or Name" value="<?php echo al_esc($f_q); ?>">
        </form>
        <div class="fw-bold text-danger fs-6">Total : <?php echo (int) $grand_total; ?></div>
    </div>

    <div class="row g-0 border rounded overflow-hidden">
        <!-- Left: Class-wise list -->
        <div class="col-md-3 border-end" style="background:#fff;">
            <?php if ($class_counts): foreach ($class_counts as $c): ?>
                <a href="index.php?page=attendance_absent_list&class_id=<?php echo (int) $c['id']; ?>"
                   class="absent-class-item <?php echo (int) $c['id'] === $f_class ? 'active' : ''; ?>">
                    <span><?php echo al_esc($c['class_name']); ?> <span class="text-muted small">(Combined)</span></span>
                    <span class="absent-count-badge <?php echo (int) $c['absent_count'] === 0 ? 'zero' : ''; ?>"><?php echo (int) $c['absent_count']; ?></span>
                </a>
            <?php endforeach; else: ?>
                <div class="p-3 text-muted small">কোনো ক্লাস পাওয়া যায়নি</div>
            <?php endif; ?>
        </div>

        <!-- Right: Absent students table -->
        <div class="col-md-9">
            <form method="POST" action="index.php?page=attendance_absent_list&class_id=<?php echo (int) $f_class; ?>" id="smsForm">
                <?php echo csrf_field(); ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle m-0" style="font-size: 13.5px;">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="checkAll" onclick="toggleAllChecks(this)"></th>
                                <th>ID</th>
                                <th style="width:70px;">Roll</th>
                                <th>Shift</th>
                                <th>Section</th>
                                <th>Name</th>
                                <th style="width:150px;">
                                    <button type="submit" name="send_sms" class="btn btn-success btn-sm fw-bold w-100" onclick="return checkAllBeforeSubmit()">
                                        <i class="fa-solid fa-paper-plane"></i> Send SMS(All)
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($absent_students): foreach ($absent_students as $st): ?>
                                <tr>
                                    <td><input type="checkbox" name="student_ids[]" value="<?php echo (int) $st['id']; ?>" class="row-check" <?php echo empty($st['guardian_phone']) ? 'disabled' : ''; ?>></td>
                                    <td><?php echo al_esc($st['student_id']); ?></td>
                                    <td><?php echo al_esc($st['roll_no']); ?></td>
                                    <td><?php echo al_esc($st['shift_name'] ?: '-'); ?></td>
                                    <td><?php echo al_esc($st['section_name'] ?: '-'); ?></td>
                                    <td>
                                        <img src="<?php echo $st['photo_path'] ? '../' . al_esc($st['photo_path']) : 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2728%27 height=%2728%27%3E%3Crect width=%2728%27 height=%2728%27 fill=%27%23e2e8f0%27/%3E%3C/svg%3E'; ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:8px;border:1px solid #e2e8f0;">
                                        <?php echo al_esc($st['name_en']); ?>
                                    </td>
                                    <td>
                                        <button type="submit" name="send_sms" class="btn btn-primary btn-sm w-100 fw-bold"
                                            onclick="return selectOnly(this, <?php echo (int) $st['id']; ?>)"
                                            <?php echo empty($st['guardian_phone']) ? 'disabled title="Guardian মোবাইল নেই"' : ''; ?>>
                                            Send SMS
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">
                                    <i class="fa-solid fa-circle-check text-success"></i> আজকে এই ক্লাসে কোনো শিক্ষার্থী Absent নেই
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleAllChecks(master) {
    document.querySelectorAll('.row-check').forEach(function (c) { c.checked = master.checked; });
}
function checkAllBeforeSubmit() {
    var checks = document.querySelectorAll('.row-check:not(:disabled)');
    if (!checks.length) { alert('পাঠানোর মতো কোনো শিক্ষার্থী নেই।'); return false; }
    checks.forEach(function (c) { c.checked = true; });
    return confirm(checks.length + ' জন অভিভাবককে SMS পাঠাতে চান?');
}
function selectOnly(btn, id) {
    document.querySelectorAll('.row-check').forEach(function (c) { c.checked = (parseInt(c.value) === id); });
    return confirm('এই শিক্ষার্থীর অভিভাবককে SMS পাঠাতে চান?');
}
</script>
