<?php
// admin/inc/perm_ui.php — Restrict করা ইউজারের জন্য UI পরিষ্কার রাখে (শুধু cosmetic; আসল ব্লক সার্ভারে)
// - sidebar থেকে অনুমতি-না-থাকা পেজের লিংক (ও ফাঁকা হয়ে যাওয়া মডিউল) সরায়
// - এই পেজে Add/Edit/Delete অনুমতি না থাকলে সংশ্লিষ্ট বোতাম/লিংক লুকায়
if (perm_is_super_admin()) { return; }
$__pu_uid = perm_current_system_user_id();
if (!$__pu_uid) { return; }
$__pu = perm_load($pdo, $__pu_uid);
if (empty($__pu['restricted'])) { return; }
$__pu_act = $__pu['actions'][$page ?? ''] ?? ['add' => true, 'edit' => true, 'delete' => true];
$__pu_cfg = ['pages' => array_values($__pu['allowed']), 'act' => $__pu_act];
?>
<script>
(function () {
    var cfg = <?php echo json_encode($__pu_cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    function pageOf(href) { var m = /[?&]page=([A-Za-z0-9_]+)/.exec(href || ''); return m ? m[1] : null; }
    function run() {
        // sidebar: অনুমতি নেই এমন পেজের লিংক সরানো
        document.querySelectorAll('.sidebar a[href*="page="]').forEach(function (a) {
            var pg = pageOf(a.getAttribute('href'));
            if (pg && pg !== 'dashboard' && cfg.pages.indexOf(pg) === -1) { var li = a.closest('li'); if (li) li.remove(); }
        });
        // ফাঁকা হয়ে যাওয়া সাবমেনু ও তার মডিউল সরানো (ভেতর থেকে বাইরের দিকে)
        for (var i = 0; i < 4; i++) {
            document.querySelectorAll('.sidebar ul').forEach(function (ul) {
                if (!ul.querySelector('li')) { var li = ul.closest('li'); if (li) li.remove(); else ul.remove(); }
            });
        }
        function hide(sel) { document.querySelectorAll(sel).forEach(function (el) { el.style.display = 'none'; el.setAttribute('data-perm-hidden', '1'); }); }
        var main = document.querySelector('.content-body') || document.body;
        function q(sel) { return main.querySelectorAll(sel); }
        function hideIn(sel) { q(sel).forEach(function (el) { el.style.display = 'none'; el.setAttribute('data-perm-hidden', '1'); }); }
        if (!cfg.act.delete) { hideIn('button[name*="delete" i], input[name*="delete" i][type="submit"], a[href*="delete" i], .btn-delete, [data-action="delete"], button[onclick*="delete" i]'); }
        if (!cfg.act.edit)   { hideIn('button[name*="edit" i], a[href*="edit" i], .btn-edit, [data-action="edit"], button[onclick*="edit" i]'); }
        if (!cfg.act.add)    { hideIn('a[href*="&add"], a[href*="?add"], a[href*="_add"], a[href*="=add"], .btn-add, [data-action="add"]'); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
</script>
