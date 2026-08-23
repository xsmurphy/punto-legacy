<?php
/**
 * REST canónico (API compartida /api) — Contactos (clientes/proveedores, type=1).
 *
 *   GET    /v1/contacts                          → lista paginada (q, status, limit, offset)
 *   GET    /v1/contacts?id=<uuid>                → detalle
 *   GET    /v1/contacts?id=<uuid>&resource=addresses → sub-recurso direcciones
 *   GET    /v1/contacts?resource=taxpayer&ruc=<ruc>  → datos del contribuyente en el padrón
 *   POST   /v1/contacts                          → crea (body: { fiscalName|name, tin, ci, phone... })
 *   PUT    /v1/contacts?id=<uuid>                → update parcial (body: { campos... })
 *   DELETE /v1/contacts?id=<uuid>                → archive (soft-delete: contactStatus = 0)
 *
 * Auth: MULTI-REALM — `apiAuthTenant(['panel','pos-app'])`. Contactos son recursos compartidos:
 * el panel administra el catálogo de clientes y el POS también necesita crearlos / consultarlos
 * en la caja. El plan F2 marca este endpoint como el primero con allowlist multi-realm.
 *
 * Tenant: COMPANY_ID del JWT (panel u POS — ambos lo traen). Lectura paginada con cap a 1000.
 * Escritura sin role-guard en este nivel — el panel local todavía aplica `allowUser('contacts',
 * 'edit')` en a_contacts.php legacy; cuando migre el front estático, el guard de role se mueve acá.
 *
 * Respuesta: envelope canónico { ok, data } / { ok:false, error }.
 *
 * Port FIEL de panel/API/v1/contacts.php (Fase 2 del desacople de /panel). Cambios respecto
 * al original: `apiMiddleware()` → `apiAuthTenant(['panel','pos-app'])`; service en namespace
 * `Punto\Api\Contacts`; `apiNotFound()` / `apiUnprocessable()` → `apiError(..., 404/422)` (en /api
 * solo existen apiOk + apiError).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);

global $db;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;
$resource = $_GET['resource'] ?? null;

$service = new \Punto\Api\Contacts\ContactService(new \Punto\Api\Contacts\ContactRepository($db));

// ── Gate de autorización ────────────────────────────────────────────────────
// `contact` es UNA tabla con tres roles (type 0=empleado, 1=cliente,
// 2=proveedor) y el catálogo tiene una familia de permisos POR ROL. Gatear
// solo por método no alcanza: el permiso depende de QUÉ contacto es, así que
// el gate se resuelve con (type, operación).
//
// type=0 está contemplado a propósito: `getById()`, `update()` y `archive()`
// NO filtran por type (ver ContactService::getById), así que sin esto
// cualquiera con `contacts.customer.edit` podía editar o archivar un EMPLEADO
// por este endpoint, salteándose el gate `contacts.user.manage` de
// /v1/users. Mismo recurso, mismo permiso, sin importar por qué puerta entre.
//
// Realm `pos-app`: la sesión del device se emite con roleId='1'
// (DeviceAuth::buildToken) → seed `owner` → estos gates siempre pasan en la
// caja y no pueden romper el mostrador. Son efectivos hoy para el realm
// `panel` (rol real del operador).

/** @param 'view'|'create'|'edit'|'delete' $op */
function contactsPermFor(int $type, string $op): string
{
    if ($type === 0) {
        // El catálogo no separa create/edit/delete para empleados.
        return $op === 'view' ? 'contacts.user.view' : 'contacts.user.manage';
    }
    if ($type === \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER) {
        // Idem proveedores: una sola clave de gestión.
        return $op === 'view' ? 'contacts.supplier.view' : 'contacts.supplier.manage';
    }
    return 'contacts.customer.' . $op;
}

function contactsRequire(int $type, string $op): void
{
    $perm = contactsPermFor($type, $op);
    if (!hasPermission($perm)) {
        apiError("No tenés permiso para esta acción (requiere: $perm)", 403);
    }
}

/**
 * type del contacto existente, o null si no existe / no es del tenant.
 *
 * Devuelve null en vez de cortar 404 a propósito: el gate tiene que correr
 * ANTES de admitir que el id existe o no. Si el 404 saliera primero, este
 * endpoint sería un oráculo de existencia — cualquiera sin permiso podría
 * distinguir un id real (404) de uno inventado (403) y enumerar la cartera de
 * clientes del comercio. El caller gatea con el type resuelto (o el default) y
 * recién después responde 404.
 */
function contactsTypeOrNull(\Punto\Api\Contacts\ContactService $service, string $id): ?int
{
    $row = $service->getById($id, COMPANY_ID);
    if ($row === null) {
        return null;
    }
    return (int) ($row['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
}

/** Gatea por el type del contacto (default cliente si no existe) y luego 404. */
function contactsRequireExisting(\Punto\Api\Contacts\ContactService $service, string $id, string $op): void
{
    $type = contactsTypeOrNull($service, $id);
    contactsRequire($type ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, $op);
    if ($type === null) {
        apiError('Contacto no encontrado', 404);
    }
}

// ── Bulk-get quirúrgico (sync realtime, context/15) ────────────────────────
// POST /v1/contacts?resource=bulk-get  body: { ids: [uuid, ...], type? }
// Mismo criterio que /v1/items?resource=bulk-get: POST porque la lista de
// ids puede ser grande (bulk edit de miles de clientes) y para que ni el
// browser ni un proxy intermedio lo cacheen. companyId SIEMPRE del JWT —
// un id ajeno al tenant no aparece en el resultado. Sin filtrar por status:
// un contacto archivado sí viaja (el caller decide sacarlo del store).
//
// `type` SÍ filtra (default TYPE_CUSTOMER, mismo default que el resto del
// endpoint) — sin esto un proveedor editado en el panel dispara el mismo
// evento genérico 'contact' y el sync de clientes del POS lo mergeaba en su
// store de CLIENTES como si fuera uno (fuga de datos, hallazgo de code
// review 2026-08-16, ver ContactRepository::getManyByIds).
if ($resource === 'bulk-get') {
    if ($method !== 'POST') {
        apiError('Method not allowed for /contacts resource=bulk-get (usar POST)', 405);
    }
    $rawBody = json_decode(file_get_contents('php://input'), true);
    $rawIds  = is_array($rawBody['ids'] ?? null) ? $rawBody['ids'] : [];
    $ids = array_values(array_unique(array_filter(
        array_map(static fn($v) => trim((string) $v), $rawIds),
        static fn($v) => $v !== ''
    )));
    $bulkType = (int) ($rawBody['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
    if (!in_array($bulkType, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
        $bulkType = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
    }
    contactsRequire($bulkType, 'view');
    if (empty($ids)) {
        apiOk(['contacts' => []]);
    }
    // Techo de borde — ver mismo comentario en /v1/items?resource=bulk-get.
    $ids = array_slice($ids, 0, 2000);
    apiOk(['contacts' => $service->getManyByIds($ids, COMPANY_ID, $bulkType)]);
}

// ── Sub-recurso: direcciones ───────────────────────────────────────────────
if ($id !== null && $resource === 'addresses') {
    if ($method === 'GET') {
        contactsRequireExisting($service, $id, 'view');
        apiOk(['addresses' => $service->addresses($id, COMPANY_ID)]);
    }
    apiError('Method not allowed for /contacts/addresses', 405);
}

// ── Lookup de RUC en el padrón (F3 de facturación electrónica) ─────────────
// No lleva $id: es una consulta previa al alta, todavía no hay contacto. Va
// acá y no bajo /v1/einvoice porque sirve a CUALQUIER comercio (el que no
// tiene FE conectada cae al padrón público) y porque el consumidor es el
// formulario de contactos, en panel y en POS — los dos realms que este
// endpoint ya autentica.
if ($resource === 'taxpayer') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /contacts?resource=taxpayer', 405);
    }
    // Todavía no hay contacto, así que no hay type contra el cual resolver:
    // alcanza con poder dar de alta alguno de los dos roles.
    if (!hasPermission('contacts.customer.create') && !hasPermission('contacts.supplier.manage')) {
        apiError('No tenés permiso para esta acción (requiere: contacts.customer.create)', 403);
    }
    $ruc = trim((string) ($_GET['ruc'] ?? ''));
    if ($ruc === '') {
        apiError('Falta el RUC a consultar', 422);
    }
    $taxpayer = (new \Punto\Api\Contacts\TaxpayerLookupService())->lookup(COMPANY_ID, $ruc);
    if ($taxpayer === null) {
        apiError('No se encontraron datos para ese RUC', 404);
    }
    apiOk($taxpayer);
}

// ── Sub-recurso: analytics (KPIs + comportamiento del contacto) ────────────
// Alimenta el tab "Resumen" / "Comportamiento" del perfil en frontend.
// type=1 (cliente, default) o type=2 (proveedor) — cambia el set de tx_types
// agregados (venta vs compra) dentro del Service.
if ($id !== null && $resource === 'analytics') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /contacts/analytics', 405);
    }
    $aType = (int) ($_GET['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
    if (!in_array($aType, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
        $aType = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
    }
    contactsRequire($aType, 'view');
    require_once __DIR__ . '/../lib/Contacts/ContactAnalyticsService.php';
    $svc = new \Punto\Api\Contacts\ContactAnalyticsService();
    $data = $svc->compute($id, $aType, COMPANY_ID);
    if ($data === null) {
        apiError('Contacto no encontrado', 404);
    }
    apiOk($data);
}

// ── Sub-recurso: statement (estado de cuenta — detalle de deuda/cobros) ────
// Alimenta el tab "Financiero" del perfil: cada factura a crédito abierta con
// su saldo y qué recibos se le aplicaron (un recibo puede repartirse entre
// varias facturas). Misma fuente de saldo que `analytics.financial.
// openInvoices` — `OpenInvoicesService::contactStatement()`, no una segunda
// fórmula (ver docblock del Service).
if ($id !== null && $resource === 'statement') {
    if ($method !== 'GET') {
        apiError('Method not allowed for /contacts/statement', 405);
    }
    $sType = (int) ($_GET['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
    if (!in_array($sType, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
        $sType = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
    }
    contactsRequire($sType, 'view');
    // Confirma pertenencia al tenant antes de calcular (mismo guard que el detalle por id).
    if ($service->getById($id, COMPANY_ID) === null) {
        apiError('Contacto no encontrado', 404);
    }
    $svc  = new \Punto\Api\Reports\OpenInvoicesService();
    $data = $svc->contactStatement($id, COMPANY_ID, $sType === \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
    apiOk($data);
}

// ── JSON body → $_POST (el front manda JSON, PHP no lo parsea automáticamente) ──
$_raw = file_get_contents('php://input');
if (is_string($_raw) && $_raw !== '') {
    $_json = json_decode($_raw, true);
    if (is_array($_json)) {
        $_POST = array_merge($_POST, $_json);
    }
}

// ── Recurso principal ───────────────────────────────────────────────────────
// Defense-in-depth: cada case termina por apiOk/apiError (que llaman exit), así que el
// fall-through no ocurre HOY — pero un break; en cada case previene un fall-through silente
// si un futuro edit agrega una branch no-terminante.
switch ($method) {
    case 'GET':
        // type: 1 = cliente (default), 2 = proveedor. El front pasa ?type=2
        // para el tab proveedores. Valores fuera de [1, 2] caen a cliente.
        $type = (int) ($_GET['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
        if (!in_array($type, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
            $type = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
        }

        if ($id !== null) {
            // Detalle por id NO filtra por type: es "este contacto", no "este
            // contacto si matchea el tab actual". Filtrar acá hacía que un
            // proveedor recién creado devolviera 404 al abrir su detalle
            // (el front no siempre manda ?type=2), dando la falsa impresión
            // de que el alta no se había guardado.
            $contact = $service->getById($id, COMPANY_ID);
            if ($contact === null) apiError('Contacto no encontrado', 404);
            // El type sale de la FILA, no del ?type= del caller: el detalle
            // por id no filtra por type, así que el permiso tiene que
            // corresponder al contacto que realmente se está devolviendo.
            contactsRequire((int) ($contact['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER), 'view');
            apiOk($contact);
        }

        contactsRequire($type, 'view');
        apiOk($service->listByType($type, COMPANY_ID, [
            'q'      => $_GET['q']      ?? null,
            'status' => $_GET['status'] ?? null,
            'limit'  => $_GET['limit']  ?? 50,
            'offset' => $_GET['offset'] ?? 0,
        ]));
        break;

    case 'POST':
        // Mismo clamp que ContactService::create() — el gate tiene que
        // evaluar el type que REALMENTE se va a insertar, no el crudo.
        $newType = (int) ($_POST['type'] ?? \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER);
        if (!in_array($newType, [\Punto\Api\Contacts\ContactService::TYPE_CUSTOMER, \Punto\Api\Contacts\ContactService::TYPE_SUPPLIER], true)) {
            $newType = \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER;
        }
        contactsRequire($newType, 'create');
        try {
            $newId = $service->create(COMPANY_ID, $_POST);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 500);
        }

        $contact = $service->getById($newId, COMPANY_ID);
        apiOk($contact ?? ['id' => $newId, 'UID' => $newId], 201);
        break;

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);
        contactsRequireExisting($service, $id, 'edit');

        $patch = $_POST;
        unset($patch['id'], $patch['contactId'], $patch['companyId'], $patch['type']);
        if (empty($patch)) apiError('Patch vacío', 422);

        try {
            $updated = $service->update($id, COMPANY_ID, $patch);
        } catch (\InvalidArgumentException $e) {
            // Ej.: idType fuera de la Tabla 3 de la SET (ContactService::ID_TYPES).
            apiError($e->getMessage(), 422);
        }
        if (!$updated) {
            apiError('Update falló', 500);
        }

        $contact = $service->getById($id, COMPANY_ID);
        apiOk($contact ?? ['id' => $id, 'UID' => $id]);
        break;

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        // Archivar un contacto no es una operación de mostrador: el POS no
        // tiene UI de borrado y su token es eterno. Restringido al panel,
        // donde el rol del operador es real (en pos-app el gate de abajo
        // pasaría siempre por el roleId='1' del device).
        if (($ctx['realm'] ?? '') !== 'panel') {
            apiError('Archivar contactos solo está disponible desde el panel', 403);
        }
        contactsRequireExisting($service, $id, 'delete');
        if (!$service->archive($id, COMPANY_ID)) {
            apiError('Archive falló', 500);
        }
        apiOk(['archived' => true, 'id' => $id]);
        break;

    default:
        apiError('Method not allowed', 405);
}
