<?php
/**
 * config/tenant.php
 * -----------------------------------------------------------
 * এই request টা কোন school-এর — সেটা বের করে master DB থেকে
 * সেই school-এর db_host/db_name/db_user/db_pass রিটার্ন করে।
 *
 * চেনার নিয়ম (দুইভাবে চিনবে, যেটা মিলে):
 *   1) Custom domain    -> tenants.custom_domain (client নিজের ডোমেইন)
 *   2) Subdomain         -> tenants.subdomain (school1.yourdomain.com)
 *
 * Local/dev testing এর জন্য ?school=CODE query param ও সাপোর্ট করে।
 */

require_once __DIR__ . '/master_db.php'; // $pdo_master

function resolve_tenant(PDO $pdo_master): array
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', $host); // remove port if any

    // ডেভ/টেস্টিং override — লাইভে থাকলে সমস্যা নাই, ?school= দিলে অথবা session-এ থাকলে কাজ করবে
    $override_code = $_GET['school'] ?? ($_SESSION['tenant_school_code'] ?? null);

    $tenant = null;

    if ($override_code) {
        $stmt = $pdo_master->prepare('SELECT * FROM tenants WHERE school_code = ? LIMIT 1');
        $stmt->execute([$override_code]);
        $tenant = $stmt->fetch();
    }

    if (!$tenant && $host) {
        // ১) কাস্টম ডোমেইন দিয়ে মেলানো
        $stmt = $pdo_master->prepare('SELECT * FROM tenants WHERE custom_domain = ? LIMIT 1');
        $stmt->execute([$host]);
        $tenant = $stmt->fetch();

        // ২) না মিললে সাবডোমেইন দিয়ে মেলানো (প্রথম অংশটা)
        if (!$tenant) {
            $parts = explode('.', $host);
            $sub = $parts[0] ?? '';
            if ($sub && $sub !== 'www') {
                $stmt = $pdo_master->prepare('SELECT * FROM tenants WHERE subdomain = ? LIMIT 1');
                $stmt->execute([$sub]);
                $tenant = $stmt->fetch();
            }
        }

        // ৩) Localhost / Tunnel / Development fallback: একটি সক্রিয় tenant থাকলে সেটি ব্যবহার করা
        if (!$tenant && (in_array($host, ['localhost', '127.0.0.1', '']) || strpos($host, 'trycloudflare.com') !== false || strpos($host, 'loca.lt') !== false || strpos($host, 'ngrok') !== false)) {
            $stmt = $pdo_master->query("SELECT * FROM tenants WHERE deleted_at IS NULL AND status = 'Active' ORDER BY id ASC LIMIT 1");
            $tenant = $stmt->fetch() ?: null;
        }
    }

    if ($tenant && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['tenant_school_code'] = $tenant['school_code'];
    }

    if (!$tenant) {
        http_response_code(404);
        die('এই ঠিকানায় কোনো স্কুল খুঁজে পাওয়া যায়নি। ঠিকানাটি আবার চেক করুন।');
    }

    // Trash-এ থাকা tenant (deleted_at সেট থাকলে) — না পাওয়া গেছে এমনই ট্রিট করা হচ্ছে
    if (!empty($tenant['deleted_at'])) {
        http_response_code(404);
        die('এই ঠিকানায় কোনো স্কুল খুঁজে পাওয়া যায়নি। ঠিকানাটি আবার চেক করুন।');
    }

    if ($tenant['status'] === 'Suspended') {
        http_response_code(403);
        die('এই স্কুলের সার্ভিসটি সাময়িকভাবে বন্ধ আছে। বিস্তারিত জানতে অ্যাডমিনের সাথে যোগাযোগ করুন।');
    }
    if ($tenant['status'] === 'Blocked') {
        http_response_code(403);
        die('এই স্কুলের অ্যাকাউন্টটি ব্লক করা হয়েছে। বিস্তারিত জানতে অ্যাডমিনের সাথে যোগাযোগ করুন।');
    }
    if ($tenant['status'] === 'Cancelled') {
        http_response_code(403);
        die('এই স্কুলের সাবস্ক্রিপশনটি বাতিল হয়ে গেছে।');
    }

    return $tenant;
}
