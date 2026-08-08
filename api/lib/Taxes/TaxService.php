<?php
declare(strict_types=1);

namespace Punto\Api\Taxes;

/**
 * TaxService — CRUD de impuestos (F0 del plan de impuestos multi-país,
 * context/38-impuestos-multi-pais.md).
 *
 * Tabla dedicada `tax` (mig 23 + mig 120). Sync con `taxonomy WHERE
 * taxonomyType='tax'` vía triggers PG bidireccionales (solo name/outletId/
 * extra — rate/kind son exclusivos de `tax`, sin contraparte en taxonomy).
 *
 * `name` sigue siendo el valor legacy compatible con getTaxValue() (en
 * Punto-PY: '10', '5', '0'). `rate`/`kind` (mig 120) son la fuente
 * numérica/tipada: `rate` es DECIMAL parseado una vez, `kind` distingue
 * 'rate' (con porcentaje) de 'exempt' (exento, sin porcentaje real).
 */
final class TaxService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, array{name: string, rate: ?float, kind: string, extra: ?string}>
     */
    public function listIndexed(string $companyId, int $limit = 100): array
    {
        $rs = $this->db->Execute(
            'SELECT taxId, name, rate, kind, extra
               FROM tax
              WHERE companyId = ?
              ORDER BY sortOrder NULLS LAST, name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = (string) ($row['taxid'] ?? $row['taxId']);
            $out[$id] = [
                'name'  => (string) ($row['name'] ?? ''),
                'rate'  => isset($row['rate']) ? (float) $row['rate'] : null,
                'kind'  => (string) ($row['kind'] ?? 'rate'),
                'extra' => $row['extra'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, name: string, rate: ?float, kind: string, extra: ?string}>
     */
    public function list(string $companyId, int $limit = 100): array
    {
        $rs = $this->db->Execute(
            'SELECT taxId, name, rate, kind, extra, sortOrder, created_at, updated_at
               FROM tax
              WHERE companyId = ?
              ORDER BY sortOrder NULLS LAST, name
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
            'SELECT taxId, name, rate, kind, extra, sortOrder, created_at, updated_at
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
        [$rate, $kind] = $this->resolveRateKind($input, $name);

        $taxId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO tax (taxId, companyId, outletId, name, rate, kind, extra)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$taxId, $companyId, $outletId, $name, $rate, $kind, $extra]
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
        $nameForDerive = null;
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new \RuntimeException('name no puede estar vacío');
            $sets[]   = 'name = ?';
            $params[] = $name;
            $nameForDerive = $name;
        }
        // rate/kind: si vienen explícitos del POST se usan tal cual; si no
        // vienen pero SÍ cambió `name`, se re-derivan del nuevo name (mismo
        // criterio que create()) para no dejar rate/kind desactualizados.
        if (array_key_exists('rate', $input) || array_key_exists('kind', $input)) {
            $current = $this->find($companyId, $taxId);
            $baseName = $nameForDerive ?? ($current['name'] ?? '');
            [$derivedRate, $derivedKind] = $this->resolveRateKind($input, $baseName);
            $sets[]   = 'rate = ?';
            $params[] = $derivedRate;
            $sets[]   = 'kind = ?';
            $params[] = $derivedKind;
        } elseif ($nameForDerive !== null) {
            [$derivedRate, $derivedKind] = $this->resolveRateKind([], $nameForDerive);
            $sets[]   = 'rate = ?';
            $params[] = $derivedRate;
            $sets[]   = 'kind = ?';
            $params[] = $derivedKind;
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

    /**
     * Reordena los impuestos del tenant: setea sortOrder = índice según el
     * orden de $orderedIds (mig 121, columna real — mismo criterio que el
     * reorder de PaymentMethodService pero sin JSON). Valida que orderedIds
     * sea una permutación EXACTA del set del tenant: sin reorder parcial y
     * sin colar ids de otro comercio (el WHERE además scopea por companyId).
     *
     * @param string[] $orderedIds taxIds en el orden deseado
     */
    public function reorder(string $companyId, array $orderedIds): void
    {
        $ids = [];
        foreach ($orderedIds as $id) {
            $id = (string) $id;
            if ($id !== '' && !in_array($id, $ids, true)) $ids[] = $id;
        }
        if ($ids === []) {
            throw new \RuntimeException('orderedIds vacío');
        }

        $existing = [];
        foreach ($this->list($companyId) as $t) {
            $existing[(string) $t['id']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($existing[$id])) {
                throw new \RuntimeException('orderedIds contiene un impuesto inexistente o de otro comercio');
            }
        }
        if (count($ids) !== count($existing)) {
            throw new \RuntimeException('orderedIds debe incluir todos los impuestos del comercio');
        }

        $this->db->StartTrans();
        try {
            $pos = 0;
            foreach ($ids as $id) {
                $ok = $this->db->Execute(
                    'UPDATE tax SET sortOrder = ?, updated_at = NOW()
                      WHERE taxId = ? AND companyId = ?',
                    [$pos, $id, $companyId]
                );
                if ($ok === false) {
                    throw new \RuntimeException('Falló el UPDATE de reorder');
                }
                $pos++;
            }
        } catch (\Throwable $e) {
            $this->db->FailTrans();
            throw new \RuntimeException('No se pudo reordenar los impuestos');
        }
        $this->db->CompleteTrans();
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
        // rate/kind ahora son columnas reales (mig 120), pobladas por backfill
        // para toda fila preexistente. El fallback a resolveRateKind() cubre
        // únicamente filas que por algún motivo (trigger de taxonomy, insert
        // externo) llegaron con rate NULL — no debería pasar en operación
        // normal post-mig 120, pero evita devolver null a un consumer que
        // ya espera un número.
        $rate = isset($row['rate']) ? (float) $row['rate'] : null;
        $kind = isset($row['kind']) ? (string) $row['kind'] : null;
        if ($rate === null || $kind === null) {
            [$rate, $kind] = $this->deriveRateKindFromName($name);
        }

        $sortOrder = $row['sortorder'] ?? $row['sortOrder'] ?? null;

        return [
            'id'         => (string) ($row['taxid'] ?? $row['taxId'] ?? ''),
            'name'       => $name,
            'rate'       => $rate,
            'kind'       => $kind,
            'extra'      => $row['extra'] ?? null,
            'sortOrder'  => $sortOrder !== null ? (int) $sortOrder : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * rate/kind explícitos del POST si vienen, si no se derivan de `name`
     * con la misma regla que la mig 120: primer número (coma o punto
     * decimal) → kind='rate' con ese valor; sin número → kind='exempt',
     * rate=0.
     *
     * @return array{0: float, 1: string}
     */
    private function resolveRateKind(array $input, string $name): array
    {
        if (array_key_exists('kind', $input)) {
            $kind = (string) $input['kind'];
            if (!in_array($kind, ['rate', 'exempt'], true)) {
                throw new \RuntimeException('kind inválido — debe ser "rate" o "exempt"');
            }
        } else {
            $kind = null;
        }

        if (array_key_exists('rate', $input) && $input['rate'] !== null && $input['rate'] !== '') {
            $rate = (float) $input['rate'];
            // Techo 0–100: una tasa fuera de ese rango no existe fiscalmente,
            // y además desbordaría DECIMAL(5,2) — el INSERT moriría con un
            // error de PG en vez de un 422 legible.
            if ($rate < 0 || $rate > 100) {
                throw new \RuntimeException('rate inválido — debe estar entre 0 y 100');
            }
        } else {
            $rate = null;
        }

        if ($rate !== null && $kind !== null) {
            return [$rate, $kind];
        }

        [$derivedRate, $derivedKind] = $this->deriveRateKindFromName($name);
        return [$rate ?? $derivedRate, $kind ?? $derivedKind];
    }

    /**
     * Público y static porque es la fuente compartida del criterio de
     * parseo legacy (SaleService/PurchasesService lo duplicaban antes de
     * F3a; EInvoiceService::resolveTaxRatesForItems lo reusa acá en vez de
     * agregar una 4ta copia).
     *
     * @return array{0: float, 1: string}
     */
    public static function deriveRateKindFromName(string $name): array
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $name, $m)) {
            $rate = (float) str_replace(',', '.', $m[0]);
            // Mismo guard que la mig 120: un número > 100 en el nombre
            // ("IVA Ley 2024") no es una tasa — cae a exempt/0 en vez de
            // desbordar DECIMAL(5,2) y reventar el INSERT.
            if ($rate <= 100) {
                return [$rate, 'rate'];
            }
        }
        return [0.0, 'exempt'];
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
