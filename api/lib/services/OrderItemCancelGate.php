<?php
declare(strict_types=1);

namespace Punto\Api\Services;

require_once __DIR__ . '/OrderItemCancelBlockedException.php';
require_once __DIR__ . '/../Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;

/**
 * OrderItemCancelGate — "quién puede borrar una línea de una comanda, y hasta
 * cuándo".
 *
 * Tres reglas, en este orden:
 *
 *   1. `pos.order.item.cancel` es requisito SIEMPRE. Sin esa clave no se anula
 *      nada, con ventana o sin ella.
 *   2. Dentro de la ventana configurada, alcanza con la clave base. La ventana
 *      se cuenta desde `pos_order_item.created_at` — el momento en que la línea
 *      se cargó, no en que la orden se envió: lo que el comercio quiere acotar
 *      es cuánto tiempo se puede deshacer algo, y ese reloj arranca cuando la
 *      línea existe.
 *   3. Pasada la ventana hace falta `pos.order.item.cancel.late`. No es una
 *      prohibición: es una ELEVACIÓN. El encargado se identifica con su PIN y
 *      la anulación procede — y queda registrada igual, con su nombre, en
 *      `pos_order_event`. La elevación no esconde nada; ese es todo el punto.
 *
 * ── El interruptor, y por qué el default es 0 ──────────────────────────────
 *
 * `company.config->>'settingOrderItemCancelWindowMinutes'` — entero de minutos.
 * `0` = SIN LÍMITE, y es el default: la feature nace APAGADA, igual que
 * `settingDrawerRequireClosedOrders`. Un comercio que actualiza no ve ningún
 * cambio de comportamiento hasta que decide la ventana en Ajustes.
 *
 * El default tenía que ser el permisivo por una razón concreta: la mitad
 * bloqueante de esta feature (la ventana) es una decisión de política del
 * comercio, pero la mitad que le da sentido (motivo obligatorio + quién lo
 * hizo) NO es opcional y ya corre siempre desde `OrderCoreService`. O sea que
 * el comercio que no configura nada igual gana la trazabilidad; lo que elige
 * es si además quiere el límite de tiempo.
 *
 * Sin migración de datos: es una clave del JSONB `company.config`, como el
 * resto de los toggles de Ajustes. Se lee con `->>` y NUNCA con el operador
 * `?` de jsonb, que PDO reescribe a placeholder y aborta el boot del
 * contenedor (incidente de las migs 74/77).
 *
 * ── El gate corre IGUAL en el panel ────────────────────────────────────────
 *
 * No hay excepción por realm. `OperatorContext` resuelve la persona en los dos
 * lados —en `panel` la credencial ES la persona, en `pos-app` sale del PIN— y
 * el permiso se mide contra SU rol. Un encargado en el panel tiene `.late` y
 * pasa; alguien sin la clave, no. Exceptuar al panel sería dejar abierta la
 * puerta de al lado: el mismo ítem, la misma anulación, sin ventana.
 *
 * Postgres: `pos_order_item` es de las tablas lowercase-sin-comillas (patrón
 * migs 79/80/85), a diferencia de `company`, que sí lleva `companyId` en
 * camelCase (memoria `project_pg_identifier_casing`).
 */
final class OrderItemCancelGate
{
    /**
     * Minutos de la ventana para este comercio. `0` = sin límite.
     *
     * Ante la AUSENCIA del dato (company sin la clave, o sin fila) devuelve 0 y
     * la anulación procede sin límite de tiempo: la regla no se inventa sola.
     * Un valor no numérico o negativo se trata igual que ausente — es
     * configuración corrupta, y la lectura estricta ("cualquier cosa rara
     * bloquea") convertiría un typo en Ajustes en una caja que no puede
     * corregir una comanda.
     *
     * Un error de DB **no** se traga acá: propaga como cualquier otra query y
     * termina en el 500 genérico de `error_handlers.php`. Mismo criterio que
     * `ShiftCloseGate::isEnabled()`.
     */
    public static function windowMinutes(string $companyId): int
    {
        if ($companyId === '') {
            return 0;
        }
        $row = ncmExecute(
            "SELECT config->>'settingOrderItemCancelWindowMinutes' AS win
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false
        );
        $raw = trim((string) ($row['win'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return 0;
        }

        // MISMO clamp que la escritura (`SettingsService`), y no por simetría
        // estética: si el techo viviera solo del lado del que guarda, un valor
        // escrito antes de que existiera —o por cualquier otra vía que toque el
        // JSONB— regiría acá sin pasar por él. El gate es quien hace cumplir la
        // regla, así que el gate también acota lo que lee.
        return min($n, \Punto\Api\Settings\SettingsService::MAX_ORDER_ITEM_CANCEL_WINDOW);
    }

    /**
     * Puerta única de la anulación de un ítem. Se llama desde el endpoint
     * ANTES de `OrderCoreService::updateItemStatus()`, y SOLO cuando el status
     * pedido es `cancelled`.
     *
     * El chequeo de permiso delega en `OperatorContext::requirePermission()`,
     * que responde 403 y corta (`apiError` hace exit). No se reimplementa acá
     * para que el mensaje del 403 —y el caso "device sin nadie desbloqueado",
     * que ahí es fail-closed— tengan UNA sola definición en todo el proyecto.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     * @throws OrderItemCancelBlockedException fuera de ventana y sin elevación
     */
    public static function assertCanCancel(array $ctx, string $companyId, string $orderItemId): void
    {
        OperatorContext::requirePermission($ctx, 'pos.order.item.cancel');

        $window = self::windowMinutes($companyId);
        if ($window === 0) {
            return; // sin límite de tiempo: la clave base ya alcanzó
        }

        $elapsed = self::elapsedMinutes($companyId, $orderItemId);
        if ($elapsed === null || $elapsed <= $window) {
            // `null` = el ítem no existe o es de otro tenant. No se decide acá:
            // `updateItemStatus()` es el que sabe distinguir "no encontrado" de
            // "de otra sucursal" y ya devuelve el mismo mensaje para los dos,
            // deliberadamente, para no revelar existencia cross-outlet.
            return;
        }

        $operator = OperatorContext::resolve($ctx);
        if (OperatorContext::can($operator, 'pos.order.item.cancel.late', $companyId)) {
            return; // elevación: pasa, y el evento igual queda registrado
        }

        $details = [
            'code'           => 'cancel_window_expired',
            'windowMinutes'  => $window,
            'elapsedMinutes' => $elapsed,
        ];

        throw new OrderItemCancelBlockedException($details, self::message($window, $elapsed));
    }

    /**
     * El texto que ve el cajero. Dice cuánto pasó, cuánto era el límite y cuál
     * es la salida — un "no podés" sin la salida es lo que más traba el
     * mostrador, porque el cajero no sabe a quién llamar.
     */
    public static function message(int $windowMinutes, int $elapsedMinutes): string
    {
        return 'No se puede anular este ítem: se cargó hace ' . $elapsedMinutes
            . ' minutos y la ventana de anulación es de ' . $windowMinutes
            . '. Pedile a un encargado que se identifique con su PIN.';
    }

    /**
     * Minutos transcurridos desde que la línea se cargó. `null` si el ítem no
     * existe para este tenant.
     *
     * El cálculo lo hace Postgres y no PHP: `created_at` es `timestamptz` y el
     * `now()` del server es el único reloj que no depende de la hora de una
     * tablet del mostrador (que puede estar corrida, y que es justamente el
     * dispositivo cuyo pedido estamos juzgando).
     *
     * `FLOOR` y no redondeo: con una ventana de 5 minutos, a los 5 minutos y 40
     * segundos el ítem lleva "5" y todavía pasa. Es el sentido que tiene
     * "ventana de 5 minutos" para quien la configuró.
     */
    private static function elapsedMinutes(string $companyId, string $orderItemId): ?int
    {
        $row = ncmExecute(
            'SELECT FLOOR(EXTRACT(EPOCH FROM (now() - created_at)) / 60) AS mins
               FROM pos_order_item
              WHERE orderitemid = ? AND companyid = ?
              LIMIT 1',
            [$orderItemId, $companyId],
            false
        );
        if (!$row || !isset($row['mins'])) {
            return null;
        }

        return (int) $row['mins'];
    }
}
