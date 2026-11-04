<?php
// config/formative_helpers.php - Formative / Class Test হিসাবের শেয়ার্ড ফাংশন (Entry ও Report পেজ ব্যবহার করে)

function fe_num($v): string { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

// Setting (subject+term > subject+সব term > সব subject+term > সব subject+সব term)
function fe_resolve(PDO $pdo, int $sid, int $class_id, int $subject_id, int $term_id): array {
    $setting = null;
    $q = $pdo->prepare("SELECT * FROM formative_settings WHERE session_id=? AND class_id=? AND subject_id=? AND term_id=? AND status='Active' LIMIT 1");
    foreach ([[$subject_id, $term_id], [$subject_id, 0], [0, $term_id], [0, 0]] as [$s, $t]) {
        $q->execute([$sid, $class_id, $s, $t]);
        $row = $q->fetch();
        if ($row) { $setting = $row; break; }
    }
    $components = [];
    $cq = $pdo->prepare("SELECT * FROM formative_components WHERE session_id=? AND class_id=? AND subject_id=? AND status='Active' ORDER BY rank ASC, id ASC");
    foreach ([$subject_id, 0] as $s) {
        $cq->execute([$sid, $class_id, $s]);
        $components = $cq->fetchAll();
        if ($components) { break; }
    }
    $tests = [];
    $tq = $pdo->prepare("SELECT t.* FROM formative_tests t JOIN formative_test_terms x ON x.test_id=t.id AND x.term_id=? WHERE t.session_id=? AND t.class_id=? AND t.subject_id=? AND t.status='Active' ORDER BY t.id ASC");
    foreach ([$subject_id, 0] as $s) {
        $tq->execute([$term_id, $sid, $class_id, $s]);
        $tests = $tq->fetchAll();
        if ($tests) { break; }
    }
    return [$setting, $components, $tests];
}

// একজন ছাত্রের মোট Formative মার্ক। $in = ['c'=>[compId=>score], 't'=>[testId=>score], 'd'=>score|null]। null = কিছু দেওয়া হয়নি।
function fe_compute(array $setting, array $components, array $tests, array $in): ?float {
    $full = (float) $setting['full_mark'];
    $pct = null; // ০–১০০
    if ($setting['mode'] === 'Component') {
        $sum = 0.0; $any = false;
        foreach ($components as $c) {
            $v = $in['c'][$c['id']] ?? null;
            if ($v === null) { continue; }
            $any = true;
            $sum += min(100.0, max(0.0, (float) $v)) * (float) $c['weight_percent'] / 100.0;
        }
        $pct = $any ? $sum : null;
    } elseif ($tests) {
        $ratios = []; $got = 0.0; $max_all = 0.0;
        foreach ($tests as $t) {
            $max_all += (float) $t['max_score'];
            $v = $in['t'][$t['id']] ?? null;
            if ($v === null) { continue; }
            $got += min((float) $t['max_score'], max(0.0, (float) $v));
            $ratios[] = min(1.0, max(0.0, (float) $v / (float) $t['max_score']));
        }
        if ($ratios) {
            if ($setting['aggregate_method'] === 'Sum') {
                $pct = $max_all > 0 ? $got / $max_all * 100 : null;
            } elseif ($setting['aggregate_method'] === 'Best N') {
                rsort($ratios);
                $top = array_slice($ratios, 0, max(1, (int) $setting['best_n']));
                $pct = array_sum($top) / count($top) * 100;
            } else {
                $pct = array_sum($ratios) / count($ratios) * 100;
            }
        }
    } else {
        $v = $in['d'] ?? null;
        $pct = ($v === null || $full <= 0) ? null : min($full, max(0.0, (float) $v)) / $full * 100;
    }
    if ($pct === null) { return null; }
    return round(min($full, $pct / 100.0 * $full), 2);
}

