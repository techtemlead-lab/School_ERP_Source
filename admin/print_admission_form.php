<?php
// admin/print_admission_form.php - ভর্তি ফরম প্রিন্ট
// - id প্যারামিটার ছাড়া ওপেন হলে (Sidebar > Admission Module > Print Admission Form): ফাঁকা ফরম টেমপ্লেট
// - id প্যারামিটার সহ ওপেন হলে (Student List / Student Admission > Print বাটন): ওই শিক্ষার্থীর তথ্য ভরা ফরম
$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) {
    $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'email' => '', 'logo_path' => null];
}

function pv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// institute address ফিল্ড আলাদা নেই institute_settings-এ, তাই branches টেবিলের প্রথম active branch থেকে ঠিকানা টানার চেষ্টা করা হচ্ছে
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}

// ------- Student লোড করা (id দেওয়া থাকলে) -------
$pf_id = (int) ($_GET['id'] ?? 0);
$stu = null;
if ($pf_id > 0) {
    $stmt = $pdo->prepare(
        "SELECT s.*, ses.session_name, sh.shift_name, c.class_name, c.medium_id,
                m.medium_name, sec.section_name, g.group_name, pc.class_name AS prev_class_name
         FROM students s
         LEFT JOIN academic_sessions ses ON ses.id = s.session_id
         LEFT JOIN academic_shifts sh ON sh.id = s.shift_id
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_mediums m ON m.id = c.medium_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         LEFT JOIN academic_groups g ON g.id = s.group_id
         LEFT JOIN academic_classes pc ON pc.id = s.prev_class_id
         WHERE s.id = ?"
    );
    $stmt->execute([$pf_id]);
    $stu = $stmt->fetch();
}

if ($stu) {
    $session_name = $stu['session_name'] ?? '';
} else {
    $current_session = $pdo->query("SELECT session_name FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
    $session_name = $current_session['session_name'] ?? date('Y');
}

// ফরমের একটা লাইন প্রিন্ট করার হেল্পার — student ডেটা থাকলে ভরা মান দেখাবে, না থাকলে ফাঁকা ড্যাশড লাইন
function pf_field(string $label_en, string $label_bn, ?string $value = null): void {
    echo '<div class="pf-field"><span class="pf-label" data-en="' . pv($label_en) . '" data-bn="' . pv($label_bn) . '"></span>'
       . '<span class="pf-blank">' . pv($value) . '</span></div>';
}

// Yes/No বা Male/Female টাইপ চেকবক্স গ্রুপ প্রিন্ট করার হেল্পার — student ডেটা থাকলে সঠিক অপশনটা টিক দেখাবে
function pf_check(string $label_en, string $label_bn, array $options, ?string $selected = null): void {
    echo '<div class="pf-field"><span class="pf-label" data-en="' . pv($label_en) . '" data-bn="' . pv($label_bn) . '"></span><div class="pf-checkbox-group">';
    foreach ($options as $opt_en => $opt_bn) {
        $checked = ($selected !== null && strcasecmp($selected, $opt_en) === 0) ? 'checked' : '';
        echo '<label><input type="checkbox" disabled ' . $checked . '> <span data-en="' . pv($opt_en) . '" data-bn="' . pv($opt_bn) . '"></span></label>';
    }
    echo '</div></div>';
}
?>

<style>
    @media print {
        .no-print { display: none !important; }
        /* এই সিস্টেমের মূল অ্যাপ শেল (sidebar/topbar/breadcrumb) প্রিন্টে সম্পূর্ণ হাইড,
           যাতে শুধু ভর্তি ফরমটাই প্রিন্ট হয় */
        .sidebar, .sticky-top-area { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
        body { background: #fff !important; }
        .print-form-wrap { box-shadow: none !important; border: none !important; }
    }
    .print-form-wrap { background: #fff; max-width: 950px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; }
    .pf-section-title { background: #f1f1f1; padding: 6px 12px; font-weight: bold; font-size: 14px; border: 1px solid #ddd; margin-top: 18px; margin-bottom: 12px; }
    .pf-row { display: flex; flex-wrap: wrap; margin-bottom: 10px; font-size: 13.5px; }
    .pf-field { width: 50%; display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; padding-right: 15px; }
    .pf-label { white-space: nowrap; color: #333; }
    .pf-blank { flex: 1; border-bottom: 1px dashed #94a3b8; min-width: 60px; font-weight: 600; color: #0a2540; padding-bottom: 1px; }
    .pf-checkbox-group { display: flex; gap: 15px; flex-wrap: wrap; }
    .pf-checkbox-group label { display: flex; align-items: center; gap: 4px; white-space: nowrap; font-size: 13.5px; }
    .pf-signature { border-top: 1px solid #333; width: 200px; text-align: center; padding-top: 4px; font-size: 12px; }
</style>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <select id="pfLang" class="form-select" style="max-width: 220px;" onchange="switchPfLang()">
        <option value="">Select Language</option>
        <option value="en" selected>English</option>
        <option value="bn">Bangla</option>
    </select>
    <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="print-form-wrap">
    <div class="d-flex justify-content-between align-items-start">
        <div style="width:90px;">
            <?php if (!empty($inst['logo_path'])): ?>
                <img src="../<?php echo pv($inst['logo_path']); ?>" style="max-width:90px;max-height:90px;">
            <?php endif; ?>
        </div>
        <div class="text-center flex-grow-1">
            <h4 class="fw-bold text-primary mb-1"><?php echo pv($inst['institute_name']); ?></h4>
            <p class="mb-0 small text-muted">
                <?php if ($branch): ?>(<?php echo pv($branch['address']); ?>, <?php echo pv($branch['city']); ?>-<?php echo pv($branch['zip_code']); ?>)<br><?php endif; ?>
                <?php if (!empty($inst['phone_number'])): ?>Phone: <?php echo pv($inst['phone_number']); ?><br><?php endif; ?>
                <?php if (!empty($inst['facebook_page'])): ?><span class="text-primary"><?php echo pv($inst['facebook_page']); ?></span><br><?php endif; ?>
                <?php if (!empty($inst['phone_number'])): ?>Mobile: <?php echo pv($inst['phone_number']); ?><?php endif; ?>
            </p>
        </div>
        <?php if ($stu && !empty($stu['photo_path'])): ?>
            <img src="../<?php echo pv($stu['photo_path']); ?>" style="width:100px;height:100px;object-fit:cover;border:1px solid #999;">
        <?php else: ?>
            <div style="width:100px;height:100px;border:1px solid #999;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;">Photo</div>
        <?php endif; ?>
    </div>

    <div class="text-center mt-2 mb-3">
        <span class="border px-3 py-1 d-inline-block" data-en="Admission Form for Session" data-bn="ভর্তি ফরম, শিক্ষাবর্ষ"> - <?php echo pv($session_name); ?></span>
    </div>

    <div class="pf-section-title" data-en="Academic Information (For office use only)" data-bn="একাডেমিক তথ্য (শুধু অফিসের ব্যবহারের জন্য)"></div>
    <div class="pf-row">
        <?php
        pf_field('Session', 'সেশন', $stu['session_name'] ?? null);
        pf_field('Medium', 'মাধ্যম', $stu['medium_name'] ?? null);
        pf_field('Shift', 'শিফট', $stu['shift_name'] ?? null);
        pf_field('Class', 'শ্রেণি', $stu['class_name'] ?? null);
        pf_field('Group', 'গ্রুপ', $stu['group_name'] ?? null);
        pf_field('Section', 'শাখা', $stu['section_name'] ?? null);
        pf_field('Student ID', 'স্টুডেন্ট আইডি', $stu['student_id'] ?? null);
        pf_field('Roll', 'রোল', $stu['roll_no'] ?? null);
        pf_field('Registration No.', 'রেজিস্ট্রেশন নং', null); // এই ফিল্ড এই সিস্টেমে ট্র্যাক করা হয় না, অফিস হাতে লিখবে
        pf_check('HSP Stipend Holder', 'উপবৃত্তি প্রাপ্ত', ['Yes' => 'হ্যাঁ', 'No' => 'না'], null); // এই সিস্টেমে ট্র্যাক করা হয় না
        ?>
    </div>

    <div class="pf-section-title" data-en="Personal Information" data-bn="ব্যক্তিগত তথ্য"></div>
    <div class="pf-row">
        <?php
        pf_field('Name (English)', 'নাম (ইংরেজি)', $stu['name_en'] ?? null);
        pf_field('Email', 'ইমেইল', $stu['email'] ?? null);
        pf_field('Name (Bangla)', 'নাম (বাংলা)', $stu['name_bn'] ?? null);
        pf_field('Mobile', 'মোবাইল', $stu['mobile'] ?? null);
        pf_field('Date of Birth', 'জন্ম তারিখ', $stu['dob'] ?? null);
        pf_field('Blood Group', 'রক্তের গ্রুপ', $stu['blood_group'] ?? null);
        pf_field('Birth Registration No.', 'জন্ম নিবন্ধন নং', $stu['birth_reg_no'] ?? null);
        pf_field('Religion', 'ধর্ম', $stu['religion'] ?? null);
        pf_check('Gender', 'লিঙ্গ', ['Male' => 'পুরুষ', 'Female' => 'মহিলা'], $stu['gender'] ?? null);
        pf_field('Nationality', 'জাতীয়তা', $stu['nationality'] ?? null);
        pf_check('Orphan', 'এতিম', ['Yes' => 'হ্যাঁ', 'No' => 'না'], $stu['orphan'] ?? null);
        pf_field('Disability Status', 'প্রতিবন্ধিতা', $stu['disability_status'] ?? null);
        pf_check('Working Student', 'কর্মজীবী শিক্ষার্থী', ['Yes' => 'হ্যাঁ', 'No' => 'না'], $stu['working_student'] ?? null);
        pf_field('Member of the Family', 'পরিবারের সদস্য সংখ্যা', null); // এই ফিল্ড এই সিস্টেমে ট্র্যাক করা হয় না
        ?>
    </div>

    <div class="pf-section-title" data-en="Previous School Information" data-bn="পূর্ববর্তী বিদ্যালয়ের তথ্য"></div>
    <div class="pf-row">
        <?php
        pf_field('Previous School Name', 'পূর্ববর্তী বিদ্যালয়ের নাম', $stu['prev_school_name'] ?? null);
        pf_field('Previous Class', 'পূর্ববর্তী শ্রেণি', $stu['prev_class_name'] ?? null);
        pf_field('Previous Section', 'পূর্ববর্তী শাখা', $stu['prev_section'] ?? null);
        pf_field('Previous Result', 'পূর্ববর্তী ফলাফল', $stu['prev_result'] ?? null);
        pf_field('Previous Roll', 'পূর্ববর্তী রোল', $stu['prev_roll'] ?? null);
        ?>
    </div>

    <div class="pf-section-title" data-en="Parents Information" data-bn="অভিভাবকের তথ্য"></div>
    <div class="pf-row">
        <?php
        pf_field("Father's Name", 'পিতার নাম', $stu['father_name'] ?? null);
        pf_field("Mother's Name", 'মাতার নাম', $stu['mother_name'] ?? null);
        pf_field("Father's NID", 'পিতার এনআইডি', $stu['father_nid'] ?? null);
        pf_field("Mother's NID", 'মাতার এনআইডি', $stu['mother_nid'] ?? null);
        pf_field("Father's Profession", 'পিতার পেশা', $stu['father_profession'] ?? null);
        pf_field("Mother's Profession", 'মাতার পেশা', $stu['mother_profession'] ?? null);
        pf_field("Father's Phone", 'পিতার ফোন', $stu['father_phone'] ?? null);
        pf_field("Mother's Phone", 'মাতার ফোন', $stu['mother_phone'] ?? null);
        pf_field('Yearly Income', 'বাৎসরিক আয়', $stu['yearly_income'] ?? null);
        pf_check('Land Less', 'ভূমিহীন', ['Yes' => 'হ্যাঁ', 'No' => 'না'], $stu['landless'] ?? null);
        ?>
    </div>

    <div class="pf-section-title" data-en="Guardian's Information" data-bn="অভিভাবক/গার্ডিয়ানের তথ্য"></div>
    <div class="pf-row">
        <?php
        pf_field("Guardian's Name", 'গার্ডিয়ানের নাম', $stu['guardian_name'] ?? null);
        pf_field("Guardian's Phone", 'গার্ডিয়ানের ফোন', $stu['guardian_phone'] ?? null);
        pf_field('Relation With Student', 'শিক্ষার্থীর সাথে সম্পর্ক', $stu['guardian_relation'] ?? null);
        pf_field("Guardian's Profession", 'গার্ডিয়ানের পেশা', $stu['guardian_profession'] ?? null);
        ?>
    </div>

    <div class="pf-section-title" data-en="Contact Information" data-bn="যোগাযোগের তথ্য"></div>
    <div class="pf-row">
        <?php
        pf_field('Present Address', 'বর্তমান ঠিকানা', $stu['present_address'] ?? null);
        pf_field('Permanent Address', 'স্থায়ী ঠিকানা', $stu['permanent_address'] ?? null);
        ?>
    </div>

    <div class="pf-section-title" data-en="Comment" data-bn="মন্তব্য"></div>
    <div style="border:1px solid #ddd;height:70px;margin-bottom:25px;padding:6px 10px;font-size:13px;"><?php echo pv($stu['comments'] ?? ''); ?></div>

    <div class="d-flex justify-content-between mt-5">
        <div class="pf-signature" data-en="Signature of Student" data-bn="শিক্ষার্থীর স্বাক্ষর"></div>
        <div class="pf-signature" data-en="Signature of Guardian" data-bn="অভিভাবকের স্বাক্ষর"></div>
        <div class="pf-signature" data-en="Signature of Teacher" data-bn="শিক্ষকের স্বাক্ষর"></div>
    </div>
</div>

<script>
function switchPfLang() {
    const lang = document.getElementById('pfLang').value || 'en';
    document.querySelectorAll('[data-en]').forEach(el => {
        el.textContent = el.dataset[lang] || el.dataset.en;
    });
}
switchPfLang();
</script>
