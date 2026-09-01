<?php
declare(strict_types=1);

/**
 * Subproceso "una request del POS" para `pos_emission_timezone_test.php`.
 *
 * Va en un proceso aparte por dos razones, no una:
 *
 *   1. `apiAuthPosContext()` termina la ejecución con `apiError()` en el path
 *      de error — no se puede try/catch sin matar al arnés. Mismo patrón que
 *      `_pos_auth_once_cli.php`.
 *   2. **Y la que importa acá**: la zona horaria que este cambio arregla es
 *      estado DE PROCESO Y DE CONEXIÓN (`date_default_timezone_set` + `SET TIME
 *      ZONE` de PostgreSQL). Un arnés que llamara al embudo de auth en su
 *      propio proceso quedaría contaminado por él y no podría comparar el
 *      "antes" contra el "después". Cada modo corre en su proceso virgen, con
 *      su conexión virgen.
 *
 * Uso:
 *   php _pos_tz_once_cli.php <bearerToken> <fixed|legacy> <saleDateNaive> <saleEpoch>
 *
 * Modos:
 *   fixed  — el código tal cual quedó: `apiAuthPosContext()` aplica el
 *            TenantClock, y `SaleInput` deriva la fecha del `timestamp`.
 *   legacy — EMULA el estado previo al fix, para probar que el arnés
 *            efectivamente reproduce el bug reportado (si `legacy` pasara los
 *            mismos asserts que `fixed`, el arnés no estaría probando nada):
 *              · sesión de PG + PHP forzados a UTC — es lo que hacía el
 *                baseline de `includes/db.php` con `APP_TIMEZONE` sin definir,
 *                porque este embudo NO cargaba `data.php` y nadie más aplicaba
 *                la TZ del tenant.
 *              · fecha tomada del texto naive `date` del payload, que es lo que
 *                `SaleInput` leía antes de mirar el `timestamp`.
 *
 * Imprime UNA línea `RESULT:<json>` con lo observado. El caller compara.
 */

$bearerToken = $argv[1] ?? '';
$mode        = $argv[2] ?? 'fixed';
$saleDate    = $argv[3] ?? '';
$saleEpoch   = (int) ($argv[4] ?? 0);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
$_SERVER['REQUEST_METHOD']     = 'POST';
$_SERVER['REQUEST_URI']        = '/v1/sales';

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Auth/apiAuthPosContext.php';

use Punto\Api\Sales\SaleInput;
use Punto\Api\Services\DrawerService;

/** @var array<string,mixed> $ctx */
$ctx        = apiAuthPosContext();
$companyId  = (string) $ctx['companyId'];
$outletId   = (string) $ctx['outletId'];
$registerId = (string) $ctx['registerId'];
$userId     = (string) $ctx['userId'];

if ($mode === 'legacy') {
    // Rebobina el reloj al estado anterior al fix. Ver el docblock de arriba.
    global $db;
    $db->Execute("SET TIME ZONE 'UTC'");
    date_default_timezone_set('UTC');
}

// Payload mínimo con la MISMA forma que arma `create-sale.ts`: `date` naive
// (lectura de reloj sin zona) + `timestamp` (instante absoluto).
$payload = [
    'uid'         => 'tz-' . bin2hex(random_bytes(6)),
    'transaction' => [
        'type'      => 0,
        'date'      => $saleDate,
        'timestamp' => $saleEpoch,
        'subtotal'  => 1000,
        'tax'       => 0,
        'discount'  => 0,
        // itemId cualquiera pero presente: `assertSimplePathEligible()` rechaza
        // las líneas sin itemId (son crédito/inCredit, path legacy). Nunca se
        // resuelve contra `item` porque este arnés no llama a `SaleService`.
        'sale'      => [['itemId' => 'c0ffee00-0000-4a00-9000-0000000000e1', 'count' => 1, 'price' => 1000, 'total' => 1000]],
        'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 1000]],
    ],
];

if ($mode === 'legacy') {
    // Lo que hacía `SaleInput::fromPayload()` antes: el texto naive, tal cual.
    $resolvedDate = (string) $payload['transaction']['date'];
} else {
    $resolvedDate = SaleInput::fromPayload($payload, $companyId)->date;
}

// El mismo binding que hace `SaleService::persistTransaction()`: la fecha viaja
// como texto y la columna es TIMESTAMPTZ, así que la sesión decide el instante.
$drawerId = DrawerService::resolveDrawerIdForDate($registerId, $companyId, $resolvedDate);

$db->Execute(
    "INSERT INTO transaction
        (transactionId, transactionDate, transactionTotal, transactionDiscount,
         transactionType, transactionPaymentType, transactionComplete,
         drawerId, registerId, outletId, companyId, userId, meta)
     VALUES (?::uuid, ?, 1000, 0, 0, ?, TRUE, ?, ?, ?, ?, ?, '{}'::jsonb)",
    [
        $txId = bin2hex(random_bytes(4)) . '-0000-4000-8000-' . bin2hex(random_bytes(6)),
        $resolvedDate,
        json_encode([['type' => 'cash', 'name' => 'Efectivo', 'price' => 1000, 'total' => 1000]]),
        $drawerId, $registerId, $outletId, $companyId, $userId,
    ]
);

// Cómo quedó guardado, leído de dos maneras: el instante absoluto (UTC) y la
// lectura en el reloj del comercio. Un desfase entre lo emitido y lo
// almacenado aparece en la primera; la segunda es lo que ve el cajero.
$row = ncmExecute(
    "SELECT to_char(transactionDate AT TIME ZONE 'UTC',           'YYYY-MM-DD HH24:MI:SS') AS utc,
            to_char(transactionDate AT TIME ZONE ?,               'YYYY-MM-DD HH24:MI:SS') AS tenant,
            drawerId AS drawerid
       FROM transaction WHERE transactionId = ?::uuid",
    [$argv[5] ?? 'UTC', $txId]
);

// `SELECT current_setting()` y no `SHOW TIME ZONE`: éste devuelve la columna
// como `TimeZone` (camelCase) y el nombre exacto depende del driver.
$pgTz = ncmExecute("SELECT current_setting('TIMEZONE') AS tz");

echo 'RESULT:' . json_encode([
    'mode'         => $mode,
    'pgTimeZone'   => $pgTz['tz'] ?? null,
    'phpTimeZone'  => date_default_timezone_get(),
    'today'        => defined('TODAY') ? TODAY : null,
    'resolvedDate' => $resolvedDate,
    'storedUtc'    => $row['utc']    ?? null,
    'storedTenant' => $row['tenant'] ?? null,
    'drawerId'     => $row['drawerid'] ?? null,
]) . "\n";
