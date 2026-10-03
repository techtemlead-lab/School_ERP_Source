<?php
// student/login.php - Student / Guardian Portal Login (Branch + Student ID + Password + Verification Code)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__.'/../config/auth_rate_limit.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')erp_login_limit($pdo,'student');

// আগে থেকেই লগইন থাকলে সরাসরি ড্যাশবোর্ডে
if (!empty($_SESSION['student_logged'])) {
    header('Location: index.php');
    exit();
}

// ব্রুট-ফোর্স থ্রটল
if (empty($_SESSION['s_login_attempts'])) { $_SESSION['s_login_attempts'] = 0; }
if (empty($_SESSION['s_login_locked_until'])) { $_SESSION['s_login_locked_until'] = 0; }

$login_error = '';

// Branch তালিকা (Global Settings > Branch থেকে) — না থাকলে খালি array
$branches = [];
try {
    $branches = $pdo->query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name")->fetchAll();
} catch (\Throwable $e) { /* table হয়তো এখনো নাই */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (time() < $_SESSION['s_login_locked_until']) {
        $login_error = 'অনেকবার ভুল চেষ্টা করা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $login_error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif (!captcha_verify('captcha_student', $_POST['verification_code'] ?? null)) {
        $login_error = 'ভেরিফিকেশন কোড সঠিক নয়!';
    } else {
        $branch_id  = (int) ($_POST['branch_id'] ?? 0);
        $student_id = trim($_POST['student_id'] ?? '');
        $password   = $_POST['password'] ?? '';

        // নোট: Student Promotion হওয়া ছাত্রের একই student_id একাধিক রো-তে থাকতে পারে (প্রতি
        // Academic Year-এ একটা, পুরনোগুলো is_current=0/Archive) — তাই is_current=1 (বর্তমান
        // বছরের রেকর্ড) prefer করে লগইন করানো হচ্ছে, যাতে ভুলবশত পুরনো বছরের (Archive) ড্যাশবোর্ডে
        // ঢুকে না যায়। is_current কলাম পুরনো ইনস্টলেশনে না থাকলে নিরাপদে self-heal করা হলো।
        $slog_cols = $pdo->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_current', $slog_cols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1");
        }
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? AND status = 'Active' ORDER BY is_current DESC, id DESC LIMIT 1");
        $stmt->execute([$student_id]);
        $row = $stmt->fetch();

        // Branch যদি সিলেক্ট করা থাকে এবং স্টুডেন্টের নিজস্ব branch_id সেট করা থাকে, তাহলে মিলিয়ে দেখা হয়
        // (branch_id সেট না থাকা মানে পুরনো/single-branch ডাটা — সেক্ষেত্রে চেক স্কিপ হবে)
        $branch_ok = true;
        if ($row && $branch_id && !empty($row['branch_id']) && (int) $row['branch_id'] !== $branch_id) {
            $branch_ok = false;
        }

        $ok = false;
        if ($row && $branch_ok) {
            if (empty($row['password'])) {
                // প্রথমবার — ডিফল্ট পাসওয়ার্ড হলো নিজের Student ID
                if (erp_env('APP_ENV')!=='production' && hash_equals((string) $row['student_id'], (string) $password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE students SET password = ?, must_change_password = 1 WHERE id = ?')
                        ->execute([$hash, $row['id']]);
                    $ok = true;
                }
            } elseif (password_verify($password, $row['password'])) {
                $ok = true;
            }
        }

        if ($ok) {
            session_regenerate_id(true); // session fixation প্রতিরোধ
            $_SESSION['student_logged']    = true;
            $_SESSION['student_pk']        = (int) $row['id'];
            $_SESSION['student_code']      = $row['student_id'];
            $_SESSION['student_name']      = $row['name_en'];
            $_SESSION['s_login_attempts']  = 0;
            setcookie('sp_seen_first_login_note', '1', time() + 60 * 60 * 24 * 365 * 5, '/');
            header('Location: index.php');
            exit();
        }

        $_SESSION['s_login_attempts']++;
        if ($_SESSION['s_login_attempts'] >= 5) {
            $_SESSION['s_login_locked_until'] = time() + 60;
            $_SESSION['s_login_attempts'] = 0;
        }
        $login_error = 'Invalid Student ID, Branch or Password!';
    }
}

$sp_show_first_login_note = empty($_COOKIE['sp_seen_first_login_note']);

$inst_name = 'School ERP';
try {
    $inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: $inst_name;
} catch (\Throwable $e) { /* table হয়তো এখনো নাই */ }

// প্রতিবার লগইন ফর্ম রেন্ডার হওয়ার আগে নতুন ভেরিফিকেশন কোড
$student_captcha_code = captcha_new('captcha_student');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student / Guardian Login - <?php echo sp_e($inst_name); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html, body { height: 100%; }
        body {
            margin: 0; min-height: 100vh;
            background: radial-gradient(circle at 15% 15%, #134e4a 0%, #0f172a 55%, #0a0f1e 100%);
            display: flex; align-items: center; justify-content: center;
            position: relative; overflow: hidden; padding: 24px 12px;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .edu-bg { position: absolute; inset: 0; z-index: 0; overflow: hidden; }
        .edu-orb {
            position: absolute; border-radius: 50%; filter: blur(60px);
            background: radial-gradient(circle, rgba(45,212,191,0.25), transparent 70%);
            animation: eduDrift 14s ease-in-out infinite alternate;
        }
        .edu-orb.two { background: radial-gradient(circle, rgba(16,185,129,0.20), transparent 70%); animation-duration: 18s; }
        @keyframes eduDrift { 0% { transform: translate(0,0) scale(1); } 100% { transform: translate(60px,40px) scale(1.15); } }
        .edu-item {
            position: absolute; opacity: 0; font-size: 50px; color: rgba(45,212,191,0.4);
            animation: eduFloat linear infinite;
        }
        .edu-item.orange { color: rgba(251,191,36,0.4); }
        .edu-item.small { font-size: 34px; }
        .edu-item.large { font-size: 64px; }
        @keyframes eduFloat {
            0% { transform: translateY(110vh) rotate(0deg) scale(0.8); opacity: 0; }
            10% { opacity: 0.85; } 90% { opacity: 0.65; }
            100% { transform: translateY(-15vh) rotate(360deg) scale(1.1); opacity: 0; }
        }

        .login-card {
            position: relative; z-index: 2;
            background: white; padding: 32px 34px 34px; border-radius: 14px;
            width: 100%; max-width: 430px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.45);
            border-top: 5px solid #14b8a6;
            animation: cardRise 0.6s ease-out;
        }
        @keyframes cardRise { 0% { opacity: 0; transform: translateY(18px); } 100% { opacity: 1; transform: translateY(0); } }
        .brand-row { display: flex; flex-direction: column; align-items: center; margin-bottom: 10px; }
        .brand-icon {
            width: 54px; height: 54px; border-radius: 14px; background: #0f172a;
            color: #2dd4bf; display: flex; align-items: center; justify-content: center;
            font-size: 24px; margin-bottom: 8px;
        }
        .brand-row span { font-size: 12.5px; font-weight: 800; letter-spacing: 0.3px; color: #0f172a; }
        .portal-title-row { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 2px; }
        .portal-title-row h3 { margin: 0; color: #0f172a; font-weight: 800; }
        .login-card p.subtitle { text-align: center; color: #64748b; font-size: 13px; margin: 4px 0 20px; }
        label.form-label { font-size: 13px; }
    </style>
</head>
<body>
    <div class="edu-bg" aria-hidden="true">
        <div class="edu-orb" style="width:300px;height:300px;top:-60px;left:-60px;"></div>
        <div class="edu-orb two" style="width:260px;height:260px;bottom:-70px;right:-40px;"></div>
        <i class="edu-item large fa-solid fa-user-graduate" style="left:8%; animation-duration:16s; animation-delay:0s;"></i>
        <i class="edu-item orange fa-solid fa-book" style="left:22%; animation-duration:20s; animation-delay:2s;"></i>
        <i class="edu-item small fa-solid fa-pen" style="left:34%; animation-duration:14s; animation-delay:4s;"></i>
        <i class="edu-item large fa-solid fa-graduation-cap" style="left:48%; animation-duration:22s; animation-delay:1s;"></i>
        <i class="edu-item orange fa-solid fa-house-laptop" style="left:62%; animation-duration:18s; animation-delay:3s;"></i>
        <i class="edu-item small fa-solid fa-people-roof" style="left:74%; animation-duration:19s; animation-delay:5s;"></i>
        <i class="edu-item fa-solid fa-book-open" style="left:86%; animation-duration:17s; animation-delay:2.5s;"></i>
    </div>

    <div class="login-card">
        <div class="brand-row">
            <div class="brand-icon"><i class="fa-solid fa-user-graduate"></i></div>
            <span><?php echo sp_e($inst_name); ?></span>
        </div>
        <div class="portal-title-row">
            <h3>Student / Guardian Login</h3>
        </div>
        <p class="subtitle">নিজের রোল/আইডি নাম্বার দিয়ে লগইন করুন</p>

        <?php if ($login_error): ?><div class="alert alert-danger py-2 text-center" style="font-size:13px;"><?php echo sp_e($login_error); ?></div><?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <?php if ($branches): ?>
            <div class="mb-3">
                <label class="form-label fw-bold">Branch <span class="text-danger">*</span></label>
                <select name="branch_id" class="form-select" required>
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int) $b['id']; ?>"><?php echo sp_e($b['branch_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-bold">User Name <span class="text-danger">*</span></label>
                <input type="text" name="student_id" class="form-control" placeholder="e.g. 260101003" required autofocus>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" placeholder="প্রথমবার হলে আপনার Student ID-ই পাসওয়ার্ড" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Verification Code <span class="text-danger">*</span></label>
                <div id="captchaBox" style="position:relative;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 10px 14px;text-align:center;">
                    <?php echo captcha_svg_markup($student_captcha_code); ?>
                    <button type="button" id="captchaRefreshBtn" title="নতুন কোড" style="position:absolute;bottom:-10px;right:14px;width:30px;height:30px;border-radius:50%;background:#059669;color:#fff;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                        <i class="fa-solid fa-rotate" style="font-size:13px;"></i>
                    </button>
                </div>
                <style>#captchaBox svg{display:block;width:100%;max-width:260px;height:auto;margin:0 auto;}</style>
                <input type="text" name="verification_code" class="form-control mt-3" placeholder="উপরের সংখ্যাগুলো লিখুন" autocomplete="off" required>
            </div>

            <button type="submit" name="login" class="btn w-100 fw-bold py-2 text-white" style="background:#0f766e;border-color:#0f766e;">Login</button>
        </form>

        <?php if ($sp_show_first_login_note): ?>
        <p class="text-muted text-center mt-3 mb-0" style="font-size:12px;">প্রথমবার লগইনের সময় Password ফিল্ডে আপনার নিজের Student ID দিন। লগইন করার পর নিজের পাসওয়ার্ড পরিবর্তন করে নিন।</p>
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
