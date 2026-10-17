<?php
/**
 * config/searchable_select.php
 * -----------------------------------------------------------
 * সব পোর্টালের (Admin / Teacher / Student) বড় ড্রপডাউনে টাইপ করে সার্চ করার সুবিধা।
 *
 * কীভাবে কাজ করে:
 *  - কোনো <select>-এ ৬টি বা তার বেশি অপশন থাকলে সেটা নিজে থেকেই "সার্চযোগ্য" হয়ে যায়
 *    (Class → Student এর মতো AJAX দিয়ে পরে ভরা তালিকাতেও কাজ করে)।
 *  - আসল <select> ফর্মে থেকেই যায় (লুকানো), তাই name/value/required/ফর্ম সাবমিট,
 *    change ইভেন্ট ও আগের সব JavaScript আগের মতোই চলে — কোনো পেজ বদলানো লাগে না।
 *  - বাদ দিতে চাইলে <select data-ss="off"> অথবা class="no-ss" দিন। multiple সিলেক্ট বাদ।
 *  - বাইরের লাইব্রেরি/CDN লাগে না।
 *
 * ব্যবহার: Layout-এর শেষে  <?php require_once __DIR__ . '/../config/searchable_select.php'; echo searchable_select_assets(); ?>
 */

if (!function_exists('searchable_select_assets')) {
    function searchable_select_assets(): string
    {
        static $printed = false;
        if ($printed) { return ''; }
        $printed = true;
        return <<<'HTML'
<style id="ss-style">
.ss-wrap{position:relative}
.ss-wrap>select.ss-native{position:absolute!important;left:0!important;bottom:0!important;width:100%!important;height:1px!important;min-height:0!important;opacity:0!important;pointer-events:none!important;padding:0!important;border:0!important;margin:0!important;display:block!important}
.ss-btn{display:block;width:100%;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.ss-btn:not(.form-select){background:#fff url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") no-repeat right .7rem center/12px 10px;padding-right:2rem}
.ss-btn.ss-ph{color:#6c757d}
.ss-btn:disabled{opacity:.65;cursor:not-allowed}
.ss-panel{position:fixed;z-index:3000;display:none;background:#fff;color:#212529;border:1px solid #cfd8e3;border-radius:8px;box-shadow:0 10px 30px rgba(15,23,42,.18);overflow:hidden;font-size:14px}
.ss-panel.ss-open{display:block}
.ss-search{display:block;width:100%;box-sizing:border-box;border:0;border-bottom:1px solid #e3e8ef;padding:9px 12px;font-size:14px;outline:none;background:#f8fafc;color:#212529}
.ss-list{max-height:260px;overflow-y:auto;list-style:none;margin:0;padding:4px}
.ss-opt{padding:7px 10px;border-radius:5px;cursor:pointer;line-height:1.35}
.ss-opt.ss-act{background:#e7f0ff}
.ss-opt.ss-sel{font-weight:600;color:#0d6efd}
.ss-opt.ss-dis{opacity:.45;cursor:not-allowed}
.ss-opt.ss-blank{color:#6c757d}
.ss-grp{font-size:12px;font-weight:700;color:#6b7280;padding:8px 10px 3px}
.ss-empty{padding:12px;color:#6b7280;text-align:center}
.ss-more{padding:6px 10px;color:#6b7280;font-size:12px;text-align:center}
</style>
<script id="ss-script">
(function () {
    if (window.__ssInit) { return; }
    window.__ssInit = true;

    var MIN_BUILD = 6;     // এতগুলো বা বেশি অপশন হলে সার্চ চালু
    var MIN_KEEP  = 3;     // এর নিচে নেমে গেলে আবার সাধারণ select
    var MAX_SHOW  = 400;   // একসাথে সর্বোচ্চ কয়টা অপশন আঁকবে (বাকিটা সার্চ করে পাবে)
    var MAP = new WeakMap();
    var openW = null;
    var descValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    var descIndex = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');

    function eligible(sel) {
        if (sel.multiple || sel.size > 1) { return false; }
        if (sel.dataset.ss === 'off' || sel.classList.contains('no-ss')) { return false; }
        if (sel.closest('.dataTables_length, .pagination, .select2-container')) { return false; }
        if (sel.classList.contains('select2-hidden-accessible')) { return false; }
        return true;
    }

    function norm(s) { return String(s || '').toLowerCase(); }

    function build(sel) {
        var w = { sel: sel, items: [], shown: [], act: -1, q: '' };
        var cs = getComputedStyle(sel);
        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        w.baseDisplay = (sel.classList.contains('w-auto') || cs.display === 'inline-block') ? 'inline-block' : 'block';
        wrap.style.display = w.baseDisplay;
        if (cs.flexGrow !== '0' || cs.flexShrink !== '1' || cs.flexBasis !== 'auto') { wrap.style.flex = cs.flex; }
        ['width', 'minWidth', 'maxWidth', 'margin'].forEach(function (p) { if (sel.style[p]) { wrap.style[p] = sel.style[p]; } });

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = (sel.className + ' ss-btn').replace(/\bss-native\b/g, '').trim();
        btn.setAttribute('aria-haspopup', 'listbox');
        if (sel.title) { btn.title = sel.title; }
        if (!/\b(form-select|form-control)\b/.test(sel.className)) {
            ['fontFamily', 'fontSize', 'lineHeight', 'color', 'padding', 'border', 'borderRadius', 'height', 'backgroundColor'].forEach(function (p) { btn.style[p] = cs[p]; });
            btn.style.paddingRight = '2rem';
        }

        var panel = document.createElement('div');
        panel.className = 'ss-panel';
        panel.innerHTML = '<input type="text" class="ss-search" autocomplete="off" placeholder="সার্চ করুন…"><ul class="ss-list" role="listbox"></ul>';
        w.wrap = wrap; w.btn = btn; w.panel = panel;
        w.search = panel.querySelector('.ss-search');
        w.list = panel.querySelector('.ss-list');

        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(btn);
        wrap.appendChild(sel);
        sel.classList.add('ss-native');
        sel.tabIndex = -1;
        (sel.closest('.modal, .offcanvas') || document.body).appendChild(panel);
        w.panelHost = panel.parentNode;

        // programmatic sel.value = ... / selectedIndex = ... হলেও লেবেল আপডেট হবে
        Object.defineProperty(sel, 'value', { configurable: true, get: function () { return descValue.get.call(sel); }, set: function (v) { descValue.set.call(sel, v); refresh(w); } });
        Object.defineProperty(sel, 'selectedIndex', { configurable: true, get: function () { return descIndex.get.call(sel); }, set: function (v) { descIndex.set.call(sel, v); refresh(w); } });

        w.onChange = function () { refresh(w); };
        sel.addEventListener('change', w.onChange);
        w.attrObs = new MutationObserver(function () { refresh(w); });
        w.attrObs.observe(sel, { attributes: true, attributeFilter: ['disabled', 'style', 'hidden'] });

        btn.addEventListener('click', function () { if (openW === w) { close(); } else { open(w); } });
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(w); }
            else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); open(w, e.key); }
        });
        w.search.addEventListener('input', function () { w.q = w.search.value; renderList(w, true); });
        w.search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(w, 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(w, -1); }
            else if (e.key === 'Enter') { e.preventDefault(); if (w.act >= 0 && w.shown[w.act]) { choose(w, w.shown[w.act]); } }
            else if (e.key === 'Escape') { e.preventDefault(); close(true); }
            else if (e.key === 'Tab') { close(); }
        });
        w.list.addEventListener('mousedown', function (e) {
            var li = e.target.closest('.ss-opt');
            if (!li) { return; }
            e.preventDefault();
            var it = w.shown[+li.dataset.i];
            if (it) { choose(w, it); }
        });
        w.list.addEventListener('mousemove', function (e) {
            var li = e.target.closest('.ss-opt');
            if (li && +li.dataset.i !== w.act) { setAct(w, +li.dataset.i, false); }
        });
        MAP.set(sel, w);
        refresh(w);
    }

    function destroy(sel) {
        var w = MAP.get(sel);
        if (!w) { return; }
        if (openW === w) { close(); }
        sel.removeEventListener('change', w.onChange);
        w.attrObs.disconnect();
        delete sel.value; delete sel.selectedIndex;
        sel.classList.remove('ss-native');
        sel.removeAttribute('tabindex');
        if (w.wrap.parentNode) { w.wrap.parentNode.insertBefore(sel, w.wrap); w.wrap.parentNode.removeChild(w.wrap); }
        if (w.panel.parentNode) { w.panel.parentNode.removeChild(w.panel); }
        MAP.delete(sel);
    }

    function refresh(w) {
        var sel = w.sel, o = sel.options[descIndex.get.call(sel)];
        var txt = o ? o.textContent.replace(/\s+/g, ' ').trim() : '';
        w.btn.textContent = txt || ' ';
        w.btn.classList.toggle('ss-ph', !o || descValue.get.call(sel) === '');
        w.btn.disabled = sel.disabled;
        w.wrap.style.display = (sel.hidden || sel.style.display === 'none') ? 'none' : w.baseDisplay;
        if (openW === w) { renderList(w, false); }
    }

    function collect(w) {
        var items = [], i = 0;
        Array.prototype.forEach.call(w.sel.children, function (ch) {
            if (ch.tagName === 'OPTGROUP') {
                items.push({ group: ch.label });
                Array.prototype.forEach.call(ch.children, function (o) { items.push(mk(o, ch.disabled)); });
            } else if (ch.tagName === 'OPTION') { items.push(mk(ch, false)); }
        });
        function mk(o, gd) { return { opt: o, text: o.textContent.replace(/\s+/g, ' ').trim(), low: norm(o.textContent), dis: o.disabled || gd, blank: o.value === '', off: o.hidden || o.style.display === 'none' }; }
        w.items = items;
    }

    function renderList(w, resetAct) {
        if (!w.items.length || !w.fresh) { collect(w); w.fresh = true; }
        var toks = norm(w.q).split(/\s+/).filter(Boolean), html = '', shown = [], extra = 0, pendingGroup = null;
        var curIdx = descIndex.get.call(w.sel);
        w.items.forEach(function (it) {
            if (it.group !== undefined) { pendingGroup = it.group; return; }
            if (it.off) { return; }
            if (toks.length && !toks.every(function (t) { return it.low.indexOf(t) !== -1; })) { return; }
            if (shown.length >= MAX_SHOW) { extra++; return; }
            if (pendingGroup !== null) { html += '<li class="ss-grp">' + esc(pendingGroup) + '</li>'; pendingGroup = null; }
            var cls = 'ss-opt' + (it.opt.index === curIdx ? ' ss-sel' : '') + (it.dis ? ' ss-dis' : '') + (it.blank ? ' ss-blank' : '');
            html += '<li class="' + cls + '" role="option" data-i="' + shown.length + '">' + (esc(it.text) || '&nbsp;') + '</li>';
            shown.push(it);
        });
        if (!shown.length) { html = '<li class="ss-empty">কিছু পাওয়া যায়নি</li>'; }
        if (extra) { html += '<li class="ss-more">আরও ' + extra + 'টি আছে — টাইপ করে সার্চ করুন</li>'; }
        w.list.innerHTML = html;
        w.shown = shown;
        if (resetAct || w.act >= shown.length || w.act < 0) {
            var si = -1;
            if (!toks.length) { shown.forEach(function (it, k) { if (it.opt.index === curIdx) { si = k; } }); }
            if (si < 0) { si = shown.findIndex(function (it) { return !it.dis && !(it.blank && toks.length); }); }
            setAct(w, si, true);
        }
    }

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function setAct(w, i, scroll) {
        var old = w.list.querySelector('.ss-act');
        if (old) { old.classList.remove('ss-act'); }
        w.act = i;
        var li = w.list.querySelector('.ss-opt[data-i="' + i + '"]');
        if (li) { li.classList.add('ss-act'); if (scroll) { li.scrollIntoView({ block: 'nearest' }); } }
    }

    function move(w, d) {
        var n = w.shown.length; if (!n) { return; }
        var i = w.act;
        for (var k = 0; k < n; k++) { i = (i + d + n) % n; if (!w.shown[i].dis) { break; } }
        setAct(w, i, true);
    }

    function choose(w, it) {
        if (it.dis) { return; }
        descIndex.set.call(w.sel, it.opt.index);
        close(true);
        refresh(w);
        w.sel.dispatchEvent(new Event('input', { bubbles: true }));
        w.sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function place(w) {
        var r = w.btn.getBoundingClientRect(), p = w.panel;
        p.style.minWidth = Math.max(r.width, 180) + 'px';
        p.style.left = Math.min(r.left, Math.max(0, window.innerWidth - p.offsetWidth - 8)) + 'px';
        var h = p.offsetHeight, below = window.innerHeight - r.bottom, above = r.top;
        p.style.top = (below >= h + 8 || below >= above) ? (r.bottom + 2) + 'px' : Math.max(4, r.top - h - 2) + 'px';
    }

    function open(w, seed) {
        if (w.sel.disabled) { return; }
        if (openW && openW !== w) { close(); }
        openW = w; w.fresh = false; w.q = seed || '';
        w.search.value = w.q;
        w.panel.classList.add('ss-open');
        renderList(w, true);
        place(w);
        w.btn.setAttribute('aria-expanded', 'true');
        setTimeout(function () { w.search.focus(); if (seed) { w.search.setSelectionRange(seed.length, seed.length); } }, 0);
    }

    function close(refocus) {
        if (!openW) { return; }
        var w = openW; openW = null;
        w.panel.classList.remove('ss-open');
        w.btn.setAttribute('aria-expanded', 'false');
        if (refocus) { w.btn.focus(); }
    }

    document.addEventListener('mousedown', function (e) {
        if (openW && !openW.panel.contains(e.target) && !openW.btn.contains(e.target)) { close(); }
    }, true);
    window.addEventListener('resize', function () { if (openW) { place(openW); } });
    window.addEventListener('scroll', function (e) {
        if (!openW || openW.panel.contains(e.target)) { return; }
        place(openW);
    }, true);
    // <label for="..."> চাপলে আমাদের বাটনে ফোকাস যাবে
    document.addEventListener('click', function (e) {
        var lb = e.target.closest && e.target.closest('label[for]');
        if (!lb) { return; }
        var el = document.getElementById(lb.getAttribute('for'));
        var w = el && MAP.get(el);
        if (w) { e.preventDefault(); w.btn.focus(); }
    });
    document.addEventListener('reset', function (e) {
        setTimeout(function () {
            e.target.querySelectorAll('select').forEach(function (s) { var w = MAP.get(s); if (w) { refresh(w); } });
        }, 0);
    }, true);

    // কোন select-এ কী করতে হবে — পরের ফ্রেমে একবারে যাচাই (AJAX দিয়ে অপশন ভরলেও)
    var queue = new Set(), scheduled = false;
    function schedule(el) {
        queue.add(el);
        if (!scheduled) { scheduled = true; requestAnimationFrame(flush); }
    }
    function flush() {
        scheduled = false;
        var arr = Array.from(queue); queue.clear();
        arr.forEach(check);
    }
    function check(sel) {
        var w = MAP.get(sel);
        if (!sel.isConnected) { if (w) { destroy(sel); } return; }
        var n = sel.options.length;
        if (w) {
            if (!eligible(sel) || n < MIN_KEEP) { destroy(sel); }
            else { w.fresh = false; refresh(w); }
        } else if (eligible(sel) && n >= MIN_BUILD) {
            build(sel);
        }
    }
    function scan(root) {
        if (root.nodeType !== 1) { return; }
        if (root.tagName === 'SELECT') { schedule(root); }
        else if (root.querySelectorAll) { root.querySelectorAll('select').forEach(schedule); }
    }
    new MutationObserver(function (ms) {
        ms.forEach(function (m) {
            var t = m.target, s = t.nodeType === 1 && t.closest ? t.closest('select') : null;
            if (s) { schedule(s); }
            m.addedNodes.forEach(scan);
            m.removedNodes.forEach(function (n) {
                if (n.nodeType !== 1 || (n.classList && (n.classList.contains('ss-panel') || n.classList.contains('ss-wrap')))) { return; }
                if (n.tagName === 'SELECT') { schedule(n); } else if (n.querySelectorAll) { n.querySelectorAll('select').forEach(schedule); }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    function init() { document.querySelectorAll('select').forEach(schedule); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>
HTML;
    }
}
