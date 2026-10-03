<?php
/**
 * landing/demo_request_ajax.php
 * -----------------------------------------------------------
 * AJAX endpoint for submitting "Request a Dedicated Demo" leads
 * Stores submitted details directly into `school_master_db.demo_requests`
 */

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../config/master_db.php'; // $pdo_master
require_once __DIR__ . '/../config/saas_lang.php';

$school_name   = trim($_POST['school_name'] ?? '');
$contact_name  = trim($_POST['contact_name'] ?? '');
$phone         = trim($_POST['phone'] ?? '');
$email         = trim($_POST['email'] ?? '');
$student_count = trim($_POST['student_count'] ?? '');
$preferred_date = trim($_POST['preferred_date'] ?? '');

if (empty($school_name) || empty($contact_name) || empty($phone)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => saas_current_lang() === 'bn' 
            ? 'অনুগ্রহ করে প্রতিষ্ঠানের নাম, আপনার নাম এবং মোবাইল নম্বর প্রদান করুন।' 
            : 'Please provide institution name, contact name, and phone number.'
    ]);
    exit;
}

try {
    $stmt = $pdo_master->prepare("
        INSERT INTO demo_requests (school_name, contact_name, phone, email, student_count_range, preferred_date, status)
        VALUES (?, ?, ?, ?, ?, ?, 'New')
    ");
    $date_val = !empty($preferred_date) ? $preferred_date : null;
    $stmt->execute([$school_name, $contact_name, $phone, $email, $student_count, $date_val]);

    echo json_encode([
        'success' => true,
        'title'   => saas_t('modal_success_title'),
        'message' => saas_t('modal_success_desc')
    ]);
} catch (PDOException $e) {
    error_log('Demo request error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => saas_current_lang() === 'bn'
            ? 'সার্ভারে সমস্যা হয়েছে, কিছুক্ষণ পর আবার চেষ্টা করুন।'
            : 'A server error occurred. Please try again later.'
    ]);
}
