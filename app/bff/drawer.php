<?php
/**
 * /bff/drawer.php — BFF de caja/drawer del POS (Slice 26).
 *
 * Reemplaza el handler loadDrawerList de load.php (L1664).
 * Dos modos según el param `chk` del sobre `?l=`:
 *   chk=1  → GET /api/v1/drawer?resource=check → { success:'true' } | { closed:'Closed' }
 *   (none) → GET /api/v1/drawer                → { data: { list, date, subtotal, ... } }
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action === 'loadDrawerList') {
    $chk = !empty($get['chk']);

    if ($chk) {
        // Callsite 2 (line 24457): ¿cajón abierto? Front verifica data.closed (truthy = cerrado).
        $res = bffApiGet('v1/drawer.php', ['resource' => 'check'], '_jwt');
        if (!$res['ok']) {
            bffFailFromApi($res);
        }
        if (!empty($res['data']['isOpen'])) {
            bffJson(['success' => 'true']);
        } else {
            bffJson(['closed' => 'Closed']);
        }
    }

    // Callsite 1 (line 24390): resumen completo. Front accede result.data.{subtotal,total,...}
    $res = bffApiGet('v1/drawer.php', [], '_jwt');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    if (!empty($res['data']['closed'])) {
        // Drawer cerrado — mismo key que legacy jsonDieMsg('Closed', 200, 'closed')
        bffJson(['closed' => 'Closed']);
    }
    bffJson(['data' => $res['data']]);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
