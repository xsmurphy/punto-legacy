<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * OperationsService — KPIs de OPERACIÓN: órdenes y espacios.
 *
 * ── Vocabulario GENÉRICO, no gastronómico (regla del owner 2026-09-10) ──────
 *
 * El módulo de órdenes sirve a un taller, una óptica, una veterinaria y un
 * restaurante. Acá no hay "cocina", "salón", "mesa", "mesero" ni "comensal":
 * hay etapas de PROCESO, ESPACIOS, RESPONSABLE y PERSONAS. El mismo criterio
 * corre para las claves del JSON, no solo para los textos de pantalla — un
 * campo `kitchenTime` obliga a traducirlo en cada consumidor y termina
 * filtrándose a la UI.
 *
 * ── Por qué la COBERTURA va primero y no es opcional ────────────────────────
 *
 * Los tiempos entre etapas existen solo si alguien marca los estados mientras
 * trabaja. Y la máquina de estados PERMITE saltear: `OrderCoreService.php:82`
 * declara `'sent' => ['in_progress','ready','delivered','cancelled']`, o sea
 * que una orden puede ir de enviada a entregada en un paso, sin pasar por
 * "en proceso". Un promedio de demora calculado sobre el 20% de las órdenes
 * no es un promedio: es una anécdota con apariencia de dato, y alguien va a
 * decidir dotación de personal con él.
 *
 * Por eso cada bloque devuelve su propia cobertura —cuántas órdenes tenían el
 * dato sobre cuántas hubo— y la pantalla la declara arriba. El gráfico se
 * dibuja igual, con la advertencia encima; esconderlo detrás de un clic ya se
 * corrigió una vez en el mapa de clientes.
 *
 * ── De dónde salen los tiempos ──────────────────────────────────────────────
 *
 * NO de `pos_order`: esa tabla solo tiene `created_at` y `closed_at` (y este
 * último se escribe únicamente al cancelar o cerrar). Las transiciones viven
 * en `pos_order_event` (mig 85), una fila por cambio de estado con
 * `from_status`/`to_status`/`created_at`. El primer evento hacia cada estado
 * es el que cuenta: si una orden vuelve a "en proceso" después de estar
 * "lista", eso es RE-TRABAJO y se mide aparte, no se promedia como si fuera
 * la demora normal.
 */
final class OperationsService
{
    /** Estados de la orden que marcan el avance del trabajo, en orden. */
    private const STAGES = ['pending', 'sent', 'in_progress', 'ready', 'delivered'];

    /**
     * Todo el dataset del reporte.
     *
     * @param list<string> $include qué bloques calcular; vacío = todos.
     * @return array<string, mixed>
     */
    public function report(string $from, string $to, string $roc, string $companyId, array $include = []): array
    {
        $want = static fn (string $k): bool => $include === [] || in_array($k, $include, true);

        $out = [];
        if ($want('volume'))   $out['volume']   = $this->volume($from, $to, $roc);
        if ($want('stages'))   $out['stages']   = $this->stages($from, $to, $roc, $companyId);
        if ($want('demand'))   $out['demand']   = $this->demand($from, $to, $roc);
        if ($want('spaces'))   $out['spaces']   = $this->spaces($from, $to, $roc, $companyId);
        return $out;
    }

    /**
     * Volumen y desenlace de las órdenes del período.
     *
     * `cancelled` se cuenta aparte y NO se descuenta del total: una orden
     * cancelada ocupó trabajo igual, y esconderla haría que el % de
     * cancelación —que es justamente el KPI que interesa— no se pueda
     * calcular desde la respuesta.
     */
    private function volume(string $from, string $to, string $roc): array
    {
        $sql = "SELECT
                    COUNT(*)                                                   AS total,
                    COUNT(*) FILTER (WHERE status = 'cancelled')               AS cancelled,
                    COUNT(*) FILTER (WHERE status = 'delivered')               AS delivered,
                    COUNT(*) FILTER (WHERE status NOT IN ('cancelled','delivered','closed')) AS open,
                    COUNT(DISTINCT spaceid) FILTER (WHERE spaceid IS NOT NULL) AS spaces_used
                  FROM pos_order
                 WHERE created_at BETWEEN ? AND ?" . $roc;

        $r = ncmExecute($sql, [$from, $to]);

        return [
            'total'      => (int) ($r['total'] ?? 0),
            'cancelled'  => (int) ($r['cancelled'] ?? 0),
            'delivered'  => (int) ($r['delivered'] ?? 0),
            'open'       => (int) ($r['open'] ?? 0),
            'spacesUsed' => (int) ($r['spaces_used'] ?? 0),
        ];
    }

    /**
     * Demora entre etapas, en minutos, y su cobertura.
     *
     * `MIN(created_at)` por estado y no el último: el primer paso hacia
     * "en proceso" es cuando el trabajo empezó de verdad. Contar el último
     * mezclaría el re-trabajo (una orden que volvió atrás) con la demora
     * normal e inflaría el promedio sin que nadie sepa por qué.
     *
     * La MEDIANA además del promedio porque una sola orden olvidada abierta
     * toda la noche mueve el promedio y no la mediana — y el promedio es el
     * número que la gente mira.
     */
    private function stages(string $from, string $to, string $roc, string $companyId): array
    {
        $sql = "WITH ordenes AS (
                    SELECT orderid, created_at
                      FROM pos_order
                     WHERE created_at BETWEEN ? AND ?
                       AND status <> 'cancelled'" . $roc . "
                ),
                marcas AS (
                    SELECT e.orderid,
                           MIN(e.created_at) FILTER (WHERE e.to_status = 'sent')        AS at_sent,
                           MIN(e.created_at) FILTER (WHERE e.to_status = 'in_progress') AS at_progress,
                           MIN(e.created_at) FILTER (WHERE e.to_status = 'ready')       AS at_ready,
                           MIN(e.created_at) FILTER (WHERE e.to_status = 'delivered')   AS at_delivered,
                           -- Re-trabajo: volver a un estado anterior después de
                           -- haberlo dejado. Es señal de proceso, no de demora.
                           COUNT(*) FILTER (
                               WHERE e.scope = 'order'
                                 AND e.from_status IN ('ready','delivered')
                                 AND e.to_status   IN ('in_progress','sent')
                           ) AS reworks
                      FROM pos_order_event e
                      JOIN ordenes o ON o.orderid = e.orderid
                     WHERE e.companyid = ?
                     GROUP BY e.orderid
                )
                SELECT
                    COUNT(*)                                                        AS orders_total,
                    COUNT(*) FILTER (WHERE at_progress IS NOT NULL)                 AS with_progress,
                    COUNT(*) FILTER (WHERE at_ready IS NOT NULL)                    AS with_ready,
                    COUNT(*) FILTER (WHERE at_delivered IS NOT NULL)                AS with_delivered,
                    COALESCE(SUM(reworks), 0)                                       AS reworks,
                    COUNT(*) FILTER (WHERE reworks > 0)                             AS orders_with_rework,
                    AVG(EXTRACT(EPOCH FROM (at_progress - o.created_at)) / 60)      AS avg_to_progress,
                    AVG(EXTRACT(EPOCH FROM (at_ready - at_progress)) / 60)          AS avg_progress_to_ready,
                    AVG(EXTRACT(EPOCH FROM (at_delivered - at_ready)) / 60)         AS avg_ready_to_delivered,
                    AVG(EXTRACT(EPOCH FROM (at_delivered - o.created_at)) / 60)     AS avg_total,
                    PERCENTILE_CONT(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (at_delivered - o.created_at)) / 60
                    )                                                               AS median_total
                  FROM marcas m
                  JOIN ordenes o ON o.orderid = m.orderid";

        $r = ncmExecute($sql, [$from, $to, $companyId]);

        $total = (int) ($r['orders_total'] ?? 0);
        $mins  = static fn ($v): ?float => $v === null ? null : round((float) $v, 1);

        return [
            'ordersTotal'         => $total,
            'avgToProgress'       => $mins($r['avg_to_progress'] ?? null),
            'avgProgressToReady'  => $mins($r['avg_progress_to_ready'] ?? null),
            'avgReadyToDelivered' => $mins($r['avg_ready_to_delivered'] ?? null),
            'avgTotal'            => $mins($r['avg_total'] ?? null),
            'medianTotal'         => $mins($r['median_total'] ?? null),
            'reworks'             => (int) ($r['reworks'] ?? 0),
            'ordersWithRework'    => (int) ($r['orders_with_rework'] ?? 0),
            // La cobertura es parte del dato, no un extra: sin esto el
            // promedio no se puede interpretar.
            'coverage' => [
                'withProgress'  => (int) ($r['with_progress'] ?? 0),
                'withReady'     => (int) ($r['with_ready'] ?? 0),
                'withDelivered' => (int) ($r['with_delivered'] ?? 0),
                'total'         => $total,
            ],
        ];
    }

    /**
     * Cuándo entran las órdenes: por hora del día y por día de la semana.
     *
     * `EXTRACT` sale en la zona del TENANT sin `AT TIME ZONE` explícito porque
     * `TenantClock::apply()` fija la zona de la sesión de Postgres antes de la
     * query (mismo mecanismo que usan `SalesService` y `DashboardService`).
     */
    private function demand(string $from, string $to, string $roc): array
    {
        $byHour = ncmExecute(
            "SELECT EXTRACT(HOUR FROM created_at)::int AS bucket, COUNT(*) AS orders
               FROM pos_order
              WHERE created_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc . "
              GROUP BY 1 ORDER BY 1",
            [$from, $to], false, false, true
        );

        // `ISODOW`: 1 = lunes … 7 = domingo. `DOW` arranca en domingo=0 y
        // obliga a rotar en el front, que es donde se equivoca.
        $byWeekday = ncmExecute(
            "SELECT EXTRACT(ISODOW FROM created_at)::int AS bucket, COUNT(*) AS orders
               FROM pos_order
              WHERE created_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc . "
              GROUP BY 1 ORDER BY 1",
            [$from, $to], false, false, true
        );

        return [
            'byHour'    => $this->buckets(is_array($byHour) ? $byHour : []),
            'byWeekday' => $this->buckets(is_array($byWeekday) ? $byWeekday : []),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buckets(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['bucket' => (int) ($r['bucket'] ?? 0), 'orders' => (int) ($r['orders'] ?? 0)];
        }
        return $out;
    }

    /**
     * Uso de los espacios: cuántas sesiones, cuánto duran, cuántas personas.
     *
     * `guests` es NULLABLE y nadie obliga a cargarlo, así que el promedio de
     * personas sale SOLO de las sesiones que lo tienen y la cobertura viaja al
     * lado. Promediar tratando el NULL como cero daría siempre un número
     * bajísimo y creíble, que es la peor clase de error.
     *
     * Las sesiones abiertas quedan fuera de la duración —todavía no
     * terminaron— pero SÍ cuentan para el volumen.
     */
    private function spaces(string $from, string $to, string $roc, string $companyId): array
    {
        $totals = ncmExecute(
            "SELECT
                 COUNT(*)                                              AS sessions,
                 COUNT(*) FILTER (WHERE guests IS NOT NULL)            AS with_guests,
                 COUNT(*) FILTER (WHERE closed_at IS NOT NULL)         AS closed,
                 AVG(guests) FILTER (WHERE guests IS NOT NULL)         AS avg_guests,
                 AVG(EXTRACT(EPOCH FROM (closed_at - opened_at)) / 60) AS avg_minutes
               FROM space_session
              WHERE opened_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc,
            [$from, $to]
        );

        // Matriz espacio × hora: es el "mapa de calor" que se pidió — qué
        // espacio se usa a qué hora. NO es el plano del salón; el plano es
        // otro trabajo (`space.posx/posy`) y responde otra pregunta.
        // El filtro de tenant va en un CTE sobre `space_session` SOLA, sin
        // JOIN: `$roc` no lleva alias (`Roc::build`) y `companyid` existe
        // tanto en `space_session` como en `space`, así que aplicado sobre el
        // JOIN daría "column reference is ambiguous" — un error que recién
        // aparecería en runtime, con el reporte en producción.
        $heat = ncmExecute(
            "WITH ses AS (
                 SELECT sessionid, tableid, opened_at
                   FROM space_session
                  WHERE opened_at BETWEEN ? AND ?
                    AND status <> 'cancelled'" . $roc . "
             )
             SELECT sp.tableid                           AS space_id,
                    sp.tablename                         AS space_name,
                    EXTRACT(HOUR FROM s.opened_at)::int  AS hour,
                    COUNT(*)                             AS sessions
               FROM ses s
               JOIN space sp ON sp.tableid = s.tableid AND sp.companyid = ?
              GROUP BY 1, 2, 3
              ORDER BY 2, 3",
            [$from, $to, $companyId], false, false, true
        );

        $matrix = [];
        foreach (is_array($heat) ? $heat : [] as $r) {
            $matrix[] = [
                'spaceId'   => (string) ($r['space_id'] ?? ''),
                'spaceName' => (string) ($r['space_name'] ?? ''),
                'hour'      => (int) ($r['hour'] ?? 0),
                'sessions'  => (int) ($r['sessions'] ?? 0),
            ];
        }

        $sessions = (int) ($totals['sessions'] ?? 0);

        return [
            'sessions'   => $sessions,
            'avgGuests'  => isset($totals['avg_guests']) && $totals['avg_guests'] !== null
                ? round((float) $totals['avg_guests'], 1) : null,
            'avgMinutes' => isset($totals['avg_minutes']) && $totals['avg_minutes'] !== null
                ? round((float) $totals['avg_minutes'], 1) : null,
            'heatmap'    => $matrix,
            'coverage'   => [
                'withGuests' => (int) ($totals['with_guests'] ?? 0),
                'closed'     => (int) ($totals['closed'] ?? 0),
                'total'      => $sessions,
            ],
        ];
    }
}
