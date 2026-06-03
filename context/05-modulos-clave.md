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
- ~~`v1/customer_address.php`~~ — **movido a `/api/v1/customer_address.php` (commit d75dd0b)**

**Nota (2026-05-28):** Los endpoints de los slices de desacople YA NO ESTÁN en `/app/API/v1/`. Fueron movidos a la API compartida `/api/v1/` (commit d75dd0b). `/app/API/` conserva solo `auth.php`, `config.php`, `refresh.php`.

**Archivos clave**:
- `app/includes/functions.php` — Utilidades (pagos, formateo, roles)
- `app/includes/jwt.php` — JWT HS256 encode/decode
- `app/includes/jwt_middleware.php` — Validación de JWT (también usada por `/api/bootstrap.php` vía chdir)
- `app/includes/ws_publish.php` — Publica eventos a Redis
- `app/includes/db.postgres.php` — Conexión a PostgreSQL
- `app/scripts/ncm-ws.js` — Cliente WebSocket
- `app/bff/lib/api_client.php` — Cliente HTTP BFF→API compartida (reenvía `_jwt`; apunta a `PUNTO_API_BASE`)

**Frontend**: Bootstrap 3 + jQuery, service worker para offline.

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

**Clientes actuales**: `/app/bff/*` (vía `app/bff/lib/api_client.php` que reenvía cookie `_jwt`). `/panel/bff/*` aún no consume /api (usa panel/API/ in-process); la migración es gradual.

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
| Front | `panel/admin/login.html`, `panel/admin/home.html`, `panel/admin/users.html` | Fronts estáticos standalone (sin shell tenant) |
| Front scripts | `panel/admin/scripts/login.js`, `panel/admin/scripts/users.js` | JS del realm admin — todo output escapado con `esc()` |

**Router** (`panel/router.php`): `/admin` + `/admin/login` → `panel/admin/home.html` / `panel/admin/login.html`; `/admin/users` → `panel/admin/users.html`.

**Estado**: F0 (tabla+seed) ✅, F1 (auth) ✅, F2 (CRUD super-admins) ✅ — F3 siguiente (companies+billing desde `main.php`). Ver plan completo en `10-roadmap.md § Admin realm`.

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
