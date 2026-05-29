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
}
