<?php
/**
 * admin/partials/live_class_embed.php
 * -----------------------------------------------------------
 * শেয়ার্ড লাইভ-ক্লাস ভিডিও এমবেড — Host (admin/live_class_host.php) আর
 * Student (student/live_class_join.php) দুই জায়গা থেকেই require হয়।
 * Require করার আগে এই ভ্যারিয়েবলগুলো সেট করা থাকতে হবে:
 *   $lc_class        — elearning_live_classes টেবিলের row (array), room_id সহ
 *   $lc_token_result — lc_generate_token() এর রিটার্ন ['ok','token','user_name']
 *   $lc_is_host      — true হলে হোস্ট-অনলি কন্ট্রোল (রিমোট মিউট) দেখাবে
 *   $lc_status_poll_url — (ঐচ্ছিক, শুধু স্টুডেন্ট সাইড) হোস্ট ক্লাস "শেষ" করলে
 *                          অটো লিভ+রিডাইরেক্টের জন্য স্ট্যাটাস পোল করার URL
 *
 * ⚠️ প্রথমবার লাইভে দেওয়ার আগে 100ms-এর অফিসিয়াল JS SDK ডকুমেন্টেশনের সাথে
 * মিলিয়ে টেস্ট করে নেওয়া ভালো (SDK ভার্সন সময়ে সময়ে বদলাতে পারে):
 * https://www.100ms.live/docs/javascript/v2/quickstart/quickstart
 *
 * ⚠️ রিমোট মিউট/আনমিউট কাজ করার জন্য 100ms ড্যাশবোর্ডে "host" রোল টেমপ্লেটে
 * Peer Management → mute/unmute পারমিশন চালু থাকতে হবে।
 */
$lc_token = htmlspecialchars($lc_token_result['token'] ?? '', ENT_QUOTES, 'UTF-8');
$lc_user_name = htmlspecialchars($lc_token_result['user_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$lc_is_host = $lc_is_host ?? false;
$lc_status_poll_url = $lc_status_poll_url ?? null;
?>
<div id="lcStatus" style="color:#cbd5e1; font-size:13px; padding:10px 4px;">লোড হচ্ছে…</div>
<div id="lcRoot" style="min-height:60vh; background:#0b0f1a; border-radius:8px;"></div>
<div id="lcControls" style="display:none; gap:8px; padding:10px 4px; flex-wrap:wrap;">
    <button type="button" id="lcBtnMic" class="btn btn-sm btn-outline-light">🎙️ মিউট করুন</button>
    <button type="button" id="lcBtnCam" class="btn btn-sm btn-outline-light">📷 ক্যামেরা বন্ধ করুন</button>
    <button type="button" id="lcBtnLeave" class="btn btn-sm btn-danger">🚪 লিভ করুন</button>
</div>
<script type="module">
(async function () {
    var status = document.getElementById('lcStatus');
    var root = document.getElementById('lcRoot');
    var controls = document.getElementById('lcControls');
    var btnMic = document.getElementById('lcBtnMic');
    var btnCam = document.getElementById('lcBtnCam');
    var btnLeave = document.getElementById('lcBtnLeave');
    var isHost = <?php echo $lc_is_host ? 'true' : 'false'; ?>;

    try {
        var mod = await import("https://esm.sh/@100mslive/hms-video-store@0.13.2?bundle");
        var hmsManager = new mod.HMSReactiveStore();
        hmsManager.triggerOnSubscribe();
        var hmsStore = hmsManager.getStore();
        var actions = hmsManager.getActions();
        await actions.join({ authToken: "<?php echo $lc_token; ?>", userName: "<?php echo $lc_user_name; ?>" });
        status.textContent = "কানেক্টেড ✅";
        controls.style.display = 'flex';

        // হোস্ট মিউট করলে, হোস্ট আনমিউট না করা পর্যন্ত স্টুডেন্ট নিজে আনমিউট করতে পারবে না
        var micLockedByHost = false;
        function applyMicLock(locked) {
            micLockedByHost = locked;
            btnMic.disabled = locked;
            btnMic.style.opacity = locked ? '0.5' : '1';
            if (locked) {
                btnMic.textContent = '🔒 হোস্ট মিউট করেছেন';
                status.textContent = '🔒 হোস্ট আপনাকে মিউট করেছেন।';
            } else {
                var enabled = hmsStore.getState(mod.selectIsLocalAudioEnabled);
                btnMic.textContent = enabled ? '🎙️ মিউট করুন' : '🔇 আনমিউট করুন';
            }
        }

        btnMic.onclick = function () {
            if (micLockedByHost) { status.textContent = '🔒 হোস্ট আপনাকে মিউট করে রেখেছেন।'; return; }
            var enabled = hmsStore.getState(mod.selectIsLocalAudioEnabled);
            actions.setLocalAudioEnabled(!enabled);
        };
        var camOn = true;
        btnCam.onclick = function () {
            camOn = !camOn;
            actions.setLocalVideoEnabled(camOn);
            btnCam.textContent = camOn ? '📷 ক্যামেরা বন্ধ করুন' : '📷 ক্যামেরা চালু করুন';
        };
        function leaveCall() {
            try { actions.leave(); } catch (e) {}
            status.textContent = 'আপনি ক্লাস থেকে বেরিয়ে গেছেন।';
            controls.style.display = 'none';
            root.innerHTML = '';
        }
        btnLeave.onclick = leaveCall;
        window.__lcLeaveCall = leaveCall;
        window.addEventListener('beforeunload', function () { try { actions.leave(); } catch (e) {} });

        hmsStore.subscribe(function () {
            if (micLockedByHost) return;
            var enabled = hmsStore.getState(mod.selectIsLocalAudioEnabled);
            btnMic.textContent = enabled ? '🎙️ মিউট করুন' : '🔇 আনমিউট করুন';
        }, mod.selectIsLocalAudioEnabled);

        function toggleRemoteMute(peer, enable) {
            if (!peer.audioTrack) return;
            actions.setRemoteTrackEnabled(peer.audioTrack, enable).then(function () {
                actions.sendDirectMessage(JSON.stringify({ type: 'mic_lock', locked: !enable }), peer.id);
            }).catch(function (e) {
                alert('মাইক্রোফোন কন্ট্রোল করা যায়নি: ' + (e && e.message ? e.message : e));
            });
        }

        var lastMsgCount = 0;
        hmsStore.subscribe(function () {
            var msgs = hmsStore.getState(mod.selectHMSMessages) || [];
            for (var i = lastMsgCount; i < msgs.length; i++) {
                try {
                    var payload = JSON.parse(msgs[i].message);
                    if (payload && payload.type === 'mic_lock' && !isHost) { applyMicLock(!!payload.locked); }
                } catch (e) { /* অন্য মেসেজ হলে উপেক্ষা */ }
            }
            lastMsgCount = msgs.length;
        }, mod.selectHMSMessages);

        hmsStore.subscribe(function () {
            var peers = (hmsStore.getState(mod.selectPeers) || []).slice();
            peers.sort(function (a, b) {
                var aHost = a.roleName === 'host' ? 0 : 1;
                var bHost = b.roleName === 'host' ? 0 : 1;
                return aHost - bHost;
            });
            root.innerHTML = '';
            peers.forEach(function (peer) {
                var box = document.createElement('div');
                box.style.cssText = 'position:relative;display:inline-block;width:260px;height:190px;margin:6px;background:#111;border-radius:8px;overflow:hidden;vertical-align:top;';

                var video = document.createElement('video');
                video.autoplay = true; video.playsInline = true; video.muted = peer.isLocal;
                video.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                box.appendChild(video);

                var isPeerHost = peer.roleName === 'host';
                var label = document.createElement('div');
                label.style.cssText = 'position:absolute;left:0;right:0;bottom:0;padding:5px 8px;background:linear-gradient(transparent,rgba(0,0,0,.8));color:#fff;font-size:12.5px;display:flex;justify-content:space-between;align-items:center;gap:6px;';

                var nameSpan = document.createElement('span');
                nameSpan.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                nameSpan.textContent = isPeerHost ? ('👨‍🏫 ' + peer.name) : peer.name + (peer.isLocal ? ' (আপনি)' : '');
                label.appendChild(nameSpan);

                if (isHost && !peer.isLocal) {
                    var audioEnabled = hmsStore.getState(mod.selectIsPeerAudioEnabled(peer.id));
                    var muteBtn = document.createElement('button');
                    muteBtn.type = 'button';
                    muteBtn.textContent = audioEnabled ? '🎙️' : '🔇';
                    muteBtn.style.cssText = 'flex:none;border:0;border-radius:4px;padding:2px 7px;cursor:pointer;background:#2563eb;color:#fff;font-size:13px;';
                    muteBtn.onclick = (function (peerRef, enabledNow) {
                        return function () { toggleRemoteMute(peerRef, !enabledNow); };
                    })(peer, audioEnabled);
                    label.appendChild(muteBtn);
                }

                box.appendChild(label);
                root.appendChild(box);
                if (peer.videoTrack) actions.attachVideo(peer.videoTrack, video);
            });
        }, mod.selectPeers);
    } catch (e) {
        status.textContent = "⚠️ কানেক্ট করা যায়নি: " + (e && e.message ? e.message : e);
    }
})();
</script>

<?php if ($lc_status_poll_url): ?>
<script>
(function () {
    var statusUrl = <?php echo json_encode($lc_status_poll_url); ?>;
    var pollTimer = setInterval(function () {
        fetch(statusUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status !== 'Live') {
                    clearInterval(pollTimer);
                    if (typeof window.__lcLeaveCall === 'function') { try { window.__lcLeaveCall(); } catch (e) {} }
                    var status = document.getElementById('lcStatus');
                    var controls = document.getElementById('lcControls');
                    var root = document.getElementById('lcRoot');
                    if (controls) controls.style.display = 'none';
                    if (root) root.innerHTML = '';
                    if (status) status.textContent = '⏹️ হোস্ট ক্লাসটি শেষ করে দিয়েছেন। কয়েক সেকেন্ডের মধ্যে ফিরিয়ে নেওয়া হবে...';
                    setTimeout(function () { window.location.href = 'index.php?page=live_class'; }, 2500);
                }
            })
            .catch(function () { /* সাময়িক নেটওয়ার্ক সমস্যা */ });
    }, 6000);
    window.addEventListener('beforeunload', function () { clearInterval(pollTimer); });
})();
</script>
<?php endif; ?>
