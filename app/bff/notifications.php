<?php
/**
 * /bff/notifications.php — BFF de notificaciones del POS (Slice 14, cluster ENCOM→Punto).
 *
 * Reemplaza los handlers notifications/notificationsCount (action.php L117/L136) que hacían
 * proxy curl a panel/API/get_notifications(_count) (roto en dev). Reenvía a
 * /api/v1/notifications.php (cookie _jwt) y devuelve el shape legacy al top level:
 *   - list  → array de notificaciones (el front itera result en buildNotifyLists)
 *   - count → { count, lastSeen } (el front lee result.count)
 *
 * Front manda action="notifications,<type>" (list) o action="notificationsCount" (count).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');
$parts  = explode(',', $action);

// --- list: action = "notifications[,type]" --------------------------------
if (($parts[0] ?? '') === 'notifications') {
    $res = bffApiPost('v1/notifications.php', ['op' => 'list'], '_jwt');
    // Degradación segura: ante fallo, lista vacía (el front itera el resultado).
    bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);
}

// --- count: action = "notificationsCount[,type]" --------------------------
if ($action === 'notificationsCount' || ($parts[0] ?? '') === 'notificationsCount') {
    $type = $parts[1] ?? 'notes';
    $res  = bffApiPost('v1/notifications.php', ['op' => 'count', 'type' => $type], '_jwt');
    // Degradación segura: ante fallo, count 0 (el front lee result.count).
    bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : ['count' => 0]);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
