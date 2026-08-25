<?php
/**
 * /api/v1/drawer.php — operaciones de caja/drawer del POS (Slice 26).
 *
 *   GET ?resource=check       → { isOpen: bool }  — ¿cajón abierto?
 *   GET ?resource=hourlyStats → { timezone, shift[], today[], yesterday[] } — ventas por hora
 *   GET                       → resumen completo — list, date, subtotal, total, tips, returns
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/DrawerService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\DrawerService;
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

// --- GET: resumen completo del cajón (composite legacy/backward-compat) ----
if ($method === 'GET') {
    $data = $svc->getSummary($registerId, $outletId, $companyId);
    if ($data === null) {
        apiOk(['closed' => true]);
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
        $payload['closing'] = $closingTotals;
    }
    apiOk($payload);
}

apiError('Operación no reconocida', 400);
