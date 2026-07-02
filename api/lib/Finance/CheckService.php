<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de cheques de Finanzas (`fin_check`) — emitidos ('issued') o recibidos
 * ('received'). Al pasar a un estado "efectivizado" genera un fin_movement
 * vía MovementService (no duplica su lógica de saldo).
 *
 * Idempotencia: el UNIQUE (companyid, source, sourceid) de fin_movement
 * (source='check', sourceid=checkid) garantiza 1 solo movimiento por cheque.
 * Si ya existe, no se vuelve a insertar. Al revertir el estado a uno no
 * efectivizado, se anula (soft-void) el movimiento asociado si existía.
 *
 * "Efectivizado":
 *   - direction='received' → status='cleared' (el cheque de un cliente se
 *     acreditó / cobró).
 *   - direction='issued'   → status='cleared' (se debitó de la cuenta al
 *     banco procesarlo). 'deposited' por sí solo NO genera movimiento
 *     (todavía no salió/entró la plata real).
 *
 * Multi-tenant: $companyId siempre explícito.
 */
final class CheckService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const DIRECTIONS = ['issued', 'received'];
    private const STATUSES = ['pending', 'deposited', 'cleared', 'bounced', 'cancelled'];
    private const SOURCE = 'check';

    /**
     * @param array{direction?:string,status?:string,from?:string,to?:string,limit?:int,offset?:int} $filters
     */
    public function list(string $companyId, array $filters = []): array
    {
        $where  = 'k.companyid = ?';
        $params = [$companyId];

        if (!empty($filters['direction']) && in_array($filters['direction'], self::DIRECTIONS, true)) {
            $where   .= ' AND k.direction = ?';
            $params[] = $filters['direction'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where   .= ' AND k.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where   .= ' AND k.duedate >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where   .= ' AND k.duedate <= ?';
            $params[] = $filters['to'];
        }

        $limit  = isset($filters['limit'])  ? max(1, min(500, (int) $filters['limit']))  : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $totalRow = ncmExecute("SELECT COUNT(*) AS n FROM fin_check k WHERE {$where}", $params);
        $total = (int) ($totalRow['n'] ?? 0);

        $sql = "SELECT k.*, a.name AS accountname, c.name AS categoryname
                  FROM fin_check k
                  LEFT JOIN fin_account a ON a.accountid = k.accountid
                  LEFT JOIN fin_category c ON c.categoryid = k.categoryid
                 WHERE {$where}
                 ORDER BY k.duedate ASC NULLS LAST, k.created_at DESC
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
            "SELECT k.*, a.name AS accountname, c.name AS categoryname
               FROM fin_check k
               LEFT JOIN fin_account a ON a.accountid = k.accountid
               LEFT JOIN fin_category c ON c.categoryid = k.categoryid
              WHERE k.checkid = ? AND k.companyid = ? LIMIT 1",
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /**
     * @param array{direction:string,accountId?:string|null,bankName?:string,checkNumber?:string,amount:float|string,issueDate?:string,dueDate?:string,contactId?:string|null,partyName?:string,categoryId?:string|null,description?:string} $data
     */
    public function create(string $companyId, array $data): array
    {
        $direction = (string) ($data['direction'] ?? '');
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new \RuntimeException('direction debe ser issued o received');
        }
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('El monto debe ser mayor a cero');
        }

        $accountId = $this->nullableUuid($data['accountId'] ?? null);
        if ($accountId !== null && !(new AccountService())->find($accountId, $companyId)) {
            throw new \RuntimeException('Cuenta no encontrada');
        }
        $categoryId = $this->nullableUuid($data['categoryId'] ?? null);
        $contactId  = $this->nullableUuid($data['contactId'] ?? null);

        $id = ncmInsert([
            'records' => [
                'companyid'   => $companyId,
                'direction'   => $direction,
                'accountid'   => $accountId,
                'bankname'    => (string) ($data['bankName'] ?? '') ?: null,
                'checknumber' => (string) ($data['checkNumber'] ?? '') ?: null,
                'amount'      => $amount,
                'issuedate'   => $this->normalizeDate($data['issueDate'] ?? null),
                'duedate'     => !empty($data['dueDate']) ? $this->normalizeDate($data['dueDate']) : null,
                'contactid'   => $contactId,
                'partyname'   => (string) ($data['partyName'] ?? '') ?: null,
                'categoryid'  => $categoryId,
                'status'      => 'pending',
                'description' => (string) ($data['description'] ?? '') ?: null,
            ],
            'table' => 'fin_check',
        ]);
        if (!$id) {
            throw new \RuntimeException('No se pudo crear el cheque');
        }

        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Cheque creado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Edita datos descriptivos del cheque. NO permite tocar direction/amount
     * si ya tiene un movimiento generado (cleared) — evitaría descuadrar el
     * saldo ya aplicado; para eso hay que revertir el estado primero.
     */
    public function update(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $existing = $this->find($id, $companyId);
        if (!$existing) {
            throw new \RuntimeException('Cheque no encontrado');
        }
        $locked = in_array($existing['status'], ['cleared'], true);

        $records = ['checkid' => $id];

        if (array_key_exists('amount', $data)) {
            if ($locked) {
                throw new \RuntimeException('No se puede editar el monto de un cheque ya efectivizado');
            }
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \RuntimeException('El monto debe ser mayor a cero');
            }
            $records['amount'] = $amount;
        }
        if (array_key_exists('accountId', $data)) {
            $accountId = $this->nullableUuid($data['accountId']);
            if ($accountId !== null && !(new AccountService())->find($accountId, $companyId)) {
                throw new \RuntimeException('Cuenta no encontrada');
            }
            $records['accountid'] = $accountId;
        }
        if (array_key_exists('categoryId', $data)) {
            $records['categoryid'] = $this->nullableUuid($data['categoryId']);
        }
        if (array_key_exists('contactId', $data)) {
            $records['contactid'] = $this->nullableUuid($data['contactId']);
        }
        if (array_key_exists('partyName', $data)) {
            $records['partyname'] = (string) $data['partyName'] ?: null;
        }
        if (array_key_exists('bankName', $data)) {
            $records['bankname'] = (string) $data['bankName'] ?: null;
        }
        if (array_key_exists('checkNumber', $data)) {
            $records['checknumber'] = (string) $data['checkNumber'] ?: null;
        }
        if (array_key_exists('issueDate', $data)) {
            $records['issuedate'] = $this->normalizeDate($data['issueDate']);
        }
        if (array_key_exists('dueDate', $data)) {
            $records['duedate'] = !empty($data['dueDate']) ? $this->normalizeDate($data['dueDate']) : null;
        }
        if (array_key_exists('description', $data)) {
            $records['description'] = (string) $data['description'] ?: null;
        }

        ncmUpdate([
            'records'     => $records,
            'table'       => 'fin_check',
            'where'       => 'checkid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]);

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el cheque actualizado');
        }
        return $row;
    }

    /**
     * Cambia el estado del cheque. Si el nuevo estado es "efectivizado"
     * (cleared) genera el movimiento correspondiente (idempotente); si se
     * revierte a un estado no efectivizado, anula el movimiento asociado.
     */
    public function changeStatus(string $id, string $companyId, string $newStatus, ?string $userId = null, ?string $outletId = null): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new \RuntimeException('Estado inválido');
        }
        $check = $this->find($id, $companyId);
        if (!$check) {
            throw new \RuntimeException('Cheque no encontrado');
        }
        if ($check['status'] === $newStatus) {
            return $check; // no-op, ya está en ese estado
        }

        $wasCleared = $check['status'] === 'cleared';
        $willClear  = $newStatus === 'cleared';

        $db->StartTrans();

        if ($willClear && !$wasCleared) {
            if (empty($check['accountId'])) {
                throw new \RuntimeException('El cheque necesita una cuenta asignada para poder efectivizarse');
            }
            $this->ensureMovement($id, $companyId, $check, $userId, $outletId);
            ncmExecute(
                'UPDATE fin_check SET status = ?, cleareddate = ? WHERE checkid = ? AND companyid = ?',
                [$newStatus, date('Y-m-d H:i:s'), $id, $companyId]
            );
        } elseif ($wasCleared && !$willClear) {
            $this->voidMovementIfExists($id, $companyId);
            ncmExecute(
                'UPDATE fin_check SET status = ?, cleareddate = NULL WHERE checkid = ? AND companyid = ?',
                [$newStatus, $id, $companyId]
            );
        } else {
            ncmExecute(
                'UPDATE fin_check SET status = ? WHERE checkid = ? AND companyid = ?',
                [$newStatus, $id, $companyId]
            );
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo cambiar el estado del cheque');
        }

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el cheque actualizado');
        }
        return $row;
    }

    /**
     * Anula el cheque (status='cancelled'). Si tenía un movimiento generado
     * (cleared), se anula también. No se hace hard-delete — un cheque
     * anulado sigue siendo parte del historial/auditoría del negocio.
     */
    public function delete(string $id, string $companyId): array
    {
        return $this->changeStatus($id, $companyId, 'cancelled');
    }

    // ── helpers internos ─────────────────────────────────────────────────

    /** Crea el movimiento asociado al cheque si todavía no existe (idempotente por UNIQUE source+sourceid). */
    private function ensureMovement(string $checkId, string $companyId, array $check, ?string $userId, ?string $outletId): void
    {
        $existing = ncmExecute(
            "SELECT movementid FROM fin_movement WHERE companyid = ? AND source = ? AND sourceid = ? AND status = 1 LIMIT 1",
            [$companyId, self::SOURCE, $checkId]
        );
        if ($existing) {
            return; // ya existe — idempotente, no duplicar
        }

        $kind = $check['direction'] === 'received' ? 'income' : 'expense';
        $label = $check['direction'] === 'received' ? 'Cheque recibido' : 'Cheque emitido';
        $description = $check['description'] ?: ($label . ($check['partyName'] ? ' — ' . $check['partyName'] : ''));

        $movementId = ncmInsert([
            'records' => [
                'companyid'   => $companyId,
                'accountid'   => $check['accountId'],
                'categoryid'  => $check['categoryId'],
                'kind'        => $kind,
                'amount'      => $check['amount'],
                'date'        => date('Y-m-d H:i:s'),
                'description' => $description,
                'source'      => self::SOURCE,
                'sourceid'    => $checkId,
                'checkid'     => $checkId,
                'userid'      => $userId,
                'outletid'    => $outletId,
                'status'      => 1,
            ],
            'table' => 'fin_movement',
        ]);
        if (!$movementId) {
            throw new \RuntimeException('No se pudo generar el movimiento del cheque');
        }

        $sign = $kind === 'income' ? '+' : '-';
        ncmExecute(
            "UPDATE fin_account SET currentbalance = currentbalance {$sign} ? WHERE accountid = ? AND companyid = ?",
            [$check['amount'], $check['accountId'], $companyId]
        );
    }

    /** Anula (soft-void) el movimiento asociado al cheque si existe, revirtiendo el saldo. */
    private function voidMovementIfExists(string $checkId, string $companyId): void
    {
        $row = ncmExecute(
            "SELECT movementid, accountid, kind, amount FROM fin_movement
              WHERE companyid = ? AND source = ? AND sourceid = ? AND status = 1 LIMIT 1",
            [$companyId, self::SOURCE, $checkId]
        );
        if (!$row) {
            return; // no había movimiento — nada que revertir
        }

        ncmExecute(
            'UPDATE fin_movement SET status = 0 WHERE movementid = ? AND companyid = ?',
            [(string) $row['movementid'], $companyId]
        );

        $reverseKind = (string) $row['kind'] === 'income' ? 'expense' : 'income';
        $sign = $reverseKind === 'income' ? '+' : '-';
        ncmExecute(
            "UPDATE fin_account SET currentbalance = currentbalance {$sign} ? WHERE accountid = ? AND companyid = ?",
            [(float) $row['amount'], (string) $row['accountid'], $companyId]
        );
    }

    private function nullableUuid($val): ?string
    {
        $val = (string) ($val ?? '');
        return ($val !== '' && preg_match(self::UUID_RE, $val)) ? $val : null;
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

    private function shape($f): array
    {
        return [
            'id'           => (string) $f['checkid'],
            'direction'    => (string) $f['direction'],
            'accountId'    => $f['accountid'] !== null ? (string) $f['accountid'] : null,
            'accountName'  => $f['accountname'] !== null ? (string) $f['accountname'] : null,
            'bankName'     => $f['bankname'] !== null ? (string) $f['bankname'] : null,
            'checkNumber'  => $f['checknumber'] !== null ? (string) $f['checknumber'] : null,
            'amount'       => (float) $f['amount'],
            'issueDate'    => (string) $f['issuedate'],
            'dueDate'      => $f['duedate'] !== null ? (string) $f['duedate'] : null,
            'contactId'    => $f['contactid'] !== null ? (string) $f['contactid'] : null,
            'partyName'    => $f['partyname'] !== null ? (string) $f['partyname'] : null,
            'categoryId'   => $f['categoryid'] !== null ? (string) $f['categoryid'] : null,
            'categoryName' => $f['categoryname'] !== null ? (string) $f['categoryname'] : null,
            'status'       => (string) $f['status'],
            'clearedDate'  => $f['cleareddate'] !== null ? (string) $f['cleareddate'] : null,
            'description'  => $f['description'] !== null ? (string) $f['description'] : null,
        ];
    }
}
