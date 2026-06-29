<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Array wrapper con lookup de keys case-insensitive.
 *
 * PG devuelve columnas en lowercase. El codebase historicamente
 * leia $row['outletId'], $row['contactName'], etc. (camelCase) porque
 * ADOdb envolvia cada fila en su propio CaseInsensitiveArray.
 *
 * Esta clase replica ese contrato sin depender de ADOdb:
 *   - offsetGet/offsetExists: exact match primero, luego strtolower
 *   - getArrayCopy/jsonSerialize/getIterator: exponen el array interno (lowercase)
 *   - Spread (...$cia), array_merge, is_array NO funcionan — usar getArrayCopy()
 *     si un caller necesita un array plano (ninguno en el codebase lo hace sobre
 *     filas de ncmExecute segun blast-radius medido el 2026-06-29).
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class CaseInsensitiveArray implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var array<string, mixed> Keys en su forma original (lowercase de PG). */
    private array $data;

    /**
     * Mapa precalculado: strtolower(key) => key_original.
     * Permite O(1) en offsetGet sin iterar en cada acceso.
     *
     * @var array<string, string>
     */
    private array $lowerMap;

    /**
     * @param array<string, mixed> $data Array plano, normalmente las fields de PG.
     */
    public function __construct(array $data)
    {
        $this->data     = $data;
        $this->lowerMap = [];
        foreach (array_keys($data) as $key) {
            $this->lowerMap[strtolower($key)] = $key;
        }
    }

    // --- ArrayAccess ---

    public function offsetExists(mixed $offset): bool
    {
        if (array_key_exists($offset, $this->data)) {
            return true;
        }
        $lower = strtolower((string) $offset);
        return isset($this->lowerMap[$lower]) && array_key_exists($this->lowerMap[$lower], $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (array_key_exists($offset, $this->data)) {
            return $this->data[$offset];
        }
        $lower = strtolower((string) $offset);
        if (isset($this->lowerMap[$lower])) {
            return $this->data[$this->lowerMap[$lower]] ?? null;
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[(string) $offset] = $value;
            $this->lowerMap[strtolower((string) $offset)] = (string) $offset;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $key = (string) $offset;
        unset($this->data[$key]);
        $lower = strtolower($key);
        if (isset($this->lowerMap[$lower]) && $this->lowerMap[$lower] === $key) {
            unset($this->lowerMap[$lower]);
        }
    }

    // --- IteratorAggregate ---

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    // --- Countable ---

    public function count(): int
    {
        return count($this->data);
    }

    // --- JsonSerializable ---

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    // --- Helpers ---

    /**
     * Devuelve el array interno como copia plana.
     * Usar cuando un caller necesita un array nativo (array_merge, spread, etc).
     *
     * @return array<string, mixed>
     */
    public function getArrayCopy(): array
    {
        return $this->data;
    }
}
