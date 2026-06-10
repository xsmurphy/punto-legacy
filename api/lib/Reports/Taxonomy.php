<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Helper de taxonomías (brand/category) para reportes — port mínimo de los getters globales
 * del panel a la /api compartida. Recibe companyId por parámetro (no globals).
 *
 * Razón: `getAllItemBrands()` solo vive en panel/includes/functions.php (lee COMPANY_ID
 * global); en /app existe `getAllItemCategories($companyId)` (Punto\App\Domain\Taxonomy)
 * pero brands no. Centralizar el helper acá evita arrastrar el realm del panel a /api y
 * sirve a los próximos reportes que también lean taxonomías.
 *
 * SQL idéntico al panel (incluye `taxonomyExtra` casteado para orden de categorías).
 */
final class Taxonomy
{
    /** @return array<string,array{name:string}> taxonomyId → { name } */
    public static function brands(string $companyId): array
    {
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'brand' AND companyId = ? LIMIT 500",
            [$companyId], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }
        $out = [];
        while (!$res->EOF) {
            $f = $res->fields;
            $out[(string) $f['taxonomyId']] = ['name' => toUTF8($f['taxonomyName'])];
            $res->MoveNext();
        }
        $res->Close();
        return $out;
    }

    /** @return array<string,array{name:string,sort:int}> taxonomyId → { name, sort } */
    public static function categories(string $companyId): array
    {
        $res = ncmExecute(
            "SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra AS INTEGER) AS sort
             FROM taxonomy WHERE taxonomyType = 'category' AND companyId = ?
             ORDER BY sort ASC LIMIT 2000",
            [$companyId], false, true
        );
        if (!$res || !is_object($res)) {
            return [];
        }
        $out = [];
        while (!$res->EOF) {
            $f = $res->fields;
            // Bug histórico del panel: `sort` se inicializa con $taxonomyName (no $sort).
            // Mantenemos el comportamiento (port fiel) — corregirlo es deuda separada.
            $out[(string) $f['taxonomyId']] = [
                'name' => toUTF8($f['taxonomyName']),
                'sort' => (int) $f['taxonomyName'],
            ];
            $res->MoveNext();
        }
        $res->Close();
        return $out;
    }
}
