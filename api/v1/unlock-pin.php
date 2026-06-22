<?php
/**
 * POST /v1/unlock-pin — Validación del PIN del lockscreen del POS.
 *
 * Acepta tanto `panel` (operador en el browser del panel) como `pos-app`
 * (device paired, JWT de larga duración). Es necesario el dual-realm porque
 * cuando el `_jwt_panel` (24h) expira, el cashier sigue trabajando con `_jwt`;
 * sin esto, el lockscreen rechazaría todo PIN como inválido aunque el cookie
 * del device esté OK.
 *
 * Match server-side contra `contact` scoped por COMPANY_ID del JWT — el
 * `lockPass` (PIN plano) nunca llega al browser por esta vía (a diferencia de
 * /v1/users, donde sí viaja porque la pantalla /settings/team lo muestra).
 */

require_once __DIR__ . '/../bootstrap.php';

apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$pin = isset($_POST['pin']) ? trim((string) $_POST['pin']) : '';
if (!preg_match('/^\d{4}$/', $pin)) {
    apiError('PIN inválido', 422);
}

$rows = ncmExecute(
    "SELECT contactId, contactName
       FROM contact
      WHERE companyId = ?
        AND type = 0
        AND contactStatus = 1
        AND lockPass = ?
      LIMIT 1",
    [COMPANY_ID, $pin]
);

if (!$rows || empty($rows[0])) {
    apiError('PIN incorrecto', 401);
}

$row = $rows[0];

apiOk([
    'user' => [
        'id'   => (string) ($row['contactid'] ?? $row['contactId'] ?? ''),
        'name' => (string) ($row['contactname'] ?? $row['contactName'] ?? ''),
    ],
]);
