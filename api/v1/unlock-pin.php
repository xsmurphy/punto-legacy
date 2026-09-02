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
$unlockCtx = apiAuthTenant(['panel', 'pos-app']);
// Defensa-en-profundidad: una pantalla cliente (module=screen) autenticada como pos-app
// no debe poder desbloquear operadores.
if (($unlockCtx['realm'] ?? '') === 'pos-app' && ($unlockCtx['module'] ?? 'pos') !== 'pos') {
    apiError('Endpoint solo accesible desde POS', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$pin = isset($_POST['pin']) ? trim((string) $_POST['pin']) : '';
if (!preg_match('/^\d{4}$/', $pin)) {
    apiError('PIN inválido', 422);
}

// Estrategia dual durante backfill:
// (a) SHA-256: iterar todos los usuarios con pinhash y verificar en PHP.
// (b) plain fallback: buscar directamente por lockPass (solo pre-backfill, LIMIT 1).
// Separar en 2 queries evita traer lockPass plano a memoria innecesariamente
// y elimina el timing oracle (el branch plano no compite con el SHA-256).
$found = null;

// (a) SHA-256: no filtrable en SQL, verificar en PHP
$pinHash = hash('sha256', $pin);
$rs = ncmExecute(
    'SELECT contactid, contactname, pinhash
       FROM contact
      WHERE companyid = ?
        AND type = 0
        AND contactstatus = 1
        AND pinhash IS NOT NULL',
    [COMPANY_ID],
    false,
    true
);
if ($rs) {
    while (!$rs->EOF) {
        $hash = $rs->fields['pinhash'] ?? null;
        if ($hash !== null && $hash === $pinHash) {
            $found = [
                'contactid'   => (string) ($rs->fields['contactid']   ?? ''),
                'contactname' => (string) ($rs->fields['contactname'] ?? ''),
            ];
            break;
        }
        $rs->MoveNext();
    }
}

// (b) plain fallback: solo para usuarios sin pinhash (pre-backfill)
if ($found === null) {
    $row = ncmExecute(
        'SELECT contactid, contactname
           FROM contact
          WHERE companyid = ?
            AND type = 0
            AND contactstatus = 1
            AND pinhash IS NULL
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

// Afirmación de operador: este es el ÚNICO punto del sistema donde el backend
// verifica, contra la BD, qué persona está parada frente a la caja. El token
// convierte ese hecho en algo que las requests siguientes pueden probar — sin
// él, el realm `pos-app` no distingue a los tres mozos que comparten la tablet
// y la exclusividad de espacios (context/15) sería un `if` sobre un dato que el
// cliente elige. Ver el docblock de OperatorAssertion.
require_once __DIR__ . '/../lib/Auth/OperatorAssertion.php';
require_once __DIR__ . '/../lib/Auth/OperatorContext.php';
$operatorId = (string) ($found['contactid'] ?? '');

// ── Permisos del operador (surfacing para la UI de la caja) ──────────────────
//
// El backend sigue siendo la autoridad: `SpaceOwnershipGuard` y compañía
// resuelven contra el rol del operador en CADA request. Esto es lo que le falta
// al front para no mentir — sin ello solo puede espejar dos de las tres
// condiciones del guard ("el espacio no tiene mozo", "el espacio es mío") y le queda
// afuera la tercera ("soy encargado y puedo intervenir"). El resultado sin esto
// es siempre malo: o se apagan acciones que el encargado SÍ puede ejecutar, o
// se dejan prendidas y revientan con 403 al tocarlas.
//
// Por qué ACÁ y no en el roster del bootstrap (`/v1/users`): ese payload
// proyecta a propósito id/name/pinhash y nada más —"ni rol"— porque vive para
// siempre en el localStorage de una tablet compartida. Los permisos son la
// capacidad de UNA persona sobre sí misma, y este es el único punto del sistema
// donde el backend comprobó contra la BD quién es esa persona. Se entregan solo
// a quien acaba de probar su PIN, en la misma respuesta que su afirmación.
//
// Filtrados al prefijo `pos.`: el resto del catálogo son permisos de PANEL
// (reportes, ajustes, contactos). En la caja no gobiernan ninguna UI, así que
// mandarlos solo agranda la superficie que se cachea en el dispositivo sin
// habilitar nada.
//
// EXTRA_POS_PERMS es la excepción explícita a esa regla, y existe porque la
// condición que la justifica —"no gobiernan ninguna UI de la caja"— dejó de ser
// cierta para una clave: desde que el GET de `/v1/reports/transactions` se
// evalúa contra el rol del OPERADOR (ver el gate en ese archivo),
// `reports.sales.view` decide si el asistente de la caja puede contestar
// "¿cuánto se vendió hoy?". Sin bajarla, la UI no tiene forma de saberlo y solo
// puede hacer dos cosas, las dos malas: prometerle ventas a un cajero que va a
// recibir 403, o callarlas también para el dueño.
//
// Es una ALLOWLIST de claves puntuales, no un ensanche del filtro: cada entrada
// tiene que gatear algo que se ve o se ofrece en la caja. Sigue sin bajar el
// catálogo de panel.
//
// Su contraparte del lado del front es `POS_TOOL_PERMISSION`
// (`frontend/lib/pos/agent-tools.ts`): una clave que gatee una tool allá y no
// esté acá NUNCA llega al dispositivo, así que la tool queda apagada para
// todos. Se agregan juntas.
$extraPosPerms = ['reports.sales.view'];
$operatorRole = \Punto\Api\Auth\OperatorContext::roleOf(COMPANY_ID, $operatorId);
$operatorPerms = $operatorRole !== null
    ? array_values(array_filter(
        \RoleService::getPermissions($operatorRole, COMPANY_ID),
        static fn($perm) => str_starts_with((string) $perm, 'pos.')
            || in_array((string) $perm, $extraPosPerms, true)
    ))
    : [];

apiOk([
    'user' => [
        'id'   => $operatorId,
        'name' => (string) ($found['contactname'] ?? ''),
    ],
    'operatorToken' => \Punto\Api\Auth\OperatorAssertion::issue(COMPANY_ID, $operatorId),
    'permissions'   => $operatorPerms,
]);
