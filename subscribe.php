<?php
session_start();
require "db.php";
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success"=>false, "error"=>"not logged in"]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['endpoint'])) {
    echo json_encode(["success"=>false, "error"=>"no endpoint"]);
    exit();
}

$endpoint = $input['endpoint'];
$p256dh = $input['keys']['p256dh'] ?? '';
$auth = $input['keys']['auth'] ?? '';
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=?, p256dh=?, auth=?");
$stmt->bind_param("isssiss", $user_id, $endpoint, $p256dh, $auth, $user_id, $p256dh, $auth);
if ($stmt->execute()) {
    echo json_encode(["success"=>true]);
} else {
    echo json_encode(["success"=>false, "error"=>$stmt->error]);
}
$stmt->close();
$conn->close();
?>