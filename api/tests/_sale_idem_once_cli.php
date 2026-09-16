<?php
declare(strict_types=1);

/**
 * Helper de `sale_idempotency_test.php` — UN `SaleService::save()` en un
 * subproceso propio, para el tenant "Verify PY" de `verify_chain/seed.sql`.
 *
 * Por qué subproceso: la carrera del mismo uid necesita DOS procesos PHP con
 * DOS conexiones de Postgres de verdad (dentro de un mismo proceso no hay
 * concurrencia que probar), y `data.php` define COMPANY_ID/OUTLET_ID como
 * constantes de proceso.
 *
 * Uso: php _sale_idem_once_cli.php <payloadJson>
 * Imprime UNA línea `RESULT:{json}` con
 *   { kind: created|duplicate|invoice_taken|aborted|invalid|db_error, transactionId? }
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Support\DbQueryException;

$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

$payload = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($payload)) {
    echo 'RESULT:' . json_encode(['kind' => 'invalid', 'message' => 'payload ilegible']) . "\n";
    exit(0);
}

$service = new SaleService(
    TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId')),
    $db,
);

try {
    $result = $service->save(SaleInput::fromPayload($payload, $companyId));
    $out = ['kind' => 'created', 'transactionId' => $result->transactionId];
} catch (DuplicateSaleException $e) {
    $out = ['kind' => 'duplicate', 'transactionId' => $e->existing->transactionId];
} catch (DuplicateInvoiceNumberException $e) {
    $out = ['kind' => 'invoice_taken'];
} catch (SaleAbortedException $e) {
    $out = ['kind' => 'aborted', 'dbError' => $e->dbError];
} catch (InvalidSaleInputException $e) {
    $out = ['kind' => 'invalid', 'message' => $e->getMessage()];
} catch (DbQueryException $e) {
    $out = ['kind' => 'db_error', 'message' => $e->getMessage()];
}

echo 'RESULT:' . json_encode($out) . "\n";
