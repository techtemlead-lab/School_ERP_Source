<?php
declare(strict_types=1);

/**
 * Public School Code resolver for the Android app.
 *
 * IMPORTANT:
 * - This file must be placed on the SAME School ERP installation as config/master_db.php.
 * - It uses the existing master DB connection instead of duplicating credentials here.
 * - The master DB tenants table contains the per-school routing information.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$schoolCode = trim((string)($_REQUEST['school_code'] ?? ''));

if ($schoolCode === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'School Code দেওয়া হয়নি'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($schoolCode) > 50 || !preg_match('/^[A-Za-z0-9_-]+$/', $schoolCode)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'School Code সঠিক নয়'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // backend/resolve-school.php -> ../config/master_db.php
    require_once __DIR__ . '/../config/master_db.php';
    require_once __DIR__ . '/_features.php';
    ensureTenantTrashColumns($pdo_master);

    $stmt = $pdo_master->prepare(
        'SELECT school_code, subdomain, custom_domain, status, deleted_at
         FROM tenants
         WHERE school_code = ?
         LIMIT 1'
    );
    $stmt->execute([$schoolCode]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant || !empty($tenant['deleted_at'])) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'এই School Code দিয়ে কোনো স্কুল পাওয়া যায়নি'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // master_schema.sql uses: Active / Suspended / Blocked / Cancelled
    if (($tenant['status'] ?? '') !== 'Active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'এই স্কুলের অ্যাকাউন্ট বর্তমানে সক্রিয় নেই'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $customDomain = trim((string)($tenant['custom_domain'] ?? ''));
    $subdomain = trim((string)($tenant['subdomain'] ?? ''));

    if ($customDomain !== '') {
        $baseUrl = 'https://' . preg_replace('#^https?://#i', '', rtrim($customDomain, '/'));
    } elseif ($subdomain !== '') {
        // Current School SaaS domain from the Android app / deployment.
        $baseUrl = 'https://' . $subdomain . '.yourdomain.com';
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'এই স্কুলের কোনো ডোমেইন/সাবডোমেইন সেট করা নেই'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'base_url' => $baseUrl,
        'school_code' => $tenant['school_code']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('School resolver error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ], JSON_UNESCAPED_UNICODE);
}
