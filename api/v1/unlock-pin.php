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

// Acepta tanto _jwt_panel (admin del panel, realm 'panel') como _jwt (device paired, realm 'pos-app').
// Se mantiene en apiAuthTenant (no migrado a apiAuthPosContext) porque este endpoint
// es el único que debe funcionar para AMBOS callers: el operador que acaba de parear
// el dispositivo (solo tiene _jwt) Y el admin logueado en el panel que también puede
// operar la caja (tiene _jwt_panel). Solo se usa COMPANY_ID del contexto, nunca USER_ID.
apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$pin = isset($_POST['pin']) ? trim((string) $_POST['pin']) : '';
if (!preg_match('/^\d{4}$/', $pin)) {
    apiError('PIN inválido', 422);
}

// Estrategia dual durante backfill:
// (a) bcrypt: iterar todos los usuarios con lockPassHash y verificar en PHP.
// (b) plain fallback: buscar directamente por lockPass (solo pre-backfill, LIMIT 1).
// Separar en 2 queries evita traer lockPass plano a memoria innecesariamente
// y elimina el timing oracle (el branch plano no compite con el bcrypt).
$found = null;

// (a) bcrypt: no filtrable en SQL, verificar en PHP
$rs = ncmExecute(
    'SELECT contactid, contactname, lockpasshash
       FROM contact
      WHERE companyid = ?
        AND type = 0
        AND contactstatus = 1
        AND lockpasshash IS NOT NULL',
    [COMPANY_ID],
    false,
    true
);
if ($rs) {
    while (!$rs->EOF) {
        $hash = $rs->fields['lockpasshash'] ?? null;
        if ($hash !== null && password_verify($pin, (string) $hash)) {
            $found = [
                'contactid'   => (string) ($rs->fields['contactid']   ?? ''),
                'contactname' => (string) ($rs->fields['contactname'] ?? ''),
            ];
            break;
        }
        $rs->MoveNext();
    }
}

// (b) plain fallback: solo para usuarios sin lockPassHash (pre-backfill)
if ($found === null) {
    $row = ncmExecute(
        'SELECT contactid, contactname
           FROM contact
          WHERE companyid = ?
            AND type = 0
            AND contactstatus = 1
            AND lockpasshash IS NULL
            AND lockpass = ?
          LIMIT 1',
        [COMPANY_ID, $pin]
    );
    if ($row) {
        $found = $row;
    }
}

if (!$found) {
    apiError('PIN incorrecto', 401);
}

apiOk([
    'user' => [
        'id'   => (string) ($found['contactid']   ?? ''),
        'name' => (string) ($found['contactname'] ?? ''),
    ],
]);
