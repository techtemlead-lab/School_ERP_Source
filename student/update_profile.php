<?php
// student/update_profile.php - শুধু Contact সংক্রান্ত তথ্য নিজে আপডেট করতে পারবে
// (Academic Info, Name, Guardian Info ইত্যাদি নিরাপত্তার জন্য এখান থেকে বদলানো যাবে না —
// সেগুলো বদলাতে হলে School Office-এ যোগাযোগ করতে হবে)
$up_msg = '';
$up_err = '';

if (isset($_POST['update_profile'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $up_err = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } else {
        $mobile  = trim($_POST['mobile'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $present = trim($_POST['present_address'] ?? '');

        $pdo->prepare("UPDATE students SET mobile = ?, email = ?, present_address = ? WHERE id = ?")
            ->execute([$mobile, $email, $present, $student['id']]);

        $student['mobile'] = $mobile;
        $student['email'] = $email;
        $student['present_address'] = $present;
        $up_msg = 'প্রোফাইল সফলভাবে আপডেট হয়েছে!';
    }
}
?>
<div class="card-custom">
    <h6 class="fw-bold mb-3" style="color:#0f766e;"><i class="fa-solid fa-user-pen me-2"></i>Update Profile</h6>
    <p class="text-muted" style="font-size:12.5px;">নিরাপত্তার কারণে শুধু Mobile, Email ও Present Address এখান থেকে বদলানো যাবে। অন্য তথ্য বদলাতে স্কুল অফিসে যোগাযোগ করুন।</p>

    <?php if ($up_msg): ?><div class="alert alert-success py-2"><?php echo sp_e($up_msg); ?></div><?php endif; ?>
    <?php if ($up_err): ?><div class="alert alert-danger py-2"><?php echo sp_e($up_err); ?></div><?php endif; ?>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Mobile Number</label>
                <input type="tel" name="mobile" class="form-control phone-11" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="১১ ডিজিটের মোবাইল নম্বর দিন" value="<?php echo sp_e($student['mobile']); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo sp_e($student['email']); ?>">
            </div>
            <div class="col-md-12 mb-3">
                <label class="form-label fw-bold" style="font-size:13px;">Present Address</label>
                <textarea name="present_address" class="form-control" rows="3"><?php echo sp_e($student['present_address']); ?></textarea>
            </div>
        </div>
        <button type="submit" name="update_profile" class="btn text-white fw-bold" style="background:#0f766e;">Save Changes</button>
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
