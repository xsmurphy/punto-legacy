<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * ShiftCloseGate — "no se cierra el turno con órdenes o espacios abiertos".
 *
 * Regla de negocio OPCIONAL del comercio (owner, 2026-08-25):
 *
 *   > "para poder cerrar el turno se tienen que cerrar todas las órdenes y
 *   >  espacios, no pueden quedar órdenes abiertas. Pero esto tiene que ser una
 *   >  función opcional que el comercio pueda activar o no."
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
     * Órdenes que cuentan como "abiertas". Mismo idiom que usa el resto del
     * dominio (`SpaceService::listWithState`, `SpaceSettlementService`): todo
     * lo que no terminó, terminó cobrado (`closed`) o anulado (`cancelled`).
     * `delivered` y `out_for_delivery` SIGUEN debiendo plata, así que bloquean.
     */
    private const ORDER_CLOSED_STATUSES = ['closed', 'cancelled'];

    /**
     * Sesiones de espacio activas. Es el mismo predicado del índice único
     * parcial `uq_space_session_active_per_space` (mig 80) — la fuente de
     * verdad de "este espacio está ocupado". Una sesión fusionada en otra
     * (`mergedinto`) queda `closed` por diseño de la mig 163, así que no
     * necesita exclusión propia acá.
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
     * Fail-OPEN a propósito, al revés que `drawerIsBlind()`: si no se puede
     * leer la config, se deja cerrar. Un cierre de caja bloqueado por una
     * lectura fallida deja al cajero con la plata contada y sin poder terminar
     * el turno; el modo a ciegas falla cerrado porque ahí el riesgo es filtrar
     * un total, que es lo contrario de un bloqueo.
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
     * Qué hay abierto en la sucursal ahora mismo.
     *
     * Devuelve SIEMPRE la foto completa (conteos + detalle acotado), prendido o
     * apagado el flag: es la misma función que alimenta el GET que el POS usa
     * para deshabilitar el botón y el error 422 del POST. Una sola consulta
     * para las dos puntas — si divergieran, el cajero vería una lista antes de
     * tocar el botón y otra distinta después.
     *
     * @return array{
     *   orderCount:int, spaceCount:int, total:int,
     *   orders:list<array{id:string,number:?int,status:string,source:string,space:?string}>,
     *   spaces:list<array{id:string,name:string,status:string}>,
     *   truncated:bool
     * }
     */
    public static function blockers(string $companyId, string $outletId): array
    {
        $empty = [
            'orderCount' => 0, 'spaceCount' => 0, 'total' => 0,
            'orders' => [], 'spaces' => [], 'truncated' => false,
        ];
        if ($companyId === '' || $outletId === '') {
            return $empty;
        }

        $orders = self::openOrders($companyId, $outletId);
        $spaces = self::openSpaces($companyId, $outletId);

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
     * @throws ShiftCloseBlockedException
     */
    public static function assertCanClose(string $companyId, string $outletId): void
    {
        if (!self::isEnabled($companyId)) {
            return;
        }
        $blockers = self::blockers($companyId, $outletId);
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
        if ($o > 0) { $partes[] = $o === 1 ? '1 orden abierta' : "$o órdenes abiertas"; }
        if ($e > 0) { $partes[] = $e === 1 ? '1 espacio abierto' : "$e espacios abiertos"; }

        return 'No se puede cerrar el turno: la sucursal tiene '
            . implode(' y ', $partes)
            . '. Cerralas o cobralas y volvé a intentar.';
    }

    // ── Consultas ──────────────────────────────────────────────────────────

    /**
     * @return list<array{id:string,number:?int,status:string,source:string,space:?string}>
     */
    private static function openOrders(string $companyId, string $outletId): array
    {
        $ph = implode(',', array_fill(0, count(self::ORDER_CLOSED_STATUSES), '?'));
        // LEFT JOIN a la sesión y al espacio para poder decir "Mesa 4" en vez
        // de un uuid. `space.tableid` es la PK del espacio: nombre heredado de
        // `dining_table` (mig 80), que la mig 81 renombró de tabla pero NO de
        // columna. `space_session.alias` (mig 163) es el nombre que el mozo le
        // puso a ESA ocupación y gana sobre el nombre fijo del espacio.
        $sql = "SELECT o.orderid, o.ordernumber, o.status, o.source,
                       COALESCE(ss.alias, sp.name) AS spacename
                  FROM pos_order o
                  LEFT JOIN space_session ss ON ss.sessionid = o.spacesessionid
                  LEFT JOIN space sp         ON sp.tableid   = ss.tableid
                 WHERE o.companyid = ?
                   AND o.outletid  = ?
                   AND o.status NOT IN ($ph)
                 ORDER BY o.ordernumber NULLS LAST, o.created_at";
        $params = array_merge([$companyId, $outletId], self::ORDER_CLOSED_STATUSES);

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
    private static function openSpaces(string $companyId, string $outletId): array
    {
        $ph = implode(',', array_fill(0, count(self::SPACE_OPEN_STATUSES), '?'));
        $sql = "SELECT ss.sessionid, ss.status, COALESCE(ss.alias, sp.name) AS spacename
                  FROM space_session ss
                  JOIN space sp ON sp.tableid = ss.tableid
                 WHERE ss.companyid = ?
                   AND ss.outletid  = ?
                   AND ss.status IN ($ph)
                 ORDER BY sp.name";
        $params = array_merge([$companyId, $outletId], self::SPACE_OPEN_STATUSES);

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
