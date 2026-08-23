<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * Tenencia de caja (context/29-numeracion-y-exclusividad-de-caja.md §4).
 *
 * `register_lease` (mig 141) es la unidad de TENENCIA: garantiza "una caja,
 * un dispositivo a la vez" con `UNIQUE (registerid) WHERE status =
 * 'active'` a nivel de BD, no de aplicación. Es INDEPENDIENTE de la
 * numeración fiscal — el arriendo de bloques de números (`numbering_lease`)
 * que antes vivía pegado a esta tenencia fue RECHAZADO por el owner
 * 2026-08-17 (§6 del doc: la unicidad del punto de expedición ya resuelve
 * solo el problema que el arriendo intentaba resolver). Este servicio ya NO
 * sabe nada de números — solo de quién tiene la caja tomada.
 *
 * Este servicio concentra las operaciones que la máquina de estados del §4
 * necesita alrededor del constraint:
 *
 *   - holderConflict(): quién tiene la caja tomada AHORA, si no es
 *     `$deviceId` — usado tanto para decidir si un claim nuevo entra, como
 *     para que `sales.php`/`offline-sync.php` rechacen una venta de un
 *     device que ya no es el tenedor real.
 *   - close():          la transición TOMADA → {released|forced} → LIBRE.
 *
 * `close()` sigue anulando (§6.1) los números `numbering_lease` no
 * consumidos que hayan quedado atados a esa tenencia — relevante solo para
 * filas HISTÓRICAS (de antes de este cambio, `registerLeaseId` seteado);
 * ya no hay escritores nuevos de `numbering_lease`, así que en tenencias
 * creadas después de este cambio ese UPDATE es un no-op esperado.
 *
 * Todo método que toca `register_lease` asume que el caller ya está DENTRO
 * de la transacción + `pg_advisory_xact_lock(hashtext(registerId))` que
 * serializa el acceso a una caja (`api/v1/register/claim.php`). Este
 * servicio NO abre transacciones ni toma locks — eso es responsabilidad del
 * caller, porque "chequear tenencia → decidir" tiene que ser atómico con lo
 * que el caller haga a continuación (tomar la caja, rechazar con 409).
 *
 * La tenencia YA NO vence por fecha/TTL (context/29 §4, corrección del
 * 2026-08-17 — antes vencía a fin de la fecha del outlet para que un bloque
 * de números no sobreviviera a un cambio de día; sin bloques, no aplica).
 * Se libera SOLO al cerrar la caja o por revocación explícita de admin.
 * `close('forced', ...)` YA tiene dos callers reales (el comentario que decía
 * "sin caller hasta que exista ese endpoint" quedó viejo y llegó a inducir una
 * conclusión equivocada en un review): `api/v1/register-lease.php` (panel,
 * "Liberar caja", F4 — implementada, ver context/29 §7) y `api/v1/devices.php`
 * (el admin revoca el dispositivo). Los dos producen `status='forced'`, que es
 * lo que `holderConflict()` lee para distinguir "me la revocaron" de "la cerré
 * yo" — o sea que es el camino del incidente offline del 2026-08-23, no un
 * caso hipotético.
 */
final class RegisterLeaseService
{
    /**
     * Cierra una tenencia activa: TOMADA → {released|forced} → LIBRE (§4 del
     * plan), y anula (§6.1) los números `numbering_lease` HISTÓRICOS que
     * hayan quedado atados a ella sin consumir (ver docblock de clase — ya
     * no hay escritores nuevos de esa tabla, así que en tenencias creadas
     * después de este cambio ese segundo UPDATE afecta 0 filas). Es un
     * no-op silencioso si `$registerLeaseId` ya no está `active` (dos
     * callers concurrentes cerrando la misma tenencia no chocan, el segundo
     * UPDATE simplemente afecta 0 filas).
     *
     * `status`/`voidReason` siguen aceptando `'expired'` porque el CHECK de
     * `register_lease`/`numbering_lease` (mig 141) todavía lo permite —
     * valor histórico, ya no lo produce ningún caller desde que la tenencia
     * dejó de vencer por fecha (context/29 §4, 2026-08-17).
     *
     * Números YA anulados (`voidedAt IS NOT NULL`, ej. por un cierre previo
     * que corrió a medias) no se re-anulan — `voidedAt`/`voidReason` quedan
     * con su valor original, ver `WHERE ... voidedat IS NULL` abajo.
     */
    public static function close(
        string $registerLeaseId,
        string $status,
        string $releasedBy,
        string $voidReason,
    ): void {
        if (!in_array($status, ['expired', 'released', 'forced'], true)) {
            throw new \InvalidArgumentException('status inválido para close(): ' . $status);
        }
        if (!in_array($voidReason, ['expired', 'released', 'forced'], true)) {
            throw new \InvalidArgumentException('voidReason inválido para close(): ' . $voidReason);
        }

        ncmExecute(
            'UPDATE "register_lease"
                SET "status" = ?, releasedat = NOW(), releasedby = ?
              WHERE registerleaseid = ? AND "status" = \'active\'',
            [$status, $releasedBy, $registerLeaseId]
        );

        ncmExecute(
            'UPDATE "numbering_lease"
                SET voidedat = NOW(), voidreason = ?
              WHERE registerleaseid = ? AND consumedat IS NULL AND voidedat IS NULL',
            [$voidReason, $registerLeaseId]
        );
    }

    /**
     * Libera la tenencia activa de `$deviceId`, si tiene alguna — self-contained:
     * abre su propia transacción + advisory lock por la caja que va a liberar (a
     * diferencia de `close()`, el caller no conoce ese `registerId` de antemano,
     * lo resuelve acá mismo a partir del device). `StartTrans()`/`CompleteTrans()`
     * soportan anidamiento (`api/includes/lib/DB.php`) — un caller que YA está
     * dentro de su propia transacción (ej. `claim.php`, con su advisory lock
     * tomado sobre OTRO registerId) puede llamar este método sin problema: el
     * lock que abre acá es sobre una caja DISTINTA, así que no hay auto-deadlock,
     * y el commit/rollback real lo decide siempre el nivel más externo.
     *
     * Cuatro callers, todos son "esta tenencia tiene que cerrarse aunque el
     * propio device que la tomó no coopere, o ni siquiera sepa que la perdió"
     * (context/29 §4 — huecos que dejaban una tenencia huérfana para siempre):
     *   - `api/v1/devices.php` (DELETE, admin revoca desde el panel,
     *     detectado 2026-08-19)
     *   - `api/v1/auth/unpair-pos-device.php` (el device se despareja a sí mismo,
     *     "Eliminar dispositivo del comercio" en Ajustes del POS,
     *     detectado 2026-08-19)
     *   - `api/v1/active-register.php` (el device cambia de caja — sea porque el
     *     operador lo mueve desde "Ajustes del POS" o porque el admin lo
     *     reasigna; la caja VIEJA se libera antes del `UPDATE device`, mismo
     *     criterio que "liberación normal" del §4. Caso real verificado en prod
     *     2026-08-20: "Christian Mac" quedó con tenencia colgada en "Caja
     *     Mariano" tras moverse a "Nueva Caja")
     *   - `api/v1/register/claim.php` (defensa en profundidad: antes de crear
     *     una tenencia NUEVA para este device, cierra cualquier OTRA que ya
     *     tuviera — así, aunque algún camino futuro se olvide de liberar al
     *     cambiar `device.registerid`, el sistema se autocorrige en el próximo
     *     `claim()`. Nunca toca la tenencia de OTRO device — eso sigue
     *     prohibido, §6)
     *   - `RegisterLeaseService` en general — un device revocado o despareado no
     *     puede volver a `claim.php` para liberarse solo: si nadie más libera su
     *     tenencia, queda tomada para siempre (la tenencia ya no vence por fecha).
     *
     * No-op silencioso si `$deviceId` no tiene tenencia activa — la inmensa
     * mayoría de los revokes/unpairs/cambios de caja son de devices que nunca
     * tomaron una caja, o que ya la habían liberado (cerrar caja normal antes
     * de desconectarse/moverse).
     */
    public static function releaseByDevice(
        string $deviceId,
        string $companyId,
        string $releasedBy,
        string $status = 'forced',
    ): void {
        $lease = ncmExecute(
            'SELECT registerleaseid, registerid FROM "register_lease"
              WHERE deviceid = ? AND companyid = ? AND "status" = \'active\' LIMIT 1',
            [$deviceId, $companyId]
        );
        if ($lease === false || $lease === 0) {
            return;
        }
        $registerLeaseId = (string) $lease['registerLeaseId'];
        $registerId      = (string) $lease['registerId'];

        global $db;
        $db->StartTrans();

        // Mismo lock exclusivo por caja que claim.php/register-lease.php — evita
        // pisar una toma concurrente de la MISMA caja mientras esta se libera.
        ncmExecute('SELECT pg_advisory_xact_lock(hashtext(?))', [$registerId]);

        // Releer bajo lock: puede haberse liberado (o hasta retomado por otro
        // device, si esta llamada llega tarde) entre el SELECT de arriba y acá.
        $current = ncmExecute(
            'SELECT "status" FROM "register_lease" WHERE registerleaseid = ?::uuid FOR UPDATE',
            [$registerLeaseId]
        );
        if ($current !== false && $current !== 0 && (string) $current['status'] === 'active') {
            self::close($registerLeaseId, $status, $releasedBy, $status);
        }

        $db->CompleteTrans();
    }

    /**
     * Chequea si `$deviceId` es el tenedor ACTUAL de `$registerId` — el
     * único chequeo de tenencia que sobrevive a la eliminación del arriendo
     * de números (context/29 §6, RECHAZADO 2026-08-17). Reemplaza a la
     * antigua `validateInvoiceNoTenancy()`, que validaba un `invoiceNo`
     * contra `numbering_lease`; ahora que el número lo decide el device
     * (`último correlativo de mi caja + 1`, sin reserva previa), lo único
     * que puede duplicar un comprobante es que DOS dispositivos operen la
     * misma caja a la vez — y eso ya lo previene `register_lease` solo.
     *
     * Tres callers:
     *   - `api/v1/register/claim.php`: ¿puede este device tomar la caja?
     *   - `api/v1/sales.php` (camino online): ¿sigue siendo el tenedor antes
     *     de guardar la venta?
     *   - `api/v1/offline-sync.php` (por venta encolada): ¿seguía siendo el
     *     tenedor real, o la caja se liberó/la tomó otro device mientras
     *     este estaba offline? Si no, la venta encolada se rechaza —
     *     aceptarla arriesgaría un correlativo que el tenedor real ya haya
     *     emitido con otro número.
     *
     * Devuelve `null` si `$deviceId` es el tenedor válido. Si NO, devuelve
     * el detalle del tenedor REAL ahora mismo (`{holderDeviceId,
     * holderDeviceName, expiresAt, reason, releasedBy, releasedAt}` —
     * `expiresAt` siempre `null` desde que la tenencia dejó de vencer por
     * fecha, se mantiene en el shape por compatibilidad con el 409 que el POS
     * ya sabe leer) — puede no haber tenedor (`holderDeviceId: null`, caja
     * libre).
     *
     * `reason` — POR QUÉ no es el tenedor. Hasta 2026-08-23 el POS recibía un
     * solo `REGISTER_NOT_HELD` con el texto "la caja fue liberada, tomada por
     * otro dispositivo, o cerrada": tres causas con tres remedios DISTINTOS
     * mezcladas en un mensaje que no le dice al cajero qué hacer. Peor: dos de
     * ellas (`released`/`never_held`) dejan la caja LIBRE, o sea que la venta
     * encolada se puede recuperar sola con un claim + reintento, y el POS la
     * estaba marcando terminal igual que el caso irrecuperable. Los cuatro
     * valores:
     *
     *   - `taken_by_other` — otro device tiene la caja tomada AHORA. Único
     *     caso NO recuperable por el device solo: hace falta que un admin la
     *     libere (`/v1/register-lease`, "Liberar caja") o que el otro device
     *     cierre. `holderDeviceId`/`holderDeviceName` dicen quién.
     *   - `revoked` — nadie la tiene, y la última tenencia de ESTE device
     *     sobre ESTA caja se cerró `forced`: un admin la liberó desde el
     *     panel, o el device fue revocado/despareado/reasignado. Es EL caso
     *     inevitable del incidente offline (context/29 §4): mientras el device
     *     estaba sin red le sacaron la caja. Recuperable: la caja está libre,
     *     el device la vuelve a tomar y el número que imprimió sigue siendo el
     *     correcto para esa rama de numeración (y si no lo fuera,
     *     `uq_transaction_expedition_invoiceno` de mig 145 lo ataja con
     *     NUMBER_TAKEN, que ya es un código propio).
     *   - `released` — nadie la tiene, y la última tenencia de este device se
     *     cerró `released`/`expired`: cierre normal de caja, o cambio de caja
     *     del propio device. Recuperable igual que `revoked`, pero el mensaje
     *     tiene que decir otra cosa — acá no intervino nadie más.
     *   - `never_held` — nadie la tiene y este device NUNCA tomó esta caja.
     *     Es el arranque offline sin claim previo (el device booteó del
     *     snapshot de IndexedDB, `claim.php` nunca corrió) y también el
     *     device recién pareado. Recuperable con un claim.
     *
     * `releasedBy` (TEXT libre, `'admin:{contactId}'` | `'device:...'` | ...)
     * viaja para que el POS pueda decir "un administrador" en vez de un
     * genérico; `releasedAt` para poder fechar el evento en la cola de ventas.
     *
     * @return array{holderDeviceId:?string,holderDeviceName:?string,expiresAt:?string,reason:string,releasedBy:?string,releasedAt:?string}|null
     */
    public static function holderConflict(
        string $registerId,
        string $companyId,
        string $deviceId,
    ): ?array {
        $activeLease = ncmExecute(
            'SELECT deviceid FROM "register_lease"
              WHERE registerid = ? AND "status" = \'active\' LIMIT 1',
            [$registerId]
        );

        if ($activeLease !== false && $activeLease !== 0 && (string) $activeLease['deviceId'] === $deviceId) {
            return null;
        }

        if ($activeLease === false || $activeLease === 0) {
            // Caja LIBRE. Distinguir "me la sacaron" de "la cerré yo" de
            // "nunca la tuve" mirando la última tenencia de este device sobre
            // esta caja — `close()` hace UPDATE, no DELETE, así que la
            // historia está toda ahí (idx_register_lease_register /
            // idx_register_lease_device, mig 141).
            $last = ncmExecute(
                'SELECT "status", releasedby, releasedat
                   FROM "register_lease"
                  WHERE registerid = ? AND deviceid = ? AND companyid = ?
                  ORDER BY takenat DESC
                  LIMIT 1',
                [$registerId, $deviceId, $companyId]
            );
            $hasLast = $last !== false && $last !== 0;

            return [
                'holderDeviceId'   => null,
                'holderDeviceName' => null,
                'expiresAt'        => null,
                'reason'           => !$hasLast
                    ? 'never_held'
                    : ((string) $last['status'] === 'forced' ? 'revoked' : 'released'),
                'releasedBy'       => $hasLast ? (($last['releasedBy'] ?? null) !== null ? (string) $last['releasedBy'] : null) : null,
                'releasedAt'       => $hasLast ? (($last['releasedAt'] ?? null) !== null ? (string) $last['releasedAt'] : null) : null,
            ];
        }

        $holderDeviceId = (string) $activeLease['deviceId'];
        $holderRow = ncmExecute(
            'SELECT deviceName FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid LIMIT 1',
            [$holderDeviceId, $companyId]
        );
        $holderHasRow = $holderRow !== false && $holderRow !== 0;

        return [
            'holderDeviceId'   => $holderDeviceId,
            'holderDeviceName' => $holderHasRow ? (string) ($holderRow['deviceName'] ?? '') : '',
            'expiresAt'        => null,
            'reason'           => 'taken_by_other',
            'releasedBy'       => null,
            'releasedAt'       => null,
        ];
    }

    /**
     * Traduce el `reason` de `holderConflict()` al par (código, mensaje) que
     * viaja al POS — una sola fuente para el 409 online (`sales.php`) y para
     * el resultado por-venta del sync offline (`offline-sync.php`), así los
     * dos caminos le dicen exactamente lo mismo al cajero.
     *
     * El CÓDIGO gobierna el comportamiento del front, el MENSAJE la
     * comprensión. Solo `REGISTER_TAKEN` es terminal — los otros tres dejan la
     * caja libre y se resuelven con un claim + reintento, que el POS hace
     * solo (`ensureTenancy()` en `lib/pos/register-tenancy.ts`).
     *
     * @param array{holderDeviceId:?string,holderDeviceName:?string,reason?:string,releasedBy?:?string} $conflict
     * @return array{0:string,1:string}
     */
    public static function conflictMessage(array $conflict): array
    {
        $reason = (string) ($conflict['reason'] ?? 'taken_by_other');

        if ($reason === 'taken_by_other') {
            $holder = (string) ($conflict['holderDeviceName'] ?? '');
            return [
                'REGISTER_TAKEN',
                $holder !== ''
                    ? 'Esta caja la tiene tomada ' . $holder . '. Pedí que la liberen desde Ajustes → Sucursales → Cajas y reintentá.'
                    : 'Esta caja la tiene tomada otro dispositivo. Pedí que la liberen desde Ajustes → Sucursales → Cajas y reintentá.',
            ];
        }

        if ($reason === 'revoked') {
            $by = (string) ($conflict['releasedBy'] ?? '');
            return [
                'REGISTER_RELEASED',
                str_starts_with($by, 'admin:')
                    ? 'Un administrador liberó esta caja mientras este dispositivo estaba sin conexión. La caja está libre: volvé a tomarla y la venta se sincroniza sola.'
                    : 'Esta caja se liberó mientras este dispositivo estaba sin conexión. La caja está libre: volvé a tomarla y la venta se sincroniza sola.',
            ];
        }

        if ($reason === 'released') {
            return [
                'REGISTER_RELEASED',
                'Esta caja se cerró desde este dispositivo mientras la venta esperaba conexión. La caja está libre: volvé a tomarla y la venta se sincroniza sola.',
            ];
        }

        return [
            'REGISTER_NEVER_HELD',
            'Este dispositivo todavía no tomó esta caja. La caja está libre: tomala y la venta se sincroniza sola.',
        ];
    }
}
