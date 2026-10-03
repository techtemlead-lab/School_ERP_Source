<?php
// config/map_embed.php - Branch এর "Map IFrame" ফিল্ড থেকে নিরাপদ ম্যাপ iframe বানানো।
// Google Maps এর পুরো <iframe ...> কোড, শুধু embed URL, বা HTML-escape হয়ে সেভ হওয়া কোড — সবই সাপোর্ট করে।
// শুধু https লিংক গ্রহণযোগ্য; বাকি সব অ্যাট্রিবিউট ফেলে দিয়ে নিজের ফিক্সড অ্যাট্রিবিউট দিয়ে iframe বানানো হয়।

function map_embed_src($raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') return '';
    $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $src = '';
    if (preg_match('/<iframe\b[^>]*?\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $raw, $m)) {
        $src = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
    } elseif (preg_match('#^https?://\S+$#i', $raw)) {
        $src = $raw; // শুধু লিংক পেস্ট করা হলে
    }
    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8')); // দুইবার escape হয়ে থাকলেও ঠিক থাকবে
    if ($src === '' || !preg_match('#^https://#i', $src)) return '';
    return $src;
}

function map_embed_html($raw, string $title = 'Map'): string
{
    $src = map_embed_src($raw);
    if ($src === '') return '';
    return '<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
}
