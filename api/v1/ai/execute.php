<?php
/**
 * POST /v1/ai/execute
 *
 * Ejecuta el LOTE de acciones previamente confirmado mediante un token.
 * El token se consume (se elimina de Redis) en el proceso — no puede reutilizarse.
 *
 * Body JSON: { confirmToken }
 *
 * Response: { ok: true, data: { results: [{action, ok, error?, data?}], okCount, failCount } }
 *
 * Un fallo en una acción del lote NO aborta las demás — cada acción se ejecuta
 * de forma independiente y su resultado (éxito o error) se reporta por separado.
 *
 * Auth: realms `panel` y `pos-app`. En la caja la autorización NO sale de la
 * credencial sino del operador que probó su PIN — ver `AgentActor`.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once API_APP_DIR . '/includes/ai_confirm_store.php';
require_once dirname(__DIR__, 2) . '/lib/Ai/AgentActor.php';

// Realm `pos-app` (context/59, pedido del owner 2026-08-31): el asistente de la
// caja pasa a poder ESCRIBIR, pero solo lo que el rol del OPERADOR permita.
// Quién es esa persona y qué puede lo resuelve `AgentActor` — el mismo objeto
// que usa `confirm.php`, para que las dos mitades de la operación no puedan
// diverger. Bajo `pos-app` exige `OperatorAssertion`: sin PIN validado no hay
// escritura, y el Bearer eterno del dispositivo no alcanza por sí solo.
$ctx = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];

$actor  = \Punto\Api\Ai\AgentActor::authorize($ctx);
// El autor de lo que se escriba es el ACTOR, no la credencial: en la caja, el
// `userId` del contexto es el contacto que pareó la tablet hace meses, y
// atribuirle a él los cambios de todos los turnos es un bug silencioso.
$userId = $actor->userId();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed', 405);
}

// $_POST viene ya hidratado del JSON body por bootstrap.php (todos los verbos
// no-form-encoded). No re-parseamos acá.
$confirmToken = trim((string) ($_POST['confirmToken'] ?? ''));
if (strlen($confirmToken) !== 32) {
    apiError('confirmToken inválido', 400);
}

// El token se ata a QUIEN lo pidió, no solo al tenant: una tablet la desbloquean
// tres personas por día, y un lote registrado por el encargado no lo ejecuta el
// mozo que tipeó su PIN después.
$stored = aiConfirmStoreConsume($confirmToken, $companyId, $userId);
if ($stored === null) {
    apiError('Token expirado, inválido o ya utilizado', 410);
}

$storedPayload = $stored['payload'] ?? [];
$actions       = $storedPayload['actions'] ?? null;

if (!is_array($actions) || count($actions) < 1) {
    apiError('El token no contiene acciones ejecutables', 422);
}

/**
 * Ejecuta UNA acción ya confirmada y devuelve su resultado de dominio.
 * Lanza InvalidArgumentException/RuntimeException en error — el caller
 * (loop del lote) las captura para no abortar las acciones restantes.
 *
 * @return array Resultado de dominio (shape depende de $action).
 */
function aiExecuteRunAction(string $action, array $payload, string $companyId, string $userId, $db): array
{
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
            realtimePublish('contact', 'create', (string) $newId);
            return ['id' => $newId];
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
            realtimePublish('contact', 'update', (string) $payload['id']);
            return ['id' => $payload['id']];
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
                throw new \RuntimeException('No se pudo crear el ítem');
            }

            $patch = ['itemName' => $payload['name'] ?? ''];
            if (isset($payload['price']))    $patch['itemPrice']    = (float) $payload['price'];
            if (isset($payload['cost']))     $patch['itemCost']     = (float) $payload['cost'];
            if (isset($payload['sku']))      $patch['sku']          = $payload['sku'];
            if ($categoryId !== null)        $patch['categoryId']   = $categoryId;
            if ($brandId !== null)           $patch['brandId']      = $brandId;

            $svc->update((string) $newId, $companyId, $patch);
            realtimePublish('item', 'create', (string) $newId);
            return ['id' => (string) $newId];
        }

        case 'update_item_price': {
            $svc = new \Punto\Api\Items\ItemService(
                new \Punto\Api\Items\ItemRepository($db)
            );
            $svc->update((string) $payload['id'], $companyId, [
                'itemPrice' => (float) $payload['newPrice'],
            ]);
            realtimePublish('item', 'update', (string) $payload['id']);
            return ['id' => $payload['id']];
        }

        case 'create_user': {
            $roleName = trim((string) ($payload['roleName'] ?? ''));
            if ($roleName === '') {
                throw new \InvalidArgumentException('roleName requerido');
            }
            $roleNameLower = strtolower($roleName);
            // Bloqueo de roles admin desde el agente (defense in depth — el
            // alcance del agente es operativo, ver [[ai-agent-scope-limits]]).
            if (in_array($roleNameLower, ['super admin', 'admin', 'administrador'], true)) {
                throw new \InvalidArgumentException('Role admin no permitido desde el agente');
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
                throw new \InvalidArgumentException("Role '$roleName' no existe en el tenant");
            }

            // MISMA regla anti-escalación que /v1/users (RoleEscalation, lib/Auth).
            // La lista negra de nombres de arriba no alcanza y nunca alcanzó:
            // bloquea 'super admin'/'admin'/'administrador' pero NO "Dueño",
            // que es el nombre del seed owner — o sea que pedirle al agente un
            // usuario con rol Dueño fabricaba un co-dueño del comercio. El
            // guard compara SETS de permisos contra el rol del operador, así
            // que no depende de acertarle a ningún nombre.
            require_once dirname(__DIR__, 2) . '/lib/Auth/RoleEscalation.php';
            RoleEscalation::guardOrThrow((string) $roleId, $companyId, 'crear un usuario con');

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
            // Datos estructurados (sin frases instructivas): el formato de
            // presentación lo dicta el system prompt del route handler para que
            // sea consistente y el agente pueda construir el bloque exacto.
            // El client implementa autoexpiración real a los 60s editando el
            // mensaje + redactando el password antes de persistir en localStorage.
            realtimePublish('user', 'create', (string) $newId);
            return [
                'id'              => $newId,
                'tempPassword'    => $tempPass,
                'userDisplayName' => $payload['name'],
                'login'           => $payload['phone'] ?? $payload['name'],
                'roleName'        => $roleName,
            ];
        }

        case 'create_category': {
            $svc   = new \Punto\Api\Categories\CategoryService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            realtimePublish('category', 'create', (string) $newId);
            return ['id' => $newId];
        }

        case 'create_brand': {
            $svc   = new \Punto\Api\Brands\BrandService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            realtimePublish('brand', 'create', (string) $newId);
            return ['id' => $newId];
        }

        case 'create_tag': {
            $svc   = new \Punto\Api\Tags\TagService($db);
            $newId = $svc->create($companyId, ['name' => $payload['name']]);
            realtimePublish('tag', 'create', (string) $newId);
            return ['id' => $newId];
        }

        case 'tabular_import': {
            $kind      = (string) ($payload['kind']      ?? '');
            $sessionId = (string) ($payload['sessionId'] ?? '');
            $mode      = (string) ($payload['mode']      ?? 'insert');
            $mapping   = isset($payload['mapping']) && is_array($payload['mapping'])
                ? $payload['mapping']
                : null;

            $result = \Punto\Api\Imports\ImportSession::run(
                $sessionId, $kind, $mapping, $mode, $companyId, $userId
            );

            if (!($result['ok'] ?? false)) {
                throw new \InvalidArgumentException($result['error'] ?? 'Error en importación');
            }

            if ($kind === 'contacts') {
                realtimePublish('contact', 'import', 'batch');
            } else {
                realtimePublish('item', 'import', 'batch');
            }

            return $result['report'] ?? [];
        }

        default:
            throw new \InvalidArgumentException('Acción no reconocida: ' . $action);
    }
}

global $db;

$results  = [];
$okCount  = 0;
$failCount = 0;

foreach ($actions as $payload) {
    if (!is_array($payload)) {
        $results[] = ['action' => null, 'ok' => false, 'error' => 'Acción del lote inválida'];
        $failCount++;
        continue;
    }

    $action = (string) ($payload['action'] ?? '');

    // Acción fuera de alcance para esta superficie (hoy: `create_user` desde la
    // caja). Se vuelve a chequear acá y no solo en `confirm` porque un token
    // sigue vivo 5 minutos: podría haberse registrado antes de un cambio de
    // política, o desde otra superficie.
    if (!$actor->allowsAction($action)) {
        $results[] = [
            'action' => $action,
            'ok'     => false,
            'error'  => 'Esa acción no se puede hacer desde la caja — se hace desde el panel',
        ];
        $failCount++;
        continue;
    }

    $requiredPerm = $actor->requiredPermission($action, $payload);
    if ($requiredPerm !== null && !$actor->can($requiredPerm)) {
        $results[] = [
            'action' => $action,
            'ok'     => false,
            'error'  => 'No tenés permiso para esta acción (requiere: ' . $requiredPerm . ')',
        ];
        $failCount++;
        continue;
    }

    try {
        $data = aiExecuteRunAction($action, $payload, $companyId, $userId, $db);
        $results[] = ['action' => $action, 'ok' => true, 'data' => $data];
        $okCount++;
    } catch (\InvalidArgumentException $e) {
        $results[] = ['action' => $action, 'ok' => false, 'error' => $e->getMessage()];
        $failCount++;
    } catch (\Punto\Api\Contacts\DuplicateContactException $e) {
        // ANTES del catch de RuntimeException (del que hereda): un choque de
        // documento o teléfono NO es una falla interna, es una respuesta útil
        // — dice con QUÉ contacto choca. Tragarlo en el "Error ejecutando la
        // acción" genérico deja al agente sin nada que contarle al usuario, y
        // encima manda a error_log algo que no es un incidente.
        $results[] = ['action' => $action, 'ok' => false, 'error' => $e->getMessage()];
        $failCount++;
    } catch (\RuntimeException $e) {
        error_log('[ai/execute] RuntimeException (' . $action . '): ' . $e->getMessage());
        $results[] = ['action' => $action, 'ok' => false, 'error' => 'Error ejecutando la acción'];
        $failCount++;
    }
}

apiOk(['results' => $results, 'okCount' => $okCount, 'failCount' => $failCount]);
