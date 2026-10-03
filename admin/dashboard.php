<?php
require_once __DIR__ . '/../config/sms.php';
sms_ensure_schema($pdo);

// টেবিল না থাকলে (ফিচার পেজ এখনো ভিজিট করা হয়নি) ড্যাশবোর্ড যেন এরর না দেয়,
// তাই প্রতিটা কাউন্ট নিরাপদভাবে (0 ডিফল্ট) রান করা হচ্ছে
function db_safe_scalar(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val !== false && $val !== null ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

$current_session = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;

// Dashboard-এর কাউন্টগুলো এতদিন branch অনুযায়ী ফিল্টার হতো না — তাই Super Admin
// একটা নির্দিষ্ট Branch-এ থাকলেও Dashboard সব Branch মিলিয়ে টোটাল দেখাতো, যেখানে
// Current Student List ঠিকই branch অনুযায়ী ফিল্টার করে দেখাতো — ফলে দুই জায়গায়
// সংখ্যা না মেলার bug হতো। এখন থেকে Dashboard-ও একই branch scoping মেনে চলবে।
$dash_where = [];
$dash_params = [];
if ($current_session_id) { $dash_where[] = 'session_id = ?'; $dash_params[] = $current_session_id; }
branch_apply_filter($dash_where, $dash_params, 'branch_id');
$dash_where_sql = $dash_where ? ' WHERE ' . implode(' AND ', $dash_where) : '';

$total_apps_stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM students$dash_where_sql");
$total_apps_stmt->execute($dash_params);
$total_apps = (int) $total_apps_stmt->fetch()['cnt'];

$pending_where = ["status = 'Pending'"];
$pending_params = [];
branch_apply_filter($pending_where, $pending_params, 'branch_id');
$pending_stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM students WHERE " . implode(' AND ', $pending_where));
$pending_stmt->execute($pending_params);
$pending_apps = (int) $pending_stmt->fetch()['cnt'];

// TOP STATS
function dash_student_count(PDO $pdo, string $extra_condition, ?int $session_id): int
{
    $where = [$extra_condition];
    $params = [];
    if ($session_id) { $where[] = 'session_id = ?'; $params[] = $session_id; }
    branch_apply_filter($where, $params, 'branch_id');
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
$active_male   = dash_student_count($pdo, "status = 'Active' AND gender = 'Male'", $current_session_id);
$active_female = dash_student_count($pdo, "status = 'Active' AND gender = 'Female'", $current_session_id);
$active_total  = dash_student_count($pdo, "status = 'Active'", $current_session_id);

$staff_where = ["status = 'Active'"];
$staff_params = [];
branch_apply_filter($staff_where, $staff_params, 'branch_id');
try {
    $staff_stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE " . implode(' AND ', $staff_where));
    $staff_stmt->execute($staff_params);
    $total_staff = (int) $staff_stmt->fetchColumn();
} catch (PDOException $e) {
    $total_staff = 0;
}

// SIDE STATS
$sms_sent        = (int) db_safe_scalar($pdo, "SELECT COUNT(*) FROM sms_logs WHERE status = 'Sent'");
$today_absent    = (int) db_safe_scalar($pdo, "SELECT COUNT(*) FROM student_daily_attendance WHERE attendance_date = CURDATE() AND status = 'Absent'");
$today_collection = (float) db_safe_scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM finance_fee_collections WHERE status = 'Paid' AND DATE(collected_at) = CURDATE()");
// এসএমএস ক্রয়/ব্যালেন্স ট্র্যাক করার মতো কোনো টেবিল এখনো সিস্টেমে নেই,
// তাই আপাতত sms_settings টেবিল থেকে (থাকলে) রিড করা হচ্ছে, না থাকলে 0
$sms_buy     = (int) db_safe_scalar($pdo, "SELECT COALESCE(sms_buy,0) FROM sms_settings WHERE id = 1");
$sms_balance = (int) db_safe_scalar($pdo, "SELECT COALESCE(sms_balance,0) FROM sms_settings WHERE id = 1");

// -----------------------------------------------------------
// আজকের Students/Staffs Attendance (dashboard widget) — student_daily_attendance /
// employee_daily_attendance টেবিল থেকে (এটাই ফাইনাল/প্রসেসড অ্যাটেন্ডেন্স টেবিল —
// ডিভাইস পাঞ্চ প্রসেস হলেও এখানে লেখে, আবার Manual Attendance থেকে হাতে মার্ক
// করলেও এখানেই লেখে) — তাই ডিভাইস ও ম্যানুয়াল দুই ধরনের এন্ট্রিই একসাথে দেখাবে।
// -----------------------------------------------------------
$dash_stu_att = [];
try {
    $dsa_where = ["sda.attendance_date = ?", "sda.status = 'Present'"];
    $dsa_params = [date('Y-m-d')];
    branch_apply_filter($dsa_where, $dsa_params, 's.branch_id');
    $dsa_sql = "SELECT s.id, s.student_id, s.roll_no, s.name_en, ac.class_name,
                       sda.check_in_time AS time_in, sda.check_out_time AS time_out,
                       sda.marked_by, zdev.device_name AS punch_zone
                FROM student_daily_attendance sda
                JOIN students s ON s.id = sda.student_id
                LEFT JOIN academic_classes ac ON ac.id = s.class_id
                LEFT JOIN device_attendance_logs dl
                       ON dl.person_type = 'student'
                      AND (dl.person_uid = s.rfid_card_no OR dl.person_uid = s.student_id OR dl.person_uid = s.tracking_id OR dl.person_uid = s.device_serial)
                      AND dl.punch_type = 'in'
                      AND dl.punch_time = sda.check_in_time
                LEFT JOIN attendance_devices zdev ON zdev.id = dl.device_id
                WHERE " . implode(' AND ', $dsa_where) . "
                GROUP BY s.id, sda.check_in_time, sda.check_out_time, sda.marked_by, zdev.device_name
                ORDER BY (sda.check_in_time IS NULL) ASC, sda.check_in_time DESC, sda.updated_at DESC
                LIMIT 5";
    $dsa_stmt = $pdo->prepare($dsa_sql);
    $dsa_stmt->execute($dsa_params);
    $dash_stu_att = $dsa_stmt->fetchAll();
} catch (PDOException $e) {
    // student_daily_attendance টেবিল এখনো তৈরি না থাকলে (কোনো অ্যাটেন্ডেন্স পেজ এখনো ভিজিট করা হয়নি) খালি লিস্ট দেখানো হবে
}

$dash_staff_att = [];
try {
    $dea_sql = "SELECT e.id, e.employee_id, e.name, dg.title AS designation_title,
                       eda.check_in_time AS time_in, eda.check_out_time AS time_out,
                       eda.marked_by, zdev.device_name AS punch_zone
                FROM employee_daily_attendance eda
                JOIN employees e ON e.id = eda.employee_id
                LEFT JOIN designations dg ON dg.id = e.designation_id
                LEFT JOIN device_attendance_logs dl
                       ON dl.person_type = 'employee'
                      AND (dl.person_uid = e.rfid_card_no OR dl.person_uid = e.employee_id OR dl.person_uid = e.tracking_id OR dl.person_uid = e.device_serial)
                      AND dl.punch_type = 'in'
                      AND dl.punch_time = eda.check_in_time
                LEFT JOIN attendance_devices zdev ON zdev.id = dl.device_id
                WHERE eda.attendance_date = ? AND eda.status = 'Present'
                GROUP BY e.id, eda.check_in_time, eda.check_out_time, eda.marked_by, zdev.device_name
                ORDER BY (eda.check_in_time IS NULL) ASC, eda.check_in_time DESC, eda.updated_at DESC
                LIMIT 5";
    $dea_stmt = $pdo->prepare($dea_sql);
    $dea_stmt->execute([date('Y-m-d')]);
    $dash_staff_att = $dea_stmt->fetchAll();
} catch (PDOException $e) {
    // employee_daily_attendance টেবিল এখনো তৈরি না থাকলে খালি লিস্ট দেখানো হবে
}

function dash_att_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<!-- TOP 4 STATS CARDS -->
<div class="row g-3 mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background: #3b5998;">
            <div>
                <h3><span style="color:#93c5fd;"><?php echo $active_male; ?></span><span style="opacity:.6;font-size:.9em;"> / </span><span style="color:#fbcfe8;"><?php echo $active_female; ?></span></h3>
                <p>Active Students (Male / Female)</p>
            </div>
            <div class="top-stat-icon"><i class="fa-solid fa-people-group"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background: #00bcd4;">
            <div><h3><?php echo $active_total; ?></h3><p>Total Active Students</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-user-graduate"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background: #1e293b;">
            <div><h3><?php echo $total_staff; ?></h3><p>Total Teachers & Staffs</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-wheelchair"></i></div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="top-stat-box" style="background: #10b981;">
            <div><h3><?php echo $total_apps; ?></h3><p>Total New Admission</p></div>
            <div class="top-stat-icon"><i class="fa-solid fa-user-plus"></i></div>
        </div>
    </div>
</div>

<!-- MAIN GRID -->
<div class="row">
    <div class="col-lg-8">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa-solid fa-user-check text-primary"></i> Today Students Attendance</h6>
                <a href="index.php?page=attendance_today" class="text-decoration-none small fw-bold">See All..</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered text-center align-middle m-0" style="font-size: 13.5px;">
                    <thead class="table-light"><tr><th>ID</th><th>Roll</th><th>Class</th><th>Name</th><th style="color: #10b981;">IN</th><th style="color: #ef4444;">OUT</th><th>Punch IN Zone</th></tr></thead>
                    <tbody>
                        <?php if ($dash_stu_att): foreach ($dash_stu_att as $r): ?>
                            <tr>
                                <td><?php echo dash_att_esc($r['student_id']); ?></td>
                                <td><?php echo dash_att_esc($r['roll_no']); ?></td>
                                <td><?php echo dash_att_esc($r['class_name']); ?></td>
                                <td class="text-start fw-semibold"><?php echo dash_att_esc($r['name_en']); ?></td>
                                <td class="fw-bold text-success">
                                    <?php if ($r['time_in']): ?>
                                        <?php echo dash_att_esc(date('h:i A', strtotime($r['time_in']))); ?>
                                    <?php else: ?>
                                        <span title="Manually marked present">Present</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold text-danger"><?php echo $r['time_out'] ? dash_att_esc(date('h:i A', strtotime($r['time_out']))) : '-'; ?></td>
                                <td><?php echo $r['punch_zone'] ? dash_att_esc($r['punch_zone']) : ($r['marked_by'] && $r['marked_by'] !== 'Device' ? '<span class="text-muted small">Manual (' . dash_att_esc($r['marked_by']) . ')</span>' : '<span class="text-muted">-</span>'); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa-solid fa-users-viewfinder text-primary"></i> Today Staffs Attendance</h6>
                <a href="index.php?page=employee_attendance_today" class="text-decoration-none small fw-bold">See All..</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered text-center align-middle m-0" style="font-size: 13.5px;">
                    <thead class="table-light"><tr><th>ID</th><th>Designation</th><th>Name</th><th style="color: #10b981;">IN</th><th style="color: #ef4444;">OUT</th><th>Punch IN Zone</th></tr></thead>
                    <tbody>
                        <?php if ($dash_staff_att): foreach ($dash_staff_att as $r): ?>
                            <tr>
                                <td><?php echo dash_att_esc($r['employee_id']); ?></td>
                                <td><?php echo $r['designation_title'] ? dash_att_esc($r['designation_title']) : '<span class="text-muted">-</span>'; ?></td>
                                <td class="text-start fw-semibold"><?php echo dash_att_esc($r['name']); ?></td>
                                <td class="fw-bold text-success">
                                    <?php if ($r['time_in']): ?>
                                        <?php echo dash_att_esc(date('h:i A', strtotime($r['time_in']))); ?>
                                    <?php else: ?>
                                        <span title="Manually marked present">Present</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold text-danger"><?php echo $r['time_out'] ? dash_att_esc(date('h:i A', strtotime($r['time_out']))) : '-'; ?></td>
                                <td><?php echo $r['punch_zone'] ? dash_att_esc($r['punch_zone']) : ($r['marked_by'] && $r['marked_by'] !== 'Device' ? '<span class="text-muted small">Manual (' . dash_att_esc($r['marked_by']) . ')</span>' : '<span class="text-muted">-</span>'); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-muted py-3">No data found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark m-0"><i class="fa-solid fa-user-clock text-warning"></i> Student Admission Pending (<span class="text-danger"><?php echo $pending_apps; ?></span>)</h5>
                <a href="index.php?page=current_student_list&f_status=Pending" class="btn btn-sm btn-outline-primary">View All & Manage</a>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle">
                    <thead class="table-light"><tr><th>Session</th><th>Class</th><th>ID</th><th>Roll</th><th>Name</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php
                        $pend_list_where = ["s.status = 'Pending'"];
                        $pend_list_params = [];
                        branch_apply_filter($pend_list_where, $pend_list_params, 's.branch_id');
                        $stmt = $pdo->prepare(
                            "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path, ses.session_name, c.class_name
                             FROM students s
                             LEFT JOIN academic_sessions ses ON ses.id = s.session_id
                             LEFT JOIN academic_classes c ON c.id = s.class_id
                             WHERE " . implode(' AND ', $pend_list_where) . " ORDER BY s.id DESC LIMIT 5"
                        );
                        $stmt->execute($pend_list_params);
                        $rows = $stmt->fetchAll();
                        if ($rows) {
                            foreach ($rows as $row) {
                                $photo = !empty($row['photo_path']) ? htmlspecialchars($row['photo_path']) : '';
                                $avatar = $photo
                                    ? "<img src='../$photo' style='width:26px;height:26px;border-radius:50%;object-fit:cover;margin-right:6px;'>"
                                    : "<i class='fa-solid fa-circle-user text-secondary' style='font-size:20px;margin-right:6px;'></i>";
                                echo "<tr>
                                    <td>" . htmlspecialchars($row['session_name'] ?? '—') . "</td>
                                    <td>" . htmlspecialchars($row['class_name'] ?? '—') . "</td>
                                    <td>" . htmlspecialchars($row['student_id']) . "</td>
                                    <td>" . htmlspecialchars($row['roll_no']) . "</td>
                                    <td>$avatar" . htmlspecialchars($row['name_en']) . "</td>
                                    <td><a href='index.php?page=student_admission&view=edit&id=" . (int) $row['id'] . "' class='btn btn-sm btn-primary'><i class='fa-solid fa-pen-to-square'></i> Process</a></td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center text-muted py-3'>No pending admission</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="side-stat-card" style="background: #ef4444;"><div class="side-stat-info"><h3><?php echo number_format($sms_sent); ?></h3><p>Total SMS Sent</p></div><div class="side-stat-icon"><i class="fa-solid fa-mobile-screen"></i></div></div>
        <div class="side-stat-card" style="background: #00bcd4;"><div class="side-stat-info"><h3><?php echo number_format($sms_buy); ?></h3><p>Total SMS Buy</p></div><div class="side-stat-icon"><i class="fa-solid fa-mobile-screen-button"></i></div></div>
        <div class="side-stat-card" style="background: #00b0ff;"><div class="side-stat-info"><h3><?php echo number_format($sms_balance); ?></h3><p>SMS Balance</p></div><div class="side-stat-icon"><i class="fa-solid fa-comment-sms"></i></div></div>
        <div class="side-stat-card" style="background: #3b5998;"><div class="side-stat-info"><h3><?php echo $today_absent; ?></h3><p>Today Students Absent</p></div><div class="side-stat-icon"><i class="fa-solid fa-user-xmark"></i></div></div>
        <div class="side-stat-card" style="background: #00acc1;"><div class="side-stat-info"><h3><?php echo number_format($today_collection, 2); ?></h3><p>Today's Collection</p></div><div class="side-stat-icon"><i class="fa-solid fa-wallet"></i></div></div>
    </div>
</div>
