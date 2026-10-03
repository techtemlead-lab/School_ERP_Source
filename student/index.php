<?php
// student/index.php - School ERP Student/Guardian Portal (Dashboard শেল + রাউটার)
require_once __DIR__ . '/_bootstrap.php';
ob_start(); // admin/index.php এর মতোই — include হওয়া পেজ থেকে header('Location: ...') redirect
            // করলে যাতে "headers already sent" warning না আসে
sp_require_login();

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

// প্রতিটা রিকোয়েস্টে নিজের সবশেষ তথ্য DB থেকে রিফ্রেশ
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['student_pk']]);
$student = $stmt->fetch();
if (!$student || $student['status'] !== 'Active') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

// Change Password Handler
$is_demo = (($GLOBALS['tenant']['school_code'] ?? ($_SESSION['tenant_school_code'] ?? '')) === 'demo');
$pass_msg = '';
$pass_err = '';
if (isset($_POST['change_password'])) {
    if ($is_demo) {
        $pass_err = 'Demo Sandbox মোডে পাসওয়ার্ড পরিবর্তন বন্ধ রাখা হয়েছে।';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $pass_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 6) {
            $pass_err = 'নতুন পাসওয়ার্ড কমপক্ষে ৬ ক্যারেক্টার হতে হবে!';
        } elseif ($new_pass !== $confirm_pass) {
            $pass_err = 'New password and confirm password do not match!';
        } elseif (!password_verify($current_pass, $student['password'] ?? '')) {
            $pass_err = 'Current password is incorrect!';
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE students SET password = ?, must_change_password = 0 WHERE id = ?')
                ->execute([$hashed, $student['id']]);
            $student['must_change_password'] = 0;
            $pass_msg = 'Password changed successfully!';
        }
    }
}

$page = $_GET['page'] ?? 'dashboard';

$page_map = [
    'dashboard'       => __DIR__ . '/dashboard.php',
    'academic_calendar' => __DIR__ . '/academic_calendar.php',
    'my_profile'      => __DIR__ . '/my_profile.php',
    'update_profile'  => __DIR__ . '/update_profile.php',
    'live_class'      => __DIR__ . '/live_class.php',
    'recorded_class'  => __DIR__ . '/recorded_class.php',
    'home_work'       => __DIR__ . '/home_work.php',
    'assignment'      => __DIR__ . '/assignment.php',
    'lesson_plan'     => __DIR__ . '/lesson_plan.php',
    'my_teacher'      => __DIR__ . '/my_teacher.php',
    'my_syllabus'     => __DIR__ . '/my_syllabus.php',
    'my_attendance'   => __DIR__ . '/my_attendance.php',
    'class_routine'   => __DIR__ . '/class_routine.php',
    'exam_routine'    => __DIR__ . '/exam_routine.php',
    'seat_plan'       => __DIR__ . '/seat_plan.php',
    'admit_card'      => __DIR__ . '/admit_card.php',
    'report_card'     => __DIR__ . '/report_card.php',
    'my_dues'         => __DIR__ . '/my_dues.php',
];

$breadcrumb_labels = [
    'dashboard'       => 'Dashboard',
    'academic_calendar' => 'Academic Calendar',
    'my_profile'      => 'My Profile',
    'update_profile'  => 'Update Profile',
    'live_class'      => 'My Live Class',
    'recorded_class'  => 'My Recorded Class',
    'home_work'       => 'My Home Work',
    'assignment'      => 'My Assignment',
    'lesson_plan'     => 'My Lesson Plan',
    'my_teacher'      => 'My Teacher',
    'my_syllabus'     => 'My Syllabus',
    'my_attendance'   => 'My Attendance',
    'class_routine'   => 'My Class Routine',
    'exam_routine'    => 'My Exam Routine',
    'seat_plan'       => 'My Seat Plan',
    'admit_card'      => 'My Admit Card',
    'report_card'     => 'My Report Card',
    'my_dues'         => 'My Dues (Monthly)',
];

$target_file = $page_map[$page] ?? __DIR__ . '/dashboard.php';
if (!isset($page_map[$page])) { $page = 'dashboard'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - <?php echo sp_e($GLOBALS['tenant']['school_name'] ?? 'School ERP'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #0f172a;
            --accent: #14b8a6;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 80px;
        }
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, sans-serif; overflow-x: hidden; }

        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: #0f172a; border-right: 1px solid #1e293b;
            z-index: 1000; display: flex; flex-direction: column;
            overflow-y: auto; overflow-x: hidden; transition: all 0.3s ease-in-out;
        }
        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        .sidebar.collapsed .brand-text,
        .sidebar.collapsed .menu-text { display: none !important; }
        .sidebar.collapsed .sidebar-menu li a { justify-content: center; padding: 12px 0; }
        .sidebar.collapsed .sidebar-menu li a i.menu-icon {
            font-size: 18px; width: 40px; height: 40px; background: #1e293b;
            color: #94a3b8; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; border: 1px solid #334155; transition: all 0.2s;
        }

        .sidebar-brand {
            background: #090d16; color: white; padding: 15px 20px;
            font-size: 17px; font-weight: 800; white-space: nowrap; height: 60px; border-bottom: 1px solid #1e293b;
            display: flex; align-items: center; justify-content: space-between;
        }

        .sidebar-menu { list-style: none; padding: 10px 0; margin: 0; white-space: nowrap; }
        .sidebar-menu li a {
            color: #cbd5e1; text-decoration: none; display: flex;
            align-items: center; gap: 10px; padding: 10px 20px;
            font-size: 13.5px; font-weight: 600; transition: all 0.2s; border-left: 4px solid transparent;
        }
        .sidebar-menu li a:hover, .sidebar-menu li a.active {
            background: #1e293b; color: #2dd4bf; border-left-color: #2dd4bf;
        }
        .sidebar-menu li a i.menu-icon { width: 22px; text-align: center; color: #94a3b8; }
        .sidebar-menu li a.active i.menu-icon, .sidebar-menu li a:hover i.menu-icon { color: #2dd4bf; }

        .main-wrapper {
            margin-left: var(--sidebar-width); min-height: 100vh;
            display: flex; flex-direction: column; transition: all 0.3s ease-in-out;
        }
        .main-wrapper.expanded { margin-left: var(--sidebar-collapsed-width); }

        .sticky-top-area { position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .top-navbar {
            background: var(--primary); color: white; padding: 0px 20px; height: 60px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .breadcrumb-bar {
            background: #ffffff; padding: 10px 30px; font-size: 13px; color: #64748b;
            border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;
        }
        .breadcrumb-bar b { color: var(--primary); }

        .content-body { padding: 25px 30px; flex: 1; }
        .card-custom {
            background: white; border-radius: 10px; border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 22px; margin-bottom: 22px;
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper" id="mainWrapper">

        <div class="sticky-top-area">
            <div class="top-navbar">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-bars fs-5 text-white" id="toggleSidebar" style="cursor:pointer;"></i>
                    <span class="badge" style="background:#14b8a6;color:#0f172a;font-weight:700;">Student Portal</span>
                    <?php if ($is_demo): ?>
                        <a href="../demo/index.php" class="badge bg-warning text-dark text-decoration-none fw-bold ms-1" title="Demo Sandbox"><i class="fa-solid fa-flask me-1"></i>Demo</a>
                    <?php endif; ?>
                </div>
                <div class="nav-icons">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center gap-1 text-decoration-none dropdown-toggle text-white" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if (!empty($student['photo_path'])): ?>
                                <img src="../<?php echo sp_e($student['photo_path']); ?>" style="width:26px;height:26px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <i class="fa-solid fa-circle-user fs-5"></i>
                            <?php endif; ?>
                            <span class="fs-6"><?php echo sp_e($student['name_en']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="font-size:13.5px;">
                            <li><a class="dropdown-item py-2" href="index.php?page=my_profile"><i class="fa-solid fa-id-card text-primary me-2"></i> My Profile</a></li>
                            <?php if (!$is_demo): ?>
                                <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-key text-primary me-2"></i> Change Password</a></li>
                            <?php else: ?>
                                <li><span class="dropdown-item py-2 text-muted disabled"><i class="fa-solid fa-lock text-muted me-2"></i> Change Password (Demo Locked)</span></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item text-danger fw-bold py-2" href="index.php?logout=true"><i class="fa-solid fa-power-off me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="breadcrumb-bar">
                <span>Home &gt; Student Portal &gt; <b><?php echo sp_e($breadcrumb_labels[$page] ?? 'Dashboard'); ?></b></span>
                <span class="text-muted">ID: <?php echo sp_e($student['student_id']); ?></span>
            </div>
        </div>

        <div class="content-body">
            <?php if (!empty($student['must_change_password']) && !$is_demo): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center py-2">
                    <span><i class="fa-solid fa-triangle-exclamation me-1"></i> আপনি এখনো ডিফল্ট পাসওয়ার্ড ব্যবহার করছেন। নিরাপত্তার জন্য পাসওয়ার্ড পরিবর্তন করে নিন।</span>
                    <a href="#" class="btn btn-sm btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#changePasswordModal">Change Now</a>
                </div>
            <?php endif; ?>
            <?php if ($pass_msg): ?><div class="alert alert-success py-2"><?php echo sp_e($pass_msg); ?></div><?php endif; ?>
            <?php if ($pass_err): ?><div class="alert alert-danger py-2"><?php echo sp_e($pass_err); ?></div><?php endif; ?>

            <?php include $target_file; ?>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="modal-header" style="background:#0f172a;color:#fff;">
                        <h5 class="modal-title fs-6"><i class="fa-solid fa-key me-2"></i> Change Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($is_demo): ?>
                            <div class="alert alert-warning py-2 mb-3">
                                <i class="fa-solid fa-lock me-1"></i> <strong>Demo Sandbox:</strong> পাসওয়ার্ড পরিবর্তন সুবিধা ডেমো অ্যাকাউন্টের জন্য নিষ্ক্রিয় রাখা হয়েছে।
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size:13px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" <?php echo $is_demo ? 'disabled' : 'required'; ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size:13px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" <?php echo $is_demo ? 'disabled' : 'required'; ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size:13px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" <?php echo $is_demo ? 'disabled' : 'required'; ?>>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="change_password" class="btn btn-sm text-white" style="background:#0f766e;" <?php echo $is_demo ? 'disabled' : ''; ?>>Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function () {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('mainWrapper').classList.toggle('expanded');
        });
    </script>
    <script>
        // Mouse Scroll করলে ফোকাসড <input type="number"> এর ভ্যালু যেন ভুলবশত বদলে না যায় —
        // scroll করার সাথে সাথে inputটা blur করে দেওয়া হচ্ছে, তাতে পেজ স্বাভাবিকভাবে স্ক্রল হবে
        // কিন্তু নাম্বার বদলাবে না।
        document.addEventListener('wheel', function (e) {
            var el = document.activeElement;
            if (el && el.tagName === 'INPUT' && el.type === 'number') {
                el.blur();
            }
        }, { passive: true });
    </script>
</body>
</html>
