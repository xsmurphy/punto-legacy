<?php
/**
 * REST — Cierre de período contable (D7/E1b de context/48-escalamiento-de-datos.md,
 * mig 157).
 *
 *   GET  /v1/period-close
 *     → { months: [{period, transactionCount, closed, closedAt, closedBy, source}],
 *         closeMonths, nextAutoClose, hasOlder }
 *     Lista los últimos 24 meses con transacciones económicas del tenant
 *     (transactiontype IN (0,1,3,4,5,6,7,10,14) — ver api/lib/Sales/SaleType.php),
 *     con su estado de cierre. `hasOlder=true` si hay transacciones más
 *     viejas que esos 24 meses (no se listan, solo se avisa — fix
 *     code-review mig 157: la query original escaneaba `transaction`
 *     entera sin cota de fecha).
 *
 *   POST /v1/period-close { period: 'YYYY-MM' }
 *     → { period, closed: true }
 *     Cierra manualmente un período fuera de la ventana abierta del tenant.
 *     Requiere permiso `settings.periodClose`. Sin endpoint de "reabrir" —
 *     fuera de alcance (ver comentario de la tabla `period_close`, mig 157).
 *
 * Auth: SOLO panel (operadores) — nunca device/pos-app. Cerrar un período es
 * una operación de backoffice, no de mostrador.
 *
 * El cierre automático (job de mantenimiento, ver api/v1/maintenance.php
 * `period-close`) usa `period_close_due()` con `source='job'`; este endpoint
 * usa `source='manual'` siempre.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = (string) $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

global $db;

if ($method === 'GET') {
    $closeMonths = max(1, min(12, (int) ((new \Punto\Api\Settings\SettingsService())->general($companyId)['settingPeriodCloseMonths'] ?? 1)));

    // Fix code-review mig 157: sin cota de fecha esto escaneaba `transaction`
    // entera (todas las particiones históricas, mig 156) solo para mostrar
    // 24 filas. Acotado a los últimos 24 meses — el particionado por rango
    // (transactiondate) hace pruning y solo toca esas particiones. `hasOlder`
    // (debajo) le avisa al panel si hay historia más vieja sin listarla acá.
    //
    // Meses con transacciones económicas del tenant, LEFT JOIN contra el
    // estado de cierre. `forceObj=true`: siempre RecordsetIterator, sin
    // importar cuántas filas vuelvan (0/1/muchas) — ver DB layer del proyecto.
    $rs = ncmExecute(
        "SELECT m.period, m.txcount,
                (pc.period IS NOT NULL) AS closed,
                pc.closedat, pc.source,
                u.contactName AS closedbyname
           FROM (
                  SELECT date_trunc('month', transactiondate)::date AS period,
                         count(*) AS txcount
                    FROM transaction
                   WHERE companyid = ?
                     AND transactiontype = ANY (ARRAY[0,1,3,4,5,6,7,10,14])
                     AND transactiondate >= (date_trunc('month', now() AT TIME ZONE 'America/Asuncion') - interval '24 months')
                   GROUP BY 1
                ) m
           LEFT JOIN period_close pc ON pc.companyid = ? AND pc.period = m.period
           LEFT JOIN contact u ON u.contactId = pc.closedby AND u.companyId = ?
          ORDER BY m.period DESC
          LIMIT 24",
        [$companyId, $companyId, $companyId],
        false,
        true
    );

    // Historia más vieja que la ventana de 24 meses de arriba, sin listarla
    // (solo un flag) — EXISTS + LIMIT 1 implícito, no repite el scan que
    // acotamos arriba.
    $hasOlderRaw = $db->GetOne(
        "SELECT EXISTS (
                SELECT 1 FROM transaction
                 WHERE companyid = ?
                   AND transactiontype = ANY (ARRAY[0,1,3,4,5,6,7,10,14])
                   AND transactiondate < (date_trunc('month', now() AT TIME ZONE 'America/Asuncion') - interval '24 months')
               )",
        [$companyId]
    );
    $hasOlder = maintenancePgBoolTrueForPeriodClose($hasOlderRaw);

    $months = [];
    if ($rs !== false) {
        while (!$rs->EOF) {
            $months[] = [
                'period'           => substr((string) $rs->fields['period'], 0, 7), // 'YYYY-MM'
                'transactionCount' => (int) $rs->fields['txcount'],
                'closed'           => maintenancePgBoolTrueForPeriodClose($rs->fields['closed']),
                'closedAt'         => $rs->fields['closedat'] !== null ? (string) $rs->fields['closedat'] : null,
                'closedBy'         => $rs->fields['closedbyname'] !== null ? (string) $rs->fields['closedbyname'] : null,
                'source'           => $rs->fields['source'] !== null ? (string) $rs->fields['source'] : null,
            ];
            $rs->MoveNext();
        }
    }

    // Próximo día 2 a las 05:00 en la zona del guard (America/Asuncion —
    // mismo criterio que period_is_closed/period_close_due). Informativo,
    // no hace falta exactitud al segundo.
    $tz   = new \DateTimeZone('America/Asuncion');
    $now  = new \DateTime('now', $tz);
    $next = new \DateTime($now->format('Y-m') . '-02 05:00:00', $tz);
    if ($next <= $now) {
        $next->modify('first day of next month')->setTime(5, 0, 0)->modify('+1 day'); // día 2
    }

    apiOk([
        'months'        => $months,
        'closeMonths'   => $closeMonths,
        'nextAutoClose' => $next->format('Y-m-d'),
        'hasOlder'      => $hasOlder,
    ]);
}

if ($method === 'POST') {
    if (!hasPermission('settings.periodClose')) {
        apiForbidden('No tenés permiso para esta acción (requiere: settings.periodClose)');
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $period = (string) ($body['period'] ?? '');

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        apiUnprocessable('Período inválido (formato esperado: YYYY-MM)');
    }
    $periodDate = $period . '-01';

    $closeMonths = max(1, min(12, (int) ((new \Punto\Api\Settings\SettingsService())->general($companyId)['settingPeriodCloseMonths'] ?? 1)));

    // Mismo límite que period_close_due(): el mes es cerrable solo si cae
    // ANTES de (mes en curso - closeMonths). Se lo pedimos a Postgres para
    // no duplicar la aritmética de fechas/zona horaria en PHP.
    $boundary = $db->GetOne(
        "SELECT (date_trunc('month', now() AT TIME ZONE 'America/Asuncion') - (? || ' months')::interval)::date",
        [$closeMonths]
    );
    if ($boundary === false || $periodDate >= (string) $boundary) {
        apiUnprocessable('No se puede cerrar un período dentro de la ventana abierta', ['closeMonths' => $closeMonths]);
    }

    $userId = (string) ($ctx['userId'] ?? '');
    $ok     = $db->Execute(
        'SELECT period_close_run(?, ?, ?, ?)',
        [$companyId, $periodDate, $userId !== '' ? $userId : null, 'manual']
    );
    if ($ok === false) {
        apiError('No se pudo cerrar el período', 500);
    }

    apiOk(['period' => $period, 'closed' => true]);
}

apiError('Método no permitido', 405);

/**
 * Boolean de Postgres vía PDO pgsql para el flag `closed` (calculado con
 * `IS NOT NULL` en el SELECT) — mismo caveat que `maintenancePgBoolTrue` de
 * maintenance.php (llega como 't'/'f' textual, nunca bool nativo). No se
 * reusa esa función porque vive en otro archivo sin incluirse acá.
 */
function maintenancePgBoolTrueForPeriodClose(mixed $v): bool
{
    return $v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1;
}
