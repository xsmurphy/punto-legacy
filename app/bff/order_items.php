<?php
/**
 * /bff/order_items.php — BFF de manipulación de items de órdenes (cluster meta-JSONB).
 *
 * Reemplaza de action.php:
 *   processOrderItems       (L394) → POST ?resource=select  (devuelve items)
 *   processOrderItemsUpdate (L451) → PUT  ?resource=process (devuelve items)
 *   moveOrderItems          (L517) → PUT  ?resource=move    (devuelve items)
 *   removeItemfromOrder      (L332) → PUT  ?resource=remove  (success)
 *
 * Los `items` vienen DENTRO del sobre `?l=` (no en POST aparte). El BFF los decodifica y
 * los reenvía como body a /api/v1/order_items.php (cookie _jwt). Devuelve al top level el
 * array de items (los que devuelven) o {success:"true"} (remove).
 */

require_once __DIR__ . '/lib/bff_init.php';
$items  = is_array($get['items'] ?? null) ? $get['items'] : [];
$ep     = 'v1/order_items.php';

switch ($action) {
    case 'processOrderItems': // POST ?resource=select → array
        $res = bffApiPost($ep . '?resource=select', ['items' => $items], '_jwt');
        bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);

    case 'processOrderItemsUpdate': // PUT ?resource=process → array
        $res = bffApiPut($ep, ['resource' => 'process'], ['items' => $items], '_jwt');
        bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);

    case 'moveOrderItems': // PUT ?resource=move → array
        $res = bffApiPut($ep, ['resource' => 'move'], ['items' => $items, 'from' => (string) ($get['from'] ?? '')], '_jwt');
        bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);

    case 'removeItemfromOrder': // PUT ?resource=remove → success
        $res = bffApiPut($ep, ['resource' => 'remove'], [
            'transId'   => (string) ($get['oid'] ?? ''),
            'itemId'    => (string) ($get['id'] ?? ''),
            'oPosition' => (string) ($get['oPosition'] ?? ''),
            'autoPrint' => ($get['autoPrint'] ?? false) ? '1' : '0',
            'motive'    => (string) ($get['motive'] ?? ''),
        ], '_jwt');
        if (!$res['ok']) {
            bffFailFromApi($res);
        }
        bffJson(['success' => 'true']);

    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}
