<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\Api\Support\TenantClock;

/**
 * Tenencia de caja (context/29-numeracion-y-exclusividad-de-caja.md, F2/F3).
 *
 * `register_lease` (mig 141) es la unidad de TENENCIA — separada de
 * `numbering_lease`, que sigue siendo "un número, una fila". El constraint
 * `UNIQUE ("registerId") WHERE status = 'active'` es la garantía real de
 * "una caja, un tenedor a la vez" (a nivel de BD, no de aplicación); este
 * servicio concentra las dos operaciones que la máquina de estados del plan
 * (§4) necesita alrededor de ese constraint:
 *
 *   - expiresAt(): cuándo vence una tenencia NUEVA que se está por crear.
 *   - close():     la transición TOMADA → {expired|released|forced} → LIBRE,
 *                  que además anula (§6.1) los números no consumidos
 *                  emitidos bajo esa tenencia.
 *
 * Todo método asume que el caller ya está DENTRO de la transacción +
 * `pg_advisory_xact_lock(hashtext(registerId))` que serializa el acceso a
 * una caja (mismo lock que `api/v1/numbering/lease.php` ya usaba antes de
 * F2, ahora ampliado para cubrir también la lectura/escritura de
 * `register_lease`). Este servicio NO abre transacciones ni toma locks —
 * eso es responsabilidad del caller, porque "chequear tenencia → decidir"
 * tiene que ser atómico con lo que el caller haga a continuación (servir un
 * bloque, crear uno nuevo, rechazar con 409).
 *
 * close() hoy tiene UN caller real: `lease.php`, que la usa para detectar
 * (lazily, al pedir la caja) que la tenencia activa ya venció (status
 * transition VENCIMIENTO del §4) y liberarla antes de dejar entrar a un
 * nuevo tenedor. Los otros dos motivos de cierre del §4 — liberación normal
 * del propio dispositivo y liberación forzada desde el panel (F4, "liberar
 * caja") — reusan la MISMA función con otro `$status`/`$releasedBy`/
 * `$voidReason` en cuanto exista un endpoint que la llame; F4 no es alcance
 * de esta tarea, así que `close('released', ...)` y `close('forced', ...)`
 * quedan implementados y sin caller todavía.
 */
final class RegisterLeaseService
{
    /**
     * `expiresAt` de una tenencia NUEVA de caja: fin de la fecha del outlet
     * en su timezone (context/29 §4.1), acotado por el vencimiento del
     * timbrado si hay uno cargado (§4.2 regla 1: `min(fin de fecha,
     * vencimiento del timbrado)`).
     *
     * Punto no modela timezone POR OUTLET — la única fuente real es
     * `company.config->>'settingTimeZone'` (`TenantClock`, un valor por
     * tenant). El plan habla de "fecha del outlet"; en el código de hoy eso
     * es la fecha del TENANT, porque no existe una columna de timezone por
     * sucursal. Un comercio con sucursales en husos horarios distintos no
     * está modelado — usar la TZ del tenant es la mejor aproximación
     * disponible sin inventar una columna nueva fuera de este alcance.
     *
     * ⚠ El chequeo de timbrado vencido (`RegisterService::invoiceAuthError()`)
     * ya cortó ANTES de llegar acá si el timbrado está vencido — este método
     * no repite ese corte, solo topea `expiresAt` si hay una fecha de
     * vencimiento FUTURA cargada. Verificado 2026-07-28: ningún register de
     * producción tiene timbrado cargado hoy (`registerInvoiceAuth`/
     * `registerInvoiceAuthExpiration` vacíos), así que en la práctica esto
     * hoy siempre devuelve el fin de fecha del tenant sin recorte.
     */
    public static function expiresAt(string $companyId, string $registerId): string
    {
        $tz        = new \DateTimeZone(TenantClock::timezone($companyId));
        $now       = new \DateTimeImmutable('now', $tz);
        $endOfDate = $now->setTime(23, 59, 59);

        // `data` es JSONB (mig 26) — Query::flattenJsonb la aplana y expone
        // sus claves a nivel de fila (mismo patrón que RegisterService::invoiceAuthError()).
        $row = ncmExecute(
            'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$registerId, $companyId]
        );
        $authExpiration = trim((string) ($row['registerInvoiceAuthExpiration'] ?? ''));

        if ($authExpiration !== '') {
            try {
                $authEndOfDate = new \DateTimeImmutable($authExpiration . ' 23:59:59', $tz);
                if ($authEndOfDate < $endOfDate) {
                    $endOfDate = $authEndOfDate;
                }
            } catch (\Throwable) {
                // Fecha de timbrado no parseable — no bloquear la toma de caja por un
                // dato corrupto, usar el fin de fecha normal.
            }
        }

        // ISO-8601 con offset explícito: la columna es TIMESTAMPTZ, y un string
        // naive dependería de la timezone de la SESIÓN de PG (no necesariamente
        // la del tenant) para interpretarse correctamente.
        return $endOfDate->format(\DateTimeInterface::ATOM);
    }

    /**
     * Cierra una tenencia activa: TOMADA → {expired|released|forced} → LIBRE
     * (§4 del plan), y anula (§6.1) los números arrendados bajo ella que
     * todavía no se consumieron. Es un no-op silencioso si `$registerLeaseId`
     * ya no está `active` (dos callers concurrentes cerrando la misma
     * tenencia no chocan, el segundo UPDATE simplemente afecta 0 filas).
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
     * Valida que un `invoiceNo` arrendado siga con tenencia válida para
     * `$deviceId` — mismo criterio que `api/v1/offline-sync.php` ya aplica
     * por venta al sincronizar (F3, context/29 §5.4), factorizado acá porque
     * `api/v1/sales.php` (camino ONLINE, F3 aplicado a ese camino) necesita
     * el MISMO chequeo antes de guardar la venta, no solo al sincronizar: un
     * número consumido online que nadie valida contra la tenencia real deja
     * la misma ventana de duplicado que el P0 de este plan cerraba para
     * offline. No se reimplementa inline en cada endpoint para no divergir
     * de a poco — un cambio al criterio (ej. tolerancia de reloj) se hace acá
     * una sola vez.
     *
     * Devuelve `null` si la tenencia es válida (el caller puede guardar la
     * venta y marcar `consumedAt`). Si NO es válida, devuelve el detalle del
     * tenedor REAL de la caja ahora mismo (mismo shape `{holderDeviceId,
     * holderDeviceName, expiresAt}` que `lease.php` ya usa en su 409) para
     * que el caller arme el mismo `apiConflict()` informativo — puede no
     * haber tenedor (caja libre, nadie la retomó todavía).
     *
     * @return array{holderDeviceId:?string,holderDeviceName:?string,expiresAt:?string}|null
     */
    public static function validateInvoiceNoTenancy(
        int $invoiceNo,
        string $registerId,
        string $companyId,
        string $deviceId,
    ): ?array {
        // Mismo WHERE que offline-sync.php: el número existe, no venció por
        // su propio TTL (24h del bloque), y no fue consumido todavía.
        $leaseRow = ncmExecute(
            'SELECT nl."registerLeaseId", nl."voidedAt",
                    rl."status" AS "registerLeaseStatus", rl."deviceId" AS "registerLeaseDeviceId"
               FROM "numbering_lease" nl
               LEFT JOIN "register_lease" rl ON rl."registerLeaseId" = nl."registerLeaseId"
              WHERE nl."invoiceNo" = ? AND nl."registerId" = ? AND nl."companyId" = ?
                AND nl."consumedAt" IS NULL AND nl."expiresAt" > NOW()
              LIMIT 1',
            [$invoiceNo, $registerId, $companyId]
        );

        $tenancyValid = false;
        if ($leaseRow !== false && $leaseRow !== 0) {
            $registerLeaseId = $leaseRow['registerLeaseId'] ?? null;
            $voidedAt        = $leaseRow['voidedAt'] ?? null;
            // registerLeaseId NULL (fila legacy sin tenencia asignada) se
            // trata igual que "sin tenencia válida" — mismo criterio
            // fail-closed que offline-sync.php usa (no inventar un tenedor).
            $tenancyValid = $registerLeaseId !== null && $registerLeaseId !== ''
                && ($voidedAt === null || $voidedAt === '')
                && (string) ($leaseRow['registerLeaseStatus'] ?? '') === 'active'
                && (string) ($leaseRow['registerLeaseDeviceId'] ?? '') === $deviceId;
        }

        if ($tenancyValid) {
            return null;
        }

        // No es válida — buscar quién tiene la caja tomada AHORA (puede ser
        // otro dispositivo, puede ser nadie) para devolver el mismo detail
        // informativo que `lease.php` ya arma en su propio 409.
        $activeLease = ncmExecute(
            'SELECT "deviceId", "expiresAt" FROM "register_lease"
              WHERE "registerId" = ? AND "status" = \'active\' LIMIT 1',
            [$registerId]
        );

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
            'expiresAt'        => (string) $activeLease['expiresAt'],
        ];
    }
}
