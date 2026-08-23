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

    try {
        switch ($action) {
            case 'open':
                $result = $svc->open($amount, $date, $userId);
                break;
            case 'close':
                $result = $svc->close($amount, $date, $userId);
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
    if ($result === true) {
        apiOk(['message' => 'true']);
    } else {
        // Idempotencia: la acción ya fue aplicada — respuesta 200 con mensaje
        apiOk(['message' => $result]);
    }
}

apiError('Operación no reconocida', 400);
