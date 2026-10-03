<?php
// admin/finance_voucher_print.php - একটা Voucher-এর প্রিন্টযোগ্য কপি
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

$inst = $pdo->query("SELECT institute_name, phone_number, logo_path FROM institute_settings WHERE id = 1")->fetch();
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
?>
<style>
    @media print {
        @page { size: A4 portrait; margin: 10mm; }
        html, body { background: #fff !important; }
        .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, .alert, footer { display: none !important; }
        .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; }
        .content-body { padding: 0 !important; margin: 0 !important; }
        .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
        .voucher-print-wrap { box-shadow: none !important; border: none !important; max-width: 100% !important; padding: 0 !important; }
        .vp-table tr { page-break-inside: avoid; }
    }
    .voucher-print-wrap { background: #fff; max-width: 800px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; }
    .vp-header { text-align: center; border-bottom: 2px solid #0a2540; padding-bottom: 12px; margin-bottom: 18px; }
    .vp-title { display: inline-block; padding: 4px 24px; border: 1px solid #333; font-weight: bold; margin-top: 10px; }
    .vp-meta-row { display: flex; justify-content: space-between; font-size: 13.5px; margin-bottom: 6px; }
    .vp-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13.5px; }
    .vp-table th, .vp-table td { border: 1px solid #999; padding: 6px 10px; }
    .vp-table th { background: #f1f5f9; }
    .vp-sign { display: flex; justify-content: space-between; margin-top: 60px; }
    .vp-sign div { border-top: 1px solid #333; width: 180px; text-align: center; padding-top: 4px; font-size: 12px; }
</style>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
    <a href="index.php?page=finance_vouchers_list" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    <?php if ($voucher): ?>
        <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <?php endif; ?>
</div>

<?php if (!$voucher): ?>
    <div class="alert alert-danger">ভাউচার খুঁজে পাওয়া যায়নি।</div>
<?php else: ?>
    <div class="voucher-print-wrap">
        <div class="vp-header">
            <?php if (!empty($inst['logo_path'])): ?><img src="../<?php echo vpv($inst['logo_path']); ?>" style="max-width:70px;max-height:70px;"><br><?php endif; ?>
            <h4 class="fw-bold mb-0"><?php echo vpv($inst['institute_name']); ?></h4>
            <?php if (!empty($inst['phone_number'])): ?><div class="small text-muted">Phone: <?php echo vpv($inst['phone_number']); ?></div><?php endif; ?>
            <div class="vp-title">
                <?php echo $voucher['voucher_type'] === 'Receive' ? 'RECEIVE VOUCHER' : 'PAYMENT VOUCHER'; ?>
            </div>
        </div>

        <div class="vp-meta-row">
            <div><strong>Voucher No:</strong> <?php echo vpv($voucher['voucher_no']); ?></div>
            <div><strong>Date:</strong> <?php echo vpv(date('d F Y', strtotime($voucher['transaction_date']))); ?></div>
        </div>
        <div class="vp-meta-row">
            <div><strong>Payment Type:</strong> <?php echo vpv($voucher['payment_type']); ?></div>
            <?php if (!empty($voucher['cheque_no'])): ?>
                <div><strong>Cheque / Deposit Slip No:</strong> <?php echo vpv($voucher['cheque_no']); ?></div>
            <?php endif; ?>
        </div>
        <?php if (!empty($voucher['party_name'])): ?>
            <div class="vp-meta-row">
                <div><strong><?php echo $voucher['voucher_type'] === 'Receive' ? 'Received From' : 'Paid To'; ?>:</strong> <?php echo vpv($voucher['party_name']); ?></div>
            </div>
        <?php endif; ?>

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
                <tr>
                    <td colspan="2" class="text-end fw-bold">Total</td>
                    <td class="text-end fw-bold"><?php echo vp_money($voucher['total_amount']); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($voucher['description'])): ?>
            <div class="mt-3" style="font-size:13.5px;">
                <strong>Description:</strong> <?php echo nl2br(vpv($voucher['description'])); ?>
            </div>
        <?php endif; ?>

        <div class="vp-sign">
            <div>Prepared By</div>
            <div>Checked By</div>
            <div>Authorized Signature</div>
        </div>
    </div>
<?php endif; ?>
