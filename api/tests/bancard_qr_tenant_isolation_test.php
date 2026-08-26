<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del AISLAMIENTO CROSS-TENANT del QR de Bancard (path de dinero).
 *
 * ── La vulnerabilidad que cierra (auditoría 2026-08-26) ──────────────────────
 * `/v1/bancard.php` refresh/cancel tomaban el `id` del QR del body y lo mandaban
 * a Bancard con el token GLOBAL de plataforma, sin verificar pertenencia: un
 * tenant refrescaba/cancelaba el cobro de OTRO comercio con solo conocer el id.
 * Bancard no expone binding id→comercio, así que se persiste local al crear
 * (`BancardService::persistOwnership`, mig 174) y refresh/cancel validan contra
 * eso. Fail-open ante id DESCONOCIDO a propósito: no rompe QRs viejos ni un
 * flujo legítimo si no capturamos la clave del id — solo bloquea lo que sabemos
 * que es de otro tenant.
 *
 * Este arnés verifica el binding (ownerCompanyOf), el fail-open, la extracción
 * de ids de la respuesta de Bancard (extractQrIds, espeja psp-qr.ts) y el guard
 * estático del endpoint.
 *
 * Uso: ver run_bancard_qr_tenant_isolation_test.sh (Postgres descartable).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\BancardService;
use Punto\Api\Context\TenantContext;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function _bqUuid(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// Tenant A = seed "Verify PY"; Tenant B = efímero.
$companyA = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletA  = '1a282724-6073-49c3-8bc3-0114a132e349';
$userA    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$companyB = _bqUuid();
$qrIdB    = 'qr-test-B-' . bin2hex(random_bytes(6));

try {
    // ── Precondición: la mig 174 creó la tabla ────────────────────────────────
    $tbl = $db->Execute("SELECT to_regclass('public.bancard_qr') AS t");
    check(
        '(0) mig 174 aplicada — tabla bancard_qr existe',
        $tbl !== false && !$tbl->EOF && !empty($tbl->fields['t']),
        'to_regclass devolvió vacío — ¿corrió migrate.php?',
        $failures, $checks
    );

    // ── Fixtures ──────────────────────────────────────────────────────────────
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Bancard ISO B\"}'::jsonb)",
        [$companyB]
    );
    // QR emitido por el tenant B.
    $db->Execute(
        'INSERT INTO bancard_qr (qrId, companyId, outletId) VALUES (?, ?::uuid, NULL)',
        [$qrIdB, $companyB]
    );

    // Service en contexto del tenant A (el atacante).
    $svcA = new BancardService(new TenantContext($companyA, $outletA, $userA, '', ''));

    // ── (a) ownerCompanyOf resuelve el dueño real ─────────────────────────────
    // El endpoint rechaza si owner !== companyId del request: acá owner=B, y el
    // request es de A, así que refresh/cancel cortaría 404.
    check(
        '(a) ownerCompanyOf(qrDeB) devuelve el companyId de B (≠ A → el endpoint corta)',
        $svcA->ownerCompanyOf($qrIdB) === $companyB,
        'devolvió: ' . var_export($svcA->ownerCompanyOf($qrIdB), true) . ' (esperado ' . $companyB . ')',
        $failures, $checks
    );

    // ── (b) fail-open: id desconocido → null (no rompe flujos legítimos/viejos) ─
    check(
        '(b) ownerCompanyOf(idDesconocido) devuelve null (fail-open)',
        $svcA->ownerCompanyOf('no-existe-' . bin2hex(random_bytes(4))) === null,
        'un id no registrado debería dar null, no bloquear',
        $failures, $checks
    );

    // ── (c) extractQrIds espeja las claves y wrappers del front ────────────────
    $ref = new \ReflectionMethod(BancardService::class, 'extractQrIds');
    $ref->setAccessible(true);
    $idsNested = $ref->invoke($svcA, '{"status":"success","data":{"id":"NESTED-1"}}');
    check(
        '(c) extractQrIds saca el id anidado en el wrapper `data`',
        in_array('NESTED-1', $idsNested, true),
        'devolvió: ' . json_encode($idsNested),
        $failures, $checks
    );
    $idsMulti = $ref->invoke($svcA, '{"qr_id":"K1","operationId":"K2"}');
    check(
        '(c2) extractQrIds captura TODAS las claves candidatas (qr_id + operationId)',
        in_array('K1', $idsMulti, true) && in_array('K2', $idsMulti, true),
        'devolvió: ' . json_encode($idsMulti),
        $failures, $checks
    );

    // ── (e) persistOwnership escribe el binding desde la respuesta de Bancard ──
    // Cierra el blind spot: el path de escritura del binding (lo que arma la
    // protección) se ejercita sin pegarle a Bancard, invocándolo con una
    // respuesta de ejemplo. TenantContext solo valida no-vacío, así que sirven
    // ids dummy para outlet/user del contexto de B.
    $svcB = new BancardService(new TenantContext($companyB, _bqUuid(), _bqUuid(), '', ''));
    $persist = new \ReflectionMethod(BancardService::class, 'persistOwnership');
    $persist->setAccessible(true);
    $qrPersist = 'qr-persist-' . bin2hex(random_bytes(6));
    $persist->invoke($svcB, json_encode(['status' => 'success', 'data' => ['id' => $qrPersist]]));
    check(
        '(e) persistOwnership guarda el binding del id anidado apuntando a B',
        $svcB->ownerCompanyOf($qrPersist) === $companyB,
        'ownerCompanyOf tras persistOwnership devolvió: ' . var_export($svcB->ownerCompanyOf($qrPersist), true),
        $failures, $checks
    );

    // ── (f) guard estático: el endpoint valida ANTES de pegarle a Bancard ──────
    $src = (string) @file_get_contents(dirname(__DIR__) . '/v1/bancard.php');
    $assertPos  = strpos($src, '$assertQrOwned($id);');
    $refreshPos = strpos($src, '$svc->refreshQR($id)');
    $cancelPos  = strpos($src, '$svc->cancelQR($id)');
    check(
        '(f) bancard.php llama $assertQrOwned ANTES de refreshQR y cancelQR',
        $assertPos !== false && $refreshPos !== false && $cancelPos !== false
            && $assertPos < $refreshPos && $assertPos < $cancelPos,
        'assert=' . var_export($assertPos, true) . ' refresh=' . var_export($refreshPos, true) . ' cancel=' . var_export($cancelPos, true),
        $failures, $checks
    );

} finally {
    try { $db->Execute('DELETE FROM bancard_qr WHERE qrId = ?', [$qrIdB]); } catch (\Throwable) {}
    try { $db->Execute('DELETE FROM company WHERE companyId = ?', [$companyB]); } catch (\Throwable) {}
}

harnessFinish($failures, $checks);
