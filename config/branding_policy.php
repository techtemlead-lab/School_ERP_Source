<?php
// config/branding_policy.php - Per-tenant & per-surface "Powered by TechTeam" branding policy engine.
// Pure helper with zero master DB dependencies (safe inside ERP_ROLE=tenant isolated runtimes).

if (!function_exists('erp_branding_surfaces')) {
    function erp_branding_surfaces(): array
    {
        return [
            'admit_1full_modern' => [
                'label' => 'Admit Card — Template 2 (1-Page Modern & QR)',
                'group' => 'Admit Card',
                'desc'  => '১-পেজ আধুনিক অ্যাডমিট কার্ড (General ও Admission)',
            ],
            'admit_1full_classic' => [
                'label' => 'Admit Card — Template 1 (1-Page Classic Grid)',
                'group' => 'Admit Card',
                'desc'  => '১-পেজ ক্লাসিক অফিসিয়াল অ্যাডমিট কার্ড',
            ],
            'seat_plan' => [
                'label' => 'Exam Seat Plan (Admin ও Student)',
                'group' => 'Exam Seat Plan',
                'desc'  => 'পরীক্ষার সিট প্ল্যান কার্ড',
            ],
        ];
    }
}

if (!function_exists('erp_branding_policies')) {
    function erp_branding_policies(): array
    {
        return [
            'allow'     => 'Allow School Toggle (স্কুল অন/অফ করতে পারবে)',
            'force_on'  => 'Force ON (সবসময় দেখাবে — লকড)',
            'force_off' => 'Force OFF (বন্ধ / White-label)',
        ];
    }
}

if (!function_exists('erp_branding_valid_policy')) {
    function erp_branding_valid_policy(?string $policy): string
    {
        $p = trim((string) ($policy ?? ''));
        return in_array($p, ['allow', 'force_on', 'force_off'], true) ? $p : 'allow';
    }
}

if (!function_exists('erp_branding_decode_policy')) {
    function erp_branding_decode_policy($raw): array
    {
        $data = [];
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $data = $dec;
            }
        }
        $default = erp_branding_valid_policy(isset($data['default']) ? (string) $data['default'] : 'allow');
        $out = ['default' => $default];
        foreach (erp_branding_surfaces() as $key => $_meta) {
            $out[$key] = erp_branding_valid_policy(isset($data[$key]) ? (string) $data[$key] : $default);
        }
        return $out;
    }
}

if (!function_exists('erp_branding_encode_policy')) {
    function erp_branding_encode_policy(array $in): string
    {
        $default = erp_branding_valid_policy(isset($in['default']) ? (string) $in['default'] : 'allow');
        $out = ['default' => $default];
        foreach (erp_branding_surfaces() as $key => $_meta) {
            $out[$key] = erp_branding_valid_policy(isset($in[$key]) ? (string) $in[$key] : $default);
        }
        return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('erp_branding_surface_policy')) {
    function erp_branding_surface_policy(string $surface, ?array $tenant = null): string
    {
        $t = $tenant ?? (is_array($GLOBALS['tenant'] ?? null) ? $GLOBALS['tenant'] : null);
        $raw = is_array($t) ? ($t['branding_policy_json'] ?? ($t['branding_policy'] ?? null)) : null;
        $map = erp_branding_decode_policy($raw);
        return $map[$surface] ?? $map['default'] ?? 'allow';
    }
}

if (!function_exists('erp_branding_can_school_toggle')) {
    function erp_branding_can_school_toggle(string $surface, ?array $tenant = null): bool
    {
        return erp_branding_surface_policy($surface, $tenant) === 'allow';
    }
}

if (!function_exists('erp_branding_resolve_show')) {
    function erp_branding_resolve_show(string $surface, bool $school_pref = true, ?array $tenant = null): bool
    {
        $pol = erp_branding_surface_policy($surface, $tenant);
        if ($pol === 'force_on') {
            return true;
        }
        if ($pol === 'force_off') {
            return false;
        }
        return $school_pref;
    }
}

if (!function_exists('erp_branding_summary')) {
    function erp_branding_summary(array $policy_map): array
    {
        $surfaces = array_keys(erp_branding_surfaces());
        $total = count($surfaces);
        $counts = ['allow' => 0, 'force_on' => 0, 'force_off' => 0];
        foreach ($surfaces as $k) {
            $st = erp_branding_valid_policy(isset($policy_map[$k]) ? (string) $policy_map[$k] : ($policy_map['default'] ?? 'allow'));
            $counts[$st]++;
        }
        if ($counts['allow'] === $total) {
            return ['label' => 'All Allowed', 'badge' => 'bg-success'];
        }
        if ($counts['force_on'] === $total) {
            return ['label' => 'All Locked ON', 'badge' => 'bg-warning text-dark'];
        }
        if ($counts['force_off'] === $total) {
            return ['label' => 'White-label (OFF)', 'badge' => 'bg-info text-dark'];
        }
        $locked_cnt = $counts['force_on'] + $counts['force_off'];
        return ['label' => "Custom ({$locked_cnt}/{$total} Locked)", 'badge' => 'bg-primary'];
    }
}
