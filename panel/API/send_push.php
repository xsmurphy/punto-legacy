<?php
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$externalId = validateHttp('ids', 'post');    // contactId(s) a notificar
$title      = iftn(validateHttp('title', 'post'), APP_NAME);
$message    = validateHttp('message', 'post');
$webLink    = iftn(validateHttp('web_url', 'post'), false);
$appLink    = iftn(validateHttp('app_url', 'post'), false);
$edata      = json_decode(stripslashes(validateHttp('edata', 'post')), true);

if (validateHttp('secret', 'post') != NCM_SECRET || !$externalId) {
    apiOk(['error' => 'Missing push data'], 500);
}

if (!is_array($externalId)) {
    $externalId = [$externalId];
}

if (!VAPID_PUBLIC_KEY || !VAPID_PRIVATE_KEY) {
    apiOk(['error' => 'VAPID keys not configured'], 500);
}

// Traer suscripciones activas de los destinatarios
$placeholders = implode(',', array_fill(0, count($externalId), '?'));
$subs = ncmExecute(
    "SELECT endpoint, p256dh, auth FROM push_subscription WHERE contactId IN ($placeholders) AND active = true",
    $externalId,
    false,
    true
);

if (!$subs || $subs->EOF) {
    apiOk(['sent' => 0, 'note' => 'No subscriptions found']);
}

$auth = [
    'VAPID' => [
        'subject'    => VAPID_SUBJECT,
        'publicKey'  => VAPID_PUBLIC_KEY,
        'privateKey' => VAPID_PRIVATE_KEY,
    ],
];

$webPush = new WebPush($auth);

$payload = json_encode([
    'title'   => $title,
    'message' => $message,
    'url'     => $webLink ?: $appLink,
    'data'    => $edata,
]);

$sent = 0;
while (!$subs->EOF) {
    $sub = Subscription::create([
        'endpoint'        => $subs->fields['endpoint'],
        'keys'            => [
            'p256dh' => $subs->fields['p256dh'],
            'auth'   => $subs->fields['auth'],
        ],
    ]);
    $webPush->queueNotification($sub, $payload);
    $sent++;
    $subs->MoveNext();
}
$subs->Close();

$failures = [];
foreach ($webPush->flush() as $report) {
    if (!$report->isSuccess()) {
        // Suscripción expirada o inválida — marcar como inactiva
        if ($report->isSubscriptionExpired()) {
            $db->Execute(
                "UPDATE push_subscription SET active = false WHERE endpoint = ?",
                [$report->getEndpoint()]
            );
        }
        $failures[] = $report->getReason();
    }
}

apiOk(['sent' => $sent, 'failures' => $failures]);
?>
