<?php
session_start();
require "db.php";
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "error" => "Not logged in."]);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT title, body, url FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    echo json_encode(["title" => $row['title'], "body" => $row['body'], "url" => $row['url']]);
} else {
    echo json_encode(["title" => "Jobske", "body" => "You have a new update", "url" => "/Jobskenewversion/home.php"]);
}

$stmt->close();
?>