<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

use Punto\Api\Support\TenantClock;

/**
 * Sesiones de conciliación bancaria (`fin_reconciliation`) — comparar el
 * saldo del extracto bancario contra los movimientos de una cuenta,
 * marcándolos como conciliados (`fin_movement.reconciled`).
 *
 * `reconciled` es SOLO un flag de bookkeeping — nunca toca
 * `fin_account.currentbalance` (eso lo gestiona exclusivamente
 * MovementService).
 *
 * Multi-tenant: $companyId siempre explícito.
 */
final class ReconciliationService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array{accountId?:string,status?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'r.companyid = ?';
        $params = [$companyId];

        if (!empty($filters['accountId']) && preg_match(self::UUID_RE, $filters['accountId'])) {
            $where   .= ' AND r.accountid = ?';
            $params[] = $filters['accountId'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['open', 'closed'], true)) {
            $where   .= ' AND r.status = ?';
            $params[] = $filters['status'];
        }

        $limit  = isset($filters['limit'])  ? max(1, min(200, (int) $filters['limit']))  : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM fin_reconciliation r WHERE {$where}", $params);
        $total = (int) ($totalRow['n'] ?? 0);

        $sql = "SELECT r.*, a.name AS accountname
                  FROM fin_reconciliation r
                  LEFT JOIN fin_account a ON a.accountid = r.accountid
                 WHERE {$where}
                 ORDER BY r.created_at DESC
                 LIMIT {$limit} OFFSET {$offset}";

        $rs = ncmExecute($sql, $params, false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return ['rows' => $rows, 'total' => $total];
    }

    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute(
            "SELECT r.*, a.name AS accountname
               FROM fin_reconciliation r
               LEFT JOIN fin_account a ON a.accountid = r.accountid
              WHERE r.reconciliationid = ? AND r.companyid = ? LIMIT 1",
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /**
     * Detalle de una sesión: la sesión + los movimientos activos de la
     * cuenta (tildados primero: todos los reconciled=true de esta sesión, o
     * candidatos activos sin conciliar todavía) + saldo conciliado y diff.
     */
    public function detail(string $id, string $companyId): array
    {
        $session = $this->find($id, $companyId);
        if (!$session) {
            throw new \RuntimeException('Sesión de conciliación no encontrada');
        }

        $rs = ncmExecute(
            "SELECT m.*, c.name AS categoryname
               FROM fin_movement m
               LEFT JOIN fin_category c ON c.categoryid = m.categoryid
              WHERE m.companyid = ? AND m.accountid = ? AND m.status = 1
                AND (m.reconciliationid = ? OR m.reconciled = false)
              ORDER BY m.date ASC, m.created_at ASC",
            [$companyId, $session['accountId'], $id],
            false,
            true
        );
        $movements = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $movements[] = $this->shapeMovement($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }

        $reconciledBalance = $this->reconciledBalance($id, $companyId);
        $difference = round($session['statementBalance'] - $reconciledBalance, 2);

        return [
            'session'           => $session,
            'movements'         => $movements,
            'reconciledBalance' => $reconciledBalance,
            'difference'        => $difference,
        ];
    }

    /**
     * @param array{accountId:string,statementDate:string,statementBalance:float|string} $data
     */
    public function create(string $companyId, array $data, ?string $userId = null): array
    {
        $accountId = (string) ($data['accountId'] ?? '');
        if (!preg_match(self::UUID_RE, $accountId)) {
            throw new \RuntimeException('accountId requerido');
        }
        if (!(new AccountService())->find($accountId, $companyId)) {
            throw new \RuntimeException('Cuenta no encontrada');
        }
        $statementDate = trim((string) ($data['statementDate'] ?? ''));
        if ($statementDate === '') {
            throw new \RuntimeException('La fecha del extracto es requerida');
        }
        $statementBalance = (float) ($data['statementBalance'] ?? 0);

        // Solo puede haber una sesión abierta por cuenta a la vez — evita
        // tildar movimientos contra dos extractos distintos en simultáneo.
        $openRow = ncmExecute(
            "SELECT reconciliationid FROM fin_reconciliation WHERE companyid = ? AND accountid = ? AND status = 'open' LIMIT 1",
            [$companyId, $accountId]
        );
        if ($openRow) {
            throw new \RuntimeException('Ya hay una conciliación abierta para esta cuenta. Cerrala o cancelala antes de crear otra.');
        }

        $id = ncmInsert([
            'records' => [
                'companyid'        => $companyId,
                'accountid'        => $accountId,
                'statementdate'    => $this->normalizeDate($statementDate, $companyId),
                'statementbalance' => $statementBalance,
                'status'           => 'open',
                'userid'           => $userId,
            ],
            'table' => 'fin_reconciliation',
        ]);
        if (!$id) {
            throw new \RuntimeException('No se pudo crear la sesión de conciliación');
        }

        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Sesión creada pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Tilda/destilda un movimiento dentro de una sesión abierta.
     * Actualiza SOLO reconciled/reconciliationid/reconciled_at — nunca toca
     * currentbalance (independiente del saldo de cuenta).
     */
    public function toggleMovement(string $sessionId, string $companyId, string $movementId, bool $reconciled): array
    {
        if (!preg_match(self::UUID_RE, $sessionId) || !preg_match(self::UUID_RE, $movementId)) {
            throw new \RuntimeException('id inválido');
        }
        $session = $this->find($sessionId, $companyId);
        if (!$session) {
            throw new \RuntimeException('Sesión de conciliación no encontrada');
        }
        if ($session['status'] !== 'open') {
            throw new \RuntimeException('La sesión ya está cerrada');
        }

        $movement = ncmExecute(
            'SELECT movementid, accountid FROM fin_movement WHERE movementid = ? AND companyid = ? AND status = 1 LIMIT 1',
            [$movementId, $companyId]
        );
        if (!$movement) {
            throw new \RuntimeException('Movimiento no encontrado');
        }
        if ((string) $movement['accountid'] !== $session['accountId']) {
            throw new \RuntimeException('El movimiento no pertenece a la cuenta de esta conciliación');
        }

        if ($reconciled) {
            ncmExecute(
                'UPDATE fin_movement SET reconciled = true, reconciliationid = ?, reconciled_at = ? WHERE movementid = ? AND companyid = ?',
                [$sessionId, TenantClock::now($companyId), $movementId, $companyId]
            );
        } else {
            ncmExecute(
                'UPDATE fin_movement SET reconciled = false, reconciliationid = NULL, reconciled_at = NULL WHERE movementid = ? AND companyid = ? AND reconciliationid = ?',
                [$movementId, $companyId, $sessionId]
            );
        }

        return $this->detail($sessionId, $companyId);
    }

    /**
     * Cierra la sesión. Si difference != 0 y $createAdjustment=false → error.
     * Si $createAdjustment=true, inserta un fin_movement de ajuste (source=
     * 'adjustment') por el monto de la diferencia, lo tilda, y recién ahí
     * cierra.
     */
    public function close(string $sessionId, string $companyId, bool $createAdjustment = false, ?string $userId = null, ?string $adjustmentCategoryId = null): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $sessionId)) {
            throw new \RuntimeException('id inválido');
        }
        $session = $this->find($sessionId, $companyId);
        if (!$session) {
            throw new \RuntimeException('Sesión de conciliación no encontrada');
        }
        if ($session['status'] !== 'open') {
            throw new \RuntimeException('La sesión ya está cerrada');
        }

        $reconciledBalance = $this->reconciledBalance($sessionId, $companyId);
        $difference = round($session['statementBalance'] - $reconciledBalance, 2);

        $db->StartTrans();

        if (abs($difference) > 0.005) {
            if (!$createAdjustment) {
                $db->CompleteTrans();
                // number_format() a secas usa los separadores por default de
                // PHP (formato en-US: "1,234.56"), que no son los de ningún
                // tenant en particular. Money::formatNumber respeta la config
                // del comercio.
                throw new \RuntimeException('Hay una diferencia de ' . \Punto\App\Domain\Money::formatNumber($difference) . '. Conciliá los movimientos faltantes o creá un ajuste.');
            }

            $kind = $difference > 0 ? 'income' : 'expense';
            $amount = abs($difference);
            // La categoría sigue siendo opcional en general, pero acá
            // SIEMPRE resolvemos un default: el ajuste es plata real (de más
            // o de menos) que entra/sale de la cuenta, no una transferencia
            // — el frontend hoy no ofrece elegir categoría en este flujo, así
            // que cae a la categoría default "Ajustes" (income/expense según
            // el signo) en vez de dejar el movimiento sin clasificar.
            $categoryId = $adjustmentCategoryId ? $this->nullableUuid($adjustmentCategoryId) : null;
            if ($categoryId === null) {
                $categoryId = (new CategoryService())->ensureAdjustmentCategoryId($companyId, $kind);
            }

            $movementId = ncmInsert([
                'records' => [
                    'companyid'        => $companyId,
                    'accountid'        => $session['accountId'],
                    'categoryid'       => $categoryId,
                    'kind'             => $kind,
                    'amount'           => $amount,
                    'date'             => TenantClock::now($companyId),
                    'description'      => 'Ajuste de conciliación bancaria',
                    'source'           => 'adjustment',
                    'sourceid'         => $sessionId,
                    'reconciliationid' => $sessionId,
                    'reconciled'       => true,
                    'reconciled_at'    => TenantClock::now($companyId),
                    'userid'           => $userId,
                    'status'           => 1,
                ],
                'table' => 'fin_movement',
            ]);
            if (!$movementId) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo crear el movimiento de ajuste');
            }

            $sign = $kind === 'income' ? '+' : '-';
            ncmExecute(
                "UPDATE fin_account SET currentbalance = currentbalance {$sign} ? WHERE accountid = ? AND companyid = ?",
                [$amount, $session['accountId'], $companyId]
            );
        }

        ncmExecute(
            "UPDATE fin_reconciliation SET status = 'closed', closed_at = ? WHERE reconciliationid = ? AND companyid = ?",
            [TenantClock::now($companyId), $sessionId, $companyId]
        );

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo cerrar la conciliación');
        }

        $row = $this->find($sessionId, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer la sesión cerrada');
        }
        return $row;
    }

    /**
     * Cancela una sesión abierta: destilda todos sus movimientos y marca la
     * sesión como cerrada sin conciliar realmente (no hay estado
     * "cancelled" en el schema — se registra en `data` para distinguirla de
     * un cierre real en reportes/auditoría).
     */
    public function cancel(string $sessionId, string $companyId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $sessionId)) {
            throw new \RuntimeException('id inválido');
        }
        $session = $this->find($sessionId, $companyId);
        if (!$session) {
            throw new \RuntimeException('Sesión de conciliación no encontrada');
        }
        if ($session['status'] !== 'open') {
            throw new \RuntimeException('Solo se pueden cancelar sesiones abiertas');
        }

        $db->StartTrans();

        ncmExecute(
            'UPDATE fin_movement SET reconciled = false, reconciliationid = NULL, reconciled_at = NULL WHERE companyid = ? AND reconciliationid = ?',
            [$companyId, $sessionId]
        );
        ncmExecute(
            "UPDATE fin_reconciliation SET status = 'closed', closed_at = ?, data = data || '{\"cancelled\":true}'::jsonb WHERE reconciliationid = ? AND companyid = ?",
            [TenantClock::now($companyId), $sessionId, $companyId]
        );

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo cancelar la sesión');
        }

        return ['id' => $sessionId, 'status' => 'closed', 'cancelled' => true];
    }

    // ── helpers internos ─────────────────────────────────────────────────

    /** Suma (income) - resta (expense) de los movimientos activos ya tildados con esta sesión. */
    private function reconciledBalance(string $sessionId, string $companyId): float
    {
        $row = ncmExecute(
            "SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE -amount END), 0) AS total
               FROM fin_movement
              WHERE companyid = ? AND reconciliationid = ? AND status = 1 AND reconciled = true",
            [$companyId, $sessionId]
        );
        return $row ? (float) $row['total'] : 0.0;
    }

    private function nullableUuid($val): ?string
    {
        $val = (string) ($val ?? '');
        return ($val !== '' && preg_match(self::UUID_RE, $val)) ? $val : null;
    }

    private function normalizeDate(?string $val, string $companyId): string
    {
        $val = $val !== null ? trim($val) : '';
        if ($val === '') {
            return TenantClock::now($companyId);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val . ' 00:00:00';
        }
        return $val;
    }

    private function shape($f): array
    {
        return [
            'id'               => (string) $f['reconciliationid'],
            'accountId'        => (string) $f['accountid'],
            'accountName'      => $f['accountname'] !== null ? (string) $f['accountname'] : null,
            'statementDate'    => (string) $f['statementdate'],
            'statementBalance' => (float) $f['statementbalance'],
            'status'           => (string) $f['status'],
            'closedAt'         => $f['closed_at'] !== null ? (string) $f['closed_at'] : null,
            'userId'           => $f['userid'] !== null ? (string) $f['userid'] : null,
        ];
    }

    private function shapeMovement($f): array
    {
        return [
            'id'            => (string) $f['movementid'],
            'categoryId'    => $f['categoryid'] !== null ? (string) $f['categoryid'] : null,
            'categoryName'  => $f['categoryname'] !== null ? (string) $f['categoryname'] : null,
            'kind'          => (string) $f['kind'],
            'amount'        => (float) $f['amount'],
            'date'          => (string) $f['date'],
            'description'   => $f['description'] !== null ? (string) $f['description'] : null,
            'source'        => (string) $f['source'],
            'reconciled'    => (bool) $f['reconciled'],
        ];
    }
}
