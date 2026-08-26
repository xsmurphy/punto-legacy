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

// ── Gate de permiso ─────────────────────────────────────────────────────────
// Este endpoint ejecuta la MISMA importación masiva que /v1/items?resource=import
// (gateada por inventory.item.*) y la de contactos, pero no chequeaba permiso:
// cualquier sesión de panel reescribía el catálogo o la agenda entera saltándose
// el modelo de permisos (escalación intra-tenant, auditoría 2026-08-26).
$op = $mode === 'update' ? 'edit' : 'create';
$allowedContactTypes = null;
if ($kind === 'items') {
    // Mismas claves que el endpoint directo (items.php).
    $perm = $mode === 'update' ? 'inventory.item.edit' : 'inventory.item.create';
    if (!hasPermission($perm)) {
        apiError("No tenés permiso para importar (requiere: $perm)", 403);
    }
} else { // contacts
    // Un CSV puede mezclar clientes (type 1) y proveedores (type 2) — los
    // empleados (type 0) no se importan por CSV. Se resuelve QUÉ tipos puede
    // importar el rol y el enforcement fino va por fila en ContactImporter:
    // así un rol solo-clientes importa un CSV de clientes sin quedar bloqueado
    // por no poder tocar proveedores, y no se cuela un tipo sin permiso. Mismas
    // claves que contacts.php (contactsPermFor).
    $allowedContactTypes = [];
    if (hasPermission("contacts.customer.$op"))    $allowedContactTypes[] = 1;
    if (hasPermission('contacts.supplier.manage')) $allowedContactTypes[] = 2;
    if ($allowedContactTypes === []) {
        apiError('No tenés permiso para importar contactos', 403);
    }
}

$result = \Punto\Api\Imports\ImportSession::run($sessionId, $kind, $mapping, $mode, $companyId, $userId, $allowedContactTypes);

if (!($result['ok'] ?? false)) {
    apiError($result['error'] ?? 'Error al ejecutar la importación', 422);
}

apiOk($result['report'] ?? []);
