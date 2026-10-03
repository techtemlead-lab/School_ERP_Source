<?php
// admin/ajax/result_working_days_ajax.php
// Result Module > Exam Settings > Working Days (Add ফর্ম) এর Exam Term / Classes /
// Start Date / End Date বদলালে (পেজ রিলোড ছাড়াই) Total Working Days লাইভ প্রিভিউ ক্যালকুলেট করে।
// Action: action=preview (GET term_id, class_id[], start_date, end_date) -> JSON {success, total}
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/permissions.php';

if (!perm_page_allowed($pdo, 'result_working_days')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'এই পেজ দেখার অনুমতি নেই।']);
    exit();
}

$action = $_GET['action'] ?? '';
if ($action !== 'preview') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// result_working_days.php এর মূল ফাইলের সাথে হুবহু calculation লজিক
function calculate_working_days(PDO $pdo, string $start, string $end, array $weekly_holidays): int
{
    $s = strtotime($start);
    $e = strtotime($end);
    if (!$s || !$e || $e < $s) return 0;

    $stmt = $pdo->prepare("SELECT start_date, end_date FROM academic_holidays WHERE status='Active' AND start_date <= ? AND end_date >= ?");
    $stmt->execute([$end, $start]);
    $holiday_ranges = $stmt->fetchAll();

    $total = 0;
    for ($t = $s; $t <= $e; $t += 86400) {
        $day_name = date('l', $t);
        if (in_array($day_name, $weekly_holidays, true)) continue;

        $ymd = date('Y-m-d', $t);
        $is_holiday = false;
        foreach ($holiday_ranges as $hr) {
            if ($ymd >= $hr['start_date'] && $ymd <= $hr['end_date']) { $is_holiday = true; break; }
        }
        if ($is_holiday) continue;

        $total++;
    }
    return $total;
}

$term_id    = (int) ($_GET['term_id'] ?? 0);
$class_ids  = array_map('intval', (array) ($_GET['class_id'] ?? []));
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

$valid = ($term_id > 0 && $class_ids && $start_date !== '' && $end_date !== '' && strtotime($end_date) >= strtotime($start_date));

$total = null;
if ($valid) {
    $branch_row = $pdo->query("SELECT weekly_holidays FROM branches WHERE status='Active' ORDER BY id ASC LIMIT 1")->fetch();
    $weekly_holidays = array_filter(array_map('trim', explode(',', $branch_row['weekly_holidays'] ?? 'Friday')));
    $total = calculate_working_days($pdo, $start_date, $end_date, $weekly_holidays);
}

echo json_encode(['success' => true, 'valid' => $valid, 'total' => $total], JSON_UNESCAPED_UNICODE);
