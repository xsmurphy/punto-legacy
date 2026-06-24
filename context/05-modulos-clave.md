<!-- REGLA: Actualizar cuando se agregue un módulo/servicio nuevo, cambie la responsabilidad
     de uno existente, o se agreguen endpoints relevantes. NO actualizar por bug fixes internos. -->

# 05 — Módulos Clave

## /app — Módulo Operativo (POS)

**Propósito**: Interfaz principal de operación diaria. Caja, facturación, mesas, órdenes,
delivery, calendario de citas, agendamientos.

**Entry point**: `app/index.php` (HTML + PHP template)

**Dispatcher principal**: ~~`app/action.php` (~143KB)~~ → **1685 líneas post-Slice 36 (2026-06-01) — ya NO es god node**
- El dispatcher de ~43+ acciones fue vaciado concern-por-concern (slices 1–36).
- **Queda únicamente el handler `processData`** (~1622 líneas) — fallback del strangler de ventas. En prod cubre type 0/3 casi al 100% vía SaleService; el legacy solo retiene parentId (edge raro).
- Setup (líneas 1–54): session, cors, JWT, company check, includes. Tail: checkExecTime + else→401.
- Ver `10-roadmap.md § action.php estado post-Slice 36` para el historial completo del vaciado.

**~~`app/load.php`~~ → ELIMINADO (`git rm`, Slice 43, commit cc02762, 2026-06-03)**
- Era el segundo dispatcher del POS (reads, APIs externas). 1714 líneas al inicio del trabajo (Slice 1).
- Vaciado progresivamente en Slices 1-43 migrando ~44 handlers al patrón BFF→API→Service.
- Última migración (Slice 43): `bancardQR`, `pixQR`, `verifyTransactionPix` → `BancardService` + `PixService`.
- El archivo fue eliminado con `git rm` una vez vacío de handlers. Ya NO existe en el repo.

**Desacople progresivo de /app al patrón Front→BFF→API→Service (iniciado 2026-05-28)**

El monolito `action.php`/`load.php` se migra concern-por-concern al mismo patrón de 3 capas que el panel. `action.php`/`load.php` siguen sirviendo los concerns no migrados.

**Slice 1 COMPLETO — `customerAddress` (commit d79cfa4, 2026-05-28)**:

| Capa | Archivo | Responsabilidad |
|------|---------|----------------|
| BFF | `app/bff/customer_address.php` | Decodifica `?l=` base64, rutea a `/api`, traduce al shape legacy del front |
| BFF lib | `app/bff/lib/api_client.php` | Cliente curl BFF→API compartida (`PUNTO_API_BASE`); reenvía cookie `_jwt` |
| API | `api/v1/customer_address.php` | Gateado por `apiAuthTenant()`; envelope canónico (movido a /api en d75dd0b) |
| API lib | `api/lib/response.php` | `apiOk()`/`apiError()` — envelope canónico compartido |
| Service | `api/lib/services/CustomerAddressService.php` | list/add/update/delete/setDefault; tenant-scoped; transacciones atómicas (movido a /api en d75dd0b) |
| Front | `app/scripts/app.js` (antes `debug.js` — unificado en Tier 3, 2026-05-30) | 5 call-sites de `ncmCustomer.address.*` repuntados a `/bff/customer_address?l=` |

**Gotcha crítico para TODOS los futuros slices de /app — `app/DB.php` sin `Insert_ID()`**:
`app/includes/lib/DB.php` (usado por /app) **divergió del panel** y NO tiene el método `Insert_ID()`. Por eso `ncmInsert()` y `ncmUpdate()` son **FATALES en /app** (llaman a `$db->Insert_ID()`). Reglas para slices /app:
- **Lecturas**: `ncmExecute` sigue funcionando bien.
- **Escrituras**: usar `$db->Execute($sql, $params)` / `$db->Insert($table, $params)` directamente, parametrizados — NUNCA `ncmInsert`/`ncmUpdate` en /app.
- **Multi-step**: envolver en `$db->StartTrans()` / `$db->CompleteTrans()` para atomicidad.

**Bugs PG que el legacy de /app tenía (corregidos en cada slice migrado)**:
- UUIDs interpolados sin comillas en WHERE (PG rechaza UUID sin quotes cuando `db_prepare` es no-op en /app)
- `DELETE ... LIMIT 1` (inválido en PG)
- Booleanos comparados/seteados con `1` en vez de `true`/`null` (ej. `customerAddressDefault` es columna BOOLEAN)
- Queries sin scope de `companyId` (violación §1 de aislamiento de tenant)

**API endpoints** (`app/API/`):
- `auth.php` — Login, emite JWT
- `config.php` — Configuración del tenant para el POS
- `refresh.php` — Refresh token
- `logout.php` — **NUEVO (commit 70dbc22, 2026-06-06)** POST-only. Cierra el ciclo del modelo device pairing del lado del usuario ("Eliminar Punto de este dispositivo"). Decode JWT desde cookie/header/POST; si trae `did`+`cid` válidos (regex UUID): `UPDATE device SET status=0, revokedAt=NOW(), revokedBy=<userId>` (doble guard tenant); llama `jwtInvalidateDeviceCache($did)` (efecto inmediato, no espera TTL 60s); mata cookie `_jwt` (setcookie con expires=1); responde `{ok:true}` incluso sin token (no leakea estado). GET → 405. Es POST-only para evitar CSRF hot-link por GET.
- ~~`v1/customer_address.php`~~ — **movido a `/api/v1/customer_address.php` (commit d75dd0b)**

**Nota (2026-05-28):** Los endpoints de los slices de desacople YA NO ESTÁN en `/app/API/v1/`. Fueron movidos a la API compartida `/api/v1/` (commit d75dd0b). `/app/API/` conserva `auth.php`, `config.php`, `refresh.php`, `logout.php`.

**Archivos clave**:
- `app/includes/functions.php` — Utilidades (pagos, formateo, roles)
- `app/includes/jwt.php` — JWT HS256 encode/decode
- `app/includes/jwt_middleware.php` — Validación de JWT (también usada por `/api/bootstrap.php` vía chdir). **Desde commit a3fefb4 (2026-06-06):** además de validar firma + realm, valida `device.status` si el JWT trae claim `did`. Cache file 60s. Expone `jwtIsDeviceRevoked()`, `jwtInvalidateDeviceCache()` y constante `AUTHED_DEVICE_ID`. Modo conservador si BD no disponible.
- `app/handoff.php` — **NUEVO (commit 01d02a3, 2026-06-09)**. Endpoint SSO que recibe un JWT corto emitido por el panel (TTL 60s, iss=`'pos-app'`), lo valida y re-emite una cookie HttpOnly `_jwt` de larga duración. Punto de entrada cuando el usuario presiona "Caja" en el sidebar del panel. Ver `02-arquitectura.md § SSO handoff panel→app`.
- `app/includes/phone.php` — **NUEVO (commit d828b02, 2026-06-09)**. Helper de libphonenumber: `phoneToE164($input, $iso): ?string` — parsea cualquier formato y devuelve E.164 o null. Ver §31 en `08-convenciones.md`.
- `panel/includes/phone.php` — **NUEVO (commit d828b02, 2026-06-09)**. Idéntico al de app/. Mismo helper para el realm panel.
- `docker-entrypoint.sh` — **NUEVO en raíz (commit 5ea3a2d, 2026-06-09)**. Entrypoint del container Coolify. Parsea `REDIS_URL` y configura `session.save_handler=redis` antes de lanzar PHP built-in server. Sin esto las sesiones se pierden en cada deploy.
- `router.php` — **NUEVO en raíz (commit 82a376b, 2026-06-09)**. Dispatcher por `Host:` header para el deploy single-container. Despacha a `/panel`, `/app` o `/api` según subdominio. God-node en producción. Ver `02-arquitectura.md § Deploy single-container`.
- `app/includes/device.php` — **NUEVO (commit a3fefb4, 2026-06-06)**. Helper de device pairing. `deviceRegister($companyId, $userId, $outletId, $registerId): ?string` — INSERT en tabla `device` + retorna `deviceId` UUID. Llamado desde `login.php` y `API/auth.php` antes de emitir el JWT.
- `app/includes/ws_publish.php` — Publica eventos a Redis
- `app/includes/db.postgres.php` — Conexión a PostgreSQL
- `app/scripts/ncm-ws.js` — Cliente WebSocket
- `app/bff/lib/api_client.php` — Cliente HTTP BFF→API compartida (reenvía `_jwt`; apunta a `PUNTO_API_BASE`)

**Frontend**: Bootstrap 3 + jQuery, service worker para offline.

### Namespace PSR-4 `Punto\App\*` en /app (Slice 0, commit 8a7819c, 2026-06-04)

`/app` ahora tiene estructura PSR-4 paralela al código legacy. El autoloader está en `app/composer.json` (`composer dump-autoload --optimize`). Los directorios PSR-4 conviven con `app/includes/` (legacy) y `app/bff/` (BFFs):

| Directorio | Namespace | Propósito |
|------------|-----------|-----------|
| `app/Helpers/` | `Punto\App\Helpers\` | Utility puras (validity, iftn, toUTF8, niceDate, etc.) |
| `app/Domain/Customer/` | `Punto\App\Domain\Customer\` | Lógica de clientes |
| `app/Domain/Money/` | `Punto\App\Domain\Money\` | Cálculos monetarios |
| `app/Domain/Inventory/` | `Punto\App\Domain\Inventory\` | Stock y movimientos |
| `app/Domain/Document/` | `Punto\App\Domain\Document\` | Facturas y numeración |
| `app/Domain/Store/` | `Punto\App\Domain\Store\` | Mesas, órdenes, cajas |
| `app/Domain/Taxonomy/` | `Punto\App\Domain\Taxonomy\` | Categorías, marcas, impuestos |
| `app/Domain/GiftCard/` | `Punto\App\Domain\GiftCard\` | Gift cards |
| `app/Http/Response/` | `Punto\App\Http\Response\` | Helpers HTTP (jsonDieMsg, dai) — **POBLADO (Slice 2)** |
| `app/Services/Notification/` | `Punto\App\Services\Notification\` | Email, SMS, Push, FE |
| `app/Database/` | `Punto\App\Database\` | Query wrapper (reemplaza ncmExecute) |

**Clases PSR-4 existentes en `/app` (post Slice 7, commit 416f4e9):**

| Clase | Namespace completo | Reemplaza (wrapper en functions.php) |
|-------|-------------------|--------------------------------------|
| `Json` | `Punto\App\Http\Response\Json` | `jsonDieResult` (158 callers) + `jsonDieMsg` (61 callers) |
| `Output` | `Punto\App\Http\Response\Output` | `dai` (542 callers) |
| `Validation` | `Punto\App\Helpers\Validation` | `validity` (716) + `validateHttp` (1524) + `validateBool` (58) + `validateResultFromDB` |
| `Str` | `Punto\App\Helpers\Str` | `toUTF8` (238) + `markupt2HTML` (19) + `isBase64Decode` (9) + `isHTML` (2) |
| `Date` | `Punto\App\Helpers\Date` | `niceDate` (166) + `getNextDatePeriod` (9) + `niceDate2` (5) + `dateStartEndTime` (5) + `translateNamesOfWeek` |
| `Math` | `Punto\App\Helpers\Math` | `divider` (50) + `rounder` + `rester` (3) |
| `Arr` | `Punto\App\Helpers\Arr` | `counts` (34) + `explodes` (134) + `implodes` (36) + `arrKey` (1) |
| `Cond` | `Punto\App\Helpers\Cond` | `iftn` (778) |
| `Taxonomy` | `Punto\App\Domain\Taxonomy` | 12 funciones taxonomy/payment (112 callers). Cache estático en `getName()` para evitar N+1 en printTags loops. Primera clase en `Domain/`. |
| `Store` | `Punto\App\Domain\Store` | 5 funciones outlet/store (67 callsites): `getCurrentOutletName` (41), `selectInputOutlet` (19), `getOperatingCost` (3), `getAllOutletData` (2), `getOutletCount` (2). Segunda clase en `Domain/`. (Slice 8, commit 7545b02) |
| `Customer` | `Punto\App\Domain\Customer` | 11 métodos estáticos (139 callsites): `getCustomerData`/`getContactData` (38+36), `getCustomerName` (36), `getAllContacts` (15), `manageLoyalty` (4), `getContactField` (4), `manageStoreCredit` (2), `manageGiftCard` (1), `getRealCustomerId` (1), `getTransactionAddress` (1), `getContactCreditLine` (1). Fix P0: `getName(mixed $data)` tipado relajado de `array` a `mixed` + early-return on false. (Slice 9, commit 51d600b) |
| `Query` | `Punto\App\Database\Query` | 7 métodos que wrappean el core DB: `execute()` (ncmExecute god node — 1035 callers), `getValue()` (99), `update()` (69), `insert()` (47), `flattenJsonb()` (23), `delete()` (3), `while()` (1). Total: 1273 callsites. `execute()` llama `self::flattenJsonb()` directo; `getValue()` llama `self::execute()` directo. (Slice 10, commit 51d600b) |
| `Document` | `Punto\App\Domain\Document` | `getNextDocNumber` — 12 callers. Hogar canónico para numeración de comprobantes. (Slice 11) |
| `Money` | `Punto\App\Domain\Money` | 8 métodos, **702 callers**: `formatNumber` (530), `formatQty` (85), `formatForDB` (73), `addTax` (7), `forceDecimals` (2), `sanitizeTaxObj` (2), `sanitizeSaleArray` (2), `sanitizePaymentObj` (1). Hogar canónico para formateo monetario e impuestos. Código nuevo usa esta clase. (Slice 12) |
| `Inventory` | `Punto\App\Domain\Inventory` | 11 métodos, **116 callers**: `manageStock` (27 — CRÍTICO), `getCompoundsArray` (23), `getItemStock` (16), `getAllItemStock` (8), `getProductionCOGS` (8), `getComboCOGS` (8), `getNeedWithWaste` (8), `getAllWasteValue` (9), `getProductionCapacity` (5), `displayableCompounds` (3), `getItemMainStock` (1). Hogar canónico para lógica de stock y COGS. Código nuevo usa esta clase. (Slice 13) |
| `GiftCard` | `Punto\App\Domain\GiftCard` | `insertNew` — 1 caller. (Slice 14) |
| `Notification` | `Punto\App\Services\Notification` (namespace `Punto\App\Services\`) | 7 métodos, **76 callers**: `sendEmails` (23), `sendSMS` (17), `sendWS` (11), `sendPush` (10), `sendEmail` (9), `sendSMTP` (5), `sendNCMSMS` (1). Hogar canónico para envío de notificaciones. (Slice 15) |

`app/Helpers/SmokeTest.php` fue **eliminada en Slice 2** (cumplida su función transitoria). Ver `08-convenciones.md §26` y `10-roadmap.md § Top-5 mejoras estructurales`. autoload: **3188 clases** (post Slices 11-15). PSR-4 `/app` prácticamente completo — 15/16, Slice 16 (deprecation removal) diferido.

---

## /api — API compartida (backend único del sistema, añadido 2026-05-28)

**Propósito**: Backend único del sistema. /panel y /app lo consumen como clientes vía HTTP.
Destinado a correr en un server dedicado separado de /panel y /app.

**Dev server**: `php -S localhost:8000 api/router.php` (port :8000, ver `.claude/launch.json`)

**Superficie pública**: Solo `/v1/*` endpoints. `bootstrap.php`, `lib/` y el router NO son web-accesibles (anti-traversal por realpath confinado a `/api/v1`).

**Auth**: `apiAuthTenant()` en `api/bootstrap.php` — JWT de tenant (cookie `_jwt` | `Authorization: Bearer` | POST `_jwt`; secret `JWT_SECRET`; claim `cid`). El mismo secret/claims que /panel y /app ya validan → una sola API autentica ambos clientes.

**Estructura**:

| Directorio/Archivo | Responsabilidad |
|-------------------|----------------|
| `api/router.php` | Dev server router — confina al path `/api/v1/` |
| `api/bootstrap.php` | Bootstrap + `apiAuthTenant()` — autentica JWT tenant, prepara contexto POS |
| `api/lib/response.php` | `apiOk()` / `apiError()` — envelope canónico |
| `api/lib/services/CustomerAddressService.php` | CRUD de direcciones de cliente. Todos los Services en `api/lib/services/` tienen `declare(strict_types=1)`, `namespace Punto\Api\Services`, `final class` y DI por constructor (`TenantContext $ctx`; más `\DB $db` en Notification y Transaction). Ver §22.14 de `08-convenciones.md`. |
| `api/lib/services/TableService.php` | rename / unreserve / assignUser / closeTable / **listTables** / **joinSpaces** / **moveOrders** de mesas |
| `api/lib/services/OrderService.php` | accept / transferToOutlet / assignUser / **customerHasOpenOrders** (slice 23 — bool, type 12 status!=4, parametrizado, multi-tenant) |
| `api/lib/services/TransactionService.php` | delete / deletePrintJob / reject / recordItemDeletion (slices 6, 28, 29) + **voidTransaction** (commit b3d164f — anulación type→7, corrige 4 bugs del legacy) + **setNote** (Slice 36a — UPDATE transactionNote vía markupt2HTML) + **changeStatus** (Slice 36b — 3 fases: schedule completion logic, UPDATE transactionStatus + motive, devuelve ids para notifs; side-effects best-effort). Recibe `TenantContext $ctx` + `\DB $db`. |
| `api/lib/services/RegisterService.php` | setSession (slice 10) / **docNumbers** (slice 22 — 7 contadores de doc por registro, bug PG de UUID sin comillas corregido) |
| `api/lib/services/ScheduleService.php` | rescheduleTo / unlock (slice 4) / updateSchedule / scheduleSession / checkIfUserOccupied (slice 20) / getSessionsList (slice 30) / getAgendaList (slice 31) / **getCalendarSlots** (slice 41 — resources/week views; fix PG IN bindeado) / **getCalendarAgenda** (slice 41 — agenda mensual agrupada por día; JOIN a contact vs N+1 SELECT del legacy) / **getCalendarMonthCounts** (slice 41 — counts por día) |
| `api/lib/services/CustomerNoteService.php` | add de notas de cliente |
| `api/lib/services/TinService.php` | búsqueda de RUC paraguayo vía Marangatu (SET). `lookup($id, $country): ?array` — descarta DV si viene con `-DV`; solo PY soportado. Shape: `{id, tin, name, fullName, address, phone}`. (Slice 38, commit dc33d7e) |
| `api/lib/services/BancardService.php` | Bancard QR payments. `createQR / refreshQR / cancelQR` — llama a `BANCARD_QR_API` con Bearer token. Construye `identifier` JSON con IDs del JWT (companyId, outletId, registerId, UID, amount, saleAmount, comission, tax). (Slice 43, commit cc02762) |
| `api/lib/services/PixService.php` | Pix (Bancard) payments. `getToken` (OAuth2 client_credentials) + `createQR` (token + /api/generate_qr, incluye token en respuesta — paridad con legacy; deuda de diseño documentada en docblock: token viaja por el cliente) + `verifyTransaction` (verifica por referenceId usando token del caller). (Slice 43, commit cc02762) |
| `api/lib/services/CustomerService.php` | **getInfo()** (slice 32 — resumen de cliente: contacto + últimos ítems vendidos + deuda corriente/vencida + gift cards activas + dirección default). Read-only salvo backfill lazy de customerAddress. Corrige SQL injection del legacy (STRING_AGG(ids) concatenado en IN() → IN(?) parametrizado). Scope companyId en todas las queries de transaction/itemSold/giftCardSold. |
| `api/v1/customer_address.php` | Endpoint CRUD customerAddress (slice 1) |
| `api/v1/tables.php` | Endpoint mesas (slices 2–3, 21, 34) — GET listTables; PUT `?resource=join` (joinSpaces), PUT `?resource=move` (moveOrders) |
| `api/v1/orders.php` | Endpoint órdenes: GET `?resource=customerHasOrders&customerId=<id>` → bool (slice 23) |
| `api/v1/schedule.php` | Endpoint agendamientos (slice 4) |
| `api/v1/customer_note.php` | Endpoint notas de cliente (slice 5) |
| `api/v1/register.php` | Endpoint de registro: GET sin acción → docNumbers (slice 22); POST setSession (slice 10) |
| `api/v1/customers.php` | Endpoint de cliente: GET `?resource=info&id=<encId>` → resumen completo del cliente (slice 32) |
| `api/v1/bancard.php` | Bancard QR: POST `{type: create\|refresh\|cancel}` → `apiOk` con respuesta de Bancard. (Slice 43) |
| `api/v1/pix.php` | Pix QR: POST `{type: create\|verify}` → `apiOk` con respuesta de Pix. (Slice 43) |

**Clientes actuales**: `/app/bff/*` (vía `app/bff/lib/api_client.php` que reenvía cookie `_jwt`). `/panel/bff/*` consume /api para los reportes migrados en F2 Y para outlets/settings/contacts/items (Bearer `_jwt_panel` vía base `'shared'` en `panel/bff/lib/api_client.php`); el resto del panel sigue usando `panel/API/` in-process — pero F3/F4/F5 del desacople original están CANCELADOS por el pivote a React (`context/12-panel-rewrite.md`). El legacy panel se elimina cuando el nuevo panel React lo cubra 100%.

### Namespaces `Punto\Api\*` portados en F2 (commits ed1026a..479887b, 2026-06-10)

Además de los 24 Reports (ver arriba), F2 portó 5 módulos CRUD/funcionales a la API compartida:

| Namespace | Directorio | Endpoint | Realm | Notas |
|-----------|-----------|---------|-------|-------|
| `Punto\Api\Outlets` | `api/lib/Outlets/` | `api/v1/outlets.php` | `['panel']` | `OutletsService`: list/get/create/delete. `create()` consulta `plans.features JSONB` para límite de inventory. `delete()` inlinea `deleteOutlet()` god-fn (13 DELETEs, 2 UPDATEs en TX). Resources copiados (no hardlinkeados). |
| `Punto\Api\Settings` | `api/lib/Settings/` | `api/v1/settings.php` | `['panel']` | Port fiel de `panel/lib/settings/SettingsService.php`; recursos de plantillas copiados a `api/lib/Settings/resources/`. |
| `Punto\Api\Bootstrap` | `api/lib/Bootstrap/` | `api/v1/bootstrap.php` | `['panel']` | Bootstrap de la sesión del panel: currency, permisos, outlets, módulos activos. |
| `Punto\Api\Contacts` | `api/lib/Contacts/` | `api/v1/contacts.php` | `['panel','pos-app']` | PRIMER endpoint multi-realm en F2. Contacts son compartidos entre panel y POS. `ContactRepository` + `ContactService`. |
| `Punto\Api\Items` | `api/lib/Items/` | `api/v1/items.php` | `['panel','pos-app']` | Endpoint FUSIONADO: absorbe el slice 25 del POS (`?resource=core|inventory|info`) + CRUD del panel. 6 services: `ItemRepository`, `ItemService`, `LocationService`, `UpsellService`, `StockService`, `CompoundService`. Dispatch por `?resource` dentro del endpoint unificado. |

### Namespace `Punto\Api\Reports\*` — cluster de reportes del panel (F2, commits c4d3231..36fc3e3, 2026-06-10)

24 services + 3 helpers migrados desde `panel/lib/reports/` al patrón moderno de /api. Todos bajo `namespace Punto\Api\Reports`, `final class`, ROC y `$companyId` por parámetro (sin globals).

**Services** (uno por tipo de reporte): `BrandsService`, `CashflowService`, `CategoriesService`, `CustomersService`, `DashboardService`, `DrawersService`, `ExpensesService`, `GiftcardsService`, `InventoryService`, `OpenInvoicesService`, `PaymentMethodsService`, `ProductionService`, `ProductsService`, `PurchasesService`, `RecurringService`, `SalesService`, `SatisfactionService`, `ScheduleService`, `StockDayService`, `StockService`, `SummaryYearService`, `TransactionsService`, `UsersService`.

**Helpers compartidos**:
- `Roc.php` — `Roc::build($cid, $oid, $alias='')`: construye el ROC scoped por company/outlet con guard UUID y prefijo de alias para JOINs.
- `NonAddingSales.php` — `compute()`, `salesByPayment()`, `lessInternalTotals()`, `previousPeriod()`: la cadena `getNonAddingToSales` del panel. Las versiones del legacy de /app están rotas en PG (USE INDEX MySQL, columna `tags` literal, sin discount).
- `Taxonomy.php` — helper de taxonomies brand/category.

**Endpoints**: `api/v1/reports/<nombre>.php` con `apiAuthTenant(['panel'])`. BFFs en `panel/bff/reports/<nombre>.php` repuntados a base `'shared'`.

**Reportes NO migrados** (pendientes): `vpayments` (proxy a gateway externo `panel/API/get_vpayments.php` — out-of-scope F2, ver F5 del desacople) y `inventory widget` (KPI semántica por definir con producto — BFF ramifica: `widget→panel`, `movements→/api`).

**Gotcha crítico (batch 14, commits en F2):** `getTaxonomyName()` del global resuelve a `/app/Domain/Taxonomy::getName` que lee `global $SQLcompanyId`. Pero `apiAuthTenant()` define `$SQLcompanyId` como variable LOCAL de función → la query sale con trailing AND roto → null → `'None'` silente para TODAS las categorías/marcas. Fix preventivo: usar lookup directo bindeado por `$companyId` del JWT (no el global). Ver convención §33 en `08-convenciones.md`.

**Deuda transitoria**: `api/bootstrap.php` hace `chdir(/app)` y reusa los includes de /app (`db/functions/jwt_middleware/head.php/data.php`) vía rutas absolutas. La consolidación de un `/api/includes` canónico es la tarea pendiente antes de que /api pueda vivir en su propio server.

**REGLA**: Todo nuevo endpoint de desacople (de /app o de /panel) va en `/api/v1/` + `/api/lib/services/`, NO en `/app/API/v1/` (que quedó vacío de slices) ni directo en `panel/API/`. Los Services en `api/lib/services/` siguen el patrón §22.14 (`namespace Punto\Api\Services`, `final class`, DI con `TenantContext`). Los módulos de dominio nuevos van en subdirectorios PascalCase (`api/lib/Sales/`, etc.) con el estándar §22.9 completo.

---

## /panel — Panel de Control Admin

**Propósito**: Gestión del negocio. Dashboard, inventario, clientes, facturación,
reportes, configuración de módulos, usuarios.

**Entry point**: `panel/index.php` (SPA con sesión PHP)

**Páginas** (`panel/a_*.php`, 80+ archivos):
- `a_dashboard.php` (91KB) — Analytics, resúmenes, datos real-time. **Migrado al BFF de 3 capas (2026-05-27)**: front estático `panel/reports/dashboard.html` + `panel/scripts/a_report_dashboard.js` (13 widgets via `/bff/reports/dashboard.php?widget=…`); capa de datos: `panel/bff/reports/dashboard.php` + `panel/API/v1/reports/dashboard.php` + `panel/lib/reports/ReportDashboardService.php`. Router: `/a_dashboard → /reports/dashboard.html` (`$bffStaticReports`). Widgets gateados por módulo (satisfaction/tables/schedule). Tour iguider deferido. **Front usa Alpine.js** (1er fragmento del panel en Alpine, 2026-05-27 cont. 10): bindings `x-text`/`x-html`/`x-show`/`x-for` reemplazan al templating Mustache+jQuery; charts Chart.js siguen imperativos. Receta de init determinista → `08-convenciones.md §17`.
- `a_items.php` (201KB) — Inventario/productos
- `a_contacts.php` (~140KB) — Clientes y proveedores. Estado de modernización (2026-05-25):
  - **Backend**: `lib/contacts/{ContactRepository,ContactService}.php` + `API/v1/contacts.php` + `scripts/api/contacts.js` (window.contactsApi) — completo.
  - **Listado (Fase 4)**: los 3 roles (customer/user/supplier) emiten `&format=json` → `scripts/contacts/render.js` (renderCustomerRow / renderUserRow / renderSupplierRow + `table(contacts, rol)`). Todos escapados con `esc()`.
  - **Editform v2 (Fase 4)**: `scripts/contacts/form.js` — `contactFormV2` con templates Mustache en `panel/contacts/templates/` (shell + header + basicTab + addressTab + notesTab). Cubre SOLO rol **customer**; user/supplier usan form legacy. Tabs "fichas/custom records" e "historial detallado" diferidos.
  - **Fallback**: form legacy (`?action=form`) sigue activo como `onError` del v2. Custom records solo en `a_contacts.php` legacy.
  - **Pendiente**: custom records, CSV export (lee columnas ya en JSONB), user/supplier en form v2.
- `a_billing.php` (23KB) — Facturación
- `a_modules.php` — Feature toggles por rubro
- `a_reports.php` — Reportes
- Otros: purchase, registers, users, settings...

**Módulos CRUD migrados al BFF de 3 capas** (primer CRUD no-reporte, 2026-05-27):
- **`a_outlets` (Sucursales)** — **1er módulo CRUD del panel en el modelo BFF/Alpine** (commit 99d1286). Migración PARCIAL: list + get-single + update al BFF; create (cascada register+inventory vía god-helpers) y delete (deleteOutlet cascadeante) quedan legacy vía `?action=`. businessHours (jQuery widget) y depósitos (adm() infra compartida) diferidos. Archivos: `panel/lib/outlets/OutletsService.php` (list/get/update) · `panel/API/v1/outlets.php` (GET list|single + POST update, gate rol 7) · `panel/bff/outlets.php` (proxy GET/POST) · `panel/views/outlets.html` (lista ncmDataTables + form Alpine x-model en modal) · `panel/scripts/a_outlets.js` (Alpine §17 detached-initTree). Router: `$bffPartialModules` en `panel/router.php` (nuevo mapa para módulos CRUD, paralelo a `$bffPartialReports`). Fronts de módulos CRUD viven en `panel/views/` (distinto de `panel/reports/` para reportes).
- **`a_settings` (Ajustes)** — **2º módulo CRUD del panel migrado al modelo BFF/Alpine** (commits 1d8fd03..63435b0, 2026-05-28). Migración COMPLETA de las 4 tabs reales del legacy. Archivos: `panel/lib/settings/SettingsService.php` (general read/write + options + taxonomies + currencies + templates) · `panel/API/v1/settings.php` (GET views: general/options/currencies/templates/templateFields; POST types: setting/currencies/saveTemplate/removeTemplate) · `panel/bff/settings.php` (proxy + composición) · `panel/views/settings.html` (tabs Alpine: Perfil, App/Visualización, Monedas, Plantillas) · `panel/scripts/a_settings.js` (Alpine §17) · `panel/scripts/a_settings_templates.js` (widget jQuery templateBuilder portado verbatim — §17.2 + §20). Router: `a_settings → /views/settings.html` en `$bffPartialModules`. FIX prod: guardado de Ajustes estaba roto en PG (tabla `setting` eliminada en Phase PG) → ahora merge JSONB en `company.config`. Nota: tab ecommerce NO existe en el legacy real (UI muerta, ver `10-roadmap.md`). Gap: drag/drop del template builder pendiente de verificación visual en entorno con sesión PHP.

**API** (`panel/API/`, ~93 endpoints):
- Lib: `panel/API/lib/response.php` (envelope canónico)
- Lib: `panel/API/lib/api_middleware.php` (JWT + fallback)
- Auth: `panel/API/auth.php` (login panel)
- CRUD: add/edit/delete/get para cada entidad
- Estado: 10/93 migrados a envelope canónico, 83 legacy

**Archivos clave**:
- `panel/includes/functions.php` (~282KB) — Mega-utilidades
- `panel/includes/simple.config.php` — Constantes globales (WS_URL, etc.)
- `panel/includes/jwt.php` — JWT para panel
- `panel/includes/ws_publish.php` — Publica a Redis
- `panel/includes/db.php` → `db.postgres.php` — Conexión BD
- `panel/includes/secure.php` — CORS, headers de seguridad

---

## panel-next/ — Nuevo panel React (greenfield, 2026-06-10+)

**Propósito**: reescritura del panel legacy a React + Next.js + shadcn/ui. Ver `context/12-panel-rewrite.md` para el plan completo de slices.

**Stack**: Next.js 15, React 19, TypeScript, shadcn/ui, TanStack Table, TanStack Query.

### /modules — Marketplace de módulos nativos (commit 59052ac, 2026-06-14)

Página `app/(panel)/modules/page.tsx` — marketplace de módulos que el tenant puede activar/desactivar.

**Catálogo**: `lib/modules-catalog.ts` — 19 módulos nativos en 5 categorías. Excluye integraciones de terceros (spotify/dropbox/mcal/tusfacturas/newton/osWidget/extraUsers).

**Backend**: `Punto\Api\Modules\ModulesService` (`api/lib/Modules/ModulesService.php`) + endpoint `api/v1/modules.php` (gateado `apiAuthTenant(['panel'])`).

**Patrón compat POS — double-write**: al activar/desactivar un módulo, el servicio escribe en DOS lugares:
1. `company.moduleData` JSONB (patrón moderno)
2. Columna flat de `company` (ej. `companyModule*`) — porque el POS lee los flags de columnas planas (`app/fetchs.php:448-456`)

Hay una allowlist estricta de keys para el double-write. Código nuevo en /api escribe al JSONB; la lectura del POS sigue leyendo columnas planas.

**Hook**: `hooks/use-modules.ts` — TanStack Query, patrón canónico CRUD panel-next.

### /history-billing — Mi plan (Billing tenant, commits 0ff4906 + 53331fe + 8ce0739, 2026-06-14)

Página `app/(panel)/history-billing/page.tsx` — vista de "Mi plan" para el tenant: plan activo, créditos de IA disponibles, historial del ledger, packs de créditos disponibles y flujo de compra.

**Backend**: `Punto\Api\Billing\BillingService` (`api/lib/Billing/BillingService.php`) + endpoint `api/v1/billing.php` (gateado `apiAuthTenant(['panel'])`).

Métodos del service:
- `summary()` — plan activo, balance de créditos AI, features del plan
- `plans()` — lista de planes disponibles
- `aiLedger($from, $to)` — historial de movimientos de créditos AI (tabla `ai_credit_ledger`)
- `requestPlanChange($requested)` — crea un `billing_request` pendiente de resolución por admin

**Compra de créditos (dLocal Go, commit ca6a030)**: `Punto\Api\Billing\PaymentsService` (`api/lib/Billing/PaymentsService.php`) gestiona el flujo de compra de packs:
- `createPackCheckout($packId, $tenantCtx)` — crea una `billing_invoice` + llama a dLocal Go para obtener redirect URL al checkout
- `handleWebhook($payload, $signature)` — procesa webhooks de dLocal; acreditación atómica con `BeginTrans + SELECT FOR UPDATE invoice+company + UPDATE + Affected_Rows + ledger`; idempotencia en 3 capas (lock de tx + Affected_Rows + índice único `uq_ai_credit_ledger_invoice_grant`)

**Proveedor dLocal Go** (`api/lib/Billing/Payments/DlocalGoProvider.php`):
- Checkout tipo REDIRECT
- `verifyWebhookSignature()` — HMAC-SHA256 + `hash_equals()`, fail-closed (401 si firma inválida)
- `getPayment()` — re-query del estado de un pago

**Endpoint webhook público**: `api/v1/billing-webhook.php` — sin JWT, lee `php://input` ANTES del bootstrap, verifica firma HMAC, devuelve 200 incluso ante errores internos (para no provocar reintentos del proveedor). Solo retorna 401 si la firma es inválida.

**Hook**: `hooks/use-billing.ts`. **Tipos**: `lib/types/billing.ts`.

Sin captura de tarjeta en el frontend — el flujo redirige al checkout de dLocal y retorna con `?checkout=success`.

### BFF catch-all same-origin (commit 580d79a, 2026-06-12)

`panel-next/app/api/v1/[...path]/route.ts` — catch-all de Next.js que reenvía **todos** los requests a `/api/v1/*` al backend PHP (`NEXT_PUBLIC_API_URL`) preservando la cookie `_jwt_panel`. El `api-client.ts` del browser usa baseURL `/api` (same-origin, sin CORS). **Este es el patrón canónico para CRUD en panel-next** (antes solo existía para el income-chart). Ver §37 en `08-convenciones.md`.

**Diagnóstico de sucursal**: el mismo commit incluye logging de diagnóstico en el handler de creación de sucursal para trazar errores de validación del backend.

### Selector de sucursal (commit fd5e5b3, 2026-06-12)

El logo wordmark del sidebar se convierte en `DropdownMenu` con la lista de sucursales cuando `outlets.length > 1`. Al seleccionar una sucursal, se llama `POST /v1/active-outlet` que re-emite el JWT panel con el nuevo claim `oid`. El bootstrap (`/v1/bootstrap`) ahora devuelve `activeOutletId`, `activeOutletName` y `outlets[]`.

### ContactAnalyticsService (commit f9a646d, 2026-06-12)

Nuevo servicio en `api/lib/Contacts/ContactAnalyticsService.php`. Endpoint: `GET /v1/contacts?id=X&resource=analytics&type=1|2`.

Devuelve por contacto:
- `totals` — resumen de visitas, monto total, ticket promedio
- `segment` — segmento RFM-lite del cliente
- `topItems` / `topCategories` — top ítems y categorías comprados
- `paymentMix` — distribución de métodos de pago
- `temporal` — distribuciones temporales (día de semana, horario)
- `outlets` — sucursales frecuentadas
- `financial` — crédito en cuenta, gift cards, puntos de fidelidad

Frontend perfil de contacto en panel-next reescrito a **4 tabs**: Resumen / Comportamiento / Financiero / Datos.

### Módulo Reportes panel-next (commits 93b7ffb, 80c775c, 4e1bd90, ee61451, 780ec4b, 2026-06-12 a 2026-06-14)

Landing `/reports` con 3 grupos del legacy (Ventas / Inventario / Admin). **23 de 24 reportes implementados** (solo "Conteo de inventario" pendiente — sin endpoint ni vista legacy):

| Reporte | Tipo |
|---------|------|
| Transacciones | listado |
| Drawers (cierres de caja) | listado |
| Productos | ranking |
| Cuentas por cobrar | listado |
| Cuentas por pagar | listado |
| Resumen anual | tabla |
| Customers | ranking |
| Categorías | ranking |
| Marcas | ranking |
| Pagos (métodos) | ranking |
| Movimientos de caja | listado |
| Órdenes | listado (nuevo, commit 780ec4b) |
| + 11 reportes migrados en ee61451 | varios |

**Nuevo backend — Órdenes** (commit 780ec4b): `Punto\Api\Reports\OrdersService` (`api/lib/Reports/OrdersService.php`) + endpoint `api/v1/reports/orders.php`. Fuente: `transaction type=12` (mismo origen que el widget de órdenes del dashboard). Estados según mapa de `panel/API/get_orders.php`: 0/1 Pendiente · 2 En espera · 3 En proceso · 4 Finalizado · 5 Enviado · 6 Cancelado.

**Abstracciones compartidas**:
- Hook genérico `useReport<T>` — fetching + estado de carga/error reutilizable por cualquier reporte.
- Componente `<RankingReportPage>` — layout canónico para reportes tipo "ranking simple" (fecha, tabla, export).

1 reporte marcado "Próximamente" en la landing (Conteo de inventario).

### `/settings` como Dialog modal (commit 67113d4, 2026-06-12)

La pantalla `/settings` en panel-next se renderiza como **Dialog modal estilo Alfred** (sidebar de 220px + content panel). Tab "Redes" fusionado al final del tab "Empresa".

### Dashboard panel-next — layout 2-col (commit 8bc61e9, 2026-06-12)

Dashboard refactoreado al **layout 2-col del legacy (8/4)**:

- **Columna principal (8)**: hero KPIs, income chart, donuts de métodos de pago, clientes recientes, Top 5 productos, horarios pico (`TopHoursCard` — nuevo componente).
- **Columna lateral (4)**: NPS, órdenes activas, información general, plan activo.

### Módulo Packs de Servicios (commits 5d8edb9..4c4d930, 2026-06-15)

Suscripciones/combos de servicios: se define un ítem de tipo "pack" con N componentes (servicios incluidos), se vende como cualquier ítem y el sistema emite un `sold_pack` que el cliente consume por canje.

**Schema**: tablas `pack_component`, `sold_pack`, `sold_pack_usage` (migración 31). Ver `context/04-modelo-de-dominio.md § Tablas de Packs de Servicios`.

**Archivos clave**:

| Capa | Archivo | Responsabilidad |
|------|---------|----------------|
| Migration | `database/migrations/postgres/31_pack_services.sql` | 3 tablas + índices |
| Service | `api/lib/Packs/PackService.php` | CRUD de componentes (`pack_component`), consulta de packs activos por contacto, canje (`sold_pack_usage`), historial |
| Endpoints | `api/v1/pack_component.php`, `api/v1/sold_pack.php`, `api/v1/sold_pack_usage.php` | gateados `apiAuthTenant(['panel','pos-app'])` |
| Panel-next | editor de ítems — tab "Pack" con builder de componentes | `panel-next/` — tipo pack dentro del form de ítem |
| Integración venta | `api/lib/Sales/SaleService.php` | al vender un ítem tipo pack, crea `sold_pack` automáticamente |
| POS Alpine | `app/` | vista de packs activos del cliente + modal de canje |
| Panel-next perfil | tab "Packs" en perfil de contacto | historial de canjes del cliente |

**Invariantes**:
- El balance por componente se computa en query (canjes usados vs `componentQty × qty`), nunca persiste.
- Lazy expiry: `expiresAt < NOW()` se evalúa al leer, no hay cron.
- `status` 1=activo, 0=bloqueado/vencido, 2=consumido completamente.

### Módulo Listas de Precios (commits 14394b5..7f3c134, 2026-06-15)

Listas de precios con ajuste porcentual global o override por ítem. Soporta recargos positivos (delivery) y descuentos negativos (mayorista). Se asigna a contactos o sucursales.

**Schema**: tablas `price_list` y `price_list_item` (migración 32). Ver `context/04-modelo-de-dominio.md § Tablas de Listas de Precios`.

**Archivos clave**:

| Capa | Archivo | Responsabilidad |
|------|---------|----------------|
| Migration | `database/migrations/postgres/32_price_lists.sql` | 2 tablas + índices |
| Service | `api/lib/PriceLists/PriceListService.php` | CRUD de listas, CRUD de overrides por ítem, resolución de precio (`price_resolve`) |
| Endpoints | `api/v1/price_list.php`, `api/v1/price_list_item.php`, `api/v1/price_resolve.php` | gateados `apiAuthTenant(['panel','pos-app'])` |
| Panel-next settings | `panel-next/app/(panel)/settings/` | página de gestión de listas de precios |
| Asignación | panel-next — perfil de contacto y ficha de sucursal | dropdown de lista activa; guarda en `contact.data->>'priceListId'` y `outlet.data->>'priceListId'` |
| POS Alpine | `app/` | F5: carga la lista al seleccionar cliente/sucursal, resuelve precio localmente |

**Resolución de precio**: override de ítem con `fixedPrice` > override con `itemAdjustment` > `defaultAdjustment` de la lista > precio base. Lista del contacto tiene precedencia sobre lista de sucursal.

---

## /admin — Realm de super-admins de plataforma (iniciado 2026-05-28)

**Propósito**: gestión de la plataforma multi-tenant. Aislado criptográficamente del realm tenant (`/panel`). Los super-admins de plataforma usan tabla propia `admin_user`, JWT propio (`_jwt_admin`, `aud:"admin"`, secret `ADMIN_JWT_SECRET`) y cookie distinta de la del panel.

**Regla de aislamiento (no-negociable):** `_jwt_panel` nunca valida en `/admin` y viceversa.

**Archivos del realm (F0–F2, 2026-05-28):**

| Capa | Archivo | Responsabilidad |
|------|---------|----------------|
| Migración | `database/migrations/postgres/09_admin_user.sql` | Tabla `admin_user` (UUID PK, email único case-insensitive, bcrypt, status, self-FK createdBy) |
| CLI seed | `panel/admin/bootstrap_seed.php` | Crea el primer admin de plataforma (idempotente, CLI-only) |
| Middleware | `panel/API/v1/admin/admin_middleware.php` | Valida `_jwt_admin` + `aud:"admin"` — gatea TODOS los endpoints `/admin` |
| Auth API | `panel/API/v1/admin/login.php` | Login público con rate-limit email+IP |
| Auth API | `panel/API/v1/admin/me.php` | Datos del admin autenticado (gated) |
| Auth BFF | `panel/bff/admin/login.php`, `me.php`, `logout.php` | Proxy BFF — setea/limpia cookie `_jwt_admin` HttpOnly |
| Users API | `panel/API/v1/admin/users.php` | CRUD de super-admins (list/get/create/update/setStatus) gateado |
| Users BFF | `panel/bff/admin/users.php` | Proxy BFF reenvía cookie `_jwt_admin` |
| Users domain | `panel/lib/admin/AdminUserService.php` | Reglas de negocio: email único case-insensitive, password >=8, no desactivar el último admin activo ni a uno mismo. Usa `$db->Insert()+Insert_ID()` (no `INSERT…RETURNING` — ver gotcha en `10-roadmap.md § Notas técnicas F2`) |
| Companies API | `panel/API/v1/admin/companies.php` | GET list / GET ?id= / **GET ?plans=1** / **GET ?id=&billing=1** / **POST ?id=uuid&action=enter** — gateado por `adminMiddleware()`. Soporta limit/offset/filter. F3.4: `?plans=1` → `listPlans()`; `?id&billing=1` → `getBilling()`. F3.5: `POST ?id=uuid&action=enter` → `getEnterToken()` (UUID validado con regex). |
| Companies BFF | `panel/bff/admin/companies.php` | Proxy con `_jwt_admin`. F3.4: reenvía `?plans=1` y `?id&billing=1` a la API. F3.5: POST proxy → reenvía con `_jwt_admin`, inyecta `_jwt_panel` como cookie HttpOnly/SameSite:Strict, falla 502 si token vacío, retorna `redirectUrl='/@#dashboard'`. |
| Companies domain | `panel/lib/admin/CompanyAdminService.php` | listAll(limit, offset, filter) / get(id) / getCounts(id) / getOwnersBatched / getCountsBatched / **update(id, payload)** / **softDelete(id)** / **hardDelete(id)** / **listPlans()** / **getBilling(id)** / **getEnterToken(id)**. Owners + counts con IN() batched — sin N+1. Filtro post-fetch en PHP; total = count del set filtrado. `mergeConfig()` inline aplana JSONB `company.config` sin importar `functions.php` (ver §27 en `08-convenciones.md`). Campo API: `externalCustomerId`. `get()` ahora incluye `planName`/`planPrice`/`balance` (lookup a tabla `plans` por `plan_code`). `update()`: PATCH semántico — whitelist de columnas directas (status/plan/blocked/smsCredit/discount/**balance**/planExpired/isTrial/expiresAt) + JSONB config merge (settingName/settingCountry via `config \|\| ?::jsonb`). Devuelve `['ok'=>true]` o `['ok'=>false,'error','code']` (404/422/500). `softDelete()`: status='cancelled' + blocked=1, reversible. `hardDelete()`: ~57 DELETEs ordenados por FK en una única TX PG (ROLLBACK on any error); self-referential FKs NULLed en preámbulo; `device` auto-borrado por ON DELETE CASCADE; requiere confirmación por nombre (`{"confirm":"<company-name>"}`) desde el API. **`listPlans()`**: SELECT plan_code/name/price de `plans` (excluye code=0), devuelve array para el `<select>` del form de edición. **`getBilling(id)`**: SELECT balance+plan de `company` + lookup planName/planPrice + últimos 50 `cpayments` (cpaymentId/date/amount/type/description); retorna null si empresa inexistente. **`getEnterToken(id)`**: genera JWT `_jwt_panel` (iss=`'panel'`, `JWT_SECRET`) para el contacto principal de la empresa (role=1, main=true, type=0) con el primer outlet activo como `oid`. NO llama `setcookie()` — el BFF inyecta la cookie. Retorna `{token, expiresIn}` o null si la empresa no tiene contacto principal con outlet activo. (F3.1–F3.5, commits 747384d+5fe4b39+5a6e4ab+fb4a691+456092f) |
| Front | `panel/admin/login.html`, `panel/admin/home.html`, `panel/admin/users.html`, `panel/admin/companies.html` | Fronts estáticos standalone (sin shell tenant) |
| Front scripts | `panel/admin/scripts/login.js`, `panel/admin/scripts/users.js`, `panel/admin/scripts/companies.js` | JS del realm admin — todo output escapado con `esc()`. `companies.js`: tabla + drawer detalle, dark theme, búsqueda case-insensitive, role=dialog aria-modal, focus management. F3.4: `renderBilling()` (stat cards balance+plan+precio + tabla de 50 cpayments con `.billing-table`/`.billing-stat`); `renderEdit()` ahora async — carga planes con `?plans=1` y renderiza `<select>` + campo balance; `saveCompany()` incluye `balance` en el payload PATCH. F3.5: botón "Ingresar" en toolbar del drawer; fetch POST `?id=uuid&action=enter` → `window.open(redirectUrl, '_blank', 'noopener')` (abre el panel en nueva pestaña). |

**Router** (`panel/router.php`): `/admin` + `/admin/login` → `panel/admin/home.html` / `panel/admin/login.html`; `/admin/users` → `panel/admin/users.html`; `/admin/companies` → `panel/admin/companies.html`.

**Estado**: F0 (tabla+seed) ✅, F1 (auth) ✅, F2 (CRUD super-admins) ✅, F3.1 (companies read-only) ✅, F3.2 (update company) ✅, F3.3 (delete cascade soft+hard) ✅, F3.4 (billing view + plan selector + balance edit) ✅, F3.5 (impersonación JWT — "Ingresar como empresa") ✅ — **F3 COMPLETO**. Próximo: F4 (desacoplar SAAS_ADM/MASTER_COMPANY_ID) o F5 (login por teléfono). Ver plan completo en `10-roadmap.md § Admin realm`.

### /admin en panel-next — Sub-app React greenfield (commits be39b06 + 605286e, 2026-06-14)

El realm admin tiene ahora su propio route group React dentro de `panel-next/`: `app/(admin)/admin/*`. **Path-based dentro del mismo dominio** (`panel-next-dev.punto.la/admin`), no en subdominio dedicado.

**Route group `(admin)`**: layout propio (`app/(admin)/layout.tsx`), no comparte hooks ni contexto con el route group `(panel)`. Rutas: `admin/login`, `admin/dashboard`, `admin/companies` (list+detail), `admin/users` (CRUD), `admin/requests`, `admin/reports`.

**BFF catch-all admin** (`app/api/admin/[...path]/route.ts`): catch-all de Next.js que proxea a `panel/API/v1/admin/*`. Regla de aislamiento: **solo forwarda la cookie `_jwt_admin`** — NUNCA forwarda `_jwt_panel`. Esto mantiene el aislamiento criptográfico del realm admin incluso dentro del mismo proceso Next.js.

**Componentes/hooks específicos del realm admin**:
- `components/admin/admin-auth-guard.tsx` — guard de autenticación del admin
- `components/admin/admin-sidebar.tsx` — sidebar standalone (no comparte nav con el panel tenant)
- `hooks/use-admin.ts` — TanStack Query para el realm admin
- `lib/api-admin.ts` — cliente HTTP del realm admin (usa `_jwt_admin`)

**Reutiliza el backend admin existente**: `adminMiddleware()`, `_jwt_admin`, tabla `admin_user`. No hay nuevo backend — el que existe desde F0–F3 sigue siendo la fuente de verdad.

**Dashboard y audit log (commit 605286e)**:

- `AdminReportsService.php` (`panel/lib/admin/`): `overview()` (companies stats + MRR/ARR + byPlan + byCountry + newPerMonth + topAiCredits) + `payments(from,to)` (cross-tenant desde tabla `cpayments`). Endpoint: `panel/API/v1/admin/dashboard.php`.
- `adminAudit()` helper en `panel/API/lib/admin_auth.php` — registra en tabla `admin_audit` (ver `04-modelo-de-dominio.md`). Best-effort, nunca lanza excepción. Wired en 10 handlers de mutación: updateCompany / grantAiCredits / setAddons / resolveRequest / suspendCompany / deleteCompany / impersonate + createAdmin / updateAdmin / setAdminStatus.

**Búsqueda server-side en companies**: `CompanyAdminService::listAll` reescrito a SQL real con `WHERE` parametrizado (ILIKE sobre settingName/settingRUC/slug) + LIMIT/OFFSET + COUNT(*). El endpoint `companies.php` acepta `?q=&status=&plan=&blocked=&page=&pageSize=`. Antes el filtro era post-fetch en PHP (no escalaba).

---

## POS React — fusionado en panel-next (2026-06-16)

> **El subproyecto `app-next/` fue ELIMINADO (fusión 2026-06-16).** El POS React vive dentro de `panel-next/` en el route group `app/(pos)/pos`. Ya NO existen branches `app-next/*`.

**Propósito**: reescritura del POS legacy (`/app`, jQuery + Bootstrap 3) a React + Next.js + shadcn/ui. Ver `context/14-app-rewrite-analysis.md` para el análisis completo.

**Stack**: Next.js 15, React 19, TypeScript, shadcn/ui, Tailwind, TanStack Query, Zustand. Comparte el mismo proceso Next.js y el mismo catch-all BFF que el panel.

**Estado**: Slice A1 (pantalla de caja con carrito) + Slice A2 (modales búsqueda producto/cliente) + lock screen + menú POS + hotkeys. Backend de ventas: `/api` compartida (`SaleService`, realm `pos-app`).

**Estructura de directorios** (dentro de `panel-next/`):

| Directorio | Responsabilidad |
|------------|----------------|
| `app/(pos)/pos/` | Route group del POS: layout + página principal |
| `components/register/` | Componentes de la caja: carrito, búsqueda, modales, lock screen, menú |
| `lib/pos/` | Stores y helpers: `lock-store.ts` (Zustand), `device.ts` |
| `lib/catalog/` | Store en memoria (Zustand) + índice de búsqueda local + fixtures dev |
| `lib/cart/` | Store del carrito (Zustand) |
| `lib/commands/` | Comandos de dominio: `create-sale.ts`, `create-customer.ts` |
| `lib/hardware/` | QZ Tray tipado, ticket builder, barcode scanner hook |
| `lib/realtime/` | Cliente WS (`ncm-ws.ts`) |
| `lib/types/` | Tipos TS: `bootstrap.ts` (incluye `userCount`), `pos-session.ts` |
| `hooks/` | `use-pos-bootstrap.ts`, `use-catalog-seed.ts` |

**Reglas**:
- Mismo patrón BFF same-origin que el panel (catch-all `app/api/v1/[...path]/route.ts`).
- Shadcn-first, MoneyInput para montos, DataTable para listados — todas las convenciones aplican.
- El catálogo vive en memoria (Zustand), hidratado de IndexedDB (Dexie, a incorporar en F2). Búsqueda local sin round-trips.
- El backend de ventas es el mismo `/api` compartido (`SaleService` idempotente, realm `pos-app`).
- Offline scope restringido: solo ventas simples contado/crédito + alta de clientes.

### Lock screen del POS (commits f4fb03c..70b708c, 2026-06-16)

Componente: `panel-next/components/register/lock-screen.tsx`. Store: `panel-next/lib/pos/lock-store.ts` (Zustand).

**Scope**: overlay `absolute inset-0 z-[60]` scoped al workspace del POS — NO al viewport global. Esto es intencional: el lock bloquea la UI de caja sin interferir con el resto del layout de Next.js ni con la sesión del operador.

**Funcionamiento**:
- PIN 4 dígitos. Captura `keydown` global + input invisible `inputMode="numeric"` para teclado virtual en mobile/tablet.
- Animaciones: `pin-pop` (bounce al agregar dígito), `pin-shake` (sacudida al PIN incorrecto) — definidas en `app/globals.css`.
- Al desbloquear: toast "Hola, [user.name]" (fallback "¡Bienvenido!").
- **Auto-lock**: se activa automáticamente cuando `bootstrap.userCount > 1` (más de un usuario en la empresa). Commit 70b708c. Si `userCount` es `undefined`, el default es NO bloquear (commit 60414b3 — fix para instalaciones que aún no tienen el campo en el bootstrap).

**TODO F2 backend**: `STUB_PIN = "1234"` → endpoint real `POST /v1/lock-screen/verify` con re-emisión de `_jwt_pos`. Ver `context/10-roadmap.md § F2 — Backend pendiente del POS`.

### Menú principal del POS (commits 162329b + 1a4ec47, 2026-06-16)

Componente: `panel-next/components/register/pos-main-menu.tsx`.

**8 secciones**: Control de Caja / Transacciones / Agenda / Órdenes / Impresoras / Módulos / Ajustes / HotKeys.

**Patrón `CustomContent` + `onSelect`**: el tipo `MenuSection` admite dos extensiones opcionales:
- `CustomContent?: React.ComponentType` — reemplaza el área de descripción+CTA default del content panel derecho con un componente propio (usado por Control de Caja, HotKeys, etc.).
- `onSelect?: ({setOpen, router, activeRegisterId}) => void` — ejecuta una acción directa al hacer click en el item del sidebar, sin mostrar content (ej. navegar a una ruta, abrir un dialog). Si está presente, la sección no muestra area derecha.

Estado inicial sin selección muestra un empty state en el content area.

**TODO F2**: las secciones de Control de Caja (apertura/cierre/arqueo) y las previews de Transacciones/Agenda/Órdenes son mocks. Impresoras pendiente de persistencia en `register.data.printers`.

### Bootstrap POS — campo `userCount` (commit 5220d63, 2026-06-16)

`api/v1/bootstrap.php` (realm `pos-app`) ahora incluye `userCount`: count de `contact` con `type=0 AND status > 0` para el tenant. El front lo consume en `lock-store.ts` para decidir si activar el auto-lock.

**TODOs en `panel-next/lib/types/bootstrap.ts`**:
- `user.name?` — nombre del operador logueado (para el toast de bienvenida del lock)
- `user.roleName?` — rol del operador (col `roleName` ya disponible en `UsersService` del backend)

Ambos pendientes de agregar al SELECT del bootstrap PHP. Ver `context/10-roadmap.md § F2 — Backend pendiente del POS`.

### Hotkeys del POS — endpoints `GET/PUT /v1/register?resource=hotkeys` (commit 92bf8c5)

Endpoints para persistir el layout de acceso rápido del POS por caja.

- `GET /v1/register?resource=hotkeys` — devuelve el array de hotkeys de la caja activa (scope `registerId+companyId` del JWT).
- `PUT /v1/register?resource=hotkeys` — actualiza el array. Validación server-side: descarta entradas sin `itemId`.
- Shape de cada hotkey: `{itemId: string, position: number, color: string, isCategory: boolean}`.
- Persistencia: `register.data.hotkeys` (JSONB — migration 26 demote ya aplicada).

**`mergeRepeated`** (commit 70b708c) — TODO F2: persistir en `register.data.mergeRepeated`. Hoy solo en memoria Zustand (default ON). Regla: si OFF → siempre crea línea nueva en el carrito; si ON → suma qty solo si el ítem coincide con la ÚLTIMA línea del array (A+A = 1 línea qty=2; A+B+A = 3 líneas separadas).

---

## Módulo Auditoría Tenant (commits b5b5f95 + 793613c, 2026-06-19)

Registro de mutaciones realizadas por usuarios del tenant. Retención automática 2 meses vía pg_cron.

**Schema**: tabla `tenant_audit` (migración 35). Campos: `id`, `companyId`, `userId`, `method`, `path`, `statusCode`, `ip`, `userAgent`, `createdAt`. Ver `database/migrations/postgres/35_tenant_audit.sql`.

**Instrumentación**: `tenantAudit()` se llama dentro de `apiAuthTenant()` en `api/bootstrap.php` — choke point único que captura TODAS las mutaciones (POST/PUT/DELETE) autenticadas del tenant. No requiere instrumentar endpoints individuales.

**Retención**: migración 36 (`database/migrations/postgres/36_pg_cron_retention.sql`) programa un job pg_cron con `cron.schedule('0 3 * * *', ...)`. La mig es fail-tolerant — no rompe el boot si pg_cron no está disponible. **Paso manual**: habilitar `shared_preload_libraries='pg_cron'` en el Postgres managed de Coolify + restart antes del deploy para que la mig 36 programe la purga sola.

**Archivos clave**:

| Capa | Archivo |
|------|---------|
| Bootstrap/choke point | `api/bootstrap.php` — `apiAuthTenant()` + `tenantAudit()` |
| Endpoint | `api/v1/reports/audit.php` — GET con filtros (fecha, userId, method, path) |
| Migraciones | `database/migrations/postgres/35_tenant_audit.sql`, `36_pg_cron_retention.sql` |
| UI panel-next | `panel-next/app/(panel)/reports/audit/page.tsx` |

---

## Módulo Catálogo M2M — etiquetas `tag`/`item_tag` (sprint 2026-06-19, context/14)

Tags multi-valor por ítem (a diferencia de `taxonomy` que es 1→N). Plan completo en `context/14-catalogo-m2m-plan.md`.

- **Backend**: `TagService` en `api/lib/Tags/TagService.php` + endpoint `/v1/tags` + ramas `resource=brands|tags` en `items.php`.
- **UI panel-next**: tab "Etiquetas" en `/settings/catalog` + `BrandsPicker`/`TagsPicker` multi-select en el form de ítem.
- **Import**: `ItemImporter` acepta listas separadas por `|` en columnas CATEGORIA/MARCA/ETIQUETAS.
- **Schema**: migs 37–39 (`tag`, `item_tag`, triggers bidireccionales taxonomy↔tag). Ver `context/04-modelo-de-dominio.md § Catálogo M2M`.

---

## Módulo Realtime Sync panel↔POS (sprint 2026-06-20, context/15)

Sincronización en tiempo real entre el panel y el POS sin polling.

- **Backend PHP**: `realtimePublish()` wired en `apiAuthTenant::realtimeAfterMutation` — publica automáticamente un evento Redis por cada mutación exitosa.
- **Cliente WS**: singleton `ncm-ws.ts` en `panel-next/lib/realtime/`. Hook `useRealtimeSync` mapea `entity → queryKeys` de TanStack Query y llama `invalidateQueries` al recibir el evento.
- **Entidades cubiertas**: items, contacts, transactions, sales (el dashboard se actualiza con ventas POS en tiempo real).
- **Redis pub/sub**: el ws-server (`ws-server/index.js`) hace de bridge Redis → WebSocket al browser.

---

## Módulo Checkout Screen (sprint 2026-06-20, context/16)

Pantalla secundaria orientada al cliente (customer-facing display), controlada por el POS.

- **Schema**: tabla `customer_display` (migración 40). Ver `context/04-modelo-de-dominio.md § customer_display`.
- **Endpoints**: `/v1/screens/{request,pair,publish,heartbeat,list,revoke}` (realm mixto panel+pos-app).
- **POS**: publica eventos `cart-update` al confirmarse una venta. `AjustesPanel` incluye sección de pantallas. Ruta Next.js: `(screen)/checkout` con estados pairing/live/confirmed/idle.
- **Panel-next**: `/settings/devices` CRUD de pantallas (hoy solo checkout screens; UI de cajas POS pendiente).
- **Gotcha Redis**: `phpredis` no está disponible en el servidor de prod — la publish PHP usa `fsockopen` + protocolo RESP directo.

---

## Módulo Reports Rollup (sprint 2026-06-20, context/18)

Pre-agregado incremental de reportes de ventas para rendimiento en volumen alto.

- **Schema**: migs 41–42 (`report_rollup`, `rollup_dirty`, `item_sales`, `item_returns`, `payments`). Funciones PG: `reconcile_rollup`, `backfill_rollup`.
- **Gate**: `REPORTS_ROLLUP_ENABLED` (env var, default OFF en prod hasta verificación numérica `?verify=1`).
- **Cutover actual (RB-1+RB-2)**: `SummaryYearService`, `CategoriesService`, `BrandsService`, `PaymentMethodsService`.
- **Estrategia**: pg_cron cada 5 min sobre `rollup_dirty`; backfill histórico en el primer deploy. Reconciliación full disponible como fallback.
- **Pendiente**: RB-3 (stock/production/commissions/vpayments).

---

## Módulos retail (sprint 2026-06-23)

### `Punto\Api\Services\InventoryCountService` — `/v1/inventory_count.php`

Conteo físico de inventario. `create()` abre sesión con `status=open`; `finish()` lee todos los `inventory_count_item` con `difference ≠ 0` y llama `Inventory::manageStock` por cada uno con `source='inventory_count'`. `cancel()` marca `status=cancelled` sin tocar el ledger. Guard: solo una sesión `open` por outlet a la vez (409 si ya existe).

### `Punto\Api\Services\StockAdjustmentService` — `/v1/stock_adjustment.php`

Ajuste puntual de stock con motivo. Pre-query de stockeables, skip de no-stockeables, loop `manageStock` con `source='adjustment'`. Form con motivos predefinidos + textarea libre + búsqueda de ítems.

### `Punto\Api\Services\StockTransferService` — `/v1/stock_transfer.php`

Transferencia de stock entre depósitos (outlets). `create()` abre TX atómica, itera ítems, doble `manageStock` (egreso `source='transfer'` en origen + ingreso en destino). `cancel()` reversa con `source='transfer-cancel'`. Guard 409 si el ítem no tiene stock suficiente en origen.

### `Punto\Api\Services\ReturnService` — `/v1/returns.php`

Devoluciones POS. Genera movimiento `type=6` con `parentTransactionId` heredado de la venta original. `FOR UPDATE` anti-race sobre la transacción padre. Devuelve efectivo (entry en `transactionPaymentType` JSON con `type:'cash'`, amount negativo) o acredita en `contactStoreCredit`. Sheet de devolución accesible desde el detalle de transacción con `parentTransactionId` precargado.

### `Punto\Api\Services\RegisterAdminService` — extends `/v1/register.php`

CRUD de cajas (registers) desde el panel `/settings/devices`. Soft-delete si la caja tiene transacciones históricas; hard-delete si no. 409 si hay devices activos usando la caja al momento del delete.

### `Punto\Api\Services\CreditPaymentService` — `/v1/credit-payments.php`

Pago de deuda en transacciones tipo crédito. Genera recibo `transaction type=5` con `parentTransactionId` y `getNextDocNumber(0, 5, ...)`. Si `amount === debt`, marca `transactionComplete=1` en la transacción padre (UPDATE en TX). Endpoint `POST /v1/credit-payments`.

### `Punto\Api\Taxonomies\LocationTaxonomyService` — extends `/v1/taxonomies.php`

CRUD de depósitos (locations/outlets) desde el detalle de sucursal en `/outlets/[id]`. Namespace `Punto\Api\Taxonomies`. POST/PUT/DELETE para `type=location`. UI con DataTable + dialog crear/editar + AlertDialog eliminar.

### `Punto\Api\Items\VariantService` — extends `/v1/items.php`

Variantes de producto Phase 1. `bulkUpsertVariants()` en TX única: genera combinaciones cartesianas de atributos, upsert por `(variantParentId, variantAttributes)`, stock inicial vía `Inventory::manageStock`. Invariantes: padre fuerza price/cost/stock=0, anti-anidamiento 409. UI: tab Variantes en el form de ítem con matriz editable + regeneración preservando filas existentes; badge "Variantes (N)" en listado + toggle "Mostrar variantes".

### AuthSentinel — handler global 401 en panel-next

`panel-next/components/auth/auth-sentinel.tsx`. Escucha el `CustomEvent("api:unauthorized")` emitido por `api-client.ts` antes de throwear un 401. Al dispararse: `queryClient.clear()` + toast "Tu sesión expiró" + `router.replace("/login")`. Debounce con `firedRef` + guard `pathname.startsWith("/login")`. **No montado en el layout del POS** — el POS usa `_jwt` device pairing (no sesión humana con expiración).

---

## Módulo Agente IA (sprint 2026-06-21, context/17)

Asistente de IA integrado en el panel y el POS. Plan completo en `context/17-ai-agent-plan.md`.

- **Provider**: OpenRouter (NO SDK Anthropic). Modelo default `deepseek/deepseek-chat-v3-0324`, configurable por tenant desde /admin (tabla `ai_model_config`, migración 43).
- **Route handler**: `panel-next/app/api/agent/chat/route.ts` — AI SDK v6 en modo OpenAI-compatible, tool `get_sales_summary` (AI-1) + 12 tools adicionales (AI-3).
- **13 tools**: 5 de lectura (sin confirmación) + 8 de escritura con `confirmToken` UUID expiración real 60s, 3 capas de defense-in-depth.
- **Créditos**: gate 402 + débito atómico en `/v1/ai/debit` (`SELECT FOR UPDATE` sobre `company.aiCreditsBalance`). Ledger `agent_chat` en `ai_credit_ledger`. Calibración pricing (creditsPerKToken vs costo real OpenRouter) pendiente.
- **UI (AI-3b)**: 3 componentes (`AgentChatContent`, `AgentChatFloating`, page `/chat`); historial Zustand persist con patrón `useChatHistoryHydrated` (`onFinishHydration`); markdown formateado (react-markdown + remark-gfm); copiar/voz; input ChatGPT-style; 5 sugerencias; mobile Sheet fullscreen.
- **FAB**: aparece en todas las rutas del panel; en `/pos` solo con el menú principal abierto.
- **Integración POS**: ítem en el menú principal del POS navega a `/chat`.
- **Pendiente**: AI-4 (UI /admin para `ai_model_config`), AI-5 (OCR + análisis libre).

---

## /panel/standalone — Pantallas independientes

**Propósito**: Vistas que corren en dispositivos dedicados (cocina, mostrador).

| Pantalla | Archivo | Canal WS | Uso |
|----------|---------|----------|-----|
| KDS (Kitchen Display) | `kds.php` + `kds.js` | `{outletId}-KDS` | Pantalla de cocina |
| KDS v2 | `kds2.php` | `{outletId}-KDS` | Variante |
| CDS (Customer Display) | `cds.php` + `cds.js` | `{outletId}-KDS` | Pantalla cliente |
| Checkout Screen | `checkoutScreen.php` | `{companyId}-{regId}-register` | Display de caja |

---

## /ws-server — WebSocket Microservice

**Propósito**: Reemplaza Pusher. Bridge real-time PHP → Browser.

**Stack**: Node.js 20 + ws@8.17 + ioredis@5.3

**Archivo único**: `ws-server/index.js` (229 líneas)

**Protocolo**:
```
Client → WS: { action: "subscribe", channel: "outlet123-KDS" }
PHP → Redis: PUBLISH punto:channel:outlet123-KDS '{...}'
WS → Client: { event: "order", channel: "outlet123-KDS", data: {...} }
```

**Canales**:
- `{outletId}-KDS` — Órdenes para cocina
- `{companyId}-{regId}-register` — Eventos de caja
- `ncm-ePOS` — Broadcasts del panel

**Config**: Puerto 6001, heartbeat 30s, auto-reconnect con backoff exponencial.

---

## /database — Migraciones y Seeds

**Migraciones**: `database/migrations/postgres/`
- `03_push_subscriptions.sql` — Web Push suscripciones

**Seeds**: `database/seeds/`
- `01_base.sql` — Planes, bancos, catálogo base
- `02_panel_user.sql` — Super admin
- `03_catalog.sql` — Catálogo de productos demo
- `04_sample_items.sql` — Items de ejemplo
- `run_seeds.sh` — Runner de seeds

---

## /scripts — Utilidades

| Script | Propósito |
|--------|-----------|
| `postgres-init.sql` | Extensiones + timezone al crear BD |
| `convert-schema.py` | Conversión MySQL → PostgreSQL |
| `migrate-mysql-to-postgres.sh` | Migración de datos |
| `mysql-to-postgres.sh` | Variante de migración |
| `setup-local.sh` | Setup del entorno local |
