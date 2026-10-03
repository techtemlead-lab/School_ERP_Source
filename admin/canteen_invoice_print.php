<?php
// admin/canteen_invoice_print.php - Canteen Sale Invoice এর প্রফেশনাল Print View (স্বতন্ত্র পেজ, sidebar ছাড়া)
session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['admin_logged'])) {
    die('আপনি লগইন করা নেই। প্রথমে লগইন করুন।');
}

function ipv($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }

$sale_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM canteen_sales WHERE id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    die('এই Invoice পাওয়া যায়নি।');
}

$stmt = $pdo->prepare(
    "SELECT si.qty, si.rate, si.amount, mi.name AS item_name
     FROM canteen_sale_items si LEFT JOIN canteen_menu_items mi ON mi.id = si.menu_item_id
     WHERE si.sale_id = ? ORDER BY si.id ASC"
);
$stmt->execute([$sale_id]);
$lines = $stmt->fetchAll();

$extra_info = null;
if ($sale['buyer_type'] === 'student' && $sale['student_pk']) {
    $stmt = $pdo->prepare(
        "SELECT s.roll_no, s.student_id, c.class_name, sec.section_name
         FROM students s
         LEFT JOIN academic_classes c ON c.id = s.class_id
         LEFT JOIN academic_sections sec ON sec.id = s.section_id
         WHERE s.id = ?"
    );
    $stmt->execute([$sale['student_pk']]);
    $extra_info = $stmt->fetch() ?: null;
}

$institute_name = $_SESSION['institute_branch'] ?? 'School ERP';
$subtotal = (float) $sale['total_amount'] + (float) $sale['discount_amount'];

$inst_row = $pdo->query("SELECT logo_path FROM institute_settings WHERE id = 1")->fetch();
$logo_path = $inst_row['logo_path'] ?? null;

$buyer_badge = ['student' => 'Student', 'employee' => 'Teacher/Staff', 'walkin' => 'Walk-in Customer'][$sale['buyer_type']] ?? 'Walk-in Customer';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?php echo ipv($sale['invoice_no']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f1f5f9; padding: 24px; }
        .invoice-box { max-width: 800px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .invoice-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0d6efd; padding-bottom: 16px; margin-bottom: 24px; }
        .invoice-head h2 { font-weight: 800; color: #0a2540; margin: 0; }
        .invoice-head .badge-invoice { text-align: right; }
        .invoice-head .badge-invoice .inv-no { font-size: 20px; font-weight: 700; color: #0d6efd; }
        .buyer-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; margin-bottom: 24px; }
        table.items-table th { background: #0a2540; color: #fff; font-size: 13px; }
        table.items-table td, table.items-table th { padding: 10px 12px; }
        .totals-box { max-width: 320px; margin-left: auto; margin-top: 16px; }
        .totals-box .row-line { display: flex; justify-content: space-between; padding: 4px 0; font-size: 14px; }
        .totals-box .row-line.grand { font-size: 18px; font-weight: 800; border-top: 2px solid #0a2540; margin-top: 6px; padding-top: 8px; }
        .totals-box .row-line.due { color: #dc2626; font-weight: 700; }
        .footer-note { text-align: center; color: #94a3b8; font-size: 12.5px; margin-top: 36px; }
        .no-print { text-align: center; margin-bottom: 20px; }
        @media print {
            body { background: #fff; padding: 0; }
            .invoice-box { box-shadow: none; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-secondary" onclick="window.close()">Close</button>
    </div>

    <div class="invoice-box">
        <div class="invoice-head">
            <div class="d-flex align-items-center gap-3">
                <?php if ($logo_path): ?>
                    <img src="../<?php echo ipv($logo_path); ?>" alt="Logo" style="height:56px; width:auto;">
                <?php endif; ?>
                <div>
                    <h2><?php echo ipv($institute_name); ?></h2>
                    <div class="text-muted" style="font-size:13px;">Canteen Sales Invoice / ক্যানটিন বিক্রয় রশিদ</div>
                </div>
            </div>
            <div class="badge-invoice">
                <div class="inv-no">#<?php echo ipv($sale['invoice_no']); ?></div>
                <div class="text-muted" style="font-size:13px;">Date: <?php echo ipv(date('d M Y, h:i A', strtotime($sale['created_at']))); ?></div>
            </div>
        </div>

        <div class="buyer-box">
            <strong>Buyer:</strong> <?php echo ipv($sale['customer_name']); ?>
            <span class="badge <?php echo $sale['buyer_type'] === 'walkin' ? 'bg-secondary' : ($sale['buyer_type'] === 'student' ? 'bg-info' : 'bg-primary'); ?> ms-2"><?php echo $buyer_badge; ?></span>
            <?php if ($sale['buyer_type'] === 'student' && $extra_info): ?>
                <div class="small text-muted mt-1">
                    ID: <?php echo ipv($sale['student_code']); ?>
                    &middot; Roll: <?php echo ipv($extra_info['roll_no']); ?>
                    <?php if ($extra_info['class_name']): ?>&middot; Class: <?php echo ipv($extra_info['class_name']); ?><?php endif; ?>
                    <?php if ($extra_info['section_name']): ?> (<?php echo ipv($extra_info['section_name']); ?>)<?php endif; ?>
                </div>
            <?php elseif ($sale['buyer_type'] === 'employee'): ?>
                <div class="small text-muted mt-1">ID: <?php echo ipv($sale['employee_code']); ?></div>
            <?php endif; ?>
        </div>

        <table class="table table-bordered items-table">
            <thead>
                <tr><th>#</th><th>Item</th><th class="text-center">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
                <?php $sl = 1; foreach ($lines as $ln): ?>
                    <tr>
                        <td><?php echo $sl++; ?></td>
                        <td><?php echo ipv($ln['item_name'] ?? 'Item'); ?></td>
                        <td class="text-center"><?php echo (int) $ln['qty']; ?></td>
                        <td class="text-end">৳<?php echo number_format((float) $ln['rate'], 2); ?></td>
                        <td class="text-end">৳<?php echo number_format((float) $ln['amount'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals-box">
            <div class="row-line"><span>Subtotal</span><span>৳<?php echo number_format($subtotal, 2); ?></span></div>
            <div class="row-line"><span>Discount</span><span>৳<?php echo number_format((float) $sale['discount_amount'], 2); ?></span></div>
            <div class="row-line grand"><span>Grand Total</span><span>৳<?php echo number_format((float) $sale['total_amount'], 2); ?></span></div>
            <div class="row-line"><span>Paid</span><span>৳<?php echo number_format((float) $sale['paid_amount'], 2); ?></span></div>
            <div class="row-line due"><span>Due</span><span>৳<?php echo number_format((float) $sale['due_amount'], 2); ?></span></div>
        </div>

        <p class="footer-note">এই রশিদটি কম্পিউটার-জেনারেটেড, স্বাক্ষরের প্রয়োজন নেই। ধন্যবাদ!</p>
    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
    </script>
</body>
</html>
