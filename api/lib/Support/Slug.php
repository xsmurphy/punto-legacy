<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Normalización y unicidad de `company.slug` — fuente única de verdad,
 * compartida entre Settings (edición manual) y Signup (generación inicial).
 * Ver mig 113_company_slug_unique.sql (índice UNIQUE parcial: NULL/'' libres).
 */
final class Slug
{
    /**
     * lowercase, [a-z0-9-], guiones consecutivos colapsados, tope 40
     * (VARCHAR(40) en BD). @return string|null  null = "sin slug".
     */
    public static function normalize(string $raw): ?string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        $s = preg_replace('/-+/', '-', (string) $s);
        $s = trim((string) $s, '-');
        $s = mb_substr((string) $s, 0, 40);
        return $s === '' ? null : $s;
    }

    /** SCOPEADO: excluye la propia company (empty string = ninguna, ej. company nueva). */
    public static function isAvailable(string $slug, string $excludeCompanyId = ''): bool
    {
        $r = ncmExecute(
            'SELECT 1 FROM company WHERE slug = ? AND companyId <> ? LIMIT 1',
            [$slug, $excludeCompanyId]
        );
        return empty($r);
    }
}
