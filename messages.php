<?php
// ===================== Messages (Page + API, merged) =====================
session_start();
require "db.php";



// Must be logged in to view messages
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];


/* =====================================================
   SEND MESSAGE (POST request)
   ===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (!$receiver_id || $message === '') {
        echo json_encode([
            "success" => false,
            "error" => "Message cannot be empty."
        ]);
        exit();
    }

    if ($receiver_id === $current_user_id) {
        echo json_encode([
            "success" => false,
            "error" => "You cannot message yourself."
        ]);
        exit();
    }

    // Make sure the receiver actually exists before inserting
    $check = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $check->bind_param("i", $receiver_id);
    $check->execute();

    if ($check->get_result()->num_rows === 0) {
        echo json_encode([
            "success" => false,
            "error" => "User not found."
        ]);
        exit();
    }

    $check->close();

    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at)
        VALUES (?, ?, ?, 0, NOW())
    ");

    $stmt->bind_param("iis", $current_user_id, $receiver_id, $message);

     if ($stmt->execute()) {
        // Trigger push notification for new message
     require_once "push_trigger.php";
$sender_name = $_SESSION['username'] ?? 'Someone';
triggerPushAsync($receiver_id, "New message from $sender_name", substr($message,0,80), "/Jobskenewversion/messages.php?user=$current_user_id");
        echo json_encode(["success" => true]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Could not send message."
        ]);
    }

    $stmt->close();
    exit();
}


/* =====================================================
   LOAD CONVERSATION (GET ?action=conversation)
   ===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'conversation') {

    header('Content-Type: application/json');

    $other_user_id = (int) ($_GET['user_id'] ?? 0);

    if (!$other_user_id) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid user."
        ]);
        exit();
    }

    // Check if this user has "cleared" this chat before — if so,
    // only show messages sent after that clear timestamp
    $clearStmt = $conn->prepare("
        SELECT cleared_at
        FROM conversation_clears
        WHERE user_id = ?
        AND other_user_id = ?
    ");

    $clearStmt->bind_param("ii", $current_user_id, $other_user_id);
    $clearStmt->execute();

    $clearRow = $clearStmt->get_result()->fetch_assoc();
    $clearStmt->close();

    $cleared_at = $clearRow['cleared_at'] ?? null;

    // Fetch every message between these two users, respecting the clear timestamp
    $stmt = $conn->prepare("
        SELECT
            messages.id,
            messages.sender_id,
            messages.receiver_id,
            messages.message,
            messages.created_at,
            users.name AS sender_name
        FROM messages
        JOIN users ON messages.sender_id = users.id
        WHERE
            (
                (messages.sender_id = ? AND messages.receiver_id = ?)
                OR
                (messages.sender_id = ? AND messages.receiver_id = ?)
            )
            AND (? IS NULL OR messages.created_at > ?)
        ORDER BY messages.created_at ASC
    ");

    $stmt->bind_param(
        "iiiiss",
        $current_user_id,
        $other_user_id,
        $other_user_id,
        $current_user_id,
        $cleared_at,
        $cleared_at
    );

    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    // Mark these messages as read now that the user is viewing them
$markRead = $conn->prepare("
    UPDATE messages
    SET is_read = 1
    WHERE receiver_id = ? AND sender_id = ?
");
$markRead->bind_param("ii", $current_user_id, $other_user_id);
$markRead->execute();
$markRead->close();

    echo json_encode([
        "success" => true,
        "messages" => $messages
    ]);

    $stmt->close();

    // FIX: this exit() was missing before — without it, execution would
    // fall through into the delete/clear blocks below and then keep
    // going all the way into the HTML page output, corrupting the JSON response.
    exit();
}


/* =====================================================
   DELETE MESSAGE (GET ?action=delete&id=X)
   FIX: this block used to sit AFTER an unconditional exit() in the
   "conversation" block above, making it unreachable code. Now that the
   conversation block exits properly on its own, this block can run.
   ===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'delete') {

    header('Content-Type: application/json');

    $message_id = (int) ($_GET['id'] ?? 0);

    if (!$message_id) {
        echo json_encode(["success" => false, "error" => "Invalid ID"]);
        exit();
    }

    // Only the original sender can delete their own message
    $stmt = $conn->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
    $stmt->bind_param("ii", $message_id, $current_user_id);

    echo json_encode(["success" => $stmt->execute()]);

    $stmt->close();
    exit();
}


/* =====================================================
   CLEAR CHAT (GET ?action=clear&user_id=X)
   Same unreachable-code fix as above applies here.
   ===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'clear') {

    header('Content-Type: application/json');

    $other_user_id = (int) ($_GET['user_id'] ?? 0);

    if (!$other_user_id) {
        echo json_encode([
            "success" => false,
            "error" => "Invalid user."
        ]);
        exit();
    }

    // Record "now" as this user's clear point for this conversation.
    // Messages sent before this timestamp won't show up for them anymore.
    $stmt = $conn->prepare("
        INSERT INTO conversation_clears (user_id, other_user_id, cleared_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE cleared_at = NOW()
    ");

    $stmt->bind_param("ii", $current_user_id, $other_user_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Could not clear chat."
        ]);
    }

    $stmt->close();
    exit();
}


/* =====================================================
   PAGE MODE — everything below only runs for a normal
   browser visit to messages.php (no action param, GET request)
   ===================================================== */

// Which conversation is currently open (if any)
$selected_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$selected_user = null;

if ($selected_user_id) {
    $stmt = $conn->prepare("SELECT id, name, profile_picture FROM users WHERE id = ?");
    $stmt->bind_param("i", $selected_user_id);
    $stmt->execute();
    $selected_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// List of everyone the current user has exchanged messages with,
// for the conversation sidebar
$stmt = $conn->prepare("
    SELECT 
        u.id, 
        u.name, 
        u.profile_picture,
        SUM(CASE 
            WHEN m.receiver_id = ? AND m.is_read = 0 
            THEN 1 ELSE 0 
        END) AS unread_count
    FROM users u
    JOIN messages m
        ON (
            (m.sender_id = u.id AND m.receiver_id = ?)
            OR
            (m.receiver_id = u.id AND m.sender_id = ?)
        )
    WHERE u.id != ?
    GROUP BY u.id, u.name, u.profile_picture
    ORDER BY u.name ASC
");

$stmt->bind_param("iiii", $current_user_id, $current_user_id, $current_user_id, $current_user_id);


$stmt->execute();
$result = $stmt->get_result();

$conversations = [];
while ($row = $result->fetch_assoc()) {
    $conversations[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Jobske</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include 'sidebar.php'; ?>

<main class="main-content messages-page <?= $selected_user ? 'chat-open' : 'chat-closed' ?>">

    <section class="messages-container">
<!-- ================= CONVERSATIONS SIDEBAR ================= -->
<aside class="conversation-list">
    <h2>Messages</h2>

    <?php if (empty($conversations)): ?>
        <p class="empty-state">No conversations yet.</p>
    <?php else: ?>
        <?php foreach ($conversations as $conversation): ?>
            <a
                href="messages.php?user_id=<?= $conversation['id'] ?>"
                class="conversation-item 
                    <?= ($selected_user_id === (int)$conversation['id']) ? 'active' : '' ?>
                    <?= ($conversation['unread_count'] > 0) ? 'unread-conversation' : '' ?>">

                <div class="conversation-avatar">
                    <?php if (!empty($conversation['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($conversation['profile_picture']) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars(strtoupper(substr($conversation['name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>

                <span><?= htmlspecialchars($conversation['name']) ?></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</aside>

        <!-- ================= CHAT PANEL ================= -->
        <section class="chat-panel">

            <?php if ($selected_user): ?>

                <header class="chat-header">
                    <a href="profile.php?user_id=<?= $selected_user['id'] ?>" class="chat-user">
                        <div class="conversation-avatar">
                            <?php if (!empty($selected_user['profile_picture'])): ?>
                                <img src="<?= htmlspecialchars($selected_user['profile_picture']) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars(strtoupper(substr($selected_user['name'], 0, 1))) ?>
                            <?php endif; ?>
                        </div>
                        <strong><?= htmlspecialchars($selected_user['name']) ?></strong>
                    </a>

                    <!-- Clear Chat button -->
                    <button id="clearChatBtn" class="btn btn-secondary" data-user="<?= $selected_user['id'] ?>">
                        Clear Chat
                    </button>
                </header>

                <!-- ===================== Delete Message Confirmation Modal ===================== -->
<div class="modal-overlay" id="deleteMsgModal" hidden>
    <div class="modal-box">
        <p>Delete this message?</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" id="deleteMsgCancelBtn">Cancel</button>
            <button class="btn btn-danger" id="deleteMsgConfirmBtn">Delete</button>
        </div>
    </div>
</div>

<!-- ===================== Clear Chat Confirmation Modal ===================== -->
<div class="modal-overlay" id="clearChatModal" hidden>
    <div class="modal-box">
        <p>Are you sure you want to clear this chat?</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" id="clearChatCancelBtn">Cancel</button>
            <button class="btn btn-danger" id="clearChatConfirmBtn">Clear</button>
        </div>
    </div>
</div>

                <div class="messages-list" id="messagesList">Loading...</div>

                <form id="messageForm" class="message-form">
                    <input type="text" id="messageInput" placeholder="Write a message..." autocomplete="off" required>
                    <button type="submit" class="btn btn-primary">Send</button>
                </form>

            <?php else: ?>

                <div class="empty-chat">
                    <h2>Select a conversation</h2>
                    <p>Choose someone from your messages.</p>
                </div>

            <?php endif; ?>

        </section>

    </section>

</main>

<?php if ($selected_user): ?>
<script>
// FIX: everything (including delete, long-press, and clear-chat listeners)
// now lives inside this single DOMContentLoaded callback, so it can see
// messagesList, clearChatBtn, and loadConversation() without "not defined" errors.
document.addEventListener("DOMContentLoaded", () => {

    const messagesList = document.getElementById("messagesList");
    const messageForm = document.getElementById("messageForm");
    const messageInput = document.getElementById("messageInput");
    const clearChatBtn = document.getElementById("clearChatBtn");

    const otherUserId = <?= (int)$selected_user_id ?>;
    const currentUserId = <?= (int)$current_user_id ?>;

    /* =================================================
       LOAD MESSAGES
       ================================================= */
    async function loadConversation() {
        try {
            const response = await fetch(`messages.php?action=conversation&user_id=${otherUserId}`);
            const data = await response.json();

            if (!data.success) {
                messagesList.innerHTML = `<p>${escapeHtml(data.error)}</p>`;
                return;
            }

            messagesList.innerHTML = "";

            if (data.messages.length === 0) {
                messagesList.innerHTML = "<p class='empty-state'>No messages yet.</p>";
                return;
            }

            data.messages.forEach(message => {
                const bubble = document.createElement("div");

                bubble.className = "message-bubble " +
                    (Number(message.sender_id) === currentUserId ? "message-mine" : "message-theirs");

                bubble.textContent = message.message;

                // FIX: dataset.id was never being set before, so the delete
                // feature had no message ID to send — this line fixes that.
                bubble.dataset.id = message.id;

   
                messagesList.appendChild(bubble);
            });

            messagesList.scrollTop = messagesList.scrollHeight;

        } catch (error) {
            messagesList.innerHTML = "<p>Could not load messages.</p>";
        }
    }

    /* =================================================
       SEND MESSAGE
       ================================================= */
    messageForm.addEventListener("submit", async (event) => {
        event.preventDefault();

        const text = messageInput.value.trim();
        if (!text) return;

        const body = new URLSearchParams();
        body.append("receiver_id", otherUserId);
        body.append("message", text);

        try {
const response = await fetch("messages.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body
});
 
            const data = await response.json();

            if (data.success) {
                messageInput.value = "";
                loadConversation();
            } else {
                alert(data.error || "Could not send message.");
            }
        } catch (error) {
            alert("Something went wrong.");
        }
    });
    const deleteMsgModal = document.getElementById("deleteMsgModal");
    const deleteMsgCancelBtn = document.getElementById("deleteMsgCancelBtn");
    const deleteMsgConfirmBtn = document.getElementById("deleteMsgConfirmBtn");
    let pendingDeleteMsgId = null;

    function askDeleteMessage(messageId) {
        pendingDeleteMsgId = messageId;
        deleteMsgModal.hidden = false;
    }

    deleteMsgCancelBtn.addEventListener("click", () => {
        deleteMsgModal.hidden = true;
        pendingDeleteMsgId = null;
    });

    deleteMsgModal.addEventListener("click", (e) => {
        if (e.target === deleteMsgModal) {
            deleteMsgModal.hidden = true;
            pendingDeleteMsgId = null;
        }
    });

    deleteMsgConfirmBtn.addEventListener("click", async () => {
        if (!pendingDeleteMsgId) return;
        try {
            const res = await fetch(`messages.php?action=delete&id=${pendingDeleteMsgId}`);
          const raw = await response.text();
console.log(raw);
const data = JSON.parse(raw);

            if (data.success) {
                loadConversation();
            } else {
                alert(data.error || "Could not delete message.");
            }
        } catch {
            alert("Something went wrong.");
        }
        deleteMsgModal.hidden = true;
        pendingDeleteMsgId = null;
    });

    /* =================================================
       DELETE MESSAGE — right-click (desktop)
       ================================================= */
    messagesList.addEventListener("contextmenu", (event) => {
        event.preventDefault();
        const bubble = event.target.closest(".message-bubble");
        if (!bubble) return;
        const messageId = bubble.dataset.id;
        if (messageId) askDeleteMessage(messageId);
    });

    /* =================================================
       DELETE MESSAGE — long-press (mobile)
       ================================================= */
    messagesList.addEventListener("touchstart", (event) => {
        const bubble = event.target.closest(".message-bubble");
        if (!bubble) return;

        let timer = setTimeout(() => {
            const messageId = bubble.dataset.id;
            if (messageId) askDeleteMessage(messageId);
        }, 600);

        bubble.addEventListener("touchend", () => clearTimeout(timer), { once: true });
    });

    /* =================================================
       CLEAR CHAT
       ================================================= */
    const clearChatModal = document.getElementById("clearChatModal");
    const clearChatCancelBtn = document.getElementById("clearChatCancelBtn");
    const clearChatConfirmBtn = document.getElementById("clearChatConfirmBtn");

    if (clearChatBtn) {
        clearChatBtn.addEventListener("click", () => {
            clearChatModal.hidden = false;
        });
    }

    clearChatCancelBtn.addEventListener("click", () => {
        clearChatModal.hidden = true;
    });

    clearChatModal.addEventListener("click", (e) => {
        if (e.target === clearChatModal) {
            clearChatModal.hidden = true;
        }
    });

    clearChatConfirmBtn.addEventListener("click", async () => {
        const userId = clearChatBtn.dataset.user;
        if (!userId) return;

        try {
            const response = await fetch(`messages.php?action=clear&user_id=${userId}`);
            const data = await response.json();
            if (data.success) {
                loadConversation();
            } else {
                alert(data.error || "Could not clear chat.");
            }
        } catch (error) {
            alert("Something went wrong.");
        }
        clearChatModal.hidden = true;
    });

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text || "";
        return div.innerHTML;
    }

    // Initial load of this conversation
    loadConversation();

});
</script>
<?php endif; ?>

</body>
</html>