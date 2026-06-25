<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * ScheduleService — agenda/calendario del POS (slice 4 del desacople de /app).
 *
 * Citas/turnos son filas de `transaction` con fromDate/toDate. Portado de
 * app/action.php (updateScheduleTo 1166, unlockCalendar 1440). Escrituras con
 * $db->Execute parametrizado (no ncm*) — corrige los bugs PG del legacy:
 *   - transactionId/companyId bindeados (el legacy los interpolaba sin comillas).
 *   - DELETE sin `LIMIT 1` (PG no lo soporta).
 *
 * updateSchedule/scheduleSession/checkIfUserOccupied reescriben/leen `transactionDetails`,
 * que vive en `meta` (jsonb) → usan los helpers txMeta* (read-modify-write, §22.6).
 */

require_once __DIR__ . '/../meta_transaction.php';

final class ScheduleService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /** Cambia la hora de fin (toDate) de una cita, preservando su fecha de inicio. */
    public function rescheduleTo(string $companyId, string $transId, string $time): array
    {
        global $db;

        $row = ncmExecute(
            'SELECT fromDate FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transId, $companyId]
        );
        if (!$row || empty($row['fromDate'])) {
            return ['ok' => false];
        }

        $toDate = date('Y-m-d', strtotime($row['fromDate'])) . ' ' . $time;

        $res = $db->Execute(
            'UPDATE transaction SET toDate = ? WHERE transactionId = ? AND companyId = ?',
            [$toDate, $transId, $companyId]
        );
        return ['ok' => $res !== false];
    }

    /** Borra un "lock" de calendario (una transaction puntual). */
    public function unlock(string $companyId, string $transId): array
    {
        global $db;
        $res = $db->Execute(
            'DELETE FROM transaction WHERE transactionId = ? AND companyId = ?',
            [$transId, $companyId]
        );
        return ['ok' => $res !== false];
    }

    /**
     * updateSchedule (action.php L1113): reagenda a una nueva hora (preservando la duración)
     * y reasigna el usuario. Setea ['user'] en cada item de transactionDetails (meta).
     */
    public function updateUser(string $companyId, string $transId, string $hour, string $newUser): array
    {
        global $db;

        $row = ncmExecute(
            'SELECT fromDate, toDate FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transId, $companyId]
        );
        if (!$row || empty($row['fromDate'])) {
            return ['ok' => false];
        }

        $diff   = strtotime($row['toDate']) - strtotime($row['fromDate']); // duración
        // Reemplaza la hora preservando minutos/segundos de fromDate (formato verbatim del legacy).
        $fromSt = strtotime(date('Y-m-d ' . $hour . ':i:s', strtotime($row['fromDate'])));
        $toSt   = $fromSt + $diff;
        $from   = date('Y-m-d H:i:s', $fromSt);
        $to     = date('Y-m-d H:i:s', $toSt);

        $details = txDetailsFromMeta(txMetaRead($transId, $companyId));
        foreach ($details as $k => $_) {
            $details[$k]['user'] = $newUser;
        }

        $res = $db->Execute(
            'UPDATE transaction SET userId = ?, fromDate = ?, toDate = ?
              WHERE transactionId = ? AND companyId = ?',
            [$newUser, $from, $to, $transId, $companyId]
        );
        if ($res === false) {
            return ['ok' => false];
        }
        txDetailsWrite($transId, $companyId, $details);
        return ['ok' => true];
    }

    /**
     * scheduleSession (action.php L1021): agenda una sesión/cita — fija from/to/user,
     * status=0, outlet/register/responsible, y setea ['userId'] en cada item (meta).
     * Las notificaciones (email/SMS/push) van en el endpoint. Scope companyId agregado
     * (el legacy sólo filtraba por transactionId).
     */
    public function session(
        string $companyId,
        string $outletId,
        string $registerId,
        string $responsibleId,
        string $transId,
        string $from,
        string $to,
        string $user
    ): array {
        global $db;

        $details = txDetailsFromMeta(txMetaRead($transId, $companyId));
        foreach ($details as $k => $_) {
            $details[$k]['userId'] = $user;
        }

        $res = $db->Execute(
            'UPDATE transaction
                SET userId = ?, fromDate = ?, toDate = ?, transactionStatus = 0,
                    outletId = ?, registerId = ?, responsibleId = ?
              WHERE transactionId = ? AND companyId = ?',
            [$user, $from, $to, $outletId, $registerId, $responsibleId, $transId, $companyId]
        );
        if ($res === false) {
            return ['ok' => false];
        }
        txDetailsWrite($transId, $companyId, $details);
        return ['ok' => true];
    }

    /**
     * checkIfUserOccupied (action.php L1197): de un set de userIds, cuáles están ocupados
     * en citas (type 13) que solapan [from,to]. Lee transactionDetails desde meta por fila
     * (multi-row → $db->Execute directo, sin _flattenJsonb). Lógica portada verbatim.
     *
     * @param string[] $users
     * @return string[] userIds ocupados (únicos)
     */
    public function checkUserOccupied(string $companyId, string $outletId, array $users, string $from, string $to): array
    {
        global $db;
        if (empty($users)) {
            return [];
        }

        $res = $db->Execute(
            'SELECT userId, transactionStatus, meta FROM transaction
              WHERE companyId = ? AND outletId = ? AND transactionType = 13
                AND transactionStatus NOT IN (6, 4)
                AND (? <= toDate AND ? >= fromDate)
              LIMIT 50',
            [$companyId, $outletId, $from, $to]
        );

        $dUsers = [];
        if ($res !== false) {
            while (!$res->EOF) {
                $f    = $res->fields;
                $meta = json_decode($f['meta'] ?? '{}', true) ?: [];
                $itms = txDetailsFromMeta($meta);

                if ($f['transactionStatus'] == 7) {
                    $itms = [];
                    if (in_array($f['userId'], $users)) {
                        $dUsers[] = $f['userId'];
                    }
                }

                if (!empty($itms)) {
                    foreach ($itms as $value) {
                        $eUser = $value['user'] ?? false;
                        if (in_array($eUser, $users)) {
                            $dUsers[] = $eUser;
                        }
                    }
                } else {
                    $dUsers[] = $f['userId'];
                }

                $res->MoveNext();
            }
        }

        return array_values(array_unique($dUsers));
    }

    /**
     * sessionsList (load.php L2262): paquetes de sesiones comprados por un cliente
     * (items con itemSessions > 0) y sus sesiones agendadas (type 13, packageId).
     *
     * Read-only. Fija el SQL injection del legacy (customerId/companyId/dates
     * concatenados). El limit que envía buildList se ignora — el legacy no aplicaba
     * LIMIT a la query principal (sólo LIMIT 50 al sub-query de sesiones).
     *
     * Retorna { date, listName?, transactionsList[] } — compatible con
     * #transactionsListTpl. `dataList` es el JSON de sesiones por paquete.
     */
    public function getSessionsList(string $companyId, string $encCustomerId, ?string $date): array
    {
        global $db, $dec, $ts;

        $customerId = dec($encCustomerId);

        $where  = 'a.customerId = ? AND a.companyId = ?';
        $params = [$customerId, $companyId];
        if ($date) {
            $where   .= ' AND a.fromDate > ? AND a.toDate < ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
        }

        $rs = $db->Execute(
            'SELECT a.transactionId, a.invoicePrefix, a.invoiceNo, b.itemSoldId, b.itemSoldDate, c.itemName, c.itemPrice
               FROM transaction a, itemSold b, item c
              WHERE ' . $where . '
                AND a.transactionId = b.transactionId
                AND b.itemId = c.itemId
                AND c.itemSessions > 0
              ORDER BY b.itemSoldDate DESC',
            $params
        );

        $out = ['date' => $date, 'transactionsList' => []];

        if ($rs && !$rs->EOF) {
            $cusData         = getContactData($customerId, 'uid');
            $out['listName'] = 'Sesiones de ' . toUTF8(iftn($cusData['secondName'], $cusData['name']));

            while (!$rs->EOF) {
                $f          = $rs->fields;
                $itemSoldId = (string) $f['itemSoldId'];

                // Sub-query de sesiones agendadas para este paquete (type 13).
                $sessions = [];
                $sRs      = $db->Execute(
                    'SELECT * FROM transaction
                      WHERE transactionType = 13 AND customerId = ? AND companyId = ? AND packageId = ?
                      LIMIT 50',
                    [$customerId, $companyId, $itemSoldId]
                );
                if ($sRs && !$sRs->EOF) {
                    while (!$sRs->EOF) {
                        $sf = $sRs->fields;
                        [$dateS, $startH, $endH] = dateStartEndTime($sf['fromDate'], $sf['toDate']);
                        $sessions[] = [
                            'id'         => enc($sf['transactionId']),
                            'status'     => $sf['transactionStatus'],
                            'startH'     => counts($startH) > 2 ? $startH : '',
                            'endH'       => counts($endH) > 2 ? $endH : '',
                            'date'       => $dateS,
                            'userId'     => enc($sf['userId']),
                            'customerId' => enc($sf['customerId']),
                            'session'    => $sf['invoiceNo'],
                            'prefix'     => $sf['invoicePrefix'],
                        ];
                        $sRs->MoveNext();
                    }
                }

                $out['transactionsList'][] = [
                    'id'        => enc($f['transactionId']),
                    'title'     => $f['itemName'],
                    'date'      => niceDate($f['itemSoldDate']),
                    'docNumber' => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                    'amount'    => formatCurrentNumber($f['itemPrice'], $dec, $ts),
                    'label'     => '<span class="label bg-primary text-xs">Sesiones</span>',
                    'type'      => 13,
                    'dataList'  => json_encode($sessions),
                ];

                $rs->MoveNext();
            }
        }

        return $out;
    }

    /**
     * agendaList (load.php L2264): citas/turnos (type 13) de un cliente o del outlet,
     * con fromDate/toDate definidos y status != 7 (no anuladas).
     *
     * Read-only. Fija el SQL injection del legacy (customerId/companyId/outletId/dates
     * concatenados). `transactionDetails` se lee de `meta` (jsonb, §22.6) vía
     * txDetailsFromMeta — el legacy aún leía la columna `transactionDetails` (rota en PG).
     *
     * Retorna { date, listName, transactionsList[], footBtn } — compatible con
     * #transactionsListTpl. `details` es el array de items para txtDetails del front.
     */
    public function getAgendaList(string $companyId, string $outletId, ?string $encCustomerId, ?string $date, int $limit): array
    {
        global $db;

        $where  = [];
        $params = [];
        if ($encCustomerId) {
            $where[]  = 'customerId = ?';
            $params[] = dec($encCustomerId);
        } else {
            $where[]  = 'outletId = ?';
            $params[] = $outletId;
        }
        $where[] = 'transactionType = 13';
        if ($date) {
            $where[]  = 'fromDate BETWEEN ? AND ?';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 23:59:59';
            $limit    = 1500;
        }
        $where[]  = 'fromDate IS NOT NULL';
        $where[]  = 'toDate IS NOT NULL';
        $where[]  = 'companyId = ?';
        $params[] = $companyId;
        $where[]  = 'transactionStatus != 7';

        $rs = $db->Execute(
            'SELECT * FROM transaction WHERE ' . implode(' AND ', $where)
            . ' ORDER BY fromDate DESC LIMIT ' . (int) $limit,
            $params
        );

        $out = ['date' => $date, 'listName' => 'Agenda', 'transactionsList' => [], 'footBtn' => ''];

        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $f      = $rs->fields;
                $status = (string) ($f['transactionStatus'] ?? '');

                $cusData    = getCustomerData($f['customerId'], 'uid');
                $cusName    = iftn(getCustomerName($cusData), 'Sin Nombre');
                $typeOfSale = '<span class="label bg-primary text-xs">Agenda</span>';
                $details    = txDetailsFromMeta(json_decode($f['meta'] ?? '{}', true) ?: []);

                [$scDate, $scStart, $scEnd] = dateStartEndTime($f['fromDate'], $f['toDate']);
                $timeFrame = $scStart . ' - ' . $scEnd;

                $stat = 'b-5x b-l ';
                $icon = '';
                switch ($status) {
                    case '0': $stat .= ($f['transactionComplete'] == 1) ? 'b-primary' : 'b-light'; break;
                    case '1': $stat .= 'b-info';    break;
                    case '2': $stat .= 'b-warning'; break;
                    case '3': $stat .= 'b-success'; break;
                    case '4': $stat .= 'b-danger';  break;
                    case '5': $stat .= 'b-dark';    break;
                    case '6': $stat  = ''; $icon = '<i class="material-icons text-success m-r">check</i>'; break;
                }

                $txName = (string) ($f['transactionName'] ?? '');
                $name   = ($txName !== 'Sale' && $txName !== 'Quote' && $txName !== '')
                    ? '<span class="text-info">' . $txName . '</span>'
                    : '';

                $out['transactionsList'][] = [
                    'id'          => enc($f['transactionId']),
                    'title'       => $name . ' ' . $cusName,
                    'date'        => niceDate($f['fromDate']),
                    'docNumber'   => ' #' . $f['invoicePrefix'] . $f['invoiceNo'],
                    'amount'      => $icon . $timeFrame,
                    'label'       => $typeOfSale,
                    'type'        => $f['transactionType'],
                    'borderColor' => $stat,
                    'details'     => $details,
                ];

                $rs->MoveNext();
            }
        }

        // footBtn: legacy mostraba "Cargar más" para cualquier resultado sin fecha
        // (el recordset es truthy aun con 0 filas); "Atrás" sólo con fecha o query fallida.
        $cuid = $encCustomerId ?? '';
        if ($rs !== false && !$date) {
            $out['footBtn'] = '<a href="#openAgenda&l=' . ($limit + 50) . '&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Cargar más</a>';
        } else {
            $out['footBtn'] = '<a href="#openAgenda&c=' . $cuid . '" class="btn btn-info btn-lg btn-rounded all-shadows hide-basic font-bold text-u-c navigate">Atrás</a>';
        }

        return $out;
    }

    // ========================================================================
    // Slice 41 — cluster Calendario (strangler de load.php?load=calendar_*).
    // ========================================================================

    /**
     * Slots de calendario para vistas resources/week (load.php?load=calendar_resources_json
     * y calendar_week_json — mismo bloque legacy con distinto rango de fechas).
     *
     * Modo `resources` (default): vista de un solo día. Usa $date.
     * Modo `week`: vista de 7 días. $weekRange = "YYYY-MM-DD|YYYY-MM-DD".
     *
     * Retorna array de slots con el shape que consume ncmCalendar:
     *   { userId, responsibleId, icon, color, customerId, customerUnd, start, end,
     *     items, total, id, blocked, note, status, details }
     *
     * $resource: filtro opcional a un solo user del staff.
     *
     * Mejora vs legacy: IN ($csv de UUIDs interpolados) → IN (?,?,?,...) bindeados.
     * Paridad preservada en condición legacy `outletId < 1 OR outletId = ?` (contactos
     * "globales" sin outlet asignado — semánticamente raros en PG con UUIDs, pero el
     * legacy lo emitía tal cual).
     */
    public function getCalendarSlots(
        string  $mode,
        string  $date,
        ?string $weekRange,
        ?string $resource,
        string  $companyId,
        string  $outletId
    ): array {
        global $dec, $ts; // separators de locale cargados por data.php (mismo uso que el legacy).

        if ($mode === 'week' && $weekRange !== null && $weekRange !== '' && str_contains($weekRange, '|')) {
            [$startDay, $endDay] = explode('|', $weekRange, 2);
            $startDate = $startDay . ' 00:00:00';
            $endDate   = $endDay   . ' 23:59:59';
        } else {
            $startDate = $date . ' 00:00:00';
            $endDate   = $date . ' 23:59:59';
        }

        $userFilter = '';
        $userParams = [];
        if ($resource !== null && $resource !== '') {
            $userFilter   = ' AND contactId = ?';
            $userParams[] = $resource;
        }

        // `outletId < 1` es rama legacy (int < 1 indicaba "user global, sin outlet").
        // En PG con UUIDs nunca matchea — se mantiene literal por paridad histórica.
        $users = ncmExecute(
            "SELECT STRING_AGG(contactId::text, ',') AS users
               FROM contact
              WHERE type = 0
                AND (outletId < 1 OR outletId = ?)
                AND companyId = ?" . $userFilter . "
              ORDER BY contactCalendarPosition ASC
              LIMIT 100",
            array_merge([$outletId, $companyId], $userParams),
            true
        );

        if (!$users || empty($users['users'])) {
            return [];
        }

        $userIds = array_values(array_filter(array_map('trim', explode(',', $users['users']))));
        if (!$userIds) {
            return [];
        }
        $inPlaceholders = implode(',', array_fill(0, count($userIds), '?'));

        $sql = "SELECT * FROM transaction
                 WHERE transactionType   = 13
                   AND transactionStatus NOT IN (4, 5)
                   AND userId IN ($inPlaceholders)
                   AND fromDate > ?
                   AND toDate   < ?
                   AND outletId  = ?
                   AND companyId = ?
                 LIMIT 500";
        $params = array_merge($userIds, [$startDate, $endDate, $outletId, $companyId]);

        $rs = ncmExecute($sql, $params, false, true);
        if (!$rs) {
            return [];
        }

        $iconMap  = ['0'=>'stars', '1'=>'thumb_up', '2'=>'keyboard_arrow_down', '3'=>'keyboard_arrow_right', '4'=>'block', '5'=>'person_add_disabled', '6'=>'check', '7'=>'block'];
        $colorMap = ['0'=>'dark',  '1'=>'info',     '2'=>'warning',             '3'=>'success',             '4'=>'dark',  '5'=>'danger',              '6'=>'dark',  '7'=>'dark'];

        $out = [];
        while (!$rs->EOF) {
            $f      = $rs->fields;
            $status = (string) ($f['transactionStatus'] ?? '0');

            $details = $f['transactionDetails'] ?? null;
            if (is_string($details) && $details !== '') {
                $details = json_decode($details, true);
            }
            if (!is_array($details)) {
                $details = [];
            }

            $out[] = [
                'userId'        => $f['userId']        ?? '',
                'responsibleId' => $f['responsibleId'] ?? '',
                'icon'          => $iconMap[$status]   ?? 'stars',
                'color'         => $colorMap[$status]  ?? 'dark',
                'customerId'    => $f['customerId']    ?? '',
                'customerUnd'   => $f['customerId']    ?? '',
                'start'         => $f['fromDate']      ?? null,
                'end'           => $f['toDate']        ?? null,
                'items'         => '',
                'total'         => (defined('CURRENCY') ? CURRENCY : '') . formatCurrentNumber((float) ($f['transactionTotal'] ?? 0), $dec ?? '', $ts ?? ''),
                'id'            => $f['transactionId'],
                'blocked'       => $status === '7',
                'note'          => $f['transactionNote'] ?? '',
                'status'        => $status,
                'details'       => $details,
            ];
            $rs->MoveNext();
        }
        $rs->Close();

        return $out;
    }

    /**
     * Agenda mensual: citas (type=13) del mes que contiene $date, agrupadas por día.
     * Retorna: [ { date: "<niceDate>", schedules: [{id, hour, name}, ...] }, ... ]
     *
     * Paridad con load.php?load=calendar_agenda_json. Mejora: JOIN a contact en vez del
     * N+1 SELECT del legacy. Filtra status=7 acá (legacy lo filtraba en el loop de output).
     */
    public function getCalendarAgenda(string $date, string $companyId, string $outletId): array
    {
        $startMonth = date('Y-m-d 00:00:00', strtotime('first day of this month', strtotime($date)));
        $endMonth   = date('Y-m-d 00:00:00', strtotime('last day of this month',  strtotime($date)));

        $rs = ncmExecute(
            "SELECT t.transactionId, t.transactionStatus, t.fromDate,
                    COALESCE(NULLIF(c.data->>'contactSecondName',''), c.contactName) AS customerName
               FROM transaction t
          LEFT JOIN contact c ON c.contactId = t.customerId AND c.companyId = t.companyId
              WHERE t.transactionType   = 13
                AND t.transactionStatus NOT IN (4, 5, 7)
                AND t.fromDate > ?
                AND t.toDate   < ?
                AND t.outletId  = ?
                AND t.companyId = ?
              ORDER BY t.fromDate ASC",
            [$startMonth, $endMonth, $outletId, $companyId],
            false,
            true
        );

        if (!$rs) {
            return [];
        }

        $group = [];
        while (!$rs->EOF) {
            $f   = $rs->fields;
            $day = niceDate($f['fromDate']);
            $group[$day][] = [
                'id'   => $f['transactionId'],
                'hour' => date('H:i', strtotime($f['fromDate'])),
                'name' => $f['customerName'] ?? '',
            ];
            $rs->MoveNext();
        }
        $rs->Close();

        $out = [];
        foreach ($group as $day => $rows) {
            $out[] = ['date' => $day, 'schedules' => $rows];
        }
        return $out;
    }

    /**
     * Counts de citas (type=13) por día para el mes de $date. Retorna { "YYYY-MM-DD" => int }.
     * El HTML del calendario mensual lo arma el endpoint a partir de estos counts.
     * Paridad con load.php?load=calendar_month (data layer).
     */
    public function getCalendarMonthCounts(string $date, string $companyId, string $outletId): array
    {
        $startDate = date('Y-m-d 00:00:00', strtotime('first day of this month', strtotime($date)));
        $endDate   = date('Y-m-d 23:59:59', strtotime('last day of this month',  strtotime($date)));

        $rs = ncmExecute(
            "SELECT DATE(fromDate) AS d, COUNT(transactionId) AS c
               FROM transaction
              WHERE transactionType   = 13
                AND transactionStatus NOT IN (4, 5)
                AND fromDate > ?
                AND toDate   < ?
                AND outletId  = ?
                AND companyId = ?
              GROUP BY d",
            [$startDate, $endDate, $outletId, $companyId],
            false,
            true
        );

        $counts = [];
        if ($rs) {
            while (!$rs->EOF) {
                $counts[(string) $rs->fields['d']] = (int) $rs->fields['c'];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $counts;
    }
}
