<?php
// ===================== notifications.php =====================
// Returns unread counts for applicants (employer side) and messages

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(["success" => false, "error" => "Not logged in."]);
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];

if (isset($_GET['action']) && $_GET['action'] === 'unread_count') {

    header('Content-Type: application/json');

    // Unread applicants: applications on jobs owned by this user, not yet read
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        WHERE jobs.employer_id = ? AND applications.is_read = 0
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $applicant_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    // Unread messages: messages sent to this user, not yet read
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $message_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    echo json_encode([
        "success" => true,
        "applicants" => $applicant_count,
        "messages" => $message_count
    ]);
    exit();
}

header('Content-Type: application/json');
echo json_encode(["success" => false, "error" => "Invalid request."]);
exit();