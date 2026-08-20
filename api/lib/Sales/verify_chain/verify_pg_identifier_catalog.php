<?php

declare(strict_types=1);

/**
 * verify_pg_identifier_catalog.php — verificador de COBERTURA TOTAL para dos
 * clases de bug de identificadores Postgres que no existen en el catálogo
 * real: (1) identificadores ENTRECOMILLADOS con case incorrecto (ver mig
 * 150_normalizar_identificadores_camelcase.sql para el porqué completo del
 * mecanismo) y (2) columnas CALIFICADAS (`alias.columna`, con o sin
 * comillas) que no existen en la tabla real del alias — la clase de bug del
 * incidente 2026-08-19 (`ci.itemUOM` SIN comillas en
 * `api/lib/Items/ItemsQuery.php`: Postgres abortó el SELECT ENTERO de
 * `/v1/items`, el POS se quedó sin catálogo/hotkeys en todos los
 * dispositivos; el chequeo B de abajo, que ya existía, no lo atrapó porque
 * solo mira identificadores ENTRECOMILLADOS). A diferencia de
 * verify_pg_identifiers.php (arnés de comportamiento que ejercita
 * incidentes puntuales YA corregidos, sin mockear nada), este script NO
 * ejecuta código de la app: es un LINT que escanea el SQL del repo y lo
 * compara contra el catálogo REAL de la Postgres viva (information_schema),
 * fallando y listando cada discrepancia archivo:línea — para que la
 * próxima columna que no existe truene ACÁ, en run.sh, antes de llegar a
 * producción, en vez de esperar al próximo incidente en runtime.
 *
 * ═══ Qué chequea ═══
 *
 * A) Schema-side (regresión del catálogo): consulta en vivo
 *    information_schema.columns/tables del schema `public` y falla si
 *    encuentra CUALQUIER tabla o columna cuyo nombre físico tenga una
 *    mayúscula. Después de la mig 150 esto debe dar CERO filas siempre —
 *    si algún día no da cero, alguien reintrodujo el problema en una
 *    migración nueva (recordatorio: migraciones YA APLICADAS no se editan,
 *    ver docblock de migrate.php — el fix, si hace falta, es SIEMPRE una
 *    migración nueva).
 *
 * B) Code-side (regresión del código): escanea todo el PHP bajo `api/`
 *    (vía token_get_all — strings reales: T_CONSTANT_ENCAPSED_STRING
 *    [strings SIN interpolación] Y T_ENCAPSED_AND_WHITESPACE [los tramos de
 *    texto fijo de un string CON interpolación, `"...{$var}..."`, o de un
 *    heredoc/nowdoc — PHP nunca tokeniza ninguno de esos dos como
 *    T_CONSTANT_ENCAPSED_STRING; agregado 2026-08-20, ver "Límites
 *    conocidos" abajo para el incidente real que expuso el gap] — no
 *    falsos positivos de comentarios ni de código no-string) y todo `.sql`
 *    del repo, buscando identificadores citados entre comillas dobles
 *    (`"Foo"` o, dentro de un string PHP con comillas dobles, `\"Foo\"`)
 *    que:
 *      1. Aparecen en un fragmento que "parece SQL": o bien contiene una
 *         palabra clave SQL EN MAYÚSCULA (SELECT/INSERT/UPDATE/DELETE/FROM/
 *         JOIN/WHERE/SET/VALUES/RETURNING/CREATE/ALTER/TRIGGER/INDEX/
 *         CONSTRAINT — case-SENSITIVE a propósito, ver docblock de
 *         SQL_KEYWORDS_RE en verify_pg_identifier_catalog_lib.php), o bien
 *         tiene la FORMA de un fragmento de WHERE/SET armado a mano
 *         (`"col" = ?`, `"col" IS NULL`, etc. — patrón real de este repo:
 *         PrintPoolService/StockTransferService/InventoryCountService arman
 *         arrays `$where[]`/`$sets[]` de fragmentos SIN keyword propia,
 *         unidos después con implode()). Para `.php` este gate se evalúa
 *         POR STRING/TRAMO (cada string o tramo interpolado es su propia
 *         unidad — evita escanear mensajes de UI/error de OTROS
 *         strings/tramos del mismo archivo que casualmente tengan una
 *         palabra con mayúscula entre comillas).
 *         Para `.sql` se evalúa por ARCHIVO ENTERO (ver Límites conocidos).
 *      2. Tienen al menos una mayúscula en el nombre citado.
 *      3. NO están inmediatamente precedidos por `AS ` (case-insensitive,
 *         espacio arbitrario) — un alias de salida deliberado (ej.
 *         `ag."minSelect" AS "gMinSelect"`, `AS "totalItems"`) le da forma
 *         camelCase al JSON/array de respuesta a propósito; no es una
 *         referencia a un identificador físico y NUNCA debe validarse
 *         contra el catálogo de columnas. Esta es la MISMA regla de
 *         seguridad que se usó para hacer el reemplazo automático de los
 *         143 call-sites de la mig 150 (ver el commit de ese trabajo) — acá
 *         se congela como chequeo permanente en vez de quedar solo en un
 *         script de una sola vez.
 *      4. Están en POSICIÓN de identificador real: arranque del fragmento,
 *         o inmediatamente después de espacio/`(`/`,`/`.` — filtra JSON
 *         embebido dentro de un patrón LIKE de datos (encontrado en la
 *         práctica en PaymentMethodService.php:
 *         `... NOT LIKE '%\"systemKey\":\"cash\"%'`, donde la comilla está
 *         DENTRO de un literal de un solo quote, precedida por `%`, nunca
 *         en posición de identificador).
 *    Para cada identificador que sobrevive esos 4 filtros, se compara
 *    contra el catálogo real (mismo que A, TODAS las tablas/columnas del
 *    schema, no solo las 18 de la mig 150): si NO matchea ningún nombre
 *    EXACTO (case-sensitive) → discrepancia, se reporta archivo:línea.
 *
 * C) Code-side (columnas calificadas, CON o SIN comillas): sobre las MISMAS
 *    unidades de texto que B (string PHP individual / archivo .sql entero),
 *    arma un mapa alias→tabla real leyendo los FROM/JOIN/UPDATE del propio
 *    bloque (validando cada nombre de tabla contra information_schema.tables
 *    ANTES de confiar en el match — así "LATERAL", una subconsulta o
 *    cualquier palabra reservada nunca generan un mapeo falso) y valida
 *    cada referencia `alias.columna` contra $columnsByTable[tabla]
 *    (case-insensitive). Si el alias no resuelve a una tabla real (LATERAL,
 *    subconsulta, CTE, SQL partido entre fragmentos PHP distintos, alias
 *    ambiguo) la referencia se OMITE explícitamente — nunca se adivina ni
 *    se reporta. Detalle completo (por qué es seguro, qué se omite y por
 *    qué) en el docblock de findBadQualifiedRefs() más abajo. Cero overlap
 *    de falsos positivos con B: B solo mira DENTRO de comillas, C mira
 *    `alias.columna` esté o no citado, pero solo cuando el alias resolvió a
 *    una tabla real — B puede marcar un identificador citado que no existe
 *    en NINGUNA tabla, C marca uno (citado o no) que no existe en LA tabla
 *    específica del alias; ambos pueden coincidir en el mismo bug real sin
 *    que eso sea un problema (dos reportes de la misma discrepancia, no una
 *    discrepancia inventada).
 *
 * ═══ Límites conocidos (documentados, no bloqueantes) ═══
 *
 * - (RESUELTO 2026-08-20, dejado documentado por el incidente real que lo
 *   expuso) Strings PHP CON interpolación (`"SELECT ... {$whereSql}"`) y
 *   heredoc/nowdoc NO se tokenizan como T_CONSTANT_ENCAPSED_STRING — PHP los
 *   parte en T_ENCAPSED_AND_WHITESPACE (los tramos de texto fijo) +
 *   T_VARIABLE/etc (las partes interpoladas). La versión original de este
 *   verificador (chequeo B, entrecomillados) SOLO miraba
 *   T_CONSTANT_ENCAPSED_STRING, así que CUALQUIER SQL armado con
 *   interpolación quedaba invisible para los dos chequeos — encontrado
 *   reintroduciendo a mano el bug real del incidente 2026-08-19
 *   (`ci.itemUOM` sin comillas) en `ItemsQuery.php::buildItemsSelectSql()`
 *   como prueba de aceptación de esta extensión: el SELECT completo de esa
 *   función es UN SOLO string PHP con `{$whereSql}`/`{$tailSql}`
 *   interpolados al final (líneas ~158-286), así que ES el archivo exacto
 *   del incidente real, y el verificador pasaba en limpio antes de este
 *   fix. Grep confirmó 71 archivos del repo con SQL dentro de un string
 *   interpolado de esta forma — ninguno de esos 71 era escaneado por
 *   ningún chequeo hasta este fix. `extractPhpSqlStrings()` en
 *   verify_pg_identifier_catalog_lib.php ahora captura AMBOS tipos de
 *   token (ver su docblock).
 * - Concatenación de fragmentos entre keyword SQL e identificador en
 *   strings PHP DISTINTOS (ej. `'SELECT ' . '"badCol" FROM foo'`) no se
 *   detecta si el fragmento con la keyword y el fragmento con la comilla
 *   quedan en tokens separados — cubierto en la práctica por el gate de
 *   FRAGMENTO (punto 1 arriba) para el patrón real que existe en este
 *   repo (arrays de WHERE/SET), pero no es un parser SQL completo.
 * - maskSqlStringLiterals() reconoce el escapado SQL estándar de comilla
 *   simple duplicada (`''` dentro de un literal '...') pero NO el escapado
 *   PHP de una comilla simple dentro de un string PHP single-quoted
 *   (`\'`) — si algún día se escribe un literal SQL con un valor que
 *   contenga una comilla simple embebida directamente en el código PHP
 *   (en vez de vía bind param `?`, que es la convención de todo este
 *   repo hoy — verificado, cero excepciones), el enmascarado podría
 *   perder la sincronía de "adentro/afuera de string" a partir de ese
 *   punto. Bajo impacto: peor caso es un falso negativo puntual en esa
 *   línea, no un falso positivo masivo ni un crash.
 *
 * ═══ Qué NO escanea (a propósito) ═══
 *
 * `api/database/migrations/postgres/*.{sql,php}` — las migraciones son
 * HISTORIA INMUTABLE (editar una ya aplicada no tiene efecto, ver docblock
 * de migrate.php). Las migraciones viejas (29, 35, 44, 46, 47, 54, 59, 69,
 * 78, 83, 107, 109, 134, 136, 138, 141...) citan camelCase A PROPÓSITO —
 * así se crearon esas columnas originalmente, es la causa raíz que la mig
 * 150 corrige, no un bug del código actual. Escanearlas generaría cientos
 * de "discrepancias" sobre SQL que ya corrió y no se puede tocar. La
 * migración 150 misma también cae acá (cita las 143 columnas camelCase
 * VIEJAS a propósito, en el lado izquierdo de cada RENAME COLUMN). La
 * protección contra que una migración FUTURA reintroduzca el problema la
 * da el chequeo (A) de arriba, que mira el catálogo real después de que la
 * migración corrió — no hace falta escanear el SQL de la migración en sí.
 *
 * `db-schema-postgres.sql` — MISMA categoría que las migraciones, por la
 * MISMA razón, aunque no viva en `migrations/postgres/`: es el punto de
 * partida de un install fresco, que corre ANTES de las migraciones 01+
 * (ver `run.sh` y el docblock de `migrate.php`). Dos de sus tablas
 * (`admin_audit`, `tenant_audit`) se crean ahí CON comillas camelCase A
 * PROPÓSITO — la migración 29/35 (inmutables, YA corrieron en prod) hacen
 * `CREATE INDEX ... ON admin_audit("createdAt")` esperando ESE nombre
 * exacto; si `db-schema-postgres.sql` las creara ya en lowercase, esas
 * migraciones (que no se pueden tocar) revientan con "column does not
 * exist" en cualquier bootstrap fresco — pasó de verdad, se intentó
 * normalizar este archivo directamente en un commit anterior de esta misma
 * rama y rompió `run.sh` en modo Postgres descartable; revertido. El único
 * camino correcto es: schema base crea camelCase → migraciones 29/35/36
 * corren igual que en prod → mig 150 normaliza — un solo camino, una sola
 * fuente de verdad para el estado FINAL, que es lo que el chequeo (A) de
 * arriba valida contra el catálogo vivo (no lo que diga este archivo).
 * Por eso NO se escanea acá: escanearlo generaría el mismo tipo de "cientos
 * de discrepancias sobre SQL pre-normalización" que las migraciones.
 *
 * `api/database/seeds/postgres/*.sql` SÍ se escanea (no son bootstrap de
 * schema, son fixtures de dev que se insertan DESPUÉS de que corrieron
 * todas las migraciones — deben estar 100% en el estado final), igual que
 * `api/lib/Sales/verify_chain/seed.sql`.
 *
 * Uso: ver run.sh, que lo corre como paso propio con las mismas env vars
 * POSTGRES_* que el resto del arnés. No requiere bootstrap.php ni ningún
 * dato sembrado — es de solo lectura contra information_schema + lectura
 * de archivos del repo. Exit code 0 si limpio, 1 si hay discrepancias.
 */

// __DIR__ = api/lib/Sales/verify_chain — 4 niveles arriba es la raíz del
// repo (api/lib/Sales/verify_chain -> api/lib/Sales -> api/lib -> api ->
// raíz). Mismo cómputo que verify_pg_identifiers.php:85 usa para llegar a
// bootstrap.php (dirname(__DIR__, 3) . '/bootstrap.php', o sea api/), un
// nivel menos porque ese apunta a api/ y acá necesitamos la raíz.
$repoRoot = dirname(__DIR__, 4);
$apiDir   = $repoRoot . '/api';

require_once $apiDir . '/database/pg_pdo_connect.php';

try {
    $pdo = pgConnectFromEnv($repoRoot);
} catch (PDOException $e) {
    fwrite(STDERR, "[verify_pg_identifier_catalog] PDO connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[verify_pg_identifier_catalog] verificá POSTGRES_HOST/USER/PASSWORD/DB env vars\n");
    exit(1);
}
$host = $_ENV['POSTGRES_HOST'] ?? getenv('POSTGRES_HOST') ?: 'localhost';
$dbnm = $_ENV['POSTGRES_DB']   ?? getenv('POSTGRES_DB')   ?: 'puntoDB';

// ═══════════════════════════════════════════════════════════════════════
// A) Schema-side: cero tablas/columnas con mayúscula en el catálogo real.
// ═══════════════════════════════════════════════════════════════════════
$failures = [];

$badCols = $pdo->query(
    "SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema = 'public' AND column_name <> lower(column_name)
      ORDER BY 1, 2"
)->fetchAll();
foreach ($badCols as $row) {
    $failures[] = sprintf(
        '[schema] columna camelCase viva en el catálogo: %s.%s (reintroducida fuera de la mig 150 — corregir con una migración NUEVA, nunca editando una ya aplicada)',
        $row['table_name'],
        $row['column_name']
    );
}

$badTables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = 'public' AND table_name <> lower(table_name)
      ORDER BY 1"
)->fetchAll();
foreach ($badTables as $row) {
    $failures[] = sprintf('[schema] tabla camelCase viva en el catálogo: %s', $row['table_name']);
}

// Catálogo completo para el chequeo B (case-sensitive, incluye TODAS las
// tablas/columnas del schema, no solo las 18 de la mig 150 — un
// identificador citado puede ser legítimo si matchea CUALQUIER objeto real).
$validColumns = [];
foreach ($pdo->query("SELECT DISTINCT column_name FROM information_schema.columns WHERE table_schema = 'public'") as $row) {
    $validColumns[$row['column_name']] = true;
}
$validTables = [];
foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'") as $row) {
    $validTables[$row['table_name']] = true;
}

// Catálogo agrupado POR TABLA (columnsByTable[tabla][columna] = true), para
// el chequeo C de abajo: a diferencia de $validColumns (plano, "¿existe esta
// columna EN ALGÚN LADO del schema?"), acá necesitamos "¿existe esta columna
// EN LA TABLA que resolvimos para este alias?" — más estricto, y es lo único
// que hubiera atrapado `ci.itemUOM` (ninguna tabla tiene `itemuom`, pero
// además aunque alguna OTRA tabla la tuviera, `item` no la tiene, que es lo
// que importa). Todas las claves ya vienen lowercase (catálogo 100%
// normalizado post mig 150), por eso el lookup de abajo compara
// case-insensitive con strtolower() en vez de duplicar la normalización acá.
$columnsByTable = [];
foreach ($pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public'") as $row) {
    $columnsByTable[$row['table_name']][$row['column_name']] = true;
}

// ═══════════════════════════════════════════════════════════════════════
// B) Code-side: escanear el repo.
// ═══════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/verify_pg_identifier_catalog_lib.php';

$scanned     = 0;
$qualChecked = 0; // referencias alias.columna resueltas contra una tabla real (OK o falla).
$qualOmitted = 0; // referencias alias.columna cuyo alias no resolvió — nunca reportadas.

// .php bajo api/, EXCLUYENDO api/database/migrations/postgres (historia
// inmutable, ver docblock de arriba).
$migrationsDir = $apiDir . '/database/migrations/postgres';
foreach (walkFiles($apiDir, 'php') as $path) {
    if (str_starts_with($path, $migrationsDir)) {
        continue;
    }
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    $scanned++;
    $relPath = substr($path, strlen($repoRoot) + 1);
    foreach (extractPhpSqlStrings($code) as [$text, $line]) {
        foreach (scanForBadQuotedIdentifiers($text, $validColumns, $validTables) as $bad) {
            $failures[] = sprintf(
                '[code] %s:%d — "%s" citado entre comillas con mayúscula, no matchea ningún objeto real del catálogo Postgres',
                $relPath,
                $line,
                $bad
            );
        }
        // Chequeo C: columnas calificadas (alias.columna, con o sin
        // comillas) resueltas contra la tabla real del alias — ver
        // docblock de findBadQualifiedRefs. $line es la línea de INICIO
        // del string PHP; se suma el número de saltos de línea hasta el
        // offset del match para apuntar a la línea EXACTA (más preciso
        // que el chequeo B de arriba, que reporta solo la línea de
        // inicio del string).
        $qr           = findBadQualifiedRefs($text, $columnsByTable, $validTables);
        $qualChecked += $qr['checked'];
        $qualOmitted += $qr['omitted'];
        foreach ($qr['failures'] as [$alias, $table, $col, $offset]) {
            $exactLine = $line + substr_count(substr($text, 0, $offset), "\n");
            $failures[] = sprintf(
                '[code-qualified] %s:%d — "%s.%s" no matchea ninguna columna real de "%s" (alias "%s" resuelto vía FROM/JOIN en el mismo bloque SQL)',
                $relPath,
                $exactLine,
                $alias,
                $col,
                $table,
                $alias
            );
        }
    }
}

// .sql en TODO el repo, EXCLUYENDO api/database/migrations/postgres Y
// db-schema-postgres.sql (bootstrap PRE-migraciones, misma categoría que
// las migraciones inmutables — ver "Qué NO escanea" arriba: admin_audit/
// tenant_audit se crean ahí con camelCase A PROPÓSITO para que las
// migraciones 29/35 ya aplicadas encuentren las columnas que esperan).
// Gate + scan a nivel ARCHIVO ENTERO (no por línea, ver docblock de
// scanSqlFileForBadIdentifiers) — cubre CREATE TABLE multi-línea donde la
// keyword y las columnas citadas están en líneas distintas.
$dbSchemaFile = $repoRoot . '/db-schema-postgres.sql';
foreach (walkFiles($repoRoot, 'sql') as $path) {
    if (str_starts_with($path, $migrationsDir) || $path === $dbSchemaFile) {
        continue;
    }
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    $scanned++;
    $relPath = substr($path, strlen($repoRoot) + 1);
    foreach (scanSqlFileForBadIdentifiers($code, $validColumns, $validTables) as [$bad, $lineNo]) {
        $failures[] = sprintf(
            '[code] %s:%d — "%s" citado entre comillas con mayúscula, no matchea ningún objeto real del catálogo Postgres',
            $relPath,
            $lineNo,
            $bad
        );
    }
    // Chequeo C sobre el archivo .sql entero — misma granularidad que el
    // chequeo B para .sql (ver docblock de scanSqlFileForBadIdentifiers).
    $qr           = findBadQualifiedRefs($code, $columnsByTable, $validTables);
    $qualChecked += $qr['checked'];
    $qualOmitted += $qr['omitted'];
    foreach ($qr['failures'] as [$alias, $table, $col, $offset]) {
        $lineNo     = substr_count(substr($code, 0, $offset), "\n") + 1;
        $failures[] = sprintf(
            '[code-qualified] %s:%d — "%s.%s" no matchea ninguna columna real de "%s" (alias "%s" resuelto vía FROM/JOIN en el mismo bloque SQL)',
            $relPath,
            $lineNo,
            $alias,
            $col,
            $table,
            $alias
        );
    }
}

echo "[verify_pg_identifier_catalog] catálogo: " . count($validColumns) . " nombres de columna únicos, " . count($validTables) . " tablas, en $dbnm@$host\n";
echo "[verify_pg_identifier_catalog] escaneados $scanned archivo(s) (.php + .sql bajo api/ y raíz, excluyendo api/database/migrations/postgres)\n";
echo "[verify_pg_identifier_catalog] columnas calificadas (alias.columna): $qualChecked resueltas y validadas contra su tabla real, $qualOmitted omitidas (alias sin FROM/JOIN resoluble en el mismo bloque — LATERAL, subconsulta, SQL partido entre fragmentos)\n";

if ($failures !== []) {
    fwrite(STDERR, "[verify_pg_identifier_catalog] FALLÓ — " . count($failures) . " discrepancia(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_pg_identifier_catalog] TODO OK — cero identificadores camelCase-quoted huérfanos, catálogo 100% lowercase\n";
exit(0);
