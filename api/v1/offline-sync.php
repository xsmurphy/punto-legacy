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

    // Exclusividad de caja (context/29 §4) — se lee la tenencia REAL de la
    // caja ahora mismo, contra `register_lease` DIRECTO (ya no contra
    // `numbering_lease`: el arriendo de números que ataba cada bloque a una
    // tenencia fue RECHAZADO por el owner 2026-08-17, ver docblock de
    // `RegisterLeaseService`). Qué se hace con el resultado NO es lo mismo que
    // en el camino online — ver "DRENAR ≠ VENDER" más abajo: acá la venta ya
    // está emitida e impresa, y solo bloquea el caso en que otro dispositivo
    // esté emitiendo contra la misma rama de numeración. Por venta, sin
    // tumbar el resto del lote.
    //
    // El chequeo va en su propio try: `holderConflict()` LEE de BD y, desde
    // que el wrapper lanza `DbQueryException`, un error de SQL acá tumbaba el
    // LOTE ENTERO con 500 sobre ventas YA EMITIDAS E IMPRESAS en el device
    // (viola offline-first, context/08 §53). Antes de que el wrapper lanzara,
    // el mismo error devolvía `false`/vacío y el ítem salía como
    // REGISTER_NOT_HELD sin arrastrar al resto. Se conserva ese
    // comportamiento: falla SOLO este ítem, con SERVER_ERROR (no
    // REGISTER_NOT_HELD — no sabemos si la caja está tomada, no pudimos leer)
    // y el lote sigue.
    try {
        $conflict = RegisterLeaseService::holderConflict($regId, $compId, $deviceId);
    } catch (DbQueryException $e) {
        error_log('[offline-sync] holderConflict falló para ' . $tempId . ': ' . $e->getMessage()
            . ' | SQLSTATE ' . $e->sqlState() . ' | SQL: ' . $e->sql());
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'SERVER_ERROR',
                'message' => 'No se pudo verificar la caja. La venta sigue en la cola local.',
            ],
        ];
        continue;
    }
    // DRENAR ≠ VENDER (owner, 2026-09-09)
    // ───────────────────────────────────
    // Hasta hoy CUALQUIER conflicto frenaba la venta encolada, y eso obligaba
    // al device a RE-TOMAR la caja antes de drenar. Ese requisito es lo que
    // convertía a `ensureTenancy()` en el único camino automático que adquiría
    // — y por ahí se colaba el bug del owner: la tablet se apropiaba de la caja
    // sola en cada ciclo de sync, aunque el admin la acabara de liberar.
    //
    // La distinción correcta es entre EMITIR y SUBIR LO YA EMITIDO:
    //
    //   - `taken_by_other` — OTRO device tiene la caja AHORA y está emitiendo
    //     contra la misma rama de numeración. Sigue siendo terminal: acá sí hay
    //     estado compartido en disputa (la distinción explícita de §53).
    //   - `revoked` / `released` / `never_held` — la caja está LIBRE. No hay
    //     nadie emitiendo con quien chocar, así que exigir tenencia no compra
    //     nada: la venta ya está EMITIDA e IMPRESA y el cliente se fue con el
    //     comprobante. Rechazarla sería repudiar un documento entregado, que es
    //     exactamente lo que §53 prohíbe.
    //
    // Lo que protege el correlativo NO es este chequeo, es
    // `uq_transaction_expedition_invoiceno` (mig 145): si el número ya se usó,
    // el INSERT de abajo falla con NUMBER_TAKEN. Ese índice es el invariante
    // real y sigue intacto — este chequeo era una segunda vuelta de llave que
    // costaba una re-adquisición de caja.
    //
    // Consecuencia buscada: un device VETADO por el admin (ver
    // `RegisterLeaseService::isAdminRevoked()`) puede terminar de subir lo que
    // emitió, pero NO puede tomar la caja ni emitir nada nuevo — que es
    // literalmente lo que pidió el owner.
    if ($conflict !== null && ($conflict['reason'] ?? '') === 'taken_by_other') {
        // Ver `RegisterLeaseService::conflictMessage()`: `REGISTER_TAKEN` es el
        // único terminal, y el mensaje nombra al dispositivo que la tiene.
        [$code, $message] = RegisterLeaseService::conflictMessage($conflict);
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => $code,
                'message' => $message,
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
        $input = SaleInput::fromPayload($decoded, (string) $authCtx['companyId']);
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
    //
    // NO hay gate de timbrado vencido acá, a diferencia de `/v1/sales.php`, y
    // es deliberado: esta venta YA SE EMITIÓ — el ticket salió de la impresora
    // y está en la mano del cliente. El backend nunca rechaza una venta ya
    // emitida (context/08 §53): un 422 acá no des-emitiría nada, solo trabaría
    // la cola del device y escondería el problema.
    //
    // Lo que sí pasa: `SaleService::save()` compara el timbrado congelado
    // contra la fecha de la operación y, si estaba vencido, deja la venta
    // MARCADA (`meta.invoiceAuthExpiredAtEmission`). Ver `InvoiceAuthGate`.
    //
    // Ojo con el caso legítimo que esto protege: una venta cobrada a las 22:00
    // del último día de vigencia que sincroniza al día siguiente NO está
    // vencida — se compara contra la fecha de la OPERACIÓN, no contra hoy — y
    // por lo tanto no se marca. La marca queda solo para el reloj de device
    // corrido o la config vieja, que es lo que el POS ya debería haber
    // bloqueado localmente antes de imprimir (`lib/pos/emission-block.ts`).
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
        // El texto crudo de PG se usa SOLO para clasificar (server-side) y para
        // el log; lo que viaja al device es un mensaje genérico. Devolverlo
        // filtraba tablas, columnas y constraints del schema a cualquiera que
        // mire la cola de sync.
        error_log('[offline-sync] venta abortada ' . $tempId . ': ' . ($e->dbError ?? $e->getMessage()));
        $isStock = $e->isStockFailure();
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => $isStock ? 'STOCK_OUT' : 'SERVER_ERROR',
                'message' => $isStock
                    ? 'Stock insuficiente para uno o más ítems de la venta.'
                    : $e->clientMessage(),
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
        // Serie CONGELADA en la venta, no la vigente de la caja (mig 209).
        // Acá importa el doble: una venta encolada offline puede llegar días
        // después de que el panel haya cambiado el punto de expedición, y
        // avanzar la serie nueva con el número de la vieja la dejaría con un
        // correlativo que nunca emitió.
        DocumentNumber::advanceTo(
            'factura',
            DocumentNumber::SCOPE_REGISTER,
            $regId,
            $compId,
            $no,
            \Punto\Api\Documents\DocumentSeries::forTransaction($result->transactionId, $compId),
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
