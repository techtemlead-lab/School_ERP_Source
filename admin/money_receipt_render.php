<?php
/**
 * config/money_receipt_render.php
 * -----------------------------------------------------------
 * Money Receipt-এর কপি রেন্ডার করার শেয়ার্ড হেল্পার (CSS + HTML) — আগে এই পুরো
 * লজিকটা শুধু admin/print_money_receipt.php-তে ছিল। এখন money_receipt.php
 * (Public/Guardian-facing, token দিয়ে খোলা, লগইন লাগে না, শুধু Student Copy
 * দেখায়) থেকেও এটাই রিইউজ করা হচ্ছে, যাতে দুই জায়গার ডিজাইন সবসময় হুবহু মিলে
 * থাকে — একবার ফিক্স করলে দুই জায়গাতেই কাজ করবে।
 */

if (!function_exists('mr_e')) {
    function mr_e($val): string { return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('mr_money')) {
    function mr_money($n): string { return number_format((float) $n, 2); }
}

// সংখ্যাকে ইংরেজি শব্দে রূপান্তর (Taka amount, ছোট/মাঝারি রেঞ্জের জন্য যথেষ্ট)
if (!function_exists('mr_number_to_words')) {
    function mr_number_to_words(int $num): string
    {
        if ($num === 0) return 'zero';
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $words = function (int $n) use (&$words, $ones, $tens): string {
            if ($n < 20) return $ones[$n];
            if ($n < 100) return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
            if ($n < 1000) return trim($ones[intdiv($n, 100)] . ' hundred ' . $words($n % 100));
            if ($n < 100000) return trim($words(intdiv($n, 1000)) . ' thousand ' . $words($n % 1000));
            if ($n < 10000000) return trim($words(intdiv($n, 100000)) . ' lakh ' . $words($n % 100000));
            return trim($words(intdiv($n, 10000000)) . ' crore ' . $words($n % 10000000));
        };
        return $words($num);
    }
}

/**
 * একটা কপির HTML রেন্ডার করার হেল্পার — রেফারেন্স ডিজাইন অনুযায়ী (লোগো/QR হেডার,
 * Session/Class/Student ID/Roll/Name/Date/Paid for/Folio No গ্রিড, Fees টেবিল,
 * In Words, লাল রঙে Dues লাইন, স্বাক্ষরের ঘর)। $logo_prefix দিয়ে লোগোর পাথ কোন
 * ফোল্ডার থেকে শুরু হবে সেটা ঠিক করা যায় — admin/ ফোল্ডারের ভেতর থেকে কল হলে
 * '../', root থেকে কল হলে '' (money_receipt.php)।
 */
if (!function_exists('mr_render_copy')) {
    function mr_render_copy(array $ctx, string $copy_label, string $qr_id, string $logo_prefix = '../'): string
    {
        extract($ctx);
        // Waiver কলাম (Fee/Waiver/Paid) কোন কপিতে দেখাবে — Student Copy আর Office/Bank Copy আলাদা সেটিং
        $mr_show_wv = $mr_waiver_on && ($copy_label === 'Student Copy' ? $mr_wv_student : $mr_wv_office);
        $GLOBALS['mr_qr_ids'][] = ['id' => $qr_id, 'text' => $folio_no . ' | ' . ($first['receipt_no'] ?? '')];
        ob_start();
        ?>
        <div class="mr-copy">
            <?php if ($show_logo && !empty($inst['logo_path'])): ?>
                <img src="<?php echo $logo_prefix; ?><?php echo mr_e($inst['logo_path']); ?>" class="mr-watermark">
            <?php endif; ?>

            <div class="mr-copy-body">
                <div class="mr-head">
                    <div class="mr-head-logo">
                        <?php if ($show_logo && !empty($inst['logo_path'])): ?>
                            <img src="<?php echo $logo_prefix; ?><?php echo mr_e($inst['logo_path']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mr-head-text">
                        <h4><?php echo mr_e($inst['institute_name'] ?? 'School Name'); ?></h4>
                        <?php if ($addr_line): ?><div class="mr-inst-line">(<?php echo mr_e($addr_line); ?>)</div><?php endif; ?>
                        <?php if (!empty($inst['phone_number'])): ?><div class="mr-inst-line">Phone: <?php echo mr_e($inst['phone_number']); ?></div><?php endif; ?>
                        <?php if (!empty($inst['website'])): ?><div class="mr-inst-line mr-web"><?php echo mr_e($inst['website']); ?></div><?php endif; ?>
                    </div>
                    <div class="mr-head-qr" id="<?php echo mr_e($qr_id); ?>"></div>
                </div>

                <div class="mr-receipt-title">(Money Receipt)</div>
                <div class="mr-copy-label"><?php echo mr_e($copy_label); ?></div>

                <table class="mr-info-grid">
                    <tr>
                        <td>Session : <?php echo mr_e($first['session_name'] ?? '—'); ?></td>
                        <td>Class : <?php echo mr_e($class_label ?: '—'); ?></td>
                    </tr>
                    <tr>
                        <td>Student ID : <?php echo mr_e($first['student_code'] ?? '—'); ?></td>
                        <td>Roll : <?php echo mr_e($first['roll_no'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td>Student Name : <?php echo mr_e($first['student_name'] ?? '—'); ?></td>
                        <td>Date : <?php echo mr_e(date('d F Y', strtotime($first['collected_at'] ?? 'now'))); ?></td>
                    </tr>
                    <tr>
                        <td>Paid for : <?php echo mr_e($paid_for_label ?: '—'); ?></td>
                        <td>Folio No : <?php echo mr_e($folio_no); ?></td>
                    </tr>
                </table>

                <table class="mr-items">
                    <?php if ($mr_show_wv): ?>
                    <thead><tr><th>Fees Type</th><th class="text-end">Fee</th><th class="text-end">Waiver</th><th class="text-end">Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): $rf = $mr_row_fin[(int) $r['id']] ?? null; ?>
                            <tr>
                                <td><?php echo mr_e($r['ac_head'] ?: '—'); ?></td>
                                <td class="text-end"><?php echo mr_money($rf ? $rf['base'] : $r['amount']); ?></td>
                                <td class="text-end"><?php echo mr_money($rf ? $rf['waived'] : 0); ?></td>
                                <td class="text-end"><?php echo mr_money($r['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="mr-total-row"><td class="text-end">Total :</td><td class="text-end"><?php echo mr_money($total_fee); ?></td><td class="text-end"><?php echo mr_money($total_waiver); ?></td><td class="text-end"><?php echo mr_money($total_amount); ?></td></tr>
                    </tbody>
                    <?php else: ?>
                    <thead><tr><th>Fees Type</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo mr_e($r['ac_head'] ?: '—'); ?></td>
                                <td class="text-end"><?php echo mr_money($r['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="mr-total-row"><td class="text-end">Total :</td><td class="text-end"><?php echo mr_money($total_amount); ?></td></tr>
                    </tbody>
                    <?php endif; ?>
                </table>

                <div class="mr-in-words">In Words : <?php echo mr_e(ucfirst(mr_number_to_words((int) round($total_amount))) . ' Tk. Only'); ?></div>

                <?php if ($period_due_total !== null): ?>
                    <div class="mr-due-line">
                        <span>Dues for <?php echo mr_e($paid_for_label ?: '—'); ?></span>
                        <span><?php echo mr_money($period_due_total); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($footer_note)): ?>
                    <p class="mr-footer-note"><?php echo mr_e($footer_note); ?></p>
                <?php endif; ?>

                <?php if ($show_signature): ?>
                    <div class="mr-sign-row">
                        <div class="mr-sign"><div class="mr-sign-line"></div>Received By</div>
                        <div class="mr-sign"><div class="mr-sign-line"></div>Accounts Officer</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

/** দুই পেজেই (admin প্রিন্ট + public view) হুবহু একই CSS — এক জায়গায় রাখা হলো। */
if (!function_exists('mr_receipt_css')) {
    function mr_receipt_css(string $mr_paper): string
    {
        ob_start();
        ?>
        body { font-family: Arial, 'Noto Sans Bengali', sans-serif; background: #eef1f5; padding: 16px; }
        .no-print-bar { max-width: 1000px; margin: 0 auto 16px; display: flex; justify-content: space-between; }
        .mr-sheet { background: #fff; margin: 0 auto 20px; box-shadow: 0 0 8px rgba(0,0,0,0.15); position: relative; display: flex; }
        .mr-portrait { width: 210mm; min-height: 148mm; padding: 8mm; box-sizing: border-box; }
        .mr-landscape { width: 297mm; min-height: 140mm; padding: 8mm; box-sizing: border-box; }
        .mr-a5 { width: 148mm; min-height: 210mm; padding: 8mm; box-sizing: border-box; }

        .mr-copy { flex: 1 1 0; padding: 0 10px; position: relative; border-right: 1px dotted #888; overflow: hidden; display: flex; flex-direction: column; }
        .mr-copy:last-child { border-right: none; }
        .mr-copy-body { position: relative; z-index: 2; flex: 1 1 auto; width: 100%; display: flex; flex-direction: column; }
        .mr-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 60%; max-height: 60%; opacity: 0.08; z-index: 1; }

        .mr-head { display: flex; align-items: flex-start; gap: 6px; }
        .mr-head-logo { width: 46px; flex: 0 0 46px; }
        .mr-head-logo img { max-width: 46px; max-height: 46px; }
        .mr-head-text { flex: 1 1 auto; text-align: center; }
        .mr-head-text h4 { margin: 0; font-weight: bold; color: #1e3a5f; font-size: 15px; }
        .mr-inst-line { font-size: 10px; color: #555; }
        .mr-web { color: #1a56c4; }
        .mr-head-qr { width: 46px; flex: 0 0 46px; }
        .mr-head-qr img, .mr-head-qr canvas { width: 46px !important; height: 46px !important; }

        .mr-receipt-title { text-align: center; font-style: italic; color: #6b3f1d; font-size: 13px; margin: 6px 0 2px; }
        .mr-copy-label { text-align: center; font-weight: bold; text-decoration: underline; font-size: 13px; margin-bottom: 8px; }

        table.mr-info-grid { width: 100%; font-size: 11px; margin-bottom: 6px; border-collapse: collapse; }
        table.mr-info-grid td { padding: 1px 2px; vertical-align: top; }

        table.mr-items { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px; }
        table.mr-items thead th { border-bottom: 1px solid #333; padding: 3px 4px; text-align: left; }
        table.mr-items tbody td { padding: 3px 4px; }
        table.mr-total-row td { border-top: 1px solid #333; font-weight: bold; padding-top: 4px; }
        .text-end { text-align: right; }

        .mr-in-words { font-size: 11px; margin: 4px 0; }
        .mr-due-line { display: flex; justify-content: space-between; color: #c0392b; font-weight: bold; font-size: 11px; margin-bottom: 6px; }
        .mr-footer-note { font-size: 10px; color: #666; margin: 4px 0; }

        .mr-sign-row { display: flex; justify-content: space-between; margin-top: auto; padding-top: 26px; }
        .mr-sign { text-align: center; font-size: 10.5px; }
        .mr-sign-line { border-top: 1px solid #333; width: 120px; margin-bottom: 3px; }

        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .no-print-bar { display: none; }
            .mr-sheet { box-shadow: none; margin: 0; width: auto; min-height: 0; padding: 0; }
            .mr-page-break { page-break-after: always; }
            .mr-copy { page-break-inside: avoid; break-inside: avoid; }
        }
        @page { size: 210mm 148mm; margin: 8mm; }
        <?php if ($mr_paper === 'a4_landscape'): ?>
        @page { size: 297mm 140mm; margin: 8mm; }
        <?php elseif ($mr_paper === 'a5'): ?>
        @page { size: A5 portrait; margin: 8mm; }
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}
