<?php
// admin/partials/profile_result_dynamic.php
// Student Profile > Result ট্যাব থেকে Search করলে এটা দেখায় — শুধু on-screen ভিউয়ের জন্য
// (প্রিন্টের জন্য Result Module > Result Reports > Student Report Card পেজ ব্যবহার হয়,
// যেটা Master Setting-এর Report Card Template অনুযায়ী Govt./MPO বা School Classic এ প্রিন্ট করে)।
?>
<style>
    .pr-card { border-radius: 14px; overflow: hidden; box-shadow: 0 6px 20px rgba(109,40,217,.12); margin-bottom: 10px; }
    .pr-hero {
        background: linear-gradient(115deg,#6d28d9 0%,#c026d3 55%,#db2777 100%);
        color: #fff; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
    }
    .pr-hero .pr-title { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; opacity: .85; }
    .pr-hero h5 { margin: 4px 0 0; font-weight: 700; }
    .pr-hero .pr-badge { background: rgba(255,255,255,.18); padding: 5px 14px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .pr-strip { display: flex; background: #fff; flex-wrap: wrap; }
    .pr-strip div { flex: 1; min-width: 110px; padding: 10px 14px; text-align: center; border-right: 1px solid #ece9f7; }
    .pr-strip div:last-child { border-right: none; }
    .pr-strip span { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: .7px; color: #78748c; margin-bottom: 3px; }
    .pr-strip b { font-size: 14px; }
    .pr-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 14px 16px 4px; background: #fff; }
    .pr-tile { border-radius: 10px; padding: 10px 12px; color: #fff; }
    .pr-tile span { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: .6px; opacity: .9; margin-bottom: 3px; }
    .pr-tile b { font-size: 19px; }
    .pr-tile.t1 { background: linear-gradient(135deg,#6d28d9,#8b5cf6); }
    .pr-tile.t2 { background: linear-gradient(135deg,#db2777,#f472b6); }
    .pr-tile.t3 { background: linear-gradient(135deg,#0d9488,#2dd4bf); }
    .pr-tile.t4 { background: linear-gradient(135deg,#d97706,#fbbf24); }
    .pr-body { background: #fff; padding: 14px 16px 18px; }
    .pr-chart-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 12px; }
    .pr-chart-row .pr-lbl { width: 200px; flex-shrink: 0; font-weight: 600; color: #1e1b2e; }
    .pr-chart-row .pr-track { flex: 1; height: 9px; background: #eee7fb; border-radius: 6px; overflow: hidden; }
    .pr-chart-row .pr-fill { height: 100%; border-radius: 6px; background: linear-gradient(90deg,#6d28d9,#a78bfa); }
    .pr-chart-row.pr-fail .pr-fill { background: linear-gradient(90deg,#dc2626,#f87171); }
    .pr-chart-row .pr-val { width: 90px; text-align: right; font-weight: 700; flex-shrink: 0; }
    .pr-result-line { margin-top: 10px; font-size: 13px; font-weight: 600; }
    .pr-result-line.pass { color: #0d9488; }
    .pr-result-line.fail { color: #dc2626; }
</style>

<div class="pr-card">
    <div class="pr-hero">
        <div>
            <div class="pr-title">School ERP · Result Preview</div>
            <h5><?php echo vv($vw['name_en']); ?> — <?php echo vv($rc_term_name); ?></h5>
        </div>
        <div class="pr-badge"><?php echo $rc_result['overall_fail'] ? '❌ Result: FAILED' : '🏆 Result: PASSED with ' . vv($rc_result['overall_gl']); ?></div>
    </div>
    <div class="pr-strip">
        <div><span>Class / Group</span><b><?php echo vv($vw['class_name']); ?><?php echo $vw['group_name'] ? ' · ' . vv($vw['group_name']) : ''; ?></b></div>
        <div><span>Section</span><b><?php echo vv($vw['section_name']); ?></b></div>
        <div><span>Roll</span><b><?php echo vv($vw['roll_no']); ?></b></div>
        <div><span>Session</span><b><?php echo vv($vw['session_name']); ?></b></div>
    </div>
    <div class="pr-tiles">
        <div class="pr-tile t1"><span>GPA</span><b><?php echo $rc_result['overall_fail'] ? '0.00' : number_format($rc_result['overall_gpa'], 2); ?></b></div>
        <div class="pr-tile t2"><span>Class Position</span><b><?php echo $rc_class_position !== null ? (int) $rc_class_position : '—'; ?></b></div>
        <div class="pr-tile t3"><span>Attendance</span><b><?php echo $rc_working_days > 0 ? round($rc_present_days / $rc_working_days * 100) . '%' : '—'; ?></b></div>
        <div class="pr-tile t4"><span>Total Marks</span><b><?php echo rtrim(rtrim(number_format($rc_result['obtained_total'], 2), '0'), '.'); ?>/<?php echo rtrim(rtrim(number_format($rc_result['full_total'], 2), '0'), '.'); ?></b></div>
    </div>
    <div class="pr-body">
        <?php foreach ($rc_result['subject_rows'] as $sr): $pr_pct = $sr['full'] > 0 ? ($sr['obtained'] / $sr['full']) * 100 : 0; ?>
            <div class="pr-chart-row<?php echo $sr['fail'] ? ' pr-fail' : ''; ?>">
                <div class="pr-lbl"><?php echo vv($sr['name']); ?></div>
                <div class="pr-track"><div class="pr-fill" style="width:<?php echo max(3, round($pr_pct)); ?>%"></div></div>
                <div class="pr-val"><?php echo rtrim(rtrim(number_format($sr['obtained'], 2), '0'), '.'); ?> · <?php echo $sr['fail'] ? 'F' : vv($sr['gl']); ?></div>
            </div>
        <?php endforeach; ?>

        <div class="pr-result-line <?php echo $rc_result['overall_fail'] ? 'fail' : 'pass'; ?>">
            Overall: <?php echo $rc_result['overall_fail'] ? 'Fail' : 'Pass'; ?>
            &nbsp;|&nbsp; Percentage: <?php echo number_format($rc_result['percentage'], 2); ?>%
            <?php if ($rc_result['fail_subject_count'] > 0): ?>&nbsp;|&nbsp; Failed Subjects: <?php echo (int) $rc_result['fail_subject_count']; ?><?php endif; ?>
        </div>
    </div>
</div>
