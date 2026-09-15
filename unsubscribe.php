<?php
session_start();
require "db.php";
if(!isset($_SESSION['user_id'])) exit();
$stmt = $conn->prepare("DELETE FROM push_subscriptions WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
?>