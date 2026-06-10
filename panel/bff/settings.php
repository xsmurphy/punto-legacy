<?php
/**
 * BFF — Ajustes de la empresa.
 *
 *   GET  /bff/settings.php?view=general|options|taxonomies[&type=]  → crudo.
 *   POST /bff/settings.php (action=update&type=setting + campos)     → guarda (vía la API).
 *
 * Gateway fino sobre la API (NO toca BD, NO formatea). Reenvía el JWT. El front formatea + arma.
 * Las vistas/escrituras aún no migradas (templates/ecommerce/monedas/adm) siguen en el PHP legacy
 * `a_settings.php` vía `?action=` hasta los incrementos siguientes.
 */

require_once __DIR__ . '/lib/api_client.php';

const SETTINGS_API = ['base' => 'shared'];

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    // Reenvía todos los campos del form (filtrando vacíos para que los toggles off no viajen).
    $payload = array_filter($_POST, fn($v) => $v !== '');
    $res = bffApiPost('v1/settings.php', $payload, '_jwt_panel', SETTINGS_API);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

$query = array_filter([
    'view' => $_GET['view'] ?? '',
    'type' => $_GET['type'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/settings.php', $query, '_jwt_panel', SETTINGS_API);
if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['ok' => true, 'data' => $res['data']]);
