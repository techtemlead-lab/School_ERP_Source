<?php
/**
 * Invoice template — প্যানেলের সাথে মিলিয়ে ডার্ক থিমে দেখাবে।
 * Caller must set $req (payment_requests row joined with tenants) and $pdo_master before including.
 */
if (!isset($req) || !$req) { http_response_code(404); echo 'Invoice পাওয়া যায়নি।'; exit; }

$branding = getSaasBranding($pdo_master);
$theme    = getThemeSettings($pdo_master);
$invoiceNo = 'INV-' . str_pad($req['id'], 5, '0', STR_PAD_LEFT);
?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $invoiceNo; ?> — Invoice</title>
<style>
<?php echo renderThemeCSS($pdo_master); ?>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--page-bg);color:var(--body-text);font-family:var(--font-family);padding:40px;max-width:800px;margin:0 auto;}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid var(--sidebar-border);padding-bottom:16px;margin-bottom:24px;}
.head h1{font-size:22px;color:var(--brand-text);}
.head img{max-height:50px;}
.meta{text-align:right;font-size:13px;color:var(--sidebar-text);}
.status{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;margin-top:6px;}
.status-approved{background:#0f3d2e;color:#4ade80;}
.status-pending{background:#3d340f;color:#facc15;}
.status-rejected{background:#3d1414;color:#f87171;}
table{width:100%;border-collapse:collapse;margin:20px 0;}
th{text-align:left;background:var(--card-bg);padding:10px;font-size:12px;text-transform:uppercase;color:var(--sidebar-text);border-bottom:1px solid var(--sidebar-border);}
td{padding:10px;border-bottom:1px solid var(--sidebar-border);font-size:14px;}
.total-row td{font-weight:700;font-size:16px;border-top:2px solid var(--sidebar-border);}
.footer{margin-top:40px;text-align:center;font-size:12px;color:var(--sidebar-text);}
.print-btn{padding:10px 24px;background:var(--sidebar-accent);color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;}
.print-btn:hover{filter:brightness(0.9);}
/* প্রিন্ট করার সময় কাগজের জন্য সাদা ব্যাকগ্রাউন্ড + কালো লেখা — কিন্তু স্ক্রিনে সবসময় ডার্ক থিম দেখাবে */
@media print{
  .no-print{display:none;}
  body{background:#fff;color:#000;}
  .head{border-color:#333;} .head h1{color:#000;}
  .meta{color:#333;}
  th{background:#f3f4f6;color:#333;border-color:#ddd;}
  td{border-color:#eee;}
  .total-row td{border-color:#333;}
  .footer{color:#888;}
}
</style>
</head>
<body>
    <div class="head">
        <div>
            <?php if ($branding['logo']): ?><img src="<?php echo htmlspecialchars($branding['logo']); ?>" alt="logo"><br><?php endif; ?>
            <h1><?php echo htmlspecialchars($branding['name']); ?></h1>
        </div>
        <div class="meta">
            <b><?php echo $invoiceNo; ?></b><br>
            তারিখ: <?php echo htmlspecialchars($req['reviewed_at'] ?? $req['created_at']); ?><br>
            <span class="status status-<?php echo strtolower($req['status']); ?>"><?php echo htmlspecialchars($req['status']); ?></span>
        </div>
    </div>

    <p><b>স্কুল:</b> <?php echo htmlspecialchars($req['school_name']); ?></p>
    <p><b>School Code:</b> <?php echo htmlspecialchars($req['school_code'] ?? ''); ?></p>

    <table>
        <thead><tr><th>বিবরণ</th><th>মাধ্যম</th><th>Transaction ID</th><th>মাস</th><th>পরিমাণ</th></tr></thead>
        <tbody>
            <tr>
                <td>Subscription Renewal</td>
                <td><?php echo htmlspecialchars($req['method']); ?></td>
                <td><?php echo htmlspecialchars($req['transaction_id']); ?></td>
                <td><?php echo (int) $req['months']; ?> মাস</td>
                <td>৳<?php echo number_format((float) $req['amount'], 2); ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="4" style="text-align:right;">সর্বমোট</td>
                <td>৳<?php echo number_format((float) $req['amount'], 2); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if (!empty($req['admin_note'])): ?><p><b>নোট:</b> <?php echo htmlspecialchars($req['admin_note']); ?></p><?php endif; ?>

    <div class="footer">এটি একটি কম্পিউটার-জেনারেটেড ইনভয়েস।</div>

    <div class="no-print" style="text-align:center;margin-top:24px;">
        <button onclick="window.print()" class="print-btn">🖨️ প্রিন্ট করো</button>
    </div>
</body>
</html>
