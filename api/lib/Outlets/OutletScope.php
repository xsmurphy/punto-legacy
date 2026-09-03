<?php
declare(strict_types=1);

namespace Punto\Api\Outlets;

/**
 * Qué sucursales puede LEER un usuario — la convención de alcance, en un lugar.
 *
 * ── La regla (decisión del owner 2026-08-24, ratificada 2026-09-02) ─────────
 * La fuente de verdad es `contact_outlet` (tabla canónica desde la mig 66),
 * NUNCA la columna legacy `contact.outletid` (que sigue existiendo sin drop y
 * quedó como back-compat: la mig 66 la usó para el backfill).
 *
 *   - Usuario con ≥1 fila en `contact_outlet` → esas sucursales, y solo esas.
 *   - Usuario con CERO filas → es GLOBAL, alcanza todas. Misma semántica que
 *     `fin_account.outletid IS NULL` (ver `context/25-sucursales-y-scopes.md`).
 *
 * Es la MISMA regla que ya aplica `UsersService::rosterForOutlet()` para decidir
 * qué usuarios aparecen en el lock screen de una caja. Está acá y no allá porque
 * ahora tiene un segundo consumidor —`bootstrap.php`, resolviendo el alcance del
 * realm `api`— y dos copias de un criterio de aislamiento de datos divergen: la
 * primera vez que alguien agregue "…y además los inactivos", la va a agregar en
 * una sola.
 *
 * ── La lista vacía significa TODAS, y por eso nunca es un error ─────────────
 * `forUser()` devolviendo `[]` no es "este usuario no ve nada": es "este usuario
 * no tiene restricción". Un caller que lo lea al revés le corta el acceso a
 * todos los dueños de un tenant (que típicamente no tienen filas). El nombre del
 * método no alcanza para decirlo, así que lo dice `isGlobal()`.
 *
 * Corolario incómodo pero deliberado: si a un usuario le asignaron una sola
 * sucursal y esa sucursal se ELIMINA, la fila se va por el `ON DELETE CASCADE`
 * de la mig 66 y el usuario pasa a ser global. Es la consecuencia directa de la
 * convención "cero filas = todas" y se prefiere a la alternativa (quedarse sin
 * ver nada, sin ningún indicio de por qué). Se documenta porque no es obvio.
 */
final class OutletScope
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Sucursales asignadas al usuario dentro de ese tenant.
     *
     * El JOIN contra `outlet` no es decorativo: acota al tenant del contexto por
     * las DOS puntas (la fila de `contact_outlet` ya trae `companyid`, pero
     * confiar en una sola columna para el aislamiento multi-tenant es cómo se
     * escriben los leaks). Ordenado por `outletid` para que el conjunto sea
     * estable entre requests — de él sale el fallback de `OUTLET_ID` cuando hay
     * que elegir uno, y un fallback que cambia de sucursal entre dos llamadas es
     * un reporte que cambia solo.
     *
     * NO filtra por `outletStatus`: una sucursal desactivada sigue teniendo
     * historia, y un reporte del año pasado que la omitiera daría un total
     * incompleto sin decirlo. El estado se muestra donde importa (la lista de
     * sucursales lo expone), no se usa para esconder datos.
     *
     * @return list<string> UUIDs asignados. `[]` = usuario GLOBAL (ver docblock).
     */
    public static function forUser(string $companyId, string $userId): array
    {
        if (!preg_match(self::UUID_RE, $companyId) || !preg_match(self::UUID_RE, $userId)) {
            // Fail-CLOSED al revés de lo que parece: con ids inválidos no hay a
            // quién consultar, y devolver `[]` diría "global" — o sea, todo. Se
            // lanza para que el embudo corte con 500 en vez de servir de más.
            throw new \RuntimeException('Contexto inválido al resolver el alcance por sucursal');
        }

        // `contact_outlet` es TODO lowercase (mig 66), a diferencia de `contact`
        // y `outlet`, que se escriben camelCase sin comillas (PG los pliega a
        // lowercase igual).
        $rows = \ncmRows(
            'SELECT co.outletid
               FROM contact_outlet co
               JOIN outlet o ON o.outletid = co.outletid AND o.companyid = co.companyid
              WHERE co.contactid = ?::uuid
                AND co.companyid = ?::uuid
              ORDER BY co.outletid ASC',
            [$userId, $companyId]
        );

        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r['outletid'] ?? '');
            if ($id !== '' && preg_match(self::UUID_RE, $id)) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /** `true` si el usuario no tiene restricción de sucursal. */
    public static function isGlobal(array $scope): bool
    {
        return $scope === [];
    }

    /**
     * ¿Este realm resuelve su sucursal desde el USUARIO?
     *
     * `api` (API keys y MCP) y `panel` sí: los opera una persona identificada,
     * con un conjunto de sucursales asignadas y una UI o una tool que elige
     * dentro de él.
     *
     * `pos-app` (y los demás realms de device: `screen`, `kds`, `print`) NO, y
     * no es un pendiente: la sucursal de una terminal sale de la fila `device`,
     * o sea del PAREO, y es fija. La tablet no tiene selector que acotar ni
     * usuario propio — el `userId` que lleva es el del contacto que la pareó,
     * y derivar el alcance de él le daría a la caja las sucursales de esa
     * persona en vez de la suya. Ver `context/25-sucursales-y-scopes.md`.
     *
     * Existe como método y no como un `in_array` suelto porque ya lo preguntan
     * `bootstrap.php` y `outlets.php`, y la respuesta tiene que ser la misma en
     * los dos: un realm que quedara adentro en el embudo y afuera en la lista
     * (o al revés) da una UI que ofrece sucursales que después devuelven 403.
     */
    public static function realmIsScoped(string $realm): bool
    {
        return $realm === 'api' || $realm === 'panel';
    }

    /**
     * ¿Puede este usuario leer esa sucursal?
     *
     * Un usuario global alcanza cualquier sucursal DEL TENANT — de ahí el
     * chequeo de pertenencia, que es lo que evita que un `outletId` de otra
     * empresa pase por ser "global, todo vale".
     */
    public static function allows(array $scope, string $outletId, string $companyId): bool
    {
        if (!preg_match(self::UUID_RE, $outletId)) {
            return false;
        }
        if (!self::isGlobal($scope)) {
            return in_array($outletId, $scope, true);
        }
        $row = \ncmExecute(
            'SELECT 1 FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$outletId, $companyId]
        );
        return (bool) $row;
    }

    // ── El alcance de ESTA request ───────────────────────────────────────────
    //
    // `bootstrap.php` lo resuelve una vez por request y lo deja en la constante
    // `VIEW_OUTLET_IDS`. Los lectores lo consumen por acá y no leyendo la
    // constante a mano, por lo mismo que `Roc` centraliza el fragmento SQL: un
    // criterio de aislamiento copiado en veinte archivos se corrige en
    // diecinueve.

    /**
     * Ids del alcance vigente. `[]` = sin restricción (todo el tenant).
     *
     * Re-valida cada uuid aunque `bootstrap.php` ya lo hizo: el valor termina
     * INTERPOLADO en SQL por `sqlIn()`, y una defensa que depende de que el
     * productor haya validado bien es una defensa que existe una sola vez.
     *
     * @return list<string>
     */
    public static function current(): array
    {
        if (!\defined('VIEW_OUTLET_IDS')) {
            return [];
        }
        $raw = \constant('VIEW_OUTLET_IDS');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = (string) $id;
            if (preg_match(self::UUID_RE, $id)) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * El alcance expresado como UN solo outlet, para las lecturas que no pasan
     * por `Roc::build` y bindean un valor único.
     *
     * Unifica el idiom que estaba copiado en cinco endpoints
     * (`defined('VIEW_OUTLET_ID') ? VIEW_OUTLET_ID : OUTLET_ID`, más el guard de
     * uuid) y le agrega el único caso que ese idiom no puede representar.
     *
     * @return string|null  `''` = sin filtro por sucursal (consolidado del
     *                      tenant); un uuid = esa sucursal; `null` = el alcance
     *                      es un SUBCONJUNTO de 2 o más y no entra en un valor
     *                      único — el caller DEBE cortar, nunca elegir uno.
     *
     * Ese `null` es el punto: la alternativa cómoda era devolver el primero del
     * conjunto, y eso le daría al usuario el total de UNA sucursal presentado
     * como el de todas las suyas. Un número incompleto que parece correcto es
     * peor que un error — el error se puede reformular con `outletId`, el
     * número equivocado se copia a una planilla.
     *
     * ── El orden importa, y es el MISMO que el de `Roc::build()` ─────────────
     * Primero la sucursal ÚNICA (`VIEW_OUTLET_ID`, ya validada contra el
     * conjunto por `bootstrap.php`), y recién después el conjunto. Si se
     * mirara el conjunto primero, un usuario de 2 sucursales PARADO EN UNA
     * —el caso normal del panel, que siempre manda `X-Outlet-Id`— recibiría
     * `null` y un 422, teniendo la respuesta pedida a mano.
     *
     * Que este método y `Roc::build()` desempaten igual no es prolijidad: son
     * los dos caminos por los que sale la MISMA pregunta ("¿qué sucursales?"),
     * uno como fragmento SQL y el otro como valor bindeado, y a veces en la
     * misma respuesta. Cuando desempataban distinto, el fragmento salía acotado
     * y el valor único salía abierto — la fuga del 2026-09-02 (`58b40d08`), que
     * ningún test de totales veía porque los dos devolvían números plausibles.
     */
    public static function single(): ?string
    {
        $ids = self::effectiveIds();
        if (count($ids) > 1) {
            return null;
        }
        return $ids[0] ?? '';
    }

    /**
     * El alcance de esta request como LISTA — la forma que sí puede expresar los
     * tres estados sin perder ninguno.
     *
     * @return list<string> `[]` = sin filtro por sucursal (el tenant entero);
     *                      1 elemento = esa sucursal; 2+ = el consolidado
     *                      ACOTADO a las sucursales del usuario.
     *
     * Es la misma pregunta que responden `Roc::build()` (como fragmento SQL) y
     * `single()` (como valor único), con el MISMO desempate — de hecho `single()`
     * ahora se deriva de acá, que es la única forma de garantizar que no se
     * separen otra vez.
     *
     * Existe porque `single()` no alcanza y el 422 no es una respuesta aceptable
     * en el panel. Para el realm `api` sí lo era: del otro lado hay un modelo que
     * lee "pedí una sucursal a la vez" y reformula. Del otro lado del panel hay
     * una persona que eligió "Todas" en el selector y espera ver sus números —
     * devolverle un error en el dashboard, los reportes de marcas, categorías,
     * balance y flujo de efectivo no es fallar seguro, es no tener la feature.
     * Los lectores que agregan (SUM/GROUP BY) pueden con una lista perfectamente;
     * lo que no podían era recibirla.
     */
    public static function effectiveIds(): array
    {
        // Una sucursal puntual gana: es el selector del panel parado en una
        // sucursal, o una consulta con `?outletId=`. `bootstrap.php` ya la
        // validó contra el conjunto (403 si no), así que acá no hay que
        // re-chequear pertenencia — solo forma.
        $v = \defined('VIEW_OUTLET_ID')
            ? (string) \constant('VIEW_OUTLET_ID')
            : (\defined('OUTLET_ID') ? (string) \constant('OUTLET_ID') : '');
        if (preg_match(self::UUID_RE, $v)) {
            return [$v];
        }

        return self::current();
    }

    /**
     * El fragmento SQL del filtro de sucursal para los lectores que NO pasan por
     * `Roc::build()` — los que consultan el rollup, el inventario y las cuentas.
     *
     * @param string       $column  nombre (o `alias.nombre`) de la columna de outlet.
     * @param list<string> $ids     el alcance (`effectiveIds()`); `[]` = sin filtro.
     * @param bool         $orNull  incluir además las filas con la columna NULL.
     *                              Es el caso de `fin_account` y de la agenda del
     *                              dashboard, donde NULL significa "de todas las
     *                              sucursales" (una cuenta global del comercio),
     *                              no "sin dato" — perderlas al acotar el alcance
     *                              haría desaparecer plata del balance.
     * @return string Fragmento que arranca con " AND ", o `''` si no hay filtro.
     *
     * ── Interpola, no bindea, y es a propósito ───────────────────────────────
     * Mismo criterio que `Roc::build()`, por una razón concreta de este
     * codebase: estos lectores arman `$params` a mano y varios insertan binds EN
     * EL MEDIO del array (las seis fechas que `CashflowService` antepone, el
     * `contactId` que `OpenInvoicesService` agrega después del outlet, el
     * `$dateCut` de `Inventory::onHandBulk` — que además DUPLICA el array para
     * sus dos CTEs). Cambiar un `?` por N corre todos esos binds, y un bind
     * corrido no rompe: devuelve otro número. Un fragmento interpolado no toca
     * el array de params, así que el cambio es imposible de desalinear.
     *
     * Seguro por construcción, no por confianza: cada id se re-valida contra
     * `UUID_RE` acá mismo y lo que no matchea se descarta. No es texto del
     * request — sale de `contact_outlet`— pero la defensa no depende de eso.
     */
    public static function sqlFilter(string $column, array $ids, bool $orNull = false): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (preg_match(self::UUID_RE, $id)) {
                $clean[] = $id;
            }
        }
        $clean = array_values(array_unique($clean));

        if ($clean === []) {
            // Sin restricción. OJO: no se emite `IS NULL` ni nada — el lector
            // ve todas las filas, que es el comportamiento histórico del `''`.
            return '';
        }

        $col  = trim($column);
        $cond = count($clean) === 1
            ? "{$col} = '" . $clean[0] . "'"
            : "{$col} IN ('" . implode("', '", $clean) . "')";

        if ($orNull) {
            $cond = "({$cond} OR {$col} IS NULL)";
        }
        return ' AND ' . $cond;
    }

    /**
     * El mensaje del 422 cuando `single()` devolvió `null`.
     *
     * Vive acá y no en cada endpoint porque quien lo lee es un MODELO: es lo
     * único que tiene para decidir si reformular la consulta o rendirse, y cinco
     * redacciones distintas del mismo límite le enseñan que son cinco límites
     * distintos. Nombra las sucursales alcanzables para que la segunda llamada
     * salga bien sin pasar antes por `get_outlets`.
     */
    public static function subsetNotSupportedMessage(): string
    {
        $ids = self::current();
        return 'Este reporte se sirve de una sola sucursal por consulta y tu usuario tiene '
            . count($ids) . ' asignadas. Volvé a pedirlo indicando outletId con una de estas: '
            . implode(', ', $ids) . '.';
    }
}
