<?php
declare(strict_types=1);

namespace Punto\Api\Notifications;

use Punto\Api\Finance\ObligationsService;

/**
 * Centro de notificaciones del panel (context/31-centro-de-notificaciones.md
 * — plan cerrado 2026-07-30).
 *
 * Feed unificado, dos fuentes, SIN persistencia propia de contenido:
 *   1. Eventos: filas de `notify` del tenant (mismo scope outlet/company que
 *      el legacy POS — ver NotificationService), ventana de 30 días.
 *   2. Vencimientos derivados EN VIVO de `ObligationsService` (compartido
 *      con /v1/finance/forecast.php) — vencidas sin límite hacia atrás +
 *      próximas a vencer (≤7 días). Si la obligación se resuelve, el aviso
 *      desaparece solo en el próximo fetch.
 *
 * Lo único persistido es el ESTADO por usuario (`notification_state`,
 * mig 103), identificado por una `alertKey` determinística:
 *   - `notify:<notifyId>`         para eventos
 *   - `due:<tipo>:<id>:<duedate>` para vencimientos derivados
 *
 * Leído (readat) atenúa; descartado (dismissedat) saca el item del feed.
 * NO usa el watermark legacy del POS (contact.contactLastNotificationSeen) —
 * ese sigue siendo exclusivo de NotificationService/realm pos-app.
 */
final class FeedService
{
    private const NOTIFY_WINDOW_DAYS = 30;
    private const UPCOMING_DAYS = 7;

    /**
     * @return array{items: array<int, array<string, mixed>>, unreadCount: int}
     */
    public function feed(string $companyId, string $userId, string $outletId, bool $includeFinance): array
    {
        $items = $this->buildItems($companyId, $userId, $outletId, $includeFinance);

        $visible = array_values(array_filter($items, static fn (array $i): bool => !$i['dismissed']));
        $unreadCount = 0;
        foreach ($visible as $i) {
            if (!$i['read']) {
                $unreadCount++;
            }
        }

        usort($visible, [self::class, 'compareItems']);

        // Salida pública: sin el flag interno `dismissed` (ya filtrado).
        $out = array_map(static function (array $i): array {
            unset($i['dismissed'], $i['sortDate']);
            return $i;
        }, $visible);

        return ['items' => $out, 'unreadCount' => $unreadCount];
    }

    /** Marca como leídos los alertKeys dados (idempotente, upsert). */
    public function markRead(string $companyId, string $userId, array $alertKeys): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($alertKeys as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $this->upsertState($companyId, $userId, $key, 'readat', $now);
        }
    }

    /** Marca como descartado un item (idempotente, upsert). */
    public function markDismissed(string $companyId, string $userId, string $alertKey): void
    {
        $alertKey = trim($alertKey);
        if ($alertKey === '') {
            return;
        }
        $this->upsertState($companyId, $userId, $alertKey, 'dismissedat', date('Y-m-d H:i:s'));
    }

    /** Marca como leídos TODOS los items visibles (no descartados) hoy. */
    public function markAllRead(string $companyId, string $userId, string $outletId, bool $includeFinance): void
    {
        $items = $this->buildItems($companyId, $userId, $outletId, $includeFinance);
        $keys = [];
        foreach ($items as $i) {
            if (!$i['dismissed']) {
                $keys[] = $i['alertKey'];
            }
        }
        $this->markRead($companyId, $userId, $keys);
    }

    /**
     * Construye TODOS los items del feed (incluidos los descartados, con
     * flag `dismissed`) — usado tanto por `feed()` (filtra visibles) como
     * por `markAllRead()` (necesita el mismo universo de alertKeys).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(string $companyId, string $userId, string $outletId, bool $includeFinance): array
    {
        $today = date('Y-m-d');
        $horizon = date('Y-m-d', strtotime('+' . self::UPCOMING_DAYS . ' days'));

        $items = [];

        // ── Eventos (tabla `notify`) — mismo scope outlet/company que el
        // legacy POS (NotificationService::listForUser), ventana acotada a
        // 30 días para no acumular historial indefinido sin watermark.
        $rs = ncmExecute(
            "SELECT notifyId, notifyTitle, notifyDate, notifyMessage, notifyLink
               FROM notify
              WHERE notifyStatus = 1
                AND (outletId = ? OR outletId IS NULL)
                AND (companyId = ? OR companyId IS NULL)
                AND notifyDate >= (now() - interval '" . self::NOTIFY_WINDOW_DAYS . " days')
              ORDER BY notifyDate DESC
              LIMIT 200",
            [$outletId, $companyId],
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $date = (string) $f['notifydate'];
                $items[] = [
                    'alertKey' => 'notify:' . (string) $f['notifyid'],
                    'kind'     => 'event',
                    'title'    => (string) ($f['notifytitle'] ?? 'Notificación'),
                    'message'  => (string) ($f['notifymessage'] ?? ''),
                    'date'     => $date,
                    'dueDate'  => null,
                    'link'     => $f['notifylink'] !== null ? (string) $f['notifylink'] : null,
                    'severity' => 'info',
                    'sortDate' => $date,
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        // ── Vencimientos derivados del forecast financiero. Gateados por
        // finance.manage — mismo permiso que exige /v1/finance/forecast.php:
        // un usuario sin acceso a Finanzas no debería ver cheques/cuotas/
        // compras en su feed de notificaciones.
        if ($includeFinance) {
            $obligations = (new ObligationsService())->list($companyId, null, $horizon);
            $kindMap = [
                'check'            => 'check_due',
                'loan_installment' => 'loan_due',
                'purchase'         => 'purchase_due',
            ];
            foreach ($obligations as $o) {
                $dueDate = (string) $o['dueDate'];
                $dueDateOnly = substr($dueDate, 0, 10);
                $overdue = $dueDateOnly < $today;
                $items[] = [
                    'alertKey' => 'due:' . (string) $o['type'] . ':' . (string) $o['id'] . ':' . $dueDateOnly,
                    'kind'     => $kindMap[$o['type']] ?? 'event',
                    'title'    => (string) $o['label'],
                    'message'  => $o['party'] !== null && $o['party'] !== ''
                        ? (string) $o['party']
                        : ($overdue ? 'Vencido' : 'Próximo a vencer'),
                    'date'     => null,
                    'dueDate'  => $dueDateOnly,
                    'amount'   => (float) $o['amount'],
                    'link'     => (string) $o['link'],
                    'severity' => $overdue ? 'overdue' : 'upcoming',
                    'sortDate' => $dueDateOnly,
                ];
            }
        }

        // ── Estado por usuario (leído/descartado) — una sola query para
        // todos los alertKeys del feed, evita N+1.
        $state = $this->loadState($companyId, $userId);
        foreach ($items as &$i) {
            $s = $state[$i['alertKey']] ?? null;
            $i['read'] = $s !== null && $s['readat'] !== null;
            $i['dismissed'] = $s !== null && $s['dismissedat'] !== null;
        }
        unset($i);

        return $items;
    }

    /**
     * @return array<string, array{readat: ?string, dismissedat: ?string}>
     */
    private function loadState(string $companyId, string $userId): array
    {
        $rs = ncmExecute(
            'SELECT alertkey, readat, dismissedat FROM notification_state WHERE companyid = ? AND userid = ?',
            [$companyId, $userId],
            false,
            true
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[(string) $f['alertkey']] = [
                    'readat'      => $f['readat'] !== null ? (string) $f['readat'] : null,
                    'dismissedat' => $f['dismissedat'] !== null ? (string) $f['dismissedat'] : null,
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /** UPSERT idempotente de un solo campo (readat|dismissedat) sin pisar el otro. */
    private function upsertState(string $companyId, string $userId, string $alertKey, string $field, string $value): void
    {
        if (!in_array($field, ['readat', 'dismissedat'], true)) {
            throw new \InvalidArgumentException('Campo de estado inválido: ' . $field);
        }
        ncmExecute(
            "INSERT INTO notification_state (companyid, userid, alertkey, {$field})
             VALUES (?, ?, ?, ?)
             ON CONFLICT (companyid, userid, alertkey) DO UPDATE
               SET {$field} = EXCLUDED.{$field}",
            [$companyId, $userId, $alertKey, $value]
        );
    }

    /** Orden del feed: vencidas primero, después upcoming, después eventos; dentro de cada grupo por fecha. */
    private static function compareItems(array $a, array $b): int
    {
        $rank = ['overdue' => 0, 'upcoming' => 1, 'info' => 2];
        $ra = $rank[$a['severity']] ?? 2;
        $rb = $rank[$b['severity']] ?? 2;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        // Vencidas/upcoming: fecha ascendente (más urgente primero).
        // Eventos: fecha descendente (más reciente primero).
        if ($a['severity'] === 'info') {
            return $b['sortDate'] <=> $a['sortDate'];
        }
        return $a['sortDate'] <=> $b['sortDate'];
    }
}
