<?php
/**
 * POST /v1/operator-pin — el operador de la caja elige SU PROPIO código POS
 * cuando todavía es el del signup (context/72 §9.3).
 *
 * Caso: sucursal con un solo usuario. La caja abre sin PIN, pero un bloqueo
 * MANUAL sí lo pide (decisión del owner 2026-09-16). Si ese usuario nunca
 * eligió su código, bloquear lo dejaría frente a uno que no conoce: antes de
 * bloquear, la caja le pide elegirlo por acá.
 *
 * ── Auth: device + operador ─────────────────────────────────────────────────
 *   - Bearer del device (realm `pos-app`, module `pos`) — `apiAuthPosContext()`
 *     no resuelve sesiones de panel (401).
 *   - Afirmación de operador vigente (`X-Operator-Token`, `OperatorAssertion`):
 *     el PIN que se cambia es el del contacto que ESA afirmación prueba. Nunca
 *     un id del body — no se lee ninguno. Sin afirmación válida para este
 *     tenant → 403.
 *
 * ── Acotado al PIN por defecto ──────────────────────────────────────────────
 * Solo mientras `pinisdefault` siga en true (409 si no). Una afirmación vive
 * 16 h en una tablet compartida; si este endpoint cambiara cualquier PIN,
 * alguien con la caja desbloqueada podría cambiarle el código al dueño y
 * dejarlo afuera. Con el PIN ya elegido, cambiarlo sigue siendo la ficha de
 * Equipo en el panel, con su permiso.
 *
 * Validación, unicidad dentro del tenant y hashes: `UsersService::update()`,
 * la misma escritura que usa el panel, que además baja `pinisdefault`.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/apiAuthPosContext.php';
require_once __DIR__ . '/../lib/Auth/OperatorAssertion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$ctx = apiAuthPosContext();
if (($ctx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}
$companyId = (string) ($ctx['companyId'] ?? '');

$operatorId = \Punto\Api\Auth\OperatorAssertion::verify(
    \Punto\Api\Auth\OperatorAssertion::fromRequest(),
    $companyId
);
if ($operatorId === null) {
    apiError('Volvé a desbloquear la caja para cambiar tu código', 403, ['reason' => 'operator_required']);
}

$pin = trim((string) ($_POST['lockPass'] ?? ''));

$svc = new \Punto\Api\Users\UsersService();
$row = ncmExecute(
    'SELECT pinisdefault FROM contact
      WHERE contactid = ? AND companyid = ? AND type = 0 AND contactstatus = 1',
    [$operatorId, $companyId]
);
if (!$row) {
    apiError('Usuario no encontrado', 404);
}
if (!in_array($row['pinisdefault'] ?? false, [true, 't', 1, '1', 'true'], true)) {
    apiError('Tu código POS ya fue elegido', 409);
}
if (!preg_match(\Punto\Api\Users\UsersService::LOCK_PASS_PATTERN, $pin)) {
    apiError('El código POS debe tener 4 dígitos numéricos', 422);
}

try {
    if (!$svc->update($operatorId, $companyId, ['lockPass' => $pin])) {
        apiError('No se pudo guardar el código', 500);
    }
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
}

apiOk(['ok' => true]);
