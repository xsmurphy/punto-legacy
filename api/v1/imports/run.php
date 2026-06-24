<?php
/**
 * POST /v1/imports/run
 *
 * Body JSON: { sessionId, kind, mapping, mode }
 *   kind    — 'items' | 'contacts'
 *   mapping — null | { [canonicalHeader]: originalColumn }
 *   mode    — 'insert' | 'update'
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

$sessionId = trim((string) ($_POST['sessionId'] ?? ''));
$kind      = trim((string) ($_POST['kind']      ?? ''));
$mapping   = isset($_POST['mapping']) && is_array($_POST['mapping']) ? $_POST['mapping'] : null;
$mode      = trim((string) ($_POST['mode']      ?? 'insert'));

if ($sessionId === '') apiError('sessionId requerido', 400);
if (!in_array($kind, ['items', 'contacts'], true)) apiError('kind debe ser "items" o "contacts"', 400);
if (!in_array($mode, ['insert', 'update'], true))  apiError('mode debe ser "insert" o "update"', 400);

$result = \Punto\Api\Imports\ImportSession::run($sessionId, $kind, $mapping, $mode, $companyId, $userId);

if (!($result['ok'] ?? false)) {
    apiError($result['error'] ?? 'Error al ejecutar la importación', 422);
}

apiOk($result['report'] ?? []);
