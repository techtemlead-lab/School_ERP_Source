<?php
// teacher/hr_update_info.php - Human Resource > HR Module > Update Information
// নিরাপত্তার জন্য টিচার শুধু নিজের সীমিত কিছু ফিল্ড (contact/address/photo) আপডেট করতে
// পারবে — Employee ID, Name, Designation, Joining Date, National ID ইত্যাদি Admin-controlled।

$stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
$stmt->execute([$teacher['id']]);
$t = $stmt->fetch();

$ui_err = '';
$ui_msg = '';

function ui_handle_upload(string $field): array
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি আপলোডে সমস্যা হয়েছে।'];
    }
    if ($file['size'] > 300 * 1024) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির সাইজ সর্বোচ্চ 300 KB হতে হবে।'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed_mimes[$ext])) {
        return ['ok' => false, 'path' => null, 'error' => 'শুধু jpg/png ছবি আপলোড করা যাবে।'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== $allowed_mimes[$ext]) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবির প্রকৃত কনটেন্ট এক্সটেনশনের সাথে মিলছে না।'];
    }
    $upload_dir = tenant_upload_abs(__DIR__ . '/..', 'employees');
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
    $random_name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $random_name)) {
        return ['ok' => false, 'path' => null, 'error' => 'ছবি সেভ করা যায়নি।'];
    }
    return ['ok' => true, 'path' => tenant_upload_rel('employees') . '/' . $random_name, 'error' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $ui_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $mobile      = trim($_POST['mobile'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $blood_group = trim($_POST['blood_group'] ?? '');
        $marital     = trim($_POST['marital_status'] ?? '');
        $facebook    = trim($_POST['facebook_link'] ?? '');
        $present_add = trim($_POST['present_address'] ?? '');
        $permanent_add = trim($_POST['permanent_address'] ?? '');

        if ($mobile === '') {
            $ui_err = 'Mobile নাম্বার দেওয়া আবশ্যক।';
        } else {
            $photo = ui_handle_upload('photo');
            if (!$photo['ok']) {
                $ui_err = $photo['error'];
            } else {
                $cols = [
                    'mobile' => $mobile, 'email' => $email, 'blood_group' => $blood_group,
                    'marital_status' => $marital, 'facebook_link' => $facebook,
                    'present_address' => $present_add, 'permanent_address' => $permanent_add,
                ];
                if ($photo['path']) { $cols['photo_path'] = $photo['path']; }

                $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($cols)));
                $cols['id'] = $t['id'];
                $pdo->prepare("UPDATE employees SET $set WHERE id = :id")->execute($cols);

                $ui_msg = 'তথ্য সফলভাবে আপডেট হয়েছে!';
                $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
                $stmt->execute([$t['id']]);
                $t = $stmt->fetch();
                $_SESSION['teacher_photo'] = $t['photo_path'];
                $_SESSION['teacher_name']  = $t['name'];
            }
        }
    }
}
?>

<div class="card-custom">
    <?php if ($ui_msg): ?><div class="alert alert-success py-2"><?php echo tp_e($ui_msg); ?></div><?php endif; ?>
    <?php if ($ui_err): ?><div class="alert alert-danger py-2"><?php echo tp_e($ui_err); ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <h6 class="fw-bold text-primary mb-3">Admin-Controlled Information (শুধু দেখা যাবে)</h6>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Employee ID</label>
                <input type="text" class="form-control" value="<?php echo tp_e($t['employee_id']); ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Name</label>
                <input type="text" class="form-control" value="<?php echo tp_e($t['name']); ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Joining Date</label>
                <input type="text" class="form-control" value="<?php echo $t['joining_date'] ? date('d M Y', strtotime($t['joining_date'])) : '—'; ?>" disabled>
            </div>
        </div>
        <p class="text-muted" style="font-size:12px;margin-top:-14px;">উপরের তথ্যগুলো পরিবর্তনের প্রয়োজন হলে স্কুল অফিসে যোগাযোগ করুন।</p>

        <h6 class="fw-bold text-primary mb-3 mt-4">নিজের তথ্য আপডেট করুন</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Mobile <span class="text-danger">*</span></label>
                <input type="tel" name="mobile" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo tp_e($t['mobile']); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo tp_e($t['email']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Blood Group</label>
                <select name="blood_group" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $t['blood_group'] === $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold" style="font-size:13px;">Marital Status</label>
                <select name="marital_status" class="form-select">
                    <option value="">Select</option>
                    <?php foreach (['Single','Married','Divorced','Widowed'] as $ms): ?>
                        <option value="<?php echo $ms; ?>" <?php echo $t['marital_status'] === $ms ? 'selected' : ''; ?>><?php echo $ms; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-bold" style="font-size:13px;">Facebook Link</label>
                <input type="text" name="facebook_link" class="form-control" value="<?php echo tp_e($t['facebook_link']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold" style="font-size:13px;">Present Address</label>
                <textarea name="present_address" class="form-control" rows="3"><?php echo tp_e($t['present_address']); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold" style="font-size:13px;">Permanent Address</label>
                <textarea name="permanent_address" class="form-control" rows="3"><?php echo tp_e($t['permanent_address']); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold" style="font-size:13px;">Profile Photo (max 300KB, jpg/png)</label>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                <?php if (!empty($t['photo_path'])): ?>
                    <div class="mt-2"><img src="../<?php echo tp_e($t['photo_path']); ?>" style="height:70px;border-radius:8px;"></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" name="update_info" class="btn btn-primary fw-bold" style="background:#0a2540;border-color:#0a2540;">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
            <a href="index.php?page=hr_view_info" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
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
