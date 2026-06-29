<?php
/**
 * /v1/screens — Pantallas cliente pareadas como device (module='screen').
 *
 *   POST ?resource=heartbeat (auth device Bearer, module=screen) — keep-alive
 *   POST ?resource=publish   (auth device Bearer, module=pos)    — emite evento al canal de la caja
 *   GET  (sin resource)      (auth panel)                        — lista pantallas del tenant
 *   DELETE ?id=<uuid>        (auth panel)                        — revoca (soft-delete) una pantalla
 *
 * El pairing ya NO pasa por este archivo: se hace vía Device Authorization Grant
 * en /v1/device_invitations con module='screen'.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/DeviceAuth.php';
require_once __DIR__ . '/../lib/Auth/apiAuthPosContext.php';

$resource = $_GET['resource'] ?? null;
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;

// ── POST ?resource=heartbeat — auth device Bearer (module=screen) ─────────────

if ($method === 'POST' && $resource === 'heartbeat') {
    $ctx = apiAuthPosContext();
    if (($ctx['module'] ?? 'pos') !== 'screen') {
        apiError('Heartbeat solo para pantallas cliente', 403);
    }
    // apiAuthPosContext ya actualiza lastSeenAt + iplast en la fila device (DeviceAuth::resolveDeviceToken)
    apiOk(['ok' => true]);
    exit;
}

// ── GET ?resource=context — auth device Bearer (module=screen) ───────────────
// Devuelve datos para que la pantalla cliente arme su UI sin esperar al
// primer cart-update del POS: companyName, registerName, outletName, logoUrl.

if ($method === 'GET' && $resource === 'context') {
    $ctx = apiAuthPosContext();
    if (($ctx['module'] ?? 'pos') !== 'screen') {
        apiError('Context solo para pantallas cliente', 403);
    }
    // settingName / settingObj viven en `company.config` JSONB y los expone
    // el flatten de ncmExecute como top-level keys (mismo patrón que
    // SettingsService::general). El logo se guarda en config.hasLogo +
    // config.logoUrl + config.logoUploadedAt (cache-bust).
    $company = ncmExecute(
        'SELECT * FROM company WHERE companyid = ?::uuid LIMIT 1',
        [$ctx['companyId']]
    );
    $obj = json_decode((string) ($company['settingObj'] ?? ''), true);
    if (!is_array($obj)) { $obj = []; }
    $hasLogo  = !empty($obj['hasLogo']);
    $logoUrl  = (string) ($obj['logoUrl'] ?? '');
    $logoStmp = isset($obj['logoUploadedAt']) ? (int) $obj['logoUploadedAt'] : null;
    $logo     = ($hasLogo && $logoUrl !== '')
        ? $logoUrl . ($logoStmp ? '?v=' . $logoStmp : '')
        : '';

    $reg = $ctx['registerId'] !== ''
        ? ncmExecute('SELECT registername FROM register WHERE registerid=?::uuid LIMIT 1', [$ctx['registerId']])
        : null;
    $out = $ctx['outletId'] !== ''
        ? ncmExecute('SELECT outletname FROM outlet WHERE outletid=?::uuid LIMIT 1', [$ctx['outletId']])
        : null;

    apiOk([
        'companyName'  => (string) ($company['settingName'] ?? ''),
        'registerName' => (string) ($reg['registername'] ?? ''),
        'outletName'   => (string) ($out['outletname'] ?? ''),
        'logoUrl'      => $logo,
    ]);
    exit;
}

// ── POST ?resource=publish — auth device Bearer (module=pos) ─────────────────

if ($method === 'POST' && $resource === 'publish') {
    $ctx = apiAuthPosContext();
    if (($ctx['module'] ?? 'pos') !== 'pos') {
        apiError('Publish solo para dispositivos POS', 403);
    }

    $type = $_POST['type'] ?? '';
    $raw  = $_POST['data'] ?? [];
    $data = is_string($raw) ? (json_decode($raw, true) ?? []) : (is_array($raw) ? $raw : []);

    $validTypes = ['cart-update', 'sale-confirmed', 'cart-cleared', 'idle'];
    if (!in_array($type, $validTypes, true)) {
        apiError('tipo inválido', 400);
    }

    wsPublish($ctx['companyId'] . ':checkout:' . $ctx['registerId'], $type, $data);

    apiOk(['ok' => true]);
    exit;
}

// ── Con auth panel: list, delete ─────────────────────────────────────────────

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];

switch (true) {

    // ── GET — listar pantallas (auth panel) ──────────────────────────────────
    case $method === 'GET': {
        // Lee de device WHERE module='screen' + JOIN a register para registerName.
        // Shape compatible con lo que useScreens() y useConnectedDevices() esperan:
        // { screens: [{ id, name, registerId, registerName, ipLast, lastSeenAt, status, createdAt }] }
        $rs = ncmExecute(
            "SELECT d.deviceid AS id, d.devicename AS name,
                    d.registerid AS \"registerId\",
                    r.registername AS \"registerName\",
                    d.iplast::text AS \"ipLast\",
                    d.lastseenat AS \"lastSeenAt\",
                    d.status,
                    d.createdat AS \"createdAt\"
             FROM device d
             LEFT JOIN register r ON r.registerid = d.registerid
             WHERE d.companyid = ?::uuid AND d.module = 'screen'
             ORDER BY d.status DESC, d.createdat DESC",
            [$companyId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $rs->fields;
                $rs->MoveNext();
            }
            $rs->Close();
        }

        apiOk(['screens' => $rows]);
        break;
    }

    // ── DELETE ?id=<uuid> — revocar pantalla (auth panel) ───────────────────
    case $method === 'DELETE': {
        if ($id === null) {
            apiError('id requerido', 422);
        }

        // Revocación: soft-delete (status=0) limitado al tenant + module=screen.
        // Verificar que el device pertenece al tenant Y es una pantalla antes de revocar.
        $screen = ncmExecute(
            'SELECT deviceid FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND module = ? AND status = 1',
            [$id, $companyId, 'screen']
        );
        if (!$screen) {
            apiError('Pantalla no encontrada', 404);
        }
        \Punto\Api\Auth\DeviceAuth::revoke($id, $companyId);

        // Notificar a la pantalla para que muestre estado desconectado.
        wsPublish('screen:' . $id, 'revoked', []);

        apiOk(['ok' => true]);
        break;
    }

    default:
        apiError('Method not allowed', 405);
}
