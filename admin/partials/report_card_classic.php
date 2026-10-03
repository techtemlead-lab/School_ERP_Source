<?php
// admin/partials/report_card_classic.php
// "School (Classic)" রিপোর্ট কার্ড ডিজাইন — Result Module > Exam Settings > Master Setting এ
// Report Card Template = "School (Classic)" সিলেক্ট করলে এটা result_students_report_card.php
// থেকে প্রতি স্টুডেন্টের জন্য একবার করে include হয়। একই লুপ ভ্যারিয়েবল ($student,
// $subject_rows, $overall_gpa ইত্যাদি) এবং একই RC_* টগলগুলো এখানেও প্রযোজ্য।
?>
<style>
    .sc-sheet {
        width: 277mm; min-height: 190mm; margin: 0 auto 20px; background: #fbf8f1;
        position: relative; padding: 10mm 12mm; font-family: 'Noto Sans Bengali', Arial, sans-serif; color: #231f1a;
    }
    .sc-frame { position: absolute; inset: 5mm; border: 2.5px solid #7a1420; pointer-events: none; }
    .sc-frame::after { content: ""; position: absolute; inset: 5px; border: 1px solid #a9822f; }
    .sc-corner { position: absolute; width: 16px; height: 16px; border: 2px solid #7a1420; }
    .sc-corner.tl { top: 5mm; left: 5mm; border-right: none; border-bottom: none; }
    .sc-corner.tr { top: 5mm; right: 5mm; border-left: none; border-bottom: none; }
    .sc-corner.bl { bottom: 5mm; left: 5mm; border-right: none; border-top: none; }
    .sc-corner.br { bottom: 5mm; right: 5mm; border-left: none; border-top: none; }
    .sc-watermark {
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        font-size: 110px; font-weight: 700; color: rgba(122, 20, 32, .045);
        letter-spacing: 6px; white-space: nowrap; pointer-events: none; z-index: 0;
    }
    .sc-content { position: relative; z-index: 1; }
    .sc-head { text-align: center; padding-bottom: 8px; border-bottom: 3px double #7a1420; margin-bottom: 14px; }
    .sc-head img.sc-logo { max-height: 54px; margin-bottom: 6px; }
    .sc-head h1 { margin: 0; font-size: 24px; letter-spacing: .5px; color: #7a1420; }
    .sc-head .sc-addr { font-size: 12px; color: #6b6154; margin-top: 2px; }
    .sc-head .sc-doctitle {
        display: inline-block; margin-top: 8px; font-style: italic; font-size: 15.5px;
        letter-spacing: 3px; text-transform: uppercase; color: #231f1a;
        padding: 3px 22px; border-top: 1px solid #a9822f; border-bottom: 1px solid #a9822f;
    }
    .sc-info-row { display: grid; grid-template-columns: 1fr 92px; gap: 18px; margin-bottom: 14px; }
    .sc-info-grid {
        display: grid; grid-template-columns: repeat(4, 1fr); row-gap: 9px; column-gap: 14px;
        font-size: 12.5px; border: 1px solid #c9bfa8; padding: 10px 16px; background: #ffffffaa;
    }
    .sc-info-grid .sc-full { grid-column: 1 / -1; }
    .sc-info-grid label { display: block; font-size: 9.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #a9822f; font-weight: 600; margin-bottom: 1px; }
    .sc-info-grid .sc-val { font-weight: 600; border-bottom: 1px dotted #c9bfa8; padding-bottom: 2px; }
    .sc-photo-box { width: 92px; height: 110px; border: 1.5px solid #7a1420; padding: 3px; background: #fff; }
    .sc-photo-box img { width: 100%; height: 100%; object-fit: cover; }
    .sc-photo-box .sc-ph { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #999; background: repeating-linear-gradient(45deg, #eee, #eee 6px, #f7f7f7 6px, #f7f7f7 12px); }
    table.sc-marks { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 12px; }
    table.sc-marks caption { text-align: left; font-style: italic; font-size: 14.5px; margin-bottom: 6px; color: #7a1420; }
    table.sc-marks th { background: #7a1420; color: #f2e6c9; font-weight: 600; font-size: 10.5px; letter-spacing: .3px; padding: 7px 6px; text-transform: uppercase; border: 1px solid #5c0e17; }
    table.sc-marks td { padding: 6px 7px; border: 1px solid #c9bfa8; text-align: center; }
    table.sc-marks td.sc-subj { text-align: left; font-weight: 600; }
    table.sc-marks tbody tr:nth-child(even) { background: #f4ede0; }
    .sc-grade-pill { display: inline-block; min-width: 24px; padding: 1px 6px; border: 1px solid #7a1420; border-radius: 2px; font-weight: 700; font-size: 11px; color: #7a1420; }
    .sc-grade-pill.sc-fail { color: #b91c1c; border-color: #b91c1c; }
    .sc-bottom { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; align-items: start; }
    .sc-summary, .sc-remarks-box { border: 1px solid #c9bfa8; padding: 10px 14px; background: #ffffffaa; font-size: 12px; }
    .sc-summary h4, .sc-remarks-box h4 { margin: 0 0 8px; font-style: italic; color: #7a1420; font-size: 14.5px; }
    .sc-summary .sc-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 14px; }
    .sc-summary .sc-grid div span { display: block; font-size: 9px; text-transform: uppercase; color: #a9822f; letter-spacing: 1px; }
    .sc-summary .sc-grid div b { font-size: 14.5px; }
    .sc-scale-mini { font-size: 10.5px; margin-top: 8px; color: #6b6154; }
    .sc-sign-row { margin-top: 22px; display: flex; justify-content: space-between; gap: 20px; text-align: center; font-size: 11.5px; }
    .sc-sign-row div { flex: 1; }
    .sc-sign-row .sc-line { border-top: 1px solid #231f1a; margin-top: 34px; padding-top: 5px; color: #6b6154; }
    .sc-qr-eiin { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 10px; color: #6b6154; }
    @media print { .sc-sheet { box-shadow: none; } @page { size: A4 landscape; margin: 6mm; } }
</style>

<div class="sc-sheet"<?php echo ($rc_idx < count($reports) - 1) ? ' style="page-break-after: always;"' : ''; ?>>
    <div class="sc-frame"></div>
    <div class="sc-corner tl"></div><div class="sc-corner tr"></div><div class="sc-corner bl"></div><div class="sc-corner br"></div>
    <div class="sc-watermark">SCHOOL ERP</div>

    <div class="sc-content">
        <div class="sc-head">
            <?php if (!empty($inst_logo)): ?>
                <img class="sc-logo" src="../<?php echo rcv($inst_logo); ?>">
            <?php endif; ?>
            <h1><?php echo rcv($inst_display_name); ?></h1>
            <?php if ($addr_line !== '' || $inst_eiin !== ''): ?>
                <div class="sc-addr"><?php echo rcv($addr_line); ?><?php echo ($addr_line !== '' && $inst_eiin !== '') ? ' — ' : ''; ?><?php echo $inst_eiin !== '' ? 'EIIN: ' . rcv($inst_eiin) : ''; ?></div>
            <?php endif; ?>
            <div class="sc-doctitle">Academic Transcript</div>
        </div>

        <div class="sc-info-row">
            <div class="sc-info-grid">
                <div><label>Student Name</label><div class="sc-val"><?php echo rcv($student['name_en']); ?></div></div>
                <div><label>Student ID</label><div class="sc-val"><?php echo rcv($student['student_id']); ?></div></div>
                <div><label>Class / Group</label><div class="sc-val"><?php echo rcv($student['class_name']); ?><?php echo $student['group_name'] ? ' (' . rcv($student['group_name']) . ')' : ''; ?></div></div>
                <div><label>Section</label><div class="sc-val"><?php echo rcv($student['section_name']); ?></div></div>
                <?php if ($RC_ROLL): ?>
                <div><label>Roll No.</label><div class="sc-val"><?php echo rcv($student['roll_no']); ?></div></div>
                <?php endif; ?>
                <div><label>Session</label><div class="sc-val"><?php echo rcv($sheet_session_name); ?></div></div>
                <div><label>Exam Term</label><div class="sc-val"><?php echo rcv($term_name); ?></div></div>
                <div><label>Shift</label><div class="sc-val"><?php echo rcv($student['shift_name']); ?></div></div>
                <div class="sc-full"><label>Father's Name</label><div class="sc-val"><?php echo rcv($student['father_name']); ?></div></div>
            </div>
            <?php if ($RC_PHOTO_QR): ?>
            <div>
                <div class="sc-photo-box">
                    <?php if (!empty($student['photo_path'])): ?>
                        <img src="../<?php echo rcv($student['photo_path']); ?>">
                    <?php else: ?>
                        <div class="sc-ph">Photo</div>
                    <?php endif; ?>
                </div>
                <div class="sc-qr-eiin">
                    <div id="rcQrBox_<?php echo (int) $rc_idx; ?>"></div>
                </div>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
        </div>

        <table class="sc-marks">
            <caption>Subject-wise Result</caption>
            <thead>
                <tr>
                    <th style="width:26px;">Sl.</th>
                    <th style="text-align:left;">Subject</th>
                    <th>Marks Obtained</th>
                    <?php if ($RC_SUBJ_GL): ?><th>Grade</th><?php endif; ?>
                    <?php if ($RC_SUBJ_GP): ?><th>GP</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subject_rows as $sr): ?>
                    <tr>
                        <td><?php echo (int) $sr['sl']; ?></td>
                        <td class="sc-subj"><?php echo rcv($sr['name']); ?></td>
                        <td><?php echo rcv(rtrim(rtrim(number_format($sr['obtained'], 2), '0'), '.')); ?> / <?php echo rcv(rtrim(rtrim(number_format($sr['full'], 2), '0'), '.')); ?></td>
                        <?php if ($RC_SUBJ_GL): ?>
                        <td><span class="sc-grade-pill<?php echo $sr['fail'] ? ' sc-fail' : ''; ?>"><?php echo $sr['fail'] ? 'F' : rcv($sr['gl']); ?></span></td>
                        <?php endif; ?>
                        <?php if ($RC_SUBJ_GP): ?>
                        <td><?php echo number_format($sr['gp'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="sc-bottom">
            <div class="sc-summary">
                <h4>Result Summary</h4>
                <div class="sc-grid">
                    <?php if ($RC_PART_PERCENT): ?>
                    <div><span>Total Marks</span><b><?php echo rcv(number_format($obtained_total, 2)); ?> / <?php echo rcv(number_format($full_total, 2)); ?></b></div>
                    <?php endif; ?>
                    <?php if ($RC_FINAL_GL_GP): ?>
                    <div><span>GPA</span><b><?php echo $overall_fail ? '0.00' : number_format($overall_gpa, 2); ?></b></div>
                    <div><span>Final Grade</span><b><?php echo $overall_fail ? 'F' : rcv($overall_gl); ?></b></div>
                    <?php endif; ?>
                    <?php if ($show_merit && $class_position !== null): ?>
                    <div><span>Class Position</span><b><?php echo (int) $class_position; ?></b></div>
                    <?php endif; ?>
                    <?php if ($RC_ATTEND): ?>
                    <div><span>Working Days</span><b><?php echo (int) $working_days; ?></b></div>
                    <div><span>Days Present</span><b><?php echo (int) $present_days; ?><?php echo $working_days > 0 ? ' (' . round($present_days / $working_days * 100) . '%)' : ''; ?></b></div>
                    <?php endif; ?>
                    <?php if ($RC_FAIL_COUNT): ?>
                    <div><span>Failed Subjects</span><b><?php echo (int) $fail_subject_count; ?></b></div>
                    <?php endif; ?>
                </div>
                <?php if ($RC_PASS_FAIL_SUM && $show_merit && isset($class_total_count)): ?>
                    <div class="sc-scale-mini">Class Total: <?php echo (int) $class_total_count; ?> &nbsp;|&nbsp; Passed: <?php echo (int) $class_pass_count; ?> &nbsp;|&nbsp; Failed: <?php echo (int) $class_fail_count; ?></div>
                <?php endif; ?>
            </div>
            <div class="sc-remarks-box">
                <h4>Teacher's Remarks</h4>
                <?php if ($RC_REMARKS):
                    $sc_remark = 'Needs Improvement';
                    if (!$overall_fail) {
                        if ($percentage >= 80) { $sc_remark = 'Excellent performance this term.'; }
                        elseif ($percentage >= 70) { $sc_remark = 'Very good progress overall.'; }
                        elseif ($percentage >= 60) { $sc_remark = 'Good, steady performance.'; }
                        elseif ($percentage >= 50) { $sc_remark = 'Satisfactory — more effort will help.'; }
                        else { $sc_remark = 'Fair — needs focused improvement.'; }
                    } else {
                        $sc_remark = 'Needs improvement in the failed subject(s) — please arrange extra support.';
                    }
                    echo rcv($sc_remark);
                endif; ?>
                <?php if ($RC_DISCIPLINE): ?><div class="sc-scale-mini">Discipline &amp; Cleanliness: ____________________</div><?php endif; ?>
                <?php if ($RC_INTERPRET): ?><div class="sc-scale-mini">Interpretation: ____________________</div><?php endif; ?>
            </div>
        </div>

        <div class="sc-sign-row">
            <div><div class="sc-line"><?php echo rcv($rc_settings['left_signature'] ?? 'Signature of Teacher'); ?></div></div>
            <div><div class="sc-line"><?php echo rcv($rc_settings['middle_signature'] ?? 'Signature of Coordinator'); ?></div></div>
            <div><div class="sc-line"><?php echo rcv($rc_settings['right_signature'] ?? 'Signature of Headmaster'); ?></div></div>
        </div>
    </div>
</div>
