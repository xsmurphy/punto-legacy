<?php
declare(strict_types=1);

namespace Punto\Api\Taxes;

/**
 * TaxService — CRUD de impuestos (Slice 3 del refactor taxonomy).
 *
 * Tabla dedicada `tax` (migration 23). Sync con `taxonomy WHERE
 * taxonomyType='tax'` vía triggers PG bidireccionales.
 *
 * IMPORTANTE: `name` guarda el valor del impuesto en formato compatible
 * con getTaxValue() (en Punto-PY: '10', '5', '0' — el porcentaje del IVA).
 * NO separamos rate en decimal todavía — se hace cuando todos los
 * consumers (facturación electrónica, reports, items) migren a leer de
 * `tax`.
 */
final class TaxService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, array{name: string, extra: ?string}>
     */
    public function listIndexed(string $companyId, int $limit = 100): array
    {
        $rs = $this->db->Execute(
            'SELECT taxId, name, extra
               FROM tax
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = (string) ($row['taxid'] ?? $row['taxId']);
            $out[$id] = [
                'name'  => (string) ($row['name'] ?? ''),
                'extra' => $row['extra'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, name: string, rate: ?float, extra: ?string}>
     */
    public function list(string $companyId, int $limit = 100): array
    {
        $rs = $this->db->Execute(
            'SELECT taxId, name, extra, created_at, updated_at
               FROM tax
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $taxId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT taxId, name, extra, created_at, updated_at
               FROM tax
              WHERE taxId = ? AND companyId = ?
              LIMIT 1',
            [$taxId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        // CaseInsensitiveArray: el cast (array) devuelve propiedades privadas, no
        // las keys reales. foreach + ArrayAccess copia bien.
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido (valor del impuesto, ej. "10")');
        }
        $extra    = isset($input['extra']) ? (string) $input['extra'] : null;
        $outletId = !empty($input['outletId']) ? (string) $input['outletId'] : null;

        $taxId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO tax (taxId, companyId, outletId, name, extra)
             VALUES (?, ?, ?, ?, ?)',
            [$taxId, $companyId, $outletId, $name, $extra]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo crear el impuesto');
        }
        return $taxId;
    }

    public function update(string $companyId, string $taxId, array $input): void
    {
        $sets   = [];
        $params = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new \RuntimeException('name no puede estar vacío');
            $sets[]   = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('extra', $input)) {
            $sets[]   = 'extra = ?';
            $params[] = $input['extra'] !== null ? (string) $input['extra'] : null;
        }
        if (array_key_exists('outletId', $input)) {
            $sets[]   = 'outletId = ?';
            $params[] = !empty($input['outletId']) ? (string) $input['outletId'] : null;
        }
        if (empty($sets)) return;

        $sets[]    = 'updated_at = NOW()';
        $params[]  = $taxId;
        $params[]  = $companyId;
        $sql = 'UPDATE tax SET ' . implode(', ', $sets)
             . ' WHERE taxId = ? AND companyId = ?';
        $ok = $this->db->Execute($sql, $params);
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar el impuesto');
        }
    }

    public function delete(string $companyId, string $taxId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM tax WHERE taxId = ? AND companyId = ?',
            [$taxId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar el impuesto');
        }
    }

    /**
     * Devuelve el `name` (valor del impuesto en formato compatible con
     * getTaxValue() del POS) por ID. Útil para callers que solo necesitan
     * el porcentaje numérico sin hacer un find() completo.
     */
    public function getValue(string $taxId): string|false
    {
        $rs = $this->db->Execute('SELECT name FROM tax WHERE taxId = ? LIMIT 1', [$taxId]);
        if ($rs === false || $rs->EOF) return false;
        return (string) ($rs->fields['name'] ?? '');
    }

    private function present(array|\CaseInsensitiveArray $row): array
    {
        $name = (string) ($row['name'] ?? '');
        // rate: best-effort cast del name a número. Si no es parseable, null.
        // Útil para clientes que necesitan el porcentaje aritmético sin tener
        // que parsear en cada uso. NO es authoritative — el shape canónico
        // sigue siendo `name` string (compat con facturación legacy).
        $rate = is_numeric($name) ? (float) $name : null;

        return [
            'id'         => (string) ($row['taxid'] ?? $row['taxId'] ?? ''),
            'name'       => $name,
            'rate'       => $rate,
            'extra'      => $row['extra'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
