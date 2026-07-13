<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$queryStr = isset($_GET['q']) ? trim($_GET['q']) : '';

$conversations = [];
$messages = [];
$tags = [];
$notes = [];

if ($queryStr !== '') {
    $searchPattern = "%" . $queryStr . "%";

    // 1. Search Customers/Conversations (by phone number)
    $stmt1 = $conn->prepare("
        SELECT id, contact_number, status, last_message_at 
        FROM conversations 
        WHERE company_id = ? AND contact_number LIKE ? 
        LIMIT 20
    ");
    $stmt1->bind_param("is", $companyId, $searchPattern);
    $stmt1->execute();
    $conversations = $stmt1->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt1->close();

    // 2. Search Messages
    $stmt2 = $conn->prepare("
        SELECT cm.id, cm.conversation_id, cm.body, cm.direction, cm.sent_at, c.contact_number 
        FROM chat_messages cm
        JOIN conversations c ON cm.conversation_id = c.id
        WHERE c.company_id = ? AND cm.body LIKE ? 
        ORDER BY cm.sent_at DESC 
        LIMIT 30
    ");
    $stmt2->bind_param("is", $companyId, $searchPattern);
    $stmt2->execute();
    $messages = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    // 3. Search Tags
    $stmt3 = $conn->prepare("
        SELECT c.id AS conv_id, c.contact_number, t.name AS tag_name, t.color
        FROM conversations c
        JOIN conversation_tags ct ON c.id = ct.conversation_id
        JOIN tags t ON t.id = ct.tag_id
        WHERE c.company_id = ? AND t.name LIKE ?
        LIMIT 20
    ");
    $stmt3->bind_param("is", $companyId, $searchPattern);
    $stmt3->execute();
    $tags = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();

    // 4. Search Notes
    $stmt4 = $conn->prepare("
        SELECT cn.id, cn.conversation_id, cn.note_body, cn.created_at, c.contact_number
        FROM conversation_notes cn
        JOIN conversations c ON cn.conversation_id = c.id
        WHERE c.company_id = ? AND cn.note_body LIKE ?
        ORDER BY cn.created_at DESC
        LIMIT 20
    ");
    $stmt4->bind_param("is", $companyId, $searchPattern);
    $stmt4->execute();
    $notes = $stmt4->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt4->close();
}

include("../layouts/header.php");
?>

<div class="card p-4 shadow-sm border-0 mb-4">
    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-search text-primary me-2"></i>Advanced Global Search</h5>
    <form method="GET" action="">
        <div class="input-group">
            <input type="text" name="q" value="<?php echo htmlspecialchars($queryStr); ?>" placeholder="Search messages, phone numbers, tags, internal notes..." class="form-control" required>
            <button type="submit" class="btn btn-primary px-4 fw-semibold"><i class="bi bi-search me-1"></i> Search</button>
        </div>
    </form>
</div>

<?php if ($queryStr !== ''): ?>
    <h6 class="text-secondary fw-semibold mb-4">Search results for "<?php echo htmlspecialchars($queryStr); ?>"</h6>

    <div class="row g-4">
        <!-- Conversations Results -->
        <div class="col-md-6">
            <div class="card p-3 shadow-sm border-0 h-100">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-people text-success me-2"></i>Matching Contacts (<?php echo count($conversations); ?>)</h6>
                <?php if (empty($conversations)): ?>
                    <p class="text-muted small">No contacts matched the criteria.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($conversations as $c): ?>
                            <a href="chat.php?conv=<?php echo $c['id']; ?>" class="list-group-item list-group-item-action border-0 px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-dark" style="font-size:13.5px;"><?php echo htmlspecialchars($c['contact_number']); ?></div>
                                    <small class="text-muted" style="font-size:11px;">Last active: <?php echo date('M d, H:i', strtotime($c['last_message_at'])); ?></small>
                                </div>
                                <span class="badge bg-secondary"><?php echo ucfirst($c['status']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tags Results -->
        <div class="col-md-6">
            <div class="card p-3 shadow-sm border-0 h-100">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-tag text-warning me-2"></i>Matching Tags (<?php echo count($tags); ?>)</h6>
                <?php if (empty($tags)): ?>
                    <p class="text-muted small">No conversation tags matched the criteria.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($tags as $t): ?>
                            <a href="chat.php?conv=<?php echo $t['conv_id']; ?>" class="list-group-item list-group-item-action border-0 px-0 d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-dark" style="font-size:13.5px;"><?php echo htmlspecialchars($t['contact_number']); ?></span>
                                <span class="badge" style="background-color: <?php echo htmlspecialchars($t['color']); ?>; font-size:11px;"><?php echo htmlspecialchars($t['tag_name']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages Results -->
        <div class="col-12">
            <div class="card p-3 shadow-sm border-0">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-chat-text text-primary me-2"></i>Matching Messages (<?php echo count($messages); ?>)</h6>
                <?php if (empty($messages)): ?>
                    <p class="text-muted small">No message content matched the criteria.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Contact</th>
                                    <th>Direction</th>
                                    <th>Message Snippet</th>
                                    <th>Sent At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($messages as $m): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?php echo htmlspecialchars($m['contact_number']); ?></td>
                                        <td><span class="badge <?php echo $m['direction'] === 'in' ? 'bg-info' : 'bg-secondary'; ?>"><?php echo $m['direction'] === 'in' ? 'Inbound' : 'Outbound'; ?></span></td>
                                        <td style="max-width:350px;" class="text-truncate">
                                            <?php 
                                            // Highlight query snippet
                                            $text = htmlspecialchars($m['body']);
                                            $hl = '<mark class="p-0 bg-warning">' . htmlspecialchars($queryStr) . '</mark>';
                                            echo str_ireplace(htmlspecialchars($queryStr), $hl, $text);
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', strtotime($m['sent_at'])); ?></td>
                                        <td><a href="chat.php?conv=<?php echo $m['conversation_id']; ?>" class="btn btn-sm btn-primary py-0 px-2" style="font-size:11.5px;">Go to Chat</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notes Results -->
        <div class="col-12">
            <div class="card p-3 shadow-sm border-0">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-journal-text text-danger me-2"></i>Matching Internal Notes (<?php echo count($notes); ?>)</h6>
                <?php if (empty($notes)): ?>
                    <p class="text-muted small">No internal notes matched the criteria.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th>Contact</th>
                                    <th>Note Content</th>
                                    <th>Created At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notes as $n): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?php echo htmlspecialchars($n['contact_number']); ?></td>
                                        <td style="max-width:350px;" class="text-truncate">
                                            <?php 
                                            $text = htmlspecialchars($n['note_body']);
                                            $hl = '<mark class="p-0 bg-warning">' . htmlspecialchars($queryStr) . '</mark>';
                                            echo str_ireplace(htmlspecialchars($queryStr), $hl, $text);
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', strtotime($n['created_at'])); ?></td>
                                        <td><a href="chat.php?conv=<?php echo $n['conversation_id']; ?>" class="btn btn-sm btn-primary py-0 px-2" style="font-size:11.5px;">Go to Chat</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include("../layouts/footer.php"); ?>
