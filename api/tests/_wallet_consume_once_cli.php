<?php
declare(strict_types=1);

/**
 * Helper de `wallet_pos_test.php` — UN consumo con saldo COMPLETO (el
 * comprobante de `SaleService`, no solo el débito) en su propio proceso y su
 * propia conexión a Postgres.
 *
 * Prueba la carrera de §5 por el camino real de la caja: dos cajas cobran con
 * saldo el mismo bolsillo a la vez. Mantiene la transacción abierta
 * `holdSeconds` DESPUÉS de que `save()` terminó (anidado en una transacción
 * externa), para que la otra caja llegue mientras el débito no está commiteado.
 *
 * Uso: php _wallet_consume_once_cli.php <uid> <pocketId> <clientId> <itemId> <amount> <holdSeconds>
 * Imprime `consumed <transactionId>` + `committed`, o `insufficient available=<n>`.
 */

$uid      = $argv[1];
$pocketId = $argv[2];
$clientId = $argv[3];
$itemId   = $argv[4];
$amount   = (float) $argv[5];
$hold     = (int) $argv[6];

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Wallet\WalletInsufficientFundsException;
use Punto\Api\Wallet\WalletService;

// Tenant fixture "Verify PY" (verify_chain/seed.sql) — mismo que el arnés.
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

global $db;

$pocket = (new WalletService())->findPocket($companyId, $pocketId);
$input  = SaleInput::forWalletConsumption([
    'uid'       => $uid,
    'client'    => $clientId,
    'sale'      => [[
        'itemId' => $itemId, 'count' => 1, 'name' => 'Carrera', 'uniPrice' => $amount, 'price' => $amount,
        'total' => $amount, 'tax' => 0, 'discount' => 0, 'totalDiscount' => 0, 'user' => '', 'type' => '',
        'date' => '', 'note' => '', 'currency' => '', 'uId' => 0,
    ]],
    'subtotal'  => $amount,
    'discount'  => 0,
    'timestamp' => time(),
    'date'      => date('Y-m-d H:i:s'),
], $companyId, $pocket, $userId);

$service = new SaleService(
    TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId')),
    $db
);

$db->StartTrans();
try {
    $result = $service->save($input);
    echo 'consumed ' . $result->transactionId . "\n";
} catch (WalletInsufficientFundsException $e) {
    echo 'insufficient available=' . $e->available . "\n";
    $db->FailTrans();
    $db->CompleteTrans();
    exit(0);
}
if ($hold > 0) {
    sleep($hold);
}
$ok = $db->CompleteTrans();
echo $ok ? "committed\n" : "rolledback\n";
