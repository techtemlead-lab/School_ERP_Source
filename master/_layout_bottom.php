<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — Shared Sidebar Layout (Footer অংশ)
// প্রতিটা master/*.php পেজের একদম শেষে require করবে
// ══════════════════════════════════════════════════════════════
?>
</div>
<?php echo renderFooterCredit($pdo_master, 'page'); ?>
<script>
function toggleMenu(e, menuId, arrowId) {
  e.preventDefault(); e.stopPropagation();
  var menu = document.getElementById(menuId);
  var arrow = document.getElementById(arrowId);
  var open = menu.offsetHeight > 0;
  if (open) { menu.style.maxHeight = '0'; arrow.textContent = '▸'; }
  else { menu.style.maxHeight = menu.scrollHeight + 'px'; arrow.textContent = '▾'; }
}

// ── টপ-রাইট ইউজার ড্রপডাউন ──────
function toggleUserMenu(e) {
  e.preventDefault(); e.stopPropagation();
  document.getElementById('userMenu').classList.toggle('open');
}
document.addEventListener('click', function(e){
  var um = document.getElementById('userMenu');
  if (um && !um.contains(e.target)) um.classList.remove('open');
});

// ── Sidebar Collapse/Expand ──────────────────────
function toggleSidebar() {
  var sb = document.getElementById('saasSidebar');
  if (window.innerWidth <= 768) {
    sb.classList.toggle('mob-open');
  } else {
    sb.classList.toggle('collapsed');
    document.body.classList.toggle('sidebar-collapsed', sb.classList.contains('collapsed'));
    localStorage.setItem('saas_sidebar_collapsed', sb.classList.contains('collapsed') ? '1' : '0');
  }
}
</script>
<script>
    // Mouse Scroll করলে ফোকাসড <input type="number"> এর ভ্যালু যেন ভুলবশত বদলে না যায় —
    // scroll করার সাথে সাথে inputটা blur করে দেওয়া হচ্ছে, তাতে পেজ স্বাভাবিকভাবে স্ক্রল হবে
    // কিন্তু নাম্বার বদলাবে না।
    document.addEventListener('wheel', function (e) {
        var el = document.activeElement;
        if (el && el.tagName === 'INPUT' && el.type === 'number') {
            el.blur();
        }
    }, { passive: true });
</script>
<?php if (function_exists('getIdleTimeoutMinutes')) include __DIR__ . '/auto_logout.php'; ?>
</body>
</html>
