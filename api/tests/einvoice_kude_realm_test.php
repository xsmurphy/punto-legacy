<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del REALM del KuDE — `/v1/einvoice.php` abierto a `pos-app`.
 *
 * El cambio que cubre (2026-09-09): la caja necesita entregarle al cliente el
 * PDF del documento fiscal de la venta que acaba de cobrar, y el endpoint era
 * `panel` exclusivo. Se abrió UN recurso (`resource=kude`) y UN verbo (GET).
 *
 * Lo que se prueba es justamente el RECORTE, porque abrir un realm es la clase
 * de cambio que se amplía sola con el tiempo:
 *
 *  1. Un Bearer de device (realm `pos-app`) pasa el gate en `resource=kude`.
 *     Sin esto la feature no existe.
 *  2. Ese MISMO Bearer es rechazado en `documents` —el listado paginado de todo
 *     el histórico fiscal del tenant—, en `account` y en `paymentMethods`
 *     (configuración del emisor). Una caja no necesita nada de eso para
 *     descargar el KuDE de la venta que tiene en pantalla.
 *  3. Ese MISMO Bearer es rechazado en el POST, que es donde se cargan el
 *     certificado y el CSC. Es el punto que más caro sale si el recorte se
 *     afloja: un device es una credencial eterna repartida por el salón.
 *  4. El panel sigue entrando al KuDE. La apertura no puede haber movido el
 *     realm en vez de sumarlo.
 *  5. Un device REVOCADO no entra ni al KuDE. El POS es token-only: sin
 *     credencial válida no hay nada que rescate la request.
 *
 * Y una propiedad de PAYLOAD, que es la otra mitad de la feature: el detalle de
 * la transacción del POS (`TransactionService::getSingle`, un servicio DISTINTO
 * del que sirve el detalle del panel) tiene que traer `einvoiceDocuments`. Sin
 * eso el botón no tiene el id que pedirle al endpoint, y el bug sería mudo: la
 * pantalla simplemente no pintaría el bloque, como si la venta no tuviera
 * factura.
 *
 * Los casos de endpoint corren en SUBPROCESO (`_permission_once_cli.php`)
 * porque `apiError()` hace `exit`.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain — ver
 * run_einvoice_kude_realm_test.sh):
 *   POSTGRES_HOST=... php -d variables_order=EGPCS api/tests/einvoice_kude_realm_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\TransactionService;

require_once dirname(__DIR__) . '/lib/services/TransactionService.php';

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

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

/**
 * Corre un endpoint de `/v1` en subproceso con el Bearer dado.
 *
 * @return array{0:int,1:string} status leído del envelope y salida cruda.
 */
function hitEndpoint(string $endpointRel, string $method, string $query, string $bearer): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpointRel, $method, $query, '{}', '', $bearer,
    ];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return [0, 'no se pudo abrir el subproceso'];
    }
    fwrite($pipes[0], '{}');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // El status sale del ENVELOPE: bajo el SAPI cli `http_response_code()` no
    // refleja lo que seteó apiError(). Mismo criterio que api_realm_test.php.
    $raw = trim($out . $err);
    if (preg_match('/BODY:(\{.*?\})\s*\nHTTP_STATUS:/s', $raw, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            if (($env['ok'] ?? null) === true) {
                return [200, $raw];
            }
            // Dos formas conviven: el envelope de apiError() y el `die()` crudo
            // de authResolve() (donde `error` es un STRING).
            $code = (int) ($env['error']['code'] ?? $env['code'] ?? 0);
            if ($code > 0) {
                return [$code, $raw];
            }
            // `authResolve()` muere con un `code` que NO es numérico
            // (`session_revoked`, `device_incomplete`): es la señal que el
            // front usa para el self-healing del device, y por eso viaja como
            // string. Sin esta rama un rechazo REAL de auth se lee como "no
            // pude parsear" y el caso da falso rojo — que es exactamente lo
            // que pasó la primera vez que corrió este arnés. Todos esos
            // códigos salen del embudo de auth, así que son 401.
            $rawCode = $env['code'] ?? null;
            if (is_string($rawCode) && $rawCode !== '' && !ctype_digit($rawCode)) {
                return [401, $raw];
            }
        }
    }
    if (preg_match('/"code":\s*"?(\d{3})/', $raw, $m)) return [(int) $m[1], $raw];
    if (str_contains($raw, '"ok":true')) return [200, $raw];
    return [0, mb_substr($raw, 0, 400)];
}

$deviceIdsToClean  = [];
$sessionIdsToClean = [];
$txIdsToClean      = [];
$docIdsToClean     = [];

/** @var \Punto\Api\Database\Query $db */
global $db;

try {
    // ── Credenciales ─────────────────────────────────────────────────────────
    $device = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — KuDE realm',
        'phpunit-like/einvoice_kude_realm_test',
        'test-kude-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $device['deviceId'];
    $deviceBearer       = $device['token'];

    $revoked = DeviceAuth::issueDeviceToken(
        $companyId,
        $outletId,
        $registerId,
        $userId,
        'Test device — KuDE realm (revocado)',
        'phpunit-like/einvoice_kude_realm_test',
        'test-kude-rev-' . bin2hex(random_bytes(6)),
    );
    $deviceIdsToClean[] = $revoked['deviceId'];
    $revokedBearer      = $revoked['token'];
    authSessionRevokeByDevice($revoked['deviceId'], $companyId);

    $panelToken = authSessionCreate('panel', [
        'companyId' => $companyId,
        'userId'    => $userId,
        'outletId'  => $outletId,
        'meta'      => ['origen' => 'einvoice_kude_realm_test'],
    ]);
    $panelRow = authSessionLookup($panelToken);
    if ($panelRow !== null) {
        $sessionIdsToClean[] = (string) $panelRow['sessionId'];
    }

    // ── Fixture: una venta con su documento fiscal ───────────────────────────
    $txId  = '9f1c0d20-0000-4000-8000-0000000000a1';
    $docId = '9f1c0d20-0000-4000-8000-0000000000b1';
    $db->Execute(
        'INSERT INTO transaction
           (transactionId, transactionTotal, transactionType, transactionStatus,
            invoiceNo, invoicePrefix, userId, outletId, companyId, registerId)
         VALUES (?, 100000, 0, 1, 123, ?, ?, ?, ?, ?)',
        [$txId, '001-001', $userId, $outletId, $companyId, $registerId]
    );
    $txIdsToClean[] = $txId;
    $db->Execute(
        "INSERT INTO einvoice_document
           (einvoicedocid, companyid, transactionid, doctype, status, cdc, issued_at)
         VALUES (?, ?, ?, 'FC', 'issued', ?, now())",
        [$docId, $companyId, $txId, str_repeat('1', 44)]
    );
    $docIdsToClean[] = $docId;

    // ── 1. El device entra al KuDE ───────────────────────────────────────────
    //
    // "Pasó el gate" = cualquier cosa MENOS 401/403/0. El 409 es un final
    // legítimo acá: el documento del fixture no tiene PDF que generar sin
    // proveedor, y ese error se emite DESPUÉS de la autenticación. Lo que
    // importa es que ya no rebota por realm.
    [$st, $raw] = hitEndpoint('v1/einvoice.php', 'GET', 'resource=kude&id=' . $docId, $deviceBearer);
    check(
        'GET einvoice?resource=kude con Bearer de device pasa el gate del realm',
        $st !== 401 && $st !== 403 && $st !== 0,
        "status = $st (401/403 = el realm pos-app no entró) | $raw",
        $failures, $checks
    );

    // ── 2. El MISMO device NO entra al resto de los recursos ─────────────────
    foreach ([
        ['documents',      'resource=documents'],
        ['account',        'resource=account'],
        ['paymentMethods', 'resource=paymentMethods'],
    ] as [$label, $query]) {
        [$st, $raw] = hitEndpoint('v1/einvoice.php', 'GET', $query, $deviceBearer);
        check(
            "GET einvoice?$query con Bearer de device se rechaza",
            $st === 401 || $st === 403,
            "status = $st — se esperaba 401/403; el recorte por resource se aflojó | $raw",
            $failures, $checks
        );
    }

    // ── 3. Ni al POST (certificado y CSC) ────────────────────────────────────
    [$st, $raw] = hitEndpoint('v1/einvoice.php', 'POST', '', $deviceBearer);
    check(
        'POST einvoice con Bearer de device se rechaza',
        $st === 401 || $st === 403,
        "status = $st — se esperaba 401/403; el POST carga certificado y CSC | $raw",
        $failures, $checks
    );

    // ── 4. El panel sigue entrando al KuDE ───────────────────────────────────
    [$st, $raw] = hitEndpoint('v1/einvoice.php', 'GET', 'resource=kude&id=' . $docId, $panelToken);
    check(
        'GET einvoice?resource=kude con credencial de panel sigue pasando el gate',
        $st !== 401 && $st !== 403 && $st !== 0,
        "status = $st — la apertura MOVIÓ el realm en vez de sumarlo | $raw",
        $failures, $checks
    );

    // ── 5. Un device revocado no entra ni al KuDE ────────────────────────────
    [$st, $raw] = hitEndpoint('v1/einvoice.php', 'GET', 'resource=kude&id=' . $docId, $revokedBearer);
    check(
        'GET einvoice?resource=kude con Bearer de device REVOCADO se rechaza',
        $st === 401,
        "status = $st — se esperaba 401 | $raw",
        $failures, $checks
    );

    // ── 6. El detalle del POS trae los documentos fiscales ───────────────────
    //
    // Es el otro extremo de la feature: sin esta clave el front no tiene el id
    // del documento y el bloque del KuDE nunca se pinta. El detalle del POS
    // pasa por TransactionService, NO por el TransactionDetailService que sirve
    // al panel — cubrir uno no cubre al otro.
    $svc    = new TransactionService(
        new TenantContext($companyId, $outletId, $userId, $registerId, 'admin'),
        $db
    );
    $detail = $svc->getSingle($txId, $companyId);
    check(
        'el detalle del POS trae einvoiceDocuments',
        is_array($detail) && array_key_exists('einvoiceDocuments', $detail),
        'la clave no está en el payload de TransactionService::getSingle()',
        $failures, $checks
    );
    $docs = is_array($detail) ? ($detail['einvoiceDocuments'] ?? []) : [];
    check(
        'einvoiceDocuments trae el id y el CDC del documento de esta venta',
        is_array($docs) && count($docs) === 1
            && ($docs[0]['id'] ?? '') === $docId
            && ($docs[0]['status'] ?? '') === 'issued'
            && ($docs[0]['cdc'] ?? '') === str_repeat('1', 44),
        'documentos devueltos: ' . json_encode($docs),
        $failures, $checks
    );

    // ── 7. El gate FISCAL de entrega (el P0 de la review) ────────────────────
    //
    // La caja es un canal de ENTREGA: el PDF se lo lleva el comprador en la
    // mano. Así que rige el mismo predicado que el email y el portal, y ese
    // predicado es UNO solo (`kudeDeliveryBlocker`). Se ejercita directo
    // porque por el endpoint todos los rechazos salen 409 y no se distinguen
    // entre sí — lo que hay que probar es CUÁL es el motivo, no que haya uno.
    $svcFe = new \Punto\Api\EInvoice\EInvoiceService();

    // El fixture nace sin `sifen_status`: emitido, con CDC, pero SIN veredicto.
    // Es EXACTAMENTE el caso del incidente registrado — CDC válido y KuDE
    // descargable sobre un documento que SIFEN todavía no aceptó.
    check(
        'un documento con CDC pero SIN veredicto de SIFEN no se puede entregar',
        $svcFe->kudeDeliveryBlocker($companyId, $docId) === 'sifen_pending',
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId), true),
        $failures, $checks
    );

    $db->Execute("UPDATE einvoice_document SET sifen_status = 'Aprobado' WHERE einvoicedocid = ?", [$docId]);
    check(
        'aprobado por SIFEN sí se puede entregar',
        $svcFe->kudeDeliveryBlocker($companyId, $docId) === null,
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId), true),
        $failures, $checks
    );

    // Numeración (mig 204): SIFEN valida SU registro, no nuestro invariante de
    // que el número sea el que salió impreso. Un documento APROBADO puede
    // igual llevar el número de otra venta — y entregarlo en mano es el peor
    // caso de todos.
    $db->Execute(
        "UPDATE einvoice_document SET numbering_mismatch = 'esperado 001-002' WHERE einvoicedocid = ?",
        [$docId]
    );
    check(
        'aprobado pero con numeración discrepante NO se puede entregar',
        $svcFe->kudeDeliveryBlocker($companyId, $docId) === 'numbering_mismatch',
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId), true),
        $failures, $checks
    );

    $db->Execute("UPDATE einvoice_document SET numbering_mismatch = NULL WHERE einvoicedocid = ?", [$docId]);
    $db->Execute("UPDATE einvoice_document SET sifen_status = 'Rechazado' WHERE einvoicedocid = ?", [$docId]);
    check(
        'rechazado por SIFEN no se puede entregar',
        $svcFe->kudeDeliveryBlocker($companyId, $docId) === 'sifen_rejected',
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId), true),
        $failures, $checks
    );

    // El endpoint del POS aplica el gate y NO streamea el PDF de un rechazado.
    [$st, $raw] = hitEndpoint('v1/einvoice.php', 'GET', 'resource=kude&id=' . $docId, $deviceBearer);
    check(
        'GET kude desde la caja sobre un documento RECHAZADO responde 409, no el PDF',
        $st === 409 && !str_contains($raw, '%PDF'),
        "status = $st | $raw",
        $failures, $checks
    );

    // Y la pantalla recibe el MISMO veredicto que aplica el endpoint, así que
    // no puede ofrecer una descarga que después va a rebotar.
    $docsAfter = $svcFe->documentsForTransaction($companyId, $txId);
    check(
        'documentsForTransaction expone el veredicto y el motivo del bloqueo',
        ($docsAfter[0]['sifenVerdict'] ?? '') === 'rejected'
            && ($docsAfter[0]['deliveryBlocker'] ?? '') === 'sifen_rejected',
        'fila: ' . json_encode($docsAfter[0] ?? null),
        $failures, $checks
    );

    // ── 8. La ANULACIÓN es política del CANAL, no de la regla ────────────────
    //
    // El portal del cliente ofrece el KuDE de una factura anulada A PROPÓSITO:
    // `kudeAvailable` incluye `cancelled` y la pantalla pinta el aviso "Este
    // documento fue anulado el …" al lado del botón. El comprador va a buscar
    // el registro de una operación pasada. La caja y el email sí lo cortan:
    // ahí el documento se entrega como comprobante de una venta viva.
    $db->Execute(
        "UPDATE einvoice_document SET sifen_status = 'Aprobado', status = 'cancelled', cancelled_at = now()
          WHERE einvoicedocid = ?",
        [$docId]
    );
    check(
        'anulada: la CAJA no la entrega',
        $svcFe->kudeDeliveryBlocker($companyId, $docId) === 'cancelled',
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId), true),
        $failures, $checks
    );
    check(
        'anulada: el PORTAL sí la entrega (cancelledBlocks: false)',
        $svcFe->kudeDeliveryBlocker($companyId, $docId, false) === null,
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId, false), true),
        $failures, $checks
    );

    // El caso que justifica que sea un PARÁMETRO y no "el portal ignora ese
    // código": si el canal salteara `cancelled` DESPUÉS, el corto-circuito ya
    // habría devuelto 'cancelled' sin llegar a mirar el veredicto, y una
    // anulada Y RECHAZADA se le habría entregado al comprador.
    $db->Execute("UPDATE einvoice_document SET sifen_status = 'Rechazado' WHERE einvoicedocid = ?", [$docId]);
    check(
        'anulada Y rechazada: el portal tampoco la entrega — el chequeo de SIFEN igual corre',
        $svcFe->kudeDeliveryBlocker($companyId, $docId, false) === 'sifen_rejected',
        'blocker = ' . var_export($svcFe->kudeDeliveryBlocker($companyId, $docId, false), true),
        $failures, $checks
    );

    $db->Execute(
        "UPDATE einvoice_document SET sifen_status = 'Aprobado', status = 'issued', cancelled_at = NULL
          WHERE einvoicedocid = ?",
        [$docId]
    );

    // Aislamiento por tenant: el documento es de ESTA company y de ninguna otra.
    $otherCompany = '0ea6c5d8-57e5-4226-8140-ec914deec099';
    $detailOther  = $svc->getSingle($txId, $otherCompany);
    check(
        'la venta no se resuelve bajo otra company',
        $detailOther === null,
        'getSingle() devolvió datos con un companyId ajeno',
        $failures, $checks
    );
} finally {
    foreach ($docIdsToClean as $id) {
        $db->Execute('DELETE FROM einvoice_document WHERE einvoicedocid = ?', [$id]);
    }
    foreach ($txIdsToClean as $id) {
        $db->Execute('DELETE FROM transaction WHERE transactionId = ?', [$id]);
    }
    foreach ($deviceIdsToClean as $id) {
        try { ncmExecute('DELETE FROM auth_session WHERE deviceid = ?::uuid', [$id]); } catch (\Throwable) {}
        try { ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$id]); } catch (\Throwable) {}
    }
    foreach ($sessionIdsToClean as $id) {
        try { ncmExecute('DELETE FROM auth_session WHERE sessionid = ?::uuid', [$id]); } catch (\Throwable) {}
    }
}

harnessFinish($failures, $checks);
