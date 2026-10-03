<?php
// admin/access_denied.php
// Super Admin > User Management > User Permission থেকে এই ইউজারকে এই পেজে
// এক্সেস দেওয়া হয়নি বলে দেখানো হচ্ছে। এটা কোনো আলাদা page_map এন্ট্রি না —
// index.php এর রাউটার নিজে থেকেই perm_page_allowed() false হলে এই ফাইল
// include করে (দেখুন index.php এর "$target_file = 'access_denied.php';")।
?>
<div class="card-custom">
    <div class="alert alert-danger mb-0">
        <i class="fa-solid fa-lock me-2"></i>
        এই পেজটি অ্যাক্সেস করার অনুমতি আপনার নেই। প্রয়োজন হলে Administrator-এর সাথে যোগাযোগ করুন।
    </div>
</div>
