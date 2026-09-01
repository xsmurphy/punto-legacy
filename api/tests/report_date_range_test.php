<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés del RANGO DE FECHAS de los reportes (`Date::reportRange`).
 *
 * ── El defecto que cierra ────────────────────────────────────────────────────
 *
 * Los ~24 endpoints de `api/v1/reports/*` y `api/v1/finance/*` repetían estas
 * dos líneas para resolver el período:
 *
 *     if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
 *     if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
 *
 * Los defaults traían la hora, pero SOLO cuando el parámetro venía vacío. Si el
 * cliente mandaba una fecha sola (`to=2026-09-01`) el valor viajaba tal cual al
 * WHERE, Postgres lo leía como `2026-09-01 00:00:00`, y `drawerOpenDate <= to`
 * dejaba afuera TODO lo ocurrido después de la medianoche de ese día. Pedir
 * "hasta el 1 de septiembre" devolvía el 1 de septiembre vacío: el último día
 * del rango siempre se perdía.
 *
 * Caso real (2026-09-01): al agente IA le preguntaron "¿cuánto efectivo hay en
 * la caja abierta?" y contestó "no hay ninguna caja abierta hoy en Head
 * Quarters". Había una, abierta ese mismo día a las 12:07 y visible en el POS
 * con sus movimientos. El agente consultaba `/v1/reports/drawers` con
 * `to=<hoy>` y el rango la excluía.
 *
 * El panel nunca lo pegó —su `rangeToBackend()` ya manda `00:00:00`/`23:59:59`,
 * compensando el defecto del lado del cliente—, y por eso el bug sobrevivió
 * tanto: solo se ve desde el agente IA, los consumidores por API key / MCP y
 * cualquier llamada directa a la API, que mandan la fecha sola.
 *
 * ── Qué se verifica ─────────────────────────────────────────────────────────
 *
 * Bloque A (unitario, sin DB): la semántica de `Date::reportRange()` — vacío →
 * default intacto, fecha sola → principio/final del día, fecha con hora →
 * respetada verbatim, fecha inexistente → inválida.
 *
 * Bloque B (integración contra Postgres): la reproducción literal del
 * incidente. Una caja abierta a las 12:07 del día X, consultada con
 * `from=X&to=X` (fechas sin hora, como las manda el agente y el selector del
 * panel) a través del servicio real. SIN el fix este bloque falla: el rango se
 * cierra en `X 00:00:00` y `listMovements()` devuelve cero filas.
 *
 * Uso (necesita Postgres migrado — ver run_report_date_range_test.sh).
 */

$companyId = 'da7e9a11-0000-4000-8000-000000000101';
$outletId  = 'da7e9a11-0000-4000-8000-000000000102';
$registerId = 'da7e9a11-0000-4000-8000-000000000103';
$contactId = 'da7e9a11-0000-4000-8000-000000000104';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);
define('USER_ID',    $contactId);
// `DrawersService` la usa para acotar una caja ABIERTA (sin cierre) hasta el
// final de hoy. En un request la define `api/data.php`; acá, el arnés.
if (!defined('TODAY')) { define('TODAY', date('Y-m-d H:i:s')); }

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\App\Helpers\Date;
use Punto\Api\Reports\DrawersService;
use Punto\Api\Reports\Roc;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void {
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++; echo "FAIL $label\n     $detail\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Bloque A — semántica del helper (unitario, sin DB).
// ─────────────────────────────────────────────────────────────────────────────

// A1. El caso del incidente: una fecha sola en `to` es el FINAL de ese día.
[$fA, $tA, $okA] = Date::reportRange('2026-09-01', '2026-09-01');
check('A1 fecha sola en `to` cierra a las 23:59:59', $tA === '2026-09-01 23:59:59',
    "esperado '2026-09-01 23:59:59', obtenido '$tA'", $failures, $checks);
check('A1b fecha sola en `from` abre a las 00:00:00', $fA === '2026-09-01 00:00:00',
    "esperado '2026-09-01 00:00:00', obtenido '$fA'", $failures, $checks);
check('A1c el rango de un solo día es válido', $okA === true,
    'reportRange marcó inválido un rango bien formado', $failures, $checks);

// A2. Una hora EXPLÍCITA se respeta: si el cliente pidió hasta las 15:00, es
//     hasta las 15:00. El helper completa lo que falta, no impone medianoche.
[$fB, $tB, $okB] = Date::reportRange('2026-09-01 08:30:00', '2026-09-01 15:00:00');
check('A2 hora explícita en `to` se respeta verbatim', $tB === '2026-09-01 15:00:00',
    "esperado '2026-09-01 15:00:00', obtenido '$tB'", $failures, $checks);
check('A2b hora explícita en `from` se respeta verbatim', $fB === '2026-09-01 08:30:00',
    "esperado '2026-09-01 08:30:00', obtenido '$fB'", $failures, $checks);

// A3. Los defaults NO cambian: vacío sigue siendo últimos 7 días / hoy 23:59:59.
[$fC, $tC, $okC] = Date::reportRange('', '');
check('A3 default de `from` = hace 7 días 00:00:00',
    $fC === date('Y-m-d 00:00:00', strtotime('-7 days')),
    "obtenido '$fC'", $failures, $checks);
check('A3b default de `to` = hoy 23:59:59', $tC === date('Y-m-d 23:59:59'),
    "obtenido '$tC'", $failures, $checks);
check('A3c rango vacío es válido (significa "usá el default")', $okC === true,
    'un rango vacío no es un error del cliente', $failures, $checks);

// A4. `validateHttp()` devuelve `false` cuando el parámetro no vino. El helper
//     lo tiene que tratar como vacío, no castearlo a la cadena "".
[$fD, $tD, $okD] = Date::reportRange(false, null);
check('A4 false/null de validateHttp se tratan como vacío',
    $fD === $fC && $tD === $tC && $okD === true,
    "obtenido from='$fD' to='$tD'", $failures, $checks);

// A5. Overrides de default (los usa /v1/finance/*, que arranca en el 1° de mes).
[$fE, $tE] = Date::reportRange('', '', '2026-09-01 00:00:00', '2026-09-30 23:59:59');
check('A5 los defaults se pueden overridear (finance usa mes calendario)',
    $fE === '2026-09-01 00:00:00' && $tE === '2026-09-30 23:59:59',
    "obtenido from='$fE' to='$tE'", $failures, $checks);

// A6. Formato inválido → flag en false Y defaults sanos, para que el caller que
//     prefiera degradar (finance) no tenga que validar por su cuenta.
[$fF, $tF, $okF] = Date::reportRange('ayer', '2026-09-01');
check('A6 formato inválido marca el rango como inválido', $okF === false,
    "'ayer' debería ser rechazado", $failures, $checks);
check('A6b un rango inválido igual devuelve defaults usables',
    $fF === date('Y-m-d 00:00:00', strtotime('-7 days')) && $tF === date('Y-m-d 23:59:59'),
    "obtenido from='$fF' to='$tF'", $failures, $checks);

// A7. Fecha con formato correcto pero INEXISTENTE. Antes pasaba el regex y
//     reventaba el bind en Postgres → 500. Ahora es 422 (o degrade en finance).
check('A7 fecha inexistente (2026-02-31) se rechaza', Date::isRangeBound('2026-02-31') === false,
    'checkdate() debería rechazar el 31 de febrero', $failures, $checks);
check('A7b fecha real (2024-02-29, bisiesto) se acepta', Date::isRangeBound('2024-02-29') === true,
    '2024 es bisiesto: el 29 de febrero existe', $failures, $checks);

// A7c. La HORA también tiene que existir. El regex acepta cualquier terna de
//      dos dígitos, así que `25:99:99` pasaba y reventaba el cast en Postgres.
//      Ahora que el helper es el único punto de validación, se corta acá.
check('A7c hora inexistente (25:99:99) se rechaza',
    Date::isRangeBound('2026-09-01 25:99:99') === false,
    'una hora fuera de rango debería ser inválida', $failures, $checks);
check('A7d hora válida en el borde (23:59:59) se acepta',
    Date::isRangeBound('2026-09-01 23:59:59') === true,
    '23:59:59 es una hora legal', $failures, $checks);

// A8. `stock-day` pide un saldo AL CIERRE del día: extremo superior suelto.
check('A8 rangeEnd() completa el final del día', Date::rangeEnd('2026-09-01') === '2026-09-01 23:59:59',
    'obtenido ' . Date::rangeEnd('2026-09-01'), $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// Bloque B — el incidente, end-to-end contra Postgres.
// ─────────────────────────────────────────────────────────────────────────────

try {
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Head Quarters\"}'::jsonb)",
        [$companyId]
    );
    $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletId, 'Head Quarters', $companyId]);
    $db->Execute('INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
                  VALUES (?, ?, TRUE, ?, ?)',
        [$registerId, 'Caja 1', $outletId, $companyId]);
    $db->Execute('INSERT INTO contact (contactId, contactName, type, companyId, outletId)
                  VALUES (?, ?, 0, ?, ?)',
        [$contactId, 'Cajero', $companyId, $outletId]);

    // La caja del incidente: ABIERTA (sin cierre) el 1 de septiembre a las 12:07.
    // El horario es lo que importa — cualquier hora después de medianoche sirve
    // para exponer el corte, y el mediodía es el caso típico de un relevo.
    $db->Execute(
        'INSERT INTO drawer (drawerId, drawerOpenDate, drawerOpenAmount, drawerUID,
                             drawerUserOpen, registerId, outletId, companyId)
         VALUES (?::uuid, ?::timestamptz, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?::uuid)',
        ['da7e9a11-0000-4000-8000-000000000105', '2026-09-01 12:07:00', 500000, 1,
         $contactId, $registerId, $outletId, $companyId]
    );

    $svc = new DrawersService();
    $roc = Roc::build($companyId, $outletId);

    // B1. LA REGRESIÓN. Así consulta el agente IA (sus tools declaran el
    //     parámetro como `Fecha fin YYYY-MM-DD`): fechas sin hora, mismo día en
    //     los dos extremos. Sin el fix el `to` se cierra en
    //     `2026-09-01 00:00:00` y esto devuelve cero filas.
    [$from, $to] = Date::reportRange('2026-09-01', '2026-09-01');
    $rows = $svc->listMovements($from, $to, $roc, $companyId);
    check('B1 la caja abierta a las 12:07 aparece en el rango del día',
        count($rows) === 1,
        'rango [' . $from . ' .. ' . $to . '] devolvió ' . count($rows) . ' filas; se esperaba 1. '
        . 'Este es el bug del agente IA: "no hay ninguna caja abierta hoy".', $failures, $checks);

    // B2. Control negativo: sin normalizar, el mismo rango pierde la caja. Deja
    //     asentado que el fixture SÍ discrimina y que B1 no pasa por casualidad.
    $rowsRaw = $svc->listMovements('2026-09-01', '2026-09-01', $roc, $companyId);
    check('B2 el rango CRUDO (sin normalizar) sí pierde la caja — el bug existía',
        count($rowsRaw) === 0,
        'se esperaba que el rango sin normalizar devolviera 0 filas, devolvió ' . count($rowsRaw)
        . '. Si esto falla, el fixture no reproduce el defecto.', $failures, $checks);

    // B3. Un `to` con hora explícita ANTERIOR a la apertura sigue excluyendo:
    //     el helper no ensancha rangos que el cliente acotó a propósito.
    [$from3, $to3] = Date::reportRange('2026-09-01', '2026-09-01 10:00:00');
    $rows3 = $svc->listMovements($from3, $to3, $roc, $companyId);
    check('B3 `to` con hora explícita 10:00 excluye la caja de las 12:07',
        count($rows3) === 0,
        'rango [' . $from3 . ' .. ' . $to3 . '] devolvió ' . count($rows3) . ' filas; se esperaba 0',
        $failures, $checks);

    // B4. El día anterior sigue vacío: el fix no corre el rango un día.
    [$from4, $to4] = Date::reportRange('2026-08-31', '2026-08-31');
    $rows4 = $svc->listMovements($from4, $to4, $roc, $companyId);
    check('B4 el día anterior no arrastra la caja del 1 de septiembre',
        count($rows4) === 0,
        'rango [' . $from4 . ' .. ' . $to4 . '] devolvió ' . count($rows4) . ' filas; se esperaba 0',
        $failures, $checks);

} finally {
    // Limpieza en orden inverso a las FK.
    $db->Execute('DELETE FROM drawer   WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM contact  WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM register WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM outlet   WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM company  WHERE companyId = ?', [$companyId]);
}

harnessFinish($failures, $checks);
