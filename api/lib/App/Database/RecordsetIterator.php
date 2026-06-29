<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Adapter sobre el recordset `DBResult` (capa de DB propia, api/includes/lib/DB.php
 * sobre PDO — el proyecto NO usa ADOdb) que GARANTIZA `$rs->fields` con lookup
 * case-insensitive vía la `CaseInsensitiveArray` de DB.php.
 *
 * `$rs->fields` puede venir como array plano (keys lowercase de PG); este wrapper
 * lo envuelve on-access vía __get en la CIA, manteniendo el resto del API intacto.
 *
 * API expuesta (composición + delegación):
 *   property  EOF              — delegada (bool)
 *   property  fields           — INTERCEPTADA — siempre `array`
 *   method    MoveNext()       — delegado
 *   method    MoveFirst()      — delegado
 *   method    Close()          — delegado
 *   method    RecordCount()    — delegado
 *   method    GetRows()        — INTERCEPTADO — castea cada fila a array
 *   method    GetAssoc(...)    — INTERCEPTADO — castea cada valor a array si es objeto
 *   cualquier otro property/method → delegado vía __get/__call.
 *
 * Compatible 1:1 con el patrón:
 *   while (!$rs->EOF) { $row = $rs->fields; ...; $rs->MoveNext(); }
 *   $rs->Close();
 */
final class RecordsetIterator
{
    /**
     * @param object $inner El recordset `DBResult` de la capa de DB propia
     * (api/includes/lib/DB.php). No se tipa estricto porque otros call-sites
     * podrían devolver variantes. Duck typing: requerimos las propiedades `EOF`,
     * `fields` y los métodos `MoveNext`/`Close`/`RecordCount`/`GetRows`,
     * validados implícitamente por __get/__call al usarse.
     */
    public function __construct(private readonly object $inner) {}

    public function __get(string $name): mixed
    {
        if ($name === 'fields') {
            $f = $this->inner->fields;
            // Extraer array plano y envolver en CIA para lookup case-insensitive.
            // PG devuelve keys lowercase; callers historicamente leen camelCase.
            // CIA (de DB.php) es el wrapper que restaura ese contrato.
            if (is_array($f)) {
                return Query::flattenJsonb($f);
            }
            // CaseInsensitiveArray (DB.php) implementa Iterator; iterator_to_array
            // preserva las keys reales (lowercase del driver pg). NO usar
            // `(array)$obj` porque eso serializa propiedades privadas con prefijos
            // `\0...\0_arr` y todos los `$rs->fields['key']` devuelven null.
            // Incidente 2026-06-28 (devices null + POS crash con charAt).
            if ($f instanceof \Traversable) {
                return Query::flattenJsonb(iterator_to_array($f));
            }
            return Query::flattenJsonb((array) $f);
        }
        return $this->inner->{$name};
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->{$name});
    }

    public function __call(string $name, array $args): mixed
    {
        return $this->inner->{$name}(...$args);
    }

    public function GetRows(int $rows = -1): array
    {
        $raw = $this->inner->GetRows($rows);
        if (!is_array($raw)) return [];
        return array_map(function ($r) {
            if (is_array($r)) return $r;
            if ($r instanceof \Traversable) return iterator_to_array($r);
            return (array) $r;
        }, $raw);
    }
}
