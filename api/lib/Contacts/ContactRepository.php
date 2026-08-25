<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

use CaseInsensitiveArray;

/**
 * ContactRepository — acceso a las tablas `contact` y `customerAddress` con SQL parametrizado.
 *
 * Solo SQL. Sin reglas de negocio, sin defaults, sin side-effects.
 * Las reglas viven en ContactService. El JSONB routing lo hacen ncmInsert/ncmUpdate.
 *
 * `type` discrimina el rol del registro en la tabla `contact`:
 *   0 = usuario interno, 1 = cliente/proveedor.
 *
 * Port FIEL de panel/lib/contacts/ContactRepository.php (Fase 2 del desacople de /panel).
 * Cambios respecto al original: namespace, `declare(strict_types=1)`, `use CaseInsensitiveArray`
 * (clase global). SQL y comportamiento idénticos.
 *
 * Nota namespace: funciones globales (ncmInsert, ncmUpdate, _flattenJsonb) resuelven a sus
 * globales por fallback de PHP — no requieren `use`.
 */
final class ContactRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Buscar contacto por id + companyId (+ type opcional).
     * Devuelve CaseInsensitiveArray (accede por contactName o contactname).
     */
    public function find(string $id, string $companyId, ?int $type = null): array|CaseInsensitiveArray|null
    {
        $sql    = "SELECT * FROM contact WHERE contactId = ? AND companyId = ?";
        $params = [$id, $companyId];
        if ($type !== null) {
            $sql     .= " AND type = ?";
            $params[] = $type;
        }
        $sql .= " LIMIT 1";

        $rs = $this->db->Execute($sql, $params);
        if ($rs === false || $rs->EOF) return null;
        return _flattenJsonb($rs->fields);
    }

    /**
     * Buscar por CI (cédula) dentro de un type. Útil para upsert por documento.
     *
     * Post-Migración 25 (2026-06-13): contactCI vive en `data` JSONB.
     * El WHERE usa `data->>'contactCI'` para preservar la funcionalidad.
     * PG puede usar el GIN index sobre `data` si la cardinalidad amerita —
     * por ahora el uso es bajo (solo upsert por documento) y la tabla está
     * acotada al tenant, así que el scan filtrado es suficiente.
     */
    public function findByCI(string $ci, string $companyId, int $type = 1): array|CaseInsensitiveArray|null
    {
        $sql = "SELECT * FROM contact
                 WHERE data->>'contactCI' = ?
                   AND companyId = ?
                   AND type = ?
                 LIMIT 1";
        $rs  = $this->db->Execute($sql, [$ci, $companyId, $type]);
        if ($rs === false || $rs->EOF) return null;
        return _flattenJsonb($rs->fields);
    }

    // `companyCountry()` se eliminó: el país del tenant lo resuelve
    // `Punto\Api\Support\TenantLocale::country()`, que es el lector único de
    // `company.config` para toda la localización (país, moneda, zona horaria)
    // y cachea por companyId. Tener la query duplicada acá era el segundo
    // origen del mismo dato — y el resto del módulo igual asumía 'PY'.

    /**
     * INSERT vía ncmInsert (genera UUID v7 + JSONB routing).
     * Retorna el contactId nuevo o false si falla.
     */
    public function create(array $record)
    {
        return ncmInsert(['table' => 'contact', 'records' => $record]);
    }

    /**
     * UPDATE por contactId + companyId. Retorna true/false.
     */
    public function update(string $id, string $companyId, array $record): bool
    {
        $ok = ncmUpdate([
            'table'       => 'contact',
            'records'     => $record,
            'where'       => 'contactId = ? AND companyId = ?',
            'whereParams' => [$id, $companyId],
        ]);
        // ncmUpdate devuelve ['error'=>false,...] en éxito o ['error'=>$msg] en fallo (nunca false).
        return is_array($ok) && empty($ok['error']);
    }

    /**
     * Soft-delete: contactStatus = 0. NO borra para preservar FKs de transacciones.
     *
     * `updated_at = TODAY` (reloj del server PHP), NO `NOW()` de Postgres —
     * mismo motivo que `ItemRepository::archive()`: el sync incremental
     * (context/43-sync-incremental.md) compara este campo contra un
     * watermark generado con `TODAY`. Un desfase entre el reloj de PHP y el
     * de la DB, aunque sea chico, podría dejar un archive() en el borde de
     * una ventana de delta sin aparecer.
     */
    public function archive(string $id, string $companyId): bool
    {
        $sql = "UPDATE contact SET contactStatus = 0, updated_at = ? WHERE contactId = ? AND companyId = ?";
        return $this->db->Execute($sql, [TODAY, $id, $companyId]) !== false;
    }

    /**
     * Lista paginada por type. Filtros opcionales en $opts:
     *   q       → ILIKE sobre nombre / TIN / CI / teléfono
     *   status  → contactStatus exacto (por defecto solo activos = 1)
     *   limit   → default 50
     *   offset  → default 0
     * Retorna array de CaseInsensitiveArray (filas crudas).
     */
    public function listByType(int $type, string $companyId, array $opts = []): array
    {
        [$where, $params] = $this->buildListWhere($type, $companyId, $opts);
        $limit  = max(1, min((int) ($opts['limit'] ?? 50), 1000));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $sql = "SELECT * FROM contact
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY contactName ASC
                 LIMIT $limit OFFSET $offset";

        $rs = $this->db->Execute($sql, $params);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }

    /**
     * Fetch puntual por una lista de ids — bulk-get quirúrgico del sync
     * realtime (context/15-realtime-sync-plan.md). SIN filtrar por status:
     * el caller necesita ver también un contacto archivado (contactStatus=0)
     * para decidir sacarlo del store, no para que desaparezca en silencio.
     * companyId SIEMPRE en el WHERE — un id ajeno al tenant no matchea.
     *
     * `type` SÍ filtra (mismo criterio que `listByType`/`find($id,$cid,$type)`):
     * sin esto, un proveedor (type=2) editado en el panel dispara el mismo
     * evento genérico `contact` y el POS lo mergea en su store de CLIENTES
     * (`useCatalogStore.customers`) como si fuera uno — bug de fuga de datos
     * encontrado en code review (2026-08-16), no teórico.
     */
    public function getManyByIds(array $ids, string $companyId, int $type): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM contact WHERE companyId = ? AND type = ? AND contactId IN ({$placeholders})";
        $rs  = $this->db->Execute($sql, array_merge([$companyId, $type], $ids));
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }

    /**
     * Delta incremental (context/43-sync-incremental.md) — clientes tocados
     * desde `$since` (exclusivo). `$since = null` trae TODOS los clientes del
     * tipo (equivale a un full sync desde el epoch) — el caller (`/v1/sync`)
     * decide cuándo pedir eso vs cortar antes por umbral de borde.
     *
     * `COALESCE(updated_at, contactDate)`: filas creadas antes de que algún
     * caller futuro se olvide de setear `updated_at` (o de una migración de
     * datos histórica) igual entran al delta comparando contra su fecha de
     * creación — sin este fallback quedarían con `updated_at IS NULL` y NUNCA
     * matchean `> $since` (NULL en SQL no es mayor que nada), serían
     * fantasmas invisibles para siempre en vez de solo hasta su próxima
     * edición real.
     *
     * SIN filtro de `contactStatus` — mismo criterio que `getManyByIds()`
     * (ver docblock arriba): un contacto archivado SÍ viaja, el caller decide
     * si lo patchea o lo trata como remoción según su UI.
     */
    public function listUpdatedSince(int $type, string $companyId, ?string $since): array
    {
        $where  = ['companyId = ?', 'type = ?'];
        $params = [$companyId, $type];
        if ($since !== null) {
            $where[]  = 'COALESCE(updated_at, contactDate) > ?';
            $params[] = $since;
        }
        $sql = 'SELECT * FROM contact WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at ASC NULLS FIRST';
        $rs  = $this->db->Execute($sql, $params);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }

    /**
     * Total de registros para la misma combinación de filtros (paginación).
     */
    public function countByType(int $type, string $companyId, array $opts = []): int
    {
        [$where, $params] = $this->buildListWhere($type, $companyId, $opts);
        $sql = "SELECT COUNT(*) AS n FROM contact WHERE " . implode(' AND ', $where);
        $rs  = $this->db->Execute($sql, $params);
        return ($rs !== false && !$rs->EOF) ? (int) $rs->fields['n'] : 0;
    }

    private function buildListWhere(int $type, string $companyId, array $opts): array
    {
        $where  = ['companyId = ?', 'type = ?'];
        $params = [$companyId, $type];

        // Por defecto solo activos, salvo que se pida un status explícito.
        if (isset($opts['status']) && $opts['status'] !== '') {
            $where[]  = 'contactStatus = ?';
            $params[] = (int) $opts['status'];
        } else {
            $where[]  = 'contactStatus = ?';
            $params[] = 1;
        }

        if (!empty($opts['q'])) {
            // Post-Migración 25: contactSecondName y contactCI viven en `data` JSONB.
            // El search los lee con data->>'key' para no quebrar al quitarlas de columnas.
            // PG normaliza el cast a text así que LOWER(...) LIKE sigue funcionando.
            $like     = '%' . strtolower($opts['q']) . '%';
            $where[]  = "(LOWER(contactName) LIKE ?
                          OR LOWER(data->>'contactSecondName') LIKE ?
                          OR LOWER(contactTIN) LIKE ?
                          OR LOWER(data->>'contactCI') LIKE ?
                          OR LOWER(contactPhone) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [$where, $params];
    }

    // ── Direcciones (customerAddress) ──────────────────────────────────────────

    /**
     * Todas las direcciones de un contacto.
     */
    public function addresses(string $contactId, string $companyId, int $limit = 20): array
    {
        // status = 1: excluye las borradas (soft-delete, mig 87) — mismo
        // filtro que CustomerAddressService::listForCustomer.
        $sql = "SELECT * FROM customerAddress
                 WHERE customerId = ? AND companyId = ? AND status = 1
                 ORDER BY customerAddressDefault DESC
                 LIMIT " . (int) $limit;
        $rs  = $this->db->Execute($sql, [$contactId, $companyId]);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }

    /**
     * Dirección default de un contacto, o null. `status = 1`: una dirección
     * borrada (soft-delete, mig 87) nunca vuelve a aparecer como default,
     * aunque el UPDATE de delete() ya le limpia el flag customerAddressDefault.
     */
    public function defaultAddress(string $contactId, string $companyId): array|CaseInsensitiveArray|null
    {
        $sql = "SELECT * FROM customerAddress
                 WHERE customerId = ? AND companyId = ? AND customerAddressDefault = true AND status = 1
                 LIMIT 1";
        $rs  = $this->db->Execute($sql, [$contactId, $companyId]);
        if ($rs === false || $rs->EOF) return null;
        return _flattenJsonb($rs->fields);
    }

    public function createAddress(array $record)
    {
        return ncmInsert(['table' => 'customerAddress', 'records' => $record]);
    }

    /**
     * Actualiza la dirección default del contacto.
     */
    public function updateDefaultAddress(string $contactId, string $companyId, array $record): bool
    {
        $ok = ncmUpdate([
            'table'       => 'customerAddress',
            'records'     => $record,
            'where'       => 'customerAddressDefault = true AND customerId = ? AND companyId = ? AND status = 1',
            'whereParams' => [$contactId, $companyId],
        ]);
        return is_array($ok) && empty($ok['error']);
    }
}
