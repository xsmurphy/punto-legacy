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
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Sales\SaleType;

// MULTI-REALM (A7, 2026-06-16): la caja vive dentro de frontend y vende con
// la sesión del panel (`_jwt_panel`), no con un realm pos-app aparte. El
// `registerId` (caja activa) viene del claim `rid` del JWT — lo setea
// /v1/active-register tras validar la caja. TODO (F2): gatear "puede vender"
// por permiso RBAC cuando exista el modelo de cajero.
require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';
$authCtx = apiAuthPosContext();
if (($authCtx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}

// A7 (P0 code-review): sin caja activa (rid=''), NO se puede vender — una
// venta sin registerId quedaría huérfana de numeración fiscal. El front
// fuerza el selector de caja, pero el guard es server-side (no confiar en él).
if (($authCtx['registerId'] ?? '') === '') {
    apiError('Seleccioná una caja antes de vender', 403);
}

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

// Cotización (type=9): path separado sin payment/stock/caja
$rawType = isset($decoded['type']) ? (int) $decoded['type']
    : (isset($decoded['transaction']['type']) ? (int) $decoded['transaction']['type'] : -1);

if ($rawType === SaleType::Quote->value) {
    try {
        $input = SaleInput::fromQuotePayload($decoded);
    } catch (InvalidSaleInputException $e) {
        apiError($e->getMessage(), 422);
    }

    $service = new SaleService(
        ctx: TenantContext::fromAuth($authCtx),
        db:  $db,
    );

    try {
        $quoteResult = $service->saveQuote($input);
    } catch (InvalidSaleInputException $e) {
        apiError($e->getMessage(), 422);
    } catch (SaleAbortedException $e) {
        // El texto de PG va al log, NUNCA a la respuesta (filtra el schema).
        error_log('[sales] cotización abortada: ' . ($e->dbError ?? $e->getMessage()));
        apiError($e->clientMessage(), 500);
    }

    apiOk($quoteResult);
}

try {
    $input = SaleInput::fromPayload($decoded);
} catch (InvalidSaleInputException $e) {
    apiError($e->getMessage(), 422);
}

/** @var DB $db */
global $db; // proveído por bootstrap → head.php; pasamos por DI al servicio.

$regId    = (string) $authCtx['registerId'];
$compId   = (string) $authCtx['companyId'];
$deviceId = (string) ($authCtx['deviceId'] ?? '');

// Todo lo que llega hasta acá es venta contado/crédito (type∈{0,3} —
// SaleInput::fromPayload ya cortó cualquier otro type más arriba en
// assertSimplePathEligible), así que SIEMPRE necesita un invoiceNo real: el
// POS lo manda desde `getNextInvoiceNo()` en TODA venta (frontend/lib/pos/
// invoice-numbering.ts — "último correlativo de mi caja + 1", ver
// context/29-numeracion-y-exclusividad-de-caja.md). Un `invoiceNo` ausente
// acá es un cliente desactualizado (bundle viejo del device) o un bug —
// dejarlo pasar en silencio reabriría el P0 fiscal (persistir
// `invoiceNo = NULL`). No es una regla de negocio que el POS ya validó al
// emitir (§53): es integridad del payload, la misma categoría que "el
// clientId pertenece al tenant" — eso el backend SIEMPRE lo valida.
if ($input->invoiceNo === null) {
    apiError('Falta el número de comprobante — actualizá el POS e intentá de nuevo', 422);
}

// Exclusividad de caja (context/29 §4) aplicada al camino ONLINE — antes
// solo `offline-sync.php` validaba esto. El número que llega acá lo decidió
// el device LOCALMENTE (`getNextInvoiceNo()`, nunca `DocumentNumber::
// allocate()` server-side — el arriendo de números que antes reservaba
// bloques fue RECHAZADO por el owner 2026-08-17, ver docblock de
// `RegisterLeaseService`); lo único que puede duplicar un comprobante ahora
// es que DOS dispositivos operen la misma caja a la vez, y eso es
// exactamente lo que `holderConflict()` chequea contra `register_lease` —
// sin depender de ningún bookkeeping de números.
//
// §53 (context/08-convenciones-criticas.md): esto NO viola "el backend nunca
// rechaza una venta ya emitida" — en este punto el ticket todavía NO se
// imprimió (`runAutoPrint` en pay-dialog.tsx corre DESPUÉS de que este POST
// responde 200), así que no hay venta ya emitida que se pierda. La
// exclusividad de caja es ESTADO COMPARTIDO (distinción explícita de §53), y
// ahí sí corresponde bloquear — mismo 409 informativo que `claim.php` ya
// usa, para que el POS muestre quién tiene la caja tomada.
$conflict = \Punto\Api\Services\RegisterLeaseService::holderConflict($regId, $compId, $deviceId);
if ($conflict !== null) {
    // Mismo texto por causa que el sync offline — una sola fuente
    // (`RegisterLeaseService::conflictMessage()`), así el cajero lee lo mismo
    // le pase online al cobrar o al sincronizar una venta encolada.
    //
    // El código de causa viaja DENTRO de `details` (`conflictCode`), no en
    // `error.code`: ese campo es el status HTTP (409) que pone `apiConflict()`
    // para todos los conflictos del sistema, y pisarlo con un string rompería
    // a cualquier cliente que lo lea como número. Así los dos caminos —este
    // 409 y el resultado por-venta de `offline-sync.php`— exponen la misma
    // clasificación, y ninguno obliga a re-derivarla del texto.
    [$conflictCode, $conflictMessage] = \Punto\Api\Services\RegisterLeaseService::conflictMessage($conflict);
    apiConflict($conflictMessage, $conflict + ['conflictCode' => $conflictCode]);
}

$service = new SaleService(
    ctx: TenantContext::fromAuth($authCtx),
    db:  $db,
);

try {
    // Finanzas Fase 3: el hook a FinanceLedger::recordSale vive centralizado en
    // SaleService::save() (best-effort ahí también) — cubre esta ruta y
    // api/v1/offline-sync.php sin duplicar la llamada.
    $result = $service->save($input);
} catch (DuplicateInvoiceNumberException $e) {
    // mig 145 — choque REAL contra uq_transaction_expedition_invoiceno: el
    // número que el device calculó ("último correlativo de mi caja + 1") ya
    // estaba tomado por OTRO transactionUID. A diferencia de
    // DuplicateSaleException (reintento del MISMO evento, 200), esto es un
    // comprobante duplicado — apiConflict (409, mismo helper que ya usa
    // holderConflict arriba para "estado compartido") para que el POS lo
    // muestre como bloqueante en vez de tratarlo como una venta sincronizada.
    apiConflict($e->getMessage());
} catch (DuplicateSaleException $e) {
    // 200 con duplicated=true — el front debe marcar el UID como sincronizado.
    // NO se marca consumedAt acá: si esta es una venta que YA se guardó en
    // un intento previo (el mismo uid), ese intento previo ya lo marcó —
    // volver a marcarlo es un no-op sobre la misma fila (WHERE por
    // invoiceNo+registerId+companyId), nunca un doble-consumo de OTRO número.
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
    // El texto de PG va al log, NUNCA a la respuesta (filtra el schema).
    error_log('[sales] venta abortada: ' . ($e->dbError ?? $e->getMessage()));
    apiError($e->clientMessage(), 500);
}

// Venta guardada — mantener document_sequence consistente con el número que
// el device ya decidió y emitió. NUNCA decide el número acá (eso reabriría
// el caso rechazado por el owner: un `allocate()` server-side entregaría
// números por encima de lo que el device ya emitió offline) — solo se
// asegura de que la secuencia no quede atrás para el próximo peek()/panel.
if ($input->invoiceNo !== null) {
    // Best-effort: la venta YA está commiteada y el comprobante YA se imprimió.
    // Un fallo de BD acá (adelantar el correlativo del panel) no puede hacer
    // fallar una venta emitida — se loguea y la respuesta sale igual. Mismo
    // criterio que rollupMarkDirty y que el camino offline (offline-sync.php).
    try {
        \Punto\Api\Documents\DocumentNumber::advanceTo(
            'factura',
            \Punto\Api\Documents\DocumentNumber::SCOPE_REGISTER,
            $regId,
            $compId,
            $input->invoiceNo,
        );
    } catch (\Throwable $e) {
        error_log('[sales] advanceTo falló (venta ya persistida): ' . $e->getMessage());
    }
}

apiOk($result->toApiPayload());
