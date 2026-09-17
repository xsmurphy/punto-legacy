<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de cualquier otra cosa (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del normalizador de nombres de etapas de órdenes (`OrderStatusLabels`).
 *
 * Traducción pura, sin Postgres: es el único camino de entrada (Ajustes) y de
 * salida (Ajustes, bootstrap, contexto de pantallas), así que lo que cubre acá
 * es lo que ve cualquier superficie.
 */

require_once dirname(__DIR__) . '/lib/Orders/OrderStatusLabels.php';

use Punto\Api\Orders\OrderStatusLabels;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label — $detail\n";
}

$r = OrderStatusLabels::normalize(['sent' => '  En   cola ', 'ready_delivery' => 'Sale ya']);
check('trim y espacios colapsados', ($r['sent'] ?? null) === 'En cola', json_encode($r), $failures, $checks);
check('el slot de envío se acepta', ($r['ready_delivery'] ?? null) === 'Sale ya', json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize(['sent' => 'x', 'paid' => 'Pagada', 'pending' => 'Item']);
check('claves desconocidas se descartan', array_keys($r) === ['sent'], json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize(['open' => '', 'sent' => '   ', 'ready' => 'Lista']);
check('vacío = nombre de fábrica (se omite)', array_keys($r) === ['ready'], json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize(['ready' => '<b>Lista</b><script>x</script>', 'sent' => 'a > b']);
check('sin HTML', ($r['ready'] ?? null) === 'Listax' && ($r['sent'] ?? null) === 'a b', json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize(['in_progress' => str_repeat('ñ', 40)]);
check('tope de largo multibyte', mb_strlen($r['in_progress'] ?? '') === OrderStatusLabels::MAX_LENGTH, json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize(['open' => ['x'], 'sent' => null, 'ready' => 5]);
check('valores no escalares se ignoran', $r === ['ready' => '5'], json_encode($r), $failures, $checks);

$r = OrderStatusLabels::normalize('{"delivered":"Entregado"}');
check('lee el JSON guardado', $r === ['delivered' => 'Entregado'], json_encode($r), $failures, $checks);

check(
    'basura = todo de fábrica',
    OrderStatusLabels::normalize('no-json') === [] && OrderStatusLabels::normalize(null) === [],
    '',
    $failures,
    $checks
);

check(
    'mapa vacío sale como objeto JSON',
    json_encode(OrderStatusLabels::forJson(null)) === '{}',
    (string) json_encode(OrderStatusLabels::forJson(null)),
    $failures,
    $checks
);

echo "\n";
harnessFinish($failures, $checks);
