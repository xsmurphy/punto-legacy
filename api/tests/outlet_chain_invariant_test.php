<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del INVARIANTE DE LA CADENA DE ALTA.
 *
 * ── La regla (owner, 2026-08-24) ────────────────────────────────────────────
 *
 *     Company
 *     └── Outlet (sucursal)          ← el signup crea una llamada "Central"
 *         ├── Location (depósito)    ← hermanos, hijos DIRECTOS del outlet
 *         └── Register (caja)
 *
 * "Estos van encadenados obligatoriamente." Ninguna sucursal puede existir sin
 * su depósito Y su caja, y ningún camino de alta puede saltearse un eslabón.
 *
 * ── Por qué existe este arnés ───────────────────────────────────────────────
 *
 * Un invariante escrito en un doc es un invariante que la próxima persona no
 * aplica. La cadena ya se rompió dos veces por el mismo motivo — un camino de
 * alta nuevo que no llamó a los creadores canónicos:
 *
 *   - `Auth\SignupService` nunca creó un depósito: TODO tenant nacía sin uno
 *     (descubierto y arreglado 2026-08-24, backfill en la mig 165).
 *   - `01_master_admin.sql` crea la sucursal master con SQL crudo y nunca
 *     insertó una caja: era la única sucursal sin caja en producción
 *     (backfill en la mig 166).
 *
 * Los dos agujeros son invisibles hasta que alguien intenta operar. Este arnés
 * los vuelve rojos.
 *
 * ── Qué verifica ────────────────────────────────────────────────────────────
 *
 *   A. ESCANEO GLOBAL — ninguna sucursal de la base, de ningún tenant, sin
 *      depósito o sin caja activa. Cubre seeds, migraciones y cualquier
 *      camino de alta que exista hoy o mañana, sin tener que enumerarlos.
 *   B. `Outlets\OutletsService::create()` con payload — outlet + depósito por
 *      defecto + caja activa, todo junto.
 *   C. El MISMO create() por el camino LEGACY (`$fields = null`, la sucursal
 *      "blank" que el panel viejo crea y edita después) — también encadenado.
 *   D. `Services\RegisterAdminService::delete()` NO puede dejar la sucursal
 *      sin caja: bloquea la última activa (409).
 *   E. …pero tampoco sobre-bloquea: con dos cajas, borrar una funciona.
 *   F. `update(['status' => false])` — el toggle del panel — tampoco puede
 *      dejarla sin caja. Es la SEGUNDA puerta al mismo estado (cero cajas
 *      operables) y se escapa de cualquier guard escrito en el call-site de
 *      `delete()`; de ahí que el guard viva en un método compartido.
 *   G. …pero una caja YA inactiva sí se borra: el guard defiende la cadena, no
 *      la existencia de filas. Sin esto, la sucursal con todas las cajas dadas
 *      de baja quedaba en 409 perpetuo, sin forma de limpiarla.
 *
 * `Auth\SignupService` no se ejercita acá (necesita el request completo del
 * alta: teléfono, roles, ítems demo, país). Su cadena queda cubierta por el
 * escaneo A en cualquier base donde haya corrido un signup.
 *
 * Uso: necesita Postgres migrado + seed.
 *   bash api/tests/run_outlet_chain_invariant_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Outlets\OutletsService;

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

/** Sucursales creadas por el arnés, para el cleanup del final. */
$creados = [];

/**
 * Corre `_register_delete_once_cli.php` y devuelve su envelope JSON.
 *
 * El subproceso NO escupe JSON limpio: `bootstrap.php` imprime avisos propios
 * antes (hoy "[RedisClient] Redis no disponible: extensión phpredis no
 * cargada"). Un `json_decode()` sobre la salida cruda devuelve null y los
 * checks fallan por el ruido, no por el comportamiento — que es exactamente el
 * falso rojo que este arnés existe para no producir.
 *
 * Se recorre la salida de atrás para adelante y se toma la última línea que
 * parsee como objeto JSON: el envelope siempre es la última cosa que se
 * imprime, tanto por `apiError()` como por el `echo` del helper.
 *
 * @return array<string, mixed>|null
 */
function bajaCajaEnSubproceso(string $companyId, string $registerId, string $accion = 'delete'): ?array
{
    $cmd = sprintf(
        'php -d variables_order=EGPCS %s %s %s %s 2>&1',
        escapeshellarg(__DIR__ . '/_register_delete_once_cli.php'),
        escapeshellarg($companyId),
        escapeshellarg($registerId),
        escapeshellarg($accion)
    );
    $salida = (string) shell_exec($cmd);

    $lineas = array_reverse(preg_split('/\R/', trim($salida)) ?: []);
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] !== '{') {
            continue;
        }
        $json = json_decode($linea, true);
        if (is_array($json)) {
            return $json;
        }
    }

    fwrite(STDERR, "Subproceso de delete() sin envelope JSON. Salida cruda:\n$salida\n");
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// A. Escaneo global — el invariante sobre TODA la base
// ═══════════════════════════════════════════════════════════════════════════
//
// `getAssoc` NO se usa acá a propósito: keyea por la primera columna y PISA en
// silencio las filas que la repiten. Con varias sucursales rotas en distintos
// tenants eso escondería justo lo que se está buscando. Recordset y punto.

echo "=== A. Escaneo global: toda sucursal con depósito Y caja activa ===\n\n";

$rs = $db->Execute(
    "SELECT o.outletid,
            o.outletname,
            o.companyid,
            (SELECT count(*) FROM taxonomy t
              WHERE t.outletid = o.outletid AND t.taxonomytype = 'location')::int AS depositos,
            (SELECT count(*) FROM taxonomy t
              WHERE t.outletid = o.outletid
                AND fn_taxonomy_is_default_location(t.taxonomytype, t.taxonomyextra))::int AS defaults,
            (SELECT count(*) FROM register r
              WHERE r.outletid = o.outletid AND r.registerstatus = TRUE)::int AS cajas
       FROM outlet o
      WHERE o.companyid IS NOT NULL"
);

if ($rs === false) {
    fwrite(STDERR, "No se pudo consultar las sucursales — el arnés no puede concluir nada.\n");
    harnessFinish(1, 0);
}

$totalOutlets = 0;
$sinDeposito  = [];
$sinDefault   = [];
$sinCaja      = [];

// forceObj/recordset: se itera con EOF, no como array (ncmExecute con
// forceObj=true devuelve recordset — tratarlo como array da [] siempre).
while (!$rs->EOF) {
    $row = $rs->fields;
    $totalOutlets++;
    $etiqueta = (string) $row['outletname'] . ' [' . (string) $row['outletid'] . ']';

    if ((int) $row['depositos'] === 0) { $sinDeposito[] = $etiqueta; }
    if ((int) $row['defaults']  === 0) { $sinDefault[]  = $etiqueta; }
    if ((int) $row['cajas']     === 0) { $sinCaja[]     = $etiqueta; }

    $rs->MoveNext();
}

printf("  %d sucursal(es) en la base.\n", $totalOutlets);
foreach ($sinDeposito as $o) { printf("  ROTA (sin depósito):      %s\n", $o); }
foreach ($sinDefault  as $o) { printf("  ROTA (sin default):       %s\n", $o); }
foreach ($sinCaja     as $o) { printf("  ROTA (sin caja activa):   %s\n", $o); }

check('sucursales sin depósito',        count($sinDeposito), 0, $failures, $checks);
check('sucursales sin depósito default', count($sinDefault),  0, $failures, $checks);
check('sucursales sin caja activa',     count($sinCaja),     0, $failures, $checks);
// Un escaneo sobre cero filas pasa trivialmente y no prueba nada.
check('la base tiene al menos una sucursal', $totalOutlets > 0, true, $failures, $checks);

// ── Company de trabajo para los casos B-E ───────────────────────────────────
$companyRow = ncmExecute(
    'SELECT companyId FROM company WHERE companyId = ? LIMIT 1',
    ['0ea6c5d8-57e5-4226-8140-ec914deec024'] // tenant "Verify PY" del seed
);
if (!$companyRow) {
    $companyRow = ncmExecute('SELECT companyId FROM company LIMIT 1');
}
if (!$companyRow) {
    fwrite(STDERR, "No hay ninguna company en la base — no se pueden correr los casos B-E.\n");
    harnessFinish(1, $checks);
}
$companyId = (string) $companyRow['companyId'];

/**
 * Estado de la cadena de una sucursal, leído directo de la base.
 *
 * @return array{depositos: int, defaults: int, cajas: int}
 */
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
// B. OutletsService::create() con payload — la cadena entera de una
// ═══════════════════════════════════════════════════════════════════════════

echo "\n=== B. OutletsService::create() con payload ===\n\n";

$svc      = new OutletsService();
$outletB  = $svc->create($companyId, ['name' => 'Arnés Cadena B ' . bin2hex(random_bytes(3))]);
if ($outletB) { $creados[] = (string) $outletB; }

check('B: create() devolvió un outletId', is_string($outletB) && $outletB !== '', true, $failures, $checks);

if ($outletB) {
    $cadB = cadenaDe((string) $outletB);
    check('B: depósitos de la sucursal nueva',        $cadB['depositos'], 1, $failures, $checks);
    check('B: depósito POR DEFECTO marcado',          $cadB['defaults'],  1, $failures, $checks);
    check('B: cajas activas de la sucursal nueva',    $cadB['cajas'],     1, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// C. Camino LEGACY — sucursal "blank" ($fields = null)
// ═══════════════════════════════════════════════════════════════════════════
//
// `api/v1/outlets.php` con {action:create} sin nombre crea una sucursal
// placeholder que el panel viejo edita después. Es un camino de alta real y
// también tiene que quedar encadenado — un eslabón que solo aparece cuando el
// payload viene completo no es un invariante.

echo "\n=== C. Camino legacy: sucursal blank (fields = null) ===\n\n";

$outletC = $svc->create($companyId, null);
if ($outletC) { $creados[] = (string) $outletC; }

check('C: create(null) devolvió un outletId', is_string($outletC) && $outletC !== '', true, $failures, $checks);

if ($outletC) {
    $cadC = cadenaDe((string) $outletC);
    check('C: depósitos de la sucursal blank',        $cadC['depositos'], 1, $failures, $checks);
    check('C: depósito POR DEFECTO marcado',          $cadC['defaults'],  1, $failures, $checks);
    check('C: cajas activas de la sucursal blank',    $cadC['cajas'],     1, $failures, $checks);

    // El depósito de la segunda sucursal NO puede chocar con el de la primera:
    // `uq_taxonomy_company_type_name` (mig 38) es UNIQUE sobre
    // (companyid, taxonomytype, lower(name)) y un nombre fijo reventaría acá.
    // Este check es la regresión de ese bug, que abortaba el alta ENTERA — así
    // que compara los nombres REALES de los dos depósitos, no una tautología:
    // si `ensureDefault()` volviera a un literal fijo, acá saldrían iguales.
    $nombreB = ncmExecute(
        "SELECT taxonomyname FROM taxonomy WHERE outletid = ? AND taxonomytype = 'location' LIMIT 1",
        [(string) $outletB]
    );
    $nombreC = ncmExecute(
        "SELECT taxonomyname FROM taxonomy WHERE outletid = ? AND taxonomytype = 'location' LIMIT 1",
        [(string) $outletC]
    );
    $nB = (string) ($nombreB['taxonomyname'] ?? '');
    $nC = (string) ($nombreC['taxonomyname'] ?? '');

    check('C: el depósito de B tiene nombre',            $nB !== '',                       true, $failures, $checks);
    check('C: el depósito de C tiene nombre',            $nC !== '',                       true, $failures, $checks);
    check('C: los dos depósitos NO comparten nombre',    strtolower($nB) !== strtolower($nC), true, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// D. El guard: no se puede eliminar la última caja de la sucursal
// ═══════════════════════════════════════════════════════════════════════════
//
// Subproceso: `delete()` responde por `apiError()`, que hace exit (ver
// `_register_delete_once_cli.php`).

echo "\n=== D. delete() bloquea la última caja de la sucursal ===\n\n";

$cajaUnica = null;
if ($outletB) {
    $r = ncmExecute(
        'SELECT registerId FROM register WHERE outletId = ? AND registerStatus = TRUE LIMIT 1',
        [(string) $outletB]
    );
    $cajaUnica = $r ? (string) $r['registerId'] : null;
}

check('D: la sucursal B tiene una caja para intentar borrar', $cajaUnica !== null, true, $failures, $checks);

if ($cajaUnica !== null) {
    $json = bajaCajaEnSubproceso($companyId, $cajaUnica, 'delete');

    check('D: delete() de la última caja NO devolvió ok', ($json['ok'] ?? null) === false, true, $failures, $checks);
    check(
        'D: el motivo es la última caja',
        is_array($json) && str_contains((string) ($json['error']['message'] ?? ''), 'última caja'),
        true,
        $failures,
        $checks
    );
    check('D: el status es 409', (int) ($json['error']['code'] ?? 0), 409, $failures, $checks);

    // Lo que realmente importa: la caja SIGUE ahí y activa.
    $cadD = cadenaDe((string) $outletB);
    check('D: la sucursal conserva su caja activa', $cadD['cajas'], 1, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// E. …y el guard NO sobre-bloquea: con dos cajas, borrar una funciona
// ═══════════════════════════════════════════════════════════════════════════
//
// Un guard que bloquea siempre "pasa" el caso D por accidente. Este caso
// separa "protege la cadena" de "rompió el borrado de cajas".

echo "\n=== E. Con dos cajas, delete() sí borra ===\n\n";

if ($outletB) {
    $segunda = ncmInsert(['records' => [
        'registerName'   => 'Arnés Caja Extra',
        'registerStatus' => 1,
        'outletId'       => (string) $outletB,
        'companyId'      => $companyId,
    ], 'table' => 'register']);

    check('E: se creó una segunda caja', is_string($segunda) && $segunda !== '', true, $failures, $checks);

    if ($segunda) {
        $json = bajaCajaEnSubproceso($companyId, (string) $segunda, 'delete');

        check('E: delete() de la segunda caja devolvió ok', ($json['ok'] ?? null) === true, true, $failures, $checks);

        $cadE = cadenaDe((string) $outletB);
        check('E: la sucursal queda con su caja original', $cadE['cajas'], 1, $failures, $checks);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// F. La otra puerta: update({status: false}) sobre la última caja
// ═══════════════════════════════════════════════════════════════════════════
//
// Desactivar la última caja deja la sucursal EXACTAMENTE igual de muerta que
// borrarla, y es el camino que usa el toggle del panel
// (`api/v1/register.php` action=update). Un guard que solo vive en `delete()`
// deja esta puerta abierta y el arnés canta verde igual — que es justo el
// falso verde que este caso cierra.

echo "\n=== F. update(status:false) también respeta la cadena ===\n\n";

if ($outletC) {
    $rC = ncmExecute(
        'SELECT registerId FROM register WHERE outletId = ? AND registerStatus = TRUE LIMIT 1',
        [(string) $outletC]
    );
    $cajaUnicaC = $rC ? (string) $rC['registerId'] : null;

    check('F: la sucursal C tiene una caja para intentar desactivar', $cajaUnicaC !== null, true, $failures, $checks);

    if ($cajaUnicaC !== null) {
        $json = bajaCajaEnSubproceso($companyId, $cajaUnicaC, 'deactivate');

        check('F: update(status:false) NO devolvió ok', ($json['ok'] ?? null) === false, true, $failures, $checks);
        check(
            'F: el motivo es la última caja',
            is_array($json) && str_contains((string) ($json['error']['message'] ?? ''), 'última caja'),
            true,
            $failures,
            $checks
        );
        check('F: el status es 409', (int) ($json['error']['code'] ?? 0), 409, $failures, $checks);

        $cadF = cadenaDe((string) $outletC);
        check('F: la sucursal conserva su caja activa', $cadF['cajas'], 1, $failures, $checks);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// G. Una caja YA inactiva sí se puede borrar (no hay 409 perpetuo)
// ═══════════════════════════════════════════════════════════════════════════
//
// El guard defiende la cadena, no la existencia de filas. Una caja ya dada de
// baja no es un eslabón vigente: si igual se le exigiera que quede otra activa,
// la sucursal cuyas cajas están TODAS inactivas — el estado que la mig 166 deja
// explícitamente para decisión humana — no podría limpiarse nunca desde el
// panel.
//
// El fixture se arma con SQL directo justamente porque el guard impide llegar a
// ese estado por la API.

echo "\n=== G. La caja ya inactiva se puede borrar ===\n\n";

$outletG = $svc->create($companyId, ['name' => 'Arnés Cadena G ' . bin2hex(random_bytes(3))]);
if ($outletG) { $creados[] = (string) $outletG; }

check('G: se creó la sucursal del caso', is_string($outletG) && $outletG !== '', true, $failures, $checks);

if ($outletG) {
    $rG = ncmExecute(
        'SELECT registerId FROM register WHERE outletId = ? LIMIT 1',
        [(string) $outletG]
    );
    $cajaG = $rG ? (string) $rG['registerId'] : null;

    // Baja "a mano" — deja la sucursal con cero cajas activas, que es
    // exactamente el estado heredado que hay que poder resolver.
    $db->Execute('UPDATE register SET registerStatus = FALSE WHERE registerId = ?', [$cajaG]);

    $cadGPrevia = cadenaDe((string) $outletG);
    check('G: la sucursal quedó sin cajas activas', $cadGPrevia['cajas'], 0, $failures, $checks);

    $json = bajaCajaEnSubproceso($companyId, (string) $cajaG, 'delete');

    check('G: delete() de la caja inactiva devolvió ok', ($json['ok'] ?? null) === true, true, $failures, $checks);

    $quedan = ncmExecute(
        'SELECT COUNT(*)::int AS cnt FROM register WHERE outletId = ?',
        [(string) $outletG]
    );
    check('G: la caja se borró de verdad', (int) ($quedan['cnt'] ?? -1), 0, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// H. Dos cajas NO comparten (timbrado, punto de expedición) — ni al CREARLAS
// ═══════════════════════════════════════════════════════════════════════════
//
// La numeración correlativa es por TALONARIO, y un talonario es un timbrado en
// un punto de expedición. Dos cajas con el mismo par llevan la misma secuencia
// y, en cuanto las dos emiten offline sin verse, salen dos facturas con el
// mismo número: un documento duplicado ante la SET (context/29). No es un
// choque de datos que se arregle borrando una fila.
//
// Se prueba por el camino de ALTA y no solo por el de edición porque desde
// 2026-09-01 hay un segundo creador de cajas: el agente IA (`create_register`,
// context/66 F1) crea con timbrado incluido, sin pasar nunca por `update()`.
// Y se prueba en proceso, sin subproceso, porque el servicio ahora LANZA
// (`RegisterAdminException`) en vez de hacer `exit` — que es exactamente lo
// que el lote del agente necesita para reportar el fallo de UNA acción sin
// llevarse puestas las demás.

echo "\n=== H. Punto de expedición único por timbrado, también en el alta ===\n\n";

require_once dirname(__DIR__) . '/lib/services/RegisterAdminService.php';

$outletH = $svc->create($companyId, ['name' => 'Arnés Timbrado H ' . bin2hex(random_bytes(3))]);
if ($outletH) { $creados[] = (string) $outletH; }

if ($outletH) {
    $svcCajas = new \Punto\Api\Services\RegisterAdminService($companyId);
    $fiscal   = ['fiscal' => ['invoiceAuth' => '16001234', 'invoicePrefix' => '001-007']];

    $primera = null;
    try {
        $primera = $svcCajas->create((string) $outletH, 'Arnés Caja Timbrada', $fiscal);
    } catch (\Throwable $e) {
        check('H: la primera caja con timbrado se creó', 'excepción: ' . $e->getMessage(), 'sin excepción', $failures, $checks);
    }
    check('H: la primera caja con timbrado se creó', is_array($primera) && !empty($primera['id']), true, $failures, $checks);

    // Misma sucursal, mismo timbrado, mismo EEE-PPP: tiene que rechazarse.
    $mensaje = null;
    $codigo  = null;
    try {
        $svcCajas->create((string) $outletH, 'Arnés Caja Duplicada', $fiscal);
    } catch (\Punto\Api\Services\RegisterAdminException $e) {
        $mensaje = $e->getMessage();
        $codigo  = $e->httpCode();
    }
    check('H: la segunda caja con el mismo par fue rechazada', $mensaje !== null, true, $failures, $checks);
    check('H: el rechazo nombra el punto de expedición',
        $mensaje !== null && str_contains($mensaje, '001-007'), true, $failures, $checks);
    check('H: el status del rechazo es 409', $codigo, 409, $failures, $checks);

    // Y el rechazo no dejó una caja a medias: la transacción del alta revierte
    // entera. Sin esto quedaría una caja sin timbrado que el panel muestra como
    // operable y que no puede emitir.
    $aMedias = ncmExecute(
        'SELECT COUNT(*)::int AS cnt FROM register WHERE outletId = ? AND registerName = ?',
        [(string) $outletH, 'Arnés Caja Duplicada']
    );
    check('H: la caja rechazada no quedó creada a medias', (int) ($aMedias['cnt'] ?? -1), 0, $failures, $checks);

    // El MISMO EEE-PPP con OTRO timbrado sí puede: son dos talonarios
    // independientes y cada uno lleva su propia secuencia.
    $otroTimbrado = null;
    try {
        $otroTimbrado = $svcCajas->create((string) $outletH, 'Arnés Caja Otro Timbrado', [
            'fiscal' => ['invoiceAuth' => '16009999', 'invoicePrefix' => '001-007'],
        ]);
    } catch (\Throwable $e) {
        check('H: mismo EEE-PPP con otro timbrado se permite', 'excepción: ' . $e->getMessage(), 'sin excepción', $failures, $checks);
    }
    check('H: mismo EEE-PPP con otro timbrado se permite',
        is_array($otroTimbrado) && !empty($otroTimbrado['id']), true, $failures, $checks);
}

// ═══════════════════════════════════════════════════════════════════════════
// Cleanup — las sucursales del arnés se van con toda su cadena
// ═══════════════════════════════════════════════════════════════════════════
//
// A mano y no con `OutletsService::delete()` para que el cleanup no dependa
// del código bajo prueba: si el cascade se rompe, el arnés tiene que fallar
// por sus checks, no dejar basura silenciosa.

foreach ($creados as $oid) {
    // Las secuencias van ANTES que las cajas: su scopeId es el registerId y
    // sin las cajas ya no hay forma de saber cuáles borrar.
    $db->Execute(
        'DELETE FROM document_sequence WHERE scopeid IN (SELECT registerId FROM register WHERE outletId = ?)',
        [$oid]
    );
    $db->Execute('DELETE FROM register WHERE outletId = ?', [$oid]);
    $db->Execute('DELETE FROM taxonomy WHERE outletId = ?', [$oid]);
    $db->Execute('DELETE FROM inventory WHERE outletId = ?', [$oid]);
    $db->Execute('DELETE FROM outlet   WHERE outletId = ?', [$oid]);
}

harnessFinish($failures, $checks);
