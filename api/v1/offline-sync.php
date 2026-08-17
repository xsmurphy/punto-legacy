<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;

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
    $tempId      = $item['clientTempId']     ?? '';
    $no          = (int) ($item['leasedInvoiceNo'] ?? 0);
    $salePayload = $item['sale']             ?? [];

    // Validate lease is active and unconsumed — mismo chequeo de siempre
    // (TTL propio del bloque de 24h, D1 de context/37).
    $leaseRow = ncmExecute(
        'SELECT nl."leaseId", nl."registerLeaseId", nl."voidedAt",
                rl."status" AS "registerLeaseStatus", rl."deviceId" AS "registerLeaseDeviceId"
           FROM "numbering_lease" nl
           LEFT JOIN "register_lease" rl ON rl."registerLeaseId" = nl."registerLeaseId"
          WHERE nl."invoiceNo" = ? AND nl."registerId" = ? AND nl."companyId" = ?
            AND nl."consumedAt" IS NULL AND nl."expiresAt" > NOW()
          LIMIT 1',
        [$no, $regId, $compId]
    );

    if ($leaseRow === false || $leaseRow === 0) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'LEASE_EXPIRED',
                'message' => 'Número de comprobante vencido o ya usado',
            ],
        ];
        continue;
    }

    // F3 (context/29 §5.4) — exclusividad de caja atada al dispositivo. El
    // número existe y no venció por su propio TTL, pero la TENENCIA de caja
    // bajo la que se arrendó tiene que seguir activa y ser del MISMO
    // dispositivo que está sincronizando. Si la caja se liberó/venció/forzó
    // mientras este device estaba offline, `RegisterLeaseService::close()`
    // (F2) ya anuló este número en la transición (`voidedAt`/`voidReason`) —
    // el device no tenía forma de enterarse hasta este momento porque no
    // tenía red. §53: el backend no rechaza una venta ya EMITIDA por reglas
    // de negocio del POS, pero numeración exclusiva es ESTADO COMPARTIDO
    // (§53, distinción explícita) — acá sí corresponde bloquear, por venta,
    // sin tumbar el resto del lote.
    //
    // registerLeaseId NULL (fila legacy sin tenencia asignada — de antes de
    // F2, o del breve gap entre el deploy de F0/F1 y el de F2) se trata igual
    // que "sin tenencia válida": no hay forma de confirmar de quién es ese
    // número, mismo criterio fail-closed que F1 usó para no inventar un
    // tenedor (context/29 §6 punto 2).
    $registerLeaseId = $leaseRow['registerLeaseId'] ?? null;
    $voidedAt        = $leaseRow['voidedAt'] ?? null;
    $tenancyValid = $registerLeaseId !== null && $registerLeaseId !== ''
        && ($voidedAt === null || $voidedAt === '')
        && (string) ($leaseRow['registerLeaseStatus'] ?? '') === 'active'
        && (string) ($leaseRow['registerLeaseDeviceId'] ?? '') === $deviceId;

    if (!$tenancyValid) {
        $results[] = [
            'clientTempId' => $tempId,
            'ok'           => false,
            'error'        => [
                'code'    => 'LEASE_REVOKED',
                'message' => 'La caja fue liberada, tomada por otro dispositivo, o venció mientras esta venta esperaba conexión. El número de comprobante quedó anulado.',
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
    }

    // Mark lease as consumed
    ncmExecute(
        'UPDATE "numbering_lease" SET "consumedAt" = NOW() WHERE "invoiceNo" = ? AND "registerId" = ? AND "companyId" = ?',
        [$no, $regId, $compId]
    );

    $results[] = [
        'clientTempId'  => $tempId,
        'ok'            => true,
        'transactionId' => $result->transactionId,
    ];
}

apiOk(['results' => $results]);
