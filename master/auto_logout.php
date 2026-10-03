<?php
// ══════════════════════════════════════════════════════════════
// School SaaS — Master Panel — Inactivity Auto Logout
// _layout_bottom.php থেকে সব guarded পেজে অটো include হয়।
// সময় কনফিগারেবল — settings.php > 🔐 Security ট্যাব থেকে মিনিট
// সেট করা যায়, 0 দিলে সম্পূর্ণ বন্ধ। ডিফল্ট ৫ মিনিট।
// ══════════════════════════════════════════════════════════════
$_idleMinutes = getIdleTimeoutMinutes($pdo_master);
if ($_idleMinutes > 0):
?>
<script>
(function(){
  var IDLE_LIMIT_MS  = <?php echo (int)$_idleMinutes; ?> * 60 * 1000;
  var WARN_BEFORE_MS = Math.min(30 * 1000, IDLE_LIMIT_MS / 4);
  var LS_KEY = 'school_saas_last_activity';
  var logoutTimer, warnTimer, warnBox;

  function logoutNow(){ window.location.href = 'logout.php?idle=1'; }
  function hideWarning(){ if (warnBox) { warnBox.remove(); warnBox = null; } }
  function showWarning(){
    if (warnBox) return;
    warnBox = document.createElement('div');
    warnBox.style.cssText = 'position:fixed;bottom:20px;right:20px;background:var(--sidebar-bg);border:1px solid var(--sidebar-accent);border-radius:10px;padding:14px 18px;color:var(--body-text);font-family:var(--font-family);font-size:13px;z-index:99999;box-shadow:0 4px 18px rgba(0,0,0,.5);max-width:280px;';
    warnBox.innerHTML = 'কিছুক্ষণের মধ্যে সেশন টাইমআউট হবে।<br><button id="idleStayBtn" style="margin-top:8px;background:var(--sidebar-accent);color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">লগইন রাখো</button>';
    document.body.appendChild(warnBox);
    document.getElementById('idleStayBtn').addEventListener('click', resetTimer);
  }
  function resetTimer(){
    hideWarning();
    clearTimeout(logoutTimer); clearTimeout(warnTimer);
    try { localStorage.setItem(LS_KEY, Date.now().toString()); } catch(e){}
    warnTimer   = setTimeout(showWarning, IDLE_LIMIT_MS - WARN_BEFORE_MS);
    logoutTimer = setTimeout(logoutNow, IDLE_LIMIT_MS);
  }
  window.addEventListener('storage', function(e){
    if (e.key === LS_KEY) {
      hideWarning(); clearTimeout(logoutTimer); clearTimeout(warnTimer);
      warnTimer   = setTimeout(showWarning, IDLE_LIMIT_MS - WARN_BEFORE_MS);
      logoutTimer = setTimeout(logoutNow, IDLE_LIMIT_MS);
    }
  });
  ['mousedown','mousemove','keydown','scroll','touchstart','click'].forEach(function(evt){
    document.addEventListener(evt, resetTimer, { passive: true });
  });
  resetTimer();
})();
</script>
<?php endif; ?>
