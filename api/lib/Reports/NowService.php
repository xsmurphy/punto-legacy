<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * "Ahora" — el estado operativo del comercio EN ESTE MOMENTO, para el bloque
 * de arriba del dashboard (widget `now`). No depende del rango de fechas que
 * eligió el usuario: es lo que está pasando hoy.
 *
 * ── La regla que manda (owner, cerrada) ────────────────────────────────────
 *
 * Una fila se muestra SOLO si el comercio usa esa capacidad y hay algo que
 * mostrar. Esta respuesta trae únicamente las filas con dato; sin filas, el
 * front no pinta el bloque. Nunca un cero.
 *
 * ── Qué NO calcula esta clase ──────────────────────────────────────────────
 *
 * Casi nada. Cada fila le pregunta a su dueño, con la definición que usa la
 * pantalla a la que linkea:
 *
 *   orders    pos_order + `OrderCoreService::ACTIVE_STATUSES`   → /pos/ordenes
 *   spaces    SpaceService::stateSummary()                     → /pos/espacios
 *   drawers   DrawersService::openNow()                        → Control de cajas
 *   staff     AttendanceService::presentNow()                  → Asistencia
 *   agenda    citas de hoy, `ScheduleService::PENDING_STATUSES` → /pos/calendario
 *   dues      ObligationsService::issuedChecksDue()            → Finanzas › Cheques
 *             OpenInvoicesService::payablesDue()               → Cuentas por pagar
 *
 * Las dos consultas que viven acá (órdenes y agenda) son las únicas que no
 * tenían un método propio en su módulo; usan las constantes del módulo para
 * no inventar otra definición de "activa" o "pendiente".
 *
 * ── Gate por fila: módulo y permiso ────────────────────────────────────────
 *
 * Cada fila exige lo mismo que la pantalla a la que lleva:
 *
 *   - Las del POS (órdenes, espacios, agenda) dependen del MÓDULO, igual que
 *     su entrada en el menú de la caja (`POS_ROUTES`). Órdenes y espacios no
 *     llevan clave: son conteos sin un monto, el mismo "armazón operativo"
 *     que los widgets `orders`/`tables` que ya estaban abiertos. La agenda sí
 *     lleva la suya (`reports.schedule.view`), la misma que el widget
 *     `schedule`.
 *   - Cajas, asistencia y vencimientos llevan la clave de su reporte.
 *
 * Una fila sin módulo o sin permiso NO se calcula (no se consulta para
 * después descartarla). Sin `$can` el widget no devuelve nada (fail-closed).
 *
 * ── Aislamiento de fallas ──────────────────────────────────────────────────
 *
 * Cada fila corre en su propio try/catch, como `AttentionService`: si una
 * consulta falla se omite esa fila y las demás salen igual.
 */
final class NowService
{
    /**
     * Minutos desde que una orden entró a cocina a partir de los cuales está
     * DEMORADA. Espejo del umbral "rojo" por defecto del KDS
     * (`DEFAULT_KDS_CONFIG.lateMin`, `frontend/lib/kds/config.ts`).
     *
     * Por qué un número fijo y no el de cada pantalla: el umbral del KDS es
     * config LOCAL de cada dispositivo (localStorage), el servidor no lo ve. Y
     * por qué desde el envío: `pos_order` no tiene `ready_at` ni una marca de
     * "empezó a prepararse" (`context/62` §Órdenes) — lo único que existe es
     * `sent_at`, que es exactamente desde donde cuenta el reloj del KDS
     * (`order.sentAt ?? order.createdAt`). Cuando llegue el objetivo por orden
     * (F-SLA-0, `targetminutes`) este número se reemplaza por ese.
     */
    public const KITCHEN_LATE_MINUTES = 20;

    /** Ventana de "Vencimientos de la semana", en días desde hoy. */
    public const DUE_DAYS = 7;

    /** Cuántos nombres/renglones viajan en las filas con lista. */
    private const LIST_LIMIT = 5;

    /**
     * Fila => [módulo requerido, permiso requerido, destino]. El orden es el
     * de pantalla. `dues` se gatea por PARTE (ver `DUE_PARTS`).
     */
    public const TILES = [
        'orders'  => ['ordersPanel', null,                    '/pos/ordenes'],
        'spaces'  => ['tables',      null,                    '/pos/espacios'],
        'drawers' => [null,          'reports.drawers.view',  '/reports/drawers'],
        'staff'   => [null,          'hr.attendance.view',    '/reports/attendance'],
        'agenda'  => ['calendar',    'reports.schedule.view', '/pos/calendario'],
        'dues'    => [null,          null,                    null],
    ];

    /** Partes de "Vencimientos de la semana" => [permiso, destino]. */
    public const DUE_PARTS = [
        'checks'   => ['finance.manage',         '/finanzas/cheques'],
        'payables' => ['reports.purchases.view', '/reports/open-invoices?tab=pagar'],
    ];

    /** @var array<string,callable> */
    private array $sources;

    /**
     * @param array<string,callable>|null $sources Solo para el arnés (forzar la
     *        falla de una fila). En producción, las reales.
     */
    public function __construct(?array $sources = null)
    {
        $this->sources = $sources ?? [
            'orders'   => $this->orders(...),
            'spaces'   => $this->spaces(...),
            'drawers'  => $this->drawers(...),
            'staff'    => $this->staff(...),
            'agenda'   => $this->agenda(...),
            'checks'   => $this->checks(...),
            'payables' => $this->payables(...),
        ];
    }

    /**
     * @param list<string>          $outletIds Alcance por sucursal (`[]` = todas).
     * @param callable(string):bool $can       ¿La persona tiene este permiso?
     * @param callable(string):bool $moduleOn  ¿El comercio tiene este módulo prendido?
     * @return array{tiles: list<array<string,mixed>>}
     */
    public function tiles(string $companyId, array $outletIds, callable $can, callable $moduleOn): array
    {
        $tiles = [];
        foreach (self::TILES as $key => [$module, $perm, $href]) {
            if ($key === 'dues') {
                $dues = $this->dues($companyId, $outletIds, $can);
                if ($dues !== null) {
                    $tiles[] = ['key' => 'dues'] + $dues;
                }
                continue;
            }
            if ($module !== null && !$moduleOn($module)) {
                continue;
            }
            if ($perm !== null && !$can($perm)) {
                continue;
            }
            $v = $this->run($key, $companyId, $outletIds);
            if ($v === null) {
                continue;
            }
            $tiles[] = ['key' => $key, 'href' => $href] + $v;
        }
        return ['tiles' => $tiles];
    }

    /** Las dos partes de vencimientos, cada una con su permiso. `null` si no queda ninguna. */
    private function dues(string $companyId, array $outletIds, callable $can): ?array
    {
        $out = [];
        foreach (self::DUE_PARTS as $part => [$perm, $href]) {
            $out[$part] = null;
            if (!$can($perm)) {
                continue;
            }
            $v = $this->run($part, $companyId, $outletIds);
            if ($v !== null) {
                $out[$part] = $v + ['href' => $href];
            }
        }
        return ($out['checks'] === null && $out['payables'] === null) ? null : $out;
    }

    /** Corre una fuente aislada. `null` = sin dato (o falló, y queda en el log). */
    private function run(string $key, string $companyId, array $outletIds): ?array
    {
        if (!isset($this->sources[$key])) {
            return null;
        }
        try {
            return ($this->sources[$key])($companyId, $outletIds);
        } catch (\Throwable $e) {
            error_log("NowService: la fila '$key' falló y se omite — " . $e->getMessage());
            return null;
        }
    }

    // ── Fuentes ────────────────────────────────────────────────────────────

    /**
     * Órdenes activas y cuántas están demoradas en cocina.
     *
     * Una orden agendada para OTRO día (`scheduled_for`, mig 225) no es de
     * "ahora": no cuenta, igual que el KDS no la muestra hasta su día. Para una
     * agendada de hoy, la demora se cuenta desde lo que llegue último —el envío
     * o la hora pactada—: pedida a las 9 para las 13, a las 13:05 no está
     * demorada.
     *
     * Usa `idx_pos_order_company_outlet_status` (companyid, outletid, status).
     *
     * @return array{active:int, late:int, lateMinutes:int}|null
     */
    private function orders(string $companyId, array $outletIds): ?array
    {
        $active  = "'" . implode("','", \Punto\Api\Orders\OrderCoreService::ACTIVE_STATUSES) . "'";
        $kitchen = "'" . implode("','", \Punto\Api\Orders\OrderCoreService::KITCHEN_STATUSES) . "'";
        $row = ncmExecute(
            "SELECT COUNT(*) AS active,
                    COUNT(*) FILTER (
                        WHERE status IN ($kitchen)
                          AND GREATEST(COALESCE(sent_at, created_at), COALESCE(scheduled_for, '-infinity'::timestamptz))
                              <= now() - make_interval(mins => ?::int)
                    ) AS late
               FROM pos_order
              WHERE companyid = ? AND status IN ($active)
                AND (scheduled_for IS NULL OR scheduled_for < date_trunc('day', now()) + interval '1 day')"
            . \Punto\Api\Outlets\OutletScope::sqlFilter('outletid', $outletIds),
            [self::KITCHEN_LATE_MINUTES, $companyId]
        );
        $n = $row ? (int) ($row['active'] ?? 0) : 0;
        if ($n <= 0) {
            return null;
        }
        return ['active' => $n, 'late' => (int) ($row['late'] ?? 0), 'lateMinutes' => self::KITCHEN_LATE_MINUTES];
    }

    /** @return array{total:int, free:int, occupied:int, billRequested:int}|null */
    private function spaces(string $companyId, array $outletIds): ?array
    {
        global $db;
        $s = (new \Punto\Api\Spaces\SpaceService($db))->stateSummary($companyId, $outletIds);
        return $s['total'] > 0 ? $s : null;
    }

    /** @return array{count:int, rows:list<array<string,string>>}|null */
    private function drawers(string $companyId, array $outletIds): ?array
    {
        $r = (new DrawersService())->openNow($companyId, $outletIds, self::LIST_LIMIT);
        return $r['count'] > 0 ? $r : null;
    }

    /** @return array{count:int, people:list<array<string,string>>}|null */
    private function staff(string $companyId, array $outletIds): ?array
    {
        $r = (new \Punto\Api\Hr\AttendanceService())->presentNow($companyId, $outletIds, self::LIST_LIMIT);
        return $r['count'] > 0 ? $r : null;
    }

    /**
     * Citas que quedan HOY: no terminaron (`toDate` futura), empiezan antes de
     * que termine el día y siguen pendientes o en curso. Las próximas
     * `LIST_LIMIT` viajan con hora y cliente.
     *
     * `transaction` está particionada por `transactionDate`, que en una cita es
     * la fecha de CARGA y no la del turno, así que no hay rango de partición
     * que acotar sin perder citas agendadas hace tiempo. La lectura va por
     * `idx_tx_company_type` (companyid, transactiontype, …): índice, no scan.
     *
     * @return array{count:int, next:list<array{id:string,from:string,to:string,customer:string}>}|null
     */
    private function agenda(string $companyId, array $outletIds): ?array
    {
        $statuses = implode(',', array_map('intval', \Punto\Api\Services\ScheduleService::PENDING_STATUSES));
        $rs = ncmExecute(
            'SELECT t.transactionId AS "id", t.fromDate AS "fromDate", t.toDate AS "toDate",
                    COALESCE(NULLIF(c.contactName, \'\'), t.transactionName, \'\') AS "customer",
                    COUNT(*) OVER () AS "total"
               FROM transaction t
          LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = t.companyId
              WHERE t.companyId = ? AND t.transactionType = 13
                AND t.transactionStatus IN (' . $statuses . ')
                AND t.toDate >= now()
                AND t.fromDate < date_trunc(\'day\', now()) + interval \'1 day\''
            . \Punto\Api\Outlets\OutletScope::sqlFilter('t.outletId', $outletIds) . '
              ORDER BY t.fromDate ASC
              LIMIT ' . self::LIST_LIMIT,
            [$companyId],
            false,
            true
        );
        $total = 0;
        $next  = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f     = $rs->fields;
                $total = (int) ($f['total'] ?? 0);
                $next[] = [
                    'id'       => (string) ($f['id'] ?? ''),
                    'from'     => (string) ($f['fromDate'] ?? ''),
                    'to'       => (string) ($f['toDate'] ?? ''),
                    'customer' => (string) ($f['customer'] ?? ''),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $total > 0 ? ['count' => $total, 'next' => $next] : null;
    }

    /** @return array{count:int, amount:float, overdue:int, next:?string}|null */
    private function checks(string $companyId, array $outletIds): ?array
    {
        $today = substr(\Punto\Api\Support\TenantClock::now($companyId), 0, 10);
        $r = (new \Punto\Api\Finance\ObligationsService())->issuedChecksDue($companyId, $today, self::DUE_DAYS);
        return ($r['count'] > 0 || $r['overdue'] > 0) ? $r : null;
    }

    /** @return array{count:int, amount:float, overdue:int, next:?string}|null */
    private function payables(string $companyId, array $outletIds): ?array
    {
        $r = (new OpenInvoicesService())->payablesDue($companyId, $outletIds, self::DUE_DAYS);
        return ($r['count'] > 0 || $r['overdue'] > 0) ? $r : null;
    }
}
