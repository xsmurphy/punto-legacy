<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del GATE DE CIERRE DE TURNO (`api/lib/services/ShiftCloseGate.php`).
 *
 * La regla que cubre es opcional por comercio: con
 * `settingDrawerRequireClosedOrders` prendido, la caja no cierra el turno
 * mientras la SUCURSAL tenga órdenes sin cobrar o espacios abiertos.
 *
 * ── Lo que este arnés existe para probar ────────────────────────────────────
 *
 * El **par discriminante** (casos C1/C2). El owner acotó la regla el
 * 2026-08-25 —"tienen que haber un estado de cobrado para cada orden,
 * independiente al estado de la orden en su proceso"— y eso cambió el criterio
 * de las órdenes de "estado del proceso" a "cobro":
 *
 *   C1. orden `out_for_delivery` **CON** link `order_billed` → NO bloquea
 *   C2. la MISMA orden **SIN** ese link                       → SÍ bloquea
 *
 * Son dos filas idénticas salvo por la existencia del link. Un arnés que no
 * distinga ese par no prueba el cambio: daría verde tanto con el criterio
 * nuevo como con el viejo (que miraba `status NOT IN (terminales)` a secas y
 * bloqueaba las dos). Todo lo demás de acá es contorno de ese par.
 *
 * Los otros ejes, y por qué cada uno:
 *
 *   - **Terminales sin link** (D1/D2): `closed` y `cancelled` se excluyen
 *     ANTES del criterio de cobro, a propósito. Un `closed` sin link sería un
 *     bloqueo sin salida —`markPaid()` rechaza una orden ya cerrada, no hay
 *     pantalla desde la que cobrarla— y un gate del que no se puede salir es
 *     peor que uno que deja pasar una anomalía histórica. `cancelled` nunca se
 *     cobra por definición.
 *   - **Espacios por STATUS y no por cobro** (E1/E2/E3): una mesa `open` con
 *     todo cobrado igual bloquea. No es una omisión: `settleIfCovered()` cierra
 *     sola la mesa cubierta, así que "mesa cobrada y abierta" es un estado
 *     transitorio; y aplicarle el criterio de cobro volvería invisible la mesa
 *     recién abierta con saldo cero, que es justo el pendiente que el comercio
 *     quiere ver a la hora del arqueo.
 *   - **Corte por fecha** (F1/F2/F3): el gate juzga contra el momento REAL del
 *     cierre, no contra el presente. Sin eso, un cierre encolado sin red que
 *     sincroniza al otro día choca contra órdenes que abrió otra caja después
 *     de que ese turno terminó, y el 422 es terminal sobre un canal FIFO.
 *   - **El flag** (A1..A3, G1): nace apagado y se lee con allowlist explícita.
 *     El caso del string vacío es el que un `?? false` dejaría pasar como
 *     "presente pero falsy" según cómo se escribiera; acá tiene que ser false.
 *   - **Aislamiento por tenant** (H1/H2): lo de otra empresa u otra sucursal
 *     no entra en el conteo.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_shift_close_gate_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/shift_close_gate_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/services/ShiftCloseBlockedException.php';
require_once dirname(__DIR__) . '/lib/services/ShiftCloseGate.php';

use Punto\Api\Services\ShiftCloseBlockedException;
use Punto\Api\Services\ShiftCloseGate;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Tenant B del mismo seed — se usa solo para el aislamiento multi-tenant.
const OTHER_COMPANY_ID = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const OTHER_OUTLET_ID  = '6d3cab3a-c040-4428-8090-6790469de3bd';

const FLAG_KEY = 'settingDrawerRequireClosedOrders';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

// ── Fixtures ───────────────────────────────────────────────────────────────

/** uuid nuevo generado por PG (PHP no tiene generador propio en el core). */
function newUuid(): string
{
    $row = ncmExecute('SELECT gen_random_uuid()::text AS id', []);
    return (string) ($row['id'] ?? '');
}

/**
 * Reloj del arnés: la hora la da POSTGRES, no PHP.
 *
 * `data.php` deja la sesión de PG en la TZ del comercio (`TenantClock::apply()`)
 * y el gate compara un string naive 'Y-m-d H:i:s' contra `timestamptz` — es
 * decir, el corte se interpreta en ESA zona. Si los cutoffs salieran del reloj
 * de PHP (que corre en la TZ del proceso, típicamente UTC), el corte se
 * desplazaría las horas del offset y los casos F1/F2 darían verde o rojo por
 * el motivo equivocado.
 */
function nowShifted(string $interval): string
{
    $row = ncmExecute("SELECT to_char(now() + ?::interval, 'YYYY-MM-DD HH24:MI:SS') AS t", [$interval]);
    return (string) ($row['t'] ?? '');
}

/**
 * Crea una orden del tenant fixture.
 *
 * `created_at` se pasa explícito porque el corte por fecha del gate se apoya en
 * esa columna; el default `now()` no alcanza para armar el caso "creada DESPUÉS
 * del cierre".
 */
function mkOrder(
    string $status,
    string $companyId,
    string $outletId,
    ?string $spaceSessionId = null,
    ?string $createdAtSql = null,
    string $source = 'counter'
): string {
    $id = newUuid();
    ncmExecute(
        "INSERT INTO pos_order (orderid, companyid, outletid, status, source, spacesessionid, created_at)
         VALUES (?, ?, ?, ?, ?, ?, COALESCE(?::timestamptz, now()))",
        [$id, $companyId, $outletId, $status, $source, $spaceSessionId, $createdAtSql]
    );
    return $id;
}

/**
 * Marca la orden como COBRADA: crea la venta y el vínculo `order_billed`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * OJO CON LA FK — la mig 156 la movió de sitio.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `order_transaction_link.transactionid` YA NO referencia `transaction`: la mig
 * 156 (particionado de `transaction` por `transactiondate`) redirigió esa FK
 * —y las otras 19 entrantes— a `transaction_registry`, que es la tabla chica y
 * NO particionada que conserva la unicidad global del id. `transaction` pasó a
 * tener PK compuesta `(transactionid, transactiondate)`, y una PK compuesta no
 * puede ser destino de una FK de una sola columna.
 *
 * Consecuencia práctica para cualquier fixture: **insertar en `transaction` y
 * dejar que el trigger `trg_transaction_registry_sync_insert` cree la fila del
 * registry**. Insertar directo en `transaction_registry` "para saltear un
 * paso" produce una venta fantasma sin cuerpo; y armar el link contra un uuid
 * inventado explota con violación de FK, no con un mensaje que hable de
 * particiones. Se inserta la venta, y recién después el link.
 */
function payOrder(string $orderId, string $companyId, string $outletId, string $registerId, string $userId): string
{
    $txId = newUuid();
    ncmExecute(
        "INSERT INTO transaction
            (transactionId, transactionDate, transactionTotal, transactionDiscount,
             transactionType, transactionPaymentType, transactionComplete,
             registerId, outletId, companyId, userId, meta)
         VALUES (?, now(), 1000, 0, 0, ?, TRUE, ?, ?, ?, ?, '{}'::jsonb)",
        [
            $txId,
            json_encode([['type' => 'cash', 'name' => 'Efectivo', 'price' => 1000, 'total' => 1000]]),
            $registerId, $outletId, $companyId, $userId,
        ]
    );

    // Sanity del fixture: si el trigger del registry no corrió, el INSERT de
    // abajo fallaría por FK y el motivo real quedaría enterrado.
    $reg = ncmExecute('SELECT transactionid FROM transaction_registry WHERE transactionid = ?', [$txId]);
    if (!$reg || empty($reg['transactionid'])) {
        throw new RuntimeException(
            "el trigger de transaction_registry no creó la fila para $txId — " .
            'revisá que la mig 156 esté aplicada antes de correr este arnés'
        );
    }

    ncmExecute(
        "INSERT INTO order_transaction_link (companyid, orderid, transactionid, kind)
         VALUES (?, ?, ?, 'order_billed')",
        [$companyId, $orderId, $txId]
    );
    return $txId;
}

function mkSpace(string $name, string $sectorId, string $companyId, string $outletId): string
{
    $id = newUuid();
    ncmExecute(
        "INSERT INTO space (tableid, companyid, outletid, sectorid, name, seats, shape, status)
         VALUES (?, ?, ?, ?, ?, 4, 'square', 1)",
        [$id, $companyId, $outletId, $sectorId, $name]
    );
    return $id;
}

function mkSession(
    string $spaceId,
    string $status,
    string $companyId,
    string $outletId,
    ?string $openedAtSql = null
): string {
    $id = newUuid();
    ncmExecute(
        "INSERT INTO space_session (sessionid, companyid, outletid, tableid, status, opened_at)
         VALUES (?, ?, ?, ?, ?, COALESCE(?::timestamptz, now()))",
        [$id, $companyId, $outletId, $spaceId, $status, $openedAtSql]
    );
    return $id;
}

/** Prende/apaga el interruptor, o lo BORRA (null) para el caso "clave ausente". */
function setFlag(?string $raw, string $companyId): void
{
    if ($raw === null) {
        ncmExecute("UPDATE company SET config = config - ? WHERE companyId = ?", [FLAG_KEY, $companyId]);
        return;
    }
    ncmExecute(
        "UPDATE company SET config = jsonb_set(COALESCE(config, '{}'::jsonb), ?::text[], to_jsonb(?::text))
          WHERE companyId = ?",
        ['{' . FLAG_KEY . '}', $raw, $companyId]
    );
}

// ── Estado a limpiar ───────────────────────────────────────────────────────
$sectorId    = '';
$spaceIds    = [];
$orderIds    = [];
$txIds       = [];
$configBackup = null;

try {
    $row = ncmExecute('SELECT config::text AS c FROM company WHERE companyId = ?', [$companyId]);
    $configBackup = (string) ($row['c'] ?? '{}');

    // ═══════════════════════════════════════════════════════════════════════
    // (A) El interruptor: nace apagado y se lee con allowlist explícita
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (A) el flag ===\n";

    setFlag(null, $companyId);
    check('(A1) sin la clave en config, el gate está APAGADO',
        ShiftCloseGate::isEnabled($companyId) === false,
        'isEnabled() devolvió true con la clave ausente — la regla no se inventa sola',
        $failures, $checks);

    $verdaderos = ['1', 't', 'true', 'yes', 'on', 'TRUE', 'On'];
    $okTrue = true; $maloTrue = '';
    foreach ($verdaderos as $v) {
        setFlag($v, $companyId);
        if (ShiftCloseGate::isEnabled($companyId) !== true) { $okTrue = false; $maloTrue = $v; break; }
    }
    check('(A2) la allowlist prende con 1/t/true/yes/on (case-insensitive)',
        $okTrue, "el valor '$maloTrue' NO prendió el gate", $failures, $checks);

    // El string VACÍO es el caso que justifica la allowlist: `config->>'k'`
    // devuelve '' cuando la clave está pero sin valor útil, y un `?? false` no
    // lo cubre porque '' no es null. Tiene que leerse como apagado.
    $falsos = ['', '0', 'f', 'false', 'no', 'off', 'null', 'sí', '2'];
    $okFalse = true; $maloFalse = '';
    foreach ($falsos as $v) {
        setFlag($v, $companyId);
        if (ShiftCloseGate::isEnabled($companyId) !== false) { $okFalse = false; $maloFalse = $v; break; }
    }
    check('(A3) todo lo demás (incl. string vacío) deja el gate apagado',
        $okFalse, "el valor '$maloFalse' prendió el gate y no debería", $failures, $checks);

    check('(A4) company inexistente/vacía no prende nada',
        ShiftCloseGate::isEnabled('') === false,
        'companyId vacío devolvió true', $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (B) Línea de base: la sucursal fixture arranca limpia
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (B) línea de base ===\n";

    setFlag('1', $companyId);
    $base = ShiftCloseGate::blockers($companyId, $outletId);
    check('(B1) el outlet fixture arranca sin bloqueantes',
        $base['total'] === 0,
        'arrancó con ' . json_encode($base) . ' — los deltas de abajo siguen siendo válidos, '
        . 'pero las aserciones sobre el DETALLE pueden verse afectadas por el tope de 25',
        $failures, $checks);

    check('(B2) sin sucursal, blockers() devuelve la foto vacía',
        ShiftCloseGate::blockers($companyId, '')['total'] === 0,
        'outletId vacío devolvió bloqueantes', $failures, $checks);

    /** Ids de órdenes que el gate reporta como bloqueantes ahora mismo. */
    $blockingOrderIds = static function (?string $cutoff = null) use ($companyId, $outletId): array {
        $b = ShiftCloseGate::blockers($companyId, $outletId, $cutoff);
        return array_map(static fn(array $o): string => $o['id'], $b['orders']);
    };

    // ═══════════════════════════════════════════════════════════════════════
    // (C) EL PAR DISCRIMINANTE — cobro sí, estado del proceso no
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (C) par discriminante: out_for_delivery cobrada vs sin cobrar ===\n";

    // Dos órdenes IDÉNTICAS: mismo status, mismo source, misma sucursal, mismo
    // instante. La única diferencia es la fila de order_transaction_link.
    $delivPagada = mkOrder('out_for_delivery', $companyId, $outletId, null, null, 'counter');
    $delivImpaga = mkOrder('out_for_delivery', $companyId, $outletId, null, null, 'counter');
    $orderIds[]  = $delivPagada;
    $orderIds[]  = $delivImpaga;
    $txIds[]     = payOrder($delivPagada, $companyId, $outletId, $registerId, $userId);

    $blk  = ShiftCloseGate::blockers($companyId, $outletId);
    $ids  = array_map(static fn(array $o): string => $o['id'], $blk['orders']);

    check('(C1) la orden out_for_delivery COBRADA no bloquea el cierre',
        !in_array($delivPagada, $ids, true),
        'el delivery ya cobrado apareció como bloqueante: ' . json_encode($blk['orders']),
        $failures, $checks);

    check('(C2) la MISMA orden SIN cobrar sí bloquea',
        in_array($delivImpaga, $ids, true),
        'el delivery sin cobrar NO apareció como bloqueante: ' . json_encode($blk['orders']),
        $failures, $checks);

    // El par tiene que discriminar DENTRO de la misma foto: si el conteo fuera
    // 2 o 0, alguna de las dos aserciones de arriba estaría pasando por un
    // motivo global (gate apagado, tenant equivocado) y no por el link.
    check('(C3) el par discrimina: exactamente 1 de las 2 cuenta',
        $blk['orderCount'] === $base['orderCount'] + 1,
        "esperaba orderCount = {$base['orderCount']}+1, vino {$blk['orderCount']}",
        $failures, $checks);

    // Y el criterio es el `kind`, no "existe cualquier fila del puente". Hoy el
    // CHECK de la mig 115 solo admite 'order_billed', así que un kind distinto
    // ni siquiera se puede insertar — se deja asentado para que el día que se
    // sume otro tipo de vínculo (una NC, por decir) esta aserción falle y
    // obligue a revisar el filtro del gate en vez de que un link que NO es un
    // cobro haga pasar por cobrada a una orden que no lo está.
    $kindsAdmitidos = ncmExecute(
        "SELECT pg_get_constraintdef(oid) AS def
           FROM pg_constraint
          WHERE conrelid = 'order_transaction_link'::regclass AND contype = 'c'
          LIMIT 1",
        []
    );
    $def = (string) ($kindsAdmitidos['def'] ?? '');
    check("(C4) hoy 'order_billed' es el único kind del puente",
        $def !== '' && substr_count($def, "'") === 2 && str_contains($def, "order_billed"),
        "el CHECK de kind cambió ($def) — revisá que ShiftCloseGate siga filtrando "
        . 'por order_billed y no por "existe cualquier link"',
        $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (D) Terminales sin link: excluidos ANTES del criterio de cobro
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (D) closed / cancelled sin link ===\n";

    $cerradaSinLink = mkOrder('closed', $companyId, $outletId);
    $orderIds[]     = $cerradaSinLink;
    $ids = $blockingOrderIds();

    // Por qué esto NO es un agujero: `closed` implica cobro (`updateStatus()`
    // lo exige, `markPaid()` lo escribe), así que una `closed` SIN link es una
    // anomalía histórica —una fila vieja, un backfill de la mig 115 sobre datos
    // sucios—. Si bloqueara, el cajero quedaría trabado sin salida: `markPaid()`
    // rechaza una orden ya cerrada, o sea que no hay pantalla del POS desde la
    // cual cobrarla. Un gate del que no se puede salir es peor que un gate que
    // deja pasar una anomalía.
    check('(D1) orden closed SIN link no bloquea (si no, sería un bloqueo sin salida)',
        !in_array($cerradaSinLink, $ids, true),
        'la orden closed sin link apareció como bloqueante — el cajero no tendría cómo destrabarse',
        $failures, $checks);

    $cancelada  = mkOrder('cancelled', $companyId, $outletId);
    $orderIds[] = $cancelada;
    check('(D2) orden cancelled sin cobrar no bloquea',
        !in_array($cancelada, $blockingOrderIds(), true),
        'la orden cancelada apareció como bloqueante', $failures, $checks);

    // Control positivo del eje: una orden viva y sin cobrar sí tiene que estar.
    $abierta    = mkOrder('open', $companyId, $outletId);
    $orderIds[] = $abierta;
    check('(D3) control: orden open sin cobrar sí bloquea',
        in_array($abierta, $blockingOrderIds(), true),
        'la orden abierta sin cobrar NO apareció — el gate no está mirando nada',
        $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (E) Espacios: el criterio sigue siendo el STATUS, no el cobro
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (E) espacios ===\n";

    $sectorId = newUuid();
    ncmExecute(
        "INSERT INTO space_sector (sectorid, companyid, outletid, name, status)
         VALUES (?, ?, ?, 'Sector shift-close-gate', 1)",
        [$sectorId, $companyId, $outletId]
    );
    $spaceOpen   = mkSpace('SCG-OPEN',   $sectorId, $companyId, $outletId);
    $spaceBill   = mkSpace('SCG-BILL',   $sectorId, $companyId, $outletId);
    $spaceClosed = mkSpace('SCG-CLOSED', $sectorId, $companyId, $outletId);
    $spaceIds    = [$spaceOpen, $spaceBill, $spaceClosed];

    // Mesa `open` con el consumo COBRADO ENTERO. Bajo el criterio de las
    // órdenes pasaría; bajo el de los espacios bloquea, y así tiene que ser:
    // una mesa cubierta de verdad ya la cerró `settleIfCovered()` sola, y leer
    // "no debe plata" volvería invisible la mesa recién abierta con saldo cero
    // —justo el pendiente que el comercio quiere ver en el arqueo—.
    $sesOpen = mkSession($spaceOpen, 'open', $companyId, $outletId);
    $txMesa  = newUuid();
    ncmExecute(
        "INSERT INTO transaction
            (transactionId, transactionDate, transactionTotal, transactionDiscount,
             transactionType, transactionPaymentType, transactionComplete,
             registerId, outletId, companyId, userId, meta)
         VALUES (?, now(), 5000, 0, 0, ?, TRUE, ?, ?, ?, ?, '{}'::jsonb)",
        [
            $txMesa,
            json_encode([['type' => 'cash', 'name' => 'Efectivo', 'price' => 5000, 'total' => 5000]]),
            $registerId, $outletId, $companyId, $userId,
        ]
    );
    $txIds[] = $txMesa;
    ncmExecute(
        "INSERT INTO space_session_payment (paymentid, companyid, outletid, sessionid, transactionid, amount, kind)
         VALUES (?, ?, ?, ?, ?, 5000, 'amount')",
        [newUuid(), $companyId, $outletId, $sesOpen, $txMesa]
    );

    $sesBill   = mkSession($spaceBill,   'bill_requested', $companyId, $outletId);
    $sesClosed = mkSession($spaceClosed, 'closed',         $companyId, $outletId);

    $blk       = ShiftCloseGate::blockers($companyId, $outletId);
    $spaceIdsB = array_map(static fn(array $s): string => $s['id'], $blk['spaces']);

    check('(E1) espacio open con TODO cobrado igual bloquea (criterio por status)',
        in_array($sesOpen, $spaceIdsB, true),
        'la mesa abierta y cobrada no apareció: ' . json_encode($blk['spaces']),
        $failures, $checks);

    check('(E2) espacio bill_requested bloquea',
        in_array($sesBill, $spaceIdsB, true),
        'la mesa con la cuenta pedida no apareció: ' . json_encode($blk['spaces']),
        $failures, $checks);

    check('(E3) espacio closed no bloquea',
        !in_array($sesClosed, $spaceIdsB, true),
        'la mesa cerrada apareció como bloqueante', $failures, $checks);

    check('(E4) el detalle del espacio trae el nombre legible, no el uuid',
        (static function (array $spaces, string $id): bool {
            foreach ($spaces as $s) {
                if ($s['id'] === $id) return $s['name'] === 'SCG-OPEN';
            }
            return false;
        })($blk['spaces'], $sesOpen),
        'esperaba name = SCG-OPEN: ' . json_encode($blk['spaces']),
        $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (F) Corte por fecha: se juzga contra el momento REAL del cierre
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (F) corte por fecha ===\n";

    $haceUnaHora  = nowShifted('-1 hour');
    $enUnaHora    = nowShifted('+1 hour');

    // Todo lo de arriba se creó "ahora". Con el corte una hora ATRÁS, nada de
    // eso existía todavía a los ojos del gate.
    $conCorteViejo = ShiftCloseGate::blockers($companyId, $outletId, $haceUnaHora);
    check('(F1) una orden creada DESPUÉS del cierre no bloquea ese cierre',
        !in_array($abierta, array_map(static fn(array $o): string => $o['id'], $conCorteViejo['orders']), true),
        'la orden posterior al corte apareció: ' . json_encode($conCorteViejo['orders']),
        $failures, $checks);

    check('(F2) el corte también aplica a los espacios (opened_at)',
        !in_array($sesOpen, array_map(static fn(array $s): string => $s['id'], $conCorteViejo['spaces']), true),
        'la mesa posterior al corte apareció: ' . json_encode($conCorteViejo['spaces']),
        $failures, $checks);

    $conCorteFuturo = ShiftCloseGate::blockers($companyId, $outletId, $enUnaHora);
    check('(F3) con el corte adelante, lo pendiente vuelve a bloquear',
        in_array($abierta, array_map(static fn(array $o): string => $o['id'], $conCorteFuturo['orders']), true),
        'la orden pendiente no apareció con el corte a futuro', $failures, $checks);

    // Basura en `date` (el campo viaja en el body del POST): se descarta el
    // corte y se juzga contra el PRESENTE. Es el comportamiento más estricto —
    // nunca uno que deje pasar un cierre por mandar cualquier cosa.
    $conBasura = ShiftCloseGate::blockers($companyId, $outletId, 'no-es-una-fecha');
    check('(F4) un date no parseable se ignora y el gate juzga contra el presente',
        $conBasura['total'] === ShiftCloseGate::blockers($companyId, $outletId)['total'],
        'con date basura el resultado difirió del sin-corte: ' . json_encode($conBasura),
        $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (G) assertCanClose(): el flag manda
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (G) assertCanClose ===\n";

    $vivo = ShiftCloseGate::blockers($companyId, $outletId);

    setFlag('0', $companyId);
    $paso = true; $detalle = '';
    try {
        ShiftCloseGate::assertCanClose($companyId, $outletId);
    } catch (ShiftCloseBlockedException $e) {
        $paso = false; $detalle = $e->getMessage();
    }
    check('(G1) con el flag APAGADO no bloquea nada, aunque haya pendientes',
        $paso && $vivo['total'] > 0,
        $paso ? 'no había pendientes: el caso no probó nada' : "lanzó igual: $detalle",
        $failures, $checks);

    setFlag('1', $companyId);
    $lanzo = false; $payload = [];
    try {
        ShiftCloseGate::assertCanClose($companyId, $outletId);
    } catch (ShiftCloseBlockedException $e) {
        $lanzo = true; $payload = $e->blockers();
    }
    check('(G2) con el flag prendido y pendientes, lanza ShiftCloseBlockedException',
        $lanzo, 'no lanzó pese a ' . json_encode($vivo), $failures, $checks);

    check('(G3) la excepción transporta el MISMO payload que el GET del POS',
        $lanzo && ($payload['total'] ?? -1) === $vivo['total']
              && ($payload['orderCount'] ?? -1) === $vivo['orderCount']
              && ($payload['spaceCount'] ?? -1) === $vivo['spaceCount'],
        'payload de la excepción: ' . json_encode($payload) . ' vs blockers(): ' . json_encode($vivo),
        $failures, $checks);

    // El mensaje tiene que decir QUÉ falta, no solo que no se puede.
    $msg1 = ShiftCloseGate::message(['orderCount' => 1, 'spaceCount' => 0]);
    $msgN = ShiftCloseGate::message(['orderCount' => 3, 'spaceCount' => 2]);
    check('(G4) el mensaje enumera órdenes y espacios, en singular y plural',
        str_contains($msg1, '1 orden sin cobrar') && !str_contains($msg1, 'espacio')
        && str_contains($msgN, '3 órdenes sin cobrar') && str_contains($msgN, '2 espacios abiertos'),
        "singular: $msg1 | plural: $msgN", $failures, $checks);

    // ═══════════════════════════════════════════════════════════════════════
    // (H) Aislamiento: otro tenant y otra sucursal no entran en el conteo
    // ═══════════════════════════════════════════════════════════════════════
    echo "\n=== (H) aislamiento ===\n";

    $ajena      = mkOrder('open', OTHER_COMPANY_ID, OTHER_OUTLET_ID);
    $orderIds[] = $ajena;

    check('(H1) una orden abierta de OTRA empresa no bloquea a esta',
        !in_array($ajena, $blockingOrderIds(), true),
        'la orden de otro tenant apareció en los bloqueantes', $failures, $checks);

    check('(H2) el gate del otro tenant sí la ve (el aislamiento no es "no ve nada")',
        in_array(
            $ajena,
            array_map(
                static fn(array $o): string => $o['id'],
                ShiftCloseGate::blockers(OTHER_COMPANY_ID, OTHER_OUTLET_ID)['orders']
            ),
            true
        ),
        'la orden no aparece ni siquiera en su propio tenant — el fixture no probó nada',
        $failures, $checks);
} finally {
    // ── Limpieza ───────────────────────────────────────────────────────────
    // Solo lo que creó este arnés, y en orden de dependencia: el link antes que
    // la venta (FK al registry), las sesiones antes que los espacios, los
    // espacios antes que el sector.
    foreach ($orderIds as $oid) {
        if ($oid === '') continue;
        ncmExecute('DELETE FROM order_transaction_link WHERE orderid = ?', [$oid]);
        ncmExecute('DELETE FROM pos_order WHERE orderid = ?', [$oid]);
    }
    foreach ($spaceIds as $sid) {
        if ($sid === '') continue;
        ncmExecute(
            'DELETE FROM space_session_payment WHERE sessionid IN (SELECT sessionid FROM space_session WHERE tableid = ?)',
            [$sid]
        );
        ncmExecute('DELETE FROM space_session WHERE tableid = ?', [$sid]);
        ncmExecute('DELETE FROM space WHERE tableid = ?', [$sid]);
    }
    if ($sectorId !== '') {
        ncmExecute('DELETE FROM space_sector WHERE sectorid = ?', [$sectorId]);
    }
    // Borrar `transaction` cascadea su fila de transaction_registry (la FK de
    // vuelta de la mig 156 es ON DELETE CASCADE); no hay que borrar el registry
    // a mano, y hacerlo primero fallaría.
    foreach ($txIds as $tid) {
        if ($tid === '') continue;
        ncmExecute('DELETE FROM transaction WHERE transactionId = ?', [$tid]);
    }
    if ($configBackup !== null) {
        ncmExecute('UPDATE company SET config = ?::jsonb WHERE companyId = ?', [$configBackup, $companyId]);
    }
}

harnessFinish($failures, $checks);
