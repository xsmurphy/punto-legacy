<?php
/**
 * /bff/sold_packs.php — BFF de packs de servicios del POS.
 *
 * Acciones:
 *   action=activePacks  contactId=<uuid>  → GET /v1/sold_pack?contactId=...
 *                                            Devuelve packs activos del cliente.
 *
 *   action=recordUsage  soldPackId=<uuid> usages=[...] performedBy?=<uuid>
 *                                         → POST /v1/sold_pack_usage
 *                                           Registra canjes de servicios del pack.
 */

require_once __DIR__ . '/lib/bff_init.php';

switch ($action) {
    case 'activePacks':
        $contactId = trim((string) ($get['contactId'] ?? ''));
        if ($contactId === '') {
            bffJson(['ok' => false, 'error' => 'Falta contactId'], 400);
        }
        $res = bffApiGet('v1/sold_pack.php', ['contactId' => $contactId], '_jwt');
        if (!$res['ok']) {
            bffFailFromApi($res);
        }
        bffJson($res['data'] ?? []);
        break;

    case 'recordUsage':
        $soldPackId  = trim((string) ($get['soldPackId']  ?? ''));
        $usages      = $get['usages'] ?? [];
        $performedBy = trim((string) ($get['performedBy'] ?? '')) ?: null;

        if ($soldPackId === '') {
            bffJson(['ok' => false, 'error' => 'Falta soldPackId'], 400);
        }

        $payload = [
            'soldPackId'  => $soldPackId,
            'usages'      => json_encode($usages),
        ];
        if ($performedBy !== null) {
            $payload['performedBy'] = $performedBy;
        }

        $res = bffApiPost('v1/sold_pack_usage.php', $payload, '_jwt');
        if (!$res['ok']) {
            bffFailFromApi($res);
        }
        bffJson($res['data'] ?? []);
        break;

    default:
        bffJson(['ok' => false, 'error' => 'Acción no reconocida'], 400);
}
