<?php
/**
 * /v1/pos-wallet — la wallet desde la CAJA (context/74 F2, D12-D15).
 *
 *   GET  ?resource=balances&contactId=<uuid>
 *        → { contactId, isChild, balances: [{ pocketId, name, active, balance }] }
 *        Saldo del cliente por bolsillo, para mostrarlo al elegirlo (D7) y para
 *        el cobro con saldo. SIEMPRE online: el saldo es compartido entre cajas
 *        y nunca se aprueba contra una copia guardada en el dispositivo (§5).
 *
 *   POST ?resource=consume
 *        body: { pocketId, sale: { uid, sale[], subtotal, discount, client, note?, tags?, timestamp, date } }
 *        → 201 { transactionId, uid, invoiceNo, total, pocketId, balance, duplicated }
 *        → 409 { code: 'INSUFFICIENT_FUNDS', available, requested }  (NO escribe nada)
 *
 *        El COMPROBANTE INTERNO DE CONSUMO (D12): en UNA transacción saca la
 *        mercadería (stock + COGS congelado por línea), numera en el talonario
 *        interno de la caja y debita el bolsillo — el chequeo de saldo y el
 *        débito son la misma operación, bajo el lock del bolsillo (§5). No suma
 *        a ventas, no mueve caja, no emite factura electrónica. Ver
 *        `SaleType::WalletConsumption`.
 *
 *        Idempotente por `sale.uid`: el POS reintenta con el MISMO uid tras un
 *        timeout y recibe el comprobante original (`duplicated: true`), nunca
 *        un segundo débito.
 *
 *        Si el saldo no alcanza responde 409 con cuánto hay y no escribe nada:
 *        la caja cobra la diferencia como una CARGA (una venta normal con su
 *        factura, D14) y recién después reintenta el consumo entero con saldo.
 *
 * ── Qué NO está acá ─────────────────────────────────────────────────────────
 *
 * La CARGA. Es una venta: va por `/v1/sales` o la cola offline como cualquier
 * otra, con la línea `walletLoad` (D15: se emite sin red). El `load` se escribe
 * dentro de esa venta (`SaleService::persistWalletMovements`).
 *
 * ── Auth: token-only, realm `pos-app` y NADA más ─────────────────────────────
 *
 * `apiAuthTenant(['pos-app'])`: el único realm aceptado es el del device (Bearer
 * del dispositivo pareado, sin cookies — mandato del POS). No es un endpoint
 * multi-realm, así que no hay "primera credencial válida gana".
 *
 * Los permisos se evalúan contra el OPERADOR del PIN (`X-Operator-Token`),
 * no contra el rol `device` — el mismo que tiene cualquiera que agarre la
 * tablet (`OperatorContext`, patrón de context/59 y de `pos.stock.count`):
 *   - `pos.wallet.spend` para cobrar con saldo;
 *   - `pos.wallet.spend` o `pos.wallet.load` para ver saldos (quien carga
 *     necesita ver cuánto tiene el cliente).
 * El autor del débito es ese operador, no el usuario que pareó la tablet.
 *
 * Alcance: el saldo es del COMERCIO (§3.1), se consume en cualquier sucursal.
 * El comprobante sí queda en la sucursal/caja del dispositivo.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;
use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Wallet\WalletException;
use Punto\Api\Wallet\WalletInsufficientFundsException;
use Punto\Api\Wallet\WalletService;

$ctx       = apiAuthTenant(['pos-app']);
$companyId = (string) $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource  = (string) ($_GET['resource'] ?? '');

if (($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Esta acción es de la caja', 403);
}
if (!(new \Punto\Api\Modules\ModulesService())->isEnabled($companyId, 'wallet')) {
    apiError('El saldo de clientes no está activo en este comercio', 403);
}

/** @var \DB $db */
global $db;
$svc = new WalletService();

$isUuid = static fn (string $v): bool
    => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);

/** Saldos del cliente + si es un cliente a cargo (no se le carga, §3.1). */
$balancesOf = static function (string $contactId) use ($svc, $companyId, $db): array {
    $rs = $db->Execute(
        'SELECT parentcontactid FROM contact WHERE contactid = ? AND companyid = ? AND type = 1 LIMIT 1',
        [$contactId, $companyId]
    );
    if (!$rs || $rs->EOF) {
        apiError('Cliente no encontrado', 404);
    }
    return [
        'contactId' => $contactId,
        'isChild'   => !empty($rs->fields['parentcontactid']),
        'balances'  => $svc->balances($companyId, $contactId),
    ];
};

try {
    switch ($resource) {
        case 'balances':
            if ($method !== 'GET') {
                apiError('Method not allowed', 405);
            }
            OperatorContext::requireAnyPermission($ctx, ['pos.wallet.spend', 'pos.wallet.load']);
            $contactId = (string) ($_GET['contactId'] ?? '');
            if (!$isUuid($contactId)) {
                apiError('contactId inválido', 422);
            }
            apiOk($balancesOf($contactId));

        case 'consume':
            if ($method !== 'POST') {
                apiError('Method not allowed', 405);
            }
            // Una caja: el comprobante numera en SU talonario interno.
            if ((string) ($ctx['registerId'] ?? '') === '') {
                apiError('Seleccioná una caja antes de cobrar', 403);
            }
            OperatorContext::requirePermission($ctx, 'pos.wallet.spend');
            $operatorId = (string) (OperatorContext::resolve($ctx)['userId'] ?? '');
            if ($operatorId === '') {
                apiError('Desbloqueá la caja con tu PIN para cobrar con saldo', 403);
            }

            $pocketId = (string) ($_POST['pocketId'] ?? '');
            $pocket   = $isUuid($pocketId) ? $svc->findPocket($companyId, $pocketId) : null;
            if ($pocket === null) {
                apiError('Bolsillo no encontrado', 404);
            }
            if (!$pocket['active']) {
                apiError('El bolsillo "' . $pocket['name'] . '" está desactivado', 409);
            }

            $salePayload = $_POST['sale'] ?? null;
            if (!is_array($salePayload)) {
                apiError('Falta el consumo', 422);
            }

            try {
                $input = SaleInput::forWalletConsumption($salePayload, $companyId, $pocket, $operatorId);
            } catch (InvalidSaleInputException $e) {
                apiError($e->getMessage(), 422);
            }

            $service = new SaleService(ctx: TenantContext::fromAuth($ctx), db: $db);

            try {
                $result = $service->save($input);
            } catch (DuplicateSaleException $e) {
                // Reintento del MISMO cobro (timeout, doble toque): se devuelve
                // el comprobante original. Solo si ESE uid era un consumo: un
                // uid de venta reutilizado no es "el mismo cobro".
                $existing = $e->existing;
                if ($existing->type !== \Punto\Api\Sales\SaleType::WalletConsumption->value) {
                    apiError('Ese cobro ya se registró como otra operación', 409);
                }
                apiOk([
                    'transactionId' => $existing->transactionId,
                    'uid'           => $existing->uid,
                    'invoiceNo'     => $existing->invoiceNo,
                    'total'         => $existing->total,
                    'pocketId'      => $pocket['id'],
                    'balance'       => $svc->balance($companyId, (string) $input->clientId, $pocket['id']),
                    'duplicated'    => true,
                ]);
            } catch (WalletInsufficientFundsException $e) {
                apiError('El saldo del bolsillo no alcanza', 409, [
                    'code'      => 'INSUFFICIENT_FUNDS',
                    'available' => $e->available,
                    'requested' => $e->requested,
                ]);
            } catch (InvalidSaleInputException $e) {
                apiError($e->getMessage(), 422);
            } catch (SaleAbortedException $e) {
                error_log('[pos-wallet] consumo abortado ' . $input->uid . ': ' . ($e->dbError ?? $e->getMessage()));
                apiError($e->clientMessage(), 500);
            }

            $row = $db->Execute(
                'SELECT invoiceno FROM transaction WHERE transactionid = ? AND companyid = ? LIMIT 1',
                [$result->transactionId, $companyId]
            );
            $net = (float) ($input->payment[0]['total'] ?? 0);

            apiOk([
                'transactionId' => $result->transactionId,
                'uid'           => $result->uid,
                'invoiceNo'     => ($row && !$row->EOF && $row->fields['invoiceno'] !== null) ? (int) $row->fields['invoiceno'] : null,
                'total'         => $net,
                'pocketId'      => $pocket['id'],
                'balance'       => $svc->balance($companyId, (string) $input->clientId, $pocket['id']),
                'duplicated'    => false,
            ], 201);

        default:
            apiError('Recurso inválido', 404);
    }
} catch (WalletException $e) {
    apiError($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
}
