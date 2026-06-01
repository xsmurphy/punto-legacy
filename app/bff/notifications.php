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

require_once __DIR__ . '/lib/bff_init.php';
$parts  = explode(',', $action);

// --- list: action = "notifications[,type]" → POST (mutación: marca visto) --
if (($parts[0] ?? '') === 'notifications') {
    $res = bffApiPost('v1/notifications.php', [], '_jwt');
    // Degradación segura: ante fallo, lista vacía (el front itera el resultado).
    bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);
}

// --- count: action = "notificationsCount[,type]" → GET ?type= -------------
if ($action === 'notificationsCount' || ($parts[0] ?? '') === 'notificationsCount') {
    $type = $parts[1] ?? 'notes';
    $res  = bffApiGet('v1/notifications.php', ['type' => $type], '_jwt');
    // Degradación segura: ante fallo, count 0 (el front lee result.count).
    bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : ['count' => 0]);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
