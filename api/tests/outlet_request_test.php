<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de las SOLICITUDES DE ALTA DE SUCURSAL (`outlet_request`, mig 219).
 *
 * ── La regla (owner, 2026-09-11) ────────────────────────────────────────────
 *
 * Cada sucursal se factura al PRECIO DEL PLAN del tenant por mes. Crear una
 * sucursal deja de ser configuración y pasa a ser un hecho comercial: el
 * comercio la PIDE y Punto la aprueba. Nadie se auto-aprovisiona facturación.
 *
 * ── Qué verifica ────────────────────────────────────────────────────────────
 *
 *   A. `status()` con plan SIN precio (trial / plan 0) — no inventa un monto:
 *      `price`, `currentMonthly` y `nextMonthly` son null. El diálogo del
 *      panel depende de poder distinguir "gratis" de "todavía no sabemos".
 *   B. `create()` registra la solicitud y NO crea ninguna sucursal.
 *   C. Una SEGUNDA pendiente se rechaza con 409.
 *   D. El índice único parcial lo garantiza la BASE, no el `if` de PHP: un
 *      INSERT directo de una segunda pendiente falla. Es la carrera que dos
 *      pestañas del panel producen y que ningún chequeo de aplicación cierra.
 *   E. Rechazar SIN motivo es 422 — el motivo es obligatorio.
 *   F. Rechazar con motivo resuelve, libera la pendiente y no crea sucursal.
 *   G. Aprobar CREA la sucursal por el servicio real, con su depósito default
 *      y su caja (la cadena de `outlet_chain_invariant_test.php`), guarda el
 *      `outletId` en la solicitud y respeta nombre y dirección pedidos.
 *   H. Resolver dos veces la misma solicitud es 409.
 *   I. `status()` con plan CON precio: el total mensual es precio × sucursales
 *      activas, y el próximo suma una más. Es la cifra que el paywall le
 *      promete al comercio.
 *
 * La AUDITORÍA del pedido no se ejercita acá: la escribe el endpoint
 * (`api/v1/outlet-requests.php`), no el servicio, y necesita el request HTTP
 * completo. Queda cubierta por `tenant_audit_test.php` + el arnés de permisos.
 *
 * Uso: necesita Postgres migrado + seed.
 *   bash api/tests/run_outlet_request_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Outlets\OutletRequestService;

$failures = 0;
$checks   = 0;

function check(string $label, mixed $got, mixed $want, int &$failures, int &$checks): void
{
    $checks++;
    if ($got === $want) {
        printf("  OK    %-58s = %s\n", $label, var_export($got, true));
        return;
    }
    $failures++;
    printf("  FALLA %-58s esperado %s, obtenido %s\n", $label, var_export($want, true), var_export($got, true));
}

global $db;

// ── Company de trabajo ──────────────────────────────────────────────────────
$companyRow = ncmExecute(
    'SELECT companyId, plan FROM company WHERE companyId = ? LIMIT 1',
    ['0ea6c5d8-57e5-4226-8140-ec914deec024'] // tenant "Verify PY" del seed
);
if (!$companyRow) {
    $companyRow = ncmExecute('SELECT companyId, plan FROM company LIMIT 1');
}
if (!$companyRow) {
    fwrite(STDERR, "No hay ninguna company en la base — el arnés no puede concluir nada.\n");
    harnessFinish(1, 0);
}
$companyId   = (string) $companyRow['companyId'];
$planOrigen  = (int) ($companyRow['plan'] ?? 0);

/** Sucursales creadas por el arnés, para el cleanup. */
$outletsCreados = [];
/** plan_code de prueba, para el cleanup. */
const PLAN_CODE_TEST = 777;
const PRECIO_TEST    = 295000.0;

$svc = new OutletRequestService();

/** Deja la empresa sin solicitudes: el arnés arranca siempre del mismo estado. */
$db->Execute('DELETE FROM outlet_request WHERE companyId = ?', [$companyId]);

/**
 * Resuelve una solicitud en un SUBPROCESO con los requires del realm `admin`
 * (sin `bootstrap.php`), que es donde /admin la resuelve de verdad.
 *
 * @return array<string,mixed> envelope del helper, o ['fatal' => …] si la
 *                             cadena de carga del realm admin está rota.
 */
function resolverComoAdmin(string $requestId, string $accion, ?string $motivo = null): array
{
    $cmd = sprintf(
        'php -d variables_order=EGPCS %s %s %s%s 2>&1',
        escapeshellarg(__DIR__ . '/_outlet_request_admin_once_cli.php'),
        escapeshellarg($requestId),
        escapeshellarg($accion),
        $motivo !== null ? ' ' . escapeshellarg($motivo) : ''
    );
    $salida = (string) shell_exec($cmd);

    // El envelope es la última línea que parsea como objeto: antes hay ruido
    // del arranque (avisos de Redis, etc.).
    foreach (array_reverse(preg_split('/\R/', trim($salida)) ?: []) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] !== '{') {
            continue;
        }
        $json = json_decode($linea, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return ['fatal' => 'el subproceso no devolvió envelope. Salida cruda: ' . $salida];
}

/** Sucursales ACTIVAS de la empresa (lo que se factura). */
function activas(string $companyId): int
{
    $r = ncmExecute(
        'SELECT count(*)::int AS n FROM outlet WHERE companyId = ? AND outletStatus = 1',
        [$companyId]
    );
    return (int) ($r['n'] ?? 0);
}

/** @return array{depositos:int, defaults:int, cajas:int} */
function cadenaDe(string $outletId): array
{
    $row = ncmExecute(
        "SELECT (SELECT count(*) FROM taxonomy t
                  WHERE t.outletid = ? AND t.taxonomytype = 'location')::int AS depositos,
                (SELECT count(*) FROM taxonomy t
                  WHERE t.outletid = ?
                    AND fn_taxonomy_is_default_location(t.taxonomytype, t.taxonomyextra))::int AS defaults,
                (SELECT count(*) FROM register r
                  WHERE r.outletid = ? AND r.registerstatus = TRUE)::int AS cajas",
        [$outletId, $outletId, $outletId]
    );
    return [
        'depositos' => (int) ($row['depositos'] ?? 0),
        'defaults'  => (int) ($row['defaults']  ?? 0),
        'cajas'     => (int) ($row['cajas']     ?? 0),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// A. status() con plan SIN precio — no se inventa un monto
// ═══════════════════════════════════════════════════════════════════════════

echo "=== A. status() con plan sin precio ===\n\n";

// Plan 0 = el default/interno, sin precio.
$db->Execute('UPDATE company SET plan = 0 WHERE companyId = ?', [$companyId]);

$st = $svc->status($companyId);
check('A. pending sin solicitudes',        $st['pending'],        null, $failures, $checks);
check('A. plan.price sin precio',          $st['plan']['price'],  null, $failures, $checks);
check('A. currentMonthly sin precio',      $st['currentMonthly'], null, $failures, $checks);
check('A. nextMonthly sin precio',         $st['nextMonthly'],    null, $failures, $checks);
check('A. outletCount > 0',                $st['outletCount'] > 0, true, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// B. create() registra la solicitud y NO crea sucursal
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== B. create() no crea la sucursal ===\n\n";

$antes = activas($companyId);
$res   = $svc->create($companyId, null, '  Sucursal Arnés 1  ', 'Av. Siempreviva 742');

check('B. create ok',                 $res['ok'] ?? false, true, $failures, $checks);
check('B. sucursales sin cambios',    activas($companyId), $antes, $failures, $checks);

$pend = $svc->pending($companyId);
check('B. hay una pendiente',         $pend !== null, true, $failures, $checks);
check('B. nombre trimmeado',          $pend['name'] ?? null, 'Sucursal Arnés 1', $failures, $checks);
check('B. dirección guardada',        $pend['address'] ?? null, 'Av. Siempreviva 742', $failures, $checks);
check('B. estado pending',            $pend['status'] ?? null, 'pending', $failures, $checks);

$requestId1 = (string) ($res['requestId'] ?? '');

// ═══════════════════════════════════════════════════════════════════════════
// C. una sola pendiente por empresa (camino de aplicación)
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== C. segunda solicitud pendiente rechazada ===\n\n";

$dup = $svc->create($companyId, null, 'Sucursal Arnés 2', null);
check('C. segunda create rechazada',  $dup['ok'] ?? true, false, $failures, $checks);
check('C. código 409',                $dup['code'] ?? 0, 409, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// D. …y lo garantiza la BASE, no el if de PHP
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== D. índice único parcial (la carrera de dos pestañas) ===\n\n";

// INSERT directo, saltándose el servicio: es exactamente lo que consigue una
// segunda request concurrente que pasó el SELECT antes de que la primera
// commiteara. El wrapper LANZA (context/08) — de ahí el try/catch, y de ahí
// que `create()` tenga que atrapar la 23505 para devolver 409 y no un 500.
$sqlState = '';
try {
    $db->Execute(
        "INSERT INTO outlet_request (companyId, name, status) VALUES (?, ?, 'pending')",
        [$companyId, 'Colada por la carrera']
    );
} catch (\Punto\Api\Support\DbQueryException $e) {
    $sqlState = $e->sqlState();
}
check('D. INSERT directo viola el índice (23505)', $sqlState, '23505', $failures, $checks);
// `create()` mapea esa misma 23505 a un 409 (ver su catch). El camino no se
// puede reproducir en un solo proceso —hace falta concurrencia real para que
// el SELECT previo no la vea—, pero el 23505 de acá es la prueba de que la
// base lo produce y de que el catch tiene a qué responder.

check('D. sigue habiendo UNA sola pendiente',
    (int) (ncmExecute(
        "SELECT count(*)::int AS n FROM outlet_request WHERE companyId = ? AND status = 'pending'",
        [$companyId]
    )['n'] ?? 0),
    1, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// E. rechazar sin motivo es 422
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== E. el motivo del rechazo es obligatorio ===\n\n";

$sinMotivo = $svc->resolve($requestId1, false, null, 'arnes@punto.la');
check('E. rechazo sin motivo falla',  $sinMotivo['ok'] ?? true, false, $failures, $checks);
check('E. código 422',                $sinMotivo['code'] ?? 0, 422, $failures, $checks);

$vacio = $svc->resolve($requestId1, false, '   ', 'arnes@punto.la');
check('E. motivo en blanco tampoco',  $vacio['code'] ?? 0, 422, $failures, $checks);
check('E. la solicitud sigue pending', $svc->pending($companyId) !== null, true, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// F. rechazar con motivo resuelve y libera la pendiente
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== F. rechazo con motivo ===\n\n";

$antes = activas($companyId);
$rej   = $svc->resolve($requestId1, false, 'La cuenta tiene facturas vencidas', 'arnes@punto.la');

check('F. rechazo ok',                $rej['ok'] ?? false, true, $failures, $checks);
check('F. status rejected',           $rej['status'] ?? null, 'rejected', $failures, $checks);
check('F. NO creó sucursal',          activas($companyId), $antes, $failures, $checks);
check('F. ya no hay pendiente',       $svc->pending($companyId), null, $failures, $checks);

$fila = ncmExecute('SELECT reason, resolvedBy, outletId FROM outlet_request WHERE id = ?', [$requestId1]);
check('F. motivo persistido',         (string) ($fila['reason'] ?? ''), 'La cuenta tiene facturas vencidas', $failures, $checks);
check('F. resolvedBy persistido',     (string) ($fila['resolvedBy'] ?? ''), 'arnes@punto.la', $failures, $checks);
check('F. sin outletId',              $fila['outletId'] ?? null, null, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// G. aprobar CREA la sucursal por el servicio real, con la cadena completa
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== G. aprobación: alta real + cadena depósito/caja ===\n\n";

$res2 = $svc->create($companyId, null, 'Sucursal Arnés Aprobada', 'Ruta 1 km 20');
check('G. nueva solicitud ok',        $res2['ok'] ?? false, true, $failures, $checks);
$requestId2 = (string) ($res2['requestId'] ?? '');

// La aprobación se ejercita en un SUBPROCESO que carga solo lo que carga un
// endpoint del realm admin — que es donde esto corre de verdad. Llamar
// `$svc->resolve()` acá daría verde con el bootstrap del tenant puesto y
// escondería un "Class not found" garantizado en producción (ver el docblock
// de `_outlet_request_admin_once_cli.php`).
$antes = activas($companyId);
$app   = resolverComoAdmin($requestId2, 'approve');

check('G. sin fatal en el realm admin', $app['fatal'] ?? null, null, $failures, $checks);
check('G. aprobación ok',             $app['ok'] ?? false, true, $failures, $checks);
check('G. status approved',           $app['status'] ?? null, 'approved', $failures, $checks);
check('G. una sucursal más',          activas($companyId), $antes + 1, $failures, $checks);

$nuevoOutletId = (string) ($app['outletId'] ?? '');
check('G. devuelve outletId',         $nuevoOutletId !== '', true, $failures, $checks);
if ($nuevoOutletId !== '') {
    $outletsCreados[] = $nuevoOutletId;

    $o = ncmExecute('SELECT outletName, outletStatus FROM outlet WHERE outletId = ? AND companyId = ?', [$nuevoOutletId, $companyId]);
    check('G. nombre pedido',         (string) ($o['outletName'] ?? ''), 'Sucursal Arnés Aprobada', $failures, $checks);
    check('G. sucursal activa',       (int) ($o['outletStatus'] ?? 0), 1, $failures, $checks);

    $dir = ncmExecute("SELECT data->>'outletAddress' AS addr FROM outlet WHERE outletId = ?", [$nuevoOutletId]);
    check('G. dirección pedida',      (string) ($dir['addr'] ?? ''), 'Ruta 1 km 20', $failures, $checks);

    $cadena = cadenaDe($nuevoOutletId);
    check('G. depósitos',             $cadena['depositos'] >= 1, true, $failures, $checks);
    check('G. depósito por defecto',  $cadena['defaults'],  1,   $failures, $checks);
    check('G. caja activa',           $cadena['cajas'] >= 1, true, $failures, $checks);

    $link = ncmExecute('SELECT outletId FROM outlet_request WHERE id = ?', [$requestId2]);
    check('G. solicitud enlazada al outlet', (string) ($link['outletId'] ?? ''), $nuevoOutletId, $failures, $checks);
}

check('G. ya no hay pendiente',       $svc->pending($companyId), null, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// H. resolver dos veces la misma solicitud
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== H. doble resolución ===\n\n";

$antes  = activas($companyId);
$otra   = $svc->resolve($requestId2, true, null, 'arnes@punto.la');
check('H. segunda resolución falla',  $otra['ok'] ?? true, false, $failures, $checks);
check('H. código 409',                $otra['code'] ?? 0, 409, $failures, $checks);
check('H. no creó otra sucursal',     activas($companyId), $antes, $failures, $checks);

$inexistente = $svc->resolve('00000000-0000-0000-0000-0000000000ff', true, null, 'arnes@punto.la');
check('H. solicitud inexistente 404', $inexistente['code'] ?? 0, 404, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// I. status() con plan CON precio — la cifra que promete el paywall
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== I. status() con plan con precio ===\n\n";

$db->Execute(
    'INSERT INTO plans (name, type, price, duration_days, plan_code)
     VALUES (?, ?, ?, 30, ?)
     ON CONFLICT DO NOTHING',
    ['Plan Arnés', 'monthly', PRECIO_TEST, PLAN_CODE_TEST]
);
$db->Execute('UPDATE company SET plan = ? WHERE companyId = ?', [PLAN_CODE_TEST, $companyId]);

$st       = $svc->status($companyId);
$cantidad = activas($companyId);

check('I. precio del plan',           $st['plan']['price'], PRECIO_TEST, $failures, $checks);
check('I. sucursales contadas',       $st['outletCount'], $cantidad, $failures, $checks);
check('I. total mensual actual',      $st['currentMonthly'], PRECIO_TEST * $cantidad, $failures, $checks);
check('I. total mensual con una más', $st['nextMonthly'], PRECIO_TEST * ($cantidad + 1), $failures, $checks);
check('I. el salto es un plan',       $st['nextMonthly'] - $st['currentMonthly'], PRECIO_TEST, $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
// J. el paywall se hace cumplir en el ÚNICO creador
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== J. OutletsService::create() falla cerrado sin origen aprobado ===\n\n";

// Si esto deja de ser rojo, el alta directa vuelve a estar abierta y la
// solicitud pasa a ser un formulario decorativo: había DOS puertas con la
// misma clave de permiso (`POST /v1/outlets?action=create` y la acción
// `create_outlet` del asistente IA).
$antes    = activas($companyId);
$rechazo  = null;
try {
    (new \Punto\Api\Outlets\OutletsService())->create($companyId, ['name' => 'Colada sin aprobar']);
} catch (\DomainException $e) {
    $rechazo = $e->getMessage();
}
check('J. alta sin origen rechazada',  $rechazo !== null, true, $failures, $checks);
check('J. no se creó ninguna sucursal', activas($companyId), $antes, $failures, $checks);

// …y el origen de soporte (migrador ENCOM) sí puede.
$idSoporte = (new \Punto\Api\Outlets\OutletsService())->create(
    $companyId,
    ['name' => 'Sucursal Arnés Soporte'],
    \Punto\Api\Outlets\OutletsService::ORIGIN_SUPPORT
);
check('J. origen soporte permitido',   is_string($idSoporte) && $idSoporte !== '', true, $failures, $checks);
if (is_string($idSoporte) && $idSoporte !== '') {
    $outletsCreados[] = $idSoporte;
}

// ═══════════════════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== Cleanup ===\n\n";

$db->Execute('UPDATE company SET plan = ? WHERE companyId = ?', [$planOrigen, $companyId]);
$db->Execute('DELETE FROM plans WHERE plan_code = ?', [PLAN_CODE_TEST]);
$db->Execute('DELETE FROM outlet_request WHERE companyId = ?', [$companyId]);

foreach ($outletsCreados as $oid) {
    $db->Execute('DELETE FROM register  WHERE outletId = ?', [$oid]);
    $db->Execute('DELETE FROM taxonomy  WHERE outletId = ?', [$oid]);
    $db->Execute('DELETE FROM outlet    WHERE outletId = ? AND companyId = ?', [$oid, $companyId]);
    printf("  limpiada sucursal %s\n", $oid);
}

harnessFinish($failures, $checks);
