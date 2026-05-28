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
 * NOTA: updateSchedule y scheduleSession NO se migran acá — reescriben
 * `transactionDetails`, columna absorbida a `meta` JSONB → requieren jsonb_set
 * (slice dedicado de agenda/órdenes). Ver context/10-roadmap.md.
 */

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
}
