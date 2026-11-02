<?php
// admin/result_settings.php - Result Module > Exam Settings > Master Setting (স্ক্রিনশট অনুযায়ী)
$rs_err = $_SESSION['rs_err'] ?? '';
$rs_msg = $_SESSION['rs_msg'] ?? '';
unset($_SESSION['rs_err'], $_SESSION['rs_msg']);

// শতকরা ওয়েট মডেল ও গড় মডেলের হেল্পার — ওয়েট/কাঠামো এখন Exam Terms পেজে (Main/Sub টার্ম) দেওয়া হয়
require_once __DIR__ . '/../config/report_card_helpers.php';

// exam_terms টেবিল এখনো তৈরি না হয়ে থাকতে পারে (Result Module > Exam Settings > Exam Terms
// পেজ কখনো ভিজিট না করা হলে) — এই পেজ নিচে exam_terms থেকে SELECT করে (Default Term বের
// করতে), তাই এখানেও নিরাপদে auto-create করা হচ্ছে, না হলে সরাসরি এই পেজে এলে Fatal Error হয়
// (result_exam_terms.php এর সাথে একই স্ট্রাকচার)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_terms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        rank INT NOT NULL DEFAULT 1,
        is_final_term TINYINT(1) NOT NULL DEFAULT 0,
        is_aggregated_result TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_routine TINYINT(1) NOT NULL DEFAULT 0,
        admit_card_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        exam_routine_with_instruction TINYINT(1) NOT NULL DEFAULT 0,
        website_result_publish TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// ---------------------------------------------------------------
// টেবিলগুলো না থাকলে অটো তৈরি
// ---------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_result_settings (
        id INT PRIMARY KEY DEFAULT 1,
        show_medium_name TINYINT(1) NOT NULL DEFAULT 1,
        show_student_roll_no TINYINT(1) NOT NULL DEFAULT 1,
        show_student_picture_qr TINYINT(1) NOT NULL DEFAULT 0,
        add_watermark_background TINYINT(1) NOT NULL DEFAULT 1,
        show_banner_instead_logo TINYINT(1) NOT NULL DEFAULT 0,
        pass_fail_summary_merit_list TINYINT(1) NOT NULL DEFAULT 1,
        merit_list_with_subjects TINYINT(1) NOT NULL DEFAULT 1,
        show_final_grade_letter_point TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_letter TINYINT(1) NOT NULL DEFAULT 1,
        show_subject_grade_point TINYINT(1) NOT NULL DEFAULT 1,
        show_result_remarks TINYINT(1) NOT NULL DEFAULT 1,
        show_working_days_attend_absent TINYINT(1) NOT NULL DEFAULT 1,
        show_absent_count TINYINT(1) NOT NULL DEFAULT 1,
        write_manual_attendance_count TINYINT(1) NOT NULL DEFAULT 1,
        show_discipline_cleanliness TINYINT(1) NOT NULL DEFAULT 1,
        show_interpretation TINYINT(1) NOT NULL DEFAULT 1,
        show_total_subject_fail_count TINYINT(1) NOT NULL DEFAULT 0,
        show_part_marks_calculation TINYINT(1) NOT NULL DEFAULT 1,
        calculate_merit_position_by_marks TINYINT(1) NOT NULL DEFAULT 0,
        enable_merit_tiebreaker TINYINT(1) NOT NULL DEFAULT 1,
        aggregate_result_type VARCHAR(50) NOT NULL DEFAULT 'Terms Wise Percentage',
        report_card_template VARCHAR(30) NOT NULL DEFAULT 'Govt. / MPO',
        left_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Teacher',
        middle_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Coordinator',
        right_signature VARCHAR(100) NOT NULL DEFAULT 'Signature of Headmaster',
        body_font_size INT NOT NULL DEFAULT 12,
        body_font_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        institute_name_color VARCHAR(10) NOT NULL DEFAULT '#000080',
        table_header_color VARCHAR(10) NOT NULL DEFAULT '#333333',
        fail_letter_grade_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        body_font_style VARCHAR(20) NOT NULL DEFAULT 'Normal',
        gpa_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        remarks_progress_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        table_footer_color VARCHAR(10) NOT NULL DEFAULT '#00B1E1',
        fail_part_marks_color VARCHAR(10) NOT NULL DEFAULT '#ff0000',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec("INSERT IGNORE INTO exam_result_settings (id) VALUES (1)");

// পুরনো ইনস্টলেশনে (নতুন কলাম যোগ হওয়ার আগে তৈরি হলে) কলাম না থাকলে নিরাপদে যোগ করা হয়
$ers_cols = $pdo->query("SHOW COLUMNS FROM exam_result_settings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('show_absent_count', $ers_cols, true)) {
    $pdo->exec("ALTER TABLE exam_result_settings ADD COLUMN show_absent_count TINYINT(1) NOT NULL DEFAULT 1 AFTER show_working_days_attend_absent");
}
if (!in_array('enable_merit_tiebreaker', $ers_cols, true)) {
    $pdo->exec("ALTER TABLE exam_result_settings ADD COLUMN enable_merit_tiebreaker TINYINT(1) NOT NULL DEFAULT 1 AFTER calculate_merit_position_by_marks");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS exam_grade_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        grade_letter VARCHAR(10) NOT NULL,
        gpa DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        mark_from DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        mark_to DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        remarks VARCHAR(100) NULL,
        KEY idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// টগল করা যায় এমন সব ফিল্ড (checkbox পাঠানো না হলে 0)
$toggle_fields = [
    'show_medium_name', 'show_student_roll_no', 'show_student_picture_qr', 'add_watermark_background',
    'show_banner_instead_logo', 'pass_fail_summary_merit_list', 'merit_list_with_subjects',
    'show_final_grade_letter_point', 'show_subject_grade_letter', 'show_subject_grade_point',
    'show_result_remarks', 'show_working_days_attend_absent', 'show_absent_count',
    'show_discipline_cleanliness', 'show_interpretation', 'show_total_subject_fail_count',
    'show_part_marks_calculation', 'calculate_merit_position_by_marks', 'enable_merit_tiebreaker',
];
$text_fields = [
    'left_signature', 'middle_signature', 'right_signature',
    'body_font_color', 'institute_name_color', 'table_header_color', 'fail_letter_grade_color',
    'gpa_color', 'remarks_progress_color', 'table_footer_color', 'fail_part_marks_color',
];
$aggregate_types = ['Terms Wise Percentage', 'Terms Wise Average', 'Terms & Subjects Wise Average', 'Terms, Subjects & Part Wise Average'];
// নতুন শতকরা ওয়েট মডেল ও গড় মডেল (গড়-ভিত্তিক আগের ৪টা পদ্ধতি উপরে আগের মতোই আছে)
$pct_models = rc_pct_models();
$pct_labels = [
    'Percentage Model 1' => 'মডেল ১ — টার্ম ওয়েট (১ম টার্ম + ২য় টার্ম + ফাইনাল টার্ম)',
    'Percentage Model 2' => 'মডেল ২ — মাসিক + টার্ম মিলিয়ে একটা রেজাল্ট, তারপর টার্মের ওয়েট',
    'Percentage Model 3' => 'মডেল ৩ — প্রতি টার্ম আলাদা রেজাল্ট (মাসিক + টার্ম), অন্য টার্মের সাথে যোগ হয় না',
    'Percentage Model 4' => 'মডেল ৪ — সব পরীক্ষার (সাময়িক ও মাসিক) আলাদা শতকরা মিলিয়ে ফাইনাল ফলাফল',
];
$avg_labels = [
    'Average Model 1' => 'গড় মডেল — মাসিক + টার্ম নম্বরের গড় করে গ্রুপের রেজাল্ট, তারপর গ্রুপগুলোর গড় = ফাইনাল',
];
// মডেল সিলেক্ট করলে আগে দেখানোর ব্যাখ্যা: [কীভাবে কাজ করে (লাইন...), সূত্র, উদাহরণ]
// ওয়েট বসে Exam Terms পেজে — টার্ম বানানোর সময় Main/Sub ও ওয়েট ফিল্ডে
$pct_explain = [
    'Percentage Model 1' => [
        'how' => [
            'প্রতিটা পরীক্ষা (Exam Term) নিজের শতকরা (%) বের করে।',
            'সব পরীক্ষা Main টার্ম। Exam Terms পেজে টার্ম বানানোর সময় প্রতিটার "মোটে ওয়েট (%)" বসান (যেমন ১ম টার্ম ১০%, ২য় টার্ম ১০%, ফাইনাল ৮০%) — সব ওয়েটের যোগফল ১০০ হতে হবে।',
            'ওয়েটগুলো আপনার ইচ্ছামতো কাস্টম বসানো যাবে (১০/১০/৮০, ২০/২০/৬০ বা অন্য যেকোনো)।',
        ],
        'formula' => 'ফাইনাল % = (১ম টার্ম% × ওয়েট + ২য় টার্ম% × ওয়েট + ফাইনাল% × ওয়েট) ÷ ১০০',
        'example' => 'ওয়েট ১০/১০/৮০; ১ম টার্ম ৭০%, ২য় টার্ম ৬০%, ফাইনাল ৭৫% হলে → (৭০×১০ + ৬০×১০ + ৭৫×৮০) ÷ ১০০ = ৭ + ৬ + ৬০ = ৭৩%',
    ],
    'Percentage Model 2' => [
        'how' => [
            'Exam Terms পেজে Main টার্ম (১ম টার্ম, ২য় টার্ম, ফাইনাল) বানান এবং প্রতিটার "মোটে ওয়েট (%)" দিন (যেমন ১০/১০/৮০) — Main ওয়েটের যোগফল ১০০।',
            'মাসিক/ক্লাস টেস্ট বানানোর সময় Term Type = Sub, Parent = কোন Main টার্ম, আর "Main-এর ভেতরে ওয়েট (%)" (যেমন ১০%) দিন।',
            'Main টার্মের নিজের পরীক্ষার ওয়েট অটো = ১০০ − Sub-গুলোর ওয়েটের যোগফল (যেমন মাসিক ১০% হলে টার্ম পরীক্ষা ৯০%)। এভাবে প্রতিটা Main = মাসিক + টার্ম মিলিত একটা রেজাল্ট।',
            'ফাইনাল টার্মেও একইভাবে ৩য় মাসিক (Sub) + ফাইনাল পরীক্ষা থাকতে পারে। সব ওয়েট কাস্টম।',
        ],
        'formula' => 'Main-এর রেজাল্ট % = (Sub% × Sub ওয়েট + Main পরীক্ষা% × বাকি ওয়েট) ÷ ১০০ ; ফাইনাল % = Σ (Main রেজাল্ট % × Main-এর মোটে ওয়েট) ÷ ১০০',
        'example' => '১ম টার্ম: মাসিক ৮০, টার্ম ৭০ (১০/৯০) → ৭১% ; ২য় টার্ম: মাসিক ৮০, টার্ম ৬০ → ৬২% ; ফাইনাল: ৩য় মাসিক ৯০, ফাইনাল ৭৫ → ৭৬.৫% ; Main ওয়েট ১০/১০/৮০ হলে → ৭.১ + ৬.২ + ৬১.২ = ৭৪.৫%',
    ],
    'Percentage Model 3' => [
        'how' => [
            'প্রতিটা Main টার্ম (১ম টার্ম, ২য় টার্ম, ফাইনাল) নিজেই একটা আলাদা চূড়ান্ত রেজাল্ট — যেমন মাসিক ১০% + টার্ম ৯০%।',
            'মাসিক/ক্লাস টেস্ট বানানোর সময় Term Type = Sub, Parent = কোন Main টার্ম, আর "Main-এর ভেতরে ওয়েট (%)" দিন। Main-এর নিজের পরীক্ষার ওয়েট অটো = ১০০ − Sub-গুলোর যোগফল।',
            'এক টার্মের রেজাল্ট অন্য টার্মের সাথে যোগ হয় না; প্রতিটা টার্মেই ফলাফল শেষ। Main টার্মের "মোটে ওয়েট" লাগে না।',
            'Final Aggregated Result পেজে কোন টার্মের রেজাল্ট দেখবেন সেটা সিলেক্ট করে নিতে হবে।',
        ],
        'formula' => 'টার্মের রেজাল্ট % = (Sub% × Sub ওয়েট + Main পরীক্ষা% × বাকি ওয়েট) ÷ ১০০ — অন্য টার্ম যোগ হয় না',
        'example' => 'মাসিক ৮০%, টার্ম ৭০% (১০/৯০) হলে → (৮০×১০ + ৭০×৯০) ÷ ১০০ = ৮ + ৬৩ = ৭১% — এই টার্মের ফলাফল, সেখানেই শেষ',
    ],
    'Percentage Model 4' => [
        'how' => [
            'সব পরীক্ষা — সাময়িক (১ম, ২য়, ফাইনাল) ও মাসিক — Main টার্ম হিসেবে বানান; কোনো Sub নেই।',
            'প্রতিটা পরীক্ষার "মোটে ওয়েট (%)" সরাসরি বসান (যেমন সাময়িক ১০ + ১০ + ৬৫ = ৮৫, মাসিক ৫ + ৫ + ৫ = ১৫)। সবার ওয়েট মিলে ঠিক ১০০ হতে হবে।',
            'সব ওয়েট কাস্টমাইজ করা যাবে।',
        ],
        'formula' => 'ফাইনাল % = Σ (প্রতিটা পরীক্ষার % × তার ওয়েট) ÷ ১০০ (সাময়িক ও মাসিক সব মিলিয়ে)',
        'example' => 'সাময়িক ৭০/৬০/৭৫% (ওয়েট ১০/১০/৬৫), মাসিক ৮০/৯০/৭০% (ওয়েট ৫/৫/৫) → ৭ + ৬ + ৪৮.৭৫ + ৪ + ৪.৫ + ৩.৫ = ৭৩.৭৫%',
    ],
    'Average Model 1' => [
        'how' => [
            'Exam Terms পেজে Main টার্ম (১ম টার্ম, ২য় টার্ম, ফাইনাল) বানান — গড়ে যেগুলো ধরা হবে সেগুলোতে "Aggregated Result = YES" দিন।',
            'মাসিক/ক্লাস টেস্ট বানানোর সময় Term Type = Sub ও Parent = কোন Main টার্ম সিলেক্ট করুন। কোনো ওয়েট/শতকরা লাগে না।',
            'প্রতিটা Main টার্ম = একটা গ্রুপ: Main পরীক্ষা + তার Sub-গুলোর প্রাপ্ত নম্বরের সাধারণ গড় (সমান ভাগ) = ঐ গ্রুপের রেজাল্ট।',
            'তারপর সব গ্রুপের রেজাল্টের গড় = ফাইনাল রেজাল্ট। ফাইনাল % = গড় প্রাপ্ত নম্বর ÷ গড় পূর্ণমান × ১০০।',
            'সাবধান: মাসিকের পূর্ণমান (যেমন ২০) আর টার্মের পূর্ণমান (যেমন ১০০) আলাদা হলে নম্বর ও পূর্ণমান দুটোই গড় হয় — সমান পূর্ণমানের পরীক্ষা একসাথে রাখলে হিসাব সবচেয়ে পরিষ্কার।',
        ],
        'notes' => [
            'কোনো পরীক্ষায় অনুপস্থিত থাকলে বা মার্ক এন্ট্রি না থাকলে সেই পরীক্ষার নম্বর ০ ধরেই গড় হয়।',
            'পাস/ফেল ও গ্রেড ঠিক হয় ফাইনাল %-এর Grade Point সেটিং অনুযায়ী।',
        ],
        'formula' => 'গ্রুপের রেজাল্ট = (মাসিক নম্বর + টার্ম নম্বর) ÷ ২ ; ফাইনাল = সব গ্রুপের রেজাল্টের গড়',
        'example' => 'সব পরীক্ষা ১০০-এর মধ্যে। ১ম গ্রুপ: মাসিক ৮০, টার্ম ৭০ → ৭৫ ; ২য় গ্রুপ: ৮০, ৬০ → ৭০ ; ফাইনাল গ্রুপ: ৯০, ৭৫ → ৮২.৫ ; ফাইনাল = (৭৫ + ৭০ + ৮২.৫) ÷ ৩ = ৭৫.৮৩ (পূর্ণমান ১০০ হলে ৭৫.৮৩%)',
    ],
];
$pct_common_notes = [
    'কোনো পরীক্ষায় অনুপস্থিত থাকলে বা মার্ক এন্ট্রি না থাকলে সেই পরীক্ষা ০% ধরা হয় (ওয়েট অন্যদের মধ্যে ভাগ হয় না)।',
    'প্রতিটা পরীক্ষার % আসে ঐ পরীক্ষার প্রাপ্ত নম্বর ÷ পূর্ণমান × ১০০ থেকে।',
    'পাস/ফেল ও গ্রেড ঠিক হয় ফাইনাল %-এর Grade Point সেটিং অনুযায়ী।',
];
$report_templates = ['Govt. / MPO', 'School (Classic)'];
$font_styles = ['Normal', 'Bold', 'Italic', 'Bold Italic'];
$font_sizes = [10, 11, 12, 13, 14, 15, 16, 18, 20];

// -----------------------------------------------------------
// Merit Tie-Breaker ক্রাইটেরিয়া (AJAX/JSON) — আগে আলাদা "Merit Tie-Breaker" পেজ ছিল, এখন Master Settings-এর
// ভেতরেই। Session + Class অনুযায়ী ক্রাইটেরিয়া লিস্ট/যোগ/উপর-নিচ/ডিলিট; সেভ হয় সাথে সাথে (Update বাটন লাগে না)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tb_action'])) {
    require_once __DIR__ . '/../config/report_card_helpers.php';
    rc_tiebreaker_ensure_table($pdo);
    while (ob_get_level() > 0) { ob_end_clean(); } // admin/index.php আগেই HTML বাফার করে রাখে, সেটা ফেলে দিয়ে শুধু JSON
    header('Content-Type: application/json; charset=utf-8');

    $out = ['ok' => false, 'msg' => 'অনুরোধ সম্পূর্ণ করা যায়নি।'];
    $tb_session = (int) ($_POST['session_id'] ?? 0);
    $tb_class   = (int) ($_POST['class_id'] ?? 0);
    $tb_action  = $_POST['tb_action'];

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $out['msg'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
    } elseif ($tb_session <= 0 || $tb_class <= 0) {
        $out['msg'] = 'Session ও Class সিলেক্ট করুন।';
    } else {
        $out['ok'] = true; $out['msg'] = '';
        if ($tb_action === 'add') {
            $type = $_POST['criterion_type'] ?? '';
            $subject_id = (int) ($_POST['subject_id'] ?? 0);
            if (!in_array($type, ['subject', 'attendance', 'age'], true)) {
                $out = ['ok' => false, 'msg' => 'Criterion Type সিলেক্ট করুন।'];
            } elseif ($type === 'subject' && $subject_id <= 0) {
                $out = ['ok' => false, 'msg' => 'Subject Marks সিলেক্ট করলে একটা Subject-ও সিলেক্ট করতে হবে।'];
            } else {
                $max = $pdo->prepare("SELECT COALESCE(MAX(priority_order), 0) FROM result_merit_tiebreakers WHERE session_id = ? AND class_id = ?");
                $max->execute([$tb_session, $tb_class]);
                $pdo->prepare("INSERT INTO result_merit_tiebreakers (session_id, class_id, criterion_type, subject_id, priority_order, status) VALUES (?, ?, ?, ?, ?, 'Active')")
                    ->execute([$tb_session, $tb_class, $type, $type === 'subject' ? $subject_id : null, (int) $max->fetchColumn() + 1]);
                sec_log_activity($pdo, 'Result', 'Create', "নতুন Merit Tie-Breaker ক্রাইটেরিয়া যোগ করা হয়েছে: {$type}");
                $out['msg'] = 'ক্রাইটেরিয়া যোগ করা হয়েছে।';
            }
        } elseif ($tb_action === 'move') {
            $id = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
            $cur = $pdo->prepare("SELECT * FROM result_merit_tiebreakers WHERE id = ? AND session_id = ? AND class_id = ?");
            $cur->execute([$id, $tb_session, $tb_class]);
            $row = $cur->fetch();
            if ($row) {
                $nb = $pdo->prepare("SELECT * FROM result_merit_tiebreakers WHERE session_id = ? AND class_id = ? AND priority_order " . ($dir === 'up' ? '<' : '>') . " ? ORDER BY priority_order " . ($dir === 'up' ? 'DESC' : 'ASC') . " LIMIT 1");
                $nb->execute([$tb_session, $tb_class, $row['priority_order']]);
                $nbr = $nb->fetch();
                if ($nbr) {
                    $pdo->prepare("UPDATE result_merit_tiebreakers SET priority_order = ? WHERE id = ?")->execute([$nbr['priority_order'], $row['id']]);
                    $pdo->prepare("UPDATE result_merit_tiebreakers SET priority_order = ? WHERE id = ?")->execute([$row['priority_order'], $nbr['id']]);
                }
            }
        } elseif ($tb_action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM result_merit_tiebreakers WHERE id = ? AND session_id = ? AND class_id = ?")->execute([$id, $tb_session, $tb_class]);
            sec_log_activity($pdo, 'Result', 'Delete', "Merit Tie-Breaker ক্রাইটেরিয়া মুছে ফেলা হয়েছে: আইডি {$id}");
            $out['msg'] = 'ক্রাইটেরিয়া মুছে ফেলা হয়েছে।';
        } elseif ($tb_action !== 'list') {
            $out = ['ok' => false, 'msg' => 'Invalid action'];
        }
        if ($out['ok']) {
            $ls = $pdo->prepare(
                "SELECT rmt.id, rmt.criterion_type, rmt.subject_id, asub.subject_name
                 FROM result_merit_tiebreakers rmt LEFT JOIN academic_subjects asub ON asub.id = rmt.subject_id
                 WHERE rmt.session_id = ? AND rmt.class_id = ? ORDER BY rmt.priority_order ASC"
            );
            $ls->execute([$tb_session, $tb_class]);
            $out['criteria'] = $ls->fetchAll();
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

// -----------------------------------------------------------
// POST Handlers
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $_SESSION['rs_err'] = 'ফর্মের মেয়াদ শেষ হয়ে গেছে, পেজ রিলোড করে আবার চেষ্টা করুন।';
        header('Location: index.php?page=result_settings');
        exit();
    }

    if (isset($_POST['save_result_settings'])) {
        $set_parts = [];
        $params = [];
        foreach ($toggle_fields as $f) {
            $set_parts[] = "$f = ?";
            $params[] = isset($_POST[$f]) && $_POST[$f] === '1' ? 1 : 0;
        }
        foreach ($text_fields as $f) {
            $set_parts[] = "$f = ?";
            $params[] = trim($_POST[$f] ?? '');
        }
        $aggregate_result_type = in_array($_POST['aggregate_result_type'] ?? '', array_merge($aggregate_types, array_keys($pct_models)), true) ? $_POST['aggregate_result_type'] : $aggregate_types[0];
        $report_card_template  = in_array($_POST['report_card_template'] ?? '', $report_templates, true) ? $_POST['report_card_template'] : $report_templates[0];
        $body_font_style       = in_array($_POST['body_font_style'] ?? '', $font_styles, true) ? $_POST['body_font_style'] : 'Normal';
        $body_font_size        = (int) ($_POST['body_font_size'] ?? 12);

        $set_parts[] = "aggregate_result_type = ?"; $params[] = $aggregate_result_type;
        $set_parts[] = "report_card_template = ?";  $params[] = $report_card_template;
        $set_parts[] = "body_font_style = ?";        $params[] = $body_font_style;
        $set_parts[] = "body_font_size = ?";         $params[] = $body_font_size;

        $sql = "UPDATE exam_result_settings SET " . implode(', ', $set_parts) . " WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        sec_log_activity($pdo, 'Result', 'Update', 'Result Master Setting আপডেট করা হয়েছে');
        $_SESSION['rs_msg'] = 'Master Setting সফলভাবে সেভ হয়েছে!';
    } elseif (isset($_POST['copy_grade_points'])) {
        $from_session_id = (int) ($_POST['from_session_id'] ?? 0);
        $cur = $pdo->query("SELECT id FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
        $current_session_id = $cur ? (int) $cur['id'] : 0;

        if ($from_session_id <= 0 || $current_session_id <= 0) {
            $_SESSION['rs_err'] = 'From Session সিলেক্ট করুন এবং একটা Current Session সেট করা আছে কিনা নিশ্চিত হোন।';
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM exam_grade_points WHERE session_id = ?");
            $chk->execute([$current_session_id]);
            if ((int) $chk->fetchColumn() > 0) {
                $_SESSION['rs_err'] = 'Current Session-এ ইতিমধ্যে Grade Point এন্ট্রি আছে, তাই কপি করা যাবে না।';
            } else {
                $rows = $pdo->prepare("SELECT grade_letter, gpa, mark_from, mark_to, remarks FROM exam_grade_points WHERE session_id = ?");
                $rows->execute([$from_session_id]);
                $rows = $rows->fetchAll();
                if (!$rows) {
                    $_SESSION['rs_err'] = 'সিলেক্ট করা Session-এ কোনো Grade Point পাওয়া যায়নি।';
                } else {
                    $ins = $pdo->prepare("INSERT INTO exam_grade_points (session_id, grade_letter, gpa, mark_from, mark_to, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($rows as $r) {
                        $ins->execute([$current_session_id, $r['grade_letter'], $r['gpa'], $r['mark_from'], $r['mark_to'], $r['remarks']]);
                    }
                    sec_log_activity($pdo, 'Result', 'Create', "Session ID {$from_session_id} থেকে Session ID {$current_session_id} এ Grade Point কপি করা হয়েছে");
                    $_SESSION['rs_msg'] = 'Grade Point সফলভাবে কপি করা হয়েছে।';
                }
            }
        }
    }

    header('Location: index.php?page=result_settings');
    exit();
}

$settings = $pdo->query("SELECT * FROM exam_result_settings WHERE id = 1")->fetch();
$current_session = $pdo->query("SELECT * FROM academic_sessions WHERE is_current_session = 1 LIMIT 1")->fetch();
$current_session_id = $current_session ? (int) $current_session['id'] : 0;
$other_sessions = $pdo->prepare("SELECT id, session_name FROM academic_sessions WHERE id != ? ORDER BY session_name DESC");
$other_sessions->execute([$current_session_id]);
$other_sessions = $other_sessions->fetchAll();

// প্রতিটা মডেলের জন্য এই সেশনের Exam Term (Main/Sub) কাঠামোর বর্তমান অবস্থা — মডেল সিলেক্ট করলে ব্যাখ্যার নিচে দেখায়
$pct_preview = [];
foreach (array_keys($pct_models) as $mk) {
    $pct_preview[$mk] = $current_session_id > 0
        ? rc_pct_structure_html(rc_pct_structure($pdo, $current_session_id, $mk), $mk)
        : '<div class="alert alert-warning py-2 small mb-2">Current Session সেট করা নেই।</div>';
}

// Merit Tie-Breaker প্যানেলের ডেটা (Session/Class/Subject ড্রপডাউন)
$tb_sessions = $pdo->query("SELECT id, session_name FROM academic_sessions ORDER BY id DESC")->fetchAll();
$tb_classes  = $pdo->query("SELECT id, class_name FROM academic_classes WHERE status = 'Active' ORDER BY rank ASC, class_name ASC")->fetchAll();
$tb_subjects = [];
foreach ($pdo->query("SELECT id, class_id, subject_name FROM academic_subjects WHERE status = 'Active' ORDER BY sort_order ASC, id ASC")->fetchAll() as $sb) {
    $tb_subjects[(int) $sb['class_id']][] = ['id' => (int) $sb['id'], 'n' => $sb['subject_name']];
}

function rsv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

// Yes/No পিল টগল রেন্ডার করার হেল্পার (স্ক্রিনশটের ধাঁচে — Yes নেভি ব্লু, No লাল)
function render_yn(string $name, string $label, $checked): void
{
    $is_yes = (int) $checked === 1;
    echo '<div class="row mb-3 align-items-center yn-row">
        <div class="col-auto">
            <input type="hidden" name="' . $name . '" id="' . $name . '_h" value="' . ($is_yes ? '1' : '0') . '">
            <button type="button" class="yn-btn ' . ($is_yes ? 'yn-yes' : 'yn-no') . '" data-target="' . $name . '_h" onclick="ynToggle(this)">' . ($is_yes ? 'Yes' : 'No') . '</button>
        </div>
        <div class="col text-muted">' . rsv($label) . '</div>
    </div>';
}

// Color picker (swatch + hex text) রেন্ডার করার হেল্পার
function render_color(string $name, string $label, string $val): void
{
    echo '<div class="row mb-3 align-items-center">
        <label class="col-sm-3 col-form-label text-end">' . rsv($label) . '</label>
        <div class="col-sm-6">
            <div class="input-group">
                <input type="color" class="form-control form-control-color" style="max-width:46px;" value="' . rsv($val) . '" oninput="document.getElementById(\'' . $name . '_hex\').value=this.value">
                <input type="text" name="' . $name . '" id="' . $name . '_hex" class="form-control" value="' . rsv($val) . '">
            </div>
        </div>
    </div>';
}
?>

<style>
.yn-btn { border: none; border-radius: 4px; color: #fff; font-weight: 700; font-size: 12px; padding: 5px 18px; min-width: 60px; }
.yn-yes { background-color: #1e3a8a; }
.yn-no  { background-color: #e0533f; }
.section-heading { color: #1e3a8a; font-weight: 700; border-bottom: 2px solid #e9ecef; padding-bottom: 8px; margin: 28px 0 18px; }
.section-heading:first-of-type { margin-top: 0; }
.rs-tabs { flex-wrap: wrap; border-bottom: 2px solid #e9ecef; }
.rs-tabs .nav-link { color: #475569; font-weight: 600; font-size: 14px; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.rs-tabs .nav-link:hover { color: #1e3a8a; }
.rs-tabs .nav-link.active { color: #1e3a8a; background: transparent; border-bottom-color: #1e3a8a; }
.rs-pane .section-heading { margin-top: 0; }
.yn-2col { column-count: 2; column-gap: 30px; }
.yn-2col .yn-row { break-inside: avoid; -webkit-column-break-inside: avoid; }
@media (max-width: 767px) { .yn-2col { column-count: 1; } }
</style>

<div class="card-custom">
    <?php if ($rs_msg): ?><div class="alert alert-success py-2"><?php echo rsv($rs_msg); ?></div><?php endif; ?>
    <?php if ($rs_err): ?><div class="alert alert-danger py-2"><?php echo rsv($rs_err); ?></div><?php endif; ?>

    <!-- ================= Tab Navbar ================= -->
    <ul class="nav nav-tabs rs-tabs mb-4" id="rsTabs" role="tablist">
        <li class="nav-item"><a href="#copy" class="nav-link active" data-rs-tab="copy"><i class="fa-solid fa-copy"></i> Copy Grade Points</a></li>
        <li class="nav-item"><a href="#onoff" class="nav-link" data-rs-tab="onoff"><i class="fa-solid fa-power-off"></i> Progress Report (On/Off)</a></li>
        <li class="nav-item"><a href="#templates" class="nav-link" data-rs-tab="templates"><i class="fa-solid fa-table-list"></i> Result Templates</a></li>
        <li class="nav-item"><a href="#signatures" class="nav-link" data-rs-tab="signatures"><i class="fa-solid fa-signature"></i> Signatures</a></li>
        <li class="nav-item"><a href="#fonts" class="nav-link" data-rs-tab="fonts"><i class="fa-solid fa-font"></i> Fonts &amp; Colors</a></li>
    </ul>

    <!-- ================= Copy Grade Points ================= -->
    <div class="rs-pane" data-rs-pane="copy">
    <h5 class="section-heading"><i class="fa-solid fa-copy"></i> Copy Grade Points to Session : <?php echo rsv($current_session['session_name'] ?? '—'); ?></h5>

    <div class="alert alert-warning py-2 small">
        <i class="fa-solid fa-circle-info"></i> Warning! You can copy when there is no manual grade entry exists for any classes of the current session.
    </div>

    <form method="POST" action="index.php?page=result_settings" class="mb-2">
        <?php echo csrf_field(); ?>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">From Session</label>
            <div class="col-sm-6">
                <select name="from_session_id" class="form-select">
                    <option value="">Select Session</option>
                    <?php foreach ($other_sessions as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>"><?php echo rsv($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="copy_grade_points" class="btn btn-primary fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;"><i class="fa-solid fa-copy"></i> Copy Now</button>
            </div>
        </div>
    </form>
    </div>

    <!-- ================= Main Settings Form ================= -->
    <form method="POST" action="index.php?page=result_settings">
        <?php echo csrf_field(); ?>

        <div class="rs-pane d-none" data-rs-pane="onoff">
        <h5 class="section-heading">
            <i class="fa-solid fa-power-off"></i> Progress Report Settings (On/Off)
        </h5>
        <div class="yn-2col">
        <?php
        render_yn('show_medium_name', 'Show Medium Name', $settings['show_medium_name']);
        render_yn('show_student_roll_no', 'Show Student Roll No.', $settings['show_student_roll_no']);
        render_yn('show_student_picture_qr', 'Show Student Picture & QR Code', $settings['show_student_picture_qr']);
        render_yn('add_watermark_background', 'Add Watermark Background', $settings['add_watermark_background']);
        render_yn('show_banner_instead_logo', 'Show Banner (Instead of Logo)', $settings['show_banner_instead_logo']);
        render_yn('pass_fail_summary_merit_list', 'Pass Fail Summary (Merit List)', $settings['pass_fail_summary_merit_list']);
        render_yn('merit_list_with_subjects', 'Merit List With Subjects', $settings['merit_list_with_subjects']);
        render_yn('show_final_grade_letter_point', 'Show Final Grade Letter & Point', $settings['show_final_grade_letter_point']);
        render_yn('show_subject_grade_letter', 'Show Subject Grade Letter', $settings['show_subject_grade_letter']);
        render_yn('show_subject_grade_point', 'Show Subject Grade Point', $settings['show_subject_grade_point']);
        render_yn('show_result_remarks', 'Show Result Remarks', $settings['show_result_remarks']);
        render_yn('show_working_days_attend_absent', 'Show Working Days & Attend Absent', $settings['show_working_days_attend_absent']);
        render_yn('show_absent_count', 'Show Absent Count (Attendance ঘরে)', $settings['show_absent_count']);
        render_yn('show_discipline_cleanliness', 'Show Discipline & Cleanliness', $settings['show_discipline_cleanliness']);
        render_yn('show_interpretation', 'Show Interpretation', $settings['show_interpretation']);
        render_yn('show_total_subject_fail_count', 'Show Total Subject Fail Count', $settings['show_total_subject_fail_count']);
        render_yn('show_part_marks_calculation', 'Show Part Marks Calculation (%)', $settings['show_part_marks_calculation']);
        render_yn('calculate_merit_position_by_marks', 'Calculate Merit Position By Marks', $settings['calculate_merit_position_by_marks']);
        render_yn('enable_merit_tiebreaker', 'Enable Merit Tie-Breaker (সমান Total-এ ক্রাইটেরিয়া দিয়ে কাটাকাটি)', $settings['enable_merit_tiebreaker']);
        ?>
        </div>
        <div class="form-text mb-2">
            "Enable Merit Tie-Breaker" <strong>No</strong> করলে Tabulation Sheet ও Students Merit List-এ সমান Total মার্কে
            সবসময় যৌথ Merit Position (Joint 1st/2nd...) দেখাবে। <strong>Yes</strong> করলে নিচের ক্রাইটেরিয়া অনুযায়ী কাটাকাটি হয়ে Merit Position ঠিক হবে।
            (টগলের Yes/No বদলালে নিচের <strong>Update</strong> বাটনে সেভ হয়; ক্রাইটেরিয়া যোগ/মোছা/ক্রম বদল সাথে সাথেই সেভ হয়।)
        </div>

        <!-- ============ Merit Tie-Breaker ক্রাইটেরিয়া (টগল Yes থাকলে দেখায়) ============ -->
        <div id="tbPanel" class="border rounded p-3 mb-3 bg-light" style="<?php echo (int) $settings['enable_merit_tiebreaker'] === 1 ? '' : 'display:none;'; ?>">
            <h6 class="fw-bold mb-2"><i class="fa-solid fa-scale-balanced"></i> Merit Tie-Breaker Criteria (Class অনুযায়ী)</h6>
            <div class="small text-muted mb-3">
                একই Class-এর একাধিক শিক্ষার্থীর Total মার্ক সমান হলে নিচের ক্রম অনুযায়ী (উপর থেকে নিচে) একে একে চেক হয় — যেখানে প্রথম পার্থক্য পাওয়া যায়
                সেটা দিয়েই Merit Position ঠিক হয়। কোনো Class-এ কিছু যোগ না থাকলে সেই Class-এ সমান Total-এ যৌথ Merit Position-ই দেখাবে।
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1">Session</label>
                    <select id="tbSession" class="form-select form-select-sm">
                        <?php foreach ($tb_sessions as $s): ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php echo $current_session_id === (int) $s['id'] ? 'selected' : ''; ?>><?php echo rsv($s['session_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1">Class</label>
                    <select id="tbClass" class="form-select form-select-sm">
                        <?php foreach ($tb_classes as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo rsv($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="tbMsg" class="small mb-2"></div>
            <table class="table table-bordered table-sm align-middle bg-white mb-3">
                <thead class="table-light"><tr><th style="width:60px;">ক্রম</th><th>ক্রাইটেরিয়া</th><th style="width:150px;">Actions</th></tr></thead>
                <tbody id="tbRows"><tr><td colspan="3" class="text-center text-muted py-2">লোড হচ্ছে…</td></tr></tbody>
            </table>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1">নতুন ক্রাইটেরিয়া</label>
                    <select id="tbType" class="form-select form-select-sm">
                        <option value="">সিলেক্ট করুন</option>
                        <option value="subject">নির্দিষ্ট Subject-এর মার্ক</option>
                        <option value="attendance">উপস্থিতি %</option>
                        <option value="age">বয়স (কম বয়সী আগে)</option>
                    </select>
                </div>
                <div class="col-md-4" id="tbSubjectWrap" style="display:none;">
                    <label class="form-label fw-bold small mb-1">Subject</label>
                    <select id="tbSubject" class="form-select form-select-sm"></select>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-sm btn-primary fw-bold" style="background-color:#1e3a8a;" onclick="tbAdd()"><i class="fa-solid fa-plus"></i> যোগ করুন</button>
                </div>
            </div>
        </div>

        </div><!-- /onoff -->

        <div class="rs-pane d-none" data-rs-pane="templates">
        <h5 class="section-heading"><i class="fa-solid fa-table-list"></i> Result Templates Settings</h5>
        <div class="row mb-3 align-items-start">
            <label class="col-sm-3 col-form-label text-end">Aggregate Result Type</label>
            <div class="col-sm-8">
                <div class="fw-bold small mb-2" style="color:#1e3a8a;">শতকরা (Percentage) ওয়েট মডেল</div>
                <?php foreach ($pct_labels as $t => $lbl): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="aggregate_result_type" id="agg_<?php echo md5($t); ?>" value="<?php echo rsv($t); ?>" data-pct="1" <?php echo $settings['aggregate_result_type'] === $t ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agg_<?php echo md5($t); ?>"><?php echo rsv($lbl); ?></label>
                    </div>
                <?php endforeach; ?>
                <div class="fw-bold small mt-3 mb-2" style="color:#1e3a8a;">গড় (Average) ভিত্তিক পদ্ধতি</div>
                <?php foreach ($avg_labels as $t => $lbl): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="aggregate_result_type" id="agg_<?php echo md5($t); ?>" value="<?php echo rsv($t); ?>" data-pct="1" <?php echo $settings['aggregate_result_type'] === $t ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agg_<?php echo md5($t); ?>"><?php echo rsv($lbl); ?></label>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($aggregate_types as $t): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="aggregate_result_type" id="agg_<?php echo md5($t); ?>" value="<?php echo rsv($t); ?>" <?php echo $settings['aggregate_result_type'] === $t ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="agg_<?php echo md5($t); ?>"><?php echo rsv($t); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ============ শতকরা/গড় মডেলের বিস্তারিত + টার্ম কাঠামোর অবস্থা (মডেল সিলেক্ট করলে দেখায়; তারপর নিচের Update বাটন) ============ -->
        <div id="pctPanel" class="border rounded p-3 mb-4 bg-light d-none">
            <?php foreach ($pct_explain as $mk => $ex): ?>
                <div class="pct-explain d-none" data-model="<?php echo rsv($mk); ?>">
                    <h6 class="fw-bold" style="color:#1e3a8a;"><i class="fa-solid fa-circle-info"></i> <?php echo rsv($pct_labels[$mk] ?? ($avg_labels[$mk] ?? $mk)); ?> — কীভাবে কাজ করে</h6>
                    <ul class="small mb-2">
                        <?php foreach ($ex['how'] as $line): ?><li><?php echo rsv($line); ?></li><?php endforeach; ?>
                        <?php foreach (($ex['notes'] ?? $pct_common_notes) as $line): ?><li><?php echo rsv($line); ?></li><?php endforeach; ?>
                    </ul>
                    <div class="small mb-1"><span class="badge bg-primary">সূত্র</span> <?php echo rsv($ex['formula']); ?></div>
                    <div class="small mb-0"><span class="badge bg-success">উদাহরণ</span> <?php echo rsv($ex['example']); ?></div>
                </div>
            <?php endforeach; ?>
            <hr>
            <h6 class="fw-bold mb-2"><i class="fa-solid fa-list-check"></i> এই সেশনের টার্ম কাঠামোর বর্তমান অবস্থা <span class="text-muted small fw-normal">(সেশন: <?php echo rsv($current_session['session_name'] ?? '—'); ?>)</span></h6>
            <?php foreach ($pct_preview as $mk => $html): ?>
                <div class="pct-preview d-none" data-model="<?php echo rsv($mk); ?>"><?php echo $html; ?></div>
            <?php endforeach; ?>
            <div class="small text-muted">
                মাসিক/টার্ম ও ওয়েট বসাতে বা বদলাতে <a href="index.php?page=result_exam_terms" class="fw-bold"><i class="fa-solid fa-arrow-up-right-from-square"></i> Exam Terms</a> পেজে যান — টার্ম বানানোর সময়ই Main/Sub ও ওয়েট দেওয়া যায়।
                মডেলটা চালু করতে নিচের <strong>Update</strong> বাটনে সেভ করুন।
            </div>
        </div>
        <div class="row mb-4 align-items-start">
            <label class="col-sm-3 col-form-label text-end">Report Card Templates</label>
            <div class="col-sm-8">
                <?php foreach ($report_templates as $t): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="report_card_template" id="tpl_<?php echo md5($t); ?>" value="<?php echo rsv($t); ?>" <?php echo $settings['report_card_template'] === $t ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="tpl_<?php echo md5($t); ?>"><?php echo rsv($t); ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        </div><!-- /templates -->

        <div class="rs-pane d-none" data-rs-pane="signatures">
        <h5 class="section-heading"><i class="fa-solid fa-signature"></i> Signatures Settings</h5>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Left Signature</label>
            <div class="col-sm-6"><input type="text" name="left_signature" class="form-control" value="<?php echo rsv($settings['left_signature']); ?>"></div>
        </div>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Middle Signature</label>
            <div class="col-sm-6"><input type="text" name="middle_signature" class="form-control" value="<?php echo rsv($settings['middle_signature']); ?>"></div>
        </div>
        <div class="row mb-4 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Right Signature</label>
            <div class="col-sm-6"><input type="text" name="right_signature" class="form-control" value="<?php echo rsv($settings['right_signature']); ?>"></div>
        </div>

        </div><!-- /signatures -->

        <div class="rs-pane d-none" data-rs-pane="fonts">
        <h5 class="section-heading"><i class="fa-solid fa-font"></i> Fonts & Colors Settings</h5>
        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Body Font Size</label>
            <div class="col-sm-6">
                <select name="body_font_size" class="form-select">
                    <?php foreach ($font_sizes as $fs): ?>
                        <option value="<?php echo (int) $fs; ?>" <?php echo (int) $settings['body_font_size'] === $fs ? 'selected' : ''; ?>><?php echo (int) $fs; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php render_color('body_font_color', 'Body Font Color', $settings['body_font_color']); ?>
        <?php render_color('institute_name_color', 'Institute Name Color', $settings['institute_name_color']); ?>
        <?php render_color('table_header_color', 'Table Header Color', $settings['table_header_color']); ?>
        <?php render_color('fail_letter_grade_color', 'Fail Letter Grade Color', $settings['fail_letter_grade_color']); ?>

        <div class="row mb-3 align-items-center">
            <label class="col-sm-3 col-form-label text-end">Body Font Style</label>
            <div class="col-sm-6">
                <select name="body_font_style" class="form-select">
                    <?php foreach ($font_styles as $st): ?>
                        <option value="<?php echo rsv($st); ?>" <?php echo $settings['body_font_style'] === $st ? 'selected' : ''; ?>><?php echo rsv($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php render_color('gpa_color', 'GPA Color', $settings['gpa_color']); ?>
        <?php render_color('remarks_progress_color', 'Remarks & Progress Color', $settings['remarks_progress_color']); ?>
        <?php render_color('table_footer_color', 'Table Footer Color', $settings['table_footer_color']); ?>
        <?php render_color('fail_part_marks_color', 'Fail Part Marks Color', $settings['fail_part_marks_color']); ?>

        </div><!-- /fonts -->

        <div id="rsSaveBar" class="d-none">
        <hr>
        <div class="row">
            <div class="col-sm-6 offset-sm-3">
                <button type="submit" name="save_result_settings" class="btn btn-primary px-4 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Update</button>
                <a href="index.php?page=dashboard" class="btn btn-danger px-4 fw-bold ms-2">Cancel</a>
            </div>
        </div>
        </div>
    </form>
</div>

<script>
// নিচের Update বার: শুধু "Copy Grade Points" ট্যাবে লুকানো
function rsRefreshBar() {
    var name = window.rsCurrentTab || 'copy';
    document.getElementById('rsSaveBar').classList.toggle('d-none', name === 'copy');
}

// Master Settings — navbar/tab টগল (সব ফিল্ড একই ফর্মে থাকে, তাই যেকোনো ট্যাব থেকে Update দিলে সব সেভ হয়)
(function () {
    var tabs = document.querySelectorAll('[data-rs-tab]');
    var panes = document.querySelectorAll('[data-rs-pane]');
    function show(name) {
        if (!document.querySelector('[data-rs-pane="' + name + '"]')) name = 'copy';
        tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-rs-tab') === name); });
        panes.forEach(function (p) { p.classList.toggle('d-none', p.getAttribute('data-rs-pane') !== name); });
        window.rsCurrentTab = name;
        rsRefreshBar();
        try { history.replaceState(null, '', '#' + name); } catch (e) {}
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) { e.preventDefault(); show(t.getAttribute('data-rs-tab')); });
    });
    show((location.hash || '').replace('#', '') || 'copy');
    // সেভের পর রিডাইরেক্ট হলে একই ট্যাবে ফিরে আসার জন্য ফর্মের action-এ ট্যাবের hash জুড়ে দিই
    document.querySelectorAll('form[action*="page=result_settings"]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var act = document.querySelector('[data-rs-tab].active');
            if (act) f.setAttribute('action', f.getAttribute('action').split('#')[0] + '#' + act.getAttribute('data-rs-tab'));
        });
    });
})();

function ynToggle(btn) {
    var hidden = document.getElementById(btn.getAttribute('data-target'));
    var isYes = hidden.value === '1';
    hidden.value = isYes ? '0' : '1';
    btn.classList.toggle('yn-yes', !isYes);
    btn.classList.toggle('yn-no', isYes);
    btn.textContent = isYes ? 'No' : 'Yes';
    // "Enable Merit Tie-Breaker" Yes হলে ক্রাইটেরিয়া প্যানেল দেখায়, No হলে লুকায়
    if (btn.getAttribute('data-target') === 'enable_merit_tiebreaker_h') {
        document.getElementById('tbPanel').style.display = isYes ? 'none' : '';
    }
}

// ---------------- শতকরা/গড় মডেল: সিলেক্ট করলে ব্যাখ্যা + টার্ম কাঠামোর অবস্থা দেখায় ----------------
(function () {
    var panel = document.getElementById('pctPanel');
    if (!panel) { return; }
    function onSelect() {
        var sel = document.querySelector('input[name="aggregate_result_type"][data-pct="1"]:checked');
        var current = sel ? sel.value : null;
        panel.classList.toggle('d-none', !current);
        document.querySelectorAll('.pct-explain, .pct-preview').forEach(function (d) { d.classList.toggle('d-none', d.getAttribute('data-model') !== current); });
    }
    document.querySelectorAll('input[name="aggregate_result_type"]').forEach(function (r) { r.addEventListener('change', onSelect); });
    onSelect();
})();

// ---------------- Merit Tie-Breaker ক্রাইটেরিয়া (AJAX) ----------------
var TB = {
    csrf: <?php echo json_encode(csrf_token()); ?>,
    subjects: <?php echo json_encode($tb_subjects, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    labels: { subject: 'নির্দিষ্ট Subject-এর মার্ক (বেশি মার্ক আগে)', attendance: 'উপস্থিতি % (বেশি উপস্থিতি আগে)', age: 'বয়স (কম বয়সী আগে)' }
};
function tbEsc(t) { var d = document.createElement('div'); d.textContent = (t == null ? '' : t); return d.innerHTML; }
function tbCall(action, extra, cb) {
    var fd = new FormData();
    fd.append('tb_action', action);
    fd.append('csrf_token', TB.csrf);
    fd.append('session_id', document.getElementById('tbSession').value);
    fd.append('class_id', document.getElementById('tbClass').value);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    fetch('index.php?page=result_settings', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var m = document.getElementById('tbMsg');
            m.textContent = j.msg || '';
            m.className = 'small mb-2 ' + (j.ok ? 'text-success' : 'text-danger');
            if (j.ok) { tbRender(j.criteria || []); if (cb) cb(); }
        })
        .catch(function () { var m = document.getElementById('tbMsg'); m.textContent = 'সার্ভারের সাথে যোগাযোগ করা যায়নি।'; m.className = 'small mb-2 text-danger'; });
}
function tbRender(list) {
    var tb = document.getElementById('tbRows');
    if (!list.length) {
        tb.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-2">এই Class-এ কোনো Tie-Breaker ক্রাইটেরিয়া নেই — সমান Total-এ যৌথ Merit Position দেখাবে।</td></tr>';
        return;
    }
    var h = '';
    list.forEach(function (c, i) {
        h += '<tr><td class="fw-bold">' + (i + 1) + '</td><td>' + tbEsc(TB.labels[c.criterion_type] || c.criterion_type) +
             (c.criterion_type === 'subject' ? ' — <span class="badge bg-primary">' + tbEsc(c.subject_name || '—') + '</span>' : '') + '</td>' +
             '<td class="text-nowrap">' +
             '<button type="button" class="btn btn-sm btn-outline-secondary" title="উপরে"' + (i === 0 ? ' disabled' : '') + ' onclick="tbCall(\'move\',{id:' + c.id + ',direction:\'up\'})"><i class="fa-solid fa-arrow-up"></i></button> ' +
             '<button type="button" class="btn btn-sm btn-outline-secondary" title="নিচে"' + (i === list.length - 1 ? ' disabled' : '') + ' onclick="tbCall(\'move\',{id:' + c.id + ',direction:\'down\'})"><i class="fa-solid fa-arrow-down"></i></button> ' +
             '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete" onclick="if(confirm(\'মুছে ফেলবেন?\'))tbCall(\'delete\',{id:' + c.id + '})"><i class="fa-solid fa-trash"></i></button>' +
             '</td></tr>';
    });
    tb.innerHTML = h;
}
function tbFillSubjects() {
    var list = TB.subjects[document.getElementById('tbClass').value] || [];
    var h = '<option value="">সিলেক্ট করুন</option>';
    list.forEach(function (x) { h += '<option value="' + x.id + '">' + tbEsc(x.n) + '</option>'; });
    document.getElementById('tbSubject').innerHTML = h;
}
function tbAdd() {
    tbCall('add', { criterion_type: document.getElementById('tbType').value, subject_id: document.getElementById('tbSubject').value }, function () {
        document.getElementById('tbType').value = ''; document.getElementById('tbSubjectWrap').style.display = 'none';
    });
}
(function () {
    var cls = document.getElementById('tbClass'), ses = document.getElementById('tbSession');
    if (!cls || !cls.options.length) { return; }
    document.getElementById('tbType').addEventListener('change', function () {
        document.getElementById('tbSubjectWrap').style.display = this.value === 'subject' ? '' : 'none';
    });
    cls.addEventListener('change', function () { tbFillSubjects(); tbCall('list'); });
    ses.addEventListener('change', function () { tbCall('list'); });
    tbFillSubjects(); tbCall('list');
})();
</script>
