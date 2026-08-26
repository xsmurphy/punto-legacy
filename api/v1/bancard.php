<?php
/**
 * /api/v1/bancard.php — QR de pago Bancard (Slice 43, strangler de load=bancardQR).
 *
 *   POST { type:'create', QRAmount, saleAmount, UID[, comission, tax] }
 *        → crea un QR. Devuelve JSON crudo de Bancard.
 *   POST { type:'refresh', id }
 *        → refresca un QR existente. Devuelve JSON crudo.
 *   POST { type:'cancel', id }
 *        → cancela un QR. Devuelve JSON crudo.
 *
 * Auth: JWT de tenant. PATH DE DINERO — port fiel del handler legacy.
 * La respuesta se pasa cruda al front (sin envolver en { ok, data }) porque
 * el front la consume directamente (ncmPayments.ePOS.qr.render(data)).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/BancardService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\BancardService;

$ctx = apiAuthTenant();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc  = new BancardService(TenantContext::fromAuth($ctx));
$type = (string) ($_POST['type'] ?? '');

if ($type === 'create') {
    $amount    = (float)  ($_POST['QRAmount']   ?? 0);
    $sale      = (float)  ($_POST['saleAmount'] ?? 0);
    $uid       = (string) ($_POST['UID']        ?? '');
    $comission = isset($_POST['comission']) ? (float) $_POST['comission'] : null;
    $tax       = isset($_POST['tax'])       ? (float) $_POST['tax']       : null;

    if ($amount <= 0 || $uid === '') {
        apiError('QRAmount y UID son obligatorios', 422);
    }

    $raw = $svc->createQR($amount, $sale, $uid, $comission, $tax);
    apiOk(json_decode($raw, true) ?: ['raw' => $raw]);
}

/**
 * Guard de pertenencia del QR (aislamiento cross-tenant, path de dinero).
 *
 * refresh/cancel operan sobre un `id` que viene del body con el token GLOBAL de
 * Bancard: sin esto, un tenant refrescaba/cancelaba el cobro de OTRO comercio
 * con solo conocer el id (auditoría 2026-08-26). El binding id→tenant se
 * persiste al crear el QR (mig 174 / BancardService::persistOwnership). Fail-open
 * ante id desconocido a propósito: no rompemos QRs creados antes de la mig ni un
 * flujo legítimo si no capturamos la clave del id — solo bloqueamos lo que
 * sabemos que es de otro tenant. 404 (no 403) para no delatar existencia.
 */
$assertQrOwned = static function (string $id) use ($svc, $ctx): void {
    $owner = $svc->ownerCompanyOf($id);
    if ($owner !== null && $owner !== (string) $ctx['companyId']) {
        apiError('QR no encontrado', 404);
    }
};

if ($type === 'refresh') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        apiError('Falta id', 422);
    }
    $assertQrOwned($id);
    $raw = $svc->refreshQR($id);
    apiOk(json_decode($raw, true) ?: ['raw' => $raw]);
}

if ($type === 'cancel') {
    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id === '') {
        apiError('Falta id', 422);
    }
    $assertQrOwned($id);
    $raw = $svc->cancelQR($id);
    apiOk(json_decode($raw, true) ?: ['raw' => $raw]);
}

apiError('type debe ser create|refresh|cancel', 422);
