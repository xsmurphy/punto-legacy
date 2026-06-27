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
    public function __construct(private readonly \ADORecordSet $inner) {}

    public function __get(string $name): mixed
    {
        if ($name === 'fields') {
            $f = $this->inner->fields;
            return is_array($f) ? $f : (array) $f;
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
        return array_map(fn($r) => is_array($r) ? $r : (array) $r, $raw);
    }
}
