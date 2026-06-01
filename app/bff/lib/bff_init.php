<?php
/**
 * bff_init.php — Bootstrap común de todos los BFF endpoints del POS.
 *
 * Expone tras el include:
 *   $get    — array decodificado del sobre `?l=` (legacy masterUrlParams)
 *   $action — (string) $get['action'] ?? ''
 *
 * El BFF del POS tiene dos roles (ver §22.12 en context/08-convenciones.md):
 *   1. Traductor de protocolo: decodifica el sobre `?l=` del front y mapea
 *      acciones legacy a verbos REST + resources del API (la mayoría de archivos).
 *   2. Compositor: pide múltiples recursos del API EN PARALELO y ensambla el
 *      dataset que el front necesita (customers, drawer, items — patrón §22.12).
 *
 * Cualquier BFF nuevo debe incluir este archivo en vez de repetir el boilerplate.
 */

require_once __DIR__ . '/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');
