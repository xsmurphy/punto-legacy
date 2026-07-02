<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de movimientos de Finanzas (`fin_movement`) — el ledger de caja simple
 * (single-entry, NO partida doble). Actualiza `fin_account.currentbalance`
 * transaccionalmente en cada insert/void.
 *
 * Idempotencia: el UNIQUE (companyid, source, sourceid) WHERE sourceid IS NOT
 * NULL evita duplicar movimientos derivados (Fase 3). Las cargas manuales no
 * traen sourceid → no aplica el UNIQUE.
 *
 * Multi-tenant: $companyId siempre explícito (§33.2).
 */
final class MovementService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const KINDS = ['income', 'expense'];

    /**
     * Lista movimientos con filtros + paginación.
     * @param array{accountId?:string,categoryId?:string,kind?:string,from?:string,to?:string,q?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'm.companyid = ? AND m.status = 1';
        $params = [$companyId];

        if (!empty($filters['accountId']) && preg_match(self::UUID_RE, $filters['accountId'])) {
            $where   .= ' AND m.accountid = ?';
            $params[] = $filters['accountId'];
        }
        if (!empty($filters['categoryId']) && preg_match(self::UUID_RE, $filters['categoryId'])) {
            $where   .= ' AND m.categoryid = ?';
            $params[] = $filters['categoryId'];
        }
        if (!empty($filters['kind']) && in_array($filters['kind'], self::KINDS, true)) {
            $where   .= ' AND m.kind = ?';
            $params[] = $filters['kind'];
        }
        if (!empty($filters['from'])) {
            $where   .= ' AND m.date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where   .= ' AND m.date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where   .= ' AND m.description ILIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit']))  : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM fin_movement m WHERE {$where}", $params);
        $total = (int) ($totalRow['n'] ?? 0);

        $sql = "SELECT m.*, a.name AS accountname, c.name AS categoryname
                  FROM fin_movement m
                  LEFT JOIN fin_account a ON a.accountid = m.accountid
                  LEFT JOIN fin_category c ON c.categoryid = m.categoryid
                 WHERE {$where}
                 ORDER BY m.date DESC, m.created_at DESC
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
            "SELECT m.*, a.name AS accountname, c.name AS categoryname
               FROM fin_movement m
               LEFT JOIN fin_account a ON a.accountid = m.accountid
               LEFT JOIN fin_category c ON c.categoryid = m.categoryid
              WHERE m.movementid = ? AND m.companyid = ? LIMIT 1",
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /**
     * Crea un movimiento manual (entrada o salida). Actualiza el saldo de la
     * cuenta en la misma transacción.
     *
     * @param array{accountId:string,categoryId?:string|null,kind:string,amount:float|string,date?:string,description?:string,paymentMethod?:string,userId?:string,outletId?:string} $data
     */
    public function create(string $companyId, array $data): array
    {
        global $db;

        $accountId = (string) ($data['accountId'] ?? '');
        if (!preg_match(self::UUID_RE, $accountId)) {
            throw new \RuntimeException('accountId requerido y debe ser UUID');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('kind debe ser income o expense');
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto debe ser mayor a cero');
        }
        $categoryId = (string) ($data['categoryId'] ?? '');
        $categoryId = ($categoryId !== '' && preg_match(self::UUID_RE, $categoryId)) ? $categoryId : null;

        $account = (new AccountService())->find($accountId, $companyId);
        if (!$account) {
            throw new \RuntimeException('Cuenta no encontrada');
        }

        $db->StartTrans();

        $movementId = $this->insertMovement($companyId, [
            'accountid'     => $accountId,
            'categoryid'    => $categoryId,
            'kind'          => $kind,
            'amount'        => $amount,
            'date'          => $this->normalizeDate($data['date'] ?? null),
            'description'   => (string) ($data['description'] ?? '') ?: null,
            'paymentmethod' => (string) ($data['paymentMethod'] ?? '') ?: null,
            'source'        => 'manual',
            'userid'        => (string) ($data['userId'] ?? '') ?: null,
            'outletid'      => (string) ($data['outletId'] ?? '') ?: null,
        ]);

        $this->applyBalanceDelta($accountId, $companyId, $kind, $amount);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed || !$movementId) {
            throw new \RuntimeException('No se pudo registrar el movimiento');
        }

        $row = $this->find((string) $movementId, $companyId);
        if (!$row) {
            throw new \RuntimeException('Movimiento creado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Transferencia entre cuentas propias: 2 movimientos (egreso origen +
     * ingreso destino) con el mismo transfergroupid. Categoría null (una
     * transferencia no es ingreso/egreso real del negocio).
     *
     * @param array{fromAccountId:string,toAccountId:string,amount:float|string,date?:string,description?:string,userId?:string,outletId?:string} $data
     */
    public function transfer(string $companyId, array $data): array
    {
        global $db;

        $fromId = (string) ($data['fromAccountId'] ?? '');
        $toId   = (string) ($data['toAccountId'] ?? '');
        if (!preg_match(self::UUID_RE, $fromId) || !preg_match(self::UUID_RE, $toId)) {
            throw new \RuntimeException('fromAccountId y toAccountId requeridos');
        }
        if ($fromId === $toId) {
            throw new \RuntimeException('La cuenta de origen y destino no pueden ser la misma');
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto debe ser mayor a cero');
        }

        $accSvc = new AccountService();
        if (!$accSvc->find($fromId, $companyId) || !$accSvc->find($toId, $companyId)) {
            throw new \RuntimeException('Cuenta de origen o destino no encontrada');
        }

        $date        = $this->normalizeDate($data['date'] ?? null);
        $description = (string) ($data['description'] ?? '') ?: null;
        $userId      = (string) ($data['userId'] ?? '') ?: null;
        $outletId    = (string) ($data['outletId'] ?? '') ?: null;
        $groupId     = $this->uuidV4();

        $db->StartTrans();

        $this->insertMovement($companyId, [
            'accountid'       => $fromId,
            'kind'            => 'expense',
            'amount'          => $amount,
            'date'            => $date,
            'description'     => $description ?? 'Transferencia entre cuentas',
            'source'          => 'transfer',
            'transfergroupid' => $groupId,
            'userid'          => $userId,
            'outletid'        => $outletId,
        ]);
        $this->applyBalanceDelta($fromId, $companyId, 'expense', $amount);

        $this->insertMovement($companyId, [
            'accountid'       => $toId,
            'kind'            => 'income',
            'amount'          => $amount,
            'date'            => $date,
            'description'     => $description ?? 'Transferencia entre cuentas',
            'source'          => 'transfer',
            'transfergroupid' => $groupId,
            'userid'          => $userId,
            'outletid'        => $outletId,
        ]);
        $this->applyBalanceDelta($toId, $companyId, 'income', $amount);

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo registrar la transferencia');
        }

        $rs = ncmExecute(
            "SELECT m.*, a.name AS accountname, c.name AS categoryname
               FROM fin_movement m
               LEFT JOIN fin_account a ON a.accountid = m.accountid
               LEFT JOIN fin_category c ON c.categoryid = m.categoryid
              WHERE m.transfergroupid = ? AND m.companyid = ?
              ORDER BY m.kind ASC",
            [$groupId, $companyId],
            false,
            true
        );
        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $rows[] = $this->shape($rs->fields);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return ['transferGroupId' => $groupId, 'movements' => $rows];
    }

    /**
     * Anula un movimiento manual (soft-void: status=0) y revierte el saldo.
     * Movimientos derivados (source != 'manual'/'transfer') no se pueden
     * anular desde acá — se anulan desde el módulo de origen (Fase 3).
     *
     * Transferencias (source='transfer'): anula AMBOS legs del mismo
     * transfergroupid en la misma transacción — anular solo uno dejaría un
     * movimiento "fantasma" visible y las dos cuentas con saldos
     * inconsistentes (bug real detectado en code review, fix antes de
     * exponer "anular" en la UI de transferencias).
     */
    public function void(string $id, string $companyId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $row = ncmExecute(
            'SELECT * FROM fin_movement WHERE movementid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Movimiento no encontrado');
        }
        if ((int) $row['status'] !== 1) {
            throw new \RuntimeException('El movimiento ya fue anulado');
        }
        $source = (string) $row['source'];
        if (!in_array($source, ['manual', 'transfer'], true)) {
            throw new \RuntimeException('Este movimiento se generó automáticamente — anulalo desde su origen');
        }

        // Transferencia: recolectar AMBOS legs del transfergroupid (activo=status 1).
        $legs = [$row];
        if ($source === 'transfer' && !empty($row['transfergroupid'])) {
            $rs = ncmExecute(
                'SELECT * FROM fin_movement WHERE transfergroupid = ? AND companyid = ? AND status = 1',
                [(string) $row['transfergroupid'], $companyId],
                false,
                true
            );
            $legs = [];
            if ($rs && is_object($rs)) {
                while (!$rs->EOF) {
                    $legs[] = $rs->fields;
                    $rs->MoveNext();
                }
                $rs->Close();
            }
            if (empty($legs)) {
                $legs = [$row];
            }
        }

        $db->StartTrans();
        foreach ($legs as $leg) {
            $legId        = (string) $leg['movementid'];
            $legAccountId = (string) $leg['accountid'];
            $legKind      = (string) $leg['kind'];
            $legAmount    = (float) $leg['amount'];
            $reverseKind  = $legKind === 'income' ? 'expense' : 'income';

            ncmExecute('UPDATE fin_movement SET status = 0 WHERE movementid = ? AND companyid = ?', [$legId, $companyId]);
            $this->applyBalanceDelta($legAccountId, $companyId, $reverseKind, $legAmount);
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo anular el movimiento');
        }

        return ['id' => $id, 'status' => 0];
    }

    // ── helpers internos ─────────────────────────────────────────────────

    private function insertMovement(string $companyId, array $fields)
    {
        return ncmInsert([
            'records' => array_merge(['companyid' => $companyId, 'status' => 1], $fields),
            'table'   => 'fin_movement',
        ]);
    }

    /** Suma (income) o resta (expense) el monto del cache currentbalance. */
    private function applyBalanceDelta(string $accountId, string $companyId, string $kind, float $amount): void
    {
        $sign = $kind === 'income' ? '+' : '-';
        ncmExecute(
            "UPDATE fin_account SET currentbalance = currentbalance {$sign} ? WHERE accountid = ? AND companyid = ?",
            [$amount, $accountId, $companyId]
        );
    }

    private function normalizeDate(?string $val): string
    {
        $val = $val !== null ? trim($val) : '';
        if ($val === '') {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return $val . ' 00:00:00';
        }
        return $val;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function shape($f): array
    {
        return [
            'id'               => (string) $f['movementid'],
            'accountId'        => (string) $f['accountid'],
            'accountName'      => $f['accountname'] !== null ? (string) $f['accountname'] : null,
            'categoryId'       => $f['categoryid'] !== null ? (string) $f['categoryid'] : null,
            'categoryName'     => $f['categoryname'] !== null ? (string) $f['categoryname'] : null,
            'kind'             => (string) $f['kind'],
            'amount'           => (float) $f['amount'],
            'date'             => (string) $f['date'],
            'description'      => $f['description'] !== null ? (string) $f['description'] : null,
            'paymentMethod'    => $f['paymentmethod'] !== null ? (string) $f['paymentmethod'] : null,
            'source'           => (string) $f['source'],
            'sourceId'         => $f['sourceid'] !== null ? (string) $f['sourceid'] : null,
            'transferGroupId'  => $f['transfergroupid'] !== null ? (string) $f['transfergroupid'] : null,
            'reconciled'       => (bool) $f['reconciled'],
            'status'           => (int) $f['status'],
        ];
    }
}
