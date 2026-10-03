<?php
// admin/library_settings.php - Library Module > Settings (Fine Rate / Due Days / Max Books)
$ls_err = $_SESSION['ls_err'] ?? '';
$ls_msg = $_SESSION['ls_msg'] ?? '';
unset($_SESSION['ls_err'], $_SESSION['ls_msg']);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS library_settings (
        id INT PRIMARY KEY DEFAULT 1, fine_per_day DECIMAL(6,2) NOT NULL DEFAULT 5.00,
        max_days_allowed INT NOT NULL DEFAULT 14, max_books_per_student INT NOT NULL DEFAULT 2
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if (!$pdo->query("SELECT id FROM library_settings WHERE id=1")->fetch()) {
    $pdo->exec("INSERT INTO library_settings (id, fine_per_day, max_days_allowed, max_books_per_student) VALUES (1, 5.00, 14, 2)");
}

function lv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['ls_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $fine_per_day = max(0, (float) ($_POST['fine_per_day'] ?? 5));
        $max_days     = max(1, (int) ($_POST['max_days_allowed'] ?? 14));
        $max_books    = max(1, (int) ($_POST['max_books_per_student'] ?? 2));

        $pdo->prepare("UPDATE library_settings SET fine_per_day=?, max_days_allowed=?, max_books_per_student=? WHERE id=1")
            ->execute([$fine_per_day, $max_days, $max_books]);
        sec_log_activity($pdo, 'Library', 'Update', "Library Settings আপডেট করা হয়েছে (Fine/Day: ৳{$fine_per_day}, Max Days: {$max_days}, Max Books: {$max_books})");
        $_SESSION['ls_msg'] = 'সেটিংস সংরক্ষণ করা হয়েছে।';
    }
    header('Location: index.php?page=library_settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM library_settings WHERE id=1")->fetch();
?>

<div class="card-custom" style="max-width:700px;">
    <?php if ($ls_msg): ?><div class="alert alert-success py-2"><?php echo lv($ls_msg); ?></div><?php endif; ?>
    <?php if ($ls_err): ?><div class="alert alert-danger py-2"><?php echo lv($ls_err); ?></div><?php endif; ?>

    <h5 class="fw-normal text-dark mb-4"><i class="fa-solid fa-gear text-primary me-1"></i> Library Settings</h5>

    <form method="POST" action="index.php?page=library_settings">
        <?php echo csrf_field(); ?>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-5 col-form-label fw-bold text-end">Fine per Day (৳)</label>
            <div class="col-sm-5">
                <input type="number" step="0.01" min="0" name="fine_per_day" class="form-control" value="<?php echo lv($settings['fine_per_day']); ?>" required>
            </div>
        </div>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-5 col-form-label fw-bold text-end">Max Days Allowed (ইস্যু মেয়াদ)</label>
            <div class="col-sm-5">
                <input type="number" min="1" name="max_days_allowed" class="form-control" value="<?php echo lv($settings['max_days_allowed']); ?>" required>
            </div>
        </div>

        <div class="row mb-4 align-items-center">
            <label class="col-sm-5 col-form-label fw-bold text-end">Max Books per Student</label>
            <div class="col-sm-5">
                <input type="number" min="1" name="max_books_per_student" class="form-control" value="<?php echo lv($settings['max_books_per_student']); ?>" required>
            </div>
        </div>

        <hr>
        <div class="row">
            <div class="col-sm-5 offset-sm-5">
                <button type="submit" name="save_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;">Save Settings</button>
            </div>
        </div>
    </form>
</div>
