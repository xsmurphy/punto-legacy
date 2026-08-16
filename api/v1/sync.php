<?php
/**
 * REST canónico (API compartida /api) — Sync del POS.
 *
 *   ── Legacy (Slice 8, `?resource=items|customers`) ─────────────────────────
 *   POST ?resource=items     { ids: [...] }  → cuáles itemIds ya no están activos
 *   POST ?resource=customers { ids: [...] }  → cuáles contactIds ya no están activos
 *
 *   Check por lista de ids — el caller manda TODOS los ids que tiene en su
 *   cache local y el server responde cuáles ya no están activos. Puerto de
 *   `app/action.php` (chkDeletedItems/chkDeletedCustomers) del /app legacy.
 *   Sin caller conocido en `frontend/` a 2026-08-16 (el /app legacy que lo
 *   usaba ya no existe en este repo) — se preserva sin tocar por si algún
 *   caller externo lo sigue usando; NO se borra sin que el owner lo pida.
 *
 *   ── Sync incremental (context/43-sync-incremental.md) ─────────────────────
 *   GET  ?resource=watermarks
 *       → { items, customers, settings, serverTime }
 *     Marca de última modificación por sección. El POS la consulta primero
 *     (barato) y decide, comparando contra su propia marca de agua local,
 *     qué secciones pedir por delta.
 *
 *   POST ?section=items      body: { since: string|null }
 *       → { items: [...], deletedIds: [...], full: bool, serverTime }
 *   POST ?section=customers  body: { since: string|null }
 *       → { customers: [...], deletedIds: [...], full: bool, serverTime }
 *
 *   Reemplaza, para el catálogo grande (5000+ items / 10000+ clientes), el
 *   patrón "mandame TODOS mis ids" de arriba por "dame lo que cambió desde
 *   tal fecha" — no requiere que el cliente enumere su cache completa en
 *   cada chequeo, y trae también los CREADOS/MODIFICADOS, no solo borrados.
 *
 *   `full=true` (items/deletedIds SIEMPRE vacíos en ese caso) cuando `since`
 *   es null o excede la ventana de retención de lápidas (90 días, mig 138) —
 *   el caller DEBE hacer un bootstrap completo, no confiar en un delta que
 *   no puede garantizar cobertura de borrados. Guarda de borde adicional: si
 *   el delta trae más filas que `SyncService::MAX_REASONABLE_ROWS`, también
 *   cae a `full` (más barato recargar todo que aplicar un merge gigante).
 *
 *   Por qué POST para items/customers y no GET: mismo criterio que
 *   `?resource=bulk-get` de items.php/contacts.php — es una LECTURA, pero un
 *   POST evita que un browser/proxy intermedio la cachee (el dispositivo
 *   siempre recibe el estado fresco). Nunca muta nada — excluido de
 *   `realtimeAfterMutation` en bootstrap.php.
 *
 * Auth: MULTI-REALM `apiAuthTenant(['panel','pos-app'])`. companyId SIEMPRE
 * del token — nunca del body.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/services/SyncService.php';
require_once __DIR__ . '/../lib/Items/ItemsQuery.php';
require_once __DIR__ . '/../lib/Sync/SyncService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\SyncService as LegacyIdSyncService;
use Punto\Api\Sync\SyncService as IncrementalSyncService;
use Punto\Api\Contacts\ContactService;
use Punto\Api\Contacts\ContactRepository;

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');
$section  = (string) ($_GET['section'] ?? '');

global $db;

// ── Sync incremental (context/43) ───────────────────────────────────────────
if ($resource === 'watermarks') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /sync resource=watermarks (usar GET)', 405);
    }
    $sync = new IncrementalSyncService($db);
    apiOk($sync->watermarks($companyId));
}

if ($section === 'items') {
    if ($method !== 'POST') {
        apiError('Method not allowed for /sync section=items (usar POST)', 405);
    }
    $body  = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $since = isset($body['since']) && $body['since'] !== '' ? (string) $body['since'] : null;
    $sync  = new IncrementalSyncService($db);
    apiOk($sync->itemsDelta($companyId, $since));
}

if ($section === 'customers') {
    if ($method !== 'POST') {
        apiError('Method not allowed for /sync section=customers (usar POST)', 405);
    }
    $body  = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $since = isset($body['since']) && $body['since'] !== '' ? (string) $body['since'] : null;
    $sync           = new IncrementalSyncService($db);
    $contactService = new ContactService(new ContactRepository($db));
    apiOk($sync->customersDelta($companyId, $since, $contactService));
}

// ── Legacy Slice 8 (check por lista de ids) — sin cambios de comportamiento ─
// El endpoint completo ahora autentica `apiAuthTenant(['panel','pos-app'])`
// (lo necesitan las ramas nuevas de arriba, mismo criterio multi-realm que
// items.php/contacts.php), pero esta rama LEGACY seguía siendo
// `apiAuthTenant()` default (solo `pos-app`) antes de esta fusión — guard
// explícito acá para no ampliar en silencio quién puede llamarla.
if (in_array($resource, ['items', 'customers'], true)) {
    if (($ctx['realm'] ?? '') !== 'pos-app') {
        apiError('resource=items|customers (legacy) es exclusivo del realm pos-app', 403);
    }
    if ($method !== 'POST') {
        apiError('Método no permitido', 405);
    }
    $legacySvc = new LegacyIdSyncService(TenantContext::fromAuth($ctx));
    $ids       = $_POST['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    if ($resource === 'items') {
        apiOk($legacySvc->deletedItems($companyId, $ids));
    }
    apiOk($legacySvc->deletedCustomers($companyId, $ids));
}

apiError('Sub-recurso no soportado (resource=watermarks|items|customers, section=items|customers)', 400);
