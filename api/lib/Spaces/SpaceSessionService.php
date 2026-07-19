<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

/**
 * SpaceSessionService — ciclo de vida de la ocupación de un espacio
 * (space_session, mig 80, context/15-espacios-module-plan.md F0+F1).
 *
 * NO implementa el cobro (F2) — close() solo cierra el registro contable de
 * la sesión; el flujo de facturación real (dividir cuenta, transaction) es
 * F2/F3. open()/requestBill()/cancel()/close() son las únicas transiciones
 * de esta fase.
 *
 * El índice único parcial `uq_space_session_active_per_space` (mig 80) es la
 * fuente de verdad de "una sola sesión activa por espacio" — open() confía en
 * la violación 23505 para devolver un error claro ante la carrera de dos
 * aperturas concurrentes (mismo patrón que SaleService, ver
 * api/lib/Sales/SaleService.php:172).
 */
final class SpaceSessionService
{
    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Abre una sesión sobre el espacio. Falla si el espacio ya tiene una
     * sesión open|bill_requested (índice único) o está deshabilitado.
     */
    public function open(string $companyId, string $tableId, ?int $guests = null, ?string $waiterId = null, ?string $outletScope = null): array
    {
        $table = ncmExecute(
            'SELECT tableid, outletid, status, shape FROM space WHERE tableid = ? AND companyid = ? LIMIT 1',
            [$tableId, $companyId]
        );
        if (!$table) {
            throw new \RuntimeException('Espacio no encontrado');
        }
        $outletId = (string) $table['outletid'];
        $this->assertOutletScope($outletId, $outletScope);
        if ((int) $table['status'] === 0) {
            throw new \InvalidArgumentException('El espacio está deshabilitado');
        }
        // Invariante que habilita el hard-delete de decorativos en
        // SpaceService::delete(): un decor JAMÁS tiene sesión — enforcement
        // acá en el write-path, no solo por convención de UI.
        if (in_array((string) ($table['shape'] ?? ''), SpaceService::DECOR_SHAPES, true)) {
            throw new \InvalidArgumentException('Un bloque decorativo no admite sesiones');
        }

        $rs = $this->db->Execute(
            'INSERT INTO space_session (sessionid, companyid, outletid, tableid, status, guests, waiterid)
             VALUES (gen_random_uuid(), ?, ?, ?, \'open\', ?, ?)
             RETURNING sessionid',
            [$companyId, $outletId, $tableId, $guests, $waiterId]
        );
        if ($rs === false) {
            $err = method_exists($this->db, 'ErrorMsg') ? (string) $this->db->ErrorMsg() : '';
            if (str_contains($err, '23505') || str_contains($err, 'uq_space_session_active_per_space')) {
                throw new \RuntimeException('El espacio ya tiene una sesión activa');
            }
            throw new \RuntimeException('No se pudo abrir el espacio');
        }
        $id = (string) ($rs->fields['sessionid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo abrir el espacio');
        }

        $session = $this->find($companyId, $id);
        if ($session !== null) {
            $this->publish($companyId, $outletId, $session);
        }
        return $session ?? [];
    }

    /** open → bill_requested (pidió la cuenta). */
    public function requestBill(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        $session = $this->lockSession($companyId, $sessionId, $outletScope);
        if ($session['status'] !== 'open') {
            throw new \InvalidArgumentException("Solo se puede pedir la cuenta desde open (actual: {$session['status']})");
        }
        $ok = $this->db->Execute(
            "UPDATE space_session SET status = 'bill_requested' WHERE sessionid = ? AND companyid = ? AND status = 'open'",
            [$sessionId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo pedir la cuenta');
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, (string) $session['outletid'], $result);
        }
        return $result ?? [];
    }

    /**
     * Cancela la sesión — solo si NO tiene órdenes activas (todas
     * closed/cancelled o sin órdenes). El espacio vuelve a 'free'.
     */
    public function cancel(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        $session = $this->lockSession($companyId, $sessionId, $outletScope);
        if (in_array($session['status'], ['closed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('La sesión ya está ' . $session['status']);
        }

        $activeOrders = ncmExecute(
            "SELECT COUNT(*) AS c FROM pos_order WHERE spacesessionid = ? AND status NOT IN ('closed','cancelled')",
            [$sessionId]
        );
        if ((int) ($activeOrders['c'] ?? 0) > 0) {
            throw new \InvalidArgumentException('No se puede cancelar: el espacio tiene órdenes activas');
        }

        $ok = $this->db->Execute(
            "UPDATE space_session SET status = 'cancelled', closed_at = now()
              WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
            [$sessionId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo cancelar la sesión');
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, (string) $session['outletid'], $result);
        }
        return $result ?? [];
    }

    /**
     * Cierra la sesión. `transactionId` opcional (F2 lo pasará al cobrar).
     * En F0+F1, sin flujo de cobro, se usa para cerrar espacios manualmente
     * (ej. espacio abierto por error).
     */
    public function close(string $companyId, string $sessionId, ?string $transactionId = null, ?string $outletScope = null): array
    {
        $session = $this->lockSession($companyId, $sessionId, $outletScope);
        if (in_array($session['status'], ['closed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('La sesión ya está ' . $session['status']);
        }

        $ok = $this->db->Execute(
            "UPDATE space_session SET status = 'closed', closed_at = now(), saletransactionid = COALESCE(?, saletransactionid)
              WHERE sessionid = ? AND companyid = ? AND status IN ('open','bill_requested')",
            [$transactionId, $sessionId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo cerrar la sesión');
        }
        $result = $this->find($companyId, $sessionId);
        if ($result !== null) {
            $this->publish($companyId, (string) $session['outletid'], $result);
        }
        return $result ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listByOutlet(string $companyId, string $outletId, ?string $status = null): array
    {
        $where  = ['companyid = ?', 'outletid = ?'];
        $params = [$companyId, $outletId];
        if ($status !== null && $status !== '') {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE ' . implode(' AND ', $where) . ' ORDER BY opened_at DESC LIMIT 500',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE sessionid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Lee + valida scope. No usa FOR UPDATE explícito (F0+F1 sin UI de alta concurrencia sobre una misma sesión). */
    private function lockSession(string $companyId, string $sessionId, ?string $outletScope): array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space_session WHERE sessionid = ? AND companyid = ? LIMIT 1',
            [$sessionId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('Sesión no encontrada');
        }
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        $this->assertOutletScope((string) $row['outletid'], $outletScope);
        return $row;
    }

    private function assertOutletScope(string $sessionOutletId, ?string $outletScope): void
    {
        if ($outletScope !== null && $sessionOutletId !== $outletScope) {
            // No revelar existencia cross-outlet (mismo patrón OrderCoreService).
            throw new \RuntimeException('Espacio/sesión no encontrado');
        }
    }

    private function publish(string $companyId, string $outletId, array $session): void
    {
        wsPublish($companyId . ':spaces:' . $outletId, 'space:state', [
            'outletId' => $outletId,
            'session'  => $session,
        ]);
        realtimePublish('space', 'update');
    }

    private function present(array $row): array
    {
        return [
            'id'                => (string) ($row['sessionid'] ?? ''),
            'companyId'         => (string) ($row['companyid'] ?? ''),
            'outletId'          => (string) ($row['outletid'] ?? ''),
            'spaceId'           => (string) ($row['tableid'] ?? ''),
            'status'            => (string) ($row['status'] ?? ''),
            'guests'            => isset($row['guests']) ? (int) $row['guests'] : null,
            'waiterId'          => $row['waiterid'] ?? null,
            'openedAt'          => $row['opened_at'] ?? null,
            'closedAt'          => $row['closed_at'] ?? null,
            'saleTransactionId' => $row['saletransactionid'] ?? null,
            'note'              => $row['note'] ?? null,
        ];
    }
}
