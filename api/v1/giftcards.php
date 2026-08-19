<?php
/**
 * /api/v1/giftcards.php — consulta de gift cards (POS).
 *
 *   GET ?code=<n>&amount=<n>     → valida para canje  → { success: <status> }
 *   GET ?code=<n>&resource=info  → datos de la gift card → { ...campos }
 *
 *   POST ?resource=validate { code }               → { ok, id, code, currentBalance, expiresAt }
 *   POST ?resource=consume  { code, transactionId } → { ok }
 *
 * companyId del JWT. Lectura → GET (§22.7). Port de chkGiftCard (modos JSON; sin HTML).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/GiftCardService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\GiftCardService;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource  = (string) ($_GET['resource'] ?? '');

// ── POST routes (nueva tabla `giftcard`) ──────────────────────────────────────

if ($method === 'POST') {
    $body = (array) (json_decode(file_get_contents('php://input'), true) ?? []);

    if ($resource === 'validate') {
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            apiError('code requerido', 400);
        }

        // Match case-insensitivo: la emisión guarda el code en MAYÚSCULAS
        // (giftcard-issue-dialog.tsx), pero el cajero puede tipearlo en minúscula.
        $row = ncmExecute(
            'SELECT id, code, currentbalance, expiresat, usedat
               FROM giftcard
              WHERE companyid = ? AND UPPER(code) = UPPER(?)
              LIMIT 1',
            [$companyId, $code]
        );

        // La guarda anterior era `empty($row) || !is_array($row)`, y ese
        // `is_array()` rompía TODO el canje: ncmExecute devuelve un
        // CaseInsensitiveArray, que es un OBJETO, así que is_array() daba
        // false siempre y la validación respondía "Giftcard no encontrada"
        // para cualquier código, existiera o no (T2 del reporte del tester).
        // `!$row` alcanza: sin filas ncmExecute devuelve false/0, y un objeto
        // siempre es truthy.
        if (!$row) {
            apiError('Giftcard no encontrada', 404);
        }

        if (!empty($row['usedAt'])) {
            apiError('La giftcard ya fue consumida', 410);
        }

        if (!empty($row['expiresAt']) && strtotime($row['expiresAt']) < time()) {
            apiError('La giftcard está vencida', 410);
        }

        // Regla del negocio (owner, 2026-08-05): la gift card es de UN SOLO
        // USO y `consume` pone currentBalance en 0, así que el total de la
        // venta tiene que ser >= el saldo. Si no, el excedente se pierde.
        //
        // Se valida ACÁ, antes de que la venta se cobre, y no en `consume`
        // (que corre DESPUÉS del cobro): rechazar recién ahí dejaría la venta
        // pagada con una tarjeta que nunca se marca usada, o sea reutilizable.
        // Mismo criterio que el preflight del cobro parcial de mesa.
        //
        // `total` es opcional por compatibilidad: un caller viejo que no lo
        // mande sigue validando existencia/vencimiento como antes.
        $total = $body['total'] ?? null;
        if ($total !== null && (float) $total < (float) $row['currentBalance']) {
            apiError(
                'El total de la venta debe ser igual o mayor al saldo de la giftcard',
                422
            );
        }

        apiOk([
            'ok'             => true,
            'id'             => $row['id'],
            'code'           => $row['code'],
            'currentBalance' => (float) $row['currentBalance'],
            'expiresAt'      => $row['expiresAt'],
        ]);
    }

    if ($resource === 'consume') {
        global $db;

        $code          = trim((string) ($body['code'] ?? ''));
        $transactionId = trim((string) ($body['transactionId'] ?? ''));

        if ($code === '' || $transactionId === '') {
            apiError('code y transactionId requeridos', 400);
        }

        // Idempotencia: si ya fue consumida por esta misma transacción, ok.
        // Match case-insensitivo: mismo motivo que en validate() arriba.
        $row = ncmExecute(
            'SELECT id, code, usedat, usedbytransactionid, expiresat
               FROM giftcard
              WHERE companyid = ? AND UPPER(code) = UPPER(?)
              LIMIT 1',
            [$companyId, $code]
        );

        // La guarda anterior era `empty($row) || !is_array($row)`, y ese
        // `is_array()` rompía TODO el canje: ncmExecute devuelve un
        // CaseInsensitiveArray, que es un OBJETO, así que is_array() daba
        // false siempre y la validación respondía "Giftcard no encontrada"
        // para cualquier código, existiera o no (T2 del reporte del tester).
        // `!$row` alcanza: sin filas ncmExecute devuelve false/0, y un objeto
        // siempre es truthy.
        if (!$row) {
            apiError('Giftcard no encontrada', 404);
        }

        if (!empty($row['usedAt'])) {
            if ((string) $row['usedByTransactionId'] === $transactionId) {
                apiOk(['ok' => true]); // re-llamada idempotente
            }
            apiError('La giftcard ya fue consumida por otra transacción', 409);
        }

        if (!empty($row['expiresAt']) && strtotime($row['expiresAt']) < time()) {
            apiError('La giftcard está vencida', 410);
        }

        // Lock optimista: WHERE usedat IS NULL garantiza que solo un proceso
        // la consume (si 0 filas afectadas → conflict). Usa el code real de la
        // fila encontrada arriba (no el tipeado) para que el UPDATE matchee.
        $db->Execute(
            'UPDATE giftcard
                SET usedat = NOW(),
                    usedbytransactionid = ?,
                    currentbalance = 0
              WHERE companyid = ? AND code = ? AND usedat IS NULL',
            [$transactionId, $companyId, $row['code']]
        );

        if ((int) $db->Affected_Rows() === 0) {
            apiError('Conflicto: la giftcard fue consumida por otro proceso', 409);
        }

        apiOk(['ok' => true]);
    }

    apiError('Recurso no encontrado', 404);
}

// ── GET routes (legacy GiftCardService, tabla giftCardSold) ───────────────────

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$svc  = new GiftCardService(TenantContext::fromAuth($ctx));
$code = $_GET['code'] ?? '';

// Validación numérica compartida (legacy: 'invalid' en 200 para ambos modos).
if (!is_numeric($code) || (int) $code < 1) {
    apiOk(['success' => 'invalid']);
}

if ($resource === 'info') {
    $info = $svc->getInfo($companyId, (int) $code);
    if ($info === null) {
        apiError('Gift Card Not Found', 404);
    }
    apiOk($info);
}

// Modo status (bool).
$status = $svc->checkStatus($companyId, $code, (float) ($_GET['amount'] ?? 0));
if ($status === 'notfound') {
    apiError('Gift Card Not Found', 404);
}
apiOk(['success' => $status]);
