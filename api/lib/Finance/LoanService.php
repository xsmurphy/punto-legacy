<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Créditos básicos (F2, context/30-cheques-prevision-creditos.md — plan
 * cerrado 2026-07-30). Alcance v1: total / cuotas iguales / primera fecha,
 * frecuencia mensual fija, sin interés calculado.
 *
 * `fin_loan` (el crédito) + `fin_loan_installment` (cuotas generadas al
 * crear). `payInstallment` reusa `MovementService::recordDerivedMovement` —
 * la MISMA primitiva idempotente que usa `FinanceLedger` (source=
 * 'loan_installment', sourceId=installmentId) — para no reimplementar el
 * cálculo de saldo ni la idempotencia (UNIQUE de fin_movement, mig 73).
 *
 * Multi-tenant: $companyId siempre explícito.
 */
final class LoanService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const STATUSES = ['active', 'settled', 'cancelled'];
    private const SOURCE = 'loan_installment';

    private MovementService $movements;

    public function __construct()
    {
        $this->movements = new MovementService();
    }

    /**
     * @param array{status?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'l.companyid = ?';
        $params = [$companyId];
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where   .= ' AND l.status = ?';
            $params[] = $filters['status'];
        }

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit']))  : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM fin_loan l WHERE {$where}", $params);
        $total = (int) ($totalRow['n'] ?? 0);

        // Agregados por crédito: cuotas pagadas + próximo vencimiento pendiente
        // — evita que el listado tenga que abrir cada detalle para mostrar
        // progreso.
        $sql = "SELECT l.*,
                       COALESCE(agg.paidcount, 0) AS paidcount,
                       agg.nextduedate
                  FROM fin_loan l
                  LEFT JOIN (
                    SELECT loanid,
                           COUNT(*) FILTER (WHERE status = 'paid') AS paidcount,
                           MIN(duedate) FILTER (WHERE status = 'pending') AS nextduedate
                      FROM fin_loan_installment
                     WHERE companyid = ?
                     GROUP BY loanid
                  ) agg ON agg.loanid = l.loanid
                 WHERE {$where}
                 ORDER BY l.created_at DESC
                 LIMIT {$limit} OFFSET {$offset}";

        $rs = ncmExecute($sql, array_merge([$companyId], $params), false, true);
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shapeLoan($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** Detalle con cuotas incluidas. */
    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute('SELECT * FROM fin_loan WHERE loanid = ? AND companyid = ? LIMIT 1', [$id, $companyId]);
        if (!$row) {
            return null;
        }
        $loan = $this->shapeLoan($row);
        $loan['installments'] = $this->installments($id, $companyId);
        return $loan;
    }

    /**
     * Crea el crédito + genera todas sus cuotas (cuotas iguales,
     * `round(principal/n, 2)`, ajuste de redondeo en la última).
     *
     * @param array{name:string,principal:float|string,installmentCount:int|string,firstDueDate:string} $data
     */
    public function create(string $companyId, array $data): array
    {
        global $db;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre / acreedor es requerido');
        }
        $principal = (float) ($data['principal'] ?? 0);
        if ($principal <= 0) {
            throw new \RuntimeException('El monto total debe ser mayor a cero');
        }
        $count = (int) ($data['installmentCount'] ?? 0);
        if ($count <= 0 || $count > 360) {
            throw new \RuntimeException('La cantidad de cuotas debe ser entre 1 y 360');
        }
        $firstDueDate = $this->normalizeDate($data['firstDueDate'] ?? null);
        if ($firstDueDate === null) {
            throw new \RuntimeException('La primera fecha de vencimiento es requerida');
        }

        $db->StartTrans();

        $loanId = ncmInsert([
            'records' => [
                'companyid'        => $companyId,
                'name'             => $name,
                'principal'        => $principal,
                'installmentcount' => $count,
                'firstduedate'     => $firstDueDate,
                'frequency'        => 'monthly',
                'status'           => 'active',
            ],
            'table' => 'fin_loan',
        ]);
        if (!$loanId) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear el crédito');
        }

        $base = round($principal / $count, 2);
        $accumulated = 0.0;
        $firstDue = new \DateTimeImmutable($firstDueDate);
        for ($i = 1; $i <= $count; $i++) {
            // Última cuota absorbe el resto del redondeo — nunca queda un
            // centavo suelto sin cuota que lo cubra.
            $amount = $i < $count ? $base : round($principal - $accumulated, 2);
            $accumulated += $amount;
            $installmentDue = $firstDue->modify('+' . ($i - 1) . ' months')->format('Y-m-d H:i:s');

            $ok = ncmInsert([
                'records' => [
                    'loanid'    => $loanId,
                    'companyid' => $companyId,
                    'seq'       => $i,
                    'duedate'   => $installmentDue,
                    'amount'    => $amount,
                    'status'    => 'pending',
                ],
                'table' => 'fin_loan_installment',
            ]);
            if (!$ok) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException("No se pudo crear la cuota {$i}");
            }
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo crear el crédito');
        }

        $row = $this->find((string) $loanId, $companyId);
        if (!$row) {
            throw new \RuntimeException('Crédito creado pero no se pudo releer');
        }
        return $row;
    }

    /** Anula el crédito (status='cancelled'). No revierte cuotas ya pagadas. */
    public function cancel(string $id, string $companyId): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $loan = $this->find($id, $companyId);
        if (!$loan) {
            throw new \RuntimeException('Crédito no encontrado');
        }
        if ($loan['status'] === 'cancelled') {
            return $loan; // idempotente
        }
        ncmExecute('UPDATE fin_loan SET status = ? WHERE loanid = ? AND companyid = ?', ['cancelled', $id, $companyId]);

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el crédito actualizado');
        }
        return $row;
    }

    /**
     * Marca una cuota como pagada: genera el movimiento (expense) desde la
     * cuenta indicada. Idempotente por el UNIQUE de fin_movement — reintentar
     * con la misma cuota nunca duplica el egreso. Si era la última cuota
     * pendiente del crédito, el crédito pasa a 'settled'.
     */
    public function payInstallment(
        string $installmentId,
        string $companyId,
        string $accountId,
        ?string $userId = null,
        ?string $outletId = null
    ): array {
        global $db;

        if (!preg_match(self::UUID_RE, $installmentId)) {
            throw new \RuntimeException('id de cuota inválido');
        }
        if (!preg_match(self::UUID_RE, $accountId)) {
            throw new \RuntimeException('Seleccioná una cuenta para registrar el pago');
        }
        if (!(new AccountService())->find($accountId, $companyId)) {
            throw new \RuntimeException('Cuenta no encontrada');
        }

        $db->StartTrans();

        // SELECT ... FOR UPDATE: bloquea la fila de la cuota durante toda la
        // transacción. Dos pagos concurrentes sobre la misma cuota (aunque
        // apunten a cuentas distintas) se serializan acá — el segundo espera
        // el lock, relee el status YA 'paid' que dejó el primero dentro de
        // ESTA transacción, y sale por el camino idempotente en vez de
        // generar un segundo movimiento.
        $installment = ncmExecute(
            'SELECT * FROM fin_loan_installment WHERE installmentid = ? AND companyid = ? LIMIT 1 FOR UPDATE',
            [$installmentId, $companyId]
        );
        if (!$installment) {
            $db->CompleteTrans();
            throw new \RuntimeException('Cuota no encontrada');
        }
        if ((string) $installment['status'] === 'paid') {
            $db->CompleteTrans();
            return $this->findInstallment($installmentId, $companyId); // idempotente
        }

        $loanId = (string) $installment['loanid'];
        $loanRow  = ncmExecute('SELECT name FROM fin_loan WHERE loanid = ? AND companyid = ? LIMIT 1', [$loanId, $companyId]);
        $loanName = $loanRow ? (string) $loanRow['name'] : 'Crédito';

        $result = $this->movements->recordDerivedMovement($companyId, self::SOURCE, $installmentId, [
            'accountId'   => $accountId,
            'kind'        => 'expense',
            'amount'      => (float) $installment['amount'],
            'date'        => date('Y-m-d H:i:s'),
            'description' => "Cuota {$installment['seq']} — {$loanName}",
            'userId'      => $userId,
            'outletId'    => $outletId,
        ]);

        ncmExecute(
            'UPDATE fin_loan_installment SET status = ?, paiddate = ?, movementid = ? WHERE installmentid = ? AND companyid = ?',
            ['paid', date('Y-m-d H:i:s'), $result['movementId'], $installmentId, $companyId]
        );

        $this->syncLoanStatus($loanId, $companyId);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo registrar el pago de la cuota');
        }

        return $this->findInstallment($installmentId, $companyId);
    }

    /** Revierte el pago de una cuota (se desmarca): anula el movimiento y vuelve a 'pending'. */
    public function unpayInstallment(string $installmentId, string $companyId): array
    {
        if (!preg_match(self::UUID_RE, $installmentId)) {
            throw new \RuntimeException('id de cuota inválido');
        }
        $installment = ncmExecute(
            'SELECT * FROM fin_loan_installment WHERE installmentid = ? AND companyid = ? LIMIT 1',
            [$installmentId, $companyId]
        );
        if (!$installment) {
            throw new \RuntimeException('Cuota no encontrada');
        }
        if ((string) $installment['status'] !== 'paid') {
            return $this->findInstallment($installmentId, $companyId); // idempotente
        }

        $this->movements->voidBySource($companyId, self::SOURCE, $installmentId);

        ncmExecute(
            'UPDATE fin_loan_installment SET status = ?, paiddate = NULL, movementid = NULL WHERE installmentid = ? AND companyid = ?',
            ['pending', $installmentId, $companyId]
        );

        $loanId = (string) $installment['loanid'];
        ncmExecute(
            "UPDATE fin_loan SET status = 'active' WHERE loanid = ? AND companyid = ? AND status = 'settled'",
            [$loanId, $companyId]
        );

        return $this->findInstallment($installmentId, $companyId);
    }

    // ── internos ──────────────────────────────────────────────────────────

    private function syncLoanStatus(string $loanId, string $companyId): void
    {
        $pending = ncmExecute(
            "SELECT COUNT(*) AS n FROM fin_loan_installment WHERE loanid = ? AND companyid = ? AND status = 'pending'",
            [$loanId, $companyId]
        );
        if ((int) ($pending['n'] ?? 1) === 0) {
            ncmExecute(
                "UPDATE fin_loan SET status = 'settled' WHERE loanid = ? AND companyid = ? AND status = 'active'",
                [$loanId, $companyId]
            );
        }
    }

    private function installments(string $loanId, string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT * FROM fin_loan_installment WHERE loanid = ? AND companyid = ? ORDER BY seq ASC',
            [$loanId, $companyId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shapeInstallment($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    private function findInstallment(string $id, string $companyId): array
    {
        $row = ncmExecute(
            'SELECT * FROM fin_loan_installment WHERE installmentid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Cuota no encontrada tras la operación');
        }
        return $this->shapeInstallment($row);
    }

    private function normalizeDate($val): ?string
    {
        $val = $val !== null ? trim((string) $val) : '';
        if ($val === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val . ' 00:00:00';
        }
        return $val;
    }

    private function shapeLoan($f): array
    {
        return [
            'id'               => (string) $f['loanid'],
            'name'             => (string) $f['name'],
            'principal'        => (float) $f['principal'],
            'installmentCount' => (int) $f['installmentcount'],
            'firstDueDate'     => (string) $f['firstduedate'],
            'frequency'        => (string) $f['frequency'],
            'status'           => (string) $f['status'],
            'createdAt'        => (string) $f['created_at'],
            'paidCount'        => isset($f['paidcount']) ? (int) $f['paidcount'] : null,
            'nextDueDate'      => isset($f['nextduedate']) && $f['nextduedate'] !== null ? (string) $f['nextduedate'] : null,
        ];
    }

    private function shapeInstallment($f): array
    {
        return [
            'id'         => (string) $f['installmentid'],
            'loanId'     => (string) $f['loanid'],
            'seq'        => (int) $f['seq'],
            'dueDate'    => (string) $f['duedate'],
            'amount'     => (float) $f['amount'],
            'status'     => (string) $f['status'],
            'paidDate'   => $f['paiddate'] !== null ? (string) $f['paiddate'] : null,
            'movementId' => $f['movementid'] !== null ? (string) $f['movementid'] : null,
        ];
    }
}
