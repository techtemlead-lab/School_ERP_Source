<?php
/**
 * lesson_plan_print.php - একটা Lesson Plan প্রিন্ট (Money Receipt-এর ডিজাইনে)
 *
 * স্বতন্ত্র (standalone) প্রিন্ট পেজ — Admin, Teacher ও Student তিন পোর্টাল থেকেই খোলা যায়:
 *   - Admin   : Draft ছাড়া সব প্ল্যান ("Office Copy")
 *   - Teacher : শুধু নিজের বানানো প্ল্যান, যেকোনো স্ট্যাটাসে ("Teacher Copy")
 *   - Student : শুধু নিজের Class/Section-এর Approved প্ল্যান ("Student Copy")
 * কোনো লগইন না থাকলে কিছুই দেখায় না।
 */

require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';              // $pdo (tenant resolve সহ)
require_once __DIR__ . '/config/branch_scope.php';    // branch_current_id()
require_once __DIR__ . '/config/permissions.php';     // perm_page_allowed()
require_once __DIR__ . '/config/lesson_plan_helpers.php';
require_once __DIR__ . '/config/security_helpers.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = (int) ($_GET['id'] ?? 0);

// Explicit portal links prevent a second login in the same browser changing roles.
$role = $_GET['portal'] ?? '';
if ($role === '') {
    $logged = array_values(array_filter(['admin', 'teacher', 'student'], fn($r) => !empty($_SESSION[$r . '_logged'])));
    if (count($logged) !== 1) { erp_stop(403, 'Open this print link from your school portal.'); }
    $role = $logged[0];
}
if (!is_string($role) || !in_array($role, ['admin', 'teacher', 'student'], true)) {
    erp_stop(403, 'Choose a valid school portal.');
}
lp_require_role($pdo, $role);
lp_ensure_tables($pdo);

$sql = "SELECT p.*, c.class_name, s.section_name, sub.subject_name, e.name AS teacher_name, ses.session_name
        FROM elearning_lesson_plans p
        LEFT JOIN academic_classes c ON c.id = p.class_id
        LEFT JOIN academic_sections s ON s.id = p.section_id
        LEFT JOIN academic_subjects sub ON sub.id = p.subject_id
        LEFT JOIN employees e ON e.id = p.teacher_id
        LEFT JOIN academic_sessions ses ON ses.id = p.session_id
        WHERE p.id = ?";
[$scope, $scope_params] = lp_plan_scope($pdo, $role);
$sql .= ' AND ' . $scope;
$params = array_merge([$id], $scope_params);
$copy = ['admin' => 'Office Copy', 'teacher' => 'Teacher Copy', 'student' => 'Student Copy'][$role];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plan = $stmt->fetch();
if (!$plan) { erp_stop(404, 'This lesson plan is unavailable in your portal.'); }

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch() ?: [];
$branch = null;
if (function_exists('branch_current_id')) {
    $bid = $plan['branch_id'] !== null ? (int) $plan['branch_id'] : lp_role_branch($pdo, $role);
    if ($bid !== null) {
        $bstmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
        $bstmt->execute([$bid]);
        $branch = $bstmt->fetch();
    } else {
        try { $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch(); }
        catch (\Throwable $e) { $branch = null; }
    }
}
$addr_line = $branch ? trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['zip_code'] ?? ''), ', ') : '';

$GLOBALS['lp_qr_ids'] = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lesson Plan<?php echo $plan ? ' - LP-' . str_pad((string) (int) $plan['id'], 5, '0', STR_PAD_LEFT) : ''; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style><?php echo lp_print_css(); ?></style>
</head>
<body>
    <div class="lp-bar">
        <div></div>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()">Print / Save PDF</button>
    </div>

    <?php if (!$plan): ?>
        <div class="lp-sheet" style="min-height:0;">
            <div class="alert alert-danger mb-0">এই Lesson Plan পাওয়া যায়নি, অথবা আপনার এটা দেখার অনুমতি নেই।</div>
        </div>
    <?php else: ?>
        <?php echo lp_render_sheet($plan, $inst, $copy, $addr_line, '', 'lpqr-0'); ?>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            <?php foreach ($GLOBALS['lp_qr_ids'] as $qr): ?>
                (function () {
                    var el = document.getElementById(<?php echo json_encode($qr['id']); ?>);
                    if (el && window.QRCode) {
                        new QRCode(el, { text: <?php echo json_encode($qr['text'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, width: 58, height: 58 });
                    }
                })();
            <?php endforeach; ?>
        </script>
    <?php endif; ?>
</body>
</html>
