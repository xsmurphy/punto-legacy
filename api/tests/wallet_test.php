<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) de la wallet multi-nivel, F1
 * (context/74, mig 232 + `WalletService`).
 *
 * Qué protege — las invariantes de §8, cada una por el servicio Y por la BD
 * cuando la BD la puede expresar (el servicio es la puerta; la BD es la red
 * para el día que alguien escriba por otro camino):
 *
 *   a. La mig 232 corrió (tablas, columna, módulo registrado).
 *   b. Catálogo de bolsillos: nombre único sin distinguir mayúsculas,
 *      renombrar, desactivar (no hay borrado).
 *   c. Jerarquía: un nivel, mismo comercio, sin auto-referencia — rechazado
 *      por el servicio y, escribiendo directo, por el trigger/CHECK.
 *   d. Carga: solo a un titular; el modo de facturación queda GRABADO.
 *   e. Saldo = suma de los movimientos, y se lee del último por `seq`.
 *   f. Un pago que dejaría negativo se rechaza, devuelve cuánto había, y no
 *      escribe nada.
 *   g. El CHECK de la BD frena un balanceafter < 0 aunque el INSERT sea
 *      directo; el trigger de encadenado frena un balanceafter inventado.
 *   h. Append-only: UPDATE, DELETE y TRUNCATE de un movimiento fallan.
 *   i. Transferencia: dos filas con el mismo grupo; a un no-hijo se rechaza;
 *      y si el SEGUNDO lado falla, el primero tampoco queda (atómica).
 *   j. Ajuste: motivo obligatorio y tampoco deja negativo.
 *   k. Reversa: solo contra un pago existente y nunca más de lo pagado.
 *   l. Orden por `seq` y paginación por cursor.
 *   m. Cambiar de titular a un cliente con saldo se rechaza.
 *   n. Bolsillo inactivo: no opera, pero su saldo se sigue viendo.
 *   o. Aislamiento multi-tenant.
 *   p. Transacción anidada (lo que hará la venta de F2): si la externa hace
 *      rollback, el débito se va con ella.
 *   q. Concurrencia REAL: dos procesos pagan el mismo saldo a la vez → solo
 *      uno pasa (el advisory lock serializa leer-y-escribir).
 *   r. La purga del tenant (/admin) es el único camino de borrado, y solo
 *      para su propio comercio.
 *
 * Tenant principal: "Verify PY" del seed de verify_chain. Vecino para el
 * aislamiento: "Verify MX". Los clientes son del arnés (UUIDs fijos) y se
 * limpian al empezar y al terminar usando la purga — así el arnés también
 * corre contra una base ya usada.
 *
 * Uso: bash api/tests/run_wallet_test.sh
 */

const W_PY       = '0ea6c5d8-57e5-4226-8140-ec914deec024';
const W_PY_ADMIN = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
const W_MX       = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const W_MX_ADMIN = '999986f1-05fe-4d91-841f-156a090e7a15';

// Clientes del arnés (type=1).
const W_T1  = 'a11e7000-0000-4000-8000-000000000101'; // titular
const W_C1  = 'a11e7000-0000-4000-8000-000000000102'; // hijo de T1
const W_T2  = 'a11e7000-0000-4000-8000-000000000103'; // otro titular
const W_G   = 'a11e7000-0000-4000-8000-000000000104'; // candidato a nieto
const W_T3  = 'a11e7000-0000-4000-8000-000000000105'; // titular para la carrera
const W_MXC = 'a11e7000-0000-4000-8000-000000000201'; // cliente de la vecina

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Wallet\WalletException;
use Punto\Api\Wallet\WalletInsufficientFundsException;
use Punto\Api\Wallet\WalletService;

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

/** Corre $fn y devuelve la excepción, o null si no lanzó. */
function thrown(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

/**
 * Corre SQL directo esperando que la BD lo rechace. Cada intento va en su
 * propia transacción con rollback, para que el error no deje la conexión
 * abortada (25P02) para el resto del arnés.
 */
function dbRejects(string $sql, array $params = []): ?string
{
    global $db;
    $db->BeginTrans();
    try {
        $db->Execute($sql, $params);
    } catch (\Throwable $e) {
        if ($db->HasOpenTransaction()) {
            $db->RollbackTrans();
        }
        return $e->getMessage() . ' ' . $db->ErrorMsg();
    }
    $db->RollbackTrans();
    return null;
}

function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

function movementCount(string $contactId): int
{
    $row = ncmExecute('SELECT COUNT(*) AS n FROM wallet_movement WHERE contactid = ?', [$contactId]);
    return (int) ($row['n'] ?? 0);
}

function sumAmounts(string $contactId, string $pocketId): float
{
    $row = ncmExecute(
        'SELECT COALESCE(SUM(amount), 0) AS s FROM wallet_movement WHERE contactid = ? AND pocketid = ?',
        [$contactId, $pocketId]
    );
    return (float) ($row['s'] ?? 0);
}

/** Purga del arnés: el ÚNICO camino de borrado de movimientos (ver mig 232). */
function purge(): void
{
    global $db;
    $all = [W_T1, W_C1, W_T2, W_G, W_T3, W_MXC];
    $ph  = implode(',', array_fill(0, count($all), '?'));
    foreach ([W_PY, W_MX] as $company) {
        $db->BeginTrans();
        $db->Execute("SELECT set_config('punto.tenant_purge', ?, true)", [$company]);
        $db->Execute("DELETE FROM wallet_movement WHERE companyid = ? AND contactid IN ($ph)", array_merge([$company], $all));
        $db->CommitTrans();
    }
    $db->Execute("UPDATE contact SET parentcontactid = NULL WHERE contactid IN ($ph)", $all);
    $db->Execute("DELETE FROM wallet_pocket WHERE companyid IN (?, ?) AND name LIKE 'Arnés %'", [W_PY, W_MX]);
}

global $db;

// ── Fixture ──────────────────────────────────────────────────────────────────
foreach ([[W_T1, 'Titular Uno', W_PY], [W_C1, 'Hijo Uno', W_PY], [W_T2, 'Titular Dos', W_PY],
          [W_G, 'Nieto', W_PY], [W_T3, 'Titular Carrera', W_PY], [W_MXC, 'Cliente Vecina', W_MX]] as [$cid, $name, $company]) {
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, type, contactStatus)
         VALUES (?, ?, ?, 1, 1)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, contactStatus = 1',
        [$cid, $name, $company]
    );
}
purge();

$svc = new WalletService();

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (a) la mig 232 corrió ===\n";
$tbl = ncmExecute(
    "SELECT COUNT(*) AS n FROM information_schema.tables
      WHERE table_schema = current_schema() AND table_name IN ('wallet_pocket', 'wallet_movement')"
);
check('(a1) existen wallet_pocket y wallet_movement', (int) ($tbl['n'] ?? 0) === 2, json_encode($tbl), $failures, $checks);
$col = ncmExecute(
    "SELECT 1 AS ok FROM information_schema.columns
      WHERE table_schema = current_schema() AND table_name = 'contact' AND column_name = 'parentcontactid'"
);
check('(a2) existe contact.parentcontactid', (bool) $col, 'falta la columna', $failures, $checks);

$modules = new \Punto\Api\Modules\ModulesService();
check('(a3) wallet es un módulo activable', in_array('wallet', \Punto\Api\Modules\ModulesService::nativeKeys(), true),
    'no está en NATIVE_KEYS', $failures, $checks);
$modules->toggle(W_PY, 'wallet', false);
check('(a4) apagado, isEnabled() da false', $modules->isEnabled(W_PY, 'wallet') === false, 'debería estar apagado', $failures, $checks);
$modules->toggle(W_PY, 'wallet', true);
check('(a5) prendido, isEnabled() da true', $modules->isEnabled(W_PY, 'wallet') === true, 'debería estar prendido', $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (b) catálogo de bolsillos ===\n";
$almuerzo = $svc->createPocket(W_PY, 'Arnés Almuerzo');
$merienda = $svc->createPocket(W_PY, '  Arnés   Merienda ');
$pA = $almuerzo['id'];
$pM = $merienda['id'];
check('(b1) crea y normaliza espacios', $merienda['name'] === 'Arnés Merienda' && $almuerzo['active'] === true,
    json_encode([$almuerzo, $merienda]), $failures, $checks);
$e = thrown(fn () => $svc->createPocket(W_PY, 'arnés almuerzo'));
check('(b2) nombre repetido (sin distinguir mayúsculas) rechazado', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
check('(b3) y la BD también lo rechaza',
    dbRejects('INSERT INTO wallet_pocket (companyid, name) VALUES (?, ?)', [W_PY, 'ARNÉS ALMUERZO ']) !== null,
    'el índice único no frenó el duplicado', $failures, $checks);
$renamed = $svc->renamePocket(W_PY, $pM, 'Arnés Merienda Tarde');
check('(b4) renombrar', $renamed['name'] === 'Arnés Merienda Tarde', json_encode($renamed), $failures, $checks);
$pMx = $svc->createPocket(W_MX, 'Arnés Almuerzo')['id'];
check('(b5) el mismo nombre en OTRO comercio sí se permite', $pMx !== '', 'no creó', $failures, $checks);
check('(b6) el catálogo es por comercio',
    count(array_filter($svc->listPockets(W_PY), fn ($p) => $p['id'] === $pMx)) === 0,
    'el bolsillo de la vecina aparece en PY', $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (c) jerarquía ===\n";
$svc->setParent(W_PY, W_C1, W_T1, W_PY_ADMIN);
$row = ncmExecute('SELECT parentcontactid FROM contact WHERE contactid = ?', [W_C1]);
check('(c1) hijo bajo titular', (string) ($row['parentcontactid'] ?? '') === W_T1, json_encode($row), $failures, $checks);

$e = thrown(fn () => $svc->setParent(W_PY, W_T1, W_T1, W_PY_ADMIN));
check('(c2) auto-referencia rechazada (servicio)', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
check('(c3) auto-referencia rechazada (BD)',
    dbRejects('UPDATE contact SET parentcontactid = contactid WHERE contactid = ?', [W_T2]) !== null,
    'el CHECK no frenó', $failures, $checks);

$e = thrown(fn () => $svc->setParent(W_PY, W_T2, W_MXC, W_PY_ADMIN));
check('(c4) titular de otro comercio rechazado (servicio)', $e instanceof WalletException && $e->getCode() === 404,
    (string) $e?->getMessage(), $failures, $checks);
$r = dbRejects('UPDATE contact SET parentcontactid = ? WHERE contactid = ?', [W_MXC, W_T2]);
check('(c5) titular de otro comercio rechazado (BD)', $r !== null && str_contains($r, 'wallet_parent_other_company'),
    (string) $r, $failures, $checks);

$e = thrown(fn () => $svc->setParent(W_PY, W_G, W_C1, W_PY_ADMIN));
check('(c6) dos niveles (el titular es hijo) rechazado (servicio)', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
$r = dbRejects('UPDATE contact SET parentcontactid = ? WHERE contactid = ?', [W_C1, W_G]);
check('(c7) dos niveles rechazado (BD)', $r !== null && str_contains($r, 'wallet_parent_is_child'), (string) $r, $failures, $checks);

$e = thrown(fn () => $svc->setParent(W_PY, W_T1, W_T2, W_PY_ADMIN));
check('(c8) un titular con hijos no puede pasar a hijo (servicio)', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
$r = dbRejects('UPDATE contact SET parentcontactid = ? WHERE contactid = ?', [W_T2, W_T1]);
check('(c9) un titular con hijos no puede pasar a hijo (BD)', $r !== null && str_contains($r, 'wallet_child_has_children'),
    (string) $r, $failures, $checks);

$e = thrown(fn () => $svc->setParent(W_PY, W_T2, W_T1, W_MX_ADMIN));
check('(c10) un actor de otro comercio no opera', $e instanceof WalletException && $e->getCode() === 403,
    (string) $e?->getMessage(), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (d) carga ===\n";
$load1 = $svc->load(W_PY, W_T1, $pA, 100, 'A', 'harness', null, W_PY_ADMIN);
$bm    = ncmExecute('SELECT billingmode FROM wallet_movement WHERE id = ?', [$load1['id']]);
check('(d1) el modo de facturación queda GRABADO en la fila', trim((string) ($bm['billingmode'] ?? '')) === 'A',
    json_encode($bm), $failures, $checks);
$e = thrown(fn () => $svc->load(W_PY, W_C1, $pA, 10, 'A', null, null, W_PY_ADMIN));
check('(d2) a un hijo no se le carga desde afuera', $e instanceof WalletException && movementCount(W_C1) === 0,
    (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->load(W_PY, W_T1, $pA, 10, 'Z', null, null, W_PY_ADMIN));
check('(d3) modo de facturación inválido rechazado', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
check('(d4) una carga sin modo la frena la BD',
    dbRejects(
        "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid)
         VALUES (?, ?, ?, 'load', 5, 105, ?)",
        [W_PY, W_T1, $pA, W_PY_ADMIN]
    ) !== null,
    'entró una carga sin billingmode', $failures, $checks);
$e = thrown(fn () => $svc->load(W_PY, W_T1, $pA, 0, 'A', null, null, W_PY_ADMIN));
check('(d5) monto cero rechazado', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (e) saldo = suma ===\n";
$svc->load(W_PY, W_T1, $pA, 50.25, 'A', 'harness', null, W_PY_ADMIN);
check('(e1) saldo = suma de los movimientos',
    near($svc->balance(W_PY, W_T1, $pA), 150.25) && near(sumAmounts(W_T1, $pA), 150.25),
    'saldo ' . $svc->balance(W_PY, W_T1, $pA) . ' suma ' . sumAmounts(W_T1, $pA), $failures, $checks);
check('(e2) un bolsillo sin movimientos da 0', near($svc->balance(W_PY, W_T1, $pM), 0.0),
    (string) $svc->balance(W_PY, W_T1, $pM), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (f) pagar sin saldo ===\n";
$before = movementCount(W_T1);
$e = thrown(fn () => $svc->spend(W_PY, W_T1, $pA, 200, 'harness', null, W_PY_ADMIN));
check('(f1) se rechaza y devuelve cuánto había',
    $e instanceof WalletInsufficientFundsException && near($e->available, 150.25) && near($e->requested, 200),
    $e ? get_class($e) . ' ' . $e->getMessage() : 'no lanzó', $failures, $checks);
check('(f2) y no escribió nada', movementCount(W_T1) === $before, 'movimientos ' . movementCount(W_T1) . " vs $before", $failures, $checks);
$sp = $svc->spend(W_PY, W_T1, $pA, 30.25, 'sale', 'a11e7000-0000-4000-8000-00000000f001', W_PY_ADMIN);
check('(f3) un pago que alcanza descuenta', near($sp['balanceAfter'], 120.0) && near($svc->balance(W_PY, W_T1, $pA), 120.0),
    json_encode($sp), $failures, $checks);
$e = thrown(fn () => $svc->spend(W_PY, W_T1, $pA, 120.01, null, null, W_PY_ADMIN));
check('(f4) un centavo de más también se rechaza', $e instanceof WalletInsufficientFundsException, (string) $e?->getMessage(), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (g) la BD frena el negativo y el saldo inventado ===\n";
$r = dbRejects(
    "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid)
     VALUES (?, ?, ?, 'spend', -500, -380, ?)",
    [W_PY, W_T1, $pA, W_PY_ADMIN]
);
check('(g1) INSERT directo con balanceafter < 0 falla por el CHECK',
    $r !== null && str_contains($r, 'wallet_movement_balance_nonneg_chk'), (string) $r, $failures, $checks);
$r = dbRejects(
    "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid, reason)
     VALUES (?, ?, ?, 'adjust', 1, 999999, ?, 'x')",
    [W_PY, W_T1, $pA, W_PY_ADMIN]
);
check('(g2) un balanceafter que no encadena con el anterior falla', $r !== null && str_contains($r, 'wallet_balance_chain_broken'),
    (string) $r, $failures, $checks);
$r = dbRejects(
    "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid)
     VALUES (?, ?, ?, 'spend', 5, 125, ?)",
    [W_PY, W_T1, $pA, W_PY_ADMIN]
);
check('(g3) un spend positivo lo frena el CHECK de signo', $r !== null && str_contains($r, 'wallet_movement_sign_chk'),
    (string) $r, $failures, $checks);
check('(g4) un movimiento contra el bolsillo de OTRO comercio lo frena la FK compuesta',
    dbRejects(
        "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid, reason)
         VALUES (?, ?, ?, 'adjust', 1, 1, ?, 'x')",
        [W_PY, W_T2, $pMx, W_PY_ADMIN]
    ) !== null,
    'entró', $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (h) append-only ===\n";
$r = dbRejects('UPDATE wallet_movement SET amount = 1000 WHERE id = ?', [$load1['id']]);
check('(h1) UPDATE de un movimiento falla', $r !== null && str_contains($r, 'wallet_movement_append_only'), (string) $r, $failures, $checks);
$r = dbRejects('DELETE FROM wallet_movement WHERE id = ?', [$load1['id']]);
check('(h2) DELETE de un movimiento falla', $r !== null && str_contains($r, 'wallet_movement_append_only'), (string) $r, $failures, $checks);
$r = dbRejects('TRUNCATE wallet_movement');
check('(h3) TRUNCATE falla', $r !== null && str_contains($r, 'wallet_movement_append_only'), (string) $r, $failures, $checks);
$db->BeginTrans();
$db->Execute("SELECT set_config('punto.tenant_purge', ?, true)", [W_MX]);
$purgeOther = null;
try {
    $db->Execute('DELETE FROM wallet_movement WHERE id = ?', [$load1['id']]);
} catch (\Throwable $ex) {
    $purgeOther = $ex->getMessage();
}
if ($db->HasOpenTransaction()) {
    $db->RollbackTrans();
}
check('(h4) la marca de purga de OTRO comercio no habilita el borrado', $purgeOther !== null,
    'borró un movimiento de PY con la marca de MX', $failures, $checks);
check('(h5) el movimiento sigue ahí', movementCount(W_T1) >= 3, (string) movementCount(W_T1), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (i) transferencia ===\n";
$tr = $svc->transfer(W_PY, W_T1, W_C1, $pA, 40, W_PY_ADMIN);
$grp = ncmExecute(
    'SELECT COUNT(*) AS n, SUM(amount) AS s FROM wallet_movement WHERE transfergroupid = ?',
    [$tr['transferGroupId']]
);
check('(i1) dos movimientos con el mismo grupo que suman cero',
    (int) ($grp['n'] ?? 0) === 2 && near((float) ($grp['s'] ?? 1), 0.0), json_encode($grp), $failures, $checks);
check('(i2) titular 80, hijo 40',
    near($svc->balance(W_PY, W_T1, $pA), 80.0) && near($svc->balance(W_PY, W_C1, $pA), 40.0),
    $svc->balance(W_PY, W_T1, $pA) . ' / ' . $svc->balance(W_PY, W_C1, $pA), $failures, $checks);

$bT1 = movementCount(W_T1);
$e = thrown(fn () => $svc->transfer(W_PY, W_T1, W_T2, $pA, 10, W_PY_ADMIN));
check('(i3) a un cliente que no es su hijo se rechaza, sin escribir',
    $e instanceof WalletException && movementCount(W_T1) === $bT1 && movementCount(W_T2) === 0,
    (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->transfer(W_PY, W_C1, W_T1, $pA, 10, W_PY_ADMIN));
check('(i4) de hijo a titular (hacia arriba) se rechaza', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->transfer(W_PY, W_T1, W_C1, $pA, 81, W_PY_ADMIN));
check('(i5) sin saldo suficiente, ningún lado',
    $e instanceof WalletInsufficientFundsException && movementCount(W_T1) === $bT1 && near($svc->balance(W_PY, W_C1, $pA), 40.0),
    (string) $e?->getMessage(), $failures, $checks);

// Atomicidad cuando el SEGUNDO lado falla: un trigger temporal hace fallar el
// INSERT del hijo. El del titular ya se escribió en la misma transacción; si
// la transferencia no fuera atómica, quedaría un débito sin crédito.
$db->Execute(
    "CREATE OR REPLACE FUNCTION fn_wallet_harness_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$
     BEGIN
       IF NEW.contactid = '" . W_C1 . "' THEN RAISE EXCEPTION 'harness_second_leg_failed'; END IF;
       RETURN NEW;
     END \$\$"
);
$db->Execute(
    'CREATE TRIGGER trg_wallet_harness_fail BEFORE INSERT ON wallet_movement
       FOR EACH ROW EXECUTE FUNCTION fn_wallet_harness_fail()'
);
$e = thrown(fn () => $svc->transfer(W_PY, W_T1, W_C1, $pA, 10, W_PY_ADMIN));
$db->Execute('DROP TRIGGER IF EXISTS trg_wallet_harness_fail ON wallet_movement');
$db->Execute('DROP FUNCTION IF EXISTS fn_wallet_harness_fail()');
check('(i6) si falla el segundo lado, el primero tampoco queda',
    $e !== null && movementCount(W_T1) === $bT1 && near($svc->balance(W_PY, W_T1, $pA), 80.0),
    ($e?->getMessage() ?? 'no lanzó') . ' | movs T1 ' . movementCount(W_T1) . " vs $bT1", $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (j) ajuste ===\n";
$e = thrown(fn () => $svc->adjust(W_PY, W_C1, $pA, 5, '   ', W_PY_ADMIN));
check('(j1) sin motivo se rechaza', $e instanceof WalletException, (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->adjust(W_PY, W_C1, $pA, -41, 'Error de carga', W_PY_ADMIN));
check('(j2) un ajuste negativo tampoco deja bajo cero', $e instanceof WalletInsufficientFundsException, (string) $e?->getMessage(), $failures, $checks);
$adj = $svc->adjust(W_PY, W_C1, $pA, -15, 'Error de carga', W_PY_ADMIN);
$adjRow = ncmExecute('SELECT reason, actorcontactid FROM wallet_movement WHERE id = ?', [$adj['id']]);
check('(j3) un ajuste válido graba motivo y autor',
    near($adj['balanceAfter'], 25.0) && ($adjRow['reason'] ?? '') === 'Error de carga'
        && (string) ($adjRow['actorcontactid'] ?? '') === W_PY_ADMIN,
    json_encode([$adj, $adjRow]), $failures, $checks);
check('(j4) un ajuste sin motivo lo frena la BD',
    dbRejects(
        "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, actorcontactid)
         VALUES (?, ?, ?, 'adjust', 1, 26, ?)",
        [W_PY, W_C1, $pA, W_PY_ADMIN]
    ) !== null,
    'entró un ajuste sin motivo', $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (k) reversa ===\n";
$saleId = 'a11e7000-0000-4000-8000-00000000f001';
$e = thrown(fn () => $svc->refund(W_PY, W_T1, $pA, 5, 'sale', 'a11e7000-0000-4000-8000-00000000f999', W_PY_ADMIN));
check('(k1) sin un pago que revertir se rechaza', $e instanceof WalletException && $e->getCode() === 404, (string) $e?->getMessage(), $failures, $checks);
$rf = $svc->refund(W_PY, W_T1, $pA, 20, 'sale', $saleId, W_PY_ADMIN);
check('(k2) revertir parte de un pago suma al bolsillo', near($rf['balanceAfter'], 100.0), json_encode($rf), $failures, $checks);
$e = thrown(fn () => $svc->refund(W_PY, W_T1, $pA, 10.26, 'sale', $saleId, W_PY_ADMIN));
check('(k3) devolver más de lo pagado se rechaza', $e instanceof WalletException && $e->getCode() === 409, (string) $e?->getMessage(), $failures, $checks);
$rf2 = $svc->refund(W_PY, W_T1, $pA, 10.25, 'sale', $saleId, W_PY_ADMIN);
check('(k4) devolver exactamente el resto se permite', near($rf2['balanceAfter'], 110.25), json_encode($rf2), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (l) orden por seq y paginación ===\n";
$page1 = $svc->movements(W_PY, W_T1, $pA, 3);
$seqs  = array_column($page1['movements'], 'seq');
$desc  = $seqs;
rsort($desc);
check('(l1) del más nuevo al más viejo por seq', $seqs === $desc && count($seqs) === 3, json_encode($seqs), $failures, $checks);
check('(l2) el primero es el último escrito', ($page1['movements'][0]['id'] ?? '') === $rf2['id'],
    json_encode($page1['movements'][0] ?? null), $failures, $checks);
$page2 = $svc->movements(W_PY, W_T1, $pA, 3, $page1['nextBeforeSeq']);
$seqs2 = array_column($page2['movements'], 'seq');
check('(l3) la página 2 sigue donde terminó la 1, sin repetir',
    $page1['nextBeforeSeq'] !== null && $seqs2 !== [] && max($seqs2) < min($seqs), json_encode([$seqs, $seqs2]), $failures, $checks);
$all = [];
$cursor = null;
do {
    $pg = $svc->movements(W_PY, W_T1, $pA, 2, $cursor);
    foreach ($pg['movements'] as $m) {
        $all[] = $m;
    }
    $cursor = $pg['nextBeforeSeq'];
} while ($cursor !== null);
$last = $all[0] ?? null;
check('(l4) recorriendo todas las páginas, la suma de amounts es el saldo',
    near(array_sum(array_column($all, 'amount')), $svc->balance(W_PY, W_T1, $pA))
        && $last !== null && near($last['balanceAfter'], $svc->balance(W_PY, W_T1, $pA)),
    count($all) . ' movimientos', $failures, $checks);
$trMov = array_values(array_filter($all, fn ($m) => $m['type'] === 'transfer'));
check('(l5) la transferencia muestra la contraparte', ($trMov[0]['counterpartId'] ?? '') === W_C1,
    json_encode($trMov[0] ?? null), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (m) cambiar de titular con saldo ===\n";
$e = thrown(fn () => $svc->setParent(W_PY, W_C1, W_T2, W_PY_ADMIN));
check('(m1) un hijo con saldo no cambia de titular', $e instanceof WalletException && $e->getCode() === 409,
    (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->setParent(W_PY, W_T2, W_T1, W_PY_ADMIN));
check('(m2) sin saldo, sí', $e === null, (string) $e?->getMessage(), $failures, $checks);
$svc->setParent(W_PY, W_T2, null, W_PY_ADMIN);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (n) bolsillo inactivo ===\n";
$svc->setPocketActive(W_PY, $pA, false);
$e = thrown(fn () => $svc->spend(W_PY, W_T1, $pA, 1, null, null, W_PY_ADMIN));
check('(n1) no se paga con un bolsillo inactivo', $e instanceof WalletException && $e->getCode() === 409, (string) $e?->getMessage(), $failures, $checks);
$bal = array_values(array_filter($svc->balances(W_PY, W_T1), fn ($b) => $b['pocketId'] === $pA));
check('(n2) pero su saldo se sigue viendo', isset($bal[0]) && $bal[0]['active'] === false && near($bal[0]['balance'], 110.25),
    json_encode($bal), $failures, $checks);
$e = thrown(fn () => $svc->adjust(W_PY, W_T1, $pA, 1, 'Corrección', W_PY_ADMIN));
check('(n3) un ajuste (corrección) sí se permite', $e === null, (string) $e?->getMessage(), $failures, $checks);
$svc->setPocketActive(W_PY, $pA, true);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (o) aislamiento multi-tenant ===\n";
check('(o1) los movimientos de un cliente de PY no se leen desde MX',
    $svc->movements(W_MX, W_T1)['movements'] === [], 'MX leyó movimientos de PY', $failures, $checks);
$e = thrown(fn () => $svc->adjust(W_MX, W_T1, $pMx, 5, 'x', W_MX_ADMIN));
check('(o2) MX no puede ajustar a un cliente de PY', $e instanceof WalletException && $e->getCode() === 404, (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->spend(W_PY, W_T1, $pMx, 1, null, null, W_PY_ADMIN));
check('(o3) PY no puede usar un bolsillo de MX', $e instanceof WalletException && $e->getCode() === 404, (string) $e?->getMessage(), $failures, $checks);
$e = thrown(fn () => $svc->load(W_PY, W_T1, $pA, 5, 'A', null, null, W_MX_ADMIN));
check('(o4) un usuario de MX no es autor válido en PY', $e instanceof WalletException && $e->getCode() === 403, (string) $e?->getMessage(), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (p) transacción anidada (como la venta de F2) ===\n";
$bNested = $svc->balance(W_PY, W_T1, $pA);
$db->StartTrans();
$svc->spend(W_PY, W_T1, $pA, 10, 'sale', null, W_PY_ADMIN);
$inside = $db->InTrans();
$db->FailTrans();
$db->CompleteTrans();
check('(p1) el débito corre dentro de la transacción externa', $inside === true, 'no quedó anidado', $failures, $checks);
check('(p2) si la externa hace rollback, el débito se va con ella', near($svc->balance(W_PY, W_T1, $pA), $bNested),
    $svc->balance(W_PY, W_T1, $pA) . " vs $bNested", $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (q) dos cajas pagan el mismo saldo a la vez ===\n";
$svc->load(W_PY, W_T3, $pM, 100, 'A', 'harness', null, W_PY_ADMIN);
$cli   = __DIR__ . '/_wallet_spend_once_cli.php';
$spawn = static function (int $hold) use ($cli, $pM) {
    $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING',
            $cli, W_PY, W_T3, $pM, '100', W_PY_ADMIN, (string) $hold];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    return [$proc, $pipes];
};
// La caja 1 debita y retiene la transacción 3 s; la caja 2 llega a mitad de
// camino. Sin el lock, la 2 leería el saldo viejo (100) y aprobaría también.
[$p1, $pipes1] = $spawn(3);
usleep(1_500_000);
[$p2, $pipes2] = $spawn(0);
$out1 = stream_get_contents($pipes1[1]) . stream_get_contents($pipes1[2]);
$out2 = stream_get_contents($pipes2[1]) . stream_get_contents($pipes2[2]);
proc_close($p1);
proc_close($p2);
check('(q1) la caja 1 paga y confirma', str_contains($out1, 'spent') && str_contains($out1, 'committed'), $out1, $failures, $checks);
check('(q2) la caja 2 esperó y encontró el bolsillo vacío', str_contains($out2, 'insufficient available=0'), $out2, $failures, $checks);
$spends = ncmExecute("SELECT COUNT(*) AS n FROM wallet_movement WHERE contactid = ? AND type = 'spend'", [W_T3]);
check('(q3) un solo débito, saldo 0',
    (int) ($spends['n'] ?? 0) === 1 && near($svc->balance(W_PY, W_T3, $pM), 0.0),
    json_encode($spends) . ' saldo ' . $svc->balance(W_PY, W_T3, $pM), $failures, $checks);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== (r) purga del tenant ===\n";
purge();
check('(r1) con la marca de SU comercio, la purga borra', movementCount(W_T1) === 0 && movementCount(W_C1) === 0,
    'quedaron movimientos', $failures, $checks);

$modules->toggle(W_PY, 'wallet', false);

harnessFinish($failures, $checks);
