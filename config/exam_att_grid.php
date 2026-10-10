<?php
require_once __DIR__ . '/result_scope.php';
// config/exam_att_grid.php — Attendance (Exam) গ্রিড: result_attendance_exam.php ও ajax/result_attendance_exam_ajax.php-এর শেয়ার্ড অংশ
//
// নিয়ম:
//  ১) নির্বাচিত Exam Part-এ যে Subject Assign করা নেই (Result > Exam Settings > Assign Exams), সেই Subject-এর কলামই আসে না।
//  ২) Exam Eligibility-তে যে শিক্ষার্থী যে Subject-এ Eligible নয় (বা Eligibility দেওয়াই হয়নি), তার ঘর নিষ্ক্রিয় — Attendance দেওয়া যায় না।
//     সার্ভারও সেভের সময় একই নিয়ম মানে (ব্রাউজার এড়িয়ে পাঠালেও আটকে যায়)।

require_once __DIR__ . '/exam_elig_grid.php';
if (!function_exists('ea_h')) { function ea_h($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); } }

if (!function_exists('ea_part_subjects')) {
// এই Class+Term+Part-এ Assign করা Subject (Full Mark (Exam) > 0), Rank অনুযায়ী
function ea_part_subjects(PDO $pdo, int $class_id, int $term_id, int $part_id, int $group_id = 0): array
{
    require_once __DIR__ . '/subject_rank.php'; subject_rank_ensure($pdo);
    try {
        $s = $pdo->prepare(
            "SELECT s.id, s.subject_name, s.group_id FROM exam_assign_subjects eas
             JOIN academic_subjects s ON s.id = eas.subject_id
             WHERE eas.class_id=? AND eas.term_id=? AND eas.part_id=? AND eas.status='Active' AND eas.full_mark_exam > 0 AND s.status='Active'
             ORDER BY s.sort_order ASC, s.id ASC"
        );
        $s->execute([$class_id, $term_id, $part_id]);
        $rows = $s->fetchAll();
        if ($group_id > 0) { $rows = array_values(array_filter($rows, function ($r) use ($group_id) { $g = (int) ($r['group_id'] ?? 0); return $g === 0 || $g === $group_id; })); }
        return $rows;
    } catch (Throwable $e) { return []; }
}
}

if (!function_exists('ea_eligibility_map')) {
// [student_id][subject_id] = 0/1 (রেকর্ড না থাকলে কী নেই) এবং রেকর্ডের মোট সংখ্যা
function ea_eligibility_map(PDO $pdo, int $term_id, int $class_id, int $shift_id, int $section_id): array
{
    $map = [];
    try {
        $s = $pdo->prepare("SELECT student_id, subject_id, is_eligible FROM exam_eligibility WHERE term_id=? AND class_id=? AND shift_id=? AND section_id=?");
        $s->execute([$term_id, $class_id, $shift_id, $section_id]);
        foreach ($s->fetchAll() as $r) { $map[(int) $r['student_id']][(int) $r['subject_id']] = (int) $r['is_eligible']; }
    } catch (Throwable $e) {}
    return $map;
}
}

if (!function_exists('ea_save')) {
// ফেরত: [শিক্ষার্থী সংখ্যা, সেভ হওয়া এন্ট্রি, বাদ পড়া (Eligible নয়/Assign নেই) ঘর]
function ea_save(PDO $pdo, int $session_id, int $term_id, int $part_id, int $class_id, int $shift_id, int $section_id, array $student_ids, array $subject_ids, array $present_map): array
{
    $student_ids = result_scope_students($pdo, $student_ids, $class_id, $shift_id, $section_id);
    $subject_ids = result_scope_subjects($pdo, $subject_ids, $class_id);
    $assigned = [];
    foreach (ea_part_subjects($pdo, $class_id, $term_id, $part_id) as $s) { $assigned[(int) $s['id']] = true; }
    $elig = ea_eligibility_map($pdo, $term_id, $class_id, $shift_id, $section_id);
    require_once __DIR__ . '/subject_religion.php';
    $sub_rel = sr_subject_map($pdo, $subject_ids);
    $stu_rel = sr_student_religion($pdo, $student_ids);

    $ins = $pdo->prepare(
        "INSERT INTO exam_attendance_subject (session_id, term_id, part_id, subject_id, class_id, shift_id, section_id, student_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)"
    );
    $del = $pdo->prepare("DELETE FROM exam_attendance_subject WHERE term_id=? AND part_id=? AND subject_id=? AND student_id=?");
    $count = 0; $blocked = 0;
    foreach ($student_ids as $stid) {
        foreach (array_unique(array_map('intval', $subject_ids)) as $sid) {
            if (empty($assigned[$sid]) || empty($elig[$stid][$sid]) || !sr_applicable($sub_rel[$sid] ?? '', $stu_rel[$stid] ?? '')) { $del->execute([$term_id, $part_id, $sid, $stid]); $blocked++; continue; }
            $status = (isset($present_map[$stid][$sid]) && $present_map[$stid][$sid] === '1') ? 'Present' : 'Absent';
            $ins->execute([$session_id, $term_id, $part_id, $sid, $class_id, $shift_id, $section_id, $stid, $status]);
            $count++;
        }
    }
    return [count($student_ids), $count, $blocked];
}
}

if (!function_exists('ea_render_grid')) {
function ea_render_grid(PDO $pdo, int $sel_class, int $sel_shift, int $sel_section, int $sel_term, int $sel_part, int $sel_group = 0): void
{
    if (!($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0 && $sel_part > 0)) {
        echo '<div class="text-center text-muted py-4">উপরে Class, Shift, Section, Exam Term এবং Exam Part সিলেক্ট করুন — তাহলে স্টুডেন্ট লিস্ট এখানে দেখাবে।</div>';
        return;
    }
    $groups = ee_groups_of_class($pdo, $sel_class);
    if ($groups && $sel_group <= 0) { echo ea_group_need_msg(); return; }
    if ($groups && !isset($groups[$sel_group])) { echo ea_group_need_msg(); return; }
    $subjects = ea_part_subjects($pdo, $sel_class, $sel_term, $sel_part, $groups ? $sel_group : 0);
    if (!$subjects) {
        echo '<div class="alert alert-warning text-center py-3 mb-0">এই Exam Part-এ এই ক্লাসের কোনো Subject <b>Assign করা নেই</b> — তাই Attendance দেওয়া যাবে না।<br><span class="small">আগে Result Module &gt; Exam Settings &gt; <b>Assign Exams</b> থেকে এই Class + Term + Part-এ Subject Assign করুন।</span></div>';
        return;
    }

    $where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $params = [$sel_class, $sel_shift, $sel_section];
    if ($groups) { $where[] = 'group_id=?'; $params[] = $sel_group; }
    branch_apply_filter($where, $params);
    $stu_stmt = $pdo->prepare("SELECT id, roll_no, name_en, religion FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $stu_stmt->execute($params);
    $students = $stu_stmt->fetchAll();
    if (!$students) { echo '<div class="text-center text-muted py-4">এই Class/Shift/Section এ কোনো Active Student পাওয়া যায়নি।</div>'; return; }
    require_once __DIR__ . '/subject_religion.php';
    $sub_rel = sr_subject_map($pdo, array_column($subjects, 'id'));

    $elig = ea_eligibility_map($pdo, $sel_term, $sel_class, $sel_shift, $sel_section);
    $elig_saved = !empty($elig);

    $existing = [];
    $ex = $pdo->prepare("SELECT student_id, subject_id, status FROM exam_attendance_subject WHERE term_id=? AND part_id=? AND class_id=? AND shift_id=? AND section_id=?");
    $ex->execute([$sel_term, $sel_part, $sel_class, $sel_shift, $sel_section]);
    foreach ($ex->fetchAll() as $r) { $existing[(int) $r['student_id']][(int) $r['subject_id']] = $r['status']; }

    // এই গ্রিডে অন্তত একটা ঘর খোলা আছে কি
    $any_open = false;
    foreach ($students as $stu) { foreach ($subjects as $sub) { if (!empty($elig[(int) $stu['id']][(int) $sub['id']]) && sr_applicable($sub_rel[(int) $sub['id']] ?? '', $stu['religion'] ?? '')) { $any_open = true; break 2; } } }
    ?>
    <?php if (!$elig_saved): ?>
        <div class="alert alert-danger py-2 small"><b>Exam Eligibility সেভ করা নেই।</b> Eligibility ছাড়া Attendance দেওয়া যায় না — আগে Result Module &gt; Exam Procedure &gt; <b>Exam Eligibility</b> থেকে এই Class/Shift/Section ও Term-এর Eligibility সেভ করুন।</div>
    <?php elseif (!$any_open): ?>
        <div class="alert alert-warning py-2 small">এই Part-এর কোনো Subject-এ কোনো শিক্ষার্থী Eligible নয় — তাই Attendance দেওয়ার মতো ঘর নেই।</div>
    <?php else: ?>
        <div class="small text-muted mb-2">ধূসর "—" ঘর = ঐ Subject-এ শিক্ষার্থী Eligible নয় (Exam Eligibility অনুযায়ী), তাই Attendance দেওয়া যাবে না।</div>
    <?php endif; ?>

    <form method="POST" action="index.php?page=result_attendance_exam">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
        <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
        <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
        <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
        <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
        <input type="hidden" name="group_id" value="<?php echo (int) $sel_group; ?>">
        <?php foreach ($subjects as $sub): ?>
            <input type="hidden" name="grid_subject_id[]" value="<?php echo (int) $sub['id']; ?>">
        <?php endforeach; ?>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th>Roll</th>
                        <th class="text-start">Name</th>
                        <?php foreach ($subjects as $sub): ?>
                            <th>
                                <div class="small text-muted mb-1">All</div>
                                <input type="checkbox" class="form-check-input axcol-all" data-subject="<?php echo (int) $sub['id']; ?>" onclick="toggleAxColumn(<?php echo (int) $sub['id']; ?>, this)">
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($students as $stu): $stid = (int) $stu['id']; ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo ea_h($stu['roll_no']); ?></td>
                            <td class="text-start"><?php echo ea_h($stu['name_en']); ?></td>
                            <input type="hidden" name="student_id[]" value="<?php echo $stid; ?>">
                            <?php foreach ($subjects as $sub):
                                $sid = (int) $sub['id'];
                                $open = !empty($elig[$stid][$sid]) && sr_applicable($sub_rel[$sid] ?? '', $stu['religion'] ?? '');
                                $checked = $open && (($existing[$stid][$sid] ?? 'Absent') === 'Present');
                            ?>
                                <?php if ($open): ?>
                                    <td>
                                        <div class="small text-muted mb-1"><?php echo ea_h($sub['subject_name']); ?></div>
                                        <input type="checkbox" class="form-check-input ax-cb ax-col-<?php echo $sid; ?>" name="present[<?php echo $stid; ?>][<?php echo $sid; ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                    </td>
                                <?php else: ?>
                                    <td class="bg-light text-muted" title="Exam Eligibility নেই — Attendance দেওয়া যাবে না">
                                        <div class="small mb-1" style="opacity:.55;text-decoration:line-through;"><?php echo ea_h($sub['subject_name']); ?></div>
                                        <span>—</span>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center mt-4">
            <button type="submit" name="save_attendance" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;" <?php echo $any_open ? '' : 'disabled'; ?>>Save Attendance</button>
        </div>
    </form>
    <?php
}
}

/**
 * Attendance (Subject) gate: returns ['assigned'=>bool,'has_elig'=>bool,'ids'=>[eligible student ids]]
 */
function ea_subject_gate(PDO $pdo, int $class, int $term, int $part, int $subject, int $shift, int $section): array
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM exam_assign_subjects WHERE class_id=? AND term_id=? AND part_id=? AND subject_id=? AND full_mark_exam > 0");
    $st->execute([$class, $term, $part, $subject]);
    $assigned = ((int) $st->fetchColumn()) > 0;
    $st = $pdo->prepare("SELECT student_id, is_eligible FROM exam_eligibility WHERE term_id=? AND class_id=? AND shift_id=? AND section_id=? AND subject_id=?");
    $st->execute([$term, $class, $shift, $section, $subject]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    require_once __DIR__ . '/subject_religion.php';
    $sub_rel = sr_subject_map($pdo, [$subject])[$subject] ?? '';
    $cand = [];
    foreach ($rows as $r) { if ((int) $r['is_eligible'] === 1) $cand[] = (int) $r['student_id']; }
    $stu_rel = $sub_rel !== '' ? sr_student_religion($pdo, $cand) : [];
    foreach ($cand as $c) { if (sr_applicable($sub_rel, $stu_rel[$c] ?? '')) $ids[$c] = true; } // ভিন্ন ধর্মের শিক্ষার্থী বাদ
    return ['assigned' => $assigned, 'has_elig' => count($rows) > 0, 'ids' => $ids];
}

function ea_subject_msg(array $gate): string
{
    if (!$gate['assigned']) return '<div class="alert alert-warning m-0">এই Subject এই Exam Part-এ <b>Assign Exams</b>-এ দেওয়া নেই — তাই Attendance নেওয়া যাবে না।</div>';
    if (!$gate['has_elig']) return '<div class="alert alert-danger m-0">এই Subject-এর জন্য <b>Exam Eligibility</b> সেভ করা নেই — Eligibility ছাড়া Attendance নেওয়া যাবে না।</div>';
    return '<div class="alert alert-warning m-0">এই Subject-এ কোনো শিক্ষার্থী Eligible নয় — Attendance নেওয়ার কেউ নেই।</div>';
}

if (!function_exists('ea_group_need_msg')) {
function ea_group_need_msg(string $what = 'Attendance'): string
{
    return '<div class="alert alert-warning text-center py-3 mb-0">এই ক্লাসে <b>Group</b> আছে — উপরে থেকে <b>Group সিলেক্ট করুন</b>, তাহলে ঐ Group-এর শিক্ষার্থীদের ' . $what . ' দেওয়া যাবে।</div>';
}
}

// ---------------------------------------------------------------------------
// Marks Entry গেট (Exam + Subject দুই পেজ ও Import শেয়ার করে)
// নিয়ম: একজন শিক্ষার্থীর একটা Subject-এ মার্ক এন্ট্রি তখনই খোলা যখন
//   (১) Exam Eligibility-তে ঐ Subject-এ Eligible, এবং
//   (২) Exam Attendance নেওয়া হয়েছে এবং Present।
// Eligibility বা Attendance না দেওয়া থাকলে ঘর বন্ধ ('noelig' / 'noatt'), Absent হলে 'absent'।
// ---------------------------------------------------------------------------
if (!function_exists('ea_mark_states')) {
/**
 * @return array ['has_elig'=>bool, 'has_att'=>bool, 'state'=>[student_id][subject_id] => 'open'|'noelig'|'noatt'|'absent']
 */
function ea_mark_states(PDO $pdo, int $class_id, int $shift_id, int $section_id, int $term_id, int $part_id, array $student_ids, array $subject_ids): array
{
    $student_ids = result_scope_students($pdo, $student_ids, $class_id, $shift_id, $section_id);
    $subject_ids = result_scope_subjects($pdo, $subject_ids, $class_id);
    $elig = ea_eligibility_map($pdo, $term_id, $class_id, $shift_id, $section_id);
    $att = [];
    try {
        $s = $pdo->prepare("SELECT student_id, subject_id, status FROM exam_attendance_subject WHERE term_id=? AND part_id=? AND class_id=? AND shift_id=? AND section_id=?");
        $s->execute([$term_id, $part_id, $class_id, $shift_id, $section_id]);
        foreach ($s->fetchAll() as $r) { $att[(int) $r['student_id']][(int) $r['subject_id']] = $r['status']; }
    } catch (Throwable $e) {}

    // ধর্মভিত্তিক Subject: ভিন্ন ধর্মের শিক্ষার্থীর ঘর সবসময় বন্ধ (Eligibility সেভ না থাকলেও)
    require_once __DIR__ . '/subject_religion.php';
    $sub_rel = sr_subject_map($pdo, $subject_ids);
    $stu_rel = sr_student_religion($pdo, $student_ids);

    $state = []; $has_att = false;
    foreach ($student_ids as $stid) {
        $stid = (int) $stid;
        foreach ($subject_ids as $sid) {
            $sid = (int) $sid;
            if (!sr_applicable($sub_rel[$sid] ?? '', $stu_rel[$stid] ?? '')) { $state[$stid][$sid] = 'norel'; continue; }
            if (empty($elig[$stid][$sid])) { $state[$stid][$sid] = 'noelig'; continue; }
            if (!isset($att[$stid][$sid])) { $state[$stid][$sid] = 'noatt'; continue; }
            $has_att = true;
            $state[$stid][$sid] = ($att[$stid][$sid] === 'Present') ? 'open' : 'absent';
        }
    }
    return ['has_elig' => !empty($elig), 'has_att' => $has_att, 'state' => $state];
}
}

if (!function_exists('ea_mark_state_label')) {
function ea_mark_state_label(string $st): string
{
    switch ($st) {
        case 'norel':  return 'ভিন্ন ধর্ম (N/A)';
        case 'noelig': return 'Eligible নয়';
        case 'noatt':  return 'Attendance নেই';
        case 'absent': return 'Absent';
    }
    return '';
}
}

if (!function_exists('mx_render_exam_form')) {
// Marks Entry (Exam) ফর্ম — result_marks_entry_exam.php ও ajax/result_marks_entry_exam_ajax.php দুটোই এটা ব্যবহার করে
function mx_render_exam_form(PDO $pdo, int $sel_class, int $sel_shift, int $sel_section, int $sel_term, int $sel_part, int $sel_group, array $subjects_list, array $students, array $marks_map): void
{
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $num = function ($v) { return rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); };

    $none_assigned = true;
    foreach ($subjects_list as $sub) { if ($sub['is_assigned']) { $none_assigned = false; break; } }
    if ($none_assigned) {
        echo '<div class="alert alert-warning py-2">এই Term/Part-এর জন্য এখনো কোনো Subject-এ Full Mark সেট করা হয়নি, তাই মার্ক এন্ট্রি করা যাবে না। আগে <a href="index.php?page=result_assign_exams">Assign Exam</a> পেজ থেকে সাবজেক্টগুলোর Full Mark সেট করুন।</div>';
        return;
    }

    $stids = array_map(function ($r) { return (int) $r['id']; }, $students);
    $sids  = array_map(function ($r) { return (int) $r['id']; }, $subjects_list);
    $gate  = ea_mark_states($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_part, $stids, $sids);

    // Eligibility সেভ না থাকলে বা Attendance একদমই নেওয়া না হলে মার্ক এন্ট্রি ফর্মই দেখাবে না
    if (!$gate['has_elig']) {
        echo '<div class="alert alert-danger py-3 mb-0"><b>Exam Eligibility সেভ করা নেই।</b> Eligibility ও Attendance না দিয়ে মার্ক এন্ট্রি করা যায় না — আগে Result Module &gt; Exam Procedure &gt; <b>Exam Eligibility</b> থেকে এই Class/Shift/Section ও Term-এর Eligibility সেভ করুন, তারপর <b>Attendance (Exam)</b> দিন।</div>';
        return;
    }
    if (!$gate['has_att']) {
        echo '<div class="alert alert-danger py-3 mb-0"><b>Exam Attendance নেওয়া হয়নি।</b> Attendance ছাড়া মার্ক এন্ট্রি করা যায় না — আগে Result Module &gt; Exam Procedure &gt; <b>Attendance (Exam)</b> থেকে এই Class/Shift/Section, Term ও Part-এর Attendance সেভ করুন।</div>';
        return;
    }

    $any_open = false;
    foreach ($gate['state'] as $row) { foreach ($row as $st) { if ($st === 'open') { $any_open = true; break 2; } } }
    ?>
    <div class="small text-muted mb-2">ধূসর ঘর = Eligible নয় / Attendance নেওয়া হয়নি / Absent — এসব ঘরে মার্ক দেওয়া যাবে না। শুধু Eligible + Present শিক্ষার্থীর ঘর খোলা।</div>

    <form method="POST" action="index.php?page=result_marks_entry_exam" id="marksForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
        <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
        <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
        <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
        <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
        <input type="hidden" name="group_id" value="<?php echo (int) $sel_group; ?>">

        <div class="table-responsive">
            <table class="table table-bordered align-middle m-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th>Name</th>
                        <th style="width:70px;">Roll</th>
                        <?php foreach ($subjects_list as $sub): ?>
                            <th class="text-center">
                                <?php echo $h($sub['subject_name']); ?><br>
                                <span class="text-warning fw-bold small">(<?php echo $h($num($sub['full_mark'])); ?>/<?php echo $h($num($sub['full_mark'])); ?>)</span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($students as $stu): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="text-primary fw-bold"><?php echo $h($stu['name_en']); ?></td>
                            <td><?php echo $h($stu['roll_no']); ?></td>
                            <?php foreach ($subjects_list as $sub):
                                $sid = (int) $sub['id'];
                                $stid = (int) $stu['id'];
                                $st = $gate['state'][$stid][$sid] ?? 'noelig';
                                $open = ($st === 'open') && !empty($sub['is_assigned']);
                                $existing = $marks_map[$sid][$stid] ?? null;
                            ?>
                                <?php if ($open): ?>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?php echo $h($sub['full_mark']); ?>"
                                               name="marks[<?php echo $sid; ?>][<?php echo $stid; ?>]"
                                               class="form-control form-control-sm text-end mxe-input"
                                               value="<?php echo $existing !== null ? $h($num($existing)) : ''; ?>"
                                               placeholder="0">
                                    </td>
                                <?php else: ?>
                                    <td class="bg-light text-center text-muted small" title="<?php echo $h(ea_mark_state_label($st)); ?> — মার্ক দেওয়া যাবে না">
                                        <?php echo $st === 'absent' ? '<span class="badge bg-danger">Absent</span>' : '<span>— ' . $h(ea_mark_state_label($st)) . '</span>'; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center mt-4">
            <button type="submit" name="save_marks" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;" <?php echo $any_open ? '' : 'disabled'; ?>>Save Marks</button>
        </div>
    </form>
    <?php
}
}

if (!function_exists('mes_render_subject_form')) {
// Marks Entry (Subject) ফর্ম — result_marks_entry_subject.php ও ajax/result_marks_entry_subject_ajax.php শেয়ার করে
function mes_render_subject_form(PDO $pdo, int $sel_class, int $sel_shift, int $sel_section, int $sel_term, int $sel_part, int $sel_subject, int $sel_group, float $full_mark_exam, array $students): void
{
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $fm = rtrim(rtrim(number_format($full_mark_exam, 2), '0'), '.');

    $stids = array_map(function ($r) { return (int) $r['id']; }, $students);
    $gate = ea_mark_states($pdo, $sel_class, $sel_shift, $sel_section, $sel_term, $sel_part, $stids, [$sel_subject]);

    if (!$gate['has_elig']) {
        echo '<div class="alert alert-danger py-3 mb-0"><b>Exam Eligibility সেভ করা নেই।</b> Eligibility ও Attendance না দিয়ে মার্ক এন্ট্রি করা যায় না — আগে Result Module &gt; Exam Procedure &gt; <b>Exam Eligibility</b> সেভ করুন, তারপর <b>Attendance (Subject/Exam)</b> দিন।</div>';
        return;
    }
    if (!$gate['has_att']) {
        echo '<div class="alert alert-danger py-3 mb-0"><b>এই Subject-এর Exam Attendance নেওয়া হয়নি।</b> Attendance ছাড়া মার্ক এন্ট্রি করা যায় না — আগে Result Module &gt; Exam Procedure &gt; <b>Attendance (Subject)</b> বা <b>Attendance (Exam)</b> সেভ করুন।</div>';
        return;
    }
    $any_open = false;
    foreach ($students as $stu) { if (($gate['state'][(int) $stu['id']][$sel_subject] ?? '') === 'open') { $any_open = true; break; } }
    ?>
    <div class="small text-muted mb-2">ধূসর ঘর = Eligible নয় / Attendance নেওয়া হয়নি / Absent — এসব ঘরে মার্ক দেওয়া যাবে না।</div>
    <form method="POST" action="index.php?page=result_marks_entry_subject" id="marksForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
        <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
        <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
        <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
        <input type="hidden" name="part_id" value="<?php echo $sel_part; ?>">
        <input type="hidden" name="subject_id" value="<?php echo $sel_subject; ?>">
        <input type="hidden" name="group_id" value="<?php echo (int) $sel_group; ?>">
        <input type="hidden" name="full_mark_exam" value="<?php echo $h($full_mark_exam); ?>">

        <div class="table-responsive">
            <table class="table table-bordered align-middle m-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">Sl.</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th style="width:90px;">Roll</th>
                        <th style="width:150px;" class="text-end">
                            Mark<br>
                            <span class="text-warning fw-bold small">(<?php echo $h($fm); ?>/<?php echo $h($fm); ?>)</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($students as $stu):
                        $st = $gate['state'][(int) $stu['id']][$sel_subject] ?? 'noelig';
                        if ($st === 'norel') { continue; } // ভিন্ন ধর্মের শিক্ষার্থী এই Subject-এর তালিকায় আসবে না
                    ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="text-primary fw-bold"><?php echo $h($stu['student_id']); ?></td>
                            <td>
                                <?php if (!empty($stu['photo_path'])): ?>
                                    <img src="../<?php echo $h($stu['photo_path']); ?>" style="width:26px;height:26px;object-fit:cover;border-radius:50%;margin-right:6px;">
                                <?php endif; ?>
                                <?php echo $h($stu['name_en']); ?>
                                <?php if ($st === 'absent'): ?><span class="badge bg-danger ms-1">Absent</span><?php endif; ?>
                            </td>
                            <td><?php echo $h($stu['roll_no']); ?></td>
                            <td>
                                <?php if ($st === 'open'): ?>
                                    <input type="number" step="0.01" min="0" max="<?php echo $h($full_mark_exam); ?>"
                                           name="marks[<?php echo (int) $stu['id']; ?>]" class="form-control form-control-sm text-end"
                                           value="<?php echo $stu['saved_mark'] !== null ? $h(rtrim(rtrim(number_format((float) $stu['saved_mark'], 2), '0'), '.')) : ''; ?>"
                                           placeholder="0">
                                <?php else: ?>
                                    <div class="bg-light text-center text-muted small rounded py-1" title="<?php echo $h(ea_mark_state_label($st)); ?> — মার্ক দেওয়া যাবে না">— <?php echo $h(ea_mark_state_label($st)); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-center mt-4">
            <button type="submit" name="save_marks" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;" <?php echo $any_open ? '' : 'disabled'; ?>>Save Marks</button>
        </div>
    </form>
    <?php
}
}
