<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del ROSTRO DEL EMPLEADO (context/83 F2, mig 231).
 *
 * Corre contra Postgres real. Lo que verifica son las invariantes que sostienen
 * la decisión de producto, no la aritmética de vectores:
 *
 *   (A) CONSENTIMIENTO — sin el registro en el legajo no se puede ni ABRIR el
 *       registro del rostro ni GUARDARLO. Es el gate que hace que la casilla
 *       del legajo signifique algo y no sea decorativa.
 *   (B) AUTORIZACIÓN — el quiosco no elige a quién enrola. Sin una ventana
 *       abierta desde el panel no se guarda nada; con una ventana abierta para
 *       OTRA persona, tampoco; y la ventana se CONSUME al usarse. Es lo único
 *       que impide reconstruir el buddy punching con la cara de otro.
 *   (C) SUCURSAL — la ventana de una sucursal no la ve el quiosco de otra, y
 *       la persona sin sucursal asignada se puede enrolar en cualquiera.
 *   (D) VERSIÓN DEL MODELO — un vector de otra versión no se sirve. Mezclarlos
 *       no da error: da distancias sin sentido, que es como se le acredita la
 *       entrada de una persona a otra.
 *   (E) COHERENCIA — capturas que no son de la misma persona se rechazan. Es el
 *       único chequeo que el servidor puede hacer sobre algo que calculó el
 *       cliente, y evita guardar un promedio que no es la cara de nadie.
 *   (F) BORRADO — el EGRESO borra la biometría, y retirar el consentimiento
 *       también. El legajo, en cambio, sobrevive: es historial laboral.
 *   (G) SCOPING — nada de esto cruza de un comercio a otro.
 *
 * El arnés ejercita los SERVICES. La foto no se ejercita (necesitaría S3): lo
 * que se ejercita es el camino sin foto, que es el que corre en el test.
 *
 * Uso (ver `run_employee_face_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/employee_face_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Hr\EmployeeFaceService;
use Punto\Api\Hr\EmployeeService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ────────
$companyId = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId  = '1a282724-6073-49c3-8bc3-0114a132e349';
$adminId   = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
// Segundo tenant del mismo seed ("Verify MX"). Existe para el caso (G).
$otherCompanyId = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
$userId = $adminId;
$roleId = '1';
require API_APP_DIR . '/data.php';

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

/** Corre algo que DEBE tirar y devuelve el mensaje; '' si no tiró. */
function rechaza(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

/**
 * Un vector de 128 dimensiones determinístico a partir de una semilla.
 *
 * `$drift` lo aleja un poco: sirve para simular otra toma de LA MISMA cara
 * (drift chico) o directamente otra persona (drift grande). No pretende parecer
 * un embedding real — pretende ser comparable de forma predecible, que es lo
 * único que este arnés necesita.
 *
 * @return array<int,float>
 */
function vector(int $seed, float $drift = 0.0): array
{
    $out = [];
    for ($i = 0; $i < 128; $i++) {
        $out[] = sin($seed * 7.13 + $i * 0.37) + $drift * cos($i * 1.91 + $seed);
    }
    return $out;
}

/** Las 3 tomas de un enrolamiento de la misma cara. @return array<int,array<int,float>> */
function tomas(int $seed): array
{
    return [vector($seed, 0.0), vector($seed, 0.02), vector($seed, 0.04)];
}

const MODELO = 'face-api-recognition-128';

$faces     = new EmployeeFaceService();          // sin S3: acá no se suben fotos
$employees = new EmployeeService($faces);

$creados = [];

/**
 * Alta de un legajo del arnés. Devuelve la fila.
 *
 * Crea primero la PERSONA: desde la mig 233 el legajo es un satélite 1:1 del
 * `contact` type=0 y no existe sin ella.
 */
$altaEmpleado = function (array $extra = []) use ($employees, $companyId, $outletId, $adminId, &$creados): array {
    $contactId = ncmExecute('SELECT gen_random_uuid() AS id')['id'];
    ncmExecute(
        'INSERT INTO contact (contactId, contactName, companyId, type, contactStatus)
         VALUES (?, ?, ?, 0, 1)',
        [$contactId, 'Rostro Test ' . bin2hex(random_bytes(4)), $companyId]
    );
    $row = $employees->create($companyId, array_merge([
        'contactId' => $contactId,
        'hireDate'  => '2026-01-01',
        'outletId'  => $outletId,
    ], $extra), $adminId);
    $creados[] = $row['id'];
    return $row;
};

try {
    // ═══════════════════════════════════════════════════════════════════════
    // (A) Consentimiento
    // ═══════════════════════════════════════════════════════════════════════
    echo "=== (A) Sin consentimiento no hay rostro ===\n";

    $sinConsent = $altaEmpleado();
    check('(A1) el legajo nace SIN consentimiento biométrico registrado',
        $sinConsent['biometricConsentAt'] === null,
        json_encode($sinConsent['biometricConsentAt']), $failures, $checks);

    $msg = rechaza(fn() => $faces->openEnrollment($companyId, $sinConsent['id'], $adminId));
    check('(A2) abrir el registro del rostro se RECHAZA sin consentimiento',
        $msg !== '',
        'openEnrollment() no tiró', $failures, $checks);

    // Se lo damos, abrimos, y se lo retiramos ANTES de capturar: el hueco real
    // entre autorizar y guardar son diez minutos de persona real.
    $employees->update($sinConsent['id'], $companyId,
        ['biometricConsent' => '1', 'actorId' => $adminId], $adminId);
    $faces->openEnrollment($companyId, $sinConsent['id'], $adminId);
    $employees->update($sinConsent['id'], $companyId,
        ['biometricConsent' => '0', 'actorId' => $adminId], $adminId);

    $msg = rechaza(fn() => $faces->enroll(
        $companyId, $sinConsent['id'], tomas(1), MODELO, $outletId
    ));
    check('(A3) y guardarlo también, si el consentimiento se retiró en el medio',
        $msg !== '',
        'enroll() no tiró después de retirar el consentimiento', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) Autorización: el quiosco no elige a quién enrola
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) El registro lo autoriza el panel, no el quiosco ===\n";

    $ana  = $altaEmpleado(['biometricConsent' => '1', 'actorId' => $adminId]);
    $beto = $altaEmpleado(['biometricConsent' => '1', 'actorId' => $adminId]);

    $msg = rechaza(fn() => $faces->enroll($companyId, $ana['id'], tomas(2), MODELO, $outletId));
    check('(B1) sin ventana abierta, el quiosco no puede guardar un rostro',
        $msg !== '',
        'enroll() guardó sin autorización previa', $failures, $checks);

    $faces->openEnrollment($companyId, $ana['id'], $adminId);

    $msg = rechaza(fn() => $faces->enroll($companyId, $beto['id'], tomas(3), MODELO, $outletId));
    check('(B2) con la ventana abierta para OTRA persona, tampoco',
        $msg !== '',
        'enroll() guardó el rostro de alguien que no estaba autorizado', $failures, $checks);

    $faces->enroll($companyId, $ana['id'], tomas(2), MODELO, $outletId);
    check('(B3) con la ventana abierta para ella, sí queda registrado',
        ($faces->statusFor($companyId, $ana['id'])['enrolledAt'] ?? null) !== null,
        json_encode($faces->statusFor($companyId, $ana['id'])), $failures, $checks);

    check('(B4) y la ventana se CONSUME: no queda abierta para capturar de nuevo',
        $faces->pendingEnrollment($companyId, $outletId) === null,
        json_encode($faces->pendingEnrollment($companyId, $outletId)), $failures, $checks);

    $msg = rechaza(fn() => $faces->enroll($companyId, $ana['id'], tomas(2), MODELO, $outletId));
    check('(B5) por eso un segundo intento sin volver a autorizar se rechaza',
        $msg !== '',
        'enroll() reutilizó una ventana ya consumida', $failures, $checks);

    // Re-enrolar PISA: nadie tiene dos caras.
    $faces->openEnrollment($companyId, $ana['id'], $adminId);
    $faces->enroll($companyId, $ana['id'], tomas(9), MODELO, $outletId);
    $row = ncmExecute(
        'SELECT COUNT(*) AS n FROM employee_face WHERE companyid = ? AND contactid = ?',
        [$companyId, $ana['id']]
    );
    check('(B6) volver a registrar REEMPLAZA: una fila por persona y modelo',
        (int) ($row['n'] ?? 0) === 1,
        'filas de employee_face: ' . json_encode($row['n'] ?? null), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (C) Sucursal
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) La ventana es de UNA sucursal ===\n";

    $otraSucursal = '00000000-0000-4000-8000-0000000000ff'; // no existe: sirve de "otro quiosco"
    $faces->openEnrollment($companyId, $beto['id'], $adminId);

    check('(C1) el quiosco de la sucursal del legajo ve la ventana',
        ($faces->pendingEnrollment($companyId, $outletId)['employeeId'] ?? null) === $beto['id'],
        json_encode($faces->pendingEnrollment($companyId, $outletId)), $failures, $checks);

    check('(C2) el quiosco de OTRA sucursal no la ve',
        $faces->pendingEnrollment($companyId, $otraSucursal) === null,
        json_encode($faces->pendingEnrollment($companyId, $otraSucursal)), $failures, $checks);

    $msg = rechaza(fn() => $faces->enroll($companyId, $beto['id'], tomas(3), MODELO, $otraSucursal));
    check('(C3) ni puede capturar contra ella',
        $msg !== '',
        'enroll() aceptó una captura desde otra sucursal', $failures, $checks);

    // Personal sin sucursal asignada (el dueño, quien rota): cualquier quiosco.
    $global = $altaEmpleado(['biometricConsent' => '1', 'actorId' => $adminId, 'outletId' => null]);
    $faces->openEnrollment($companyId, $global['id'], $adminId);
    check('(C4) la persona SIN sucursal asignada se puede enrolar en cualquier quiosco',
        ($faces->pendingEnrollment($companyId, $otraSucursal)['employeeId'] ?? null) === $global['id'],
        json_encode($faces->pendingEnrollment($companyId, $otraSucursal)), $failures, $checks);
    $faces->enroll($companyId, $global['id'], tomas(5), MODELO, $otraSucursal);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) Versión del modelo
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) Vectores de otro modelo no se sirven ===\n";

    $ids = array_column($faces->facesForOutlet($companyId, $outletId, MODELO), 'employeeId');
    check('(D1) el quiosco recibe los rostros de su sucursal para SU versión',
        in_array($ana['id'], $ids, true),
        'ids recibidos: ' . json_encode($ids), $failures, $checks);

    check('(D2) con una versión desconocida no recibe NADA (mejor que datos incomparables)',
        $faces->facesForOutlet($companyId, $outletId, 'modelo-inexistente-9') === [],
        'devolvió filas para una versión que no existe', $failures, $checks);

    $msg = rechaza(fn() => $faces->enroll($companyId, $beto['id'], tomas(3), 'modelo-inexistente-9', $outletId));
    check('(D3) y un enrolamiento con versión desconocida se rechaza',
        $msg !== '',
        'enroll() aceptó una versión de modelo que el servidor no conoce', $failures, $checks);

    $msg = rechaza(fn() => $faces->enroll(
        $companyId, $beto['id'], [array_slice(vector(3), 0, 64), vector(3), vector(3)], MODELO, $outletId
    ));
    check('(D4) ni una captura con la cantidad de dimensiones equivocada',
        $msg !== '',
        'enroll() aceptó un vector de largo distinto al del modelo', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) Coherencia de las capturas
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) Las tomas tienen que ser de la misma persona ===\n";

    $msg = rechaza(fn() => $faces->enroll(
        $companyId, $beto['id'], [vector(11), vector(12), vector(13)], MODELO, $outletId
    ));
    check('(E1) tres caras distintas no se promedian: se rechazan',
        $msg !== '',
        'enroll() promedió capturas que no son de la misma persona', $failures, $checks);

    $msg = rechaza(fn() => $faces->enroll(
        $companyId, $beto['id'], [vector(3), vector(3, 0.02)], MODELO, $outletId
    ));
    check('(E2) y con menos tomas de las necesarias, tampoco',
        $msg !== '',
        'enroll() aceptó menos capturas que el mínimo', $failures, $checks);

    // La ventana de Beto sigue viva (ninguno de los intentos anteriores la
    // consumió, porque ninguno llegó a guardar). Eso mismo se verifica acá.
    check('(E3) un intento rechazado NO consume la ventana: se puede reintentar',
        ($faces->pendingEnrollment($companyId, $outletId)['employeeId'] ?? null) === $beto['id'],
        json_encode($faces->pendingEnrollment($companyId, $outletId)), $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (G) Scoping multi-tenant
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (G) Nada cruza de un comercio a otro ===\n";

    $idsAjenos = array_column($faces->facesForOutlet($otherCompanyId, $outletId, MODELO), 'employeeId');
    check('(G1) el otro comercio no recibe estos rostros',
        !in_array($ana['id'], $idsAjenos, true),
        'ids ajenos: ' . json_encode($idsAjenos), $failures, $checks);

    check('(G2) ni puede leer su estado',
        $faces->statusFor($otherCompanyId, $ana['id']) === null,
        json_encode($faces->statusFor($otherCompanyId, $ana['id'])), $failures, $checks);

    $msg = rechaza(fn() => $faces->openEnrollment($otherCompanyId, $ana['id'], $adminId));
    check('(G3) ni abrir una ventana sobre un legajo que no es suyo',
        $msg !== '',
        'openEnrollment() cruzó de comercio', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (F) Borrado
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (F) La biometría se borra; el legajo sobrevive ===\n";

    // Retirar el consentimiento borra el rostro.
    $employees->update($global['id'], $companyId,
        ['biometricConsent' => '0', 'actorId' => $adminId], $adminId);
    check('(F1) retirar el consentimiento BORRA el rostro registrado',
        $faces->statusFor($companyId, $global['id']) === null,
        json_encode($faces->statusFor($companyId, $global['id'])), $failures, $checks);

    // El egreso también.
    $egresada = $employees->terminate($ana['id'], $companyId, '2026-09-17', 'Fin de contrato', $adminId);
    check('(F2) el EGRESO borra el rostro',
        $faces->statusFor($companyId, $ana['id']) === null,
        json_encode($faces->statusFor($companyId, $ana['id'])), $failures, $checks);

    check('(F3) y el legajo SOBREVIVE: sigue siendo historial laboral',
        $employees->find($ana['id'], $companyId) !== null && $egresada['endDate'] === '2026-09-17',
        json_encode($egresada['endDate'] ?? null), $failures, $checks);

    check('(F4) el rostro de la egresada deja de bajar al quiosco',
        !in_array($ana['id'], array_column($faces->facesForOutlet($companyId, $outletId, MODELO), 'employeeId'), true),
        'el quiosco todavía recibe el rostro de una persona egresada', $failures, $checks);

    // La ficha del legajo lee el estado sin una query por fila.
    $listado = $employees->list($companyId, ['state' => 'all']);
    $conClave = array_filter($listado, static fn($e) => array_key_exists('face', $e));
    check('(F5) el listado del panel trae el estado del rostro de cada legajo',
        count($conClave) === count($listado) && $listado !== [],
        'legajos: ' . count($listado) . ', con clave `face`: ' . count($conClave), $failures, $checks);

} finally {
    // Limpieza: rostros y ventanas caen por CASCADE al borrar el legajo.
    foreach ($creados as $employeeId) {
        // Borrar la persona se lleva legajo, rostro y habilitación por CASCADE.
        ncmExecute('DELETE FROM contact WHERE contactId = ? AND companyId = ?', [$employeeId, $companyId]);
    }
}

harnessFinish($failures, $checks);
