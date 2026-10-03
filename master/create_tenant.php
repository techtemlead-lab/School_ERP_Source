<?php
require_once __DIR__ . '/_guard.php';
masterRequireAccess($pdo_master, 'create_tenant', 'add');

$errors = [];
$success = false;
$manual_sql = null;

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return substr($s, 0, 30) ?: 'school' . rand(100, 999);
}

/**
 * schema.sql কে আলাদা আলাদা SQL statement এ ভাঙে — শুধু ";\n" এ split করার
 * বদলে, quote (' এবং ") এর ভেতরের সেমিকোলন বাদ দিয়ে, প্রতিটা আসল
 * statement-terminating সেমিকোলন ধরে split করে। CRLF/LF দুটোই হ্যান্ডল করে।
 */
function split_sql_statements(string $sql): array
{
    $sql = str_replace("\r\n", "\n", $sql); // CRLF -> LF নরমালাইজ
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $inQuote = null; // ' বা " বা null
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $i + 1 < $len) { // এস্কেপ ক্যারেক্টার — পরের ক্যারেক্টার যেভাবেই থাকুক নিয়ে নাও
                $buf .= $sql[++$i];
                continue;
            }
            if ($ch === $inQuote) { $inQuote = null; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inQuote = $ch; $buf .= $ch; continue; }
        if ($ch === ';') {
            $statements[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') { $statements[] = $buf; }

    // কমেন্ট-অনলি বা খালি statement বাদ দাও
    $clean = [];
    foreach ($statements as $stmt) {
        $lines = array_filter(
            array_map('rtrim', explode("\n", $stmt)),
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '--')
        );
        $t = trim(implode("\n", $lines));
        if ($t !== '') { $clean[] = $t; }
    }
    return $clean;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, আবার চেষ্টা করুন।';
    } else {
        $school_name   = trim($_POST['school_name'] ?? '');
        $school_code   = slugify($_POST['school_code'] ?? $school_name);
        $subdomain     = $school_code;
        $custom_domain = trim($_POST['custom_domain'] ?? '') ?: null;
        $plan          = $_POST['plan'] ?? 'Trial';
        $contact_name  = trim($_POST['contact_name'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');

        // Super Admin (এই স্কুলের admin panel-এ লগইন করার একমাত্র মালিক অ্যাকাউন্ট)
        $super_admin_username = trim($_POST['super_admin_username'] ?? '');
        $super_admin_password = (string) ($_POST['super_admin_password'] ?? '');

        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = trim($_POST['db_pass'] ?? '');
        $db_mode       = ($_POST['db_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual';
        $create_db_now = $db_mode === 'auto'; // Auto সিলেক্ট করলেই স্ক্রিপ্ট নিজে DB বানানোর চেষ্টা করবে

        if ($school_name === '' || $db_name === '' || $db_user === '' || $db_pass === '') {
            $errors[] = 'স্কুলের নাম, DB Name, DB User, DB Password — সবগুলো পূরণ করা আবশ্যক।';
        }
        if ($super_admin_username === '' || $super_admin_password === '') {
            $errors[] = 'Super Admin User ID (username/phone/email) এবং Password — দুটোই আবশ্যক।';
        } elseif (strlen($super_admin_password) < 6) {
            $errors[] = 'Super Admin Password কমপক্ষে ৬ ক্যারেক্টার হতে হবে।';
        }

        // school_code / subdomain আগে থেকে থাকলে
        if (!$errors) {
            $chk = $pdo_master->prepare('SELECT id FROM tenants WHERE school_code = ? OR subdomain = ?');
            $chk->execute([$school_code, $subdomain]);
            if ($chk->fetch()) {
                $errors[] = 'এই school_code/subdomain ইতিমধ্যে ব্যবহৃত হয়েছে। আলাদা একটা দাও।';
            }
        }

        // ধাপ ১: DB তৈরি (ঐচ্ছিক — শেয়ার্ড হোস্টিং এ সাধারণত এই প্রিভিলেজ থাকে না,
        // তাই fail করলে manual SQL instruction দেখানো হবে)
        if (!$errors && $create_db_now) {
            try {
                // এই কানেকশনের জন্য master DB user এরই CREATE privilege লাগবে
                $pdo_master->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo_master->exec("CREATE USER IF NOT EXISTS '{$db_user}'@'localhost' IDENTIFIED BY " . $pdo_master->quote($db_pass));
                $pdo_master->exec("GRANT ALL PRIVILEGES ON `{$db_name}`.* TO '{$db_user}'@'localhost'");
                $pdo_master->exec("FLUSH PRIVILEGES");
            } catch (PDOException $e) {
                $manual_sql = "CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                    . "CREATE USER '{$db_user}'@'localhost' IDENTIFIED BY 'তোমার_পাসওয়ার্ড';\n"
                    . "GRANT ALL PRIVILEGES ON `{$db_name}`.* TO '{$db_user}'@'localhost';\n"
                    . "FLUSH PRIVILEGES;";
                $errors[] = 'অটো DB তৈরি করা যায়নি (শেয়ার্ড হোস্টিং এ সাধারণত এই পারমিশন থাকে না)। '
                    . 'নিচের SQL cPanel > phpMyAdmin এ ম্যানুয়ালি রান করে, অথবা cPanel > MySQL Databases থেকে DB+User বানিয়ে, তারপর "Manual" অপশন সিলেক্ট করে আবার সাবমিট করো।';
            }
        }

        // ধাপ ২: নতুন DB-তে কানেক্ট করে schema.sql ইমপোর্ট
        if (!$errors) {
            try {
                $tenant_pdo = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
                $statements = split_sql_statements($sql);
                $stepNo = 0;
                foreach ($statements as $stmt) {
                    $stepNo++;
                    try {
                        $tenant_pdo->exec($stmt);
                    } catch (PDOException $stmtErr) {
                        $preview = mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 120);
                        throw new PDOException(
                            "Statement #{$stepNo} এ সমস্যা — {$stmtErr->getMessage()} | SQL: {$preview}..."
                        );
                    }
                }
            } catch (PDOException $e) {
                $errors[] = 'DB তে কানেক্ট বা schema import করা যায়নি: ' . $e->getMessage()
                    . ' — নিশ্চিত করো DB Host/Name/User/Password সঠিক এবং DB টা আগে থেকে তৈরি আছে।';
            }
        }

        // ধাপ ২.৫: schema.sql-এর ডিফল্ট admin এর বদলে, ফর্মে দেওয়া Super Admin
        // username/password বসিয়ে দেওয়া হচ্ছে — এটাই এই স্কুলের একমাত্র Super Admin অ্যাকাউন্ট
        if (!$errors) {
            try {
                $hash = password_hash($super_admin_password, PASSWORD_BCRYPT);
                // schema.sql-এ শুধু একটাই admin রো ইনসার্ট হয় (WHERE NOT EXISTS দিয়ে) —
                // তাই ORDER BY id ASC LIMIT 1 দিয়ে নিরাপদে সেই একটা রো-ই আপডেট করা হচ্ছে
                $upd = $tenant_pdo->prepare('UPDATE admin SET username = ?, password = ? ORDER BY id ASC LIMIT 1');
                $upd->execute([$super_admin_username, $hash]);
                if ($upd->rowCount() === 0) {
                    // কোনো কারণে admin রো না থাকলে (যেমন পুরনো/আংশিক schema), সরাসরি ইনসার্ট
                    $tenant_pdo->prepare('INSERT INTO admin (username, password) VALUES (?, ?)')
                        ->execute([$super_admin_username, $hash]);
                }
            } catch (PDOException $e) {
                $errors[] = 'Super Admin অ্যাকাউন্ট সেট করা যায়নি: ' . $e->getMessage();
            }
        }

        // ধাপ ২.৬: schema.sql-এ 'Your School Name' নামে জেনেরিক ডেমো
        // institute_settings ও branches রো ইনসার্ট হয় — সেগুলো এখন ফর্মে দেওয়া আসল
        // school_name / contact_phone / contact_email দিয়ে ওভাররাইট করা হচ্ছে।
        // (logo_path/favicon_path/banner_path ইচ্ছাকৃতভাবে ছোঁয়া হচ্ছে না — ওগুলো
        // schema.sql-এই NULL/খালি থাকে, স্কুল নিজে পরে আপলোড করে নেবে।)
        if (!$errors) {
            try {
                $tenant_pdo->prepare(
                    'UPDATE institute_settings SET institute_name = ?, phone_number = ?, email = ? WHERE id = 1'
                )->execute([$school_name, $contact_phone, $contact_email]);

                $tenant_pdo->prepare(
                    'UPDATE branches SET branch_name = ?, phone = ?, email = ? ORDER BY id ASC LIMIT 1'
                )->execute([$school_name, $contact_phone, $contact_email]);
            } catch (PDOException $e) {
                $errors[] = 'Institute/Branch তথ্য সেট করা যায়নি: ' . $e->getMessage();
            }
        }

        // ধাপ ৩: master DB তে tenant রেজিস্টার
        if (!$errors) {
            $stmt = $pdo_master->prepare(
                'INSERT INTO tenants (school_name, school_code, subdomain, custom_domain, db_host, db_name, db_user, db_pass, plan, status, contact_name, contact_phone, contact_email, trial_ends_at)
                 VALUES (?,?,?,?,?,?,?,?,?,\'Active\',?,?,?,?)'
            );
            $trial_ends = $plan === 'Trial' ? date('Y-m-d', strtotime('+14 days')) : null;
            $stmt->execute([$school_name, $school_code, $subdomain, $custom_domain, $db_host, $db_name, $db_user, $db_pass, $plan, $contact_name, $contact_phone, $contact_email, $trial_ends]);
            logActivity($pdo_master, (int) $pdo_master->lastInsertId(), 'tenant_created', $school_name);
            $success = true;
        }
    }
}
$pageTitle = 'নতুন স্কুল যোগ করুন';
$pageIcon  = '➕';
$activeKey = 'create_tenant';
require_once __DIR__ . '/_layout_top.php';
?>
    <div style="max-width: 720px;">

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5 class="fw-bold">✅ স্কুল সফলভাবে যোগ হয়েছে!</h5>
                <p class="mb-1">Subdomain: <code><?php echo htmlspecialchars($subdomain); ?>.তোমারডোমেইন.com</code></p>
                <p class="mb-1">টেস্ট করতে: <code>?school=<?php echo htmlspecialchars($school_code); ?></code> যোগ করে ভিজিট করো</p>
                <p class="mb-0">Super Admin লগইন: <b><?php echo htmlspecialchars($super_admin_username); ?></b> / <b>(আপনার দেওয়া পাসওয়ার্ড)</b></p>
            </div>
            <a href="create_tenant.php" class="btn btn-primary">আরেকটা স্কুল যোগ করো</a>
            <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard এ যাও</a>
        <?php else: ?>

            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
            <?php if ($manual_sql): ?>
                <div class="alert alert-warning">
                    <b>ম্যানুয়ালি রান করার SQL:</b>
                    <pre class="mb-0 mt-2" style="white-space:pre-wrap;"><?php echo htmlspecialchars($manual_sql); ?></pre>
                </div>
            <?php endif; ?>

            <div class="card-custom p-4">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <h6 class="fw-bold mb-3">স্কুলের তথ্য</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">স্কুলের নাম *</label>
                            <input type="text" name="school_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Subdomain Code (শুধু ছোট হাতের অক্ষর/সংখ্যা)</label>
                            <input type="text" name="school_code" class="form-control" placeholder="e.g. school1" value="<?php echo htmlspecialchars($_POST['school_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Custom Domain (ঐচ্ছিক)</label>
                            <input type="text" name="custom_domain" class="form-control" placeholder="erp.clientschool.com" value="<?php echo htmlspecialchars($_POST['custom_domain'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Plan</label>
                            <select name="plan" class="form-select">
                                <option>Trial</option><option>Basic</option><option>Standard</option><option>Premium</option>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">যোগাযোগ (স্কুলের দায়িত্বশীল)</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">নাম</label>
                            <input type="text" name="contact_name" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">ফোন</label>
                            <input type="tel" name="contact_phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">ইমেইল</label>
                            <input type="email" name="contact_email" class="form-control">
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Super Admin অ্যাকাউন্ট</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">User ID (Username / Phone / Email) *</label>
                            <input type="text" name="super_admin_username" class="form-control" required placeholder="e.g. 01700000000 বা admin@school.com" value="<?php echo htmlspecialchars($_POST['super_admin_username'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Password *</label>
                            <div class="input-group">
                                <input type="password" id="pw_super_admin" name="super_admin_password" class="form-control" required minlength="6" placeholder="কমপক্ষে ৬ ক্যারেক্টার" value="<?php echo htmlspecialchars($_POST['super_admin_password'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="const f=document.getElementById('pw_super_admin'); f.type = f.type==='password' ? 'text' : 'password';">👁</button>
                            </div>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">এই User ID + Password দিয়েই স্কুলের অ্যাডমিন প্যানেলে (admin/index.php) Super Admin হিসেবে লগইন করা যাবে। এখান থেকেই স্কুলের বাকি সব User (Admin/Teacher/Student ইত্যাদি) তৈরি করা যাবে।</small>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Database তথ্য</h6>
                    <?php $db_mode_val = $db_mode ?? 'manual'; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold d-block mb-2">DB তৈরি করার পদ্ধতি</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <input type="radio" name="db_mode" id="db_mode_auto" value="auto" class="btn-check" <?php echo $db_mode_val === 'auto' ? 'checked' : ''; ?>>
                            <label for="db_mode_auto" class="px-3 py-2 rounded" style="cursor:pointer; border:2px solid var(--sidebar-border, #333); background: var(--sidebar-bg, #1a1a1a); font-size: 13px;">
                                ⚙️ <b>Auto</b>
                            </label>

                            <input type="radio" name="db_mode" id="db_mode_manual" value="manual" class="btn-check" <?php echo $db_mode_val !== 'auto' ? 'checked' : ''; ?>>
                            <label for="db_mode_manual" class="px-3 py-2 rounded" style="cursor:pointer; border:2px solid var(--sidebar-border, #333); background: var(--sidebar-bg, #1a1a1a); font-size: 13px;">
                                ✋ <b>Manual</b> <span class="badge bg-success" style="font-size:10px;">শেয়ার্ড হোস্টিং</span>
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">Auto: স্ক্রিপ্ট নিজে DB+User বানানোর চেষ্টা করবে (VPS-এ কাজ করে)। Manual: cPanel থেকে আগে DB+User বানিয়ে রাখলে শুধু schema import হবে (শেয়ার্ড হোস্টিং-এ প্রযোজ্য)।</small>
                    </div>
                    <style>
                        .btn-check:checked + label { border-color: #7c3aed !important; box-shadow: 0 0 0 1px #7c3aed; }
                    </style>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Host</label>
                            <input type="text" name="db_host" class="form-control" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Name *</label>
                            <input type="text" name="db_name" class="form-control" required placeholder="dbprefix_school1" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB User *</label>
                            <input type="text" name="db_user" class="form-control" required placeholder="dbprefix_school1" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">DB Password *</label>
                            <div class="input-group">
                                <input type="password" id="pw_db_pass" name="db_pass" class="form-control" required value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="const f=document.getElementById('pw_db_pass'); f.type = f.type==='password' ? 'text' : 'password';">👁</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success fw-bold w-100 py-2">স্কুল তৈরি করো</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না, ১১ ডিজিটের বেশি হলে কেটে দেয়
document.querySelectorAll('.phone-11').forEach(function (input) {
    // প্রতিটা ফিল্ডের ঠিক পরে একটা এরর মেসেজ বসানো হচ্ছে (আগে থেকে না থাকলে)
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }

    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        // খালি রাখা যাবে (আবশ্যক না হলে) — কিন্তু কিছু লিখলে অবশ্যই ১১ ডিজিট হতে হবে,
        // ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে (রিলোড ছাড়া) সরে যাবে
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
