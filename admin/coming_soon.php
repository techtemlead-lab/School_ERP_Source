<?php
// admin/coming_soon.php - যে ফিচারগুলো এখনো তৈরি করা হয়নি, সেগুলোর জন্য একটা
// বন্ধুত্বপূর্ণ placeholder পেজ (যাতে ক্লিক করলে কিছু না দেখিয়ে ভাঙা না লাগে)

$labels = [
    'student_admission'          => 'Student Admission',
    'print_admission_form'       => 'Print Admission Form',
    'archive_student_list'       => 'Archive Student List',
    'current_student_search'     => 'Current Student Search',
    'archive_student_search'     => 'Archive Student Search',
    'student_switch'             => 'Student Switch Process',
    'print_student_id_card'      => 'Print Students ID Card',
    'student_biometric_export'   => 'Student Biometric Export',
    'student_migration'          => 'Student Migration Process',
    'print_student_testimonial'  => 'Print Student Testimonial',
    'print_transfer_certificate' => 'Print Student Transfer Certificate',
    'print_certificate'          => 'Print Certificate (প্রত্যয়নপত্র)',
];

$title = $labels[$page] ?? ucfirst(str_replace('_', ' ', $page));
?>

<div class="card-custom text-center py-5">
    <div class="mb-3">
        <i class="fa-solid fa-hammer" style="font-size: 48px; color: #f59e0b;"></i>
    </div>
    <h4 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($title); ?></h4>
    <p class="text-muted mb-0">এই ফিচারটি এখনো তৈরি করা হয়নি — শীঘ্রই যুক্ত করা হবে।</p>
    <p class="text-muted small">This feature is coming soon.</p>
</div>
