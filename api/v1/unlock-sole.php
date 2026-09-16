<?php
/**
 * POST /v1/unlock-sole — desbloqueo de la caja SIN PIN cuando la sucursal
 * tiene un único usuario habilitado (context/72 §9.3, D-P2).
 *
 * Regla del owner (2026-09-16): si no hay más de un usuario, no tiene sentido
 * pedir PIN. Pero el PIN no es solo una traba de UI: el desbloqueo produce la
 * AFIRMACIÓN DE OPERADOR (`OperatorAssertion`), que es lo que atribuye ventas,
 * permisos y auditoría a una persona. Este endpoint emite esa MISMA afirmación
 * —vía `OperatorUnlock::grant()`, compartido con `/v1/unlock-pin`— y solo
 * después de comprobar acá, contra la BD, que el roster de la sucursal del
 * device es de exactamente uno.
 *
 * ── Auth: SOLO el Bearer del device (realm `pos-app`, module `pos`) ─────────
 * `apiAuthPosContext()` resuelve únicamente tokens de device; una sesión de
 * panel no pasa (401). A diferencia de `/v1/unlock-pin`, que por historia es
 * multi-realm, acá no hay ningún caller de panel que justificarlo: sin PIN, la
 * identidad sale ENTERA de "¿quién más podría ser?", y esa pregunta solo tiene
 * sentido contra la sucursal FIJA de una caja pareada. Un panel "desbloqueando
 * sin PIN" no tiene caja ni sucursal fija contra la cual contar.
 *
 * La sucursal sale del PAREO (`OUTLET_ID` del contexto del device), nunca del
 * body: no se acepta ningún parámetro.
 *
 * Respuestas:
 *   200 { user, operatorToken, permissions } — mismo shape que /v1/unlock-pin.
 *   403 { reason: 'pin_required' } — la sucursal tiene 0 o 2+ usuarios: hay
 *       que desbloquear con PIN. Con cero no hay a nombre de quién operar.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/apiAuthPosContext.php';
require_once __DIR__ . '/../lib/Auth/OperatorUnlock.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$ctx = apiAuthPosContext();
// Una pantalla cliente / KDS / estación de impresión también autentica con
// Bearer de device: ninguna opera a nombre de una persona.
if (($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}

$companyId = (string) ($ctx['companyId'] ?? '');
$outletId  = (string) ($ctx['outletId'] ?? '');

$sole = \Punto\Api\Auth\OperatorUnlock::soleOperator($companyId, $outletId);
if ($sole === null) {
    apiError('Esta caja se desbloquea con el código de usuario', 403, ['reason' => 'pin_required']);
}

apiOk(\Punto\Api\Auth\OperatorUnlock::grant($companyId, $sole['id'], $sole['name']));
