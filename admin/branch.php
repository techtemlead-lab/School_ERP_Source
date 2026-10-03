<?php
// admin/branch.php - Branch Settings (Global Settings > Branch)
//
// পারমিশন:
//   - Super Admin: Add + Edit + Delete, সব করতে পারবে
//   - অন্য সবাই (Admin/Teacher/Accountant/HR/Data Entry Operator ইত্যাদি): শুধু
//     View + Edit করতে পারবে — নতুন ব্রাঞ্চ Add বা Delete করতে পারবে না
//     (এটা users_list.php/sms_users_*.php-এ ব্যবহৃত একই is_super_admin প্যাটার্ন)
$is_super_admin = $_SESSION['is_super_admin'] ?? true;

$branch_msg = $_SESSION['branch_msg'] ?? '';
$branch_err = $_SESSION['branch_err'] ?? '';
unset($_SESSION['branch_msg'], $_SESSION['branch_err']);

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$add_mode = isset($_GET['mode']) && $_GET['mode'] === 'add';

// -----------------------------------------------------------
// Authority Signature আপলোড হেল্পার
// -----------------------------------------------------------
function branch_handle_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের সাইজ 2MB এর বেশি হওয়া যাবে না।'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png/webp ফাইল আপলোড করা যাবে।'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইলের প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }

    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'branch');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ফাইল সেভ করা যায়নি।'];
    }

    return ['ok' => true, 'path' => tenant_upload_rel('branch') . '/' . $random_name, 'error' => null];
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['branch_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=branch');
        exit();
    }

    if (isset($_POST['save_branch'])) {
        $id = (int) ($_POST['id'] ?? 0);

        // নতুন ব্রাঞ্চ (id=0) শুধু Super Admin অ্যাড করতে পারবে — Update (id>0)
        // সবার জন্যই খোলা
        if ($id === 0 && !$is_super_admin) {
            $_SESSION['branch_err'] = 'নতুন ব্রাঞ্চ যোগ করার অনুমতি শুধু Super Admin-এর আছে।';
            header('Location: index.php?page=branch');
            exit();
        }

        $short_name    = trim($_POST['short_name'] ?? '');
        $branch_name   = trim($_POST['branch_name'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $city          = trim($_POST['city'] ?? '');
        $zip_code      = trim($_POST['zip_code'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $fax           = trim($_POST['fax'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $holidays_arr  = $_POST['weekly_holidays'] ?? [];
        $map_iframe    = trim($_POST['map_iframe'] ?? '');
        $status        = trim($_POST['status'] ?? 'Active');

        $valid_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $holidays_arr = array_values(array_intersect($holidays_arr, $valid_days));
        $weekly_holidays = $holidays_arr ? implode(',', $holidays_arr) : 'Friday';

        if (!in_array($status, ['Active', 'Inactive'], true)) $status = 'Active';

        // map_iframe-এ শুধু <iframe> ট্যাগ (এবং তার ভেতরের src/allow ইত্যাদি অ্যাট্রিবিউট) ছাড়া
        // বাকি সব ট্যাগ ফেলে দেওয়া হচ্ছে, script ইনজেকশন ঠেকাতে
        $map_iframe = strip_tags($map_iframe, '<iframe>');

        if ($short_name === '' || $branch_name === '' || $address === '' || $city === '' || $zip_code === '' || $phone === '') {
            $_SESSION['branch_err'] = 'Short Name, Branch Name, Address, City, Zip Code, Phone — এই ফিল্ডগুলো আবশ্যক।';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['branch_err'] = 'সঠিক ইমেইল দিন।';
        } else {
            $sig = branch_handle_upload('signature');
            if (!$sig['ok']) {
                $_SESSION['branch_err'] = $sig['error'];
            } else {
                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE branches SET short_name=?, branch_name=?, address=?, city=?, zip_code=?, phone=?, fax=?, email=?,
                            weekly_holidays=?, map_iframe=?, status=?, signature_path=COALESCE(?, signature_path)
                         WHERE id=?"
                    );
                    $stmt->execute([
                        $short_name, $branch_name, $address, $city, $zip_code, $phone, $fax ?: null, $email ?: null,
                        $weekly_holidays, $map_iframe ?: null, $status, $sig['path'], $id,
                    ]);
                    sec_log_activity($pdo, 'Branch', 'Update', "ব্রাঞ্চ তথ্য আপডেট করা হয়েছে: {$branch_name} ({$short_name})");
                    $_SESSION['branch_msg'] = 'ব্রাঞ্চ তথ্য আপডেট করা হয়েছে।';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO branches (short_name, branch_name, address, city, zip_code, phone, fax, email, weekly_holidays, map_iframe, status, signature_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $short_name, $branch_name, $address, $city, $zip_code, $phone, $fax ?: null, $email ?: null,
                        $weekly_holidays, $map_iframe ?: null, $status, $sig['path'],
                    ]);
                    sec_log_activity($pdo, 'Branch', 'Create', "নতুন ব্রাঞ্চ তৈরি করা হয়েছে: {$branch_name} ({$short_name})");
                    $_SESSION['branch_msg'] = 'নতুন ব্রাঞ্চ যোগ করা হয়েছে।';
                }
            }
        }
    } elseif (isset($_POST['delete_branch'])) {
        if (!$is_super_admin) {
            $_SESSION['branch_err'] = 'ব্রাঞ্চ ডিলিট করার অনুমতি শুধু Super Admin-এর আছে।';
            header('Location: index.php?page=branch');
            exit();
        }
        $id = (int) ($_POST['id'] ?? 0);
        $del_stmt = $pdo->prepare("SELECT branch_name, short_name FROM branches WHERE id = ?");
        $del_stmt->execute([$id]);
        $del_branch = $del_stmt->fetch();
        $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$id]);
        sec_log_activity($pdo, 'Branch', 'Delete', "ব্রাঞ্চ মুছে ফেলা হয়েছে: " . ($del_branch ? "{$del_branch['branch_name']} ({$del_branch['short_name']})" : "#$id"));
        $_SESSION['branch_msg'] = 'ব্রাঞ্চ মুছে ফেলা হয়েছে।';
    }

    header('Location: index.php?page=branch');
    exit();
}

// Add mode-এ non-super-admin সরাসরি URL দিয়ে ঢুকতে চাইলে লিস্টে ফেরত পাঠাও
if ($add_mode && !$is_super_admin) {
    $_SESSION['branch_err'] = 'নতুন ব্রাঞ্চ যোগ করার অনুমতি শুধু Super Admin-এর আছে।';
    header('Location: index.php?page=branch');
    exit();
}

$branches = $pdo->query("SELECT * FROM branches ORDER BY id ASC")->fetchAll();

$edit_branch = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_branch = $stmt->fetch();
}

$form_defaults = $edit_branch ?: [
    'id' => 0, 'short_name' => '', 'branch_name' => '', 'address' => '', 'city' => '', 'zip_code' => '',
    'phone' => '', 'fax' => '', 'email' => '', 'signature_path' => null, 'weekly_holidays' => 'Friday',
    'map_iframe' => '', 'status' => 'Active',
];
$selected_holidays = explode(',', $form_defaults['weekly_holidays']);

function bv($val): string { return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// পেজের মোড: 'form' (Add বা Edit — আলাদা, পুরো পেজ জুড়ে) অথবা 'list' (ডিফল্ট)
$show_form = $edit_branch || $add_mode;
?>

<div class="card-custom">

    <?php if ($show_form): ?>
        <!-- ===================== Add / Edit — আলাদা পেজ ===================== -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-building"></i> <?php echo $edit_branch ? 'Edit Branch' : 'Add New Branch'; ?></h4>
            <a href="index.php?page=branch" class="btn btn-outline-secondary fw-bold"><i class="fa-solid fa-arrow-left"></i> Branch List-এ ফিরে যাও</a>
        </div>

        <?php if ($branch_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($branch_err); ?></div><?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <form method="POST" action="index.php?page=branch" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $form_defaults['id']; ?>">

                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Short Name <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="short_name" class="form-control" value="<?php echo bv($form_defaults['short_name']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Branch Name <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="branch_name" class="form-control" value="<?php echo bv($form_defaults['branch_name']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-start">
                        <label class="col-sm-3 col-form-label fw-bold text-end pt-2">Address <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><textarea name="address" class="form-control" rows="3" required><?php echo bv($form_defaults['address']); ?></textarea></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">City <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="city" class="form-control" value="<?php echo bv($form_defaults['city']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Zip Code <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="text" name="zip_code" class="form-control" value="<?php echo bv($form_defaults['zip_code']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Phone/Mobile <span class="text-danger">*</span></label>
                        <div class="col-sm-8"><input type="tel" name="phone" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo bv($form_defaults['phone']); ?>" required></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Fax</label>
                        <div class="col-sm-8"><input type="text" name="fax" class="form-control" value="<?php echo bv($form_defaults['fax']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Email</label>
                        <div class="col-sm-8"><input type="email" name="email" class="form-control" value="<?php echo bv($form_defaults['email']); ?>"></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Authority Signature</label>
                        <div class="col-sm-8">
                            <input type="file" name="signature" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                            <?php if (!empty($form_defaults['signature_path'])): ?>
                                <small class="text-muted">বর্তমান: <a href="../<?php echo bv($form_defaults['signature_path']); ?>" target="_blank">দেখুন</a></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Weekly Holiday <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach (['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day): ?>
                                    <div class="form-check form-check-inline border rounded px-2 py-1">
                                        <input class="form-check-input" type="checkbox" name="weekly_holidays[]" value="<?php echo $day; ?>" id="day_<?php echo $day; ?>" <?php echo in_array($day, $selected_holidays, true) ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="day_<?php echo $day; ?>"><?php echo $day; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-start">
                        <label class="col-sm-3 col-form-label fw-bold text-end pt-2">Map IFrame</label>
                        <div class="col-sm-8">
                            <textarea name="map_iframe" class="form-control" rows="3" placeholder='<iframe src="https://www.google.com/maps/embed?..."></iframe>'><?php echo bv($form_defaults['map_iframe']); ?></textarea>
                            <small class="text-muted">Google Maps থেকে "Embed a map" এর iframe কোড এখানে পেস্ট করুন।</small>
                        </div>
                    </div>
                    <div class="row mb-4 align-items-center">
                        <label class="col-sm-3 col-form-label fw-bold text-end">Status <span class="text-danger">*</span></label>
                        <div class="col-sm-8">
                            <select name="status" class="form-select">
                                <option value="Active" <?php echo $form_defaults['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $form_defaults['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-8 offset-sm-3">
                            <button type="submit" name="save_branch" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;"><?php echo $edit_branch ? 'Update Branch' : 'Add Branch'; ?></button>
                            <a href="index.php?page=branch" class="btn btn-secondary px-4 fw-bold ms-2">বাতিল</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- ===================== Branch List ===================== -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-building"></i> Branch Settings</h4>
            <?php if ($is_super_admin): ?>
                <a href="index.php?page=branch&mode=add" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;">
                    <i class="fa-solid fa-plus"></i> Add Branch
                </a>
            <?php endif; ?>
        </div>

        <?php if ($branch_msg): ?><div class="alert alert-success py-2"><?php echo htmlspecialchars($branch_msg); ?></div><?php endif; ?>
        <?php if ($branch_err): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($branch_err); ?></div><?php endif; ?>

        <?php if (!$is_super_admin): ?>
            <div class="alert alert-info py-2 small mb-3">
                <i class="fa-solid fa-circle-info"></i> এই মুহূর্তে তুমি শুধু ব্রাঞ্চের তথ্য দেখতে ও Edit করতে পারবে — নতুন ব্রাঞ্চ যোগ বা ডিলিট শুধু Super Admin করতে পারে।
            </div>
        <?php endif; ?>

        <h6 class="fw-bold mb-3">Branch List</h6>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Short Name</th>
                        <th>Branch Name</th>
                        <th>Address</th>
                        <th>City</th>
                        <th>Zip</th>
                        <th>Phone</th>
                        <th>Fax</th>
                        <th>Email</th>
                        <th>Weekly Holiday</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($branches): foreach ($branches as $b): $csrf = htmlspecialchars(csrf_token()); ?>
                        <tr>
                            <td><strong><?php echo bv($b['short_name']); ?></strong></td>
                            <td><?php echo bv($b['branch_name']); ?></td>
                            <td class="small"><?php echo nl2br(bv($b['address'])); ?></td>
                            <td class="small"><?php echo bv($b['city']); ?></td>
                            <td class="small"><?php echo bv($b['zip_code']); ?></td>
                            <td class="small"><?php echo bv($b['phone']); ?></td>
                            <td class="small"><?php echo bv($b['fax']); ?></td>
                            <td class="small"><?php echo bv($b['email']); ?></td>
                            <td class="small"><?php echo bv(str_replace(',', ', ', $b['weekly_holidays'])); ?></td>
                            <td><?php echo $b['status'] === 'Active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-end text-nowrap">
                                <a href="index.php?page=branch&edit=<?php echo (int) $b['id']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                <?php if ($is_super_admin): ?>
                                    <form method="POST" action="index.php?page=branch" class="d-inline" onsubmit="return confirm('মুছে ফেলবেন?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                        <button type="submit" name="delete_branch" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="11" class="text-center text-muted py-3">No branches found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>
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
