<?php
// admin/scholarship_types.php - Finance Module > Scholarship & Welfare > Scholarship Types
// স্কলারশিপ/উপবৃত্তির ধরন তৈরি (সরকারি উপবৃত্তি, বোর্ড ট্যালেন্টপুল, এনজিও, স্কুলের নিজস্ব ইত্যাদি) —
// এই তালিকা থেকেই Assign Scholarship পেজে ছাত্র-ছাত্রীদের স্কলারশিপ এসাইন করা হয়।
require_once __DIR__ . '/../config/scholarship_helpers.php';
sch_ensure_tables($pdo);

function stv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$st_msg = $_SESSION['st_msg'] ?? '';
$st_err = $_SESSION['st_err'] ?? '';
unset($_SESSION['st_msg'], $_SESSION['st_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['st_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=scholarship_types');
        exit();
    }

    if (isset($_POST['save_type'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'Government';
        $description = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        if (!in_array($category, ['Government', 'Board', 'NGO', 'School'], true)) { $category = 'Government'; }

        if ($name === '') {
            $_SESSION['st_err'] = 'স্কলারশিপের নাম আবশ্যক।';
        } elseif ($id > 0) {
            $pdo->prepare("UPDATE scholarship_types SET name=?, category=?, description=?, status=? WHERE id=?")
                ->execute([$name, $category, $description, $status, $id]);
            $_SESSION['st_msg'] = 'স্কলারশিপ টাইপ আপডেট করা হয়েছে।';
            sec_log_activity($pdo, 'Scholarship', 'Update', "স্কলারশিপ টাইপ আপডেট করা হয়েছে: {$name} ({$category})");
        } else {
            $pdo->prepare("INSERT INTO scholarship_types (name, category, description, status) VALUES (?, ?, ?, ?)")
                ->execute([$name, $category, $description, $status]);
            $_SESSION['st_msg'] = 'নতুন স্কলারশিপ টাইপ তৈরি করা হয়েছে।';
            sec_log_activity($pdo, 'Scholarship', 'Create', "নতুন স্কলারশিপ টাইপ তৈরি করা হয়েছে: {$name} ({$category})");
        }
    } elseif (isset($_POST['delete_type'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $in_use = $pdo->prepare("SELECT COUNT(*) FROM scholarship_awards WHERE scholarship_type_id = ?");
        $in_use->execute([$id]);
        if ((int) $in_use->fetchColumn() > 0) {
            $_SESSION['st_err'] = 'এই টাইপে ইতিমধ্যে Award এসাইন করা আছে — মুছে ফেলা যাবে না, চাইলে Inactive করে দিন।';
        } else {
            $del_name_row = $pdo->prepare("SELECT name FROM scholarship_types WHERE id = ?");
            $del_name_row->execute([$id]);
            $del_name = $del_name_row->fetchColumn();
            $pdo->prepare("DELETE FROM scholarship_types WHERE id = ?")->execute([$id]);
            $_SESSION['st_msg'] = 'স্কলারশিপ টাইপ মুছে ফেলা হয়েছে।';
            sec_log_activity($pdo, 'Scholarship', 'Delete', "স্কলারশিপ টাইপ মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
        }
    }
    header('Location: index.php?page=scholarship_types');
    exit();
}

$view = $_GET['view'] ?? 'list';
$edit_row = null;
if ($view === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM scholarship_types WHERE id = ?");
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $edit_row = $stmt->fetch();
}
$c = $edit_row ?: ['id' => 0, 'name' => '', 'category' => 'Government', 'description' => '', 'status' => 'Active'];
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <div class="card-custom">
        <?php if ($st_err): ?><div class="alert alert-danger py-2"><?php echo stv($st_err); ?></div><?php endif; ?>
        <form method="POST" action="index.php?page=scholarship_types">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">নাম <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="name" class="form-control" required value="<?php echo stv($c['name']); ?>" placeholder="যেমন: প্রাথমিক উপবৃত্তি (PESP), মাধ্যমিক উপবৃত্তি, বোর্ড ট্যালেন্টপুল">
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">ধরন <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="category" class="form-select" required>
                        <option value="Government" <?php echo $c['category'] === 'Government' ? 'selected' : ''; ?>>সরকারি (Government)</option>
                        <option value="Board" <?php echo $c['category'] === 'Board' ? 'selected' : ''; ?>>বোর্ড (Board)</option>
                        <option value="NGO" <?php echo $c['category'] === 'NGO' ? 'selected' : ''; ?>>এনজিও (NGO)</option>
                        <option value="School" <?php echo $c['category'] === 'School' ? 'selected' : ''; ?>>স্কুলের নিজস্ব (School)</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">বিবরণ (ঐচ্ছিক)</label>
                <div class="col-sm-6">
                    <textarea name="description" class="form-control" rows="2"><?php echo stv($c['description']); ?></textarea>
                </div>
            </div>
            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status</label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $c['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $c['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <hr>
            <div class="text-center">
                <button type="submit" name="save_type" class="btn btn-primary px-4 fw-bold"><?php echo $c['id'] ? 'Update' : 'Create'; ?></button>
                <a href="index.php?page=scholarship_types" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <?php $rows = $pdo->query("SELECT * FROM scholarship_types ORDER BY id DESC")->fetchAll(); ?>
    <div class="card-custom">
        <?php if ($st_msg): ?><div class="alert alert-success py-2"><?php echo stv($st_msg); ?></div><?php endif; ?>
        <?php if ($st_err): ?><div class="alert alert-danger py-2"><?php echo stv($st_err); ?></div><?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=scholarship_types&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Scholarship Type</a>
            <a href="index.php?page=scholarship_award" class="btn btn-outline-primary"><i class="fa-solid fa-user-plus"></i> Assign Scholarship</a>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light"><tr><th>Srl.</th><th>নাম</th><th>ধরন</th><th>বিবরণ</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="fw-bold"><?php echo stv($row['name']); ?></td>
                            <td><?php echo stv(sch_category_label($row['category'])); ?></td>
                            <td class="text-muted small"><?php echo stv($row['description']); ?></td>
                            <td><span class="badge <?php echo $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo stv($row['status']); ?></span></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=scholarship_types&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=scholarship_types" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_type" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">কোনো স্কলারশিপ টাইপ তৈরি করা হয়নি। "Add Scholarship Type" থেকে শুরু করুন।</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
