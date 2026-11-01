<?php
// config/seat_card_helper.php - Exam Seat Plan কার্ডের ডিজাইন (Admin + Student পোর্টাল দুই জায়গায় একই)
if (!function_exists('sc_e')) {
    function sc_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('sc_css')) {
    function sc_css(): string
    {
        return <<<'CSS'
<style>
@page { size: A4; margin: 8mm; }
@media print {
    /* অ্যাডমিন লেআউট (সাইডবার/টপবার/ব্রেডক্রাম্ব) প্রিন্টে বাদ — শুধু কার্ড ছাপা হবে */
    .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, footer, .sidebar-overlay { display: none !important; }
    html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; height: auto !important; overflow: visible !important; }
    .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; padding: 0 !important; }
    .content-body { padding: 0 !important; margin: 0 !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sc-grid { display: grid !important; grid-template-columns: repeat(2, 92mm); justify-content: center; gap: 4mm !important; }
    .sc-grid .seatcard { width: 92mm !important; box-shadow: none !important; }
}
.sc-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; }
.seatcard {
    position: relative; width: 390px; max-width: 100%; background: #fff; overflow: hidden;
    border: 1.5px solid #1e3a8a; border-radius: 12px; font-family: 'Segoe UI', Arial, sans-serif;
    box-shadow: 0 2px 10px rgba(30,58,138,.12); page-break-inside: avoid; break-inside: avoid; text-align: left;
}
.seatcard .sc-head {
    display: flex; align-items: center; gap: 10px; padding: 10px 12px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #fff;
}
.seatcard .sc-logo {
    width: 52px; height: 52px; flex: 0 0 52px; border-radius: 50%; background: #fff; padding: 3px;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.seatcard .sc-logo img { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; image-rendering: -webkit-optimize-contrast; }
.seatcard .sc-logo span { color: #1e3a8a; font-weight: 800; font-size: 20px; }
.seatcard .sc-inst { flex: 1; min-width: 0; text-align: center; }
.seatcard .sc-inst b { display: block; font-size: 14.5px; line-height: 1.25; letter-spacing: .2px; }
.seatcard .sc-inst small { display: block; font-size: 11px; opacity: .92; margin-top: 2px; }
.seatcard .sc-room {
    flex: 0 0 auto; background: #fbbf24; color: #1e293b; border-radius: 8px; padding: 4px 9px; text-align: center; line-height: 1.1;
}
.seatcard .sc-room small { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.seatcard .sc-room b { font-size: 18px; }
.seatcard .sc-ribbon {
    text-align: center; background: #eef2ff; color: #1e3a8a; font-weight: 800; font-size: 12.5px;
    letter-spacing: 1.2px; text-transform: uppercase; padding: 5px 8px; border-bottom: 1px dashed #93c5fd;
}
.seatcard .sc-ribbon em { font-style: normal; color: #b45309; }
.seatcard .sc-body { display: flex; gap: 12px; padding: 12px; position: relative; }
.seatcard .sc-body::before {
    content: ''; position: absolute; inset: 0; background: var(--sc-wm) center/55% no-repeat; opacity: .06; pointer-events: none;
}
.seatcard .sc-photo {
    width: 84px; height: 100px; flex: 0 0 84px; object-fit: cover; border: 2px solid #1e3a8a; border-radius: 8px; background: #f1f5f9;
    display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px;
}
.seatcard .sc-info { flex: 1; min-width: 0; font-size: 12.5px; position: relative; }
.seatcard .sc-name { font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 5px; line-height: 1.25; word-break: break-word; }
.seatcard .sc-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 10px; }
.seatcard .sc-grid2 div { line-height: 1.5; }
.seatcard .sc-grid2 span { display: block; font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.seatcard .sc-grid2 b { color: #0f172a; font-size: 13px; }
.seatcard .sc-foot {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 8px 12px; background: #f8fafc; border-top: 1px solid #e2e8f0;
}
.seatcard .sc-sid span { display: block; font-size: 9.5px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.seatcard .sc-sid b { font-size: 16px; color: #1e3a8a; letter-spacing: .5px; }
.seatcard .sc-qr { width: 62px; height: 62px; background: #fff; padding: 2px; border: 1px solid #cbd5e1; border-radius: 6px; }
.seatcard .sc-qr img, .seatcard .sc-qr canvas { width: 100% !important; height: 100% !important; }
</style>
CSS;
    }
}

/**
 * একটা Seat Card এর HTML রিটার্ন করে।
 * $d: institute, logo, room, term, part, year, name, class, shift, section, roll, student_id, photo, qr_id (DOM id)
 */
if (!function_exists('sc_card')) {
    function sc_card(array $d): string
    {
        $logo = !empty($d['logo']) ? '../' . ltrim($d['logo'], '/') : '';
        $wm = $logo !== '' ? "--sc-wm:url('" . sc_e($logo) . "');" : '';
        ob_start(); ?>
        <div class="seatcard">
            <div class="sc-head">
                <div class="sc-logo">
                    <?php if ($logo !== ''): ?><img src="<?php echo sc_e($logo); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr(trim((string) $d['institute']), 0, 1)); ?></span><?php endif; ?>
                </div>
                <div class="sc-inst">
                    <b><?php echo sc_e($d['institute']); ?></b>
                    <small><?php echo sc_e($d['term']); ?></small>
                </div>
                <?php if (trim((string) $d['room']) !== ''): ?>
                    <div class="sc-room"><small>Room</small><b><?php echo sc_e($d['room']); ?></b></div>
                <?php endif; ?>
            </div>
            <div class="sc-ribbon">Seat Layout - <?php echo sc_e($d['year']); ?><?php echo $d['part'] !== '' ? ' <em>(' . sc_e($d['part']) . ')</em>' : ''; ?></div>
            <div class="sc-body" style="<?php echo $wm; ?>">
                <?php if (!empty($d['photo'])): ?>
                    <img class="sc-photo" src="../<?php echo sc_e(ltrim($d['photo'], '/')); ?>" alt="Photo">
                <?php else: ?>
                    <div class="sc-photo">Photo</div>
                <?php endif; ?>
                <div class="sc-info">
                    <div class="sc-name"><?php echo sc_e($d['name']); ?></div>
                    <div class="sc-grid2">
                        <div><span>Class</span><b><?php echo sc_e($d['class'] ?: '-'); ?></b></div>
                        <div><span>Roll No</span><b><?php echo sc_e($d['roll'] ?: '-'); ?></b></div>
                        <div><span>Shift</span><b><?php echo sc_e($d['shift'] ?: '-'); ?></b></div>
                        <div><span>Section</span><b><?php echo sc_e($d['section'] ?: '-'); ?></b></div>
                    </div>
                </div>
            </div>
            <div class="sc-foot">
                <div class="sc-sid"><span>Student ID</span><b><?php echo sc_e($d['student_id']); ?></b></div>
                <div class="sc-qr" id="<?php echo sc_e($d['qr_id']); ?>" data-qr="<?php echo sc_e($d['student_id']); ?>"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// পেজের নিচে একবার কল করলে সব .sc-qr এ QR তৈরি হয়
if (!function_exists('sc_qr_script')) {
    function sc_qr_script(): string
    {
        return <<<'JS'
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.sc-qr[data-qr], .ad-qr[data-qr]').forEach(function (el) {
    if (window.QRCode) { new QRCode(el, { text: el.getAttribute('data-qr'), width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M }); }
});
</script>
JS;
    }
}


// ------------------------------------------------------------------
// Admit Card ডিজাইন (Result > Download Admit Card)
// ------------------------------------------------------------------
if (!function_exists('ac_layout')) {
    /**
     * Admit Card লেআউট: preset (4rect | 4sq | 3rect | custom) + custom হলে ori/w/h (mm)।
     * A4, মার্জিন ৮mm, কার্ডের মাঝে ৪mm gap। রিটার্ন: ori, w, h (mm), cols, rows, per, gap, mode, dw (design px), k (scale)
     */
    function ac_layout(array $in = []): array
    {
        $presets = [
            '4rect' => ['portrait', 94, 135],
            '4sq'   => ['portrait', 94, 94],
            '3rect' => ['portrait', 194, 90],
        ];
        $preset = (string) ($in['preset'] ?? '4rect');
        if ($preset !== 'custom' && !isset($presets[$preset])) { $preset = '4rect'; }
        if ($preset === 'custom') {
            $ori = (($in['ori'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';
            $w = (float) ($in['w'] ?? 94); $h = (float) ($in['h'] ?? 94);
        } else {
            [$ori, $w, $h] = $presets[$preset];
        }
        $gap = 4; $margin = 8;
        $pw = ($ori === 'landscape' ? 297 : 210) - 2 * $margin;
        $ph = ($ori === 'landscape' ? 210 : 297) - 2 * $margin;
        $w = max(50.0, min((float) $pw, $w));
        $h = max(40.0, min((float) $ph, $h));
        $cols = max(1, (int) floor(($pw + $gap) / ($w + $gap)));
        $rows = max(1, (int) floor(($ph + $gap) / ($h + $gap)));
        $aspect = $w / $h;
        // কার্ডের ভেতরের কনটেন্টের ন্যূনতম ডিজাইন-উচ্চতা (H0) ও চওড়া (minDw) অনুযায়ী dw; যাতে কিছু কাটা না পড়ে
        if ($aspect >= 1.6)      { $mode = 'wide'; $h0 = 340; $min_dw = 760; }
        elseif ($aspect >= 1.2)  { $mode = 'std';  $h0 = 300; $min_dw = 400; }
        else                     { $mode = 'tall'; $h0 = 380; $min_dw = 300; }
        $dw = (int) ceil(max($min_dw, $h0 * $aspect));
        $k = ($w * 3.7795) / $dw;
        return [
            'preset' => $preset, 'ori' => $ori, 'w' => round($w, 1), 'h' => round($h, 1),
            'cols' => $cols, 'rows' => $rows, 'per' => $cols * $rows, 'gap' => $gap,
            'mode' => $mode, 'dw' => $dw, 'k' => $k, 'dh' => ($h * 3.7795) / $k,
        ];
    }
}

if (!function_exists('ac_css')) {
    function ac_css(?array $L = null): string
    {
        $L = $L ?: ac_layout();
        $css = <<<'CSS'
<style>
@media print {
    .no-print, .sidebar, .sticky-top-area, .top-navbar, .breadcrumb-bar, footer, .sidebar-overlay { display: none !important; }
    html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; height: auto !important; overflow: visible !important; }
    .main-wrapper, .main-wrapper.expanded { margin-left: 0 !important; width: 100% !important; min-height: 0 !important; padding: 0 !important; }
    .content-body { padding: 0 !important; margin: 0 !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ac-page { margin: 0 !important; border: none !important; padding: 0 !important; page-break-after: always; break-after: page; }
    .ac-page:last-child { page-break-after: auto; break-after: auto; }
    .ac-cell .admitcard2 { box-shadow: none !important; }
}
.ac-pages { display: block; }
.ac-page { display: grid; justify-content: center; align-content: start; margin: 0 auto 18px; padding: 8px; border: 1px dashed #cbd5e1; }
.ac-cell { position: relative; overflow: hidden; border-radius: 10px; }
.admitcard2 {
    position: absolute; top: 0; left: 0; transform-origin: top left; display: flex; flex-direction: column;
    background: #fff; overflow: hidden; text-align: left; box-sizing: border-box;
    border: 1.5px solid #0f766e; border-radius: 10px; font-family: 'Segoe UI', Arial, sans-serif;
    box-shadow: 0 2px 10px rgba(15,118,110,.15); page-break-inside: avoid; break-inside: avoid;
}
.admitcard2 .ad-head {
    display: flex; align-items: center; gap: 9px; padding: 9px 11px; color: #fff;
    background: linear-gradient(135deg, #0f3d3e 0%, #0f766e 100%);
}
.admitcard2 .ad-logo {
    width: 48px; height: 48px; flex: 0 0 48px; border-radius: 50%; background: #fff; padding: 3px;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.admitcard2 .ad-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.admitcard2 .ad-logo span { color: #0f766e; font-weight: 800; font-size: 19px; }
.admitcard2 .ad-inst { flex: 1; min-width: 0; text-align: center; }
.admitcard2 .ad-inst b { display: block; font-size: 14px; line-height: 1.25; }
.admitcard2 .ad-inst small { display: block; font-size: 9.5px; opacity: .92; margin-top: 2px; line-height: 1.3; }
.admitcard2 .ad-inst .web { color: #fde68a; }
.admitcard2 .ad-title {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    background: #f0fdfa; border-bottom: 1px dashed #5eead4; padding: 5px 8px;
}
.admitcard2 .ad-title .pill {
    background: #fbbf24; color: #1e293b; font-weight: 800; font-size: 12px; letter-spacing: 1.5px;
    padding: 2px 12px; border-radius: 20px;
}
.admitcard2 .ad-title .term { font-weight: 700; font-size: 12px; color: #0f3d3e; }
.admitcard2 .ad-body { display: flex; align-items: center; flex: 1 1 auto; gap: 11px; padding: 11px; position: relative; }
.admitcard2 .ad-body::before {
    content: ''; position: absolute; inset: 0; background: var(--ad-wm) center/50% no-repeat; opacity: .06; pointer-events: none;
}
.admitcard2 .ad-photo {
    width: 80px; height: 96px; flex: 0 0 80px; object-fit: cover; border: 2px solid #0f766e; border-radius: 6px; background: #f1f5f9;
    display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px;
}
.admitcard2 .ad-info { flex: 1; min-width: 0; position: relative; }
.admitcard2 .ad-name { font-size: 15px; font-weight: 800; color: #0f172a; line-height: 1.25; margin-bottom: 5px; word-break: break-word; }
.admitcard2 .ad-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 10px; }
.admitcard2 .ad-grid span { display: block; font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; line-height: 1.2; }
.admitcard2 .ad-grid b { font-size: 12.5px; color: #0f172a; line-height: 1.3; }
.admitcard2 .ad-qr { width: 66px; height: 66px; flex: 0 0 66px; background: #fff; padding: 2px; border: 1px solid #cbd5e1; border-radius: 6px; }
.admitcard2 .ad-qr img, .admitcard2 .ad-qr canvas { width: 100% !important; height: 100% !important; }
.admitcard2 .ad-foot {
    display: flex; justify-content: space-between; align-items: flex-end; gap: 10px;
    padding: 20px 14px 7px; background: #f8fafc; border-top: 1px solid #e2e8f0;
}
.admitcard2 .ad-sign { flex: 1; text-align: center; font-size: 9.5px; color: #475569; border-top: 1px solid #64748b; padding-top: 2px; }
.admitcard2 .ad-note { text-align: center; font-size: 8.5px; color: #64748b; padding: 0 8px 6px; background: #f8fafc; }
/* wide (চওড়া কার্ড): বড় ফন্ট/ছবি */
.admitcard2.ac-wide .ad-photo { width: 110px; height: 132px; flex-basis: 110px; }
.admitcard2.ac-wide .ad-qr { width: 90px; height: 90px; flex-basis: 90px; }
.admitcard2.ac-wide .ad-name { font-size: 22px; }
.admitcard2.ac-wide .ad-grid b { font-size: 16px; }
.admitcard2.ac-wide .ad-inst b { font-size: 20px; }
.admitcard2.ac-wide .ad-inst small { font-size: 12px; }
/* tall/square: ছবি+তথ্য ওপরে, QR নিচে মাঝে */
.admitcard2.ac-tall .ad-body { flex-wrap: wrap; justify-content: center; align-content: center; }
.admitcard2.ac-tall .ad-info { flex: 1 1 150px; }
.admitcard2.ac-tall .ad-qr { margin: 0 auto; }
</style>
CSS;
        $page = '@page { size: A4 ' . $L['ori'] . '; margin: 8mm; }';
        $dyn  = '.ac-page { grid-template-columns: repeat(' . (int) $L['cols'] . ', ' . $L['w'] . 'mm); gap: ' . (int) $L['gap'] . 'mm; }'
              . '.ac-cell { width: ' . $L['w'] . 'mm; height: ' . $L['h'] . 'mm; }'
              . '.admitcard2 { width: ' . $L['dw'] . 'px; height: ' . round($L['dh'], 2) . 'px; transform: scale(' . round($L['k'], 5) . '); }';
        return str_replace('<style>', '<style>' . $page . $dyn, $css);
    }
}

/**
 * $d: institute, logo, address, website, term, year, name, class, shift, section, roll, student_id, photo, qr_id
 */
if (!function_exists('ac_card')) {
    function ac_card(array $d): string
    {
        $logo = !empty($d['logo']) ? '../' . ltrim($d['logo'], '/') : '';
        $wm = $logo !== '' ? "--ad-wm:url('" . sc_e($logo) . "');" : '';
        $L = $d['layout'] ?? ac_layout();
        ob_start(); ?>
        <div class="ac-cell"><div class="admitcard2 ac-<?php echo sc_e($L['mode']); ?>">
            <div class="ad-head">
                <div class="ad-logo">
                    <?php if ($logo !== ''): ?><img src="<?php echo sc_e($logo); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr(trim((string) $d['institute']), 0, 1)); ?></span><?php endif; ?>
                </div>
                <div class="ad-inst">
                    <b><?php echo sc_e($d['institute']); ?></b>
                    <?php if (trim((string) ($d['address'] ?? '')) !== ''): ?><small><?php echo sc_e($d['address']); ?></small><?php endif; ?>
                    <?php if (trim((string) ($d['website'] ?? '')) !== ''): ?><small class="web"><?php echo sc_e($d['website']); ?></small><?php endif; ?>
                </div>
            </div>
            <div class="ad-title">
                <span class="pill">ADMIT CARD</span>
                <span class="term"><?php echo sc_e(trim($d['term'] . ' - ' . $d['year'], ' -')); ?></span>
            </div>
            <div class="ad-body" style="<?php echo $wm; ?>">
                <?php if (!empty($d['photo'])): ?>
                    <img class="ad-photo" src="../<?php echo sc_e(ltrim($d['photo'], '/')); ?>" alt="Photo">
                <?php else: ?>
                    <div class="ad-photo">Photo</div>
                <?php endif; ?>
                <div class="ad-info">
                    <div class="ad-name"><?php echo sc_e($d['name']); ?></div>
                    <div class="ad-grid">
                        <div><span>Student ID</span><b><?php echo sc_e($d['student_id']); ?></b></div>
                        <div><span>Roll No</span><b><?php echo sc_e($d['roll'] ?: '-'); ?></b></div>
                        <div><span>Class</span><b><?php echo sc_e($d['class'] ?: '-'); ?></b></div>
                        <div><span>Shift</span><b><?php echo sc_e($d['shift'] ?: '-'); ?></b></div>
                        <div><span>Section</span><b><?php echo sc_e($d['section'] ?: '-'); ?></b></div>
                    </div>
                </div>
                <div class="ad-qr" id="<?php echo sc_e($d['qr_id']); ?>" data-qr="<?php echo sc_e($d['student_id']); ?>"></div>
            </div>
            <div class="ad-foot">
                <div class="ad-sign">Class Teacher</div>
                <div class="ad-sign">Head of Institute</div>
            </div>
            <div class="ad-note">পরীক্ষার হলে এই কার্ডটি অবশ্যই সঙ্গে আনতে হবে।</div>
        </div></div>
        <?php
        return ob_get_clean();
    }
}
