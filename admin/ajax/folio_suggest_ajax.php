<?php
// admin/ajax/folio_suggest_ajax.php - Fees Re-collection পেজের Folio No লাইভ সাজেশন
// কমপক্ষে ২ অক্ষর লিখলেই মিলে যাওয়া Folio গুলো দেখায় — সর্বশেষ Collection করা Folio সবার আগে।
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session শেষ হয়ে গেছে, আবার লগইন করুন।']);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/branch_scope.php';

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit();
}

// LIKE-এর বিশেষ চিহ্ন (% _ \) এস্কেপ করা
$like = '%' . addcslashes($q, '%_\\') . '%';

$where = ["fc.folio_no IS NOT NULL", "fc.folio_no <> ''", "fc.folio_no LIKE ?"];
$params = [$like];
branch_apply_filter($where, $params, 's.branch_id');

$stmt = $pdo->prepare(
    "SELECT fc.folio_no, MAX(fc.id) AS last_id, MAX(fc.collected_at) AS last_at, SUM(fc.amount) AS total,
            s.student_id AS code, s.name_en AS name, s.roll_no, c.class_name
     FROM finance_fee_collections fc
     LEFT JOIN students s ON s.id = fc.student_id
     LEFT JOIN academic_classes c ON c.id = s.class_id
     WHERE " . implode(' AND ', $where) . "
     GROUP BY fc.folio_no, s.id, s.student_id, s.name_en, s.roll_no, c.class_name
     ORDER BY last_id DESC
     LIMIT 10"
);
$stmt->execute($params);

$results = [];
foreach ($stmt->fetchAll() as $r) {
    $results[] = [
        'folio_no' => $r['folio_no'],
        'name'     => $r['name'],
        'code'     => $r['code'],
        'roll_no'  => $r['roll_no'],
        'class'    => $r['class_name'],
        'total'    => number_format((float) $r['total'], 2),
        'at'       => $r['last_at'],
    ];
}
echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
