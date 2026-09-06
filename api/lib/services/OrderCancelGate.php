<?php
declare(strict_types=1);

namespace Punto\Api\Services;

require_once __DIR__ . '/OrderCancelBlockedException.php';
require_once __DIR__ . '/../Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;

/**
 * OrderCancelGate — "quién puede hacer desaparecer una comanda, y hasta
 * cuándo".
 *
 * ── Por qué UN gate y no uno por grano ─────────────────────────────────────
 *
 * Nació como `OrderItemCancelGate`, cubriendo solo la anulación de UNA LÍNEA.
 * La puerta de al lado quedó abierta: cancelar la ORDEN ENTERA
 * (`action=status` con `status='cancelled'`) no tenía ni un `hasPermission()`
 * en todo `orders-core.php`, y cancelar la SESIÓN DEL ESPACIO cascadea a
 * cancelar todas sus órdenes (`SpaceSessionService::cancel()`) sin ninguna
 * verificación tampoco.
 *
 * Los tres son la MISMA pregunta del comercio —"¿esta persona puede hacer que
 * una comanda deje de existir?"— con distinto radio de daño, y el radio más
 * grande era el menos protegido. El modelo de amenaza que motivó la feature
 * (el cajero entrega la comanda impresa como si fuera comprobante, cobra en
 * mano y después borra el registro) se ejecuta más fácil con la orden entera
 * que línea por línea.
 *
 * Por eso hay un solo gate con tres puertas y NO tres gates paralelos: dos
 * implementaciones de la misma regla se desincronizan a la primera corrección,
 * y el agujero reaparece en la que nadie tocó. Misma clave
 * (`pos.order.item.cancel`), misma ventana
 * (`settingOrderItemCancelWindowMinutes`), misma elevación
 * (`pos.order.item.cancel.late`). Lo único que cambia por grano es DESDE QUÉ
 * `created_at` se cuenta la ventana:
 *
 *   - ítem   → `pos_order_item.created_at`  (cuándo se cargó la línea)
 *   - orden  → `pos_order.created_at`       (cuándo se abrió la comanda)
 *   - sesión → `space_session.opened_at`    (cuándo se abrió la mesa)
 *
 * Las CLAVES no se renombraron aunque el gate sí. Renombrarlas obligaría a un
 * backfill por tenant y dejaría el permiso apagado en producción entre el
 * deploy y la migración — exactamente la ventana en la que la feature no
 * protege nada. La clave dice `item` por historia, no por alcance; este
 * docblock es su definición vigente.
 *
 * ── Las tres reglas, en este orden ─────────────────────────────────────────
 *
 *   1. `pos.order.item.cancel` es requisito SIEMPRE. Sin esa clave no se anula
 *      nada, con ventana o sin ella.
 *   2. Dentro de la ventana configurada, alcanza con la clave base.
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
 * OJO — el default permisivo aplica a la VENTANA, no al PERMISO. Con la
 * ventana en 0 la regla 1 sigue corriendo: cancelar una orden entera exige
 * `pos.order.item.cancel` desde este cambio, cosa que antes no exigía nada. Es
 * un cambio de comportamiento deliberado y el motivo de la migración 190, que
 * ya propagó la clave a los roles que la necesitan.
 *
 * Sin migración de datos para el interruptor: es una clave del JSONB
 * `company.config`, como el resto de los toggles de Ajustes. Se lee con `->>`
 * y NUNCA con el operador `?` de jsonb, que PDO reescribe a placeholder y
 * aborta el boot del contenedor (incidente de las migs 74/77).
 *
 * ── El gate corre IGUAL en el panel ────────────────────────────────────────
 *
 * No hay excepción por realm. `OperatorContext` resuelve la persona en los dos
 * lados —en `panel` la credencial ES la persona, en `pos-app` sale del PIN— y
 * el permiso se mide contra SU rol. Un encargado en el panel tiene `.late` y
 * pasa; alguien sin la clave, no. Exceptuar al panel sería dejar abierta la
 * puerta de al lado: la misma orden, la misma anulación, sin ventana.
 *
 * ── Dónde NO va el gate ────────────────────────────────────────────────────
 *
 * En el ENDPOINT, nunca dentro de `OrderCoreService`. Autorización y dominio
 * son capas distintas: el guard de "una orden ya cobrada no se cancela" vive
 * en el service y vale para todo caller, incluidas las cascadas internas; el
 * gate vale para el pedido que llega de afuera con una persona detrás. Meterlo
 * en el service haría que la cascada de `SpaceSessionService` se juzgara a sí
 * misma contra la ventana de cada orden que cancela — la sesión ya se gateó
 * una vez, arriba, que es donde la persona apretó el botón.
 *
 * Postgres: `pos_order_item`, `pos_order` y `space_session` son de las tablas
 * lowercase-sin-comillas (patrón migs 79/80/85), a diferencia de `company`,
 * que sí lleva `companyId` en camelCase (memoria
 * `project_pg_identifier_casing`).
 */
final class OrderCancelGate
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
     * Puerta de la anulación de UN ÍTEM. Se llama desde el endpoint ANTES de
     * `OrderCoreService::updateItemStatus()`, y SOLO cuando el status pedido es
     * `cancelled`.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     * @throws OrderCancelBlockedException fuera de ventana y sin elevación
     */
    public static function assertCanCancelItem(array $ctx, string $companyId, string $orderItemId): void
    {
        self::assertCanCancel(
            $ctx,
            $companyId,
            self::elapsedMinutes(
                'SELECT FLOOR(EXTRACT(EPOCH FROM (now() - created_at)) / 60) AS mins
                   FROM pos_order_item
                  WHERE orderitemid = ? AND companyid = ?
                  LIMIT 1',
                $orderItemId,
                $companyId
            ),
            'este ítem',
            'se cargó'
        );
    }

    /**
     * Puerta de la anulación de la ORDEN ENTERA. Se llama desde el endpoint
     * ANTES de `OrderCoreService::updateStatus()`, y SOLO cuando el status
     * pedido es `cancelled`.
     *
     * La ventana se cuenta desde `pos_order.created_at` —cuándo se abrió la
     * comanda— y no desde el ítem más nuevo. Lo que el comercio acota es
     * cuánto tiempo se puede deshacer algo, y para la orden ese reloj arranca
     * cuando la orden existe. Tomar el ítem más nuevo permitiría reabrir la
     * ventana de una comanda vieja agregándole una línea.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     * @throws OrderCancelBlockedException fuera de ventana y sin elevación
     */
    public static function assertCanCancelOrder(array $ctx, string $companyId, string $orderId): void
    {
        self::assertCanCancel(
            $ctx,
            $companyId,
            self::elapsedMinutes(
                'SELECT FLOOR(EXTRACT(EPOCH FROM (now() - created_at)) / 60) AS mins
                   FROM pos_order
                  WHERE orderid = ? AND companyid = ?
                  LIMIT 1',
                $orderId,
                $companyId
            ),
            'esta orden',
            'se abrió'
        );
    }

    /**
     * Puerta de la cancelación de una SESIÓN DE ESPACIO (mesa).
     *
     * No es una orden, pero cancela órdenes: `SpaceSessionService::cancel()`
     * cascadea sobre TODAS las órdenes vivas de la sesión (decisión del owner
     * 2026-07-19). Sin esta puerta, el gate de la orden entera se saltea con
     * un click en otra pantalla — el mismo efecto, más comandas de una sola
     * vez, y en el flujo de mesa que es justamente donde el comercio describió
     * el problema.
     *
     * Se gatea la SESIÓN, una sola vez, y no cada orden de la cascada: la
     * persona pidió cancelar la mesa, no siete órdenes. Un chequeo por orden
     * dejaría la cascada a medias si una sola quedara fuera de ventana —
     * órdenes canceladas y mesa abierta, el peor de los estados.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     * @throws OrderCancelBlockedException fuera de ventana y sin elevación
     */
    public static function assertCanCancelSpaceSession(array $ctx, string $companyId, string $sessionId): void
    {
        self::assertCanCancel(
            $ctx,
            $companyId,
            self::elapsedMinutes(
                'SELECT FLOOR(EXTRACT(EPOCH FROM (now() - opened_at)) / 60) AS mins
                   FROM space_session
                  WHERE sessionid = ? AND companyid = ?
                  LIMIT 1',
                $sessionId,
                $companyId
            ),
            'esta mesa',
            'se abrió'
        );
    }

    /**
     * El cuerpo compartido de las tres puertas. Recibe los minutos ya
     * calculados porque lo único que difiere entre granos es de qué columna
     * salen; la REGLA —permiso, ventana, elevación, forma del 422— es una sola.
     *
     * El chequeo de permiso delega en `OperatorContext::requirePermission()`,
     * que responde 403 y corta (`apiError` hace exit). No se reimplementa acá
     * para que el mensaje del 403 —y el caso "device sin nadie desbloqueado",
     * que ahí es fail-closed— tengan UNA sola definición en todo el proyecto.
     *
     * @param array<string,mixed> $ctx
     * @param int|null $elapsed `null` = la entidad no existe o es de otro
     *        tenant. NO se decide acá: el service es el que sabe distinguir "no
     *        encontrado" de "de otra sucursal" y ya devuelve el mismo mensaje
     *        para los dos, deliberadamente, para no revelar existencia
     *        cross-outlet.
     * @param string $subject "este ítem" | "esta orden" | "esta mesa"
     * @param string $verb    "se cargó" | "se abrió"
     * @throws OrderCancelBlockedException
     */
    private static function assertCanCancel(
        array $ctx,
        string $companyId,
        ?int $elapsed,
        string $subject,
        string $verb
    ): void {
        OperatorContext::requirePermission($ctx, 'pos.order.item.cancel');

        $window = self::windowMinutes($companyId);
        if ($window === 0) {
            return; // sin límite de tiempo: la clave base ya alcanzó
        }
        if ($elapsed === null || $elapsed <= $window) {
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

        throw new OrderCancelBlockedException($details, self::message($window, $elapsed, $subject, $verb));
    }

    /**
     * El texto que ve el cajero. Dice cuánto pasó, cuánto era el límite y cuál
     * es la salida — un "no podés" sin la salida es lo que más traba el
     * mostrador, porque el cajero no sabe a quién llamar.
     */
    public static function message(
        int $windowMinutes,
        int $elapsedMinutes,
        string $subject = 'este ítem',
        string $verb = 'se cargó'
    ): string {
        return 'No se puede anular ' . $subject . ': ' . $verb . ' hace ' . $elapsedMinutes
            . ' minutos y la ventana de anulación es de ' . $windowMinutes
            . '. Pedile a un encargado que se identifique con su PIN.';
    }

    /**
     * Minutos transcurridos desde el `created_at` que corresponda al grano.
     * `null` si la entidad no existe para este tenant.
     *
     * El cálculo lo hace Postgres y no PHP: las columnas son `timestamptz` y el
     * `now()` del server es el único reloj que no depende de la hora de una
     * tablet del mostrador (que puede estar corrida, y que es justamente el
     * dispositivo cuyo pedido estamos juzgando).
     *
     * `FLOOR` y no redondeo: con una ventana de 5 minutos, a los 5 minutos y 40
     * segundos la entidad lleva "5" y todavía pasa. Es el sentido que tiene
     * "ventana de 5 minutos" para quien la configuró.
     *
     * La query llega como parámetro y no se arma acá con concatenación: los
     * tres SELECT son literales fijos en sus call-sites, así que ni el nombre
     * de la tabla ni el de la columna pueden venir de un request.
     */
    private static function elapsedMinutes(string $sql, string $id, string $companyId): ?int
    {
        if ($id === '' || $companyId === '') {
            return null;
        }
        $row = ncmExecute($sql, [$id, $companyId], false);
        if (!$row || !isset($row['mins'])) {
            return null;
        }

        return (int) $row['mins'];
    }
}
