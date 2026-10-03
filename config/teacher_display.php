<?php
/**
 * config/teacher_display.php
 * -----------------------------------------------------------
 * ওয়েবসাইটে (হোমপেজ index.php + teachers.php) Teacher/Staff দেখানোর কমন কোড।
 *
 * Home Page Settings-এর "Show School/College Section Filter" (sms_settings.show_section_filter)
 *   OFF → ফিল্টার: All | Teacher | Staff                  (ফ্ল্যাট গ্রিড)
 *   ON  → ফিল্টার: All | School Section | College Section  (সেকশনের ভেতরে Teacher ও Staff আলাদা হেডিং-এ)
 *
 * employees.section_level: 'School' | 'College' | 'Both' | ফাঁকা
 *   Both = দুই সেকশনেই দেখাবে। ফাঁকা (পুরনো ডেটা) = শুধু "All"-এ দেখাবে।
 * সাজানো হয় rank অনুযায়ী (ছোট rank আগে)।
 */

// Teacher/Staff কোয়েরির ORDER BY — Teachers আগে, তারপর Staffs; প্রতিটার ভেতরে rank (ফাঁকা rank সবার শেষে), তারপর নাম
const TD_ORDER_BY = "FIELD(e.type, 'Teachers', 'Staffs'), (e.`rank` IS NULL OR e.`rank` = 0), e.`rank` ASC, e.name ASC";

function td_sections_of(array $t): array
{
    $s = $t['section_level'] ?? '';
    if ($s === 'Both') return ['school', 'college'];
    if ($s === 'School') return ['school'];
    if ($s === 'College') return ['college'];
    return [];
}

function td_card(array $t, array $o, bool $with_badge): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $role = $t['type'] === 'Teachers' ? 'teacher' : 'staff';
    $secs = td_sections_of($t);
    $photo = !empty($t['photo_path']) ? $h($t['photo_path']) : 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
    $out = '<div class="teacher-card" data-role="' . $role . '">';
    $out .= '<img src="' . $photo . '" alt="' . $h($t['name']) . '" onerror="this.src=\'https://cdn-icons-png.flaticon.com/512/3135/3135715.png\'">';
    $out .= '<h5>' . $h($t['name']) . '</h5>';
    $out .= '<p class="designation">' . $h($t['designation_title'] ?? '') . '</p>';
    if ($with_badge && $secs) {
        $lbl = count($secs) === 2 ? t('sec_school_branch') . ' + ' . t('sec_college_branch')
            : ($secs[0] === 'school' ? t('sec_school_branch') : t('sec_college_branch'));
        $out .= '<p class="teacher-meta"><span class="sec-badge">' . $h($lbl) . '</span></p>';
    }
    if ($o['show_mobile'] && !empty($t['mobile'])) $out .= '<p class="teacher-meta">📞 ' . $h($t['mobile']) . '</p>';
    if ($o['show_email'] && !empty($t['email'])) $out .= '<p class="teacher-meta">✉️ ' . $h($t['email']) . '</p>';
    if ($o['show_address'] && !empty($t['present_address'])) $out .= '<p class="teacher-meta">📍 ' . $h($t['present_address']) . '</p>';
    return $out . '</div>';
}

/** ফিল্টার বাটনের সারি */
function td_filters(array $o): string
{
    $init = $o['init_section'];
    if ($o['section_mode']) {
        $b = fn($key, $label) => '<button type="button" class="filter-btn' . ($init === $key ? ' active' : '') . '" onclick="tdFilter(\'' . $key . '\', this)">' . htmlspecialchars($label) . '</button>';
        return '<div class="teacher-filter-btns">' . $b('all', t('filter_all')) . $b('school', t('sec_school_branch')) . $b('college', t('sec_college_branch')) . '</div>';
    }
    $b = fn($key, $label) => '<button type="button" class="filter-btn' . ($key === 'all' ? ' active' : '') . '" onclick="tdFilter(\'' . $key . '\', this)">' . htmlspecialchars($label) . '</button>';
    return '<div class="teacher-filter-btns">' . $b('all', t('filter_all')) . $b('teacher', t('filter_teacher')) . $b('staff', t('filter_staff')) . '</div>';
}

/** গ্রিড(গুলো) + JS। $o['limit'] > 0 হলে প্রতিটা গ্রিডে সর্বোচ্চ ততটা কার্ড (হোমপেজের জন্য) */
function td_grids(array $teachers, array $o): string
{
    $limit = (int) ($o['limit'] ?? 0);
    $cut = fn(array $a) => $limit > 0 ? array_slice($a, 0, $limit) : $a;
    $empty = '<div class="tl-empty" style="grid-column:1/-1;text-align:center;color:#94a3b8;">' . htmlspecialchars(t('no_teacher_staff')) . '</div>';
    $grid = function (array $list, string $id = '') use ($o, $cut, $empty) {
        $list = $cut($list);
        $html = '<div class="teachers-grid"' . ($id ? ' id="' . $id . '"' : '') . '>';
        foreach ($list as $t) $html .= td_card($t, $o, $o['section_mode']);
        return $html . ($list ? '' : $empty) . '</div>';
    };

    if (!$o['section_mode']) {
        return '<div id="tdFlat">' . $grid($teachers, 'teachersGrid') . '</div>' . td_script(false, 'all');
    }

    $out = '<div class="td-panel" data-panel="all"' . ($o['init_section'] === 'all' ? '' : ' style="display:none"') . '>' . $grid($teachers, 'teachersGrid') . '</div>';
    foreach (['school' => 'sec_school_branch', 'college' => 'sec_college_branch'] as $key => $lbl) {
        $in = array_values(array_filter($teachers, fn($t) => in_array($key, td_sections_of($t), true)));
        $tch = array_values(array_filter($in, fn($t) => $t['type'] === 'Teachers'));
        $stf = array_values(array_filter($in, fn($t) => $t['type'] !== 'Teachers'));
        $out .= '<div class="td-panel" data-panel="' . $key . '"' . ($o['init_section'] === $key ? '' : ' style="display:none"') . '>';
        if (!$in) {
            $out .= $empty;
        } else {
            if ($tch) $out .= '<h4 class="td-group-title">' . htmlspecialchars(t('filter_teacher')) . '</h4>' . $grid($tch);
            if ($stf) $out .= '<h4 class="td-group-title">' . htmlspecialchars(t('filter_staff')) . '</h4>' . $grid($stf);
        }
        $out .= '</div>';
    }
    return $out . td_script(true, $o['init_section']);
}

function td_script(bool $section_mode, string $init): string
{
    return '<script>
    function tdFilter(key, btn) {
        document.querySelectorAll(".teacher-filter-btns .filter-btn").forEach(function (b) { b.classList.remove("active"); });
        btn.classList.add("active");
        ' . ($section_mode ? '
        document.querySelectorAll(".td-panel").forEach(function (p) { p.style.display = p.getAttribute("data-panel") === key ? "" : "none"; });
        var more = document.getElementById("tdSeeAll");
        if (more) more.href = "teachers.php" + (key === "all" ? "" : "?section=" + key);
        ' : '
        document.querySelectorAll("#teachersGrid .teacher-card").forEach(function (c) {
            c.style.display = (key === "all" || c.getAttribute("data-role") === key) ? "" : "none";
        });
        ') . '
    }
    </script>
    <style>
    .td-group-title { margin: 18px 0 10px; font-size: 16px; font-weight: 700; color: #1e3a8a; border-left: 4px solid #1e3a8a; padding-left: 8px; }
    .td-panel .teachers-grid { margin-bottom: 8px; }
    </style>';
}
