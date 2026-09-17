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
 *   b. Alta: campos mínimos, normalización camelCase → columnas, y que los
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
 *   f. Referencias validadas contra el TENANT: un `outletId` o un `userId` de
 *      otra empresa se rechazan aunque la FK los acepte (apunta a la tabla,
 *      no al comercio). Y el `userId` tiene que ser type=0: un CLIENTE no
 *      puede quedar vinculado como usuario del legajo.
 *   g. Invariantes de la BD con mensaje entendible: egreso anterior al
 *      ingreso, monto fijo sin periodicidad, periodicidad inválida, y los dos
 *      índices únicos (un usuario en dos legajos, un documento repetido).
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
// Un CLIENTE (type=1) del mismo comercio: no puede vincularse como usuario.
$customerId     = 'e33b1eea-0000-4000-8000-000000000104';

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
foreach ([[$userId, $companyId, 'Usuaria del sistema', 0],
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
    'fullName'       => 'Rosa Benítez',
    'documentNumber' => '1.234.567',
    'jobTitle'       => 'Cocinera',
    'hireDate'       => '2024-03-01',
    'outletId'       => $outletId,
    'fixedAmount'    => 2750000,
    'fixedPeriod'    => 'monthly',
    'email'          => 'rosa@ejemplo.com',
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
check(
    'el nombre es obligatorio',
    expectThrow(static fn() => $svc->create($companyId, ['hireDate' => '2024-01-01'])) !== null,
    'aceptó un legajo sin nombre',
    $failures,
    $checks
);
check(
    'la fecha de ingreso es obligatoria',
    expectThrow(static fn() => $svc->create($companyId, ['fullName' => 'Sin ingreso'])) !== null,
    'aceptó un legajo sin fecha de ingreso',
    $failures,
    $checks
);

// D1: los TRES esquemas conviven en la misma fila.
$vendedor = $svc->create($companyId, [
    'fullName'    => 'Aldo Cáceres',
    'jobTitle'    => 'Vendedor',
    'hireDate'    => '2023-06-15',
    'userId'      => $userId,
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
    'el vínculo opcional al usuario del sistema se guarda y se resuelve',
    $vendedor['userId'] === $userId && $vendedor['userName'] === 'Usuaria del sistema',
    json_encode(['userId' => $vendedor['userId'], 'userName' => $vendedor['userName']]),
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
        && $editado['userId'] === $userId,
    json_encode($editado),
    $failures,
    $checks
);

// Desvincular al usuario NO toca el legajo (D2).
$desvinculado = $svc->update($vendedor['id'], $companyId, ['userId' => null], $userId);
check(
    'desvincular al usuario deja el legajo intacto',
    $desvinculado['userId'] === null && $desvinculado['fullName'] === 'Aldo Cáceres',
    json_encode($desvinculado),
    $failures,
    $checks
);
// …y se puede volver a vincular.
$svc->update($vendedor['id'], $companyId, ['userId' => $userId], $userId);

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
        'fullName' => 'Con sucursal ajena',
        'hireDate' => '2024-01-01',
        'outletId' => $otherOutletId,
    ])) !== null,
    'aceptó una sucursal de otro comercio',
    $failures,
    $checks
);
check(
    'un usuario de otra empresa se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName' => 'Con usuario ajeno',
        'hireDate' => '2024-01-01',
        'userId'   => $otherUserId,
    ])) !== null,
    'aceptó un usuario de otro comercio',
    $failures,
    $checks
);
check(
    'un CLIENTE no se puede vincular como usuario del legajo',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName' => 'Con cliente como usuario',
        'hireDate' => '2024-01-01',
        'userId'   => $customerId,
    ])) !== null,
    'aceptó un contacto type=1 como usuario',
    $failures,
    $checks
);

// ── g. Invariantes ───────────────────────────────────────────────────────────

check(
    'el egreso no puede ser anterior al ingreso',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName' => 'Fechas al revés',
        'hireDate' => '2024-05-01',
        'endDate'  => '2024-04-01',
    ])) !== null,
    'aceptó un egreso anterior al ingreso',
    $failures,
    $checks
);
check(
    'un monto fijo sin periodicidad se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName'    => 'Sin periodicidad',
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
        'fullName'    => 'Periodicidad rara',
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
check(
    'un usuario no puede estar en dos legajos vigentes',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName' => 'Duplicado por usuario',
        'hireDate' => '2024-01-01',
        'userId'   => $userId,
    ])) !== null,
    'aceptó dos legajos vigentes para el mismo usuario',
    $failures,
    $checks
);
check(
    'un documento no se repite entre legajos vigentes',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName'       => 'Duplicado por documento',
        'hireDate'       => '2024-01-01',
        'documentNumber' => '1.234.567',
    ])) !== null,
    'aceptó dos legajos vigentes con el mismo documento',
    $failures,
    $checks
);
check(
    'un teléfono inválido se rechaza',
    expectThrow(static fn() => $svc->create($companyId, [
        'fullName' => 'Teléfono roto',
        'hireDate' => '2024-01-01',
        'phone'    => '12',
    ])) !== null,
    'aceptó un teléfono que libphonenumber no valida',
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
    'el índice único ignora a los archivados (el documento se puede reusar)',
    (static function () use ($svc, $companyId) {
        try {
            $svc->create($companyId, [
                'fullName'       => 'Reusa el documento del archivado',
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
