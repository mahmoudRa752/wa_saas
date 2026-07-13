<?php if (isset($_SESSION['company_id'])): ?>
    </div></div></div>
    <?php
    // Lazy cron trigger for background broadcasts (runs asynchronously on Windows)
    $chkQuery = $conn->query("SELECT id FROM broadcast_campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW() LIMIT 1");
    if ($chkQuery && $chkQuery->num_rows > 0) {
        pclose(popen("start /B php " . escapeshellarg(__DIR__ . '/../cron/process_broadcasts.php'), "r"));
    }
    ?>
<?php else: ?>
    </div></div><?php endif; ?>

<footer class="app-footer text-center py-3 text-muted border-top bg-white mt-auto" style="font-size: 0.875rem;">
    &copy; <?php echo date("Y"); ?> WA Training Manager &mdash; M.R Platform
</footer>

<!-- Toast Container for Notifications -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 99999;" id="globalToastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isset($_SESSION['company_id'])): ?>
<script>
let lastNotificationTime = '<?php echo date("Y-m-d H:i:s"); ?>';

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function playBeepSound() {
    if (localStorage.getItem('muteAudioNotifications') === '1') {
        return;
    }
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        
        const osc1 = audioCtx.createOscillator();
        const gain1 = audioCtx.createGain();
        osc1.connect(gain1);
        gain1.connect(audioCtx.destination);
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
        gain1.gain.setValueAtTime(0.08, audioCtx.currentTime);
        gain1.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.15);
        osc1.start();
        osc1.stop(audioCtx.currentTime + 0.15);
        
        const osc2 = audioCtx.createOscillator();
        const gain2 = audioCtx.createGain();
        osc2.connect(gain2);
        gain2.connect(audioCtx.destination);
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.1); // A5
        gain2.gain.setValueAtTime(0.12, audioCtx.currentTime + 0.1);
        gain2.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
        osc2.start(audioCtx.currentTime + 0.1);
        osc2.stop(audioCtx.currentTime + 0.3);
    } catch (e) {
        console.warn("AudioContext block: ", e);
    }
}

function showNotificationToast(msg) {
    const container = document.getElementById('globalToastContainer');
    if (!container) return;
    
    const toastId = 'toast-' + msg.id;
    const bodySnippet = msg.body.length > 60 ? msg.body.substring(0, 60) + '...' : msg.body;
    const toastHtml = `
        <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header" style="background:#0f172a; color:#fff;">
                <i class="bi bi-whatsapp me-2 text-success"></i>
                <strong class="me-auto">New WhatsApp Message</strong>
                <small class="text-white-50">Just now</small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <div class="mb-1 text-secondary" style="font-size:11px; font-weight:bold;">From: ${escapeHtml(msg.contact_number)}</div>
                <p class="mb-2" style="font-size:13.5px;color:#1e293b;">${escapeHtml(bodySnippet)}</p>
                <a href="/wa_saas/dashboard/chat.php?conv=${msg.conversation_id}" class="btn btn-primary btn-sm px-3" style="font-size:11px;background:#00a884;border:none;">Open Chat</a>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 6000 });
    bsToast.show();
}

function checkNotifications() {
    fetch('/wa_saas/api/get_new_incoming_messages.php?last_check_time=' + encodeURIComponent(lastNotificationTime))
        .then(r => r.json())
        .then(data => {
            if (data.server_time) {
                lastNotificationTime = data.server_time;
            }
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    const isChatPage = window.location.pathname.endsWith('chat.php');
                    const isActiveChat = (typeof CONV_ID !== 'undefined' && CONV_ID === msg.conversation_id);
                    
                    if (isChatPage && isActiveChat) {
                        if (document.hidden) {
                            playBeepSound();
                        }
                    } else {
                        playBeepSound();
                        showNotificationToast(msg);
                        
                        if (isChatPage) {
                            const convItem = document.querySelector(`.conv-item[data-number="${msg.contact_number}"]`);
                            if (convItem) {
                                let badge = convItem.querySelector('.unread-badge');
                                if (!badge) {
                                    const meta = convItem.querySelector('.conv-meta');
                                    if (meta) {
                                        meta.insertAdjacentHTML('afterbegin', '<span class="unread-badge">1</span>');
                                    }
                                } else {
                                    badge.innerText = parseInt(badge.innerText) + 1;
                                }
                                const preview = convItem.querySelector('.conv-preview');
                                if (preview) {
                                    preview.innerText = msg.body.substring(0, 30) + (msg.body.length > 30 ? '...' : '');
                                }
                            }
                        }
                    }
                });
            }
        }).catch(() => {});
}

setInterval(checkNotifications, 5000);
</script>
<?php endif; ?>
</body>
</html>