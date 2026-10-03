<?php
// admin/website.php - Website Module (Notice / Slider / Gallery)
// একটাই ফাইলে তিনটা সাব-মডিউল, sidebar এ website_slider / website_notice /
// website_gallery — তিনটা slug-ই এই ফাইলে আসে (admin/index.php এ ম্যাপ করা)।
// List view + Add/Edit view আলাদা — academic_session.php এর প্যাটার্ন অনুসরণ করে।

$website_msg = $_SESSION['website_msg'] ?? '';
$website_err = $_SESSION['website_err'] ?? '';
unset($_SESSION['website_msg'], $_SESSION['website_err']);

// -----------------------------------------------------------
// Self-heal: notices টেবিল আগে তৈরি না থাকলে বানানো, আর branch_id
// কলাম (পুরনো ইনস্টলেশনে যোগ করা হয়নি বলে ছিল না) না থাকলে অ্যাড করা —
// নাহলে notices.branch_id নিয়ে JOIN/UPDATE/INSERT-এ Fatal error হতো
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        notice_date DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$notices_cols = $pdo->query("SHOW COLUMNS FROM notices")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('branch_id', $notices_cols, true)) {
    $pdo->exec("ALTER TABLE notices ADD COLUMN branch_id INT NULL AFTER notice_date");
}
// body (rich text কনটেন্ট) আর attachment (PDF/Word ফাইল) — আগে notice-এ শুধু
// title/date থাকতো, বিস্তারিত লেখা বা ফাইল যোগ করার কোনো উপায় ছিল না
if (!in_array('body', $notices_cols, true)) {
    $pdo->exec("ALTER TABLE notices ADD COLUMN body LONGTEXT NULL AFTER branch_id");
}
if (!in_array('attachment_path', $notices_cols, true)) {
    $pdo->exec("ALTER TABLE notices ADD COLUMN attachment_path VARCHAR(255) NULL AFTER body");
}
if (!in_array('attachment_name', $notices_cols, true)) {
    $pdo->exec("ALTER TABLE notices ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path");
}

// কোন ট্যাব প্রথমে খোলা থাকবে সেটা URL slug থেকে ঠিক হয়
$active_tab = 'notice';
if ($page === 'website_slider') $active_tab = 'slider';
if ($page === 'website_gallery') $active_tab = 'gallery';

$view = $_GET['view'] ?? 'list';

// -----------------------------------------------------------
// ছবি আপলোড হেল্পার (slider/gallery) — extension + real MIME
// টাইপ দুটোই যাচাই হয়, random filename দেওয়া হয়
// -----------------------------------------------------------
function website_handle_upload(string $field, bool $required): array
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

function website_delete_file(?string $relPath): void
{
    if ($relPath) {
        $abs = __DIR__ . '/../' . $relPath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

// -----------------------------------------------------------
// Notice এর বিস্তারিত (body) Quill rich text editor থেকে আসে, তাই HTML
// ট্যাগ থাকবে — সেভ করার আগে শুধু নিরাপদ ফরম্যাটিং ট্যাগ রাখা হয়
// (bold/italic/list/link ইত্যাদি), script/iframe/on* অ্যাট্রিবিউট বাদ যায়
// -----------------------------------------------------------
function notice_sanitize_richtext(string $html): string
{
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><span><h1><h2><h3><h4><blockquote>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i', 'href="#"', $html);
    return $html;
}

// -----------------------------------------------------------
// Notice-এর সাথে PDF/Word ফাইল আপলোড হেল্পার — extension + real MIME
// টাইপ দুটোই যাচাই হয়, original নাম আলাদাভাবে সেভ থাকে (ডাউনলোডের সময়
// দেখানোর জন্য), আসল ফাইল random নামে সেভ হয়
// -----------------------------------------------------------
function website_handle_doc_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'name' => null, 'error' => null];
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'name' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'name' => null, 'error' => 'ফাইলের সাইজ 10MB এর বেশি হওয়া যাবে না।'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'name' => null, 'error' => 'শুধু PDF/DOC/DOCX ফাইল আপলোড করা যাবে।'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mimes[$ext], true)) {
        return ['ok' => false, 'path' => null, 'name' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'website/notices');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'name' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    }

    return [
        'ok' => true,
        'path' => tenant_upload_rel('website/notices') . '/' . $random_name,
        'name' => basename($file['name']),
        'error' => null,
    ];
}

// -----------------------------------------------------------
// POST Handlers — সবগুলোতে CSRF + prepared statement
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['website_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    // ---------- NOTICE ----------
    if (isset($_POST['save_notice'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $date  = trim($_POST['notice_date'] ?? '');
        $body  = notice_sanitize_richtext(trim($_POST['body'] ?? ''));
        $notice_branch_id = (int) ($_POST['branch_id'] ?? 0) ?: null; // NULL = All Branches
        if ($title === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            $_SESSION['website_err'] = 'শিরোনাম ও সঠিক তারিখ দিন।';
            header('Location: index.php?page=website_notice&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }

        $doc = website_handle_doc_upload('notice_attachment');
        if (!$doc['ok']) {
            $_SESSION['website_err'] = $doc['error'];
            header('Location: index.php?page=website_notice&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $remove_attachment = isset($_POST['remove_attachment']);

        if ($id > 0) {
            $old = $pdo->prepare("SELECT attachment_path FROM notices WHERE id = ?");
            $old->execute([$id]);
            $old_row = $old->fetch();

            if ($doc['path']) {
                // নতুন ফাইল আপলোড হলে পুরনোটা মুছে নতুনটা বসে
                if ($old_row) website_delete_file($old_row['attachment_path']);
                $pdo->prepare("UPDATE notices SET title=?, notice_date=?, branch_id=?, body=?, attachment_path=?, attachment_name=? WHERE id=?")
                    ->execute([$title, $date, $notice_branch_id, $body ?: null, $doc['path'], $doc['name'], $id]);
            } elseif ($remove_attachment) {
                if ($old_row) website_delete_file($old_row['attachment_path']);
                $pdo->prepare("UPDATE notices SET title=?, notice_date=?, branch_id=?, body=?, attachment_path=NULL, attachment_name=NULL WHERE id=?")
                    ->execute([$title, $date, $notice_branch_id, $body ?: null, $id]);
            } else {
                $pdo->prepare("UPDATE notices SET title=?, notice_date=?, branch_id=?, body=? WHERE id=?")
                    ->execute([$title, $date, $notice_branch_id, $body ?: null, $id]);
            }
            $_SESSION['website_msg'] = 'নোটিশ আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Notice আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO notices (title, notice_date, branch_id, body, attachment_path, attachment_name) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $date, $notice_branch_id, $body ?: null, $doc['path'], $doc['name']]);
            $_SESSION['website_msg'] = 'নোটিশ যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Notice যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_notice');
        exit();
    } elseif (isset($_POST['toggle_notice'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE notices SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['website_msg'] = 'নোটিশের স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_notice'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_notice = $pdo->prepare("SELECT title, attachment_path FROM notices WHERE id = ?");
        $del_notice->execute([$id]);
        $del_notice_row = $del_notice->fetch();
        $pdo->prepare("DELETE FROM notices WHERE id = ?")->execute([$id]);
        if ($del_notice_row) website_delete_file($del_notice_row['attachment_path']);
        $_SESSION['website_msg'] = 'নোটিশ মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Notice ডিলিট করা হয়েছে: " . ($del_notice_row['title'] ?? "#$id"));
    }

    // ---------- SLIDER ----------
    elseif (isset($_POST['save_slider'])) {
        $id       = (int) ($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $btn_text = trim($_POST['button_text'] ?? '');
        $btn_link = trim($_POST['button_link'] ?? '');
        $sort     = (int) ($_POST['sort_order'] ?? 0);

        if ($title === '') {
            $_SESSION['website_err'] = 'স্লাইড শিরোনাম আবশ্যক।';
            header('Location: index.php?page=website_slider&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = website_handle_upload('slider_image', $id === 0);
        if (!$img['ok']) {
            $_SESSION['website_err'] = $img['error'];
            header('Location: index.php?page=website_slider&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM sliders WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE sliders SET title=?, subtitle=?, image_path=?, button_text=?, button_link=?, sort_order=? WHERE id=?")
                    ->execute([$title, $subtitle ?: null, $img['path'], $btn_text ?: null, $btn_link ?: null, $sort, $id]);
                if ($old_row) website_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE sliders SET title=?, subtitle=?, button_text=?, button_link=?, sort_order=? WHERE id=?")
                    ->execute([$title, $subtitle ?: null, $btn_text ?: null, $btn_link ?: null, $sort, $id]);
            }
            $_SESSION['website_msg'] = 'স্লাইড আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Slider আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO sliders (title, subtitle, image_path, button_text, button_link, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $subtitle ?: null, $img['path'], $btn_text ?: null, $btn_link ?: null, $sort]);
            $_SESSION['website_msg'] = 'স্লাইড যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Slider যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=website_slider');
        exit();
    } elseif (isset($_POST['toggle_slider'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE sliders SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['website_msg'] = 'স্লাইডের স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_slider'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path FROM sliders WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM sliders WHERE id = ?")->execute([$id]);
        if ($row) website_delete_file($row['image_path']);
        $_SESSION['website_msg'] = 'স্লাইড মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Slider ডিলিট করা হয়েছে: #$id");
    }

    // ---------- GALLERY ----------
    elseif (isset($_POST['save_gallery'])) {
        $id      = (int) ($_POST['id'] ?? 0);
        $caption = trim($_POST['caption'] ?? '');
        $sort    = (int) ($_POST['sort_order'] ?? 0);

        if ($caption === '') {
            $_SESSION['website_err'] = 'ক্যাপশন আবশ্যক।';
            header('Location: index.php?page=website_gallery&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        $img = website_handle_upload('gallery_image', $id === 0);
        if (!$img['ok']) {
            $_SESSION['website_err'] = $img['error'];
            header('Location: index.php?page=website_gallery&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM gallery_items WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE gallery_items SET caption=?, image_path=?, sort_order=? WHERE id=?")
                    ->execute([$caption, $img['path'], $sort, $id]);
                if ($old_row) website_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE gallery_items SET caption=?, sort_order=? WHERE id=?")
                    ->execute([$caption, $sort, $id]);
            }
            $_SESSION['website_msg'] = 'গ্যালারি আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Gallery আইটেম আপডেট করা হয়েছে: {$caption}");
        } else {
            $pdo->prepare("INSERT INTO gallery_items (caption, image_path, sort_order) VALUES (?, ?, ?)")
                ->execute([$caption, $img['path'], $sort]);
            $_SESSION['website_msg'] = 'গ্যালারি ছবি যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Gallery-এ ছবি যুক্ত করা হয়েছে: {$caption}");
        }
        header('Location: index.php?page=website_gallery');
        exit();
    } elseif (isset($_POST['toggle_gallery'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE gallery_items SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['website_msg'] = 'গ্যালারি আইটেমের স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_gallery'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path FROM gallery_items WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM gallery_items WHERE id = ?")->execute([$id]);
        if ($row) website_delete_file($row['image_path']);
        $_SESSION['website_msg'] = 'গ্যালারি ছবি মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Gallery থেকে ছবি ডিলিট করা হয়েছে: #$id");
    }

    header('Location: index.php?page=' . $page);
    exit();
}

function wv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }
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
    <?php if ($website_msg): ?>
        <div class="alert alert-success py-2"><?php echo htmlspecialchars($website_msg); ?></div>
    <?php endif; ?>
    <?php if ($website_err): ?>
        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($website_err); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'notice' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_notice">Notice List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'slider' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_slider">Slider List</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'gallery' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_gallery">Gallery List</a></li>
    </ul>

    <?php // ============================== NOTICE ============================== ?>
    <?php if ($active_tab === 'notice'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'notice_date' => date('Y-m-d'), 'body' => '', 'attachment_path' => null, 'attachment_name' => null];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM notices WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-bullhorn text-white"></i></div>
            <form method="POST" action="index.php?page=website_notice" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Date <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="date" name="notice_date" class="form-control" value="<?php echo wv($d['notice_date']); ?>" required></div>
                </div>
                <?php $wn_branches = branch_list_active($pdo); if ($wn_branches): ?>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Branch</label>
                    <div class="col-sm-6">
                        <select name="branch_id" class="form-select">
                            <option value="">-- সব Branch (All Branches) --</option>
                            <?php foreach ($wn_branches as $wnb): ?>
                                <option value="<?php echo (int) $wnb['id']; ?>" <?php echo (int) ($d['branch_id'] ?? 0) === (int) $wnb['id'] ? 'selected' : ''; ?>><?php echo wv($wnb['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">নির্দিষ্ট Branch সিলেক্ট করলে পাবলিক সাইটে সেই notice-এর পাশে ওই branch-এর নাম ব্যাজ হিসেবে দেখা যাবে। খালি রাখলে এটা সব Branch-এর জন্য প্রযোজ্য বলে ধরা হবে।</small>
                    </div>
                </div>
                <?php endif; ?>
                <div class="mb-4" style="clear:both;">
                    <label class="fw-bold d-block mb-2">বিস্তারিত (Details)</label>
                    <input type="hidden" name="body" id="noticeBodyInput" value="<?php echo wv($d['body']); ?>">
                    <div id="noticeBodyQuill" style="background:#fff;min-height:180px;border:1px solid #ced4da;"><?php echo (string) ($d['body'] ?? ''); ?></div>
                    <small class="text-muted d-block mt-2">চাইলে খালি রাখতে পারেন — শুধু শিরোনাম আর তারিখ দিয়েও নোটিশ দেওয়া যায়।</small>
                </div>

                <div class="mb-4" style="clear:both;">
                    <label class="fw-bold d-block mb-2">সংযুক্তি (Attachment)</label>
                    <div style="border:2px dashed #94a3b8;border-radius:8px;padding:16px;background:#f8fafc;max-width:520px;">
                        <button type="button" id="noticeAttachmentBtn" class="btn btn-outline-primary btn-sm mb-2">
                            <i class="fa-solid fa-upload"></i> PDF/Word ফাইল বাছাই করুন
                        </button>
                        <!--
                            মোবাইলের কিছু ব্রাউজার/ওয়েবভিউতে display:none করা ফাইল ইনপুটে
                            label[for] দিয়ে ক্লিক পাস করা কাজ করে না (ফাইল পিকার খোলে না) —
                            তাই display:none এর বদলে ভিজুয়ালি-হিডেন CSS ব্যবহার করা হচ্ছে,
                            আর উপরের বাটনে JS দিয়ে সরাসরি .click() কল করা হচ্ছে
                        -->
                        <input type="file" name="notice_attachment" id="noticeAttachmentInput" accept=".pdf,.doc,.docx"
                            style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
                        <div id="noticeAttachmentFileName" class="small text-muted">কোনো ফাইল সিলেক্ট করা হয়নি।</div>
                        <div class="small text-muted mt-2" style="line-height:1.6;">সমর্থিত ফরম্যাটঃ PDF, DOC, DOCX &nbsp;•&nbsp; সর্বোচ্চ সাইজঃ 10MB</div>

                        <?php if ($view === 'edit' && !empty($d['attachment_path'])): ?>
                            <div class="mt-3 p-2 border rounded d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#fff;">
                                <a href="../<?php echo wv($d['attachment_path']); ?>" target="_blank" class="text-truncate" style="max-width:100%;"><i class="fa-solid fa-paperclip"></i> বর্তমান ফাইলঃ <?php echo wv($d['attachment_name']); ?></a>
                                <span class="form-check mb-0 text-nowrap">
                                    <input class="form-check-input" type="checkbox" name="remove_attachment" id="removeAttachment" value="1">
                                    <label class="form-check-label small text-danger" for="removeAttachment">এই ফাইলটি মুছে ফেলুন</label>
                                </span>
                            </div>
                            <div class="small text-muted mt-2">নতুন ফাইল আপলোড করলে পুরনোটা স্বয়ংক্রিয়ভাবে বদলে যাবে।</div>
                        <?php endif; ?>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_notice" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_notice" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>

            <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
            <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.js"></script>
            <script>
            (function () {
                var noticeQuill = null;
                try {
                    if (typeof Quill !== 'undefined') {
                        noticeQuill = new Quill('#noticeBodyQuill', {
                            theme: 'snow',
                            modules: { toolbar: [['bold', 'italic', 'underline'], [{ 'list': 'ordered' }, { 'list': 'bullet' }], ['link'], ['clean']] }
                        });
                    }
                } catch (e) {
                    // Quill CDN লোড না হলে (নেট ধীর/অফলাইন) চুপচাপ সাধারণ ডিভ হিসেবে থেকে যাবে, ফর্ম তবুও সাবমিট হবে
                    noticeQuill = null;
                }
                var formEl = document.querySelector('form[action="index.php?page=website_notice"]');
                if (formEl) {
                    formEl.addEventListener('submit', function () {
                        if (noticeQuill) {
                            document.getElementById('noticeBodyInput').value = noticeQuill.root.innerHTML;
                        }
                    });
                }

                // "PDF/Word ফাইল বাছাই করুন" বাটনে ক্লিক করলে সরাসরি হিডেন ফাইল ইনপুটের
                // .click() কল করা হচ্ছে — label[for] এর উপর নির্ভর না করে, যাতে যেসব
                // মোবাইল ব্রাউজার/ওয়েবভিউতে label ক্লিক পাস করে না সেখানেও কাজ করে
                var attachInput = document.getElementById('noticeAttachmentInput');
                var attachBtn = document.getElementById('noticeAttachmentBtn');
                var attachLabel = document.getElementById('noticeAttachmentFileName');
                if (attachInput && attachBtn) {
                    attachBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        attachInput.click();
                    });
                }
                if (attachInput && attachLabel) {
                    attachInput.addEventListener('change', function () {
                        if (attachInput.files && attachInput.files.length > 0) {
                            attachLabel.innerHTML = '<i class="fa-solid fa-circle-check text-success"></i> সিলেক্ট করা হয়েছেঃ ' + attachInput.files[0].name;
                        } else {
                            attachLabel.textContent = 'কোনো ফাইল সিলেক্ট করা হয়নি।';
                        }
                    });
                }
            })();
            </script>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'notices.title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'notices.is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT notices.*, branches.branch_name FROM notices LEFT JOIN branches ON branches.id = notices.branch_id";
            if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY notice_date DESC, notices.id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $notices = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_notice&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($notices); ?> of <?php echo count($notices); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_notice">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Title</th><th>Date</th><th>Branch</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wv($f_title); ?>"></th>
                                <th></th>
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
                            <?php if ($notices): $srl = 1; foreach ($notices as $n): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><?php echo wv($n['title']); ?> <?php if (!empty($n['attachment_path'])): ?><i class="fa-solid fa-paperclip text-muted" title="সংযুক্তি আছে"></i><?php endif; ?></td>
                                    <td class="small text-muted"><?php echo wv($n['notice_date']); ?></td>
                                    <td><?php echo $n['branch_name'] ? '<span class="badge bg-info text-dark">' . wv($n['branch_name']) . '</span>' : '<span class="badge bg-light text-dark border">All Branches</span>'; ?></td>
                                    <td><?php echo $n['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_notice&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_notice&view=edit&id=<?php echo (int) $n['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_notice', 'delete_notice', <?php echo (int) $n['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No notices found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== SLIDER ============================== ?>
    <?php if ($active_tab === 'slider'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'title' => '', 'subtitle' => '', 'image_path' => '', 'button_text' => '', 'button_link' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM sliders WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-images text-white"></i></div>
            <form method="POST" action="index.php?page=website_slider" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Title <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wv($d['title']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Subtitle</label>
                    <div class="col-sm-6"><textarea name="subtitle" class="form-control" rows="2"><?php echo wv($d['subtitle']); ?></textarea></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Image <?php echo $view === 'edit' ? '<br><small class="text-muted fw-normal">(পরিবর্তন না করলে খালি রাখুন)</small>' : ''; ?><span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="file" name="slider_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" <?php echo $view === 'edit' ? '' : 'required'; ?>>
                        <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                            <img src="../<?php echo wv($d['image_path']); ?>" class="mt-2" style="width:120px;height:70px;object-fit:cover;border-radius:4px;" alt="">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Button Text</label>
                    <div class="col-sm-6"><input type="text" name="button_text" class="form-control" placeholder="বিস্তারিত জানুন" value="<?php echo wv($d['button_text']); ?>"></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Button Link</label>
                    <div class="col-sm-6"><input type="text" name="button_link" class="form-control" placeholder="#about অথবা admission.php" value="<?php echo wv($d['button_link']); ?>"></div>
                </div>
                <div class="row mb-4 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                    <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-sm-6 offset-sm-3">
                        <button type="submit" name="save_slider" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_slider" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_title  = trim($_GET['f_title'] ?? '');
            $f_status = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM sliders"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $sliders = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_slider&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($sliders); ?> of <?php echo count($sliders); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_slider">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Preview</th><th>Title</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wv($f_title); ?>"></th>
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
                            <?php if ($sliders): $srl = 1; foreach ($sliders as $s): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><img src="../<?php echo wv($s['image_path']); ?>" style="width:90px;height:50px;object-fit:cover;border-radius:4px;" alt=""></td>
                                    <td class="text-start"><?php echo wv($s['title']); ?></td>
                                    <td><?php echo (int)$s['sort_order']; ?></td>
                                    <td><?php echo $s['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_slider&view=edit&id=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_slider&view=edit&id=<?php echo (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_slider', 'delete_slider', <?php echo (int) $s['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No slides found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ============================== GALLERY ============================== ?>
    <?php if ($active_tab === 'gallery'): ?>
        <?php if ($view === 'add' || $view === 'edit'):
            $d = ['id' => 0, 'caption' => '', 'image_path' => '', 'sort_order' => 0];
            if ($view === 'edit') {
                $stmt = $pdo->prepare("SELECT * FROM gallery_items WHERE id = ?");
                $stmt->execute([(int) ($_GET['id'] ?? 0)]);
                $row = $stmt->fetch();
                if ($row) $d = $row;
            }
        ?>
            <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-image text-white"></i></div>
            <form method="POST" action="index.php?page=website_gallery" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Caption <span class="text-danger">*</span></label>
                    <div class="col-sm-6"><input type="text" name="caption" class="form-control" value="<?php echo wv($d['caption']); ?>" required></div>
                </div>
                <div class="row mb-3 align-items-center">
                    <label class="col-sm-3 col-form-label fw-bold text-end">Image <?php echo $view === 'edit' ? '<br><small class="text-muted fw-normal">(পরিবর্তন না করলে খালি রাখুন)</small>' : ''; ?><span class="text-danger">*</span></label>
                    <div class="col-sm-6">
                        <input type="file" name="gallery_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" <?php echo $view === 'edit' ? '' : 'required'; ?>>
                        <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                            <img src="../<?php echo wv($d['image_path']); ?>" class="mt-2" style="width:100px;height:70px;object-fit:cover;border-radius:4px;" alt="">
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
                        <button type="submit" name="save_gallery" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                        <a href="index.php?page=website_gallery" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        <?php else:
            $f_caption = trim($_GET['f_caption'] ?? '');
            $f_status  = $_GET['f_status'] ?? 'All';
            $where = []; $params = [];
            if ($f_caption !== '') { $where[] = 'caption LIKE ?'; $params[] = "%$f_caption%"; }
            if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
            $sql = "SELECT * FROM gallery_items"; if ($where) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY sort_order ASC, id ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $gallery = $stmt->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="index.php?page=website_gallery&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
                <span class="text-muted small">Showing 1-<?php echo count($gallery); ?> of <?php echo count($gallery); ?> item.</span>
            </div>
            <form method="GET" action="index.php">
                <input type="hidden" name="page" value="website_gallery">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                            <tr><th>Srl.</th><th>Preview</th><th>Caption</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                            <tr>
                                <th></th><th></th>
                                <th><input type="text" name="f_caption" class="form-control form-control-sm" value="<?php echo wv($f_caption); ?>"></th>
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
                            <?php if ($gallery): $srl = 1; foreach ($gallery as $g): $csrf = htmlspecialchars(csrf_token()); ?>
                                <tr>
                                    <td><?php echo $srl++; ?></td>
                                    <td><img src="../<?php echo wv($g['image_path']); ?>" style="width:80px;height:50px;object-fit:cover;border-radius:4px;" alt=""></td>
                                    <td class="text-start"><?php echo wv($g['caption']); ?></td>
                                    <td><?php echo (int)$g['sort_order']; ?></td>
                                    <td><?php echo $g['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                    <td class="text-nowrap">
                                        <a href="index.php?page=website_gallery&view=edit&id=<?php echo (int) $g['id']; ?>" class="btn btn-sm btn-outline-secondary" title="View / Edit"><i class="fa-solid fa-eye"></i></a>
                                        <a href="index.php?page=website_gallery&view=edit&id=<?php echo (int) $g['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_gallery', 'delete_gallery', <?php echo (int) $g['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No gallery photos found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
