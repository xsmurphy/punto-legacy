<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * ¿Este dispositivo dejó rastro operativo? — la pregunta que decide si se
 * puede BORRAR físicamente de `device`.
 *
 * El incidente (owner, 2026-09-01)
 * ────────────────────────────────
 * "Eliminar" sobre un dispositivo ya revocado explotaba con el error crudo de
 * Postgres:
 *
 *   SQLSTATE[23503]: Foreign key violation: update or delete on table "device"
 *   violates foreign key constraint "register_lease_deviceId_fkey"
 *
 * `register_lease.deviceid` es `NOT NULL REFERENCES device(deviceid)` sin
 * `ON DELETE` (mig 141), y eso está BIEN: `register_lease` es el rastro de qué
 * aparato tenía qué caja cuando se emitió cada comprobante. Un `CASCADE`
 * borraría esa cadena y un `SET NULL` la desataría — las dos rompen la
 * auditoría fiscal que la tabla existe para sostener. La FK no es el problema:
 * el problema es ofrecer una acción que no puede funcionar.
 *
 * Por qué el chequeo NO es solo `register_lease`
 * ──────────────────────────────────────────────
 * Criterio del owner, más amplio que el error que vio: *"un device con
 * historial operativo no se debería poder eliminar porque de lo contrario algo
 * queda huérfano"*. `register_lease` es la única tabla con FK DURA a `device`,
 * o sea la única que grita. Las otras tres llevan el `deviceId` SIN FK, así que
 * un hard delete las deja apuntando a un aparato que ya no existe **en
 * silencio** — que es peor que el 23503, porque nadie se entera:
 *
 *   - `auth_session.deviceid`     (mig 69, sin FK)  — sesiones del aparato.
 *   - `station_printer.deviceid`  (mig 83, NOT NULL, sin FK a propósito)
 *   - `pos_order_event.actor_id`  (mig 85, con `actor_kind='device'`)
 *
 * `printer_binding.bluetoothdeviceid` (mig 61) NO cuenta y no está acá: es una
 * MAC de Bluetooth, no un `device` de Punto.
 *
 * Sobre `station_printer` — es el caso más discutible de los cuatro y se
 * incluye a conciencia. Visto solo, es CONFIGURACIÓN descartable: la estación
 * descubre el hardware y lo vuelve a registrar sola. Lo que lo vuelve historial
 * es lo que cuelga de él: `print_job` referencia `station_printer` con
 * `ON DELETE CASCADE`, así que esas filas son la trazabilidad de qué se imprimió
 * y dónde. Borrar el device no las borra —no hay FK que cascadee desde acá— pero
 * deja impresoras huérfanas que siguen listándose y siguen siendo elegibles en
 * `printer_binding`, apuntando a una estación que ya no existe. Ese es
 * exactamente el "algo queda huérfano" del criterio.
 *
 * Un dispositivo SIN ninguna de las cuatro cosas —un KDS, un display, un POS
 * que nunca tomó caja ni tocó una orden— se borra como siempre. La barrera no
 * es el tipo de aparato, es haber dejado rastro.
 *
 * Una sola definición, dos consumidores
 * ─────────────────────────────────────
 * `selectSql()` produce las expresiones que van EMBEBIDAS en una query sobre
 * `device`, y las usan los dos lados de la misma regla:
 *
 *   - `GET /v1/devices` — para que el panel deshabilite "Eliminar" con el
 *     motivo a la vista, en vez de ofrecer un botón que va a fallar.
 *   - `DELETE /v1/devices?hard=1` — el gate real, que devuelve 409 explicando
 *     en qué consiste el historial.
 *
 * Si mañana aparece una quinta tabla con `deviceId`, se suma a `SOURCES` y las
 * dos superficies se enteran juntas. Ese es todo el punto de que esto sea un
 * servicio y no dos `EXISTS` copiados.
 */
final class DeviceHistoryService
{
    /**
     * Fuentes de historial, en orden de gravedad.
     *
     * `{d}` es el alias de la tabla `device` en la query del caller. Cada
     * expresión se castea a `int` a propósito: PDO/pgsql devuelve los booleanos
     * con una representación que depende de la configuración del driver
     * (`t`/`f`, `1`/``, bool nativo), y comparar eso con `== true` es la clase
     * de ambigüedad que en este codebase ya causó bugs de runtime. Un `0`/`1`
     * no admite interpretación.
     *
     * `pos_order_event` filtra por `actor_kind = 'device'` y no solo por
     * `actor_id`: la columna guarda un `userid` O un `deviceid` según el actor,
     * y aunque una colisión de UUID entre las dos poblaciones es prácticamente
     * imposible, el predicado correcto es el que dice lo que se quiso decir.
     * Además hace usable el índice parcial de la mig 184.
     *
     * Los alias internos van prefijados (`h_rl`, `h_as`, `h_oe`, `h_sp`) porque
     * estas expresiones se EMBEBEN en la query de otro: el `GET /v1/devices`
     * ya tiene un `register_lease rl` y un `auth_session s` propios, y reusar
     * esos nombres acá adentro los sombrearía. Sombrear no rompería nada hoy
     * —la subconsulta resuelve a su propio alias— pero deja una trampa para el
     * día que alguien quiera correlacionar contra el de afuera.
     *
     * Casing: las cuatro tablas son lowercase físico y van SIN comillas.
     * `auth_session`, `register_lease` y `station_printer` nacieron con
     * columnas camelCase entrecomilladas, pero la mig 150 las normalizó junto
     * con las otras 15 tablas mixtas; `pos_order_event` (mig 85) nació
     * lowercase.
     *
     * @var array<string,array{column:string,label:string,sql:string}>
     */
    private const SOURCES = [
        // Auditoría FISCAL: qué aparato tenía qué caja al emitir cada
        // comprobante. Es la única con FK dura — y la razón por la que esa FK
        // no lleva ON DELETE.
        'register_lease' => [
            'column' => 'history_register_lease',
            'label'  => 'tenencia de cajas',
            'sql'    => '(EXISTS (SELECT 1 FROM register_lease h_rl
                                   WHERE h_rl.deviceid  = {d}.deviceid
                                     AND h_rl.companyid = {d}.companyid))::int',
        ],
        'auth_session' => [
            'column' => 'history_auth_session',
            'label'  => 'sesiones de acceso',
            'sql'    => '(EXISTS (SELECT 1 FROM auth_session h_as
                                   WHERE h_as.deviceid  = {d}.deviceid
                                     AND h_as.companyid = {d}.companyid))::int',
        ],
        'pos_order_event' => [
            'column' => 'history_order_event',
            'label'  => 'actividad sobre órdenes',
            'sql'    => '(EXISTS (SELECT 1 FROM pos_order_event h_oe
                                   WHERE h_oe.actor_kind = \'device\'
                                     AND h_oe.actor_id   = {d}.deviceid
                                     AND h_oe.companyid  = {d}.companyid))::int',
        ],
        'station_printer' => [
            'column' => 'history_station_printer',
            'label'  => 'impresoras de la estación',
            'sql'    => '(EXISTS (SELECT 1 FROM station_printer h_sp
                                   WHERE h_sp.deviceid  = {d}.deviceid
                                     AND h_sp.companyid = {d}.companyid))::int',
        ],
    ];

    /**
     * Expresiones `EXISTS ... AS <alias>` para embeber en un SELECT sobre
     * `device`. No lleva parámetros: son subconsultas CORRELACIONADAS contra
     * las columnas del alias, así que el mismo texto sirve para una fila o para
     * el listado entero.
     */
    public static function selectSql(string $deviceAlias = 'd'): string
    {
        $parts = [];
        foreach (self::SOURCES as $src) {
            $parts[] = str_replace('{d}', $deviceAlias, $src['sql']) . ' AS ' . $src['column'];
        }
        return implode(",\n            ", $parts);
    }

    /**
     * Lee de una fila ya traída por una query que incluyó `selectSql()` qué
     * tipos de historial tiene ese device.
     *
     * @param  array<string,mixed>|\ArrayAccess<string,mixed> $row
     * @return list<string> claves de `SOURCES` presentes; `[]` = sin historial.
     */
    public static function kindsFromRow($row): array
    {
        $kinds = [];
        foreach (self::SOURCES as $key => $src) {
            if ((int) ($row[$src['column']] ?? 0) === 1) {
                $kinds[] = $key;
            }
        }
        return $kinds;
    }

    /**
     * Igual que `kindsFromRow()` pero resolviendo la fila por su cuenta — para
     * el caller que tiene un `deviceId` y nada más (el DELETE).
     *
     * El `companyId` va DENTRO de la query, nunca comparado después: mismo
     * criterio anti-IDOR que el resto de `devices.php`.
     *
     * @return list<string>
     */
    public static function kindsFor(string $deviceId, string $companyId): array
    {
        $row = ncmExecute(
            'SELECT ' . self::selectSql('d') . '
               FROM device d
              WHERE d.deviceid = ?::uuid AND d.companyid = ?::uuid',
            [$deviceId, $companyId]
        );
        if ($row === false || $row === 0) {
            // El device no existe para este tenant. Sin fila no hay historial
            // que reportar — el caller ya resolvió el 404 antes de llegar acá.
            return [];
        }
        return self::kindsFromRow($row);
    }

    /**
     * Enumeración legible de los tipos de historial, para el mensaje del 409:
     * "tenencia de cajas, sesiones de acceso y actividad sobre órdenes".
     *
     * El PANEL no consume este texto — recibe las CLAVES (`historyKinds`) y
     * arma su propia copia en `lib/devices/connected-device.ts`. El contrato
     * entre las dos capas son las claves semánticas, no el castellano: este
     * mensaje es para quien llega al endpoint sin pasar por la UI.
     *
     * @param list<string> $kinds
     */
    public static function describe(array $kinds): string
    {
        $labels = [];
        foreach ($kinds as $kind) {
            if (isset(self::SOURCES[$kind])) {
                $labels[] = self::SOURCES[$kind]['label'];
            }
        }
        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        $last = array_pop($labels);
        return implode(', ', $labels) . ' y ' . $last;
    }
}
