<?php
/**
 * /api/v1/drawer.php — operaciones de caja/drawer del POS (Slice 26).
 *
 *   GET ?resource=check       → { isOpen: bool }  — ¿cajón abierto?
 *   GET ?resource=hourlyStats → { timezone, shift[], today[], yesterday[] } — ventas por hora
 *   GET ?resource=blockers    → { enabled, total, orders[], spaces[] } — qué impide cerrar
 *   GET                       → resumen completo — list, date, subtotal, total, tips, returns
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/DrawerService.php';
require_once __DIR__ . '/../lib/services/ShiftCloseBlockedException.php';
require_once __DIR__ . '/../lib/services/ShiftCloseGate.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\DrawerService;
use Punto\Api\Services\ShiftCloseBlockedException;
use Punto\Api\Services\ShiftCloseGate;
use Punto\Api\Support\TenantClock;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
// Defensa-en-profundidad: una pantalla cliente no debe poder operar el cajón.
if (($ctx['realm'] ?? '') === 'pos-app' && ($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

$svc      = new DrawerService(TenantContext::fromAuth($ctx));
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

/**
 * ¿Esta caja arquea A CIEGAS? (`register.data->>'registerBlindControl'`).
 *
 * Hasta ahora el ocultamiento vivía ENTERAMENTE en el frontend
 * (`context/modules/14-caja.md` regla 7): el backend devolvía los montos
 * reales y cualquiera con las devtools abiertas veía el resumen del turno que
 * el dueño había decidido esconderle a ese cajero. El control a ciegas dejaba
 * de serlo con dos clics.
 *
 * El filtro vive acá, en el servidor, porque una regla que solo se aplica en
 * el cliente no es una regla: es una sugerencia. Fail-CLOSED — si la caja no
 * se puede resolver, se asume a ciegas: el costo de equivocarse para este lado
 * es que el cajero no ve un número; para el otro, romper una decisión del
 * dueño.
 */
function drawerIsBlind(string $registerId, string $companyId): bool
{
    if ($registerId === '') {
        return true;
    }
    $row = ncmExecute(
        "SELECT COALESCE(data->>'registerBlindControl', 'false') AS blindcontrol
           FROM register WHERE registerId = ? AND companyId = ? LIMIT 1",
        [$registerId, $companyId],
        false
    );
    if (!$row) {
        return true;
    }
    return ($row['blindcontrol'] ?? 'false') === 'true';
}

/**
 * El resumen del turno, sin los acumulados, para una caja a ciegas.
 *
 * Lo que SOBREVIVE es la lista de medios de pago (sin sus montos): el cajero a
 * ciegas tiene que saber QUÉ contar — si no, no puede arquear y el modo pierde
 * sentido. Lo que el dueño decidió ocultar son los números, no la existencia
 * de las ventas con tarjeta.
 */
function drawerBlindSummary(array $data): array
{
    $methods = [];
    foreach ($data['expectedByMethod'] ?? [] as $m) {
        $methods[] = [
            'key'    => (string) ($m['key'] ?? ''),
            'name'   => (string) ($m['name'] ?? ''),
            'code'   => (string) ($m['code'] ?? ''),
            'isCash' => (bool) ($m['isCash'] ?? false),
            // Sin `expected`: es exactamente el número que no se muestra.
        ];
    }
    return [
        'blind'            => true,
        'date'             => $data['date'] ?? null,
        'expectedByMethod' => $methods,
        // Vacíos y no ausentes: el cliente distingue "caja abierta sin datos
        // que mostrar" de "caja cerrada" por la presencia de `list`.
        'list'             => [],
        'paymentBreakdown' => [],
        'soldProducts'     => [],
    ];
}

// --- GET ?resource=check: ¿cajón abierto? ---------------------------------
if ($method === 'GET' && $resource === 'check') {
    apiOk(['isOpen' => $svc->isOpen($registerId, $outletId, $companyId)]);
}

// --- Recursos GRANULARES del cierre de caja (patrón BFF-compone) -----------
// `open` da la fila del drawer abierto; los demás filtran por `since` (la fecha
// de apertura). registerId/outletId/companyId SIEMPRE salen del JWT (scoping de
// tenant garantizado); `since` es un parámetro de cliente → estos recursos son
// "extracciones/ingresos/ventas DESDE una fecha" (reusables por reportes). El
// rollup CONFIABLE del cierre es el que compone el BFF derivando `since` de
// `open` (no un granular suelto con un `since` arbitrario).
if ($method === 'GET' && $resource === 'open') {
    $open = $svc->getOpen($registerId, $outletId, $companyId);
    if ($open === null) {
        apiOk(['closed' => true]);
    }
    apiOk($open);
}
// `hourlyStats` es el único granular que NO exige `since` del cliente: describe
// "el turno EN CURSO + hoy y ayer calendario", así que la ventana del turno se
// deriva del drawer abierto (fuente única de verdad, igual que getSummary). Se
// sigue aceptando un `since` explícito para consultas ad-hoc de reportes (solo
// mueve la serie `shift`; hoy/ayer siempre salen del reloj del tenant).
if ($method === 'GET' && $resource === 'hourlyStats') {
    $openForHourly = $svc->getOpen($registerId, $outletId, $companyId);
    $since = trim((string) ($_GET['since'] ?? ''));
    if ($since === '') {
        $since = (string) ($openForHourly['drawerOpenDate'] ?? '');
    }
    if ($since === '') {
        apiOk(['closed' => true, 'shift' => [], 'today' => [], 'yesterday' => []]);
    }
    apiOk($svc->getHourlyStats($registerId, $since, $openForHourly['drawerId'] ?? null));
}
if ($method === 'GET' && in_array($resource, ['expenses', 'income', 'salesByPayment'], true)) {
    $since = trim((string) ($_GET['since'] ?? ''));
    if ($since === '') {
        apiError('Falta since', 422);
    }
    // Para salesByPayment resolvemos el drawerId de la caja abierta (mig 70):
    // filtra exacto por sesión + fallback por fecha para filas NULL. Sin caja
    // abierta → drawerId null → solo fallback por `since` (backward-compat).
    $openForBreakdown = ($resource === 'salesByPayment')
        ? $svc->getOpen($registerId, $outletId, $companyId)
        : null;
    $drawerIdForBreakdown = $openForBreakdown['drawerId'] ?? null;
    $data = match ($resource) {
        'expenses'       => $svc->getExpenses($registerId, $since),
        'income'         => $svc->getIncome($registerId, $since),
        'salesByPayment' => ['payments' => $svc->getPaymentBreakdown($registerId, $since, $drawerIdForBreakdown)],
    };
    apiOk($data);
}

// GET ?resource=blockers = qué impide cerrar el turno (órdenes y espacios
// abiertos de la SUCURSAL) + si el comercio prendió la regla.
//
// Es el MISMO `ShiftCloseGate::blockers()` que valida el POST. Deliberado: el
// POS deshabilita el botón "Cerrar caja" con esta respuesta, y si la lista que
// se muestra antes de tocar el botón saliera de otra consulta que la que
// rechaza el cierre, el cajero podría ver "todo listo" y comerse un 422.
//
// `enabled` viaja siempre para que el POS distinga "no hay nada abierto" de
// "esta regla no aplica en este comercio": son dos botones habilitados por
// motivos distintos y el segundo no tiene que mostrar ningún aviso.
//
// OJO CON EL ORDEN: va ARRIBA del `if ($method === 'GET')` de abajo, que es un
// catch-all sin `$resource` y termina en `apiOk()`. Abajo, este handler es
// inalcanzable y `?resource=blockers` devuelve el resumen del turno.
if ($method === 'GET' && $resource === 'blockers') {
    $payload = ShiftCloseGate::blockers($companyId, $outletId);
    $payload['enabled'] = ShiftCloseGate::isEnabled($companyId);
    apiOk($payload);
}

// --- GET: resumen completo del cajón (composite legacy/backward-compat) ----
// CATCH-ALL: cualquier `?resource=` no manejado arriba cae acá.
if ($method === 'GET') {
    $data = $svc->getSummary($registerId, $outletId, $companyId);
    if ($data === null) {
        apiOk(['closed' => true]);
    }
    if (drawerIsBlind($registerId, $companyId)) {
        apiOk(drawerBlindSummary($data));
    }
    apiOk($data);
}

// --- POST: mutaciones de caja (abrir, cerrar, extracción, ingreso) ----------
// Body JSON: { action: "open"|"close"|"expense"|"income", amount, date, note?, user? }
// Portado de app/action.php handlers: openCloseDrawer, expense, drwrIncome.
if ($method === 'POST') {
    // Guard: mutaciones de caja requieren registerId activo. El JWT del panel
    // tiene rid='' por default; solo el JWT pos-app o un panel que llamó
    // /v1/active-register llegan acá con registerId no vacío. Sin esta guarda,
    // un panel sin caja activa crearía drawers fantasma con registerId=''.
    if ($registerId === '' || $registerId === null) {
        apiError('Caja no seleccionada (registerId vacío). Activá una caja antes de operar.', 403);
    }

    $rawBody = file_get_contents('php://input');
    $body    = json_decode($rawBody ?: '', true);

    if (!is_array($body)) {
        apiError('Body JSON inválido', 400);
    }

    $action = trim((string) ($body['action'] ?? ''));
    $amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;
    $date   = trim((string) ($body['date'] ?? ''));
    $note   = trim((string) ($body['note'] ?? ''));
    $user   = trim((string) ($body['user'] ?? ''));
    // Lo contado MEDIO POR MEDIO (mig 169). Opcional a propósito: un cliente
    // desplegado antes de este cambio —o un cierre que quedó encolado en una
    // tablet— manda solo `amount`, y ese cierre tiene que seguir funcionando
    // exactamente igual. `amount` sigue siendo el efectivo en los dos casos.
    $counted = isset($body['counted']) && is_array($body['counted']) ? $body['counted'] : [];

    if ($date === '') {
        // Tenant-local naive (no UTC del container) — ver TenantClock. Sin esto
        // los gastos/ingresos de caja quedaban +3h adelantados y se ordenaban
        // por encima de eventos posteriores en "últimos movimientos" (2026-07-30).
        //
        // Es un FALLBACK, no un override: sólo corre cuando el cliente no mandó
        // fecha. NO pisa la del cliente, y no debe hacerlo — la fecha de una
        // apertura/cierre es la de EMISIÓN. Un turno abierto sin red y
        // sincronizado después conserva su hora: `use-drawer.ts` calcula
        // `date` con el reloj del comercio ANTES de encolar la operación
        // (`tenantNow(timezone)`) y la manda tal cual al drenar la cola.
        //
        // Tampoco es el bug de zona de 2026-09-01 (venta corrida 3h): ese vivía
        // en el OTRO embudo. Este endpoint entra por `apiAuthTenant()` →
        // `data.php` → `TenantClock::apply()`, así que su sesión de PostgreSQL
        // siempre estuvo en la zona del comercio — por eso el turno quedaba
        // bien y sólo la venta se corría. Ver `lib/Auth/apiAuthPosContext.php`.
        $date = TenantClock::now($companyId);
    }

    // userId del JWT tiene precedencia; $user del body es solo para auditoría/email del legacy
    $userId = $ctx['userId'] ?? '';

    // ── Gate de autorización por acción ────────────────────────────────────
    // `expense`/`income` (extracción e ingreso de efectivo) van con
    // finance.manage: no tienen clave propia en el catálogo y son movimientos
    // de plata, no la apertura/cierre del turno — colgarlos de
    // pos.drawer.close le cambiaría el significado a esa clave. finance.manage
    // ya es la clave de todo lo que mueve caja (finance/movements.php,
    // pago a proveedor en credit-payments.php) y está en el seed de manager.
    //
    // Realm `pos-app`: la sesión del device se emite con el rol seed `device`,
    // cuyo piso incluye pos.drawer.open/close y finance.manage — abrir, cerrar
    // y mover efectivo son operaciones de mostrador y la caja nunca se queda
    // sin poder hacerlas (offline-first). El gate discrimina de verdad para
    // cualquier rol más chico y para el realm `panel`.
    $drawerPerm = [
        'open'    => 'pos.drawer.open',
        'close'   => 'pos.drawer.close',
        'expense' => 'finance.manage',
        'income'  => 'finance.manage',
    ][$action] ?? null;
    // Fail-CLOSED: una acción que no está en el mapa no tiene permiso
    // asignado, y `$drawerPerm !== null &&` la dejaba pasar el gate entero
    // para que el `default:` del switch de abajo la rechazara — o sea que la
    // próxima acción que alguien agregue al switch sin agregarla al mapa nace
    // sin gate. El 400 sale acá, antes de cualquier efecto.
    if ($drawerPerm === null) {
        apiError("Acción '$action' no soportada", 400);
    }
    if (!hasPermission($drawerPerm)) {
        apiError("No tenés permiso para esta acción (requiere: $drawerPerm)", 403);
    }

    // Arqueo del turno que se está por cerrar. Se lee ANTES del cierre porque
    // después la fila ya no está abierta y `getOpen()` no la encuentra. Va en la
    // respuesta para que un cierre hecho SIN CONEXIÓN pueda compararse contra el
    // total que el dispositivo había mostrado: el cajero cerró mirando ese
    // número y, si el del servidor es otro, tiene que verlo (front:
    // `shift-close-reconciliation.ts`). Es best-effort — un fallo leyendo el
    // arqueo no puede impedir que la caja cierre.
    $closingTotals = null;
    if ($action === 'close') {
        // ── Gate: no se cierra el turno con órdenes o espacios abiertos ────
        // Opcional por comercio (`settingDrawerRequireClosedOrders`, apagado
        // por default). El front ya deshabilita el botón con GET
        // ?resource=blockers; esto es la regla de verdad — un POS con la
        // config vieja, o un curl al endpoint, chocan igual.
        //
        // SOLO si hay un turno abierto de verdad, y ese `isOpen` es la pieza
        // que evita el limbo del cierre encolado. Un cierre que se hizo sin
        // red y sincroniza al otro día ya se aplicó (o se aplica por el camino
        // idempotente `'Already Closed'` de DrawerService::close). Si el gate
        // corriera igual, órdenes abiertas DESPUÉS de que ese turno terminó
        // rechazarían para siempre una operación que ya no tiene nada que
        // validar — y el canal `drawer` es FIFO, así que ese rechazo también
        // congelaría la apertura del turno siguiente. Con la guarda, el
        // reenvío pasa derecho y la caja del día siguiente abre.
        //
        // Cuando el turno SÍ sigue abierto el rechazo es correcto y tiene
        // salida: lo que bloquea son órdenes y espacios de la MISMA sucursal,
        // visibles y cerrables desde ese mismo POS, y la operación queda en
        // Pendientes para reintentar (o descartar). Nunca es un callejón.
        //
        // `$date` acota el gate a lo que existía CUANDO EL CAJERO CERRÓ, no a
        // lo que hay ahora. Es la otra mitad de la protección contra el limbo,
        // y la que cubre el caso que `isOpen` no ve: un cierre encolado a las
        // 22:00 que sincroniza a las 10:00 con el turno todavía abierto en el
        // servidor: sin el corte lo frenarían las órdenes que abrió OTRA caja
        // después de que ese turno terminó. Online, `$date` es ahora y el
        // corte no cambia nada.
        if ($svc->isOpen($registerId, $outletId, $companyId)) {
            try {
                ShiftCloseGate::assertCanClose($companyId, $outletId, $date);
            } catch (ShiftCloseBlockedException $e) {
                // 422 y no 500: es una regla de negocio, y el POS necesita el
                // detalle para listar QUÉ falta. El catch va ACÁ y no abajo
                // porque el `catch (\RuntimeException)` del try grande mapea a
                // 500 — correcto para lo que se lanza ahí (errores de DB de
                // DrawerService), inservible para esto.
                apiError($e->getMessage(), 422, $e->blockers());
            }
        }
        try {
            $closingTotals = $svc->getClosingTotals($registerId, $outletId, $companyId);
        } catch (\Throwable $e) {
            error_log('[drawer.php] getClosingTotals falló antes del cierre: ' . $e->getMessage());
        }
    }

    try {
        switch ($action) {
            case 'open':
                $result = $svc->open($amount, $date, $userId);
                break;
            case 'close':
                // `$closingTotals` se pasa a `close()` en vez de dejar que lo
                // relea: el número que se congela y el que se le devuelve al
                // cajero tienen que salir de la MISMA lectura. Releerlo abre
                // una ventana (una venta que entra en el medio) en la que el
                // arqueo informado y el guardado difieren sin que nadie pueda
                // explicar por qué.
                $result = $svc->close($amount, $date, $userId, $counted, $closingTotals);
                break;
            case 'expense':
                $result = $svc->addExpense($amount, $note, $date);
                break;
            case 'income':
                $result = $svc->addIncome($amount, $note, $date);
                break;
            default:
                apiError("Acción '$action' no soportada", 400);
        }
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 500);
    }

    // $result es true en éxito, o un string con el motivo de idempotencia
    // (Already Open, Already Closed, etc.) — se devuelve como ok=true para
    // que el front lo maneje igual que el legacy (jsonDieMsg con 200+success).
    // `closing` solo viaja en el cierre y solo si se pudo leer. Ausente cuando
    // la caja ya estaba cerrada (reenvío idempotente): no hay turno del que
    // informar, y devolver ceros ahí se leería como "el turno fue de 0".
    $payload = ['message' => $result === true ? 'true' : $result];
    if ($closingTotals !== null) {
        // `byMethod` es el arqueo completo del cierre: esperado, contado y
        // diferencia POR MEDIO DE PAGO. Es lo que el POS pinta como informe
        // post-cierre y lo que un cierre hecho sin red compara cuando por fin
        // sincroniza. `counted` vacío (cliente viejo) devuelve las mismas
        // filas con `counted: null` salvo la del efectivo — el arqueo se
        // informa igual, con lo que se sepa.
        $closingTotals['byMethod'] = DrawerService::composeArqueo(
            $closingTotals['expectedByMethod'] ?? [],
            $counted !== [] ? $counted : [[
                'key'     => DrawerService::paymentGroupKey(DrawerService::CASH_METHOD_NAME),
                'name'    => DrawerService::CASH_METHOD_NAME,
                'isCash'  => true,
                'counted' => $amount,
            ]],
        );
        // A ciegas el arqueo NO se le devuelve al cajero. Sobrevive lo que él
        // mismo declaró (`counted`) — eso no es un acumulado, lo tipeó recién—;
        // se van el esperado, la diferencia y los totales del turno, que es
        // justo lo que el dueño decidió que esta caja no ve. El veredicto lo
        // mira él desde el panel.
        if (drawerIsBlind($registerId, $companyId)) {
            $blindRows = [];
            foreach ($closingTotals['byMethod'] as $r) {
                $blindRows[] = [
                    'key'     => $r['key'],
                    'name'    => $r['name'],
                    'isCash'  => $r['isCash'],
                    'counted' => $r['counted'],
                ];
            }
            $closingTotals = [
                'blind'    => true,
                'date'     => $closingTotals['date'] ?? null,
                'byMethod' => $blindRows,
            ];
        }
        $payload['closing'] = $closingTotals;
    }
    apiOk($payload);
}

apiError('Operación no reconocida', 400);
