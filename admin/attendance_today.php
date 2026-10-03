<?php
// admin/attendance_today.php - Attendance Module > Student Attendance > Today's Attendance
// device_attendance_logs টেবিল থেকে (ZKTeco/eSSL ইত্যাদি ADMS পুশ ডেটা) নির্দিষ্ট দিনের
// IN/OUT পাঞ্চ বের করে students টেবিলের সাথে ম্যাচ করে (rfid_card_no অথবা student_id দিয়ে) দেখানো হয়।

function ta_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// Filters
// -----------------------------------------------------------
$f_date  = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) { $f_date = date('Y-m-d'); }
$f_class = $_GET['f_class'] ?? 'All';
$f_q     = trim($_GET['q'] ?? '');

$classes_list = $pdo->query("SELECT id, class_name FROM academic_classes ORDER BY rank ASC, class_name ASC")->fetchAll();

// -----------------------------------------------------------
// Data fetch — নির্দিষ্ট দিনের প্রতিটা স্টুডেন্টের প্রথম IN ও শেষ OUT পাঞ্চ বের করা হচ্ছে,
// এবং প্রথম IN পাঞ্চটা কোন ডিভাইস/জোন থেকে হয়েছে সেটাও বের করা হচ্ছে (Punch IN Zone)।
// -----------------------------------------------------------
$rows = [];
$attendance_table_missing = false;

try {
    $sql = "SELECT s.id, s.student_id, s.roll_no, s.name_en, s.photo_path,
                   ac.class_name, asec.section_name,
                   att.time_in, att.time_out, zdev.device_name AS punch_zone
            FROM (
                SELECT person_uid,
                       MIN(CASE WHEN punch_type = 'in'  THEN punch_time END) AS time_in,
                       MAX(CASE WHEN punch_type = 'out' THEN punch_time END) AS time_out
                FROM device_attendance_logs
                WHERE person_type = 'student' AND DATE(punch_time) = ?
                GROUP BY person_uid
            ) att
            JOIN students s ON (s.rfid_card_no = att.person_uid OR s.student_id = att.person_uid OR s.tracking_id = att.person_uid OR s.device_serial = att.person_uid)
            LEFT JOIN academic_classes  ac   ON ac.id   = s.class_id
            LEFT JOIN academic_sections asec ON asec.id = s.section_id
            LEFT JOIN device_attendance_logs first_log
                   ON first_log.person_uid  = att.person_uid
                  AND first_log.person_type = 'student'
                  AND first_log.punch_type  = 'in'
                  AND first_log.punch_time  = att.time_in
            LEFT JOIN attendance_devices zdev ON zdev.id = first_log.device_id
            WHERE 1 = 1";
    $params = [$f_date];

    if ($f_class !== 'All') {
        $sql .= " AND s.class_id = ?";
        $params[] = (int) $f_class;
    }
    if ($f_q !== '') {
        $sql .= " AND (s.name_en LIKE ? OR s.roll_no LIKE ? OR s.student_id LIKE ?)";
        $like = '%' . $f_q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql .= " GROUP BY s.id, att.time_in, att.time_out, zdev.device_name
              ORDER BY att.time_in ASC, s.roll_no ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    // device_attendance_logs / attendance_devices টেবিল এখনো তৈরি না থাকলে (ডিভাইস কানেক্ট করা হয়নি)
    // পেজ ক্র্যাশ না করিয়ে খালি লিস্ট দেখানো হবে।
    $attendance_table_missing = true;
    error_log('attendance_today.php query failed: ' . $e->getMessage());
    $debug_exception = $e->getMessage();
}

// ── সাময়িক ডিবাগ ব্লক — ?debug=1 দিলেই দেখাবে, স্বাভাবিক ভিউ-তে কিচ্ছু বদলাবে না ──
if (isset($_GET['debug'])) {
    echo '<div style="background:#111;color:#0f0;padding:15px;font-family:monospace;font-size:12px;white-space:pre-wrap;margin-bottom:15px;border-radius:6px;">';
    echo "FILE: " . __FILE__ . "\n";
    echo "FILE MTIME: " . date('Y-m-d H:i:s', filemtime(__FILE__)) . "\n";
    echo "PHP VERSION: " . PHP_VERSION . "\n";
    echo "f_date: " . htmlspecialchars($f_date) . "\n";
    echo "SQL:\n" . htmlspecialchars($sql ?? '(sql not built)') . "\n\n";
    echo "PARAMS: " . htmlspecialchars(json_encode($params ?? [])) . "\n";
    echo "ROW COUNT: " . count($rows) . "\n";
    if (!empty($debug_exception)) { echo "EXCEPTION: " . htmlspecialchars($debug_exception) . "\n"; }
    echo "RAW ROWS: " . htmlspecialchars(json_encode($rows)) . "\n";
    echo '</div>';
}

$total_present = count($rows);
$display_date  = date('F j, Y', strtotime($f_date));
?>

<div class="card-custom">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="fw-bold text-dark m-0">
            <i class="fa-solid fa-user-check text-primary"></i>
            Students Attendance <span class="text-danger">(<?php echo ta_esc($display_date); ?>)</span>
        </h6>
        <div class="fw-bold text-dark">Total : (<?php echo (int) $total_present; ?>)</div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="row g-2 align-items-center mb-3">
        <input type="hidden" name="page" value="attendance_today">
        <div class="col-6 col-md-3">
            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo ta_esc($f_date); ?>" max="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="col-6 col-md-3">
            <select name="f_class" class="form-select form-select-sm">
                <option value="All">-- All Class --</option>
                <?php foreach ($classes_list as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo ($f_class !== 'All' && (int) $f_class === (int) $c['id']) ? 'selected' : ''; ?>>
                        <?php echo ta_esc($c['class_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-8 col-md-4">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by ID, Roll or Name" value="<?php echo ta_esc($f_q); ?>">
        </div>
        <div class="col-4 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="index.php?page=attendance_today" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate"></i></a>
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
                    <th>Roll</th>
                    <th>Class</th>
                    <th>Name</th>
                    <th style="color:#10b981;">IN</th>
                    <th style="color:#ef4444;">OUT</th>
                    <th>Punch IN Zone</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo ta_esc($r['student_id']); ?></td>
                        <td><?php echo ta_esc($r['roll_no']); ?></td>
                        <td>
                            <?php echo ta_esc($r['class_name']); ?>
                            <?php if (!empty($r['section_name'])): ?>
                                <small class="text-muted">(<?php echo ta_esc($r['section_name']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-start fw-semibold"><?php echo ta_esc($r['name_en']); ?></td>
                        <td class="fw-bold text-success">
                            <?php echo $r['time_in'] ? ta_esc(date('h:i A', strtotime($r['time_in']))) : '-'; ?>
                        </td>
                        <td class="fw-bold text-danger">
                            <?php echo $r['time_out'] ? ta_esc(date('h:i A', strtotime($r['time_out']))) : '-'; ?>
                        </td>
                        <td><?php echo $r['punch_zone'] ? ta_esc($r['punch_zone']) : '<span class="text-muted">-</span>'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-muted py-3">No data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
