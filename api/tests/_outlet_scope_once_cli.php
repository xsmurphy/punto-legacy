<?php
declare(strict_types=1);

/**
 * Un caso del arnés de alcance por sucursal, en un PROCESO LIMPIO.
 *
 * Existe por una razón dura de PHP: `VIEW_OUTLET_ID` / `VIEW_OUTLET_IDS` /
 * `OUTLET_ID` son CONSTANTES, y una constante se define una vez por proceso. Dos
 * alcances distintos no pueden convivir. Además el objetivo es probar el embudo
 * REAL —`apiAuthTenant()` resolviendo una credencial de verdad— y no una
 * simulación que defina las constantes a mano: una simulación pasaría igual si
 * mañana alguien borra el bloque de `bootstrap.php`.
 *
 * Uso (lo invoca `outlet_scope_test.php`, no se corre a mano):
 *   php _outlet_scope_once_cli.php <token> <realm> <outletIdParam|-> <viewHeader|->
 *
 * Imprime una línea `RESULT:{json}` con lo observado.
 */

// ── La request simulada, ANTES de bootstrap ──────────────────────────────────
$token       = (string) ($argv[1] ?? '');
$realm       = (string) ($argv[2] ?? 'api');
$outletParam = (string) ($argv[3] ?? '-');
$viewHeader  = (string) ($argv[4] ?? '-');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/v1/reports/sales';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
if ($outletParam !== '-') {
    $_GET['outletId'] = $outletParam;
}
if ($viewHeader !== '-') {
    $_SERVER['HTTP_X_OUTLET_ID'] = $viewHeader;
}

/**
 * `apiError()` termina el proceso con `exit`. Para poder OBSERVAR el 403 en vez
 * de sufrirlo, se captura la salida y se traduce a un RESULT antes de morir.
 * Sin esto, el caso (c) —sucursal no asignada— sería indistinguible de un
 * arnés que se cayó.
 */
ob_start();
register_shutdown_function(static function (): void {
    $buffered = ob_get_length() !== false ? (string) ob_get_clean() : '';
    if ($buffered === '' || strpos($buffered, 'RESULT:') !== false) {
        echo $buffered;
        return;
    }
    $decoded = json_decode(trim($buffered), true);
    echo $buffered . "\n";
    echo 'RESULT:' . json_encode([
        'aborted' => true,
        'status'  => http_response_code() ?: 0,
        'error'   => is_array($decoded) ? ($decoded['error'] ?? $decoded) : trim($buffered),
    ]) . "\n";
});

require_once dirname(__DIR__) . '/bootstrap.php';

$realms = [$realm];
$ctx    = apiAuthTenant($realms);

ob_end_clean();

// ── Lo observado ─────────────────────────────────────────────────────────────
$companyId = (string) COMPANY_ID;
$roc       = \Punto\Api\Reports\Roc::build($companyId, (string) OUTLET_ID);

// El total que ve un reporte que pasa por `Roc::build`. Es la aserción que
// importa: no se compara contra otra consulta del mismo código, sino contra un
// número que el arnés padre calculó a mano con los montos que sembró.
$row = ncmExecute(
    "SELECT COALESCE(SUM(transactionTotal), 0) AS total, COUNT(*) AS n
       FROM transaction
      WHERE transactionComplete = TRUE {$roc}",
    []
);

// El MISMO total, pero por el otro camino: el de los lectores que no pasan por
// `Roc::build` (rollup, inventario, cuentas), que arman su filtro con
// `OutletScope::sqlFilter()` sobre `effectiveIds()`.
//
// Existe porque ese camino no tenía NINGUNA prueba contra Postgres real, y es el
// que corre riesgo de interpolación: comillas, el `IN` de dos uuids, el fragmento
// concatenado después de un WHERE que ya tiene binds. Si `sqlFilter` emitiera SQL
// inválido, o filtrara de más o de menos, este número se separa del de `Roc` —
// que es exactamente la clase de divergencia (dos respuestas a la misma pregunta)
// que causó la fuga de `58b40d08`.
$sqlFilter   = \Punto\Api\Outlets\OutletScope::sqlFilter(
    'outletId',
    \Punto\Api\Outlets\OutletScope::effectiveIds()
);
$rowFiltered = ncmExecute(
    "SELECT COALESCE(SUM(transactionTotal), 0) AS total
       FROM transaction
      WHERE transactionComplete = TRUE AND companyId = ?{$sqlFilter}",
    [$companyId]
);

// La MISMA expresión que `api/v1/outlets.php` — si acá se copiara un criterio
// propio, el arnés estaría midiendo su copia y no el endpoint que alimenta el
// selector del sidebar.
$svc      = new \Punto\Api\Outlets\OutletsService();
$scopeIds = \Punto\Api\Outlets\OutletScope::realmIsScoped((string) ($ctx['realm'] ?? ''))
    ? (\Punto\Api\Outlets\OutletScope::current() ?: null)
    : null;
$outlets  = $svc->listAll($companyId, $scopeIds);

echo 'RESULT:' . json_encode([
    'aborted'        => false,
    'realm'          => (string) $ctx['realm'],
    'outletId'       => (string) OUTLET_ID,
    'viewOutletId'   => defined('VIEW_OUTLET_ID') ? (string) VIEW_OUTLET_ID : null,
    'viewOutletIds'  => defined('VIEW_OUTLET_IDS') ? array_values((array) VIEW_OUTLET_IDS) : null,
    'scope'          => \Punto\Api\Outlets\OutletScope::current(),
    'single'         => \Punto\Api\Outlets\OutletScope::single(),
    'roc'            => $roc,
    'sqlFilter'      => $sqlFilter,
    'totalFiltered'  => (float) ($rowFiltered['total'] ?? -1),
    'total'          => (float) ($row['total'] ?? -1),
    'rows'           => (int) ($row['n'] ?? -1),
    'outletNames'    => array_map(static fn ($o) => (string) ($o['name'] ?? $o['outletName'] ?? '?'), $outlets),
]) . "\n";
