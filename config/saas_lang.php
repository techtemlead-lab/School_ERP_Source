<?php
/**
 * config/saas_lang.php
 * -----------------------------------------------------------
 * SaaS Marketing & Landing Page Translation & Localization Engine
 * Supports Bengali ('bn' - Default) and English ('en').
 * Persists via Session, Cookie, and URL query param (?lang=bn|en).
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Get current SaaS language ('bn' or 'en')
 */
function saas_current_lang(): string
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['bn', 'en'], true)) {
        $lang = $_GET['lang'];
        $_SESSION['saas_lang'] = $lang;
        @setcookie('saas_lang', $lang, time() + (86400 * 30), '/');
        return $lang;
    }

    if (!empty($_SESSION['saas_lang'])) {
        return $_SESSION['saas_lang'];
    }

    if (!empty($_COOKIE['saas_lang']) && in_array($_COOKIE['saas_lang'], ['bn', 'en'], true)) {
        return $_COOKIE['saas_lang'];
    }

    return 'bn'; // Default to Bangla
}

/**
 * Translate a language key
 */
function saas_t(string $key, array $replace = []): string
{
    static $dict = null;
    if ($dict === null) {
        $file = __DIR__ . '/saas_strings.php';
        $dict = file_exists($file) ? require $file : [];
    }

    $lang = saas_current_lang();
    $text = $dict[$lang][$key] ?? $dict['bn'][$key] ?? $key;

    if (!empty($replace)) {
        foreach ($replace as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, (string)$value, $text);
        }
    }

    return $text;
}

/**
 * Convert numbers to Bengali digits if current language is Bangla
 */
function saas_num($number): string
{
    $str = (string) $number;
    if (saas_current_lang() !== 'bn') {
        return $str;
    }

    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return str_replace($en, $bn, $str);
}
