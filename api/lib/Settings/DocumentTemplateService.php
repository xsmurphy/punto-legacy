<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

/**
 * DocumentTemplateService — CRUD de templates de documentos imprimibles.
 *
 * Reemplaza el storage legacy en taxonomy(taxonomyType='printTemplate'). El
 * config JSONB acepta cualquier shape; el shape canónico lo define el frontend
 * en panel-next/lib/types/document-template.ts. Mantenemos abierto para que
 * agregar campos no requiera migración (toggles nuevos, textos extra, etc.).
 *
 * Reglas:
 *   - Cada (companyId, docType) puede tener máximo 1 isDefault=true (partial
 *     unique index en migration 18). El servicio se ocupa de hacer toggle al
 *     setear uno nuevo como default.
 *   - companyId scope: nunca leemos/escribimos sin filtrar por companyId.
 */
final class DocumentTemplateService
{
    private $db;

    public const VALID_DOC_TYPES = ['receipt', 'invoice', 'quote', 'workorder', 'giftcard', 'delivery'];
    public const VALID_PAGE_SIZES = ['57mm', '76mm', '80mm', 'A4', 'A4-landscape', 'letter', 'legal'];

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function list(string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT templateId, name, docType, pageSize, isDefault, config, created_at, updated_at
               FROM document_template
              WHERE companyId = ?
              ORDER BY docType, isDefault DESC, name',
            [$companyId]
        );
        if ($rs === false) return [];
        return array_map([$this, 'present'], $rs->GetRows());
    }

    public function find(string $companyId, string $templateId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT templateId, name, docType, pageSize, isDefault, config, created_at, updated_at
               FROM document_template
              WHERE templateId = ? AND companyId = ? LIMIT 1',
            [$templateId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        // PG driver devuelve CaseInsensitiveArray en ->fields. El cast `(array) $rs->fields`
        // NO sirve — retorna las propiedades privadas del objeto (`data`, `keyMap` con
        // \0 prefix), no las keys/values reales. Tampoco funciona `iterator_to_array`
        // (CaseInsensitiveArray NO implementa Iterator). foreach sí itera correctamente
        // a través de ArrayAccess. Sin esto el present() recibía un array vacío y
        // devolvía templateId=null + name="" → editar plantilla rompía con 422.
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /** @return string templateId nuevo */
    public function create(string $companyId, array $input): string
    {
        $this->validate($input);
        $name      = trim((string) ($input['name'] ?? ''));
        $docType   = (string) ($input['docType'] ?? 'receipt');
        $pageSize  = (string) ($input['pageSize'] ?? '80mm');
        $isDefault = !empty($input['isDefault']);
        $config    = is_array($input['config'] ?? null) ? $input['config'] : [];

        if ($isDefault) $this->clearDefault($companyId, $docType);

        $templateId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO document_template (templateId, companyId, name, docType, pageSize, isDefault, config, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, NOW())',
            [$templateId, $companyId, $name, $docType, $pageSize, $isDefault ? 'true' : 'false', json_encode($config)]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo crear el template');
        return $templateId;
    }

    public function update(string $companyId, string $templateId, array $input): void
    {
        // Validar solo los campos que vienen — partial update.
        if (isset($input['docType']) && !in_array($input['docType'], self::VALID_DOC_TYPES, true)) {
            throw new \RuntimeException('docType inválido');
        }
        if (isset($input['pageSize']) && !in_array($input['pageSize'], self::VALID_PAGE_SIZES, true)) {
            throw new \RuntimeException('pageSize inválido');
        }

        // Si están seteando isDefault=true, primero limpiar el actual.
        if (!empty($input['isDefault'])) {
            // Necesitamos el docType actual para saber sobre qué scope limpiar.
            $current = $this->find($companyId, $templateId);
            if ($current === null) throw new \RuntimeException('Template no encontrado');
            $docType = (string) ($input['docType'] ?? $current['docType']);
            $this->clearDefault($companyId, $docType);
        }

        $sets   = [];
        $params = [];
        foreach (['name', 'docType', 'pageSize'] as $col) {
            if (array_key_exists($col, $input)) {
                $sets[]   = "$col = ?";
                $params[] = (string) $input[$col];
            }
        }
        if (array_key_exists('isDefault', $input)) {
            $sets[]   = 'isDefault = ?';
            $params[] = $input['isDefault'] ? 'true' : 'false';
        }
        if (array_key_exists('config', $input)) {
            $sets[]   = 'config = ?::jsonb';
            $params[] = json_encode($input['config']);
        }
        if (empty($sets)) return; // nada para actualizar

        $sets[]    = 'updated_at = NOW()';
        $params[]  = $templateId;
        $params[]  = $companyId;
        $sql = 'UPDATE document_template SET ' . implode(', ', $sets)
             . ' WHERE templateId = ? AND companyId = ?';
        $ok = $this->db->Execute($sql, $params);
        if ($ok === false) throw new \RuntimeException('No se pudo actualizar el template');
    }

    public function delete(string $companyId, string $templateId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM document_template WHERE templateId = ? AND companyId = ?',
            [$templateId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo eliminar el template');
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function present(array $row): array
    {
        // PG jsonb puede llegar como string (driver pdo_pgsql/pgsql, lo más común),
        // como stdClass (algunos drivers PHP 8.x con cast automático), o como
        // array nativo (si algo upstream ya hizo json_decode). Normalizamos todo.
        $config = $row['config'] ?? $row['Config'] ?? null;
        if (is_string($config) && $config !== '') {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        } elseif (is_object($config)) {
            $decoded = json_decode(json_encode($config), true);
            $config = is_array($decoded) ? $decoded : [];
        }
        return [
            'templateId' => $row['templateid'] ?? $row['templateId'] ?? null,
            'name'       => $row['name'] ?? '',
            'docType'    => $row['doctype'] ?? $row['docType'] ?? 'receipt',
            'pageSize'   => $row['pagesize'] ?? $row['pageSize'] ?? '80mm',
            'isDefault'  => (bool) ($row['isdefault'] ?? $row['isDefault'] ?? false),
            'config'     => is_array($config) ? $config : [],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function validate(array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('name requerido');
        $docType = (string) ($input['docType'] ?? '');
        if (!in_array($docType, self::VALID_DOC_TYPES, true)) {
            throw new \RuntimeException('docType inválido (debe ser uno de: ' . implode(', ', self::VALID_DOC_TYPES) . ')');
        }
        $pageSize = (string) ($input['pageSize'] ?? '80mm');
        if (!in_array($pageSize, self::VALID_PAGE_SIZES, true)) {
            throw new \RuntimeException('pageSize inválido');
        }
    }

    private function clearDefault(string $companyId, string $docType): void
    {
        $this->db->Execute(
            'UPDATE document_template SET isDefault = false WHERE companyId = ? AND docType = ? AND isDefault = true',
            [$companyId, $docType]
        );
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
