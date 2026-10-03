<?php
// admin/website_misc.php - Website Module (Career List / Board Result / Useful Links / Social Media)
// একটাই ফাইলে চারটা সাব-মডিউল, sidebar এ website_career / website_board_result /
// website_useful_links / website_social — চারটা slug-ই এই ফাইলে আসে (admin/index.php এ ম্যাপ করা)।
// List view + Add/Edit view আলাদা — academic_session.php এর প্যাটার্ন অনুসরণ করে।

$wms_msg = $_SESSION['wms_msg'] ?? '';
$wms_err = $_SESSION['wms_err'] ?? '';
unset($_SESSION['wms_msg'], $_SESSION['wms_err']);

$active_tab = 'career';
if ($page === 'website_board_result') $active_tab = 'board_result';
if ($page === 'website_useful_links') $active_tab = 'useful_links';
if ($page === 'website_social') $active_tab = 'social';

$view = $_GET['view'] ?? 'list';

// -----------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_careers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        position_title VARCHAR(150) NOT NULL,
        department VARCHAR(150) NULL,
        deadline_date DATE NULL,
        description TEXT NULL,
        apply_link VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_board_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        exam_year VARCHAR(20) NOT NULL,
        result_link VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_useful_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_social_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(50) NOT NULL,
        url VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$social_platforms = ['Facebook', 'YouTube', 'Twitter / X', 'Instagram', 'LinkedIn', 'WhatsApp', 'TikTok', 'Other'];

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wms_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    // ---------- CAREER ----------
    if (isset($_POST['save_career'])) {
        $id       = (int) ($_POST['id'] ?? 0);
        $title    = trim($_POST['position_title'] ?? '');
        $dept     = trim($_POST['department'] ?? '');
        $deadline = trim($_POST['deadline_date'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $link     = trim($_POST['apply_link'] ?? '');

        if ($title === '') {
            $_SESSION['wms_err'] = 'পদের নাম আবশ্যক।';
            header('Location: index.php?page=website_career&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_careers SET position_title=?, department=?, deadline_date=?, description=?, apply_link=? WHERE id=?")
                ->execute([$title, $dept ?: null, $deadline ?: null, $desc ?: null, $link ?: null, $id]);
            $_SESSION['wms_msg'] = 'নিয়োগ বিজ্ঞপ্তি আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Career বিজ্ঞপ্তি আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_careers (position_title, department, deadline_date, description, apply_link) VALUES (?, ?, ?, ?, ?)")
                ->execute([$title, $dept ?: null, $deadline ?: null, $desc ?: null, $link ?: null]);
            $_SESSION['wms_msg'] = 'নিয়োগ বিজ্ঞপ্তি যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Career বিজ্ঞপ্তি যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_career');
        exit();
    } elseif (isset($_POST['toggle_career'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_careers SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_career'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_career = $pdo->prepare("SELECT position_title FROM website_careers WHERE id = ?");
        $del_career->execute([$id]);
        $del_career_title = $del_career->fetchColumn();
        $pdo->prepare("DELETE FROM website_careers WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'নিয়োগ বিজ্ঞপ্তি মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Career বিজ্ঞপ্তি ডিলিট করা হয়েছে: " . ($del_career_title ?: "#$id"));
    }

    // ---------- BOARD RESULT ----------
    elseif (isset($_POST['save_board_result'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $year  = trim($_POST['exam_year'] ?? '');
        $link  = trim($_POST['result_link'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || $year === '' || $link === '') {
            $_SESSION['wms_err'] = 'শিরোনাম, সাল ও লিংক আবশ্যক।';
            header('Location: index.php?page=website_board_result&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_board_results SET title=?, exam_year=?, result_link=?, sort_order=? WHERE id=?")
                ->execute([$title, $year, $link, $sort, $id]);
            $_SESSION['wms_msg'] = 'বোর্ড রেজাল্ট আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Board Result আপডেট করা হয়েছে: {$title} ({$year})");
        } else {
            $pdo->prepare("INSERT INTO website_board_results (title, exam_year, result_link, sort_order) VALUES (?, ?, ?, ?)")
                ->execute([$title, $year, $link, $sort]);
            $_SESSION['wms_msg'] = 'বোর্ড রেজাল্ট যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Board Result যুক্ত করা হয়েছে: {$title} ({$year})");
        }
        header('Location: index.php?page=website_board_result');
        exit();
    } elseif (isset($_POST['toggle_board_result'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_board_results SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_board_result'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_board = $pdo->prepare("SELECT title FROM website_board_results WHERE id = ?");
        $del_board->execute([$id]);
        $del_board_title = $del_board->fetchColumn();
        $pdo->prepare("DELETE FROM website_board_results WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'বোর্ড রেজাল্ট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Board Result ডিলিট করা হয়েছে: " . ($del_board_title ?: "#$id"));
    }

    // ---------- USEFUL LINKS ----------
    elseif (isset($_POST['save_useful_link'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $url   = trim($_POST['url'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || $url === '') {
            $_SESSION['wms_err'] = 'শিরোনাম ও লিংক আবশ্যক।';
            header('Location: index.php?page=website_useful_links&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_useful_links SET title=?, url=?, sort_order=? WHERE id=?")
                ->execute([$title, $url, $sort, $id]);
            $_SESSION['wms_msg'] = 'লিংক আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Useful Link আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_useful_links (title, url, sort_order) VALUES (?, ?, ?)")
                ->execute([$title, $url, $sort]);
            $_SESSION['wms_msg'] = 'লিংক যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Useful Link যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_useful_links');
        exit();
    } elseif (isset($_POST['toggle_useful_link'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_useful_links SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_useful_link'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_link = $pdo->prepare("SELECT title FROM website_useful_links WHERE id = ?");
        $del_link->execute([$id]);
        $del_link_title = $del_link->fetchColumn();
        $pdo->prepare("DELETE FROM website_useful_links WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'লিংক মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Useful Link ডিলিট করা হয়েছে: " . ($del_link_title ?: "#$id"));
    }

    // ---------- SOCIAL MEDIA ----------
    elseif (isset($_POST['save_social'])) {
        $id       = (int) ($_POST['id'] ?? 0);
        $platform = trim($_POST['platform'] ?? '');
        $url      = trim($_POST['url'] ?? '');
        $sort     = (int) ($_POST['sort_order'] ?? 0);

        if ($platform === '' || $url === '') {
            $_SESSION['wms_err'] = 'প্ল্যাটফর্ম ও লিংক আবশ্যক।';
            header('Location: index.php?page=website_social&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_social_media SET platform=?, url=?, sort_order=? WHERE id=?")
                ->execute([$platform, $url, $sort, $id]);
            $_SESSION['wms_msg'] = 'সোশ্যাল মিডিয়া লিংক আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Social Media লিংক আপডেট করা হয়েছে: {$platform}");
        } else {
            $pdo->prepare("INSERT INTO website_social_media (platform, url, sort_order) VALUES (?, ?, ?)")
                ->execute([$platform, $url, $sort]);
            $_SESSION['wms_msg'] = 'সোশ্যাল মিডিয়া লিংক যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Social Media লিংক যুক্ত করা হয়েছে: {$platform}");
        }
        header('Location: index.php?page=website_social');
        exit();
    } elseif (isset($_POST['toggle_social'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_social_media SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_social'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_social = $pdo->prepare("SELECT platform FROM website_social_media WHERE id = ?");
        $del_social->execute([$id]);
        $del_social_platform = $del_social->fetchColumn();
        $pdo->prepare("DELETE FROM website_social_media WHERE id = ?")->execute([$id]);
        $_SESSION['wms_msg'] = 'সোশ্যাল মিডিয়া লিংক মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Social Media লিংক ডিলিট করা হয়েছে: " . ($del_social_platform ?: "#$id"));
    }

    header('Location: index.php?page=' . $page);
    exit();
}

function wmsv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-globe"></i> Website Module</h4>


    <!-- সারিওয়ারি Delete বাটন আগে প্রতিটা নিজের ছোট্ট &lt;form&gt;-এ মোড়ানো ছিল, আর সেই ফর্মগুলো
         থাকত নিচের টেবিলটা মোড়ানো GET ফিল্টার ফর্মের ভেতরে — অর্থাৎ ফর্মের ভেতরে ফর্ম। HTML-এ
         নেস্টেড &lt;form&gt; বৈধ না, তাই ব্রাউজার ভেতরের ফর্মটাকে বাতিল করে তার ইনপুট/বাটনগুলো
         বাইরের GET ফিল্টার ফর্মেই বসিয়ে দিত। ফলে Delete-এ ক্লিক করলে আসল POST ডিলিট রিকোয়েস্টের
         বদলে ফিল্টার ফর্মটাই (GET, action=index.php) সাবমিট হয়ে যেত — কিছুই ডিলিট হতো না।
         সমাধানঃ একটামাত্র শেয়ার্ড হিডেন ফর্ম, GET ফিল্টার ফর্মের বাইরে রাখা — প্রতিটা Delete
         বাটন এখন আলাদা ফর্ম না বানিয়ে শুধু এটাকেই JS দিয়ে সাবমিট করে। -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" id="wcRowActionCsrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="id" id="wcRowActionId" value="">
    </form>
    <script>
    function wcRowAction(url, field, id, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return false;
        var f = document.getElementById('wcRowActionForm');
        f.setAttribute('action', url);
        document.getElementById('wcRowActionId').value = id;
        var old = f.querySelector('input[data-dyn-field]');
        if (old) old.parentNode.removeChild(old);
        var fld = document.createElement('input');
        fld.type = 'hidden'; fld.name = field; fld.value = '1'; fld.setAttribute('data-dyn-field', '1');
        f.appendChild(fld);
        f.submit();
        return false;
    }
    </script>
    <?php if ($wms_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($wms_msg); ?></div>
    <?php endif; ?>
    <?php if ($wms_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($wms_err); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'career' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_career">Career List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'board_result' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_board_result">Board Result</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'useful_links' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_useful_links">Useful Links</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'social' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_social">Social Media</a></li>
    </ul>

    <?php // ============================== CAREER ============================== ?>
    <?php if ($active_tab === 'career'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'position_title' => '', 'department' => '', 'deadline_date' => '', 'description' => '', 'apply_link' => ''];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_careers WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-briefcase text-white"></i></div>
            <form method="POST" action="index.php?page=website_career">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Position Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="position_title" class="form-control" value="<?php echo wmsv($d['position_title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Department</label>
                    <div class="col-sm-6"><input type="text" name="department" class="form-control" value="<?php echo wmsv($d['department']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Application Deadline</label>
                    <div class="col-sm-6"><input type="date" name="deadline_date" class="form-control" value="<?php echo wmsv($d['deadline_date']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Description</label>
                    <div class="col-sm-6"><textarea name="description" class="form-control" rows="3"><?php echo wmsv($d['description']); ?></textarea></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Apply Link (ঐচ্ছিক)</label>
                    <div class="col-sm-6"><input type="text" name="apply_link" class="form-control" placeholder="mailto: অথবা ফর্ম লিংক" value="<?php echo wmsv($d['apply_link']); ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_career" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_career" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'position_title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_careers"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $careers = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_career&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($careers); ?> of <?php echo count($careers); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_career">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Position</th><th>Department</th><th>Deadline</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmsv($f_title); ?>"></th>
                                <th></th><th></th>
                                <th>
                                    <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                        <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Hidden" <?php echo $f_status === 'Hidden' ? 'selected' : ''; ?>>Hidden</option>
                                    </select>
                                </th>
                                <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($careers): $srl = 1; foreach ($careers as $c): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td class="fw-bold"><?php echo wmsv($c['position_title']); ?></td>
                                    <td class="small text-muted"><?php echo wmsv($c['department']); ?></td>
                                    <td class="small text-muted"><?php echo wmsv($c['deadline_date']); ?></td>
                                    <td><?php echo $c['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_career&view=edit&id=<?php echo (int) $c['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_career&view=edit&id=<?php echo (int) $c['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_career', 'delete_career', <?php echo (int) $c['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No job posts found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== BOARD RESULT ============================== ?>
    <?php if ($active_tab === 'board_result'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'exam_year' => '', 'result_link' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_board_results WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-award text-white"></i></div>
            <form method="POST" action="index.php?page=website_board_result">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" placeholder="যেমন: SSC Result 2026" value="<?php echo wmsv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Exam Year <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="exam_year" class="form-control" placeholder="2026" value="<?php echo wmsv($d['exam_year']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Result Link <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="result_link" class="form-control" placeholder="https://eboardresults.com/..." value="<?php echo wmsv($d['result_link']); ?>" required></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_board_result" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_board_result" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_board_results"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $board_results = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_board_result&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($board_results); ?> of <?php echo count($board_results); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_board_result">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Title</th><th>Year</th><th>Link</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmsv($f_title); ?>"></th>
                                <th></th><th></th>
                                <th>
                                    <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                        <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Hidden" <?php echo $f_status === 'Hidden' ? 'selected' : ''; ?>>Hidden</option>
                                    </select>
                                </th>
                                <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($board_results): $srl = 1; foreach ($board_results as $b): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo wmsv($b['title']); ?></td>
                                    <td><?php echo wmsv($b['exam_year']); ?></td>
                                    <td class="small text-truncate" style="max-width:200px;"><a href="<?php echo wmsv($b['result_link']); ?>" target="_blank"><?php echo wmsv($b['result_link']); ?></a></td>
                                    <td><?php echo $b['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_board_result&view=edit&id=<?php echo (int) $b['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_board_result&view=edit&id=<?php echo (int) $b['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_board_result', 'delete_board_result', <?php echo (int) $b['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No board results found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== USEFUL LINKS ============================== ?>
    <?php if ($active_tab === 'useful_links'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'url' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_useful_links WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-link text-white"></i></div>
            <form method="POST" action="index.php?page=website_useful_links">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" placeholder="যেমন: Ministry of Education" value="<?php echo wmsv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">URL <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="url" class="form-control" placeholder="https://moedu.gov.bd" value="<?php echo wmsv($d['url']); ?>" required></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_useful_link" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_useful_links" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_useful_links"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $useful_links = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_useful_links&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($useful_links); ?> of <?php echo count($useful_links); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_useful_links">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Title</th><th>URL</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmsv($f_title); ?>"></th>
                                <th></th><th></th>
                                <th>
                                    <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                        <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Hidden" <?php echo $f_status === 'Hidden' ? 'selected' : ''; ?>>Hidden</option>
                                    </select>
                                </th>
                                <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($useful_links): $srl = 1; foreach ($useful_links as $l): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo wmsv($l['title']); ?></td>
                                    <td class="small text-truncate" style="max-width:200px;"><a href="<?php echo wmsv($l['url']); ?>" target="_blank"><?php echo wmsv($l['url']); ?></a></td>
                                    <td><?php echo (int)$l['sort_order']; ?></td>
                                    <td><?php echo $l['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_useful_links&view=edit&id=<?php echo (int) $l['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_useful_links&view=edit&id=<?php echo (int) $l['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_useful_links', 'delete_useful_link', <?php echo (int) $l['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No links found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== SOCIAL MEDIA ============================== ?>
    <?php if ($active_tab === 'social'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'platform' => '', 'url' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_social_media WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-share-nodes text-white"></i></div>
            <form method="POST" action="index.php?page=website_social">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Platform <span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <select name="platform" class="form-select" required>
                            <option value="">-- বাছাই করুন --</option>
                            <?php foreach ($social_platforms as $sp): ?>
                                <option value="<?php echo wmsv($sp); ?>" <?php echo $d['platform'] === $sp ? 'selected' : ''; ?>><?php echo wmsv($sp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Profile / Page URL <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="url" class="form-control" placeholder="https://facebook.com/yourschool" value="<?php echo wmsv($d['url']); ?>" required></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_social" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_social" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_platform = $_GET['f_platform'] ?? 'All';
            $f_status   = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_platform !== 'All') { $where[] = 'platform = ?'; $params[] = $f_platform; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_social_media"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $social_media = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_social&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($social_media); ?> of <?php echo count($social_media); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_social">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Platform</th><th>URL</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th>
                                    <select name="f_platform" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_platform === 'All' ? 'selected' : ''; ?>>All</option>
                                        <?php foreach ($social_platforms as $sp): ?>
                                            <option value="<?php echo wmsv($sp); ?>" <?php echo $f_platform === $sp ? 'selected' : ''; ?>><?php echo wmsv($sp); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </th>
                                <th></th><th></th>
                                <th>
                                    <select name="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All</option>
                                        <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Hidden" <?php echo $f_status === 'Hidden' ? 'selected' : ''; ?>>Hidden</option>
                                    </select>
                                </th>
                                <th><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($social_media): $srl = 1; foreach ($social_media as $s): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td class="fw-bold"><?php echo wmsv($s['platform']); ?></td>
                                    <td class="small text-truncate" style="max-width:200px;"><a href="<?php echo wmsv($s['url']); ?>" target="_blank"><?php echo wmsv($s['url']); ?></a></td>
                                    <td><?php echo (int)$s['sort_order']; ?></td>
                                    <td><?php echo $s['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_social&view=edit&id=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_social&view=edit&id=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_social', 'delete_social', <?php echo (int) $s['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No social links found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
