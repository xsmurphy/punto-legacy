<?php
declare(strict_types=1);

namespace Punto\Api\Tags;

/**
 * TagService — CRUD de etiquetas de producto (Slice 4 del refactor taxonomy).
 *
 * Tabla dedicada `tag` (migration 39). Sync con `taxonomy WHERE
 * taxonomyType='tag'` vía triggers PG bidireccionales.
 *
 * Mismo patrón que CategoryService — copiar/modificar para nuevos tipos.
 */
final class TagService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, array{name: string, extra: ?string}>
     */
    public function listIndexed(string $companyId, int $limit = 500): array
    {
        $rs = $this->db->Execute(
            'SELECT tagId, name, extra
               FROM tag
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = (string) ($row['tagid'] ?? $row['tagId']);
            $out[$id] = [
                'name'  => (string) ($row['name'] ?? ''),
                'extra' => $row['extra'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, name: string, extra: ?string}>
     */
    public function list(string $companyId, int $limit = 500): array
    {
        $rs = $this->db->Execute(
            'SELECT tagId, name, extra, created_at, updated_at
               FROM tag
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

    public function find(string $companyId, string $tagId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT tagId, name, extra, created_at, updated_at
               FROM tag
              WHERE tagId = ? AND companyId = ?
              LIMIT 1',
            [$tagId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        // CaseInsensitiveArray: el cast (array) no expone las keys/values reales
        // — devuelve las propiedades privadas. foreach + ArrayAccess sí copia.
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido');
        }
        $extra    = isset($input['extra']) ? (string) $input['extra'] : null;
        $outletId = !empty($input['outletId']) ? (string) $input['outletId'] : null;

        $tagId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO tag (tagId, companyId, outletId, name, extra)
             VALUES (?, ?, ?, ?, ?)',
            [$tagId, $companyId, $outletId, $name, $extra]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo crear la etiqueta');
        }
        return $tagId;
    }

    /**
     * Resuelve una etiqueta por NOMBRE dentro del tenant y la CREA si no existe.
     * Devuelve el `tagId`, o `null` si el nombre es vacío.
     *
     * Existe para el camino "el usuario escribió una etiqueta nueva" (etiquetas
     * de VENTA, SaleService B7): el POS es offline-first, así que el nombre
     * viaja dentro del payload encolado y recién se resuelve contra el catálogo
     * al sincronizar — pedirle a la caja que cree el tag ANTES de vender sería
     * una llamada de red bloqueante en el cobro.
     *
     * Case-insensitive por el MISMO criterio que el índice único que ya tiene
     * la tabla (`uq_tag_company_name` sobre `(companyId, LOWER(name))`, mig 39):
     * si buscáramos con `=` a secas, "Whatsapp" no encontraría "whatsapp" y el
     * INSERT siguiente chocaría contra ese índice.
     *
     * NO puede fallar por una carrera: dos cajas sincronizando la misma
     * etiqueta nueva a la vez harían que el segundo INSERT violara el índice
     * único, y en este wrapper CUALQUIER error de PG marca la transacción como
     * fallida (`DB::handleQueryFailure` → `failTransaction()`) — o sea que la
     * VENTA se perdería por una etiqueta. De ahí el `ON CONFLICT DO NOTHING` +
     * re-lectura en vez de un INSERT pelado.
     *
     * Escribe en `tag` (nunca en `taxonomy`): los triggers bidireccionales de
     * la mig 39 replican la fila a `taxonomy` en la misma transacción, que es
     * lo que satisface la FK de `toTag.tagId → taxonomy(taxonomyId)`.
     */
    public function resolveOrCreateByName(string $companyId, string $name, ?string $outletId = null): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = $this->findIdByName($companyId, $name);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->Execute(
            'INSERT INTO tag (tagId, companyId, outletId, name)
             VALUES (?, ?, ?, ?)
             ON CONFLICT DO NOTHING',
            [$this->generateUuid(), $companyId, $outletId, $name]
        );

        // Re-lectura: si el ON CONFLICT no insertó (carrera), ganó el otro y su
        // id es el bueno. No reusamos el uuid generado arriba a ciegas.
        return $this->findIdByName($companyId, $name);
    }

    private function findIdByName(string $companyId, string $name): ?string
    {
        $rs = $this->db->Execute(
            'SELECT tagId FROM tag
              WHERE companyId = ? AND LOWER(name) = LOWER(?)
              LIMIT 1',
            [$companyId, $name]
        );
        if ($rs === false || $rs->EOF) {
            return null;
        }
        $id = (string) ($rs->fields['tagid'] ?? $rs->fields['tagId'] ?? '');
        return $id !== '' ? $id : null;
    }

    public function update(string $companyId, string $tagId, array $input): void
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
        $params[]  = $tagId;
        $params[]  = $companyId;
        $sql = 'UPDATE tag SET ' . implode(', ', $sets)
             . ' WHERE tagId = ? AND companyId = ?';
        $ok = $this->db->Execute($sql, $params);
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar la etiqueta');
        }
    }

    public function delete(string $companyId, string $tagId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM tag WHERE tagId = ? AND companyId = ?',
            [$tagId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar la etiqueta');
        }
    }

    // ── item_tag m2m ──────────────────────────────────────────────────────

    /**
     * Etiquetas asignadas a un item.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function listForItem(string $itemId): array
    {
        $rs = $this->db->Execute(
            'SELECT t.tagId, t.name
               FROM item_tag it
               JOIN tag t ON t.tagId = it.tagId
              WHERE it.itemId = ?
              ORDER BY t.name',
            [$itemId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = [
                'id'   => (string) ($row['tagid'] ?? $row['tagId']),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Reemplaza todas las etiquetas asignadas a un item. `$tags` es array de
     * `['id' => uuid]`. Multi-tenant: el caller pasa companyId verificado.
     */
    public function syncForItem(string $itemId, string $companyId, array $tags): void
    {
        $this->db->Execute('DELETE FROM item_tag WHERE itemId = ?', [$itemId]);
        foreach ($tags as $t) {
            $id = (string) ($t['id'] ?? '');
            if ($id === '') continue;
            $this->db->Execute(
                'INSERT INTO item_tag (itemId, tagId) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$itemId, $id]
            );
        }
    }

    private function present(array|\CaseInsensitiveArray $row): array
    {
        return [
            'id'         => (string) ($row['tagid'] ?? $row['tagId'] ?? ''),
            'name'       => (string) ($row['name'] ?? ''),
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
