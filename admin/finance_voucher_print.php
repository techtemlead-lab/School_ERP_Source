<?php
// admin/finance_voucher_print.php - একটা Voucher-এর প্রিন্টযোগ্য কপি
require_once __DIR__ . '/../config/portal_access.php';
erp_portal_controller_role(['admin']);
erp_portal_require_role($pdo, 'admin', 'finance_voucher_print');
function vpv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
function vp_money($n): string { return number_format((float) $n, 2); }

// টেবিল না থাকলে অটো তৈরি (অন্য কোনো voucher পেজ আগে visit না করলেও যাতে crash না করে)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_vouchers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_no VARCHAR(50) NOT NULL,
        voucher_type VARCHAR(20) NOT NULL,
        transaction_date DATE NOT NULL,
        payment_type VARCHAR(10) NOT NULL DEFAULT 'Cash',
        description TEXT NULL,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_voucher_no (voucher_no),
        KEY idx_type (voucher_type), KEY idx_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS finance_voucher_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        voucher_id INT NOT NULL,
        ac_head_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remarks VARCHAR(255) NULL,
        KEY idx_voucher (voucher_id), KEY idx_head (ac_head_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
function vp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $chk = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $chk->execute([$table, $column]);
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
vp_ensure_column($pdo, 'finance_vouchers', 'cheque_no', "VARCHAR(60) NULL AFTER payment_type");
vp_ensure_column($pdo, 'finance_vouchers', 'party_name', "VARCHAR(150) NULL AFTER cheque_no");

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM finance_vouchers WHERE id = ?");
$stmt->execute([$id]);
$voucher = $stmt->fetch();

$inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch();
if (!$inst) { $inst = ['institute_name' => 'School Name', 'phone_number' => '', 'logo_path' => null]; }

$details = [];
if ($voucher) {
    $d = $pdo->prepare(
        "SELECT vd.*, h.ac_head, h.ac_code FROM finance_voucher_details vd
         LEFT JOIN finance_account_heads h ON h.id = vd.ac_head_id
         WHERE vd.voucher_id = ? ORDER BY vd.id ASC"
    );
    $d->execute([$id]);
    $details = $d->fetchAll();
}

// Money Receipt-এর একই ডিজাইনে প্রিন্ট করার জন্য: ঠিকানা + "In Words" হেল্পার
require_once __DIR__ . '/../config/money_receipt_render.php';
$vp_branch = null;
try {
    $vp_bid = function_exists('branch_current_id') ? branch_current_id() : null;
    if ($vp_bid !== null) { $bs = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1"); $bs->execute([$vp_bid]); $vp_branch = $bs->fetch(); }
    else { $vp_branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch(); }
} catch (\Throwable $e) { $vp_branch = null; }
$vp_addr = $vp_branch ? trim(($vp_branch['address'] ?? '') . ', ' . ($vp_branch['city'] ?? '') . ' ' . ($vp_branch['zip_code'] ?? ''), ', ') : '';
$vp_is_recv = $voucher && $voucher['voucher_type'] === 'Receive';
?>
<style>
    @media print {
        @page { size: A4 portrait; margin: 8mm; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .voucher-print-wrap { box-shadow: none !important; border: none !important; max-width: 100% !important; padding: 0 !important; min-height: 270mm !important; }
        .vp-table tr { page-break-inside: avoid; }
    }
    /* ডিজাইন: Money Receipt-এর সাথে হুবহু মিল (navy পিল, আউটলাইন পিল, বক্সড গ্রিড, হালকা নীল টেবিল হেডার) */
    .voucher-print-wrap { background: #fff; max-width: 800px; margin: 0 auto; padding: 10mm; border: 1px solid #ddd; box-shadow: 0 0 8px rgba(0,0,0,.12);
                          position: relative; overflow: hidden; display: flex; flex-direction: column; min-height: 240mm; font-family: Arial, 'Noto Sans Bengali', sans-serif; color: #111827; }
    .vp-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); max-width: 60%; max-height: 60%; opacity: .07; z-index: 1; }
    .vp-body { position: relative; z-index: 2; flex: 1 1 auto; display: flex; flex-direction: column; }
    .vp-head { display: flex; align-items: flex-start; gap: 10px; }
    .vp-head-logo { width: 56px; flex: 0 0 56px; } .vp-head-logo img { max-width: 56px; max-height: 56px; }
    .vp-head-text { flex: 1 1 auto; text-align: center; }
    .vp-head-text h4 { margin: 0; font-weight: 700; color: #1f2937; font-size: 19px; }
    .vp-inst-line { font-size: 11px; color: #4b5563; }
    .vp-head-qr { width: 56px; flex: 0 0 56px; } .vp-head-qr img, .vp-head-qr canvas { width: 54px !important; height: 54px !important; }
    .vp-tagrow { display: flex; justify-content: space-between; align-items: center; margin: 10px 0 8px; }
    .vp-pill { background: #1e3a5f; color: #fff; font-weight: 700; font-size: 12px; letter-spacing: .6px; padding: 6px 12px; border-radius: 4px; text-transform: uppercase; }
    .vp-pill-out { border: 1.5px solid #1e3a5f; color: #1e3a5f; font-weight: 700; font-size: 11px; letter-spacing: .5px; padding: 4px 12px; border-radius: 999px; text-transform: uppercase; }
    table.vp-grid { width: 100%; border-collapse: collapse; border: 1px solid #d1d5db; margin-bottom: 10px; }
    table.vp-grid td { width: 50%; border: 1px solid #e5e7eb; padding: 5px 8px; vertical-align: top; }
    .vp-lbl { display: block; font-size: 9px; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
    .vp-val { display: block; font-size: 13px; font-weight: 700; color: #111827; word-break: break-word; }
    .vp-table { width: 100%; border-collapse: collapse; border: 1px solid #d1d5db; margin-top: 4px; font-size: 12.5px; }
    .vp-table th { background: #e8eef7; color: #1f2937; border-bottom: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; font-weight: 700; }
    .vp-table td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .vp-table .vp-total td { border-top: 1px solid #9ca3af; font-weight: 700; }
    .vp-table .text-end, .vp-table th.text-end { text-align: right; }
    .vp-words { font-size: 12px; font-style: italic; margin: 8px 0; }
    .vp-desc { border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 10px; font-size: 12.5px; margin-top: 6px; }
    .vp-stamp { position: absolute; bottom: 62mm; right: 20mm; z-index: 3; transform: rotate(-18deg); width: 112px; height: 112px; border: 3px double; border-radius: 50%;
                display: flex; flex-direction: column; align-items: center; justify-content: center; opacity: .4; pointer-events: none; }
    .vp-stamp b { font-size: 17px; letter-spacing: 1px; } .vp-stamp span { font-size: 9px; }
    .vp-sign { display: flex; justify-content: space-between; margin-top: auto; padding-top: 50px; }
    .vp-sign div { border-top: 1px solid #374151; width: 170px; text-align: center; padding-top: 4px; font-size: 12px; font-weight: 700; }
    .vp-foot { text-align: center; font-size: 9px; color: #9ca3af; margin-top: 10px; }
</style>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <a href="index.php?page=finance_vouchers_list" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    <?php if ($voucher): ?>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <?php endif; ?>
</div>

<?php if (!$voucher): ?>
    <div class="alert alert-danger">ভাউচার খুঁজে পাওয়া যায়নি।</div>
<?php else:
    $vp_color = $vp_is_recv ? '#15803d' : '#1e3a5f'; ?>
    <div class="voucher-print-wrap">
        <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo vpv($inst['logo_path']); ?>" class="vp-watermark" alt=""><?php endif; ?>
        <div class="vp-stamp" style="color:<?php echo $vp_color; ?>;border-color:<?php echo $vp_color; ?>;"><b><?php echo $vp_is_recv ? 'RECEIVED' : 'PAID'; ?></b><span><?php echo vpv(date('d/m/Y', strtotime($voucher['transaction_date']))); ?></span></div>
        <div class="vp-body">
            <div class="vp-head">
                <div class="vp-head-logo"><?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo vpv($inst['logo_path']); ?>" alt=""><?php endif; ?></div>
                <div class="vp-head-text">
                    <h4><?php echo vpv($inst['institute_name']); ?></h4>
                    <?php if ($vp_addr !== ''): ?><div class="vp-inst-line">(<?php echo vpv($vp_addr); ?>)</div><?php endif; ?>
                    <?php if (!empty($inst['phone_number'])): ?><div class="vp-inst-line">Phone: <?php echo vpv($inst['phone_number']); ?></div><?php endif; ?>
                    <?php if (!empty($inst['website'])): ?><div class="vp-inst-line" style="color:#1a56c4;"><?php echo vpv($inst['website']); ?></div><?php endif; ?>
                </div>
                <div class="vp-head-qr" id="vpqr"></div>
            </div>

            <div class="vp-tagrow">
                <div class="vp-pill"><?php echo $vp_is_recv ? 'Receive Voucher' : 'Payment Voucher'; ?></div>
                <div class="vp-pill-out"><?php echo vpv($voucher['payment_type']); ?></div>
            </div>

            <table class="vp-grid">
                <tr>
                    <td><span class="vp-lbl">Voucher No</span><span class="vp-val"><?php echo vpv($voucher['voucher_no']); ?></span></td>
                    <td><span class="vp-lbl">Date</span><span class="vp-val"><?php echo vpv(date('d F Y', strtotime($voucher['transaction_date']))); ?></span></td>
                </tr>
                <tr>
                    <td><span class="vp-lbl">Payment Type</span><span class="vp-val"><?php echo vpv($voucher['payment_type']); ?></span></td>
                    <td><span class="vp-lbl">Cheque / Deposit Slip No</span><span class="vp-val"><?php echo !empty($voucher['cheque_no']) ? vpv($voucher['cheque_no']) : '—'; ?></span></td>
                </tr>
                <?php if (!empty($voucher['party_name'])): ?>
                <tr>
                    <td colspan="2"><span class="vp-lbl"><?php echo $vp_is_recv ? 'Received From' : 'Paid To'; ?></span><span class="vp-val"><?php echo vpv($voucher['party_name']); ?></span></td>
                </tr>
                <?php endif; ?>
            </table>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th style="width:50px;">Srl.</th>
                        <th>Particulars (A/C Head)</th>
                        <th style="width:130px;" class="text-end">Amount (৳)</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $srl = 1; foreach ($details as $d): ?>
                        <tr>
                            <td><?php echo $srl++; ?></td>
                            <td><?php echo vpv($d['ac_head'] ?? ''); ?> <?php echo $d['ac_code'] ? '(' . vpv($d['ac_code']) . ')' : ''; ?></td>
                            <td class="text-end"><?php echo vp_money($d['amount']); ?></td>
                            <td><?php echo vpv($d['remarks']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="vp-total">
                        <td colspan="2" class="text-end">Total :</td>
                        <td class="text-end"><?php echo vp_money($voucher['total_amount']); ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <div class="vp-words"><b>In Words :</b> <?php echo vpv(ucfirst(mr_number_to_words((int) round((float) $voucher['total_amount']))) . ' Tk. Only'); ?></div>

            <?php if (!empty($voucher['description'])): ?>
                <div class="vp-desc"><strong>Description:</strong> <?php echo nl2br(vpv($voucher['description'])); ?></div>
            <?php endif; ?>

            <div class="vp-sign">
                <div>Prepared By</div>
                <div>Checked By</div>
                <div>Authorized Signature</div>
            </div>
            <div class="vp-foot">Printed on <?php echo vpv(date('d M Y, h:i A')); ?></div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        window.addEventListener('load', function () {
            var el = document.getElementById('vpqr');
            if (el && window.QRCode) { new QRCode(el, { text: <?php echo json_encode($voucher['voucher_no'] . ' | ' . vp_money($voucher['total_amount']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, width: 54, height: 54 }); }
        });
    </script>
<?php endif; ?>
