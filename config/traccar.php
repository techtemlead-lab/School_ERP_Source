<?php
/**
 * config/traccar.php
 * -----------------------------------------------------------
 * Traccar GPS Platform এর সাথে কথা বলার হেল্পার ফাংশন।
 * Session-based auth ব্যবহার করে (Traccar এর REST API), cookie
 * একটা টেম্প ফাইলে ক্যাশ করে রাখা হয় যাতে প্রতিবার login করতে না হয়
 * (২০ মিনিট পর পর নতুন করে login হবে)।
 */

function traccar_get_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $row = $pdo->query("SELECT * FROM transport_gps_settings WHERE id = 1")->fetch();
    $cache = $row ?: [];
    return $cache;
}

function traccar_cookie_path(array $settings): string
{
    return sys_get_temp_dir() . '/traccar_cookie_' . md5((string) ($settings['base_url'] ?? '') . ($settings['username'] ?? '')) . '.txt';
}

/**
 * Login করে session cookie নিশ্চিত করা (দরকার হলে নতুন করে, নাহলে cached cookie ব্যবহার)
 * সফল হলে true, ব্যর্থ হলে false রিটার্ন করে।
 */
function traccar_ensure_session(array $settings): bool
{
    if (function_exists('erp_is_demo') && (erp_is_demo() || erp_env('ERP_INTEGRATIONS_ENABLED','1')==='0')) { return false; }
    $base_url = rtrim($settings['base_url'] ?? '', '/');
    if ($base_url === '' || empty($settings['username']) || empty($settings['password'])) return false;

    $cookie_file = traccar_cookie_path($settings);
    $is_fresh = file_exists($cookie_file) && (time() - filemtime($cookie_file) < 1200); // ২০ মিনিট পর্যন্ত reuse

    if ($is_fresh) return true;

    $ch = curl_init($base_url . '/api/session');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['email' => $settings['username'], 'password' => $settings['password']]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie_file,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $resp !== false && $http_code === 200;
}

/**
 * একটা authenticated GET কল — /api/devices, /api/positions ইত্যাদির জন্য
 * ব্যর্থ হলে null রিটার্ন করে।
 */
function traccar_api_get(array $settings, string $endpoint): ?array
{
    if (!traccar_ensure_session($settings)) return null;

    $base_url = rtrim($settings['base_url'] ?? '', '/');
    $cookie_file = traccar_cookie_path($settings);

    $ch = curl_init($base_url . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookie_file,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http_code !== 200) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/** সব ডিভাইসের লিস্ট (id, name, uniqueId ইত্যাদি) */
function traccar_get_devices(array $settings): ?array
{
    return traccar_api_get($settings, '/api/devices');
}

/** সব ডিভাইসের সর্বশেষ অবস্থান (latitude, longitude, speed, course, deviceTime ইত্যাদি) */
function traccar_get_positions(array $settings): ?array
{
    return traccar_api_get($settings, '/api/positions');
}

/** কানেকশন টেস্ট — login + devices fetch সফল হলে true */
function traccar_test_connection(array $settings): array
{
    // পুরনো cookie থাকলে মুছে ফেলে ফ্রেশ login বাধ্য করা হচ্ছে (টেস্ট বাটনের জন্য)
    @unlink(traccar_cookie_path($settings));

    if (!traccar_ensure_session($settings)) {
        return ['success' => false, 'message' => 'Login ব্যর্থ হয়েছে — URL/Email/Password চেক করুন।'];
    }
    $devices = traccar_get_devices($settings);
    if ($devices === null) {
        return ['success' => false, 'message' => 'Login সফল হলেও Device লিস্ট আনা যায়নি।'];
    }
    return ['success' => true, 'message' => count($devices) . ' টি ডিভাইস পাওয়া গেছে।', 'devices' => $devices];
}
