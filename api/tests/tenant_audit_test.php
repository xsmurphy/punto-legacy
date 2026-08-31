<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la cadena de auditoría del tenant: `tenantAudit()` → `tenant_audit`
 * → el SELECT de `api/v1/reports/audit.php` → el JSON que consume
 * `/reports/audit`.
 *
 * ── Por qué existe ───────────────────────────────────────────────────────────
 *
 * El owner reportó `/reports/audit` vacío. Leyendo el código la cadena "se ve
 * bien" de punta a punta, y esa es exactamente la situación en la que hace
 * falta un arnés: `tenantAudit()` está diseñada para no interrumpir nunca la
 * request, así que sus DOS caminos de falla son invisibles desde afuera —
 *
 *   1. `if (!isset($db) || !is_object($db)) return;` — retornaba sin dejar
 *      rastro (arreglado: ahora loguea igual que el catch);
 *   2. `catch (\Throwable)` → `error_log` y seguir. Si el INSERT falla SIEMPRE
 *      (tipo de columna, largo, `?::jsonb` con un valor que jsonb rechaza), el
 *      sistema entero se comporta como si no hubiera nada que auditar.
 *
 * En los dos casos el resultado observable es idéntico al de "no hubo
 * actividad": tabla vacía, 200, cero errores. Sin una prueba que meta una fila
 * de verdad y la lea de vuelta, no se puede distinguir "funciona y no hubo
 * mutaciones" de "está roto hace dos meses".
 *
 * ── Qué prueba ───────────────────────────────────────────────────────────────
 *
 *  A. Camino feliz: contexto realista entra y se lee con el MISMO SELECT del
 *     endpoint (incluidos los LEFT JOIN a contact/outlet, que son los que
 *     resuelven userName/outletName).
 *  B. Casos límite que podrían estar rompiéndolo en silencio:
 *     - `meta` vacío: `json_encode([])` da `"[]"`, NO `"{}"`. jsonb acepta el
 *       array, pero el front lo tipa `Record<string, unknown>` — hay que saber
 *       cuál de las dos formas llega.
 *     - `userId`/`outletId` null (realm `api`, o una mutación sin sucursal).
 *     - endpoint más largo que los 160 chars de la columna.
 *     - realm más largo que los 20 chars de la columna.
 *     - `targetId` e `ip` en su largo máximo.
 *  C. Scope de tenant: el SELECT no puede traer filas de otro comercio.
 *  D. Contrato del JSON contra `AuditRow` de `frontend/hooks/use-reports.ts`.
 *     Es la mitad de la cadena que ningún test tocaba: la fila puede estar
 *     perfecta en la BD y la tabla igual pintar celdas vacías si las claves no
 *     son las que el front lee.
 *  E. El rango de fechas: `BETWEEN from AND to` con el `to` que manda el front.
 *
 * Uso (necesita Postgres migrado — ver run_tenant_audit_test.sh):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/tenant_audit_test.php
 */

// Fixtures del seed de verify_chain (tenant "Verify PY").
$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId  = '1a282724-6073-49c3-8bc3-0114a132e349';
$userId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5'; // contact "Verify PY Admin"
$otherCompanyId = 'fa8cf679-9003-417e-8726-5b772d3b6e88'; // tenant "Verify MX"

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';

global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n";
    if ($detail !== '') { echo "     $detail\n"; }
}

// ── Precondición: la tabla existe con el schema de la mig 35 + mig 150 ───────
//
// Va primero y es una ASERCIÓN, no un `CREATE TABLE` del arnés: si el test se
// creara su propia tabla estaría probando su invención, no la de producción.
// El nombre de las columnas es el corazón del caso (la mig 150 las pasó de
// `"companyId"` a `companyid`), así que se verifica contra el catálogo.
$colsRs = $db->Execute(
    "SELECT column_name, data_type, character_maximum_length
       FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = 'tenant_audit'"
);
$cols = [];
if ($colsRs && is_object($colsRs)) {
    while (!$colsRs->EOF) {
        $f = $colsRs->fields;
        $cols[(string) $f['column_name']] = [
            'type' => (string) $f['data_type'],
            'len'  => $f['character_maximum_length'],
        ];
        $colsRs->MoveNext();
    }
}

check(
    'A1 la tabla tenant_audit existe',
    $cols !== [],
    'information_schema no devolvió ninguna columna — la mig 35 no corrió en esta base'
);

$expected = ['id', 'companyid', 'userid', 'outletid', 'realm', 'method',
             'endpoint', 'targetid', 'meta', 'ip', 'createdat'];
$missing  = array_values(array_diff($expected, array_keys($cols)));
check(
    'A2 columnas lowercase como las dejó la mig 150',
    $missing === [],
    'faltan: ' . implode(', ', $missing) . ' — presentes: ' . implode(', ', array_keys($cols))
);

check('A3 meta es jsonb', ($cols['meta']['type'] ?? '') === 'jsonb', 'tipo real: ' . ($cols['meta']['type'] ?? 'ausente'));
check('A4 createdat es timestamptz', ($cols['createdat']['type'] ?? '') === 'timestamp with time zone', 'tipo real: ' . ($cols['createdat']['type'] ?? 'ausente'));

// Largos declarados: son los que `tenantAudit()` asume al hacer substr().
// Si una mig futura los achica, el substr deja de proteger y el INSERT
// empieza a fallar en silencio — este check lo convierte en rojo.
$lens = ['realm' => 20, 'method' => 10, 'endpoint' => 160, 'targetid' => 64, 'ip' => 64];
foreach ($lens as $col => $len) {
    check(
        "A5 $col tiene largo $len (el que asume el substr de tenantAudit)",
        (int) ($cols[$col]['len'] ?? 0) === $len,
        'largo real: ' . var_export($cols[$col]['len'] ?? null, true)
    );
}

// Base limpia para este arnés: solo las filas de los dos tenants del seed.
$db->Execute('DELETE FROM tenant_audit WHERE companyid IN (?, ?)', [$companyId, $otherCompanyId]);

/**
 * Corre el MISMO SELECT que `api/v1/reports/audit.php`, con el mismo
 * `forceObj` y la misma materialización del recordset. Copiado a propósito:
 * si el endpoint cambia y el arnés no, el test deja de cubrirlo y hay que
 * enterarse acá y no en producción.
 *
 * @return list<array<string,mixed>>
 */
function runAuditSelect(string $companyId, string $from, string $to): array
{
    $rs = ncmExecute(
        'SELECT
            ta.id,
            ta.companyid   AS "companyId",
            ta.userid      AS "userId",
            ta.outletid    AS "outletId",
            ta.realm,
            ta.method,
            ta.endpoint,
            ta.targetid    AS "targetId",
            ta.meta::text  AS "metaJson",
            ta.ip,
            to_char(ta.createdat AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\') AS "createdAt",
            c.contactName  AS "userName",
            o.outletName   AS "outletName"
         FROM tenant_audit ta
         LEFT JOIN contact c ON c.contactId = ta.userid   AND c.companyId = ta.companyid
         LEFT JOIN outlet  o ON o.outletId  = ta.outletid AND o.companyId = ta.companyid
         WHERE ta.companyid = ?
           AND ta.createdat BETWEEN ? AND ?
         ORDER BY ta.createdat DESC
         LIMIT 1000',
        [$companyId, $from, $to],
        false,
        true
    );

    $rows = [];
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            $metaDecoded = json_decode((string) ($f['metaJson'] ?? ''), true);
            $meta = (is_array($metaDecoded) && $metaDecoded !== []) ? $metaDecoded : null;

            $rows[] = [
                'id'         => $f['id'],
                'companyId'  => $f['companyId'],
                'userId'     => $f['userId'],
                'outletId'   => $f['outletId'],
                'realm'      => $f['realm'],
                'method'     => $f['method'],
                'endpoint'   => $f['endpoint'],
                'targetId'   => $f['targetId'],
                'meta'       => $meta,
                'ip'         => $f['ip'],
                'createdAt'  => $f['createdAt'],
                'userName'   => $f['userName'],
                'outletName' => $f['outletName'],
            ];
            $rs->MoveNext();
        }
    }
    return $rows;
}

// Rango como lo manda el front: `rangeToBackend()` de
// frontend/components/date-range-picker.tsx — from a las 00:00:00 y to a las
// 23:59:59 del día. Con `to` a las 00:00:00 (el otro formato que acepta el
// regex del endpoint) TODO lo de hoy queda afuera, así que el rango es parte
// de lo que hay que probar, no un detalle del setup.
$from = date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = date('Y-m-d 23:59:59');

// ── B. Camino feliz ─────────────────────────────────────────────────────────
tenantAudit(
    ['companyId' => $companyId, 'userId' => $userId, 'outletId' => $outletId, 'realm' => 'panel'],
    'POST',
    '/v1/items',
    'a1b2c3d4-0000-4000-8000-000000000001',
    ['source' => 'arnes']
);

$rows = runAuditSelect($companyId, $from, $to);
check('B1 la fila entra y el SELECT del endpoint la encuentra', count($rows) === 1, 'filas: ' . count($rows));

$r = $rows[0] ?? null;
if ($r !== null) {
    check('B2 method persistido', (string) $r['method'] === 'POST', var_export($r['method'], true));
    check('B3 endpoint persistido', (string) $r['endpoint'] === '/v1/items', var_export($r['endpoint'], true));
    check('B4 realm persistido', (string) $r['realm'] === 'panel', var_export($r['realm'], true));
    check('B5 targetId persistido', (string) $r['targetId'] === 'a1b2c3d4-0000-4000-8000-000000000001', var_export($r['targetId'], true));
    check('B6 el LEFT JOIN a contact resuelve userName', (string) $r['userName'] === 'Verify PY Admin', var_export($r['userName'], true));
    check('B7 el LEFT JOIN a outlet resuelve outletName', (string) $r['outletName'] === 'Verify PY - Sucursal', var_export($r['outletName'], true));
    // B8 es el bug que el arnés destapó: `meta` es uno de los tres nombres que
    // `Query::flattenJsonb()` desempaqueta y BORRA de la fila. Pedida como
    // `ta.meta` desaparecía y su contenido se colaba como columnas sueltas
    // (`source` acá; `keyId` en las llamadas del realm `api`). El alias
    // `metaJson` la saca de esa lista.
    check('B8 meta sobrevive al flatten del wrapper y vuelve como objeto', $r['meta'] === ['source' => 'arnes'], var_export($r['meta'], true));
    check(
        'B8b ninguna clave del jsonb se cuela como columna de la fila',
        !array_key_exists('source', $r),
        'claves: ' . implode(', ', array_keys($r))
    );
    check('B9 createdAt no viene vacío', trim((string) $r['createdAt']) !== '', var_export($r['createdAt'], true));
    // El front hace `new Date(row.createdAt)`. Un `YYYY-MM-DD HH:MI:SS+00` con
    // espacio no es ISO-8601 y varios browsers devuelven Invalid Date.
    check(
        'B10 createdAt es ISO-8601 UTC parseable por new Date()',
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $r['createdAt']),
        var_export($r['createdAt'], true)
    );
}

// ── C. Contrato del JSON que consume el front ───────────────────────────────
//
// `AuditRow` (frontend/hooks/use-reports.ts) declara createdAt/userId/outletId/
// targetId/companyId en camelCase, y la columna "Fecha" de la tabla lee
// `accessorKey: "createdAt"`. Si el endpoint devuelve las claves lowercase del
// catálogo, la request sale 200 con N filas y la tabla pinta la fecha vacía en
// todas — el mismo modo de falla silenciosa que ya mordió en /settings/sessions
// (ver la nota de CaseInsensitiveArray en api/includes/lib/DB.php).
$payload = json_decode(json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE), true);
$first   = $payload['rows'][0] ?? [];

foreach (['id', 'companyId', 'userId', 'outletId', 'realm', 'method', 'endpoint',
          'targetId', 'meta', 'ip', 'createdAt', 'userName', 'outletName'] as $key) {
    check(
        "C1 el JSON trae la clave '$key' que declara AuditRow",
        array_key_exists($key, $first),
        'claves reales: ' . implode(', ', array_keys($first))
    );
}
check(
    'C2 createdAt no llega vacío al front (columna "Fecha" de la tabla)',
    isset($first['createdAt']) && trim((string) $first['createdAt']) !== '',
    var_export($first['createdAt'] ?? null, true)
);

// ── D. Casos límite ─────────────────────────────────────────────────────────
$db->Execute('DELETE FROM tenant_audit WHERE companyid = ?', [$companyId]);

// D1: meta vacío. json_encode([]) da "[]", no "{}".
tenantAudit(
    ['companyId' => $companyId, 'userId' => $userId, 'outletId' => $outletId, 'realm' => 'panel'],
    'DELETE',
    '/v1/contacts',
    null,
    []
);
$rows = runAuditSelect($companyId, $from, $to);
check('D1 meta vacío no rompe el INSERT', count($rows) === 1, 'filas: ' . count($rows));
if (isset($rows[0])) {
    // Se documenta la forma REAL que llega, sea "[]" o "{}": el front la tipa
    // Record<string,unknown> y hoy no la renderiza, pero si mañana la lee tiene
    // que saber con qué se va a encontrar.
    // `json_encode([])` da "[]", no "{}". jsonb lo acepta, pero el front lo tipa
    // `Record<string, unknown> | null`: el endpoint normaliza las dos formas
    // vacías a null para que "sin metadata" sea un solo valor.
    check(
        'D2 meta vacío llega como null, no como [] ni {}',
        $rows[0]['meta'] === null,
        'valor: ' . var_export($rows[0]['meta'], true)
    );
}

// D2: userId y outletId null — es el caso del realm `api` (key sin sucursal) y
// el de cualquier mutación fuera del scope de una sucursal. Las columnas son
// nullables y el `?:` de tenantAudit convierte '' en null.
$db->Execute('DELETE FROM tenant_audit WHERE companyid = ?', [$companyId]);
tenantAudit(
    ['companyId' => $companyId, 'userId' => '', 'outletId' => '', 'realm' => 'api'],
    'GET',
    '/v1/settings',
    null,
    ['keyId' => 'c3a1e470-0000-4000-8000-000000000999']
);
$rows = runAuditSelect($companyId, $from, $to);
check('D3 userId/outletId vacíos entran como NULL sin romper', count($rows) === 1, 'filas: ' . count($rows));
if (isset($rows[0])) {
    check('D4 userId queda NULL', $rows[0]['userId'] === null, var_export($rows[0]['userId'], true));
    check('D5 outletId queda NULL', $rows[0]['outletId'] === null, var_export($rows[0]['outletId'], true));
    // El meta del realm `api` lleva el keyId de la credencial que llamó. Es el
    // dato que vuelve investigable un "el MCP hizo algo raro" — si el flatten
    // se lo come, la auditoría de M0 pierde justo su columna útil.
    check('D5b el keyId del realm api sobrevive dentro de meta', ($rows[0]['meta']['keyId'] ?? null) === 'c3a1e470-0000-4000-8000-000000000999', var_export($rows[0]['meta'], true));
    check('D6 el LEFT JOIN tolera userid NULL (userName null, no error)', $rows[0]['userName'] === null, var_export($rows[0]['userName'], true));
    check('D7 realm api se persiste (es la lectura auditada de M0)', (string) $rows[0]['realm'] === 'api', var_export($rows[0]['realm'], true));
}

// D3: endpoint más largo que la columna (160). El substr de tenantAudit tiene
// que truncar ANTES de que PG rechace por largo.
$db->Execute('DELETE FROM tenant_audit WHERE companyid = ?', [$companyId]);
$longEndpoint = '/v1/' . str_repeat('segmento-largo/', 30); // ~455 chars
tenantAudit(
    ['companyId' => $companyId, 'userId' => $userId, 'outletId' => $outletId, 'realm' => 'panel'],
    'PATCH',
    $longEndpoint,
    str_repeat('T', 200), // targetId > 64
    []
);
$rows = runAuditSelect($companyId, $from, $to);
check('D8 endpoint de 455 chars no rompe el INSERT', count($rows) === 1, 'filas: ' . count($rows));
if (isset($rows[0])) {
    check('D9 endpoint truncado a 160', strlen((string) $rows[0]['endpoint']) === 160, 'largo: ' . strlen((string) $rows[0]['endpoint']));
    check('D10 targetId truncado a 64', strlen((string) $rows[0]['targetId']) === 64, 'largo: ' . strlen((string) $rows[0]['targetId']));
}

// D4: realm de más de 20 chars. No hay realm así hoy, pero la columna es
// VARCHAR(20) y el substr es lo único que lo sostiene.
$db->Execute('DELETE FROM tenant_audit WHERE companyid = ?', [$companyId]);
tenantAudit(
    ['companyId' => $companyId, 'userId' => $userId, 'outletId' => $outletId, 'realm' => str_repeat('r', 40)],
    'PUT',
    '/v1/users',
    null,
    []
);
$rows = runAuditSelect($companyId, $from, $to);
check('D11 realm de 40 chars no rompe el INSERT', count($rows) === 1, 'filas: ' . count($rows));
if (isset($rows[0])) {
    check('D12 realm truncado a 20', strlen((string) $rows[0]['realm']) === 20, 'largo: ' . strlen((string) $rows[0]['realm']));
}

// ── E. Scope de tenant ──────────────────────────────────────────────────────
$db->Execute('DELETE FROM tenant_audit WHERE companyid IN (?, ?)', [$companyId, $otherCompanyId]);
tenantAudit(['companyId' => $companyId,      'userId' => $userId, 'outletId' => $outletId, 'realm' => 'panel'], 'POST', '/v1/items', null, []);
tenantAudit(['companyId' => $otherCompanyId, 'userId' => null,    'outletId' => null,      'realm' => 'panel'], 'POST', '/v1/items', null, []);

$rows      = runAuditSelect($companyId, $from, $to);
$otherRows = runAuditSelect($otherCompanyId, $from, $to);
check('E1 el SELECT solo trae las filas del tenant pedido', count($rows) === 1, 'filas: ' . count($rows));
check('E2 el otro tenant ve las suyas y solo las suyas', count($otherRows) === 1, 'filas: ' . count($otherRows));

// ── F. El rango de fechas ───────────────────────────────────────────────────
//
// El BETWEEN es inclusivo en los dos extremos, así que un `to` a las 00:00:00
// (formato que el regex de audit.php acepta) deja afuera TODO lo del día. El
// front manda 23:59:59 y por eso funciona; si alguien "simplifica" el formato
// a YYYY-MM-DD, la página vuelve a verse vacía sin que nada falle.
$todayZero = date('Y-m-d 00:00:00');
$rowsCut   = runAuditSelect($companyId, $from, $todayZero);
check(
    'F1 un `to` a las 00:00:00 deja fuera lo de hoy (por eso el front manda 23:59:59)',
    count($rowsCut) === 0,
    'filas con to=' . $todayZero . ': ' . count($rowsCut)
);

$rowsFull = runAuditSelect($companyId, $from, $to);
check('F2 con el `to` que manda el front, la fila de hoy entra', count($rowsFull) === 1, 'filas: ' . count($rowsFull));

// Rango pasado sin actividad: es el caso legítimo de "no hubo nada", y el
// endpoint tiene que devolver 0 filas sin error. Es lo que la UI debe
// distinguir de "esto está roto".
$rowsPast = runAuditSelect($companyId, '2020-01-01 00:00:00', '2020-01-31 23:59:59');
check('F3 un rango sin actividad devuelve 0 filas sin error', count($rowsPast) === 0, 'filas: ' . count($rowsPast));

// ── G. El early-return de $db deja rastro ───────────────────────────────────
//
// Antes retornaba mudo: si el global no estaba, la auditoría no existía y no
// había una sola línea en ningún lado que lo dijera. Se prueba desde afuera —
// se desarma el global, se llama, y se exige que haya escrito al error_log.
$logFile = tempnam(sys_get_temp_dir(), 'tenant_audit_log_');
$prevLog = ini_get('error_log');
ini_set('error_log', $logFile);

$savedDb = $db;
unset($GLOBALS['db']);
tenantAudit(['companyId' => $companyId, 'userId' => $userId, 'outletId' => $outletId, 'realm' => 'panel'], 'POST', '/v1/items', null, []);
$GLOBALS['db'] = $savedDb;
$db = $savedDb;

ini_set('error_log', $prevLog === false ? '' : (string) $prevLog);
$logged = (string) @file_get_contents($logFile);
@unlink($logFile);

check(
    'G1 sin $db, tenantAudit loguea en vez de retornar mudo',
    str_contains($logged, '[tenantAudit]'),
    'el error_log quedó: ' . var_export($logged, true)
);

// Limpieza.
$db->Execute('DELETE FROM tenant_audit WHERE companyid IN (?, ?)', [$companyId, $otherCompanyId]);

harnessFinish($failures, $checks);
