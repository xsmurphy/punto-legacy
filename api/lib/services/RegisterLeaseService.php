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
 *   - claim():          la transición LIBRE → TOMADA, y la confirmación de
 *     "sigo siendo el tenedor". Es la única que ESCRIBE tenencias nuevas, y
 *     desde 2026-09-01 solo lo hace con `$acquire = true` — confirmar dejó de
 *     implicar tomar (ver su docblock: mientras las dos cosas fueron una
 *     sola, el latido del POS se quedaba con toda caja que quedara libre).
 *   - holderConflict(): quién tiene la caja tomada AHORA, si no es
 *     `$deviceId` — usado tanto para decidir si un claim nuevo entra, como
 *     para que `sales.php`/`offline-sync.php` rechacen una venta de un
 *     device que ya no es el tenedor real.
 *   - close():          la transición TOMADA → {released|forced} → LIBRE, y
 *     el aviso realtime a todo el comercio (ver `publishReleased()`).
 *
 * `close()` sigue anulando (§6.1) los números `numbering_lease` no
 * consumidos que hayan quedado atados a esa tenencia — relevante solo para
 * filas HISTÓRICAS (de antes de este cambio, `registerLeaseId` seteado);
 * ya no hay escritores nuevos de `numbering_lease`, así que en tenencias
 * creadas después de este cambio ese UPDATE es un no-op esperado.
 *
 * Sobre transacciones y locks — dos grupos, y la diferencia importa:
 *
 *   - `claim()` y `releaseByDevice()` son SELF-CONTAINED: abren su propia
 *     transacción y toman ellos el `pg_advisory_xact_lock(hashtext(registerId))`
 *     que serializa el acceso a una caja. Tienen que hacerlo porque en los dos
 *     la secuencia "leer tenencia → decidir → escribir" es un solo acto
 *     atómico: partirla entre el caller y el servicio es justamente el hueco
 *     por el que se cuelan dos tenedores.
 *   - `close()` y `holderConflict()` asumen que el caller ya está dentro de esa
 *     transacción y ese lock (`register-lease.php`, `claim()`,
 *     `releaseByDevice()`), porque su resultado tiene que ser atómico con lo
 *     que el caller haga a continuación.
 *
 * `StartTrans()`/`CompleteTrans()` soportan anidamiento (`api/includes/lib/DB.php`),
 * así que el primer grupo se puede llamar desde adentro del segundo — el
 * commit/rollback real lo decide siempre el nivel más externo.
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
     * CONFIRMAR la tenencia de una caja y, solo si `$acquire`, TOMARLA.
     *
     * Es la política de exclusividad de caja del §4 completa, con su
     * transacción y su lock. Vive en el servicio y no en
     * `api/v1/register/claim.php` porque una regla que solo se puede ejercitar
     * levantando un endpoint HTTP no se puede testear — y esta decide si dos
     * dispositivos pueden facturar contra el mismo punto de expedición, que es
     * exactamente la clase de invariante que hay que poder probar contra
     * Postgres real (`api/tests/register_tenancy_offline_test.php`).
     *
     * `$acquire` es LA distinción que faltaba (owner, 2026-09-01):
     *
     *   - `true`  — si la caja está libre, la toma. Solo dos callers legítimos
     *               del lado del POS: el botón "Tomar caja" del cajero y el
     *               drenaje de la cola offline (una venta YA emitida).
     *   - `false` — pregunta y nada más. Es lo que usan el latido de 5 min, el
     *               evento `online`, el evento realtime y el montaje del
     *               workspace. Antes no existía: cada latido tomaba la caja de
     *               paso, así que un POS abierto se la volvía a llevar apenas
     *               otro dispositivo la soltaba, y quién facturaba lo decidía
     *               un timer en vez de una persona.
     *
     * NUNCA toca la tenencia de OTRO device, con `$acquire` o sin él — "el
     * último que llega pisa al anterior" fue RECHAZADO (§6).
     *
     * @return array{registerLeaseId:?string,created:bool,conflict:?array{holderDeviceId:?string,holderDeviceName:?string,expiresAt:?string,reason:string,releasedBy:?string,releasedAt:?string}}
     *         `conflict !== null` ⇒ este device NO es el tenedor (el `reason`
     *         dice si la caja está ocupada o simplemente libre).
     *         `registerLeaseId !== null` ⇒ lo es; `created` distingue "la
     *         acaba de tomar" de "ya la tenía".
     *         Los dos en null/false ⇒ falla transitoria de DB en el INSERT.
     */
    public static function claim(
        string $registerId,
        string $companyId,
        string $outletId,
        string $deviceId,
        bool $acquire,
    ): array {
        global $db;
        $db->StartTrans();

        // Lock exclusivo de sesión por caja. Dos requests para la misma caja
        // esperan acá (sea del mismo dispositivo o de dos distintos); distintas
        // cajas no bloquean entre sí (el lock key es por registerId).
        // `hashtext()` produce un int4 estable desde un string arbitrario — así
        // se deriva el lock key del UUID de la caja sin truncarlo a int.
        ncmExecute('SELECT pg_advisory_xact_lock(hashtext(?))', [$registerId]);

        // Tenencia activa de esta caja, si hay alguna. FOR UPDATE: nadie más
        // puede leer/tocar esta fila hasta que cerremos la transacción — el
        // advisory lock ya serializa por registerId, este FOR UPDATE es defensa
        // en profundidad (mismo registerId nunca tiene 2 filas active por el
        // constraint de mig 141, pero el lock de fila es gratis acá adentro).
        $activeLease = ncmExecute(
            'SELECT registerleaseid, deviceid
               FROM "register_lease"
              WHERE registerid = ? AND "status" = \'active\'
              FOR UPDATE',
            [$registerId]
        );
        $hasActive = $activeLease !== false && $activeLease !== 0;

        if ($hasActive && (string) $activeLease['deviceId'] === $deviceId) {
            // Ya es el tenedor: confirmar sin escribir nada.
            $db->CompleteTrans();
            return [
                'registerLeaseId' => (string) $activeLease['registerLeaseId'],
                'created'         => false,
                'conflict'        => null,
            ];
        }

        // Dos motivos para no darle la caja, y el `reason` los distingue:
        //   - la tiene OTRO device (`taken_by_other`) — prohibido quitársela;
        //   - está LIBRE pero este POST solo venía a preguntar
        //     (`released`/`revoked`/`never_held`) — la toma es un acto
        //     explícito del cajero.
        //
        // El detalle sale de `holderConflict()`, el mismo resolver que usan
        // `sales.php` y `offline-sync.php`; antes acá se armaba un shape a mano
        // con `reason` fijo en `taken_by_other`, porque cuando el claim siempre
        // tomaba la caja libre esa era la única causa posible. Corre dentro del
        // lock, así que lee el estado inmediatamente anterior a la respuesta.
        if ($hasActive || !$acquire) {
            $conflict = self::holderConflict($registerId, $companyId, $deviceId);
            $db->FailTrans();
            $db->CompleteTrans();
            return ['registerLeaseId' => null, 'created' => false, 'conflict' => $conflict];
        }

        // Defensa en profundidad (context/29 §4, "dispositivo cambia de caja" +
        // bug real 2026-08-20: tenencia colgada en la caja anterior de un device
        // reasignado). El camino correcto para liberar la caja VIEJA es
        // `active-register.php` (o cualquier otro que cambie `device.registerid`)
        // al momento del cambio — pero si algún camino se olvida, este device NO
        // puede terminar con tenencia activa en DOS cajas a la vez.
        // Autocorrección: antes de tomar ESTA caja (confirmado arriba que nadie
        // la tiene), cerrar cualquier OTRA tenencia activa de este MISMO
        // deviceId. Nunca toca la tenencia de OTRO device.
        self::releaseByDevice($deviceId, $companyId, 'device:claim-self-correct', 'released');

        // Nadie tiene la caja tomada — este dispositivo la toma ahora.
        // `expiresAt` queda NULL (mig 144): la tenencia ya no vence por fecha.
        $newLease = ncmExecute(
            'INSERT INTO "register_lease"
                (companyid, outletid, registerid, deviceid, "status")
             VALUES (?, ?, ?, ?, \'active\')
             RETURNING registerleaseid',
            [$companyId, $outletId, $registerId, $deviceId]
        );
        if ($newLease === false || $newLease === 0 || (string) ($newLease['registerLeaseId'] ?? '') === '') {
            // No debería pasar (INSERT ... RETURNING sobre una tabla sin
            // triggers), pero si el driver devuelve false por una falla
            // transitoria de DB, no dejar la caja sin tenedor real.
            $db->FailTrans();
            $db->CompleteTrans();
            return ['registerLeaseId' => null, 'created' => false, 'conflict' => null];
        }

        $registerLeaseId = (string) $newLease['registerLeaseId'];
        $db->CompleteTrans();

        // La caja pasó de LIBRE a TOMADA: el resto del comercio tiene que
        // enterarse, no solo cuando se libera. Sin esto, el otro POS que estaba
        // esperando esta caja se queda hasta 5 minutos creyendo que sigue libre
        // y ofreciendo un botón "Tomar caja" que ya no puede funcionar.
        //
        // Post-`CompleteTrans()`: el evento sale con la fila commiteada, así
        // que quien lo reciba y vuelva a preguntar lee el estado nuevo. (El
        // default de `realtimeAfterMutation()` no cubre `claim.php`: corre
        // dentro de `apiAuthTenant()` y ese endpoint autentica con
        // `apiAuthPosContext()`.)
        try {
            \realtimePublish('register-lease', 'create', $registerLeaseId, 'all', $companyId);
        } catch (\Throwable $e) {
            // Best-effort: una caída de Redis no puede tumbar una toma de caja
            // que ya está en la base.
        }

        return ['registerLeaseId' => $registerLeaseId, 'created' => true, 'conflict' => null];
    }

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

        // El `companyId` se lee ANTES del UPDATE por dos razones: el evento
        // realtime necesita saber a qué tenant publicar (no se puede depender
        // de `COMPANY_ID`: tres de los cuatro caminos que liberan corren en
        // realm `pos-app`), y `status` dice si esta llamada va a transicionar
        // algo de verdad o es el no-op de un segundo caller. Sin ese dato
        // publicaríamos un "se liberó la caja" también cuando no se liberó
        // nada.
        //
        // Sin filtro por tenant a propósito, y por eso mismo esta lectura NO
        // decide nada: solo saca de la fila el companyId al que hay que
        // avisarle. `close()` recibe un `registerLeaseId` que TODOS sus callers
        // ya validaron contra su propio tenant (`register-lease.php` con
        // `companyid = COMPANY_ID` en el WHERE, `releaseByDevice()` con el
        // companyId del device). Un caller futuro que pase un id sin validar
        // rompería ese contrato mucho antes de llegar acá — el UPDATE de abajo
        // tampoco filtra por tenant.
        $lease = ncmExecute(
            'SELECT companyid, "status" FROM "register_lease" WHERE registerleaseid = ?',
            [$registerLeaseId]
        );
        $wasActive = $lease !== false && $lease !== 0 && (string) $lease['status'] === 'active';
        $companyId = $wasActive ? (string) $lease['companyId'] : '';

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

        if ($wasActive && $companyId !== '') {
            self::publishReleased($registerLeaseId, $companyId);
        }
    }

    /**
     * Avisa al comercio que una caja quedó libre.
     *
     * POR QUÉ ACÁ Y NO EN CADA ENDPOINT (bug del owner, 2026-09-01)
     * ─────────────────────────────────────────────────────────────
     * Cuatro caminos liberan una tenencia y solo UNO avisaba: el "Liberar
     * caja" del panel (`/v1/register-lease`), y ni siquiera a propósito — lo
     * hacía por el DEFAULT de `realtimeAfterMutation()`, que deriva la entity
     * del path. Cerrar caja (`/v1/drawer`), revocar un dispositivo
     * (`/v1/devices` DELETE), despararlo (`/v1/auth/unpair-pos-device`) y
     * cambiarlo de caja (`/v1/active-register`) también liberan, pero publican
     * `drawer`/`device`/etc. El POS escucha `register-lease`
     * (`use-realtime-sync.ts`) y por esos cuatro caminos no se enteraba nunca:
     * el otro dispositivo se quedaba hasta 5 minutos —el latido— creyendo que
     * la caja seguía ocupada.
     *
     * `close()` es el choke point único por el que pasan los cuatro, así que
     * el aviso vive acá: un camino nuevo que libere una tenencia hereda el
     * evento sin que nadie tenga que acordarse.
     *
     * SOBRE EL MOMENTO. Esto corre DENTRO de la transacción del caller (todos
     * llaman a `close()` entre `StartTrans()` y `CompleteTrans()`), o sea
     * pre-commit. No es un problema para el consumidor que importa: el POS
     * reacciona llamando a `/v1/register/claim`, que toma
     * `pg_advisory_xact_lock(hashtext(registerId))` — el MISMO lock que el
     * caller sostiene mientras libera. La consulta del POS se serializa detrás
     * del commit por construcción. Y es estrictamente mejor que lo que había:
     * el default de `realtimeAfterMutation()` publica al ENTRAR la request,
     * antes de que el handler toque una fila.
     *
     * Best-effort: `realtimePublish()` no puede tumbar una liberación que ya
     * está escrita.
     */
    private static function publishReleased(string $registerLeaseId, string $companyId): void
    {
        try {
            \realtimePublish('register-lease', 'delete', $registerLeaseId, 'all', $companyId);
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }
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
