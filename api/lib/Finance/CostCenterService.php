<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * CRUD de centros de costo (`fin_cost_center`) — la taxonomía que responde
 * "¿a qué centro va este gasto?" (una sucursal, un área, una obra).
 *
 * Pedido del owner 2026-08-24: "similar a las categorías de gastos". La
 * diferencia con `CategoryService` es deliberada y de dominio, no de estilo:
 *
 *   - LISTA PLANA — sin `parentid`. Un centro de costo es un destino, no un
 *     plan de cuentas. Sin jerarquía no hay reglas de padre/hija que validar.
 *   - SIN `kind` — el mismo centro puede recibir egresos e ingresos.
 *   - SIN `issystem` — no hay centros por defecto que sembrar, así que no hay
 *     nada que proteger de un borrado (`CategoryService` sí necesita ese flag
 *     porque sus defaults los crea el sistema y los usan los hooks).
 *
 * Multi-tenant: `$companyId` siempre explícito (§33.2).
 * Ver mig 167_centros_de_costo.sql.
 */
final class CostCenterService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Solo los ACTIVOS — es lo que alimenta los selectores del formulario de gasto. */
    public function list(string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT * FROM fin_cost_center WHERE companyid = ? AND status = 1
              ORDER BY sortorder ASC, name ASC',
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
            'SELECT * FROM fin_cost_center WHERE costcenterid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        return $row ? $this->shape($row) : null;
    }

    /**
     * true si el centro existe, pertenece al comercio y está ACTIVO — el
     * chequeo que hace `MovementService` antes de imputar un movimiento.
     * Un centro archivado no puede recibir imputaciones nuevas (sí conserva
     * las viejas: el histórico no se reescribe).
     */
    public function isAssignable(string $id, string $companyId): bool
    {
        $row = $this->find($id, $companyId);
        return $row !== null && (int) $row['status'] === 1;
    }

    /** @param array{name?:string,code?:?string,sortOrder?:int} $data */
    public function create(string $companyId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre del centro de costo es requerido');
        }
        $code = $this->normalizeCode($data['code'] ?? null);

        $id = $this->guardUnique(fn() => ncmInsert([
            'records' => [
                'companyid' => $companyId,
                'name'      => $name,
                'code'      => $code,
                'sortorder' => (int) ($data['sortOrder'] ?? 99),
                'status'    => 1,
            ],
            'table' => 'fin_cost_center',
        ]));

        if (!$id) {
            throw new \RuntimeException('No se pudo crear el centro de costo');
        }
        $row = $this->find((string) $id, $companyId);
        if (!$row) {
            throw new \RuntimeException('Centro de costo creado pero no se pudo leer de vuelta');
        }
        return $row;
    }

    /**
     * Renombra y/o reasigna el código. `code` solo se toca si la clave viene
     * en el payload — un PUT que solo cambia el nombre no debe borrar el
     * código ya cargado.
     */
    public function update(string $id, string $companyId, array $data): array
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        if (!$this->find($id, $companyId)) {
            throw new \RuntimeException('Centro de costo no encontrado');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('El nombre no puede estar vacío');
        }

        $records = ['costcenterid' => $id, 'name' => $name];
        if (array_key_exists('code', $data)) {
            $records['code'] = $this->normalizeCode($data['code']);
        }
        if (array_key_exists('sortOrder', $data)) {
            $records['sortorder'] = (int) $data['sortOrder'];
        }

        $this->guardUnique(fn() => ncmUpdate([
            'records'     => $records,
            'table'       => 'fin_cost_center',
            'where'       => 'costcenterid = ? AND companyid = ?',
            'whereParams' => [$id, $companyId],
        ]));

        $row = $this->find($id, $companyId);
        if (!$row) {
            throw new \RuntimeException('No se pudo releer el centro de costo actualizado');
        }
        return $row;
    }

    /**
     * Archiva (soft-delete). NUNCA borra físico: los movimientos ya imputados
     * apuntan acá por FK y el histórico no se reescribe — el centro archivado
     * sigue nombrando esos gastos, solo deja de ofrecerse para imputaciones
     * nuevas.
     */
    public function archive(string $id, string $companyId): void
    {
        if (!preg_match(self::UUID_RE, $id)) {
            throw new \RuntimeException('id inválido');
        }
        if (!$this->find($id, $companyId)) {
            throw new \RuntimeException('Centro de costo no encontrado');
        }
        ncmExecute(
            'UPDATE fin_cost_center SET status = 0 WHERE costcenterid = ? AND companyid = ?',
            [$id, $companyId]
        );
    }

    private function normalizeCode(mixed $code): ?string
    {
        return AccountingCode::normalize($code);
    }

    /** Ver `Support\UniqueViolation::guard()` — los índices son de la mig 167. */
    private function guardUnique(callable $fn)
    {
        return \Punto\Api\Support\UniqueViolation::guard(
            $fn,
            [
                'uq_fin_cost_center_code' => 'Ya existe un centro de costo con ese código',
                'uq_fin_cost_center_name' => 'Ya existe un centro de costo con ese nombre',
            ],
            'Ya existe un centro de costo con ese nombre o código',
        );
    }

    private function shape($f): array
    {
        return [
            'id'        => (string) $f['costcenterid'],
            'name'      => (string) $f['name'],
            'code'      => $f['code'] !== null && (string) $f['code'] !== '' ? (string) $f['code'] : null,
            'sortOrder' => (int) $f['sortorder'],
            'status'    => (int) $f['status'],
        ];
    }
}
