<?php
/**
 * teacher_question_file.php - শিক্ষকের আপলোড করা প্রশ্নপত্র ফাইল নিরাপদে ডাউনলোড/দেখা
 *
 * ফাইলগুলো ওয়েব থেকে সরাসরি খোলা যায় না (প্রশ্ন গোপনীয়)। শুধু
 *   - লগইন করা Admin (পারমিশন থাকলে), অথবা
 *   - ফাইলটা যে শিক্ষক আপলোড করেছেন তিনি
 * এই স্ক্রিপ্ট দিয়ে খুলতে পারবেন। শিক্ষার্থীরা কখনোই না।
 */

require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/uploads.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/teacher_question_helpers.php';

require_once __DIR__ . '/config/portal_access.php';
$role = erp_portal_link_role(['admin', 'teacher']);
erp_portal_require_role($pdo, $role, 'teacher_questions');
tq_ensure_tables($pdo);
$id = (int) ($_GET['id'] ?? 0);

[$scope, $scope_params] = tq_scope($pdo, $role, 'teacher_question_papers');
$sql = "SELECT file_path, file_name, file_ext FROM teacher_question_papers WHERE id=? AND file_path IS NOT NULL AND $scope";
$params = array_merge([$id], $scope_params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch();

$types = tq_allowed_types();
$ext = $row ? strtolower((string) $row['file_ext']) : '';
if (!$row || !isset($types[$ext])) { http_response_code(404); die('ফাইল পাওয়া যায়নি।'); }

$path = tq_storage_dir(__DIR__) . basename($row['file_path']);
if (!is_file($path)) { http_response_code(404); die('ফাইল পাওয়া যায়নি।'); }

[$ctype, $inline] = $types[$ext];
$download_name = preg_replace('/[\r\n"]/', '', (string) ($row['file_name'] ?: ('question.' . $ext)));
header('Content-Type: ' . $ctype);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline && empty($_GET['download']) ? 'inline' : 'attachment') . '; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: sandbox');
readfile($path);
exit;
