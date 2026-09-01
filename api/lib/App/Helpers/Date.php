<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers de fecha/tiempo del POS.
 *
 * Reemplaza las funciones globales (Slice 5 del plan PSR-4):
 *   - niceDate($d, ...)           → Date::nice($d, ...)
 *   - niceDate2($d, $type)        → Date::niceAgo($d, $type)
 *   - getNextDatePeriod(...)      → Date::nextPeriod(...)
 *   - dateStartEndTime($s, $e)    → Date::startEndTime($s, $e)
 *   - translateNamesOfWeek($w, ?) → Date::translateWeekName($w, ?)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~185 callsites totales del POS:
 *   - niceDate:             166 callers (función más usada del módulo)
 *   - getNextDatePeriod:      9 callers (cálculo recurrente de ventas)
 *   - niceDate2:              5 callers (display "Hace 2 horas")
 *   - dateStartEndTime:       5 callers (split de rango horario)
 *   - translateNamesOfWeek:   0 callers externos (interno de nice())
 *
 * NOTA: depende de `$GLOBALS['meses']` (array i18n de meses ES) definido
 * en config.php / data.php del bootstrap de /app. Se accede vía
 * `$GLOBALS['meses']` para no asumir scope ni romper si el global no
 * existe (fallback array vacío + 'Sin fecha').
 *
 * NO se incluyó `strToDate` — vive en panel/includes/functions.php
 * (fuera de scope del refactor /app).
 */
final class Date
{
    /**
     * Formato aceptado para un extremo de rango: `YYYY-MM-DD` con hora
     * OPCIONAL. Es el mismo regex que estaba duplicado, verbatim, en los ~24
     * endpoints de `api/v1/reports/*` y `api/v1/finance/*`, mas la FRACCION de
     * segundo opcional.
     *
     * La fraccion se acepta por dos razones. La primera es que sin ella el
     * helper rechazaba su PROPIA salida —`isRangeBound(rangeEnd('2026-09-01'))`
     * daba false—, una trampa para el proximo caller que encadene los dos.
     * La segunda es que el panel necesita poder MANDAR el ultimo instante del
     * dia: su selector arma el rango del lado del cliente y, como una hora
     * explicita se respeta verbatim, sin fraccion se quedaba con el agujero
     * del ultimo segundo que `END_OF_DAY` cierra.
     */
    public const RANGE_BOUND_RE = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d{1,6})?)?$/';

    /**
     * Último instante representable de un día, con la precisión REAL de las
     * columnas (`timestamptz` = microsegundos). Ver `rangeEnd()` para por qué
     * no es `23:59:59` ni un borde exclusivo.
     */
    public const END_OF_DAY = '23:59:59.999999';

    /**
     * Formato aceptado para un extremo de FRANJA HORARIA: `HH:MM` con los
     * segundos OPCIONALES.
     *
     * Por qué `HH:MM` y no un entero de hora (`7`..`11`): el pedido que motivó
     * la feature es textual — "ventas de 07:00 a 11:59". Con granularidad de
     * hora entera esa franja sólo se puede aproximar, y una como 07:30-11:15 no
     * se puede expresar en absoluto. Un `EXTRACT(HOUR ...)` como predicado
     * dejaba el minuto afuera para siempre; el costo de soportarlo es cero (el
     * predicado compara `::time` en vez de un entero).
     *
     * Por qué los segundos son opcionales pero se aceptan: `HH:MM` es lo que va
     * a mandar la UI y lo que escribe una persona, pero un caller que arme la
     * hora desde un timestamp (`substr($ts, 11)`) produce `HH:MM:SS` — y un
     * helper que rechace la forma más obvia de derivar su propio input es una
     * trampa (la misma que la fracción de segundo cerró en `RANGE_BOUND_RE`).
     *
     * Por qué DOS dígitos obligatorios en la hora: aceptar `7:00` obliga a
     * normalizar antes de comparar como string (ver `hourRange()`, que decide
     * el cruce de medianoche con `<=` lexicográfico) y abre la puerta a `7:5`.
     *
     * A diferencia de `RANGE_BOUND_RE`, este regex SÍ exige los rangos reales
     * (`00-23` / `00-59`) en vez de `\d{2}`. Puede hacerlo porque no hay fecha
     * de por medio: sin `checkdate()` en el medio no hay razón para partir la
     * validación en dos pasos, y así `25:99` no puede llegar a Postgres — que
     * es exactamente el agujero que `isRangeBound()` tuvo que tapar aparte.
     */
    public const HOUR_BOUND_RE = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    /**
     * Identificador de columna aceptable para interpolar en el SQL de
     * `hourRange()`: `col` o `alias.col`. No es validación de input del
     * cliente —el nombre lo escribe el servicio— sino un guard de
     * defense-in-depth, mismo criterio que el UUID_RE de `Reports\Roc`.
     */
    private const SQL_COLUMN_RE = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * ¿El valor es un extremo de rango sintácticamente válido?
     *
     * Vacío cuenta como válido: significa "usá el default", no "el cliente se
     * equivocó". Además del formato se verifica que la fecha EXISTA
     * (`checkdate`): `2026-02-31` matchea el regex pero hace explotar el cast
     * a timestamp en Postgres, y un 500 por una fecha inventada por el cliente
     * es un 422 mal reportado. `finance/reports.php` ya hacía este checkdate
     * por su cuenta; acá pasa a valer para todos.
     */
    public static function isRangeBound(mixed $value): bool
    {
        $value = trim((string) ($value === false || $value === null ? '' : $value));
        if ($value === '') {
            return true;
        }
        if (!preg_match(self::RANGE_BOUND_RE, $value, $m)) {
            return false;
        }
        if (!checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return false;
        }
        // La hora tambien tiene que existir. El regex acepta cualquier terna de
        // dos digitos, asi que `2026-09-01 25:99:99` lo pasaba y reventaba el
        // cast a timestamp en Postgres. Ahora que este helper es el UNICO punto
        // de validacion de los ~24 endpoints, el chequeo va aca.
        if (($m[1] ?? '') !== '') {
            [$h, $i, $sec] = array_map('intval', explode(':', trim($m[1])));
            if ($h > 23 || $i > 59 || $sec > 59) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normaliza el extremo INFERIOR de un rango: una fecha sola significa el
     * PRINCIPIO de ese día.
     *
     * @param mixed       $value   Crudo del request (acepta el `false`/`null` de validateHttp()).
     * @param string|null $default Valor a usar cuando viene vacío. Default: hace 7 días a las 00:00:00.
     */
    public static function rangeStart(mixed $value, ?string $default = null): string
    {
        return self::normalizeBound($value, '00:00:00', $default ?? date('Y-m-d 00:00:00', strtotime('-7 days')));
    }

    /**
     * Normaliza el extremo SUPERIOR de un rango: una fecha sola significa el
     * FINAL de ese día.
     *
     * ── Por qué `.999999` y no `23:59:59` ──────────────────────────────────
     * Las columnas de fecha del sistema son `timestamptz` sin precisión
     * declarada, o sea MICROSEGUNDOS (el default de Postgres). Un dato real:
     * `2026-08-28 11:19:13.098481-03`.
     *
     * Con `<= '... 23:59:59'` se pierde todo lo que caiga entre
     * `23:59:59.000001` y `23:59:59.999999`: casi un segundo entero del final
     * del día. Es poco probable y por eso es peligroso — en una caja con
     * volumen va a pasar alguna vez, y una venta que no aparece en el reporte
     * del día sin ninguna explicación es exactamente el faltante que nadie
     * logra reconstruir después.
     *
     * El estándar para rangos temporales es el intervalo semi-abierto
     * (`>= inicio_del_día AND < inicio_del_día_siguiente`), que no depende de
     * la precisión del tipo. Acá NO se usa por una razón concreta y medida:
     * las comparaciones vivas contra el extremo superior son 60 `BETWEEN` y
     * 19 `<=` en `api/lib/Reports/*.php` + `api/lib/Finance/*.php` (contadas
     * con `grep -cE 'BETWEEN \? AND \?' / '<= \?'`), y `BETWEEN` es INCLUSIVO
     * en los dos extremos: correr el `to` al día siguiente sin reescribir cada
     * una no arregla nada, lo empeora — el rango pasa a tragarse el primer
     * instante del día siguiente. Verificado contra Postgres 16 con una venta
     * a las `23:59:59.5` y otra a las `00:00:00` del día siguiente:
     *
     *     ts <= '2026-09-01 23:59:59'                      -> pierde la de .5
     *     ts <= '2026-09-01 23:59:59.999999'               -> correcto
     *     ts <  '2026-09-02 00:00:00'                      -> correcto (equivale)
     *     ts BETWEEN '...01 00:00:00' AND '...02 00:00:00' -> trae la del día 2
     *
     * Hay además consumidores que NO son SQL y que un `to` corrido rompe:
     * `RollupReader` hace `substr($to, 0, 10)` contra columnas `day date` (un
     * `to` del día siguiente suma un DÍA entero a los reportes de marcas,
     * categorías y medios de pago), y varios servicios sacan el largo del
     * período con `strtotime($to)` para comparar contra el período anterior.
     * Con `.999999` siguen andando sin tocarlos: `substr` da el mismo día y
     * `strtotime` trunca al segundo.
     *
     * Si algún día una columna pasara a tener MÁS precisión que microsegundos
     * —Postgres no lo soporta hoy— este helper vuelve a dejar un hueco. Ahí sí
     * corresponde el borde exclusivo.
     *
     * @param mixed       $value   Crudo del request (acepta el `false`/`null` de validateHttp()).
     * @param string|null $default Valor a usar cuando viene vacío. Default: hoy al último instante.
     */
    public static function rangeEnd(mixed $value, ?string $default = null): string
    {
        return self::normalizeBound($value, self::END_OF_DAY, $default ?? date('Y-m-d ' . self::END_OF_DAY));
    }

    /**
     * Resuelve el par `from`/`to` de un endpoint de reportes.
     *
     * ── El defecto que cierra ───────────────────────────────────────────────
     *
     * Los ~24 endpoints de `api/v1/reports/*` y `api/v1/finance/*` repetían
     * estas dos líneas:
     *
     *     if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
     *     if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
     *
     * Los defaults traían la hora, pero SOLO cuando el parámetro venía vacío.
     * Si el cliente mandaba una fecha sola (`to=2026-09-01`) el valor viajaba
     * tal cual al WHERE, Postgres lo leía como `2026-09-01 00:00:00`, y el
     * filtro `<= '2026-09-01'` dejaba afuera TODO lo ocurrido después de la
     * medianoche de ese día. O sea: pedir "hasta el 1 de septiembre" devolvía
     * el 1 de septiembre vacío — el último día del rango siempre se perdía.
     *
     * Caso real que lo destapó (2026-09-01): al agente IA le preguntaron
     * "¿cuánto efectivo hay en la caja abierta?" y contestó "no hay ninguna
     * caja abierta hoy en Head Quarters". Había una, abierta ese mismo día a
     * las 12:07 y visible en el POS con sus movimientos: el agente consultaba
     * `/v1/reports/drawers` con `to=2026-09-01` y el rango la excluía.
     *
     * Por qué sobrevivió tanto: el PANEL nunca lo pegó. Su `rangeToBackend()`
     * (`frontend/components/date-range-picker.tsx`) ya mandaba
     * `from ... 00:00:00` / `to ... 23:59:59`, o sea que compensaba el defecto
     * del lado del cliente. Los que sí mandan una fecha sola son el agente IA
     * (sus tools declaran `Fecha fin YYYY-MM-DD`), los consumidores por API
     * key / MCP, y cualquier llamada directa a la API. El contrato del endpoint
     * no puede depender de que el cliente conozca el truco.
     *
     * ── La semántica ────────────────────────────────────────────────────────
     *
     *   - Parámetro VACÍO      → default (últimos 7 días / hoy 23:59:59). Sin cambios.
     *   - Fecha SOLA           → `from` al principio del día, `to` al final del día.
     *   - Fecha CON hora       → se respeta tal cual: `to=2026-09-01 15:00:00`
     *                            es "hasta las 15:00" porque el cliente lo pidió.
     *
     * ── Zona horaria ────────────────────────────────────────────────────────
     *
     * Este helper NO toca zonas horarias: solo completa la hora que falta, y
     * los defaults salen del mismo `date()` de antes. Que eso sea correcto no
     * es casualidad: `apiAuthTenant()` corre `TenantClock::apply()`
     * (`api/bootstrap.php`), que setea la TZ del tenant tanto en PHP
     * (`date_default_timezone_set`) como en la sesión de Postgres
     * (`SET TIME ZONE`). Para cuando estas líneas se ejecutan, `date()` ya
     * devuelve hora de pared del comercio, y el string naive que viaja al
     * WHERE lo interpreta PG en esa MISMA zona contra columnas `timestamptz`.
     * Los dos extremos hablan el mismo huso, así que completar la hora acá es
     * consistente. Ojo: eso vale para lo que pasa por un embudo de auth — un
     * cron o un consumidor que arme el rango fuera de un request sigue en UTC.
     *
     * @param mixed       $from        Crudo del request.
     * @param mixed       $to          Crudo del request.
     * @param string|null $defaultFrom Override del default de `from` (ej. `date('Y-m-01 00:00:00')` en finance).
     * @param string|null $defaultTo   Override del default de `to`.
     *
     * @return array{0: string, 1: string, 2: bool} [from, to, valid]. Cuando
     *         `valid` es false los dos primeros traen los DEFAULTS, para que un
     *         caller que prefiera degradar en vez de tirar 422 pueda ignorar el
     *         flag y seguir con un rango sano.
     */
    public static function reportRange(mixed $from, mixed $to, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        $valid = self::isRangeBound($from) && self::isRangeBound($to);
        if (!$valid) {
            // Rango inválido → defaults. El caller decide si además corta con 422.
            return [self::rangeStart('', $defaultFrom), self::rangeEnd('', $defaultTo), false];
        }

        return [self::rangeStart($from, $defaultFrom), self::rangeEnd($to, $defaultTo), true];
    }

    /**
     * Completa la hora faltante de un extremo de rango.
     *
     * No valida: `reportRange()`/`isRangeBound()` ya filtraron. Un valor que
     * llegue acá sin pasar por ahí y no tenga formato de fecha se devuelve
     * intacto — normalizar basura sería inventarle una semántica.
     */
    private static function normalizeBound(mixed $value, string $timePart, string $default): string
    {
        $value = trim((string) ($value === false || $value === null ? '' : $value));
        if ($value === '') {
            return $default;
        }
        // `strlen === 10` es "vino solo la fecha". Con hora explícita no se toca.
        return strlen($value) === 10 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $value . ' ' . $timePart
            : $value;
    }

    /**
     * ¿El valor es un extremo de franja horaria sintácticamente válido?
     *
     * Vacío cuenta como válido, igual que en `isRangeBound()`: significa "sin
     * franja" —el caso de la enorme mayoría de las consultas—, no "el cliente
     * se equivocó".
     */
    public static function isHourBound(mixed $value): bool
    {
        $value = trim((string) ($value === false || $value === null ? '' : $value));

        return $value === '' || (bool) preg_match(self::HOUR_BOUND_RE, $value);
    }

    /**
     * Resuelve el filtro de FRANJA HORARIA de un reporte: el fragmento de
     * `WHERE` que deja sólo las filas cuya hora del día cae en la franja, para
     * TODOS los días del rango de fechas.
     *
     * ── Qué resuelve (y por qué no alcanza con `from`/`to`) ──────────────────
     *
     * Un rango `from`/`to` es un intervalo CONTINUO. "Del 1 al 30 de septiembre
     * de 07:00 a 11:59" mandado como rango incluye las 19 horas de cada noche
     * del medio: no hay forma de expresar la franja con los parámetros que ya
     * existen. La hora del día es una dimensión PROPIA, ortogonal al rango, y
     * por eso son dos parámetros más y no otra manera de mandar `from`/`to`.
     * (Lo que sí funciona hoy sin nada de esto es la franja DENTRO de un solo
     * día: `from=... 07:00:00&to=... 11:59:59`. Ver `context/67`, Caso A.)
     *
     * ── La franja que cruza medianoche ──────────────────────────────────────
     *
     * Es el caso real de cualquier bar: opera de 20:00 a 04:00. Leído
     * ingenuamente el predicado queda `hora >= 20 AND hora < 04`, que no puede
     * ser verdadero nunca y devuelve CERO filas — un reporte vacío en vez de un
     * error, o sea el peor modo de falla posible. Cuando el inicio es POSTERIOR
     * al fin, el predicado se invierte a un `OR`: `hora >= 20 OR hora <= 04`.
     * Se resuelve acá, una sola vez, justamente para que ningún call-site
     * futuro tenga que acordarse.
     *
     * La comparación que decide la inversión es `<=` sobre los strings ya
     * normalizados. Es correcta porque el formato es de ancho fijo con ceros a
     * la izquierda (`HH:MM:SS…`): el orden lexicográfico y el cronológico
     * coinciden. Por eso `HOUR_BOUND_RE` exige dos dígitos en la hora.
     *
     * ── El extremo superior es INCLUSIVO de la unidad que se pidió ──────────
     *
     * `hourTo=11:59` cierra en `11:59:59.999999`, no en `11:59:00`. Es la misma
     * decisión —y por la misma razón— que `rangeEnd()`: las columnas son
     * `timestamptz` (microsegundos), así que un corte en `11:59:00` pierde el
     * minuto 59 entero, y uno en `11:59:59` pierde su última fracción. "Hasta
     * las 11:59" significa el minuto 11:59 incluido, igual que "hasta el 1 de
     * septiembre" significa ese día entero.
     *
     * No se usa el borde EXCLUSIVO (`< '12:00'`) por dos razones. La primera es
     * consistencia con `rangeEnd()`, que ya eligió el tope inclusivo con
     * fracción y documenta por qué. La segunda es que correr el tope al
     * siguiente le rompe el pie a la detección del cruce de medianoche:
     * `23:00`-`23:59` se convertiría en `23:00`-`24:00` y habría que tratar
     * `24:00` aparte (Postgres acepta `time '24:00:00'`, pero comparar contra
     * él ya no es el mismo orden lexicográfico que usa la inversión).
     *
     * ── Un solo extremo ─────────────────────────────────────────────────────
     *
     * `hourFrom` sin `hourTo` es "desde las 20:00 hasta el final del día";
     * `hourTo` sin `hourFrom` es "desde medianoche hasta las 11:59". Se soporta
     * porque el agente IA es el consumidor natural de esta feature y una
     * pregunta como "¿cuánto vendí después de las 8 de la noche?" no trae el
     * otro extremo. Ojo: un extremo faltante se completa con el borde del día,
     * así que NUNCA dispara el `OR` de medianoche por accidente.
     *
     * ── Sin franja no se agrega NADA ────────────────────────────────────────
     *
     * Los dos parámetros vacíos devuelven el fragmento vacío y cero binds: la
     * query queda byte por byte como está hoy. Es el caso común, y así los ~24
     * endpoints ya migrados no pagan nada —ni costo ni riesgo— por que esto
     * exista.
     *
     * ── Zona horaria (el riesgo silencioso de esta feature) ─────────────────
     *
     * La hora de una venta depende del huso con que se la mire, y un filtro que
     * se equivoque de huso devuelve datos PLAUSIBLES y falsos: peor que fallar.
     *
     * Con `$timezone` en null el predicado se apoya en la zona de la SESIÓN de
     * Postgres. Eso es correcto detrás de un embudo de auth y sólo ahí:
     * `apiAuthTenant()` hace `require data.php`, que corre
     * `TenantClock::apply()` y deja la sesión de PG en la zona del comercio
     * (verificado: `api/data.php:84`; los 24 endpoints de reportes entran por
     * ese embudo). La conexión NO nace en la zona del tenant —abre con la de la
     * plataforma, UTC en el container de prod, y recién el embudo la cambia—,
     * así que un cron, el realm `/admin` o un arnés de tests que arme la query
     * fuera de un request DEBE pasar `$timezone` explícito
     * (`TenantClock::timezone($companyId)`). Es la misma advertencia del
     * docblock de `reportRange()`, acá con consecuencias peores: un rango mal
     * husado corre el borde de un día, una franja mal husada corre TODAS las
     * filas del reporte.
     *
     * La zona viaja como PARÁMETRO BINDEADO, no interpolada. Es la diferencia
     * con `TenantClock::applyToDatabaseSession()`, que no tiene opción (`SET`
     * no acepta parámetros) y por eso valida contra la lista IANA antes de
     * concatenar. Acá no hace falta ese guard porque no hay superficie de
     * inyección. Sí se castea (`?::text`): con `EMULATE_PREPARES=false` el
     * parámetro llega sin tipo, y `AT TIME ZONE` tiene dos overloads
     * (`text` e `interval`) — el cast le saca toda ambigüedad al planner.
     *
     * PRECONDICIÓN de `$timezone`: la columna tiene que ser `timestamptz`.
     * Las tres que va a filtrar la F1 lo son —`transaction.transactionDate`,
     * `itemsold.itemSoldDate`, `drawer.drawerOpenDate`, verificadas una por una
     * en `db-schema-postgres.sql`—, pero eso NO es una garantía sobre todo el
     * schema: cada columna nueva que se sume al filtro se verifica antes.
     * Sobre un `timestamp` naive el `AT TIME ZONE`
     * significa lo contrario (lo interpreta EN esa zona en vez de mostrarlo en
     * ella) y devolvería basura: esa columna se filtra sin `$timezone`.
     *
     * ── Índices ─────────────────────────────────────────────────────────────
     *
     * El predicado de franja NO usa el índice de fecha: es un filtro residual
     * sobre las filas que el rango ya trajo. Eso es aceptable porque la franja
     * SIEMPRE acompaña a un rango de fechas —nunca es el único filtro
     * temporal—, así que opera sobre un conjunto ya acotado por el índice y por
     * el particionado por mes (`context/48`). El helper no puede hacer cumplir
     * esa condición (no ve el resto del `WHERE`), así que es CONTRATO del
     * call-site: sólo se agrega este fragmento a una query que ya acota por
     * `from`/`to`. En los servicios de reportes se cumple por construcción —
     * todos reciben el rango y lo bindean—, pero si alguna vez se quisiera
     * filtrar por franja sin rango, ahí sí hace falta un índice funcional sobre
     * la expresión, que hoy no existe.
     *
     * ── La forma del retorno ────────────────────────────────────────────────
     *
     * Fragmento + params, no dos enteros validados. Devolver enteros obligaría
     * a cada servicio a escribir su propio predicado y, con eso, a acordarse
     * del cruce de medianoche: es exactamente el patrón duplicado que
     * `reportRange()` vino a matar para `from`/`to` (ver `context/67`,
     * arquitecturas rechazadas).
     *
     * El riesgo conocido de devolver SQL armado afuera de la query —que el
     * `OR` de medianoche se mezcle con las otras condiciones del `WHERE`— se
     * cierra en la forma del fragmento, no en la disciplina del caller: SIEMPRE
     * sale entre paréntesis y SIEMPRE arranca con " AND ", así que es
     * concatenable después de cualquier `WHERE` existente sin cambiarle el
     * significado. El " AND " de entrada es la otra cara de eso: el fragmento
     * asume que el `WHERE` ya trae al menos una condición (siempre la hay — el
     * scope de tenant y el rango de fechas), así que pegarlo pelado después de
     * un `WHERE` daría `WHERE AND (…)`. Falla en el primer request, no en
     * silencio. Es la misma convención que `Reports\Roc::build()`, que ya
     * viaja así por los 24 endpoints; la diferencia es que Roc interpola sus
     * valores (UUIDs del token) y esto los bindea (vienen del request).
     *
     * Uso típico en un servicio de reportes (F1):
     *
     *     [$hourSql, $hourParams] = Date::hourRange('transactionDate', $hf, $ht);
     *     $sql = '... WHERE transactionType IN (0, 3)
     *                 AND transactionDate >= ? AND transactionDate <= ?'
     *                 . $roc . $hourSql;
     *     ncmExecute($sql, array_merge([$from, $to], $hourParams));
     *
     * El orden de los binds es el de aparición del `?` en el SQL: los params de
     * la franja van DONDE se concatena el fragmento. En una query con varios
     * rangos (`CustomersService::dashboard()` bindea tres) hay que insertarlos
     * en la posición que corresponde, no siempre al final.
     *
     * @param string      $column    Columna de fecha sobre la que filtrar (`transactionDate`, `t.transactionDate`, `itemSoldDate`…).
     * @param mixed       $hourFrom  Crudo del request (acepta el `false`/`null` de validateHttp()).
     * @param mixed       $hourTo    Crudo del request.
     * @param string|null $timezone  TZ IANA explícita. Null = la de la sesión de PG (sólo válido detrás del embudo de auth).
     *
     * @return array{0: string, 1: array<int, string>, 2: bool} [sql, params, valid].
     *         Cuando `valid` es false el fragmento viene VACÍO: una franja mal
     *         formada degrada a "sin franja" —resultado más amplio, nunca uno
     *         inventado— para que el caller que prefiera seguir pueda ignorar
     *         el flag, igual que hace `reportRange()` con sus defaults. El que
     *         quiera cortar con 422 mira el flag.
     *
     * @throws \InvalidArgumentException si `$column` no es un identificador simple.
     */
    public static function hourRange(string $column, mixed $hourFrom, mixed $hourTo, ?string $timezone = null): array
    {
        if (!preg_match(self::SQL_COLUMN_RE, $column)) {
            // Un nombre de columna inválido es un error de PROGRAMACIÓN, no del
            // cliente: lo escribe el servicio, nunca el request. Por eso explota
            // en vez de degradar como los extremos de hora — devolver "sin
            // franja" acá escondería el bug y, encima, la interpolación.
            throw new \InvalidArgumentException('Date::hourRange: columna inválida (' . $column . ')');
        }

        $from = trim((string) ($hourFrom === false || $hourFrom === null ? '' : $hourFrom));
        $to   = trim((string) ($hourTo === false || $hourTo === null ? '' : $hourTo));

        if (!self::isHourBound($from) || !self::isHourBound($to)) {
            return ['', [], false];
        }
        if ($from === '' && $to === '') {
            return ['', [], true];
        }

        $start = $from === '' ? '00:00:00' : self::normalizeHourBound($from, false);
        $end   = $to   === '' ? self::END_OF_DAY : self::normalizeHourBound($to, true);

        // `::time` sobre la columna, no `EXTRACT(HOUR ...)`: el predicado tiene
        // que discriminar el minuto (ver HOUR_BOUND_RE). Sobre `timestamptz` el
        // cast usa la zona de la sesión; el `AT TIME ZONE` la fija explícita.
        $expr    = $timezone !== null && $timezone !== '' ? '(' . $column . ' AT TIME ZONE ?::text)::time' : $column . '::time';
        $tzParam = $timezone !== null && $timezone !== '' ? [$timezone] : [];

        // LA INVERSIÓN: inicio posterior al fin = la franja cruza medianoche
        // (20:00 a 04:00). Con `AND` no devuelve nada nunca.
        $op = $start <= $end ? 'AND' : 'OR';

        return [
            ' AND (' . $expr . ' >= ?::time ' . $op . ' ' . $expr . ' <= ?::time)',
            array_merge($tzParam, [$start], $tzParam, [$end]),
            true,
        ];
    }

    /**
     * Completa el extremo de una franja horaria a `HH:MM:SS[.999999]`.
     *
     * No valida: `hourRange()`/`isHourBound()` ya filtraron. El extremo
     * SUPERIOR se lleva la unidad que se pidió entera (`11:59` →
     * `11:59:59.999999`, `11:59:30` → `11:59:30.999999`) por la misma razón que
     * `rangeEnd()` cierra el día en `.999999`: las columnas tienen precisión de
     * microsegundo.
     */
    private static function normalizeHourBound(string $value, bool $isEnd): string
    {
        // `strlen === 5` es "vino sin segundos" (`HH:MM`).
        $full = strlen($value) === 5 ? $value . ($isEnd ? ':59' : ':00') : $value;

        return $isEnd ? $full . '.999999' : $full;
    }

    /**
     * Formatea una fecha al estilo legacy del POS:
     *   "Domingo 03 de Junio, 2026 a las 14:30"
     *
     * Equivalente legacy: `niceDate($date, $hours, $noDay, $year, $weekDay)`.
     *
     * @param mixed $date     Cualquier formato parseable por strtotime() o '0000-00-00 00:00:00'.
     * @param bool  $hours    Si true, append " a las HH:MM".
     * @param bool  $noDay    Si true, omite el día del mes.
     * @param bool  $year     Si true (default), append ", YYYY".
     * @param bool  $weekDay  Si true, prepend nombre del día ("Lunes", "Martes"...).
     * @return string         "Sin fecha" si entrada inválida; sino fecha formateada.
     */
    public static function nice(mixed $date, bool $hours = false, bool $noDay = false, bool $year = true, bool $weekDay = false): string
    {
        if ($date === '0000-00-00 00:00:00' || !Validation::isValid($date)) {
            return 'Sin fecha';
        }

        $meses = $GLOBALS['meses'] ?? [];
        $ts    = strtotime((string) $date);

        $y       = $year ? ', ' . date('Y', $ts) : '';
        $m       = (int) date('m', $ts);
        $d       = $noDay ? '' : date('d', $ts) . ' de ';
        $h       = date('H', $ts);
        $mi      = date('i', $ts);
        $l       = $weekDay ? self::translateWeekName(date('l', $ts)) . ' ' : '';
        $hoursto = $hours ? ' a las ' . $h . ':' . $mi : '';
        $monthName = $meses[$m - 1] ?? '';

        return $l . $d . $monthName . $y . $hoursto;
    }

    /**
     * Formato "tiempo relativo": "Hace 2 horas", "Hace 3 días", etc.
     * Equivalente legacy: `niceDate2($datetime, $type)`.
     *
     * @param string $type 'normal' (default, 1 unidad + prefijo "Hace"),
     *                     'full' (todas las unidades + prefijo),
     *                     'small' (1 unidad, abreviada, sin prefijo).
     */
    public static function niceAgo(mixed $datetime, string $type = 'normal'): string
    {
        if ($datetime === '0000-00-00 00:00:00' || !Validation::isValid($datetime)) {
            return 'Sin fecha';
        }

        $now    = new \DateTime();
        $ago    = new \DateTime((string) $datetime);
        $diff   = $now->diff($ago);
        $plural = '';

        // El legacy calcula semanas a partir de $diff->d; replico verbatim.
        $weekends  = floor($diff->d / 7);
        $diff->d  -= $weekends * 7;

        if ($type === 'small') {
            $string = [
                'y' => 'año', 'm' => 'mes', 'w' => 'sem',
                'd' => 'día', 'h' => 'h',   'i' => 'min', 's' => 'seg',
            ];
        } else {
            $string = [
                'y' => 'año', 'm' => 'mes', 'w' => 'semana',
                'd' => 'día', 'h' => 'hora', 'i' => 'minutos', 's' => 'segundos',
            ];
        }

        foreach ($string as $k => &$v) {
            if (!empty($diff->$k)) {
                if ($type !== 'small') {
                    $plural = ($k === 'm') ? 'es' : 's';
                }
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? $plural : '');
            } else {
                unset($string[$k]);
            }
        }

        if ($type === 'normal') {
            $string = array_slice($string, 0, 1);
            return $string ? 'Hace ' . implodes(', ', $string) : 'Ahora';
        }
        if ($type === 'full') {
            return $string ? 'Hace ' . implodes(', ', $string) : 'Ahora';
        }
        // small
        $string = array_slice($string, 0, 1);
        return $string ? implodes(', ', $string) : 'Ahora';
    }

    /**
     * Devuelve la fecha + ($times × periodo) en el formato especificado.
     * Usado por el cron de ventas recurrentes para calcular `nextDate` y `endDate`.
     * Equivalente legacy: `getNextDatePeriod($frecuency, $times, $date, $format)`.
     *
     * @param string $frequency 'daily' | 'weekly' | 'monthly' | 'quarterly' | 'yearly' | otro (no-op).
     * @param int    $times     Cantidad de periodos a sumar.
     * @param string $date      Fecha base (default: TODAY).
     * @param string $format    Formato de salida para date() (default: 'Y-m-d 00:00:00').
     *
     * NOTA: 'fortnight' está documentada en el legacy pero su rama está vacía
     * (cae al else de no-op). Preservado verbatim por paridad.
     */
    public static function nextPeriod(string $frequency, int $times, ?string $date = null, string $format = 'Y-m-d 00:00:00'): string
    {
        $date = $date ?? (defined('TODAY') ? TODAY : date('Y-m-d H:i:s'));

        $ts = match ($frequency) {
            'daily'     => strtotime($date . ' +' . $times . ' day'),
            'weekly'    => strtotime($date . ' +' . $times . ' week'),
            'monthly'   => strtotime($date . ' +' . $times . ' month'),
            'quarterly' => strtotime($date . ' +' . ($times * 3) . ' month'),
            'yearly'    => strtotime($date . ' +' . $times . ' year'),
            default     => strtotime($date), // incluye 'fortnight' (paridad legacy: rama vacía).
        };

        return date($format, $ts);
    }

    /**
     * Split de rango horario "YYYY-MM-DD HH:MM:SS" → [fecha, inicio HH:MM, fin HH:MM].
     * Equivalente legacy: `dateStartEndTime($startDate, $endDate)`.
     *
     * @return array{0: string, 1: string, 2: string} [date, startHHMM, endHHMM].
     */
    public static function startEndTime(string $startDate, string $endDate): array
    {
        $date  = explodes(' ', $startDate, 0);
        $start = explodes(' ', $startDate, 1);
        $end   = explodes(' ', $endDate,   1);

        $start = explodes(':', $start, 0) . ':' . explodes(':', $start, 1);
        $end   = explodes(':', $end,   0) . ':' . explodes(':', $end,   1);

        return [$date, $start, $end];
    }

    /**
     * Traduce nombre de día/mes en inglés al español (o reemplaza vacío).
     * Equivalente legacy: `translateNamesOfWeek($word, $lang)`.
     *
     * NOTA: el legacy tenía argumento `$lang` con rama 'br' completamente
     * comentada (bug histórico inocuo). Se preserva verbatim: $lang se ignora.
     */
    public static function translateWeekName(string $word, string $lang = 'es'): string
    {
        $src = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $es  = ['Lunes',  'Martes',  'Miércoles', 'Jueves',   'Viernes', 'Sábado',  'Domingo'];
        return str_replace($src, $es, $word);
    }
}
