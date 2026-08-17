<?php
/**
 * 142_register_lease_backfill.php
 *
 * F1 de context/29 §6 — backfill de `register_lease` (creada en la mig 141)
 * para las cajas con un bloque de números vivo hoy (`consumedAt IS NULL AND
 * "expiresAt" > NOW()` en `numbering_lease`). Corre UNA vez, en el cutover:
 * después de esto, F2 (`lease.php`, fuera de esta migración) es quien
 * mantiene `register_lease` al día.
 *
 * ── Desvío del plan §6 punto 1, documentado a propósito ──────────────────
 * El plan pide atribuir la tenencia al "último dispositivo que consumió un
 * número de esa caja (MAX(leasedAt) o MAX(consumedAt) en
 * numbering_lease/transaction)". Verificado contra el schema real: NI
 * `numbering_lease` NI `transaction` tienen columna `deviceId` — el device
 * que llama a `offline-sync.php` nunca queda registrado contra el número que
 * consume (confirmado leyendo ese endpoint completo). No hay forma de saber,
 * por historial, qué dispositivo consumió qué número. Inventar una columna
 * de atribución retroactiva no es este alcance (F0/F1 no tocan
 * `offline-sync.php`) y el brief de esta tarea es explícito: si no hay forma
 * confiable de atribuir, no inventar — dejar la caja sin tenencia.
 *
 * Proxy usado en su lugar, con el mismo espíritu que §6 punto 2 (sin
 * ambigüedad → atribuir; con ambigüedad → anular):
 *   - Exactamente 1 dispositivo activo (`device.status = 1`) pareado a esa
 *     caja (`device.registerid`) → ese es el ÚNICO que pudo haber pedido o
 *     consumido ese bloque (hoy `lease.php` sirve a cualquier device de la
 *     caja, pero solo puede haber sido UNO si solo hay uno pareado). No es
 *     una adivinanza entre candidatos: es el único candidato posible. Se
 *     crea `register_lease` activa con ese `deviceId` y se backfillea
 *     `numbering_lease."registerLeaseId"` en las filas vivas de esa caja.
 *   - 0 dispositivos activos, o 2+ — no hay forma de atribuir sin adivinar
 *     (0: el que lo pidió ya no está pareado/activo; 2+: es exactamente el
 *     caso de los 4 dispositivos verificado en producción, §2 del plan). El
 *     bloque completo se anula (`voidedAt`, `voidReason = 'forced'`, §6
 *     punto 2) y la caja queda LIBRE — el primer request post-cutover la
 *     toma limpia. El caso "0 dispositivos" no está escrito explícitamente
 *     en el plan (que solo habla de ">1"), pero cae bajo el mismo principio:
 *     sin tenedor claro, no se inventa uno.
 *
 * `numbering_lease."registerLeaseId"` queda NULL en toda fila ya consumida
 * (`consumedAt IS NOT NULL`) — son historia, el plan §6 punto 3 es explícito
 * en que no importan para el invariante y no se tocan acá.
 *
 * `register_lease.expiresAt`/`takenAt` para las filas backfilleadas son una
 * aproximación, no el cálculo final de F2 (fin de la fecha del outlet,
 * §4.1 — requiere timezone del outlet, que es resorte del endpoint, fuera de
 * este script): `expiresAt` = el mayor `"expiresAt"` entre los bloques vivos
 * de esa caja (no le da más vida de la que ya tenía el bloque que ampara);
 * `takenAt` = el menor `"leasedAt"` de esos mismos bloques (proxy de "desde
 * cuándo viene sosteniendo números esta caja"). F2, al primer ciclo de
 * liberar/retomar esa caja, recalcula `expiresAt` con la regla real — este
 * valor es solo para que la fila de auditoría nazca coherente, no vive para
 * siempre.
 *
 * Usa PDO directo ($GLOBALS['migrationPdo'], patrón migs 56/102) — no
 * ncmExecute, evita el footgun de forceObj=true devolviendo un recordset en
 * vez de un array.
 *
 * Todo o nada (`beginTransaction`/`commit`): sin esto, un blip de conexión a
 * mitad del loop deja algunas cajas atribuidas y otras no, migrate.php NUNCA
 * marca el archivo como aplicado (solo lo hace si termina sin excepción), y
 * el redeploy automático re-corre el script desde cero — el segundo INSERT
 * para una caja ya atribuida choca contra `uq_register_lease_active` y tira
 * una excepción que bloquea el boot hasta limpiar a mano. Con la transacción,
 * un fallo a mitad de camino no deja rastro: rollback completo, el próximo
 * intento arranca limpio.
 */

$pdo = $GLOBALS['migrationPdo'] ?? null;
if (!$pdo) {
    fwrite(STDERR, "[migrate 142] migrationPdo no disponible\n");
    return;
}

try {
    $pdo->beginTransaction();

    // Cajas con AL MENOS un número vivo hoy (bloque no consumido, no vencido).
    $stmt = $pdo->query(
        'SELECT "registerId", "companyId", "outletId",
                MIN("leasedAt")  AS "firstLeasedAt",
                MAX("expiresAt") AS "lastExpiresAt"
           FROM "numbering_lease"
          WHERE "consumedAt" IS NULL AND "expiresAt" > NOW()
          GROUP BY "registerId", "companyId", "outletId"'
    );
    $registers = $stmt->fetchAll();

    $attributed        = 0;
    $voidedNoDevice     = 0;
    $voidedMultiDevice  = 0;

    $selectDevices = $pdo->prepare(
        'SELECT deviceid FROM device WHERE registerid = ?::uuid AND status = 1'
    );
    $insertLease = $pdo->prepare(
        'INSERT INTO "register_lease"
            ("registerLeaseId", "companyId", "outletId", "registerId", "deviceId",
             "status", "takenAt", "expiresAt")
         VALUES (gen_random_uuid(), ?, ?, ?, ?, \'active\', ?, ?)
         RETURNING "registerLeaseId"'
    );
    $backfillNumbering = $pdo->prepare(
        'UPDATE "numbering_lease" SET "registerLeaseId" = ?
          WHERE "registerId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW()'
    );
    $voidNumbering = $pdo->prepare(
        'UPDATE "numbering_lease" SET "voidedAt" = NOW(), "voidReason" = \'forced\'
          WHERE "registerId" = ? AND "consumedAt" IS NULL AND "expiresAt" > NOW()'
    );

    foreach ($registers as $row) {
        $registerId = $row['registerId'];
        $companyId  = $row['companyId'];
        $outletId   = $row['outletId'];

        $selectDevices->execute([$registerId]);
        $devices = $selectDevices->fetchAll();

        if (count($devices) === 1) {
            $deviceId = $devices[0]['deviceid'];

            $insertLease->execute([
                $companyId,
                $outletId,
                $registerId,
                $deviceId,
                $row['firstLeasedAt'],
                $row['lastExpiresAt'],
            ]);
            $leaseRow         = $insertLease->fetch();
            $registerLeaseId  = $leaseRow['registerLeaseId'];

            $backfillNumbering->execute([$registerLeaseId, $registerId]);
            $attributed++;
        } elseif (count($devices) === 0) {
            $voidNumbering->execute([$registerId]);
            $voidedNoDevice++;
        } else {
            $voidNumbering->execute([$registerId]);
            $voidedMultiDevice++;
        }
    }

    $pdo->commit();

    $totalVoided = $voidedNoDevice + $voidedMultiDevice;
    echo "[migrate 142] register_lease backfill: {$attributed} caja(s) con tenencia atribuida, "
       . "{$totalVoided} caja(s) anuladas sin tenedor claro "
       . "({$voidedMultiDevice} con >1 dispositivo activo, {$voidedNoDevice} con 0 dispositivos activos)\n";
} catch (Throwable $e) {
    // Fail-fast a propósito (P0 fiscal): no tragar el error y dejar el
    // deploy seguir con un backfill a medias. Rollback completo — un retry
    // post-falla arranca limpio, sin filas a medio insertar que choquen
    // contra uq_register_lease_active. migrate.php aborta el container si
    // esto escapa — mismo criterio que una PDOException.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[migrate 142] ERROR: " . $e->getMessage() . "\n");
    throw $e;
}
