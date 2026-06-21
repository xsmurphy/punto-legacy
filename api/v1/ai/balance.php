<?php
/**
 * GET /v1/ai/balance
 *
 * Devuelve el balance de créditos IA del tenant.
 * No toma lock — solo lectura para polling del chat.
 *
 * Auth: realm panel. GET only.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

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
