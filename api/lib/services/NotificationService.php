<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
use DB;
/**
 * NotificationService — notificaciones del POS (Slice 14, cluster ENCOM→Punto).
 *
 * Lógica portada de panel/API/get_notifications.php + get_notifications_count.php (los
 * endpoints canónicos a los que app/action.php (L117/L136) hacía proxy curl — ROTO en dev).
 *
 * Tabla `notify` (lowercase). El "último visto" se guarda en contact.contactLastNotificationSeen.
 *
 * NOTA — list() ESCRIBE (marca como visto): por eso se expone vía POST (op=list), no GET.
 * No es un self-heal (§16) sino la semántica real de "marcar leído" → es una mutación
 * legítima y va en POST. count() es lectura pura.
 *
 * Quirk legacy preservado: list() NO filtra por notifyMode/type (el type se computa pero
 * el legacy nunca lo usaba en el query de la lista); count() SÍ filtra por notifyMode.
 *
 * Gotchas PG: identificadores sin comillas (§22.5), todo bindeado, scope companyId
 * agregado al UPDATE de mark-seen (el legacy no lo tenía).
 */

final class NotificationService
{
    public function __construct(
        public readonly TenantContext $ctx,
        public readonly \DB $db,
    ) {}

    /**
     * Lista las notificaciones nuevas (posteriores al último visto) y marca como visto.
     *
     * @return array<int, array{title,message,mode,date,timeago,link,type}>
     */
    public function listForUser(string $companyId, string $userId, string $outletId): array
    {
        $lastSeen = $this->lastSeen($userId);

        $rows = ncmExecute(
            "SELECT * FROM notify
              WHERE notifyStatus = 1
                AND (outletId = ? OR outletId IS NULL)
                AND notifyDate > ?
                AND (companyId = ? OR companyId IS NULL)
              ORDER BY notifyDate DESC
              LIMIT 100",
            [$outletId, $lastSeen, $companyId],
            false,
            true
        );

        $out = [];
        if ($rows) {
            while (!$rows->EOF) {
                $f     = $rows->fields;
                $out[] = [
                    'title'   => $f['notifyTitle'],
                    'message' => $f['notifyMessage'],
                    'mode'    => $f['notifyMode'],
                    'date'    => $f['notifyDate'],
                    'timeago' => timeago($f['notifyDate']),
                    'link'    => $f['notifyLink'],
                    'type'    => $f['notifyType'],
                ];
                $rows->MoveNext();
            }
        }

        // Marca como visto (mutación legítima — por eso list va en POST). Scope companyId.
        $this->db->Execute(
            'UPDATE contact SET contactLastNotificationSeen = ? WHERE contactId = ? AND companyId = ?',
            [date('Y-m-d H:i:s'), $userId, $companyId]
        );

        return $out;
    }

    /**
     * Cuenta las notificaciones nuevas (sin marcar como visto). Lectura pura.
     *
     * @return array{count:int, lastSeen:string}
     */
    public function countForUser(string $companyId, string $userId, string $outletId, string $type): array
    {
        $mode     = ($type === 'notes') ? '1' : '0';
        $lastSeen = $this->lastSeen($userId);

        $row = ncmExecute(
            "SELECT COUNT(notifyId) as count FROM notify
              WHERE notifyMode = ? AND notifyStatus = 1
                AND (outletId = ? OR outletId IS NULL)
                AND notifyDate > ?
                AND (companyId = ? OR companyId IS NULL)",
            [$mode, $outletId, $lastSeen, $companyId]
        );

        return [
            'count'    => (int) ($row['count'] ?? 0),
            'lastSeen' => $lastSeen,
        ];
    }

    /** Último visto del usuario (contact.contactLastNotificationSeen), con default legacy. */
    private function lastSeen(string $userId): string
    {
        $row = ncmExecute(
            'SELECT contactLastNotificationSeen FROM contact WHERE contactId = ?',
            [$userId]
        );
        return iftn($row['contactLastNotificationSeen'] ?? null, '2019-01-01 00:00:00');
    }
}
