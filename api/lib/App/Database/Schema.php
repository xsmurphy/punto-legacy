<?php
declare(strict_types=1);

namespace Punto\App\Database;

/**
 * Schema real de PostgreSQL: columnas, PK y columna JSONB de cada tabla.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * `_getTableSchema()` era una copia A MANO del schema: 22 tablas declaradas
 * sobre 137 que tiene la base. Cada migración que agregaba una columna abría
 * una divergencia, y el wrapper (`ncmInsert`/`ncmUpdate`) tomaba dos decisiones
 * críticas contra esa copia vieja. De ahí salieron dos familias de bug que se
 * repitieron durante meses:
 *
 *   1. **Columna real que termina en el JSONB.** Si la tabla estaba en el map
 *      pero la columna no, `_routeToJsonb` la trataba como campo desconocido y
 *      la escribía dentro de `data`. Los SELECT leen la columna → el dato nunca
 *      aparecía, y la request respondía 200. Así se perdieron `hasVariants` y
 *      `pinhash` (cambiar el PIN respondía OK y se seguía entrando con el
 *      viejo).
 *   2. **PK inventada o duplicada.** Si la tabla NO estaba en el map, la PK se
 *      resolvía contra el catálogo de PG, que devuelve los nombres en
 *      minúscula (`taxid`), mientras el código escribe en camelCase (`taxId`).
 *      El resultado fue `INSERT INTO tax (taxId, ..., taxid)` → "column
 *      specified more than once", y antes una PK inventada que mataba la
 *      extracción de caja.
 *
 * La causa común no era ninguno de los dos bugs: eran DOS FUENTES DE VERDAD
 * (el map a mano vs. el schema real) y DOS CONVENCIONES DE NOMBRE (camelCase en
 * el código vs. minúsculas en PG). Esta clase elimina las dos: el schema se lee
 * de `information_schema`, y los nombres se resuelven sin distinguir
 * mayúsculas devolviendo SIEMPRE el nombre real de la columna.
 *
 * ── Costo ───────────────────────────────────────────────────────────────────
 *
 * UNA query por request para TODAS las tablas (~137 tablas / ~1.500 columnas),
 * cacheada en estático. Con APCu disponible se cachea entre requests y la query
 * desaparece del path caliente. El cache se invalida solo: la clave incluye un
 * hash del catálogo, así que una migración que agrega una columna genera una
 * clave nueva sin que nadie tenga que acordarse de purgar nada.
 */
final class Schema
{
    /** @var array<string,array{columns: array<string,string>, pk: ?string, pkType: ?string, jsonb: ?string}>|null */
    private static ?array $cache = null;

    private const APCU_KEY = 'punto.schema.v1';

    /**
     * Descripción de una tabla, o null si no existe.
     *
     * `columns` mapea el nombre EN MINÚSCULA al nombre REAL de la columna, que
     * es lo que permite aceptar `taxId` y escribir `taxid` sin duplicar.
     *
     * @return array{columns: array<string,string>, pk: ?string, pkType: ?string, jsonb: ?string}|null
     */
    public static function table(string $table): ?array
    {
        $all = self::all();
        return $all[strtolower($table)] ?? null;
    }

    /**
     * Nombre REAL de una columna, aceptando cualquier capitalización.
     * null si la tabla no tiene esa columna.
     */
    public static function column(string $table, string $column): ?string
    {
        $t = self::table($table);
        return $t['columns'][strtolower($column)] ?? null;
    }

    /**
     * PK de la tabla con su nombre real, o null si no tiene o es compuesta.
     *
     * Compuesta → null a propósito: no hay UNA columna que generar, y elegir
     * una a ciegas escribe en la equivocada.
     */
    public static function primaryKey(string $table): ?string
    {
        return self::table($table)['pk'] ?? null;
    }

    /** ¿La PK es uuid? Es la única en la que tiene sentido generar un v7. */
    public static function primaryKeyIsUuid(string $table): bool
    {
        return (self::table($table)['pkType'] ?? null) === 'uuid';
    }

    /**
     * Columna JSONB donde enrutar los campos que no son columnas, o null si la
     * tabla no tiene ninguna.
     *
     * Si hay más de una (`data` y `meta`), gana la convencional en este orden:
     * data → config → meta → extra. El orden está fijo para que la elección no
     * dependa del orden físico de las columnas, que puede cambiar.
     */
    public static function jsonbColumn(string $table): ?string
    {
        return self::table($table)['jsonb'] ?? null;
    }

    /**
     * Separa un record en (columnas reales, campos para el JSONB).
     *
     * Las claves de las columnas se normalizan al nombre REAL: un record con
     * `taxId` sale con la clave que la tabla tiene de verdad, así que ya no
     * puede colisionar consigo misma.
     *
     * @param  array<string,mixed> $record
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: string}
     *         [columnas, extras JSONB, nombre de la columna JSONB]
     */
    public static function split(string $table, array $record): array
    {
        $meta = self::table($table);
        if ($meta === null) {
            // Tabla desconocida (vista, tabla de otro schema, o el catálogo no
            // se pudo leer): se devuelve el record tal cual. Si algo no existe,
            // el SQL falla — que es lo correcto. El silencio es peor.
            return [$record, [], ''];
        }

        $jsonbCol = $meta['jsonb'];
        $columns  = [];
        $extras   = [];

        foreach ($record as $key => $value) {
            $real = $meta['columns'][strtolower((string) $key)] ?? null;
            if ($real !== null) {
                $columns[$real] = $value;
                continue;
            }

            if ($jsonbCol === null) {
                // No es columna y no hay dónde enrutarlo. Se deja en el record
                // para que el SQL reviente con "column does not exist": un
                // campo que se pierde en silencio cuesta meses de detectar,
                // un error se ve en el primer intento.
                $columns[$key] = $value;
                continue;
            }

            $extras[$key] = $value;
        }

        return [$columns, $extras, $jsonbCol ?? ''];
    }

    /** Fuerza la relectura del catálogo. Para tests y para after-migrate. */
    public static function flush(): void
    {
        self::$cache = null;
        if (function_exists('apcu_delete')) {
            @apcu_delete(self::APCU_KEY);
        }
    }

    /**
     * @return array<string,array{columns: array<string,string>, pk: ?string, pkType: ?string, jsonb: ?string}>
     */
    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (function_exists('apcu_fetch')) {
            $hit = @apcu_fetch(self::APCU_KEY);
            if (is_array($hit) && isset($hit['stamp'], $hit['data']) && $hit['stamp'] === self::stamp()) {
                return self::$cache = $hit['data'];
            }
        }

        $built = self::build();
        self::$cache = $built;

        if (function_exists('apcu_store')) {
            // TTL corto además del stamp: si el stamp no se pudo calcular
            // (catálogo ilegible), el cache igual expira solo.
            @apcu_store(self::APCU_KEY, ['stamp' => self::stamp(), 'data' => $built], 300);
        }

        return $built;
    }

    /**
     * Huella del catálogo: cambia cuando cambia cualquier columna de cualquier
     * tabla. Es lo que hace que una migración invalide el cache sin que nadie
     * tenga que purgarlo a mano.
     */
    private static function stamp(): string
    {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }

        global $db;
        try {
            $rs = $db->Execute(
                "SELECT count(*)::text || ':' || COALESCE(max(attnum)::text, '0') AS s
                   FROM pg_attribute a
                   JOIN pg_class c ON c.oid = a.attrelid
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = current_schema() AND c.relkind IN ('r','p') AND a.attnum > 0 AND NOT a.attisdropped"
            );
            if ($rs && !$rs->EOF) {
                return $stamp = (string) ($rs->fields['s'] ?? 'na');
            }
        } catch (\Throwable $e) {
            error_log('[Schema] no se pudo calcular el stamp: ' . $e->getMessage());
        }
        return $stamp = 'na';
    }

    /**
     * @return array<string,array{columns: array<string,string>, pk: ?string, pkType: ?string, jsonb: ?string}>
     */
    private static function build(): array
    {
        global $db;

        $out = [];

        try {
            // Una sola pasada por todo el schema. `pg_attribute` en vez de
            // information_schema: es sensiblemente más rápido y trae el tipo y
            // la pertenencia a la PK en la misma fila.
            //
            // `relkind IN ('r','p')`: 'p' son tablas PARTICIONADAS (E1,
            // context/48 — `transaction`/`itemsold` desde la mig 156). Sin
            // esto una tabla particionada desaparecía del catálogo apenas se
            // migraba: `ncmInsert`/`Schema::split` dejaban de rutear sus
            // columnas y toda venta empezaba a fallar.
            //
            // `pk_first_attnum`: primer atnum de la PK, vía indkey[0] (int2vector
            // es indexable, 0-based). Se usa abajo para las PK COMPUESTAS de las
            // tablas particionadas (ver comentario en el loop).
            $rs = $db->Execute(
                "SELECT c.relname                    AS tabla,
                        c.relkind                    AS relkind,
                        a.attname                    AS col,
                        a.attnum                     AS attnum,
                        format_type(a.atttypid, NULL) AS tipo,
                        COALESCE(i.indisprimary, false) AS es_pk,
                        COALESCE(array_length(i.indkey::int[], 1), 0) AS pk_cols,
                        i.indkey[0]::int              AS pk_first_attnum
                   FROM pg_attribute a
                   JOIN pg_class c      ON c.oid = a.attrelid
                   JOIN pg_namespace n  ON n.oid = c.relnamespace
              LEFT JOIN pg_index i      ON i.indrelid = c.oid AND i.indisprimary
                                       AND a.attnum = ANY(i.indkey)
                  WHERE n.nspname = current_schema()
                    AND c.relkind IN ('r','p')
                    AND a.attnum > 0
                    AND NOT a.attisdropped"
            );

            while ($rs && !$rs->EOF) {
                $f     = $rs->fields;
                $table = strtolower((string) ($f['tabla'] ?? ''));
                $col   = (string) ($f['col'] ?? '');
                $tipo  = strtolower((string) ($f['tipo'] ?? ''));

                if ($table === '' || $col === '') {
                    $rs->MoveNext();
                    continue;
                }

                if (!isset($out[$table])) {
                    $out[$table] = ['columns' => [], 'pk' => null, 'pkType' => null, 'jsonb' => null];
                }

                $out[$table]['columns'][strtolower($col)] = $col;

                // PK simple (pk_cols === 1): sin cambios, es el caso normal.
                //
                // PK COMPUESTA (pk_cols > 1) en una tabla PARTICIONADA
                // (relkind='p'): Postgres exige que la columna de partición
                // forme parte de la PK, así que `transaction`/`itemsold`
                // (mig 156) tienen PK (transactionid, transactiondate) /
                // (itemsoldid, itemsolddate). La columna de identidad real
                // sigue siendo la PRIMERA (transactionid/itemsoldid) — la
                // segunda está ahí solo para satisfacer al motor. Se expone
                // esa primera columna como `pk`/`pkType` (vía indkey[0]) para
                // que `ncmInsert`/`AutoExecute` sigan generando su UUID v7
                // igual que antes del particionado. Una PK compuesta de una
                // tabla NO particionada sigue ignorándose (comportamiento
                // viejo): ahí sí no hay UNA columna que generar.
                $esPk    = self::truthy($f['es_pk'] ?? false);
                $pkCols  = (int) ($f['pk_cols'] ?? 0);
                $relkind = (string) ($f['relkind'] ?? 'r');
                $attnum  = (int) ($f['attnum'] ?? -1);
                $pkFirstAttnum = isset($f['pk_first_attnum']) && $f['pk_first_attnum'] !== null
                    ? (int) $f['pk_first_attnum']
                    : null;

                $esIdentidadDeLaPk = $pkCols === 1
                    || ($relkind === 'p' && $pkFirstAttnum !== null && $attnum === $pkFirstAttnum);

                if ($esPk && $esIdentidadDeLaPk) {
                    $out[$table]['pk']     = $col;
                    $out[$table]['pkType'] = $tipo;
                }

                if ($tipo === 'jsonb') {
                    $out[$table]['jsonb'] = self::pickJsonb($out[$table]['jsonb'], $col);
                }

                $rs->MoveNext();
            }
        } catch (\Throwable $e) {
            // Falla cerrada: sin schema, `split()` devuelve el record intacto y
            // el SQL decide. Nunca se inventa una columna ni se traga un campo.
            error_log('[Schema] no se pudo leer el catálogo: ' . $e->getMessage());
            return [];
        }

        return $out;
    }

    /**
     * Elige la columna JSONB "de destino" con un orden fijo de preferencia.
     * Sin esto, una tabla con `data` y `meta` dependería del orden físico.
     */
    private static function pickJsonb(?string $actual, string $candidato): string
    {
        $orden = ['data' => 0, 'config' => 1, 'meta' => 2, 'extra' => 3];
        if ($actual === null) {
            return $candidato;
        }
        $ra = $orden[strtolower($actual)]    ?? 99;
        $rc = $orden[strtolower($candidato)] ?? 99;
        return $rc < $ra ? $candidato : $actual;
    }

    /**
     * PG devuelve booleanos como bool nativo con pdo_pgsql, pero el wrapper
     * puede entregar 't'/'f' según por dónde pase la fila.
     */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));
        return $s === 't' || $s === 'true' || $s === '1';
    }
}
