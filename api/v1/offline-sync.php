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

$authCtx = apiAuthPosContext();
$regId   = $authCtx['registerId'];
$compId  = $authCtx['companyId'];

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

    // Validate lease is active and unconsumed
    $leaseRow = ncmExecute(
        'SELECT "leaseId" FROM "numbering_lease" WHERE "invoiceNo" = ? AND "registerId" = ? AND "companyId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW() LIMIT 1',
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
