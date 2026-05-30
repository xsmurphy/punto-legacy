<?php
declare(strict_types=1);

/**
 * /api/v1/sales.php — guardado de ventas (API compartida del sistema).
 *
 *   POST  data[]={...payload del front...}
 *     → guarda la venta y devuelve { success, transactionId, uid, duplicated }
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 *
 * Strangler-fig de `app/action.php?action=processData` — ver SaleService.
 *
 * Estilo: convención §22.9 — DTOs + excepciones custom + DI explícita.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;

$authCtx = apiAuthTenant();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

// El front manda el payload completo como JSON dentro de `data[]` (idéntico al
// legacy processData, para que la cola offline pueda enrutar sin reformatear).
$rawData = $_POST['data'] ?? null;
if (is_array($rawData)) {
    $rawData = $rawData[0] ?? null;
}

if (!is_string($rawData) || $rawData === '') {
    apiError('Falta el payload data[]', 422);
}

$decoded = json_decode($rawData, true);
if (!is_array($decoded)) {
    apiError('Payload data[] no es JSON válido', 422);
}

try {
    $input = SaleInput::fromPayload($decoded);
} catch (InvalidSaleInputException $e) {
    apiError($e->getMessage(), 422);
}

/** @var DB $db */
global $db; // proveído por bootstrap → head.php; pasamos por DI al servicio.

$service = new SaleService(
    ctx: TenantContext::fromAuth($authCtx),
    db:  $db,
);

try {
    $result = $service->save($input);
} catch (DuplicateSaleException $e) {
    // 200 con duplicated=true — el front debe marcar el UID como sincronizado.
    apiOk([
        'success'    => true,
        'duplicated' => true,
        'uid'        => $e->uid,
        'message'    => 'Duplicated Entry',
    ]);
} catch (InvalidSaleInputException $e) {
    // Validaciones del servicio (ej: clientId no pertenece al tenant) → 422.
    apiError($e->getMessage(), 422);
} catch (SaleAbortedException $e) {
    apiError($e->dbError ?? 'Sale transaction aborted', 500);
}

apiOk($result->toApiPayload());
