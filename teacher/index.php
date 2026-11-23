<?php
// teacher/index.php - School ERP Teacher Portal (Dashboard শেল + রাউটার)
require_once __DIR__ . '/_bootstrap.php';
ob_start(); // পুরো পেজ output buffer করা হচ্ছে (admin/index.php এর মতোই) — এখানে include হওয়া
            // পেজগুলো (homework.php, live_class.php, recorded_class.php ইত্যাদি) POST হ্যান্ডেল
            // করে header('Location: ...') দিয়ে redirect করে; HTML প্রিন্ট শুরু হয়ে যাওয়ার পরও
            // সেটা যাতে "headers already sent" warning ছাড়াই কাজ করে
tp_require_login();

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

// আবার প্রতিটা রিকোয়েস্টে নিজের সবশেষ তথ্য DB থেকে রিফ্রেশ (নাম/ছবি বদলে থাকলে)
$stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['teacher_id']]);
$teacher = $stmt->fetch();
if (!$teacher || $teacher['status'] !== 'Active') {
    // এমপ্লয়ি ডিলিট/ইনঅ্যাক্টিভ হয়ে গেলে সাথে সাথে লগআউট
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit();
}

// Change Password Handler
$pass_msg = '';
$pass_err = '';
if (!erp_is_demo() && isset($_POST['change_password'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $pass_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 12) {
            $pass_err = 'নতুন পাসওয়ার্ড কমপক্ষে ১২ ক্যারেক্টার হতে হবে!';
        } elseif ($new_pass !== $confirm_pass) {
            $pass_err = 'New password and confirm password do not match!';
        } elseif (!password_verify($current_pass, $teacher['password'] ?? '')) {
            $pass_err = 'Current password is incorrect!';
        } else {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE employees SET password = ?, must_change_password = 0 WHERE id = ?')
                ->execute([$hashed, $teacher['id']]);
            $teacher['must_change_password'] = 0;
            $pass_msg = 'Password changed successfully!';
        }
    }
}

$page = $_GET['page'] ?? 'dashboard';
if(!erp_is_demo() && !empty($teacher['must_change_password']) && ($page!=='dashboard' || (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !isset($_POST['change_password']))))erp_stop(403,'Change your initial password from the portal dashboard before using other features.');

$page_map = [
    'dashboard'                  => __DIR__ . '/dashboard.php',
    'routine_view'                => __DIR__ . '/routine_view.php', // টিচারের নিজের রুটিন (admin এর ক্লাস-ভিত্তিক ভিউ না)
    'hr_view_info'                => __DIR__ . '/hr_view_info.php',
    'hr_update_info'              => __DIR__ . '/hr_update_info.php',
    'result_exam_routine'         => __DIR__ . '/../admin/result_exam_routine.php',
    'result_marks_entry_subject'  => __DIR__ . '/../admin/result_marks_entry_subject.php',
    'result_formative_entry'      => __DIR__ . '/../admin/result_formative_entry.php',
    'result_formative_report'     => __DIR__ . '/../admin/result_formative_report.php',
    'homework'                    => __DIR__ . '/../admin/homework.php',
    'lesson_plan'                 => __DIR__ . '/../admin/lesson_plan.php',
    'teacher_questions'           => __DIR__ . '/../admin/teacher_questions.php',
    'live_class'                  => __DIR__ . '/../admin/live_class.php',
    'live_class_host'             => __DIR__ . '/../admin/live_class_host.php',
    'recorded_class'              => __DIR__ . '/../admin/recorded_class.php',
];

$breadcrumb_labels = [
    'dashboard'                  => 'Dashboard',
    'routine_view'                => 'Routine Module &gt; My Class Routine',
    'hr_view_info'                => 'Human Resource &gt; HR Module &gt; View Information',
    'hr_update_info'              => 'Human Resource &gt; HR Module &gt; Update Information',
    'result_exam_routine'         => 'Result Module &gt; Exam Procedure &gt; Exam Routine',
    'result_marks_entry_subject'  => 'Result Module &gt; Exam Procedure &gt; Marks Entry (Subject)',
    'result_formative_entry'      => 'Result Module &gt; Exam Procedure &gt; Formative / Class Test Entry',
    'result_formative_report'     => 'Result Module &gt; Exam Procedure &gt; Formative / Class Test Report',
    'homework'                    => 'E-Learning Module &gt; Homework',
    'lesson_plan'                 => 'E-Learning Module &gt; Lesson Plan',
    'teacher_questions'           => 'Result Module &gt; Exam Procedure &gt; Question Upload',
    'live_class'                  => 'E-Learning Module &gt; Live Class',
    'live_class_host'             => 'E-Learning Module &gt; Live Class &gt; Host',
    'recorded_class'              => 'E-Learning Module &gt; Recorded Class',
];

$target_file = $page_map[$page] ?? __DIR__ . '/dashboard.php';
if (!isset($page_map[$page])) { $page = 'dashboard'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Portal - <?php echo tp_e($GLOBALS['tenant']['school_name'] ?? 'School ERP'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0a2540;
            --accent: #38bdf8;
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
        .sidebar.collapsed .sidebar-search,
        .sidebar.collapsed .menu-text { display: none !important; }
        .sidebar.collapsed .sidebar-menu li a { justify-content: center; padding: 12px 0; }
        .sidebar.collapsed .sidebar-menu li a i.menu-icon {
            font-size: 18px; width: 40px; height: 40px; background: #1e293b;
            color: #94a3b8; border-radius: 50%; display: flex; align-items: center;
            justify-content: center; border: 1px solid #334155; transition: all 0.2s;
        }

        .sidebar-brand {
            background: #090d16; color: white; padding: 15px 20px;
            font-size: 18px; font-weight: 800; white-space: nowrap; height: 60px; border-bottom: 1px solid #1e293b;
        }
        .sidebar-search { padding: 15px; border-bottom: 1px solid #1e293b; }
        .sidebar-search input { font-size: 13px; background: #1e293b; border-color: #334155; color: white; }
        .sidebar-search input::placeholder { color: #94a3b8; }

        .sidebar-menu { list-style: none; padding: 10px 0; margin: 0; white-space: nowrap; }
        .sidebar-menu li a {
            color: #cbd5e1; text-decoration: none; display: flex;
            align-items: center; justify-content: space-between; padding: 11px 20px;
            font-size: 13.5px; font-weight: 600; transition: all 0.2s; border-left: 4px solid transparent;
        }
        .sidebar-menu li a:hover, .sidebar-menu li a.active {
            background: #1e293b; color: #38bdf8; border-left-color: #38bdf8;
        }
        .sidebar-menu li a i { width: 25px; color: #94a3b8; }

        .submenu { list-style: none; padding-left: 0; background: #090d16; border-bottom: 1px solid #1e293b; }
        .submenu li a { padding: 8px 20px 8px 35px !important; font-size: 12.5px !important; color: #94a3b8 !important; border-left: none !important; }
        .submenu li a:hover { color: #38bdf8 !important; background: #1e293b; }
        .submenu li.has-submenu > a i { width: auto; font-size: 10px !important; color: #64748b; }
        .submenu-nested { border-bottom: none; }
        .submenu-nested li a { padding-left: 50px !important; font-size: 12px !important; }

        .main-wrapper {
            margin-left: var(--sidebar-width); min-height: 100vh;
            display: flex; flex-direction: column; transition: all 0.3s ease-in-out;
        }
        .main-wrapper.expanded { margin-left: var(--sidebar-collapsed-width); }

        /* সব পেজের প্রিন্ট: sidebar/topbar লুকিয়ে কনটেন্ট পুরো পাতায় (পেজ-নির্দিষ্ট প্রিন্ট CSS এর আগে কাজ করে) */
        @media print {
            html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
            .sidebar, .sidebar-overlay, .sticky-top-area, .top-navbar, .breadcrumb-bar, .sidebar-toggle-btn { display: none !important; }
            .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; display: block !important; }
            .content-body { padding: 0 !important; margin: 0 !important; }
            .card-custom { box-shadow: none !important; }
            .table-responsive { overflow: visible !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        .sticky-top-area { position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .top-navbar {
            background: var(--primary); color: white; padding: 0px 20px; height: 60px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .top-navbar .nav-icons { display: flex; gap: 12px; align-items: center; }
        .top-navbar .nav-icons a { color: white; font-size: 16px; text-decoration: none; position: relative; }

        .dropdown-menu .dropdown-item { color: #334155 !important; font-weight: 600; }
        .dropdown-menu .dropdown-item:hover { background-color: #f1f5f9 !important; color: #0284c7 !important; }

        .breadcrumb-bar {
            background: #ffffff; padding: 10px 30px; font-size: 13px; color: #64748b;
            border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;
        }
        .breadcrumb-bar b { color: var(--primary); }

        .content-body { padding: 25px 30px; flex: 1; }
        .top-stat-box {
            border-radius: 8px; padding: 18px 15px; color: white;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); height: 100%;
        }
        .top-stat-box h3 { font-size: 20px; font-weight: 800; margin: 0; line-height: 1.2; }
        .top-stat-box p { font-size: 11.5px; margin: 0; opacity: 0.9; font-weight: 500; }
        .top-stat-icon {
            width: 50px; height: 50px; background: rgba(255, 255, 255, 0.2);
            border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;
        }
        .card-custom {
            background: white; border-radius: 10px; border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); padding: 25px; margin-bottom: 25px;
        }
    </style>
</head>
<body>
<?php if (erp_is_demo()) require __DIR__ . '/../demo/banner.php'; ?>

    <!-- SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- STICKY TOP AREA -->
        <div class="sticky-top-area">
            <div class="top-navbar">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge" style="background:#38bdf8;color:#0a2540;font-weight:700;">Teacher Portal</span>
                </div>

                <div class="nav-icons">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center gap-1 text-decoration-none dropdown-toggle text-white" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if (!empty($teacher['photo_path'])): ?>
                                <img src="../<?php echo tp_e($teacher['photo_path']); ?>" style="width:26px;height:26px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <i class="fa-solid fa-user-circle fs-5"></i>
                            <?php endif; ?>
                            <span class="fs-6"><?php echo tp_e($teacher['name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="font-size: 13.5px;">
                            <?php if (!erp_is_demo()): ?><li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-key text-primary me-2"></i> Change Password</a></li><?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item text-danger fw-bold py-2" href="index.php?logout=true"><i class="fa-solid fa-power-off me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- BREADCRUMB -->
            <div class="breadcrumb-bar">
                <span>Home &gt; Teacher Portal &gt; <b><?php echo $breadcrumb_labels[$page] ?? 'Dashboard'; ?></b></span>
                <span class="text-muted">ID: <?php echo tp_e($teacher['employee_id']); ?></span>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content-body">
            <?php if (!erp_is_demo() && !empty($teacher['must_change_password'])): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center py-2">
                    <span><i class="fa-solid fa-triangle-exclamation me-1"></i> আপনি এখনো ডিফল্ট পাসওয়ার্ড ব্যবহার করছেন। নিরাপত্তার জন্য পাসওয়ার্ড পরিবর্তন করে নিন।</span>
                    <a href="#" class="btn btn-sm btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#changePasswordModal">Change Now</a>
                </div>
            <?php endif; ?>
            <?php if ($pass_msg): ?><div class="alert alert-success py-2"><?php echo tp_e($pass_msg); ?></div><?php endif; ?>
            <?php if ($pass_err): ?><div class="alert alert-danger py-2"><?php echo tp_e($pass_err); ?></div><?php endif; ?>

            <?php include $target_file; ?>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal fade" id="changePasswordModal" <?php if (erp_is_demo()) echo 'style="display:none!important"'; ?> tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="modal-header" style="background:#0a2540;color:#fff;">
                        <h5 class="modal-title fs-6" id="changePasswordModalLabel"><i class="fa-solid fa-key me-2"></i> Change Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold" style="font-size: 13px;">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="change_password" <?php if (erp_is_demo()) echo 'disabled title="Disabled in public demo"'; ?> class="btn btn-success btn-sm">Update Password</button>
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
<?php require_once __DIR__ . '/../config/searchable_select.php'; echo searchable_select_assets(); // বড় ড্রপডাউনে টাইপ করে সার্চ ?>
<?php require_once __DIR__ . '/../config/table_print.php'; echo tprint_assets($pdo, $page ?? 'dashboard', 'teacher'); ?>
</body>
</html>
