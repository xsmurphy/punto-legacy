<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * AttendanceService — fichaje de asistencia del personal (Slice 13, cluster ENCOM→Punto).
 *
 * Lógica portada de panel/API/set_attendance.php (el endpoint canónico al que el handler
 * clockIn de app/action.php L65 le hacía proxy vía curl a API_ENCOM_URL). Ese proxy estaba
 * ROTO en dev (/app apuntaba a localhost:8002/API, que no tiene el archivo) → esta
 * migración lo arregla unificándolo en la /api compartida.
 *
 * Toggle de turno: si hay un turno abierto (sin attendanceCloseDate) lo cierra; si no,
 * abre uno nuevo. Tabla `attendance` (columnas reales lowercase, sin transactionDetails).
 *
 * Gotchas PG: identificadores sin comillas (§22.5), todo bindeado, scope companyId
 * agregado al UPDATE (el legacy sólo filtraba por attendanceId).
 */

final class AttendanceService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    /**
     * Alterna el fichaje del usuario en un outlet.
     *
     * @return array{error:bool, type:string} type ∈ {'open','closed'}; error=true si falló la BD.
     */
    public function toggle(string $companyId, string $outletId, string $userId): array
    {
        global $db;

        // Turno abierto = sin fecha de cierre (o cierre "centinela" pre-2000 del legacy).
        $open = ncmExecute(
            "SELECT attendanceId FROM attendance
              WHERE userId = ? AND outletId = ? AND companyId = ?
                AND (attendanceCloseDate IS NULL OR attendanceCloseDate < '2000-01-01 00:00:00')
              ORDER BY attendanceOpenDate DESC
              LIMIT 1",
            [$userId, $outletId, $companyId]
        );

        if ($open) {
            // Cerrar el turno abierto.
            $res = $db->Execute(
                'UPDATE attendance SET attendanceCloseDate = ? WHERE attendanceId = ? AND companyId = ?',
                [TODAY, $open['attendanceId'], $companyId]
            );
            return ['error' => $res === false, 'type' => 'closed'];
        }

        // Abrir un turno nuevo.
        $res = $db->Execute(
            'INSERT INTO attendance (attendanceOpenDate, userId, outletId, companyId)
             VALUES (?, ?, ?, ?)',
            [TODAY, $userId, $outletId, $companyId]
        );
        return ['error' => $res === false, 'type' => 'open'];
    }
}
