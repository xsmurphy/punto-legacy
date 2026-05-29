<?php
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

class ScheduleService
{
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
}
