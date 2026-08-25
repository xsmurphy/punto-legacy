<?php
/**
 * /v1/screens — Pantallas cliente pareadas como device (module en
 * DISPLAY_MODULES: 'screen' | 'kds' | 'display').
 *
 *   POST ?resource=heartbeat (auth device Bearer, module de pantalla) — keep-alive
 *   POST ?resource=publish   (auth device Bearer, module=pos)    — emite evento al canal de la caja
 *   GET  (sin resource)      (auth panel)                        — lista pantallas del tenant
 *   DELETE ?id=<uuid>        (auth panel)                        — revoca (soft-delete) una pantalla
 *
 * El pairing ya NO pasa por este archivo: se hace vía Device Authorization Grant
 * en /v1/device_invitations con module='screen'|'kds'|'display'.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/DeviceAuth.php';
require_once __DIR__ . '/../lib/Auth/apiAuthPosContext.php';

// Módulos que son "pantalla" (heartbeat/context genérico, no operan el carrito).
// KDS y pantalla de mozos (O2, context/24-orders-module-plan.md) comparten el
// mismo pairing/heartbeat que el checkout screen — solo cambia el canal WS al
// que se suscriben en el front (`{companyId}:kds:{outletId}` en vez de
// `{companyId}:checkout:{registerId}`). 'print' (Estación de Impresión, P0,
// context/26-print-station-plan.md) se suma acá con el mismo criterio: la
// estación es un device pareado que necesita heartbeat/context genérico —
// su canal propio es `{companyId}:print:{outletId}` (api/v1/print-jobs.php).
const DISPLAY_MODULES = ['screen', 'kds', 'display', 'print'];

$resource = $_GET['resource'] ?? null;
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;

// ── POST ?resource=heartbeat — auth device Bearer (module pantalla) ───────────

if ($method === 'POST' && $resource === 'heartbeat') {
    $ctx = apiAuthPosContext();
    if (!in_array($ctx['module'] ?? 'pos', DISPLAY_MODULES, true)) {
        apiError('Heartbeat solo para pantallas cliente', 403);
    }
    // apiAuthPosContext ya actualiza lastSeenAt + iplast en la fila device (DeviceAuth::resolveDeviceToken)
    apiOk(['ok' => true]);
    exit;
}

// ── GET ?resource=context — auth device Bearer (module pantalla) ─────────────
// Devuelve datos para que la pantalla cliente arme su UI sin esperar al
// primer cart-update del POS: companyName, registerName, outletName, logoUrl
// y la config de LOCALE del tenant (moneda/país/separadores/decimales).
//
// El locale entró acá el 2026-08-25: la pantalla del cliente formateaba los
// montos con `Intl.NumberFormat("es-PY", { currency: "PYG" })` hardcodeado,
// así que el cliente de un comercio NO paraguayo veía su total en guaraníes.
// Es la superficie más visible del sistema — la mira el consumidor final, no
// el cajero — y no falla ruidosamente: simplemente muestra el símbolo
// equivocado. La pantalla no tiene bootstrap propio (se autentica con el
// Bearer del device, no con el del operador), así que el dato tiene que
// viajar por acá; los nombres de campo son los MISMOS que expone
// `/v1/bootstrap` para que el front use un solo resolver (lib/tenant-locale.ts).

if ($method === 'GET' && $resource === 'context') {
    $ctx = apiAuthPosContext();
    if (!in_array($ctx['module'] ?? 'pos', DISPLAY_MODULES, true)) {
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
        // companyId/outletId: fuente de verdad para que KDS/display armen su
        // canal WS (`{companyId}:kds:{outletId}`) sin depender de que el
        // pairing haya persistido esos IDs en localStorage — la fila `device`
        // ya los tiene, evita duplicar estado (O2, context/24-orders-module-plan.md).
        'companyId'    => (string) $ctx['companyId'],
        'outletId'     => (string) $ctx['outletId'],
        'companyName'  => (string) ($company['settingName'] ?? ''),
        'registerName' => (string) ($reg['registername'] ?? ''),
        'outletName'   => (string) ($out['outletname'] ?? ''),
        'logoUrl'      => $logo,
        // Locale del tenant — mismo shape/nombres que `/v1/bootstrap` para que
        // la pantalla reuse los resolvers del front sin traducir campos.
        // Se mandan crudos (string vacío si el tenant no los configuró): la
        // cadena de fallbacks —moneda configurada → moneda del país → signo
        // genérico— vive en `frontend/lib/tenant-locale.ts`, en UN solo lugar.
        // Poner un default acá volvería a esconder la falta de configuración.
        'currency'     => (string) ($company['settingCurrency'] ?? ''),
        'decimal'      => (string) ($company['settingDecimal'] ?? 'no'),
        'thousand'     => ((string) ($company['settingThousandSeparator'] ?? '')) === 'comma' ? 'comma' : 'dot',
        'country'      => (string) ($company['settingCountry'] ?? ''),
        'timezone'     => (string) ($company['settingTimeZone'] ?? ''),
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

    // qr-show/qr-hide: QR de pago (Bancard) en la pantalla del cliente. El
    // payload lleva SOLO lo que la pantalla tiene que pintar (payload del QR,
    // monto, etiqueta) — nunca credenciales ni la respuesta cruda del PSP.
    $validTypes = ['cart-update', 'sale-confirmed', 'cart-cleared', 'idle', 'qr-show', 'qr-hide'];
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
