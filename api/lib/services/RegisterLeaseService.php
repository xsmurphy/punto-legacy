<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * Tenencia de caja (context/29-numeracion-y-exclusividad-de-caja.md §4).
 *
 * `register_lease` (mig 141) es la unidad de TENENCIA: garantiza "una caja,
 * un dispositivo a la vez" con `UNIQUE ("registerId") WHERE status =
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
 * Se libera SOLO al cerrar la caja o por revocación explícita de admin
 * (panel, "Liberar caja", F4 — no implementado todavía, `close('forced',
 * ...)` queda listo y sin caller hasta que exista ese endpoint).
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
     * con su valor original, ver `WHERE ... "voidedAt" IS NULL` abajo.
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
                SET "status" = ?, "releasedAt" = NOW(), "releasedBy" = ?
              WHERE "registerLeaseId" = ? AND "status" = \'active\'',
            [$status, $releasedBy, $registerLeaseId]
        );

        ncmExecute(
            'UPDATE "numbering_lease"
                SET "voidedAt" = NOW(), "voidReason" = ?
              WHERE "registerLeaseId" = ? AND "consumedAt" IS NULL AND "voidedAt" IS NULL',
            [$voidReason, $registerLeaseId]
        );
    }

    /**
     * Libera la tenencia activa de `$deviceId`, si tiene alguna — self-contained:
     * abre su propia transacción + advisory lock (a diferencia de `close()`, los
     * callers de este método NO están ya dentro de un lock por registerId, porque
     * no lo conocen de antemano: lo resuelven acá mismo a partir del device).
     *
     * Tres callers, los tres son "este device deja de operar, de una forma que
     * no depende de que el propio device siga vivo para liberar su caja"
     * (context/29 §4 — huecos que dejaban una tenencia huérfana para siempre,
     * detectados 2026-08-19):
     *   - `api/v1/devices.php` (DELETE, admin revoca desde el panel)
     *   - `api/v1/auth/unpair-pos-device.php` (el device se despareja a sí mismo,
     *     "Eliminar dispositivo del comercio" en Ajustes del POS)
     *   - `RegisterLeaseService` en general — un device revocado o despareado no
     *     puede volver a `claim.php` para liberarse solo: si nadie más libera su
     *     tenencia, queda tomada para siempre (la tenencia ya no vence por fecha).
     *
     * No-op silencioso si `$deviceId` no tiene tenencia activa — la inmensa
     * mayoría de los revokes/unpairs son de devices que nunca tomaron una caja,
     * o que ya la habían liberado (cerrar caja normal antes de desconectarse).
     */
    public static function releaseByDevice(
        string $deviceId,
        string $companyId,
        string $releasedBy,
        string $status = 'forced',
    ): void {
        $lease = ncmExecute(
            'SELECT "registerLeaseId", "registerId" FROM "register_lease"
              WHERE "deviceId" = ? AND "companyId" = ? AND "status" = \'active\' LIMIT 1',
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
            'SELECT "status" FROM "register_lease" WHERE "registerLeaseId" = ?::uuid FOR UPDATE',
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
     * holderDeviceName, expiresAt}` — `expiresAt` siempre `null` desde que
     * la tenencia dejó de vencer por fecha, se mantiene en el shape por
     * compatibilidad con el 409 que el POS ya sabe leer) — puede no haber
     * tenedor (`holderDeviceId: null`, caja libre).
     *
     * @return array{holderDeviceId:?string,holderDeviceName:?string,expiresAt:?string}|null
     */
    public static function holderConflict(
        string $registerId,
        string $companyId,
        string $deviceId,
    ): ?array {
        $activeLease = ncmExecute(
            'SELECT "deviceId" FROM "register_lease"
              WHERE "registerId" = ? AND "status" = \'active\' LIMIT 1',
            [$registerId]
        );

        if ($activeLease !== false && $activeLease !== 0 && (string) $activeLease['deviceId'] === $deviceId) {
            return null;
        }

        if ($activeLease === false || $activeLease === 0) {
            return ['holderDeviceId' => null, 'holderDeviceName' => null, 'expiresAt' => null];
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
        ];
    }
}
