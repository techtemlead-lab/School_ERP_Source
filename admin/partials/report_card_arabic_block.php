<?php
// admin/partials/report_card_arabic_block.php
// রিপোর্ট কার্ডে আলাদা "Arabic Result" ব্লক (Position, Department, Monthly/Formative/Exam..., Total, Letter Grade, Grade Point)।
// result_students_report_card.php-এর দুই টেমপ্লেট (Govt / Classic) থেকেই include হয়।
// দরকারি ভ্যারিয়েবল: $rep['arabic'] (rc_student_result_mode() থেকে), $rep['arabic_position'], $student, $parts (exam_parts লিস্ট)
$ab = $rep['arabic'] ?? null;
if ($ab):
    $ab_part_names = [];
    foreach ($parts as $pp) { $ab_part_names[(int) $pp['id']] = $pp['part_name']; }
    $ab_part_ids = array_keys($ab['parts']);
    sort($ab_part_ids);
    $ab_fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2), '0'), '.'); };
    $ab_names = implode(', ', array_map(function ($r) { return $r['name']; }, $ab['rows']));
    $ab_dept = $student['group_name'] ?? '';
?>
<table class="rc-arabic-table" style="width:100%; border-collapse:collapse; margin-top:12px; font-size:13px;">
    <thead>
        <tr style="background:#f1f1f1;">
            <th style="border:1px solid #333; padding:6px; text-align:left; width:18%;" rowspan="2">Arabic Result<br><small style="font-weight:normal;"><?php echo rcv($ab_names); ?></small></th>
            <th style="border:1px solid #333; padding:6px;">Position</th>
            <th style="border:1px solid #333; padding:6px;">Department</th>
            <?php foreach ($ab_part_ids as $apid): ?>
                <th style="border:1px solid #333; padding:6px;"><?php echo rcv($ab_part_names[$apid] ?? 'Part'); ?></th>
            <?php endforeach; ?>
            <th style="border:1px solid #333; padding:6px;">Total</th>
            <th style="border:1px solid #333; padding:6px;">Letter Grade</th>
            <th style="border:1px solid #333; padding:6px;">Grade Point</th>
        </tr>
    </thead>
    <tbody>
        <tr style="text-align:center;">
            <td style="border:1px solid #333; padding:6px;"><?php echo (!$ab['fail'] && ($rep['arabic_position'] ?? null) !== null) ? (int) $rep['arabic_position'] : '-'; ?></td>
            <td style="border:1px solid #333; padding:6px;"><?php echo rcv($ab_dept !== '' ? $ab_dept : '-'); ?></td>
            <?php foreach ($ab_part_ids as $apid): ?>
                <td style="border:1px solid #333; padding:6px;"><?php echo isset($ab['part_marks'][$apid]) ? $ab_fmt($ab['part_marks'][$apid]) : '-'; ?></td>
            <?php endforeach; ?>
            <td style="border:1px solid #333; padding:6px;"><?php echo $ab_fmt($ab['obtained_total']); ?></td>
            <td style="border:1px solid #333; padding:6px; <?php echo $ab['fail'] ? 'color:#dc2626; font-weight:700;' : ''; ?>"><?php echo $ab['fail'] ? 'F' : rcv($ab['gl']); ?></td>
            <td style="border:1px solid #333; padding:6px;"><?php echo number_format($ab['fail'] ? 0 : $ab['gp'], 2); ?></td>
        </tr>
    </tbody>
</table>
<?php endif; ?>
