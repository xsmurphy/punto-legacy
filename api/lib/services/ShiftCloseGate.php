<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * ShiftCloseGate — "no se cierra el turno con órdenes sin cobrar ni espacios
 * abiertos".
 *
 * Regla de negocio OPCIONAL del comercio (owner, 2026-08-25):
 *
 *   > "para poder cerrar el turno se tienen que cerrar todas las órdenes y
 *   >  espacios, no pueden quedar órdenes abiertas. Pero esto tiene que ser una
 *   >  función opcional que el comercio pueda activar o no."
 *
 * ...acotada el mismo día, al preguntarle por el delivery ya cobrado que sigue
 * en la calle:
 *
 *   > "En ese caso no debe bloquear.. es decir tienen que haber un estado de
 *   >  cobrado para cada orden.. independiente al estado de la orden en su
 *   >  proceso"
 *
 * Lo que frena el arqueo es entonces la PLATA pendiente, no el trabajo
 * pendiente. Ver `ORDER_PAID_LINK_KIND` para el criterio de las órdenes y
 * `SPACE_OPEN_STATUSES` para por qué los espacios NO cambian.
 *
 * El interruptor es `company.config->>'settingDrawerRequireClosedOrders'`
 * (Ajustes → POS → "Cajas y arqueo"), APAGADO por default: sin activarlo, el
 * cierre se comporta exactamente como antes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ALCANCE: SUCURSAL, no caja. Y no es una preferencia — es lo único que el
 * modelo de datos permite responder.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * El criterio de partida del owner era mirar solo lo que ESA caja tiene
 * abierto ("el turno es de una caja"), condicionado a verificar si las órdenes
 * están atadas a caja o a sucursal. Verificado, y decide lo contrario:
 *
 *  1. `space_session` NO TIENE columna de caja (mig 80, renombrada en la 81):
 *     solo `companyid` + `outletid` + `tableid`. Un espacio es de la sucursal;
 *     cualquier caja lo puede cobrar. Un gate por caja no podría mirar
 *     espacios en absoluto — o sea, la mitad del pedido del owner.
 *  2. `pos_order.registerid` existe (mig 79) pero NADIE lo filtra: ni
 *     `OrderCoreService::list()`, ni el guard de scope, ni la pantalla de
 *     órdenes del POS. La orden que abrió una tablet pareada a la caja A la
 *     cobra la caja B, y la lista que ve el cajero es la de la SUCURSAL.
 *     Gatear por `registerid` sería estrenar una dimensión que hoy no
 *     significa nada operativamente, y dejar pasar justo las órdenes de
 *     espacio (las que motivaron el pedido).
 *
 * La contrapartida está declarada y es real: una caja no cierra su turno
 * mientras otra caja de la misma sucursal tenga algo abierto. Por eso el
 * interruptor nace apagado y lo prende el comercio. Lo que NO es, es un
 * callejón sin salida: todo lo que bloquea se ve y se cierra desde el MISMO
 * POS donde el cajero está parado, porque órdenes y espacios se listan por
 * sucursal. Ver `context/51-configuracion-offline-de-la-caja.md` §8.
 *
 * Casing: `pos_order`, `space_session` y `space` son todo lowercase sin
 * comillas (convención de las migs 71/72). NO son de las 18 tablas camelCase
 * (memoria `project_pg_identifier_casing`).
 */
final class ShiftCloseGate
{
    /**
     * Estados TERMINALES de la orden. No son el criterio del gate — son las
     * órdenes que ya salieron del flujo operativo y que ninguna acción del POS
     * puede volver a tocar.
     *
     * Se excluyen por separado del criterio de cobro por un motivo concreto:
     * `closed` implica cobro (`updateStatus()` lo exige, `markPaid()` lo
     * escribe), pero un `closed` sin link —una fila vieja, un backfill de la
     * mig 115 sobre datos sucios— sería un bloqueo del que el cajero NO puede
     * salir: `markPaid()` rechaza una orden ya cerrada, así que no hay forma de
     * cobrarla desde ninguna pantalla. Un gate del que no se puede salir es
     * peor que un gate que deja pasar una anomalía histórica.
     */
    private const ORDER_TERMINAL_STATUSES = ['closed', 'cancelled'];

    /**
     * El `kind` del vínculo que significa "esta orden ya se cobró".
     *
     * ─────────────────────────────────────────────────────────────────────
     * EL CRITERIO ES EL COBRO, NO EL ESTADO DEL PROCESO (owner, 2026-08-25)
     * ─────────────────────────────────────────────────────────────────────
     *
     *   > "En ese caso no debe bloquear.. es decir tienen que haber un estado
     *   >  de cobrado para cada orden.. independiente al estado de la orden en
     *   >  su proceso"
     *
     * Confirmado el caso que este archivo dejaba anotado: un delivery ya
     * cobrado que sale en moto no le debe nada a nadie y no tiene por qué
     * frenar el arqueo. Lo que frena el cierre es la PLATA que falta entrar,
     * no el trabajo que falta terminar.
     *
     * Ese "estado de cobrado" ya existe y no hubo que inventarlo. Ojo, no es
     * `pos_order.saletransactionid`: esa columna la DROPEÓ la mig 115
     * (`115_transaction_link.sql:230`) y la reemplazó por la tabla puente
     * `order_transaction_link`, que además resuelve el caso "varias órdenes,
     * una sola factura". El docblock de `OrderCoreService.php:71-78` sigue
     * nombrando la columna vieja, pero el rastro vivo es la fila del puente.
     *
     * Y el cambio NO es cosmético: el flujo "Orden en venta" (cobrar primero,
     * producir después) crea la orden CON el link ya puesto y status `sent`
     * (`OrderCoreService.php:402` + `:434`), y de ahí recorre `in_progress →
     * ready → out_for_delivery → delivered` cobrada y viva. La implicación
     * corre en un solo sentido: `closed` ⇒ hay link, pero hay link ⇏ `closed`.
     * Esas cinco filas son exactamente las que antes bloqueaban de más.
     *
     * Se filtra por `kind` explícito y no por "existe cualquier fila": hoy el
     * CHECK de la mig 115 solo admite `order_billed`, pero el día que se sume
     * otro tipo de vínculo (una nota de crédito, por decir), un link que NO es
     * un cobro no puede hacer pasar por cobrada a una orden que no lo está.
     */
    private const ORDER_PAID_LINK_KIND = 'order_billed';

    /**
     * Sesiones de espacio activas. Es el mismo predicado del índice único
     * parcial `uq_space_session_active_per_space` (mig 80) — la fuente de
     * verdad de "este espacio está ocupado". Una sesión fusionada en otra
     * (`mergedinto`) queda `closed` por diseño de la mig 163, así que no
     * necesita exclusión propia acá.
     *
     * ── Por qué los ESPACIOS siguen mirando el status y NO el cobro ─────────
     *
     * La decisión del owner acota el criterio de las ÓRDENES. Los espacios se
     * quedan como estaban, y no por inercia — por tres razones:
     *
     *  1. **No hay nada que corregir.** El caso que motivó el cambio es la
     *     orden cobrada que sigue viva en su proceso. En un espacio ese estado
     *     no existe: `SpaceSessionService::close()` se niega a cerrar con saldo
     *     pendiente, y al revés `SpaceSettlementService::settleIfCovered()`
     *     cierra la sesión sola en cuanto el saldo llega a cero. Una mesa
     *     cobrada del todo YA está `closed` y este predicado no la ve. No hay
     *     "mesa cobrada y abierta" que rescatar.
     *  2. **Aplicar el criterio de cobro la rompería.** Una mesa recién abierta
     *     sin consumo tiene saldo 0. Leída como "no debe plata" pasaría el
     *     gate — o sea, la mesa ocupada a la hora del arqueo, que es
     *     exactamente el pendiente que el comercio quiere ver, sería la que se
     *     vuelve invisible.
     *  3. **Una mesa ocupada no es una deuda, es un espacio sin liberar.** Es
     *     un pendiente operativo real que se traspasa de turno a turno, y el
     *     cajero lo resuelve desde el mismo POS.
     *
     * El cobro parcial refuerza lo mismo: existe solo acá (`space_session_payment`,
     * mig 90), y una sesión con parciales sigue `open` hasta que se cubre el
     * saldo. Bajo este predicado bloquea, que es lo correcto — todavía debe.
     * En las órdenes el parcial NO existe: el link de la mig 115 es binario y
     * sin columna `amount` por decisión explícita, así que una orden está
     * cobrada o no lo está, y no hay tercer caso que definir.
     */
    private const SPACE_OPEN_STATUSES = ['open', 'bill_requested'];

    /** Cuántas filas se detallan. El conteo es siempre el total, sin tope. */
    private const DETAIL_LIMIT = 25;

    /**
     * ¿El comercio pidió este gate?
     *
     * Se lee de `company.config` (JSONB) igual que el resto de los toggles de
     * Ajustes. `SettingsService` persiste los booleanos como 1/0 (`tinyBoolMap`),
     * así que se acepta el mismo abanico que su `truthy()`.
     *
     * Ante la AUSENCIA del dato (company sin la clave, o sin fila) devuelve
     * `false` y el cierre procede: la regla no se inventa sola. Eso es lo
     * único que este método decide — un error de DB **no** se traga acá,
     * propaga como cualquier otra query y termina en el 500 genérico de
     * `error_handlers.php`. No es fail-open: es "la clave no está, entonces no
     * está prendida".
     */
    public static function isEnabled(string $companyId): bool
    {
        if ($companyId === '') {
            return false;
        }
        $row = ncmExecute(
            "SELECT config->>'settingDrawerRequireClosedOrders' AS flag
               FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false
        );
        $v = strtolower((string) ($row['flag'] ?? ''));
        return in_array($v, ['1', 't', 'true', 'yes', 'on'], true);
    }

    /**
     * Qué hay abierto en la sucursal y sigue sin resolverse.
     *
     * Devuelve SIEMPRE la foto completa (conteos + detalle acotado), prendido o
     * apagado el flag: es la misma función que alimenta el GET que el POS usa
     * para deshabilitar el botón y el error 422 del POST. Una sola consulta
     * para las dos puntas — si divergieran, el cajero vería una lista antes de
     * tocar el botón y otra distinta después.
     *
     * `$openedBefore` (naive tenant-local 'Y-m-d H:i:s') acota a lo que ya
     * EXISTÍA en ese momento. Es lo que hace justo el juicio de un cierre que
     * se hizo sin red: se lo evalúa contra el estado que el turno tenía cuando
     * se cerró, no contra el presente. Sin esto, un cierre de las 22:00 que
     * sincroniza a las 10:00 del día siguiente choca contra órdenes que abrió
     * OTRA caja después de que ese turno terminó — el 422 es terminal, el canal
     * `drawer` es FIFO, y el cajero de la mañana queda trabado por algo que no
     * tiene nada que ver con el turno que se cerró.
     *
     * La semántica final es "existía al cerrar Y sigue pendiente ahora": una
     * orden de las 21:00 que alguien cobró a las 23:00 ya no aparece (apareció
     * su link), que es el resultado correcto — se resolvió.
     *
     * Comparar el string naive contra `timestamptz` es válido acá porque
     * `TenantClock::apply()` deja la sesión de PG en la TZ del comercio
     * (`apiAuthTenant` → `data.php`), que es la convención de storage del
     * proyecto. Sin eso, el literal se interpretaría en UTC y el corte se
     * correría las horas del offset.
     *
     * @return array{
     *   orderCount:int, spaceCount:int, total:int,
     *   orders:list<array{id:string,number:?int,status:string,source:string,space:?string}>,
     *   spaces:list<array{id:string,name:string,status:string}>,
     *   truncated:bool
     * }
     */
    public static function blockers(string $companyId, string $outletId, ?string $openedBefore = null): array
    {
        $empty = [
            'orderCount' => 0, 'spaceCount' => 0, 'total' => 0,
            'orders' => [], 'spaces' => [], 'truncated' => false,
        ];
        if ($companyId === '' || $outletId === '') {
            return $empty;
        }

        $cutoff = self::normalizeCutoff($openedBefore);
        $orders = self::openOrders($companyId, $outletId, $cutoff);
        $spaces = self::openSpaces($companyId, $outletId, $cutoff);

        $orderCount = count($orders);
        $spaceCount = count($spaces);

        return [
            'orderCount' => $orderCount,
            'spaceCount' => $spaceCount,
            'total'      => $orderCount + $spaceCount,
            'orders'     => array_slice($orders, 0, self::DETAIL_LIMIT),
            'spaces'     => array_slice($spaces, 0, self::DETAIL_LIMIT),
            'truncated'  => $orderCount > self::DETAIL_LIMIT || $spaceCount > self::DETAIL_LIMIT,
        ];
    }

    /**
     * Frena el cierre si corresponde. Se llama desde `api/v1/drawer.php` SOLO
     * cuando hay un turno abierto de verdad — ver el comentario del call-site:
     * un reenvío sobre una caja YA CERRADA nunca pasa por acá, porque si no un
     * cierre encolado quedaría rechazado para siempre por órdenes que se
     * abrieron DESPUÉS de que ese turno terminó.
     *
     * `$closeDate` es la fecha del cierre tal como la manda el cliente — la
     * hora en que el cajero REALMENTE cerró, no la del sync (mismo dato con el
     * que se sella `drawerCloseDate`). Acota el gate a lo que existía en ese
     * momento; ver `blockers()`.
     *
     * @throws ShiftCloseBlockedException
     */
    public static function assertCanClose(string $companyId, string $outletId, ?string $closeDate = null): void
    {
        if (!self::isEnabled($companyId)) {
            return;
        }
        $blockers = self::blockers($companyId, $outletId, $closeDate);
        if ($blockers['total'] === 0) {
            return;
        }
        throw new ShiftCloseBlockedException($blockers, self::message($blockers));
    }

    /**
     * El texto que ve el cajero. Dice QUÉ falta, no solo que no puede — un
     * "no podés cerrar" sin el detalle es lo que más frustra en el mostrador.
     */
    public static function message(array $blockers): string
    {
        $partes = [];
        $o = (int) ($blockers['orderCount'] ?? 0);
        $e = (int) ($blockers['spaceCount'] ?? 0);
        if ($o > 0) { $partes[] = $o === 1 ? '1 orden sin cobrar' : "$o órdenes sin cobrar"; }
        if ($e > 0) { $partes[] = $e === 1 ? '1 espacio abierto' : "$e espacios abiertos"; }

        return 'No se puede cerrar el turno: la sucursal tiene '
            . implode(' y ', $partes)
            . '. Cobralas o cancelalas y volvé a intentar.';
    }

    // ── Consultas ──────────────────────────────────────────────────────────

    /**
     * Valida el corte antes de que llegue a la query.
     *
     * El `date` del cierre viaja en el body, así que puede ser cualquier cosa.
     * Un string que PG no pueda castear a `timestamptz` aborta la request
     * entera con un 500 (y, peor, envenena la transacción si alguna vez esto
     * corriera dentro de una). Lo que no parsea se descarta y el gate vuelve a
     * juzgar contra el presente — el comportamiento más estricto, nunca uno
     * que deje pasar un cierre por mandar basura en `date`.
     */
    private static function normalizeCutoff(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $raw) {
            error_log('[ShiftCloseGate] date de cierre no parseable, se ignora el corte: ' . $raw);
            return null;
        }
        return $raw;
    }

    /**
     * @return list<array{id:string,number:?int,status:string,source:string,space:?string}>
     */
    private static function openOrders(string $companyId, string $outletId, ?string $cutoff): array
    {
        $ph = implode(',', array_fill(0, count(self::ORDER_TERMINAL_STATUSES), '?'));
        // LEFT JOIN a la sesión y al espacio para poder decir "Mesa 4" en vez
        // de un uuid. `space.tableid` es la PK del espacio: nombre heredado de
        // `dining_table` (mig 80), que la mig 81 renombró de tabla pero NO de
        // columna. `space_session.alias` (mig 163) es el nombre que el mozo le
        // puso a ESA ocupación y gana sobre el nombre fijo del espacio.
        //
        // El NOT EXISTS es el criterio de verdad: bloquea la orden VIVA que
        // todavía no se cobró. Va con `companyid` además de `orderid` —el
        // orderid solo ya es único, pero el aislamiento por tenant no se
        // apoya en que un uuid no colisione— y con `kind` explícito.
        //
        // Casing lowercase sin comillas en las tres tablas: `pos_order` y
        // `space_session` por las migs 79/80, `order_transaction_link` por la
        // 115. Ninguna es de las camelCase entrecomilladas de
        // `context/08-convenciones-criticas.md` §44.
        $sql = "SELECT o.orderid, o.ordernumber, o.status, o.source,
                       COALESCE(ss.alias, sp.name) AS spacename
                  FROM pos_order o
                  LEFT JOIN space_session ss ON ss.sessionid = o.spacesessionid
                  LEFT JOIN space sp         ON sp.tableid   = ss.tableid
                 WHERE o.companyid = ?
                   AND o.outletid  = ?
                   AND o.status NOT IN ($ph)
                   AND NOT EXISTS (
                         SELECT 1 FROM order_transaction_link l
                          WHERE l.orderid   = o.orderid
                            AND l.companyid = o.companyid
                            AND l.kind      = ?
                       )"
             . ($cutoff !== null ? " AND o.created_at < ?" : "")
             . " ORDER BY o.ordernumber NULLS LAST, o.created_at";
        // Los params van en el MISMO orden en que la query lee los `?`:
        // companyid, outletid, los N estados terminales, el kind del link y
        // —si hay corte— la fecha. Cualquier reordenamiento del SQL de arriba
        // tiene que replicarse acá.
        $params = array_merge(
            [$companyId, $outletId],
            self::ORDER_TERMINAL_STATUSES,
            [self::ORDER_PAID_LINK_KIND]
        );
        if ($cutoff !== null) { $params[] = $cutoff; }

        // forceObj=true devuelve un RECORDSET, no un array: se itera con
        // `while (!$rs->EOF)`. Tratarlo como array da [] siempre (memoria
        // `project_ncmexecute_forceobj_recordset`).
        $rs   = ncmExecute($sql, $params, false, true);
        $out  = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $num = $f['ordernumber'] ?? null;
                $out[] = [
                    'id'     => (string) ($f['orderid'] ?? ''),
                    'number' => $num === null || $num === '' ? null : (int) $num,
                    'status' => (string) ($f['status'] ?? ''),
                    'source' => (string) ($f['source'] ?? ''),
                    'space'  => ($f['spacename'] ?? null) !== null && $f['spacename'] !== ''
                        ? (string) $f['spacename'] : null,
                ];
                $rs->MoveNext();
            }
        }
        return $out;
    }

    /** @return list<array{id:string,name:string,status:string}> */
    private static function openSpaces(string $companyId, string $outletId, ?string $cutoff): array
    {
        $ph = implode(',', array_fill(0, count(self::SPACE_OPEN_STATUSES), '?'));
        $sql = "SELECT ss.sessionid, ss.status, COALESCE(ss.alias, sp.name) AS spacename
                  FROM space_session ss
                  JOIN space sp ON sp.tableid = ss.tableid
                 WHERE ss.companyid = ?
                   AND ss.outletid  = ?
                   AND ss.status IN ($ph)"
             . ($cutoff !== null ? " AND ss.opened_at < ?" : "")
             . " ORDER BY sp.name";
        $params = array_merge([$companyId, $outletId], self::SPACE_OPEN_STATUSES);
        if ($cutoff !== null) { $params[] = $cutoff; }

        $rs  = ncmExecute($sql, $params, false, true);
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[] = [
                    'id'     => (string) ($f['sessionid'] ?? ''),
                    'name'   => (string) ($f['spacename'] ?? ''),
                    'status' => (string) ($f['status'] ?? ''),
                ];
                $rs->MoveNext();
            }
        }
        return $out;
    }
}
