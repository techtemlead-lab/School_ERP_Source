<?php
// admin/finance_money_receipt_settings.php - Finance Module > Money Receipt Settings
// Money Receipt প্রিন্ট করার সময় কোন কপি (Student/Office/Bank), কোন পেপার সাইজ
// (A4 Portrait/Landscape/Half), Due দেখাবে কিনা ইত্যাদি এখান থেকে কন্ট্রোল হয় —
// এই একটা সেটিং পুরো সফটওয়্যারের সবগুলো Money Receipt প্রিন্ট বাটনের জন্য প্রযোজ্য
// (Student Fees Collection, Quick Collection, Fees Re-collection, Money Receipt List)।

function mrs_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// -----------------------------------------------------------
// টেবিল না থাকলে অটো-তৈরি (Singleton — সবসময় id=1 একটাই রো)
// -----------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_money_receipt_settings (
        id INT PRIMARY KEY DEFAULT 1,
        copy_type VARCHAR(20) NOT NULL DEFAULT 'student',
        paper_size VARCHAR(20) NOT NULL DEFAULT 'a4_portrait',
        show_due TINYINT(1) NOT NULL DEFAULT 1,
        show_logo TINYINT(1) NOT NULL DEFAULT 1,
        show_signature TINYINT(1) NOT NULL DEFAULT 1,
        footer_note VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
if ((int) $pdo->query("SELECT COUNT(*) FROM finance_money_receipt_settings")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO finance_money_receipt_settings (id) VALUES (1)");
}

// পুরনো ইনস্টলেশনে show_waiver কলাম না থাকলে যোগ করে দেওয়া (ডিফল্ট: চালু)
$mrs_cols = $pdo->query("SHOW COLUMNS FROM finance_money_receipt_settings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('show_waiver', $mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver TINYINT(1) NOT NULL DEFAULT 1 AFTER show_due");
}
if (!in_array('show_waiver_student', $mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN show_waiver_student TINYINT(1) NOT NULL DEFAULT 1 AFTER show_waiver");
}
// পুরনো ইনস্টলেশনে allow_instant_waiver কলাম না থাকলে যোগ করে দেওয়া (ডিফল্ট: বন্ধ) —
// এই টগল চালু থাকলেই Student Fees Collection পেজে Pay Now-এর পাশে Instant Waiver ঘর দেখাবে
if (!in_array('allow_instant_waiver', $mrs_cols, true)) {
    $pdo->exec("ALTER TABLE finance_money_receipt_settings ADD COLUMN allow_instant_waiver TINYINT(1) NOT NULL DEFAULT 0 AFTER show_waiver_student");
}

$mrs_copy_types = [
    'student'       => 'শুধু Student Copy',
    'office_student' => 'Office Copy + Student Copy',
    'office_bank_student' => 'Office Copy + Bank Copy + Student Copy',
];
$mrs_paper_sizes = [
    'a4_portrait'  => 'A4 Portrait (সিলেক্ট করা সবগুলো কপি একই A4 পেজে পাশাপাশি প্রিন্ট হবে)',
    'a4_landscape' => 'A4 Landscape (সিলেক্ট করা সবগুলো কপি একই A4 পেজে পাশাপাশি, লম্বালম্বি)',
    'a5'           => 'A5 (প্রতিটা কপি আলাদা আলাদা A5 সাইজের পেজে আলাদাভাবে প্রিন্ট হবে)',
];

// -----------------------------------------------------------
// সেভ
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mr_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['mrs_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=finance_money_receipt_settings');
        exit();
    }

    $copy_type = $_POST['copy_type'] ?? 'student';
    if (!isset($mrs_copy_types[$copy_type])) { $copy_type = 'student'; }

    $paper_size = $_POST['paper_size'] ?? 'a4_portrait';
    if (!isset($mrs_paper_sizes[$paper_size])) { $paper_size = 'a4_portrait'; }

    $show_due = isset($_POST['show_due']) ? 1 : 0;
    $show_waiver = isset($_POST['show_waiver']) ? 1 : 0;
    $show_waiver_student = isset($_POST['show_waiver_student']) ? 1 : 0;
    $allow_instant_waiver = isset($_POST['allow_instant_waiver']) ? 1 : 0;
    $show_logo = isset($_POST['show_logo']) ? 1 : 0;
    $show_signature = isset($_POST['show_signature']) ? 1 : 0;
    $footer_note = trim($_POST['footer_note'] ?? '');
    if (mb_strlen($footer_note) > 255) { $footer_note = mb_substr($footer_note, 0, 255); }

    $stmt = $pdo->prepare(
        "UPDATE finance_money_receipt_settings
         SET copy_type = ?, paper_size = ?, show_due = ?, show_waiver = ?, show_waiver_student = ?, allow_instant_waiver = ?, show_logo = ?, show_signature = ?, footer_note = ?
         WHERE id = 1"
    );
    $stmt->execute([$copy_type, $paper_size, $show_due, $show_waiver, $show_waiver_student, $allow_instant_waiver, $show_logo, $show_signature, $footer_note ?: null]);

    sec_log_activity($pdo, 'Finance', 'Update', 'Money Receipt Settings আপডেট করা হয়েছে।');
    $_SESSION['mrs_msg'] = 'Money Receipt Settings সেভ করা হয়েছে।';
    header('Location: index.php?page=finance_money_receipt_settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM finance_money_receipt_settings WHERE id = 1")->fetch();
$mrs_msg = $_SESSION['mrs_msg'] ?? '';
$mrs_err = $_SESSION['mrs_err'] ?? '';
unset($_SESSION['mrs_msg'], $_SESSION['mrs_err']);
?>

<div class="card-custom" style="max-width: 820px;">
    <h5 class="fw-bold mb-3"><i class="fa-solid fa-receipt"></i> Money Receipt Settings</h5>
    <p class="text-muted small">এই সেটিংস অনুযায়ীই Student Fees Collection, Quick Collection, Fees Re-collection এবং
        Money Receipt List পেজের "Print Money Receipt" বাটনগুলো রিসিট প্রিন্ট করবে।</p>

    <?php if ($mrs_msg): ?><div class="alert alert-success py-2"><?php echo mrs_e($mrs_msg); ?></div><?php endif; ?>
    <?php if ($mrs_err): ?><div class="alert alert-danger py-2"><?php echo mrs_e($mrs_err); ?></div><?php endif; ?>

    <form method="POST" action="index.php?page=finance_money_receipt_settings">
        <?php echo csrf_field(); ?>

        <label class="form-label fw-bold">রিসিটে কোন কোন কপি প্রিন্ট হবে?</label>
        <div class="mb-4">
            <?php foreach ($mrs_copy_types as $key => $label): ?>
                <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="copy_type" id="ct_<?php echo $key; ?>" value="<?php echo $key; ?>" <?php echo $settings['copy_type'] === $key ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ct_<?php echo $key; ?>"><?php echo mrs_e($label); ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <label class="form-label fw-bold">পেপার সাইজ / লেআউট</label>
        <div class="mb-4">
            <?php foreach ($mrs_paper_sizes as $key => $label): ?>
                <div class="form-check mb-1">
                    <input class="form-check-input" type="radio" name="paper_size" id="ps_<?php echo $key; ?>" value="<?php echo $key; ?>" <?php echo $settings['paper_size'] === $key ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ps_<?php echo $key; ?>"><?php echo mrs_e($label); ?></label>
                </div>
            <?php endforeach; ?>
        </div>

        <label class="form-label fw-bold">অন্যান্য অপশন</label>
        <div class="mb-4">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="show_due" name="show_due" <?php echo $settings['show_due'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_due">রিসিটে বকেয়া (Due) দেখাবে</label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="show_waiver" name="show_waiver" <?php echo !empty($settings['show_waiver']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_waiver">Office Copy ও Bank Copy-তে Fee, Waiver ও Paid আলাদা দেখাবে</label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="show_waiver_student" name="show_waiver_student" <?php echo !empty($settings['show_waiver_student']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_waiver_student">Student Copy-তে Fee, Waiver ও Paid আলাদা দেখাবে <span class="text-muted small">(বন্ধ থাকলে শুধু Paid অঙ্ক দেখাবে)</span></label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="allow_instant_waiver" name="allow_instant_waiver" <?php echo !empty($settings['allow_instant_waiver']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="allow_instant_waiver">Fees Collection পেজগুলোতে Instant Waiver দেওয়ার অপশন চালু <span class="text-muted small">(চালু করলে Student Fees Collection ও Quick Collection পেজে Waiver ঘর দেখাবে, যেটা Waiver Report-এ যোগ হবে)</span></label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="show_logo" name="show_logo" <?php echo $settings['show_logo'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_logo">প্রতিষ্ঠানের লোগো দেখাবে</label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="show_signature" name="show_signature" <?php echo $settings['show_signature'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_signature">স্বাক্ষরের ঘর (Cashier/Guardian) দেখাবে</label>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold" for="footer_note">ফুটার নোট (ঐচ্ছিক)</label>
            <input type="text" class="form-control" id="footer_note" name="footer_note" maxlength="255" placeholder="যেমনঃ ধন্যবাদান্তে, রশিদ ছাড়া টাকা ফেরত/দাবি গ্রহণযোগ্য নয়।" value="<?php echo mrs_e($settings['footer_note']); ?>">
        </div>

        <button type="submit" name="save_mr_settings" class="btn btn-primary fw-bold px-4"><i class="fa-solid fa-floppy-disk"></i> সেভ করুন</button>
    </form>
</div>
