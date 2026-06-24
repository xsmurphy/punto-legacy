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

// Buscar candidatos con lockPassHash (bcrypt) o lockPass (plano, compat).
// No se puede filtrar por PIN en SQL porque bcrypt no es reversible.
$rs = ncmExecute(
    'SELECT "contactId", "contactName", "lockPassHash", "lockPass"
       FROM contact
      WHERE "companyId" = ?
        AND type = 0
        AND "contactStatus" = 1
        AND ("lockPassHash" IS NOT NULL OR "lockPass" IS NOT NULL)
      LIMIT 100',
    [COMPANY_ID],
    false,
    true
);

$found = null;
if ($rs) {
    while (!$rs->EOF) {
        $row  = (array) $rs->fields;
        $hash  = $row['lockpasshash'] ?? $row['lockPassHash'] ?? null;
        $plain = $row['lockpass']     ?? $row['lockPass']     ?? null;
        if ($hash !== null && password_verify($pin, (string) $hash)) {
            $found = $row;
            break;
        } elseif ($hash === null && $plain !== null && $plain === $pin) {
            $found = $row;
            break;
        }
        $rs->MoveNext();
    }
}

if (!$found) {
    apiError('PIN incorrecto', 401);
}

apiOk([
    'user' => [
        'id'   => (string) ($found['contactid']   ?? $found['contactId']   ?? ''),
        'name' => (string) ($found['contactname'] ?? $found['contactName'] ?? ''),
    ],
]);
