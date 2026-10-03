<?php
// admin/employee_attendance_today.php - Attendance Module > Employee Attendance > Today's Attendance
// device_attendance_logs টেবিল থেকে (ZKTeco/eSSL ইত্যাদি ADMS পুশ ডেটা) নির্দিষ্ট দিনের
// IN/OUT পাঞ্চ বের করে employees টেবিলের সাথে ম্যাচ করে (rfid_card_no অথবা employee_id দিয়ে) দেখানো হয়।
// প্যাটার্ন: attendance_today.php (Student) থেকে হুবহু — শুধু students এর জায়গায় employees।

function eat_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// Filters
// -----------------------------------------------------------
$f_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) { $f_date = date('Y-m-d'); }
$f_type = $_GET['f_type'] ?? 'All';
$f_q    = trim($_GET['q'] ?? '');

$types_list = ['Teachers', 'Staffs', 'Board Of Directors', 'Academic Committee'];

// -----------------------------------------------------------
// Data fetch — নির্দিষ্ট দিনের প্রতিটা কর্মীর প্রথম IN ও শেষ OUT পাঞ্চ বের করা হচ্ছে
// -----------------------------------------------------------
$rows = [];
$attendance_table_missing = false;

try {
    $sql = "SELECT e.id, e.employee_id, e.name, e.type, e.photo_path,
                   dg.title AS designation_title,
                   att.time_in, att.time_out, zdev.device_name AS punch_zone
            FROM (
                SELECT person_uid,
                       MIN(CASE WHEN punch_type = 'in'  THEN punch_time END) AS time_in,
                       MAX(CASE WHEN punch_type = 'out' THEN punch_time END) AS time_out
                FROM device_attendance_logs
                WHERE person_type = 'employee' AND DATE(punch_time) = ?
                GROUP BY person_uid
            ) att
            JOIN employees e ON (e.rfid_card_no = att.person_uid OR e.employee_id = att.person_uid OR e.tracking_id = att.person_uid OR e.device_serial = att.person_uid)
            LEFT JOIN designations dg ON dg.id = e.designation_id
            LEFT JOIN device_attendance_logs first_log
                   ON first_log.person_uid  = att.person_uid
                  AND first_log.person_type = 'employee'
                  AND first_log.punch_type  = 'in'
                  AND first_log.punch_time  = att.time_in
            LEFT JOIN attendance_devices zdev ON zdev.id = first_log.device_id
            WHERE 1 = 1";
    $params = [$f_date];

    if ($f_type !== 'All') {
        $sql .= " AND e.type = ?";
        $params[] = $f_type;
    }
    if ($f_q !== '') {
        $sql .= " AND (e.name LIKE ? OR e.employee_id LIKE ?)";
        $like = '%' . $f_q . '%';
        $params[] = $like; $params[] = $like;
    }

    $sql .= " GROUP BY e.id, att.time_in, att.time_out, zdev.device_name
              ORDER BY att.time_in ASC, e.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $attendance_table_missing = true;
    error_log('employee_attendance_today.php query failed: ' . $e->getMessage());
}

$total_present = count($rows);
$display_date  = date('F j, Y', strtotime($f_date));
?>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold text-dark m-0">
            <i class="fa-solid fa-user-check text-primary"></i>
            Employees Attendance <span class="text-danger">(<?php echo eat_esc($display_date); ?>)</span>
        </h6>
        <div class="fw-bold text-dark">Total : (<?php echo (int) $total_present; ?>)</div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="row g-2 align-items-center mb-3">
        <input type="hidden" name="page" value="employee_attendance_today">
        <div class="col-6 col-md-3">
            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo eat_esc($f_date); ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="col-6 col-md-3">
            <select name="f_type" class="form-select form-select-sm">
                <option value="All">-- All Type --</option>
                <?php foreach ($types_list as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-8 col-md-4">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by ID or Name" value="<?php echo eat_esc($f_q); ?>">
        </div>
        <div class="col-4 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="index.php?page=employee_attendance_today" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate"></i></a>
        </div>
    </form>

    <?php if ($attendance_table_missing): ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Attendance ডিভাইস ডেটা টেবিল এখনো সেটআপ হয়নি। প্রথমে <a href="index.php?page=device_settings">Device Settings</a> থেকে অ্যাটেন্ডেন্স মেশিন যোগ করুন।
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-bordered text-center align-middle m-0" style="font-size: 13.5px;">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Designation</th>
                    <th>Type</th>
                    <th style="color:#10b981;">IN</th>
                    <th style="color:#ef4444;">OUT</th>
                    <th>Punch IN Zone</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo eat_esc($r['employee_id']); ?></td>
                        <td class="text-start fw-semibold"><?php echo eat_esc($r['name']); ?></td>
                        <td><?php echo eat_esc($r['designation_title']); ?></td>
                        <td><?php echo eat_esc($r['type']); ?></td>
                        <td class="fw-bold text-success">
                            <?php echo $r['time_in'] ? eat_esc(date('h:i A', strtotime($r['time_in']))) : '-'; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php echo $r['time_out'] ? eat_esc(date('h:i A', strtotime($r['time_out']))) : '-'; ?>
                        </td>
                        <td><?php echo $r['punch_zone'] ? eat_esc($r['punch_zone']) : '<span class="text-muted">-</span>'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
