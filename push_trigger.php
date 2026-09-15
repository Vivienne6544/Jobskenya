<?php
// ===================== push_trigger.php =====================
// Fires a background request to process_push.php and returns immediately,
// so the calling page (job.php / messages.php) doesn't wait for the
// actual push to be sent.

function triggerPushAsync($user_id, $title, $body, $url) {

    error_log("[PUSH_TRIGGER] Called for user_id=$user_id title=$title");

    $payload = json_encode([
        "user_id" => $user_id,
        "title" => $title,
        "body" => $body,
        "url" => $url
    ]);

    // Build the URL to process_push.php on this same server
    $host = $_SERVER['HTTP_HOST'];
    $path = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $target = "http://$host$path/process_push.php";

    error_log("[PUSH_TRIGGER] Target URL: $target");

    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

    // Key part: don't wait for a response, and give up almost instantly
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 100);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

    $result = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("[PUSH_TRIGGER] curl_exec result: " . var_export($result, true) . " | errno=$curlErrno | error=$curlError");
}