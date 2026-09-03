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
     */
    public static function single(): ?string
    {
        $set = self::current();
        if (count($set) > 1) {
            return null;
        }
        if (count($set) === 1) {
            return $set[0];
        }
        // Sin restricción: el comportamiento histórico, intacto. Panel con el
        // selector del logo → `VIEW_OUTLET_ID`; pos-app y realm `api` global →
        // la sucursal del contexto. Un valor que no es uuid (incluido el `''`
        // del modo "Todas") significa consolidado.
        $v = \defined('VIEW_OUTLET_ID')
            ? (string) \constant('VIEW_OUTLET_ID')
            : (\defined('OUTLET_ID') ? (string) \constant('OUTLET_ID') : '');

        return preg_match(self::UUID_RE, $v) ? $v : '';
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
