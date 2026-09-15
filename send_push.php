<?php
require __DIR__ . '/vendor/autoload.php';
require "db.php";

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

define('VAPID_PUBLIC_KEY', 'BC5JuAqFrl49UCZADtZSoBa9_ZTmYuDuXs7Jghod8rlT6Wu2si56J1muoGnCrXACJl57-Wq4XtLqs4QEvxT51ZY');
define('VAPID_PRIVATE_KEY', '-463QhO21OZdJdREDERe5bGg5dLiiUrgd4CqPiSzgyg');
define('VAPID_SUBJECT', 'mailto:viviennembithi@gmail.com');

function sendPushToUser($user_id, $title = "Jobske", $body = "New message", $url = "/Jobskenewversion/messages.php") {
    global $conn;

    // Get this user's saved subscriptions (endpoint + keys)
    $stmt = $conn->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ],
    ];

    $webPush = new WebPush($auth);

    $payload = json_encode([
        "title" => $title,
        "body" => $body,
        "url" => $url,
    ]);

    while ($row = $res->fetch_assoc()) {
        $subscription = Subscription::create([
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
        ]);

        $webPush->queueNotification($subscription, $payload);
    }

    $stmt->close();

    // Actually sends everything queued above, and tells us what failed
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();

        if (!$report->isSuccess()) {
            // Subscription is gone (expired/unsubscribed) — clean it up
            if ($report->isSubscriptionExpired()) {
                $del = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint=?");
                $del->bind_param("s", $endpoint);
                $del->execute();
                $del->close();
            } else {
                error_log("Push failed for $endpoint: " . $report->getReason());
            }
        }
    }
}