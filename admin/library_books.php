<?php
// admin/library_books.php - Library Module > Book Management
$lb_err = $_SESSION['lb_err'] ?? '';
$lb_msg = $_SESSION['lb_msg'] ?? '';
unset($_SESSION['lb_err'], $_SESSION['lb_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(150) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
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
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_issues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        student_id INT NOT NULL,
        issue_date DATE NOT NULL,
        due_date DATE NOT NULL,
        return_date DATE NULL,
        fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        fine_paid TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Issued','Returned','Lost') NOT NULL DEFAULT 'Issued',
        remarks VARCHAR(255) NULL,
        issued_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_book (book_id), INDEX idx_student (student_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['lb_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=library_books');
        exit();
    }

    if (isset($_POST['save_book'])) {
        $id           = (int) ($_POST['id'] ?? 0);
        $book_code    = trim($_POST['book_code'] ?? '');
        $isbn         = trim($_POST['isbn'] ?? '');
        $title        = trim($_POST['title'] ?? '');
        $title_bn     = trim($_POST['title_bn'] ?? '');
        $author       = trim($_POST['author'] ?? '');
        $publisher    = trim($_POST['publisher'] ?? '');
        $category_id  = (int) ($_POST['category_id'] ?? 0) ?: null;
        $edition      = trim($_POST['edition'] ?? '');
        $language     = trim($_POST['language'] ?? 'Bengali');
        $shelf_no     = trim($_POST['shelf_no'] ?? '');
        $rack_no      = trim($_POST['rack_no'] ?? '');
        $price        = (float) ($_POST['price'] ?? 0);
        $total_copies = max(1, (int) ($_POST['total_copies'] ?? 1));
        $status       = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

        if ($title === '') {
            $_SESSION['lb_err'] = 'Book Title খালি রাখা যাবে না।';
        } else {
            if ($id > 0) {
                // ইতিমধ্যে ইস্যু হওয়া কপি বাদে available_copies রিক্যালকুলেট করা হচ্ছে
                $issued_stmt = $pdo->prepare("SELECT COUNT(*) c FROM library_issues WHERE book_id = ? AND status = 'Issued'");
                $issued_stmt->execute([$id]);
                $issued_count = (int) $issued_stmt->fetch()['c'];
                $available = max(0, $total_copies - $issued_count);

                $stmt = $pdo->prepare("UPDATE library_books SET book_code=?, isbn=?, title=?, title_bn=?, author=?, publisher=?, category_id=?, edition=?, language=?, shelf_no=?, rack_no=?, price=?, total_copies=?, available_copies=?, status=? WHERE id=?");
                $stmt->execute([$book_code ?: null, $isbn, $title, $title_bn, $author, $publisher, $category_id, $edition, $language, $shelf_no, $rack_no, $price, $total_copies, $available, $status, $id]);
                sec_log_activity($pdo, 'Library', 'Update', "বইয়ের তথ্য আপডেট করা হয়েছে: {$title} (Code: {$book_code})");
                $_SESSION['lb_msg'] = 'বইয়ের তথ্য আপডেট করা হয়েছে।';
            } else {
                $stmt = $pdo->prepare("INSERT INTO library_books (book_code, isbn, title, title_bn, author, publisher, category_id, edition, language, shelf_no, rack_no, price, total_copies, available_copies, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$book_code ?: null, $isbn, $title, $title_bn, $author, $publisher, $category_id, $edition, $language, $shelf_no, $rack_no, $price, $total_copies, $total_copies, $status]);
                $new_id = (int) $pdo->lastInsertId();
                if ($book_code === '') {
                    $book_code = 'LIB-' . str_pad((string) $new_id, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("UPDATE library_books SET book_code = ? WHERE id = ?")->execute([$book_code, $new_id]);
                }
                sec_log_activity($pdo, 'Library', 'Create', "নতুন বই যোগ করা হয়েছে: {$title} (Code: {$book_code})");
                $_SESSION['lb_msg'] = 'নতুন বই যোগ করা হয়েছে।';
            }
        }
    } elseif (isset($_POST['delete_book'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $check = $pdo->prepare("SELECT COUNT(*) c FROM library_issues WHERE book_id = ? AND status = 'Issued'");
        $check->execute([$id]);
        if ((int) $check->fetch()['c'] > 0) {
            $_SESSION['lb_err'] = 'এই বইয়ের কপি এখনো কারো কাছে ইস্যু করা আছে, মুছে ফেলা যাবে না।';
        } else {
            $del_stmt = $pdo->prepare("SELECT title, book_code FROM library_books WHERE id = ?");
            $del_stmt->execute([$id]);
            $del_book = $del_stmt->fetch();
            $pdo->prepare("DELETE FROM library_books WHERE id = ?")->execute([$id]);
            sec_log_activity($pdo, 'Library', 'Delete', "বই মুছে ফেলা হয়েছে: " . ($del_book['title'] ?? "#$id") . " (Code: " . ($del_book['book_code'] ?? '-') . ")");
            $_SESSION['lb_msg'] = 'বই মুছে ফেলা হয়েছে।';
        }
    }

    header('Location: index.php?page=library_books');
    exit();
}

$view = $_GET['view'] ?? 'list';
$categories = $pdo->query("SELECT id, category_name FROM library_categories WHERE status='Active' ORDER BY category_name ASC")->fetchAll();
?>

<?php if ($view === 'add' || $view === 'edit'): ?>
    <?php
    $edit_row = null;
    if ($view === 'edit') {
        $edit_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM library_books WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_row = $stmt->fetch();
    }
    $d = $edit_row ?: [
        'id' => 0, 'book_code' => '', 'isbn' => '', 'title' => '', 'title_bn' => '', 'author' => '',
        'publisher' => '', 'category_id' => '', 'edition' => '', 'language' => 'Bengali', 'shelf_no' => '',
        'rack_no' => '', 'price' => 0, 'total_copies' => 1, 'status' => 'Active',
    ];
    ?>
    <div class="card-custom">
        <?php if ($lb_err): ?><div class="alert alert-danger py-2"><?php echo lv($lb_err); ?></div><?php endif; ?>

        <div class="bg-warning bg-opacity-75 p-2 mb-4" style="width:40px;border-radius:4px;">
            <i class="fa-solid fa-book text-white"></i>
        </div>

        <form method="POST" action="index.php?page=library_books">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Book Code</label>
                        <div class="col-sm-8"><input type="text" name="book_code" class="form-control" value="<?php echo lv($d['book_code']); ?>" placeholder="খালি রাখলে অটো তৈরি হবে (LIB-0001)"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">ISBN</label>
                        <div class="col-sm-8"><input type="text" name="isbn" class="form-control" value="<?php echo lv($d['isbn']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Title (English) <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="title" class="form-control" value="<?php echo lv($d['title']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Title (বাংলা)</label>
                        <div class="col-sm-8"><input type="text" name="title_bn" class="form-control" value="<?php echo lv($d['title_bn']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Author</label>
                        <div class="col-sm-8"><input type="text" name="author" class="form-control" value="<?php echo lv($d['author']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Publisher</label>
                        <div class="col-sm-8"><input type="text" name="publisher" class="form-control" value="<?php echo lv($d['publisher']); ?>"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Category</label>
                        <div class="col-sm-8">
                            <select name="category_id" class="form-select">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $d['category_id'] === (string) $c['id'] ? 'selected' : ''; ?>><?php echo lv($c['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Edition</label>
                        <div class="col-sm-8"><input type="text" name="edition" class="form-control" value="<?php echo lv($d['edition']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Language</label>
                        <div class="col-sm-8">
                            <select name="language" class="form-select">
                                <?php foreach (['Bengali', 'English', 'Arabic', 'Other'] as $lng): ?>
                                    <option value="<?php echo $lng; ?>" <?php echo $d['language'] === $lng ? 'selected' : ''; ?>><?php echo $lng; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Shelf No.</label>
                        <div class="col-sm-4"><input type="text" name="shelf_no" class="form-control" value="<?php echo lv($d['shelf_no']); ?>"></div>
                        <label class="col-sm-1 col-form-label fw-bold text-end p-0">Rack</label>
                        <div class="col-sm-3"><input type="text" name="rack_no" class="form-control" value="<?php echo lv($d['rack_no']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Price (৳)</label>
                        <div class="col-sm-8"><input type="number" step="0.01" min="0" name="price" class="form-control" value="<?php echo lv($d['price']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Total Copies <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="number" min="1" name="total_copies" class="form-control" value="<?php echo lv($d['total_copies']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-4 col-form-label fw-bold text-end">Status</label>
                        <div class="col-sm-8">
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo $d['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $d['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            <div class="row">
                <div class="col-sm-6 offset-sm-3">
                    <button type="submit" name="save_book" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $view === 'edit' ? 'Update' : 'Save Book'; ?></button>
                    <a href="index.php?page=library_books" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php
    $f_title    = trim($_GET['f_title'] ?? '');
    $f_category = $_GET['f_category'] ?? '';
    $f_status   = $_GET['f_status'] ?? 'All';

    $where = [];
    $params = [];
    if ($f_title !== '')    { $where[] = '(lb.title LIKE ? OR lb.title_bn LIKE ? OR lb.author LIKE ? OR lb.book_code LIKE ?)'; $params[] = "%$f_title%"; $params[] = "%$f_title%"; $params[] = "%$f_title%"; $params[] = "%$f_title%"; }
    if ($f_category !== '') { $where[] = 'lb.category_id = ?'; $params[] = (int) $f_category; }
    if ($f_status !== 'All') { $where[] = 'lb.status = ?'; $params[] = $f_status; }

    $sql = "SELECT lb.*, lc.category_name FROM library_books lb LEFT JOIN library_categories lc ON lc.id = lb.category_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY lb.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $total_books_count = (int) $pdo->query("SELECT COALESCE(SUM(total_copies),0) c FROM library_books")->fetch()['c'];
    $available_count    = (int) $pdo->query("SELECT COALESCE(SUM(available_copies),0) c FROM library_books")->fetch()['c'];
    ?>
    <div class="row mb-3">
        <div class="col-md-4">
            <div class="top-stat-box" style="background:#1e3a8a;">
                <div><h3><?php echo count($rows); ?></h3><p>মোট বইয়ের শিরোনাম</p></div>
                <div class="top-stat-icon"><i class="fa-solid fa-book"></i></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="top-stat-box" style="background:#0284c7;">
                <div><h3><?php echo $total_books_count; ?></h3><p>মোট কপি সংখ্যা</p></div>
                <div class="top-stat-icon"><i class="fa-solid fa-layer-group"></i></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="top-stat-box" style="background:#10b981;">
                <div><h3><?php echo $available_count; ?></h3><p>শেলফে উপলব্ধ কপি</p></div>
                <div class="top-stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            </div>
        </div>
    </div>

    <div class="card-custom">
        <?php if ($lb_msg): ?><div class="alert alert-success py-2"><?php echo lv($lb_msg); ?></div><?php endif; ?>
        <?php if ($lb_err): ?><div class="alert alert-danger py-2"><?php echo lv($lb_err); ?></div><?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <a href="index.php?page=library_books&view=add" class="btn btn-success"><i class="fa-solid fa-plus"></i> Add Book</a>
            <span class="text-muted small">Showing 1-<?php echo count($rows); ?> of <?php echo count($rows); ?> items.</span>
        </div>

        <form method="GET" action="index.php" class="row g-2 mb-3">
            <input type="hidden" name="page" value="library_books">
            <div class="col-md-4"><input type="text" name="f_title" class="form-control" placeholder="Title / Author / Code দিয়ে খুঁজুন" value="<?php echo lv($f_title); ?>"></div>
            <div class="col-md-3">
                <select name="f_category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (string) $f_category === (string) $c['id'] ? 'selected' : ''; ?>><?php echo lv($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="f_status" class="form-select">
                    <option value="All" <?php echo $f_status === 'All' ? 'selected' : ''; ?>>All Status</option>
                    <option value="Active" <?php echo $f_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $f_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-primary w-100"><i class="fa-solid fa-filter"></i> Filter</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Category</th>
                        <th>Shelf/Rack</th>
                        <th>Copies (Avail/Total)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows): foreach ($rows as $row): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><span class="badge bg-dark"><?php echo lv($row['book_code']); ?></span></td>
                            <td>
                                <div class="fw-bold"><?php echo lv($row['title']); ?></div>
                                <?php if ($row['title_bn']): ?><div class="text-muted small"><?php echo lv($row['title_bn']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo lv($row['author']); ?></td>
                            <td><?php echo lv($row['category_name']); ?></td>
                            <td><?php echo lv($row['shelf_no']); ?><?php echo $row['rack_no'] ? ' / ' . lv($row['rack_no']) : ''; ?></td>
                            <td>
                                <span class="badge <?php echo $row['available_copies'] > 0 ? 'bg-success' : 'bg-danger'; ?>"><?php echo (int) $row['available_copies']; ?></span>
                                / <?php echo (int) $row['total_copies']; ?>
                            </td>
                            <td><?php echo $row['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-nowrap">
                                <?php if ((int) $row['available_copies'] > 0): ?>
                                <a href="index.php?page=library_issue&book_id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Issue এই বই"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
                                <?php endif; ?>
                                <a href="index.php?page=library_books&view=edit&id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                <form method="POST" action="index.php?page=library_books" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_book" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">No books found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
