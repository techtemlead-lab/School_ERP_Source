<?php
/**
 * scholarship_application_print.php - শিক্ষাবৃত্তি আবেদন ফরম প্রিন্ট (পূরণ করা অবস্থায়)
 *
 * স্বতন্ত্র প্রিন্ট পেজ (lesson_plan_print.php-র মতো) — Admin বা সংশ্লিষ্ট Student/Guardian খুলতে পারে:
 *   - Admin   : যেকোনো আবেদন
 *   - Student : শুধু নিজের আবেদন
 */

require_once __DIR__ . '/config/runtime.php';
erp_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/branch_scope.php';
require_once __DIR__ . '/config/permissions.php';
require_once __DIR__ . '/config/scholarship_application_helpers.php';

require_once __DIR__ . '/config/portal_access.php';
$role = erp_portal_link_role(['admin', 'student']);
erp_portal_require_role($pdo, $role, 'scholarship_applications');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

sa_ensure_tables($pdo);
$id = (int) ($_GET['id'] ?? 0);

$sql = sa_select_sql() . " WHERE a.id = ?";
$params = [$id];
[$scope, $scope_params] = erp_portal_student_scope($pdo, $role, 'a.student_id');
$sql .= " AND $scope"; $params = array_merge($params, $scope_params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$a = $stmt->fetch();
if (!$a) { erp_stop(404, 'Application unavailable in this portal.'); }

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch() ?: [];
$branch = null;
if ($a && function_exists('branch_current_id')) {
    try {
        $record_branch = $pdo->prepare('SELECT branch_id FROM students WHERE id=?'); $record_branch->execute([$a['student_id']]);
        $bid = $record_branch->fetchColumn() ?: null;
        if ($bid !== null) {
            $b = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1"); $b->execute([$bid]); $branch = $b->fetch();
        } else {
            $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
        }
    } catch (\Throwable $e) { $branch = null; }
}
$addr_line = $branch ? trim(($branch['address'] ?? '') . ', ' . ($branch['city'] ?? '') . ' ' . ($branch['zip_code'] ?? ''), ', ') : '';
$logo = !empty($inst['logo_path']) ? $inst['logo_path'] : '';

/** পূরণ করা মান — ডটেড আন্ডারলাইনসহ; খালি হলে ফাঁকা লাইন */
function sa_u($v, string $min = '90px'): string
{
    $t = trim((string) $v);
    return '<span class="u" style="min-width:' . $min . '">' . ($t === '' ? '&nbsp;' : sa_e($t)) . '</span>';
}
function sa_box(bool $checked): string { return '<span class="cb">' . ($checked ? '&#10003;' : '&nbsp;') . '</span>'; }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>শিক্ষাবৃত্তি আবেদন<?php echo $a ? ' - ' . sa_e(sa_app_no($a)) : ''; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { font-family: 'Noto Sans Bengali', 'Nirmala UI', 'SolaimanLipi', Arial, sans-serif; background: #eef1f5; padding: 16px; color: #111; }
        .bar { max-width: 210mm; margin: 0 auto 14px; display: flex; justify-content: flex-end; }
        .page { width: 210mm; min-height: 290mm; margin: 0 auto 20px; background: #fff; padding: 8mm; box-sizing: border-box; box-shadow: 0 0 8px rgba(0,0,0,.15); }
        /* সজ্জিত বর্ডার — ফর্মের নকশার মতো X-প্যাটার্ন */
        .frame { position: relative; min-height: 274mm; box-sizing: border-box; padding: 6mm 7mm 5mm; display: flex; flex-direction: column;
                 border: 11px solid transparent;
                 border-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='33' height='33'><g stroke='%23222' stroke-width='1.2' fill='none'><path d='M1 1l9 9M10 1l-9 9M12 1l9 9M21 1l-9 9M23 1l9 9M32 1l-9 9M1 12l9 9M10 12l-9 9M23 12l9 9M32 12l-9 9M1 23l9 9M10 23l-9 9M12 23l9 9M21 23l-9 9M23 23l9 9M32 23l-9 9'/></g></svg>") 11 round; }
        .wm { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: 120mm; max-height: 120mm; object-fit: contain; opacity: .09; z-index: 0; }
        .content { position: relative; z-index: 1; flex: 1 1 auto; display: flex; flex-direction: column; font-size: 13px; line-height: 2; }
        .head { display: flex; align-items: center; gap: 10px; }
        .head .logo { width: 70px; flex: 0 0 70px; } .head .logo img { max-width: 70px; max-height: 70px; }
        .head .t { flex: 1; text-align: center; margin-right: 70px; }
        .head h1 { font-size: 19px; font-weight: 700; margin: 0; line-height: 1.4; }
        .head .sub { font-size: 13px; }
        .row2 { display: flex; justify-content: space-between; margin-top: 4px; }
        .u { display: inline-block; border-bottom: 1px dotted #000; padding: 0 5px; text-align: center; font-weight: 600; line-height: 1.5; }
        .u[style*="70%"] { text-align: left; }
        .cb { display: inline-block; width: 15px; height: 15px; border: 1.2px solid #000; vertical-align: middle; margin: 0 2px; text-align: center; line-height: 12px; font-size: 13px; font-weight: 700; }
        h3 { font-size: 13.5px; font-weight: 700; margin: 6px 0 0; line-height: 1.8; }
        table.kids { width: 100%; border-collapse: collapse; margin: 3px 0 4px; line-height: 1.5; }
        table.kids th, table.kids td { border: 1px solid #000; padding: 3px 6px; font-size: 12.5px; }
        table.kids th { text-align: center; font-weight: 700; }
        .sign { margin-top: 10px; padding-left: 40px; line-height: 2; }
        .office { margin-top: auto; border: 1px solid #333; padding: 6px 10px; font-size: 12px; line-height: 1.8; background: rgba(248,250,252,.9); }
        .office .ttl { font-weight: 700; text-decoration: underline; }
        .foot { display: flex; justify-content: space-between; border-top: 1px solid #5b8db8; margin-top: 8px; padding-top: 4px; font-size: 12px; }
        @media (max-width: 800px) { body { padding: 8px; overflow-x: auto; } }
        @media print { body { background: #fff; padding: 0; } .bar { display: none; } .page { box-shadow: none; margin: 0; padding: 0; width: auto; min-height: 0; } }
        @page { size: A4 portrait; margin: 8mm; }
    </style>
</head>
<body>
<div class="bar"><button type="button" class="btn btn-primary fw-bold" onclick="window.print()">Print / Save PDF</button></div>

<?php if (!$a): ?>
    <div class="page"><div class="alert alert-danger mb-0">এই আবেদন পাওয়া যায়নি, অথবা আপনার এটা দেখার অনুমতি নেই।</div></div>
<?php else:
    $m = sa_status_meta($a);
    $children = sa_children($a['children_json']);
    while (count($children) < 3) { $children[] = ['gender' => '', 'age' => '', 'inst' => '', 'class' => '']; }
    $order = (int) $a['child_order'];
    $rel = sa_relation_label($a['applicant_relation']);
    $session_year = preg_replace('/\s+/', '', (string) ($a['session_name'] ?? ''));
    ?>
<div class="page">
  <div class="frame">
    <?php if ($logo): ?><img src="<?php echo sa_e($logo); ?>" class="wm" alt=""><?php endif; ?>
    <div class="content">
        <div class="head">
            <div class="logo"><?php if ($logo): ?><img src="<?php echo sa_e($logo); ?>" alt=""><?php endif; ?></div>
            <div class="t">
                <h1><?php echo sa_e($inst['institute_name'] ?? ''); ?></h1>
                <div class="sub">শিক্ষাবৃত্তি আবেদন ফরম – <?php echo sa_e($a['session_name'] ?? ''); ?> ইং</div>
            </div>
        </div>

        <div class="row2">
            <div>তারিখ: <?php echo sa_u(date('d/m/Y', strtotime($a['applied_at'])), '130px'); ?></div>
            <div>আবেদন নং <?php echo sa_u(sa_app_no($a), '130px'); ?></div>
        </div>

        <div>বরাবর,<br>অধ্যক্ষ,<br><?php echo sa_e($inst['institute_name'] ?? ''); ?>,<?php echo $addr_line !== '' ? '<br>' . sa_e($addr_line) . '।' : ''; ?></div>
        <div style="margin-top:2px;">বিষয়ঃ শিক্ষাবৃত্তির জন্য আবেদন।</div>

        <div style="text-align:justify;">
            বিনীত নিবেদন এই যে, আমি <?php echo sa_u($a['applicant_name'], '150px'); ?> , <?php echo sa_u($a['name_en'], '140px'); ?> এর <?php echo sa_e($rel); ?>।
            <?php echo sa_u($a['name_en'], '120px'); ?> আমার <?php echo sa_box($order === 1); ?>১ম <?php echo sa_box($order === 2); ?>২য় <?php echo sa_box($order === 3); ?>৩য় <?php if ($order >= 4): ?><?php echo sa_box(true); ?>৪র্থ বা পরবর্তী <?php endif; ?>সন্তান, যে আপনার বিদ্যালয়ে
            <?php echo sa_u($a['session_name'] ?? '', '70px'); ?> শিক্ষাবর্ষে <?php echo sa_u($a['class_name'] ?? '', '100px'); ?> শ্রেণিতে (<?php echo sa_u($a['shift_name'] ?? '', '70px'); ?> শিফট, রোল <?php echo sa_u($a['roll_no'], '40px'); ?>) অধ্যয়নরত।
            আমি পেশায় একজন <?php echo sa_u($a['occupation'] ?? '', '130px'); ?>। আমার যা উপার্জন তা দিয়ে সংসারের ব্যয় মেটানোর পাশাপাশি সন্তানদের লেখাপড়ার ব্যয় মেটানো আমার পক্ষে খুব কষ্টসাধ্য। আমার পরিবারের ও আয়ের একটা সংক্ষিপ্ত বিবরণ নিচে উল্লেখ করা হলঃ
        </div>

        <h3>সন্তান/সন্ততির তথ্যঃ</h3>
        <table class="kids">
            <thead><tr><th style="width:55px;">ক্রমিক</th><th style="width:70px;">লিঙ্গ</th><th style="width:60px;">বয়স</th><th>অধ্যয়নরত প্রতিষ্ঠানের নাম</th><th style="width:150px;">অধ্যয়নরত শ্রেণি</th></tr></thead>
            <tbody>
            <?php foreach ($children as $i => $c): ?>
                <tr><td class="text-center"><?php echo ($c['gender'] . $c['age'] . $c['inst'] . $c['class']) !== '' ? $i + 1 : '&nbsp;'; ?></td>
                    <td class="text-center"><?php echo sa_e($c['gender'] === 'Male' ? 'ছেলে' : ($c['gender'] === 'Female' ? 'মেয়ে' : $c['gender'])); ?></td>
                    <td class="text-center"><?php echo sa_e($c['age']); ?></td><td><?php echo sa_e($c['inst']); ?></td><td><?php echo sa_e($c['class']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>আয়সংক্রান্ত তথ্যঃ</h3>
        <div>মাসিক আয় <?php echo sa_u(number_format((float) $a['monthly_income'], 0), '170px'); ?> টাকা।</div>

        <h3>পারিবারিক তথ্যঃ</h3>
        <div>মোট সদস্য <?php echo sa_u($a['family_members'], '80px'); ?> জন। উপার্জনক্ষম <?php echo sa_u($a['earning_members'], '80px'); ?> জন।</div>

        <h3>ঠিকানাঃ</h3>
        <div>বর্তমান ঠিকানাঃ <?php echo sa_u($a['present_address'], '70%'); ?></div>
        <div>স্থায়ী ঠিকানাঃ <?php echo sa_u($a['permanent_address'], '70%'); ?></div>
        <div>হোয়াটসঅ্যাপঃ <?php echo sa_u($a['whatsapp'] ?? '', '150px'); ?> ইমেইলঃ <?php echo sa_u($a['email'] ?? '', '230px'); ?></div>
        <?php if (trim((string) $a['reason']) !== ''): ?><div style="line-height:1.7;margin-top:4px;"><b>অতিরিক্ত বক্তব্যঃ</b> <?php echo nl2br(sa_e($a['reason'])); ?></div><?php endif; ?>

        <div style="text-align:justify;margin-top:6px;">অতএব, জনাবের কাছে আকুল আবেদন এই যে, আমার বর্তমান আর্থিক অসচ্ছলতার কথা বিবেচনা করে আমার সন্তানকে উপবৃত্তি প্রদানের মাধ্যমে লেখাপড়া চালিয়ে যাওয়ার ব্যবস্থা প্রদানে আপনার কাছে আন্তরিকভাবে অনুরোধ করছি।</div>

        <div class="sign">
            বিনীত<br>
            &nbsp;&nbsp;&nbsp;আপনার একান্ত অনুগত<br>
            <?php echo sa_u($a['applicant_name'], '200px'); ?> এর <?php echo sa_e($rel); ?>।<br>
            নাম <?php echo sa_u($a['applicant_name'], '230px'); ?>।<br>
            মোবাইল <?php echo sa_u($a['mobile'], '210px'); ?>।
        </div>

        <div class="office">
            <div class="ttl">অফিস কর্তৃক পূরণীয়</div>
            অবস্থাঃ <b><?php echo sa_e($m['label']); ?></b>
            <?php if ($a['status'] === 'Approved'): ?>
                · <?php echo sa_e($a['type_name'] ?? 'শিক্ষাবৃত্তি'); ?> · পরিমাণ ৳<?php echo number_format((float) $a['approved_amount'], 2); ?> · ধরন: <?php echo sa_e(sa_mode_label($a['settle_mode'])); ?>
                <?php if (($a['award_status'] ?? '') === 'Disbursed'): ?> · সমন্বয় ৳<?php echo number_format((float) $a['adjusted_amount'], 2); ?>, নগদ ৳<?php echo number_format((float) $a['cash_amount'], 2); ?> (রিসিপ্ট <?php echo sa_e($a['disb_receipt']); ?>)<?php endif; ?>
            <?php endif; ?>
            <?php if ($a['reviewed_at']): ?> · তারিখ <?php echo sa_e(date('d/m/Y', strtotime($a['reviewed_at']))); ?><?php endif; ?>
            <?php if (trim((string) $a['admin_remarks']) !== ''): ?><br>মন্তব্যঃ <?php echo sa_e($a['admin_remarks']); ?><?php endif; ?>
            <div style="text-align:right;margin-top:14px;"><span style="border-top:1px solid #000;padding:0 20px;">অধ্যক্ষ / কর্তৃপক্ষের স্বাক্ষর</span></div>
        </div>

        <div class="foot">
            <span>&#9742; <?php echo sa_e($inst['phone_number'] ?? ''); ?></span>
            <span><?php echo sa_e($inst['website'] ?? ''); ?></span>
            <span><?php echo sa_e($inst['email'] ?? ''); ?></span>
        </div>
    </div>
  </div>
</div>
<?php endif; ?>
</body>
</html>
