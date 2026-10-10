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
$tp_show_first_login_note = erp_env('APP_ENV') !== 'production' && empty($_COOKIE['tp_seen_first_login_note']);

$inst_name = 'School ERP';
try {
    $inst_name = $pdo->query("SELECT institute_name FROM institute_settings WHERE id = 1")->fetchColumn() ?: $inst_name;
} catch (\Throwable $e) { /* table হয়তো এখনো নাই */ }

// প্রতিবার লগইন ফর্ম রেন্ডার হওয়ার আগে নতুন ভেরিফিকেশন কোড
$teacher_captcha_code = captcha_new('captcha_teacher');

require_once __DIR__ . '/../config/login_ui.php';
$lg_logo = login_ui_logo_url($pdo, '../');
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Login - <?php echo tp_e($inst_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo login_ui_css('teacher'); ?></style>
</head>
<body class="lg">
<main class="lg-shell">
    <?php echo login_ui_aside('teacher', $inst_name, $lg_logo); ?>

    <section class="lg-main">
        <div class="lg-card">
            <div class="lg-bar"></div>
            <h2>শিক্ষক সাইন-ইন</h2>
            <p class="lg-sub">এমপ্লয়ি আইডি ও পাসওয়ার্ড দিয়ে প্রবেশ করুন</p>

            <?php if ($login_error): ?><div class="lg-alert err"><i class="fa-solid fa-circle-exclamation mt-1"></i><span><?php echo tp_e($login_error); ?></span></div><?php endif; ?>

            <form method="POST" autocomplete="on">
                <?php echo csrf_field(); ?>
                <div class="lg-field">
                    <label class="lg-label">Employee (Teacher) ID</label>
                    <div class="lg-input"><i class="fa-solid fa-id-badge"></i>
                        <input type="text" name="employee_id" placeholder="যেমন: 20260001" required autofocus></div>
                </div>
                <div class="lg-field">
                    <label class="lg-label">Password</label>
                    <div class="lg-input"><i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" placeholder="আপনার পাসওয়ার্ড" required>
                        <button type="button" class="lg-eye" title="দেখান/লুকান"><i class="fa-solid fa-eye"></i></button></div>
                </div>
                <?php echo login_ui_captcha(captcha_svg_markup($teacher_captcha_code)); ?>
                <button type="submit" name="login" class="lg-btn">সাইন-ইন করুন <i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <?php if ($tp_show_first_login_note): ?>
            <div class="lg-note"><i class="fa-solid fa-circle-info"></i> প্রথমবার লগইনের সময় Password ফিল্ডে আপনার নিজের Employee ID দিন। লগইন করার পর নিজের পাসওয়ার্ড পরিবর্তন করে নিন।</div>
            <?php endif; ?>

            <?php echo login_ui_switch([
                ['label' => 'অ্যাডমিন', 'href' => '../admin/', 'icon' => 'fa-gauge-high'],
                ['label' => 'শিক্ষার্থী / অভিভাবক', 'href' => '../student/login.php', 'icon' => 'fa-user-graduate'],
            ]); ?>
        </div>
    </section>
</main>
<?php echo login_ui_script(); ?>
</body>
</html>
