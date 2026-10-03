<?php
// student/_placeholder.php - যে মডিউলগুলোর এডমিন-সাইড ব্যাকএন্ড এখনো তৈরি হয়নি
// (Live Class, Home Work, Assignment, Lesson Plan, My Teacher, My Syllabus, Report Card)
// তার জন্য একটা বন্ধুত্বপূর্ণ placeholder — অ্যাডমিন প্যানেলে মডিউলটা চালু হলে এই পেজে
// আসল ডাটা দেখানো শুরু হবে।
$sp_placeholder_titles = [
    'live_class'   => ['icon' => 'fa-video',           'title' => 'My Live Class'],
    'home_work'    => ['icon' => 'fa-laptop',            'title' => 'My Home Work'],
    'assignment'   => ['icon' => 'fa-building-columns',  'title' => 'My Assignment'],
    'lesson_plan'  => ['icon' => 'fa-book',              'title' => 'My Lesson Plan'],
    'my_teacher'   => ['icon' => 'fa-chalkboard-user',   'title' => 'My Teacher'],
    'my_syllabus'  => ['icon' => 'fa-book-open',         'title' => 'My Syllabus'],
    'report_card'  => ['icon' => 'fa-file-lines',        'title' => 'My Report Card'],
];
$sp_ph = $sp_placeholder_titles[$page] ?? ['icon' => 'fa-circle-info', 'title' => 'Module'];
?>
<div class="card-custom text-center py-5">
    <div class="mb-3">
        <i class="fa-solid <?php echo $sp_ph['icon']; ?>" style="font-size: 48px; color: #14b8a6;"></i>
    </div>
    <h4 class="fw-bold text-dark mb-2"><?php echo sp_e($sp_ph['title']); ?></h4>
    <p class="text-muted mb-0">এই মুহূর্তে এখানে দেখানোর মতো কোনো তথ্য নেই।</p>
    <p class="text-muted small">School Admin Panel থেকে এই মডিউলের তথ্য যোগ করা হলে এখানে দেখা যাবে।</p>
</div>
