<?php
// admin/ajax/fees_dues_preview_ajax.php - Student Fees Collection পেজে Admission/Month বাটনে
// ক্লিক করলেই ডানপাশের "Student Dues Preview" লাইভ আপডেট করার জন্য (পেজ রিলোড ছাড়াই)।
// Search চাপার আগেই ইউজার দেখতে পাবে কোন মাসে কত Dues আছে।
require_once __DIR__ . '/../../config/runtime.php';
erp_session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';
require_once __DIR__ . '/../../config/fees_period_resolver.php';

$MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];
$PERIODS = [0 => 'Admission'] + $MONTHS;

$student_id = (int) ($_GET['student_id'] ?? 0);
$session_id = (int) ($_GET['session_id'] ?? 0);

$periods = [];
foreach (explode(',', (string) ($_GET['periods'] ?? '')) as $pv) {
    if ($pv === '') { continue; }
    $pv = (int) $pv;
    if ($pv >= 0 && $pv <= 12 && !in_array($pv, $periods, true)) { $periods[] = $pv; }
}
sort($periods);

// all=1 — Admission + ১২ মাস, সব Period একসাথে (একটাই রিকোয়েস্টে)। Student সিলেক্ট করলেই একবার
// এটা আনা হয় আর ব্রাউজারে ক্যাশ থাকে, তাই এরপর মাস টগল করলে আর সার্ভারে যেতে হয় না — সাথে সাথে দেখায়
$all = !empty($_GET['all']);

if (!$student_id || !$session_id || (!$periods && !$all)) {
    echo json_encode(['success' => true, 'rows' => [], 'totals' => null, 'student' => null]);
    exit();
}

// Branch scope মেনে Student আনা — অন্য ব্রাঞ্চের স্টুডেন্টের তথ্য যাতে দেখা না যায়
$where = ['id = ?'];
$params = [$student_id];
branch_apply_filter($where, $params);
$stmt = $pdo->prepare("SELECT * FROM students WHERE " . implode(' AND ', $where) . " LIMIT 1");
$stmt->execute($params);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student পাওয়া যায়নি।']);
    exit();
}

$fee_heads = $pdo->query("SELECT id, ac_code, ac_head FROM finance_account_heads WHERE ac_type = 'Academic' AND has_child = 'No' AND status = 'Active' ORDER BY ac_head ASC")->fetchAll();

if ($all) {
    $by_period = [];
    foreach (array_keys($PERIODS) as $period) {
        $list = [];
        foreach (sfc_resolve_period($pdo, (int) $student['id'], $session_id, (int) $student['class_id'], $period, $fee_heads) as $it) {
            $list[] = [
                'period_name' => $PERIODS[$period],
                'name'        => $it['name'],
                'dues'        => (float) $it['dues'],
                'waived'      => (float) $it['waived'],
                'paid'        => (float) $it['paid'],
                'payable'     => (float) $it['dues'], // Preview-তে Payable = এখনো যা দিতে হবে (নিচের সাধারণ মোডের মতোই)
            ];
        }
        $by_period[$period] = $list;
    }
    echo json_encode([
        'success'   => true,
        'all'       => true,
        'by_period' => $by_period,
        'student'   => [
            'father'   => (string) ($student['father_name'] ?? ''),
            'mother'   => (string) ($student['mother_name'] ?? ''),
            'guardian' => (string) ($student['guardian_phone'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$rows = [];
$t_dues = 0.0; $t_waived = 0.0; $t_paid = 0.0; $t_payable = 0.0;
foreach ($periods as $period) {
    foreach (sfc_resolve_period($pdo, (int) $student['id'], $session_id, (int) $student['class_id'], $period, $fee_heads) as $it) {
        $rows[] = [
            'period_name' => $PERIODS[$period],
            'name'        => $it['name'],
            'dues'        => number_format((float) $it['dues'], 2),
            'waived'      => number_format((float) $it['waived'], 2),
            'paid'        => number_format((float) $it['paid'], 2),
            // Preview-তে Payable = এখনো যা দিতে হবে (Paid বাদ দিয়ে) — পুরো ফি নয়
            'payable'     => number_format((float) $it['dues'], 2),
        ];
        $t_dues    += $it['dues'];
        $t_waived  += $it['waived'];
        $t_paid    += $it['paid'];
        $t_payable += $it['dues'];
    }
}

echo json_encode([
    'success' => true,
    'title'   => implode(', ', array_map(static fn($p) => $PERIODS[$p], $periods)),
    'rows'    => $rows,
    'totals'  => [
        'dues'    => number_format($t_dues, 2),
        'waived'  => number_format($t_waived, 2),
        'paid'    => number_format($t_paid, 2),
        'payable' => number_format($t_payable, 2),
    ],
    'student' => [
        'father'   => (string) ($student['father_name'] ?? ''),
        'mother'   => (string) ($student['mother_name'] ?? ''),
        'guardian' => (string) ($student['guardian_phone'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE);
