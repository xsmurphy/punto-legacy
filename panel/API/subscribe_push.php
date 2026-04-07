<?php
/**
 * Registra o actualiza una suscripción Web Push para el usuario autenticado.
 *
 * El browser llama este endpoint después de obtener una PushSubscription.
 * Payload (JSON body o POST):
 *   - endpoint : string  (URL de la suscripción del browser)
 *   - p256dh   : string  (clave pública del cliente, base64url)
 *   - auth     : string  (secreto de autenticación, base64url)
 */

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    $body = $_POST;
}

$endpoint = trim($body['endpoint'] ?? '');
$p256dh   = trim($body['p256dh']   ?? '');
$auth     = trim($body['auth']     ?? '');

if (!$endpoint || !$p256dh || !$auth) {
    apiOk(['error' => 'endpoint, p256dh and auth are required'], 400);
}

// Upsert: actualiza si ya existe el endpoint, inserta si no
$existing = ncmExecute(
    "SELECT id FROM push_subscription WHERE endpoint = ? LIMIT 1",
    [$endpoint]
);

if ($existing && !$existing->EOF) {
    $db->Execute(
        "UPDATE push_subscription SET p256dh = ?, auth = ?, contactId = ?, active = true, updatedAt = NOW() WHERE endpoint = ?",
        [$p256dh, $auth, USER_ID, $endpoint]
    );
} else {
    $db->Execute(
        "INSERT INTO push_subscription (contactId, companyId, endpoint, p256dh, auth, active, createdAt, updatedAt)
         VALUES (?, ?, ?, ?, ?, true, NOW(), NOW())",
        [USER_ID, COMPANY_ID, $endpoint, $p256dh, $auth]
    );
}

apiOk(['subscribed' => true]);
?>
