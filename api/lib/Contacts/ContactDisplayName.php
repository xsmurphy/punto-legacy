<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

/**
 * ContactDisplayName — resuelve el nombre de display de un contacto a partir
 * de las columnas `contactName` (razón social) + `contactSecondName` (nombre
 * de persona), según la convención de ContactService::mapToColumns().
 *
 * Para una PERSONA (sin fiscalName), ambas columnas se escriben con el MISMO
 * valor (es correcto, ver ContactService). El bug histórico era que cada
 * reporte concatenaba `contactName . ' ' . contactSecondName` sin contemplar
 * ese caso, mostrando el nombre duplicado ("BENITEZ MARTINEZ, JOSE MARIA
 * BENITEZ MARTINEZ, JOSE MARIA"). Para una EMPRESA (con fiscalName != name)
 * concatenar sigue siendo correcto: razón social + nombre de contacto.
 *
 * Este helper reemplaza las ~7 copias privadas `contactNames()` que existían
 * en api/lib/Reports/*Service.php (todas con la misma lógica ad-hoc).
 */
final class ContactDisplayName
{
    /**
     * Resuelve el nombre de display de UN contacto.
     * Si los dos valores (normalizados) son iguales, devuelve uno solo.
     * Si difieren, concatena (orden actual: razón social + nombre).
     * Si alguno está vacío, devuelve el otro sin espacios de más.
     */
    public static function resolve(?string $contactName, ?string $contactSecondName): string
    {
        $name   = trim((string) $contactName);
        $second = trim((string) $contactSecondName);

        if ($name === '') {
            return $second;
        }
        if ($second === '') {
            return $name;
        }
        // Comparación case-insensitive: mismo criterio que "es el mismo valor".
        if (mb_strtolower($name) === mb_strtolower($second)) {
            return $name;
        }

        return trim($name . ' ' . $second);
    }

    /**
     * Lookup batch contactId → nombre de display, scopeado por companyId
     * (aislamiento multi-tenant obligatorio). Reemplaza el
     * `SELECT ... WHERE contactId IN (...)` duplicado en los reportes.
     *
     * @param array $ids
     * @param string $companyId
     * @return array<string,string> contactId => nombre de display
     */
    public static function batch(array $ids, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT contactId, contactName, data->>'contactSecondName' AS contactSecondName FROM contact WHERE companyId = ? AND contactId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];

        $map = [];
        foreach ($res as $c) {
            $map[(string) $c['contactId']] = self::resolve(
                $c['contactName'] ?? null,
                $c['contactSecondName'] ?? null
            );
        }
        return $map;
    }
}
