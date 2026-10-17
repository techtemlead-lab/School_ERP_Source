<?php
/**
 * config/default_password.php
 * Student/Teacher পোর্টালের ডিফল্ট পাসওয়ার্ড (যাদের নিজের পাসওয়ার্ড এখনো সেট হয়নি)।
 * নতুন Student/Employee যোগ করলে password কলাম খালি (NULL) থাকে, তাই তারাও এই পাসওয়ার্ডে
 * প্রথমবার ঢুকতে পারে; ঢোকার পর নিজের পাসওয়ার্ড বদলানোর বার্তা দেখায়।
 */
if (!function_exists('erp_default_password')) {
    function erp_default_password(): string
    {
        return '123456';
    }
}
