<?php

declare(strict_types=1);

/**
 * verify_pg_identifier_catalog_lib.php — parsers/regex puros usados por
 * verify_pg_identifier_catalog.php (chequeos B y C, ver docblock de ese
 * archivo para el detalle completo de qué hace cada uno y por qué). Extraído
 * a un archivo aparte SIN ejecución top-level (sin PDO, sin conexión a DB) a
 * propósito: permite testear estas funciones puras contra un catálogo
 * cualquiera (ej. un dump de columnas/tablas armado a mano) sin depender de
 * Postgres — así es como se validó "cero falsos positivos sobre el repo
 * actual" comparando contra un snapshot de columnas de producción durante el
 * desarrollo de esta extensión, sin poder levantar el arnés completo
 * (Docker sin memoria en la máquina de desarrollo). El script principal
 * hace `require_once` de este archivo y llama a estas funciones con el
 * catálogo REAL leído en vivo de information_schema — nunca con datos
 * mockeados en ejecución normal.
 */

// Case-SENSITIVE a propósito (sin /i): el 100% del SQL real de este repo
// escribe sus keywords en MAYÚSCULA (verificado con grep — cero excepciones,
// ni siquiera en los .sql de seeds/fixtures), así que exigir mayúscula acá
// es gratis para detectar SQL real y evita que el gate dispare por accidente
// con prosa que usa la MISMA palabra en minúscula como método/verbo PHP —
// encontrado en la práctica: el mensaje de log
// `"...LocationTaxonomyService::delete() lee itemLocation..."` en
// verify_pg_identifiers.php contiene `delete()` (llamada a método, minúscula)
// que con /i activaba el gate igual que un `DELETE FROM` real, y el mensaje
// TAMBIÉN cita `\"itemLocation\"` entre comillas (para explicar el bug viejo
// que corrige) — sin este fix, el chequeo B marcaba esa cita descriptiva
// como si fuera una columna citada rota, un falso positivo real (no hay SQL
// ejecutándose ahí, es una oración). Este gate es compartido por los
// chequeos B y C — el fix aplica a ambos.
const SQL_KEYWORDS_RE = '/\b(SELECT|INSERT|UPDATE|DELETE|FROM|JOIN|WHERE|SET|VALUES|RETURNING|INTO|GROUP\s+BY|ORDER\s+BY|CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+INDEX|CREATE\s+TRIGGER|ON\s+CONFLICT|CONSTRAINT)\b/';

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

// Mismo patrón que SQL_FRAGMENT_RE pero para la variante SIN comillas de un
// fragmento armado a mano (`$where[] = 'd.created_at >= ?::date';` — mismo
// patrón real de PrintPoolService/StockTransferService/ContactRepository/
// EInvoiceService, pero con la columna calificada por alias en vez de un
// identificador citado suelto). Sin este gate, findBadQualifiedRefs()
// (chequeo C) nunca llegaba a mirar estos fragmentos: ni SQL_KEYWORDS_RE
// (no tienen ninguna keyword propia) ni SQL_FRAGMENT_RE (exige comillas)
// los abría, así que un `alias.columnaMal` sin comillas en ESTE patrón
// específico de fragmento habría pasado exactamente por el mismo hueco que
// motivó el chequeo C (hallado por code-reviewer sobre este mismo diff:
// `d.created_at >= ?::date` en EInvoiceService.php/ContactRepository.php).
// Usado SOLO acá (chequeo C) — el chequeo B no lo necesita, ya que solo
// mira identificadores CITADOS y este patrón nunca lleva comillas.
const QUALIFIED_FRAGMENT_RE = '/^\s*[A-Za-z_]\w*\.[A-Za-z_]\w*\s*(::\w+\s*)?(=|<>|!=|>=|<=|>|<|\bIS\b|\bIN\b|\bLIKE\b)/i';

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
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                // Trozo LITERAL de un string PHP con interpolación
                // (`"SELECT ... WHERE {$whereSql}"`) — el tokenizer de PHP
                // SOLO produce T_CONSTANT_ENCAPSED_STRING para un string SIN
                // ninguna variable adentro; en cuanto hay una `{$var}` o
                // `$var`, el contenido se parte en tokens
                // T_ENCAPSED_AND_WHITESPACE (los tramos de texto fijo) +
                // T_VARIABLE/T_CURLY_OPEN/etc (las partes interpoladas). Sin
                // este branch, CUALQUIER SQL armado con interpolación en vez
                // de concatenación (`.`) quedaba invisible para el chequeo
                // B Y el chequeo C — encontrado en la práctica reintroduciendo
                // a mano `ci.itemUOM` (el bug real del incidente 2026-08-19)
                // en `ItemsQuery.php::buildItemsSelectSql()`: ese SELECT
                // completo es UN SOLO string PHP con `{$whereSql}`/
                // `{$tailSql}` interpolados al final, así que TODO el cuerpo
                // (líneas ~158-286) es un único token T_ENCAPSED_AND_WHITESPACE
                // — sin este branch, `token_get_all()` nunca entregaba ese
                // texto como T_CONSTANT_ENCAPSED_STRING y el verificador NO
                // detectaba el bug en el archivo exacto donde ocurrió (se
                // verificó re-introduciendo el bug antes de este fix: pasaba
                // en limpio). Grep confirmó 71 archivos del repo con SQL
                // dentro de un string interpolado de esta forma — sin este
                // branch, esos 71 archivos NUNCA fueron escaneados por
                // ninguno de los dos chequeos, a pesar de que el docblock
                // del chequeo B decía cubrir "todo el PHP bajo api/".
                $out[] = [$text, $tokLine];
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

// ═══════════════════════════════════════════════════════════════════════
// C) Code-side: columnas CALIFICADAS (`alias.columna` / `alias."columna"`),
//    con o sin comillas, resueltas contra la tabla REAL del alias — la
//    clase de bug del incidente 2026-08-19 (`ci.itemUOM`, SIN comillas, así
//    que el chequeo B de arriba nunca la mira: QUOTED_IDENT_RE solo matchea
//    dentro de comillas).
//
// ═══ Cómo se resuelve el alias ═══
//
// Se escanea el MISMO texto (string PHP individual, o archivo .sql entero
// — mismas unidades que el chequeo B) buscando `FROM`/`JOIN`/`UPDATE`
// seguidos de un nombre de tabla real (validado contra
// information_schema.tables — así "LATERAL", "SELECT" o cualquier palabra
// reservada capturada por error nunca generan un mapeo falso) y, si le
// sigue un alias, lo registra (`FROM item i`, `JOIN "addon_group" ag`,
// `JOIN tax ON tax.taxId = ...` → sin alias explícito, la tabla se
// auto-referencia con su propio nombre). `FROM` además encadena listas
// separadas por coma (`FROM transaction a, itemSold b, item c`, estilo
// pre-JOIN que este repo todavía usa en varios reportes).
//
// Con ese mapa alias→tabla, cada referencia `alias.columna` del mismo
// texto se valida contra $columnsByTable[tabla] (case-insensitive — el
// código escribe `i.itemName`, la columna real es `itemname`). Si el alias
// NO resuelve a una tabla real, la referencia se OMITE (no se reporta ni
// como falla ni como OK) — nunca se adivina.
//
// ═══ Qué se omite a propósito (y por qué es seguro) ═══
//
// - Alias de subconsultas/LATERAL sin tabla real detrás (`addons`,
//   `compound`, `cov`, `ch`, `vc`, `st` en ItemsQuery.php; `elems` en
//   RegisterAdminService.php, que sale de `jsonb_array_elements(...) AS
//   elems(hotkey)`, una function-returning-table, no una tabla del
//   catálogo): el parser de FROM/JOIN de abajo para en seco apenas ve
//   `LATERAL` o una palabra que no matchea ninguna tabla real, así que esos
//   alias JAMÁS entran al mapa — cualquier `addons.groups`, `compound.items`
//   etc. se cuenta como omitida, nunca como válida ni como error.
// - CTEs (`WITH x AS (...)`): hoy no hay ninguna en el repo (verificado con
//   grep), pero si apareciera, el nombre del CTE tampoco matchea
//   information_schema.tables → mismo camino de omisión seguro.
// - SQL partido entre fragmentos PHP concatenados (`'SELECT '.'"x" FROM
//   foo'`) o entre `$whereSql`/`$tailSql` inyectados por parámetro: el
//   FROM y la referencia pueden terminar en unidades de texto DISTINTAS
//   (dos T_CONSTANT_ENCAPSED_STRING separados) — el mapa de alias es por
//   unidad de texto, así que el alias de la referencia simplemente no
//   aparece en el mapa de SU unidad → omitida. Nunca se intenta correlacionar
//   FROM/JOIN entre fragmentos distintos, así que nunca se arriesga una
//   resolución incorrecta por eso.
// - Colisión de alias: si el MISMO alias mapea a DOS tablas reales
//   distintas dentro de la misma unidad de texto (no se encontró ningún
//   caso real en este repo — cada `ncmExecute()`/string es su propia
//   unidad y los alias de una letra son consistentes archivo a archivo,
//   ver docblock de findBadQualifiedRefs), el alias se marca AMBIGUO y
//   se omite en vez de arriesgar una tabla equivocada.
//
// ═══ Qué NO escanea (mismo alcance que el chequeo B) ═══
//
// UPDATE/DELETE con JOIN (`UPDATE ... FROM`, `DELETE ... USING`): no hay
// ningún caso en el repo hoy (verificado con grep) — si aparece uno, sus
// alias tampoco resuelven (el parser de abajo no reconoce esa sintaxis) y
// caen en el mismo camino de omisión seguro, nunca en un falso positivo.

// Palabras reservadas que NUNCA son un alias válido — si aparecen
// inmediatamente después de un nombre de tabla en FROM/JOIN/UPDATE, la
// tabla NO tiene alias explícito (se autoreferencia por su propio nombre).
// Sin esta lista, `FROM item WHERE ...` capturaría "WHERE" como si fuera
// el alias de `item` (bug real, encontrado corriendo este parser contra
// EInvoiceService.php: `JOIN tax ON ...` capturaba "ON" como alias antes
// de agregar este filtro).
const TABLE_ALIAS_BLACKLIST = [
    'ON' => true, 'WHERE' => true, 'GROUP' => true, 'ORDER' => true,
    'LIMIT' => true, 'HAVING' => true, 'JOIN' => true, 'LEFT' => true,
    'RIGHT' => true, 'INNER' => true, 'OUTER' => true, 'CROSS' => true,
    'LATERAL' => true, 'USING' => true, 'SET' => true, 'VALUES' => true,
    'RETURNING' => true, 'UNION' => true, 'FOR' => true, 'WITH' => true,
    'WINDOW' => true, 'INTO' => true, 'DO' => true, 'AS' => true,
    // Defensa adicional (ningún caso real hoy, ver findBadQualifiedRefs):
    // palabras reservadas que tampoco son alias válidos si aparecen justo
    // después de un nombre de tabla (ej. `INSERT INTO tbl SELECT ...` sin
    // lista de columnas — no hay ningún caso así en el repo hoy, pero si
    // apareciera, "SELECT" no debe capturarse como alias de `tbl`).
    'SELECT' => true, 'INSERT' => true, 'DELETE' => true, 'UPDATE' => true,
    'CASE' => true, 'WHEN' => true, 'THEN' => true, 'ELSE' => true,
    'END' => true, 'NOT' => true, 'AND' => true, 'OR' => true,
];

// Identificador simple (bareword) o citado (`"x"`/`\"x\"`), con espacio
// líder opcional. Grupo 1 = mayúscula si es palabra clave candidata (para
// comparar contra TABLE_ALIAS_BLACKLIST), grupo 2 = el nombre tal cual
// aparece en el texto (para usar como alias real, preservando case).
const NEXT_WORD_RE = '/\G\s*(?:(\\\\?)"([A-Za-z_][A-Za-z0-9_]*)\1"|([A-Za-z_][A-Za-z0-9_]*))/';

/**
 * Intenta leer la "próxima palabra" (identificador bareword o citado) a
 * partir de $pos, saltando espacio en blanco líder. `\G` ancla el match a
 * la posición exacta pasada en el 5to argumento de preg_match (offset) —
 * si no matchea ahí (ej. lo próximo es `(`, `,`, fin de string), devuelve
 * null sin consumir nada.
 *
 * @return array{0:string,1:string,2:int}|null [palabra en MAYÚSCULA (para
 *         chequear contra keywords), palabra tal cual (para usar como
 *         nombre real), posición justo después de la palabra]
 */
function nextWord(string $text, int $pos): ?array
{
    if (!preg_match(NEXT_WORD_RE, $text, $m, PREG_OFFSET_CAPTURE, $pos)) {
        return null;
    }
    // Grupo 2 = nombre citado (sin comillas), grupo 3 = bareword. Uno de
    // los dos siempre viene vacío ('') cuando la alternativa no participó
    // — más confiable que confiar en el offset -1 de PREG_OFFSET_CAPTURE.
    $word = ($m[2][0] !== '') ? $m[2][0] : $m[3][0];
    $end  = $m[0][1] + strlen($m[0][0]);
    return [strtoupper($word), $word, $end];
}

/**
 * Parsea la lista de tabla(s)+alias que sigue a un FROM/JOIN/UPDATE en
 * $pos. Para FROM encadena listas separadas por coma (estilo pre-JOIN,
 * `FROM transaction a, itemSold b`); para JOIN/UPDATE es una sola tabla.
 * Solo registra un binding si el nombre de tabla capturado existe
 * REALMENTE en el catálogo ($validTableNamesLower) — cualquier otra cosa
 * (LATERAL, una subconsulta, una función) simplemente no matchea y el
 * parser corta ahí sin generar un mapeo falso.
 *
 * @return list<array{0:string,1:string}> lista de [aliasLower, tablaLower]
 */
function parseTableAliasBindings(string $text, int $pos, bool $allowCommaChain, array $validTableNamesLower): array
{
    $bindings = [];
    $guard    = 0;
    while ($guard++ < 30) { // tope defensivo — nunca debería iterar tanto en SQL real.
        $w = nextWord($text, $pos);
        if ($w === null) {
            break;
        }
        [$wordUpper, $tableOrig, $afterTable] = $w;
        if (!isset($validTableNamesLower[strtolower($tableOrig)])) {
            break; // no es una tabla real (LATERAL, subconsulta, función, keyword...) — no seguir.
        }
        $pos   = $afterTable;
        $alias = $tableOrig; // default: sin alias explícito, la tabla se autoreferencia.
        $w2    = nextWord($text, $pos);
        if ($w2 !== null) {
            [$word2Upper, $word2Orig, $afterWord2] = $w2;
            if ($word2Upper === 'AS') {
                $w3 = nextWord($text, $afterWord2);
                if ($w3 !== null && !isset(TABLE_ALIAS_BLACKLIST[$w3[0]])) {
                    $alias = $w3[1];
                    $pos   = $w3[2];
                }
                // si no hay identificador válido tras AS, se deja el default
                // (self-alias) y NO se avanza $pos más allá de la tabla —
                // más conservador que arriesgar un parseo incorrecto.
            } elseif (!isset(TABLE_ALIAS_BLACKLIST[$word2Upper])) {
                $alias = $word2Orig;
                $pos   = $afterWord2;
            }
        }
        $bindings[] = [strtolower($alias), strtolower($tableOrig)];
        if (!$allowCommaChain) {
            break;
        }
        if (preg_match('/\G\s*,/', $text, $mComma, 0, $pos)) {
            $pos += strlen($mComma[0]);
            continue;
        }
        break;
    }
    return $bindings;
}

// `-- comentario hasta fin de línea` — se enmascara ANTES de buscar
// FROM/JOIN y referencias calificadas por el mismo motivo que
// maskSqlStringLiterals(): un comentario puede contener texto con forma de
// `alias.columna` por coincidencia (ej. "ver context/45-satelites-item-
// contact-sync.md" tiene un punto antes de "md") que no es SQL real. Se
// preserva el salto de línea para no correr el conteo de líneas.
function maskSqlLineComments(string $sql): string
{
    // OJO: reemplazar por '' (en vez de espacios) ACORTA el texto y
    // desincroniza cada offset posterior de $masked contra el $text
    // original — exactamente el mismo tipo de bug que maskSqlStringLiterals()
    // evita a propósito preservando longitud/saltos de línea (ver su
    // docblock). Encontrado en la práctica: con el reemplazo vacío, el
    // offset reportado para `ci.itemUOM` en ItemsQuery.php (que tiene
    // varios comentarios `--` ANTES de esa línea) apuntaba a la línea 224
    // en vez de la 253 real — la discrepancia se detectaba bien, pero
    // señalando la línea equivocada. `str_repeat(' ', ...)` preserva el
    // largo exacto de cada comentario, así que el offset sigue siendo
    // válido contra $text sin importar cuántos comentarios haya antes.
    $sql = preg_replace_callback(
        '/--[^\n]*/',
        static fn(array $m): string => str_repeat(' ', strlen($m[0])),
        $sql
    ) ?? $sql;

    // `/* ... */` (multilínea) — mismo motivo y misma técnica que `--`
    // arriba (largo exacto preservado vía str_repeat, saltos de línea
    // internos intactos para no correr el conteo de línea). No hay ningún
    // comentario de bloque en el SQL de este repo hoy (grep en limpio,
    // todo usa `--`), pero sin esto un `/* ... */` futuro sería el mismo
    // punto ciego que `--` tenía antes del fix de arriba — más barato
    // cubrirlo ahora que esperar el próximo incidente.
    return preg_replace_callback(
        '#/\*.*?\*/#s',
        static fn(array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
        $sql
    ) ?? $sql;
}

const QUALIFIED_REF_RE = '/\b([A-Za-z_][A-Za-z0-9_]*)\.(?:(\\\\?)"([A-Za-z_][A-Za-z0-9_]{0,62})\2"|([A-Za-z_][A-Za-z0-9_]{0,62}))\b/';

/**
 * Núcleo del chequeo C: dado un bloque de texto "que parece SQL" (mismo
 * gate que findBadIdentifiers), arma el mapa alias→tabla real y valida
 * cada referencia `alias.columna` (citada o no) contra
 * $columnsByTable[tabla].
 *
 * @param array<string,array<string,true>> $columnsByTable
 * @param array<string,true> $validTableNamesLower
 * @return array{failures:list<array{0:string,1:string,2:string,3:int}>,checked:int,omitted:int}
 *         failures = [alias, tabla, columna, offset]; checked = referencias
 *         resueltas y validadas (OK o falla); omitted = referencias cuyo
 *         alias no resolvió a una tabla real (nunca reportadas).
 */
function findBadQualifiedRefs(string $text, array $columnsByTable, array $validTableNamesLower): array
{
    if (!preg_match(SQL_KEYWORDS_RE, $text)
        && !preg_match(SQL_FRAGMENT_RE, $text)
        && !preg_match(QUALIFIED_FRAGMENT_RE, $text)
    ) {
        return ['failures' => [], 'checked' => 0, 'omitted' => 0];
    }
    // Comentarios PRIMERO, string literals DESPUÉS: si un comentario `--`
    // tuviera un apóstrofe suelto (ej. "-- ojo: no usar el patrón viejo"
    // con una contracción en inglés, o cualquier `'` que no cierre un
    // literal), enmascarar strings ANTES de neutralizar el comentario
    // dejaría a maskSqlStringLiterals() creyendo que ahí arranca un
    // literal '...' y enmascararía SQL real después del comentario como si
    // fuera data — un falso NEGATIVO silencioso (no rompe el criterio de
    // "cero falsos positivos", pero podría esconder un bug real). Sin
    // casos hoy (grep en limpio: ningún comentario `--` del repo tiene un
    // `'` suelto), pero invertir el orden lo evita sin costo.
    $masked = maskSqlStringLiterals(maskSqlLineComments($text));

    // Mapa alias→tabla, con detección de ambigüedad (mismo alias apuntando
    // a dos tablas reales distintas dentro de la misma unidad de texto →
    // se marca `false` y nunca se resuelve, en vez de arriesgar la tabla
    // equivocada).
    $aliasMap = [];
    // `INSERT INTO` es un caso aparte (dos palabras, sin alias posible en la
    // sintaxis de este repo) — registra la tabla como su propio alias, que
    // es exactamente lo que hace falta para el patrón `ON CONFLICT (...) DO
    // UPDATE SET x = tabla.col + 1` (upsert idempotente, MUY usado acá:
    // `document_sequence.nextnumber = document_sequence.nextnumber + 1` en
    // DocumentNumber.php, 31 archivos con ON CONFLICT en el repo). Sin esto
    // esas referencias caían todas en "omitida" por no tener ningún
    // FROM/JOIN que las resolviera.
    // Case-SENSITIVE a propósito (sin /i), MISMA razón que SQL_KEYWORDS_RE
    // (ver su docblock): con /i, un "from"/"update" en minúscula dentro de
    // prosa (ej. un comentario o mensaje que sobrevivió el gate por
    // coincidir con otra keyword en mayúscula en el mismo bloque) podría
    // fabricar un binding alias→tabla falso y hacer que una referencia
    // real de OTRO alias en el mismo texto se resuelva contra la tabla
    // equivocada. Encontrado por code-reviewer sobre este mismo diff como
    // inconsistencia latente (sin caso real hoy, grep en limpio) — se
    // corrige acá para no depender de que nunca aparezca.
    $insertKeywordPatterns = ['FROM' => '\bFROM\b', 'JOIN' => '\bJOIN\b', 'UPDATE' => '\bUPDATE\b', 'INSERT' => '\bINSERT\s+INTO\b'];
    foreach ($insertKeywordPatterns as $kw => $kwRe) {
        if (!preg_match_all('/' . $kwRe . '/', $masked, $kwMatches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($kwMatches[0] as $km) {
            $afterKw  = $km[1] + strlen($km[0]);
            $bindings = parseTableAliasBindings($masked, $afterKw, $kw === 'FROM', $validTableNamesLower);
            foreach ($bindings as [$aliasLower, $tableLower]) {
                if (!array_key_exists($aliasLower, $aliasMap)) {
                    $aliasMap[$aliasLower] = $tableLower;
                } elseif ($aliasMap[$aliasLower] !== $tableLower) {
                    $aliasMap[$aliasLower] = false; // ambiguo — nunca resolver.
                }
            }
        }
    }

    $failures = [];
    $checked  = 0;
    $omitted  = 0;
    if (!preg_match_all(QUALIFIED_REF_RE, $masked, $matches, PREG_OFFSET_CAPTURE)) {
        return ['failures' => [], 'checked' => 0, 'omitted' => 0];
    }
    foreach ($matches[0] as $i => $full) {
        $alias = strtolower($matches[1][$i][0]);
        $col   = $matches[3][$i][0] !== '' ? $matches[3][$i][0] : $matches[4][$i][0];
        if ($col === '') {
            continue;
        }
        $table = $aliasMap[$alias] ?? null;
        if ($table === null || $table === false) {
            $omitted++; // alias no resuelto o ambiguo — nunca se reporta.
            continue;
        }
        $checked++;
        if (!isset($columnsByTable[$table][strtolower($col)])) {
            $failures[] = [$alias, $table, $col, $full[1]];
        }
    }
    return ['failures' => $failures, 'checked' => $checked, 'omitted' => $omitted];
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
