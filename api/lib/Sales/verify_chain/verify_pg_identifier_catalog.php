<?php

declare(strict_types=1);

/**
 * verify_pg_identifier_catalog.php — verificador de COBERTURA TOTAL para la
 * clase de bug de identificadores Postgres entrecomillados con case
 * incorrecto (ver mig 150_normalizar_identificadores_camelcase.sql para el
 * porqué completo del mecanismo). A diferencia de verify_pg_identifiers.php
 * (arnés de comportamiento que ejercita 6 incidentes puntuales YA
 * corregidos, sin mockear nada), este script NO ejecuta código de la app:
 * es un LINT que escanea el SQL del repo en busca de identificadores
 * entrecomillados con al menos una mayúscula y los compara contra el
 * catálogo REAL de la Postgres viva (information_schema), fallando y
 * listando cada discrepancia — para que la próxima columna camelCase-quoted
 * que alguien introduzca por accidente truene ACÁ, en run.sh, antes de
 * llegar a producción, en vez de esperar al sexto incidente en runtime
 * (ya van cinco documentados, ver mig 150 para el detalle de cada uno).
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
 *    (vía token_get_all — solo strings reales, T_CONSTANT_ENCAPSED_STRING,
 *    no falsos positivos de comentarios ni de código no-string; no hay
 *    heredoc/nowdoc en este repo hoy, ver "Límites conocidos" abajo) y todo
 *    `.sql` del repo, buscando identificadores citados entre comillas
 *    dobles (`"Foo"` o, dentro de un string PHP con comillas dobles,
 *    `\"Foo\"`) que:
 *      1. Aparecen en un fragmento que "parece SQL": o bien contiene una
 *         palabra clave SQL (SELECT/INSERT/UPDATE/DELETE/FROM/JOIN/WHERE/
 *         SET/VALUES/RETURNING/CREATE/ALTER/TRIGGER/INDEX/CONSTRAINT), o
 *         bien tiene la FORMA de un fragmento de WHERE/SET armado a mano
 *         (`"col" = ?`, `"col" IS NULL`, etc. — patrón real de este repo:
 *         PrintPoolService/StockTransferService/InventoryCountService arman
 *         arrays `$where[]`/`$sets[]` de fragmentos SIN keyword propia,
 *         unidos después con implode()). Para `.php` este gate se evalúa
 *         POR STRING LITERAL (cada string PHP es su propia unidad — evita
 *         escanear mensajes de UI/error de OTROS strings del mismo archivo
 *         que casualmente tengan una palabra con mayúscula entre comillas).
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
 * ═══ Límites conocidos (documentados, no bloqueantes) ═══
 *
 * - Heredoc/nowdoc (`<<<SQL ... SQL`) no se detecta — token_get_all() no
 *   los tokeniza como T_CONSTANT_ENCAPSED_STRING. Verificado (grep) que
 *   NINGÚN .php de este repo usa heredoc/nowdoc hoy; si se adopta ese
 *   estilo en el futuro, este verificador necesita extenderse.
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
 * `api/database/seeds/postgres/*.sql` SÍ se escanea (no son migraciones,
 * son fixtures de dev editables), igual que `api/lib/Sales/verify_chain/
 * seed.sql` y `db-schema-postgres.sql` (schema base de un install fresco,
 * código vivo, se edita cuando hace falta).
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

// ═══════════════════════════════════════════════════════════════════════
// B) Code-side: escanear el repo.
// ═══════════════════════════════════════════════════════════════════════
const SQL_KEYWORDS_RE = '/\b(SELECT|INSERT|UPDATE|DELETE|FROM|JOIN|WHERE|SET|VALUES|RETURNING|INTO|GROUP\s+BY|ORDER\s+BY|CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+INDEX|CREATE\s+TRIGGER|ON\s+CONFLICT|CONSTRAINT)\b/i';

// Fragmentos de WHERE/SET armados a mano en arrays ($where[] = '"col" = ?';
// más abajo un implode(' AND ', $where)) NO contienen ninguna palabra clave
// SQL propia — son un patrón real de este codebase (PrintPoolService,
// StockTransferService, InventoryCountService antes del fix de la mig 150).
// Sin este gate, un fragmento reintroducido con comillas camelCase (ej.
// `$where[] = '"badCol" = ?';`) nunca tendría SELECT/WHERE/etc en el MISMO
// string literal y se colaría sin marcar. Matchea un string que EMPIEZA
// (tras trim) con un identificador citado seguido de un operador de
// comparación típico.
const SQL_FRAGMENT_RE = '/^\s*\\\\?"[A-Za-z_]\w*\\\\?"\s*(=|<>|!=|>=|<=|>|<|\bIS\b|\bIN\b|\bLIKE\b)/i';

// `"Ident"` o `\"Ident\"` — identificador Postgres válido, con al menos una
// mayúscula en algún punto (si no tiene mayúscula, citarlo es válido/no-op
// y fuera del alcance de este bug).
const QUOTED_IDENT_RE = '/(\\\\?)"([A-Za-z_][A-Za-z0-9_]{0,62})(\\\\?)"/';

const AS_BEFORE_RE = '/\bAS\s*$/i';

// Posición válida para que una comilla ABRA un identificador SQL real:
// arranque del string, o inmediatamente después de espacio/newline, `(`,
// `,` o `.` (alias de tabla). Chequeo barato adicional, defensa en
// profundidad — el filtro REAL contra el falso positivo de datos
// entrecomillados es maskSqlStringLiterals() de abajo.
const VALID_IDENT_START_RE = '/(^|[\s(,.])$/';

/**
 * Enmascara el CONTENIDO de literales '...' de un solo quote (deja los
 * caracteres `'` delimitadores y los saltos de línea intactos, para no
 * mover offsets ni romper el conteo de línea; todo lo demás adentro pasa a
 * espacio). Respeta el escape SQL estándar de comilla simple duplicada
 * (`''` dentro de un literal = una comilla literal, NO cierra el string).
 *
 * Por qué hace falta: un JSON embebido como valor de un literal SQL (ej.
 * `'{"settingName":"Demo Company",...}'` en los seeds de dev, o
 * `'%\"systemKey\":\"cash\"%'` en un LIKE de PaymentMethodService.php) usa
 * EXACTAMENTE la misma forma sintáctica local que una lista real de
 * identificadores citados (comillas dobles separadas por coma/espacio/
 * llave) — ninguna heurística posicional barata distingue ambos casos de
 * forma confiable. Enmascarar el contenido de los literales '...' ANTES de
 * buscar `"identificador"` es la única forma correcta de garantizar que
 * QUOTED_IDENT_RE solo pueda matchear comillas que están en sintaxis SQL
 * real (fuera de cualquier literal de datos), sin falsos positivos.
 *
 * Encontrado en la práctica: un dry-run de este verificador contra el
 * repo real (con catálogo vacío para maximizar falsos positivos) tiraba
 * ~68 candidatos, TODOS claves JSON dentro de literales '...' en los
 * seeds de dev (api/database/seeds/postgres/*.sql, verify_chain/seed.sql)
 * antes de agregar este enmascarado — cero después.
 */
function maskSqlStringLiterals(string $sql): string
{
    $out      = '';
    $len      = strlen($sql);
    $inString = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($ch === "\n") {
            $out .= "\n"; // nunca enmascarar saltos de línea: rompería el conteo de línea.
            continue;
        }
        if ($inString) {
            if ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $out .= '  '; // comilla escapada (''), sigue dentro del literal.
                    $i++;
                    continue;
                }
                $inString = false;
                $out     .= $ch; // comilla de cierre real, sin enmascarar.
                continue;
            }
            $out .= ' ';
            continue;
        }
        if ($ch === "'") {
            $inString = true;
        }
        $out .= $ch;
    }
    return $out;
}

/**
 * @return array<int, array{0:string,1:int}> lista de [texto_string, línea_inicio]
 *
 * El texto devuelto es el CONTENIDO del string PHP, sin las comillas de
 * delimitación de PHP (primer/último carácter del token, siempre el mismo
 * — simple o doble — para T_CONSTANT_ENCAPSED_STRING). Es necesario
 * pelarlas ACÁ: si se dejan, maskSqlStringLiterals() ve la comilla simple
 * de apertura de un string PHP `'SELECT "companyId" ...'` como si fuera el
 * inicio de un literal SQL '...' y enmascara TODO el contenido de adentro
 * como si fuera data — silenciando cualquier identificador real que
 * hubiera SQL adentro (bug real, encontrado corriendo este mismo
 * verificador contra un snippet de prueba antes de wirearlo a run.sh: con
 * el token completo sin pelar, CERO de los identificadores camelCase
 * inyectados a mano en un string PHP single-quoted se detectaban).
 */
function extractPhpSqlStrings(string $code): array
{
    $out    = [];
    $tokens = token_get_all($code);
    foreach ($tokens as $tok) {
        if (is_array($tok)) {
            [$id, $text, $tokLine] = $tok;
            if ($id === T_CONSTANT_ENCAPSED_STRING && strlen($text) >= 2) {
                $out[] = [substr($text, 1, -1), $tokLine];
            }
        }
    }
    return $out;
}

/**
 * Núcleo compartido: dado un bloque de texto "que parece SQL", devuelve
 * los [identificador, offset_en_$text] en discrepancia. Opera sobre una
 * copia con los literales '...' enmascarados (maskSqlStringLiterals) para
 * que QUOTED_IDENT_RE solo pueda matchear comillas en sintaxis SQL real,
 * nunca datos (JSON embebido, patrones LIKE, etc.) — el offset devuelto es
 * válido tanto contra el texto enmascarado como contra el original porque
 * el enmascarado preserva longitud y saltos de línea exactos.
 *
 * @return list<array{0:string,1:int}> lista de [identificador, offset]
 */
function findBadIdentifiers(string $text, array $validColumns, array $validTables): array
{
    if (!preg_match(SQL_KEYWORDS_RE, $text) && !preg_match(SQL_FRAGMENT_RE, $text)) {
        return [];
    }
    $masked = maskSqlStringLiterals($text);
    $bad    = [];
    if (!preg_match_all(QUOTED_IDENT_RE, $masked, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($matches[2] as $i => $m) {
        [$name] = $m;
        if (!preg_match('/[A-Z]/', $name)) {
            continue; // sin mayúscula: no es el bug de esta clase.
        }
        // OJO: el offset de "AS antes de esto" tiene que medirse desde el
        // arranque del match COMPLETO (la comilla/backslash de apertura,
        // $matches[0]), no desde el arranque del grupo 2 (el nombre) — si
        // se mide desde el grupo 2, el texto previo termina en `AS "` (con
        // la comilla incluida) y el regex `\bAS\s*$` nunca matchea porque
        // hay un carácter `"` entre "AS" y el final del string, así que el
        // filtro de alias de salida quedaría siempre inerte (bug real,
        // encontrado corriendo este mismo verificador contra el repo real
        // antes de wirearlo a run.sh — con catálogo vacío de prueba TODOS
        // los alias `AS "algo"` aparecían como falso positivo).
        $matchOffset = $matches[0][$i][1];
        $pre         = substr($masked, 0, $matchOffset);
        if (preg_match(AS_BEFORE_RE, $pre)) {
            continue; // alias de salida deliberado.
        }
        if (!preg_match(VALID_IDENT_START_RE, $pre)) {
            continue; // defensa en profundidad — no debería dispararse nunca gracias al enmascarado.
        }
        if (isset($validColumns[$name]) || isset($validTables[$name])) {
            continue; // matchea un objeto real del catálogo — legítimo.
        }
        $bad[] = [$name, $matchOffset];
    }
    return $bad;
}

/** @return list<string> nombres en discrepancia (para .php, línea = la del token completo). */
function scanForBadQuotedIdentifiers(string $text, array $validColumns, array $validTables): array
{
    $out = [];
    foreach (findBadIdentifiers($text, $validColumns, $validTables) as [$name]) {
        $out[] = $name;
    }
    return $out;
}

/**
 * Variante para archivos .sql completos: a diferencia de
 * scanForBadQuotedIdentifiers() (que opera por STRING PHP para los .php,
 * cada string es su propia unidad de gate), acá el gate de "parece SQL" se
 * evalúa contra el ARCHIVO ENTERO — un CREATE TABLE de varias líneas
 * típico de db-schema-postgres.sql/seed.sql tiene la keyword `CREATE
 * TABLE` en la primera línea y las columnas citadas en líneas siguientes
 * SIN ninguna keyword propia; gatear por línea dejaría pasar esas columnas
 * sin marcar. Es seguro ampliar el gate a nivel archivo acá (y NO en PHP)
 * porque un `.sql` es SQL de punta a punta — no hay riesgo de strings de
 * UI/mensajes de error mezclados como en un .php.
 *
 * @return list<array{0:string,1:int}> lista de [identificador, línea]
 */
function scanSqlFileForBadIdentifiers(string $text, array $validColumns, array $validTables): array
{
    $out = [];
    foreach (findBadIdentifiers($text, $validColumns, $validTables) as [$name, $offset]) {
        $out[] = [$name, substr_count(substr($text, 0, $offset), "\n") + 1];
    }
    return $out;
}

/** @return \RecursiveIteratorIterator<\RecursiveDirectoryIterator> */
function walkFiles(string $dir, string $ext): iterable
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        /** @var \SplFileInfo $file */
        $path = $file->getPathname();
        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            continue;
        }
        if (str_ends_with($path, '.' . $ext)) {
            yield $path;
        }
    }
}

$scanned = 0;

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
    foreach (extractPhpSqlStrings($code) as [$text, $line]) {
        foreach (scanForBadQuotedIdentifiers($text, $validColumns, $validTables) as $bad) {
            $failures[] = sprintf(
                '[code] %s:%d — "%s" citado entre comillas con mayúscula, no matchea ningún objeto real del catálogo Postgres',
                substr($path, strlen($repoRoot) + 1),
                $line,
                $bad
            );
        }
    }
}

// .sql en TODO el repo, EXCLUYENDO api/database/migrations/postgres.
// Gate + scan a nivel ARCHIVO ENTERO (no por línea, ver docblock de
// scanSqlFileForBadIdentifiers) — cubre CREATE TABLE multi-línea donde la
// keyword y las columnas citadas están en líneas distintas.
foreach (walkFiles($repoRoot, 'sql') as $path) {
    if (str_starts_with($path, $migrationsDir)) {
        continue;
    }
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    $scanned++;
    foreach (scanSqlFileForBadIdentifiers($code, $validColumns, $validTables) as [$bad, $lineNo]) {
        $failures[] = sprintf(
            '[code] %s:%d — "%s" citado entre comillas con mayúscula, no matchea ningún objeto real del catálogo Postgres',
            substr($path, strlen($repoRoot) + 1),
            $lineNo,
            $bad
        );
    }
}

echo "[verify_pg_identifier_catalog] catálogo: " . count($validColumns) . " nombres de columna únicos, " . count($validTables) . " tablas, en $dbnm@$host\n";
echo "[verify_pg_identifier_catalog] escaneados $scanned archivo(s) (.php + .sql bajo api/ y raíz, excluyendo api/database/migrations/postgres)\n";

if ($failures !== []) {
    fwrite(STDERR, "[verify_pg_identifier_catalog] FALLÓ — " . count($failures) . " discrepancia(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_pg_identifier_catalog] TODO OK — cero identificadores camelCase-quoted huérfanos, catálogo 100% lowercase\n";
exit(0);
