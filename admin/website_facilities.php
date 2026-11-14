<?php
// admin/website_facilities.php - Website Module > Facilities List
// ওয়েবসাইটের "আমাদের সুবিধাসমূহ" (Our Facilities) সেকশনের কার্ডগুলো এখান থেকে ম্যানেজ হয়
// (আগে index.php-তে হার্ডকোড করা ছিল)। ডেটা: config/website_facilities.php
require_once __DIR__ . '/../config/website_facilities.php';
if (!function_exists('site_i18n_dictionary')) { require_once __DIR__ . '/../config/lang.php'; } // প্রথমবার ডিফল্ট ৬টা বসানোর জন্য ডিকশনারি
wf_ensure_table($pdo);

$wf_msg = $_SESSION['wf_msg'] ?? '';
$wf_err = $_SESSION['wf_err'] ?? '';
unset($_SESSION['wf_msg'], $_SESSION['wf_err']);

$view = $_GET['view'] ?? 'list';

function wfa_handle_upload(string $field): array
{
    return erp_image_upload($field, false, 'website');
}

// শুধু নিজেদের আপলোড করা লোকাল ফাইল মুছবে (ডিফল্ট ছবির http লিংক নয়)
function wfa_delete_file(?string $relPath): void
{
    if ($relPath && !preg_match('#^https?://#i', $relPath)) {
        $abs = __DIR__ . '/../' . $relPath;
        if (is_file($abs)) { @unlink($abs); }
    }
}

function wfv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function wfa_img($path): string { return preg_match('#^https?://#i', (string) $path) ? wfv($path) : '../' . wfv($path); }

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['wf_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=website_facilities');
        exit();
    }

    if (isset($_POST['save_facility'])) {
        $id    = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $title_en = trim($_POST['title_en'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $desc_en = trim($_POST['description_en'] ?? '');
        $sort  = (int) ($_POST['sort_order'] ?? 0);
        $back  = 'index.php?page=website_facilities&view=' . ($id ? "edit&id=$id" : 'add');

        if ($title === '') {
            $_SESSION['wf_err'] = 'শিরোনাম (Title) দিন।';
            header("Location: $back");
            exit();
        }
        $img = wfa_handle_upload('facility_image');
        if (!$img['ok']) {
            $_SESSION['wf_err'] = $img['error'];
            header("Location: $back");
            exit();
        }
        if ($id > 0) {
            $old_row = null;
            if ($img['path']) {
                $old = $pdo->prepare("SELECT image_path FROM website_facilities WHERE id = ?");
                $old->execute([$id]);
                $old_row = $old->fetch();
                $pdo->prepare("UPDATE website_facilities SET title=?, title_en=?, description=?, description_en=?, image_path=?, sort_order=? WHERE id=?")
                    ->execute([$title, $title_en ?: null, $desc ?: null, $desc_en ?: null, $img['path'], $sort, $id]);
                if ($old_row) wfa_delete_file($old_row['image_path']);
            } else {
                $pdo->prepare("UPDATE website_facilities SET title=?, title_en=?, description=?, description_en=?, sort_order=? WHERE id=?")
                    ->execute([$title, $title_en ?: null, $desc ?: null, $desc_en ?: null, $sort, $id]);
            }
            $_SESSION['wf_msg'] = 'সুবিধা আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Update', "ওয়েবসাইট Facility আপডেট করা হয়েছে: {$title}");
        } else {
            $pdo->prepare("INSERT INTO website_facilities (title, title_en, description, description_en, image_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$title, $title_en ?: null, $desc ?: null, $desc_en ?: null, $img['path'], $sort]);
            $_SESSION['wf_msg'] = 'নতুন সুবিধা যোগ করা হয়েছে।';
            sec_log_activity($pdo, 'Website', 'Create', "ওয়েবসাইট Facility যুক্ত করা হয়েছে: {$title}");
        }
    } elseif (isset($_POST['toggle_facility'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_facilities SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        $_SESSION['wf_msg'] = 'স্ট্যাটাস বদলানো হয়েছে।';
    } elseif (isset($_POST['delete_facility'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $row_stmt = $pdo->prepare("SELECT image_path, title FROM website_facilities WHERE id = ?");
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch();
        $pdo->prepare("DELETE FROM website_facilities WHERE id = ?")->execute([$id]);
        if ($row) wfa_delete_file($row['image_path']);
        $_SESSION['wf_msg'] = 'সুবিধা মুছে ফেলা হয়েছে।';
        sec_log_activity($pdo, 'Website', 'Delete', "ওয়েবসাইট Facility ডিলিট করা হয়েছে: " . ($row['title'] ?? "#$id"));
    }
    header('Location: index.php?page=website_facilities');
    exit();
}
?>

<div class="card-custom">
    <h4 class="fw-bold mb-4 text-dark"><i class="fa-solid fa-globe"></i> Website Module &gt; Facilities</h4>

    <!-- শেয়ার্ড হিডেন ফর্ম (Toggle/Delete) — GET ফিল্টার ফর্মের বাইরে, যাতে নেস্টেড ফর্ম না হয় -->
    <form method="POST" id="wcRowActionForm" style="display:none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
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

    <?php if ($wf_msg): ?><div class="alert alert-success py-2"><?php echo wfv($wf_msg); ?></div><?php endif; ?>
    <?php if ($wf_err): ?><div class="alert alert-danger py-2"><?php echo wfv($wf_err); ?></div><?php endif; ?>

    <?php if ($view === 'add' || $view === 'edit'):
        $d = ['id' => 0, 'title' => '', 'title_en' => '', 'description' => '', 'description_en' => '', 'image_path' => '', 'sort_order' => 0];
        if ($view === 'edit') {
            $stmt = $pdo->prepare("SELECT * FROM website_facilities WHERE id = ?");
            $stmt->execute([(int) ($_GET['id'] ?? 0)]);
            $row = $stmt->fetch();
            if ($row) $d = $row;
        } else {
            $d['sort_order'] = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM website_facilities")->fetchColumn();
        }
    ?>
        <a href="index.php?page=website_facilities" class="d-inline-block bg-warning bg-opacity-75 p-2 mb-4 text-decoration-none" style="width:40px;border-radius:4px;" title="তালিকায় ফিরে যান"><i class="fa-solid fa-file-lines text-white"></i></a>
        <form method="POST" action="index.php?page=website_facilities" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Title (বাংলা) <span class="text-danger">*</span></label>
                <div class="col-sm-6"><input type="text" name="title" class="form-control" value="<?php echo wfv($d['title']); ?>" placeholder="যেমন: ডিজিটাল ক্লাসরুম" required></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Title (English)</label>
                <div class="col-sm-6"><input type="text" name="title_en" class="form-control" value="<?php echo wfv($d['title_en']); ?>" placeholder="e.g. Digital Classroom"></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Description (বাংলা)</label>
                <div class="col-sm-6"><textarea name="description" class="form-control" rows="2"><?php echo wfv($d['description']); ?></textarea></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Description (English)</label>
                <div class="col-sm-6"><textarea name="description_en" class="form-control" rows="2"><?php echo wfv($d['description_en']); ?></textarea>
                    <small class="text-muted">English ফাঁকা রাখলে ইংরেজি সাইটেও বাংলা লেখাই দেখাবে (উল্টোটাও)।</small></div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Image</label>
                <div class="col-sm-6">
                    <input type="file" name="facility_image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    <small class="text-muted">কার্ডের ব্যাকগ্রাউন্ড ছবি (সর্বোচ্চ 3MB)। না দিলে নীল গ্রেডিয়েন্ট দেখাবে।</small>
                    <?php if ($view === 'edit' && !empty($d['image_path'])): ?>
                        <div><img src="<?php echo wfa_img($d['image_path']); ?>" class="mt-2" style="width:140px;height:80px;object-fit:cover;border-radius:4px;" alt=""></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Sort Order</label>
                <div class="col-sm-6"><input type="number" name="sort_order" class="form-control" value="<?php echo (int) $d['sort_order']; ?>">
                    <small class="text-muted">ছোট সংখ্যা আগে দেখায়।</small></div>
            </div>
            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_facility" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=website_facilities" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    <?php else:
        $f_title  = trim($_GET['f_title'] ?? '');
        $f_status = $_GET['f_status'] ?? 'All';
        $where = []; $params = [];
        if ($f_title !== '') { $where[] = '(title LIKE ? OR title_en LIKE ?)'; $params[] = "%$f_title%"; $params[] = "%$f_title%"; }
        if ($f_status !== 'All') { $where[] = 'is_active = ?'; $params[] = $f_status === 'Active' ? 1 : 0; }
        $sql = "SELECT * FROM website_facilities" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY sort_order ASC, id ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $items = $stmt->fetchAll();
    ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=website_facilities&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i></a>
            <span class="text-muted small">Showing 1-<?php echo count($items); ?> of <?php echo count($items); ?> item.</span>
        </div>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="website_facilities">
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
                    <thead class="table-light">
                        <tr><th>Srl.</th><th>Image</th><th>Title</th><th>Description</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                        <tr>
                            <th></th><th></th>
                            <th><input type="text" name="f_title" class="form-control form-control-sm" value="<?php echo wfv($f_title); ?>"></th>
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
                        <?php if ($items): $srl = 1; foreach ($items as $it): ?>
                            <tr>
                                <td><?php echo $srl++; ?></td>
                                <td><?php if ($it['image_path']): ?><img src="<?php echo wfa_img($it['image_path']); ?>" style="width:80px;height:50px;object-fit:cover;border-radius:4px;" alt=""><?php else: ?>—<?php endif; ?></td>
                                <td class="text-start"><?php echo wfv($it['title']); ?><?php if (!empty($it['title_en'])): ?><br><small class="text-muted"><?php echo wfv($it['title_en']); ?></small><?php endif; ?></td>
                                <td class="text-start small text-muted"><?php echo wfv(mb_strimwidth((string) $it['description'], 0, 80, '…', 'UTF-8')); ?></td>
                                <td><?php echo (int) $it['sort_order']; ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm p-0 border-0" title="Active/Hidden বদলান" onclick="return wcRowAction('index.php?page=website_facilities', 'toggle_facility', <?php echo (int) $it['id']; ?>, '');">
                                        <?php echo $it['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?>
                                    </button>
                                </td>
                                <td class="text-nowrap">
                                    <a href="index.php?page=website_facilities&view=edit&id=<?php echo (int) $it['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return wcRowAction('index.php?page=website_facilities', 'delete_facility', <?php echo (int) $it['id']; ?>, 'মুছে ফেলবেন?');"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">কোনো সুবিধা নেই — ওয়েবসাইটে "আমাদের সুবিধাসমূহ" সেকশন লুকানো থাকবে।</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>
