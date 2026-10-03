<?php
/**
 * config/captcha.php
 * -----------------------------------------------------------
 * সহজ, নির্ভরযোগ্য "Verification Code" (Captcha) হেল্পার — কোনো GD
 * এক্সটেনশনের উপর নির্ভর করে না (shared hosting-এ সবসময় GD থাকে না),
 * বরং সরাসরি ইনলাইন SVG আঁকা হয় যা যেকোনো সার্ভারে কাজ করবে।
 *
 * ব্যবহার (Admin / Teacher / Student — প্রতিটার নিজস্ব session key):
 *   $code = captcha_new('captcha_admin');          // নতুন কোড session-এ রাখে, রিটার্ন করে
 *   echo captcha_svg_markup($code);                 // SVG markup প্রিন্ট করার জন্য
 *   captcha_verify('captcha_admin', $_POST['...']); // POST-এ যাচাই করার জন্য (one-time use)
 */

function captcha_new(string $session_key, int $digits = 6): string
{
    $min = (int) str_pad('1', $digits, '0');
    $max = (int) str_pad('', $digits, '9');
    $code = (string) random_int($min, $max);
    $_SESSION[$session_key] = $code;
    return $code;
}

function captcha_verify(string $session_key, ?string $input): bool
{
    $expected = $_SESSION[$session_key] ?? null;
    unset($_SESSION[$session_key]); // এক-বার ব্যবহারযোগ্য — যাচাই করার সাথে সাথেই মুছে ফেলা হয়
    $input = trim((string) $input);
    return !empty($expected) && $input !== '' && hash_equals((string) $expected, $input);
}

function captcha_svg_markup(string $code): string
{
    $len    = strlen($code);
    $width  = 40 + $len * 30;
    $height = 60;
    // ছবিতে যেমন নীল/কমলা/বেগুনি রঙ পালা করে ব্যবহার হয়েছে, সেই রকম মিশ্র প্যালেট
    $colors = ['#2563eb', '#ea580c', '#7c3aed', '#0891b2', '#db2777', '#16a34a'];

    $svg  = '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="verification code">';
    $svg .= '<rect x="0.5" y="0.5" width="' . ($width - 1) . '" height="' . ($height - 1) . '" rx="10" fill="#fdfdfd" stroke="#e5e7eb"/>';

    // হালকা ধূসর dot-noise — সারা বক্স জুড়ে ছিটিয়ে (কনফেত্তি লুক)
    for ($i = 0; $i < 36; $i++) {
        $cx = random_int(4, $width - 4);
        $cy = random_int(4, $height - 4);
        $r  = random_int(1, 2) / 2;
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#c7ccd6" opacity="0.9"/>';
    }

    // প্রতিটা ডিজিটের বেস পজিশন আগেই ঠিক করে রাখা হচ্ছে (একটু কাছাকাছি/ওভারল্যাপ
    // করা যাতে সহজে আলাদা করে পড়া না যায়), যাতে নিচের সংযোগ-রেখা আর লাইনগুলো
    // ডিজিটগুলোর ঠিক উপর দিয়েই যায়
    $chars = str_split($code);
    $step  = ($width - 44) / max(1, $len - 1);
    $baseX = [];
    $baseY = [];
    $x = 22;
    foreach ($chars as $ch) {
        $baseX[] = $x;
        $baseY[] = random_int($height - 34, $height - 22);
        $x += $step * 0.86; // একটু কাছাকাছি বসানো — অক্ষরগুলো সামান্য ওভারল্যাপ করবে
    }

    // ডিজিট থেকে ডিজিটে সংযোগকারী ঢেউ-খেলানো রেখা (ঠিক সংখ্যাগুলোর উপর দিয়েই যায়)
    for ($i = 0; $i < count($baseX) - 1; $i++) {
        $mx = ($baseX[$i] + $baseX[$i + 1]) / 2;
        $my = min($baseY[$i], $baseY[$i + 1]) - random_int(6, 14);
        $svg .= '<path d="M' . $baseX[$i] . ',' . $baseY[$i] . ' Q ' . $mx . ',' . $my . ' ' . $baseX[$i + 1] . ',' . $baseY[$i + 1] . '" fill="none" stroke="#94a3b8" stroke-width="1.1" opacity="0.8"/>';
    }

    // আরও ২-৩টা লম্বা রঙিন লাইন সংখ্যার উপর দিয়েই আড়াআড়িভাবে যাবে
    $wave_colors = ['#c084fc', '#f0abfc', '#93c5fd', '#fca5a5', '#67e8f9'];
    for ($i = 0; $i < 3; $i++) {
        $wy1 = random_int(10, $height - 10);
        $wy2 = random_int(10, $height - 10);
        $wcolor = $wave_colors[array_rand($wave_colors)];
        $svg .= '<path d="M0,' . $wy1 . ' Q ' . ($width * 0.5) . ',' . random_int(10, $height - 10) . ' ' . $width . ',' . $wy2 . '" fill="none" stroke="' . $wcolor . '" stroke-width="1.4" opacity="0.75"/>';
    }

    // এক কোণে ছোট ছোট ক্রস-হ্যাচ দাগ
    $hx = random_int(6, (int) ($width * 0.3));
    $hy = random_int(6, (int) ($height * 0.4));
    for ($i = 0; $i < 5; $i++) {
        $lx1 = $hx + $i * 5; $ly1 = $hy;
        $lx2 = $lx1 - 6;     $ly2 = $hy + 12;
        $svg .= '<line x1="' . $lx1 . '" y1="' . $ly1 . '" x2="' . $lx2 . '" y2="' . $ly2 . '" stroke="#d1d5db" stroke-width="1"/>';
    }

    // ডিজিটগুলো — এলোমেলো ঘোরানো + সামান্য skew (বাঁকানো) + এলোমেলো সাইজ,
    // যাতে সহজে টেক্সট-হিসেবে চেনা না যায় কিন্তু চোখে ঠিকই পড়া যায়
    foreach ($chars as $i => $ch) {
        $rot   = random_int(-28, 28);
        $skew  = random_int(-12, 12);
        $color = $colors[array_rand($colors)];
        $fs    = random_int(24, 32);
        $svg  .= '<text x="0" y="0" font-family="Arial, sans-serif" font-weight="800" font-size="' . $fs . '" fill="' . $color . '" transform="translate(' . $baseX[$i] . ',' . $baseY[$i] . ') rotate(' . $rot . ') skewX(' . $skew . ')">' . htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}
