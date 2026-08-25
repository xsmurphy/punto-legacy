<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de categorías de Finanzas (`fin_category`) + seed de defaults.
 * Árbol de 2 niveles máximo: 'income' (Ingresos) | 'expense' (Egresos), cada
 * categoría raíz puede tener subcategorías (`parentid`), pero una subcategoría
 * NUNCA puede tener hijas propias. Las categorías default (`issystem=true`)
 * no se pueden borrar — solo renombrar.
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
        ['expense', 'Devoluciones', 6],
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

    /** @param array{name:string,kind:string,code?:?string,sortorder?:int,parentId?:?string} $data */
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

        $parentId = $this->resolveParentId($companyId, $kind, $data['parentId'] ?? null, null);

        $id = $this->guardUniqueCode(fn() => ncmInsert([
            'records' => [
                'companyid' => $companyId,
                'name'      => $name,
                'kind'      => $kind,
                'code'      => AccountingCode::normalize($data['code'] ?? null),
                'parentid'  => $parentId,
                'sortorder' => (int) ($data['sortorder'] ?? 99),
                'issystem'  => false,
                'status'    => 1,
            ],
            'table' => 'fin_category',
        ]));

        if (!$id) {
            throw new \RuntimeException('No se pudo crear la categoría');
        }
        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Categoría creada pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Permite renombrar, reasignar `parentId` y cargar/cambiar el `code`
     * contable externo (kind fijo tras creación — cambiar de income↔expense
     * rompe el histórico).
     *
     * Las categorías `issystem` SÍ aceptan código: "Proveedores" o "Ventas"
     * son justamente las que el contador necesita mapear. Lo que no se puede
     * de una default es borrarla (ver `archive()`).
     */
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

        $records = ['categoryid' => $id, 'name' => $name];

        // `code` solo se toca si la clave viene en el payload — un PUT que
        // solo renombra no debe borrar el código contable ya cargado.
        if (array_key_exists('code', $data)) {
            $records['code'] = AccountingCode::normalize($data['code']);
        }

        if (array_key_exists('parentId', $data)) {
            if ($this->hasChildren($id, $companyId)) {
                // Una categoría CON hijas no puede pasar a ser hija de otra —
                // rompería el árbol de 2 niveles.
                if (($data['parentId'] ?? null) !== null) {
                    throw new \RuntimeException('Esta categoría tiene subcategorías: no puede tener padre');
                }
                $records['parentid'] = null;
            } else {
                $records['parentid'] = $this->resolveParentId(
                    $companyId,
                    (string) $existing['kind'],
                    $data['parentId'],
                    $id
                );
            }
        }

        $this->guardUniqueCode(fn() => ncmUpdate([
            'records'     => $records,
            'table'       => 'fin_category',
            'where'       => 'categoryid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]));

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer la categoría actualizada');
        }
        return $row;
    }

    /** Archiva (soft-delete). Las categorías default (issystem) o con hijas activas no se pueden borrar. */
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
        if ($this->hasChildren($id, $companyId)) {
            throw new \RuntimeException('Esta categoría tiene subcategorías: moveé o eliminá las hijas primero');
        }
        ncmExecute(
            'UPDATE fin_category SET status = 0 WHERE categoryid = ? AND companyid = ?',
            [$id, $companyId]
        );
    }

    /**
     * Valida y normaliza `parentId` para create/update.
     *
     * Reglas (árbol de 2 niveles máximo):
     *   - null/'' → sin padre (categoría raíz).
     *   - debe existir, ser UUID, del mismo companyId y del mismo kind.
     *   - el padre NO puede tener padre (no se anida a 3+ niveles).
     *   - una categoría no puede ser su propio padre (`excludeId`).
     */
    private function resolveParentId(string $companyId, string $kind, ?string $parentId, ?string $excludeId): ?string
    {
        $parentId = trim((string) ($parentId ?? ''));
        if ($parentId === '') {
            return null;
        }
        if (!preg_match(self::UUID_RE, $parentId)) {
            throw new \RuntimeException('parentId inválido');
        }
        if ($excludeId !== null && $parentId === $excludeId) {
            throw new \RuntimeException('Una categoría no puede ser su propio padre');
        }
        $parent = $this->find($parentId, $companyId);
        if (!$parent) {
            throw new \RuntimeException('Categoría padre no encontrada');
        }
        if ($parent['kind'] !== $kind) {
            throw new \RuntimeException('La categoría padre debe ser del mismo tipo (ingreso/egreso)');
        }
        if ($parent['parentId'] !== null) {
            throw new \RuntimeException('La categoría padre no puede ser a su vez una subcategoría');
        }
        return $parentId;
    }

    /** true si existe alguna categoría activa con `parentid` = $id. */
    private function hasChildren(string $id, string $companyId): bool
    {
        $row = ncmExecute(
            'SELECT COUNT(*) AS n FROM fin_category WHERE parentid = ? AND companyid = ? AND status = 1',
            [$id, $companyId]
        );
        return $row && (int) ($row['n'] ?? 0) > 0;
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

    /**
     * Devuelve el categoryid de la categoría default "Devoluciones" del tenant.
     *
     * A diferencia de Ventas/Proveedores no alcanza con `ensureSeed()`: el seed
     * solo corre en tenants SIN ninguna categoría, así que los que ya venían de
     * Fase 1 nunca recibirían una categoría agregada después. Se crea on-demand
     * (idempotente por nombre+kind; el SELECT no filtra `status` para no
     * re-crearla si el tenant la archivó).
     */
    public function ensureReturnsCategoryId(string $companyId): string
    {
        $this->ensureSeed($companyId);

        $find = static fn() => ncmExecute(
            "SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = 'expense' AND name = 'Devoluciones' LIMIT 1",
            [$companyId]
        );

        $row = $find();
        if (!$row) {
            ncmInsert([
                'records' => [
                    'companyid' => $companyId,
                    'name'      => 'Devoluciones',
                    'kind'      => 'expense',
                    'sortorder' => 6,
                    'issystem'  => true,
                    'status'    => 1,
                ],
                'table' => 'fin_category',
            ]);
            $row = $find();
        }

        return $row ? (string) $row['categoryid'] : '';
    }

    /**
     * Devuelve el categoryid de la categoría default "Notas de crédito de
     * compra" del tenant — plata que un proveedor nos devuelve (`kind`=
     * income, espejo de "Devoluciones" que es `expense` porque ahí somos
     * nosotros los que devolvemos). On-demand igual que
     * `ensureReturnsCategoryId`: los tenants que ya venían de antes de esta
     * feature no la reciben vía `ensureSeed` (que solo corre en tenants sin
     * NINGUNA categoría).
     */
    public function ensurePurchaseCreditNoteCategoryId(string $companyId): string
    {
        $this->ensureSeed($companyId);

        $find = static fn() => ncmExecute(
            "SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = 'income' AND name = 'Notas de crédito de compra' LIMIT 1",
            [$companyId]
        );

        $row = $find();
        if (!$row) {
            ncmInsert([
                'records' => [
                    'companyid' => $companyId,
                    'name'      => 'Notas de crédito de compra',
                    'kind'      => 'income',
                    'sortorder' => 2,
                    'issystem'  => true,
                    'status'    => 1,
                ],
                'table' => 'fin_category',
            ]);
            $row = $find();
        }

        return $row ? (string) $row['categoryid'] : '';
    }

    /**
     * Devuelve el categoryid de la categoría default "Préstamos" del tenant —
     * cuota de crédito pagada (`LoanService::payInstallment`, source=
     * 'loan_installment'). On-demand igual que `ensureReturnsCategoryId`: los
     * tenants que ya venían de antes de F2 créditos no la reciben vía
     * `ensureSeed` (que solo corre en tenants sin NINGUNA categoría).
     */
    public function ensureLoanInstallmentCategoryId(string $companyId): string
    {
        $this->ensureSeed($companyId);

        $find = static fn() => ncmExecute(
            "SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = 'expense' AND name = 'Préstamos' LIMIT 1",
            [$companyId]
        );

        $row = $find();
        if (!$row) {
            ncmInsert([
                'records' => [
                    'companyid' => $companyId,
                    'name'      => 'Préstamos',
                    'kind'      => 'expense',
                    'sortorder' => 7,
                    'issystem'  => true,
                    'status'    => 1,
                ],
                'table' => 'fin_category',
            ]);
            $row = $find();
        }

        return $row ? (string) $row['categoryid'] : '';
    }

    /**
     * Devuelve el categoryid de la categoría default "Ajustes" del tenant
     * (income o expense según el signo de la diferencia) — usada por
     * `ReconciliationService::close()` cuando se crea un movimiento de ajuste
     * de conciliación sin que el usuario haya elegido una categoría propia.
     * On-demand igual que `ensureReturnsCategoryId`.
     */
    public function ensureAdjustmentCategoryId(string $companyId, string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \RuntimeException('kind debe ser income o expense');
        }
        $this->ensureSeed($companyId);

        $find = static fn() => ncmExecute(
            'SELECT categoryid FROM fin_category WHERE companyid = ? AND kind = ? AND name = ? LIMIT 1',
            [$companyId, $kind, 'Ajustes']
        );

        $row = $find();
        if (!$row) {
            ncmInsert([
                'records' => [
                    'companyid' => $companyId,
                    'name'      => 'Ajustes',
                    'kind'      => $kind,
                    'sortorder' => 8,
                    'issystem'  => true,
                    'status'    => 1,
                ],
                'table' => 'fin_category',
            ]);
            $row = $find();
        }

        return $row ? (string) $row['categoryid'] : '';
    }

    /** Ver `Support\UniqueViolation::guard()` — el índice es de la mig 167. */
    private function guardUniqueCode(callable $fn)
    {
        return \Punto\Api\Support\UniqueViolation::guard(
            $fn,
            ['uq_fin_category_code' => 'Ya existe una categoría con ese código'],
            'Ya existe una categoría con ese código',
        );
    }

    private function shape($f): array
    {
        return [
            'id'        => (string) $f['categoryid'],
            'name'      => (string) $f['name'],
            'kind'      => (string) $f['kind'],
            // Código contable EXTERNO (mig 167) — el que matchea contra el
            // plan de cuentas del contador. Opcional.
            'code'      => isset($f['code']) && $f['code'] !== null && (string) $f['code'] !== ''
                ? (string) $f['code']
                : null,
            'parentId'  => $f['parentid'] !== null ? (string) $f['parentid'] : null,
            'sortOrder' => (int) $f['sortorder'],
            'isSystem'  => (bool) $f['issystem'],
            'status'    => (int) $f['status'],
        ];
    }
}
