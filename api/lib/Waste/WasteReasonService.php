<?php
declare(strict_types=1);

namespace Punto\Api\Waste;

/**
 * WasteReasonService — CRUD de motivos de merma del tenant.
 *
 * Modelo: igual patrón que PaymentMethodService — los motivos viven en
 * `taxonomy` (taxonomyType='wasteReason', scoped por companyId). El
 * `taxonomyId` (UUID) es la identidad estable que `waste_event.reasonid`
 * referencia (soft reference, sin FK — el motivo puede editarse/borrarse
 * sin migrar eventos históricos, igual que payment methods con ventas).
 *
 * Reglas:
 *   - Multi-tenant: TODO query filtra por companyId. Nunca se confía el
 *     companyId del cliente.
 *   - ensureSeed() es idempotente y se dispara lazy en el primer list()
 *     del tenant (no en un hook de creación de company) — mismo patrón
 *     que PaymentMethodService::ensureSeed().
 */
final class WasteReasonService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId): array
    {
        $this->ensureSeed($companyId);

        $rs = $this->db->Execute(
            'SELECT taxonomyId, taxonomyName, taxonomyExtra
               FROM taxonomy
              WHERE companyId = ? AND taxonomyType = ?
              ORDER BY taxonomyName ASC',
            [$companyId, 'wasteReason']
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        usort($out, function (array $a, array $b): int {
            $sa = $a['sortOrder'] ?? PHP_INT_MAX;
            $sb = $b['sortOrder'] ?? PHP_INT_MAX;
            return $sa <=> $sb ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT taxonomyId, taxonomyName, taxonomyExtra
               FROM taxonomy
              WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?
              LIMIT 1',
            [$id, $companyId, 'wasteReason']
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /** @throws \RuntimeException */
    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido');
        }
        $extra = ['sortOrder' => (int) ($input['sortOrder'] ?? 999)];

        $rs = $this->db->Execute(
            'INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
             VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb)
             RETURNING taxonomyId',
            [$companyId, 'wasteReason', $name, json_encode($extra)]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el motivo de merma');
        }
        $newId = (string) ($rs->fields['taxonomyid'] ?? $rs->fields['taxonomyId'] ?? '');
        if ($newId === '') {
            throw new \RuntimeException('No se pudo crear el motivo de merma');
        }
        return $newId;
    }

    public function update(string $companyId, string $id, array $input): void
    {
        $current = $this->find($companyId, $id);
        if ($current === null) {
            throw new \RuntimeException('Motivo de merma no encontrado');
        }
        $name = array_key_exists('name', $input)
            ? trim((string) $input['name'])
            : (string) $current['name'];
        if ($name === '') {
            throw new \RuntimeException('name no puede estar vacío');
        }
        $extra = [
            'sortOrder' => array_key_exists('sortOrder', $input)
                ? (int) $input['sortOrder']
                : (int) ($current['sortOrder'] ?? 999),
        ];

        $ok = $this->db->Execute(
            'UPDATE taxonomy
                SET taxonomyName = ?, taxonomyExtra = ?::jsonb
              WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?',
            [$name, json_encode($extra), $id, $companyId, 'wasteReason']
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar el motivo de merma');
        }
    }

    public function delete(string $companyId, string $id): void
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Motivo de merma no encontrado');
        }
        $ok = $this->db->Execute(
            'DELETE FROM taxonomy WHERE taxonomyId = ? AND companyId = ? AND taxonomyType = ?',
            [$id, $companyId, 'wasteReason']
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar el motivo de merma');
        }
        // waste_event.reasonid es soft reference (sin FK) — eventos históricos
        // quedan con un reasonid huérfano, inofensivo al leer (se resuelve por
        // LEFT JOIN, ver Reports/ProductionService::waste()).
    }

    /**
     * Auto-seed idempotente: si el tenant no tiene ningún motivo de merma,
     * crea los defaults del catálogo estándar.
     */
    public function ensureSeed(string $companyId): void
    {
        $rs = $this->db->Execute(
            'SELECT COUNT(*) AS n FROM taxonomy WHERE companyId = ? AND taxonomyType = ?',
            [$companyId, 'wasteReason']
        );
        if ($rs === false) return;
        $rows = $rs->GetRows();
        $n = (int) ($rows[0]['n'] ?? 0);
        if ($n > 0) return;

        $defaults = ['Vencimiento', 'Rotura/Daño', 'Error de producción', 'Pérdida/Robo', 'Otro'];
        foreach ($defaults as $i => $name) {
            $this->db->Execute(
                'INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb)',
                [$companyId, 'wasteReason', $name, json_encode(['sortOrder' => $i])]
            );
        }
    }

    private function present(array $row): array
    {
        $extra = [];
        $raw   = $row['taxonomyextra'] ?? $row['taxonomyExtra'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $extra = $decoded;
        }
        return [
            'id'        => (string) ($row['taxonomyid'] ?? $row['taxonomyId'] ?? ''),
            'name'      => (string) ($row['taxonomyname'] ?? $row['taxonomyName'] ?? ''),
            'sortOrder' => (int) ($extra['sortOrder'] ?? 999),
        ];
    }
}
