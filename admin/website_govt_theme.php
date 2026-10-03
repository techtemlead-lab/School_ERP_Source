<?php
// admin/website_govt_theme.php - Website Module > "Govt Theme Settings" (আলাদা সাব-সেকশন)
// শুধুমাত্র তখনই sidebar-এ দেখা যায় যখন Institute Settings > Website Theme = "Govt Theme-1"
// সিলেক্ট করা থাকে (দেখুন admin/sidebar.php)। দুটো ট্যাব — এপিএ (APA) ও শুদ্ধাচার কৌশল —
// দুটোই একই website_govt_documents টেবিলে doc_type কলাম দিয়ে আলাদা করা।
// পাবলিক সাইটে এই ডাটা website_theme_govt1.php-এর নেভিগেশন মেনু থেকে দেখানো হয়।

$wgt_msg = $_SESSION['wgt_msg'] ?? '';
$wgt_err = $_SESSION['wgt_err'] ?? '';
unset($_SESSION['wgt_msg'], $_SESSION['wgt_err']);

$active_tab = 'apa';
if ($page === 'website_govt_suddhachar') $active_tab = 'suddhachar';

$view = $_GET['view'] ?? 'list';

// -----------------------------------------------------------
// টেবিল না থাকলে অটো তৈরি
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS website_govt_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_type ENUM('APA','Suddhachar') NOT NULL DEFAULT 'APA',
        title VARCHAR(200) NOT NULL,
        doc_year VARCHAR(20) NULL,
        doc_link VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$wgt_doc_type = $active_tab === 'suddhachar' ? 'Suddhachar' : 'APA';
$wgt_tab_label = $active_tab === 'suddhachar' ? 'শুদ্ধাচার কৌশল' : 'এপিএ (APA)';
$wgt_tab_page  = $active_tab === 'suddhachar' ? 'website_govt_suddhachar' : 'website_govt_apa';

// -----------------------------------------------------------
// POST Handler
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wgt_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=' . $page);
        exit();
    }

    if (isset($_POST['save_govt_doc'])) {
        $id       = (int) ($_POST['id'] ?? 0);
        $doc_type = ($_POST['doc_type'] ?? 'APA') === 'Suddhachar' ? 'Suddhachar' : 'APA';
        $title    = trim($_POST['title'] ?? '');
        $year     = trim($_POST['doc_year'] ?? '');
        $link     = trim($_POST['doc_link'] ?? '');
        $sort     = (int) ($_POST['sort_order'] ?? 0);
        $redirect_page = $doc_type === 'Suddhachar' ? 'website_govt_suddhachar' : 'website_govt_apa';

        if ($title === '' || $link === '') {
            $_SESSION['wgt_err'] = 'শিরোনাম ও লিংক আবশ্যক।';
            header('Location: index.php?page=' . $redirect_page . '&view=' . ($id ? "edit&id=$id" : 'add'));
            exit();
        }
        if ($id > 0) {
            $pdo->prepare("UPDATE website_govt_documents SET doc_type=?, title=?, doc_year=?, doc_link=?, sort_order=? WHERE id=?")
                ->execute([$doc_type, $title, $year ?: null, $link, $sort, $id]);
            $_SESSION['wgt_msg'] = 'ডকুমেন্ট আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "Govt Theme - " . ($doc_type === 'Suddhachar' ? 'শুদ্ধাচার কৌশল' : 'এপিএ') . " ডকুমেন্ট আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_govt_documents (doc_type, title, doc_year, doc_link, sort_order) VALUES (?, ?, ?, ?, ?)")
                ->execute([$doc_type, $title, $year ?: null, $link, $sort]);
            $_SESSION['wgt_msg'] = 'ডকুমেন্ট যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "Govt Theme - " . ($doc_type === 'Suddhachar' ? 'শুদ্ধাচার কৌশল' : 'এপিএ') . " ডকুমেন্ট যুক্ত করা হয়েছে: {$title}");
        }
        header('Location: index.php?page=' . $redirect_page);
        exit();
    } elseif (isset($_POST['toggle_govt_doc'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_govt_documents SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wgt_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_govt_doc'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $del_doc = $pdo->prepare("SELECT title FROM website_govt_documents WHERE id = ?");
        $del_doc->execute([$id]);
        $del_doc_title = $del_doc->fetchColumn();
        $pdo->prepare("DELETE FROM website_govt_documents WHERE id = ?")->execute([$id]);
        $_SESSION['wgt_msg'] = 'ডকুমেন্ট মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "Govt Theme ডকুমেন্ট ডিলিট করা হয়েছে: " . ($del_doc_title ?: "#$id"));
    }

    header('Location: index.php?page=' . $page);
    exit();
}

function wgtv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<div class="card-custom">
    <h4 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-landmark"></i> Website Module — Govt Theme Settings</h4>

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
    <p class="text-muted small mb-4">
        <i class="fa-solid fa-circle-info me-1"></i>
        এই সেকশনটা শুধু তখনই কাজে লাগে যখন <b>Institute Settings &gt; Website Theme</b>-এ
        <b>"Govt Theme-1"</b> সিলেক্ট করা থাকে — এখানে যা যোগ করবেন তা পাবলিক ওয়েবসাইটের
        Govt Theme-এর নেভিগেশন মেনুতে ("এপিএ" / "শুদ্ধাচার কৌশল") সরাসরি দেখা যাবে।
    </p>

    <?php if ($wgt_msg): ?><div class="alert alert-success py-2"><?php echo wgtv($wgt_msg); ?></div><?php endif; ?>
    <?php if ($wgt_err): ?><div class="alert alert-danger py-2"><?php echo wgtv($wgt_err); ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'apa' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_govt_apa">এপিএ (APA)</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $active_tab === 'suddhachar' ? 'active fw-bold' : ''; ?>" href="index.php?page=website_govt_suddhachar">শুদ্ধাচার কৌশল</a></li>
    </ul>

    <?php if ($view === 'add' || $view === 'edit'):
        $d = ['id' => 0, 'doc_type' => $wgt_doc_type, 'title' => '', 'doc_year' => '', 'doc_link' => '', 'sort_order' => 0];
        if ($view === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM website_govt_documents WHERE id = ?");
            $stmt->execute([(int) ($_GET['id'] ?? 0)]);
            $row = $stmt->fetch();
            if ($row) $d = $row;
        }
    ?>
        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;"><i class="fa-solid fa-file-lines text-white"></i></div>
        <form method="POST" action="index.php?page=<?php echo $wgt_tab_page; ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
            <input type="hidden" name="doc_type" value="<?php echo wgtv($wgt_doc_type); ?>">
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">শিরোনাম <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="title" class="form-control" placeholder="যেমন: <?php echo $wgt_tab_label; ?> ২০২৬" value="<?php echo wgtv($d['title']); ?>" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">সাল (ঐচ্ছিক)</label>
                <div class="col-sm-6"><input type="text" name="doc_year" class="form-control" placeholder="2026" value="<?php echo wgtv($d['doc_year']); ?>"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">ডকুমেন্ট লিংক <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="doc_link" class="form-control" placeholder="PDF আপলোড লিংক অথবা https://..." value="<?php echo wgtv($d['doc_link']); ?>" required></div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>"></div>
            </div>
            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_govt_doc" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=<?php echo $wgt_tab_page; ?>" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php else:
        $f_title  = trim($_GET['f_title'] ?? '');
        $f_status = $_GET['f_status'] ?? 'All';
        $where = ['doc_type = ?']; $params = [$wgt_doc_type];
        if ($f_title !== '') { $where[] = 'title LIKE ?'; $params[] = "%$f_title%"; }
        if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
        $sql = "SELECT * FROM website_govt_documents WHERE " . implode(' AND ', $where) . " ORDER BY sort_order ASC, id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $govt_docs = $stmt->fetchAll();
    ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=<?php echo $wgt_tab_page; ?>&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($govt_docs); ?> of <?php echo count($govt_docs); ?> item.</span>
        </div>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="<?php echo $wgt_tab_page; ?>">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr><th>Srl.</th><th>শিরোনাম</th><th>সাল</th><th>লিংক</th><th>Status</th><th>Actions</th></tr>
                        <tr>
                            <th></th>
                            <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wgtv($f_title); ?>"></th>
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
                        <?php if ($govt_docs): $srl = 1; foreach ($govt_docs as $gd): $csrf = htmlspecialchars(csrf_token()); ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td class="fw-bold"><?php echo wgtv($gd['title']); ?></td>
                                <td><?php echo wgtv($gd['doc_year']); ?></td>
                                <td class="small text-truncate" style="max-width:200px;"><a href="<?php echo wgtv($gd['doc_link']); ?>" target="_blank"><?php echo wgtv($gd['doc_link']); ?></a></td>
                                <td><?php echo $gd['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=<?php echo $wgt_tab_page; ?>&view=edit&id=<?php echo (int) $gd['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=<?php echo $wgt_tab_page; ?>', 'delete_govt_doc', <?php echo (int) $gd['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">এখনো কোনো <?php echo $wgt_tab_label; ?> ডকুমেন্ট যোগ করা হয়নি</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
