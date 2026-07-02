<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de cuentas de Finanzas (`fin_account`) + seed de defaults + recompute
 * de saldo. Caja simple (single-entry) — NO partida doble.
 *
 * Multi-tenant: $companyId siempre explícito (§33.2 — nunca globals).
 * `currentbalance` es un cache actualizado transaccionalmente por
 * MovementService en cada insert/void de movimiento; recomputable desde
 * openingbalance + Σ movimientos activos (ver recomputeBalance()).
 */
final class AccountService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const TYPES = ['cash', 'bank', 'wallet'];

    /**
     * Lista cuentas activas del tenant. Auto-seed en el primer acceso (si el
     * tenant no tiene ninguna cuenta todavía).
     */
    public function list(string $companyId): array
    {
        $this->ensureSeed($companyId);

        $rs = ncmExecute(
            'SELECT * FROM fin_account WHERE companyid = ? AND status = 1 ORDER BY issystem DESC, name ASC',
            [$companyId],
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
        return $rows;
    }

    public function find(string $id, string $companyId): ?array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            return null;
        }
        $row = ncmExecute(
            'SELECT * FROM fin_account WHERE accountid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /**
     * @param array{name:string,type?:string,openingbalance?:float|string,bankname?:string,accountnumber?:string,outletid?:string|null} $data
     */
    public function create(string $companyId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre de la cuenta es requerido');
        }
        $type = (string) ($data['type'] ?? 'bank');
        if (!in_array($type, self::TYPES, true)) {
            throw new \RuntimeException('Tipo de cuenta inválido');
        }
        $opening = (float) ($data['openingbalance'] ?? 0);
        $outletId = (string) ($data['outletid'] ?? '');
        $outletId = ($outletId !== '' && preg_match(self::UUID_RE, $outletId)) ? $outletId : null;

        $id = ncmInsert([
            'records' => [
                'companyid'      => $companyId,
                'name'           => $name,
                'type'           => $type,
                'openingbalance' => $opening,
                'currentbalance' => $opening,
                'bankname'       => (string) ($data['bankname'] ?? '') ?: null,
                'accountnumber'  => (string) ($data['accountnumber'] ?? '') ?: null,
                'outletid'       => $outletId,
                'issystem'       => false,
                'status'         => 1,
            ],
            'table' => 'fin_account',
        ]);

        if (!$id) {
            throw new \RuntimeException('No se pudo crear la cuenta');
        }

        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Cuenta creada pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Edita nombre/banco/nro de cuenta. NO permite tocar type/openingbalance
     * (cambiar el tipo o el saldo inicial rompería la contabilidad de
     * currentbalance — si el usuario se equivocó, se recomienda archivar y
     * crear de nuevo).
     */
    public function update(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $existing = $this->find($id, $companyId);
        if (!$existing) {
            throw new \RuntimeException('Cuenta no encontrada');
        }

        $records = ['accountid' => $id];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \RuntimeException('El nombre no puede estar vacío');
            }
            $records['name'] = $name;
        }
        if (array_key_exists('bankname', $data)) {
            $records['bankname'] = (string) $data['bankname'] ?: null;
        }
        if (array_key_exists('accountnumber', $data)) {
            $records['accountnumber'] = (string) $data['accountnumber'] ?: null;
        }

        ncmUpdate([
            'records'     => $records,
            'table'       => 'fin_account',
            'where'       => 'accountid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]);

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer la cuenta actualizada');
        }
        return $row;
    }

    /**
     * Archiva una cuenta (soft-delete vía status=0). Las cuentas `issystem`
     * (Efectivo) no se pueden archivar — es el fallback fijo del mapeo de
     * métodos de pago.
     */
    public function archive(string $id, string $companyId): void
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $existing = $this->find($id, $companyId);
        if (!$existing) {
            throw new \RuntimeException('Cuenta no encontrada');
        }
        if ($existing['issystem']) {
            throw new \RuntimeException('La cuenta Efectivo no se puede archivar');
        }
        ncmExecute(
            'UPDATE fin_account SET status = 0 WHERE accountid = ? AND companyid = ?',
            [$id, $companyId]
        );
    }

    /**
     * Recomputa currentbalance desde cero: openingbalance + Σ movimientos
     * activos (status=1) de la cuenta. Usado para auto-corrección/auditoría —
     * el hot path (MovementService) actualiza el cache incrementalmente.
     */
    public function recomputeBalance(string $accountId, string $companyId): float
    {
        $row = ncmExecute(
            "SELECT a.openingbalance,
                    COALESCE(SUM(CASE WHEN m.kind = 'income' THEN m.amount ELSE -m.amount END), 0) AS delta
               FROM fin_account a
               LEFT JOIN fin_movement m ON m.accountid = a.accountid AND m.status = 1
              WHERE a.accountid = ? AND a.companyid = ?
              GROUP BY a.openingbalance",
            [$accountId, $companyId]
        );
        $balance = $row ? (float) $row['openingbalance'] + (float) $row['delta'] : 0.0;
        ncmExecute(
            'UPDATE fin_account SET currentbalance = ? WHERE accountid = ? AND companyid = ?',
            [$balance, $accountId, $companyId]
        );
        return $balance;
    }

    /**
     * Auto-seed: si el tenant no tiene NINGUNA cuenta, crea la cuenta
     * "Efectivo" (issystem=true, no borrable). NO crea ningún banco
     * placeholder — el usuario crea sus propias cuentas bancarias
     * (decisión owner 2026-07-02, ver context/22 §7).
     */
    public function ensureSeed(string $companyId): void
    {
        $row = ncmExecute(
            'SELECT COUNT(*) AS n FROM fin_account WHERE companyid = ?',
            [$companyId]
        );
        if ($row && (int) ($row['n'] ?? 0) > 0) {
            return;
        }
        ncmInsert([
            'records' => [
                'companyid'      => $companyId,
                'name'           => 'Efectivo',
                'type'           => 'cash',
                'openingbalance' => 0,
                'currentbalance' => 0,
                'issystem'       => true,
                'status'         => 1,
            ],
            'table' => 'fin_account',
        ]);
    }

    /** Devuelve accountid de la cuenta Efectivo del tenant (la crea si no existe). */
    public function ensureCashAccountId(string $companyId): string
    {
        $this->ensureSeed($companyId);
        $row = ncmExecute(
            "SELECT accountid FROM fin_account WHERE companyid = ? AND issystem = TRUE LIMIT 1",
            [$companyId]
        );
        return $row ? (string) $row['accountid'] : '';
    }

    private function shape($f): array
    {
        return [
            'id'             => (string) $f['accountid'],
            'name'           => (string) $f['name'],
            'type'           => (string) $f['type'],
            'openingBalance' => (float) $f['openingbalance'],
            'currentBalance' => (float) $f['currentbalance'],
            'bankName'       => $f['bankname'] !== null ? (string) $f['bankname'] : null,
            'accountNumber'  => $f['accountnumber'] !== null ? (string) $f['accountnumber'] : null,
            'outletId'       => $f['outletid'] !== null ? (string) $f['outletid'] : null,
            'isSystem'       => (bool) $f['issystem'],
            'status'         => (int) $f['status'],
        ];
    }
}
