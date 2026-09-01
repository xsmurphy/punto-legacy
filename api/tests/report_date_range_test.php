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
 * ── Y además: la FRANJA HORARIA (`Date::hourRange`, F0 de context/67) ───────
 *
 * Bloque C (unitario, sin DB): la semántica del filtro de franja horaria — la
 * dimensión que el rango de fechas NO puede expresar. "De 07:00 a 11:59 todos
 * los días de septiembre" mandado como `from`/`to` es un intervalo CONTINUO que
 * incluye las noches del medio; la franja es un predicado aparte que se repite
 * cada día. Se verifica el predicado normal, el que CRUZA MEDIANOCHE (20:00 a
 * 04:00, el bar: con `AND` no devuelve nada nunca), la franja vacía (no agrega
 * nada a la query — el caso común), las horas inválidas y los bordes.
 *
 * Bloque D (integración contra Postgres): el predicado corriendo de verdad,
 * con filas a distintas horas. Incluye lo que ningún test unitario puede
 * probar: que la ZONA HORARIA cambia el resultado (las MISMAS filas con la
 * MISMA franja dan 2 o 0 según el huso), y que apoyarse en la zona de la
 * sesión —lo que hace el embudo de auth con `TenantClock::apply()`— da
 * exactamente lo mismo que pasarla explícita. Un filtro horario con el huso
 * equivocado devuelve datos plausibles y falsos: es el modo de falla que este
 * bloque existe para descartar.
 *
 * Uso (necesita Postgres migrado — ver run_report_date_range_test.sh).
 */

$companyId = 'da7e9a11-0000-4000-8000-000000000101';
$outletId  = 'da7e9a11-0000-4000-8000-000000000102';
$registerId = 'da7e9a11-0000-4000-8000-000000000103';
// Segunda caja: `uidx_drawer_register_open` permite UNA sola caja abierta por
// register (invariante de exclusividad), y B5 necesita un segundo drawer abierto.
$registerId2 = 'da7e9a11-0000-4000-8000-000000000107';
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
check('A1 fecha sola en `to` cierra al ULTIMO INSTANTE del dia', $tA === '2026-09-01 ' . Date::END_OF_DAY,
    "esperado '2026-09-01 " . Date::END_OF_DAY . "', obtenido '$tA'", $failures, $checks);
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
check('A3b default de `to` = hoy al ultimo instante', $tC === date('Y-m-d ' . Date::END_OF_DAY),
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
[$fE, $tE] = Date::reportRange('', '', '2026-09-01 00:00:00', '2026-09-30 ' . Date::END_OF_DAY);
check('A5 los defaults se pueden overridear (finance usa mes calendario)',
    $fE === '2026-09-01 00:00:00' && $tE === '2026-09-30 ' . Date::END_OF_DAY,
    "obtenido from='$fE' to='$tE'", $failures, $checks);

// A6. Formato inválido → flag en false Y defaults sanos, para que el caller que
//     prefiera degradar (finance) no tenga que validar por su cuenta.
[$fF, $tF, $okF] = Date::reportRange('ayer', '2026-09-01');
check('A6 formato inválido marca el rango como inválido', $okF === false,
    "'ayer' debería ser rechazado", $failures, $checks);
check('A6b un rango inválido igual devuelve defaults usables',
    $fF === date('Y-m-d 00:00:00', strtotime('-7 days')) && $tF === date('Y-m-d ' . Date::END_OF_DAY),
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
check('A8 rangeEnd() completa el final del día', Date::rangeEnd('2026-09-01') === '2026-09-01 ' . Date::END_OF_DAY,
    'obtenido ' . Date::rangeEnd('2026-09-01'), $failures, $checks);

// A9. El agujero que motivó `.999999`: las columnas son `timestamptz` (=
// microsegundos), así que un corte en `23:59:59` deja afuera casi un segundo
// entero del final del día. Una venta a las 23:59:59.5 desaparecía del reporte
// del día sin ninguna explicación.
check('A9 el ultimo instante del dia cubre los microsegundos',
    Date::rangeEnd('2026-09-01') > '2026-09-01 23:59:59.5',
    'un corte en 23:59:59 perdia todo lo posterior a esa fraccion', $failures, $checks);

// A10. La fraccion de segundo se acepta en la ENTRADA. Hace falta porque el
//      selector del panel manda la hora explicita, y una hora explicita se
//      respeta verbatim: sin fraccion el panel se quedaba con el agujero.
//      Ademas cierra la trampa de que el helper rechazara su propia salida.
check('A10 isRangeBound acepta su propia salida (round-trip)',
    Date::isRangeBound(Date::rangeEnd('2026-09-01')) === true,
    'el helper rechazaba el valor que el mismo produce', $failures, $checks);
check('A10b se acepta una fraccion explicita del cliente',
    Date::isRangeBound('2026-09-01 23:59:59.999999') === true,
    'el panel manda esta forma', $failures, $checks);
check('A10c se rechaza mas precision que microsegundos',
    Date::isRangeBound('2026-09-01 23:59:59.1234567') === false,
    'Postgres no pasa de microsegundos', $failures, $checks);
[$fG, $tG] = Date::reportRange('2026-09-01 00:00:00', '2026-09-01 23:59:59.999999');
check('A10d el tope con fraccion explicita se respeta verbatim',
    $tG === '2026-09-01 23:59:59.999999',
    "obtenido '$tG'", $failures, $checks);

// ─────────────────────────────────────────────────────────────────────────────
// Bloque C — franja horaria (`Date::hourRange`), unitario.
// ─────────────────────────────────────────────────────────────────────────────

// C1. Franja normal. El fragmento arranca con " AND " y viene entre
//     paréntesis: así se concatena después de cualquier WHERE sin cambiarle el
//     significado (misma convención que `Reports\Roc::build`).
[$c1sql, $c1par, $c1ok] = Date::hourRange('transactionDate', '07:00', '11:59');
check('C1 franja normal arma un predicado AND',
    $c1sql === ' AND (transactionDate::time >= ?::time AND transactionDate::time <= ?::time)' && $c1ok === true,
    "obtenido '$c1sql'", $failures, $checks);
check('C1b el fragmento es concatenable (arranca con AND y cierra el paréntesis)',
    str_starts_with($c1sql, ' AND (') && substr($c1sql, -1) === ')',
    "obtenido '$c1sql'", $failures, $checks);

// C2. EL TOPE ES INCLUSIVO DE LA UNIDAD PEDIDA. "Hasta las 11:59" es el minuto
//     11:59 entero, no `11:59:00`. Misma decisión (y misma razón: columnas con
//     precisión de microsegundo) que el `.999999` de `rangeEnd()`.
check('C2 el tope 11:59 cierra al final de ese minuto',
    $c1par === ['07:00:00', '11:59:59.999999'],
    'obtenido ' . json_encode($c1par), $failures, $checks);
[, $c2par] = Date::hourRange('transactionDate', '07:00:15', '11:59:30');
check('C2b con segundos explícitos el tope cierra al final de ESE segundo',
    $c2par === ['07:00:15', '11:59:30.999999'],
    'obtenido ' . json_encode($c2par), $failures, $checks);

// C3. LA FRANJA QUE CRUZA MEDIANOCHE. El bar que opera de 20:00 a 04:00: con
//     `hora >= 20 AND hora <= 04` el predicado no puede ser verdadero nunca y
//     el reporte sale VACÍO en silencio. Cuando el inicio es posterior al fin
//     el predicado se invierte a OR — esto es lo central de la F0.
[$c3sql, $c3par, $c3ok] = Date::hourRange('transactionDate', '20:00', '04:00');
check('C3 la franja que cruza medianoche invierte el predicado a OR',
    $c3sql === ' AND (transactionDate::time >= ?::time OR transactionDate::time <= ?::time)' && $c3ok === true,
    "obtenido '$c3sql'", $failures, $checks);
check('C3b los extremos de la franja invertida no se tocan',
    $c3par === ['20:00:00', '04:00:59.999999'],
    'obtenido ' . json_encode($c3par), $failures, $checks);

// C4. SIN FRANJA NO SE AGREGA NADA. Es el caso de la enorme mayoría de las
//     consultas: la query queda byte por byte como está hoy, sin binds de más.
//     Si esto falla, los 24 endpoints ya migrados pagan por una feature que no
//     están usando.
[$c4sql, $c4par, $c4ok] = Date::hourRange('transactionDate', '', '');
check('C4 sin franja el fragmento es vacío y no agrega binds',
    $c4sql === '' && $c4par === [] && $c4ok === true,
    "obtenido sql='$c4sql' params=" . json_encode($c4par), $failures, $checks);
[$c4bsql, , $c4bok] = Date::hourRange('transactionDate', false, null);
check('C4b false/null de validateHttp se tratan como "sin franja"',
    $c4bsql === '' && $c4bok === true, "obtenido '$c4bsql'", $failures, $checks);

// C5. Horas inválidas. Degradan a "sin franja" (resultado más amplio, nunca uno
//     inventado) y marcan el flag para que el endpoint pueda cortar con 422.
foreach (['25:00', '07:60', '7:00', '07', 'mañana', '07:00:61', '07:00:00.5'] as $bad) {
    [$sqlBad, $parBad, $okBad] = Date::hourRange('transactionDate', $bad, '11:59');
    check("C5 hora inválida ('$bad') se rechaza y no filtra",
        $okBad === false && $sqlBad === '' && $parBad === [],
        "obtenido ok=" . var_export($okBad, true) . " sql='$sqlBad'", $failures, $checks);
}
check('C5b isHourBound acepta las formas válidas',
    Date::isHourBound('00:00') && Date::isHourBound('23:59:59') && Date::isHourBound(''),
    'HH:MM, HH:MM:SS y vacío son válidos', $failures, $checks);

// C6. BORDE 00:00. El inicio del día tiene que ser una franja legal, no
//     confundirse con "vacío" — un `if ($hourFrom)` ingenuo lo trataría como
//     ausente y silenciaría el filtro.
[$c6sql, $c6par, $c6ok] = Date::hourRange('transactionDate', '00:00', '00:00');
check('C6 la franja 00:00-00:00 filtra el primer minuto del día (no es "sin franja")',
    $c6sql !== '' && $c6par === ['00:00:00', '00:00:59.999999'] && $c6ok === true,
    "obtenido sql='$c6sql' params=" . json_encode($c6par), $failures, $checks);
check('C6b 00:00 a 23:59 cubre el día entero sin invertirse',
    Date::hourRange('transactionDate', '00:00', '23:59')[1] === ['00:00:00', '23:59:59.999999']
    && str_contains(Date::hourRange('transactionDate', '00:00', '23:59')[0], ' AND '),
    'el día completo no debería disparar el OR de medianoche', $failures, $checks);

// C7. Un solo extremo: se completa con el borde del día. Importa que NUNCA
//     dispare el OR por accidente — "desde las 20:00" es 20:00→fin del día.
[$c7sql, $c7par] = Date::hourRange('transactionDate', '20:00', '');
check('C7 sólo hourFrom = desde esa hora hasta el final del día',
    $c7par === ['20:00:00', Date::END_OF_DAY] && !str_contains($c7sql, ' OR '),
    "obtenido sql='$c7sql' params=" . json_encode($c7par), $failures, $checks);
[$c7bsql, $c7bpar] = Date::hourRange('transactionDate', '', '11:59');
check('C7b sólo hourTo = desde medianoche hasta esa hora',
    $c7bpar === ['00:00:00', '11:59:59.999999'] && !str_contains($c7bsql, ' OR '),
    "obtenido sql='$c7bsql' params=" . json_encode($c7bpar), $failures, $checks);

// C8. TZ explícita: la zona viaja BINDEADA (no interpolada) y se repite en cada
//     aparición de la expresión, en orden de aparición del `?` en el SQL. Si el
//     orden se desarma, Postgres compara una hora contra un nombre de zona.
[$c8sql, $c8par] = Date::hourRange('transactionDate', '07:00', '11:59', 'America/Bogota');
check('C8 la TZ explícita se bindea en cada aparición, en orden',
    $c8sql === ' AND ((transactionDate AT TIME ZONE ?::text)::time >= ?::time AND (transactionDate AT TIME ZONE ?::text)::time <= ?::time)'
    && $c8par === ['America/Bogota', '07:00:00', 'America/Bogota', '11:59:59.999999'],
    "obtenido sql='$c8sql' params=" . json_encode($c8par), $failures, $checks);
check('C8b sin TZ no se emite AT TIME ZONE (se apoya en la sesión de PG)',
    !str_contains($c1sql, 'AT TIME ZONE'),
    'el fragmento sin TZ no debería fijar zona', $failures, $checks);

// C9. Columna con alias (`t.transactionDate`): CustomersService y DashboardService
//     hacen JOIN y califican sus columnas. Sin esto la F1 no encaja.
check('C9 acepta columna calificada por alias',
    str_contains(Date::hourRange('t.transactionDate', '07:00', '11:59')[0], 't.transactionDate::time'),
    'obtenido ' . Date::hourRange('t.transactionDate', '07:00', '11:59')[0], $failures, $checks);

// C10. Columna inválida = error de PROGRAMACIÓN (el nombre lo escribe el
//      servicio, nunca el request) y se interpola en el SQL: explota, no degrada.
$threw = false;
try { Date::hourRange('transactionDate; DROP TABLE transaction --', '07:00', '11:59'); }
catch (\InvalidArgumentException $e) { $threw = true; }
check('C10 una columna que no es identificador simple lanza (no se interpola)',
    $threw === true, 'hourRange debería rechazar el nombre de columna', $failures, $checks);

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
    $db->Execute('INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
                  VALUES (?, ?, TRUE, ?, ?)',
        [$registerId2, 'Caja 2', $outletId, $companyId]);
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

    // B5. EL AGUJERO DEL ÚLTIMO SEGUNDO, contra Postgres de verdad. Una caja
    //     abierta a las 23:59:59.5 —la venta que "nadie logra explicar" cuando
    //     el total del día no cuadra con el arqueo—. Con el tope viejo de
    //     `23:59:59` esta fila NO entraba: el `<=` la dejaba afuera por medio
    //     segundo. `A9` verifica el string; esto verifica el comportamiento.
    $db->Execute(
        'INSERT INTO drawer (drawerId, drawerOpenDate, drawerOpenAmount, drawerUID,
                             drawerUserOpen, registerId, outletId, companyId)
         VALUES (?::uuid, ?::timestamptz, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?::uuid)',
        ['da7e9a11-0000-4000-8000-000000000106', '2026-09-01 23:59:59.500000', 100000, 2,
         $contactId, $registerId2, $outletId, $companyId]
    );
    [$from5, $to5] = Date::reportRange('2026-09-01', '2026-09-01');
    $rows5 = $svc->listMovements($from5, $to5, $roc, $companyId);
    check('B5 una caja abierta a las 23:59:59.5 entra en el rango del dia',
        count($rows5) === 2,
        'rango [' . $from5 . ' .. ' . $to5 . '] devolvió ' . count($rows5) . ' filas; se esperaban 2 '
        . '(la de las 12:07 y la de las 23:59:59.5). Con un tope en 23:59:59 se pierde la segunda.',
        $failures, $checks);

    // B5b. Control: el tope VIEJO (`23:59:59`) efectivamente la perdía. Deja
    //      asentado que B5 no pasa por casualidad.
    $rows5b = $svc->listMovements('2026-09-01 00:00:00', '2026-09-01 23:59:59', $roc, $companyId);
    check('B5b el tope viejo 23:59:59 SI perdia la de las 23:59:59.5 — el agujero existia',
        count($rows5b) === 1,
        'se esperaba 1 fila con el tope viejo, se obtuvieron ' . count($rows5b)
        . '. Si esto falla, el fixture no reproduce el agujero del ultimo segundo.',
        $failures, $checks);

    // B6. EL CAMINO DEL PANEL. `rangeToBackend()` manda la hora EXPLICITA, y una
    //     hora explicita se respeta verbatim — asi que el panel solo deja de
    //     perder el ultimo segundo si manda `.999999`. Esto verifica ese camino
    //     exacto, que es por donde entran las 24 pantallas de reportes.
    $rows6 = $svc->listMovements('2026-09-01 00:00:00', '2026-09-01 23:59:59.999999', $roc, $companyId);
    check('B6 el rango que manda el panel (hora explicita) cubre las 23:59:59.5',
        count($rows6) === 2,
        'devolvió ' . count($rows6) . ' filas; se esperaban 2. Si da 1, el panel sigue '
        . 'perdiendo el ultimo segundo aunque el backend este arreglado.', $failures, $checks);

    // B4. El día anterior sigue vacío: el fix no corre el rango un día.
    [$from4, $to4] = Date::reportRange('2026-08-31', '2026-08-31');
    $rows4 = $svc->listMovements($from4, $to4, $roc, $companyId);
    check('B4 el día anterior no arrastra la caja del 1 de septiembre',
        count($rows4) === 0,
        'rango [' . $from4 . ' .. ' . $to4 . '] devolvió ' . count($rows4) . ' filas; se esperaba 0',
        $failures, $checks);

    // ─────────────────────────────────────────────────────────────────────────
    // Bloque D — la franja horaria contra Postgres de verdad.
    //
    // Cuatro cajas en instantes ABSOLUTOS (offset explícito `+00`, así el
    // fixture no depende de la zona de la sesión) el 15/16 de septiembre.
    // Todas CERRADAS: `uidx_drawer_register_open` sólo admite una abierta por
    // caja, y acá lo que importa es la hora de apertura, no el estado.
    //
    //   fila   instante UTC        hora local UTC   hora local America/Bogota
    //   D-a    15/09 08:30 +00     08:30            03:30
    //   D-b    15/09 12:00 +00     12:00            07:00
    //   D-c    15/09 21:00 +00     21:00            16:00
    //   D-d    16/09 02:00 +00     02:00            21:00 (del 15)
    // ─────────────────────────────────────────────────────────────────────────

    $db->Execute("SET TIME ZONE 'UTC'");

    $hourFixtures = [
        ['da7e9a11-0000-4000-8000-00000000010a', 10, '2026-09-15 08:30:00+00'],
        ['da7e9a11-0000-4000-8000-00000000010b', 11, '2026-09-15 12:00:00+00'],
        ['da7e9a11-0000-4000-8000-00000000010c', 12, '2026-09-15 21:00:00+00'],
        ['da7e9a11-0000-4000-8000-00000000010d', 13, '2026-09-16 02:00:00+00'],
    ];
    foreach ($hourFixtures as [$id, $uid, $openAt]) {
        $db->Execute(
            'INSERT INTO drawer (drawerId, drawerOpenDate, drawerCloseDate, drawerOpenAmount, drawerUID,
                                 drawerUserOpen, registerId, outletId, companyId)
             VALUES (?::uuid, ?::timestamptz, ?::timestamptz, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?::uuid)',
            [$id, $openAt, $openAt, 0, $uid, $contactId, $registerId, $outletId, $companyId]
        );
    }

    /**
     * Cuenta las cajas del fixture que caen en la franja. El rango de fechas va
     * con offset explícito y es más ancho que el fixture a propósito: lo que se
     * mide acá es el predicado de FRANJA, no el borde del rango (eso es el
     * bloque B). Refleja el contrato del helper — la franja siempre viaja
     * acompañada del rango, nunca sola.
     */
    $countInBand = function (string $hf, string $ht, ?string $tz = null, string $alias = '') use ($companyId): int {
        $pfx = $alias !== '' ? $alias . '.' : '';
        [$hourSql, $hourParams] = Date::hourRange($pfx . 'drawerOpenDate', $hf, $ht, $tz);
        $sql = 'SELECT COUNT(*) AS n FROM drawer' . ($alias !== '' ? ' ' . $alias : '')
             . ' WHERE ' . $pfx . 'companyId = ?::uuid'
             . ' AND ' . $pfx . 'drawerOpenDate >= ?::timestamptz'
             . ' AND ' . $pfx . 'drawerOpenDate <= ?::timestamptz'
             . ' AND ' . $pfx . 'drawerUID >= 10' . $hourSql;
        $r = ncmExecute($sql, array_merge(
            [$companyId, '2026-09-14 00:00:00+00', '2026-09-17 23:59:59+00'],
            $hourParams
        ));
        return (int) ($r['n'] ?? -1);
    };

    // D0. Guard del fixture: la aritmética de abajo asume que Bogotá es UTC-5
    //     (sin horario de verano desde 1993). Si alguna vez cambia la tzdata del
    //     contenedor, que falle acá y no en un assert de franja sin explicación.
    $offRow = ncmExecute(
        "SELECT EXTRACT(HOUR FROM (timestamptz '2026-09-15 12:00:00+00' AT TIME ZONE 'America/Bogota'))::int AS h",
        []
    );
    check('D0 el fixture asume America/Bogota = UTC-5', (int) ($offRow['h'] ?? -1) === 7,
        'las 12:00 UTC deberían ser las 07:00 en Bogotá; la tzdata del contenedor dice otra cosa',
        $failures, $checks);

    // D1. El predicado corre y selecciona lo que debe. Franja de mañana leída en
    //     UTC: entran la de 08:30 y la de 12:00, quedan afuera 21:00 y 02:00.
    check('D1 la franja 08:00-12:59 selecciona las dos cajas de la mañana',
        $countInBand('08:00', '12:59', 'UTC') === 2,
        'obtenido ' . $countInBand('08:00', '12:59', 'UTC') . ', se esperaban 2', $failures, $checks);

    // D2. LA ZONA HORARIA CAMBIA LA RESPUESTA. Mismas filas, misma franja, otro
    //     huso: cero. Es el modo de falla que hace peligrosa esta feature — un
    //     reporte plausible y falso, no un error. Si D1 y D2 dieran lo mismo, el
    //     `AT TIME ZONE` del helper no estaría haciendo nada.
    check('D2 la MISMA franja en otro huso da otro resultado (0, no 2)',
        $countInBand('08:00', '12:59', 'America/Bogota') === 0,
        'obtenido ' . $countInBand('08:00', '12:59', 'America/Bogota')
        . '; si da 2, el AT TIME ZONE no se está aplicando', $failures, $checks);
    check('D2b en hora de Bogotá esas dos cajas caen en la franja 03:00-07:59',
        $countInBand('03:00', '07:59', 'America/Bogota') === 2,
        'obtenido ' . $countInBand('03:00', '07:59', 'America/Bogota') . ', se esperaban 2',
        $failures, $checks);

    // D3. Sin TZ explícita el predicado se apoya en la zona de la SESIÓN, que es
    //     lo que deja `TenantClock::apply()` en todo request de reportes
    //     (`apiAuthTenant` → `data.php`). Con la sesión en Bogotá el resultado
    //     tiene que ser IDÉNTICO al de la TZ explícita: eso es lo que autoriza a
    //     los 24 endpoints a no pasar el huso.
    $db->Execute("SET TIME ZONE 'America/Bogota'");
    check('D3 sin TZ explícita, la sesión de PG manda (= lo que hace TenantClock::apply)',
        $countInBand('03:00', '07:59') === 2 && $countInBand('08:00', '12:59') === 0,
        'con la sesión en Bogotá se esperaban 2 y 0; obtenidos '
        . $countInBand('03:00', '07:59') . ' y ' . $countInBand('08:00', '12:59'),
        $failures, $checks);
    $db->Execute("SET TIME ZONE 'UTC'");

    // D4. LA FRANJA QUE CRUZA MEDIANOCHE, con filas reales. El bar de 20:00 a
    //     04:00: tienen que entrar la de las 21:00 Y la de las 02:00. Con el
    //     predicado leído ingenuamente (`>= 20 AND <= 04`) esto da 0.
    check('D4 la franja 20:00-04:00 trae las de las 21:00 y las 02:00',
        $countInBand('20:00', '04:00', 'UTC') === 2,
        'obtenido ' . $countInBand('20:00', '04:00', 'UTC')
        . '; con AND en vez de OR este caso devuelve 0 en silencio', $failures, $checks);
    check('D4b control: 20:00-23:59 (sin cruzar) trae sólo la de las 21:00',
        $countInBand('20:00', '23:59', 'UTC') === 1,
        'obtenido ' . $countInBand('20:00', '23:59', 'UTC')
        . '; si da 2, el OR se está aplicando donde no corresponde', $failures, $checks);

    // D5. SIN FRANJA, LA QUERY QUEDA COMO ESTÁ: las cuatro filas del rango.
    check('D5 sin franja no se filtra nada (las 4 cajas del rango)',
        $countInBand('', '') === 4,
        'obtenido ' . $countInBand('', '') . ', se esperaban 4', $failures, $checks);

    // D6. El tope inclusivo, medido: una franja de un minuto agarra la fila de
    //     ese minuto exacto y el minuto anterior no la agarra.
    check('D6 la franja 02:00-02:00 agarra la caja de las 02:00',
        $countInBand('02:00', '02:00', 'UTC') === 1,
        'obtenido ' . $countInBand('02:00', '02:00', 'UTC'), $failures, $checks);
    check('D6b la franja 01:59-01:59 no la agarra',
        $countInBand('01:59', '01:59', 'UTC') === 0,
        'obtenido ' . $countInBand('01:59', '01:59', 'UTC'), $failures, $checks);

    // D7. Columna calificada por alias: es como filtran los servicios con JOIN
    //     (CustomersService, DashboardService). Verifica que el fragmento sigue
    //     siendo SQL válido ahí.
    check('D7 el predicado funciona con la columna calificada por alias',
        $countInBand('08:00', '12:59', 'UTC', 'd') === 2,
        'obtenido ' . $countInBand('08:00', '12:59', 'UTC', 'd'), $failures, $checks);

} finally {
    // La sesión vuelve a la zona con la que arrancó: el bloque D la mueve.
    $db->Execute("SET TIME ZONE 'UTC'");
    // Limpieza en orden inverso a las FK.
    $db->Execute('DELETE FROM drawer   WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM contact  WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM register WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM outlet   WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM company  WHERE companyId = ?', [$companyId]);
}

harnessFinish($failures, $checks);
