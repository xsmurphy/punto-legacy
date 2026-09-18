<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) del legajo de empleados — RRHH F0
 * (context/83, mig 229 + `EmployeeService`).
 *
 * Qué protege:
 *
 *   a. La migración 229 corrió: `employee` y `employee_attachment` existen.
 *   b. Alta: el legajo CUELGA de una persona (`contactId`, mig 233) y no se
 *      puede crear sin ella; normalización camelCase → columnas; y que los
 *      tres esquemas de remuneración CONVIVAN en la misma fila (fijo + hora +
 *      comisión). Es la D1 del plan: si un día alguien los convierte en un
 *      enum excluyente, esto falla.
 *   c. Edición PARCIAL: un patch que toca el puesto no puede borrar el resto
 *      de los campos ya cargados.
 *   d. EGRESO: `terminate()` escribe la fecha y NO borra ni archiva la fila —
 *      el legajo es historial laboral. Y no se puede egresar dos veces.
 *   e. Aislamiento multi-tenant: un empleado de la empresa A no se lee, no se
 *      edita, no se egresa y no se archiva desde la empresa B. Cuatro verbos,
 *      no uno: el aislamiento se rompe por el que nadie probó.
 *   f. Referencias validadas contra el TENANT: un `outletId` o un `contactId`
 *      de otra empresa se rechazan aunque la FK los acepte (apunta a la tabla,
 *      no al comercio). Y el `contactId` tiene que ser type=0: un CLIENTE no
 *      puede tener legajo.
 *   i. §9.1: la identidad NO se copia — el nombre sale de `contact`, el legajo
 *      no cambia de dueño, y una persona no puede tener dos legajos.
 *   g. Invariantes de la BD con mensaje entendible: egreso anterior al
 *      ingreso, monto fijo sin periodicidad, periodicidad inválida, la PK (una
 *      persona, un legajo) y el índice único del documento.
 *   h. `archive()` saca la fila del listado sin borrarla, y `terminate()` NO
 *      la saca (sigue apareciendo como egresada).
 *
 * Fixture propio con UUIDs fijos de este arnés, DOS empresas (para el
 * aislamiento) y borrado al empezar y al terminar.
 *
 * Uso (necesita Postgres migrado — ver `run_employee_legajo_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/employee_legajo_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

$companyIdConst = 'e33b1eea-0000-4000-8000-000000000101';
$outletIdConst  = 'e33b1eea-0000-4000-8000-000000000102';
$userIdConst    = 'e33b1eea-0000-4000-8000-000000000103';
// Segunda empresa: la vecina contra la que se prueba el aislamiento.
$otherCompanyId = 'e33b1eea-0000-4000-8000-000000000201';
$otherOutletId  = 'e33b1eea-0000-4000-8000-000000000202';
$otherUserId    = 'e33b1eea-0000-4000-8000-000000000203';
// Un CLIENTE (type=1) del mismo comercio: no puede tener legajo.
$customerId     = 'e33b1eea-0000-4000-8000-000000000104';
// Una persona por legajo (mig 233). Estos son los dueños de los dos legajos
// que arma el test, más uno suelto para los casos que necesitan una persona
// libre.
$cocineraId     = 'e33b1eea-0000-4000-8000-000000000105';
$vendedorId     = 'e33b1eea-0000-4000-8000-000000000106';
$sparePersonId  = 'e33b1eea-0000-4000-8000-000000000107';
// Dos más, porque desde la mig 233 la PK es la persona: un legajo archivado la
// deja ocupada para siempre, así que cada caso que CREA una fila necesita la
// suya. Los casos que solo esperan una excepción comparten `$sparePersonId`.
$spare2Id       = 'e33b1eea-0000-4000-8000-000000000108';
$spare3Id       = 'e33b1eea-0000-4000-8000-000000000109';

define('COMPANY_ID', $companyIdConst);
define('OUTLET_ID',  $outletIdConst);
define('USER_ID',    $userIdConst);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Hr\EmployeeService;

$companyId = $companyIdConst;
$outletId  = $outletIdConst;
$userId    = $userIdConst;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/** Corre $fn y devuelve el mensaje de la excepción, o null si no lanzó. */
function expectThrow(callable $fn): ?string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

/** true si la tabla existe — es el chequeo de que la migración corrió. */
function hasTable(string $table): bool
{
    $row = ncmExecute(
        'SELECT 1 AS ok FROM information_schema.tables
          WHERE table_name = ? AND table_schema = current_schema() LIMIT 1',
        [$table]
    );
    return (bool) $row;
}

/** Los montos viajan por NUMERIC y vuelven como string. */
function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.005;
}

// ── Fixture ──────────────────────────────────────────────────────────────────

global $db;

$companyConfig = static fn(string $name): string => json_encode([
    'settingName'              => $name,
    'settingDecimal'           => 'no',
    'settingThousandSeparator' => 'dot',
    'settingCountry'           => 'PY',
    'settingCurrency'          => 'PYG',
    'settingTimeZone'          => 'America/Asuncion',
    'settingTaxName'           => 'IVA',
    'settingLanguage'          => 'es',
    'settingSocialMedia'       => '{}',
    'settingObj'               => '{}',
]);

foreach ([[$companyId, 'Legajo Test'], [$otherCompanyId, 'Legajo Test Vecina']] as [$cid, $cname]) {
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
         ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
        [$cid, $companyConfig($cname)]
    );
}
foreach ([[$outletId, $companyId, 'Legajo Test - Sucursal'],
          [$otherOutletId, $otherCompanyId, 'Legajo Test - Sucursal vecina']] as [$oid, $cid, $oname]) {
    $db->Execute(
        "INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
         VALUES (?, ?, 1, ?)
         ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
        [$oid, $oname, $cid]
    );
}
// type=0 = usuario del sistema; type=1 = cliente.
//
// Desde la mig 233 CADA legajo necesita su propia persona: el legajo es un
// satélite 1:1 del contacto, así que el fixture arma tantos usuarios como
// legajos vaya a crear, más los de los casos de error.
foreach ([[$userId, $companyId, 'Usuaria del sistema', 0],
          [$cocineraId, $companyId, 'Rosa Benítez', 0],
          [$vendedorId, $companyId, 'Aldo Cáceres', 0],
          [$sparePersonId, $companyId, 'Persona de repuesto', 0],
          [$spare2Id, $companyId, 'Persona de repuesto 2', 0],
          [$spare3Id, $companyId, 'Persona de repuesto 3', 0],
          [$otherUserId, $otherCompanyId, 'Usuario de la vecina', 0],
          [$customerId, $companyId, 'Cliente cualquiera', 1]] as [$cid2, $cid, $cname2, $ctype]) {
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
         VALUES (?, ?, ?, NULL, ?, 1)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName',
        [$cid2, $cname2, $cid, $ctype]
    );
}

$resetFixture = static function () use ($db, $companyId, $otherCompanyId): void {
    $db->Execute('DELETE FROM employee_attachment WHERE companyid IN (?, ?)', [$companyId, $otherCompanyId]);
    $db->Execute('DELETE FROM employee WHERE companyid IN (?, ?)', [$companyId, $otherCompanyId]);
};
$resetFixture();

$svc = new EmployeeService();

// ── a. La migración corrió ───────────────────────────────────────────────────

check('existe la tabla employee', hasTable('employee'), 'la mig 229 no aplicó', $failures, $checks);
check(
    'existe la tabla employee_attachment',
    hasTable('employee_attachment'),
    'la mig 229 no aplicó',
    $failures,
    $checks
);

// ── b. Alta ──────────────────────────────────────────────────────────────────

$cocinera = $svc->create($companyId, [
    'contactId'      => $cocineraId,
    'documentNumber' => '1.234.567',
    'jobTitle'       => 'Cocinera',
    'hireDate'       => '2024-03-01',
    'outletId'       => $outletId,
    'fixedAmount'    => 2750000,
    'fixedPeriod'    => 'monthly',
], $userId);

check(
    'el alta devuelve el legajo con sus datos',
    $cocinera['fullName'] === 'Rosa Benítez'
        && $cocinera['jobTitle'] === 'Cocinera'
        && $cocinera['hireDate'] === '2024-03-01'
        && $cocinera['outletId'] === $outletId,
    json_encode($cocinera),
    $failures,
    $checks
);
check(
    'un empleado sin fecha de egreso nace activo',
    $cocinera['endDate'] === null && $cocinera['active'] === true && $cocinera['status'] === 1,
    json_encode(['endDate' => $cocinera['endDate'], 'active' => $cocinera['active']]),
    $failures,
    $checks
);
check(
    'la sucursal se resuelve con su nombre',
    $cocinera['outletName'] === 'Legajo Test - Sucursal',
    (string) $cocinera['outletName'],
    $failures,
    $checks
);
// §9.1: el legajo ES de una persona. Sin ella no hay a quién sumarle horas,
// atribuirle una venta ni liquidarle un sueldo.
check(
    'el legajo no se puede crear sin persona',
    expectThrow(static fn() => $svc->create($companyId, ['hireDate' => '2024-01-01'])) !== null,
    'aceptó un legajo sin contactId',
    $failures,
    $checks
);
check(
    'el id del legajo ES el de la persona',
    $cocinera['id'] === $cocineraId && $cocinera['userId'] === $cocineraId,
    json_encode(['id' => $cocinera['id'], 'userId' => $cocinera['userId']]),
    $failures,
    $checks
);
// El nombre NO se copia: sale del contacto por JOIN. Si un día alguien vuelve a
// guardarlo en `employee`, esto falla — que es el punto.
$db->Execute('UPDATE contact SET contactName = ? WHERE contactId = ?', ['Rosa B. de Benítez', $cocineraId]);
check(
    'el nombre sale del contacto, no de una copia en el legajo',
    $svc->find($cocineraId, $companyId)['fullName'] === 'Rosa B. de Benítez',
    'el legajo devolvió un nombre viejo: hay una copia guardada',
    $failures,
    $checks
);
$db->Execute('UPDATE contact SET contactName = ? WHERE contactId = ?', ['Rosa Benítez', $cocineraId]);
check(
    'la fecha de ingreso es obligatoria',
    expectThrow(static fn() => $svc->create($companyId, ['contactId' => $sparePersonId])) !== null,
    'aceptó un legajo sin fecha de ingreso',
    $failures,
    $checks
);

// D1: los TRES esquemas conviven en la misma fila.
$vendedor = $svc->create($companyId, [
    'contactId'   => $vendedorId,
    'jobTitle'    => 'Vendedor',
    'hireDate'    => '2023-06-15',
    'fixedAmount' => 1500000,
    'fixedPeriod' => 'biweekly',
    'hourlyRate'  => 12000,
    'commissions' => true,
], $userId);

check(
    'los tres esquemas de remuneración conviven (fijo + hora + comisión)',
    near($vendedor['fixedAmount'], 1500000.0)
        && $vendedor['fixedPeriod'] === 'biweekly'
        && near($vendedor['hourlyRate'], 12000.0)
        && $vendedor['commissions'] === true,
    json_encode($vendedor),
    $failures,
    $checks
);
check(
    'el legajo trae el nombre y el estado de la credencial de esa persona',
    $vendedor['fullName'] === 'Aldo Cáceres' && $vendedor['userActive'] === true,
    json_encode(['fullName' => $vendedor['fullName'], 'userActive' => $vendedor['userActive']]),
    $failures,
    $checks
);
// §9.3: el PIN es del usuario y es OPCIONAL. El legajo solo dice si lo tiene.
check(
    'sin código cargado, el legajo lo dice y no lo inventa',
    $vendedor['hasPin'] === false,
    json_encode(['hasPin' => $vendedor['hasPin']]),
    $failures,
    $checks
);
$db->Execute('UPDATE contact SET pinhash = ? WHERE contactId = ?', [hash('sha256', '4321'), $vendedorId]);
check(
    'el código del USUARIO es el que ve el legajo (no hay un segundo PIN)',
    $svc->find($vendedorId, $companyId)['hasPin'] === true,
    'el legajo no leyó el PIN del contacto',
    $failures,
    $checks
);

// ── c. Edición parcial ───────────────────────────────────────────────────────

$editado = $svc->update($vendedor['id'], $companyId, ['jobTitle' => 'Jefe de ventas'], $userId);
check(
    'un patch parcial no borra lo que no vino en el payload',
    $editado['jobTitle'] === 'Jefe de ventas'
        && $editado['fullName'] === 'Aldo Cáceres'
        && near($editado['fixedAmount'], 1500000.0)
        && $editado['fixedPeriod'] === 'biweekly'
        && $editado['userId'] === $vendedorId,
    json_encode($editado),
    $failures,
    $checks
);

// §9.1: el legajo NO cambia de dueño. Mover un historial laboral de una
// persona a otra no es una edición; el endpoint además descarta la clave.
$noMovido = $svc->update($vendedor['id'], $companyId, ['contactId' => $sparePersonId], $userId);
check(
    'el legajo no cambia de persona al editarlo',
    $noMovido['id'] === $vendedorId && $noMovido['fullName'] === 'Aldo Cáceres',
    json_encode(['id' => $noMovido['id'], 'fullName' => $noMovido['fullName']]),
    $failures,
    $checks
);

// ── d. Egreso ────────────────────────────────────────────────────────────────

$egresado = $svc->terminate($cocinera['id'], $companyId, '2025-08-31', 'Renuncia', $userId);
check(
    'el egreso escribe la fecha y el motivo',
    $egresado['endDate'] === '2025-08-31' && $egresado['endReason'] === 'Renuncia',
    json_encode($egresado),
    $failures,
    $checks
);
check(
    'el egresado deja de estar activo pero la fila NO se archiva',
    $egresado['active'] === false && $egresado['status'] === 1,
    json_encode(['active' => $egresado['active'], 'status' => $egresado['status']]),
    $failures,
    $checks
);
check(
    'el legajo egresado sigue siendo legible (es historial)',
    $svc->find($cocinera['id'], $companyId) !== null,
    'el egreso borró el legajo',
    $failures,
    $checks
);
check(
    'no se puede egresar dos veces',
    expectThrow(static fn() => $svc->terminate($cocinera['id'], $companyId, '2025-09-30', null, $userId)) !== null,
    'aceptó un segundo egreso',
    $failures,
    $checks
);

// Recontratar = editar el legajo, no una operación aparte.
$recontratado = $svc->update($cocinera['id'], $companyId, ['endDate' => null, 'endReason' => null], $userId);
check(
    'volver a poner endDate en null reactiva el legajo',
    $recontratado['endDate'] === null && $recontratado['active'] === true,
    json_encode($recontratado),
    $failures,
    $checks
);
$svc->terminate($cocinera['id'], $companyId, '2025-08-31', 'Renuncia', $userId);

// ── e. Aislamiento multi-tenant ──────────────────────────────────────────────

check(
    'un legajo de otra empresa no se LEE',
    $svc->find($cocinera['id'], $otherCompanyId) === null,
    'la empresa vecina leyó un legajo ajeno',
    $failures,
    $checks
);
check(
    'un legajo de otra empresa no se EDITA',
    expectThrow(static fn() => $svc->update($cocinera['id'], $otherCompanyId, ['jobTitle' => 'Hackeado'])) !== null,
    'la empresa vecina editó un legajo ajeno',
    $failures,
    $checks
);
check(
    'un legajo de otra empresa no se EGRESA',
    expectThrow(static fn() => $svc->terminate($vendedor['id'], $otherCompanyId, '2025-01-01', null)) !== null,
    'la empresa vecina egresó un legajo ajeno',
    $failures,
    $checks
);
check(
    'un legajo de otra empresa no se ARCHIVA',
    expectThrow(static fn() => $svc->archive($vendedor['id'], $otherCompanyId)) !== null,
    'la empresa vecina archivó un legajo ajeno',
    $failures,
    $checks
);
// El intento fallido no dejó rastro.
$intacto = $svc->find($vendedor['id'], $companyId);
check(
    'después de los intentos cruzados el legajo quedó intacto',
    $intacto !== null && $intacto['jobTitle'] === 'Jefe de ventas' && $intacto['status'] === 1,
    json_encode($intacto),
    $failures,
    $checks
);
check(
    'el listado de una empresa no incluye empleados de la otra',
    $svc->list($otherCompanyId) === [],
    'la empresa vecina vio empleados ajenos en el listado',
    $failures,
    $checks
);

// ── f. Referencias validadas contra el tenant ────────────────────────────────

check(
    'una sucursal de otra empresa se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId' => $sparePersonId,
        'hireDate'  => '2024-01-01',
        'outletId'  => $otherOutletId,
    ])) !== null,
    'aceptó una sucursal de otro comercio',
    $failures,
    $checks
);
check(
    'una persona de otra empresa se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId' => $otherUserId,
        'hireDate'  => '2024-01-01',
    ])) !== null,
    'aceptó una persona de otro comercio',
    $failures,
    $checks
);
check(
    'un CLIENTE no puede tener legajo',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId' => $customerId,
        'hireDate'  => '2024-01-01',
    ])) !== null,
    'aceptó un contacto type=1 como empleado',
    $failures,
    $checks
);

// ── g. Invariantes ───────────────────────────────────────────────────────────

check(
    'el egreso no puede ser anterior al ingreso',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId' => $sparePersonId,
        'hireDate'  => '2024-05-01',
        'endDate'   => '2024-04-01',
    ])) !== null,
    'aceptó un egreso anterior al ingreso',
    $failures,
    $checks
);
check(
    'un monto fijo sin periodicidad se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId'   => $sparePersonId,
        'hireDate'    => '2024-01-01',
        'fixedAmount' => 1000000,
    ])) !== null,
    'aceptó un monto fijo sin periodicidad',
    $failures,
    $checks
);
check(
    'una periodicidad inválida se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId'   => $sparePersonId,
        'hireDate'    => '2024-01-01',
        'fixedAmount' => 1000000,
        'fixedPeriod' => 'quincenalmente',
    ])) !== null,
    'aceptó una periodicidad fuera del CHECK',
    $failures,
    $checks
);
check(
    'sacar el monto fijo limpia también la periodicidad',
    (static function () use ($svc, $companyId, $vendedor) {
        $row = $svc->update($vendedor['id'], $companyId, ['fixedAmount' => null]);
        return $row['fixedAmount'] === null && $row['fixedPeriod'] === null;
    })(),
    'quedó una periodicidad huérfana (el CHECK de la BD lo habría rechazado)',
    $failures,
    $checks
);
// La PK, ahora. Y a diferencia del índice parcial que reemplaza, tampoco deja
// un segundo legajo ARCHIVADO de la misma persona.
check(
    'una persona no puede tener dos legajos',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId' => $vendedorId,
        'hireDate'  => '2024-01-01',
    ])) !== null,
    'aceptó dos legajos para la misma persona',
    $failures,
    $checks
);
check(
    'un documento no se repite entre legajos vigentes',
    expectThrow(static fn() => $svc->create($companyId, [
        'contactId'      => $sparePersonId,
        'hireDate'       => '2024-01-01',
        'documentNumber' => '1.234.567',
    ])) !== null,
    'aceptó dos legajos vigentes con el mismo documento',
    $failures,
    $checks
);
// El teléfono dejó de ser del legajo (mig 233): es del contacto y lo valida
// `UsersService`. Mandarlo acá no puede escribir nada.
check(
    'el legajo ignora un teléfono en el payload en vez de guardarlo aparte',
    (static function () use ($svc, $companyId, $spare2Id) {
        $row = $svc->create($companyId, [
            'contactId' => $spare2Id,
            'hireDate'  => '2024-01-01',
            'phone'     => '12',
        ]);
        $ok = $row['phone'] === null;
        // Se borra la fila en vez de archivarla: archivarla dejaría a esa
        // persona ocupada (la PK no distingue estado) y le sumaría un legajo
        // al conteo del listado que revisa el bloque siguiente.
        ncmExecute('DELETE FROM employee WHERE contactid = ? AND companyid = ?', [$row['id'], $companyId]);
        return $ok;
    })(),
    'el legajo guardó un teléfono propio: volvió la copia de identidad',
    $failures,
    $checks
);

// ── h. Listado: archivado vs. egresado ───────────────────────────────────────

$todos = $svc->list($companyId);
check(
    'el listado trae los dos legajos vigentes (incluido el egresado)',
    count($todos) === 2,
    'trajo ' . count($todos),
    $failures,
    $checks
);
check(
    'el filtro de activos excluye al egresado',
    count($svc->list($companyId, ['state' => 'active'])) === 1,
    json_encode($svc->list($companyId, ['state' => 'active'])),
    $failures,
    $checks
);
check(
    'el filtro de egresados trae solo al que se fue',
    (static function () use ($svc, $companyId, $cocinera) {
        $rows = $svc->list($companyId, ['state' => 'terminated']);
        return count($rows) === 1 && $rows[0]['id'] === $cocinera['id'];
    })(),
    json_encode($svc->list($companyId, ['state' => 'terminated'])),
    $failures,
    $checks
);
check(
    'la búsqueda encuentra por nombre, documento y puesto',
    count($svc->list($companyId, ['q' => 'Benítez'])) === 1
        && count($svc->list($companyId, ['q' => '1.234.567'])) === 1
        && count($svc->list($companyId, ['q' => 'Jefe de ventas'])) === 1,
    'la búsqueda no matcheó alguno de los tres campos',
    $failures,
    $checks
);

$svc->archive($cocinera['id'], $companyId);
check(
    'archivar saca la fila del listado sin borrarla',
    count($svc->list($companyId)) === 1
        && count($svc->list($companyId, ['includeArchived' => true])) === 2
        && $svc->find($cocinera['id'], $companyId) !== null,
    json_encode($svc->list($companyId)),
    $failures,
    $checks
);
check(
    'el índice único del documento ignora a los archivados (se puede reusar)',
    (static function () use ($svc, $companyId, $spare3Id) {
        try {
            $svc->create($companyId, [
                'contactId'      => $spare3Id,
                'hireDate'       => '2026-01-01',
                'documentNumber' => '1.234.567',
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    })(),
    'un legajo archivado sigue bloqueando su documento',
    $failures,
    $checks
);

// ── Teardown ─────────────────────────────────────────────────────────────────

$resetFixture();

harnessFinish($failures, $checks);
