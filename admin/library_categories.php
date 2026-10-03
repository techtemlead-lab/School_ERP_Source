<?php
// admin/library_categories.php - Library Module > Book Categories
$lc_err = $_SESSION['lc_err'] ?? '';
$lc_msg = $_SESSION['lc_msg'] ?? '';
unset($_SESSION['lc_err'], $_SESSION['lc_msg']);

// library_categories টেবিল না থাকলে অটো তৈরি
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
// library_books টেবিল না থাকলে অটো তৈরি (নিচের book_count সাব-কোয়েরি এই টেবিল ব্যবহার করে,
// এই পেজে সরাসরি আসলে অন্য কোনো Library পেজ আগে visit না করলেও crash যাতে না হয়)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_code VARCHAR(50) NULL,
        isbn VARCHAR(30) NULL,
        title VARCHAR(255) NOT NULL,
        title_bn VARCHAR(255) NULL,
        author VARCHAR(150) NULL,
        publisher VARCHAR(150) NULL,
        category_id INT NULL,
        edition VARCHAR(50) NULL,
        language VARCHAR(30) NOT NULL DEFAULT 'Bengali',
        shelf_no VARCHAR(30) NULL,
        rack_no VARCHAR(30) NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_copies INT NOT NULL DEFAULT 1,
        available_copies INT NOT NULL DEFAULT 1,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_book_code (book_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handlers (Create / Update / Delete)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lc_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=library_categories');
        exit();
    }

    if (isset($_POST['save_category'])) {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['category_name'] ?? '');
        $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($name === '') {
            $_SESSION['lc_err'] = 'Category Name খালি রাখা যাবে না।';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE library_categories SET category_name=?, status=? WHERE id=?");
                $stmt->execute([$name, $status, $id]);
                sec_log_activity($pdo, 'Library', 'Update', "Library Category আপডেট করা হয়েছে: {$name}");
                $_SESSION['lc_msg'] = 'Category আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO library_categories (category_name, status) VALUES (?, ?)");
                $stmt->execute([$name, $status]);
                sec_log_activity($pdo, 'Library', 'Create', "নতুন Library Category যোগ করা হয়েছে: {$name}");
                $_SESSION['lc_msg'] = 'নতুন Category যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_category'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) c FROM library_books WHERE category_id = ?");
        $check->execute([$id]);
        if ((int) $check->fetch()['c'] > 0) {
            $_SESSION['lc_err'] = 'এই Category তে বই আছে, মুছে ফেলা যাবে না। আগে বইগুলো অন্য Category তে সরান।';
        } else {
            $del_name_stmt = $pdo->prepare("SELECT category_name FROM library_categories WHERE id = ?");
            $del_name_stmt->execute([$id]);
            $del_name = $del_name_stmt->fetchColumn();
            $pdo->prepare("DELETE FROM library_categories WHERE id = ?")->execute([$id]);
            sec_log_activity($pdo, 'Library', 'Delete', "Library Category মুছে ফেলা হয়েছে: " . ($del_name ?: "#$id"));
            $_SESSION['lc_msg'] = 'Category মুছে ফেলা হয়েছে।';
        }
    }

    header('Location: index.php?page=library_categories');
    exit();
}

$view = $_GET['view'] ?? 'list';
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM library_categories WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: ['id' => 0, 'category_name' => '', 'status' => 'Active'];
    ?>
    <div class="card-custom">
        <?php if ($lc_err): ?><div class="alert alert-danger py-2"><?php echo lv($lc_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-tags text-white"></i>
        </div>

        <form method="POST" action="index.php?page=library_categories">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row mb-3 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Category Name <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <input type="text" name="category_name" class="form-control" value="<?php echo lv($d['category_name']); ?>" placeholder="e.g. Text Book, Novel, Reference Book" required>
                </div>
            </div>

            <div class="row mb-4 align-items-center">
                <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                <div class="col-sm-6">
                    <select name="status" class="form-select">
                        <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_category" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Create'; ?></button>
                    <a href="index.php?page=library_categories" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $stmt = $pdo->query("SELECT lc.*, (SELECT COUNT(*) FROM library_books lb WHERE lb.category_id = lc.id) AS book_count
                          FROM library_categories lc ORDER BY lc.category_name ASC");
    $rows = $stmt->fetchAll();
    ?>
    <div class="card-custom">
        <?php if ($lc_msg): ?><div class="alert alert-success py-2"><?php echo lv($lc_msg); ?></div><?php endif; ?>
        <?php if ($lc_err): ?><div class="alert alert-danger py-2"><?php echo lv($lc_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=library_categories&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Category</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Srl.</th>
                        <th>Category Name</th>
                        <th>Books</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): $srl = 1; foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo lv($row['category_name']); ?></td>
                            <td><span class="badge bg-info text-dark"><?php echo (int) $row['book_count']; ?></span></td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <a href="index.php?page=library_categories&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=library_categories" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No categories found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
