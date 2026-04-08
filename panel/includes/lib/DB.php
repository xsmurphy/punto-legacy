<?php

/**
 * DB — Wrapper PDO que emula la API de ADOdb usada en este proyecto.
 *
 * Permite reemplazar ADOdb con cero cambios en el código de la aplicación.
 * Para activarlo, en db.postgres.php cambiar:
 *
 *   // ANTES (ADOdb)
 *   include_once($adodbPath);
 *   $db = ADONewConnection('postgres9');
 *   $db->Connect($host, $user, $pass, $dbname);
 *   $db->SetFetchMode(ADODB_FETCH_ASSOC);
 *
 *   // DESPUÉS (PDO)
 *   require_once __DIR__ . '/lib/DB.php';
 *   $db = new DB();
 *   $db->Connect($host, $user, $pass, $dbname);
 *
 * Métodos implementados:
 *   Execute, execute, AutoExecute, Insert, GetAssoc, CacheGetAssoc,
 *   cacheExecute, SelectLimit, qstr, Prepare, Param, StartTrans,
 *   CompleteTrans, FailTrans, HasFailedTrans, ErrorMsg, ErrorNo,
 *   SetFetchMode, cacheFlush, Close, selectDb, Connect, NConnect
 *
 * Propiedades emuladas: debug, databaseType, cacheSecs, fetchMode, port
 */

// ─────────────────────────────────────────────────────────────────────────────
// CaseInsensitiveArray — array con acceso case-insensitive a claves
//
// Resuelve la diferencia MySQL (case-insensitive) vs PostgreSQL (lowercase):
// $fields['contactPassword'] encuentra la clave 'contactpassword' de PG.
// ─────────────────────────────────────────────────────────────────────────────
class CaseInsensitiveArray implements ArrayAccess, IteratorAggregate, Countable
{
    private array $data   = [];
    private array $keyMap = []; // strtolower(key) => original key

    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            $lower             = strtolower((string) $k);
            $this->keyMap[$lower] = (string) $k;
            $this->data[(string) $k] = $v;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->keyMap[strtolower((string) $offset)]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $lower = strtolower((string) $offset);
        return isset($this->keyMap[$lower])
            ? ($this->data[$this->keyMap[$lower]] ?? null)
            : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) return;
        $lower                = strtolower((string) $offset);
        $this->keyMap[$lower] = (string) $offset;
        $this->data[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        $lower = strtolower((string) $offset);
        if (isset($this->keyMap[$lower])) {
            unset($this->data[$this->keyMap[$lower]], $this->keyMap[$lower]);
        }
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->data);
    }

    public function count(): int { return count($this->data); }

    public function toArray(): array { return $this->data; }
}

// ─────────────────────────────────────────────────────────────────────────────
// DBResult — reemplaza ADORecordSet
// ─────────────────────────────────────────────────────────────────────────────
class DBResult
{
    /** Fila actual con acceso case-insensitive. Equivale a $rs->fields */
    public CaseInsensitiveArray $fields;

    /** true cuando no quedan más filas. Equivale a $rs->EOF */
    public bool $EOF = true;

    private array $rows = [];
    private int   $pos  = 0;

    public function __construct(array $rows)
    {
        $this->rows   = $rows;
        $this->EOF    = empty($rows);
        $this->fields = new CaseInsensitiveArray($this->EOF ? [] : $rows[0]);
    }

    /** Avanza al siguiente registro. Equivale a $rs->MoveNext(). */
    public function MoveNext(): void
    {
        $this->pos++;
        if ($this->pos >= count($this->rows)) {
            $this->EOF    = true;
            $this->fields = new CaseInsensitiveArray([]);
        } else {
            $this->fields = new CaseInsensitiveArray($this->rows[$this->pos]);
        }
    }

    /** Número total de filas. Equivale a $rs->RecordCount(). */
    public function RecordCount(): int
    {
        return count($this->rows);
    }

    /** Retorna la fila actual como array asociativo. */
    public function GetRowAssoc(bool $upper = false): array
    {
        $arr = $this->fields->toArray();
        return $upper ? array_change_key_case($arr, CASE_UPPER) : $arr;
    }

    /** Retorna todas las filas. */
    public function GetRows(): array
    {
        return $this->rows;
    }

    /** No-op: compatibilidad ADOdb ($rs->Close()). */
    public function Close(): void {}
}

// ─────────────────────────────────────────────────────────────────────────────
// DB — reemplaza el objeto ADOdb
// ─────────────────────────────────────────────────────────────────────────────
class DB
{
    private ?PDO   $pdo       = null;
    private string $lastError = '';
    private int    $lastErrNo = 0;
    private bool   $transOk  = true;

    // Propiedades públicas de compatibilidad ADOdb
    public int    $port         = 5432;
    public int    $cacheSecs    = 3600;
    public bool   $debug        = false;
    public string $databaseType = 'postgres';
    public int    $fetchMode    = 2; // ADODB_FETCH_ASSOC

    // ─── Conexión ──────────────────────────────────────────────────────────

    /**
     * Establece la conexión con PostgreSQL.
     * Equivale a ADOdb->Connect($host, $user, $pass, $db).
     */
    public function Connect(string $host, string $user, string $pass, string $db): bool
    {
        return $this->_connect($host, $user, $pass, $db);
    }

    /** Alias de Connect (ADOdb tenía NConnect para conexiones no-persistentes). */
    public function NConnect(string $host, string $user, string $pass, string $db): bool
    {
        return $this->_connect($host, $user, $pass, $db);
    }

    private function _connect(string $host, string $user, string $pass, string $db): bool
    {
        try {
            $dsn       = "pgsql:host={$host};port={$this->port};dbname={$db}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrNo = (int) $e->getCode();
            return false;
        }
    }

    // ─── Consultas ─────────────────────────────────────────────────────────

    /**
     * Ejecuta SQL con parámetros posicionales (?).
     * Retorna DBResult en éxito, false en error.
     * Equivale a ADOdb->Execute($sql, $params).
     */
    public function Execute(string|array $sql, array|false $params = []): DBResult|false
    {
        // ADOdb->Prepare() devuelve [$sql, false, false]; compatibilidad:
        if (is_array($sql)) {
            $sql = $sql[0];
        }
        if ($params === false) {
            $params = [];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            // SELECT returns rows, INSERT/UPDATE/DELETE return empty result
            $isSelect = stripos(ltrim($sql), 'SELECT') === 0 || stripos(ltrim($sql), 'WITH') === 0;
            return new DBResult($isSelect ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrNo = (int) $e->getCode();
            // Mark transaction as failed so CompleteTrans rolls back
            if ($this->pdo->inTransaction()) {
                $this->transOk = false;
            }
            error_log('[DB] Execute error: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
            return false;
        }
    }


    /**
     * INSERT o UPDATE automático desde un array asociativo.
     * Equivale a ADOdb->AutoExecute($table, $data, 'INSERT'|'UPDATE', $where).
     */
    public function AutoExecute(string $table, array $data, string $mode, string $where = ''): bool
    {
        $mode = strtoupper(trim($mode));

        if ($mode === 'INSERT') {
            $cols         = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $sql          = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            $params       = array_values($data);
        } elseif ($mode === 'UPDATE') {
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $sql    = "UPDATE {$table} SET {$sets}" . ($where !== '' ? " WHERE {$where}" : '');
            $params = array_values($data);
        } else {
            return false;
        }

        return $this->Execute($sql, $params) !== false;
    }

    /**
     * INSERT simple. Equivale a ADOdb->Insert($table, $fields).
     */
    public function Insert(string $table, array $data): bool
    {
        return $this->AutoExecute($table, $data, 'INSERT');
    }

    /**
     * SELECT con LIMIT y OFFSET.
     * Equivale a ADOdb->SelectLimit($sql, $limit, $offset).
     */
    public function SelectLimit(string $sql, int $limit, int $offset = -1, array $params = []): DBResult|false
    {
        $sql .= " LIMIT {$limit}";
        if ($offset >= 0) {
            $sql .= " OFFSET {$offset}";
        }
        return $this->Execute($sql, $params);
    }

    /**
     * Retorna array asociativo keyed por la primera columna.
     * Equivale a ADOdb->GetAssoc($sql).
     */
    public function GetAssoc(string $sql, array $params = [], bool $force = false): array|false
    {
        $result = $this->Execute($sql, $params);
        if ($result === false) {
            return false;
        }
        $assoc = [];
        foreach ($result->GetRows() as $row) {
            $key         = reset($row);
            $assoc[$key] = $row;
        }
        return $assoc;
    }

    /**
     * Versión "cacheada" de GetAssoc — $secs ignorado, sin cache real.
     */
    public function CacheGetAssoc(int $secs, string $sql, array $params = []): array|false
    {
        return $this->GetAssoc($sql, $params);
    }

    /**
     * Versión "cacheada" de Execute — $secs ignorado, sin cache real.
     * Soporta llamadas con 2 args ($sql, $params) o 3 args ($secs, $sql, $params).
     */
    public function cacheExecute(mixed $secsOrSql, mixed $sqlOrParams = [], mixed $params = []): DBResult|false
    {
        if (is_string($secsOrSql)) {
            // Llamada con 2 args: cacheExecute($sql, $params)
            return $this->Execute($secsOrSql, is_array($sqlOrParams) ? $sqlOrParams : []);
        }
        // Llamada con 3 args: cacheExecute($secs, $sql, $params)
        return $this->Execute((string) $sqlOrParams, is_array($params) ? $params : []);
    }

    // ─── Escapado y parámetros ─────────────────────────────────────────────

    /**
     * Escapa y entrecomilla un valor para uso directo en SQL.
     * Equivale a ADOdb->qstr($value).
     *
     * PREFERÍ SIEMPRE PARÁMETROS POSICIONALES (?) EN LUGAR DE ESTE MÉTODO.
     */
    public function qstr(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return $this->pdo->quote((string) $value);
    }

    /**
     * Heurística de compatibilidad:
     * - Si recibe SQL (contiene espacio o ?), retorna [$sql, false, false]
     *   para que Execute() lo desenrolle (emula ADOdb Prepare para statements).
     * - Si recibe un valor simple (ID, string corto), lo escapa como qstr().
     *
     * En este codebase Prepare() se usa de las dos formas.
     */
    public function Prepare(mixed $value): string
    {
        // En ADOdb, Prepare() devuelve [$sql, false, false] para caching.
        // En PDO no necesitamos ese wrapping — devolvemos el SQL directamente.
        if (is_array($value)) {
            return (string) $value[0];
        }
        $str = (string) $value;
        // Si parece un valor escalar (sin espacios ni ?), escaparlo como string literal.
        if (!str_contains($str, ' ') && !str_contains($str, '?')) {
            return $this->qstr($str);
        }
        return $str;
    }

    /**
     * Retorna el placeholder posicional.
     * ADOdb->Param($name) devuelve '?' en drivers PostgreSQL.
     */
    public function Param(string $name = ''): string
    {
        return '?';
    }

    // ─── Transacciones ─────────────────────────────────────────────────────

    /** Inicia una transacción. Equivale a ADOdb->StartTrans(). */
    public function StartTrans(): void
    {
        $this->transOk = true;
        $this->pdo->beginTransaction();
    }

    /**
     * Confirma o revierte la transacción según FailTrans().
     * Equivale a ADOdb->CompleteTrans().
     */
    public function CompleteTrans(): bool
    {
        if ($this->transOk) {
            $this->pdo->commit();
            return true;
        }
        $this->pdo->rollBack();
        return false;
    }

    /** Marca la transacción actual como fallida. */
    public function FailTrans(): void
    {
        $this->transOk = false;
    }

    /** Retorna true si la transacción fue marcada como fallida. */
    public function HasFailedTrans(): bool
    {
        return !$this->transOk;
    }

    // ─── Diagnóstico ───────────────────────────────────────────────────────

    public function ErrorMsg(): string { return $this->lastError; }
    public function ErrorNo(): int     { return $this->lastErrNo; }

    // ─── Misceláneos ───────────────────────────────────────────────────────

    /** No-op: siempre FETCH_ASSOC. */
    public function SetFetchMode(int $mode): void {}

    /** No-op: sin cache. */
    public function cacheFlush(): void {}

    /** Cierra la conexión. */
    public function Close(): void { $this->pdo = null; }

    /** No-op: la DB se elige en el DSN, no hay cambio en runtime en PG. */
    public function selectDb(string $db): bool { return true; }

    /**
     * Absorbe propiedades ADOdb no definidas (e.g. $db->outp, $db->bind)
     * sin lanzar errores.
     */
    public function __set(string $name, mixed $value): void {}
    public function __get(string $name): mixed { return null; }
}
