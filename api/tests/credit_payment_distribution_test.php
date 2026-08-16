<?php
declare(strict_types=1);

/**
 * Test standalone del reparto FIFO de `CreditPaymentService::distributeFifo()`
 * (modo "monto libre" del cobro/pago de crédito — context/41 payment
 * feature, 2026-08). El proyecto no tiene PHPUnit instalado; este script
 * corre solo, sin DB ni bootstrap completo (el método bajo test es puro),
 * y sale con código != 0 si algo falla — así sirve tanto para correr a mano
 * como para engancharlo a un CI futuro.
 *
 * Correr: php api/tests/credit_payment_distribution_test.php
 */

require_once __DIR__ . '/../lib/services/CreditPaymentService.php';

use Punto\Api\Services\CreditPaymentService;

$failures = 0;

/**
 * @param array<string,float> $expected
 * @param array<string,float> $actual
 */
function assertSameAllocations(string $label, array $expected, array $actual, int &$failures): void
{
    $ok = array_keys($expected) === array_keys($actual);
    if ($ok) {
        foreach ($expected as $k => $v) {
            if (abs($v - $actual[$k]) > 0.001) {
                $ok = false;
                break;
            }
        }
    }
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n";
    echo "     esperado: " . json_encode($expected) . "\n";
    echo "     obtenido: " . json_encode($actual) . "\n";
}

// ── Caso del owner: deuda de 3 facturas (100, 200, 300), pago de 250 ──────
// → la más vieja (A) se salda completa, la segunda (B) queda parcial en 150
// imputados (de 200 → sobran 50 sin pagar), la tercera (C) no se toca.
$result = CreditPaymentService::distributeFifo(
    ['A' => 100.0, 'B' => 200.0, 'C' => 300.0],
    250.0
);
assertSameAllocations(
    'deuda 100/200/300, pago 250 → A completa, B parcial 150, C intacta',
    ['A' => 100.0, 'B' => 150.0],
    $result,
    $failures
);

// ── Monto exacto para pagar TODO ("pagar todas") ──────────────────────────
$result = CreditPaymentService::distributeFifo(
    ['A' => 100.0, 'B' => 200.0, 'C' => 300.0],
    600.0
);
assertSameAllocations(
    'deuda 100/200/300, pago 600 (total exacto) → las 3 completas',
    ['A' => 100.0, 'B' => 200.0, 'C' => 300.0],
    $result,
    $failures
);

// ── Monto que solo alcanza para la primera ────────────────────────────────
$result = CreditPaymentService::distributeFifo(
    ['A' => 100.0, 'B' => 200.0, 'C' => 300.0],
    40.0
);
assertSameAllocations(
    'deuda 100/200/300, pago 40 → solo A, parcial',
    ['A' => 40.0],
    $result,
    $failures
);

// ── Una sola factura ("pagar una puntual") ────────────────────────────────
$result = CreditPaymentService::distributeFifo(['A' => 500.0], 500.0);
assertSameAllocations('deuda única 500, pago 500 → completa', ['A' => 500.0], $result, $failures);

// ── Remanente exacto en el límite de redondeo no genera allocation fantasma ──
$result = CreditPaymentService::distributeFifo(['A' => 100.0, 'B' => 100.0], 100.0);
assertSameAllocations('deuda 100/100, pago 100 → solo A, B no aparece', ['A' => 100.0], $result, $failures);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
