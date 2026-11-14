<?php
/**
 * config/uploads.php
 * -----------------------------------------------------------
 * SaaS multi-tenant হওয়ার পর প্রতিটা স্কুলের uploaded ফাইল
 * (student photo, institute logo, ID card template ইত্যাদি)
 * আলাদা রাখার জন্য। প্রতিটা tenant এর জন্য uploads/{school_code}/...
 * ফোল্ডারে ফাইল যাবে, যাতে এক স্কুলের ছবি আরেক স্কুলের সাথে মিক্স
 * না হয়ে যায়।
 *
 * এই ফাইলটা config/db.php এর পরে লোড হতে হবে (কারণ $GLOBALS['tenant']
 * ততক্ষণে সেট হয়ে গেছে config/db.php থেকে)।
 */

/**
 * তেমন কোনো module folder নাম (যেমন 'students', 'institute') দিলে
 * সেই স্কুলের জন্য 'uploads/{school_code}/students' রিটার্ন করে।
 * SaaS না থাকলে (tenant সেট না থাকলে) পুরনো আচরণ ধরে রাখতে 'legacy' ফোল্ডার ব্যবহার হয়।
 */
function tenant_upload_rel(string $sub = ''): string
{
    $code = $GLOBALS['tenant']['school_code'] ?? 'legacy';
    $sub = trim($sub, '/');
    return 'uploads/' . (function_exists('erp_is_demo') && erp_is_demo() ? 'demo/' : '') . $code . ($sub !== '' ? '/' . $sub : '');
}

/**
 * উপরেরটার filesystem absolute path ভার্সন। $base_dir হলো সেই ফাইলটার
 * __DIR__ যেখান থেকে কল হচ্ছে project root পর্যন্ত কত ধাপ (../) লাগবে সেটা
 * আগে থেকেই মিলিয়ে পাঠাতে হবে — যেমন admin/ ফোল্ডারের কোনো ফাইল থেকে
 * কল করলে $project_root_dir = __DIR__ . '/..'
 */
function tenant_upload_abs(string $project_root_dir, string $sub = ''): string
{
    return rtrim($project_root_dir, '/') . '/' . tenant_upload_rel($sub) . '/';
}

/**
 * ওয়েবসাইট/মিডিয়া ইত্যাদি পেজের ছবি আপলোডের শেয়ার্ড হেল্পার।
 * - jpg/png/webp, আসল কনটেন্ট (MIME) যাচাই, random ফাইলের নাম
 * - বড় ছবি (মোবাইল ক্যামেরার ৩-১০MB) সার্ভারে আপনা-আপনি ছোট করে (সর্বোচ্চ $max_dim px) রাখে — GD থাকলে
 * - কোথায় আটকাল তা পরিষ্কার বাংলা বার্তায় (PHP সীমা / ফোল্ডার তৈরি / লেখার অনুমতি ইত্যাদি)
 * ফেরত: ['ok'=>bool, 'path'=>?string (প্রজেক্ট-রুট রিলেটিভ), 'error'=>?string]
 */
function erp_image_upload(string $field, bool $required, string $sub = 'website', int $max_bytes = 10485760, int $max_dim = 1920): array
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => !$required, 'path' => null, 'error' => $required ? 'ছবি আবশ্যক।' : null];
    }
    $file = $_FILES[$field];
    if (is_array($file['error'])) { return ['ok' => false, 'path' => null, 'error' => 'একসাথে একটাই ছবি দিন।']; }
    $err = (int) $file['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE   => 'ছবির সাইজ সার্ভারের সীমার (' . ini_get('upload_max_filesize') . ') বেশি। ছোট ছবি দিন।',
            UPLOAD_ERR_FORM_SIZE  => 'ছবির সাইজ ফর্মের সীমার বেশি।',
            UPLOAD_ERR_PARTIAL    => 'ছবি আংশিক আপলোড হয়েছে (নেটওয়ার্ক সমস্যা) — আবার চেষ্টা করুন।',
            UPLOAD_ERR_NO_TMP_DIR => 'সার্ভারে অস্থায়ী ফোল্ডার নেই — Administrator-কে জানান।',
            UPLOAD_ERR_CANT_WRITE => 'সার্ভার ডিস্কে ছবি লিখতে পারেনি — Administrator-কে জানান।',
            UPLOAD_ERR_EXTENSION  => 'সার্ভারের একটা এক্সটেনশন আপলোড থামিয়েছে।',
        ];
        return ['ok' => false, 'path' => null, 'error' => $msgs[$err] ?? 'ছবি আপলোডে সমস্যা হয়েছে (কোড ' . $err . ')।'];
    }
    if ($file['size'] > $max_bytes) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ ' . (int) ($max_bytes / 1048576) . 'MB এর বেশি হওয়া যাবে না।'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ছবি আপলোড করা যাবে।'];
    }
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $file['tmp_name']);
    finfo_close($fi);
    if ($mime !== $allowed[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }

    $dir = tenant_upload_abs(__DIR__ . '/..', $sub);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'path' => null, 'error' => 'আপলোড ফোল্ডার তৈরি করা যায়নি (' . tenant_upload_rel($sub) . ') — সার্ভারের লেখার অনুমতি দেখুন।'];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'path' => null, 'error' => 'আপলোড ফোল্ডারে লেখার অনুমতি নেই (' . tenant_upload_rel($sub) . ') — Administrator-কে জানান।'];
    }

    $name = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $dir . $name;
    $saved = false;

    // বড় ছবি ছোট করা (GD থাকলে); ব্যর্থ হলে সরাসরি আসল ফাইলটাই সেভ
    if (function_exists('imagecreatetruecolor') && ($file['size'] > 1048576 || true)) {
        $info = @getimagesize($file['tmp_name']);
        if ($info && ($info[0] > $max_dim || $info[1] > $max_dim || $file['size'] > 1572864)) {
            $src = $mime === 'image/jpeg' ? @imagecreatefromjpeg($file['tmp_name']) : ($mime === 'image/png' ? @imagecreatefrompng($file['tmp_name']) : @imagecreatefromwebp($file['tmp_name']));
            if ($src) {
                if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                    $ex = @exif_read_data($file['tmp_name']);
                    $o = (int) ($ex['Orientation'] ?? 1);
                    $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                    if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
                }
                $w = imagesx($src); $h = imagesy($src);
                $scale = min(1, $max_dim / max($w, $h));
                $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                if ($mime !== 'image/jpeg') { imagealphablending($dst, false); imagesavealpha($dst, true); }
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $saved = $mime === 'image/jpeg' ? @imagejpeg($dst, $dest, 85) : ($mime === 'image/png' ? @imagepng($dst, $dest, 6) : @imagewebp($dst, $dest, 85));
                imagedestroy($src); imagedestroy($dst);
                if (!$saved && is_file($dest)) { @unlink($dest); }
            }
        }
    }
    if (!$saved && !@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি (ফাইল সরানো ব্যর্থ) — ডিস্কের জায়গা/অনুমতি দেখুন।'];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'path' => tenant_upload_rel($sub) . '/' . $name, 'error' => null];
}
