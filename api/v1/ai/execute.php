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
require_once dirname(__DIR__, 2) . '/lib/Ai/ContactPayload.php';
// Se carga arriba y no dentro del `case`: el `catch` del loop la nombra, y una
// clase que solo existe si esa rama corrió deja el catch sin matchear.
require_once dirname(__DIR__, 2) . '/lib/services/RegisterAdminException.php';

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
 * Resuelve un rol POR NOMBRE contra el catálogo REAL del tenant.
 * Compartido por `create_user` y `assign_role`: las dos acciones reciben
 * del modelo un nombre de rol en castellano y ninguna puede inventarse su
 * propia forma de resolverlo.
 *
 * El catálogo es `RoleService::getRoles()` — la tabla `taxonomy` con los roles
 * del comercio, seeds y customs. NO `UsersService::roles()`, que devuelve una
 * const de UN elemento ('Super Admin') y era lo que consultaba `create_user`:
 * con ese catálogo, cualquier rol real del tenant ("Cajero", "Encargado")
 * respondía "no existe en el tenant", y el único nombre que resolvía era
 * justo el que la lista negra de abajo rechaza. O sea que la acción no podía
 * terminar bien de ninguna manera. Se arregla acá, en el resolver compartido,
 * y no en cada `case`.
 *
 * Devuelve el rol CANÓNICO —id y nombre tal como los tiene el comercio—, no el
 * string que tipeó el modelo: lo que se le muestra al usuario después tiene que
 * ser el nombre real del rol, no su paráfrasis ("cajero" → "Cajero").
 *
 * @return array{id: string, name: string}
 * @throws \InvalidArgumentException si el rol es admin o no existe.
 */
function aiResolveRoleByName(string $roleName, string $companyId): array
{
    $roleName = trim($roleName);
    if ($roleName === '') {
        throw new \InvalidArgumentException('roleName requerido');
    }
    $roleNameLower = mb_strtolower($roleName);

    // Bloqueo de roles admin desde el agente (defense in depth — el alcance del
    // agente es operativo, ver [[ai-agent-scope-limits]]). La lista negra por
    // nombre no alcanza y nunca alcanzó (no incluye "Dueño"): el guard que de
    // verdad sostiene la regla es RoleEscalation, que compara SETS de permisos.
    // Esta se conserva porque corta antes y con un mensaje que el agente puede
    // repetirle al cliente sin hablar de permisos.
    if (in_array($roleNameLower, ['super admin', 'admin', 'administrador'], true)) {
        throw new \InvalidArgumentException('Role admin no permitido desde el agente');
    }

    require_once dirname(__DIR__, 2) . '/lib/Auth/RoleService.php';
    $disponibles = [];
    foreach (\RoleService::getRoles($companyId) as $r) {
        $nombre = (string) ($r['name'] ?? '');
        if (mb_strtolower($nombre) === $roleNameLower) {
            return ['id' => (string) $r['id'], 'name' => $nombre];
        }
        $disponibles[] = $nombre;
    }

    // El mensaje lista los roles que SÍ existen: de este error sale la
    // repregunta del bot al cliente ("¿cuál de estos?"), no solo el log.
    throw new \InvalidArgumentException(
        "Role '$roleName' no existe en el tenant" .
        ($disponibles !== [] ? '. Roles disponibles: ' . implode(', ', $disponibles) : '')
    );
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
            // Dirección default: NO se arma acá. `ContactService::create()`
            // ya crea el contacto Y su dirección default en el mismo paso
            // (mapToAddress + syncDefaultAddress); lo único que hacía falta
            // era pasarle los campos. Omitirlos es lo que llevó al agente a
            // contestarle al dueño que "el sistema no tiene campos de
            // dirección ni coordenadas" — una limitación de ESTA acción
            // presentada como una del producto. Las coordenadas se aplican
            // solo en par (ver mapToAddress); `/v1/ai/confirm` rechaza antes
            // el par incompleto para que el bot pueda repreguntar.
            $newId = $svc->create($companyId, [
                'name'     => $payload['name']  ?? '',
                'type'     => (int) ($payload['type'] ?? 1),
                'phone'    => $payload['phone'] ?? null,
                'email'    => $payload['email'] ?? null,
                'note'     => $payload['note']  ?? null,
                'address'  => $payload['address']  ?? null,
                'city'     => $payload['city']     ?? null,
                'location' => $payload['location'] ?? null,
                'lat'      => $payload['lat']      ?? null,
                'lng'      => $payload['lng']      ?? null,
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
            // `ContactService::update()` sincroniza la dirección default con el
            // MISMO mapper que create() (syncDefaultAddress), así que editar la
            // dirección desde el agente no necesita nada nuevo: alcanza con no
            // tirar los campos. Se copian solo los que vinieron — el patch es
            // parcial y un campo ausente NO debe borrar lo que ya está cargado.
            foreach (\Punto\Api\Ai\ContactPayload::ADDRESS_FIELDS as $addrField) {
                if (isset($payload[$addrField])) $patch[$addrField] = $payload[$addrField];
            }
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
            // `itemSKU`, NO `sku`: el patch viaja crudo al writer genérico
            // (`ItemService::update` → `ncmUpdate`), que rutea al JSONB `data`
            // cualquier clave que no sea columna. Con `sku` el valor se guardaba
            // sin error y quedaba invisible: no aparecía en el listado ni en la
            // búsqueda por SKU. Todas las otras claves de este patch ya son
            // nombres de columna; esta era la única que no.
            if (isset($payload['sku']))      $patch['itemSKU']      = $payload['sku'];
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
            // Resolución + lista negra de nombres admin: resolver compartido.
            // No crear usuarios huérfanos sin rol — lanza con un mensaje que el
            // agente puede usar para repreguntar con los roles que sí existen.
            $rol      = aiResolveRoleByName($roleName, $companyId);
            $roleId   = $rol['id'];
            $roleName = $rol['name'];

            // MISMA regla anti-escalación que /v1/users (RoleEscalation, lib/Auth).
            // La lista negra de nombres del resolver no alcanza y nunca alcanzó:
            // bloquea 'super admin'/'admin'/'administrador' pero NO "Dueño",
            // que es el nombre del seed owner — o sea que pedirle al agente un
            // usuario con rol Dueño fabricaba un co-dueño del comercio. El
            // guard compara SETS de permisos contra el rol del operador, así
            // que no depende de acertarle a ningún nombre.
            require_once dirname(__DIR__, 2) . '/lib/Auth/RoleEscalation.php';
            RoleEscalation::guardOrThrow($roleId, $companyId, 'crear un usuario con');

            $svc = new \Punto\Api\Users\UsersService();

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

        case 'assign_role': {
            $targetId = trim((string) ($payload['id'] ?? ''));

            // Nadie cambia su propio rol, ni siquiera para bajárselo: es la vía
            // más corta a la escalación y no tiene caso de uso legítimo. Va
            // PRIMERO, igual que en /v1/users PUT — si corriera después de los
            // guards de escalación, pedir "bajame a Cajero" respondería un
            // mensaje sobre permisos ajenos en vez del motivo real. Acá el "uno
            // mismo" es el ACTOR que le habla al agente ($userId ya es el
            // operador, no la credencial), así que la regla vale igual pedida
            // por chat que por el panel.
            if ($targetId === $userId) {
                throw new \InvalidArgumentException('No podés cambiar tu propio rol');
            }

            $rol    = aiResolveRoleByName((string) ($payload['roleName'] ?? ''), $companyId);
            $roleId = $rol['id'];

            $svc    = new \Punto\Api\Users\UsersService();
            $target = $svc->get($targetId, $companyId);
            if ($target === null) {
                throw new \InvalidArgumentException('Ese usuario no existe en el comercio');
            }

            require_once dirname(__DIR__, 2) . '/lib/Auth/RoleEscalation.php';
            // Los MISMOS dos guards que /v1/users PUT, en el mismo orden:
            //   1. el rol ACTUAL del target — sin esto un Encargado le cambia
            //      el rol al Dueño y se queda con el comercio;
            //   2. el rol que se va a asignar — sin esto se reparte hacia
            //      arriba lo que uno mismo no tiene.
            // Van los dos porque cubren direcciones distintas y ninguno
            // implica al otro.
            RoleEscalation::guardOrThrow((string) ($target['roleId'] ?? null), $companyId, 'editar un usuario con');
            RoleEscalation::guardOrThrow($roleId, $companyId, 'asignar');

            if (!$svc->update($targetId, $companyId, ['roleId' => $roleId])) {
                throw new \RuntimeException('No se pudo asignar el rol');
            }
            realtimePublish('user', 'update', $targetId);
            return [
                'id'              => $targetId,
                'userDisplayName' => $target['name'] ?? $targetId,
                'roleName'        => $rol['name'],
            ];
        }

        case 'create_outlet': {
            $name = trim((string) ($payload['name'] ?? ''));
            $svc  = new \Punto\Api\Outlets\OutletsService();
            // El servicio es el ÚNICO creador válido: la sucursal nace
            // encadenada a su depósito por defecto y a una caja inicial
            // (invariante del owner 2026-08-24, arnés
            // `outlet_chain_invariant_test.php`). Un INSERT desde acá dejaría
            // una sucursal en la que no se puede guardar stock ni abrir turno.
            $newId = $svc->create($companyId, ['name' => $name, 'status' => 1]);
            if (!$newId) {
                throw new \RuntimeException('No se pudo crear la sucursal');
            }
            // Sin `realtimePublish`: hoy NINGÚN camino de alta de sucursal
            // emite evento (tampoco el POST de /v1/outlets), así que el front
            // no tiene invalidación registrada para la entidad `outlet`.
            // Emitirlo solo desde el agente sería un evento que nadie escucha.
            return [
                'id'   => (string) $newId,
                'name' => $name,
                // Se declara para que el agente pueda contárselo al cliente en
                // vez de que aparezca una caja que nadie pidió.
                'note' => 'La sucursal se creó con su depósito y una caja inicial ("Nueva Caja").',
            ];
        }

        case 'create_register': {
            $name     = trim((string) ($payload['name'] ?? ''));
            $outletId = trim((string) ($payload['outletId'] ?? ''));

            // El modelo casi nunca tiene el uuid: resuelve por NOMBRE de
            // sucursal, mismo patrón que categoryName/brandName en create_item.
            if ($outletId === '') {
                $outletName = trim((string) ($payload['outletName'] ?? ''));
                $row = ncmExecute(
                    'SELECT outletId FROM outlet WHERE companyId = ? AND outletName ILIKE ? LIMIT 1',
                    [$companyId, $outletName]
                );
                $outletId = (string) ($row['outletId'] ?? $row['outletid'] ?? '');
                if ($outletId === '') {
                    throw new \InvalidArgumentException("La sucursal '$outletName' no existe en el comercio");
                }
            }

            require_once dirname(__DIR__, 2) . '/lib/services/RegisterAdminService.php';
            // TODA la validación fiscal es del servicio y NO se replica acá:
            // el par (timbrado, punto de expedición) tiene que ser único por
            // comercio porque dos cajas que lo compartan emiten facturas con
            // el mismo número, y eso es ilegal ante la SET (context/29). Una
            // segunda copia de esa regla en el ejecutor del agente es una
            // copia que se queda vieja. El servicio rechaza con
            // `RegisterAdminException` y su mensaje ya está escrito para que
            // lo lea el usuario: dice con qué caja choca y qué hacer.
            $svc = new \Punto\Api\Services\RegisterAdminService($companyId);
            $res = $svc->create($outletId, $name, [
                'fiscal' => [
                    'invoiceAuth'   => (string) ($payload['timbrado'] ?? ''),
                    'invoicePrefix' => (string) ($payload['expeditionPoint'] ?? ''),
                ],
            ]);
            // `create()` ya publica el evento de realtime.
            return [
                'id'              => (string) ($res['id'] ?? ''),
                'name'            => $name,
                'outletId'        => $outletId,
                'expeditionPoint' => (string) ($payload['expeditionPoint'] ?? ''),
            ];
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
    } catch (\Punto\Api\Services\RegisterAdminException $e) {
        // ANTES del catch de RuntimeException (del que hereda): las reglas de
        // caja —punto de expedición ocupado, nombre repetido, número que
        // pisaría una factura emitida— son respuestas de NEGOCIO con un
        // mensaje escrito para el usuario. Tragarlas en el "Error ejecutando
        // la acción" genérico dejaría al agente sin nada que contarle al
        // cliente justo en el error que más repregunta necesita, y mandaría a
        // error_log algo que no es un incidente.
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
