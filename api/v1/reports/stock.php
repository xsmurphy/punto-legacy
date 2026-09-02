<?php
/**
 * REST canónico (API compartida /api) — Niveles de Stock (multi-depósito) (raw).
 *
 *   GET /v1/reports/stock
 *       → { needsOutlet: bool, rows: [...] }. needsOutlet=true si no hay sucursal válida
 *         seleccionada. Filas crudas (números). Auth: realms `panel`, `pos-app`,
 *         `mcp`. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../bootstrap.php';

// `pos-app` (context/59 D3/F1): el asistente de la caja lee stock con el Bearer
// del device. NO agrega exposición material — el device ya ve el stock de TODAS
// las sucursales por otra puerta: `/api/pos/items?id=X&resource=inventory-movements`
// (items.php:150 acepta `pos-app`; su GET devuelve `breakdown`, que agrupa por
// outlet filtrando SOLO por companyId — StockMovementsService::breakdown:98).
// Eso es lo que pinta la ficha de producto del mostrador, para derivar al
// cliente a la sucursal que sí tiene el artículo (use-pos-item-info.ts:7-8).
// Acá el scope es más chico todavía: `VIEW_OUTLET_ID` está restringido al realm
// `panel` (bootstrap.php:284), así que una request `pos-app` no puede ensanchar
// el reporte más allá de la sucursal de su caja.
$ctx = apiAuthTenant(['panel', 'pos-app', 'api']);
$svc = new \Punto\Api\Reports\StockService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA — el ÚNICO del directorio que no es uniforme ────
 *
 * La clave es `inventory.item.view`, la misma que gobierna el catálogo en
 * `items.php`: esto es el catálogo con su saldo por depósito, no un reporte de
 * ventas. Lo que cambia acá es CONTRA QUIÉN se mide, y cambia por realm.
 *
 * ── `panel` y `api`: contra la persona ──────────────────────────────────────
 *
 * Igual que el resto de `/v1/reports/*`. Una API key hereda el rol de quien la
 * creó, así que la key de un cajero lee lo que el cajero puede leer.
 *
 * ── `pos-app`: el piso del DEVICE, y NO el gate estricto ────────────────────
 *
 * `OperatorContext::requirePermission()` es fail-closed sin operador: sin
 * `X-Operator-Token` devuelve 403. Acá eso ROMPERÍA la caja, y no en un caso
 * de borde. El único consumidor `pos-app` de este endpoint es la tool
 * `get_stock` del asistente del mostrador, y esa tool corre HAYA O NO alguien
 * desbloqueado — `frontend/lib/pos/use-pos-agent-chat.ts:120-135` manda el
 * header solo si hay token, y `get_stock` está fuera de `POS_TOOL_PERMISSION`
 * a propósito (`frontend/lib/pos/agent-tools.ts:176-178`). Hay tres estados
 * normales sin token: el desbloqueo OFFLINE (el PIN se valida contra el roster
 * cacheado y no hay firma del server), la ventana entre el unlock optimista y
 * la respuesta de `/api/pos/unlock`, y un unlock online que falló con la caja
 * ya operando.
 *
 * Y no hace falta: es la decisión ya cerrada en `context/59` §D9. Precio y
 * stock son lo que la pantalla de venta tiene abierta delante de quien opera
 * la caja; exigir operador acá no protegería nada que no esté a un toque de
 * distancia. Encima el device ya ve el stock de TODAS las sucursales por otra
 * puerta (`items.php?resource=inventory-movements`, ver el comentario del
 * `apiAuthTenant` de arriba), y `VIEW_OUTLET_ID` está restringido al realm
 * `panel`, así que una request `pos-app` no puede ensanchar el reporte más allá
 * de la sucursal de su caja.
 *
 * El piso correcto para una TERMINAL es entonces el rol `device`, que tiene
 * `inventory.item.view` en su seed justamente para el catálogo. Es la misma
 * clave; lo que cambia es que se mide contra la terminal y no contra una
 * persona que puede no estar.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
if ((string) ($ctx['realm'] ?? '') === 'pos-app') {
    if (!hasPermission('inventory.item.view')) {
        apiError('No tenés permiso para esta acción (requiere: inventory.item.view)', 403);
    }
} else {
    \Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'inventory.item.view');
}

// Outlet efectivo: si el browser eligió scope (VIEW_OUTLET_ID definida vía
// header X-Outlet-Id), prevalece sobre OUTLET_ID del JWT. Tanto el gate como
// el service necesitan ver el efectivo, no el raw — si no, el agregado del
// ledger (SUM por sucursal) sale de la sucursal equivocada en modo "Todas" o
// al switchear.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;

// Gate: requiere una sucursal válida (UUID) — el reporte agrupa stock por outlet.
// Modo "Todas" (VIEW_OUTLET_ID='') NO permite este reporte (no tiene sentido
// stock por sucursal sin una sucursal).
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $effectiveOutletId)) {
    apiOk(['needsOutlet' => true, 'rows' => []]);
}

// El service ya no recibe fragmentos SQL interpolados (context/52): el scope
// viaja bindeado. Lo que hacía falta conservar de `Roc::build` es su guard de
// contexto — que COMPANY_ID sea un UUID real antes de consultar.
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido (companyId no es UUID)', 500);
}

apiOk(['needsOutlet' => false, 'rows' => $svc->levels(COMPANY_ID, $effectiveOutletId)]);
