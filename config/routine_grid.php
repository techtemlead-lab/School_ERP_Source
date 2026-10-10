<?php
/**
 * config/routine_grid.php
 * -----------------------------------------------------------
 * সব প্যানেলের (Admin / Teacher / Student / Guardian) রুটিন ভিউ একই চেহারায় দেখানোর শেয়ার্ড রেন্ডারার:
 *
 *      Day | 1st Class | 2nd Class | 3rd Class ...
 *      Saturday | Arabic / শিক্ষক / 8:00 am - 8:28 am | ...
 *
 * ব্যবহার:  echo rg_css();  echo rg_grid_html($week_days, $day_entries, ['show_teacher' => true]);
 *
 * $day_entries: [দিন => [রো, রো...]]; প্রতিটা রো-তে subject_name, teacher_name, start_time, end_time, has_break;
 *               (ঐচ্ছিক) class_name, section_name, shift_name, period_no
 *
 * opts:
 *   show_teacher  (true)   সেলে শিক্ষকের নাম
 *   show_class    (false)  সেলে Class (Section) — শিক্ষকের নিজের রুটিনের জন্য
 *   by_period     (false)  true হলে রো-র period_no অনুযায়ী কলামে বসে (শিক্ষকের ফাঁকা পিরিয়ড বোঝা যায়);
 *                          false হলে শুরুর সময়ের ক্রমে 1st, 2nd... বসে
 *   only_day      ('')     শুধু একটা দিন দেখান
 *   skip_empty    (true)   রুটিন-ছাড়া দিন বাদ
 *   highlight_day ('')     আজকের দিন হালকা হাইলাইট
 *   viewer_religion (null) শিক্ষার্থী/অভিভাবকের ধর্ম — দিলে যে Subject-এর subject_religion মেলে না সেগুলো দেখানো হয় না
 *                          (null = ফিল্টার নেই; অ্যাডমিন/শিক্ষক সব দেখেন এবং সেলে ধর্মের ট্যাগ দেখানো হয়)
 *
 * Parallel Period: একই দিনে একই শুরু-শেষ সময়ের একাধিক রো (যেমন ইসলাম শিক্ষা + হিন্দু ধর্ম শিক্ষা, ভিন্ন শিক্ষক)
 * একই "Nth Class" কলামের একটা সেলে একসাথে দেখায়।
 */

require_once __DIR__ . '/subject_religion.php';

if (!function_exists('rg_e')) {
    function rg_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

function rg_time($t): string
{
    return $t ? strtolower(date('g:i a', strtotime((string) $t))) : '';
}

function rg_ordinal(int $n): string
{
    $suf = 'th';
    if ($n % 100 < 11 || $n % 100 > 13) { $suf = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'; }
    return $n . $suf;
}

/**
 * Parallel Period গ্রুপিং: সময়ের ক্রমে সাজানো রো-তালিকা -> স্লটের তালিকা (প্রতি স্লট = একই সময়ের এক বা একাধিক রো)।
 * সময় নেই / Break রো আলাদা স্লট।
 */
function rg_slots(array $list): array
{
    $slots = []; $idx = [];
    foreach ($list as $e) {
        $is_break = !empty($e['has_break']) && empty($e['subject_name']);
        $k = (!$is_break && !empty($e['start_time'])) ? $e['start_time'] . '|' . ($e['end_time'] ?? '') : null;
        if ($k !== null && isset($idx[$k])) { $slots[$idx[$k]][] = $e; continue; }
        $slots[] = [$e];
        if ($k !== null) { $idx[$k] = count($slots) - 1; }
    }
    return $slots;
}

function rg_css(): string
{
    static $done = false;
    if ($done) { return ''; }
    $done = true;
    return '<style>
    .rg-wrap { overflow-x: auto; }
    table.rg-grid { width: 100%; border-collapse: collapse; background: #fff; color: #6b7280; font-size: 14px; }
    table.rg-grid th, table.rg-grid td { border: 1px solid #dcdcdc; padding: 9px 8px; text-align: center; vertical-align: middle; line-height: 1.55; }
    table.rg-grid thead th { background: #eeeeee; color: #555; font-weight: 700; }
    table.rg-grid tbody tr:first-child td { border-top: 7px solid #dcdcdc; }
    table.rg-grid tbody tr:nth-child(odd) td { background: #f7f7f7; }
    table.rg-grid tbody tr:nth-child(even) td { background: #fcfcfc; }
    table.rg-grid td.rg-day { font-weight: 700; color: #555; width: 130px; }
    table.rg-grid tr.rg-today td { background: #ecfdf5 !important; }
    table.rg-grid .rg-sub { color: #374151; }
    table.rg-grid .rg-cls { color: #0f766e; font-weight: 600; }
    table.rg-grid .rg-rel { color: #0e7490; font-size: 12px; font-weight: 600; }
    table.rg-grid .rg-break { font-weight: 600; color: #92400e; }
    table.rg-grid .rg-item + .rg-item { border-top: 1px dashed #cfcfcf; margin-top: 6px; padding-top: 6px; }
    .rg-title { text-align: center; margin-bottom: 10px; }
    .rg-title h5 { font-weight: 700; margin: 0; }
    .rg-title p { color: #6b7280; margin: 0; }
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; }
        body { background: #fff !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin-bottom: 0 !important; }
        .rg-wrap { overflow: visible !important; }
        table.rg-grid, table.rg-grid th, table.rg-grid td { border-color: #000 !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.rg-grid tr { page-break-inside: avoid; }
        .rg-block { page-break-after: always; }
        .rg-block:last-child { page-break-after: auto; }
    }
    </style>';
}

/** এক সেলের ভেতরের HTML */
function rg_cell_html(array $e, array $o): string
{
    $is_break = !empty($e['has_break']) && empty($e['subject_name']);
    $h = '<div class="rg-item">';
    if ($o['show_class'] && !empty($e['class_name'])) {
        $h .= '<div class="rg-cls">' . rg_e($e['class_name']) . (!empty($e['section_name']) ? ' (' . rg_e($e['section_name']) . ')' : '') . '</div>';
    }
    if ($is_break) {
        $h .= '<div class="rg-break">Break / Tiffin</div>';
    } else {
        $h .= '<div class="rg-sub">' . rg_e($e['subject_name'] ?: '—');
        if (($o['viewer_religion'] ?? null) === null && sr_norm($e['subject_religion'] ?? '') !== '') {
            $h .= ' <span class="rg-rel">(' . rg_e(sr_norm($e['subject_religion'])) . ')</span>';
        }
        $h .= '</div>';
        if ($o['show_teacher'] && !empty($e['teacher_name'])) { $h .= '<div>' . rg_e($e['teacher_name']) . '</div>'; }
    }
    if (!empty($e['start_time'])) {
        $h .= '<div>' . rg_e(rg_time($e['start_time'])) . ($e['end_time'] ? ' - ' . rg_e(rg_time($e['end_time'])) : '') . '</div>';
    }
    return $h . '</div>';
}

function rg_grid_html(array $week_days, array $day_entries, array $opts = []): string
{
    $o = $opts + ['show_teacher' => true, 'show_class' => false, 'by_period' => false, 'only_day' => '', 'skip_empty' => true, 'highlight_day' => '', 'viewer_religion' => null];

    // দিনওয়ারি সেল-তালিকা: [দিন => [কলাম_ইনডেক্স => [রো...]]]
    $grid = []; $max = 0;
    foreach ($week_days as $day) {
        if ($o['only_day'] !== '' && $day !== $o['only_day']) { continue; }
        $list = array_values($day_entries[$day] ?? []);
        if ($o['viewer_religion'] !== null) { // ধর্মভিত্তিক Subject: ভিন্ন ধর্মের শিক্ষার্থীর রুটিনে আসবে না
            $vr = $o['viewer_religion'];
            $list = array_values(array_filter($list, function ($e) use ($vr) { return sr_applicable($e['subject_religion'] ?? '', $vr); }));
        }
        if (!$list && $o['skip_empty']) { continue; }
        usort($list, function ($a, $b) {
            $sa = $a['start_time'] ?: '99:99:99'; $sb = $b['start_time'] ?: '99:99:99';
            return $sa === $sb ? ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0)) : strcmp($sa, $sb);
        });
        $cells = [];
        if ($o['by_period']) {
            foreach ($list as $e) {
                $p = (int) ($e['period_no'] ?? 0);
                if ($p <= 0) { $p = 1; while (isset($cells[$p])) { $p++; } }
                $cells[$p][] = $e;
            }
            if ($cells) { $max = max($max, max(array_keys($cells))); }
        } else {
            foreach (rg_slots($list) as $i => $slot) { foreach ($slot as $e) { $cells[$i + 1][] = $e; } }
            $max = max($max, count($cells));
        }
        $grid[$day] = $cells;
    }
    if (!$grid) { return ''; }
    $max = max($max, 1);

    $h = '<div class="rg-wrap"><table class="rg-grid"><thead><tr><th>Day</th>';
    for ($i = 1; $i <= $max; $i++) { $h .= '<th>' . rg_ordinal($i) . ' Class</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($grid as $day => $cells) {
        $h .= '<tr' . ($o['highlight_day'] === $day ? ' class="rg-today"' : '') . '><td class="rg-day">' . rg_e($day) . '</td>';
        for ($i = 1; $i <= $max; $i++) {
            $h .= '<td>';
            foreach ($cells[$i] ?? [] as $e) { $h .= rg_cell_html($e, $o); }
            $h .= '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}
