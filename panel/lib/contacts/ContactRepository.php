<?php

/**
 * ContactRepository — acceso a las tablas `contact` y `customerAddress` con SQL parametrizado.
 *
 * Solo SQL. Sin reglas de negocio, sin defaults, sin side-effects.
 * Las reglas viven en ContactService. El JSONB routing lo hacen ncmInsert/ncmUpdate.
 *
 * `type` discrimina el rol del registro en la tabla `contact`:
 *   0 = usuario interno, 1 = cliente/proveedor.
 */
class ContactRepository
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
    public function find(string $id, string $companyId, ?int $type = null): ?CaseInsensitiveArray
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
     */
    public function findByCI(string $ci, string $companyId, int $type = 1): ?CaseInsensitiveArray
    {
        $sql = "SELECT * FROM contact WHERE contactCI = ? AND companyId = ? AND type = ? LIMIT 1";
        $rs  = $this->db->Execute($sql, [$ci, $companyId, $type]);
        if ($rs === false || $rs->EOF) return null;
        return _flattenJsonb($rs->fields);
    }

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
     */
    public function archive(string $id, string $companyId): bool
    {
        $sql = "UPDATE contact SET contactStatus = 0, updated_at = NOW() WHERE contactId = ? AND companyId = ?";
        return $this->db->Execute($sql, [$id, $companyId]) !== false;
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
            $like     = '%' . strtolower($opts['q']) . '%';
            $where[]  = '(LOWER(contactName) LIKE ? OR LOWER(contactSecondName) LIKE ?
                          OR LOWER(contactTIN) LIKE ? OR LOWER(contactCI) LIKE ?
                          OR LOWER(contactPhone) LIKE ?)';
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
        $sql = "SELECT * FROM customerAddress
                 WHERE customerId = ? AND companyId = ?
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
     * Dirección default de un contacto, o null.
     */
    public function defaultAddress(string $contactId, string $companyId): ?CaseInsensitiveArray
    {
        $sql = "SELECT * FROM customerAddress
                 WHERE customerId = ? AND companyId = ? AND customerAddressDefault = true
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
            'where'       => 'customerAddressDefault = true AND customerId = ? AND companyId = ?',
            'whereParams' => [$contactId, $companyId],
        ]);
        return is_array($ok) && empty($ok['error']);
    }
}
