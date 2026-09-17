<?php
declare(strict_types=1);

/**
 * Helper de `replenishment_need_test.php` — UN movimiento de stock de salida
 * dentro de su propia transacción, en un proceso (y una conexión) propios.
 *
 * Existe para probar la carrera real: dos cajas vendiendo el mismo ítem a la
 * vez, cada una con su conexión a Postgres. Dentro de un solo proceso PHP no
 * se puede: hay una sola conexión y la segunda "caja" esperaría a la primera
 * para siempre.
 *
 * Mantiene la transacción abierta `holdSeconds` DESPUÉS del movimiento, para
 * que la otra caja llegue mientras la necesidad todavía no está commiteada.
 *
 * Uso: php _replenishment_move_once_cli.php <companyId> <outletId> <userId> <itemId> <qty> <holdSeconds>
 */

$companyId = $argv[1];
$outletId  = $argv[2];
$userId    = $argv[3];
$itemId    = $argv[4];
$qty       = (float) $argv[5];
$hold      = (int) $argv[6];

require_once dirname(__DIR__) . '/bootstrap.php';

$registerId = '';
$roleId     = '1';
require API_APP_DIR . '/data.php';

use Punto\App\Domain\Inventory;

global $db;
$db->StartTrans();
Inventory::manageStock([
    'itemId'        => $itemId,
    'outletId'      => $outletId,
    'date'          => date('Y-m-d H:i:s'),
    'locationId'    => null,
    'count'         => $qty,
    'type'          => '-',
    'source'        => 'sale',
    'transactionId' => null,
    'userId'        => $userId,
    'companyId'     => $companyId,
]);
echo "moved\n";
if ($hold > 0) {
    sleep($hold);
}
$ok = $db->CompleteTrans();
echo $ok ? "committed\n" : "rolledback\n";
