<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Adapter sobre ADORecordSet que GARANTIZA `$rs->fields` como array plano.
 *
 * El recordset de ADOdb puede devolver `fields` como CaseInsensitiveArray
 * (objeto), lo que rompe cualquier función con typehint `array`. Este wrapper
 * lo castea on-access vía __get, manteniendo el resto del API intacto.
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
     * @param object $inner Cualquier recordset estilo ADOdb. NO se tipa con
     * `\ADORecordSet` porque el driver del proyecto devuelve `DBResult`
     * (subclase ADOdb específica del proyecto) y otros call-sites podrían
     * devolver variantes. Duck typing: requerimos las propiedades `EOF`,
     * `fields` y los métodos `MoveNext`/`Close`/`RecordCount`/`GetRows`,
     * validados implícitamente por __get/__call al usarse.
     */
    public function __construct(private readonly object $inner) {}

    public function __get(string $name): mixed
    {
        if ($name === 'fields') {
            $f = $this->inner->fields;
            if (is_array($f)) return $f;
            // CaseInsensitiveArray (ADOdb) implementa Iterator; iterator_to_array
            // preserva las keys reales (lowercase del driver pg). NO usar
            // `(array)$obj` porque eso serializa propiedades privadas con prefijos
            // `\0...\0_arr` y todos los `$rs->fields['key']` devuelven null.
            // Incidente 2026-06-28 (devices null + POS crash con charAt).
            if ($f instanceof \Traversable) return iterator_to_array($f);
            return (array) $f;
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
