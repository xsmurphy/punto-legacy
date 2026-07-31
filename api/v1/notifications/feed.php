<?php
/**
 * /v1/notifications/feed.php — Centro de notificaciones del panel
 * (context/31-centro-de-notificaciones.md — plan cerrado 2026-07-30).
 *
 *   GET                          → { items: [...], unreadCount } — feed
 *     unificado: eventos (tabla `notify`) + vencimientos derivados EN VIVO
 *     del forecast financiero (Punto\Api\Finance\ObligationsService).
 *   POST op=read    { alertKeys: string[] } → marca leídos (idempotente)
 *   POST op=dismiss { alertKey: string }     → descarta del feed (idempotente)
 *   POST op=readAll                          → marca todo lo visible como leído
 *
 * Cada mutación devuelve el feed actualizado (mismo shape que GET) para que
 * el frontend pueda pisar la query cache sin un segundo round-trip.
 *
 * Auth realm `panel` — DISTINTO del `/v1/notifications` legacy (realm
 * pos-app, watermark contact.contactLastNotificationSeen). Ese endpoint NO
 * se toca: sigue siendo el feed del POS.
 */
require_once __DIR__ . '/../../bootstrap.php';

use Punto\Api\Notifications\FeedService;

$ctx = apiAuthTenant(['panel']);
$companyId = (string) COMPANY_ID;
$userId    = (string) ($ctx['userId'] ?? '');
$outletId  = (string) ($ctx['outletId'] ?? '');

if ($userId === '') {
    apiError('Usuario inválido', 401);
}

$svc = new FeedService();
// Los vencimientos derivados (cheques/cuotas/compras) exigen el mismo
// permiso que /v1/finance/forecast.php — un usuario sin acceso a Finanzas
// no debe ver esa información en su feed de notificaciones.
$includeFinance = hasPermission('finance.manage');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    apiOk($svc->feed($companyId, $userId, $outletId, $includeFinance));
}

if ($method === 'POST') {
    $op = trim((string) ($_POST['op'] ?? ''));

    if ($op === 'read') {
        $alertKeys = is_array($_POST['alertKeys'] ?? null) ? $_POST['alertKeys'] : [];
        $svc->markRead($companyId, $userId, $alertKeys);
        apiOk($svc->feed($companyId, $userId, $outletId, $includeFinance));
    }

    if ($op === 'dismiss') {
        $alertKey = trim((string) ($_POST['alertKey'] ?? ''));
        if ($alertKey === '') {
            apiError('alertKey requerido', 400);
        }
        $svc->markDismissed($companyId, $userId, $alertKey);
        apiOk($svc->feed($companyId, $userId, $outletId, $includeFinance));
    }

    if ($op === 'readAll') {
        $svc->markAllRead($companyId, $userId, $outletId, $includeFinance);
        apiOk($svc->feed($companyId, $userId, $outletId, $includeFinance));
    }

    apiError('op inválida (read|dismiss|readAll)', 400);
}

apiError('Método no permitido', 405);
