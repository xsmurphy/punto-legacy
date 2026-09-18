<?php
declare(strict_types=1);

/**
 * Helper de `wallet_test.php` — UN pago con saldo dentro de su propia
 * transacción, en un proceso (y una conexión a Postgres) propios.
 *
 * Existe para probar la carrera real de context/74 §5: dos cajas que leen
 * "quedan 5.000" y aprueban 5.000 cada una. Dentro de un solo proceso PHP no
 * se puede: hay una sola conexión.
 *
 * Mantiene la transacción abierta `holdSeconds` DESPUÉS del débito — como la
 * venta de F2, que debita y sigue escribiendo el resto antes de confirmar —
 * para que la otra caja llegue mientras este débito todavía no está
 * commiteado.
 *
 * Uso: php _wallet_spend_once_cli.php <companyId> <contactId> <pocketId> <amount> <actorId> <holdSeconds>
 * Imprime `spent` + `committed`, o `insufficient available=<n>`.
 */

$companyId = $argv[1];
$contactId = $argv[2];
$pocketId  = $argv[3];
$amount    = (float) $argv[4];
$actorId   = $argv[5];
$hold      = (int) $argv[6];

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Wallet\WalletInsufficientFundsException;
use Punto\Api\Wallet\WalletService;

global $db;
$db->StartTrans();
try {
    (new WalletService())->spend($companyId, $contactId, $pocketId, $amount, 'harness', null, $actorId);
    echo "spent\n";
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
