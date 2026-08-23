<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Alta de impresora idempotente — la mitad SERVIDOR de la configuración
 * offline del POS (context/51).
 *
 * Por qué esto necesita Postgres real y no un mock: lo que se está probando es
 * el `ON CONFLICT ("id") DO NOTHING` sobre la clave primaria y el re-SELECT
 * scopeado por `companyId`. Un mock del wrapper de BD contestaría lo que uno
 * espera; la única forma de saber si la cláusula está bien escrita es que el
 * motor la ejecute.
 *
 * El caso que cierra: el POS crea una impresora sin conexión y la encola. Al
 * volver la red la manda, el servidor la aplica, y la RESPUESTA se pierde
 * (túnel, proxy, la tablet se durmió). El device no puede distinguir eso de
 * "no llegó", así que reintenta. Antes de este cambio, el segundo envío creaba
 * una SEGUNDA impresora idéntica y el cajero terminaba con la comanda saliendo
 * dos veces.
 *
 * Casos:
 *   A. Alta con `id` del cliente: crea la fila CON ese id (no uno inventado
 *      por el servidor) — que es lo que permite editarla o borrarla mientras
 *      todavía está en cola.
 *   B. Reenvío del MISMO alta: devuelve la misma fila y NO crea una segunda.
 *      Es la idempotencia propiamente dicha.
 *   C. Reenvío con el mismo id pero otros datos: sigue sin duplicar, y no
 *      pisa la fila original — `DO NOTHING` es no hacer nada, no un upsert.
 *   D. Un id que ya existe en OTRO comercio: 409, y jamás se devuelve la fila
 *      ajena. Es el riesgo que abre aceptar un id del cliente y acá se fija
 *      que esté cerrado.
 *   E. Alta sin `id`: sigue funcionando con el uuid del servidor
 *      (compatibilidad con el panel y cualquier otro call-site).
 *   F. Un `id` que no es UUID se rechaza con 422 antes de tocar la base.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_printer_binding_idempotency_test.sh`):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/printer_binding_idempotency_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/services/PrinterBindingService.php';

use Punto\Api\Services\PrinterBindingService;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';

// Ids propios de este arnés — prefijo reconocible para la limpieza.
$clientId  = 'ceceface-0000-4000-8000-000000000001';
$foreignId = 'ceceface-0000-4000-8000-000000000002';
$otherCompanyId = 'dddddddd-0000-4000-8000-00000000000d';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/** Cuántas filas hay con ese id, sin filtrar por comercio. */
function countById(string $id): int
{
    $rs = ncmExecute(
        'SELECT COUNT(*) AS n FROM "printer_binding" WHERE "id" = ?',
        [$id],
        false, true
    );
    if (!$rs || $rs->EOF) return 0;
    $n = (int) ($rs->fields['n'] ?? $rs->fields['N'] ?? 0);
    $rs->Close();
    return $n;
}

function cleanup(string $registerId, string $clientId, string $foreignId): void
{
    ncmExecute('DELETE FROM "printer_binding" WHERE registerid = ?', [$registerId]);
    ncmExecute('DELETE FROM "printer_binding" WHERE "id" IN (?::uuid, ?::uuid)', [$clientId, $foreignId]);
}

// ── Estado limpio: el arnés se puede correr dos veces seguidas ───────────────
cleanup($registerId, $clientId, $foreignId);

$svc = new PrinterBindingService($companyId, $outletId);

$payload = [
    'name'      => 'Comanda Cocina',
    'color'     => 'amber',
    'transport' => 'usb',
    'mode'      => 'escpos',
];

// ── A. Alta con id del cliente ───────────────────────────────────────────────
$a = $svc->create($registerId, $payload + ['id' => $clientId]);
check(
    'A1 el alta usa el id que mandó el cliente',
    ($a['id'] ?? '') === $clientId,
    'id devuelto=' . ($a['id'] ?? 'null'),
    $failures
);
check(
    'A2 quedó exactamente una fila',
    countById($clientId) === 1,
    'filas=' . countById($clientId),
    $failures
);

// ── B. Reenvío idéntico (la respuesta se perdió y el device reintenta) ───────
$b = $svc->create($registerId, $payload + ['id' => $clientId]);
check(
    'B1 el reenvío devuelve la MISMA impresora',
    ($b['id'] ?? '') === $clientId,
    'id devuelto=' . ($b['id'] ?? 'null'),
    $failures
);
check(
    'B2 el reenvío NO creó una segunda impresora',
    countById($clientId) === 1,
    'filas=' . countById($clientId),
    $failures
);

// ── C. Mismo id, datos distintos: DO NOTHING no es upsert ───────────────────
$c = $svc->create($registerId, ['name' => 'Otra cosa', 'transport' => 'usb'] + ['id' => $clientId]);
check(
    'C1 sigue sin duplicar',
    countById($clientId) === 1,
    'filas=' . countById($clientId),
    $failures
);
check(
    'C2 no pisó los datos originales',
    ($c['name'] ?? '') === 'Comanda Cocina',
    'name=' . ($c['name'] ?? 'null'),
    $failures
);

// ── D. Id que pertenece a OTRO comercio ─────────────────────────────────────
// Se inserta a mano una fila de otro tenant con un id conocido, y se intenta
// crear una impresora con ESE id desde nuestro comercio.
ncmExecute(
    'INSERT INTO "printer_binding" ("id",companyid,outletid,registerid,"name","transport")
     VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?)',
    [$foreignId, $otherCompanyId, $otherCompanyId, $otherCompanyId, 'Impresora ajena', 'usb']
);

$rejected = false;
$rejectedCode = 0;
$leaked = null;
try {
    $leaked = $svc->create($registerId, $payload + ['id' => $foreignId]);
} catch (\RuntimeException $e) {
    $rejected = true;
    $rejectedCode = $e->getCode();
}
check(
    'D1 un id de otro comercio se rechaza (no se devuelve la fila ajena)',
    $rejected && $leaked === null,
    $rejected ? 'ok' : 'devolvió: ' . json_encode($leaked),
    $failures
);
check(
    'D2 el rechazo es 409 (conflicto de id), no un 500 opaco',
    $rejectedCode === 409,
    'code=' . $rejectedCode,
    $failures
);
$foreignRow = ncmExecute(
    'SELECT "name" FROM "printer_binding" WHERE "id" = ?',
    [$foreignId],
    false
);
check(
    'D3 la fila del otro comercio quedó intacta',
    ($foreignRow['name'] ?? '') === 'Impresora ajena',
    'name=' . ($foreignRow['name'] ?? 'null'),
    $failures
);

// ── E. Alta sin id (panel y cualquier call-site que no lo mande) ────────────
$e = $svc->create($registerId, ['name' => 'Sin id', 'transport' => 'usb']);
check(
    'E1 sin id del cliente el servidor genera uno',
    !empty($e['id']) && $e['id'] !== $clientId,
    'id=' . ($e['id'] ?? 'null'),
    $failures
);

// ── F. Id con forma inválida ────────────────────────────────────────────────
$badRejected = false;
$badCode = 0;
try {
    $svc->create($registerId, $payload + ['id' => 'no-soy-un-uuid']);
} catch (\RuntimeException $ex) {
    $badRejected = true;
    $badCode = $ex->getCode();
}
check(
    'F1 un id que no es UUID se rechaza con 422',
    $badRejected && $badCode === 422,
    'rejected=' . var_export($badRejected, true) . ' code=' . $badCode,
    $failures
);

// ── Limpieza ─────────────────────────────────────────────────────────────────
cleanup($registerId, $clientId, $foreignId);

echo "\n";
harnessFinish($failures, $checks);
