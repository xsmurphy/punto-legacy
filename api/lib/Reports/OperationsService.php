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
 * NO de `pos_order`: esa tabla solo tiene `created_at`, `sent_at` y
 * `closed_at` (este último se escribe únicamente al cancelar o cerrar). Las
 * transiciones viven en `pos_order_event` (mig 85), una fila por cambio de
 * estado con `scope`/`from_status`/`to_status`/`created_at`.
 *
 * SOLO los eventos `scope='order'`. Los de ítem usan los MISMOS nombres de
 * estado (`ready`, `delivered`) con otro significado —una línea lista no es la
 * orden lista—, y sin el filtro la entrega del primer ítem pasaba por la
 * entrega de la orden. Lo verifica `api/tests/operations_report_test.php`.
 *
 * Qué marca cuenta en cada etapa, y por qué:
 *   - ENVÍO: la primera vez que llegó a `sent`. La cola se mide desde acá y no
 *     desde `created_at`: una orden que nació `open` y se envió diez minutos
 *     después no estuvo esperando a nadie esos diez minutos.
 *   - EN PROCESO: la primera vez. Es cuando el trabajo empezó de verdad.
 *   - LISTA: la ÚLTIMA vez. Si una orden quedó lista, volvió a proceso y quedó
 *     lista de nuevo, el tiempo rehecho fue TRABAJO, no espera: medirlo hasta
 *     la primera "lista" lo pasaba a la etapa siguiente. Y con la primera, una
 *     orden que salteó "en proceso" (enviada → lista) y después volvió a
 *     proceso daba una demora NEGATIVA.
 *   - ENTREGADA: la primera vez.
 *
 * Toda diferencia se promedia solo cuando es >= 0: un reloj de tablet atrasado
 * no puede restarle minutos al promedio de las demás.
 *
 * El re-trabajo se mide aparte y NO se promedia como si fuera la demora
 * normal: a nivel orden (lista → en proceso/enviada) y a nivel línea
 * (lista → en preparación, el "devolver" del board).
 */
final class OperationsService
{
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
        if ($want('volume'))   $out['volume']   = $this->volume($from, $to, $roc, $companyId);
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
     *
     * `completed` = entregadas + cobradas. `markPaid()` cierra la orden desde
     * CUALQUIER estado activo (una orden cobrada mientras seguía en proceso
     * pasa a `closed` sin haber sido nunca `delivered`), así que contar solo
     * `delivered` como "terminadas" subestima justo a los comercios que cobran
     * al final.
     *
     * Los espacios usados salen de la SESIÓN de la orden (`spacesessionid` →
     * `space_session.tableid`): `pos_order` no guarda el espacio. El filtro de
     * tenant va en un CTE sobre `pos_order` sola porque `$roc` no lleva alias y
     * `companyid`/`status` existen en las dos tablas.
     */
    private function volume(string $from, string $to, string $roc, string $companyId): array
    {
        $sql = "WITH o AS (
                    SELECT status, spacesessionid
                      FROM pos_order
                     WHERE created_at BETWEEN ? AND ?" . $roc . "
                )
                SELECT
                    COUNT(*)                                                     AS total,
                    COUNT(*) FILTER (WHERE o.status = 'cancelled')               AS cancelled,
                    COUNT(*) FILTER (WHERE o.status = 'delivered')               AS delivered,
                    COUNT(*) FILTER (WHERE o.status IN ('delivered','closed'))   AS completed,
                    COUNT(*) FILTER (WHERE o.status NOT IN ('cancelled','delivered','closed')) AS open,
                    COUNT(DISTINCT ss.tableid)                                   AS spaces_used
                  FROM o
             LEFT JOIN space_session ss
                    ON ss.sessionid = o.spacesessionid
                   AND ss.companyid = ?";

        $r = ncmExecute($sql, [$from, $to, $companyId]);

        return [
            'total'      => (int) ($r['total'] ?? 0),
            'cancelled'  => (int) ($r['cancelled'] ?? 0),
            'delivered'  => (int) ($r['delivered'] ?? 0),
            'completed'  => (int) ($r['completed'] ?? 0),
            'open'       => (int) ($r['open'] ?? 0),
            'spacesUsed' => (int) ($r['spaces_used'] ?? 0),
        ];
    }

    /**
     * Demora entre etapas, en minutos, y su cobertura.
     *
     * El universo es TODA orden no cancelada del período, tenga eventos o no
     * (LEFT JOIN): una orden sin log —histórica, o de un camino que no lo
     * escribe— es exactamente la que no tiene el dato, y sacarla del
     * denominador hacía que la cobertura se mintiera a favor.
     *
     * La MEDIANA además del promedio porque una sola orden olvidada abierta
     * toda la noche mueve el promedio y no la mediana — y el promedio es el
     * número que la gente mira.
     *
     * `skipped` = entregadas sin haber pasado por "en proceso" o por "lista":
     * es la medida directa del salto que permite la máquina de estados, y la
     * pantalla la muestra como tal. `fullyTracked` = con las tres marcas.
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
                           MIN(e.created_at) FILTER (WHERE e.scope = 'order' AND e.to_status = 'sent')        AS at_sent,
                           MIN(e.created_at) FILTER (WHERE e.scope = 'order' AND e.to_status = 'in_progress') AS at_progress,
                           MAX(e.created_at) FILTER (WHERE e.scope = 'order' AND e.to_status = 'ready')       AS at_ready,
                           MIN(e.created_at) FILTER (WHERE e.scope = 'order' AND e.to_status = 'delivered')   AS at_delivered,
                           COUNT(*) FILTER (
                               WHERE e.scope = 'order'
                                 AND e.from_status = 'ready'
                                 AND e.to_status IN ('in_progress','sent')
                           ) AS reworks,
                           COUNT(*) FILTER (
                               WHERE e.scope = 'item'
                                 AND e.from_status = 'ready'
                                 AND e.to_status = 'preparing'
                           ) AS rework_items
                      FROM pos_order_event e
                      JOIN ordenes o ON o.orderid = e.orderid
                     WHERE e.companyid = ?
                     GROUP BY e.orderid
                ),
                t AS (
                    SELECT COALESCE(m.at_sent, o.created_at) AS t_sent,
                           m.at_progress, m.at_ready, m.at_delivered,
                           COALESCE(m.reworks, 0)      AS reworks,
                           COALESCE(m.rework_items, 0) AS rework_items
                      FROM ordenes o
                 LEFT JOIN marcas m ON m.orderid = o.orderid
                )
                SELECT
                    COUNT(*)                                                         AS orders_total,
                    COUNT(*) FILTER (WHERE at_progress  IS NOT NULL)                 AS with_progress,
                    COUNT(*) FILTER (WHERE at_ready     IS NOT NULL)                 AS with_ready,
                    COUNT(*) FILTER (WHERE at_delivered IS NOT NULL)                 AS with_delivered,
                    COUNT(*) FILTER (
                        WHERE at_delivered IS NOT NULL
                          AND (at_progress IS NULL OR at_ready IS NULL)
                    )                                                                AS skipped,
                    COUNT(*) FILTER (
                        WHERE at_progress IS NOT NULL AND at_ready IS NOT NULL AND at_delivered IS NOT NULL
                    )                                                                AS fully_tracked,
                    COALESCE(SUM(reworks), 0)                                        AS reworks,
                    COALESCE(SUM(rework_items), 0)                                   AS rework_items,
                    COUNT(*) FILTER (WHERE reworks > 0 OR rework_items > 0)          AS orders_with_rework,
                    COUNT(*) FILTER (WHERE at_progress  >= t_sent)                   AS n_to_progress,
                    COUNT(*) FILTER (WHERE at_ready     >= at_progress)              AS n_progress_to_ready,
                    COUNT(*) FILTER (WHERE at_delivered >= at_ready)                 AS n_ready_to_delivered,
                    COUNT(*) FILTER (WHERE at_delivered >= t_sent)                   AS n_total,
                    AVG(EXTRACT(EPOCH FROM (at_progress - t_sent)) / 60)
                        FILTER (WHERE at_progress >= t_sent)                         AS avg_to_progress,
                    AVG(EXTRACT(EPOCH FROM (at_ready - at_progress)) / 60)
                        FILTER (WHERE at_ready >= at_progress)                       AS avg_progress_to_ready,
                    AVG(EXTRACT(EPOCH FROM (at_delivered - at_ready)) / 60)
                        FILTER (WHERE at_delivered >= at_ready)                      AS avg_ready_to_delivered,
                    AVG(EXTRACT(EPOCH FROM (at_delivered - t_sent)) / 60)
                        FILTER (WHERE at_delivered >= t_sent)                        AS avg_total,
                    PERCENTILE_CONT(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM (at_delivered - t_sent)) / 60
                    ) FILTER (WHERE at_delivered >= t_sent)                          AS median_total
                  FROM t";

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
            'reworkItems'         => (int) ($r['rework_items'] ?? 0),
            'ordersWithRework'    => (int) ($r['orders_with_rework'] ?? 0),
            // La cobertura es parte del dato, no un extra: sin esto el
            // promedio no se puede interpretar.
            'coverage' => [
                'withProgress'  => (int) ($r['with_progress'] ?? 0),
                'withReady'     => (int) ($r['with_ready'] ?? 0),
                'withDelivered' => (int) ($r['with_delivered'] ?? 0),
                'skipped'       => (int) ($r['skipped'] ?? 0),
                'fullyTracked'  => (int) ($r['fully_tracked'] ?? 0),
                'total'         => $total,
                // Sobre cuántas órdenes se calculó CADA promedio — exactamente
                // su denominador, no una aproximación: "en proceso → lista"
                // necesita las dos marcas, y `withReady` cuenta también las
                // que llegaron a lista salteando "en proceso".
                'stageSamples'  => [
                    'toProgress'       => (int) ($r['n_to_progress'] ?? 0),
                    'progressToReady'  => (int) ($r['n_progress_to_ready'] ?? 0),
                    'readyToDelivered' => (int) ($r['n_ready_to_delivered'] ?? 0),
                    'total'            => (int) ($r['n_total'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Cuándo entran las órdenes: por hora del día y por día de la semana.
     * Sin las canceladas: el bloque responde cuándo hay trabajo que hacer.
     *
     * `EXTRACT` sale en la zona del TENANT sin `AT TIME ZONE` explícito porque
     * `TenantClock::apply()` fija la zona de la sesión de Postgres antes de la
     * query (mismo mecanismo que usan `SalesService` y `DashboardService`; el
     * arnés lo verifica cambiando la zona de la sesión).
     *
     * `ncmRows` y no `ncmExecute(..., getAssoc)`: el segundo indexa por la
     * primera columna y descarta en silencio las filas que la repiten.
     */
    private function demand(string $from, string $to, string $roc): array
    {
        $byHour = ncmRows(
            "SELECT EXTRACT(HOUR FROM created_at)::int AS bucket, COUNT(*) AS orders
               FROM pos_order
              WHERE created_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc . "
              GROUP BY 1 ORDER BY 1",
            [$from, $to]
        );

        // `ISODOW`: 1 = lunes … 7 = domingo. `DOW` arranca en domingo=0 y
        // obliga a rotar en el front, que es donde se equivoca.
        $byWeekday = ncmRows(
            "SELECT EXTRACT(ISODOW FROM created_at)::int AS bucket, COUNT(*) AS orders
               FROM pos_order
              WHERE created_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc . "
              GROUP BY 1 ORDER BY 1",
            [$from, $to]
        );

        return [
            'byHour'    => $this->buckets($byHour),
            'byWeekday' => $this->buckets($byWeekday),
        ];
    }

    /** @param iterable<array<string, mixed>|\ArrayAccess> $rows */
    private function buckets(iterable $rows): array
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
     * Una sesión FUSIONADA (`mergedinto` no nulo, mig 163) no es una ocupación:
     * es la cuenta de un espacio que se unió a otro, queda `closed` con cero
     * órdenes. Contarla inflaba la rotación; se excluye de todo y se informa
     * aparte en `merged`.
     *
     * Las sesiones abiertas quedan fuera de la duración —todavía no
     * terminaron— pero SÍ cuentan para el volumen.
     */
    private function spaces(string $from, string $to, string $roc, string $companyId): array
    {
        $totals = ncmExecute(
            "SELECT
                 COUNT(*) FILTER (WHERE mergedinto IS NULL)                            AS sessions,
                 COUNT(*) FILTER (WHERE mergedinto IS NOT NULL)                        AS merged,
                 COUNT(*) FILTER (WHERE mergedinto IS NULL AND guests IS NOT NULL)     AS with_guests,
                 COUNT(*) FILTER (WHERE mergedinto IS NULL AND closed_at IS NOT NULL)  AS closed,
                 AVG(guests) FILTER (WHERE mergedinto IS NULL AND guests IS NOT NULL)  AS avg_guests,
                 AVG(EXTRACT(EPOCH FROM (closed_at - opened_at)) / 60)
                     FILTER (WHERE mergedinto IS NULL AND closed_at >= opened_at)      AS avg_minutes
               FROM space_session
              WHERE opened_at BETWEEN ? AND ?
                AND status <> 'cancelled'" . $roc,
            [$from, $to]
        );

        // Matriz espacio × hora: el "mapa de calor" que se pidió — qué espacio
        // está OCUPADO a qué hora. NO es el plano del local; el plano es otro
        // trabajo (`space.posx/posy`) y responde otra pregunta.
        //
        // Una sesión cerrada ocupa CADA hora que tocó (20:30-22:10 pinta 20,
        // 21 y 22), no solo la de apertura: contar solo la apertura hacía que
        // un espacio ocupado toda la tarde pesara lo mismo que uno de diez
        // minutos. Una sesión ABIERTA no tiene fin conocido y pinta solo su
        // hora de apertura: estirarla hasta hoy pintaría el día entero con una
        // sesión que alguien se olvidó de cerrar.
        //
        // Tope de 24 horas por sesión: la matriz cuenta sesiones DISTINTAS por
        // hora del día, así que más allá de un día no agrega nada — y sin tope
        // una sesión olvidada una semana generaba 168 filas para decir lo
        // mismo.
        //
        // El filtro de tenant va en un CTE sobre `space_session` SOLA, sin
        // JOIN: `$roc` no lleva alias (`Roc::build`) y `companyid` existe
        // tanto en `space_session` como en `space`, así que aplicado sobre el
        // JOIN daría "column reference is ambiguous".
        $heat = ncmRows(
            "WITH ses AS (
                 SELECT sessionid, tableid, opened_at,
                        LEAST(COALESCE(closed_at, opened_at), opened_at + INTERVAL '23 hours') AS until_at
                   FROM space_session
                  WHERE opened_at BETWEEN ? AND ?
                    AND status <> 'cancelled'
                    AND mergedinto IS NULL" . $roc . "
             ),
             horas AS (
                 SELECT DISTINCT s.sessionid, s.tableid, EXTRACT(HOUR FROM g.h)::int AS hour
                   FROM ses s
             CROSS JOIN LATERAL generate_series(
                            date_trunc('hour', s.opened_at),
                            GREATEST(s.until_at, s.opened_at),
                            INTERVAL '1 hour'
                        ) AS g(h)
             )
             SELECT sp.tableid AS space_id,
                    sp.name    AS space_name,
                    h.hour     AS hour,
                    COUNT(*)   AS sessions
               FROM horas h
               JOIN space sp ON sp.tableid = h.tableid AND sp.companyid = ?
              GROUP BY sp.tableid, sp.name, h.hour
              ORDER BY sp.name, h.hour",
            [$from, $to, $companyId]
        );

        $matrix = [];
        foreach ($heat as $r) {
            $matrix[] = [
                'spaceId'   => (string) ($r['space_id'] ?? ''),
                'spaceName' => (string) ($r['space_name'] ?? ''),
                'hour'      => (int) ($r['hour'] ?? 0),
                'sessions'  => (int) ($r['sessions'] ?? 0),
            ];
        }

        // Filas de la matriz: todos los espacios activos del alcance, incluidos
        // los que NUNCA se usaron en el período — un espacio siempre vacío es
        // un hallazgo, y si solo aparecieran los usados no se vería. Sin la
        // decoración (`decor_wall`/`decor_plant`): una pared no tiene
        // ocupación.
        $list = ncmRows(
            "SELECT tableid AS space_id, name AS space_name
               FROM space
              WHERE status = 1
                AND shape NOT IN ('decor_wall','decor_plant')" . $roc . "
              ORDER BY sort, name",
            []
        );
        $spaceList = [];
        foreach ($list as $r) {
            $spaceList[] = [
                'spaceId'   => (string) ($r['space_id'] ?? ''),
                'spaceName' => (string) ($r['space_name'] ?? ''),
            ];
        }

        $sessions = (int) ($totals['sessions'] ?? 0);

        return [
            'sessions'   => $sessions,
            'merged'     => (int) ($totals['merged'] ?? 0),
            'avgGuests'  => isset($totals['avg_guests']) && $totals['avg_guests'] !== null
                ? round((float) $totals['avg_guests'], 1) : null,
            'avgMinutes' => isset($totals['avg_minutes']) && $totals['avg_minutes'] !== null
                ? round((float) $totals['avg_minutes'], 1) : null,
            'heatmap'    => $matrix,
            'spaceList'  => $spaceList,
            'coverage'   => [
                'withGuests' => (int) ($totals['with_guests'] ?? 0),
                'closed'     => (int) ($totals['closed'] ?? 0),
                'total'      => $sessions,
            ],
        ];
    }
}
