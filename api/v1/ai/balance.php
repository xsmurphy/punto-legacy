<?php
/**
 * GET /v1/ai/balance
 *
 * Devuelve el balance de créditos IA del tenant.
 * No toma lock — solo lectura para polling del chat.
 *
 * Auth: realms `panel` y `pos-app`. GET only.
 */

require_once __DIR__ . '/../../bootstrap.php';

// `pos-app` (context/59 F2): el asistente de la caja consulta el saldo antes de
// cada llamada, igual que el del panel. Es el MISMO crédito del MISMO tenant —
// el saldo no es dato por usuario ni por caja, así que el realm no cambia qué
// se devuelve, solo quién puede preguntarlo.
$ctx = apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Method not allowed', 405);
}

$companyId = $ctx['companyId'];

$row = ncmExecute(
    'SELECT aiCreditsBalance FROM company WHERE companyId = ? LIMIT 1',
    [$companyId],
    false,
    true
);

$balance = 0;
if ($row && !$row->EOF) {
    $balance = (int) ($row->fields['aicreditsbalance'] ?? $row->fields['aiCreditsBalance'] ?? 0);
}

apiOk(['balance' => $balance]);
