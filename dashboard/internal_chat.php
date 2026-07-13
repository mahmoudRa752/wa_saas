<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int) $_SESSION['company_id'];
$myUserId  = (int) $_SESSION['user_id'];

// Get list of colleagues in the company
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.role, u.email,
           (SELECT COUNT(*) FROM internal_messages im 
            WHERE im.company_id = ? AND im.sender_id = u.id AND im.receiver_id = ? AND im.is_read = 0) AS unread_count,
           (SELECT im2.message FROM internal_messages im2
            WHERE im2.company_id = ? AND ((im2.sender_id = u.id AND im2.receiver_id = ?) OR (im2.sender_id = ? AND im2.receiver_id = u.id))
            ORDER BY im2.id DESC LIMIT 1) AS last_message
    FROM users u
    WHERE u.company_id = ? AND u.id != ?
    ORDER BY last_message IS NULL, u.name ASC
");
$stmt->bind_param("iiiiiii", $companyId, $myUserId, $companyId, $myUserId, $myUserId, $companyId, $myUserId);
$stmt->execute();
$colleagues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeColleagueId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
$activeColleague = null;
$initMessages = [];

if ($activeColleagueId > 0) {
    // Verify colleague is in same company
    $stmt = $conn->prepare("SELECT id, name, role, email FROM users WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("ii", $activeColleagueId, $companyId);
    $stmt->execute();
    $activeColleague = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($activeColleague) {
        // Fetch last 50 messages
        $stmt = $conn->prepare("
            SELECT im.id, im.sender_id, im.receiver_id, im.message, im.created_at, u.name AS sender_name
            FROM internal_messages im
            JOIN users u ON im.sender_id = u.id
            WHERE im.company_id = ?
              AND ((im.sender_id = ? AND im.receiver_id = ?) OR (im.sender_id = ? AND im.receiver_id = ?))
            ORDER BY im.id DESC LIMIT 50
        ");
        $stmt->bind_param("iiiiii", $companyId, $myUserId, $activeColleagueId, $activeColleagueId, $myUserId);
        $stmt->execute();
        $initMessages = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();

        // Mark received messages as read
        $stmt = $conn->prepare("
            UPDATE internal_messages 
            SET is_read = 1 
            WHERE company_id = ? AND sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->bind_param("iii", $companyId, $activeColleagueId, $myUserId);
        $stmt->execute();
        $stmt->close();
    } else {
        $activeColleagueId = 0;
    }
}

$lastMsgId = 0;
if (!empty($initMessages)) {
    $ids = array_column($initMessages, 'id');
    $lastMsgId = max($ids);
}

include("../layouts/header.php");
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.chat-wrapper { display: flex; height: calc(100vh - 120px); background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; position: relative; }
.conv-list { width: 340px; min-width: 340px; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; }
.conv-list-header { padding: 12px 16px; font-weight: 700; font-size: 16px; border-bottom: 1px solid #e2e8f0; color: #111b21; display: flex; justify-content: space-between; align-items: center; background: #f0f2f5; }
.search-chat-bar { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; background: #fff; display: flex; gap: 8px; align-items: center; }
.search-input-wrapper { flex: 1; background: #f0f2f5; border-radius: 8px; padding: 4px 10px; display: flex; align-items: center; gap: 8px; }
.search-input-wrapper input { border: none; background: transparent; width: 100%; outline: none; font-size: 13px; color: #111b21; }
.conv-list-body { flex: 1; overflow-y: auto; background: #fff; }
.conv-item { display: flex; align-items: center; gap: 12px; padding: 13px 16px; cursor: pointer; border-bottom: 1px solid #f8f9fa; text-decoration: none; transition: background .15s; position: relative; }
.conv-item:hover { background: #f5f6f6; }
.conv-item.active { background: #eaeaea; }
.conv-avatar { width: 44px; height: 44px; border-radius: 50%; background: #6366f1; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 14px; flex-shrink: 0; }
.conv-avatar.role-admin { background: #0f766e; }
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-size: 14px; font-weight: 600; color: #111b21; display: flex; align-items: center; justify-content: space-between; }
.conv-preview { font-size: 13px; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.conv-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.unread-badge { background: #3b82f6; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 20px; min-width: 18px; text-align: center; }
.role-lbl { font-size: 10px; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; font-weight: bold; }
.role-lbl.admin { background: #ccfbf1; color: #115e59; }

.chat-window { flex: 1; display: flex; flex-direction: column; min-width: 0; background-color: #f8fafc; }
.chat-header { padding: 10px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 12px; background: #f0f2f5; z-index: 10; }
.chat-header-avatar { width: 40px; height: 40px; border-radius: 50%; background: #6366f1; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; }
.chat-header-avatar.role-admin { background: #0f766e; }
.chat-header-info .name { font-size: 15px; font-weight: 600; color: #111b21; }
.chat-header-info .email { font-size: 12px; color: #64748b; }
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; background: #f1f5f9; }
.msg-row { display: flex; width: 100%; }
.msg-row.in { justify-content: flex-start; }
.msg-row.out { justify-content: flex-end; }
.msg-bubble { max-width: 60%; padding: 8px 12px; border-radius: 8px; font-size: 14px; line-height: 1.4; word-break: break-word; display: flex; flex-direction: column; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.msg-row.in .msg-bubble { background: #fff; border-top-left-radius: 0; color: #1e293b; border: 1px solid #e2e8f0; }
.msg-row.out .msg-bubble { background: #dbeafe; border-top-right-radius: 0; color: #1e3a8a; border: 1px solid #bfdbfe; }
.msg-time { font-size: 10px; color: #64748b; margin-top: 4px; text-align: right; }
.chat-input-area { padding: 10px 16px; background: #f0f2f5; display: flex; gap: 12px; align-items: center; z-index: 10; position: relative; border-top: 1px solid #e2e8f0; }
.input-action-btn { background: transparent; border: none; color: #54656f; font-size: 22px; cursor: pointer; }
.chat-input-container { flex: 1; background: #fff; border-radius: 8px; padding: 6px 12px; display: flex; align-items: center; border: 1px solid #cbd5e1; }
.chat-input-container textarea { flex: 1; border: none; resize: none; max-height: 100px; outline: none; font-size: 15px; font-family: inherit; line-height: 1.3; padding: 4px 0; }
.send-btn-wa { background: transparent; border: none; color: #2563eb; font-size: 22px; cursor: pointer; }
.send-btn-wa:disabled { color: #94a3b8; }
.chat-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #64748b; gap: 12px; background: #f8fafc; }
.chat-empty-state i { font-size: 80px; color: #cbd5e1; }
.native-emoji-picker { position: absolute; bottom: 65px; left: 16px; width: 280px; height: 220px; background: #fff; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: none; flex-direction: column; z-index: 999; }
.emoji-picker-header { padding: 8px; background: #f8fafc; font-size: 12px; font-weight: bold; color: #64748b; border-bottom: 1px solid #e2e8f0; border-top-left-radius: 10px; border-top-right-radius: 10px; }
.emoji-picker-grid { flex: 1; overflow-y: auto; padding: 8px; display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; font-size: 22px; text-align: center; }
.emoji-item { cursor: pointer; user-select: none; }
</style>
<div class="chat-wrapper">
    <div class="conv-list">
        <div class="conv-list-header">
            <span>Colleagues Directory</span>
            <i class="bi bi-people-fill text-secondary"></i>
        </div>
        <div class="search-chat-bar">
            <div class="search-input-wrapper">
                <i class="bi bi-search text-secondary"></i>
                <input type="text" id="userSearch" placeholder="Search colleagues..." onkeyup="filterColleagues()">
            </div>
        </div>
        <div class="conv-list-body" id="colleaguesListBody">
            <?php if (empty($colleagues)): ?>
                <div style="padding:40px; text-align:center; color:#64748b;">No colleagues found.</div>
            <?php else: ?>
                <?php foreach ($colleagues as $user): ?>
                    <?php
                    $isActive = ($user['id'] == $activeColleagueId);
                    $preview = $user['last_message'] ? mb_substr($user['last_message'], 0, 30) . '...' : 'Start chatting internally';
                    $isAdminRole = ($user['role'] === 'admin');
                    ?>
                    <a href="?user=<?php echo $user['id']; ?>" class="conv-item <?php echo $isActive ? 'active' : ''; ?>" data-name="<?php echo htmlspecialchars($user['name']); ?>">
                        <div class="conv-avatar <?php echo $isAdminRole ? 'role-admin' : ''; ?>">
                            <?php echo strtoupper(substr($user['name'], 0, 2)); ?>
                        </div>
                        <div class="conv-info">
                            <div class="conv-name">
                                <span><?php echo htmlspecialchars($user['name']); ?></span>
                                <span class="role-lbl <?php echo $user['role']; ?>"><?php echo $user['role']; ?></span>
                            </div>
                            <div class="conv-preview"><?php echo htmlspecialchars($preview); ?></div>
                        </div>
                        <div class="conv-meta">
                            <?php if ($user['unread_count'] > 0): ?>
                                <span class="unread-badge"><?php echo $user['unread_count']; ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($activeColleague): ?>
        <div class="chat-window">
            <div class="chat-header">
                <div class="chat-header-avatar <?php echo $activeColleague['role'] === 'admin' ? 'role-admin' : ''; ?>">
                    <?php echo strtoupper(substr($activeColleague['name'], 0, 2)); ?>
                </div>
                <div class="chat-header-info">
                    <div class="name"><?php echo htmlspecialchars($activeColleague['name']); ?></div>
                    <div class="email"><?php echo htmlspecialchars($activeColleague['email']); ?></div>
                </div>
            </div>
            <div class="chat-messages" id="chatMessages">
                <?php foreach ($initMessages as $msg): ?>
                    <?php $directionClass = ($msg['sender_id'] == $myUserId) ? 'out' : 'in'; ?>
                    <div class="msg-row <?php echo $directionClass; ?>" data-id="<?php echo $msg['id']; ?>">
                        <div class="msg-bubble">
                            <div class="msg-body-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                            <div class="msg-time">
                                <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="chat-input-area">
                <div class="native-emoji-picker" id="nativeEmojiPicker">
                    <div class="emoji-picker-header">Emojis</div>
                    <div class="emoji-picker-grid" id="emojiGrid"></div>
                </div>
                <button class="input-action-btn" id="emojiBtn" type="button"><i class="bi bi-emoji-smile"></i></button>
                <div class="chat-input-container">
                    <textarea id="msgInput" placeholder="Type an internal message..."></textarea>
                </div>
                <button class="send-btn-wa" id="sendBtn"><i class="bi bi-send-fill"></i></button>
            </div>
        </div>
    <?php else: ?>
        <div class="chat-empty-state">
            <i class="bi bi-chat-left-text"></i>
            <h4>Internal Chat</h4>
            <p>Select a colleague from the directory to start a private conversation.</p>
        </div>
    <?php endif; ?>
</div>

<script>
const COLLEAGUE_ID = <?php echo (int) $activeColleagueId; ?>;
let lastMsgId = <?php echo (int) $lastMsgId; ?>;
let polling = null;

const msgInput = document.getElementById('msgInput');
const sendBtn = document.getElementById('sendBtn');
const emojiBtn = document.getElementById('emojiBtn');
const pickerEl = document.getElementById('nativeEmojiPicker');
const grid = document.getElementById('emojiGrid');
const chatMessages = document.getElementById('chatMessages');

const emojisList = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗',
    '👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤌', '📌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👍', '👎', '❤️'
];

if (grid) {
    emojisList.forEach(emoji => {
        const span = document.createElement('span');
        span.className = 'emoji-item';
        span.innerText = emoji;
        span.onclick = () => { if (msgInput) msgInput.value += emoji; };
        grid.appendChild(span);
    });
}

if (emojiBtn && pickerEl) {
    emojiBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        pickerEl.style.display = pickerEl.style.display === 'flex' ? 'none' : 'flex';
    });
    document.addEventListener('click', (e) => {
        if (!pickerEl.contains(e.target) && e.target !== emojiBtn) pickerEl.style.display = 'none';
    });
}

if (msgInput) {
    msgInput.addEventListener('input', () => {
        msgInput.style.height = 'auto';
        msgInput.style.height = Math.min(msgInput.scrollHeight, 100) + 'px';
    });
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
}

if (sendBtn) {
    sendBtn.addEventListener('click', sendMessage);
}

function scrollToBottom() {
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

// Initial scroll to bottom
scrollToBottom();

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function renderMessage(msg) {
    const time = msg.created_at.substring(11, 16);
    const direction = (msg.sender_id == <?php echo $myUserId; ?>) ? 'out' : 'in';
    return `
        <div class="msg-row ${direction}" data-id="${msg.id}">
            <div class="msg-bubble">
                <div class="msg-body-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                <div class="msg-time">${time}</div>
            </div>
        </div>
    `;
}

async function sendMessage() {
    if (!msgInput || !COLLEAGUE_ID) return;
    const text = msgInput.value.trim();
    if (!text) return;
    msgInput.value = '';
    msgInput.style.height = 'auto';
    
    if (sendBtn) sendBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('colleague_id', COLLEAGUE_ID);
    formData.append('message', text);
    
    try {
        const res = await fetch('/wa_saas/api/send_internal_chat.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.id) {
            chatMessages.insertAdjacentHTML('beforeend', renderMessage(data));
            lastMsgId = Math.max(lastMsgId, data.id);
            scrollToBottom();
        }
    } catch (e) {
        console.error(e);
    } finally {
        if (sendBtn) sendBtn.disabled = false;
        msgInput.focus();
    }
}

function pollMessages() {
    if (!COLLEAGUE_ID) return;
    fetch(`/wa_saas/api/get_internal_messages.php?colleague_id=${COLLEAGUE_ID}&after_id=${lastMsgId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.messages || data.messages.length === 0) return;
            data.messages.forEach(msg => {
                if (document.querySelector(`[data-id="${msg.id}"]`)) return;
                chatMessages.insertAdjacentHTML('beforeend', renderMessage(msg));
                lastMsgId = Math.max(lastMsgId, msg.id);
            });
            scrollToBottom();
        }).catch(() => {});
}

function filterColleagues() {
    const query = document.getElementById('userSearch').value.toLowerCase();
    document.querySelectorAll('#colleaguesListBody .conv-item').forEach(item => {
        const name = item.getAttribute('data-name').toLowerCase();
        item.style.display = name.includes(query) ? 'flex' : 'none';
    });
}

if (COLLEAGUE_ID > 0) {
    polling = setInterval(pollMessages, 3000);
}
window.addEventListener('beforeunload', () => { if (polling) clearInterval(polling); });
</script>
<?php include("../layouts/footer.php"); ?>
