<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

require_once dirname(__DIR__) . '/Auth/OperatorAssertion.php';
require_once dirname(__DIR__) . '/Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorAssertion;
use Punto\Api\Auth\OperatorContext;
use Punto\Api\Sales\SaleInput;

/**
 * ¿Quien EMITIÓ esta venta podía cargar saldo? (wallet F2, context/74 §12)
 *
 * El camino directo (`/v1/sales`) lo pregunta al operador del PIN de la
 * request, que es quien emite en ese momento. La cola offline no puede: la
 * venta se emitió sin red, a veces horas antes, y quien sincroniza puede ser
 * otra persona (o nadie). Por eso la caja embebe en toda venta con carga la
 * afirmación firmada del operador AL EMITIR (`SaleInput::$walletLoadAuth`), y
 * acá se verifica:
 *
 *   - firma del servidor + comercio (no se fabrica en el browser);
 *   - vigente en el INSTANTE de la emisión (`OperatorAssertion::verifyAt`), no
 *     ahora: una afirmación que venció entre la emisión y el sync sigue
 *     probando quién emitió;
 *   - `pos.wallet.load` en el rol de esa persona.
 *
 * Sin afirmación, o sin permiso, devuelve false: la venta YA EMITIDA se guarda
 * igual y la carga queda retenida y marcada (`SaleService::save()`).
 */
final class WalletLoadPermission
{
    public const PERMISSION = 'pos.wallet.load';

    public static function authorizedAtEmission(SaleInput $input, string $companyId): bool
    {
        $hasLoad = false;
        foreach ($input->sale as $line) {
            if (is_array($line) && is_array($line['walletLoad'] ?? null)) {
                $hasLoad = true;
                break;
            }
        }
        if (!$hasLoad) {
            return true;
        }

        $at = $input->timestamp > 0 ? $input->timestamp : (int) strtotime($input->date);
        $operatorId = OperatorAssertion::verifyAt($input->walletLoadAuth, $companyId, $at);
        if ($operatorId === null) {
            return false;
        }
        $roleId = OperatorContext::roleOf($companyId, $operatorId);
        return OperatorContext::can(
            ['userId' => $operatorId, 'roleId' => $roleId, 'identified' => true],
            self::PERMISSION,
            $companyId
        );
    }
}
