<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Planes sin versionado (owner 2026-09-15, context/34 §F4 SUPERSEDED).
 *
 * "Un cambio en el plan aplica a todos los que están atados a ese plan."
 * El versionado viejo clonaba la fila con un plan_code nuevo y archivaba la
 * vieja sin mover a nadie: en producción los tenants del Trial se quedaron en
 * el archivado (0 créditos) y el aumento a 500 nunca les llegó.
 *
 * ── Qué se verifica ────────────────────────────────────────────────────────
 *   A. Editar precio/límites/features/créditos es un UPDATE de la MISMA fila:
 *      mismo plan_code, mismo id, sin fila nueva, sin archivar, y los tenants
 *      siguen en el plan y LEEN los valores nuevos por el JOIN en vivo.
 *   B. name vacío → 422; plan inexistente → 404.
 *   C. El plan 0 se edita en el lugar, y con el 0 duplicado (índice único
 *      parcial) solo cambia la fila leída.
 *   D. list() trae también los archivados, con el conteo de tenants.
 *   E. update() funciona en el contexto real de /admin (solo includes/db.php,
 *      sin functions.php) — el realm admin ya rompió prod por eso.
 *   F. Mig 222 con el estado de producción: Trial 3 archivado (0 créditos, 6
 *      tenants) + Trial 5 vigente (500) → sobrevive el 3 con los términos del
 *      5, todos los tenants del Trial en el 3, el 5 borrado, billing_request
 *      re-apuntado. Un type con dos vigentes y uno sin vigente NO se tocan. Un
 *      type cuyo vigente ya es el código más bajo solo absorbe a los tenants.
 *   G. La mig 222 es idempotente.
 *
 * Uso: bash api/tests/run_plan_in_place_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Admin/PlanAdminService.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $GLOBALS['failures'] = ($GLOBALS['failures'] ?? 0) + 1;
    echo "FAIL $label\n";
    if ($detail !== '') {
        echo "     $detail\n";
    }
}

/** Filas como arrays planos (el realm admin itera recordsets así). */
function rows(string $sql, array $params = []): array
{
    global $db;
    $r   = $db->Execute($sql, $params);
    $out = [];
    if ($r) {
        while (!$r->EOF) {
            $out[] = $r->fields->toArray();
            $r->MoveNext();
        }
    }
    return $out;
}

function one(string $sql, array $params = []): ?array
{
    $r = rows($sql, $params);
    return $r[0] ?? null;
}

function insertPlan(int $code, string $name, string $type, float $price, int $credits, int $archived, int $maxUsers = 5): void
{
    global $db;
    $db->Execute(
        "INSERT INTO plans (name, type, price, duration_days, max_items, max_users, max_customers,
                            max_outlets, max_registers, max_suppliers, max_categories, max_brands,
                            features, ai_credits_monthly, plan_code, archived)
         VALUES (?, ?, ?, 30, 100, ?, 100, 1, 1, 10, 10, 10, '{}'::jsonb, ?, ?, ?)",
        [$name, $type, $price, $maxUsers, $credits, $code, $archived]
    );
}

function insertCompany(string $id, int $plan): void
{
    global $db;
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', ?, 0.00, FALSE, '{}'::jsonb)",
        [$id, $plan]
    );
}

function cid(int $n): string
{
    return sprintf('5a1e0000-0000-4000-9000-%012d', $n);
}

global $db;

// El arnés necesita el catálogo en blanco: los seeds (0 Free, 3 Trial, …) se
// reemplazan por un fixture controlado.
$db->Execute("DELETE FROM billing_request WHERE companyId::text LIKE '5a1e0000-%'");
$db->Execute("DELETE FROM company WHERE companyId::text LIKE '5a1e0000-%'");
$preexisting = one('SELECT count(*) AS n FROM company WHERE plan <> 0');
$db->Execute('UPDATE company SET plan = 0 WHERE plan <> 0');
$db->Execute('DELETE FROM plans');

$svc = new PlanAdminService();

// ── A. Edición en el lugar ──────────────────────────────────────────────────
insertPlan(0, 'Free', 'free', 0, 0, 0, 1);
insertPlan(10, 'Básico', 'basic', 100000, 1000, 0, 3);
insertCompany(cid(1), 10);
insertCompany(cid(2), 10);

$before   = one('SELECT id FROM plans WHERE plan_code = 10');
$countPre = (int) one('SELECT count(*) AS n FROM plans')['n'];

$res = $svc->update(10, [
    'name' => 'Básico', 'type' => 'basic', 'price' => 150000, 'duration_days' => 31,
    'max_users' => 7, 'max_items' => 250, 'ai_credits_monthly' => 2500,
    'features' => ['inventory' => true, 'tables' => false],
]);
$after = one('SELECT * FROM plans WHERE plan_code = 10');

check('A1 update ok', !empty($res['ok']), json_encode($res));
check('A2 no se creó una fila nueva', (int) one('SELECT count(*) AS n FROM plans')['n'] === $countPre);
check('A3 misma fila (mismo id)', $after && $after['id'] === $before['id']);
check('A4 no se archivó', $after && (int) $after['archived'] === 0);
check(
    'A5 los valores quedaron escritos',
    $after && abs((float) $after['price'] - 150000) < 0.001 && (int) $after['max_users'] === 7
        && (int) $after['max_items'] === 250 && (int) $after['ai_credits_monthly'] === 2500
        && (int) $after['duration_days'] === 31,
    json_encode($after)
);
$feat = json_decode((string) ($after['features'] ?? '{}'), true);
check('A6 features jsonb escrito', is_array($feat) && ($feat['inventory'] ?? null) === true, (string) ($after['features'] ?? ''));
check('A7 la respuesta no habla de versiones', !array_key_exists('versioned', $res) && ($res['plan']['code'] ?? null) === 10);
check('A8 la respuesta cuenta los tenants del plan', ($res['tenants'] ?? null) === 2, json_encode($res['tenants'] ?? null));

// Los lectores en vivo (UsersService, BillingService, PlanLifecycleService)
// resuelven por este JOIN: el tenant ve el valor nuevo sin tocar `company`.
$live = rows(
    'SELECT c.plan, p.max_users, p.ai_credits_monthly, p.price
       FROM company c JOIN plans p ON p.plan_code = c.plan
      WHERE c.companyId IN (?, ?)',
    [cid(1), cid(2)]
);
check(
    'A9 los dos tenants siguen en el plan 10 y leen los términos nuevos',
    count($live) === 2 && array_reduce($live, fn($ok, $r) => $ok && (int) $r['plan'] === 10
        && (int) $r['max_users'] === 7 && (int) $r['ai_credits_monthly'] === 2500, true),
    json_encode($live)
);

$res = $svc->update(10, ['name' => 'Básico 2']);
check('A10 editar solo el nombre también es en el lugar', !empty($res['ok']) && one('SELECT name FROM plans WHERE plan_code = 10')['name'] === 'Básico 2');

// ── B. Validaciones ─────────────────────────────────────────────────────────
$res = $svc->update(10, ['name' => '   ']);
check('B1 name vacío → 422', empty($res['ok']) && ($res['code'] ?? 0) === 422, json_encode($res));
$res = $svc->update(999, ['price' => 1]);
check('B2 plan inexistente → 404', empty($res['ok']) && ($res['code'] ?? 0) === 404, json_encode($res));

// ── C. Plan 0 ───────────────────────────────────────────────────────────────
$res = $svc->update(0, ['max_users' => 2, 'price' => 0, 'ai_credits_monthly' => 50]);
$free = one('SELECT * FROM plans WHERE plan_code = 0');
check('C1 el plan 0 se edita en el lugar (límites y créditos)', !empty($res['ok']) && (int) $free['max_users'] === 2 && (int) $free['ai_credits_monthly'] === 50, json_encode($res));

insertPlan(0, 'Free duplicado', 'free', 0, 0, 0, 1); // el índice único de plan_code excluye al 0
$res     = $svc->update(0, ['max_users' => 9]);
$changed = (int) one('SELECT count(*) AS n FROM plans WHERE plan_code = 0 AND max_users = 9')['n'];
check('C2 con el 0 duplicado, solo cambia UNA fila', !empty($res['ok']) && $changed === 1, "filas cambiadas: $changed");
$db->Execute("DELETE FROM plans WHERE plan_code = 0 AND name = 'Free duplicado'");

// ── D. list() ───────────────────────────────────────────────────────────────
insertPlan(11, 'Viejo', 'old', 1, 0, 1);
insertCompany(cid(3), 11);
$list  = $svc->list();
$byCode = [];
foreach ($list as $p) {
    $byCode[$p['code']] = $p;
}
check('D1 list() incluye archivados', isset($byCode[11]) && $byCode[11]['archived'] === true, json_encode(array_keys($byCode)));
check('D2 list() cuenta tenants por plan', ($byCode[10]['tenants'] ?? null) === 2 && ($byCode[11]['tenants'] ?? null) === 1);
$res = $svc->update(11, ['max_users' => 4]);
check('D3 un plan archivado con tenants también se edita (es un plan vivo)', !empty($res['ok']) && (int) one('SELECT max_users FROM plans WHERE plan_code = 11')['max_users'] === 4, json_encode($res));
check('D4 editar no escribe archived', (int) one('SELECT archived FROM plans WHERE plan_code = 11')['archived'] === 1);

// ── E. Contexto real de /admin: solo includes/db.php ────────────────────────
$probe = tempnam(sys_get_temp_dir(), 'plan_') . '.php';
file_put_contents($probe, <<<'PHP'
<?php
require_once getenv('PUNTO_API_DIR') . '/includes/db.php';
require_once getenv('PUNTO_API_DIR') . '/lib/Admin/PlanAdminService.php';
$svc = new PlanAdminService();
$res = $svc->update(10, ['max_brands' => 77]);
$lst = $svc->list();
echo (!empty($res['ok']) && is_array($lst) && count($lst) > 0) ? 'UPDATE_OK' : ('FALLO ' . json_encode($res));
PHP);
$probeOut = trim((string) shell_exec(sprintf(
    'PUNTO_API_DIR=%s %s -d variables_order=EGPCS %s 2>&1',
    escapeshellarg(dirname(__DIR__)),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($probe)
)));
@unlink($probe);
check('E1 update()/list() corren sin el bootstrap del panel', str_contains($probeOut, 'UPDATE_OK'), $probeOut);
check('E2 y escribieron', (int) one('SELECT max_brands FROM plans WHERE plan_code = 10')['max_brands'] === 77);

// ── F. Mig 222 con el estado de producción ─────────────────────────────────
$db->Execute("DELETE FROM billing_request WHERE companyId::text LIKE '5a1e0000-%'");
$db->Execute("DELETE FROM company WHERE companyId::text LIKE '5a1e0000-%'");
$db->Execute('DELETE FROM plans');

// Réplica del catálogo de prod (2026-09-15).
insertPlan(0, 'Free', 'free', 0, 0, 0, 1);
insertPlan(1, 'Local Dev Plan', 'dev', 0, 0, 0);
insertPlan(3, 'Trial', 'trial', 0, 0, 1, 99999);       // ARCHIVADO, 0 créditos
insertPlan(4, 'Inicial', 'inicial', 299000, 10000, 0);
insertPlan(5, 'Trial', 'trial', 0, 500, 0, 99999);     // vigente, 500 créditos
$db->Execute("UPDATE plans SET features = '{\"inventory\":true}'::jsonb, duration_days = 14 WHERE plan_code = 5");
for ($i = 1; $i <= 6; $i++) {
    insertCompany(cid(100 + $i), 3);   // los 6 tenants del Trial archivado
}
insertCompany(cid(107), 5);            // uno que ya está en el vigente
insertCompany(cid(108), 4);            // Inicial: no se toca

// Casos que NO se pueden adivinar.
insertPlan(20, 'Pro', 'pro', 1, 0, 1);  // archivado, DOS vigentes → no se toca
insertPlan(21, 'Pro A', 'pro', 2, 0, 0);
insertPlan(22, 'Pro B', 'pro', 3, 0, 0);
insertCompany(cid(120), 20);
insertPlan(30, 'Legacy', 'legacy', 1, 0, 1); // archivado sin vigente → no se toca
insertCompany(cid(130), 30);

// Vigente = código más bajo: solo absorbe a los tenants del archivado.
insertPlan(40, 'Plus', 'plus', 50, 100, 0);
insertPlan(41, 'Plus', 'plus', 40, 0, 1);
insertCompany(cid(141), 41);

$db->Execute(
    "INSERT INTO billing_request (companyId, requestedPlanCode, currentPlanCode, status)
     VALUES (?, 5, 3, 'pending'), (?, 41, 40, 'approved')",
    [cid(101), cid(141)]
);

$migSql = file_get_contents(dirname(__DIR__) . '/database/migrations/postgres/222_planes_sin_versionado.sql');
$pdo    = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('POSTGRES_HOST'), getenv('POSTGRES_PORT') ?: '5432', getenv('POSTGRES_DB')),
    getenv('POSTGRES_USER'),
    getenv('POSTGRES_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$migErr = '';
try {
    $pdo->exec($migSql); // igual que migrate.php: multi-statement con BEGIN/COMMIT
} catch (\Throwable $e) {
    $migErr = $e->getMessage();
}
check('F0 la mig 222 corre', $migErr === '', $migErr);

$trial = rows("SELECT * FROM plans WHERE type = 'trial'");
check('F1 queda UN solo plan Trial', count($trial) === 1, json_encode(array_column($trial, 'plan_code')));
$t = $trial[0] ?? [];
check('F2 sobrevive el código 3 (el que asigna SignupService)', (int) ($t['plan_code'] ?? -1) === 3);
check(
    'F3 con los términos del vigente (500 créditos, 14 días, features)',
    (int) ($t['ai_credits_monthly'] ?? 0) === 500 && (int) ($t['duration_days'] ?? 0) === 14
        && (json_decode((string) ($t['features'] ?? '{}'), true)['inventory'] ?? null) === true,
    json_encode($t)
);
check('F4 y no archivado', (int) ($t['archived'] ?? 1) === 0);
$onTrial = (int) one("SELECT count(*) AS n FROM company WHERE companyId::text LIKE '5a1e0000-%' AND plan = 3")['n'];
check('F5 los 7 tenants del Trial (6 del archivado + 1 del vigente) están en el 3', $onTrial === 7, "en el 3: $onTrial");
check('F6 nadie quedó en el 5', (int) one('SELECT count(*) AS n FROM company WHERE plan = 5')['n'] === 0);
check('F7 Inicial y su tenant intactos', (int) one('SELECT plan FROM company WHERE companyId = ?', [cid(108)])['plan'] === 4
    && (int) one('SELECT ai_credits_monthly FROM plans WHERE plan_code = 4')['ai_credits_monthly'] === 10000);
$br = one('SELECT requestedPlanCode, currentPlanCode FROM billing_request WHERE companyId = ?', [cid(101)]);
check('F8 billing_request re-apuntado (5→3)', $br && (int) $br['requestedplancode'] === 3 && (int) $br['currentplancode'] === 3, json_encode($br));
check(
    'F9 type con DOS vigentes no se toca',
    (int) one("SELECT count(*) AS n FROM plans WHERE type = 'pro'")['n'] === 3
        && (int) one('SELECT plan FROM company WHERE companyId = ?', [cid(120)])['plan'] === 20
);
check(
    'F10 type sin vigente no se toca',
    one('SELECT archived FROM plans WHERE plan_code = 30') !== null
        && (int) one('SELECT plan FROM company WHERE companyId = ?', [cid(130)])['plan'] === 30
);
$plus = rows("SELECT plan_code, price, ai_credits_monthly FROM plans WHERE type = 'plus'");
check(
    'F11 vigente = código más bajo: queda el 40 con sus términos y absorbe al tenant del 41',
    count($plus) === 1 && (int) $plus[0]['plan_code'] === 40 && (int) $plus[0]['ai_credits_monthly'] === 100
        && (int) one('SELECT plan FROM company WHERE companyId = ?', [cid(141)])['plan'] === 40,
    json_encode($plus)
);
$br2 = one('SELECT requestedPlanCode FROM billing_request WHERE companyId = ?', [cid(141)]);
check('F12 billing_request del 41 re-apuntado al 40', $br2 && (int) $br2['requestedplancode'] === 40);
check('F13 Free y Local Dev intactos', (int) one('SELECT count(*) AS n FROM plans WHERE plan_code IN (0, 1)')['n'] === 2);

// ── G. Idempotencia ─────────────────────────────────────────────────────────
$snap = static fn() => json_encode([
    rows('SELECT plan_code, name, type, price, ai_credits_monthly, archived FROM plans ORDER BY plan_code, name'),
    rows("SELECT companyId, plan FROM company WHERE companyId::text LIKE '5a1e0000-%' ORDER BY companyId"),
    rows("SELECT companyId, requestedPlanCode, currentPlanCode FROM billing_request WHERE companyId::text LIKE '5a1e0000-%' ORDER BY companyId"),
]);
$s1 = $snap();
$migErr = '';
try {
    $pdo->exec($migSql);
} catch (\Throwable $e) {
    $migErr = $e->getMessage();
}
check('G1 la segunda corrida no falla', $migErr === '', $migErr);
check('G2 y no cambia nada', $snap() === $s1);

// ── Limpieza ────────────────────────────────────────────────────────────────
$db->Execute("DELETE FROM billing_request WHERE companyId::text LIKE '5a1e0000-%'");
$db->Execute("DELETE FROM company WHERE companyId::text LIKE '5a1e0000-%'");
echo 'companies preexistentes con plan <> 0 (movidas a 0 por el fixture): ' . ($preexisting['n'] ?? '?') . "\n";

harnessFinish($failures, $checks);
