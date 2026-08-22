<?php

/**
 * DB — Wrapper PDO propio sobre PostgreSQL. ÚNICA capa de acceso a DB del
 * proyecto (no hay ORM ni librería externa). Cargado desde `includes/db.php`.
 *
 * API pública (nombres heredados del wrapper legacy que precedió a esta clase):
 *   Execute, execute, AutoExecute, Insert, GetAssoc, CacheGetAssoc,
 *   cacheExecute, SelectLimit, qstr, Prepare, Param, StartTrans,
 *   CompleteTrans, FailTrans, HasFailedTrans, BeginTrans, CommitTrans,
 *   RollbackTrans, Affected_Rows, ErrorMsg, ErrorNo,
 *   SetFetchMode, cacheFlush, Close, selectDb, Connect, NConnect
 *
 * Propiedades: debug, databaseType, cacheSecs, fetchMode, port
 */

// ─────────────────────────────────────────────────────────────────────────────
// CaseInsensitiveArray — array con acceso case-insensitive a claves
//
// Resuelve la diferencia MySQL (case-insensitive) vs PostgreSQL (lowercase):
// $fields['contactPassword'] encuentra la clave 'contactpassword' de PG.
// ─────────────────────────────────────────────────────────────────────────────
// `JsonSerializable` NO es decorativo: sin él, `json_encode()` de una fila
// (`$rs->fields`) serializaba `{}` — `$data`/`$keyMap` son privados y la clase
// no exponía nada más. Todo endpoint que devolviera filas crudas en la
// respuesta mandaba objetos vacíos: la request salía 200, el front recibía N
// filas sin ninguna propiedad y pintaba una tabla de celdas en blanco
// (/settings/sessions, reporte del owner 2026-08-01 — las sesiones ACTIVAS se
// veían como "Revocada" porque `status` también llegaba undefined). Es el
// mismo patrón de falla silenciosa que flattenJsonb: 200 + dato desaparecido.
class CaseInsensitiveArray implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
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

    /** Serializa como la fila que representa (ver nota de la clase). */
    public function jsonSerialize(): array { return $this->data; }
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

    /**
     * Fila actual como array PHP NATIVO.
     *
     * `$rs->fields` es un CaseInsensitiveArray, no un array — sirve para leer
     * (implementa ArrayAccess), pero NO satisface un tipo `: array`. Devolverlo
     * desde una función tipada así tira TypeError en runtime, y el guard suele
     * correr antes del write, así que rompe la operación entera.
     *
     * Ese TypeError ya se había parchado a mano —con un `instanceof` idéntico—
     * en ItemService, DrawerService, VariantService, OutletsService,
     * TransactionService, Query.php y v1/items.php. Diez copias de la misma
     * conversión es la señal de que faltaba el método, no de que faltara un
     * parche más: la conversión vive acá y los call-sites piden `row()`.
     *
     * Usá `->fields` para leer sueltamente; usá `row()` cuando el valor cruza
     * una frontera tipada `array` (return, parámetro, json_encode estricto).
     */
    public function row(): array
    {
        return $this->fields->toArray();
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

    /**
     * Rebobina el cursor al primer registro.
     * Define moveFirst() y MoveFirst() — PHP es case-insensitive en nombres
     * de método, así que basta con una implementación.
     */
    public function MoveFirst(): void
    {
        $this->pos = 0;
        if (count($this->rows) > 0) {
            $this->EOF    = false;
            $this->fields = new CaseInsensitiveArray($this->rows[0]);
        } else {
            $this->EOF    = true;
            $this->fields = new CaseInsensitiveArray([]);
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

    /** No-op: compatibilidad con la API legacy ($rs->Close()). */
    public function Close(): void {}
}

// ─────────────────────────────────────────────────────────────────────────────
// DB — reemplaza el objeto de acceso a datos legacy
// ─────────────────────────────────────────────────────────────────────────────
class DB
{
    private ?PDO   $pdo          = null;
    private string $lastError    = '';
    /**
     * PRIMER error desde que arrancó la transacción actual.
     *
     * En PG, el primer statement que falla envenena la transacción: todo lo que
     * sigue devuelve 25P02 ("current transaction is aborted"). Como cada uno de
     * esos pisaba `lastError`, el error que llegaba al usuario era SIEMPRE la
     * cascada y nunca la causa — por eso el signup reportaba 25P02 pelado y no
     * se podía diagnosticar. Este se setea una sola vez por transacción.
     */
    private string $firstError   = '';
    private int    $lastErrNo    = 0;
    private bool   $lastWasDml   = false;
    private bool   $transOk      = true;
    private int    $lastRowCount = 0;

    // Propiedades públicas de compatibilidad con la API legacy
    public int    $port         = 5432;
    public int    $cacheSecs    = 3600;
    public bool   $debug        = false;
    public string $databaseType = 'postgres';
    public int    $fetchMode    = 2; // ADODB_FETCH_ASSOC

    // Captura el PK auto-generado por el último AutoExecute INSERT.
    // En PostgreSQL los PK son UUIDs (gen_random_uuid() default), así que
    // PDO::lastInsertId() no funciona. Usamos RETURNING * y guardamos la
    // primera columna del resultado.
    private ?string $_lastInsertId = null;

    // ─── Conexión ──────────────────────────────────────────────────────────

    /**
     * Establece la conexión con PostgreSQL.
     */
    public function Connect(string $host, string $user, string $pass, string $db): bool
    {
        return $this->_connect($host, $user, $pass, $db);
    }

    /** Alias de Connect (la API legacy tenía NConnect para conexiones no-persistentes). */
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
     * Primera fila de un SELECT (CaseInsensitiveArray) o false.
     * La capa NO implementaba este método → callers `$db->GetRow(...)` reventaban
     * con "Call to undefined method" → 500 silente (display_errors=0). Causó el
     * incidente de pagos a crédito y devoluciones (2026-06-30). Agregarlo acá
     * (wrapper) en vez de parchear cada call-site.
     */
    public function GetRow(string|array $sql, array|false $params = []): CaseInsensitiveArray|false
    {
        $rs = $this->Execute($sql, $params);
        if ($rs === false || $rs->EOF) {
            return false;
        }
        return $rs->fields;
    }

    /**
     * Primer valor (primera columna de la primera fila) o false.
     */
    public function GetOne(string|array $sql, array|false $params = []): mixed
    {
        $rs = $this->Execute($sql, $params);
        if ($rs === false || $rs->EOF) {
            return false;
        }
        foreach ($rs->fields as $v) {
            return $v;
        }
        return false;
    }

    /**
     * Ejecuta SQL con parámetros posicionales (?).
     * Retorna DBResult en éxito, false en error.
     */
    public function Execute(string|array $sql, array|false $params = []): DBResult|false
    {
        // Prepare() de la API legacy devuelve [$sql, false, false]; compatibilidad:
        if (is_array($sql)) {
            $sql = $sql[0];
        }
        if ($params === false) {
            $params = [];
        }

        // PG no acepta bool PHP en placeholders (false → "" vacío).
        // Convertimos a 'true'/'false' textual aceptado por PG.
        foreach ($params as $i => $v) {
            if (is_bool($v)) $params[$i] = $v ? 'true' : 'false';
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            // SELECT/WITH siempre devuelven filas. INSERT/UPDATE/DELETE pueden
            // devolver filas SI tienen cláusula RETURNING (PG-specific). Sin
            // este chequeo, un `INSERT ... RETURNING id` parsea como
            // "INSERT" (no SELECT) → wrapper descarta las filas → callers
            // que dependen del id devuelto fallan silenciosamente.
            $trimmed      = ltrim($sql);
            $isSelect     = stripos($trimmed, 'SELECT') === 0 || stripos($trimmed, 'WITH') === 0;
            $hasReturning = (bool) preg_match('/\bRETURNING\b/i', $sql);
            $this->lastWasDml   = !($isSelect || $hasReturning);
            $this->lastRowCount = $stmt->rowCount();
            return new DBResult(($isSelect || $hasReturning) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []);
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->noteFirstError($e->getMessage());
            $this->lastErrNo = (int) $e->getCode();
            // Mark transaction as failed so CompleteTrans rolls back
            if ($this->pdo->inTransaction()) {
                $this->transOk = false;
            }
            error_log('[DB] Execute error: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
            // Guard de cierre de período (mig 157, context/48 D7): el trigger
            // fn_period_guard() levanta SQLSTATE PC001 con el literal
            // 'period_closed'. Este es el ÚNICO choke point de escritura del
            // proyecto (Query::execute, ncmUpdate/AutoExecute UPDATE y los
            // $db->Execute directos de los servicios pasan todos por acá) —
            // se relanza como excepción tipada acá, no por endpoint, para que
            // el mapeo a HTTP 409 viva en un solo lugar (api/bootstrap.php).
            // Matcheamos por SQLSTATE (getCode()) ADEMÁS del texto: PDO expone
            // el SQLSTATE como código de la excepción — más robusto que confiar
            // solo en el mensaje si el driver alguna vez le antepone contexto.
            if (str_contains($e->getMessage(), 'period_closed') || $e->getCode() === 'PC001') {
                // Fix code-review mig 157: tiramos una excepción en vez de
                // `return false`, así que el caller NUNCA llega a su propio
                // CompleteTrans()/RollbackTrans() — sin este rollback explícito
                // acá, la transacción PDO queda abierta colgada (visible como
                // "idle in transaction" en pg_stat_activity) hasta que el
                // proceso PHP termina. Cerramos la TX (todos los niveles de
                // anidamiento, StartTrans() o BeginTrans()) ANTES de propagar.
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->transDepth = 0;
                $this->transOk    = true;
                throw new \Punto\Api\Support\PeriodClosedException($e->getMessage(), (int) $e->getCode(), $e);
            }
            return false;
        }
    }


    /**
     * INSERT o UPDATE automático desde un array asociativo.
     */
    public function AutoExecute(string $table, array $data, string $mode, string $where = '', array $whereParams = []): bool
    {
        $mode = strtoupper(trim($mode));

        // PG rechaza booleanos PHP en placeholders genéricos (false → "" string vacío).
        // Convertimos a 'true'/'false' textual que PG sí acepta como BOOLEAN.
        foreach ($data as $k => $v) {
            if (is_bool($v)) $data[$k] = $v ? 'true' : 'false';
        }

        if ($mode === 'INSERT') {
            $cols         = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            // RETURNING * permite capturar el PK auto-generado (UUIDs en PG).
            $sql          = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) RETURNING *";
            $params       = array_values($data);

            try {
                $stmt = $this->pdo->prepare($sql);
                $ok   = $stmt->execute($params);
                if ($ok) {
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    // PK siempre es la primera columna en este schema (convención
                    // de los CREATE TABLE de db-schema-postgres.sql).
                    $this->_lastInsertId = $row ? (string) array_values($row)[0] : null;
                }
                return $ok;
            } catch (\PDOException $e) {
                // MISMO manejo que Execute(): este catch solo logueaba y
                // devolvía false, sin tocar lastError ni transOk. Dos efectos,
                // los dos silenciosos:
                //
                //   1. ErrorMsg() quedaba vacío, así que el error REAL de PG se
                //      perdía. SaleAbortedException::$dbError llegaba null y el
                //      operador solo veía "Sale transaction aborted" — sin la
                //      causa, ningún fallo de INSERT era diagnosticable.
                //   2. transOk seguía true, así que HasFailedTrans() decía que
                //      todo iba bien y CompleteTrans() mandaba un COMMIT sobre
                //      una transacción PG ya ABORTADA. PG lo degrada a ROLLBACK
                //      y devuelve éxito: la operación se reportaba OK y no
                //      había persistido nada. Es el escenario que el chequeo
                //      post-commit de SaleService (§22.8.1) tuvo que agregar
                //      para no facturar ventas fantasma; la causa estaba acá.
                //
                // El primer statement que falla es también el que deja la tx
                // envenenada: todo lo que sigue devuelve 25P02 ("current
                // transaction is aborted"), que es el síntoma que se reportaba,
                // no la causa.
                $this->lastError     = $e->getMessage();
                $this->noteFirstError($e->getMessage());
                $this->lastErrNo     = (int) $e->getCode();
                $this->_lastInsertId = null;
                if ($this->pdo->inTransaction()) {
                    $this->transOk = false;
                }
                error_log('[DB] AutoExecute INSERT error: ' . $e->getMessage() . ' | SQL: ' . $sql);
                return false;
            }
        } elseif ($mode === 'UPDATE') {
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $sql    = "UPDATE {$table} SET {$sets}" . ($where !== '' ? " WHERE {$where}" : '');
            // Si el caller pasó whereParams, se appendean a los params de SET.
            $params = array_merge(array_values($data), $whereParams);
            return $this->Execute($sql, $params) !== false;
        }

        return false;
    }

    /**
     * Retorna el PK auto-generado por el último AutoExecute INSERT.
     * Equivalente a MySQL's LAST_INSERT_ID(). En PostgreSQL con UUIDs,
     * capturamos vía RETURNING *.
     */
    public function Insert_ID(): ?string
    {
        return $this->_lastInsertId;
    }

    /**
     * INSERT simple.
     */
    public function Insert(string $table, array $data): bool
    {
        return $this->AutoExecute($table, $data, 'INSERT');
    }

    /**
     * SELECT con LIMIT y OFFSET.
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
     *
     * Acepta `false` en $params igual que Execute(): el default documentado de
     * `ncmExecute($sql, $array = false, ...)` es false, así que una query SIN
     * parámetros que pide assoc — `ncmExecute($sql, false, false, false, true)`
     * — llegaba acá con false y tiraba TypeError (capturado en producción,
     * GlitchTip issue 11). Execute ya toleraba false; la asimetría era el bug.
     */
    public function GetAssoc(string $sql, array|false $params = [], bool $force = false): array|false
    {
        if ($params === false) {
            $params = [];
        }
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
    public function CacheGetAssoc(int $secs, string $sql, array|false $params = []): array|false
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
     *   para que Execute() lo desenrolle (emula el Prepare legacy para statements).
     * - Si recibe un valor simple (ID, string corto), lo escapa como qstr().
     *
     * En este codebase Prepare() se usa de las dos formas.
     */
    public function Prepare(mixed $value): string
    {
        // En la API legacy, Prepare() devolvía [$sql, false, false] para caching.
        // En PDO no necesitamos ese wrapping — devolvemos el SQL directamente.
        if (is_array($value)) {
            return (string) $value[0];
        }
        $str = (string) $value;

        // UUIDs y numéricos son SQL-safe y se usan como params de `?` placeholders.
        // NO envolver en quotes — eso rompe PDO::bindValue (recibe "'uuid'" con
        // comillas embebidas y postgres lo rechaza con "invalid uuid syntax").
        // Detectar UUID v4/v7: 8-4-4-4-12 hex con guiones.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str)) {
            return $str;
        }
        if (is_numeric($str)) {
            return $str;
        }

        // Si parece un valor escalar (sin espacios ni ?), escaparlo como string literal.
        // Mantenido para callers legacy que concatenan directo en SQL (deberían
        // migrar a `?` placeholders — pendiente como deuda en roadmap).
        if (!str_contains($str, ' ') && !str_contains($str, '?')) {
            return $this->qstr($str);
        }
        return $str;
    }

    /**
     * Retorna el placeholder posicional ('?' para PostgreSQL).
     */
    public function Param(string $name = ''): string
    {
        return '?';
    }

    // ─── Transacciones ─────────────────────────────────────────────────────
    //
    // Smart transactions con contador de anidamiento: un service que abre TX
    // puede llamar a otro
    // service que también abre TX — solo el StartTrans más externo hace
    // beginTransaction real y solo su CompleteTrans commitea/rollbackea.
    // FailTrans en cualquier nivel marca TODA la transacción como fallida.
    // Sin esto, el beginTransaction anidado tira PDOException "There is
    // already an active transaction" (P0 cascada cancel espacios 2026-07-19).

    /** Profundidad de anidamiento de StartTrans (0 = sin TX activa). */
    private int $transDepth = 0;

    /** Inicia una transacción (o suma un nivel si ya hay una activa). */
    public function StartTrans(): void
    {
        if ($this->transDepth === 0) {
            $this->transOk     = true;
            $this->firstError  = '';
            $this->pdo->beginTransaction();
        }
        $this->transDepth++;
    }

    /**
     * Guarda el primer error de la transacción y no lo pisa nunca más.
     * Los 25P02 posteriores son consecuencia, no causa.
     */
    private function noteFirstError(string $message): void
    {
        if ($this->firstError === '') {
            $this->firstError = $message;
        }
    }

    /**
     * Error de origen de la transacción actual, o '' si no hubo.
     * Cuando la transacción falló, esto es lo que hay que reportar.
     */
    public function FirstError(): string
    {
        return $this->firstError;
    }

    /**
     * Cierra un nivel de transacción. Solo el nivel más externo confirma o
     * revierte (según FailTrans acumulado); los niveles anidados solo
     * decrementan y devuelven el estado actual.
     */
    public function CompleteTrans(): bool
    {
        if ($this->transDepth === 0) {
            return $this->transOk; // CompleteTrans sin StartTrans — no-op defensivo
        }
        $this->transDepth--;
        if ($this->transDepth > 0) {
            return $this->transOk;
        }
        if ($this->transOk) {
            $this->pdo->commit();
            return true;
        }
        $this->pdo->rollBack();
        return false;
    }

    /** Marca la transacción actual (todos los niveles) como fallida. */
    public function FailTrans(): void
    {
        $this->transOk = false;
    }

    /** Retorna true si la transacción fue marcada como fallida. */
    public function HasFailedTrans(): bool
    {
        return !$this->transOk;
    }

    /**
     * True si hay una transacción abierta (cualquier profundidad). Usado por
     * services para decidir si un side-effect (publish WS) corre ahora o
     * debe diferirse a después del commit del orquestador externo.
     */
    public function InTrans(): bool
    {
        return $this->transDepth > 0;
    }

    /**
     * Inicia transacción directa (PDO nativo).
     * Complemento de CommitTrans/RollbackTrans (patrón try/catch/rollback).
     * Diferente de StartTrans/CompleteTrans (patrón FailTrans de la API legacy).
     */
    public function BeginTrans(): void
    {
        $this->transOk = true;
        $this->pdo->beginTransaction();
    }

    /** Confirma transacción directa. */
    public function CommitTrans(): bool
    {
        $this->pdo->commit();
        return true;
    }

    /** Revierte transacción directa. */
    public function RollbackTrans(): bool
    {
        $this->pdo->rollBack();
        $this->transOk = false;
        return true;
    }

    // ─── Diagnóstico ───────────────────────────────────────────────────────

    /**
     * Retorna filas afectadas por el último DML (INSERT/UPDATE/DELETE).
     * Usa PDOStatement::rowCount() internamente.
     */
    public function Affected_Rows(): int
    {
        return $this->lastRowCount;
    }

    /**
     * true si la última Execute() fue un DML (INSERT/UPDATE/DELETE) SIN
     * cláusula RETURNING — es decir, una query que no devuelve filas aunque
     * haya aplicado cambios. Usado por Query::execute (ncmExecute) para
     * distinguir "DML exitoso con 0 filas retornadas" (no es error) de
     * "SELECT con 0 resultados" (semánticas de retorno distintas).
     */
    public function WasLastDml(): bool
    {
        return $this->lastWasDml;
    }

    /**
     * Si la transacción falló, devuelve el error de ORIGEN y no el último.
     * Todo caller que reporta un fallo lo hace después de la cascada de 25P02,
     * así que `lastError` a esa altura es siempre "current transaction is
     * aborted" — el síntoma. Sin esto el signup mostraba 25P02 pelado y la
     * causa real no aparecía en ningún lado.
     */
    public function ErrorMsg(): string
    {
        return $this->firstError !== '' ? $this->firstError : $this->lastError;
    }
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
     * Absorbe propiedades legacy no definidas (e.g. $db->outp, $db->bind)
     * sin lanzar errores.
     */
    public function __set(string $name, mixed $value): void {}
    public function __get(string $name): mixed { return null; }
}

/**
 * Normaliza a un array PHP NATIVO cualquier fila que salga del DB layer.
 *
 * `$rs->fields` y todo lo que derive de él son `CaseInsensitiveArray`: se leen
 * como array (implementan ArrayAccess) pero NO satisfacen un typehint `array`.
 * Cruzar con uno de ellos una frontera tipada —un `return` declarado `: array`,
 * un parámetro, un `array_*`— tira TypeError en runtime.
 *
 * La conversión estaba copiada a mano, con el mismo ternario, en ItemService,
 * VariantService, DrawerService y v1/items.php. Cada aparición nueva del bug se
 * había arreglado en su call-site; esta es la versión única. `Query.php` tiene
 * la variante que además contempla `Traversable` — delega acá para el caso CIA.
 *
 * Regla: `->fields` para leer suelto; `ncmRow()` cuando el valor cruza una
 * frontera que exige `array` de verdad.
 */
function ncmRow(mixed $row): array
{
    if ($row instanceof CaseInsensitiveArray) {
        return $row->toArray();
    }
    if ($row instanceof Traversable) {
        return iterator_to_array($row);
    }
    return (array) $row;
}
