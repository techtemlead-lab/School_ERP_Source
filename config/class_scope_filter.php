<?php
/**
 * config/class_scope_filter.php
 * -----------------------------------------------------------
 * সাইটজুড়ে "Class সিলেক্ট করলে শুধু ওই Class-এর Shift ও Section আসবে" — একটা শেয়ার্ড স্ক্রিপ্ট।
 *
 * কীভাবে কাজ করে: পেজের যেকোনো ফর্মে Class + (Shift এবং/অথবা Section) সিলেক্ট থাকলে নিজে থেকে জোড়া চিনে নেয়।
 * নামের ধরন: class_id, shift_id, section_id অথবা f_class, f_shift, f_section, অথবা s_ / from_ / to_ / new_ উপসর্গওয়ালা ইত্যাদি (একই উপসর্গ
 * + একই ফর্ম = একটা জোড়া)। সম্পর্ক academic_sections টেবিল থেকে: Section-এর class_id + shift_id।
 *   - Class বদলালে → Shift তালিকা ছাঁটা হয় (ওই Class-এর Section-এ যে Shift আছে শুধু সেগুলো), Section-ও ছাঁটা হয়
 *   - Shift বদলালে → Section আরও ছাঁটা হয় (ওই Class + Shift-এর Section)
 *   - Class ফাঁকা ("Select Class"/"All") থাকলে কিছুই ছাঁটা হয় না
 * যেসব পেজ নিজের JS/AJAX দিয়ে Section ভরে, তাদের সাথে সংঘর্ষ এড়াতে MutationObserver দিয়ে তাদের পরে আবার ছাঁটা হয়।
 * কোনো ফর্মে বন্ধ করতে চাইলে ফর্মে data-no-class-scope অ্যাট্রিবিউট দিন।
 *
 * ব্যবহারঃ পেজের </body>-এর ঠিক আগে — <?php include __DIR__ . '/../config/class_scope_filter.php'; ?> (নিচে $pdo লাগবে)
 */
$__cs_rows = [];
try {
    foreach ($pdo->query("SELECT id, class_id, shift_id FROM academic_sections")->fetchAll(PDO::FETCH_NUM) as $r) {
        $__cs_rows[] = [(int) $r[0], (int) $r[1], (int) $r[2]];
    }
} catch (Throwable $e) {
    $__cs_rows = []; // টেবিল না থাকলে (নতুন ইনস্টলেশন) স্ক্রিপ্ট কিছুই করবে না
}
if (!$__cs_rows) { return; }
?>
<script>
(function () {
    // [section_id, class_id, shift_id]
    var MAP = <?php echo json_encode($__cs_rows); ?>;

    function isId(v) { return /^[0-9]+$/.test(String(v)) && Number(v) > 0; }

    // ---- ফর্ম অনুযায়ী জোড়া খোঁজা ----
    var forms = [];
    var groups = {};
    document.querySelectorAll('select[name]').forEach(function (el) {
        if (el.multiple) { return; }
        var m = el.name.match(/^(.*?)(class|shift|section)(?:_id)?$/);
        if (!m) { return; }
        var form = el.form;
        if (form && form.hasAttribute('data-no-class-scope')) { return; }
        var fi = form ? forms.indexOf(form) : -2;
        if (form && fi === -1) { forms.push(form); fi = forms.length - 1; }
        var key = fi + '|' + m[1];
        (groups[key] = groups[key] || {})[m[2]] = el;
    });

    Object.keys(groups).forEach(function (k) {
        var g = groups[k];
        if (!g['class'] || !(g.shift || g.section)) { return; }
        wire(g);
    });

    function wire(g) {
        var busy = false;
        var stash = {}; // select => { order: [value], opts: {value: <option>} } — একবার দেখা সব option মনে রাখা
        function remember(sel) {
            var st = stash[sel.name + '|' + (sel.id || '')] = stash[sel.name + '|' + (sel.id || '')] || { order: [], opts: {} };
            Array.prototype.forEach.call(sel.options, function (o) {
                if (!(o.value in st.opts)) { st.order.push(o.value); }
                st.opts[o.value] = o; // পরে AJAX-এ নতুন option এলে সেটাই সর্বশেষ ধরা হয়
            });
            return st;
        }
        function rebuild(sel, allowed) {
            if (!sel) { return; }
            var st = remember(sel);
            var cur = sel.value;
            var keep = [];
            st.order.forEach(function (v) {
                var o = st.opts[v];
                if (!isId(v) || allowed === null || allowed[Number(v)]) { keep.push(o); }
            });
            // অপরিবর্তিত হলে DOM ছোঁয়া হবে না (অকারণ ফ্লিকার/ইভেন্ট এড়াতে)
            var same = keep.length === sel.options.length && keep.every(function (o, i) { return sel.options[i] === o; });
            if (!same) {
                var wasDisabled = sel.disabled;
                sel.innerHTML = '';
                keep.forEach(function (o) { sel.appendChild(o); });
                sel.disabled = wasDisabled;
            }
            var stillThere = keep.some(function (o) { return o.value === cur; });
            if (stillThere) { sel.value = cur; }
            else if (sel.options.length) { sel.selectedIndex = 0; } // বাছাই করা মান আর প্রযোজ্য নয় — প্লেসহোল্ডারে ফিরে যাওয়া
        }
        function apply() {
            if (busy) { return; }
            busy = true;
            try {
                var cid = isId(g['class'].value) ? Number(g['class'].value) : 0;
                // ১) Shift — ওই Class-এর Section-এ যে Shift আছে শুধু সেগুলো (কোনো Section-ই না থাকলে সব দেখায়)
                if (g.shift) {
                    var allowShift = null;
                    if (cid) {
                        var s = {}, any = false;
                        MAP.forEach(function (r) { if (r[1] === cid) { any = true; s[r[2]] = true; } });
                        if (any) { allowShift = s; }
                    }
                    rebuild(g.shift, allowShift);
                }
                // ২) Section — Class (এবং Shift সিলেক্ট থাকলে Shift) মিলিয়ে
                if (g.section) {
                    var sid = g.shift && isId(g.shift.value) ? Number(g.shift.value) : 0;
                    var allowSec = null;
                    if (cid || sid) {
                        var a = {};
                        MAP.forEach(function (r) {
                            if ((!cid || r[1] === cid) && (!sid || r[2] === sid)) { a[r[0]] = true; }
                        });
                        allowSec = a;
                    }
                    rebuild(g.section, allowSec);
                }
            } finally { busy = false; }
        }

        g['class'].addEventListener('change', apply);
        if (g.shift) { g.shift.addEventListener('change', apply); }

        // অন্য কোনো স্ক্রিপ্ট (যেমন AJAX) Shift/Section-এর option বদলালে আবার ছাঁটা
        var pending = false;
        var mo = new MutationObserver(function () {
            if (busy || pending) { return; }
            pending = true;
            setTimeout(function () { pending = false; apply(); }, 0);
        });
        [g.shift, g.section].forEach(function (sel) { if (sel) { mo.observe(sel, { childList: true }); } });

        apply(); // পেজ খোলার সময় (সার্ভার-সিলেক্টেড ভ্যালু রেখে)
    }
})();
</script>
