<?php
/**
 * qms-webhook.php - বাইরের Question Management System (QMS) থেকে প্রশ্ন এই URL এ পাঠাবে:
 *   https://<এই স্কুলের ডোমেইন>/qms-webhook.php?token=<webhook_token>
 * (URL + token টা Result Module > Question Management > Create Question পেজে দেখানো হয়)
 *
 * প্রতিটা স্কুল তাদের নিজের ডোমেইন দিয়ে এই URL কল পাবে, তাই config/db.php নিজে থেকেই
 * ঠিক সেই স্কুলের DB-তে কানেক্ট করে দেয় (বাকি সব পেজের মতোই) — আলাদা কিছু করা লাগে না।
 *
 * Body (JSON) একটা প্রশ্ন হতে পারে, অথবা {"questions": [...]} আকারে অনেকগুলো একসাথে।
 * সাপোর্টেড ফিল্ড: external_question_id, subject, class_name / class, question_type,
 * question_text / question, options, correct_answer, marks
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/qms_helpers.php';

qms_ensure_tables($pdo);
header('Content-Type: application/json');

$settings = qms_get_settings($pdo);
$expected_token = $settings['webhook_token'] ?? '';
$given_token = $_GET['token'] ?? '';
if ($expected_token === '' || !hash_equals($expected_token, (string) $given_token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    exit();
}

// একটা প্রশ্ন হোক বা একাধিক (questions: []) - দুটোই সাপোর্ট করা হলো
$items = isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : [$payload];

$stmt = $pdo->prepare(
    "INSERT INTO qms_questions
        (external_question_id, subject, class_name, question_type, question_text, options_json, correct_answer, marks, raw_payload)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$saved = 0;
foreach ($items as $item) {
    if (!is_array($item)) continue;

    $external_id   = (string) ($item['external_question_id'] ?? $item['id'] ?? '');
    $subject       = (string) ($item['subject'] ?? '');
    $class_name    = (string) ($item['class_name'] ?? $item['class'] ?? '');
    $question_type = (string) ($item['question_type'] ?? $item['type'] ?? '');
    $question_text = (string) ($item['question_text'] ?? $item['question'] ?? '');
    $options       = $item['options'] ?? null;
    $options_json  = $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
    $correct       = (string) ($item['correct_answer'] ?? $item['answer'] ?? '');
    $marks         = isset($item['marks']) ? (float) $item['marks'] : null;

    if ($question_text === '') continue; // ন্যূনতম দরকারি ফিল্ড

    $stmt->execute([
        $external_id ?: null,
        $subject ?: null,
        $class_name ?: null,
        $question_type ?: null,
        $question_text,
        $options_json,
        $correct ?: null,
        $marks,
        json_encode($item, JSON_UNESCAPED_UNICODE),
    ]);
    $saved++;
}

http_response_code(200);
echo json_encode(['ok' => true, 'saved' => $saved]);
