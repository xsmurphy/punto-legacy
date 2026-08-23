<?php
declare(strict_types=1);

/**
 * Helper de `stock_all_outlets_test.php` — corre UNA invocación de
 * `Inventory::getAllItemStock()` en un subproceso propio y devuelve el
 * resultado como JSON.
 *
 * Existe por dos motivos, los dos irreducibles en un solo proceso:
 *
 *   1. `COMPANY_ID` y `OUTLET_ID` son CONSTANTES (las define `api/data.php`).
 *      No se pueden redefinir. El caso "el resultado NO depende del OUTLET_ID
 *      del contexto" exige correr la MISMA función con distinto `OUTLET_ID`, y
 *      el caso de fuga entre tenants exige distinto `COMPANY_ID`. Un proceso
 *      por combinación es la única forma honesta de probarlo.
 *   2. La implementación VIEJA revienta contra PG (`invalid input syntax for
 *      type uuid`, ver el docblock de `Inventory::getAllItemStock`). Con
 *      `DB_THROW_ON_ERROR` encendido eso es una excepción que además deja la
 *      conexión/transacción en un estado del que no conviene seguir midiendo.
 *      Aislada en un subproceso, el padre solo lee el veredicto.
 *
 * Uso:
 *   php _stock_all_outlets_once_cli.php <companyId> <outletId> <modo>
 *
 *   modo = all     → getAllItemStock(false, true)   (agregado multi-sucursal)
 *          single  → getAllItemStock(false, false)  (sucursal del contexto)
 *          legacy  → réplica VERBATIM de la implementación previa al fix,
 *                    para el antes/después contra datos reales
 *
 * Salida (stdout, una línea JSON):
 *   {"ok":true,"rows":{"<itemId>":{"onHand":"…","cogs":"…"}, …}}
 *   {"ok":false,"error":"<clase>: <mensaje>"}
 */

$argvSafe = $_SERVER['argv'] ?? [];
if (count($argvSafe) < 4) {
    fwrite(STDERR, "uso: _stock_all_outlets_once_cli.php <companyId> <outletId> <all|single|legacy>\n");
    exit(2);
}

[, $companyId, $outletId, $mode] = $argvSafe;

// Las constantes del contexto se definen ANTES del bootstrap: es lo que hace
// `api/data.php` en un request real, y varias piezas las leen al cargarse.
define('COMPANY_ID', $companyId);
define('OUTLET_ID', $outletId);
define('USER_ID', '00000000-0000-4000-8000-000000000000');

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\App\Domain\Inventory;

/**
 * Réplica VERBATIM de `Inventory::getAllItemStock()` ANTES del fix
 * (`git show 9c626f72:api/lib/App/Domain/Inventory.php`). No se toca ni se
 * "mejora": su único trabajo es reproducir el número que el sistema devolvía
 * hasta ahora, para poder medir cuánto se movió.
 */
function legacyGetAllItemStock(mixed $outlet = false, bool $all = false): array
{
    $sql = 'SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs
            FROM stock t1
            JOIN (
                SELECT (array_agg(stockId ORDER BY stockDate DESC, stockId DESC))[1] AS stockId
                FROM stock
                WHERE outletId = ?
                GROUP BY itemId
            ) t2 ON t1.stockId = t2.stockId AND t1.outletId = ?';

    if ($all) {
        $allOutletsArray = getAllOutletData();
        $result          = [];
        foreach ($allOutletsArray as $outletKey => $val) {
            $item = ncmExecute($sql, [$outletKey, $outletKey], false, true, true);
            if ($item) {
                foreach ($item as $itemId => $values) {
                    $result[$itemId]['itemId']   = $values['itemId'];
                    // @phpstan-ignore-next-line — el "Undefined array key onHand"
                    // del original es parte de lo que se está reproduciendo.
                    $result[$itemId]['onHand'] = ($result[$itemId]['onHand'] ?? 0) + $values['onHand'];
                    $result[$itemId]['cogs']     = $values['cogs'];
                }
            }
        }
    } else {
        $outlet = iftn($outlet, OUTLET_ID);
        $result = ncmExecute($sql, [$outlet, $outlet], false, true, true);
    }

    return validity($result) ? $result : [];
}

/** Normaliza el mapa de filas a `{itemId: {onHand, cogs}}` serializable. */
function normalizeRows(mixed $rows): array
{
    $out = [];
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $itemId => $row) {
        $out[(string) $itemId] = [
            'onHand' => isset($row['onHand']) ? (string) $row['onHand'] : null,
            'cogs'   => isset($row['cogs']) ? (string) $row['cogs'] : null,
        ];
    }
    ksort($out);
    return $out;
}

try {
    $rows = match ($mode) {
        'all'    => Inventory::getAllItemStock(false, true),
        'single' => Inventory::getAllItemStock(false, false),
        'legacy' => legacyGetAllItemStock(false, true),
        default  => throw new InvalidArgumentException("modo desconocido: $mode"),
    };

    echo json_encode(['ok' => true, 'rows' => normalizeRows($rows)], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    echo json_encode([
        'ok'    => false,
        'error' => get_class($e) . ': ' . $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
