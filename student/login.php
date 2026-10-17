<?php
// student/login.php - Student / Guardian Portal Login (Branch + Student ID + Password + Verification Code)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/default_password.php';
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
                // প্রথমবার — ডিফল্ট পাসওয়ার্ড (config/default_password.php)
                if (hash_equals(erp_default_password(), (string) $password)) {
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
require_once __DIR__ . '/../config/login_ui.php';
$lg_logo = login_ui_logo_url($pdo, '../');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student / Guardian Login - <?php echo sp_e($inst_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo login_ui_css('student'); ?></style>
</head>
<body class="lg">
<main class="lg-shell">
    <?php echo login_ui_aside('student', $inst_name, $lg_logo); ?>

    <section class="lg-main">
        <div class="lg-card">
            <div class="lg-bar"></div>
            <h2>সাইন-ইন</h2>
            <p class="lg-sub">নিজের Student ID ও পাসওয়ার্ড দিয়ে প্রবেশ করুন</p>

            <?php if ($login_error): ?><div class="lg-alert err"><i class="fa-solid fa-circle-exclamation mt-1"></i><span><?php echo sp_e($login_error); ?></span></div><?php endif; ?>

            <form method="POST" autocomplete="on">
                <?php echo csrf_field(); ?>

                <?php if ($branches): ?>
                <div class="lg-field">
                    <label class="lg-label">Branch</label>
                    <div class="lg-input"><i class="fa-solid fa-building-columns"></i>
                        <select name="branch_id" required>
                            <option value="">-- Branch সিলেক্ট করুন --</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo (int) $b['id']; ?>"><?php echo sp_e($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>
                <?php endif; ?>

                <div class="lg-field">
                    <label class="lg-label">User Name (Student ID)</label>
                    <div class="lg-input"><i class="fa-solid fa-user"></i>
                        <input type="text" name="student_id" placeholder="যেমন: 260101003" required autofocus></div>
                </div>
                <div class="lg-field">
                    <label class="lg-label">Password</label>
                    <div class="lg-input"><i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" placeholder="আপনার পাসওয়ার্ড" required>
                        <button type="button" class="lg-eye" title="দেখান/লুকান"><i class="fa-solid fa-eye"></i></button></div>
                </div>
                <?php echo login_ui_captcha(captcha_svg_markup($student_captcha_code)); ?>
                <button type="submit" name="login" class="lg-btn">সাইন-ইন করুন <i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <?php if ($sp_show_first_login_note): ?>
            <div class="lg-note"><i class="fa-solid fa-circle-info"></i> প্রথমবার লগইনের সময় Password ফিল্ডে ডিফল্ট পাসওয়ার্ড দিন (স্কুল থেকে জেনে নিন)। লগইন করার পর নিজের পাসওয়ার্ড পরিবর্তন করে নিন।</div>
            <?php endif; ?>

            <?php echo login_ui_switch([
                ['label' => 'শিক্ষক', 'href' => '../teacher/login.php', 'icon' => 'fa-chalkboard-user'],
                ['label' => 'অ্যাডমিন', 'href' => '../admin/', 'icon' => 'fa-gauge-high'],
            ]); ?>
        </div>
    </section>
</main>
<?php echo login_ui_script(); ?>
</body>
</html>
