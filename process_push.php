<?php
// ===================== process_push.php =====================
// Called in the background by push_trigger.php.
// Does the actual slow work of sending a push notification.

error_log("[PROCESS_PUSH] Hit. Method: " . $_SERVER['REQUEST_METHOD']);

require_once "send_push.php";

$rawInput = file_get_contents('php://input');
error_log("[PROCESS_PUSH] Raw input: " . $rawInput);

$input = json_decode($rawInput, true);

if (!$input || !isset($input['user_id'])) {
    error_log("[PROCESS_PUSH] Missing/invalid input, exiting.");
    exit();
}

$user_id = (int) $input['user_id'];
$title = $input['title'] ?? 'Jobske';
$body = $input['body'] ?? 'You have a new update';
$url = $input['url'] ?? '/Jobskenewversion/home.php';

error_log("[PROCESS_PUSH] Calling sendPushToUser for user_id=$user_id");

sendPushToUser($user_id, $title, $body, $url);

error_log("[PROCESS_PUSH] sendPushToUser call finished.");