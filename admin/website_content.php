<?php
// admin/website_content.php - Website Module (Why Choose Image / Menu List / Content List / Breaking News)
// একটাই ফাইলে চারটা সাব-মডিউল, sidebar এ website_why_choose / website_menu /
// website_content / website_breaking_news — চারটা slug-ই এই ফাইলে আসে (admin/index.php এ ম্যাপ করা)।
// List view + Add/Edit view আলাদা — academic_session.php এর প্যাটার্ন অনুসরণ করে।

$wc_msg = $_SESSION['wc_msg'] ?? '';
$wc_err = $_SESSION['wc_err'] ?? '';
unset($_SESSION['wc_msg'], $_SESSION['wc_err']);

$active_tab = 'why_choose';
if ($page === 'website_menu') $active_tab = 'menu';
if ($page === 'website_content') $active_tab = 'content';
if ($page === 'website_breaking_news') $active_tab = 'breaking_news';

$view = $_GET['view'] ?? 'list';

// -----------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_why_choose (
        id INT AUTO_INCREMENT PRIMARY KEY,
        image_path VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description VARCHAR(500) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
// Menu / Sub Menu / Content টেবিল, ডিফল্ট (Core) মেনু সিড আর মাইগ্রেশন — সব config/website_menu.php তে
// (পাবলিক হেডারও একই ফাইল ব্যবহার করে, তাই দুই দিকের ডাটা সবসময় এক)
require_once __DIR__ . '/../config/website_menu.php';
wm_ensure_schema($pdo);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_breaking_news (
        id INT AUTO_INCREMENT PRIMARY KEY,
        news_text VARCHAR(500) NOT NULL,
        link VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// -----------------------------------------------------------
// ছবি আপলোড হেল্পার (Why Choose Image) — extension + real MIME যাচাই
// -----------------------------------------------------------
function wc_handle_upload(string $field, bool $required): array
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

function wc_delete_file(?string $relPath): void
{
    if ($relPath) {
        $abs = __DIR__ . '/../' . $relPath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

// -----------------------------------------------------------
// POST Handlers — সবগুলোতে CSRF + prepared statement
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    // ---------- WHY CHOOSE IMAGE ----------
    if (isset($_POST['save_why_choose'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '') {
            $_SESSION['wc_err'] = 'শিরোনাম আবশ্যক।';
            header('Location: index.php?page=website_why_choose&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = wc_handle_upload('why_choose_image', $id === 0);
        if (!$img['ok']) {
            $_SESSION['wc_err'] = $img['error'];
            header('Location: index.php?page=website_why_choose&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM website_why_choose WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE website_why_choose SET image_path=?, title=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$img['path'], $title, $desc ?: null, $sort, $id]);
                if ($old_row) wc_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE website_why_choose SET title=?, description=?, sort_order=? WHERE id=?")
                    ->execute([$title, $desc ?: null, $sort, $id]);
            }
            $_SESSION['wc_msg'] = 'আইটেম আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Why Choose আইটেম আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_why_choose (image_path, title, description, sort_order) VALUES (?, ?, ?, ?)")
                ->execute([$img['path'], $title, $desc ?: null, $sort]);
            $_SESSION['wc_msg'] = 'Why Choose আইটেম যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Why Choose আইটেম যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_why_choose');
        exit();
    } elseif (isset($_POST['toggle_why_choose'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_why_choose SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_why_choose'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path FROM website_why_choose WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM website_why_choose WHERE id = ?")->execute([$id]);
        if ($row) wc_delete_file($row['image_path']);
        $_SESSION['wc_msg'] = 'আইটেম মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Why Choose আইটেম ডিলিট করা হয়েছে: #$id");
    }

    // ---------- MENU LIST ----------
    elseif (isset($_POST['save_menu'])) {
        $id     = (int) ($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $link   = trim($_POST['link'] ?? '');
        $blank  = isset($_POST['open_new_tab']) ? 1 : 0;
        $sort   = (int) ($_POST['sort_order'] ?? 0);
        $parent = (int) ($_POST['parent_id'] ?? 0);
        $back   = 'index.php?page=website_menu&view=' . ($id ? "edit&id=$id" : 'add' . ($parent ? "&parent_id=$parent" : ''));

        $row = null;
        if ($id > 0) {
            $rs = $pdo->prepare("SELECT * FROM website_menu_items WHERE id = ?");
            $rs->execute([$id]);
            $row = $rs->fetch();
            if (!$row) {
                $_SESSION['wc_err'] = 'মেনুটি পাওয়া যায়নি।';
                header('Location: index.php?page=website_menu');
                exit();
            }
        }

        // ডিফল্ট (Core) মেনু: শুধু Super Admin, আর শুধু Sort Order বদলানো যাবে (নাম/লিংক সিস্টেমের, বাংলা-ইংরেজি অনুবাদসহ)
        if ($row && !empty($row['is_core'])) {
            if (!branch_is_super_admin()) {
                $_SESSION['wc_err'] = 'এটা ডিফল্ট (কোর) মেনু — শুধু Super Admin এডিট করতে পারবেন।';
            } else {
                $pdo->prepare("UPDATE website_menu_items SET sort_order=? WHERE id=?")->execute([$sort, $id]);
                $_SESSION['wc_msg'] = 'মেনুর ক্রম আপডেট করা হয়েছে।';
                sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Menu ক্রম আপডেট করা হয়েছে: {$row['title']}");
            }
            header('Location: index.php?page=website_menu');
            exit();
        }

        if ($title === '') {
            $_SESSION['wc_err'] = 'মেনুর নাম আবশ্যক।';
            header('Location: ' . $back);
            exit();
        }
        // লিংক ফাঁকা থাকলে অটো page.php?id=.. হবে; javascript:/data: লিংক ব্লক (হেডারে href হিসেবে বসে)
        if ($link !== '' && preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $link)) {
            $_SESSION['wc_err'] = 'এই ধরনের লিংক ব্যবহার করা যাবে না।';
            header('Location: ' . $back);
            exit();
        }
        // Parent: শুধু মূল (top-level) মেনুর নিচে সাব-মেনু হয় — ২ লেভেল
        if ($parent > 0) {
            $pc = $pdo->prepare("SELECT parent_id FROM website_menu_items WHERE id = ?");
            $pc->execute([$parent]);
            $prow = $pc->fetch();
            if (!$prow || (int) $prow['parent_id'] > 0 || $parent === $id) {
                $_SESSION['wc_err'] = 'সঠিক Parent Menu বেছে নিন (শুধু মূল মেনুর নিচে সাব-মেনু হয়)।';
                header('Location: ' . $back);
                exit();
            }
            if ($id > 0) {
                $kc = $pdo->prepare("SELECT COUNT(*) FROM website_menu_items WHERE parent_id = ?");
                $kc->execute([$id]);
                if ((int) $kc->fetchColumn() > 0) {
                    $_SESSION['wc_err'] = 'এই মেনুর নিজের সাব-মেনু আছে, তাই এটাকে অন্য মেনুর সাব-মেনু বানানো যাবে না।';
                    header('Location: ' . $back);
                    exit();
                }
            }
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_menu_items SET title=?, link=?, open_new_tab=?, sort_order=?, parent_id=? WHERE id=?")
                ->execute([$title, $link, $blank, $sort, $parent, $id]);
            $_SESSION['wc_msg'] = 'মেনু আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Menu আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_menu_items (title, link, open_new_tab, sort_order, parent_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$title, $link, $blank, $sort, $parent]);
            $_SESSION['wc_msg'] = $parent ? 'সাব-মেনু যোগ করা হয়েছে।' : 'মেনু যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Menu যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_menu');
        exit();
    } elseif (isset($_POST['toggle_menu'])) {
        // Hide/Show — Super Admin সহ সব অ্যাডমিন করতে পারবে (Core মেনুও)
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_menu_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_menu'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_menu = $pdo->prepare("SELECT title, is_core FROM website_menu_items WHERE id = ?");
        $del_menu->execute([$id]);
        $del_menu_row = $del_menu->fetch();
        if (!$del_menu_row) {
            $_SESSION['wc_err'] = 'মেনুটি পাওয়া যায়নি।';
        } elseif (!branch_is_super_admin()) {
            $_SESSION['wc_err'] = 'মেনু ডিলিট করার অনুমতি শুধু Super Admin এর আছে।';
        } else {
            wm_delete_menu($pdo, $id); // সাব-মেনুসহ; যুক্ত কন্টেন্ট মুছে না গিয়ে "কোনো মেনু নয়" হয়ে যায়
            $_SESSION['wc_msg'] = 'মেনু মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Menu ডিলিট করা হয়েছে: " . $del_menu_row['title']);
        }
    }

    // ---------- CONTENT LIST ----------
    elseif (isset($_POST['save_content'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $sort    = (int) ($_POST['sort_order'] ?? 0);
        $menu_id = (int) ($_POST['menu_id'] ?? 0);
        if ($menu_id > 0) {
            $mc = $pdo->prepare("SELECT COUNT(*) FROM website_menu_items WHERE id = ?");
            $mc->execute([$menu_id]);
            if (!(int) $mc->fetchColumn()) $menu_id = 0;
        }

        if ($title === '' || $content === '') {
            $_SESSION['wc_err'] = 'শিরোনাম ও কন্টেন্ট আবশ্যক।';
            header('Location: index.php?page=website_content&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_contents SET title=?, content=?, sort_order=?, menu_id=? WHERE id=?")
                ->execute([$title, $content, $sort, $menu_id, $id]);
            $_SESSION['wc_msg'] = 'কন্টেন্ট আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Content আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_contents (title, content, sort_order, menu_id) VALUES (?, ?, ?, ?)")
                ->execute([$title, $content, $sort, $menu_id]);
            $_SESSION['wc_msg'] = 'কন্টেন্ট যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Content যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_content');
        exit();
    } elseif (isset($_POST['toggle_content'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_contents SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_content'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_content = $pdo->prepare("SELECT title FROM website_contents WHERE id = ?");
        $del_content->execute([$id]);
        $del_content_title = $del_content->fetchColumn();
        $pdo->prepare("DELETE FROM website_contents WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'কন্টেন্ট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Content ডিলিট করা হয়েছে: " . ($del_content_title ?: "#$id"));
    }

    // ---------- BREAKING NEWS ----------
    elseif (isset($_POST['save_breaking_news'])) {
        $id   = (int) ($_POST['id'] ?? 0);
        $text = trim($_POST['news_text'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $sort = (int) ($_POST['sort_order'] ?? 0);

        if ($text === '') {
            $_SESSION['wc_err'] = 'নিউজ টেক্সট আবশ্যক।';
            header('Location: index.php?page=website_breaking_news&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_breaking_news SET news_text=?, link=?, sort_order=? WHERE id=?")
                ->execute([$text, $link ?: null, $sort, $id]);
            $_SESSION['wc_msg'] = 'ব্রেকিং নিউজ আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Breaking News আপডেট করা হয়েছে: " . mb_strimwidth($text, 0, 60, '...'));
        } else {
            $pdo->prepare("INSERT INTO website_breaking_news (news_text, link, sort_order) VALUES (?, ?, ?)")
                ->execute([$text, $link ?: null, $sort]);
            $_SESSION['wc_msg'] = 'ব্রেকিং নিউজ যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Breaking News যুক্ত করা হয়েছে: " . mb_strimwidth($text, 0, 60, '...'));
        }
        header('Location: index.php?page=website_breaking_news');
        exit();
    } elseif (isset($_POST['toggle_breaking_news'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_breaking_news SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_breaking_news'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_news = $pdo->prepare("SELECT news_text FROM website_breaking_news WHERE id = ?");
        $del_news->execute([$id]);
        $del_news_text = $del_news->fetchColumn();
        $pdo->prepare("DELETE FROM website_breaking_news WHERE id = ?")->execute([$id]);
        $_SESSION['wc_msg'] = 'ব্রেকিং নিউজ মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Breaking News ডিলিট করা হয়েছে: " . ($del_news_text ? mb_strimwidth($del_news_text, 0, 60, '...') : "#$id"));
    }

    header('Location: index.php?page=' . $page);
    exit();
}

function wcv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }
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
    <?php if ($wc_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($wc_msg); ?></div>
    <?php endif; ?>
    <?php if ($wc_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($wc_err); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'why_choose' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_why_choose">Why Choose Image</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'menu' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_menu">Menu List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'content' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_content">Content List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'breaking_news' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_breaking_news">Breaking News</a></li>
    </ul>

    <?php // ============================== WHY CHOOSE IMAGE ============================== ?>
    <?php if ($active_tab === 'why_choose'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'image_path' => '', 'title' => '', 'description' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_why_choose WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-star text-white"></i></div>
            <form method="POST" action="index.php?page=website_why_choose" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wcv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Description</label>
                    <div class="col-sm-6"><textarea name="description" class="form-control" rows="2"><?php echo wcv($d['description']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Image / Icon <?php echo $view === 'edit' ? '<br><small class="text-muted fw-normal">(পরিবর্তন না করলে খালি রাখুন)</small>' : ''; ?><span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="file" name="why_choose_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" <?php echo $view === 'edit' ? '' : 'required'; ?>>
                        <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                            <img src="../<?php echo wcv($d['image_path']); ?>" class="mt-2" style="width:80px;height:60px;object-fit:cover;border-radius:4px;" alt="">
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
                        <button type="submit" name="save_why_choose" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_why_choose" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_why_choose"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $why_choose_items = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_why_choose&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($why_choose_items); ?> of <?php echo count($why_choose_items); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_why_choose">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Preview</th><th>Title</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wcv($f_title); ?>"></th>
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
                            <?php if ($why_choose_items): $srl = 1; foreach ($why_choose_items as $w): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><img src="../<?php echo wcv($w['image_path']); ?>" style="width:70px;height:50px;object-fit:cover;border-radius:4px;" alt=""></td>
                                    <td class="text-start"><?php echo wcv($w['title']); ?></td>
                                    <td><?php echo (int)$w['sort_order']; ?></td>
                                    <td><?php echo $w['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_why_choose&view=edit&id=<?php echo (int) $w['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_why_choose&view=edit&id=<?php echo (int) $w['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_why_choose', 'delete_why_choose', <?php echo (int) $w['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No items found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== MENU LIST ============================== ?>
    <?php if ($active_tab === 'menu'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'link' => '', 'open_new_tab' => 0, 'sort_order' => 0, 'is_core' => 0, 'parent_id' => (int) ($_GET['parent_id'] ?? 0)];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_menu_items WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if (!$row || (!empty($row['is_core']) && !branch_is_super_admin())) {
                    // কোর মেনু Super Admin ছাড়া এডিট নয় — URL সরাসরি টাইপ করলেও ব্লক
                    $_SESSION['wc_err'] = $row ? 'এটা ডিফল্ট (কোর) মেনু — শুধু Super Admin এডিট করতে পারবেন।' : 'মেনুটি পাওয়া যায়নি।';
                    header('Location: index.php?page=website_menu');
                    exit();
                }
                $d = $row;
            }
            $is_core_row = !empty($d['is_core']);
            $kc = $pdo->prepare("SELECT COUNT(*) FROM website_menu_items WHERE parent_id = ?");
            $kc->execute([(int) $d['id']]);
            $has_kids = $d['id'] && (int) $kc->fetchColumn() > 0;
            $parent_opts = $pdo->prepare("SELECT id, title FROM website_menu_items WHERE parent_id = 0 AND id <> ? ORDER BY sort_order ASC, id ASC");
            $parent_opts->execute([(int) $d['id']]);
            $parent_opts = $parent_opts->fetchAll();
            $lock_parent = $is_core_row || $has_kids;
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-bars text-white"></i></div>
            <?php if ($is_core_row): ?>
                <div class="alert alert-info py-2 small">এটা ডিফল্ট (Core) মেনু — নাম ও লিংক সিস্টেমের ঠিক করা (বাংলা/ইংরেজি অনুবাদসহ), শুধু Sort Order বদলানো যাবে। এর নিচে সাব-মেনু ও কন্টেন্ট যোগ করতে পারবেন।</div>
            <?php endif; ?>
            <form method="POST" action="index.php?page=website_menu">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Parent Menu</label>
                    <div class="col-sm-6">
                        <select name="parent_id" class="form-select" <?php echo $lock_parent ? 'disabled' : ''; ?>>
                            <option value="0">— মূল মেনু (Top Level) —</option>
                            <?php foreach ($parent_opts as $po): ?>
                                <option value="<?php echo (int) $po['id']; ?>" <?php echo (int) $d['parent_id'] === (int) $po['id'] ? 'selected' : ''; ?>><?php echo wcv($po['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($has_kids): ?><div class="form-text">এই মেনুর নিজের সাব-মেনু আছে, তাই এটা অন্য মেনুর সাব-মেনু হতে পারবে না।</div><?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Menu Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" placeholder="যেমন: About Us" value="<?php echo wcv($d['title']); ?>" <?php echo $is_core_row ? 'readonly' : 'required'; ?>></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Link</label>
                    <div class="col-sm-6">
                        <input type="text" name="link" class="form-control" placeholder="ফাঁকা রাখলে অটো পেজ তৈরি হবে (Content Add করলে সেখানে দেখাবে)" value="<?php echo wcv($d['link']); ?>" <?php echo $is_core_row ? 'readonly' : ''; ?>>
                        <div class="form-text">ফাঁকা রাখলে মেনুর নিজস্ব পেজ (page.php) খুলবে। বাইরের লিংক বা #section-ও দেওয়া যায়।</div>
                    </div>
                </div>
                <?php if (!$is_core_row): ?>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">নতুন ট্যাব</label>
                    <div class="col-sm-6">
                        <div class="form-check">
                            <input type="checkbox" name="open_new_tab" class="form-check-input" id="wcOpenNewTab" <?php echo $d['open_new_tab'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="wcOpenNewTab">নতুন ট্যাবে খুলবে</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_menu" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_menu" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $menu_items = [];
            foreach (wm_admin_flat($pdo) as $m) {
                if ($f_title !== '' && mb_stripos($m['title'], $f_title) === false) continue;
                if ($f_status === 'Active' && !$m['is_active']) continue;
                if ($f_status === 'Hidden' && $m['is_active']) continue;
                $menu_items[] = $m;
            }
            $content_counts = $pdo->query("SELECT menu_id, COUNT(*) c FROM website_contents WHERE menu_id > 0 GROUP BY menu_id")->fetchAll(PDO::FETCH_KEY_PAIR);
            $is_sa = branch_is_super_admin();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_menu&view=add" class="btn btn-success" title="নতুন মেনু"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($menu_items); ?> of <?php echo count($menu_items); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_menu">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Title</th><th>Link</th><th>Contents</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wcv($f_title); ?>"></th>
                                <th></th><th></th><th></th>
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
                            <?php if ($menu_items): $srl = 1; foreach ($menu_items as $m):
                                $m_core = !empty($m['is_core']);
                                $m_cnt  = (int) ($content_counts[$m['id']] ?? 0);
                                $can_edit = !$m_core || $is_sa; // কোর মেনু এডিট (শুধু ক্রম) — Super Admin; Hide/Show সবাই; Delete শুধু Super Admin
                            ?>
                                <tr class="<?php echo $m['is_active'] ? '' : 'table-light text-muted'; ?>">
                                    <td><?php echo $srl++; ?></td>
                                    <td>
                                        <?php if ((int) $m['depth'] === 1): ?><span class="text-muted ms-3">└─</span> <?php endif; ?>
                                        <?php echo wcv($m['title']); ?>
                                        <?php if ($m_core): ?><span class="badge bg-secondary" title="ডিফল্ট মেনু — শুধু Super Admin ডিলিট/এডিট করতে পারবেন">Core</span><?php endif; ?>
                                        <?php if ((int) $m['depth'] === 1): ?><span class="badge bg-info text-dark">Sub</span><?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo trim((string) $m['link']) !== '' ? wcv($m['link']) : '<em>auto: page.php?id=' . (int) $m['id'] . '</em>'; ?></td>
                                    <td>
                                        <?php if ($m_cnt > 0): ?>
                                            <a href="index.php?page=website_content&f_menu=<?php echo (int) $m['id']; ?>" class="badge bg-primary text-decoration-none"><?php echo $m_cnt; ?></a>
                                        <?php else: ?><span class="text-muted small">0</span><?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $m['sort_order']; ?></td>
                                    <td><?php echo $m['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <?php if ((int) $m['depth'] === 0): ?>
                                        <a href="index.php?page=website_menu&view=add&parent_id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-success" title="Sub Menu যোগ করুন"><i class="fa-solid fa-sitemap"></i></a>
                                        <?php endif; ?>
                                        <a href="index.php?page=website_content&view=add&menu_id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-info" title="Content যোগ করুন"><i class="fa-solid fa-file-circle-plus"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" title="<?php echo $m['is_active'] ? 'Hide করুন' : 'Show করুন'; ?>" onclick="return wcRowAction('index.php?page=website_menu', 'toggle_menu', <?php echo (int) $m['id']; ?>, '');"><i class="fa-solid <?php echo $m['is_active'] ? 'fa-eye' : 'fa-eye-slash'; ?>"></i></button>
                                        <?php if ($can_edit): ?>
                                            <a href="index.php?page=website_menu&view=edit&id=<?php echo (int) $m['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <?php endif; ?>
                                        <?php if ($is_sa): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_menu', 'delete_menu', <?php echo (int) $m['id']; ?>, <?php echo wcv(json_encode($m_core ? 'এটা ডিফল্ট (Core) মেনু — ডিলিট করলে ওয়েবসাইট থেকে সরে যাবে (সাব-মেনুসহ)। মুছে ফেলবেন?' : ((int) $m['depth'] === 0 ? 'মেনু ও এর সব সাব-মেনু মুছে যাবে (কন্টেন্ট থেকে যাবে)। মুছে ফেলবেন?' : 'মুছে ফেলবেন?'), JSON_UNESCAPED_UNICODE)); ?>);"><i class="fa-solid fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No menu items found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== CONTENT LIST ============================== ?>
    <?php if ($active_tab === 'content'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'content' => '', 'sort_order' => 0, 'menu_id' => (int) ($_GET['menu_id'] ?? 0)];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_contents WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
            $menu_flat = wm_admin_flat($pdo);
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-align-left text-white"></i></div>
            <form method="POST" action="index.php?page=website_content">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" placeholder="যেমন: About Us / Mission" value="<?php echo wcv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Menu / Sub Menu</label>
                    <div class="col-sm-6">
                        <select name="menu_id" class="form-select">
                            <option value="0">— কোনো মেনু নয় (হোমপেজের About সেকশনে দেখাবে) —</option>
                            <?php foreach ($menu_flat as $mf): ?>
                                <option value="<?php echo (int) $mf['id']; ?>" <?php echo (int) ($d['menu_id'] ?? 0) === (int) $mf['id'] ? 'selected' : ''; ?>><?php echo (int) $mf['depth'] === 1 ? '&nbsp;&nbsp;&nbsp;└─ ' : ''; ?><?php echo wcv($mf['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">মেনু বা সাব-মেনু বেছে নিলে কন্টেন্টটা সেই মেনুর পেজে (page.php) দেখাবে।</div>
                    </div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Content <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><textarea name="content" class="form-control" rows="6" required><?php echo wcv($d['content']); ?></textarea></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_content" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_content" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $f_menu   = $_GET['f_menu'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            if ($f_menu !== 'All') { $where[] = 'menu_id = ?'; $params[] = (int) $f_menu; }
            $menu_flat = wm_admin_flat($pdo);
            $menu_names = [];
            foreach ($menu_flat as $mf) $menu_names[(int) $mf['id']] = $mf['title'];
            $sql = "SELECT * FROM website_contents"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $content_items = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_content&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($content_items); ?> of <?php echo count($content_items); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_content">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Title</th><th>Menu</th><th>Content</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wcv($f_title); ?>"></th>
                                <th>
                                    <select name="f_menu" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="All" <?php echo $f_menu === 'All' ? 'selected' : ''; ?>>All</option>
                                        <option value="0" <?php echo $f_menu === '0' ? 'selected' : ''; ?>>— কোনো মেনু নয় —</option>
                                        <?php foreach ($menu_flat as $mf): ?>
                                            <option value="<?php echo (int) $mf['id']; ?>" <?php echo $f_menu === (string) $mf['id'] ? 'selected' : ''; ?>><?php echo (int) $mf['depth'] === 1 ? '&nbsp;&nbsp;└─ ' : ''; ?><?php echo wcv($mf['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </th>
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
                            <?php if ($content_items): $srl = 1; foreach ($content_items as $c): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td class="fw-bold"><?php echo wcv($c['title']); ?></td>
                                    <td class="small"><?php echo !empty($c['menu_id']) && isset($menu_names[(int) $c['menu_id']]) ? wcv($menu_names[(int) $c['menu_id']]) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="small text-muted"><?php echo wcv(mb_strimwidth($c['content'], 0, 80, '...')); ?></td>
                                    <td><?php echo $c['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_content&view=edit&id=<?php echo (int) $c['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_content&view=edit&id=<?php echo (int) $c['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_content', 'delete_content', <?php echo (int) $c['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No content found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== BREAKING NEWS ============================== ?>
    <?php if ($active_tab === 'breaking_news'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'news_text' => '', 'link' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM website_breaking_news WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-bolt text-white"></i></div>
            <form method="POST" action="index.php?page=website_breaking_news">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">News Text <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><textarea name="news_text" class="form-control" rows="2" required><?php echo wcv($d['news_text']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Link (ঐচ্ছিক)</label>
                    <div class="col-sm-6"><input type="text" name="link" class="form-control" placeholder="#notice অথবা news.php?id=1" value="<?php echo wcv($d['link']); ?>"></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_breaking_news" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_breaking_news" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_text   = trim($_GET['f_text'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_text !== '') { $where[] = 'news_text LIKE ?'; $params[] = "%$f_text%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM website_breaking_news"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $breaking_news_items = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_breaking_news&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($breaking_news_items); ?> of <?php echo count($breaking_news_items); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_breaking_news">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>News Text</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_text" class="form-control form-control-sm" value="<?php echo wcv($f_text); ?>"></th>
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
                            <?php if ($breaking_news_items): $srl = 1; foreach ($breaking_news_items as $n): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo wcv($n['news_text']); ?></td>
                                    <td><?php echo (int)$n['sort_order']; ?></td>
                                    <td><?php echo $n['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_breaking_news&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_breaking_news&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_breaking_news', 'delete_breaking_news', <?php echo (int) $n['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No breaking news found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
