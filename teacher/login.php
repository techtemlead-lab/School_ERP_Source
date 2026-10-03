<?php
// teacher/login.php - Teacher Portal Login (Employee ID + Password)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__.'/../config/auth_rate_limit.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')erp_login_limit($pdo,'teacher');

// আগে থেকেই লগইন থাকলে সরাসরি ড্যাশবোর্ডে
if (!empty($_SESSION['teacher_logged'])) {
    header('Location: index.php');
    exit();
}

// ব্রুট-ফোর্স থ্রটল (admin login এর মতোই সহজ সংস্করণ)
if (empty($_SESSION['t_login_attempts'])) { $_SESSION['t_login_attempts'] = 0; }
if (empty($_SESSION['t_login_locked_until'])) { $_SESSION['t_login_locked_until'] = 0; }

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (time() < $_SESSION['t_login_locked_until']) {
        $login_error = 'অনেকবার ভুল চেষ্টা করা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $login_error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (!captcha_verify('captcha_teacher', $_POST['verification_code'] ?? null)) {
        $login_error = 'ভেরিফিকেশন কোড সঠিক নয়!';
    } else {
        $employee_id = trim($_POST['employee_id'] ?? '');
        $password    = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$employee_id]);
        $row = $stmt->fetch();

        $ok = false;

        if ($row) {
            if (empty($row['password'])) {
                // এই এমপ্লয়ির জন্য এখনো কোনো পাসওয়ার্ড সেট হয়নি —
                // ডিফল্ট পাসওয়ার্ড হলো তার নিজের Employee ID
                if (erp_env('APP_ENV')!=='production' && hash_equals((string) $row['employee_id'], (string) $password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE employees SET password = ?, must_change_password = 1 WHERE id = ?')
                        ->execute([$hash, $row['id']]);
                    $ok = true;
                }
            } elseif (password_verify($password, $row['password'])) {
                $ok = true;
            }
        }

        if ($ok) {
            session_regenerate_id(true); // session fixation প্রতিরোধ
            $_SESSION['teacher_logged']      = true;
            $_SESSION['teacher_id']          = (int) $row['id'];
            $_SESSION['teacher_employee_id'] = $row['employee_id'];
            $_SESSION['teacher_name']        = $row['name'];
            $_SESSION['teacher_photo']       = $row['photo_path'];
            $_SESSION['t_login_attempts']    = 0;
            // এই ব্রাউজারে অন্তত একবার সফল লগইন হয়েছে — তাই পরের বার থেকে
            // "প্রথমবার লগইনের নিয়ম" নোটিশটা আর দেখানো হবে না (৫ বছরের কুকি)
            setcookie('tp_seen_first_login_note', '1', time() + 60 * 60 * 24 * 365 * 5, '/');
            header('Location: index.php');
            exit();
        }

        $_SESSION['t_login_attempts']++;
        if ($_SESSION['t_login_attempts'] >= 5) {
            $_SESSION['t_login_locked_until'] = time() + 60;
            $_SESSION['t_login_attempts'] = 0;
        }
        $login_error = 'Invalid Employee ID or Password!';
    }
}

// এই ব্রাউজারে আগে অন্তত একবার সফল লগইন হয়ে থাকলে "প্রথমবার লগইনের নিয়ম"
// নোটিশটা আর দেখানো হবে না
$tp_show_first_login_note = empty($_COOKIE['tp_seen_first_login_note']);

$inst_name = 'School ERP';
try {
    $inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: $inst_name;
} catch (\Throwable $e) { /* table হয়তো এখনো নাই */ }

// প্রতিবার লগইন ফর্ম রেন্ডার হওয়ার আগে নতুন ভেরিফিকেশন কোড
$teacher_captcha_code = captcha_new('captcha_teacher');

// লোগো ইমেজ base64 data URI হিসেবে সরাসরি এমবেড করা — কোনো ফাইল পাথ/আপলোড
// ঠিকঠাক না থাকলেও লোগো সবসময় দেখা যাবে
$tp_brand_logo_data_uri = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48Y2lyY2xlIGN4PSIxMjgiIGN5PSIxMjgiIHI9IjEyMCIgZmlsbD0iIzFlNDA3YyIvPjxwYXRoIGQ9Ik0xMjggNjRsODggNDAtODggNDAtODgtNDB6IiBmaWxsPSIjZmZmIi8+PHBhdGggZD0iTTg0IDEzOHYzNGMwIDEyIDIwIDIyIDQ0IDIyczQ0LTEwIDQ0LTIydi0zNGwtNDQgMjB6IiBmaWxsPSIjZmZmIi8+PC9zdmc+';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Login - School ERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html, body { height: 100%; }
        body {
            margin: 0;
            background: radial-gradient(circle at 20% 20%, #16213e 0%, #0f172a 55%, #0a0f1e 100%);
            height: 100vh; display: flex; align-items: center; justify-content: center;
            position: relative; overflow: hidden;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        /* ---------- পুরো পেজ জুড়ে বড় Logo Watermark ---------- */
        .edu-bg-logo {
            position: absolute; inset: 0; z-index: 0;
            display: flex; align-items: center; justify-content: center;
            pointer-events: none;
        }
        .edu-bg-logo img {
            width: min(78vw, 720px); height: auto; opacity: 0.16;
            filter: drop-shadow(0 0 50px rgba(56,189,248,0.25));
            animation: bgLogoPulse 8s ease-in-out infinite;
        }
        @keyframes bgLogoPulse {
            0%, 100% { transform: scale(1); opacity: 0.14; }
            50%      { transform: scale(1.03); opacity: 0.20; }
        }

        /* ---------- Teacher-Teaching থিমের Animated Icons ---------- */
        .edu-bg { position: absolute; inset: 0; z-index: 1; overflow: hidden; }
        .edu-item {
            position: absolute; opacity: 0; font-size: 54px;
            color: rgba(56, 189, 248, 0.42);
            animation: eduFloat linear infinite;
            filter: drop-shadow(0 0 8px rgba(56,189,248,0.2));
        }
        .edu-item.orange { color: rgba(251, 191, 36, 0.42); }
        .edu-item.green  { color: rgba(52, 211, 153, 0.38); }
        .edu-item.small  { font-size: 38px; }
        .edu-item.large  { font-size: 70px; }

        @keyframes eduFloat {
            0%   { transform: translateY(110vh) rotate(0deg) scale(0.8); opacity: 0; }
            10%  { opacity: 0.9; }
            90%  { opacity: 0.7; }
            100% { transform: translateY(-15vh) rotate(360deg) scale(1.1); opacity: 0; }
        }

        .edu-orb {
            position: absolute; border-radius: 50%; filter: blur(60px);
            background: radial-gradient(circle, rgba(56,189,248,0.25), transparent 70%);
            animation: eduDrift 14s ease-in-out infinite alternate;
            z-index: 1;
        }
        .edu-orb.two { background: radial-gradient(circle, rgba(16,185,129,0.20), transparent 70%); animation-duration: 18s; }
        @keyframes eduDrift {
            0%   { transform: translate(0, 0) scale(1); }
            100% { transform: translate(60px, 40px) scale(1.15); }
        }

        /* ---------- Login Card ---------- */
        .login-card {
            position: relative; z-index: 2;
            background: white; padding: 34px 40px 40px; border-radius: 12px;
            width: 100%; max-width: 400px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.45);
            border-top: 5px solid #38bdf8;
            animation: cardRise 0.6s ease-out;
        }
        @keyframes cardRise {
            0%   { opacity: 0; transform: translateY(18px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .brand-row { display: flex; flex-direction: column; align-items: center; margin-bottom: 14px; }
        .brand-row img { width: 54px; height: auto; margin-bottom: 6px; }
        .brand-row span { font-size: 12.5px; font-weight: 800; letter-spacing: 0.3px; color: #0a2540; }
        .login-card h3 { color: #0a2540; font-weight: 800; margin-bottom: 2px; text-align: center; }
        .login-card p.subtitle { text-align: center; color: #64748b; font-size: 13px; margin-bottom: 22px; }
        .login-icon { width: 50px; height: 50px; border-radius: 50%; background: #0a2540; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .portal-title-row { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 2px; }
        .portal-title-row h3 { margin-bottom: 0; }
    </style>
</head>
<body>

    <!-- পুরো পেজ জুড়ে বড় School ERP Logo (Watermark ব্যাকগ্রাউন্ড) -->
    <div class="edu-bg-logo" aria-hidden="true">
        <img src="<?php echo $tp_brand_logo_data_uri; ?>" alt="">
    </div>

    <!-- Teacher-Teaching থিমের Animated Background Icons -->
    <div class="edu-bg" aria-hidden="true">
        <div class="edu-orb" style="width:320px;height:320px;top:-60px;left:-60px;"></div>
        <div class="edu-orb two" style="width:280px;height:280px;bottom:-80px;right:-40px;"></div>

        <i class="edu-item fa-solid fa-chalkboard-user large" style="left:8%;  animation-duration:16s; animation-delay:0s;"></i>
        <i class="edu-item orange fa-solid fa-book"           style="left:20%; animation-duration:20s; animation-delay:2s;"></i>
        <i class="edu-item green fa-solid fa-apple-whole small" style="left:32%; animation-duration:14s; animation-delay:4s;"></i>
        <i class="edu-item fa-solid fa-chalkboard large"       style="left:46%; animation-duration:22s; animation-delay:1s;"></i>
        <i class="edu-item orange fa-solid fa-graduation-cap"  style="left:60%; animation-duration:18s; animation-delay:3s;"></i>
        <i class="edu-item green fa-solid fa-clipboard-list small" style="left:72%; animation-duration:19s; animation-delay:5s;"></i>
        <i class="edu-item fa-solid fa-pen-nib"                style="left:84%; animation-duration:17s; animation-delay:2.5s;"></i>
        <i class="edu-item orange fa-solid fa-lightbulb large" style="left:92%; animation-duration:21s; animation-delay:0.5s;"></i>
        <i class="edu-item green fa-solid fa-users small"      style="left:12%; animation-duration:23s; animation-delay:7s;"></i>
        <i class="edu-item fa-solid fa-book-open"              style="left:54%; animation-duration:15s; animation-delay:8s;"></i>
    </div>

    <div class="login-card">
        <div class="brand-row">
            <img src="<?php echo $tp_brand_logo_data_uri; ?>" alt="School ERP">
            <span>School ERP</span>
        </div>
        <div class="portal-title-row">
            <div class="login-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
            <h3>Teacher Portal</h3>
        </div>
        <p class="subtitle"><?php echo tp_e($inst_name); ?></p>
        <?php if ($login_error): ?><div class="alert alert-danger py-2 text-center" style="font-size:13px;"><?php echo tp_e($login_error); ?></div><?php endif; ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Employee (Teacher) ID</label>
                <input type="text" name="employee_id" class="form-control" placeholder="e.g. 20260001" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Password</label>
                <input type="password" name="password" class="form-control" placeholder="প্রথমবার হলে আপনার Employee ID-ই পাসওয়ার্ড" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Verification Code</label>
                <div id="captchaBox" style="position:relative;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 10px 14px;text-align:center;">
                    <?php echo captcha_svg_markup($teacher_captcha_code); ?>
                    <button type="button" id="captchaRefreshBtn" title="নতুন কোড" style="position:absolute;bottom:-10px;right:14px;width:30px;height:30px;border-radius:50%;background:#059669;color:#fff;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <i class="fa-solid fa-rotate" style="font-size:13px;"></i>
                    </button>
                </div>
                <style>#captchaBox svg{display:block;width:100%;max-width:260px;height:auto;margin:0 auto;}</style>
                <input type="text" name="verification_code" class="form-control mt-3" placeholder="উপরের সংখ্যাগুলো লিখুন" autocomplete="off" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100 fw-bold py-2" style="background:#0a2540;border-color:#0a2540;">Login</button>
        </form>
        <?php if ($tp_show_first_login_note): ?>
        <p class="text-muted text-center mt-3 mb-0" style="font-size:12px;">প্রথমবার লগইনের সময় Password ফিল্ডে আপনার নিজের Employee ID দিন। লগইন করার পর নিজের পাসওয়ার্ড পরিবর্তন করে নিন।</p>
        <?php endif; ?>
    </div>
    <script>
    document.getElementById('captchaRefreshBtn')?.addEventListener('click', function () {
        const btn = this;
        const icon = btn.querySelector('i');
        icon.classList.add('fa-spin');
        fetch('captcha_refresh.php')
            .then(res => res.json())
            .then(data => {
                document.getElementById('captchaBox').querySelector('svg').outerHTML = data.svg;
            })
            .finally(() => icon.classList.remove('fa-spin'));
    });
    </script>
</body>
</html>
