<?php
require_once __DIR__ . '/../config/runtime.php';
erp_session_start();
require_once __DIR__ . '/../config/master_db.php'; // $pdo_master
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/theme.php';   // থিম ইঞ্জিন + ব্র্যান্ডিং
require_once __DIR__ . '/_access.php'; // master_users + মাইগ্রেশন

ensureMasterUsersTable($pdo_master);
require_once __DIR__.'/../config/auth_rate_limit.php';
if(isset($_POST['login']))erp_login_limit($pdo_master,'master');

if (!empty($_SESSION['master_user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
if (($_GET['idle'] ?? '') === '1') {
    $error = 'Session-এর মেয়াদ শেষ, আবার লগইন করো।';
}
if (isset($_POST['login'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $pdo_master->prepare('SELECT * FROM master_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && $row['is_active'] && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['master_user_id'] = $row['id'];
            $_SESSION['master_name']    = $row['name'];
            $_SESSION['master_role']    = $row['role'];
            $pdo_master->prepare('UPDATE master_users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
            header('Location: dashboard.php');
            exit();
        }
        $error = 'ভুল ইউজারনেম অথবা পাসওয়ার্ড, অথবা অ্যাকাউন্ট নিষ্ক্রিয়!';
    }
}

$branding = getSaasBranding($pdo_master);
?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($branding['name']); ?> — Master Panel Login</title>
<?php if ($branding['favicon']): ?><link rel="icon" href="<?php echo htmlspecialchars($branding['favicon']); ?>"><?php endif; ?>
<style>
<?php echo renderThemeCSS($pdo_master); ?>
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--page-bg);color:var(--body-text);font-family:var(--font-family);font-weight:var(--font-weight-base);font-style:var(--font-style-base);min-height:100vh;display:flex;align-items:center;justify-content:center}
.card{background:var(--card-bg);border:1px solid var(--sidebar-border);border-radius:var(--border-radius);padding:40px;width:360px}
.logo{text-align:center;margin-bottom:28px}
.logo img{max-width:120px;max-height:64px;object-fit:contain;margin-bottom:10px;}
.logo h1{font-size:22px;color:var(--brand-text);letter-spacing:1px}
.logo p{font-size:12px;color:var(--topbar-text);font-weight:700;margin-top:4px}
label{display:block;font-size:13px;color:var(--sidebar-text);margin-bottom:6px;margin-top:16px}
input{width:100%;background:var(--sidebar-bg);border:1px solid var(--sidebar-border);border-radius:8px;padding:10px 14px;color:var(--body-text);font-size:14px;outline:none}
input:focus{border-color:var(--sidebar-accent)}
.btn{width:100%;background:var(--sidebar-accent);color:#fff;border:none;padding:12px;border-radius:8px;font-size:15px;cursor:pointer;margin-top:24px;font-weight:var(--font-weight-medium)}
.btn:hover{filter:brightness(0.9);}
.error{background:#2a1010;border:1px solid #5a1a1a;color:#ff6b6b;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:12px}
<?php echo renderFooterCreditCSS(); ?>
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <?php if ($branding['logo']): ?>
      <img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="Logo">
      <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
    <?php else: ?>
      <h1>🏫 <?php echo htmlspecialchars($branding['name']); ?></h1>
    <?php endif; ?>
    <p>Master Control Panel</p>
  </div>
  <?php if ($error): ?><div class="error">❌ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <form method="POST">
    <?php echo csrf_field(); ?>
    <label>Username</label>
    <input type="text" name="username" required autofocus>
    <label>Password</label>
    <input type="password" name="password" required>
    <button class="btn" type="submit" name="login">Login করুন</button>
  </form>
  <?php echo renderFooterCredit($pdo_master, 'login'); ?>
</div>
</body>
</html>
