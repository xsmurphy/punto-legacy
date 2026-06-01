<?php
/**
 * /bff/giftcards.php — BFF de gift cards del POS.
 *
 * Reemplaza chkGiftCard (action.php L736), modos JSON:
 *   type=bool          → GET ?code=&amount=  → { success: <status> }
 *   type=data (json=1) → GET ?code=&resource=info → { ...datos }
 *
 * NO toca BD. Reenvía a /api/v1/giftcards.php (cookie _jwt) y devuelve el shape legacy al
 * top level (el front lee result.success o los campos). Ante fallo → onFail del front
 * (que muestra "Gift Card inexistente").
 */

require_once __DIR__ . '/lib/bff_init.php';

if (($get['action'] ?? '') !== 'chkGiftCard') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$code = (string) ($get['id'] ?? '');
$type = (string) ($get['type'] ?? 'bool');

if ($type === 'data') {
    $res = bffApiGet('v1/giftcards.php', ['code' => $code, 'resource' => 'info'], '_jwt');
} else {
    $res = bffApiGet('v1/giftcards.php', ['code' => $code, 'amount' => (string) ($get['amount'] ?? 0)], '_jwt');
}

if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson($res['data']);
