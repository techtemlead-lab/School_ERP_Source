<?php
/**
 * config/table_print.php
 * -----------------------------------------------------------
 * Shared Print helper library & auto-print enhancer across Admin, Teacher,
 * and Student portals.
 *
 * - Prefix `tprint_` / CSS `tprint-` (avoids collision with `tp_*` in teacher/_bootstrap.php).
 * - Tier 1 pages call tprint_page_css(), tprint_header_html(), tprint_controls() explicitly.
 * - Tier 2 pages listed in tprint_auto_pages($portal) are automatically enhanced via
 *   tprint_assets($pdo, $page, $portal) when a printable data table is present.
 */

if (!function_exists('tprint_esc')) {
    function tprint_esc($val): string
    {
        return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tprint_meta')) {
    /**
     * Fetch active branch / institute branding metadata for print headers.
     */
    function tprint_meta(?PDO $pdo, string $logo_prefix = '../'): array
    {
        static $raw = null;
        if ($raw === null) {
            $branch = [];
            $inst   = [];
            if ($pdo !== null) {
                try {
                    $bid = function_exists('branch_current_id') ? branch_current_id() : null;
                    if ($bid !== null) {
                        $bq = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
                        $bq->execute([$bid]);
                        $branch = $bq->fetch() ?: [];
                    } else {
                        $branch = $pdo->query("SELECT * FROM branches WHERE status = 'Active' ORDER BY id ASC LIMIT 1")->fetch() ?: [];
                    }
                } catch (\Throwable $e) {
                    $branch = [];
                }
                try {
                    $inst = $pdo->query("SELECT * FROM institute_settings WHERE id = 1")->fetch() ?: [];
                } catch (\Throwable $e) {
                    $inst = [];
                }
            }

            $name = trim((string) ($branch['branch_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($inst['institute_name'] ?? ''));
            }
            if ($name === '') {
                $name = trim((string) ($GLOBALS['tenant']['school_name'] ?? 'School ERP'));
            }

            $addrParts = array_filter([
                trim((string) ($branch['address'] ?? '')),
                trim((string) ($branch['city'] ?? '')),
            ], static fn($v) => $v !== '');
            $address = implode(', ', $addrParts);
            $zip = trim((string) ($branch['zip_code'] ?? ''));
            if ($address !== '' && $zip !== '') {
                $address .= '-' . $zip;
            } elseif ($address === '') {
                $address = trim((string) ($inst['address'] ?? ''));
            }

            $phone   = trim((string) (($branch['phone'] ?? '') ?: ($inst['phone_number'] ?? '')));
            $website = trim((string) ($inst['website'] ?? ''));
            $logoPath = ltrim(trim((string) ($inst['logo_path'] ?? '')), '/');

            $raw = [
                'name'      => $name,
                'address'   => $address,
                'phone'     => $phone,
                'website'   => $website,
                'logo_path' => $logoPath,
            ];
        }

        return [
            'name'    => $raw['name'],
            'address' => $raw['address'],
            'phone'   => $raw['phone'],
            'website' => $raw['website'],
            'logo'    => $raw['logo_path'] !== '' ? ($logo_prefix . $raw['logo_path']) : '',
        ];
    }
}

if (!function_exists('tprint_header_html')) {
    /**
     * Render institutional print header block with title, optional filter/meta subtitle, and timestamp.
     */
    function tprint_header_html(?PDO $pdo, string $title, string $subtitle = '', bool $always = false, string $logo_prefix = '../'): string
    {
        $m = tprint_meta($pdo, $logo_prefix);
        $cls = 'tprint-header' . ($always ? ' tprint-header-always' : '');
        $html = '<div class="' . $cls . '">';
        $html .= '<div class="tprint-top">';
        if ($m['logo'] !== '') {
            $html .= '<img src="' . tprint_esc($m['logo']) . '" alt="" class="tprint-logo">';
        }
        $html .= '<div class="tprint-inst-info">';
        if ($m['name'] !== '') {
            $html .= '<h4 class="tprint-inst">' . tprint_esc($m['name']) . '</h4>';
        }
        if ($m['address'] !== '') {
            $html .= '<div class="tprint-line">' . tprint_esc($m['address']) . '</div>';
        }
        $contact = [];
        if ($m['phone'] !== '') {
            $contact[] = 'Phone: ' . $m['phone'];
        }
        if ($m['website'] !== '') {
            $contact[] = $m['website'];
        }
        if ($contact) {
            $html .= '<div class="tprint-line">' . tprint_esc(implode(' | ', $contact)) . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="tprint-title-wrap"><span class="tprint-title">' . tprint_esc($title) . '</span></div>';
        if (trim($subtitle) !== '') {
            $html .= '<div class="tprint-sub">' . tprint_esc($subtitle) . '</div>';
        }
        $html .= '<div class="tprint-printed">Printed: ' . tprint_esc(date('d M Y, h:i A')) . '</div>';
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('tprint_is_print_all')) {
    function tprint_is_print_all(): bool
    {
        return isset($_GET['print_all']) && $_GET['print_all'] === '1';
    }
}

if (!function_exists('tprint_all_url')) {
    function tprint_all_url(array $strip_keys = ['p', 'pg', 'print_all', 'print_orient']): string
    {
        $q = [];
        foreach ($_GET as $k => $v) {
            if (in_array((string) $k, $strip_keys, true) || is_array($v)) {
                continue;
            }
            $q[$k] = $v;
        }
        $q['print_all'] = '1';
        return 'index.php?' . http_build_query($q);
    }
}

if (!function_exists('tprint_back_url')) {
    function tprint_back_url(array $strip_keys = ['print_all', 'print_orient']): string
    {
        $q = [];
        foreach ($_GET as $k => $v) {
            if (in_array((string) $k, $strip_keys, true) || is_array($v)) {
                continue;
            }
            $q[$k] = $v;
        }
        return 'index.php?' . http_build_query($q);
    }
}

if (!function_exists('tprint_controls')) {
    /**
     * Render Portrait/Landscape selector + Print button (+ optional Print All button for paginated tables).
     */
    function tprint_controls(array $opts = []): string
    {
        $orient = strtolower((string) ($opts['orient'] ?? 'portrait'));
        if (isset($_GET['print_orient']) && in_array($_GET['print_orient'], ['portrait', 'landscape'], true)) {
            $orient = $_GET['print_orient'];
        }
        $orient = $orient === 'landscape' ? 'landscape' : 'portrait';

        $wrap         = array_key_exists('wrap', $opts) ? (bool) $opts['wrap'] : true;
        $printAllUrl  = (string) ($opts['print_all_url'] ?? '');
        $totalCount   = (int) ($opts['total_count'] ?? 0);
        $shownCount   = (int) ($opts['shown_count'] ?? 0);
        $isPrintAll   = array_key_exists('is_print_all', $opts) ? (bool) $opts['is_print_all'] : tprint_is_print_all();
        $backUrl      = (string) ($opts['back_url'] ?? tprint_back_url());
        $fontPx       = (int) ($opts['font_px'] ?? 0);

        $tag   = $wrap ? 'div' : 'span';
        $cls   = $wrap
            ? 'd-flex justify-content-end align-items-center gap-2 mb-2 flex-wrap no-print tprint-toolbar'
            : 'd-inline-flex align-items-center gap-2 flex-wrap no-print tprint-toolbar';
        $attrs = $isPrintAll ? ' data-tprint-autoprint="1"' : '';

        $out = '<' . $tag . ' class="' . $cls . '"' . $attrs . '>';
        $out .= '<label class="small fw-semibold text-muted mb-0">Page:</label>';
        $out .= '<select class="form-select form-select-sm tprint-orient-select" data-ss="off" style="width:auto;">';
        $out .= '<option value="portrait"' . ($orient === 'portrait' ? ' selected' : '') . '>Portrait</option>';
        $out .= '<option value="landscape"' . ($orient === 'landscape' ? ' selected' : '') . '>Landscape</option>';
        $out .= '</select>';
        $out .= '<button type="button" class="btn btn-sm btn-primary fw-bold tprint-btn" style="background-color:#1e3a8a;"'
              . ($fontPx > 0 ? ' data-font-px="' . $fontPx . '"' : '')
              . ' onclick="return window.erpTablePrint ? window.erpTablePrint(this) : (window.print(), false);">'
              . '<i class="fa-solid fa-print me-1"></i> Print</button>';

        if (!$isPrintAll && $printAllUrl !== '' && $totalCount > $shownCount) {
            $out .= '<a href="' . tprint_esc($printAllUrl) . '" target="_blank" class="btn btn-sm btn-outline-primary fw-semibold tprint-all-btn"'
                  . ' onclick="return window.erpTablePrintAll ? window.erpTablePrintAll(this) : true;">'
                  . '<i class="fa-solid fa-print me-1"></i> Print All (' . $totalCount . ')</a>';
        } elseif ($isPrintAll) {
            $badgeCount = $shownCount > 0 ? $shownCount : $totalCount;
            $out .= '<span class="badge bg-info text-dark">All ' . $badgeCount . ' rows loaded</span>';
            $out .= '<a href="' . tprint_esc($backUrl) . '" class="btn btn-sm btn-outline-secondary">Back</a>';
        }

        $out .= '</' . $tag . '>';
        return $out;
    }
}

if (!function_exists('tprint_page_css')) {
    /**
     * Emit Tier-1 page print CSS so even Ctrl+P applies orientation, table sizing, and UI hiding.
     */
    function tprint_page_css(string $default_orient = 'portrait', int $font_px = 11): string
    {
        $o = $default_orient === 'landscape' ? 'landscape' : 'portrait';
        if (isset($_GET['print_orient']) && in_array($_GET['print_orient'], ['portrait', 'landscape'], true)) {
            $o = $_GET['print_orient'];
        }
        $font_px = max(8, min(14, $font_px));
        return '<style class="tprint-default-page-style">@media print {
            @page { size: A4 ' . $o . '; margin: 8mm; }
            html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
            .no-print, .tprint-toolbar, .pagination, nav[aria-label], .nav-tabs, .alert:not(.tprint-keep-alert) { display: none !important; }
            .card-custom { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 0 10px 0 !important; }
            .table-responsive { overflow: visible !important; }
            .card-custom table { width: 100% !important; font-size: ' . $font_px . 'px !important; border-collapse: collapse !important; }
            .card-custom table th, .card-custom table td { padding: 3px 4px !important; }
            .card-custom table thead { display: table-header-group; }
            .card-custom table tr { page-break-inside: avoid; }
            .card-custom table img { max-width: 18px !important; max-height: 18px !important; }
            .tprint-header { display: block !important; text-align: center; margin-bottom: 12px; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }</style>';
    }
}

if (!function_exists('tprint_auto_pages')) {
    /**
     * Explicit Tier-2 allowlist of data/list/report pages that get auto-injected Print controls.
     * Excludes Tier-1 explicit pages, already-printable pages, forms, settings, and CMS editors.
     */
    function tprint_auto_pages(string $portal = 'admin'): array
    {
        if ($portal === 'student') {
            return [
                'academic_calendar' => ['title' => 'Academic Calendar', 'orient' => 'portrait'],
                'exam_routine'      => ['title' => 'My Exam Routine',   'orient' => 'portrait'],
                'my_attendance'     => ['title' => 'My Attendance',     'orient' => 'portrait'],
                'my_dues'           => ['title' => 'My Dues (Monthly)', 'orient' => 'portrait'],
            ];
        }
        if ($portal === 'teacher') {
            return [
                'homework'          => ['title' => 'Homework List',      'orient' => 'landscape'],
                'lesson_plan'       => ['title' => 'Lesson Plan List',   'orient' => 'landscape'],
                'live_class'        => ['title' => 'Live Class List',    'orient' => 'landscape'],
                'recorded_class'    => ['title' => 'Recorded Class List','orient' => 'landscape'],
                'teacher_questions' => ['title' => 'Teacher Questions',  'orient' => 'landscape'],
            ];
        }
        if ($portal !== 'admin') {
            return [];
        }

        return [
            // Academic & Branch
            'academic_session'              => ['title' => 'Academic Sessions',               'orient' => 'portrait'],
            'academic_medium'               => ['title' => 'Academic Media',                  'orient' => 'portrait'],
            'academic_shift'                => ['title' => 'Academic Shifts',                 'orient' => 'portrait'],
            'academic_class'                => ['title' => 'All Class List',                  'orient' => 'portrait'],
            'academic_section'              => ['title' => 'All Section List',                'orient' => 'portrait'],
            'academic_group'                => ['title' => 'All Group List',                  'orient' => 'portrait'],
            'academic_subject'              => ['title' => 'All Subject List',                'orient' => 'landscape'],
            'branch'                        => ['title' => 'Branch List',                     'orient' => 'landscape'],

            // Admission
            'admissions'                    => ['title' => 'Admission Applications',          'orient' => 'landscape'],
            'admission_publish'             => ['title' => 'Admission Result / Publish',      'orient' => 'landscape'],
            'admission_selection'           => ['title' => 'Admission Selection List',        'orient' => 'landscape'],
            'admission_fee_claims'          => ['title' => 'Admission Fee Claims / Payments', 'orient' => 'landscape'],

            // Routine
            'routine_general'               => ['title' => 'General Routine List',            'orient' => 'landscape'],

            // HR, Leave & Payroll
            'hr_employee_type'              => ['title' => 'Employee Types',                  'orient' => 'portrait'],
            'hr_designation'                => ['title' => 'All Designations',                'orient' => 'portrait'],
            'hr_working_shift'              => ['title' => 'Working Shifts',                  'orient' => 'portrait'],
            'leave_types'                   => ['title' => 'Leave Types',                     'orient' => 'portrait'],
            'leave_manage'                  => ['title' => 'Leave Register',                  'orient' => 'landscape'],
            'payroll_head'                  => ['title' => 'Payroll Heads',                   'orient' => 'portrait'],
            'payroll_employee_payscale'     => ['title' => 'Employee Payscale',               'orient' => 'landscape'],
            'payroll_salary_chart'          => ['title' => 'Employee Salary Chart',           'orient' => 'landscape'],

            // Result, Analytics & Questions
            'result_grade_points'           => ['title' => 'Grade Points',                    'orient' => 'portrait'],
            'result_exam_terms'             => ['title' => 'Exam Terms',                      'orient' => 'portrait'],
            'result_exam_parts'             => ['title' => 'Exam Parts',                      'orient' => 'portrait'],
            'result_assign_exams'           => ['title' => 'Assigned Exams',                  'orient' => 'landscape'],
            'result_assign_subject_teacher' => ['title' => 'Subject Teacher Assignments',     'orient' => 'landscape'],
            'result_working_days'           => ['title' => 'Working Days',                    'orient' => 'portrait'],
            'result_exam_fee_collection'    => ['title' => 'Exam Fee Online Payments',        'orient' => 'landscape'],
            'result_subject_analysis'       => ['title' => 'Subject-wise Performance Analysis','orient' => 'landscape'],
            'result_student_progress'       => ['title' => 'Student Progress Tracking',       'orient' => 'landscape'],
            'result_section_comparison'     => ['title' => 'Class / Section Comparison',      'orient' => 'landscape'],
            'result_performer_alert'        => ['title' => 'Top / Bottom Performer Alert',    'orient' => 'landscape'],
            'result_marks_pending'          => ['title' => 'Marks Entry Pending Status',      'orient' => 'landscape'],
            'result_multiyear_cgpa'         => ['title' => 'Multi-Year CGPA Report',          'orient' => 'landscape'],
            'teacher_questions'             => ['title' => 'Teacher Questions',               'orient' => 'landscape'],

            // Finance & Scholarship
            'finance_head'                  => ['title' => 'Accounts Head List',              'orient' => 'portrait'],
            'finance_admission_fees'        => ['title' => 'Admission Fees Settings',         'orient' => 'landscape'],
            'finance_monthly_fees'          => ['title' => 'Monthly Fees Settings',           'orient' => 'landscape'],
            'finance_student_waiver'        => ['title' => 'Student Wise Waiver List',        'orient' => 'landscape'],
            'finance_online_payments'       => ['title' => 'Online Fee Payments',             'orient' => 'landscape'],
            'scholarship_types'             => ['title' => 'Scholarship Types',               'orient' => 'portrait'],
            'scholarship_applications'      => ['title' => 'Scholarship Applications',        'orient' => 'landscape'],
            'scholarship_award'             => ['title' => 'Scholarship Awards',              'orient' => 'landscape'],
            'scholarship_disbursement'      => ['title' => 'Scholarship Disbursements',       'orient' => 'landscape'],
            'poor_fund_aid'                 => ['title' => 'Poor Fund / Charity Aid',         'orient' => 'landscape'],

            // Accounts Module
            'accounts_chart'                => ['title' => 'Chart of Accounts',               'orient' => 'portrait'],
            'accounts_journal_voucher'      => ['title' => 'Journal Vouchers',                'orient' => 'landscape'],
            'accounts_receipt_voucher'      => ['title' => 'Receipt Vouchers',                'orient' => 'landscape'],
            'accounts_payment_voucher'      => ['title' => 'Payment Vouchers',                'orient' => 'landscape'],
            'accounts_contra_voucher'       => ['title' => 'Contra Vouchers',                 'orient' => 'landscape'],
            'accounts_vendors'              => ['title' => 'Vendors List',                    'orient' => 'landscape'],
            'accounts_vendor_bills'         => ['title' => 'Vendor Bills',                    'orient' => 'landscape'],

            // Library Module
            'library_books'                 => ['title' => 'Library Books List',              'orient' => 'landscape'],
            'library_categories'            => ['title' => 'Library Book Categories',         'orient' => 'portrait'],
            'library_issue'                 => ['title' => 'Issued Books List',               'orient' => 'landscape'],
            'library_return'                => ['title' => 'Book Return History',             'orient' => 'landscape'],

            // Transport Module
            'transport_vehicles'            => ['title' => 'Transport Vehicles',              'orient' => 'landscape'],
            'transport_routes'              => ['title' => 'Transport Routes',                'orient' => 'landscape'],
            'transport_stops'               => ['title' => 'Transport Route Stops',           'orient' => 'portrait'],
            'transport_assign_student'      => ['title' => 'Student Transport Assignments',   'orient' => 'landscape'],
            'transport_assign_staff'        => ['title' => 'Staff Transport Assignments',     'orient' => 'landscape'],
            'transport_fees'                => ['title' => 'Transport Fee Collection',        'orient' => 'landscape'],

            // Inventory Module
            'inventory'                     => ['title' => 'Inventory Items',                 'orient' => 'landscape'],
            'inventory_items'               => ['title' => 'Inventory Items',                 'orient' => 'landscape'],
            'inventory_purchase'            => ['title' => 'Inventory Purchases',             'orient' => 'landscape'],
            'inventory_sales'               => ['title' => 'Inventory Sales List',            'orient' => 'landscape'],
            'inventory_sales_return'        => ['title' => 'Inventory Sales Returns',         'orient' => 'landscape'],
            'inventory_due'                 => ['title' => 'Inventory Due List',              'orient' => 'landscape'],
            'inventory_supplier'            => ['title' => 'Inventory Suppliers',             'orient' => 'landscape'],
            'inventory_adjustment'          => ['title' => 'Inventory Stock Adjustments',     'orient' => 'landscape'],

            // Canteen Module
            'canteen_menu'                  => ['title' => 'Canteen Menu Items',              'orient' => 'landscape'],
            'canteen_raw_materials'         => ['title' => 'Canteen Raw Materials',           'orient' => 'landscape'],
            'canteen_purchase'              => ['title' => 'Canteen Purchases',               'orient' => 'landscape'],
            'canteen_due'                   => ['title' => 'Canteen Due List',                'orient' => 'landscape'],
            'canteen_supplier'              => ['title' => 'Canteen Suppliers',               'orient' => 'landscape'],
            'canteen_stock_adjustment'      => ['title' => 'Canteen Stock Adjustments',       'orient' => 'landscape'],

            // Promotional, E-Learning, Users & Security
            'promo_groups'                  => ['title' => 'Promotional Groups',              'orient' => 'portrait'],
            'promo_contacts'                => ['title' => 'Promotional Contacts',            'orient' => 'landscape'],
            'homework'                      => ['title' => 'Homework List',                   'orient' => 'landscape'],
            'lesson_plan'                   => ['title' => 'Lesson Plan List',                'orient' => 'landscape'],
            'live_class'                    => ['title' => 'Live Class List',                 'orient' => 'landscape'],
            'recorded_class'                => ['title' => 'Recorded Class List',             'orient' => 'landscape'],
            'users_list'                    => ['title' => 'System Users List',               'orient' => 'landscape'],
            'sms_users_students'            => ['title' => 'Student SMS Users',               'orient' => 'landscape'],
            'sms_users_employees'           => ['title' => 'Employee SMS Users',              'orient' => 'landscape'],
            'security_active_sessions'      => ['title' => 'Active Sessions',                 'orient' => 'landscape'],
        ];
    }
}

if (!function_exists('tprint_assets')) {
    /**
     * Emit shared CSS & JS runtime (plus Tier-2 auto-enhancement when allowlisted).
     */
    function tprint_assets(?PDO $pdo, string $page, string $portal = 'admin'): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        $autoMap  = tprint_auto_pages($portal);
        $viewMode = (string) ($_GET['view'] ?? '');
        $autoCfg  = null;
        if (isset($autoMap[$page]) && !in_array($viewMode, ['add', 'edit'], true)) {
            $entry = $autoMap[$page];
            $autoCfg = [
                'title'      => $entry['title'],
                'orient'     => $entry['orient'],
                'headerHtml' => tprint_header_html($pdo, $entry['title']),
            ];
        }

        $cfgJson = $autoCfg
            ? json_encode($autoCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)
            : 'null';

        $css = <<<'CSS'
<style id="tprint-style">
.tprint-header { display: none; }
.tprint-header.tprint-header-always { display: block; text-align: center; margin-bottom: 12px; }
.tprint-top { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 4px; }
.tprint-logo { width: 56px; height: 56px; object-fit: contain; }
.tprint-inst-info { text-align: center; }
.tprint-inst { color: #1e3a8a; font-weight: 800; font-size: 18px; margin: 0 0 2px; }
.tprint-line { font-size: 12px; color: #475569; line-height: 1.35; }
.tprint-title-wrap { margin-top: 6px; }
.tprint-title { display: inline-block; padding: 3px 14px; border: 1.5px solid #334155; border-radius: 3px; font-weight: 700; font-size: 13.5px; color: #0f172a; }
.tprint-sub { margin-top: 4px; font-size: 11.5px; color: #334155; font-weight: 600; }
.tprint-printed { margin-top: 2px; font-size: 10.5px; color: #64748b; }

@media print {
    .no-print, .tprint-toolbar, .tprint-hide-print { display: none !important; }
    .tprint-header { display: block !important; text-align: center; margin-bottom: 12px; }

    body.tprint-printing { background: #fff !important; height: auto !important; overflow: visible !important; }
    body.tprint-printing .pagination,
    body.tprint-printing nav[aria-label],
    body.tprint-printing .nav-tabs,
    body.tprint-printing .alert:not(.tprint-keep-alert) { display: none !important; }
    body.tprint-printing .card-custom,
    body.tprint-printing .card { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 0 10px 0 !important; }
    body.tprint-printing .table-responsive { overflow: visible !important; }
    body.tprint-printing table { width: 100% !important; border-collapse: collapse !important; }
    body.tprint-printing table thead { display: table-header-group; }
    body.tprint-printing table tr { page-break-inside: avoid; }
    body.tprint-printing table img { max-height: 20px !important; max-width: 20px !important; }
    body.tprint-printing table.tprint-table-compact { font-size: 9.5px !important; }
    body.tprint-printing table.tprint-table-compact th,
    body.tprint-printing table.tprint-table-compact td { padding: 2px 4px !important; }
    body.tprint-printing table.tprint-table-medium { font-size: 10.5px !important; }
    body.tprint-printing table.tprint-table-medium th,
    body.tprint-printing table.tprint-table-medium td { padding: 3px 5px !important; }
    body.tprint-printing table.tprint-table-normal { font-size: 11.5px !important; }
    body.tprint-printing table.tprint-table-normal th,
    body.tprint-printing table.tprint-table-normal td { padding: 4px 6px !important; }
    body.tprint-printing .tprint-expand-col { flex: 0 0 100% !important; max-width: 100% !important; width: 100% !important; }
}
</style>
CSS;

        $js = <<<JS
<script id="tprint-script">
(function () {
    if (window.__tprintInit) { return; }
    window.__tprintInit = true;

    var autoCfg = {$cfgJson};
    var autoBarActive = false;

    function getOrient(el) {
        var bar = el ? el.closest('.tprint-toolbar') : document.querySelector('.tprint-toolbar');
        var sel = bar ? bar.querySelector('.tprint-orient-select') : null;
        if (!sel) { sel = document.querySelector('.tprint-orient-select'); }
        return (sel && sel.value === 'landscape') ? 'landscape' : 'portrait';
    }

    function applyPageStyle(orient, fontPx) {
        var s = document.getElementById('tprintPageStyle');
        if (!s) {
            s = document.createElement('style');
            s.id = 'tprintPageStyle';
        }
        // Always append at end of body so user-selected orientation overrides inline page styles
        (document.body || document.head).appendChild(s);
        var css = '@page { size: A4 ' + orient + '; margin: 8mm; }';
        if (fontPx && fontPx > 0) {
            css += ' @media print { .card-custom table { font-size: ' + fontPx + 'px !important; } }';
        }
        s.textContent = css;
    }

    function isVisibleEl(el) {
        if (!el) { return false; }
        if (el.closest('.modal, .d-none, .tab-pane:not(.active)')) { return false; }
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    function isPrintableDataTable(tbl) {
        if (!tbl || !isVisibleEl(tbl)) { return false; }
        var bodyRows = Array.prototype.slice.call(tbl.querySelectorAll('tbody > tr'));
        if (!bodyRows.length) { return false; }
        var visibleRows = bodyRows.filter(function (tr) { return tr.style.display !== 'none'; });
        if (!visibleRows.length) { return false; }

        // Check if this is predominantly a form-entry grid (e.g. marks entry / attendance checkboxes)
        var totalCells = 0;
        var inputCells = 0;
        visibleRows.slice(0, 15).forEach(function (tr) {
            Array.prototype.slice.call(tr.cells).forEach(function (td) {
                totalCells++;
                if (td.querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea')) {
                    inputCells++;
                }
            });
        });
        if (totalCells > 0 && (inputCells / totalCells) > 0.5) {
            return false;
        }
        return true;
    }

    function findPrintableTables(root) {
        return Array.prototype.slice.call(root.querySelectorAll('table')).filter(isPrintableDataTable);
    }

    function markHidden(el) {
        if (el && !el.classList.contains('tprint-hide-print')) {
            el.classList.add('tprint-hide-print');
        }
    }

    function cellHasOnlyControls(td) {
        if (!td) { return false; }
        var clone = td.cloneNode(true);
        clone.querySelectorAll('button, a, form, input, select').forEach(function (n) { n.remove(); });
        return clone.textContent.replace(/\s+/g, '') === '' && !!td.querySelector('button, a, form, input[type="checkbox"]');
    }

    function prepareAutoPrint() {
        var body = document.querySelector('.content-body');
        if (!body) { return; }
        var tables = findPrintableTables(body);
        if (!tables.length) { return; }

        // Hide non-printable cards, standalone filter forms, tabs, alerts, pagination
        var printableCards = new Set();
        tables.forEach(function (t) {
            var c = t.closest('.card-custom, .card');
            if (c) { printableCards.add(c); }
        });

        body.querySelectorAll('.card-custom, .card').forEach(function (card) {
            if (!printableCards.has(card)) {
                var containsPrintable = false;
                printableCards.forEach(function (pc) {
                    if (card.contains(pc) || pc.contains(card)) { containsPrintable = true; }
                });
                if (!containsPrintable) {
                    var colParent = card.parentElement && /\bcol-/.test(card.parentElement.className) ? card.parentElement : null;
                    if (colParent && !findPrintableTables(colParent).length) {
                        markHidden(colParent);
                        if (colParent.parentElement) {
                            Array.prototype.slice.call(colParent.parentElement.children).forEach(function (sib) {
                                if (sib !== colParent && /\bcol-/.test(sib.className) && findPrintableTables(sib).length) {
                                    sib.classList.add('tprint-expand-col');
                                }
                            });
                        }
                    } else {
                        markHidden(card);
                    }
                }
            }
        });

        body.querySelectorAll('.nav-tabs, .inv-tab-boxes, .pagination, nav[aria-label], .alert:not(.tprint-keep-alert)').forEach(markHidden);

        // Hide standalone direct button/heading bars outside cards (e.g. Back to Routes)
        Array.prototype.slice.call(body.children).forEach(function (ch) {
            if (ch.classList.contains('card-custom') || ch.classList.contains('card') || ch.classList.contains('row') || ch.classList.contains('tprint-header')) {
                return;
            }
            if (!findPrintableTables(ch).length && ch.querySelector('a.btn, button.btn, input, select')) {
                markHidden(ch);
            }
        });

        body.querySelectorAll('form').forEach(function (form) {
            var hasTable = tables.some(function (t) { return form.contains(t); });
            if (!hasTable && !form.closest('table')) {
                markHidden(form);
            }
        });

        // Hide top Add/toolbar button strips inside printable cards (not inside the table)
        printableCards.forEach(function (card) {
            Array.prototype.slice.call(card.children).forEach(function (ch) {
                if (ch.classList.contains('tprint-header') || ch.classList.contains('table-responsive') || ch.tagName === 'TABLE') {
                    return;
                }
                if (ch.querySelector('table')) { return; }
                if (ch.matches('form') || ch.querySelector('a.btn, button.btn, input, select')) {
                    if (!ch.querySelector('.tprint-keep-print')) {
                        markHidden(ch);
                    }
                }
            });
        });

        // Clean up filter rows & Action columns on each printable table
        tables.forEach(function (tbl) {
            var thead = tbl.tHead;
            var headRows = thead ? Array.prototype.slice.call(thead.rows) : [];
            var primaryHead = null;

            headRows.forEach(function (hr) {
                var hasFilterCtrl = !!hr.querySelector('input:not([type="checkbox"]), select, button');
                if (headRows.length > 1 && hasFilterCtrl) {
                    markHidden(hr);
                } else if (!primaryHead) {
                    primaryHead = hr;
                }
            });
            if (!primaryHead && headRows.length) {
                primaryHead = headRows[0];
            }
            if (!primaryHead) { return; }

            var headerCells = Array.prototype.slice.call(primaryHead.cells);
            var headerCount = headerCells.length;
            var bodyRows = Array.prototype.slice.call(tbl.querySelectorAll('tbody > tr')).filter(function (r) { return r.style.display !== 'none'; });
            var hideCols = [];

            headerCells.forEach(function (th, idx) {
                var txt = th.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
                if (txt === 'action' || txt === 'actions' || txt === 'অ্যাকশন' || txt === 'edit' || txt === 'delete') {
                    hideCols.push(idx);
                    return;
                }
                if ((txt === 'print' || txt === 'প্রিন্ট') && bodyRows.length > 0) {
                    var printSample = bodyRows.slice(0, 10);
                    if (printSample.every(function (r) { return r.cells.length === headerCount && cellHasOnlyControls(r.cells[idx]); })) {
                        hideCols.push(idx);
                        return;
                    }
                }
                if (th.querySelector('input[type="checkbox"], button')) {
                    hideCols.push(idx);
                    return;
                }
                if (txt === '' && bodyRows.length > 0) {
                    var sample = bodyRows.slice(0, 10);
                    var allCtrl = sample.every(function (r) {
                        return r.cells.length === headerCount && cellHasOnlyControls(r.cells[idx]);
                    });
                    if (allCtrl) { hideCols.push(idx); }
                }
            });

            if (hideCols.length) {
                var allRows = Array.prototype.slice.call(tbl.rows);
                allRows.forEach(function (r) {
                    if (r.cells.length === headerCount) {
                        hideCols.forEach(function (idx) { markHidden(r.cells[idx]); });
                    } else if (r.cells.length === 1 && r.cells[0].colSpan === headerCount) {
                        if (!r.cells[0].dataset.tprintOrigColspan) {
                            r.cells[0].dataset.tprintOrigColspan = String(r.cells[0].colSpan);
                        }
                        r.cells[0].colSpan = Math.max(1, headerCount - hideCols.length);
                    }
                });
            }

            var visCols = Math.max(1, headerCount - hideCols.length);
            tbl.classList.remove('tprint-table-compact', 'tprint-table-medium', 'tprint-table-normal');
            if (visCols >= 11) {
                tbl.classList.add('tprint-table-compact');
            } else if (visCols >= 8) {
                tbl.classList.add('tprint-table-medium');
            } else {
                tbl.classList.add('tprint-table-normal');
            }
        });
    }

    function cleanupAutoPrint() {
        document.body.classList.remove('tprint-printing');
        document.querySelectorAll('.tprint-hide-print').forEach(function (el) {
            el.classList.remove('tprint-hide-print');
        });
        document.querySelectorAll('.tprint-expand-col').forEach(function (el) {
            el.classList.remove('tprint-expand-col');
        });
        document.querySelectorAll('[data-tprint-orig-colspan]').forEach(function (td) {
            td.colSpan = parseInt(td.dataset.tprintOrigColspan, 10) || 1;
            delete td.dataset.tprintOrigColspan;
        });
        document.querySelectorAll('.tprint-table-compact, .tprint-table-medium, .tprint-table-normal').forEach(function (tbl) {
            tbl.classList.remove('tprint-table-compact', 'tprint-table-medium', 'tprint-table-normal');
        });
    }

    window.erpTablePrint = function (btn) {
        var orient = getOrient(btn);
        var fontPx = btn && btn.dataset && btn.dataset.fontPx ? parseInt(btn.dataset.fontPx, 10) : 0;
        applyPageStyle(orient, fontPx);
        if (autoBarActive || (btn && btn.closest('.tprint-auto-bar'))) {
            prepareAutoPrint();
        }
        document.body.classList.add('tprint-printing');
        window.print();
        return false;
    };

    window.erpTablePrintAll = function (anchor) {
        if (!anchor) { return true; }
        var orient = getOrient(anchor);
        try {
            var u = new URL(anchor.getAttribute('href'), window.location.href);
            u.searchParams.set('print_orient', orient);
            anchor.setAttribute('href', u.toString());
        } catch (e) { /* keep original href */ }
        return true;
    };

    window.addEventListener('beforeprint', function () {
        if (autoBarActive && !document.body.classList.contains('tprint-printing')) {
            var bars = Array.prototype.slice.call(document.querySelectorAll('.tprint-auto-bar'));
            var visBar = bars.find(isVisibleEl) || bars[0];
            var sel = visBar ? visBar.querySelector('.tprint-orient-select') : null;
            applyPageStyle((sel && sel.value === 'landscape') ? 'landscape' : 'portrait', 0);
            prepareAutoPrint();
            document.body.classList.add('tprint-printing');
        }
    });

    window.addEventListener('afterprint', cleanupAutoPrint);

    function initAutoToolbar() {
        if (!autoCfg) { return; }
        var body = document.querySelector('.content-body');
        if (!body) { return; }

        // Never inject if the page already defines explicit print controls
        if (body.querySelector('.tprint-toolbar:not(.tprint-auto-bar), .tprint-btn:not(.tprint-auto-btn), [onclick*="eaPrint"], [onclick*="invPrintReport"]')) {
            return;
        }
        var existingPrintBtns = Array.prototype.slice.call(body.querySelectorAll('button[onclick*="window.print"], a[onclick*="window.print"]'));
        if (existingPrintBtns.length) {
            return;
        }

        var tables = findPrintableTables(body);
        if (!tables.length) { return; }

        var firstCard = tables[0].closest('.inv-tab-pane, .card-custom, .card') || tables[0].parentElement;
        if (!firstCard || firstCard.querySelector('.tprint-toolbar')) { return; }

        var bar = document.createElement('div');
        bar.className = 'd-flex justify-content-end align-items-center gap-2 mb-2 flex-wrap no-print tprint-toolbar tprint-auto-bar';
        bar.innerHTML =
            '<label class="small fw-semibold text-muted mb-0">Page:</label>' +
            '<select class="form-select form-select-sm tprint-orient-select" data-ss="off" style="width:auto;">' +
                '<option value="portrait"' + (autoCfg.orient === 'portrait' ? ' selected' : '') + '>Portrait</option>' +
                '<option value="landscape"' + (autoCfg.orient === 'landscape' ? ' selected' : '') + '>Landscape</option>' +
            '</select>' +
            '<button type="button" class="btn btn-sm btn-primary fw-bold tprint-btn tprint-auto-btn" style="background-color:#1e3a8a;" onclick="return window.erpTablePrint(this);">' +
                '<i class="fa-solid fa-print me-1"></i> Print</button>';

        var hdrWrap = document.createElement('div');
        hdrWrap.innerHTML = autoCfg.headerHtml || '';
        var hdr = hdrWrap.firstElementChild;

        if (firstCard.classList.contains('inv-tab-pane') || firstCard.classList.contains('card-custom') || firstCard.classList.contains('card')) {
            var insertInside = firstCard.querySelector(':scope > .card-body') || firstCard;
            if (hdr) { insertInside.insertBefore(hdr, insertInside.firstChild); }
            insertInside.insertBefore(bar, insertInside.firstChild);
        } else {
            if (hdr) { firstCard.parentNode.insertBefore(hdr, firstCard); }
            firstCard.parentNode.insertBefore(bar, firstCard);
        }
        autoBarActive = true;
    }

    function attachTabClickWatcher() {
        document.addEventListener('click', function (e) {
            if (e.target && e.target.closest('.inv-tab-box, [data-bs-toggle="tab"], [data-bs-toggle="pill"]')) {
                setTimeout(initAutoToolbar, 30);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAutoToolbar();
            attachTabClickWatcher();
        });
    } else {
        initAutoToolbar();
        attachTabClickWatcher();
    }

    window.addEventListener('load', function () {
        var autoToolbar = document.querySelector('.tprint-toolbar[data-tprint-autoprint="1"]');
        if (autoToolbar) {
            var btn = autoToolbar.querySelector('.tprint-btn');
            if (btn) {
                setTimeout(function () { window.erpTablePrint(btn); }, 250);
            }
        }
    });
})();
</script>
JS;

        return $css . "\n" . $js;
    }
}
