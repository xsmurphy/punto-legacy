<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Support\DbQueryException;
use Punto\Api\Services\RegisterLeaseService;

require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$authCtx  = apiAuthPosContext();
$regId    = $authCtx['registerId'];
$compId   = $authCtx['companyId'];
$deviceId = (string) ($authCtx['deviceId'] ?? '');

if (($regId ?? '') === '') {
    apiError('Seleccioná una caja antes de operar', 403);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$sales = $body['sales'] ?? [];

if (!is_array($sales) || count($sales) === 0) {
    apiError('Falta sales[]', 422);
}

$results = [];

foreach ($sales as $item) {
    $tempId      = $item['clientTempId'] ?? '';
    $no          = (int) ($item['invoiceNo'] ?? 0);
    $salePayload = $item['sale']          ?? [];

    if ($no < 1) {
        // Cliente desactualizado (bundle viejo, antes de este cambio) o
        // payload corrupto — sin invoiceNo no hay documento fiscal válido
        // que guardar (mismo gate que sales.php, ver context/29 §5).
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'INVALID_INPUT',
                'message' => 'Falta el número de comprobante',
            ],
        ];
        continue;
    }

    // Exclusividad de caja (context/29 §4) — el device tiene que SEGUIR
    // siendo el tenedor de la caja para que el número que emitió offline sea
    // legítimo. Si la caja se liberó, la tomó otro dispositivo, o se forzó
    // mientras este estaba offline, sincronizar esta venta arriesgaría
    // duplicar un correlativo que el tenedor real ya haya emitido — mismo
    // chequeo que el camino online (`sales.php`) ya aplica antes de guardar,
    // ahora contra `register_lease` DIRECTO (ya no contra `numbering_lease`
    // — el arriendo de números que ataba cada bloque a una tenencia fue
    // RECHAZADO por el owner 2026-08-17, ver docblock de
    // `RegisterLeaseService`). §53: el backend no rechaza una venta ya
    // EMITIDA por reglas de negocio del POS, pero la exclusividad de caja es
    // ESTADO COMPARTIDO (distinción explícita de §53) — acá sí corresponde
    // bloquear, por venta, sin tumbar el resto del lote.
    $conflict = RegisterLeaseService::holderConflict($regId, $compId, $deviceId);
    if ($conflict !== null) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'REGISTER_NOT_HELD',
                'message' => 'La caja fue liberada, tomada por otro dispositivo, o cerrada mientras esta venta esperaba conexión.',
                'details' => $conflict,
            ],
        ];
        continue;
    }

    // Inject invoiceNo into payload
    $decoded = is_array($salePayload) ? $salePayload : [];
    $decoded['invoiceno'] = $no;
    if (isset($decoded['transaction']) && is_array($decoded['transaction'])) {
        $decoded['transaction']['invoiceno'] = $no;
    }

    // Parse sale input
    try {
        $input = SaleInput::fromPayload($decoded);
    } catch (InvalidSaleInputException $e) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'INVALID_INPUT',
                'message' => $e->getMessage(),
            ],
        ];
        continue;
    }

    // Process sale
    global $db;
    $service = new SaleService(ctx: TenantContext::fromAuth($authCtx), db: $db);

    try {
        $result = $service->save($input);
    } catch (DuplicateInvoiceNumberException $e) {
        // mig 145 — choque REAL contra uq_transaction_expedition_invoiceno,
        // NO un reintento del mismo uid (eso es DuplicateSaleException, más
        // abajo, y sigue devolviendo ok=true). §53 (context/08 §53): esto es
        // "estado compartido" (numeración exclusiva), la misma excepción que
        // ya justifica bloquear REGISTER_NOT_HELD aunque la venta ya se haya
        // emitido/impreso en el device — no evapora la venta: queda con
        // ok=false por-item, sin tumbar el resto del lote, y el front la deja
        // en la cola local (IndexedDB) con status 'failed' para revisión
        // manual del operador.
        //
        // code 'NUMBER_TAKEN': slot YA RESERVADO en
        // frontend/components/pos/sync-queue-dialog.tsx (PERMANENT_ERROR_CODES),
        // sin otro caller en el repo — este es exactamente el caso para el
        // que existía. Reusarlo (no inventar DUPLICATE_INVOICE_NUMBER) hace
        // que el diálogo lo trate como error PERMANENTE (sin botón
        // "Reintentar" — reintentar el mismo payload vuelve a chocar
        // siempre) sin tocar el front.
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'NUMBER_TAKEN',
                'message' => $e->getMessage(),
            ],
        ];
        continue;
    } catch (DuplicateSaleException $e) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => true,
            'transactionId' => $e->uid,
            'duplicated'   => true,
        ];
        continue;
    } catch (InvalidSaleInputException $e) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'INVALID_INPUT',
                'message' => $e->getMessage(),
            ],
        ];
        continue;
    } catch (SaleAbortedException $e) {
        $msg  = $e->dbError ?? $e->getMessage() ?? 'Sale aborted';
        $code = (stripos($msg, 'stock') !== false) ? 'STOCK_OUT' : 'SERVER_ERROR';
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => $code,
                'message' => $msg,
            ],
        ];
        continue;
    } catch (DbQueryException $e) {
        // Error de SQL fuera del bloque de escritura de SaleService::save()
        // (los pre-checks previos al StartTrans: dupli check, lectura de
        // impuestos, resolución de ítems). El bloque de escritura ya los
        // traduce a SaleAbortedException, pero acá se cubre el resto: sin este
        // catch, UNA venta con un problema de BD tumbaría el LOTE ENTERO del
        // sync offline y las demás ventas —ya emitidas e impresas en el
        // device— quedarían sin subir. Falla solo este ítem; el front lo deja
        // en la cola local para revisión. El mensaje de PG no se devuelve al
        // cliente (filtra el schema): va al log.
        error_log('[offline-sync] DbQueryException en ' . $tempId . ': ' . $e->getMessage()
            . ' | SQLSTATE ' . $e->sqlState() . ' | SQL: ' . $e->sql());
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'SERVER_ERROR',
                'message' => 'Error al procesar la operación',
            ],
        ];
        continue;
    }

    // Mantener document_sequence consistente con el número que el device ya
    // emitió offline — mismo criterio que sales.php en el camino online (ver
    // docblock de DocumentNumber::advanceTo()).
    // Best-effort: la venta YA está commiteada y el device YA imprimió el
    // comprobante. Un fallo de BD acá (avanzar el correlativo del panel para
    // que no reuse un número que el POS ya gastó) NO puede volver ok=false una
    // venta emitida — se loguea y sigue. Mismo criterio que rollupMarkDirty.
    try {
        DocumentNumber::advanceTo(
            'factura',
            DocumentNumber::SCOPE_REGISTER,
            $regId,
            $compId,
            $no,
        );
    } catch (\Throwable $e) {
        error_log('[offline-sync] advanceTo falló para ' . $tempId . ' (venta ya persistida): ' . $e->getMessage());
    }

    $results[] = [
        'clientTempId'  => $tempId,
        'ok'            => true,
        'transactionId' => $result->transactionId,
    ];
}

apiOk(['results' => $results]);
