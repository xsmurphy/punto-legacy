<?php
/**
 * POST /v1/ai/execute
 *
 * Ejecuta una acción previamente confirmada mediante un token.
 * El token se consume (se elimina de Redis) en el proceso — no puede reutilizarse.
 *
 * Body JSON: { confirmToken }
 *
 * Response: { ok: true, data: { action, result } }
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once API_APP_DIR . '/includes/ai_confirm_store.php';

$ctx = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

// $_POST viene ya hidratado del JSON body por bootstrap.php (todos los verbos
// no-form-encoded). No re-parseamos acá.
$confirmToken = trim((string) ($_POST['confirmToken'] ?? ''));
if (strlen($confirmToken) !== 32) {
    apiError('confirmToken inválido', 400);
}

$stored = aiConfirmStoreConsume($confirmToken, $companyId);
if ($stored === null) {
    apiError('Token expirado, inválido o ya utilizado', 410);
}

$payload = $stored['payload'] ?? [];
$action  = (string) ($payload['action'] ?? '');

global $db;

try {
    switch ($action) {

        case 'create_contact': {
            $svc   = new \Punto\Api\Contacts\ContactService(
                new \Punto\Api\Contacts\ContactRepository($db)
            );
            $newId = $svc->create($companyId, [
                'name'  => $payload['name']  ?? '',
                'type'  => (int) ($payload['type'] ?? 1),
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
                'note'  => $payload['note']  ?? null,
            ]);
            apiOk(['action' => $action, 'result' => ['id' => $newId]]);
            break;
        }

        case 'update_contact': {
            $svc = new \Punto\Api\Contacts\ContactService(
                new \Punto\Api\Contacts\ContactRepository($db)
            );
            $patch = [];
            if (isset($payload['name']))  $patch['name']  = $payload['name'];
            if (isset($payload['phone'])) $patch['phone'] = $payload['phone'];
            if (isset($payload['email'])) $patch['email'] = $payload['email'];
            if (isset($payload['note']))  $patch['note']  = $payload['note'];
            $svc->update((string) $payload['id'], $companyId, $patch);
            apiOk(['action' => $action, 'result' => ['id' => $payload['id']]]);
            break;
        }

        case 'create_item': {
            $kind = $payload['kind'] ?? 'producto';
            // Tanto producto como servicio son itemType='product' en el legacy
            // (ver map en ItemImporter::legacyFlagsForKind). El kind discrimina
            // visualmente y para reglas de stock; el itemType legacy no.
            $itemTypeLegacy = 'product';

            // Resolver categoryName → categoryId
            $categoryId = null;
            if (!empty($payload['categoryName'])) {
                $catRow = ncmExecute(
                    'SELECT categoryId FROM category WHERE companyId = ? AND name ILIKE ? LIMIT 1',
                    [$companyId, trim((string) $payload['categoryName'])]
                );
                if ($catRow) {
                    $categoryId = $catRow['categoryid'] ?? $catRow['categoryId'] ?? null;
                }
            }

            // Resolver brandName → brandId
            $brandId = null;
            if (!empty($payload['brandName'])) {
                $brandRow = ncmExecute(
                    'SELECT brandId FROM brand WHERE companyId = ? AND name ILIKE ? LIMIT 1',
                    [$companyId, trim((string) $payload['brandName'])]
                );
                if ($brandRow) {
                    $brandId = $brandRow['brandid'] ?? $brandRow['brandId'] ?? null;
                }
            }

            $svc   = new \Punto\Api\Items\ItemService(
                new \Punto\Api\Items\ItemRepository($db)
            );
            $newId = $svc->createBlank($companyId, $itemTypeLegacy, $kind);
            if ($newId === false) {
                apiError('No se pudo crear el ítem', 500);
            }

            $patch = ['itemName' => $payload['name'] ?? ''];
            if (isset($payload['price']))    $patch['itemPrice']    = (float) $payload['price'];
            if (isset($payload['cost']))     $patch['itemCost']     = (float) $payload['cost'];
            if (isset($payload['sku']))      $patch['sku']          = $payload['sku'];
            if ($categoryId !== null)        $patch['categoryId']   = $categoryId;
            if ($brandId !== null)           $patch['brandId']      = $brandId;

            $svc->update((string) $newId, $companyId, $patch);
            apiOk(['action' => $action, 'result' => ['id' => (string) $newId]]);
            break;
        }

        case 'update_item_price': {
            $svc = new \Punto\Api\Items\ItemService(
                new \Punto\Api\Items\ItemRepository($db)
            );
            $svc->update((string) $payload['id'], $companyId, [
                'itemPrice' => (float) $payload['newPrice'],
            ]);
            apiOk(['action' => $action, 'result' => ['id' => $payload['id']]]);
            break;
        }

        case 'create_user': {
            $roleName = trim((string) ($payload['roleName'] ?? ''));
            if ($roleName === '') {
                apiError('roleName requerido', 422);
            }
            $roleNameLower = strtolower($roleName);
            // Bloqueo de roles admin desde el agente (defense in depth — el
            // alcance del agente es operativo, ver [[ai-agent-scope-limits]]).
            if (in_array($roleNameLower, ['super admin', 'admin', 'administrador'], true)) {
                apiError('Role admin no permitido desde el agente', 403);
            }

            $svc    = new \Punto\Api\Users\UsersService();
            $roleId = null;
            foreach ($svc->roles($companyId) as $r) {
                if (strtolower((string) $r['name']) === $roleNameLower) {
                    $roleId = $r['id'];
                    break;
                }
            }
            if ($roleId === null) {
                // No crear usuarios huérfanos sin rol — error explícito para
                // que el agente pueda informar y reintentar con un role válido.
                apiError("Role '$roleName' no existe en el tenant", 422);
            }

            // Contraseña temporal server-side: 16 chars hex = 64 bits de entropy.
            // Se devuelve al operador (sesión autenticada del propio admin) en la
            // response para que pueda dictarla al nuevo usuario. NO se loguea ni
            // se persiste en el chat history server-side. El operador la copia y
            // se la entrega offline.
            $tempPass = bin2hex(random_bytes(8));

            $newId = $svc->create($companyId, [
                'name'     => $payload['name'],
                'phone'    => $payload['phone'] ?? null,
                'password' => $tempPass,
                'roleId'   => $roleId,
            ]);
            apiOk([
                'action' => $action,
                'result' => [
                    'id'           => $newId,
                    'tempPassword' => $tempPass,
                    'message'      => 'Usuario creado. Pasale esta contraseña: ' . $tempPass . ' (no se mostrará otra vez).',
                ],
            ]);
            break;
        }

        case 'create_category': {
            $svc   = new \Punto\Api\Categories\CategoryService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            apiOk(['action' => $action, 'result' => ['id' => $newId]]);
            break;
        }

        case 'create_brand': {
            $svc   = new \Punto\Api\Brands\BrandService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            apiOk(['action' => $action, 'result' => ['id' => $newId]]);
            break;
        }

        case 'create_tag': {
            $svc   = new \Punto\Api\Tags\TagService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            apiOk(['action' => $action, 'result' => ['id' => $newId]]);
            break;
        }

        default:
            apiError('Acción no reconocida: ' . $action, 400);
    }

} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    error_log('[ai/execute] RuntimeException: ' . $e->getMessage());
    apiError('Error ejecutando la acción', 500);
}
