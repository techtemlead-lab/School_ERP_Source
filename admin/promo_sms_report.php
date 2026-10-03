<?php
// admin/promo_sms_report.php - Promotional Module > SMS Details Report
// sms_logs টেবিল থেকে (Absent/Due/Promo/Result/Admission SMS সহ সবগুলোর) ফিল্টার করে রিপোর্ট দেখায়

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        phone VARCHAR(20) NOT NULL,
        purpose VARCHAR(50) NOT NULL DEFAULT 'absent',
        message TEXT NULL,
        status ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
        response TEXT NULL,
        sent_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
require_once __DIR__ . '/../config/whatsapp.php';
wa_ensure_schema($pdo); // sms_logs.channel কলাম self-heal হয়ে যোগ হয়ে যাবে

function psr_esc($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$purpose_labels = [
    'absent'    => 'Absent',
    'due'       => 'Accounts',
    'promo'     => 'Promotional',
    'result'    => 'Result',
    'admission' => 'Admission',
];

$types = $pdo->query("SELECT DISTINCT purpose FROM sms_logs ORDER BY purpose ASC")->fetchAll(PDO::FETCH_COLUMN, 0);

$f_type   = $_GET['f_type'] ?? '';
$f_channel = $_GET['f_channel'] ?? '';
$f_start  = $_GET['f_start'] ?? date('Y-m-01');
$f_end    = $_GET['f_end'] ?? date('Y-m-d');
$f_body   = trim($_GET['f_body'] ?? '');
$f_mobile = trim($_GET['f_mobile'] ?? '');
$submitted = isset($_GET['submit']) || true; // ডিফল্ট রেঞ্জ দিয়ে সবসময় লোড হয়ে থাকবে, screenshot অনুযায়ী

$where = [];
$params = [];
if ($f_type !== '')   { $where[] = 'purpose = ?';    $params[] = $f_type; }
if ($f_channel !== '') { $where[] = 'channel = ?';   $params[] = $f_channel; }
if ($f_start !== '')  { $where[] = 'DATE(created_at) >= ?'; $params[] = $f_start; }
if ($f_end !== '')    { $where[] = 'DATE(created_at) <= ?'; $params[] = $f_end; }
if ($f_body !== '')   { $where[] = 'message LIKE ?';  $params[] = "%$f_body%"; }
if ($f_mobile !== '') { $where[] = 'phone LIKE ?';    $params[] = "%$f_mobile%"; }

$sql = "SELECT * FROM sms_logs";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY created_at DESC LIMIT 1000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$inst = $pdo->query("SELECT institute_name, phone_number FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '']; }
$fr_bid2 = branch_current_id();
if ($fr_bid2 !== null) {
    $branch_stmt2 = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt2->execute([$fr_bid2]);
    $branch = $branch_stmt2->fetch();
} else {
    $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch();
}
$site_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
?>

<div class="card-custom">
    <form method="GET" action="index.php">
        <input type="hidden" name="page" value="promo_sms_report">
        <input type="hidden" name="submit" value="1">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">Sms Type</label>
                <select name="f_type" class="form-select">
                    <option value="">Select Type</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?php echo psr_esc($t); ?>" <?php echo $f_type === $t ? 'selected' : ''; ?>><?php echo psr_esc($purpose_labels[$t] ?? ucfirst($t)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Channel</label>
                <select name="f_channel" class="form-select">
                    <option value="">All (SMS + WhatsApp)</option>
                    <option value="SMS" <?php echo $f_channel === 'SMS' ? 'selected' : ''; ?>>SMS</option>
                    <option value="WhatsApp" <?php echo $f_channel === 'WhatsApp' ? 'selected' : ''; ?>>WhatsApp</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                <input type="date" name="f_start" class="form-control" value="<?php echo psr_esc($f_start); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                <input type="date" name="f_end" class="form-control" value="<?php echo psr_esc($f_end); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Sms Body</label>
                <input type="text" name="f_body" class="form-control" value="<?php echo psr_esc($f_body); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Mobile Number</label>
                <input type="tel" name="f_mobile" class="form-control phone-11" inputmode="numeric" maxlength="11" value="<?php echo psr_esc($f_mobile); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color:#1e3a8a;">Submit</button>
            </div>
        </div>
    </form>
</div>

<div class="card-custom mt-3" id="printArea">
    <div class="d-flex justify-content-end mb-2 no-print">
        <button type="button" class="btn btn-info text-white btn-sm fw-bold" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
    </div>

    <div class="text-center mb-3 pb-3 border-bottom">
        <h5 class="fw-bold text-primary mb-1"><?php echo psr_esc($inst['institute_name']); ?></h5>
        <?php if ($branch && !empty($branch['address'])): ?>
            <div class="small text-muted">(<?php echo psr_esc($branch['address']); ?><?php echo $branch['city'] ? ', ' . psr_esc($branch['city']) : ''; ?>)</div>
        <?php endif; ?>
        <?php if (!empty($inst['phone_number']) || !empty($branch['phone'])): ?>
            <div class="small">Phone: <?php echo psr_esc($inst['phone_number'] ?: $branch['phone']); ?></div>
        <?php endif; ?>
        <div class="small"><a href="<?php echo psr_esc($site_url); ?>" target="_blank"><?php echo psr_esc($site_url); ?></a></div>
        <h6 class="fw-bold mt-3 mb-0">SMS Details Report : <?php echo date('d M Y', strtotime($f_start)); ?> to <?php echo date('d M Y', strtotime($f_end)); ?></h6>
        <div class="text-danger fw-bold mt-1">Total SMS : <?php echo count($rows); ?></div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered align-middle" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th>Sl.</th>
                    <th>Type</th>
                    <th>Channel</th>
                    <th>Mobile No</th>
                    <th>SMS Body</th>
                    <th>SMS Count</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): $srl = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo $srl++; ?></td>
                        <td><?php echo psr_esc($purpose_labels[$row['purpose']] ?? ucfirst($row['purpose'])); ?></td>
                        <td><?php echo ($row['channel'] ?? 'SMS') === 'WhatsApp' ? '<span class="badge bg-success"><i class="fa-brands fa-whatsapp"></i> WhatsApp</span>' : '<span class="badge bg-primary">SMS</span>'; ?></td>
                        <td><?php echo psr_esc($row['phone']); ?></td>
                        <td><?php echo psr_esc($row['message']); ?></td>
                        <td>1</td>
                        <td><?php echo date('d, F (D) Y', strtotime($row['created_at'])); ?></td>
                        <td><?php echo $row['status'] === 'Sent' ? '<span class="badge bg-success">Successful</span>' : '<span class="badge bg-danger">Failed</span>'; ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">এই ফিল্টারে কোনো SMS পাওয়া যায়নি</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { position: absolute; left: 0; top: 0; width: 100%; }
    .no-print { display: none !important; }
}
</style>
<script>
// ফোন নম্বর ফিল্ড (.phone-11 ক্লাস): টাইপ করার সাথে সাথেই সংখ্যা ছাড়া অন্য কিছু নেয় না,
// ১১ ডিজিটের বেশি হলে কেটে দেয়, ১১ ডিজিট না হওয়া পর্যন্ত নিচে লাল মেসেজ দেখায় (রিলোড ছাড়া),
// ১১ ডিজিট হয়ে গেলেই মেসেজ সাথে সাথে সরে যায়
document.querySelectorAll('.phone-11').forEach(function (input) {
    let errEl = input.nextElementSibling;
    if (!errEl || !errEl.classList.contains('phone11-err')) {
        errEl = document.createElement('div');
        errEl.className = 'small text-danger mt-1 d-none phone11-err';
        errEl.textContent = 'মোবাইল নম্বর অবশ্যই ১১ ডিজিটের হতে হবে।';
        input.insertAdjacentElement('afterend', errEl);
    }
    function checkPhone11() {
        let digits = input.value.replace(/\D/g, '');
        if (digits.length > 11) digits = digits.slice(0, 11);
        input.value = digits;
        const invalid = digits.length > 0 && digits.length !== 11;
        errEl.classList.toggle('d-none', !invalid);
        input.classList.toggle('is-invalid', invalid);
    }
    input.addEventListener('input', checkPhone11);
    input.addEventListener('blur', checkPhone11);
});
</script>
