<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de categorías de Finanzas (`fin_category`) + seed de defaults.
 * Árbol de 1 nivel: 'income' (Ingresos) | 'expense' (Egresos). Las categorías
 * default (`issystem=true`) no se pueden borrar — solo renombrar.
 */
final class CategoryService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const KINDS = ['income', 'expense'];

    /** Defaults: [kind, name, sortorder]. */
    private const DEFAULTS = [
        ['income', 'Ventas', 0],
        ['income', 'Otros ingresos', 1],
        ['expense', 'Proveedores', 0],
        ['expense', 'Sueldos', 1],
        ['expense', 'Alquiler', 2],
        ['expense', 'Servicios', 3],
        ['expense', 'Impuestos', 4],
        ['expense', 'Otros', 5],
    ];

    public function list(string $companyId): array
    {
        $this->ensureSeed($companyId);

        $rs = ncmExecute(
            'SELECT * FROM fin_category WHERE companyid = ? AND status = 1 ORDER BY kind ASC, sortorder ASC, name ASC',
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
            'SELECT * FROM fin_category WHERE categoryid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /** @param array{name:string,kind:string,sortorder?:int} $data */
    public function create(string $companyId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre de la categoría es requerido');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('kind debe ser income o expense');
        }

        $id = ncmInsert([
            'records' => [
                'companyid' => $companyId,
                'name'      => $name,
                'kind'      => $kind,
                'sortorder' => (int) ($data['sortorder'] ?? 99),
                'issystem'  => false,
                'status'    => 1,
            ],
            'table' => 'fin_category',
        ]);

        if (!$id) {
            throw new \RuntimeException('No se pudo crear la categoría');
        }
        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Categoría creada pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /** Solo permite renombrar (kind fijo tras creación — cambiar de income↔expense rompe el histórico). */
    public function update(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $existing = $this->find($id, $companyId);
        if (!$existing) {
            throw new \RuntimeException('Categoría no encontrada');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre no puede estar vacío');
        }

        ncmUpdate([
            'records'     => ['categoryid' => $id, 'name' => $name],
            'table'       => 'fin_category',
            'where'       => 'categoryid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]);

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer la categoría actualizada');
        }
        return $row;
    }

    /** Archiva (soft-delete). Las categorías default (issystem) no se pueden borrar. */
    public function archive(string $id, string $companyId): void
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        $existing = $this->find($id, $companyId);
        if (!$existing) {
            throw new \RuntimeException('Categoría no encontrada');
        }
        if ($existing['isSystem']) {
            throw new \RuntimeException('Las categorías por defecto no se pueden eliminar');
        }
        ncmExecute(
            'UPDATE fin_category SET status = 0 WHERE categoryid = ? AND companyid = ?',
            [$id, $companyId]
        );
    }

    /** Auto-seed: si el tenant no tiene ninguna categoría, crea las default (issystem=true). */
    public function ensureSeed(string $companyId): void
    {
        $row = ncmExecute(
            'SELECT COUNT(*) AS n FROM fin_category WHERE companyid = ?',
            [$companyId]
        );
        if ($row && (int) ($row['n'] ?? 0) > 0) {
            return;
        }
        foreach (self::DEFAULTS as [$kind, $name, $sort]) {
            ncmInsert([
                'records' => [
                    'companyid' => $companyId,
                    'name'      => $name,
                    'kind'      => $kind,
                    'sortorder' => $sort,
                    'issystem'  => true,
                    'status'    => 1,
                ],
                'table' => 'fin_category',
            ]);
        }
    }

    /** Devuelve el categoryid de la categoría default "Ventas" del tenant. */
    public function ensureSalesCategoryId(string $companyId): string
    {
        $this->ensureSeed($companyId);
        $row = ncmExecute(
            "SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = 'income' AND name = 'Ventas' LIMIT 1",
            [$companyId]
        );
        return $row ? (string) $row['categoryid'] : '';
    }

    /** Devuelve el categoryid de la categoría default "Proveedores" del tenant. */
    public function ensurePurchasesCategoryId(string $companyId): string
    {
        $this->ensureSeed($companyId);
        $row = ncmExecute(
            "SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = 'expense' AND name = 'Proveedores' LIMIT 1",
            [$companyId]
        );
        return $row ? (string) $row['categoryid'] : '';
    }

    private function shape($f): array
    {
        return [
            'id'        => (string) $f['categoryid'],
            'name'      => (string) $f['name'],
            'kind'      => (string) $f['kind'],
            'parentId'  => $f['parentid'] !== null ? (string) $f['parentid'] : null,
            'sortOrder' => (int) $f['sortorder'],
            'isSystem'  => (bool) $f['issystem'],
            'status'    => (int) $f['status'],
        ];
    }
}
