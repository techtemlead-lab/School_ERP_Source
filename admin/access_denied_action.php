<?php
// admin/access_denied_action.php
// পেজে ঢোকার (View) অনুমতি আছে, কিন্তু এই কাজটার (Add/Edit/Delete) অনুমতি নেই — index.php রাউটার
// perm_action_allowed() false হলে এই ফাইল include করে। কিছুই সেভ/মুছা/বদলানো হয়নি।
$pda_kind = $perm_denied_kind ?? '';
?>
<div class="card-custom">
    <div class="alert alert-warning mb-3">
        <i class="fa-solid fa-hand me-2"></i>
        এই পেজে <b><?php echo htmlspecialchars(perm_action_label($pda_kind), ENT_QUOTES, 'UTF-8'); ?></b> করার অনুমতি আপনার নেই। কিছুই পরিবর্তন হয়নি।
        প্রয়োজন হলে Administrator-এর সাথে যোগাযোগ করুন।
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=<?php echo urlencode((string) ($page ?? 'dashboard')); ?>"><i class="fa-solid fa-arrow-left me-1"></i> পেজে ফিরে যান</a>
</div>
