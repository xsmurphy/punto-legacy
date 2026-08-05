<?php
declare(strict_types=1);

namespace Punto\Api\Contacts;

/**
 * ContactDisplayName — resuelve el nombre de display de un contacto a partir
 * de `contactSecondName` (nombre de la persona) y `contactName` (razón
 * social), según la convención de ContactService::mapToColumns().
 *
 * REGLA (decisión del owner, 2026-08-05): el nombre y la razón social son
 * cosas DISTINTAS y NUNCA se concatenan. Se muestra el nombre; si no hay
 * nombre, se cae a la razón social.
 *
 * Es el mismo criterio que ya usaba el legacy en `api/lib/services/*`
 * (`COALESCE(NULLIF(secondName,''), contactName)`) — o sea que la forma
 * correcta ya existía y eran los reportes los que se habían desviado.
 *
 * El bug que originó este helper: cada reporte concatenaba
 * `contactName . ' ' . contactSecondName`, y como para una PERSONA (sin
 * fiscalName) ambas columnas llevan el MISMO valor a propósito, el nombre
 * salía duplicado ("BENITEZ MARTINEZ, JOSE MARIA BENITEZ MARTINEZ, JOSE
 * MARIA"). En una empresa el resultado era peor: pegaba razón social y
 * nombre en una sola cadena, que son datos de distinta naturaleza.
 *
 * Este helper reemplaza las ~8 copias privadas (`contactNames()`,
 * `userNames()`) que existían en api/lib/Reports/*Service.php.
 */
final class ContactDisplayName
{
    /**
     * Resuelve el nombre de display de UN contacto: el NOMBRE de la persona
     * (`contactSecondName`) y, si no hay, la razón social (`contactName`).
     * Nunca concatena — son datos de distinta naturaleza.
     */
    public static function resolve(?string $contactName, ?string $contactSecondName): string
    {
        $second = trim((string) $contactSecondName);
        if ($second !== '') {
            return $second;
        }
        return trim((string) $contactName);
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
