<?php
require_once __DIR__ . '/result_scope.php';
// config/exam_elig_grid.php — Exam Eligibility গ্রিড (Group-wise): result_exam_eligibility.php ও ajax/result_exam_eligibility_ajax.php-এর শেয়ার্ড অংশ
//
// Group-ওয়ালা Class (যেমন Nine/Ten): প্রতিটা Subject-এর group_id আছে। একজন শিক্ষার্থী শুধু নিজের Group-এর Subject
// (এবং Group-ছাড়া কমন Subject)-এ Eligible হতে পারে। অন্য Group-এর Subject-এ ঘর ধূসর/নিষ্ক্রিয় ("—") থাকে এবং সেভের সময় 0 ধরা হয়।
// Group ফিল্টার বাছলে শুধু সেই Group-এর শিক্ষার্থী ও সেই Group + কমন Subject-এর কলাম দেখায়।

if (!function_exists('ee_h')) { function ee_h($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); } }

if (!function_exists('ee_groups_of_class')) {
// [group_id => group_name]
function ee_groups_of_class(PDO $pdo, int $class_id): array
{
    try {
        $s = $pdo->prepare("SELECT id, group_name FROM academic_groups WHERE class_id=? AND status='Active' ORDER BY id ASC");
        $s->execute([$class_id]);
        $out = [];
        foreach ($s->fetchAll() as $g) { $out[(int) $g['id']] = $g['group_name']; }
        return $out;
    } catch (Throwable $e) { return []; }
}
}

if (!function_exists('ee_applicable')) {
// এই শিক্ষার্থী কি এই Subject-এ পরীক্ষা দিতে পারে? (Subject Group-ছাড়া / শিক্ষার্থীর Group নেই / Group মিলেছে)
function ee_applicable(array $sub, array $stu): bool
{
    $sg = (int) ($sub['group_id'] ?? 0);
    $tg = (int) ($stu['group_id'] ?? 0);
    if (!($sg === 0 || $tg === 0 || $sg === $tg)) { return false; }
    require_once __DIR__ . '/subject_religion.php';
    return sr_applicable($sub['religion'] ?? '', $stu['religion'] ?? ''); // ধর্মভিত্তিক Subject
}
}

if (!function_exists('ee_subject_color_class')) {
function ee_subject_color_class($sub): string
{
    if ($sub['subject_type'] === 'Compulsory') return 'sub-main';
    if ($sub['subject_type'] === 'Optional' && empty($sub['group_id'])) return 'sub-classwise';
    return 'sub-studentwise';
}
}

if (!function_exists('ee_save')) {
// POST সেভ — ফেরত: [শিক্ষার্থী সংখ্যা, এন্ট্রি সংখ্যা]
function ee_save(PDO $pdo, int $session_id, int $term_id, int $class_id, int $shift_id, int $section_id, array $student_ids, array $subject_ids, array $elig_map): array
{
    $student_ids = result_scope_students($pdo, $student_ids, $class_id, $shift_id, $section_id);
    $subject_ids = result_scope_subjects($pdo, $subject_ids, $class_id);
    require_once __DIR__ . '/subject_religion.php';
    sr_ensure($pdo);
    $stu_group = []; $sub_group = []; $stu_rel = []; $sub_rel = [];
    if ($student_ids) {
        $in = implode(',', $student_ids);
        foreach ($pdo->query("SELECT id, group_id, religion FROM students WHERE id IN ($in)")->fetchAll() as $r) { $stu_group[(int) $r['id']] = (int) $r['group_id']; $stu_rel[(int) $r['id']] = (string) $r['religion']; }
    }
    if ($subject_ids) {
        $in = implode(',', $subject_ids);
        foreach ($pdo->query("SELECT id, group_id, religion FROM academic_subjects WHERE id IN ($in)")->fetchAll() as $r) { $sub_group[(int) $r['id']] = (int) $r['group_id']; $sub_rel[(int) $r['id']] = (string) ($r['religion'] ?? ''); }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO exam_eligibility (session_id, term_id, class_id, shift_id, section_id, student_id, subject_id, is_eligible)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_eligible = VALUES(is_eligible)"
    );
    $count = 0;
    foreach ($student_ids as $stid) {
        foreach ($subject_ids as $sid) {
            $ok = ee_applicable(['group_id' => $sub_group[$sid] ?? 0, 'religion' => $sub_rel[$sid] ?? ''], ['group_id' => $stu_group[$stid] ?? 0, 'religion' => $stu_rel[$stid] ?? '']); // অন্য Group/ধর্মের Subject হলে সবসময় 0
            $is_elig = ($ok && isset($elig_map[$stid][$sid]) && $elig_map[$stid][$sid] === '1') ? 1 : 0;
            $stmt->execute([$session_id, $term_id, $class_id, $shift_id, $section_id, $stid, $sid, $is_elig]);
            $count++;
        }
    }
    return [count($student_ids), $count];
}
}

if (!function_exists('ee_render_grid')) {
// গ্রিডের HTML (Class+Shift+Section+Term বাছা হলে); $sel_group = 0 মানে সব Group
function ee_render_grid(PDO $pdo, int $sel_class, int $sel_shift, int $sel_section, int $sel_term, int $sel_group = 0): void
{
    require_once __DIR__ . '/subject_rank.php'; subject_rank_ensure($pdo); // sort_order কলাম নিশ্চিত
    $all_selected = ($sel_class > 0 && $sel_shift > 0 && $sel_section > 0 && $sel_term > 0);
    if (!$all_selected) {
        echo '<div class="text-center text-muted py-4">উপরে Class, Shift, Section এবং Exam Term সিলেক্ট করুন — তাহলে ঐ ক্লাসের সব Student ও Subject এখানে দেখাবে।</div>';
        return;
    }
    require_once __DIR__ . '/subject_religion.php';
    sr_ensure($pdo);
    $groups = ee_groups_of_class($pdo, $sel_class);
    if ($sel_group > 0 && !isset($groups[$sel_group])) { $sel_group = 0; }

    $sub_stmt = $pdo->prepare(
        "SELECT id, subject_name, subject_type, group_id, religion FROM academic_subjects
         WHERE class_id=? AND status='Active'
         ORDER BY (subject_type='Compulsory') DESC, sort_order ASC, id ASC"
    );
    $sub_stmt->execute([$sel_class]);
    $subjects = $sub_stmt->fetchAll();
    if ($sel_group > 0) { // শুধু ঐ Group + কমন (Group-ছাড়া) Subject
        $subjects = array_values(array_filter($subjects, function ($s) use ($sel_group) { return (int) $s['group_id'] === 0 || (int) $s['group_id'] === $sel_group; }));
    }

    $where = ['class_id=?', 'shift_id=?', 'section_id=?', "status='Active'"];
    $params = [$sel_class, $sel_shift, $sel_section];
    if ($sel_group > 0) { $where[] = 'group_id=?'; $params[] = $sel_group; }
    branch_apply_filter($where, $params);
    $stu_stmt = $pdo->prepare("SELECT id, roll_no, name_en, group_id, religion FROM students WHERE " . implode(' AND ', $where) . " ORDER BY (roll_no + 0) ASC, roll_no ASC");
    $stu_stmt->execute($params);
    $students = $stu_stmt->fetchAll();

    $existing = [];
    if ($subjects && $students) {
        $ex = $pdo->prepare("SELECT student_id, subject_id, is_eligible FROM exam_eligibility WHERE term_id=? AND class_id=? AND shift_id=? AND section_id=?");
        $ex->execute([$sel_term, $sel_class, $sel_shift, $sel_section]);
        foreach ($ex->fetchAll() as $r) { $existing[(int) $r['student_id']][(int) $r['subject_id']] = (int) $r['is_eligible']; }
    }

    // ধর্ম ফাঁকা (অচেনা) শিক্ষার্থী — ধর্মভিত্তিক Subject তারা পাবে না, তাই সতর্কতা
    $has_rel_sub = false;
    foreach ($subjects as $sx) { if (sr_norm($sx['religion'] ?? '') !== '') { $has_rel_sub = true; break; } }
    $no_rel = [];
    if ($has_rel_sub) { foreach ($students as $sx) { if (sr_norm($sx['religion'] ?? '') === '') { $no_rel[] = $sx['name_en'] . ' (Roll ' . $sx['roll_no'] . ')'; } } }

    if (!$subjects) { echo '<div class="text-center text-muted py-4">এই ক্লাসের জন্য কোনো Subject পাওয়া যায়নি। প্রথমে Academic Module &gt; Subject থেকে Subject যোগ করুন।</div>'; return; }
    if (!$students) { echo '<div class="text-center text-muted py-4">এই Class/Shift/Section' . ($sel_group > 0 ? '/Group' : '') . ' এ কোনো Active Student পাওয়া যায়নি।</div>'; return; }
    ?>
    <?php if ($no_rel): ?>
        <div class="alert alert-warning py-2 small"><b>ধর্ম দেওয়া নেই (<?php echo count($no_rel); ?> জন):</b> এই শিক্ষার্থীরা ধর্মভিত্তিক Subject (যেমন ইসলাম/হিন্দু ধর্ম শিক্ষা) পাবে না। Student Edit থেকে Religion দিন — <?php echo ee_h(implode(', ', array_slice($no_rel, 0, 8))); ?><?php echo count($no_rel) > 8 ? ' …' : ''; ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-center gap-5 mb-4">
        <div class="elig-legend-item"><span class="sub-th-dot" style="background:#1f2937;"></span><span>(Main subjects)</span></div>
        <div class="elig-legend-item"><span class="sub-th-dot" style="background:#f4a6b8;"></span><span class="text-danger">(Class wise optional subjects)</span></div>
        <div class="elig-legend-item"><span class="sub-th-dot" style="background:#c99a1e;"></span><span style="color:#b8860b;">(Student wise optional subjects)</span></div>
        <?php if ($groups): ?><div class="elig-legend-item"><span class="sub-th-dot" style="background:#e5e7eb;border:1px solid #cbd5e1;"></span><span class="text-muted">(অন্য Group-এর Subject — নিষ্ক্রিয়)</span></div><?php endif; ?>
    </div>

    <form method="POST" action="index.php?page=result_exam_eligibility">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_id" value="<?php echo $sel_class; ?>">
        <input type="hidden" name="shift_id" value="<?php echo $sel_shift; ?>">
        <input type="hidden" name="section_id" value="<?php echo $sel_section; ?>">
        <input type="hidden" name="term_id" value="<?php echo $sel_term; ?>">
        <input type="hidden" name="group_id" value="<?php echo $sel_group; ?>">
        <?php foreach ($subjects as $sub): ?>
            <input type="hidden" name="grid_subject_id[]" value="<?php echo (int) $sub['id']; ?>">
        <?php endforeach; ?>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>Sl.</th>
                        <th class="text-start">Name</th>
                        <th>Roll</th>
                        <th>Group</th>
                        <th>Religion</th>
                        <?php foreach ($subjects as $sub): $cls = ee_subject_color_class($sub); ?>
                            <th class="<?php echo $cls; ?>">
                                <span class="sub-th-dot mb-1" style="cursor:pointer;" title="Select/Unselect All" onclick="toggleColumn(<?php echo (int) $sub['id']; ?>, this)"></span>
                                <?php if (!empty($sub['group_id']) && isset($groups[(int) $sub['group_id']])): ?><div class="small fw-normal text-muted"><?php echo ee_h($groups[(int) $sub['group_id']]); ?></div><?php endif; ?>
                                <?php if (sr_norm($sub['religion'] ?? '') !== ''): ?><div class="small fw-normal text-info"><?php echo ee_h(sr_norm($sub['religion'])); ?></div><?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($students as $stu): $stid = (int) $stu['id']; ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td class="text-start"><?php echo ee_h($stu['name_en']); ?></td>
                            <td><?php echo ee_h($stu['roll_no']); ?></td>
                            <td><?php echo !empty($stu['group_id']) ? ee_h($groups[(int) $stu['group_id']] ?? $stu['group_id']) : 'N/A'; ?></td>
                            <td><?php echo ee_h($stu['religion']); ?></td>
                            <input type="hidden" name="student_id[]" value="<?php echo $stid; ?>">
                            <?php foreach ($subjects as $sub):
                                $sid = (int) $sub['id'];
                                $cls = ee_subject_color_class($sub);
                                $app = ee_applicable($sub, $stu);
                                $checked = $app && (array_key_exists($sid, $existing[$stid] ?? []) ? (bool) $existing[$stid][$sid] : true);
                            ?>
                                <?php if ($app): ?>
                                    <td class="<?php echo $cls; ?>">
                                        <div class="small sub-name mb-1"><?php echo ee_h($sub['subject_name']); ?></div>
                                        <input type="checkbox" class="form-check-input elig-cb elig-col-<?php echo $sid; ?>" name="elig[<?php echo $stid; ?>][<?php echo $sid; ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                                    </td>
                                <?php else: ?>
                                    <td class="bg-light text-muted" title="অন্য Group / ভিন্ন ধর্মের Subject">
                                        <div class="small mb-1" style="opacity:.55;text-decoration:line-through;"><?php echo ee_h($sub['subject_name']); ?></div>
                                        <span>—</span>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <hr>
        <div class="text-center">
            <button type="submit" name="save_eligibility" class="btn btn-primary px-5 fw-bold" style="background-color:#1e3a8a;border-color:#1e3a8a;">Save Eligibility</button>
        </div>
    </form>
    <?php
}
}
