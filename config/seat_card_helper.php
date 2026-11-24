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


if (!function_exists('ac_asset_url')) {
    function ac_asset_url(?string $path): string
    {
        $p = trim((string) ($path ?? ''));
        if ($p === '') {
            return '';
        }
        if (preg_match('#^(https?://|data:|\.\./)#i', $p)) {
            return $p;
        }
        return '../' . ltrim($p, '/');
    }
}

if (!function_exists('ac_fmt_date')) {
    function ac_fmt_date(?string $raw, string $fallback = '—', string $fmt = 'd/m/Y'): string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '' || $v === '0000-00-00') {
            return $fallback;
        }
        $ts = strtotime($v);
        return $ts !== false ? date($fmt, $ts) : $v;
    }
}

if (!function_exists('ac_calc_age')) {
    function ac_calc_age(?string $dob): string
    {
        $v = trim((string) ($dob ?? ''));
        if ($v === '' || $v === '0000-00-00') {
            return '—';
        }
        try {
            $b = new DateTime($v);
            $now = new DateTime('today');
            if ($b > $now) {
                return '—';
            }
            $diff = $b->diff($now);
            return $diff->y . ' years ' . $diff->m . ' months';
        } catch (Throwable $e) {
            return '—';
        }
    }
}

if (!function_exists('ac_default_instructions')) {
    /**
     * মাধ্যমিক ও উচ্চ মাধ্যমিক শিক্ষা বোর্ড (বাংলাদেশ) অনুমোদিত পরীক্ষার সাধারণ নির্দেশাবলী (ডিফল্ট)।
     */
    function ac_default_instructions(): array
    {
        $rules = implode("\n", [
            '১. পরীক্ষার্থীকে পরীক্ষা শুরুর কমপক্ষে ৩০ (ত্রিশ) মিনিট পূর্বে পরীক্ষার কেন্দ্রে/কক্ষে উপস্থিত হয়ে নির্ধারিত আসনে বসতে হবে। পরীক্ষা শুরুর পর কোনো পরীক্ষার্থীকে কক্ষে প্রবেশ করতে দেওয়া হবে না।',
            '২. পরীক্ষার হলে মূল প্রবেশপত্র (Admit Card) এবং প্রযোজ্য ক্ষেত্রে রেজিস্ট্রেশন/আইডি কার্ড অবশ্যই সঙ্গে আনতে হবে। প্রবেশপত্র ব্যতীত কোনো পরীক্ষার্থীকে পরীক্ষায় অংশগ্রহণ করতে দেওয়া হবে না।',
            '৩. উত্তরপত্র ও ওএমআর (OMR) ফরমে রোল নম্বর, রেজিস্ট্রেশন/আইডি নম্বর ও বিষয় কোড সঠিকভাবে লিখে কেবল কালো বা নীল বলপয়েন্ট কলম দিয়ে নির্ধারিত বৃত্ত ভরাট করতে হবে।',
            '৪. উত্তরপত্র ও ওএমআর (OMR) শিটে পেন্সিল, হোয়াইট ফ্লুইড (সাদা কালি) বা কোনো প্রকার কাটাকাটি করা সম্পূর্ণ নিষিদ্ধ এবং কোনো অবস্থাতেই উত্তরপত্র বা ওএমআর শিট ভাঁজ করা যাবে না।',
            '৫. পরীক্ষার কক্ষে নিজস্ব কালো/নীল বলপয়েন্ট কলম, পেন্সিল, জ্যামিতি বক্স এবং কেবল অনুমোদিত সাধারণ সায়েন্টিফিক ক্যালকুলেটর (নন-প্রোগ্রামেবল) ব্যবহার করা যাবে।',
            '৬. পরীক্ষার প্রশ্নপত্র ও উত্তরপত্রের পৃষ্ঠা সংখ্যা ঠিক আছে কিনা তা লেখার পূর্বেই যাচাই করে নিতে হবে এবং উত্তরপত্রের কোনো পৃষ্ঠা ছেঁড়া বা নষ্ট করা যাবে না।',
            '৭. পরীক্ষার কক্ষে কোনো পরীক্ষার্থী অন্যের সাথে কথা বলা, বই-খাতা, নোট বা কোনো অসদুপায় অবলম্বন করতে পারবে না।',
            '৮. হাজিরা খাতায় স্বাক্ষর না করে এবং কক্ষ পরিদর্শকের নিকট উত্তরপত্র ও ওএমআর শিট জমা না দিয়ে কোনো পরীক্ষার্থী পরীক্ষার কক্ষ ত্যাগ করতে পারবে না।',
            '৯. পরীক্ষা শেষ না হওয়া পর্যন্ত এবং পরিদর্শকের অনুমতি ব্যতীত পরীক্ষার কক্ষের বাইরে যাওয়া যাবে না; পরীক্ষা চলাকালীন পূর্ণ শৃঙ্খলা বজায় রাখতে হবে।',
            '১০. পরীক্ষায় কোনো প্রকার অসদুপায় অবলম্বন করলে বা নিষিদ্ধ সামগ্রী পাওয়া গেলে সংশ্লিষ্ট বিষয়ের বা সম্পূর্ণ পরীক্ষা বাতিলসহ সরকারি ও প্রাতিষ্ঠানিক বিধি মোতাবেক শাস্তিমূলক ব্যবস্থা গ্রহণ করা হবে।',
        ]);

        return [
            'note_text'   => 'প্রবেশপত্রে পরীক্ষার্থীর নাম, পিতা-মাতার নাম, রোল নম্বর, শ্রেণি, শাখা, ছবি বা অন্য কোনো তথ্যে কোনো অসঙ্গতি বা ভুল পরিলক্ষিত হলে পরীক্ষা শুরুর পূর্বেই প্রতিষ্ঠান প্রধান / পরীক্ষা নিয়ন্ত্রকের নিকট আবেদন করে তা সংশোধন করে নিতে হবে। ছবি ও স্বাক্ষরবিহীন প্রবেশপত্র গ্রহণযোগ্য হবে না।',
            'heading'     => 'পরীক্ষার্থীদের জন্য গুরুত্বপূর্ণ নির্দেশাবলী (IMPORTANT DIRECTIONS FOR CANDIDATES)',
            'rules_text'  => $rules,
            'carry_text'  => 'মূল প্রবেশপত্র (Admit Card), শিক্ষার্থী আইডি/রেজিস্ট্রেশন কার্ড (প্রযোজ্য ক্ষেত্রে), কালো/নীল বলপয়েন্ট কলম, পেন্সিল, রাবার, স্কেল ও অনুমোদিত সাধারণ ক্যালকুলেটর।',
            'barred_text' => 'মোবাইল ফোন, স্মার্টওয়াচ, ব্লুটুথ বা যেকোনো ইলেকট্রনিক ডিভাইস, প্রোগ্রামেবল ক্যালকুলেটর, ডিজিটাল ঘড়ি, বই, খাতা, নোট, কাগজের টুকরো ও কোনো প্রকার অসদুপায়ের সামগ্রী পরীক্ষার কক্ষে আনা সম্পূর্ণ নিষিদ্ধ।',
            'is_custom'   => false,
        ];
    }
}

if (!function_exists('ac_ensure_instructions_table')) {
    function ac_ensure_instructions_table(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admit_card_instructions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL DEFAULT 0,
                note_text TEXT NULL,
                heading VARCHAR(255) NULL,
                rules_text MEDIUMTEXT NULL,
                carry_text TEXT NULL,
                barred_text TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_branch (branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('ac_load_instructions')) {
    function ac_load_instructions(PDO $pdo, ?int $branch_id = null): array
    {
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;
        try {
            ac_ensure_instructions_table($pdo);
            $stmt = $pdo->prepare("SELECT * FROM admit_card_instructions WHERE branch_id = ? LIMIT 1");
            $stmt->execute([$bid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row && $bid > 0) {
                $stmt->execute([0]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($row) {
                return [
                    'note_text'       => (string) ($row['note_text'] ?? ''),
                    'heading'         => (string) ($row['heading'] ?? ''),
                    'rules_text'      => (string) ($row['rules_text'] ?? ''),
                    'carry_text'      => (string) ($row['carry_text'] ?? ''),
                    'barred_text'     => (string) ($row['barred_text'] ?? ''),
                    'is_custom'       => true,
                    'scope_branch_id' => (int) ($row['branch_id'] ?? $bid),
                ];
            }
        } catch (Throwable $e) {
            // DB সমস্যা হলে নিরাপদে সরকারি ডিফল্ট রিটার্ন করবে
        }
        $def = ac_default_instructions();
        $def['scope_branch_id'] = $bid;
        return $def;
    }
}

if (!function_exists('ac_save_instructions')) {
    function ac_save_instructions(PDO $pdo, ?int $branch_id, array $data): void
    {
        ac_ensure_instructions_table($pdo);
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;
        $note   = trim((string) ($data['note_text'] ?? ''));
        $head   = mb_substr(trim((string) ($data['heading'] ?? '')), 0, 255);
        $rules  = trim((string) ($data['rules_text'] ?? ''));
        $carry  = trim((string) ($data['carry_text'] ?? ''));
        $barred = trim((string) ($data['barred_text'] ?? ''));

        $stmt = $pdo->prepare(
            "INSERT INTO admit_card_instructions (branch_id, note_text, heading, rules_text, carry_text, barred_text)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                note_text = VALUES(note_text),
                heading = VALUES(heading),
                rules_text = VALUES(rules_text),
                carry_text = VALUES(carry_text),
                barred_text = VALUES(barred_text)"
        );
        $stmt->execute([$bid, $note, $head, $rules, $carry, $barred]);
    }
}

if (!function_exists('ac_reset_instructions')) {
    function ac_reset_instructions(PDO $pdo, ?int $branch_id = null): void
    {
        ac_ensure_instructions_table($pdo);
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;
        $stmt = $pdo->prepare("DELETE FROM admit_card_instructions WHERE branch_id = ?");
        $stmt->execute([$bid]);
    }
}

if (!function_exists('ac_default_settings')) {
    /**
     * হোয়াইট-লেবেল অ্যাডমিট কার্ড কাস্টমাইজেশনের ডিফল্ট সেটিংস (কোনো নির্দিষ্ট স্কুলের হার্ডকোড ডাটা ছাড়া)।
     */
    function ac_default_settings(): array
    {
        return [
            'default_layout'        => '4rect',
            'default_ori'           => 'portrait',
            'default_w'             => 94.0,
            'default_h'             => 135.0,
            'full_template'         => 'classic',

            'card_title'            => 'Admit Card',
            'show_nb_note'          => 1,
            'nb_note_text'          => 'N.B: This Admit Card must be carried in the exam hall. Without this card no one will be allowed to sit for the exam.',
            'show_compact_note'     => 1,
            'compact_note_text'     => 'পরীক্ষার হলে এই কার্ডটি অবশ্যই সঙ্গে আনতে হবে।',

            'show_logo'             => 1,
            'logo_path'             => '',
            'show_institute_name'   => 1,
            'custom_institute_name' => '',
            'show_slogan'           => 1,
            'custom_slogan'         => '',
            'show_address'          => 1,
            'show_address_half'     => 0,
            'custom_address'        => '',
            'show_phone'            => 1,
            'custom_phone'          => '',
            'show_email'            => 1,
            'custom_email'          => '',
            'show_website'          => 1,
            'custom_website'        => '',
            'show_eiin'             => 1,
            'custom_eiin'           => '',
            'show_issue_date'       => 1,

            'show_sig_principal'    => 1,
            'sig_principal_path'    => '',
            'sig_principal_name'    => '',
            'sig_principal_label'   => '',
            'show_sig_secondary'    => 1,
            'sig_secondary_path'    => '',
            'sig_secondary_name'    => '',
            'sig_secondary_label'   => '',
            'show_sig_candidate'    => 1,
            'sig_candidate_label'   => 'Signature of the Candidate',

            'show_photo'            => 1,
            'show_qr'               => 1,
            'show_student_id'       => 1,
            'show_roll'             => 1,
            'show_class'            => 1,
            'show_group'            => 1,
            'show_shift'            => 1,
            'show_section'          => 1,
            'show_branch'           => 1,
            'show_session'          => 1,
            'show_dob'              => 1,
            'show_father_name'      => 1,
            'show_mother_name'      => 1,
            'show_student_address'  => 1,
            'show_gender'           => 1,
            'show_religion'         => 1,
            'show_blood_group'      => 1,
            'show_exam_schedule'    => 1,
            'show_instructions'     => 1,

            'show_version'           => 1,
            'custom_version'         => '',
            'show_category'          => 1,
            'custom_category'        => 'General',
            'show_age'               => 1,
            'show_birth_reg'         => 1,
            'show_student_mobile'    => 1,
            'show_permanent_address' => 1,
            'show_routine'           => 1,
            'routine_mode'           => 'auto_db',
            'custom_routine_rows'    => [],
            'schedule_exam_date'     => '',
            'schedule_result_date'   => '',

            'is_custom'             => false,
            'scope_branch_id'       => 0,
        ];
    }
}

if (!function_exists('ac_ensure_settings_table')) {
    function ac_ensure_settings_table(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admit_card_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL DEFAULT 0,
                default_layout VARCHAR(30) NOT NULL DEFAULT '4rect',
                default_ori VARCHAR(15) NOT NULL DEFAULT 'portrait',
                default_w DECIMAL(5,1) NOT NULL DEFAULT 94.0,
                default_h DECIMAL(5,1) NOT NULL DEFAULT 135.0,
                full_template VARCHAR(30) NOT NULL DEFAULT 'classic',
                logo_path VARCHAR(255) NULL,
                sig_principal_path VARCHAR(255) NULL,
                sig_secondary_path VARCHAR(255) NULL,
                config_json MEDIUMTEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_branch (branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('ac_load_settings')) {
    function ac_load_settings(PDO $pdo, ?int $branch_id = null): array
    {
        $def = ac_default_settings();
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;
        try {
            ac_ensure_settings_table($pdo);
            $stmt = $pdo->prepare("SELECT * FROM admit_card_settings WHERE branch_id = ? LIMIT 1");
            $stmt->execute([$bid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row && $bid > 0) {
                $stmt->execute([0]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($row) {
                $extra = json_decode((string) ($row['config_json'] ?? ''), true);
                $cfg = is_array($extra) ? array_merge($def, $extra) : $def;
                $cfg['default_layout']     = (string) ($row['default_layout'] ?? $def['default_layout']);
                $cfg['default_ori']        = (string) ($row['default_ori'] ?? $def['default_ori']);
                $cfg['default_w']          = (float) ($row['default_w'] ?? $def['default_w']);
                $cfg['default_h']          = (float) ($row['default_h'] ?? $def['default_h']);
                $cfg['full_template']      = (string) ($row['full_template'] ?? $def['full_template']);
                $cfg['logo_path']          = (string) ($row['logo_path'] ?? '');
                $cfg['sig_principal_path'] = (string) ($row['sig_principal_path'] ?? '');
                $cfg['sig_secondary_path'] = (string) ($row['sig_secondary_path'] ?? '');
                $cfg['is_custom']          = true;
                $cfg['scope_branch_id']    = (int) ($row['branch_id'] ?? $bid);
                return $cfg;
            }
        } catch (Throwable $e) {
            // DB সমস্যা হলে নিরাপদ ডিফল্ট রিটার্ন করবে
        }
        $def['scope_branch_id'] = $bid;
        return $def;
    }
}

if (!function_exists('ac_save_settings')) {
    function ac_save_settings(PDO $pdo, ?int $branch_id, array $in): void
    {
        ac_ensure_settings_table($pdo);
        $def = ac_default_settings();
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;

        $allowed_layouts = ['4rect', '4sq', '3rect', '2half', '1full', 'custom'];
        $dl = (string) ($in['default_layout'] ?? $def['default_layout']);
        if (!in_array($dl, $allowed_layouts, true)) { $dl = '4rect'; }
        $ori = (($in['default_ori'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';
        $dw = max(50.0, min(287.0, (float) ($in['default_w'] ?? 94.0)));
        $dh = max(40.0, min(287.0, (float) ($in['default_h'] ?? 135.0)));
        $ft = preg_replace('/[^a-z0-9_-]/i', '', (string) ($in['full_template'] ?? 'classic')) ?: 'classic';
        if (!in_array($ft, ['classic', 'modern'], true)) { $ft = 'classic'; }

        $logo = trim((string) ($in['logo_path'] ?? ''));
        $sig_p = trim((string) ($in['sig_principal_path'] ?? ''));
        $sig_s = trim((string) ($in['sig_secondary_path'] ?? ''));

        $payload = [];
        foreach ($def as $k => $v) {
            if (in_array($k, ['default_layout', 'default_ori', 'default_w', 'default_h', 'full_template', 'logo_path', 'sig_principal_path', 'sig_secondary_path', 'is_custom', 'scope_branch_id'], true)) {
                continue;
            }
            if ($k === 'routine_mode') {
                $rm = trim((string) ($in[$k] ?? 'auto_db'));
                $payload[$k] = in_array($rm, ['auto_db', 'custom_table', 'admission_schedule'], true) ? $rm : 'auto_db';
            } elseif (is_array($v)) {
                $rows_in = is_array($in[$k] ?? null) ? $in[$k] : [];
                $clean_rows = [];
                foreach ($rows_in as $rr) {
                    if (!is_array($rr)) { continue; }
                    $r_date  = mb_substr(trim((string) ($rr['date'] ?? '')), 0, 100);
                    $r_time  = mb_substr(trim((string) ($rr['time'] ?? '')), 0, 100);
                    $r_subj  = mb_substr(trim((string) ($rr['subject'] ?? '')), 0, 120);
                    $r_marks = mb_substr(trim((string) ($rr['full_marks'] ?? '')), 0, 40);
                    if ($r_date === '' && $r_time === '' && $r_subj === '' && $r_marks === '') { continue; }
                    $clean_rows[] = ['date' => $r_date, 'time' => $r_time, 'subject' => $r_subj, 'full_marks' => $r_marks];
                    if (count($clean_rows) >= 20) { break; }
                }
                $payload[$k] = $clean_rows;
            } elseif (is_int($v)) {
                $payload[$k] = !empty($in[$k]) ? 1 : 0;
            } else {
                $payload[$k] = mb_substr(trim((string) ($in[$k] ?? $v)), 0, 500);
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare(
            "INSERT INTO admit_card_settings
                (branch_id, default_layout, default_ori, default_w, default_h, full_template, logo_path, sig_principal_path, sig_secondary_path, config_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                default_layout = VALUES(default_layout),
                default_ori = VALUES(default_ori),
                default_w = VALUES(default_w),
                default_h = VALUES(default_h),
                full_template = VALUES(full_template),
                logo_path = VALUES(logo_path),
                sig_principal_path = VALUES(sig_principal_path),
                sig_secondary_path = VALUES(sig_secondary_path),
                config_json = VALUES(config_json)"
        );
        $stmt->execute([$bid, $dl, $ori, $dw, $dh, $ft, $logo ?: null, $sig_p ?: null, $sig_s ?: null, $json]);
    }
}

if (!function_exists('ac_reset_settings')) {
    /**
     * কাস্টম সেটিংস মুছে পুরনো আপলোড করা ইমেজ পাথগুলো রিটার্ন করে।
     */
    function ac_reset_settings(PDO $pdo, ?int $branch_id = null): array
    {
        ac_ensure_settings_table($pdo);
        $bid = $branch_id !== null ? max(0, (int) $branch_id) : 0;
        $q = $pdo->prepare("SELECT logo_path, sig_principal_path, sig_secondary_path FROM admit_card_settings WHERE branch_id = ? LIMIT 1");
        $q->execute([$bid]);
        $old = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $del = $pdo->prepare("DELETE FROM admit_card_settings WHERE branch_id = ?");
        $del->execute([$bid]);
        return array_values(array_filter([
            $old['logo_path'] ?? '',
            $old['sig_principal_path'] ?? '',
            $old['sig_secondary_path'] ?? '',
        ], static fn($v) => trim((string) $v) !== ''));
    }
}

if (!function_exists('ac_cfg')) {
    function ac_cfg(array $d): array
    {
        $def = ac_default_settings();
        $in = is_array($d['settings'] ?? null) ? $d['settings'] : [];
        return array_merge($def, $in);
    }
}

if (!function_exists('ac_resolve_header')) {
    function ac_resolve_header(array $d, array $S): array
    {
        $custom_inst = trim((string) ($S['custom_institute_name'] ?? ''));
        $inst = $custom_inst !== '' ? $custom_inst : trim((string) ($d['institute'] ?? ''));

        $custom_logo = trim((string) ($S['logo_path'] ?? ''));
        $logo = ac_asset_url($custom_logo !== '' ? $custom_logo : ($d['logo'] ?? ''));

        $custom_slogan = trim((string) ($S['custom_slogan'] ?? ''));
        $slogan = $custom_slogan !== '' ? $custom_slogan : trim((string) ($d['slogan'] ?? ''));

        $custom_addr = trim((string) ($S['custom_address'] ?? ''));
        $address = $custom_addr !== '' ? $custom_addr : trim((string) ($d['address'] ?? ''));

        $custom_phone = trim((string) ($S['custom_phone'] ?? ''));
        $phone = $custom_phone !== '' ? $custom_phone : trim((string) ($d['phone'] ?? ''));

        $custom_email = trim((string) ($S['custom_email'] ?? ''));
        $email = $custom_email !== '' ? $custom_email : trim((string) ($d['email'] ?? ''));

        $custom_web = trim((string) ($S['custom_website'] ?? ''));
        $website = $custom_web !== '' ? $custom_web : trim((string) ($d['website'] ?? ''));

        $custom_eiin = trim((string) ($S['custom_eiin'] ?? ''));
        $eiin = $custom_eiin !== '' ? $custom_eiin : trim((string) ($d['eiin'] ?? ''));

        $p_path = trim((string) ($S['sig_principal_path'] ?? ''));
        $sig_p_img = ac_asset_url($p_path !== '' ? $p_path : ($d['branch_signature'] ?? ''));
        $sig_p_name = trim((string) ($S['sig_principal_name'] ?? ''));
        $sig_p_label = trim((string) ($S['sig_principal_label'] ?? '')) ?: trim((string) ($d['sig_right_label'] ?? ''));

        $s_path = trim((string) ($S['sig_secondary_path'] ?? ''));
        $sig_s_img = ac_asset_url($s_path !== '' ? $s_path : ($d['secondary_signature'] ?? ''));
        $sig_s_name = trim((string) ($S['sig_secondary_name'] ?? ''));
        $sig_s_label = trim((string) ($S['sig_secondary_label'] ?? '')) ?: trim((string) ($d['sig_left_label'] ?? ''));

        $card_title = trim((string) ($S['card_title'] ?? '')) ?: 'Admit Card';

        return compact(
            'inst', 'logo', 'slogan', 'address', 'phone', 'email', 'website', 'eiin',
            'sig_p_img', 'sig_p_name', 'sig_p_label',
            'sig_s_img', 'sig_s_name', 'sig_s_label',
            'card_title'
        );
    }
}

// ------------------------------------------------------------------
// Admit Card ডিজাইন (Result > Download Admit Card)
// ------------------------------------------------------------------
if (!function_exists('ac_layout')) {
    /**
     * Admit Card লেআউট: preset (4rect | 4sq | 3rect | 2half | 1full | custom) + custom হলে ori/w/h (mm)।
     * A4, মার্জিন ৮mm, কার্ডের মাঝে ৪mm gap। রিটার্ন: ori, w, h (mm), cols, rows, per, gap, mode, dw (design px), k (scale)
     */
    function ac_layout(array $in = []): array
    {
        $presets = [
            '4rect' => ['portrait', 94, 135],
            '4sq'   => ['portrait', 94, 94],
            '3rect' => ['portrait', 194, 90],
            '2half' => ['portrait', 193, 118],
            '1full' => ['portrait', 193, 278],
        ];
        $preset = (string) ($in['preset'] ?? '4rect');
        if ($preset !== 'custom' && !isset($presets[$preset])) { $preset = '4rect'; }
        if ($preset === 'custom') {
            $ori = (($in['ori'] ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';
            $w = (float) ($in['w'] ?? 94); $h = (float) ($in['h'] ?? 94);
        } else {
            [$ori, $w, $h] = $presets[$preset];
        }
        $gap = ($preset === '2half') ? 28 : 4;
        $margin_x = 8;
        $margin_y = ($preset === '2half') ? 14 : 8;
        $pw = ($ori === 'landscape' ? 297 : 210) - 2 * $margin_x;
        $ph = ($ori === 'landscape' ? 210 : 297) - 2 * $margin_y;
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
            'mx' => $margin_x, 'my' => $margin_y,
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
    .ac-page { margin: 0 !important; border: none !important; box-sizing: border-box !important; width: 100% !important; page-break-after: always; break-after: page; }
    .ac-page:last-child { page-break-after: auto; break-after: auto; }
    .ac-pages { overflow: visible !important; }
    .ac-cell .admitcard2, .ac-cell .admit-half, .ac-cell .admit-full { box-shadow: none !important; }
}
.ac-pages { display: block; overflow-x: auto; }
.ac-page { display: grid; justify-content: center; align-content: start; margin: 0 auto 18px; padding: 8px; border: 1px dashed #cbd5e1; }
.ac-cell { position: relative; overflow: hidden; border-radius: 10px; }
.ac-cell.ac-cell-half, .ac-cell.ac-cell-full { border-radius: 0; overflow: hidden; }
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
    padding: 18px 14px 7px; background: #f8fafc; border-top: 1px solid #e2e8f0;
}
.admitcard2 .ad-foot.has-sig-img { padding-top: 5px; }
.admitcard2 .ad-sign { flex: 1; text-align: center; font-size: 9.5px; color: #475569; }
.admitcard2 .ad-sign-img { height: 22px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 2px; }
.admitcard2 .ad-sign-img img { max-height: 20px; max-width: 90px; object-fit: contain; }
.admitcard2 .ad-sign-line { border-top: 1px solid #64748b; padding-top: 2px; }
.admitcard2 .ad-sign-name { font-weight: 700; color: #0f172a; font-size: 9.5px; line-height: 1.15; }
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

/* ====== ২ টা / পাতা — Half Page (Image #2 style, 193mm x 118mm) ====== */
.admit-half {
    width: 100%; height: 100%; box-sizing: border-box; background: #fff; border: 1.5px solid #1e293b;
    display: flex; flex-direction: column; font-family: 'Segoe UI', 'Noto Sans Bengali', Arial, sans-serif;
    color: #111827; text-align: left; page-break-inside: avoid; break-inside: avoid;
}
.admit-half .ah-head {
    display: flex; align-items: center; gap: 8px; padding: 6px 12px 5px; border-bottom: 1.3px solid #334155;
}
.admit-half .ah-logo {
    width: 50px; height: 50px; flex: 0 0 50px; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.admit-half .ah-head-spacer { width: 12px; flex: 0 0 12px; }
.admit-half .ah-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.admit-half .ah-logo span {
    width: 44px; height: 44px; border-radius: 50%; border: 2px solid #1e3a8a; color: #1e3a8a;
    font-weight: 900; font-size: 19px; display: flex; align-items: center; justify-content: center;
}
.admit-half .ah-inst { flex: 1; text-align: center; min-width: 0; }
.admit-half .ah-inst-name {
    font-size: 16.5px; font-weight: 900; color: #1e3a8a; letter-spacing: .25px; line-height: 1.15;
    text-transform: uppercase; white-space: nowrap;
}
.admit-half .ah-inst-sub {
    font-size: 12px; color: #334155; font-style: italic; font-family: Georgia, 'Times New Roman', serif;
    margin-top: 2px; line-height: 1.2; overflow-wrap: break-word;
}
.admit-half .ah-inst-addr {
    font-size: 10px; color: #475569; margin-top: 1px; line-height: 1.2; overflow-wrap: break-word;
}
.admit-half .ah-inst-contact {
    font-size: 10.5px; color: #1e293b; font-weight: 600; margin-top: 2px; line-height: 1.2;
}
.admit-half .ah-title-row { position: relative; text-align: center; padding: 5px 14px 2px; }
.admit-half .ah-card-box {
    display: inline-block; border: 1.4px solid #111827; padding: 2px 36px;
    font-size: 15px; font-weight: 800; color: #0f172a; line-height: 1.25;
}
.admit-half .ah-term {
    font-size: 13px; font-weight: 700; text-decoration: underline; text-underline-offset: 2px;
    color: #111827; margin-top: 3px;
}
.admit-half .ah-issue { position: absolute; right: 14px; top: 7px; font-size: 11px; color: #111827; }
.admit-half .ah-body {
    flex: 1 1 auto; display: flex; align-items: flex-start; gap: 12px; padding: 10px 16px 2px; min-height: 0;
}
.admit-half .ah-info-wrap {
    flex: 1; min-width: 0; display: grid; grid-template-columns: 1.35fr 1fr; gap: 0 14px; align-items: end;
}
.admit-half .ah-kv { width: 100%; border-collapse: collapse; }
.admit-half .ah-kv td { padding: 3px 2px; font-size: 12.5px; color: #111827; vertical-align: top; line-height: 1.3; }
.admit-half .ah-kv td.ah-lbl { width: 82px; font-weight: 700; white-space: nowrap; }
.admit-half .ah-kv td.ah-col { width: 10px; font-weight: 700; text-align: center; }
.admit-half .ah-kv td.ah-val { font-weight: 500; overflow-wrap: break-word; }
.admit-half .ah-photo {
    width: 106px; height: 118px; flex: 0 0 106px; border: 1.5px solid #111827; background: #f8fafc;
    overflow: hidden; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px;
}
.admit-half .ah-photo img { width: 100%; height: 100%; object-fit: cover; }
.admit-half .ah-sigs {
    display: flex; justify-content: space-between; align-items: flex-end; gap: 36px; padding: 2px 32px 6px;
}
.admit-half .ah-sig { width: 40%; text-align: center; }
.admit-half .ah-sig-img { height: 28px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 2px; }
.admit-half .ah-sig-img img { max-height: 26px; max-width: 140px; object-fit: contain; }
.admit-half .ah-sig-line {
    border-top: 1.2px solid #111827; padding-top: 3px; font-size: 11.5px; font-weight: 600; color: #111827;
}
.admit-half .ah-sig-name { font-size: 11px; font-weight: 700; color: #0f172a; margin-top: 2px; line-height: 1.15; }
.admit-half .ah-sig-role { font-size: 10.5px; font-weight: 500; color: #334155; line-height: 1.15; }
.admit-half .ah-nb {
    margin: 0 14px 8px; border: 1px solid #475569; padding: 4px 8px;
    font-size: 10.5px; font-weight: 700; line-height: 1.3; color: #111827;
}

/* ====== ১ টা / পাতা — Full Page (Image #1 style, 193mm x 278mm) ====== */
.admit-full {
    width: 100%; height: 100%; box-sizing: border-box; background: #fff; border: 1.2px solid #4b5563;
    display: flex; flex-direction: column; font-family: 'Segoe UI', 'Noto Sans Bengali', Arial, sans-serif;
    color: #111827; text-align: left; overflow: hidden; page-break-inside: avoid; break-inside: avoid;
}
.admit-full .af-head {
    display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-bottom: 1px solid #6b7280;
}
.admit-full .af-logo, .admit-full .af-head-spacer {
    width: 50px; height: 50px; flex: 0 0 50px; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.admit-full .af-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.admit-full .af-logo span {
    width: 44px; height: 44px; border-radius: 50%; border: 2px solid #1e3a8a; color: #1e3a8a;
    font-weight: 800; font-size: 18px; display: flex; align-items: center; justify-content: center;
}
.admit-full .af-head-text { flex: 1; text-align: center; min-width: 0; }
.admit-full .af-inst {
    font-size: 15.5px; font-weight: 800; color: #1e293b; text-transform: uppercase;
    letter-spacing: .3px; line-height: 1.2;
}
.admit-full .af-title {
    font-size: 13px; font-weight: 700; color: #111827; text-transform: uppercase; margin-top: 2px; line-height: 1.2;
}
.admit-full .af-sub { font-size: 10px; color: #475569; margin-top: 1px; }
.admit-full .af-slogan { font-size: 10px; font-style: italic; color: #334155; margin-top: 1px; }
.admit-full .af-upper {
    display: grid; grid-template-columns: 40% 36% 24%; border-bottom: 1px solid #6b7280;
}
.admit-full .af-col-left { border-right: 1px solid #9ca3af; display: flex; flex-direction: column; min-width: 0; }
.admit-full .af-lrow {
    display: grid; grid-template-columns: 38% 62%; border-bottom: 1px solid #cbd5e1;
    padding: 4px 6px; font-size: 10px; line-height: 1.3; align-items: start;
}
.admit-full .af-lrow.af-grow { flex: 1 1 auto; }
.admit-full .af-lrow:last-child { border-bottom: 0; }
.admit-full .af-lk { color: #374151; font-weight: 600; padding-right: 4px; }
.admit-full .af-lv { color: #0f172a; font-weight: 700; overflow-wrap: break-word; }
.admit-full .af-lv-reg { font-weight: 500; }
.admit-full .af-col-mid { border-right: 1px solid #9ca3af; display: flex; flex-direction: column; min-width: 0; }
.admit-full .af-mgrid2 { display: grid; grid-template-columns: 1fr 1fr; }
.admit-full .af-mgrid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; }
.admit-full .af-mcell { border-right: 1px solid #cbd5e1; border-bottom: 1px solid #9ca3af; min-width: 0; }
.admit-full .af-mcell:last-child { border-right: 0; }
.admit-full .af-mlbl {
    background: #f8fafc; font-size: 8.5px; color: #374151; font-weight: 600;
    text-align: center; padding: 2px 4px; border-bottom: 1px solid #cbd5e1; line-height: 1.2;
}
.admit-full .af-mval {
    font-size: 10.5px; font-weight: 700; color: #0f172a; text-align: center;
    padding: 4px; line-height: 1.25; overflow-wrap: break-word;
}
.admit-full .af-mval-lg { font-size: 12px; }
.admit-full .af-mband-title {
    background: #f1f5f9; font-size: 8.5px; font-weight: 700; color: #1e293b; text-align: center;
    padding: 2px 4px; border-bottom: 1px solid #cbd5e1; text-transform: uppercase; letter-spacing: .3px;
}
.admit-full .af-msig {
    flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
    padding: 4px 6px 5px; text-align: center; min-height: 44px;
}
.admit-full .af-msig img { max-height: 26px; max-width: 120px; object-fit: contain; margin-bottom: 2px; }
.admit-full .af-msig-name { font-size: 9.5px; font-weight: 700; color: #0f172a; line-height: 1.15; }
.admit-full .af-msig-lbl { font-size: 9.5px; font-weight: 700; color: #1f2937; }
.admit-full .af-col-right { display: flex; flex-direction: column; min-width: 0; }
.admit-full .af-roll-box { border-bottom: 1px solid #9ca3af; text-align: center; }
.admit-full .af-roll-lbl {
    background: #f8fafc; font-size: 9px; font-weight: 700; color: #374151;
    padding: 2px 4px; border-bottom: 1px solid #cbd5e1;
}
.admit-full .af-roll-val { font-size: 16px; font-weight: 800; color: #0f172a; padding: 4px; line-height: 1.2; }
.admit-full .af-photo-wrap {
    flex: 1 1 auto; display: flex; align-items: center; justify-content: center;
    padding: 7px; border-bottom: 1px solid #9ca3af;
}
.admit-full .af-photo {
    width: 112px; height: 132px; border: 1px solid #6b7280; background: #f8fafc; overflow: hidden;
    display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px;
}
.admit-full .af-photo img { width: 100%; height: 100%; object-fit: cover; }
.admit-full .af-cand-sig {
    height: 70px; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; padding: 4px 6px 5px;
}
.admit-full .af-cand-sig-lbl {
    font-size: 9.5px; color: #1f2937; font-weight: 600; border-top: 1px dashed #cbd5e1;
    width: 92%; text-align: center; padding-top: 2px;
}
.admit-full .af-rules-sec {
    flex: 1 1 auto; padding: 6px 10px 7px; display: flex; flex-direction: column; gap: 4px;
    overflow: hidden; font-size: 9.3px; line-height: 1.34; color: #1f2937;
}
.admit-full .af-note { color: #b91c1c; font-size: 9px; line-height: 1.32; text-align: justify; }
.admit-full .af-rules-title { font-size: 10px; font-weight: 800; color: #111827; margin-top: 2px; }
.admit-full .af-rule-item { margin-bottom: 2px; text-align: justify; }
.admit-full .af-rules-ol { margin: 0; padding-left: 16px; }
.admit-full .af-rules-ol li { margin-bottom: 2px; text-align: justify; }
.admit-full .af-strip { font-size: 9px; line-height: 1.32; text-align: justify; }
.admit-full .af-strip-lbl { color: #b91c1c; font-weight: 800; }

/* ====== ১ টা / পাতা — Template 2: Modern Routine & QR (193mm x 278mm) ====== */
.admit-modern {
    width: 100%; height: 100%; box-sizing: border-box; background: #fff;
    padding: 10px 14px 8px; display: flex; flex-direction: column;
    font-family: 'Segoe UI', 'Noto Sans Bengali', Arial, sans-serif;
    color: #111827; text-align: left; overflow: hidden; page-break-inside: avoid; break-inside: avoid;
}
.admit-modern .am-head {
    display: flex; align-items: flex-start; gap: 12px; margin-bottom: 5px;
}
.admit-modern .am-logo {
    width: 58px; height: 58px; flex: 0 0 58px; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.admit-modern .am-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.admit-modern .am-logo span {
    width: 48px; height: 48px; border-radius: 50%; border: 2px solid #16285B; color: #16285B;
    font-weight: 800; font-size: 20px; display: flex; align-items: center; justify-content: center;
}
.admit-modern .am-inst { flex: 1; min-width: 0; }
.admit-modern .am-inst-name { font-size: 19.5px; font-weight: 600; color: #111827; line-height: 1.18; margin-bottom: 2px; }
.admit-modern .am-inst-slogan { font-size: 11px; font-style: italic; color: #374151; line-height: 1.25; }
.admit-modern .am-inst-addr { font-size: 10.5px; color: #374151; line-height: 1.3; }
.admit-modern .am-inst-contact { font-size: 10.5px; color: #1f2937; line-height: 1.3; margin-top: 1px; }
.admit-modern .am-qr-box { flex: 0 0 66px; text-align: center; }
.admit-modern .am-qr-box .ad-qr { width: 60px; height: 60px; margin: 0 auto; padding: 2px; background: #fff; border: 1px solid #cbd5e1; }
.admit-modern .am-qr-box .ad-qr img, .admit-modern .am-qr-box .ad-qr canvas { width: 100% !important; height: 100% !important; }
.admit-modern .am-qr-cap { font-size: 8px; color: #64748b; margin-top: 2px; }
.admit-modern .am-title { text-align: center; font-size: 15px; font-weight: 700; color: #0f172a; margin: 3px 0 6px; }
.admit-modern .am-exam-box {
    border: 1.1px solid #374151; background: #f3f4f6; padding: 5px 10px 6px; margin-bottom: 8px;
}
.admit-modern .am-exam-banner { text-align: center; font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
.admit-modern .am-meta-grid { width: 100%; border-collapse: collapse; font-size: 11px; }
.admit-modern .am-meta-grid td { padding: 2px 4px; vertical-align: top; line-height: 1.3; }
.admit-modern .am-mk { font-weight: 700; color: #1f2937; white-space: nowrap; width: 12%; }
.admit-modern .am-mv { color: #111827; font-weight: 500; width: 21%; overflow-wrap: break-word; }
.admit-modern .am-cand-wrap {
    display: flex; align-items: flex-start; gap: 12px; background: #f8fafc; padding: 7px 10px;
    margin-bottom: 8px; border: 1px solid #e5e7eb;
}
.admit-modern .am-cand-tbl { flex: 1; width: 100%; border-collapse: collapse; font-size: 11px; }
.admit-modern .am-cand-tbl td { padding: 2.2px 4px; vertical-align: top; line-height: 1.3; }
.admit-modern .am-ck { font-weight: 700; color: #1f2937; width: 114px; white-space: nowrap; }
.admit-modern .am-ck2 { font-weight: 700; color: #1f2937; width: 58px; white-space: nowrap; padding-left: 10px; }
.admit-modern .am-photo {
    width: 96px; height: 112px; flex: 0 0 96px; border: 1px solid #cbd5e1; background: #fff;
    overflow: hidden; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 11px;
}
.admit-modern .am-photo img { width: 100%; height: 100%; object-fit: cover; }
.admit-modern .am-routine-box {
    border: 1.1px solid #374151; background: #f8fafc; padding: 6px 10px; margin-bottom: 8px;
}
.admit-modern .am-routine-title { text-align: center; font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 5px; }
.admit-modern .am-routine-tbl { width: 100%; border-collapse: collapse; font-size: 10px; background: #fff; }
.admit-modern .am-routine-tbl th, .admit-modern .am-routine-tbl td { border: 1px solid #9ca3af; padding: 3px 6px; text-align: left; }
.admit-modern .am-routine-tbl th { background: #e5e7eb; font-weight: 700; color: #111827; }
.admit-modern .am-routine-tbl .am-rc { text-align: center; }
.admit-modern .am-sched-row {
    display: flex; justify-content: space-around; align-items: center; gap: 12px; font-size: 11px; padding: 3px 0; flex-wrap: wrap;
}
.admit-modern .am-rules { flex: 0 1 auto; font-size: 9.8px; line-height: 1.38; color: #111827; margin-bottom: 6px; overflow: hidden; }
.admit-modern .am-rules-head { font-weight: 700; font-size: 11px; margin-bottom: 3px; color: #0f172a; }
.admit-modern .am-rules-note { color: #b91c1c; font-size: 9.3px; margin-bottom: 3px; text-align: justify; }
.admit-modern .am-rule-item { margin-bottom: 2px; text-align: justify; }
.admit-modern .am-rules-ol { margin: 0; padding-left: 18px; }
.admit-modern .am-rules-ol li { margin-bottom: 2px; text-align: justify; }
.admit-modern .am-rules-strip { font-size: 9.3px; margin-top: 3px; text-align: justify; }
.admit-modern .am-sig-row {
    display: flex; justify-content: flex-end; margin-top: 4px; margin-bottom: 6px; padding-right: 12px;
}
.admit-modern .am-sig-box { width: 155px; text-align: center; }
.admit-modern .am-sig-img { height: 32px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 2px; }
.admit-modern .am-sig-img img { max-height: 30px; max-width: 145px; object-fit: contain; }
.admit-modern .am-sig-line { border-top: 1.2px solid #111827; padding-top: 2px; font-size: 11px; font-weight: 700; color: #0f172a; }
.admit-modern .am-sig-name { font-size: 10.5px; font-weight: 700; color: #0f172a; line-height: 1.15; }
.admit-modern .am-sig-role { font-size: 10px; font-weight: 600; color: #374151; line-height: 1.15; }
.admit-modern .am-foot {
    margin-top: auto; border-top: 1px solid #cbd5e1; padding-top: 5px;
    display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; font-size: 9.5px; color: #374151;
}
.admit-modern .am-foot-left { text-align: left; line-height: 1.35; }
.admit-modern .am-foot-right { text-align: right; line-height: 1.15; }
.admit-modern .am-pow-lbl { font-size: 8.5px; font-style: italic; color: #475569; }
.admit-modern .am-pow-logo { height: 16px; width: auto; display: block; margin: 2px 0 1px auto; }
.admit-modern .am-pow-url { font-size: 8.5px; font-weight: 600; color: #16285B; }
</style>
CSS;
        $my = (int) ($L['my'] ?? 8);
        $mx = (int) ($L['mx'] ?? 8);
        $page = '@page { size: A4 ' . $L['ori'] . '; margin: 0; }';
        $page_pad = ($L['preset'] === '2half') ? 'padding: ' . $my . 'mm ' . $mx . 'mm;' : '';
        $dyn  = '.ac-page { grid-template-columns: repeat(' . (int) $L['cols'] . ', ' . $L['w'] . 'mm); gap: ' . (int) $L['gap'] . 'mm;' . $page_pad . ' }'
              . '.ac-cell { width: ' . $L['w'] . 'mm; height: ' . $L['h'] . 'mm; }'
              . '.admitcard2 { width: ' . $L['dw'] . 'px; height: ' . round($L['dh'], 2) . 'px; transform: scale(' . round($L['k'], 5) . '); }';
        $print_pad = '@media print { .ac-page { padding: ' . $my . 'mm ' . $mx . 'mm 0 ' . $mx . 'mm !important; } }';
        return str_replace(['<style>', '</style>'], ['<style>' . $page, $dyn . $print_pad . '</style>'], $css);
    }
}

if (!function_exists('ac_card_half')) {
    function ac_card_half(array $d): string
    {
        $S = ac_cfg($d);
        $H = ac_resolve_header($d, $S);
        $photo = !empty($S['show_photo']) ? ac_asset_url($d['photo'] ?? '') : '';
        $inst = $H['inst'];

        $contact_parts = [];
        if (!empty($S['show_phone']) && $H['phone'] !== '') { $contact_parts[] = 'Mobile: ' . $H['phone']; }
        if (!empty($S['show_email']) && $H['email'] !== '') { $contact_parts[] = 'Email: ' . $H['email']; }
        if (!empty($S['show_website']) && $H['website'] !== '') { $contact_parts[] = $H['website']; }

        $inst_len = mb_strlen($inst);
        if ($inst_len <= 22)      { $inst_fs = '21px'; }
        elseif ($inst_len <= 32)  { $inst_fs = '18.5px'; }
        elseif ($inst_len <= 45)  { $inst_fs = '16.5px'; }
        elseif ($inst_len <= 56)  { $inst_fs = '14.5px'; }
        else                      { $inst_fs = '13px'; }

        $term = trim((string) ($d['term'] ?? ''));
        $issue_date = trim((string) ($d['issue_date'] ?? '')) ?: date('d/m/Y');

        $show_head_logo = !empty($S['show_logo']);
        $show_head_name = !empty($S['show_institute_name']) && $inst !== '';
        $show_head_sub  = !empty($S['show_slogan']) && $H['slogan'] !== '';
        $show_head_addr = !empty($S['show_address']) && !empty($S['show_address_half']) && $H['address'] !== '';
        $show_header = $show_head_logo || $show_head_name || $show_head_sub || $show_head_addr || !empty($contact_parts);

        $left_rows = [['Name', trim((string) ($d['name'] ?? '')) ?: '—']];
        if (!empty($S['show_student_id'])) { $left_rows[] = ['Student ID', trim((string) ($d['student_id'] ?? '')) ?: '—']; }
        if (!empty($S['show_roll']))       { $left_rows[] = ['Roll No',    trim((string) ($d['roll'] ?? '')) ?: '—']; }
        if (!empty($S['show_class']))      { $left_rows[] = ['Class',      trim((string) ($d['class'] ?? '')) ?: '—']; }
        if (!empty($S['show_group']))      { $left_rows[] = ['Group',      trim((string) ($d['group'] ?? '')) ?: 'None']; }
        if (!empty($S['show_section']))    { $left_rows[] = ['Section',    trim((string) ($d['section'] ?? '')) ?: '—']; }

        $mid_rows = [];
        if (!empty($S['show_branch']))  { $mid_rows[] = ['Branch',  trim((string) ($d['branch_name'] ?? '')) ?: ($inst ?: '—')]; }
        if (!empty($S['show_shift']))   { $mid_rows[] = ['Shift',   trim((string) ($d['shift'] ?? '')) ?: '—']; }
        if (!empty($S['show_session'])) { $mid_rows[] = ['Session', trim((string) ($d['session'] ?? '')) ?: (trim((string) ($d['year'] ?? '')) ?: '—')]; }

        $sigs = [];
        if (!empty($S['show_sig_principal'])) {
            $sigs[] = ['img' => $H['sig_p_img'], 'name' => $H['sig_p_name'], 'label' => $H['sig_p_label'] ?: 'Principal'];
        }
        if (!empty($S['show_sig_secondary'])) {
            $sigs[] = ['img' => $H['sig_s_img'], 'name' => $H['sig_s_name'], 'label' => $H['sig_s_label'] ?: 'Class Teacher'];
        }
        $nb_text = (!empty($S['show_nb_note'])) ? trim((string) ($S['nb_note_text'] ?? '')) : '';

        ob_start(); ?>
        <div class="ac-cell ac-cell-half"><div class="admit-half">
            <?php if ($show_header): ?>
            <div class="ah-head">
                <?php if ($show_head_logo): ?>
                <div class="ah-logo">
                    <?php if ($H['logo'] !== ''): ?><img src="<?php echo sc_e($H['logo']); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr($inst !== '' ? $inst : 'A', 0, 1)); ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="ah-inst">
                    <?php if ($show_head_name): ?><div class="ah-inst-name" style="font-size:<?php echo $inst_fs; ?>;"><?php echo sc_e($inst); ?></div><?php endif; ?>
                    <?php if ($show_head_sub): ?><div class="ah-inst-sub"><?php echo sc_e($H['slogan']); ?></div><?php endif; ?>
                    <?php if ($show_head_addr): ?><div class="ah-inst-addr"><?php echo sc_e($H['address']); ?></div><?php endif; ?>
                    <?php if ($contact_parts): ?><div class="ah-inst-contact"><?php echo sc_e(implode('  |  ', $contact_parts)); ?></div><?php endif; ?>
                </div>
                <?php if ($show_head_logo): ?><div class="ah-head-spacer"></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="ah-title-row">
                <span class="ah-card-box"><?php echo sc_e($H['card_title']); ?></span>
                <?php if ($term !== ''): ?><div class="ah-term"><?php echo sc_e($term); ?></div><?php endif; ?>
                <?php if (!empty($S['show_issue_date'])): ?><div class="ah-issue"><strong>Issue Date:</strong> <?php echo sc_e($issue_date); ?></div><?php endif; ?>
            </div>
            <div class="ah-body">
                <div class="ah-info-wrap"<?php echo empty($mid_rows) ? ' style="grid-template-columns:1fr;"' : ''; ?>>
                    <table class="ah-kv">
                        <?php foreach ($left_rows as [$k, $v]): ?>
                        <tr>
                            <td class="ah-lbl"><?php echo sc_e($k); ?></td>
                            <td class="ah-col">:</td>
                            <td class="ah-val"><?php echo sc_e($v); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if ($mid_rows): ?>
                    <table class="ah-kv">
                        <?php foreach ($mid_rows as [$k, $v]): ?>
                        <tr>
                            <td class="ah-lbl"><?php echo sc_e($k); ?></td>
                            <td class="ah-col">:</td>
                            <td class="ah-val"><?php echo sc_e($v); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php endif; ?>
                </div>
                <?php if (!empty($S['show_photo'])): ?>
                <div class="ah-photo">
                    <?php if ($photo !== ''): ?><img src="<?php echo sc_e($photo); ?>" alt="Photo">
                    <?php else: ?>Photo<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($sigs): ?>
            <div class="ah-sigs"<?php echo count($sigs) === 1 ? ' style="justify-content:flex-end;"' : ''; ?>>
                <?php foreach ($sigs as $sg): ?>
                <div class="ah-sig">
                    <div class="ah-sig-img">
                        <?php if ($sg['img'] !== ''): ?><img src="<?php echo sc_e($sg['img']); ?>" alt="Signature"><?php endif; ?>
                    </div>
                    <?php if ($sg['name'] !== ''): ?>
                        <div class="ah-sig-line">
                            <div class="ah-sig-name"><?php echo sc_e($sg['name']); ?></div>
                            <div class="ah-sig-role"><?php echo sc_e($sg['label']); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="ah-sig-line"><?php echo sc_e($sg['label']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($nb_text !== ''): ?><div class="ah-nb"><?php echo sc_e($nb_text); ?></div><?php endif; ?>
        </div></div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('ac_card_full_modern')) {
    function ac_card_full_modern(array $d): string
    {
        $S = ac_cfg($d);
        $H = ac_resolve_header($d, $S);
        $photo = !empty($S['show_photo']) ? ac_asset_url($d['photo'] ?? '') : '';
        $inst = $H['inst'];
        $term = trim((string) ($d['term'] ?? ''));
        $part = trim((string) ($d['part'] ?? ''));
        $session = trim((string) ($d['session'] ?? '')) ?: trim((string) ($d['year'] ?? ''));

        $show_head_logo = !empty($S['show_logo']);
        $show_head_name = !empty($S['show_institute_name']) && $inst !== '';
        $show_head_slogan = !empty($S['show_slogan']) && $H['slogan'] !== '';
        $show_head_addr = !empty($S['show_address']) && $H['address'] !== '';
        $show_qr = !empty($S['show_qr']);
        $qr_id = trim((string) ($d['qr_id'] ?? '')) ?: 'acqr-m';
        $qr_val = trim((string) ($d['student_id'] ?? '')) ?: trim((string) ($d['roll'] ?? ''));

        $contact_spans = [];
        if (!empty($S['show_phone']) && $H['phone'] !== '') {
            $contact_spans[] = 'Phone <strong>' . sc_e($H['phone']) . '</strong>';
        }
        if (!empty($S['show_website']) && $H['website'] !== '') {
            $contact_spans[] = 'Website <strong>' . sc_e($H['website']) . '</strong>';
        }
        if (!empty($S['show_email']) && $H['email'] !== '') {
            $contact_spans[] = 'Email <strong>' . sc_e($H['email']) . '</strong>';
        }

        $exam_banner_parts = [];
        if ($term !== '') { $exam_banner_parts[] = $term; }
        if ($part !== '') { $exam_banner_parts[] = '(' . $part . ')'; }
        $exam_banner = implode(' ', $exam_banner_parts);
        if ($session !== '') {
            $exam_banner .= ($exam_banner !== '' ? ' - ' : '') . $session;
        }

        $ver_custom = trim((string) ($S['custom_version'] ?? ''));
        $version_val = $ver_custom !== '' ? $ver_custom : (trim((string) ($d['version'] ?? '')) ?: 'All');
        $cat_val = trim((string) ($S['custom_category'] ?? '')) ?: 'General';

        $meta_cells = [];
        if (!empty($S['show_class']))    { $meta_cells[] = ['Class',    trim((string) ($d['class'] ?? '')) ?: '—']; }
        if (!empty($S['show_version']))  { $meta_cells[] = ['Version',  $version_val]; }
        if (!empty($S['show_category'])) { $meta_cells[] = ['Category', $cat_val]; }
        if (!empty($S['show_session']))  { $meta_cells[] = ['Session',  $session ?: '—']; }
        if (!empty($S['show_shift']))    { $meta_cells[] = ['Shift',    trim((string) ($d['shift'] ?? '')) ?: '—']; }
        if (!empty($S['show_group']))    { $meta_cells[] = ['Group',    trim((string) ($d['group'] ?? '')) ?: '—']; }
        $meta_rows = array_chunk($meta_cells, 3);

        $dob_fmt = ac_fmt_date($d['dob'] ?? '', '—', 'F j, Y');
        $age_str = ac_calc_age($d['dob'] ?? '');
        $breg_val = trim((string) ($d['birth_reg_no'] ?? '')) ?: '—';
        $gender_val = trim((string) ($d['gender'] ?? '')) ?: '—';
        $mob_val = trim((string) ($d['student_mobile'] ?? '')) ?: '—';
        $pres_addr = trim((string) ($d['present_address'] ?? '')) ?: '—';
        $perm_addr = trim((string) ($d['permanent_address'] ?? '')) ?: '—';

        $rmode = (string) ($S['routine_mode'] ?? 'auto_db');
        $db_rows = is_array($d['routine_rows'] ?? null) ? $d['routine_rows'] : [];
        $cust_rows = is_array($S['custom_routine_rows'] ?? null) ? $S['custom_routine_rows'] : [];
        $sched_exam = trim((string) ($S['schedule_exam_date'] ?? ''));
        $sched_res = trim((string) ($S['schedule_result_date'] ?? ''));

        $routine_rows_to_show = [];
        $show_sched_box = false;
        if (!empty($S['show_routine'])) {
            if ($rmode === 'admission_schedule') {
                if ($sched_exam === '' && !empty($db_rows[0])) {
                    $sched_exam = trim((string) (($db_rows[0]['date'] ?? '') . ' ' . ($db_rows[0]['time'] ?? '')));
                }
                if ($sched_exam !== '' || $sched_res !== '') {
                    $show_sched_box = true;
                }
            } else {
                $routine_rows_to_show = ($rmode === 'custom_table') ? $cust_rows : ($db_rows ?: $cust_rows);
                if (!$routine_rows_to_show && ($sched_exam !== '' || $sched_res !== '')) {
                    $show_sched_box = true;
                }
            }
        }

        $instr = is_array($d['instructions'] ?? null) ? $d['instructions'] : ac_default_instructions();
        $note_text = trim((string) ($instr['note_text'] ?? ''));
        $heading_text = trim((string) ($instr['heading'] ?? ''));
        $rules_raw = trim((string) ($instr['rules_text'] ?? ''));
        $carry_text = trim((string) ($instr['carry_text'] ?? ''));
        $barred_text = trim((string) ($instr['barred_text'] ?? ''));
        $rules_lines = $rules_raw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $rules_raw) ?: []), static fn($l) => $l !== ''))
            : [];
        $has_prefix = false;
        foreach ($rules_lines as $rl) {
            if (preg_match('/^(?:[\d০-৯]+[\.\)\-:]|[•\-\*])\s*/u', $rl)) {
                $has_prefix = true;
                break;
            }
        }

        $sig_principal_lbl = $H['sig_p_label'] ?: 'Principal';
        $print_date = date('d-m-Y');

        ob_start(); ?>
        <div class="ac-cell ac-cell-full"><div class="admit-modern">
            <div class="am-head">
                <?php if ($show_head_logo): ?>
                <div class="am-logo">
                    <?php if ($H['logo'] !== ''): ?><img src="<?php echo sc_e($H['logo']); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr($inst !== '' ? $inst : 'A', 0, 1)); ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="am-inst">
                    <?php if ($show_head_name): ?><div class="am-inst-name"><?php echo sc_e($inst); ?></div><?php endif; ?>
                    <?php if ($show_head_slogan): ?><div class="am-inst-slogan"><?php echo sc_e($H['slogan']); ?></div><?php endif; ?>
                    <?php if ($show_head_addr): ?><div class="am-inst-addr"><?php echo sc_e($H['address']); ?></div><?php endif; ?>
                    <?php if ($contact_spans): ?><div class="am-inst-contact"><?php echo implode(', ', $contact_spans); ?></div><?php endif; ?>
                </div>
                <?php if ($show_qr && $qr_val !== ''): ?>
                <div class="am-qr-box">
                    <div class="ad-qr" id="<?php echo sc_e($qr_id); ?>" data-qr="<?php echo sc_e($qr_val); ?>"></div>
                    <div class="am-qr-cap">Scan to verify</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="am-title"><?php echo sc_e($H['card_title']); ?></div>

            <div class="am-exam-box">
                <?php if ($exam_banner !== ''): ?><div class="am-exam-banner"><?php echo sc_e($exam_banner); ?></div><?php endif; ?>
                <table class="am-meta-grid">
                    <?php if (!empty($S['show_roll']) || !empty($S['show_student_id'])): ?>
                    <tr>
                        <?php if (!empty($S['show_roll'])): ?>
                            <td class="am-mk">Roll</td>
                            <td class="am-mv"><strong><?php echo sc_e(trim((string) ($d['roll'] ?? '')) ?: '—'); ?></strong></td>
                        <?php endif; ?>
                        <?php if (!empty($S['show_student_id'])): ?>
                            <td class="am-mk">Student ID</td>
                            <td class="am-mv"<?php echo empty($S['show_roll']) ? ' colspan="5"' : ' colspan="3"'; ?>><?php echo sc_e(trim((string) ($d['student_id'] ?? '')) ?: '—'); ?></td>
                        <?php elseif (!empty($S['show_roll'])): ?>
                            <td colspan="4"></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($meta_rows as $mr): ?>
                    <tr>
                        <?php foreach ($mr as [$mk, $mv]): ?>
                            <td class="am-mk"><?php echo sc_e($mk); ?></td>
                            <td class="am-mv"><?php echo sc_e($mv); ?></td>
                        <?php endforeach; ?>
                        <?php for ($pad = count($mr); $pad < 3; $pad++): ?>
                            <td class="am-mk"></td><td class="am-mv"></td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="am-cand-wrap">
                <table class="am-cand-tbl">
                    <tr>
                        <td class="am-ck">Name</td>
                        <td colspan="3"><strong><?php echo sc_e(trim((string) ($d['name'] ?? '')) ?: '—'); ?></strong></td>
                    </tr>
                    <?php if (!empty($S['show_father_name'])): ?>
                    <tr>
                        <td class="am-ck">Father's Name</td>
                        <td colspan="3"><?php echo sc_e(trim((string) ($d['father_name'] ?? '')) ?: '—'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_mother_name'])): ?>
                    <tr>
                        <td class="am-ck">Mother's Name</td>
                        <td colspan="3"><?php echo sc_e(trim((string) ($d['mother_name'] ?? '')) ?: '—'); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_dob']) || !empty($S['show_age'])): ?>
                    <tr>
                        <?php if (!empty($S['show_dob'])): ?>
                            <td class="am-ck">Date of Birth</td>
                            <td<?php echo empty($S['show_age']) ? ' colspan="3"' : ''; ?>><?php echo sc_e($dob_fmt); ?></td>
                        <?php endif; ?>
                        <?php if (!empty($S['show_age'])): ?>
                            <td class="<?php echo !empty($S['show_dob']) ? 'am-ck2' : 'am-ck'; ?>">Age</td>
                            <td<?php echo empty($S['show_dob']) ? ' colspan="3"' : ''; ?>><?php echo sc_e($age_str); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_birth_reg']) || !empty($S['show_gender'])): ?>
                    <tr>
                        <?php if (!empty($S['show_birth_reg'])): ?>
                            <td class="am-ck">Birth Reg. No.</td>
                            <td<?php echo empty($S['show_gender']) ? ' colspan="3"' : ''; ?>><?php echo sc_e($breg_val); ?></td>
                        <?php endif; ?>
                        <?php if (!empty($S['show_gender'])): ?>
                            <td class="<?php echo !empty($S['show_birth_reg']) ? 'am-ck2' : 'am-ck'; ?>">Gender</td>
                            <td<?php echo empty($S['show_birth_reg']) ? ' colspan="3"' : ''; ?>><?php echo sc_e($gender_val); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_student_mobile'])): ?>
                    <tr>
                        <td class="am-ck">Mobile</td>
                        <td colspan="3"><?php echo sc_e($mob_val); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_student_address'])): ?>
                    <tr>
                        <td class="am-ck">Present Address</td>
                        <td colspan="3"><?php echo sc_e($pres_addr); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($S['show_permanent_address'])): ?>
                    <tr>
                        <td class="am-ck">Permanent Address</td>
                        <td colspan="3"><?php echo sc_e($perm_addr); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php if (!empty($S['show_photo'])): ?>
                <div class="am-photo">
                    <?php if ($photo !== ''): ?><img src="<?php echo sc_e($photo); ?>" alt="Photo">
                    <?php else: ?>Photo<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($routine_rows_to_show): ?>
            <div class="am-routine-box">
                <div class="am-routine-title">Exam Routine</div>
                <table class="am-routine-tbl">
                    <thead>
                        <tr>
                            <th style="width:22%;">Date</th>
                            <th style="width:22%;">Time</th>
                            <th>Subject</th>
                            <th class="am-rc" style="width:13%;">Full Marks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routine_rows_to_show as $rr): ?>
                        <tr>
                            <td><?php echo sc_e($rr['date'] ?? '—'); ?></td>
                            <td><?php echo sc_e($rr['time'] ?? '—'); ?></td>
                            <td><?php echo sc_e($rr['subject'] ?? '—'); ?></td>
                            <td class="am-rc"><?php echo sc_e(($rr['full_marks'] ?? '') !== '' ? $rr['full_marks'] : '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php elseif ($show_sched_box): ?>
            <div class="am-routine-box">
                <div class="am-routine-title">Exam Schedule</div>
                <div class="am-sched-row">
                    <?php if ($sched_exam !== ''): ?><div>Written Exam Date: <strong><?php echo sc_e($sched_exam); ?></strong></div><?php endif; ?>
                    <?php if ($sched_res !== ''): ?><div>Result Date: <strong><?php echo sc_e($sched_res); ?></strong></div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($S['show_instructions'])): ?>
            <div class="am-rules">
                <div class="am-rules-head"><?php echo sc_e($heading_text !== '' ? $heading_text : 'নির্দেশিকা:'); ?></div>
                <?php if ($note_text !== ''): ?><div class="am-rules-note"><?php echo nl2br(sc_e($note_text)); ?></div><?php endif; ?>
                <?php if ($rules_lines): ?>
                    <?php if ($has_prefix): ?>
                        <?php foreach ($rules_lines as $rl): ?><div class="am-rule-item"><?php echo sc_e($rl); ?></div><?php endforeach; ?>
                    <?php else: ?>
                        <ol class="am-rules-ol"><?php foreach ($rules_lines as $rl): ?><li><?php echo sc_e($rl); ?></li><?php endforeach; ?></ol>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($carry_text !== ''): ?><div class="am-rules-strip"><strong>সঙ্গে আনতে হবে:</strong> <?php echo sc_e($carry_text); ?></div><?php endif; ?>
                <?php if ($barred_text !== ''): ?><div class="am-rules-strip"><strong>নিষিদ্ধ সামগ্রী:</strong> <?php echo sc_e($barred_text); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($S['show_sig_principal'])): ?>
            <div class="am-sig-row">
                <div class="am-sig-box">
                    <div class="am-sig-img">
                        <?php if ($H['sig_p_img'] !== ''): ?><img src="<?php echo sc_e($H['sig_p_img']); ?>" alt="Principal Signature"><?php endif; ?>
                    </div>
                    <?php if ($H['sig_p_name'] !== ''): ?>
                        <div class="am-sig-line">
                            <div class="am-sig-name"><?php echo sc_e($H['sig_p_name']); ?></div>
                            <div class="am-sig-role"><?php echo sc_e($sig_principal_lbl); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="am-sig-line"><?php echo sc_e($sig_principal_lbl); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="am-foot">
                <div class="am-foot-left">
                    <div>Printing Date: <strong><?php echo sc_e($print_date); ?></strong></div>
                    <?php if ($inst !== ''): ?><div style="font-style:italic;">This admit card is the official property of <?php echo sc_e($inst); ?></div><?php endif; ?>
                </div>
                <div class="am-foot-right">
                    <div class="am-pow-lbl">Powered by</div>
                    <img class="am-pow-logo" src="../assets/techteam-logo.svg" alt="TechTeam">
                    <div class="am-pow-url">techteamlead.com</div>
                </div>
            </div>
        </div></div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('ac_card_full')) {
    function ac_card_full(array $d): string
    {
        $S = ac_cfg($d);
        if (($S['full_template'] ?? 'classic') === 'modern') {
            return ac_card_full_modern($d);
        }
        $H = ac_resolve_header($d, $S);
        $photo = !empty($S['show_photo']) ? ac_asset_url($d['photo'] ?? '') : '';
        $inst = $H['inst'];
        $term = trim((string) ($d['term'] ?? ''));
        $session = trim((string) ($d['session'] ?? '')) ?: trim((string) ($d['year'] ?? ''));

        $subtitle_parts = array_filter([
            !empty($S['show_address']) ? $H['address'] : '',
            (!empty($S['show_phone']) && $H['phone'] !== '') ? 'Mobile: ' . $H['phone'] : '',
            (!empty($S['show_email']) && $H['email'] !== '') ? 'Email: ' . $H['email'] : '',
            !empty($S['show_website']) ? $H['website'] : '',
        ], static fn($v) => $v !== '');

        $eiin = $H['eiin'] !== '' ? $H['eiin'] : (trim((string) ($d['branch_short'] ?? '')) ?: '—');
        $centre_name = trim((string) ($d['branch_name'] ?? '')) ?: ($inst ?: '—');
        $centre_addr = trim((string) ($d['branch_address'] ?? '')) ?: $H['address'];
        $cand_addr = trim((string) ($d['present_address'] ?? '')) ?: (trim((string) ($d['permanent_address'] ?? '')) ?: '—');
        $dob_fmt = ac_fmt_date($d['dob'] ?? '', '—');
        $exam_date = trim((string) ($d['exam_date'] ?? '')) ?: (trim((string) ($d['issue_date'] ?? '')) ?: date('d/m/Y'));
        $exam_time = trim((string) ($d['exam_time'] ?? '')) ?: (trim((string) ($d['shift'] ?? '')) ?: '—');
        $sig_principal_lbl = $H['sig_p_label'] ?: 'Head of Institute';

        $show_head_logo = !empty($S['show_logo']);
        $show_head_name = !empty($S['show_institute_name']) && $inst !== '';
        $show_head_slogan = !empty($S['show_slogan']) && $H['slogan'] !== '';
        $show_right_col = !empty($S['show_roll']) || !empty($S['show_photo']) || !empty($S['show_sig_candidate']);

        $band1 = [];
        if (!empty($S['show_student_id'])) { $band1[] = ['Student ID / Reg. No.', trim((string) ($d['student_id'] ?? '')) ?: '—', true]; }
        if (!empty($S['show_dob']))        { $band1[] = ['Date of Birth', $dob_fmt, false]; }

        $band2 = [];
        if (!empty($S['show_gender']))      { $band2[] = ['Gender', trim((string) ($d['gender'] ?? '')) ?: '—', false]; }
        if (!empty($S['show_religion']))    { $band2[] = ['Religion', trim((string) ($d['religion'] ?? '')) ?: '—', false]; }
        if (!empty($S['show_blood_group'])) { $band2[] = ['Blood Group', trim((string) ($d['blood_group'] ?? '')) ?: '—', false]; }

        $band3 = [];
        if (!empty($S['show_class'])) { $band3[] = ['Class', trim((string) ($d['class'] ?? '')) ?: '—', false]; }
        if (!empty($S['show_group'])) { $band3[] = ['Group', trim((string) ($d['group'] ?? '')) ?: 'N/A', false]; }

        $band4 = [];
        if (!empty($S['show_shift']))   { $band4[] = ['Shift', trim((string) ($d['shift'] ?? '')) ?: '—', false]; }
        if (!empty($S['show_section'])) { $band4[] = ['Section', trim((string) ($d['section'] ?? '')) ?: '—', false]; }

        $band5 = [];
        if (!empty($S['show_session']))       { $band5[] = ['Session', $session ?: '—', false]; }
        if (!empty($S['show_exam_schedule'])) { $band5[] = ['Exam Part', trim((string) ($d['part'] ?? '')) ?: 'Regular', false]; }

        $band6 = [];
        if (!empty($S['show_exam_schedule'])) {
            $band6[] = ['Exam Date', $exam_date, false];
            $band6[] = ['Term', $term ?: '—', false];
            $band6[] = ['Timings', $exam_time, false];
        }

        $render_band = static function (array $cells): string {
            if (!$cells) { return ''; }
            $cols = count($cells);
            $out = '<div class="af-mgrid2" style="grid-template-columns:repeat(' . $cols . ',minmax(0,1fr));">';
            foreach ($cells as [$lbl, $val, $lg]) {
                $out .= '<div class="af-mcell"><div class="af-mlbl">' . sc_e($lbl) . '</div>'
                      . '<div class="af-mval' . ($lg ? ' af-mval-lg' : '') . '">' . sc_e($val) . '</div></div>';
            }
            return $out . '</div>';
        };

        $instr = is_array($d['instructions'] ?? null) ? $d['instructions'] : ac_default_instructions();
        $note_text = trim((string) ($instr['note_text'] ?? ''));
        $heading_text = trim((string) ($instr['heading'] ?? ''));
        $rules_raw = trim((string) ($instr['rules_text'] ?? ''));
        $carry_text = trim((string) ($instr['carry_text'] ?? ''));
        $barred_text = trim((string) ($instr['barred_text'] ?? ''));

        $rules_lines = $rules_raw !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $rules_raw) ?: []), static fn($l) => $l !== ''))
            : [];
        $has_prefix = false;
        foreach ($rules_lines as $rl) {
            if (preg_match('/^(?:[\d০-৯]+[\.\)\-:]|[•\-\*])\s*/u', $rl)) {
                $has_prefix = true;
                break;
            }
        }

        ob_start(); ?>
        <div class="ac-cell ac-cell-full"><div class="admit-full">
            <div class="af-head">
                <?php if ($show_head_logo): ?>
                <div class="af-logo">
                    <?php if ($H['logo'] !== ''): ?><img src="<?php echo sc_e($H['logo']); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr($inst !== '' ? $inst : 'A', 0, 1)); ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="af-head-text">
                    <?php if ($show_head_name): ?><div class="af-inst"><?php echo sc_e($inst); ?></div><?php endif; ?>
                    <?php if ($show_head_slogan): ?><div class="af-slogan"><?php echo sc_e($H['slogan']); ?></div><?php endif; ?>
                    <div class="af-title"><?php echo sc_e(mb_strtoupper($H['card_title'])); ?><?php echo $term !== '' ? ' — ' . sc_e($term) : ''; ?><?php echo (!empty($S['show_session']) && $session !== '') ? ' (' . sc_e($session) . ')' : ''; ?></div>
                    <?php if ($subtitle_parts): ?><div class="af-sub"><?php echo sc_e(implode(' | ', $subtitle_parts)); ?></div><?php endif; ?>
                </div>
                <?php if ($show_head_logo): ?><div class="af-head-spacer"></div><?php endif; ?>
            </div>

            <div class="af-upper"<?php echo !$show_right_col ? ' style="grid-template-columns:52% 48%;"' : ''; ?>>
                <div class="af-col-left">
                    <?php if (!empty($S['show_eiin'])): ?>
                    <div class="af-lrow">
                        <div class="af-lk">EIIN / Code :</div>
                        <div class="af-lv"><?php echo sc_e($eiin); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($S['show_branch'])): ?>
                    <div class="af-lrow af-grow">
                        <div class="af-lk">Centre / Institute :</div>
                        <div class="af-lv"><?php echo sc_e($centre_name); ?><?php if (!empty($S['show_address']) && $centre_addr !== ''): ?><br><span class="af-lv-reg"><?php echo nl2br(sc_e($centre_addr)); ?></span><?php endif; ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($S['show_student_address'])): ?>
                    <div class="af-lrow af-grow">
                        <div class="af-lk">Candidate's Address :</div>
                        <div class="af-lv af-lv-reg"><?php echo nl2br(sc_e($cand_addr)); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="af-lrow">
                        <div class="af-lk">Candidate's Name :</div>
                        <div class="af-lv" style="text-transform:uppercase;"><?php echo sc_e(trim((string) ($d['name'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <?php if (!empty($S['show_father_name'])): ?>
                    <div class="af-lrow">
                        <div class="af-lk">Father's Name :</div>
                        <div class="af-lv" style="text-transform:uppercase;"><?php echo sc_e(trim((string) ($d['father_name'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($S['show_mother_name'])): ?>
                    <div class="af-lrow">
                        <div class="af-lk">Mother's Name :</div>
                        <div class="af-lv" style="text-transform:uppercase;"><?php echo sc_e(trim((string) ($d['mother_name'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="af-col-mid"<?php echo !$show_right_col ? ' style="border-right:0;"' : ''; ?>>
                    <?php echo $render_band($band1); ?>
                    <?php echo $render_band($band2); ?>
                    <?php echo $render_band($band3); ?>
                    <?php echo $render_band($band4); ?>
                    <?php if ($band5 || $band6): ?>
                        <div class="af-mband-title">Academic &amp; Examination Details</div>
                        <?php echo $render_band($band5); ?>
                        <?php echo $render_band($band6); ?>
                    <?php endif; ?>
                    <?php if (!empty($S['show_sig_principal'])): ?>
                    <div class="af-msig">
                        <?php if ($H['sig_p_img'] !== ''): ?><img src="<?php echo sc_e($H['sig_p_img']); ?>" alt="Authority Signature"><?php endif; ?>
                        <?php if ($H['sig_p_name'] !== ''): ?><div class="af-msig-name"><?php echo sc_e($H['sig_p_name']); ?></div><?php endif; ?>
                        <div class="af-msig-lbl"><?php echo sc_e($sig_principal_lbl); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($show_right_col): ?>
                <div class="af-col-right">
                    <?php if (!empty($S['show_roll'])): ?>
                    <div class="af-roll-box">
                        <div class="af-roll-lbl">Roll No.</div>
                        <div class="af-roll-val"><?php echo sc_e(trim((string) ($d['roll'] ?? '')) ?: '—'); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($S['show_photo'])): ?>
                    <div class="af-photo-wrap">
                        <div class="af-photo">
                            <?php if ($photo !== ''): ?><img src="<?php echo sc_e($photo); ?>" alt="Photo">
                            <?php else: ?>Photo<?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($S['show_sig_candidate'])): ?>
                    <div class="af-cand-sig">
                        <div class="af-cand-sig-lbl"><?php echo sc_e(trim((string) ($S['sig_candidate_label'] ?? '')) ?: 'Signature of the Candidate'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($S['show_instructions'])): ?>
            <div class="af-rules-sec">
                <?php if ($note_text !== ''): ?>
                    <div class="af-note"><strong>বিশেষ দ্রষ্টব্য (NOTE):</strong> <?php echo nl2br(sc_e($note_text)); ?></div>
                <?php endif; ?>
                <?php if ($heading_text !== ''): ?>
                    <div class="af-rules-title"><?php echo sc_e($heading_text); ?></div>
                <?php endif; ?>
                <?php if ($rules_lines): ?>
                    <?php if ($has_prefix): ?>
                        <div class="af-rules-list">
                            <?php foreach ($rules_lines as $rl): ?>
                                <div class="af-rule-item"><?php echo sc_e($rl); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <ol class="af-rules-ol">
                            <?php foreach ($rules_lines as $rl): ?>
                                <li><?php echo sc_e($rl); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($carry_text !== ''): ?>
                    <div class="af-strip"><strong class="af-strip-lbl">সঙ্গে আনতে হবে (MUST CARRY):</strong> <?php echo sc_e($carry_text); ?></div>
                <?php endif; ?>
                <?php if ($barred_text !== ''): ?>
                    <div class="af-strip"><strong class="af-strip-lbl">নিষিদ্ধ সামগ্রী (BARRED ITEMS):</strong> <?php echo sc_e($barred_text); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div></div>
        <?php
        return ob_get_clean();
    }
}

/**
 * $d: institute, logo, address, website, term, year, name, class, shift, section, roll, student_id, photo, qr_id
 */
if (!function_exists('ac_card')) {
    function ac_card(array $d): string
    {
        $L = $d['layout'] ?? ac_layout();
        if (($L['preset'] ?? '') === '2half') {
            return ac_card_half($d);
        }
        if (($L['preset'] ?? '') === '1full') {
            return ac_card_full($d);
        }

        $S = ac_cfg($d);
        $H = ac_resolve_header($d, $S);
        $logo = !empty($S['show_logo']) ? $H['logo'] : '';
        $wm = $logo !== '' ? "--ad-wm:url('" . sc_e($logo) . "');" : '';
        $photo = !empty($S['show_photo']) ? ac_asset_url($d['photo'] ?? '') : '';

        $sigs = [];
        if (!empty($S['show_sig_secondary'])) {
            $sigs[] = ['img' => $H['sig_s_img'], 'name' => $H['sig_s_name'], 'label' => $H['sig_s_label'] ?: 'Class Teacher'];
        }
        if (!empty($S['show_sig_principal'])) {
            $sigs[] = ['img' => $H['sig_p_img'], 'name' => $H['sig_p_name'], 'label' => $H['sig_p_label'] ?: 'Head of Institute'];
        }
        $has_sig_img = false;
        foreach ($sigs as $sg) { if ($sg['img'] !== '') { $has_sig_img = true; break; } }
        $compact_note = (!empty($S['show_compact_note'])) ? trim((string) ($S['compact_note_text'] ?? '')) : '';

        ob_start(); ?>
        <div class="ac-cell"><div class="admitcard2 ac-<?php echo sc_e($L['mode']); ?>">
            <div class="ad-head">
                <?php if (!empty($S['show_logo'])): ?>
                <div class="ad-logo">
                    <?php if ($logo !== ''): ?><img src="<?php echo sc_e($logo); ?>" alt="Logo">
                    <?php else: ?><span><?php echo sc_e(mb_substr($H['inst'] !== '' ? $H['inst'] : 'A', 0, 1)); ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="ad-inst">
                    <?php if (!empty($S['show_institute_name']) && $H['inst'] !== ''): ?><b><?php echo sc_e($H['inst']); ?></b><?php endif; ?>
                    <?php if (!empty($S['show_slogan']) && $H['slogan'] !== ''): ?><small><?php echo sc_e($H['slogan']); ?></small><?php endif; ?>
                    <?php if (!empty($S['show_address']) && $H['address'] !== ''): ?><small><?php echo sc_e($H['address']); ?></small><?php endif; ?>
                    <?php if (!empty($S['show_website']) && $H['website'] !== ''): ?><small class="web"><?php echo sc_e($H['website']); ?></small><?php endif; ?>
                </div>
            </div>
            <div class="ad-title">
                <span class="pill"><?php echo sc_e(mb_strtoupper($H['card_title'])); ?></span>
                <span class="term"><?php echo sc_e(trim($d['term'] . ' - ' . $d['year'], ' -')); ?></span>
            </div>
            <div class="ad-body" style="<?php echo $wm; ?>">
                <?php if (!empty($S['show_photo'])): ?>
                    <?php if ($photo !== ''): ?>
                        <img class="ad-photo" src="<?php echo sc_e($photo); ?>" alt="Photo">
                    <?php else: ?>
                        <div class="ad-photo">Photo</div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="ad-info">
                    <div class="ad-name"><?php echo sc_e($d['name']); ?></div>
                    <div class="ad-grid">
                        <?php if (!empty($S['show_student_id'])): ?><div><span>Student ID</span><b><?php echo sc_e($d['student_id']); ?></b></div><?php endif; ?>
                        <?php if (!empty($S['show_roll'])): ?><div><span>Roll No</span><b><?php echo sc_e($d['roll'] ?: '-'); ?></b></div><?php endif; ?>
                        <?php if (!empty($S['show_class'])): ?><div><span>Class</span><b><?php echo sc_e($d['class'] ?: '-'); ?></b></div><?php endif; ?>
                        <?php if (!empty($S['show_shift'])): ?><div><span>Shift</span><b><?php echo sc_e($d['shift'] ?: '-'); ?></b></div><?php endif; ?>
                        <?php if (!empty($S['show_section'])): ?><div><span>Section</span><b><?php echo sc_e($d['section'] ?: '-'); ?></b></div><?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($S['show_qr'])): ?>
                <div class="ad-qr" id="<?php echo sc_e($d['qr_id']); ?>" data-qr="<?php echo sc_e($d['student_id']); ?>"></div>
                <?php endif; ?>
            </div>
            <?php if ($sigs): ?>
            <div class="ad-foot<?php echo $has_sig_img ? ' has-sig-img' : ''; ?>">
                <?php foreach ($sigs as $sg): ?>
                <div class="ad-sign">
                    <?php if ($has_sig_img): ?>
                        <div class="ad-sign-img"><?php if ($sg['img'] !== ''): ?><img src="<?php echo sc_e($sg['img']); ?>" alt="Signature"><?php endif; ?></div>
                    <?php endif; ?>
                    <div class="ad-sign-line">
                        <?php if ($sg['name'] !== ''): ?><div class="ad-sign-name"><?php echo sc_e($sg['name']); ?></div><?php endif; ?>
                        <?php echo sc_e($sg['label']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($compact_note !== ''): ?><div class="ad-note"><?php echo sc_e($compact_note); ?></div><?php endif; ?>
        </div></div>
        <?php
        return ob_get_clean();
    }
}
