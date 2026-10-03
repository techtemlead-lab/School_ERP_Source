<?php
// admin/website_media.php - Website Module (Album List / News List / Events List)
// একটাই ফাইলে তিনটা সাব-মডিউল, sidebar এ website_album / website_news /
// website_events — তিনটা slug-ই এই ফাইলে আসে (admin/index.php এ ম্যাপ করা)।
// List view + Add/Edit view আলাদা — academic_session.php এর প্যাটার্ন অনুসরণ করে।

$wm_msg = $_SESSION['wm_msg'] ?? '';
$wm_err = $_SESSION['wm_err'] ?? '';
unset($_SESSION['wm_msg'], $_SESSION['wm_err']);

$active_tab = 'album';
if ($page === 'website_news') $active_tab = 'news';
if ($page === 'website_events') $active_tab = 'events';

$view = $_GET['view'] ?? 'list';

// -----------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_albums (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        cover_image VARCHAR(255) NOT NULL,
        description VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_news (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        image_path VARCHAR(255) NULL,
        short_desc VARCHAR(500) NULL,
        full_desc TEXT NULL,
        news_date DATE NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        image_path VARCHAR(255) NULL,
        event_date DATE NOT NULL,
        location VARCHAR(255) NULL,
        description TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// -----------------------------------------------------------
// ছবি আপলোড হেল্পার — extension + real MIME টাইপ যাচাই
// -----------------------------------------------------------
function wm_handle_upload(string $field, bool $required): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => !$required, 'path' => null, 'error' => $required ? 'ছবি আবশ্যক।' : null];
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ 3MB এর বেশি হওয়া যাবে না।'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ছবি আপলোড করা যাবে।'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'website');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।'];
    }

    return ['ok' => true, 'path' => tenant_upload_rel('website') . '/' . $random_name, 'error' => null];
}

function wm_delete_file(?string $relPath): void
{
    if ($relPath) {
        $abs = __DIR__ . '/../' . $relPath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wm_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    // ---------- ALBUM ----------
    if (isset($_POST['save_album'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '') {
            $_SESSION['wm_err'] = 'অ্যালবামের নাম আবশ্যক।';
            header('Location: index.php?page=website_album&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = wm_handle_upload('cover_image', $id === 0);
        if (!$img['ok']) {
            $_SESSION['wm_err'] = $img['error'];
            header('Location: index.php?page=website_album&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT cover_image FROM website_albums WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE website_albums SET title=?, cover_image=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$title, $img['path'], $desc ?: null, $sort, $id]);
                if ($old_row) wm_delete_file($old_row['cover_image']);
            } else {
                $pdo->prepare("UPDATE website_albums SET title=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$title, $desc ?: null, $sort, $id]);
            }
            $_SESSION['wm_msg'] = 'অ্যালবাম আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Album আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_albums (title, cover_image, description, sort_order) VALUES (?, ?, ?, ?)")
                ->execute([$title, $img['path'], $desc ?: null, $sort]);
            $_SESSION['wm_msg'] = 'অ্যালবাম যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Album যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_album');
        exit();
    } elseif (isset($_POST['toggle_album'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_albums SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wm_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_album'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT cover_image FROM website_albums WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM website_albums WHERE id = ?")->execute([$id]);
        if ($row) wm_delete_file($row['cover_image']);
        $_SESSION['wm_msg'] = 'অ্যালবাম মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Album ডিলিট করা হয়েছে: #$id");
    }

    // ---------- NEWS ----------
    elseif (isset($_POST['save_news'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $short = trim($_POST['short_desc'] ?? '');
        $full  = trim($_POST['full_desc'] ?? '');
        $date  = trim($_POST['news_date'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            $_SESSION['wm_err'] = 'শিরোনাম ও সঠিক তারিখ দিন।';
            header('Location: index.php?page=website_news&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = wm_handle_upload('news_image', false);
        if (!$img['ok']) {
            $_SESSION['wm_err'] = $img['error'];
            header('Location: index.php?page=website_news&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM website_news WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE website_news SET title=?, image_path=?, short_desc=?, full_desc=?, news_date=?, sort_order=? WHERE id=?")
                    ->execute([$title, $img['path'], $short ?: null, $full ?: null, $date, $sort, $id]);
                if ($old_row) wm_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE website_news SET title=?, short_desc=?, full_desc=?, news_date=?, sort_order=? WHERE id=?")
                    ->execute([$title, $short ?: null, $full ?: null, $date, $sort, $id]);
            }
            $_SESSION['wm_msg'] = 'নিউজ আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট News আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_news (title, image_path, short_desc, full_desc, news_date, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $img['path'], $short ?: null, $full ?: null, $date, $sort]);
            $_SESSION['wm_msg'] = 'নিউজ যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট News যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_news');
        exit();
    } elseif (isset($_POST['toggle_news'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_news SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wm_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_news'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path FROM website_news WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM website_news WHERE id = ?")->execute([$id]);
        if ($row) wm_delete_file($row['image_path']);
        $_SESSION['wm_msg'] = 'নিউজ মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট News ডিলিট করা হয়েছে: #$id");
    }

    // ---------- EVENTS ----------
    elseif (isset($_POST['save_event'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $date  = trim($_POST['event_date'] ?? '');
        $loc   = trim($_POST['location'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            $_SESSION['wm_err'] = 'শিরোনাম ও সঠিক তারিখ দিন।';
            header('Location: index.php?page=website_events&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = wm_handle_upload('event_image', false);
        if (!$img['ok']) {
            $_SESSION['wm_err'] = $img['error'];
            header('Location: index.php?page=website_events&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM website_events WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE website_events SET title=?, image_path=?, event_date=?, location=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$title, $img['path'], $date, $loc ?: null, $desc ?: null, $sort, $id]);
                if ($old_row) wm_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE website_events SET title=?, event_date=?, location=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$title, $date, $loc ?: null, $desc ?: null, $sort, $id]);
            }
            $_SESSION['wm_msg'] = 'ইভেন্ট আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Event আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_events (title, image_path, event_date, location, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $img['path'], $date, $loc ?: null, $desc ?: null, $sort]);
            $_SESSION['wm_msg'] = 'ইভেন্ট যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Event যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_events');
        exit();
    } elseif (isset($_POST['toggle_event'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_events SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wm_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_event'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path FROM website_events WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM website_events WHERE id = ?")->execute([$id]);
        if ($row) wm_delete_file($row['image_path']);
        $_SESSION['wm_msg'] = 'ইভেন্ট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Event ডিলিট করা হয়েছে: #$id");
    }

    header('Location: index.php?page=' . $page);
    exit();
}

function wmv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }
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
    <?php if ($wm_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($wm_msg); ?></div>
    <?php endif; ?>
    <?php if ($wm_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($wm_err); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'album' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_album">Album List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'news' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_news">News List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'events' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_events">Events List</a></li>
    </ul>

    <?php // ============================== ALBUM ============================== ?>
    <?php if ($active_tab === 'album'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'cover_image' => '', 'description' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_albums WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-images text-white"></i></div>
            <form method="POST" action="index.php?page=website_album" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wmv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Description</label>
                    <div class="col-sm-6"><textarea name="description" class="form-control" rows="2"><?php echo wmv($d['description']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Cover Image <?php echo $view === 'edit' ? '<br><small class="text-muted fw-normal">(পরিবর্তন না করলে খালি রাখুন)</small>' : ''; ?><span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="file" name="cover_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" <?php echo $view === 'edit' ? '' : 'required'; ?>>
                        <?php if ($view === 'edit' && !empty($d['cover_image'])): ?>
                            <img src="../<?php echo wmv($d['cover_image']); ?>" class="mt-2" style="width:100px;height:60px;object-fit:cover;border-radius:4px;" alt="">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_album" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_album" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_albums"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $albums = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_album&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($albums); ?> of <?php echo count($albums); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_album">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Cover</th><th>Title</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmv($f_title); ?>"></th>
                                <th></th>
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
                            <?php if ($albums): $srl = 1; foreach ($albums as $a): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><img src="../<?php echo wmv($a['cover_image']); ?>" style="width:90px;height:50px;object-fit:cover;border-radius:4px;" alt=""></td>
                                    <td class="text-start"><?php echo wmv($a['title']); ?></td>
                                    <td><?php echo (int)$a['sort_order']; ?></td>
                                    <td><?php echo $a['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_album&view=edit&id=<?php echo (int) $a['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_album&view=edit&id=<?php echo (int) $a['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_album', 'delete_album', <?php echo (int) $a['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No albums found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== NEWS ============================== ?>
    <?php if ($active_tab === 'news'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'image_path' => '', 'short_desc' => '', 'full_desc' => '', 'news_date' => date('Y-m-d'), 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_news WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-newspaper text-white"></i></div>
            <form method="POST" action="index.php?page=website_news" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wmv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Date <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="date" name="news_date" class="form-control" value="<?php echo wmv($d['news_date']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Image (ঐচ্ছিক)</label>
                    <div class="col-sm-6">
                        <input type="file" name="news_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                        <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                            <img src="../<?php echo wmv($d['image_path']); ?>" class="mt-2" style="width:100px;height:60px;object-fit:cover;border-radius:4px;" alt="">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Short Description</label>
                    <div class="col-sm-6"><textarea name="short_desc" class="form-control" rows="2"><?php echo wmv($d['short_desc']); ?></textarea></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Full Description</label>
                    <div class="col-sm-6"><textarea name="full_desc" class="form-control" rows="4"><?php echo wmv($d['full_desc']); ?></textarea></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_news" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_news" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_news"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY news_date DESC, id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $news = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_news&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($news); ?> of <?php echo count($news); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_news">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Image</th><th>Title</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmv($f_title); ?>"></th>
                                <th></th>
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
                            <?php if ($news): $srl = 1; foreach ($news as $n): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php if ($n['image_path']): ?><img src="../<?php echo wmv($n['image_path']); ?>" style="width:80px;height:50px;object-fit:cover;border-radius:4px;" alt=""><?php else: ?>—<?php endif; ?></td>
                                    <td class="text-start"><?php echo wmv($n['title']); ?></td>
                                    <td class="small text-muted"><?php echo wmv($n['news_date']); ?></td>
                                    <td><?php echo $n['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_news&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_news&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_news', 'delete_news', <?php echo (int) $n['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No news found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== EVENTS ============================== ?>
    <?php if ($active_tab === 'events'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'image_path' => '', 'event_date' => date('Y-m-d'), 'location' => '', 'description' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_events WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-calendar-days text-white"></i></div>
            <form method="POST" action="index.php?page=website_events" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wmv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Date <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="date" name="event_date" class="form-control" value="<?php echo wmv($d['event_date']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Location</label>
                    <div class="col-sm-6"><input type="text" name="location" class="form-control" value="<?php echo wmv($d['location']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Image (ঐচ্ছিক)</label>
                    <div class="col-sm-6">
                        <input type="file" name="event_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                        <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                            <img src="../<?php echo wmv($d['image_path']); ?>" class="mt-2" style="width:100px;height:60px;object-fit:cover;border-radius:4px;" alt="">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Description</label>
                    <div class="col-sm-6"><textarea name="description" class="form-control" rows="3"><?php echo wmv($d['description']); ?></textarea></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_event" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_events" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_events"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY event_date DESC, id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $events = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_events&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($events); ?> of <?php echo count($events); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_events">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Image</th><th>Title</th><th>Date</th><th>Location</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wmv($f_title); ?>"></th>
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
                            <?php if ($events): $srl = 1; foreach ($events as $e): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php if ($e['image_path']): ?><img src="../<?php echo wmv($e['image_path']); ?>" style="width:80px;height:50px;object-fit:cover;border-radius:4px;" alt=""><?php else: ?>—<?php endif; ?></td>
                                    <td class="text-start"><?php echo wmv($e['title']); ?></td>
                                    <td class="small text-muted"><?php echo wmv($e['event_date']); ?></td>
                                    <td class="small text-muted"><?php echo wmv($e['location']); ?></td>
                                    <td><?php echo $e['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_events&view=edit&id=<?php echo (int) $e['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_events&view=edit&id=<?php echo (int) $e['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_events', 'delete_event', <?php echo (int) $e['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No events found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
