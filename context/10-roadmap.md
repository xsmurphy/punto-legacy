<!-- REGLA: Este es el roadmap único del proyecto. Actualizar cuando:
     - Se completa un item (marcar ✅)
     - Se agrega un item nuevo
     - Cambian las prioridades
     - Se cierra una fase o se abre una nueva
     Antes era MODERNIZATION.md (consolidado acá el 2026-05-16). -->

# 10 — Roadmap Técnico

Roadmap único del proyecto Punto POS. Objetivo: modernizar progresivamente sin
big-bang rewrites, manteniendo el sistema funcional en cada etapa.

> **Última actualización:** 2026-05-31
> **Fuente histórica:** consolidado desde `MODERNIZATION.md` (eliminado)

---

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.x, sin framework, archivos monolíticos |
| DB | PostgreSQL 16 vía ADOdb + Docker ✅ |
| Frontend | Bootstrap 3 + jQuery, HTML mezclado con PHP |
| Auth (app) | JWT HS256 ✅ — cookie `_jwt` HttpOnly + fallback legacy activo |
| Auth (panel) | JWT HS256 ✅ — 68/68 endpoints con apiMiddleware() |
| IDs | UUID v7 ✅ — enc()/dec() identity, ncmInsert auto-genera PK |
| API | ~68 endpoints en `panel/API/*.php`, todos con envelope canónico ✅ |
| WebSockets | ~~Pusher~~ → ws-server propio (Node.js + Redis Pub/Sub) ✅ |
| Seguridad | Bypass key eliminado, CORS allowlist, debug gateado |

---

## Vista general de fases

```
Phase 0 ✅ → Phase 1 ✅ → Phase 2 ✅ → Phase 3 → Phase 6
                                  ↓
                       Phase WS ✅ (completado durante Phase 2)
                                  ↓
                       Phase UUID ✅ (enc/dec identity + UUID v7 en ncmInsert)
                                  ↓
                       Phase PG ✅ (PostgreSQL: schema v2 + JSONB + migrations PHP)
                                  ↓
                             Phase AI ← SIGUIENTE
```

---

## Orden de ejecución actual (prioridad)

1. **AHORA**: Phase AI.1 — agente básico + widget web + 5 tools solo lectura
2. **Luego**: Phase AI.2 — bot de Telegram
3. **Paralelo**: Migración endpoints legacy MySQL → PG (B2-B5), Phase 3, Phase 6

---

# Prioridad ALTA (próximas 4-8 semanas)

## Migración endpoints legacy MySQL → PostgreSQL (P0 seguridad + funcional)

**Problema**: 19 archivos PHP (endpoints API públicos + crons + utilities) usan conexión MySQL hardcoded a una BD que ya no existe (`incomepo_905`, `incomepo_rucpy`). Las credenciales están en el repo Git e historial. **Los endpoints están desplegados y se usan, pero todos fallan al conectar.**

**Decisión del usuario (2026-05-16)**: NO BORRAR los archivos — son endpoints vivos. Migrar la referencia de BD.

**Lista exacta** (auditada 2026-05-16):
- EASY (5): `panel/API/get_payment_methods.php` ✅, `get_check_issuing.php` ✅, `edit_inventory.php`, `edit_customers_test.php`, `add_customers_test.php`
- MEDIUM (9): `panel/API/add_items[_test].php`, `add_inventory[_test].php`, `edit_items.php`, `panel/crons/cronTrialAboutToExpire.php`, `cronCreateInvoices.php`, `app/tin.php`, `app/rucs.php`
- HARD (5): `panel/API/delete_items.php` (función mal nombrada), `delete_inventory.php` (llama `createInventory()` que NO existe — bug fatal), `dbcreator.php`/`dbcopier.php` (DDL MySQL hardcoded), `panel/API/get_inventory.php` (`die()` arriba)

**Bugs preexistentes detectados durante auditoría**:
- `delete_inventory.php:69`: llama función inexistente `createInventory()` → endpoint nunca ejecuta DELETE
- `delete_items.php`: función interna mal nombrada `editItem()` (debería ser `deleteItem()`)
- `add_inventory_test.php:42`: `outletId = 2446` hardcodeado

**Plan — 5 batches**:
1. **B1** ✅ COMPLETO: Crear `panel/API/lib/legacy_db.php` helper que reemplaza el bloque MySQL hardcoded
2. **B2 EASY**: Aplicar helper a los 5 EASY. **Estado: 2/5 migrados** (`get_payment_methods.php`, `get_check_issuing.php`)
3. **B3 MEDIUM**: Crons + APIs simples (5 archivos)
4. **B4 MEDIUM+HARD**: Inventory APIs + arreglar los 2 bugs en delete_*
5. **B5 Decisión separada**: `tin.php`/`rucs.php` (recrear `incomepo_rucpy` en PG o descontinuar fallback), `dbcreator/dbcopier` (deprecar o reescribir), endpoints con `die()` (reactivar o limpiar)

**Esfuerzo total**: ~12-15 horas (sin B5)

**Riesgos**: Estos endpoints son API pública para integraciones externas. Cualquier cambio de comportamiento puede romper apps cliente. Mitigar: tests con curl + payloads ejemplo antes/después.

---

## Admin realm — super-admins de plataforma separados (iniciado 2026-05-28)

**Decisión**: los super-admins de plataforma dejan de ser un "tenant especial" (flag `SAAS_ADM` sobre `MASTER_COMPANY_ID`) y pasan a ser usuarios propios en `admin_user`, con login en `/admin` y JWT criptográficamente separado del realm tenant. Ver [ADR-002](context/adr/ADR-002-admin-realm-separado.md) y `02-arquitectura.md § Admin realm`.

**Dos realms aislados:** `_jwt_panel` (tenant, `JWT_SECRET`) nunca valida en `/admin`; `_jwt_admin` (`ADMIN_JWT_SECRET`, `aud:"admin"`) nunca valida en el panel tenant. Secrets + cookies + audience distintos.

**Login de tenant:** los tenants mantienen su login pero por **TELÉFONO** (no email). `findEmailOrPhoneLogin` ya tiene el branch de phone. El login de tenant NO se depreca, solo cambia el identificador.

**Franchiser:** sigue como realm tenant (`/panel/franchiser.php`, gateado por `isParent`) — NO va a `/admin`.

### Plan de fases (6 fases, no big-bang — cada una deployable)

| Fase | Qué | Estado |
|------|-----|--------|
| **F0** | Tabla `admin_user` (migración 09) + `bootstrap_seed.php` (CLI idempotente, bcrypt) + vars `.env` (`ADMIN_JWT_SECRET/TTL`, `ADMIN_BOOTSTRAP_EMAIL/PASSWORD`). Verificado E2E en DB local. | ✅ HECHA (commit 01a8929, 2026-05-28) |
| **F1** | Auth del realm `/admin`: login email+pass, JWT propio (`_jwt_admin`, `aud:"admin"`), `adminMiddleware`, `login.html` estático + BFF, rate-limit. | ✅ HECHA (commit 96f8b8f, 2026-05-28) |
| **F2** | CRUD de admins en `/admin` (modelo BFF 3 capas). No permitir desactivar el último admin activo. | ✅ HECHA (commit 89e7388, 2026-05-28) |
| **F3** | Home `/admin` + migrar gestión de companies + billing desde `main.php` (queries cross-tenant aisladas en `lib/admin`). | **SIGUIENTE** |
| **F4** | ⚠️ RIESGO ALTO — desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant (quitar redirect `@.php:11`, limpiar `config.php`). Va ÚLTIMO porque rompe el gate de identidad legacy. | Pendiente |
| **F5** | Login de tenant por teléfono (no email) — independiente de F1–F4. | Pendiente |
| **F6** | Decommission de `main.php` como admin + hardening + verificar aislamiento de realms E2E. | Pendiente |

**Notas técnicas F0:**
- `admin_user`: UUID PK, email único case-insensitive (`lower(email)`), `passwordHash` bcrypt, `status` 1/0, `createdBy` self-FK, `lastLoginAt`, timestamps. Sin `companyId`.
- `bootstrap_seed.php`: CLI-only (`PHP_SAPI === 'cli'`), no loguea el password, no-op si el admin ya existe.
- `MASTER_COMPANY_ID` **no es más gate de identidad** post-F4, pero sigue intacto hasta entonces. No tocar el redirect de `@.php:11` ni `SAAS_ADM` hasta F4.

**Notas técnicas F2:**
- Stack BFF 3 capas completo: `panel/lib/admin/AdminUserService.php` (data layer — list/get/create/update/setStatus) + `panel/API/v1/admin/users.php` (gateado por `adminMiddleware()`, `_jwt_admin`, `aud:"admin"`) + `panel/bff/admin/users.php` (proxy cookie) + `panel/admin/users.html` + `panel/admin/scripts/users.js` (front standalone, todo escapado con `esc()`). Router: `/admin/users → /admin/users.html`. `home.html` linkea al CRUD.
- **Gotcha DB::Execute / INSERT…RETURNING**: `create()` usa `$db->Insert() + Insert_ID()` en vez de `INSERT…RETURNING` porque `DB::Execute()` solo materializa filas para sentencias que empiezan con `SELECT` o `WITH`. Un `INSERT…RETURNING` devuelve recordset vacío aunque inserte la fila, causando falso fallo. Aplica a todo insert que necesite el UUID generado.
- **Follow-up P1 (no bloqueante, diferido a F3+):** `bffFailFromApi()` en `panel/bff/lib/api_client.php` colapsa todo status !=401/403 a 502 — los 422 de negocio del service llegan al browser como 502 (el texto del error sí surface). Afecta toda la infra BFF compartida del realm tenant; recablear 422/404/409 verbatim queda fuera de scope de F2 para no arriesgar regresión.

---

## Desacople del monolito /app (POS) — patrón Front→BFF→API→Service (iniciado 2026-05-28)

El mismo patrón de 3 capas del panel se aplica al POS `/app`. El dispatcher monolítico `action.php`/`load.php` (con ~43+ concerns) se migra concern-por-concern. `action.php`/`load.php` siguen sirviendo los concerns no migrados (migración incremental, no big-bang).

**IMPORTANTE (commit d75dd0b, 2026-05-28):** Los endpoints y servicios de los 5 slices YA NO viven en `/app/API/v1/` ni en `/app/lib/`. Fueron extraídos a la **API compartida `/api` top-level** (hermano de /panel y /app). Los BFFs de /app apuntan a `/api` vía `PUNTO_API_BASE`.

**Slice 1 COMPLETO — `customerAddress` (commit d79cfa4, luego movido en d75dd0b)**:
- Front: 5 call-sites de `ncmCustomer.address.*` en `app/scripts/debug.js` repuntados a `/bff/customer_address?l=` (solo cambia el path; el payload `?l=` base64 es idéntico).
- BFF: `app/bff/customer_address.php` (decodifica `?l=`, rutea a `/api`, traduce al shape legacy del front) + `app/bff/lib/api_client.php` (cliente curl, reenvía `_jwt`, apunta a `PUNTO_API_BASE`).
- API: `api/v1/customer_address.php` (JWT-gated vía `apiAuthTenant()`) + `api/lib/response.php` (envelope canónico).
- Service: `api/lib/services/CustomerAddressService.php` (list/add/update/delete/setDefault; tenant-scoped; transacciones atómicas).
- Verificado E2E (curl, server :8002→:8000, JWT real): list/add/update/delete/setDefault OK; default correcto en clear-then-insert; inyección rechazada.

**Slice 2 COMPLETO — mesas `rename`/`unreserve` (commit e9f694c, luego movido en d75dd0b)**:
- `api/lib/services/TableService.php` (UPDATE sobre `transaction` type 11, por companyId+outletId+transactionName, parametrizado) + `api/v1/tables.php` + `app/bff/tables.php` + 2 call-sites en `debug.js`.
- Fixes PG del legacy: OUTLET_ID bindeado (UUID sin comillas), `transactionName` (VARCHAR) comparado como string, + scope por companyId.
- Verificado E2E: rename (note) + unreserve (status=1) confirmados en BD.

**Slice 3 COMPLETO — `setUserToSpace` (commit 2b9b437, luego movido en d75dd0b)**:
- `TableService::assignUser` (UPDATE userId type 11, parametrizado + companyId/outletId) + op en `api/v1/tables.php` + 1 call-site.
- El push al usuario asignado va **best-effort** (`try/catch \Throwable`).
- Verificado E2E: assign con FK válida → 200; FK inválida rechazada (502).

**Pendiente en mesas/órdenes — DIFERIDOS por estar ROTOS en PG (necesitan fix semántico, no solo port):**
- ✅ `joinSpaces` — **RESUELTO (commit 5642a1c, 2026-05-30)**: `TableService::joinSpaces(companyId, outletId, tFrom, tTo)` — resuelve el `transactionId` (UUID) de la mesa destino, marca la mesa origen como hija (`transactionParentId = ese UUID`, consumido por `closeTable`/`listTables`), reasigna sus órdenes (type 12) de tFrom a tTo. Devuelve 404 si la mesa destino no existe. `api/v1/tables.php` PUT `?resource=join` body `{from,to}`. `app/bff/tables.php` action `joinSpaces`. `globalv2.js` + `debug.js` repuntados a `bff/tables`.
- ✅ `moveOrders` — **RESUELTO (commit 5642a1c, 2026-05-30)**: `TableService::moveOrders(companyId, outletId, registerId, userId, tFrom, tTo)` — mueve las órdenes (ítems) de una mesa a otra y ABRE la mesa destino si estaba cerrada (INSERT type 11; PK por DEFAULT `gen_random_uuid()`). No es fusión: no marca `transactionParentId`. Guard "Debe abrir el espacio" eliminado del front (ahora el backend abre la mesa destino); chequeo `joined` null-safe. `api/v1/tables.php` PUT `?resource=move`. `app/bff/tables.php` action `moveOrders`.
- `setUserToOrder`: reescribe `transactionDetails`, columna **absorbida a `meta` JSONB** (ya no existe como columna) → requiere `jsonb_set` sobre `meta`. Va en un slice de "órdenes" (type 12) con el manejo correcto de `meta`.
- `closeTable`: **cascada** (borra la mesa + sus órdenes) → slice dedicado con transacción.
- `moveOrderItems`, `transferOrderToOutlet`: mueven ítems/órdenes entre mesas/outlets — dominio "órdenes".

**Slice 4 COMPLETO — calendario (commit 1cae3c4, luego movido en d75dd0b)**: `api/lib/services/ScheduleService.php` (`rescheduleTo` → UPDATE toDate preservando fromDate; `unlock` → DELETE) + `api/v1/schedule.php` + `app/bff/schedule.php` + 2 call-sites. Fixes PG: transactionId/companyId bindeados, DELETE sin LIMIT, validación de formato de hora. **Diferidos**: `updateSchedule`/`scheduleSession` (escriben `transactionDetails` → `meta` JSONB).

**Slice 5 COMPLETO — customerNote (commit 56afb0c, luego movido en d75dd0b)**: `api/lib/services/CustomerNoteService.add` (INSERT `contactNote`, parametrizado + companyId) + `api/v1/customer_note.php` + `app/bff/customer_note.php` + 1 call-site. E2E OK.

**Slice 6 COMPLETO — TransactionService (commit 866052b, 2026-05-28)**: `deleteTransaction` + `deleteInPrintServer` + `rejectOrder` (+ sendWS best-effort) + `deleteItemHistory` (markupt2HTML preservado). `api/lib/services/TransactionService.php` + `api/v1/transactions.php` + `app/bff/transactions.php`.

**Slice 7 COMPLETO — OrderService.accept (commits be998d5 + fix 3b81914)**: `acceptOrder` → status 2 + push/WS/email/SMS al cliente. Bug del legacy corregido: enviaba `enc($id)` al WS con `$id` indefinido → se usa `$transactionId`. `setUserToOrder` se **revirtió/difirió** (escribe transactionDetails → meta jsonb).

**Slice 8 COMPLETO — SyncService (commit e85bfdb)**: `chkDeletedItems` + `chkDeletedCustomers` (checks de sync offline). Mejora: `IN(...)` parametrizado (chunked 20000) en vez de traer 50k filas y diffear en PHP. Verificado contra DB real + tenant-isolation.

**Slice 9 COMPLETO — OrderService.transferToOutlet (commit 31e2396)**: `transferOrderToOutlet` → UPDATE outletId con validación de orden+outlet existentes (reasons order_not_found/outlet_not_found/update_failed → 404/500).

**Slice 10 COMPLETO — RegisterService.setSession (commit e8cd12c)**: `setSession` → UPDATE register.sessionId + broadcast WS best-effort. `checkSession` NO portado: dead code (front usa WS bind, no HTTP).

**Slice 11 COMPLETO — TableService.closeTable (commit 22ac0eb)**: `closeTable` → 3 ops (borra mesa type 11 según kind any/customer/table + borra unidas sólo kind=any + finaliza órdenes type 12 a status 4). match column vía `match($kind)` (literal interno, no input).

**Slice 12 COMPLETO — CurrencyService (commit e107bf8)**: `setCurrencies` → lista de monedas extranjeras con tasa (config compute sobre $_fullSettings/$_COUNTRIES_H, sin DB). GET endpoint.

**Cluster ENCOM→Punto (slices 13-15, 2026-05-28)** — migra a /api los handlers de /app que proxyaban (vía `API_ENCOM_URL`, **roto en dev**) a endpoints canónicos de `panel/API/`. "ENCOM" = nombre viejo del sistema (hoy "Punto"), NO código muerto. Cambio de auth: api_key+company_id (request) → JWT. La lógica se portó desde `panel/API/*.php`. **panel/screens siguen consumiendo `panel/API/` por ahora** (no roto; migración futura).

- **Slice 13 — AttendanceService (commit 3be3ae5)**: `clockIn` → toggle de turno (tabla `attendance`). Token QR (md5(companyId.outletId)) verificado con hash_equals. BFF devuelve `{error,type}` plano; ante fallo `{error:true}` (el front no chequea ok → un fallo no debe verse como éxito).
- **Slice 14 — NotificationService (commit 17443c8)**: `notifications` (op=list, marca visto → POST no GET, §16) + `notificationsCount` (op=count). Quirk legacy: list no filtra por notifyMode, count sí.
- **Slice 15 — VPaymentService (commit c1fce01)**: **MONEY PATH**. Los 3 handlers de pago (`ePOSAddCardTransaction`/`cajaPOS…`/`PixAddTransaction`) → port FIEL de `panel/API/add_vpayment.php` (idempotencia, comisión/payout sobre eposData, cascada factura-crédito type-5). Decisiones: branch 4456 omitido (dead int vs uuid), generateUID inlineado, guard div-por-cero, getNextDocNumber 4-arg (/app) no 3-arg (panel). BFF nunca reporta éxito si falla. `verifyQRPaymentCode` = dead (sin port).

**Producción repuntada (commit 5f1b367)**: descubierto que `globalv2.js` es el front de PRODUCCIÓN (debug.js era sólo para pruebas) y los slices 1-13 sólo habían tocado debug.js → producción seguía en legacy. Backfill de globalv2.js con los 11 repoints + notifications + vpayments. Ver convención §22.2b.

**Retrofit REST de /api/v1 ✅ COMPLETO (2026-05-28)**: los slices 6-15 usaban POST+`op` (RPC), divergente del REST de `panel/API/v1`. Retrofiteados los 11 endpoints + BFFs a GET/POST/PUT/DELETE (commit `6b8082a`), convención §22.7. Infra: shim `php://input`→`$_POST` en `api/bootstrap.php` + `bffApiPut/bffApiDelete` (commit `d7b34cb`). El service layer NO cambió. Recursos por `?id=`, sub-recursos/sub-acciones por `?resource=`.

**BUG P0 SISTÉMICO CERRADO (commit e3d02cc, 2026-05-31) ✅:** `bffApiGet` llamaba a `bffApiSend` con 3 args → método defaulteaba a `'POST'` → todos los reads GET llegaban a la API como POST → "Operación no reconocida" en cualquier endpoint gateado por `$method === 'GET'` (§22.7). Rompía CADA lectura vía BFF en uso real del front: customer info/records, item info, register docNumbers, orders, tables, schedule, transactions. Pasó desapercibido porque los slices se verificaron con curl GET directo, no vía BFF. Fix: `bffApiGet` pasa `'GET'` explícito. **QA pendiente**: los slices de lectura previos deben re-verificarse a través del front (no solo curl) para confirmar comportamiento correcto post-fix.

**Gap de producción cerrado (commit `84be19f`)**: los slices 6-13 habían construido BFF+API pero NO repuntado el front → producción seguía en `action?l=` (legacy). Repuntados los 11 call-sites de slices 6-13 a sus BFFs en globalv2.js + debug.js. Quedan en `action?l=` SÓLO los handlers no migrados (sale, processData, cluster meta-JSONB, joinSpaces/moveOrders, chkGiftCard, consultStatusElectronicInvoice).

### Reestructura + vendoreo de /app (EN CURSO — Tiers 1-3 hechos 2026-05-30)

Tarea estructural iniciada (ortogonal al desacople). Estado por tier:

- ✅ **Tier 1 (commit fff7d7490)**: borrados `app/encom_chrome.crx` (binario extensión Chrome con marca ENCOM vieja, 60KB) y `app/pdftest.php` (archivo de prueba, 81 líneas). 0 referencias en el repo.
- ✅ **Tier 2 (commit 975a30d)**: iconos PWA movidos de `app/` raíz a `app/assets/icons/` (android-/apple-/ms-icon, favicon-16/32/96, splash). Refs actualizadas en `manifest.json`, `browserconfig.xml` y los `<head>` de `index.html`/`index.php`/`schedule_calendar.php`. `favicon.ico` queda en root (well-known path). APP_VERSION → 2.0.9.5. **Deuda preexistente NO tocada** (refs muertas desde antes, sin archivo en disco): los `<head>` referencian `apple-touch-icon-*` (los reales son `apple-icon-*`), `favicon-196x196`, `favicon-128` y `mstile-*` (los reales son `ms-icon-*`) — reconciliar cuando se regenere el set de iconos.
- ✅ **Tier 3 (commit e97aed7)**: `globalv2.js` renombrado a **`app/scripts/app.js`** (nombre con sentido) y `debug.js` **ELIMINADO** (era un duplicado byte-idéntico mantenido a mano — §22.2b resuelta). `app/includes/assets.php` colapsado a un único `/scripts/app.js` (sin selector debug/mobile/normal). Refs actualizadas en `index.html`, `cache-sw.php`, `filesCompiler.php`, `build.sh`. APP_VERSION → 2.0.9.6 (invalida SW).

**Pendiente de la reestructura:**
- Vendorear deps JS vía npm en vez de copias manuales (Fase B del vendoreo — diferida por el usuario; ver entry 2026-05-28 del session-log).
- Unificar `fetch.php` / `fetchs.php` si quedan vivos al vaciar los dispatchers.
- Mover/renombrar `action.php`/`load.php`/`fetch.php` cuando se vacíen por completo.
- ✅ **Rebrand de iconos ENCOM → Punto (HECHO)**: set completo regenerado desde `Media/logo/punto_iso.svg` (cairosvg + Pillow, script temporal). favicon/android transparentes; apple/ms-tiles/splash con fondo blanco. Se crearon los que faltaban (apple-touch-icon-*, favicon-128/196, mstile-* incl. 310x150) + `favicon.ico` multi-res, y se arreglaron las refs muertas de los `<head>` → `/assets/icons/`. APP_VERSION → 2.0.9.7.

**Servicios legacy (en `/api/lib/services/` — sin namespace, 18 servicios)**: `CustomerAddressService`, `TableService` (rename/unreserve/assignUser/closeTable/**listTables** — slice 21; **joinSpaces**/**moveOrders** — commit 5642a1c), `ScheduleService` (rescheduleTo/unlock — slice 4; updateSchedule/scheduleSession/checkIfUserOccupied — slice 20; **getSessionsList** — slice 30; **getAgendaList** — slice 31), `CustomerNoteService`, `TransactionService` (**getTransactionList(listType,…)** — slice 28: quotes/saved + **getMainList()** — slice 29: transactions panel de ventas), `OrderService` (accept/transferToOutlet/assignUser + **customerHasOpenOrders** — slice 23 + **queryOrderRows/getTableClose/getTableDetail/getList** — slice 27), `SyncService`, `RegisterService` (**setSession** — slice 10 + **docNumbers** — slice 22), `CurrencyService`, **`CustomerService`** (**getInfo()** — slice 32: resumen del cliente, read-only salvo backfill lazy de customerAddress + **getRecords()** — slice 33: fichas personalizadas, datos estructurados + **5 recursos granulares** — commit c4edef9: `getProfile`, `getRecentItems`, `getDebt`, `getGiftcards`, `getAddress` — patrón API granular + BFF compone, cada uno reusable e independiente). Plomería: `app/bff/lib/api_client.php` (**`bffApiGetMulti()`** + **`bffDecodeEnvelope()`** — commit c4edef9) + `api/lib/response.php`.

**Servicios modernos (en `/api/lib/<Module>/` — namespace `Punto\Api\<Module>`, PSR-4, DTOs, enums)**: **`SaleService`** — ubicado en `api/lib/Sales/SaleService.php` (namespace `Punto\Api\Sales`; commit 4037977). Primer módulo del proyecto con namespace + DTOs + autoloader PSR-4. Implementación incremental completada hasta 35c. Métodos principales: `save()` (entry point), `persistItemsAndStock()`, `persistGiftCardRedemptions()`, `redeemGiftCard()`, `sellGiftCard()`, `notifyGiftCardBeneficiaries()`, `persistLoyaltyEarning()`. DTOs: `SaleInput` (fromPayload, validación fail-fast, `assertSimplePathEligible` delega en `saleIsSimplePathEligible()`), `SaleResult`. Enum: `SaleType` (Cashsale=0, Creditsale=3, etc.). Excepciones: `InvalidSaleInputException`, `DuplicateSaleException`, `SaleAbortedException`. Context: `api/lib/Context/TenantContext.php` (DTO inmutable, namespace `Punto\Api\Context`). **Este es el patrón a seguir para módulos nuevos** — ver §22.9 en `08-convenciones.md`.

### Refactor de `app/scripts/app.js` — DIFERIDO (decisión 2026-05-30)

**Estado actual**: 26.927 líneas, 34 módulos globales `ncm*` (`Menu`/`ActionSheet`/`Modules`/`Spaces`/`Maths`/`FE`/`Payments`/`Helpers`/`WebSockets`/`Events`/`Orders`/`Calendar`/`Notify`/`DatePicker`/`Transactions`/`Alerts`/`Dialogs`/`Globals`/`UIX`/`Tutorial`/...), **1.654 cross-references** entre módulos, **0 tests automatizados**.

**Decisión**: NO hacer refactor de app.js como proyecto separado por ahora. El refactor por partes sin tests automatizados es ruleta rusa; el acoplamiento real (1.654 refs cruzadas) hace que "por partes" arrastre dependencias; y el backend desacople (slices 1-34+) ya está limpiando el front incrementalmente sin proyecto paralelo. El riesgo más caro de /app no está en app.js sino en `processData` (money path).

**Plan diferido** (cuando haya capacidad y tests):

1. **Split sin refactor** (~1 día, riesgo cero): mover módulos ya aislados a archivos propios, sin tocar lógica. Concatenación en `build.sh`/`filesCompiler.php` produce el mismo bundle.
   - `ncmMaths` (~270 líneas) → `app/scripts/maths.js`
   - `ncmAlerts` (~280 líneas) → `app/scripts/alerts.js`
   - `ncmDatePicker` (~1070 líneas) → `app/scripts/date-picker.js`
   - `ncmHttp` (~150 líneas) → `app/scripts/http.js`
   - `ncmWebSockets` (~145 líneas) → `app/scripts/websockets.js`
   - `ncmTutorial` (~640 líneas) → `app/scripts/tutorial.js`
   - Resultado: ~2.500 líneas afuera, `app.js` baja a ~24k sin cambio de comportamiento.

2. **Tests E2E primero** (~2-3 días, ROI altísimo, **prerequisito de cualquier refactor real**): Playwright sobre los 6 flujos críticos ya verificados manualmente:
   - Login → grilla con productos
   - Carrito + cobrar Efectivo (sin cliente / con cliente)
   - Venta a crédito
   - Modificar count antes de cobrar
   - Crear/editar/eliminar cliente
   - En CI → cualquier refactor pasa de ruleta rusa a verde/rojo en minutos.

3. **Slicing dirigido por backend** (lo que ya hacemos): cuando un slice del desacople toca un módulo del front, extraer ese módulo en el mismo commit. Ej.: al migrar `processData → SaleService`, mover `ncmTransactions`+`ncmItems` a archivos propios como parte del mismo trabajo.

**Lo que NO se hace**: "gran refactor de app.js" en proyecto separado, antes de tests, mientras el backend desacople está en curso.

### action.php — mapa de lo que queda (post Slice 12, 2026-05-28)

Los **handlers limpios están agotados**. Lo restante son clusters con dependencias:

- **Cluster meta-JSONB (8 handlers) — ✅ COMPLETO (slices 18-20, 2026-05-28)**: `setUserToOrder` (slice 18, OrderService.assignUser), `removeItemfromOrder`/`processOrderItems`/`processOrderItemsUpdate`/`moveOrderItems` (slice 19, OrderItemsService), `updateSchedule`/`scheduleSession`/`checkIfUserOccupied` (slice 20, ScheduleService). Helper `api/lib/meta_transaction.php` (`txMetaRead`/`txDetailsFromMeta`/`txDetailsWrite`): read-modify-write de `transactionDetails` dentro de `meta` (jsonb) preservando otras keys (tags); usa `$db->Execute` directo (no ncmExecute, que aplica `_flattenJsonb` y elimina la key `meta`). Lecturas multi-fila (checkIfUserOccupied) hacen `SELECT meta` + decode por fila. Lógica de matching (itemId/oPosition/parent) y quirks (keys userId vs user) portados verbatim. Todo verificado contra DB real.
- **Cluster ENCOM→Punto ✅ HECHO (slices 13-15)**: `clockIn`✅ `notifications`✅ `notificationsCount`✅ `ePOSAddCardTransaction`✅ `cajaPOSAddCardAndQrTransaction`✅ `PixAddTransaction`✅. `verifyQRPaymentCode`=dead (borrar). Análisis KDS hecho: los endpoints canónicos viven en `panel/API/`; el verdadero nodo compartido /app↔KDS↔CDS es `get_orders.php` (type 12) — va con la migración de load.php (lectura de órdenes), NO con este cluster. panel/screens siguen en panel/API por ahora. `send_webSocket` ya cubierto vía `sendWS()`.
- **Mesa-merge (2 handlers) — ✅ RESUELTO (commit 5642a1c, 2026-05-30)**: `joinSpaces` → `TableService::joinSpaces` (une mesa origen en destino via UUID); `moveOrders` → `TableService::moveOrders` (mueve órdenes + abre destino si cerrada). Los bugs int→UUID del legacy corregidos: nº de mesa en columna UUID, varchar vs int sin comillas, UUIDs sin comillas, `USE INDEX` (MySQL), params desalineados. Todo parametrizado + scope companyId+outletId del JWT. Ver sección "Pendiente en mesas/órdenes" arriba para detalles de la semántica.
- **`processData` (~1540 líneas)**: el guardado de ventas. **ESTADO ACTUAL (2026-05-31 post-35c.2)**: SaleService es AUTORITATIVO para ventas simples (cashsale/creditsale type 0/3 elegibles) **y para gift card (vender + pagar — 35c ✅)**. El legacy YA NO es safety-net del path simple ni de gift card — lo RECHAZA con 409. El legacy sólo retiene los sub-slices pendientes de migrar: EI (35b), sesiones (35d), inCredit/storeCredit/points (35e), recurrente (35f), ruteados vía 422. La elegibilidad la determina `saleIsSimplePathEligible()` en `functions.php` (fuente única compartida entre tiers — ver `02-arquitectura.md § Guardado de ventas`). **Flujos verificados E2E en PG (legacy)**: venta cashsale básica ✅ (commit b45684f), venta con cliente seleccionado ✅ (commit b0617ea), venta a crédito type=3/dueDate/complete=false ✅, modificar items en carrito (count 1→5) ✅. Bugs encadenados resueltos: (1) UUID NULL para customerId/transactionParentId=0, (2) timestamp vacío → NULL, (3)-(4) columnas `contactFixedComission`/`itemComissionPercent`/`itemComissionType`/`itemSessions` demoted a JSONB → `SELECT *` + `_flattenJsonb` + filtro PHP (§22.8), (5) `getValue('item','itemSessions',...)` con UUID sin comillas + columna demoted → mismo patrón, (6) `updateLastTimeEdit()` escribía a columnas `company.*LastUpdate` inexistentes en PG → RMW sobre `config` JSONB con `$db->Execute` directo (§22.8.1). **Paths NO migrados aún (post-35c)**: EI (factura electrónica / 35b), sesiones agendadas (35d), inCredit/storeCredit/points (35e), recurrente (35f). **Migración a SaleService — SLICE 35 (strangler-fig incremental)**: BFF→API→Service (`api/lib/Sales/SaleService.php`, `api/v1/sales.php`, `app/bff/sales.php`); front (`app/scripts/app.js`, `ncmHttp.postSale()`) repuntado en 35a.7. Mapeo por bloques B1-B16 del legacy. Plan de sub-slices:
  - **35a.1** ✅ **COMPLETO + REFACTOR a §22.9** (commits caf63fe esqueleto + 4037977 modernización): SaleService + endpoint + BFF.
  - **35a.2** ✅ **COMPLETO** (commit 53b68bd): B1 (parse + dupli check por transactionUID + StartTrans) + B3 (INSERT transaction con meta JSONB doble-encode, NULL-coalesce UUID/timestamp, transactionComplete por tipo). Mejoras vs legacy: IDOR clientId cross-company cerrado, status range enforced, type no-numérico rechazado, race-safe vía UNIQUE 23505→duplicate.
  - **35a.3** ✅ **COMPLETO** (commit 11a159e): B4 (toTaxObj) + B6 (toAddress, IDOR addressId cerrado) + B7 (toTag, FIX PG: legacy hacía intval sobre UUID → ahora preserva UUID + valida FK/tenant). Fix meta.tags → JSON-string (los readers OrderService:229/TransactionService:41 hacen json_decode).
  - **35a.4** ✅ **COMPLETO** (commit 6ea1e5a): B8 — `persistItemsAndStock()`: loop de items → lookup itemType/itemPrice + comisiones (usuario/item) + COGS por tipo + INSERT itemSold + compounds (manageStock recursivo) + manageStock del ítem principal. Defensa en profundidad: `assertSimplePathEligible` (rechaza payloads no-simples → 422) + `assertNoScheduledItems` (pre-flight antes de StartTrans). Verificación post-commit: SELECT confirma que la fila `transaction` realmente persistió (guard contra rollback silencioso del wrapper — ver §22.8.2). Verificado E2E: stock 100→98, COGS correcto, multi-item, comisión fija de usuario aplicada. **FIXES GOD-HELPER manageStock** (app/includes/functions.php) — estaba 100% ROTO en PG para items stockeables (afecta también al legacy processData — ninguna venta con tracking de inventario descontaba stock): UUID sin comillas corregidos (§22.5), getValue('setting',...) → constante COMPANY_NAME (tabla `setting` no existe en PG), iftn($x,NULL) → `?: null` (footgun: iftn nunca devuelve NULL — ver §22.8.2), is_array($stock) → instanceof ArrayAccess + isset() (ncmExecute devuelve CaseInsensitiveArray).
  - **35a.5** ✅ **COMPLETO** (commit 1a8d539): B10 loyalty earning + payment eligibility. FIX god-helper `manageCustomerLoyalty` (app/includes/functions.php): removido `$db->Prepare()` que qstr-quoteaba el monto (8000 → '8000') rompiendo comparaciones numéricas; SELECT * para `loyaltyMin`/`loyaltyValue` demoted a JSONB; amount parametrizado. Verificado E2E: 80 pts acumulados.
  - **$db->Prepare() ELIMINADO (commit 2b37d26, 2026-05-31)** ✅: los 5 usos restantes de `$db->Prepare()` en `app/includes/functions.php` eliminados. Funciones saneadas: `manageCustomerStoreCredit` (+ fix comillas dobles en `updated_at`), `update register`, `checkIfExists` (dead code), `getSalesByPayment` (fechas mal preparadas → BETWEEN parametrizado), `voidSale`. **FIX P0 en voidSale**: Prepare quoteaba el UUID de `$trId` → bind params con comillas literales → SELECT inicial devolvía vacío → TODA la restauración al anular (loyalty/storeCredit/giftcard/inventario) se saltaba en silencio. Ahora `$trId` crudo + bind correcto. `$db->Prepare()` queda sin usos en `app/includes/functions.php`.
  - **35a.6** ✅ **COMPLETO** (commit a52ecf6): B14+B15 notificaciones post-commit best-effort (email/SMS al cliente + sendAuditoria). FIX god-helper `getContactData` (app/includes/functions.php): UUID sin comillas en el WHERE → devolvía FALSE para TODOS los clientes (rompía notificaciones + modales de cliente + reportes). Ahora quoted. Patrón: cada side effect en try/catch \Throwable, nunca lanza; un fallo externo no afecta la venta ya commiteada.
  - **BACKEND SaleService COMPLETO (35a.1–35a.6)** para el path simple (cashsale/creditsale). God-helpers arreglados durante el slice 35 que afectaban también al legacy processData: `manageStock` (35a.4), `manageCustomerLoyalty` (35a.5), `getContactData` (35a.6), `getCurrentOutletName` — todos tenían bugs PG (UUID sin comillas, $db->Prepare, JSONB mal leído).
  - **35a.7** ✅ **COMPLETO — MILESTONE** (commit 89b980e, 2026-05-31): repunteo del front de producción (`app/scripts/app.js`) al SaleService nuevo. `ncmHttp.postSale()`: ventas type 0/3 van a `bff/sales` (SaleService); ante 422 (no elegible) o error/timeout (2500ms) → fallback a `postToServer(legacy)`. Ítems no-venta (updateDocNumber, type!=0/3) → legacy sin cambio. Idempotencia dual-path: UNIQUE constraint en ambos paths evita duplicados incluso en el race condition (SaleService persiste + timeout + fallback → "Duplicated Entry" → `ifIstrue=true` → UID marcado). **El guardado de ventas simples está LIVE en producción por el nuevo path, con safety-net legacy.** APP_VERSION 2.0.9.7 → 2.0.9.8 (invalida SW).
  - **35a.8** ✅ **COMPLETO** (commit dbf2866, 2026-05-31): deprecación del path simple en `processData`. SaleService es ahora AUTORITATIVO para type 0/3 simple; el legacy YA NO es su safety-net. Cambios: (1) NUEVA función global `saleIsSimplePathEligible($payload,$sale):?string` en `app/includes/functions.php` = FUENTE ÚNICA DE VERDAD de la elegibilidad (null=simple | string motivo) — compartida por ambos tiers sin duplicar la regla. (2) `SaleInput::assertSimplePathEligible` (API) delega en ella → 422 para no-simples. (3) `processData` (legacy): guard ANTES de `StartTrans` — type 0/3 simple → `jsonDieMsg` 409 (`ifIstrue=false` en el front → orphans → reintenta SaleService). Las no-simples (motivo devuelto) NO se rechazan → siguen en legacy (35b–35f). (4) Front (`app.js`): `postSale.fail` distingue 422 (→fallback legacy, no-simple) de 5xx/timeout (→`callback(false)`, SIN fallback; orphans reintenta SaleService). `syncOrphans` y `sendDataToServer` pasan a usar `postSale` en vez de `postToServer` directo. Verificado: unit test 10 casos elegibilidad + delegación SaleInput + guard E2E (simple→processData→409). **Riesgo aceptado**: SaleService es dependencia dura del path simple — ante caída sistémica las ventas simples se encolan pero no completan hasta recuperarse.
  - **35c** ✅ **COMPLETO** (commits 0e3c7bf + d099019, 2026-05-31): **gift card — vender y pagar** totalmente en SaleService. Ver entry `_session-log.md` 2026-05-31 §35c para detalle completo.
    - **35c.1** (commit 0e3c7bf): PAGAR con gift card (redención). `SaleService::persistGiftCardRedemptions` + `redeemGiftCard`: por cada payment type=`'giftcard'` debita `giftCardSold.giftCardSoldValue` dentro de la transacción (decremento atómico: `GREATEST(value-?,0)`; tenant-scoped; card no encontrada → log+skip, no throw). `persistLoyaltyEarning` excluye points/storeCredit/giftcard del earning (matchea el branch `else` del legacy). `saleIsSimplePathEligible`: `'giftcard'` sale de los payment types rechazados (points/storeCredit siguen en legacy).
    - **35c.2** (commit d099019): VENDER gift card. `SaleService::sellGiftCard` (port de `insertNewGiftCard`): dedup por `timestamp` parametrizado + tenant-scoped; `beneficiaryId` con check de formato UUID antes del SELECT + validado contra contact del tenant → null si no resuelve; null-coalesce de expires/sendDate/note/color. `SaleService::notifyGiftCardBeneficiaries`: e-gift email/SMS al beneficiario si `giftDate=hoy`, POST-COMMIT best-effort. `persistItemsAndStock`: ítem con `giftcardId` → `sellGiftCard()` (fuera del guard de itemId, como el legacy); si tiene `itemId` además → genera `itemSold`/stock. `saleIsSimplePathEligible`: `giftcardId` en items sale del rechazo; items type=`'giftcard'` saltean el check de empty-itemId.
    - **Estado del strangler tras 35c**: SaleService cubre venta simple (35a) + gift card vender/pagar (35c). El legacy processData retiene: EI (35b, diferida), sesiones agendadas (35d), inCredit/storeCredit/points (35e), recurrente (35f). Verificado E2E: redención exacta/cap, venta-giftcard con/sin itemId, beneficiario válido/bogus.
  - **35b** ⏳ Migración del path EI (electronicInvoicePY) al SaleService. **NOTA**: el legacy `processData` sigue roto en PG para ventas EI — INSERT falla → "Duplicated Entry" → venta EI se pierde. Esto es PRE-EXISTENTE (antes de 35a.7 la venta EI iba directo al legacy con el mismo resultado); no es una regresión de 35a.7.
  - **35d** ⏳ Migración de sesiones agendadas (duration/parentId de sesión) al SaleService.
  - **35e** ⏳ Migración de inCredit/storeCredit/points (payment types que gastan balance) al SaleService.
  - **35f** ⏳ Migración de ventas recurrentes (repeat) al SaleService.
  Trabajo grande, incremental, alto riesgo (money+inventario). La estructura `api/lib/Sales/` + `api/lib/Context/` queda establecida como modelo para futuros módulos (ver §22.9 en `08-convenciones.md`).
  **DEUDA conocida (35a.3, no regresión — matchea legacy)**: `toTaxObj.toTaxObjText` es VARCHAR(255); con ~6+ impuestos el json_encode excede y aborta la tx por truncación (22001). Fix: migración para widening a TEXT.
- **`deleteClient` — ✅ AGREGADO al POS (commit afc2706, 2026-05-30)**: soft-delete de cliente desde el POS. Handler nuevo en `app/action.php`: UPDATE `contactStatus=0` parametrizado con scope `companyId+type=1`; notifica WS con `deleted=true`; llama `updateLastTimeEdit('customer')`. Espeja el patrón del REST canónico `panel/API/v1/contacts.php DELETE` / `ContactRepository::archive`. Los handlers `newClient` y `updateClient` del POS ya existían y son funcionales. **Deuda P2 (diferida)**: los tres handlers de cliente del POS duplican SQL del REST canónico; ideal migrar a que el POS llame al canónico via BFF (misma deuda que VPayment/Attendance pero para clientes). No verifica `Affected_Rows` (no devuelve 404 si el id no existe) ni llama `sendAuditoria` — matchea el canónico en ese punto.
- **`sale` (~525 líneas)**: renderiza listas HTML de transacciones (monstruo de lectura).
- **Breakages preexistentes del panel (mismo tipo de migración, follow-up)**: `panel/crons/cronCreateRecurringInvoice.php` lee `recurringSaleData` de multi-row sin flatten → NULL; `panel/report_transactions.php` + `panel/a_report_transactions.php` hacen UPDATE de la columna `tags` (ya no existe) → falla.
- **HTML/especial**: `chkGiftCard` (gift cards, devuelve HTML/JSON), `consultStatusElectronicInvoice` (factura electrónica).
- **DEAD (borrar al vaciar action.php)**: `checkoutScreen` (`return false` al inicio), `checkSession` (front usa WS, no HTTP), `encode` (front no lo llama; enc() es identity → no-op).

### Migración de panel/API → /api (gradual, pendiente)

`panel/API/*` (~93 endpoints) permanece en su lugar y funciona. Se migra gradualmente a `/api/v1/` conforme se tocan módulos. No hay timeline definido — se hace concern-por-concern.

### Consolidar /api/includes canónico (deuda transitoria — para el SERVER-SPLIT, no perf)

`api/bootstrap.php` actualmente hace `chdir(/app)` y reutiliza los includes de /app (`db/functions/jwt_middleware/head.php/data.php`) vía rutas absolutas. Esta dependencia de /app es transitoria; debe eliminarse antes de que /api pueda moverse a su propio server. La tarea: crear `/api/includes/` con los archivos mínimos (db, functions subset, jwt_middleware, response) independientes de /panel y /app.

**CORRECCIÓN del análisis de perf (2026-05-31, profiling del bootstrap):** el "2.5× overhead" (95ms vs 37ms) que el piloto midió en DEV es **casi 100% un artefacto de dev**, NO un problema de producción. Breakdown medido del bootstrap: `head.php (parse functions.php 5085 líneas + config) = 74.6ms` · `jwt = 2.7ms` · `data.php = 4.2ms`. El costo lo domina **PHP re-parseando functions.php en cada request** porque el dev server (`php -S`) tiene **`opcache.enable_cli` OFF**. En **producción** (php-fpm/apache) `opcache.enable` está **ON** → el bytecode se cachea → el bootstrap baja a ~7ms (jwt+data). Es decir: el patrón BFF-compone NO paga el parse N× en prod.
- **Fix dev aplicado (launch.json):** `opcache.enable_cli=1` + `validate_timestamps=1` + `revalidate_freq=0` en los 5 dev servers → cada worker de `php -S` parsea functions.php una vez y reusa el bytecode (chequea mtime por request → código fresco sin stale). Bootstrap dev **74ms → ~7ms**. **Requiere reiniciar los servers.**
- **Costo real de BFF-compose en PROD:** el bootstrap repite `data.php` (carga settings/modules/outlet, ~4ms) y la validación JWT (~3ms) en cada uno de los N calls. Para N=5 son ~35ms de overhead redundante (vs ~7ms de un call único). Pequeño en absoluto, pero optimizable haciendo `data.php` lazy (cargar settings sólo cuando el endpoint los pide) o cacheando el contexto por-request.
- **Re-prioridad:** consolidar `/api/includes` NO es urgente por perf (prod ya está bien con opcache). Su valor real es el **SERVER-SPLIT** (desacoplar /api de /app para correrla en server dedicado — objetivo arquitectónico declarado). La micro-optimización de perf (si se quiere) es `data.php` lazy, no la consolidación completa.

**Slice 21 COMPLETO — `tablesJson` de load.php (commit dd1dee1, 2026-05-29)**: `TableService::listTables(companyId, outletId)` — consulta mesas abiertas (type 11), retorna array keyed por `transactionName`. `api/v1/tables.php` GET sin acción → `apiOk($tables)`. `app/bff/tables.php` handler `action===''` → `bffApiGet`. `app/load.php`: eliminadas las 52 líneas del handler `tablesJson` (dead code con 4 bugs PG: VARCHAR/int, UUID intval, god-function, sin companyId scope). `globalv2.js` + `debug.js` repuntados a `bff/tables`.

**Slice 22 COMPLETO — `docsNum` de load.php (commit 3d222b9, 2026-05-29)**: `RegisterService::docNumbers(registerId, companyId)` — retorna los 7 contadores de documento del registro (registerId, invoiceNo, ticketNo, returnNo, scheduleNo, orderNo, quoteNo). Bug PG corregido: el legacy `getNextDocNumber()`/`getValue()` en `app/includes/functions.php` interpolaba el `companyId` UUID sin comillas en el WHERE → siempre fallaba en PG; el nuevo código bindea todos los params. `api/v1/register.php` GET sin acción → `apiOk($svc->docNumbers(registerId, companyId))` (registerId del JWT). `app/bff/register.php` handler `action==='docsNum'` → `bffApiGet('v1/register.php')` → objeto plano (el front lo consume directamente sin wrap). `app/load.php`: eliminado handler `docsNum` + eliminado handler muerto `chkInvoiceNo` (sin callsites en todo el repo). `globalv2.js` + `debug.js`: `updateDocs` repuntado a `ncmHttp.getit(bff/register?l=<action:docsNum>, ..., 'json')`. `RegisterService` pasa a tener dos métodos: `setSession` (slice 10) + `docNumbers` (slice 22).

**Slice 23 COMPLETO — `customerHasOrders` de load.php (commit 3fd615b, 2026-05-29)**: `OrderService::customerHasOpenOrders(companyId, outletId, customerId): bool` — consulta si el cliente tiene órdenes abiertas (transaction type 12, status != 4) en el outlet; multi-tenant, parametrizado. `api/v1/orders.php` GET `?resource=customerHasOrders&customerId=<id>` → `apiOk(['hasOrders'=>bool])`; companyId/outletId del JWT. `app/bff/orders.php` handler `action==='customerHasOrders'` → `bffApiGet` → retorna `{hasOrders:bool}` plano. `app/load.php`: eliminado handler legacy (patrón HTTP-401-as-signal → booleano limpio). `globalv2.js` + `debug.js`: repuntados a `bff/orders?l=<action:customerHasOrders>`, verifican `data.hasOrders` con `type:'json'`.

**Slice 27 COMPLETO — `ordersList` de load.php (commit cdd7483, 2026-05-29)**: los dos handlers legacy de `ordersList` (~357 líneas en total) migrados a `OrderService` + `api/v1/orders.php` + `app/bff/orders.php`.
- `OrderService::queryOrderRows(t, kind, outletId)` — privado; ejecuta el SQL base para queries de mesa/cliente/cualquiera.
- `OrderService::getTableClose(t, kind, outletId, companyId)` → items agrupados para el cierre/carga de mesa (json mode); retorna `{items, tags, ids}`. Endpoint: `GET ?resource=tableClose&t=&kind=`.
- `OrderService::getTableDetail(t, kind, outletId, companyId)` → vista de detalle de orden (non-json mode); retorna `{data[], title, subTitle, orderId, type}`. Endpoint: `GET ?resource=tableDetail&t=&kind=`.
- `OrderService::getList(outletId, companyId, encCustomerId, date, limit)` → historial paginado de órdenes; retorna `{date, listName, transactionsList[], footBtn}`. Endpoint: `GET ?resource=list&customerId=&date=&limit=`.
- BFF: `action=ordersTableList` (json flag → tableClose, else → tableDetail) + `action=ordersList` (list mode).
- `app/load.php`: eliminados handler A (~237 líneas, t-based) + handler B (~120 líneas, buildList).
- JS: 3 callsites (6806/6835/7495) en `globalv2.js` + `debug.js` repuntados a `bff/orders?action=ordersTableList`; `buildList` dinámico (línea 11691) condicional — cuando `load==='ordersList'` → `bff/orders?action=ordersList`.
- **Bug corregido**: handler B legacy concatenaba `$cuid`/`COMPANY_ID`/fechas directamente en el SQL (SQL injection) → reemplazado con queries parametrizadas.
- **Meta JSONB**: `transactionDetails` y `tags` leídos desde `meta` JSONB en resultados multi-fila de ADOdb.
- `OrderService` ahora cubre tanto mutaciones de estado (accept/transfer/assignUser) como operaciones de query (customerHasOpenOrders/getTableClose/getTableDetail/getList).

**Slice 28 COMPLETO — `quotesList` + `savedList` de load.php (commit 57b9bcf, 2026-05-29)**: `TransactionService::getTransactionList(listType, outletId, companyId, encCustomerId, date, limit)` — cubre `listType='quotes'` (transactionType=9) y `listType='saved'` (transactionType=2). Config por tipo: label HTML, colores de estado, anchor hash. Queries parametrizadas (cierra SQL injection del legacy — `$cuid`/`COMPANY_ID`/fechas concatenados en string). Endpoint: `api/v1/transactions.php` GET `?resource=list&listType=quotes|saved&customerId=&date=&limit=`. BFF: `app/bff/transactions.php` con `action=quotesList` y `action=savedList`. `app/load.php`: eliminados los dos handlers legacy (~115 líneas `quotesList` type-9 + ~117 líneas `savedList` type-2). `globalv2.js` + `debug.js`: condicional de Slice 27 (solo `ordersList`) reemplazado por `_bffListMap = {ordersList:'orders', quotesList:'transactions', savedList:'transactions'}` — cualquier load mapeado rutea al BFF correspondiente; los no mapeados siguen al legacy `load?l=`.

**Slice 29 COMPLETO — `transactions` de load.php (commit 66da236, 2026-05-29)**: `TransactionService::getMainList()` — lista principal de transacciones para el panel de ventas. Roles 4/5 ven solo tipos 2/10 filtrados por `userId`; el resto ve todos los tipos del tenant. Batch credit query con `IN(?)`. Endpoint: `api/v1/transactions.php` GET `?resource=mainList`. BFF: `app/bff/transactions.php` con `action=transactions`. `app/load.php`: eliminado el handler `transactions` (~320 líneas con SQL injection de variables concatenadas → reemplazado por queries parametrizadas). `globalv2.js` + `debug.js`: `_bffListMap` ahora incluye `transactions: 'transactions'` — la lista por defecto de `buildList` ya va por BFF.

**Slice 30 COMPLETO — `sessionsList` de load.php (commit 1d02620, 2026-05-29)**: `ScheduleService::getSessionsList(companyId, outletId, customerId, date): array` — lista read-only de paquetes de sesiones (items con `itemSessions > 0`) y sus sesiones agendadas (transaction type 13 del cliente en el outlet). Endpoint: `api/v1/schedule.php` GET `?resource=sessions&customerId=&date=`. BFF: `app/bff/schedule.php` handler `action=sessionsList`. `app/load.php`: eliminadas las ~89 líneas del handler legacy `sessionsList`. `globalv2.js` + `debug.js`: `_bffListMap` ahora incluye `sessionsList: 'schedule'` — el handler del POS ya va por BFF. **SQL injection corregida**: el legacy concatenaba `customerId`/`companyId`/fechas directamente en el SQL; el nuevo código bindea todos los params. Nota QA: el filtro por fecha del legacy emitía SQL malformado (literal de hora fuera del string citado vía `$db->Prepare`) → la salida filtrada por fecha puede diferir de prod legacy. `ScheduleService` ahora tiene: `rescheduleTo` (slice 4), `unlock` (slice 4), `updateSchedule` (slice 20), `scheduleSession` (slice 20), `checkIfUserOccupied` (slice 20), `getSessionsList` (slice 30).

**Slice 31 COMPLETO — `agendaList` de load.php (commit 74fed79, 2026-05-29)**: `ScheduleService::getAgendaList(companyId, outletId, customerId, date, limit)` — lista read-only de citas/turnos (transactionType=13, status!=7, fromDate/toDate no nulos). Lee `transactionDetails` desde `meta` JSONB (§22.6). Corrige SQL injection del legacy (customerId/companyId/outletId/dates → params). `footBtn` replica el comportamiento legacy (Cargar más sin fecha, Atrás con fecha/query fallida). Endpoint: `api/v1/schedule.php` GET `?resource=agenda&customerId=&date=&limit=`. BFF: `app/bff/schedule.php` handler `action=agendaList`. `app/load.php`: eliminadas las ~189 líneas del handler legacy. `globalv2.js` + `debug.js`: `_bffListMap` ahora incluye `agendaList: 'schedule'`. `ScheduleService` ahora tiene: `rescheduleTo`/`unlock` (slice 4), `updateSchedule`/`scheduleSession`/`checkIfUserOccupied` (slice 20), `getSessionsList` (slice 30), `getAgendaList` (slice 31).

**Slice 32 COMPLETO — `customerInfo` de load.php (commit 0e185f4, 2026-05-29)**: `CustomerService::getInfo(companyId, outletId, customerId)` — resumen del cliente: contacto + últimos ítems vendidos (STRING_AGG de transactionIds → IN parametrizado) + deuda corriente/vencida + gift cards activas + dirección default. Read-only salvo backfill lazy de `customerAddress` (vía `$db->Insert`). Corrige SQL injection del legacy: STRING_AGG(transactionIds) concatenado en `IN(<string>)` con UUIDs sin comillas (roto en PG) → IDs recolectados + `IN(?)` parametrizado. Agrega scope `companyId` en todas las queries de transaction/itemSold/giftCardSold. Booleanos PG correctos (`transactionComplete = false`, `customerAddressDefault = true`). `api/v1/customers.php` GET `?resource=info&id=`. `app/bff/customers.php` handler `action=customerInfo`. `app/load.php`: eliminado el handler `customerInfo` (~272 líneas). `globalv2.js` + `debug.js`: `ncmCustomer.infoModal` repuntado a `bff/customers`. **Nota QA**: preserva un bug del legacy — la deuda vencida usa las devoluciones de la deuda corriente (`$totalRetrns` en vez de `$totalRetrnsV`, que era dead code en el original). **Deuda técnica P2 (reviewer)**: `getDebtListByTransaction()` en `app/includes/functions.php` sigue con `IN()` sin parametrizar y sin scope `companyId` — ahora es invocada desde un endpoint tenant-facing, lo que eleva la prioridad de limpiarla.

**Slice 33 COMPLETO — `customerRecord` de load.php (commits b0fbec3 → 3d62191, 2026-05-29)**: `CustomerService::getRecords(companyId, customerId)` — fichas personalizadas del cliente (tablas `customerRecord` + `cRecordField` + `cRecordValue`), devuelve datos estructurados (id, label, tipo, valor por campo). `api/v1/customers.php` GET `?resource=records&id=`. `app/bff/customers.php` handler `action=customerRecord`. **Template reescrito en Alpine** (commit 3d62191 — supercede b0fbec3 que usaba Mustache): `<template id="customerRecordTpl">` con `x-data`/`x-for`/`x-if`/`x-text`/`x-html` en `app/index.php` + `app/index.html`. Componente `customerRecord` registrado con `Alpine.data()` en `alpine:init` en `globalv2.js` + `debug.js`. Render: clonar `<template>`, `Alpine.initTree(el)` detached. Switch: dos ramas `x-if` (con `checked` / sin `checked`) para alinear con `switchit()`/`recordsEdit`. `x-for` con wrapper `display:contents` (raíz única requerida por Alpine). `globalv2.js` + `debug.js`: `ncmCustomer.recordsList` reescrito — fetch JSON a `bff/customers` + `Alpine.initTree`. `app/load.php`: eliminado el handler `customerRecord` (~300 líneas, el ÚLTIMO del desacople de listas/fichas). **SQL injection corregida**: queries parametrizadas; scope `companyId` agregado. **INFRA Alpine**: `assets/vendor/js/alpinejs-3.14.1.min.js` vendoreado (local/offline); `app/index.html` (defer), `app/cache-sw.php` (precache), `app/filesCompiler.php` (bundle). `APP_VERSION` 2.0.9.3 → 2.0.9.4. **Nota QA**: requiere verificación manual del modal de fichas en browser (render + guardado + subida de imagen).

**CIERRE DEL DESACOPLE DE load.php (listas/fichas):** con Slice 33 se completó la migración de TODOS los handlers del kit listas/fichas de `app/load.php` al patrón BFF→API→Service. `CustomerService` ahora tiene: `getInfo()` (slice 32) + `getRecords()` (slice 33).

**PILOTO ARQUITECTÓNICO — API granular + BFF compone (commit c4edef9, 2026-05-31) ✅ HECHO:** refactor de `customerInfo` que invierte la responsabilidad de composición. La API expone 5 recursos GET granulares reusables (`profile/recentItems/debt/giftcards/address`); el BFF los pide en paralelo con `bffApiGetMulti()` y los mergea en el shape legacy. Output BYTE-IDÉNTICO al `getInfo()` legacy (diffCount=0). **DECISIÓN tomada**: esta es la dirección del codebase — API granular + BFF compone. Los endpoints fat actuales (`getInfo`/composite de records, listas de orders/transactions) son **deuda a refactorizar** al patrón granular cuando se toquen. Trade-off medido y bottleneck identificado: ver `/api/includes` canónico arriba.

**Lo que QUEDA en load.php (NO son parte del desacople de listas/fichas):**
- **Dead code** (nunca se migran, se borran al vaciar load.php): `tweet`, `orders`, `ordersPanel`, `calendar_*`, `customerProgress`, `walink`, `printServer`, `ordersPanelAPI`.
- **APIs externas diferidas** (requieren integraciones externas para testear): `bancardQR`, `pixQR`, `verifyTransactionPix`, `ePOSPending`, `verifyTransactionEPOS`, `userLocation`, `tin`.

Los clusters restantes de `action.php` (mesa-merge, monstruos processData/sale, HTML/especial, dead) no son de `load.php`.

**Slice 34 COMPLETO — `joinSpaces` + `moveOrders` de action.php (commit 5642a1c, 2026-05-30)**: los dos handlers del cluster "Mesa-merge", diferidos por estar ROTOS en PG (int→UUID), migrados a `TableService` con la semántica correcta:
- `TableService::joinSpaces(companyId, outletId, tFrom, tTo)` — une la mesa origen en la destino: resuelve el `transactionId` (UUID) de la mesa destino, marca la origen como hija (`transactionParentId = ese UUID`, que `closeTable`/`listTables` ya consumen), reasigna sus órdenes (type 12). Devuelve 404 si la mesa destino no existe.
- `TableService::moveOrders(companyId, outletId, registerId, userId, tFrom, tTo)` — mueve las órdenes (ítems) de una mesa a otra y ABRE la mesa destino si estaba cerrada (INSERT type 11; PK por DEFAULT `gen_random_uuid()`). No es fusión: no marca `transactionParentId`.
- `api/v1/tables.php`: PUT `?resource=join` body `{from,to}` / PUT `?resource=move` body `{from,to}`. `app/bff/tables.php`: actions `joinSpaces` y `moveOrders`. `globalv2.js` + `debug.js` repuntados de `action?l=` a `bff/tables`; guard "Debe abrir el espacio" eliminado del front (backend ahora abre la mesa destino); chequeo `joined` null-safe.
- `app/action.php`: eliminados los dos handlers legacy (~51 líneas).
- **Fixes PG del legacy**: nº de mesa guardado en columna UUID, varchar vs int sin comillas, UUIDs sin comillas, `USE INDEX` (MySQL), params desalineados (2 placeholders/3 args). Todo parametrizado + scope companyId+outletId del JWT.
- `TableService` ahora tiene: rename / unreserve / assignUser / closeTable / listTables / **joinSpaces** / **moveOrders**.

**PATRÓN VIGENTE — datos estructurados + Alpine (establecido Slice 33, reescrito commit 3d62191):** para handlers HTML server-rendered, la API devuelve datos estructurados (campos tipados) y el front renderiza con un template Alpine en `app/index.php`/`app/index.html`. El patrón Mustache que documentaba b0fbec3 fue reemplazado en la misma jornada. Ver convención §24 en `08-convenciones.md`. Los componentes Alpine se registran en `app/scripts/app.js` (única fuente del front — `globalv2.js`/`debug.js` reemplazados en Tier 3, 2026-05-30).

**Deuda de migración Mustache→Alpine en /app (~22 templates existentes, 2026-05-29):** `customerRecord` es el primer template en usar Alpine en `/app`. Los demás templates Mustache del POS (templates de items, fichas de contacto, etc.) son deuda de migración incremental — se migran a Alpine cuando se toquen, no de forma preventiva. Mustache 4.0.1 sigue cargado hasta que todos migren.

**Deuda: de-hardcode de dominios + ENCOM→Punto (2026-05-29):** regla nueva en `CLAUDE.md` — no hardcodear dominios; deben venir de config/env. Hoy quedan hardcodeados `*.encom.app` (y `*.encom.com.py`/`encom.site`) en: `app|panel/includes/cors.php` (allowlists), `app|panel/{400,404,500}.shtml` (CSS/imgs cargados desde `panel.encom.app` + links a `status/www.encom.app`), `app|panel/manifest.json` (`start_url`/`scope`), `*.htaccess` (ErrorDocument, mayormente comentados). Plan por categoría (NO find-replace ciego): (1) cors.php → allowlist via `$_ENV` con la lista actual como **fallback** (cuidado: en `/app`, cors.php se incluye ANTES de `simple.config.php` que carga `.env` → hay que reordenar o cargar env en cors; CORS es security-sensitive). (2) `.shtml` → assets a rutas relativas/locales; links externos via valor de build. (3) `manifest.json` → `scope`/`start_url` relativos. (4) ✅ HECHO (commit 6217784) `API_ENCOM_URL` era un **alias duplicado** de `API_URL` (mismo valor) → 18 usos migrados a `API_URL` y define eliminado. (5) BD: `permissions.encom.*` (clave JSON en `contact`/`user`, ver `panel/main.php:19` + seed `01_master_admin.sql`) y nombre `encomdb` → migración de datos coordinada. (6) `encom_app.png` → renombrar archivo + refs. (7) **error_logs trackeados en git** (`panel/API/error_log` 82k líneas, etc.) → untrackear + gitignore (no son código; ahí está el 99% de los "encom"). `punto.app` aún NO existe en config — los renames de dominio esperan a que la infra esté lista (cambian solo el valor de env).

### ✅ RESUELTO (commit f77b47a) — `app/DB.php` sin `Insert_ID()`

`app/includes/lib/DB.php` había **divergido del panel** y no tenía `Insert_ID()`, por lo que `ncmInsert()`/`ncmUpdate()` eran **FATALES en /app** (todo el legacy de escritura de `action.php` estaba latentemente roto en PG post-Phase-PG).

**Fix (f77b47a)**: se sincronizó con el panel — `AutoExecute` INSERT usa `RETURNING *` y captura el PK (1ª columna) en `$_lastInsertId`; nuevo `Insert_ID()`; el branch UPDATE limpia `$_lastInsertId` (no devolver id stale). Verificado: `ncmInsert` devuelve el UUID, `ncmUpdate` `error=false`. **`ncmInsert`/`ncmUpdate` vuelven a funcionar en /app.**

**Regla para slices de /app con escrituras** (sigue vigente por preferencia, aunque ncm* ya no rompe):
- Preferir `$db->Execute($sql, $params)` **parametrizado** (evita el bug de UUID-sin-comillas que arrastran los `where` string de ncmUpdate + es a prueba de inyección).
- Multi-step → `$db->StartTrans()` / `$db->CompleteTrans()` para atomicidad.
- Booleans PG: `true`/`false`/`null`, nunca `1`/`0` en literales SQL.

---

## Phase 2.A — Retrofit envelope canónico ✅ COMPLETO

**68/68 endpoints** ahora usan `apiMiddleware()` con envelope canónico. Incluye `auth.php` (ruta legacy migrada 2026-05-16).

**Bugs arreglados**:
- ~~2.B — `get_company` 500~~ → `getTagsDefaults()` tiene guard `!is_object($result)` ✅
- ~~2.C — `get_orders` 500~~ → resuelto implícitamente por Phase UUID (`dec()` es identity) ✅

**Hardenings post-Phase 2 (2026-05-16)**:
- ✅ HMAC write token en `panel/API/kds.php` action=update — slug ya no permite mutar órdenes
- ✅ `.env` parser robusto — soporta valores con quotes
- ✅ Logging del fallback legacy `?l=` en `app/load.php`, `fetch.php`, `action.php` via `app/includes/legacy_auth_log.php`

**Nice-to-have (no bloquean Phase AI)**:

| # | Qué | Detalle | Prioridad |
|---|-----|---------|-----------|
| 2.D | OpenAPI spec para los 68 endpoints | `panel/API/openapi.yaml` | Baja |
| 2.E | Helpers de validación de request | `panel/API/lib/validate.php` | Baja |

---

## SQL Injection Audit (deflated — riesgo bajo)

**Resultado de auditoría 2026-05-16**: el "SQL injection audit" original resultó tener riesgo
mucho menor del esperado. De 14 casos candidatos:
- 5 = dead code
- 7 = mitigados (validateHttp + db_prepare, vars internas como $SQLcompanyId)
- 2 = a parametrizar (legitimate findings)

**Pendiente**: parametrizar 2 casos restantes en `app/includes/functions.php` (líneas 3529, 4561, 4563) — esfuerzo ~1h. Baja prioridad ahora que el riesgo real está documentado.

**Hallazgos separados** detectados durante el audit (no son SQL injection, pero importantes):
- 🟡 IDOR potencial en `panel/screens/scheduleConfirm.php:6` — `COMPANY_ID` definido desde URL base64 sin verificar JWT. Rompe regla §1 (aislamiento tenant)
- 🐛 Query rota en `app/includes/functions.php:4568` — SQL tiene 2 placeholders pero pasa 3 valores
- 🧹 Dead code en `panel/API/get_tin.php` líneas 39, 55-57 que referencian la BD muerta `ruc_py`

---

## Phase PG — queries con schema viejo (deuda real descubierta 2026-05-19)

**Problema descubierto al cargar el dashboard franchiser visualmente**:
Phase PG migró ~95 archivos pero quedaron queries en el panel que aún
referencian schema viejo eliminado/refactoreado:

| Tipo de bug | Ejemplo | Cantidad estimada |
|------------|---------|-------------------|
| `FROM setting` | `FROM company a, setting b` | varias (eliminada Phase PG.2) |
| Columnas que ahora viven en JSONB | `SELECT settingTimeZone FROM company` | docenas — debe ser `config->>'settingTimeZone'` |
| Columnas que no existen | `SELECT accountId FROM company` | TBD |
| UUIDs concatenados con doble-quoting | `outletId = ''019e4075-...''` | varias |
| `companyId IN()` sin null check | `IN(implode(',', $ids))` | varias |

**Cómo encontrar más**: arrancar preview server con `display_errors=on`,
ejercer el flujo end-to-end del dashboard/listados/reportes del panel,
y leer los logs PHP. Cada error revela una query rota.

**Estrategia recomendada**: agente `postgres-pro` puede mapear todas las
queries del panel que referencien `settingX FROM company` o tabla `setting`
y generar un patch masivo cambiando a `config->>'X'`. Tiempo estimado: 4-6h.

**Bloquea**: navegación completa del panel admin (dashboard, reports).
NO bloquea: login, API endpoints `panel/API/` migrados (todos usan
`_flattenJsonb` ya), `/app` POS, KDS/CDS, WebSocket — todos validados OK.

---

## Deprecation del fallback legacy `?l=` en /app ✅ COMPLETO (server)

**Problema (resuelto 2026-05-16)**: `app/load.php`, `app/fetch.php` y
`app/action.php` aceptaban identidad client-supplied via
`?l=base64(companyId,outletId,userId,roleId,registerId)` cuando el JWT
fallaba. Cualquier request sin cookie `_jwt` podía impersonar cualquier
tenant.

**Fix aplicado (proyecto pre-producción → hard-disable directo)**:
- Server: load.php / fetch.php / action.php retornan 401 si JWT falla,
  sin fallback a IDs del request.
- `?l=` se mantiene como sobre base64 pero SOLO para extraer la
  operación (`load`, `action`) — los IDs vienen del JWT.

**Pendiente menor (cleanup, no seguridad)**:
- `app/scripts/app.js` (antes `globalv2.js` — renombrado en Tier 3 de la reestructura, commit e97aed7) aún construye el payload completo en `?l=` con IDs que el server ya ignora. Limpiar en una sesión futura: el cliente debería mandar solo `?l=base64({action})` o pasar directo a query params planos (`?action=...`).

---

## Migration Runner

**Problema**: Las migraciones se corren a mano. En deploy con Coolify no hay step automático.

**Propuesta**: Script bash que:
1. Lee tabla `schema_migrations` (crear si no existe)
2. Compara con archivos en `database/migrations/postgres/`
3. Ejecuta los pendientes en orden
4. Registra en `schema_migrations`

**Esfuerzo**: ~3 horas

**Riesgos**: Bajos. Idempotencia ya requerida por convención (§3 de `08-convenciones.md`).

---

# Prioridad MEDIA (2-4 meses)

## Phase AI.1 — Agente IA básico

**Problema**: El valor diferencial del producto es ser AI-first. Sin agente funcional
no hay diferenciación.

**Dependencia**: backend de los módulos consultados expuesto como Services/API limpia (las tools del agente leen de ahí). Refuerza la estrategia backend-first de `02-arquitectura.md`: cada módulo modernizado le da más superficie de datos al agente. NO requiere modernizar el frontend de los reportes — el agente los reemplaza.

**Cliente LLM**: OpenRouter (no Anthropic directo), SDK `openai` apuntando a OpenRouter. El "tool use" es function calling estándar (formato OpenAI).

### Visión

Un agente autónomo que habla con la API de Punto via JWT. Los usuarios interactúan con el sistema por chat (widget web, Telegram, WhatsApp) en lenguaje natural. El agente interpreta la intención, llama los endpoints correctos y devuelve respuestas formateadas.

**El agente tiene dos facetas que comparten la misma base** (tools deterministas + LLM orquestador):

1. **Chatbot / asistente genérico** — el usuario pregunta sobre sus datos ("¿cómo van las ventas?", "¿qué producto se vende menos?"), pide recomendaciones ("¿qué debería reponer?", "¿conviene este combo?") y obtiene ayuda operativa. Conversacional, libre.

2. **Analista de datos** — reemplaza la proliferación de reportes hardcodeados (~13K líneas en `report_*`). El usuario pide reportes ad-hoc en NL y **dashboards customizados**: describe qué quiere ver, la IA arma la estructura, se guarda, y se renderiza en vivo. Ver "Reportes y dashboards por IA" abajo.

**Decisión (2026-05-24)**: el agente es el reemplazo de los reportes exploratorios, no un chatbot decorativo. Los reportes legales/contables (facturación, libro de ventas, impositivos) se quedan hardcodeados — formato exacto y auditable, la IA no aporta ahí.

### Regla de oro — correctitud y seguridad (NO negociable)

En finanzas/inventario los números deben ser **exactos**; un dato mal calculado hace que el dueño decida con info falsa.

- **Tool calling determinista, NUNCA text-to-SQL libre.** El LLM elige entre funciones acotadas y parametrizadas (`ventas_periodo(desde, hasta, agrupar_por)`, `top_productos(n)`, `stock_bajo()`). Cada tool ejecuta SQL fija filtrada por `companyId`. El LLM **no escribe SQL ni hace aritmética** — solo decide qué preguntar y presenta el resultado.
- **Por qué**: text-to-SQL libre = fuga multi-tenant (leer otra company), queries que tumban la DB, y JOINs mal hechos = números errados con cara de correctos.
- **Aislamiento de tenant**: el JWT del usuario fija `companyId`; toda tool lo aplica en el WHERE. El LLM nunca recibe ni elige el `companyId`.

### Reportes y dashboards por IA

- **Reporte ad-hoc**: pregunta NL → el LLM elige tool(s) → datos exactos → respuesta formateada (texto + tabla/gráfico).
- **Dashboard custom**: el usuario describe ("ventas diarias del mes, top 5 productos, alertas de stock bajo") → la IA genera una **config** (JSON: lista de widgets, cada uno = una tool + params) → se guarda la config (`dashboard.config` JSONB por usuario/company) → el dashboard se renderiza **en vivo**, cada widget llama su tool.
- **KPIs guardados = DEFINICIONES, no valores.** Se persiste la fórmula/tool/params del KPI, nunca el número calculado (quedaría viejo y, si lo calculó la IA, podría estar mal). Los datos son siempre frescos y deterministas.
- La IA hace el trabajo creativo (estructurar) **una vez**; los números vienen de queries cada vez.

### Arquitectura

```
Telegram / WhatsApp / Widget Web
         ↓
    punto-agent/  (microservicio Python + FastAPI)
    ├── Interpreta intención (LLM via OpenRouter — function calling)
    ├── Llama tools deterministas → panel/API/* con JWT del usuario
    └── Formatea y devuelve respuesta (texto / tabla / config de dashboard)
         ↓
    panel/API/  (los endpoints existentes, sin modificar)
```

### Por qué esto funciona sin tocar el monolito

El agente solo necesita el JWT del usuario y los endpoints. No sabe nada de PHP ni de la base de datos. La API de Punto es su única interfaz.

### Tools de Claude (cada tool = un endpoint)

```python
tools = [
    {
        "name": "get_sales_report",
        "description": "Obtiene ventas de un período",
        "input_schema": {
            "properties": {
                "date_from": {"type": "string"},
                "date_to": {"type": "string"}
            }
        }
    },
    { "name": "get_stock_level", ... },
    { "name": "create_order", ... },
    { "name": "get_customers", ... },
    # ~20 tools para los casos de uso más frecuentes
]
```

### Casos de uso iniciales

| Faceta | Ejemplo de input | Action |
|--------|-----------------|--------|
| Chatbot | "mandame el cierre de hoy" | `ventas_periodo(hoy)` → resumen formateado |
| Chatbot | "cuánto stock me queda de Coca Cola" | `stock_nivel` con filtro |
| Chatbot (recomendación) | "¿qué debería reponer?" | `stock_bajo` + razonamiento sobre el resultado |
| Analista (reporte ad-hoc) | "ventas del mes pasado por categoría" | `ventas_periodo(..., agrupar_por=categoria)` → tabla/gráfico |
| Analista (dashboard) | "armame un dashboard con ventas diarias, top 5 productos y stock bajo" | genera config JSON → se guarda → render en vivo |
| Escritura (AI.3+) | "registrá una venta de 2 hamburguesas" | `create_order` |
| Proactivo (AI.5) | (sin trigger) stock bajo detectado | Alerta automática |

### Stack técnico

```
punto-agent/
├── main.py              # FastAPI app
├── agent.py             # Lógica del agente (Claude tool use)
├── tools/
│   ├── sales.py         # Wrappers para endpoints de ventas
│   ├── inventory.py     # Wrappers para items/stock
│   └── orders.py        # Wrappers para órdenes
├── channels/
│   ├── telegram.py      # python-telegram-bot
│   └── whatsapp.py      # Meta Cloud API o Twilio
└── auth.py              # Vincula usuario Telegram/WA → JWT de Punto
```

### Auth del agente

```
Usuario envía /start en Telegram
    → Bot genera código de vinculación de 6 dígitos
    → Usuario ingresa el código en el panel de Punto
    → Panel registra: telegram_id ↔ companyId + JWT
    → El agente usa ese JWT para todas las llamadas futuras
```

### Fases de implementación

| Fase | Scope | Prioridad |
|------|-------|-----------|
| AI.1 | Agente básico (OpenRouter) + widget web chat + 5 tools de solo lectura (ventas, items, stock, clientes) | Alta |
| AI.2 | **Reportes ad-hoc por NL** — el chatbot responde preguntas de datos con tablas/gráficos (reemplaza reportes exploratorios) | Alta |
| AI.3 | **Dashboards customizados** — IA genera config JSON, se guarda en `dashboard.config`, render en vivo. KPIs = definiciones | Alta |
| AI.4 | Recomendaciones (reponer stock, combos, productos lentos) sobre los datos de las tools | Media |
| AI.5 | Integración Telegram + bot de reportes | Media |
| AI.6 | Tools de escritura (crear órdenes, registrar ventas) | Media |
| AI.7 | WhatsApp (Meta Cloud API) | Media |
| AI.8 | Alertas proactivas (cron que monitorea + notifica) | Media |
| AI.9 | Contexto persistente por usuario (memoria conversacional) | Baja |

**Esfuerzo MVP (AI.1)**: ~2 semanas. AI.2/AI.3 reusan las mismas tools — el costo es el frontend (chat + render de tablas/dashboards), no nuevo backend.

---

## Phase 3 — Separación de capas: front.html → bff.php → api.php (ACTIVO 2026-05-26)

> **⚠️ Reescrito 2026-05-26.** La propuesta vieja (page controller PHP + template HTML)
> quedó superseded por la **REGLA RAÍZ: PHP nunca sirve HTML**. Ver
> [02-arquitectura.md](02-arquitectura.md) § "Arquitectura objetivo: BFF de 3 niveles".

**Estructura canónica para TODO el sistema** (decisión del usuario 2026-05-26):

```
front.html (HTML+JS, cero PHP)  →  bff.php (PHP, sin BD)  →  api.php (PHP + Postgres)
auth + chrome client-side          intermedia + formatea      única capa con queries
```

**Esto revierte** el diferimiento del boundary HTTP de la sesión 2026-05-26 (que dejaba
el BFF llamando a `lib/` in-process). Ahora el BFF llama a la API por HTTP desde el día 1.

**Piloto COMPLETO E2E (commit 051dd59, 2026-05-26): módulo Reportes, reporte `a_report_summary`** (read-only, sin escrituras):

| # | Qué | Archivo | Estado |
|---|-----|---------|--------|
| 3.1 | API: SQL de agregación de ventas (6 handlers; SQL portado MySQL→PG: `HOUR()`→`EXTRACT`, sin `USE INDEX`) | `panel/API/v1/reports/sales.php` + `panel/lib/reports/ReportSalesService.php` | ✅ |
| 3.2 | BFF: llama a la API por HTTP, compone derivados (netSales, margin, byweek, período anterior), helpers de fecha puros (sin `functions.php`) | `panel/bff/reports/summary.php` | ✅ |
| 3.3 | Front estático + JS recableado al BFF; formateo de números y flechas de comparación client-side; `drawChart`/`chartByHours` intactos | `panel/reports/summary.html` + `panel/scripts/a_report_summary.js` | ✅ |
| 3.4 | Bootstrap del chrome (`panel/API/v1/bootstrap.php` devuelve `thousand` como `'comma'/'dot'`; `panel/bff/bootstrap.php` lo expone al front) | `panel/API/v1/bootstrap.php` + `panel/bff/bootstrap.php` | ✅ |
| 3.5 | Replicar a los demás reportes + módulos (patrón YA fijado y verificado E2E con datos) | — | EN CURSO — 15 hechos + 1 alias: `summary`✅ `p_methods`✅ `inventory`✅ `users`✅ `categories`✅ `brands`✅ `stock_day`✅ `satisfaction`✅ (1er WRITE) `stock`✅ `recurring`✅ (2º WRITE) `summary_year`✅ `customers`✅ (núcleo; extras diferidos) `expenses`✅ (3er WRITE) + `by_brands`↪alias `drawers`✅ (4º WRITE) `products`✅ (1er HEAVY, ~1750 líneas legacy) `purchases`✅ (16º; 1ª MIGRACIÓN PARCIAL: solo las 3 lecturas al BFF, CRUD+fiscales quedan legacy vía `?action=`) `transactions`✅ (17º; el MÁS GRANDE ~3987 líneas; 2ª parcial: 3 lecturas BFF + FE-tab legacy + CRUD/fiscales legacy) `giftcards`✅ (18º, mediano; 1 lectura BFF + KPIs; form edición + writes legacy) `schedule`✅ (19º, mediano ~907; 3 lecturas BFF + donut/KPIs; modal sesiones + delete legacy) `production`✅ (20º, mediano ~1068; 3 lecturas BFF + KPIs; recipe/export/delete legacy; módulo deshabilitado → data-layer verificado, no E2E con datos reales) `cashflow`✅ (21º) `open_invoices`✅ (22º) `vpayments`✅ (23º, gateway externo) — **TODOS los reportes migrados** — `dashboard`✅ (1er módulo NO-reporte en el modelo BFF completo; front commit `bedd81c`: 13 widgets recableados a `/bff/reports/dashboard.php?widget=…`, HTML+Mustache verbatim del legacy, widgets gateados por módulo satisfaction/tables/schedule; tour iguider deferido — ver §"Follow-up: reemplazar iguider") |

**Notas del piloto (commits 051dd59, 973c9c5)**:
- **División de labor (canónica, ver 02-arquitectura.md § REGLA RAÍZ 2)**: el PHP (API+BFF) NUNCA genera markup NI formatea para display. El **BFF devuelve datos CRUDOS** (números `1395000`, fechas ISO, comparaciones como datos `{dir,pct,positive,prev:<crudo>}`, promedio crudo) + hace **cálculos/cross-data** (netSales, totales, byweek, margin, alineación período anterior). El **front formatea TODO** lo presentacional (números via `formatNumber`, fechas, %, textos) **y arma el markup**; en DataTables el `data-order` usa el valor crudo. Anti-patrones a corregir al migrar: (a) BFF que arma `<table>` HTML (lo que hacen `a_report_orders` y los legacy); (b) BFF que pre-formatea números/fechas a strings (corregido en commit `3bb636c` tras haberlo hecho mal una vuelta). Esto es lo que hay que replicar en 3.5.
- Verificado E2E en browser: KPIs, charts, tabs, date-picker con re-fetch sin reload, cero errores de consola.
- **Data-path verificado CON datos** (commit `973c9c5`+): se sembraron ~12 transacciones de prueba en company 0001 (tag `meta.seed=bff-pilot-verify`, quedaron como demo) → KPIs, flechas de comparación con color, charts, Medios de Pago y Por Día pueblan correctamente.
- **2º reporte migrado: `a_report_p_methods`** (commit `e35f8c7`, ajustado en `3bb636c`): mismo molde — API raw → BFF gateway/cálculos (crudo) → front formatea + arma 2 tablas + chart. Confirma que el patrón es mecánico/replicable.
- **Hallazgo (impersonalización/JWT)**: al "entrar" a una empresa hija el JWT `_jwt_panel` NO se reemite (solo cambia la sesión PHP), así que el BFF/API scopean por la empresa del LOGIN. Trackeado en `adr/ADR-001` (re-emit del JWT + `franchiser_to_tenant`).
- **Bugs PG-port arreglados de paso** (al verificar con datos): `getSalesByPayment` y `getAllSalesByDrawerPeriod` leían la columna `tags` (movida a `meta` JSONB) + `USE INDEX` de MySQL (commit `b796b3c`).
- **3er reporte: `a_report_inventory`** (commit `679c52e`): mismo molde + fix PG (legacy usaba `BETWEEN "..."` con comillas dobles). El service hace lookup de items parametrizado (no `getAllItems`).
- **4º reporte: `a_report_users`** (commit `356efc2`): agregados por usuario (itemSold⋈transaction⋈contact). bootstrap expone `companyId` + `publicUrl`. El filtro de outlet del legacy era no-op (UUID>int).
- **Cola de reportes "simples" (lista del usuario)** — batch por fuente de datos (opción 2):
  - **Batch A (itemSold)** ✅: `users`, `categories`, `brands`. Verificados con seed de `itemSold` (commits 356efc2, 474d4b5, 4888788). El `SELECT itemId … GROUP BY` se resolvió quitando el itemId del SELECT (PG no tiene `MIN(uuid)`); la resta de combos (getAllCombosCompoundsDiscount) se omite (helper roto en PG). El bloque ad-hoc `?doit=beibe` de brands no se migró.
  - **Batch B (stock/item)** ✅ COMPLETO: `stock_day`✅ (commit 75b3851; 3 fixes PG: bool=int, DATE(), y `ORDER BY stockId DESC`→`stockDate DESC` porque stockId es uuid aleatorio en PG). `stock`✅ (commit 676fe6a; ver notas PG abajo). `satisfaction`✅ (commit ce5d60a).
  - **`satisfaction`** ✅ (commit ce5d60a): **primer WRITE por el BFF**. Patrón de escritura establecido: front POST → `bff/reports/x.php` (action=...) → `bffApiPost()` (NUEVO en bff/lib/api_client.php, forwardea el JWT) → API `POST /API/v1/...` que ejecuta con `$db->Execute` (ncmExecute devuelve false para DELETE) scopeado por `companyId`. Fix de seguridad: el DELETE legacy no scopeaba por companyId (IDOR) → ahora sí. **Follow-up**: el gate de permiso fino `allowUser('sales','delete')` no se puede usar en el API porque usa `ROLE_ID` y apiMiddleware solo define `PANEL_AUTHED_ROLE` → por ahora se bloquea el rol read-only (7); wirear `ROLE_ID` en apiMiddleware habilitaría allowUser en TODOS los writes del API v1.
- **Cola "simples" — estado final** ✅ COMPLETA (6 de 6): `users`✅ `categories`✅ `brands`✅ `stock_day`✅ `satisfaction`✅ `stock`✅. Todos los reportes "simples" designados por el usuario están migrados. Batch A ✅ + Batch B ✅.
- **10º reporte: `a_report_recurring`** (commit 980f908, 2026-05-27): Facturas Recurrentes. 2º reporte con WRITE por el BFF (pause→status 2, activate→status 1, remove→DELETE). Reusa el patrón satisfaction (front POST → bff bffApiPost → API POST con `$db->Execute`, gate rol 7). Tenant scoping SOLO por `companyId` (sin `getROC()`/`$roc`) porque la tabla `recurring` **no tiene columna `outletId`** — igual que el legacy. **Hallazgo de data shape (reutilizable):** `_routeToJsonb` almacenó `recurringSaleData` como un **STRING-valued key** en `data` JSONB: los writers originales (`cronCreateRecurringInvoice.php:103`, `app/action.php:2502`) hacen `json_encode($sale)` antes de pasar a `ncmInsert`, así que `data->'recurringSaleData'` tiene `jsonb_typeof = string` (no es un objeto anidado). El service lee con `->>` y `json_decode` en PHP → robusto a string-de-json y objeto anidado. **Lección generalizable:** cualquier otro reporte que lea una columna legacy "absorbida" a JSONB debe chequear si el writer usa `json_encode` antes del insert — si lo hace, el valor es un string y hay que usar `->>'columna'` + `json_decode`, NO `->` directo. Archivos: `panel/lib/reports/ReportRecurringService.php`, `panel/API/v1/reports/recurring.php`, `panel/bff/reports/recurring.php`, `panel/reports/recurring.html`, `panel/scripts/a_report_recurring.js`; routing: `/a_report_recurring` en `panel/router.php`. Seed: 2 filas en company 0001 (1 activa/mes, 1 pausada/sem, cliente "Cliente Prueba SRL") en la forma real del writer.
- **Notas PG de `a_report_stock` (commit 676fe6a, 2026-05-27)**: (a) `itemTrackInventory = true` (legacy usaba `= 1`, roto en PG). (b) Gate de outlet reemplazado: `OUTLET_ID < 2` siempre era true en PG (UUID<int → siempre 0<2 → "seleccione sucursal") → reemplazado por check de validez de UUID que devuelve flag `needsOutlet`. (c) Stock total/costo calculado desde la fila MÁS RECIENTE por `stockDate DESC` (no `getAllItemStock` que ordena por `stockId` — uuid aleatorio, no cronológico). (d) `principal.count` = onHand − Σ(depósitos) — corrección de bug legacy (el legacy restaba solo el último depósito). (e) Patrón N+1 (items × locations) mantenido deliberadamente, igual al legacy; flagged para optimización futura (`itemId IN (...)`). Archivos: `panel/lib/reports/ReportStockService.php`, `panel/API/v1/reports/stock.php`, `panel/bff/reports/stock.php`, `panel/reports/stock.html`, `panel/scripts/a_report_stock.js`; routing: `/a_report_stock` → `$bffStaticReports` en `panel/router.php`.
- **11º reporte: `a_report_summary_year`** (commit 844a436, 2026-05-27): Resumen Anual de Ingresos y Egresos. Read-only. El BFF hace los cálculos cross-data por mes: `netTotal = salesTotal − discount − returnsTotal − nonAddingTotal`; `revenue = netTotal − expenses`; `margin`; promedio anual. La API/service devuelve agregados mensuales crudos (EXTRACT en vez de MONTH(), sin USE INDEX, sin columna `transactionDate as date` no agrupada — las fronteras mes se derivan en PHP). El front formatea, mapea mes-número→nombre en español, arma tabla + chart Chart.js (barras Ingresos, líneas Egresos/Margen, anotaciones: línea de promedio, COVID-2020, fin de año) y selector de año derivado de `company.createdAt` (`years[]` del service). Reusa `getNonAddingToSales()` (ya probado en `summary`). Verificado E2E: 2026 Mayo netTotal 1.335.000, revenue 1.215.000, margin 91%; cero errores de consola. Archivos: `panel/lib/reports/ReportSummaryYearService.php`, `panel/API/v1/reports/summary_year.php`, `panel/bff/reports/summary_year.php`, `panel/reports/summary-year.html`, `panel/scripts/a_report_summary_year.js`; routing: `/a_report_summary_year` en `panel/router.php`.
- **12º reporte: `a_report_customers`** (commit 4c0ad35, 2026-05-27): Reporte de Clientes. Alcance NÚCLEO: KPIs + tabla de ranking de 19 columnas + gráfico de barras top-15 clientes + date-picker. Los extras exploratorios del legacy (mapa de localidades con lat/lng, gráfico de recurrentes/nuevos, analítica de comportamiento via `getCustomersRate`) están **intencionalmente diferidos como candidatos a Phase AI** — el agente los reemplazará con reportes ad-hoc. Archivos: `panel/lib/reports/ReportCustomersService.php`, `panel/API/v1/reports/customers.php`, `panel/bff/reports/customers.php`, `panel/reports/customers.html`, `panel/scripts/a_report_customers.js`; routing: `/a_report_customers`. Seed: 4 transacciones de company 0001 vinculadas al contacto "Cliente Prueba SRL" (demo).
- **`by_brands` — alias de ruta** (commit 4c0ad35): `a_report_by_brands` era un duplicado huérfano/no-linkeado de `brands` (misma lógica "Ventas por Marca"). Resuelto como router alias `/a_report_by_brands` → `/reports/brands.html`. Sin código nuevo. **Primer caso de "duplicado legacy consolidado vía alias"** — patrón reutilizable para otros reportes duplicados que aparezcan.
- **13º reporte: `a_report_expenses`** (commit 9b55d70, 2026-05-27): Movimientos de Caja. **3er WRITE por el BFF** (update + delete, ambos con `$db->Execute`, bound params, scopeados por `companyId`). El GET devuelve `{rows, users}` en un solo request: `rows` = movimientos con batch lookups para nombres de outlet/register/user (sin usar god-functions `getAllRegisters`/`getAllUsers`/`getCurrentOutletName`); `users` = contactos `type=0` para el dropdown del modal de edición. Outlet scope: `getROC(1)` filtra genuinamente por `OUTLET_ID` del JWT (UUID-strict equality) — a diferencia de users/stock donde el filtro era no-op (UUID>int). La semilla de verificación debía estar en el outlet ...002 (master), no en ...010. **Hallazgos generalizables de esta migración**: ① `formatNumberToInsertDB()` depende de las constantes `DECIMAL/THOUSAND_SEPARATOR` del contexto de página — fatales en el middleware de la API. Patrón correcto: el PARSING de amounts es presentacional → hacerlo en el FRONT (helper JS `parseAmount` que invierte la máscara), y la API solo valida `is_numeric` + castea a float. Aplica a cualquier reporte con escritura de monto dinero. ② `masksCurrency` (máscara JS compartida) strip-ea separadores y trata los dígitos restantes como unidades enteras → pre-alimentar un input de moneda con "150.000,00" da "15.000.000". Al pre-rellenar para edición, pasar el valor en unidades enteras (`String(Math.round(amount))`), nunca el string formateado. Footgun latente compartido por los forms de edición legacy. Archivos: `panel/lib/reports/ReportExpensesService.php`, `panel/API/v1/reports/expenses.php`, `panel/bff/reports/expenses.php`, `panel/reports/expenses.html`, `panel/scripts/a_report_expenses.js`; routing: `/a_report_expenses` en `panel/router.php`.
- **14º reporte: `a_report_drawers`** (commit 27b6452, 2026-05-27): Cierres de Caja. **4º WRITE por el BFF** (close + correct + remove). CRUD completo con re-query del DETAIL: el cliente envía solo el `drawerId`; el service re-fetch todo scopeado a `companyId` (el legacy pasaba un blob base64 desde el cliente → `?d=` confiado sin verificar). Totales (sold/expense/income/return) via helpers PG-safe `getAllSalesByDrawerPeriod`/`sumTotalBetweenDateRanges`; desglose por medio de pago via `getSalesByPayment`; lookups de nombre (outlet/register/user) batch parametrizados. Fix de seguridad: `remove()` legacy era `DELETE ... WHERE drawerId=? LIMIT 1` → IDOR (sin companyId) + `LIMIT` inválido en PG DELETE; ahora `WHERE drawerId=? AND companyId=?`. El cierre (`close()`) usa `PANEL_AUTHED_USER` (sub del JWT, constante correcta en el middleware API) validado como UUID para `drawerUserClose` (nullable FK) — `USER_ID` NO existe en el contexto API. Sumas de expense/income ahora filtran por `companyId` además de `registerId`. Archivos: `panel/lib/reports/ReportDrawersService.php`, `panel/API/v1/reports/drawers.php`, `panel/bff/reports/drawers.php`, `panel/reports/drawers.html`, `panel/scripts/a_report_drawers.js`; routing: `/a_report_drawers` en `panel/router.php`. **Hallazgos generalizables de esta migración** (críticos para reportes futuros): ① **TRAP `ncmExecute` single-row / `is_array`**: `ncmExecute` para un SELECT de una sola fila (sin `forceObj`, sin `getAssoc`) devuelve un objeto `CaseInsensitiveArray`, NO un array PHP — por eso `is_array($result)` es `FALSE` y silencia el valor (todo queda en 0 / null). Patrón correcto: validar con check truthy + acceso por clave (`$result['col'] ?? $default`). `is_array()` SÍ es correcto para resultados de `getAssoc=true` (que devuelven arrays PHP reales). Afecta cualquier helper que haga `ncmExecute` sin flags. ② **`PANEL_AUTHED_USER` vs `USER_ID`**: en el contexto del middleware API (`apiMiddleware()`), la constante de usuario autenticado es `PANEL_AUTHED_USER` (= claim `sub` del JWT, un UUID de usuario; = 0 en el path legacy `api_key`). `USER_ID` no está definido ahí y causa un error silencioso. Siempre usar `PANEL_AUTHED_USER` en código nuevo dentro de `panel/API/v1/`.
- **Bugs PG latentes descubiertos en customers**:
  - **`getCustomerData()` / `getContactData()` interpolación sin comillas** ✅ **ARREGLADO (commit cbe22cd, 2026-05-27)**: en la branch `'uid'`/`'contactId'`, construía `WHERE contactId = $id` interpolando el UUID via `$db->Prepare($id)`, pero `$db->Prepare` devuelve UUIDs **sin comillas** → error de sintaxis PG → la función caía al fallback `'Sin Nombre'` vacío en todos sus ~72 call-sites (incluyendo `ReportSatisfactionService`). Ahora usa bound params: `WHERE contactId = ? AND companyId = ?`. Tenant scope y flag `$isCustomer` preservados. Verificado E2E: el reporte de satisfaction ya resuelve el nombre del cliente.
  - **Columnas demotadas de `contact` seleccionadas explícitamente** (migración `06_contact_jsonb_demote.sql`): `contactAddress`, `contactAddress2`, `contactLocation`, `contactCity` ya no existen como columnas reales — están en `contact.data` JSONB. Cualquier query que las seleccione por nombre explícito falla con "undefined column" en PG. Requiere `SELECT *` + `_flattenJsonb` (o `data->>'...'`). Generalizable: ante cualquier query que nombre columnas demotadas de `contact`, reemplazar por `SELECT *` + flatten. Ver también nota homóloga en `04-modelo-de-dominio.md`.
- **Bug latente `lessInternalTotals()` + `USE INDEX` + columna `tags`** ✅ **ARREGLADO (commit 50acccb, 2026-05-27)**: quitado `USE INDEX` (MySQL), `tags` reemplazado por `meta->>'tags' AS tags`, y `transactionType IN($tTypes)` parametrizado (split/intval/bind; `SMALLINT` → `intval` correcto). Verificado vía PDO (9 filas, tags vía meta->>).
- **`getAllContacts` / `getAllContactsRaw` — parametrizar companyId/type/IN** ✅ **ARREGLADO (commit 1c2af24, 2026-05-27)**: `getAllContactsRaw` tenía companyId interpolado sin comillas (UUID → error sintaxis PG) y su field-list por defecto seleccionaba columnas demotadas a `data` JSONB por migración 06 → "undefined column". Reescrito a `SELECT *` vía `ncmExecute` getAssoc (aplica `_flattenJsonb`) con companyId/type como bound params; ignora la lista legacy `$fields`/`$index`. `getAllContacts` (wrapper): type interpolado e `IN($in)` con `db_prepare` no-op (UUIDs sin comillas) → ahora type e IN parametrizados (split+bind); `IN` filtra por `contactId`. ~~**Residuales pre-existentes fuera de scope**~~ ✅ **CERRADOS (commit 1a1beb9)**: (1) `get_sales.php:48` ya no interpola el IN en `$where` — rutea por el wrapper `getAllContacts` (path `$in` parametrizado, `realKeys=true`, guard si no hay ids). (2) El wrapper ahora hace `_flattenJsonb($result->fields)` por fila en el loop hand-rolled (forceObj no aplana solo) → los campos demovidos a JSONB (`contactAddress`/`City`/`Note`) se exponen. Verificado E2E: `/API/get_sales.php` devuelve `customer_address` "Calle Falsa 123" (columna demotada). **Familia de god-functions PG 100% cerrada, sin residuales.**
- **Familia de god-functions PG CERRADA**: `getCustomerData`/`getContactData` ✅, `getAllItems`/`getAllItemsRaw` ✅, `getAllContacts`/`getAllContactsRaw` ✅, `lessInternalTotals` ✅ — todas con bound params.
- **Seed de verificación** (company 0001, dejado como demo): ~12 transacciones (`meta.seed=bff-pilot-verify`), 6 movimientos de stock (`stockNote=seed:bff-inv-verify`), 9 líneas itemSold + 2 categorías/2 marcas asignadas a 2 ítems (`meta.seed=bff-itemsold-verify`).
- **`getAllItems()` / `getAllItemsRaw()` interpolación sin comillas** ✅ **ARREGLADO (commit 179208c, 2026-05-27)**: ambas interpolaban `AND companyId = " . COMPANY_ID` (UUID sin comillas → error sintaxis PG → query vacía; el widget de KPIs de inventory via `getAllInventoryAndItemsModule` daba 0). `getAllItems` además interpolaba `itemId IN($in)` con `db_prepare` (no-op) → roto + SQL-injectable. Ahora: `companyId = ?` como bound param; la lista `$in` ("uuid1,uuid2,…") se split/trim/empty-filtra y se bindea en `IN (?,?,…)`. Tenant scope y semántica preservados. Vector de inyección cerrado. Verificado vía PDO (raw → 4 items, IN(2) → 2). El widget de inventory KPIs (`getAllInventoryAndItemsModule`) debería ahora poblar correctamente en vez de dar 0.
- **Fix `main.php` — banner T&C + listado de empresas (impersonación) (commit 0102d0f + data, 2026-05-27)**:
  - **Banner "Términos y Condiciones" eliminado**: se quitó el bloque de `mainAlerts()` (`functions.php`) que mostraba "Hemos actualizado nuestros Términos y Condiciones / Acepto". Se mantiene la alerta de facturas vencidas (EXPIRED). El handler `?acceptTerms=true` queda inalcanzable pero válido.
  - **Listado de empresas vacío (no se podía impersonar)**: el handler `?action=showTable` (main.php:629) gatea con `$userPermission['companyList']` (= `data[0].permissions.encom.companyList` del contacto). El usuario `admin@local.test` ("Administrador") tenía `data={}` (sin permisos) → "No permissions" → tabla vacía. Fix (autorizado por el usuario): se le copió el set de permisos `encom` del usuario `master@local.test` (privilege grant, **dato de demo en company 0001**, no en git). El código de `showTable` es PG-limpio (usa `company`/`contact`/`outlet` + campos flatten). Verificado E2E: tabla con 4 empresas + enter-links `?url=true&companyId=`.
  - **Hallazgo (ADR-001 reforzado): `main.php` usa la SESIÓN PHP legacy para `COMPANY_ID`, no el JWT.** Si la sesión PHP está impersonando otra empresa (vía `getCompanyLoginSession`), `main.php` rebota a `/login`→`/@` (gate `COMPANY_ID != ENCOM_COMPANY_ID`, línea 12) aunque el JWT/BFF estén en la master (0001). **Reset sin password:** `?backToSaaS=true` (config.php:146, sólo si `SAAS_ADM`) hace `getCompanyLoginSession(MASTER_COMPANY_ID)` y redirige a `/main`. Refuerza el follow-up de ADR-001: unificar la identidad en el JWT (re-emitir al impersonar) en vez de la sesión PHP divergente.
- **15º reporte: `a_report_products`** (commit f7ff2de, 2026-05-27): Reporte de Artículos. **PRIMER REPORTE "HEAVY"** (~1750 líneas legacy). Read-only con 3 vistas en tabs (Resumen — agregado por producto, Detallado — líneas de venta, Combos) + 4 KPIs con comparación período anterior + gráfico de barras + date-picker + búsqueda de detalle + drill-down por URL (itmId/cusId/usrId/month/year). Archivos: `panel/lib/reports/ReportProductsService.php`, `panel/API/v1/reports/products.php`, `panel/bff/reports/products.php`, `panel/reports/products.html`, `panel/scripts/a_report_products.js`; routing: `/a_report_products` en `panel/router.php`.
  **Hallazgos críticos (generalizables a los demás reportes heavy):**
  1. **Patrón heavy-report — motor ERP en el service**: para reportes financieros con múltiples vistas, las fórmulas exactas (utilidad por fila + agregados) se computan en el Service (fuente única de verdad), no en el BFF. El BFF solo suma/deriva KPIs y el gráfico. El front formatea. Reduce el riesgo de que una fórmula diverja entre capas. Aplica a compras, transacciones y cualquier reporte con fórmula financiera por fila.
  2. **Fórmulas financieras difieren por vista y son P0**: general/detail: `(total − COGS) − comisión` (sin restar impuesto); combos: `((total − impuesto) − COGS) − comisión`. Un primer pass del code-reviewer detectó la fórmula de detail restando impuesto indebidamente (P0 financiero). Verificar cada fórmula contra la línea legacy original.
  3. **Asimetría de SUM por modo de filtro (P1 si se normaliza)**: los branches `cusId`/`usrId` suman COGS/descuento **sin** multiplicar por unidades; los branches default/`itmId`/`month` suman **con** `*units`. Hay que preservar esta asimetría — un primer pass la normalizó incorrectamente.
  4. **Bugs PG recurrentes en heavy reports**: `USE INDEX` (MySQL) → eliminar; `MONTH()`/`YEAR()` → `EXTRACT`; columnas movidas a JSONB (ej. `transaction.tags → meta`) no se pueden SELECT por nombre; `getAllCombosCompoundsDiscount` roto en PG (`itemSoldParent != 0` UUID-vs-int) → se omite consistentemente (igual que en brands/categories); "self-heal write en GET" (ej. ncmUpdate de `itemSoldTax` sin scope + LIMIT inválido PG en DELETE) → **eliminar, nunca portar** (recomputar para display, jamás escribir en un GET).
  5. **Bug de interpolación literal en búsqueda `src`**: el término estaba LITERALMENTE dentro de un string PHP single-quoted (`'... LIKE \'%\' . $word . \'%\''`) — nunca interpolaba. Reescribir como ILIKE parametrizado. Patrón a vigilar en otros reportes con search.
- **16º reporte: `a_report_purchases`** (Compras y Gastos): **PRIMERA MIGRACIÓN PARCIAL**. El módulo legacy (~2632 líneas) es un CRUD pesado + 2 fiscales, no un reporte limpio. Se migraron al BFF SOLO las 3 vistas de LECTURA (`general` tipo 1,4 + deuda; `cobros` tipo 5 + comprobante padre; `detail` transaction⋈itemSold). El CRUD de edición (`edit`/`update`/`paymentForm`/`addPayment`/`delete`) y los fiscales (`rg90`, `libro-compra`) **siguen en el PHP legacy `a_report_purchases.php`**. Archivos: `panel/lib/reports/ReportPurchasesService.php`, `panel/API/v1/reports/purchases.php`, `panel/bff/reports/purchases.php`, `panel/reports/purchases.html`, `panel/scripts/a_report_purchases.js`.
  **Patrón nuevo — migración parcial vía router** (reutilizable para los otros pesados CRUD: transactions, giftCards): `panel/router.php` sirve el front estático cuando NO hay `?action=`, y cae al PHP legacy cuando SÍ lo hay (`if ($path === '/a_report_x' && empty($_GET['action']))`). El front recablea las 3 lecturas al BFF; los writes/fiscales se cargan en los modales globales del shell (`#modalXLarge` editar, `#modalTiny` pago) o ventanas nuevas, apuntando a `/a_report_purchases?action=…`. En prod replicar con `RewriteCond %{QUERY_STRING} !(^|&)action=` en `.htaccess`.
  **Hallazgos generalizables:**
  1. **`transaction.transactionDetails` fue absorbido a `meta` JSONB** (Phase PG) — no se puede SELECT por nombre. Leer con `a.meta->>'transactionDetails'` + `json_decode`. Mismo patrón que `tags`→`meta`.
  2. **BOOLEAN PG `transactionComplete`**: ADOdb (el driver real de `ncmExecute`) lo devuelve como **1/0**, así que `(int)$v` funciona; pero PDO/otros pueden devolver `'t'/'f'` (y `(int)'t'===0` silenciaría los completos). Se agregó un helper `isComplete()` robusto al driver (`is_bool` || `'1'/'t'/'true'`). Aplicar a cualquier lectura de columna boolean PG.
  3. **`country` agregado al bootstrap** (`/API/v1/bootstrap.php` → `config->>'settingCountry'`): para gatear reportes fiscales locales (RG90/Libro Compra, PY-only) desde el front. Reutilizable por otros fiscales.
  4. **Fixes PG de paso**: `FROM transagction` (typo legacy en la rama `src` de detail) → `transaction`; rama `cobros`/supId tenía un `AND transactionDate` colgante (sin BETWEEN) seguido del `$roc` → SQL roto en PG, eliminado; deuda calculada con un único `SUM ... GROUP BY transactionParentId` (el legacy hacía N+1).
  5. **Seguridad**: el service es read-only; el `delete` de pagos legacy (que era IDOR sin companyId) sigue sirviéndose por `?action=delete` legacy (que SÍ scopea por companyId). Búsqueda `src` parametrizada (ILIKE), lookups batch con `IN (?,?…)`, roc por companyId/outlet en cada query.
- **17º reporte: `a_report_transactions`** (Pagos y Transacciones): **EL MÁS GRANDE (~3987 líneas)**. 2ª migración parcial. Se migraron al BFF 3 vistas de lectura de BD: `detail` (ventas tipos 0,3,6,7,8 con deuda de crédito + auth/prefijo/padding del comprobante de la caja + tags + totales calculables por tipo), `cobros` (pagos tipo 5 + comprobante padre tipo 0,3), `quotes` (cotizaciones tipo 9 + estado). **La vista `feTable` NO se migró**: es un gateway a una API externa de Facturación Electrónica con token hardcodeado (no verificable en dev, análogo a vpayments) → el front carga su HTML directo del legacy `?action=feTable`. CRUD/export/fiscales (rg90/libro-ventas/mcal/tusFacturas) quedan legacy. Router: `transactions` agregado al mapa `$bffPartialReports` (mismo patrón que purchases). Archivos: `panel/lib/reports/ReportTransactionsService.php`, `panel/API/v1/reports/transactions.php`, `panel/bff/reports/transactions.php`, `panel/reports/transactions.html`, `panel/scripts/a_report_transactions.js`.
  **Hallazgos generalizables:**
  1. **TRAP `array_map` sobre filas getAssoc de `ncmExecute`**: extraer ids con `array_map(fn($r)=>$r['col'], $res)` sobre el resultado de `ncmExecute(...,getAssoc=true)` (filas `CaseInsensitiveArray`) **lee mal algunas columnas** (devuelve vacío para `customerId` mientras `userId`/`transactionId` sí funcionan, dependiendo del SELECT — se observó con SELECT explícito de columnas + una columna computada `meta->>'x' AS x`). El `foreach($res as $f){ $ids[]=$f['col']; }` es FIABLE. Regla: recolectar ids/valores de filas getAssoc con `foreach`, NO `array_map`. (En purchases el array_map andaba porque era sobre filas `->fields` recolectadas con `while`, no sobre el array getAssoc directo.)
  2. **`transaction.tags` absorbido a `meta` JSONB** → leer con `meta->>'tags'` (JSON string → `json_decode`). Mismo patrón que `transactionDetails`.
  3. **`register.registerReturnPrefix` vive DENTRO del `data` JSONB de la caja** (no flatten) → `json_decode($r['data'])`. Distinguir clave-ausente (null, no override) de clave-vacía ('' limpia el prefijo) con `array_key_exists` para devolución (tipo 6), igual que el legacy.
  4. **Datos de caja por fila eran N+1** en el legacy (un `SELECT * FROM register` por fila) → un solo lookup batch `registerInfo()`.
  5. **Correctitud financiera**: deuda de crédito `topay = netTotal − SUM(ABS pagos+devoluciones tipo 5,6)`; tipo 7 (anulado) → todos los totales calculables en 0; padding del comprobante con `registerDocsLeadingZeros`. Verificado E2E con seed (contado/crédito+pago/devolución/anulada/cotización).
- **18º reporte: `a_report_giftcards`** (Gift Cards, mediano ~531 líneas): 1 vista de lectura (`detail` = giftCardSold activadas con beneficiario/sucursal/documento resueltos) + 4 KPIs (vencidas/por-vencer/canjeadas/vigentes + valor vigente). El form de edición (`giftcard`) y los writes (`update`/`delete`) quedan legacy vía `?action=`. El BFF computa los KPIs sobre las filas (cross-data). **Edge case faithful (code-reviewer P1):** una gift card sin `giftCardSoldExpires` cuenta como VENCIDA, igual que el legacy (`strtotime('')=false → 0 < hoy`); el guard `!$exp || $exp < $now` lo preserva. **P2 hardening:** el `giftCardSoldColor` se valida como hex (`/^[0-9a-fA-F]{3,8}$/`) antes de interpolarlo en `style="color:#..."` (esc() no cubre `;`/`:` → inyección CSS). Archivos: `panel/lib/reports/ReportGiftcardsService.php`, `panel/API/v1/reports/giftcards.php`, `panel/bff/reports/giftcards.php`, `panel/reports/giftcards.html`, `panel/scripts/a_report_giftcards.js`; router: `giftcards` en `$bffPartialReports`.
- **19º reporte: `a_report_schedule`** (Agendamientos, mediano ~907 líneas): 3 vistas de lectura — `detail` (citas tipo 13 + summary de conteos por estado + donut chart + KPIs), `stats` (conteos por contacto: `uit=usr` usuarios / `uit=cus` clientes), `sessions` (paquetes con `itemSessions` > 1, realizadas/pendientes). El modal de sesiones (`detail` por id) y el write (`delete`) quedan legacy vía `?action=`; el click en una fila abre el form de TRANSACTIONS legacy (cross-módulo). Router: `schedule` en `$bffPartialReports`.
  **Hallazgo CRÍTICO (TRAP, generalizable):** `ncmExecute(..., getAssoc=true)` usa ADOdb `GetAssoc` que **keyea el resultado por la PRIMERA columna del SELECT**. Si esa columna se REPITE entre filas (ej. un agregado `GROUP BY contacto, estado` donde el contacto aparece en varias filas), cada fila sobrescribe a la anterior → **se pierde data silenciosamente** (sólo sobrevive 1 fila por valor de la 1ª columna). Síntoma observado: stats mostraba sólo 1 estado por contacto. **Regla:** usar getAssoc SOLO cuando la 1ª columna es única por fila (id, transactionId, etc.); para agregados con primera-columna repetida, iterar el recordset con `forceObj=true` + `while(!$res->EOF)`. (Distinto del TRAP de `array_map` de transactions, que es sobre cómo se leen las filas; este es sobre cómo `GetAssoc` las indexa.)
  **Otros hallazgos:** `contactInCalendar` (filtro de usuarios en calendario) e `itemSessions` (sesiones por ítem) fueron demovidos a `data` JSONB → leer con `data->>'...'` (cast `::int` con `COALESCE(NULLIF(...,''),'0')` para itemSessions). `getTotalScheduleByStatus()` interpola el contactId SIN comillas (UUID → error PG) y es N+1 → reemplazado por agregados parametrizados (`statusTotals` GROUP BY estado, `countsByContact` GROUP BY contacto+estado). `nombre de contacto`: el legacy usa contactName (no concatenar secondName, que duplicaba el nombre cuando coinciden). Archivos: `panel/lib/reports/ReportScheduleService.php`, `panel/API/v1/reports/schedule.php`, `panel/bff/reports/schedule.php`, `panel/reports/schedule.html`, `panel/scripts/a_report_schedule.js`.
- **20º reporte: `a_report_production`** (Producción, mediano ~1068 líneas): 3 vistas de lectura — `general` (producción agregada por ítem = tabla `production` tipo 1 + ventas de ítems `direct_production`, con utilidad por fila), `detail` (ventas direct_production línea por línea), `compound` (compuestos de `productionRecipe` + stock `stockSource='production'`, toggle día). El modal de receta (`recipe`), el export XLSX y el write (`delete`) quedan legacy vía `?action=`. **El módulo de producción está DESHABILITADO en la empresa de prueba** → verificado a nivel data-layer (las 3 vistas ejecutan sin error PG) + `general` con un seed mínimo (1 fila `production` → utilidad 50000 computada correcto) + render estructural; `detail`/`compound` ejecutan limpio pero sin datos reales que validen sus números (limitación aceptada). Fórmula de utilidad replicada FIEL de `buildTableList` legacy: `average=cogs/units; utility=((price−average)−comisión)−impuesto; total=utility*units`.
  **Fixes PG (el legacy estaba MUY roto en este módulo):** ① **`productionType` es BOOLEAN en PG** (no int) → el filtro legacy `productionType = 1` da error → usar `= true`. ② compuestos: `$db->GetAssoc()` keyea por la 1ª columna (itemId) dejándolo FUERA del value → el `IN()` quedaba vacío; + `stockSource = \'production\'` en string PHP doble-comilla deja backslashes literales (`\'production\'`) → error PG. Ahora itemIds bindeados parametrizados + `stockSource = ?`. ③ `$roc` sin calificar era ambiguo en los JOIN (transaction e item ambos tienen companyId) → calificado por alias `b.`. ④ meta de ítem vía `getItemData()` (aplana JSONB; `itemTax`/`itemComission`/`categoryId` demovidos). ⑤ **(code-reviewer P1)** general agregaba `GROUP BY itemId, userId` + asignación con overwrite → sub-contaba ítems producidos por >1 usuario; corregido a `GROUP BY itemId` + `MAX(userId)`. Archivos: `panel/lib/reports/ReportProductionService.php`, `panel/API/v1/reports/production.php`, `panel/bff/reports/production.php`, `panel/reports/production.html`, `panel/scripts/a_report_production.js`.
- **21º–23º reportes: `cashflow` + `open_invoices` + `vpayments`** (los 3 "con wrinkle", todos read-only, en `$bffStaticReports`):
  - **`a_report_cashflow`** (Flujo de Caja): una vista (`getCashFlow`) — ingresos (ventas contado 0,6 + cobros) − egresos (compra mercadería + gastos + pagos) = saldo; saldo inicial del período anterior + acumulado. **Wrinkle resuelto:** el split mercadería/servicios usaba `itemId > 0`/`= 0` (MySQL: 0 = sin ítem) → en PG itemId es UUID: mercadería = `itemId IS NOT NULL`, gasto = `itemId IS NULL`. `getChartSales` legacy era código muerto (`$sql` indefinido, front no lo llamaba) → no migrado.
  - **`a_report_open_invoices`** (Cuentas por Cobrar/Pagar): una vista (`general`, `state=income` tipo3 / `outcome` tipo4), agrupada por contacto con facturas + deuda + KPIs. **Se ELIMINÓ el self-heal write** (marcaba `transactionComplete=1` en el GET — §16). **Fix PG:** `transactionComplete < 1` rompe en boolean → `= false`. Estado de vencimiento fiel (incl. rareza legacy timestamp-vs-duración → toda no-vencida es "por vencer"). `getRowPaid` legacy era branch muerto.
  - **`a_report_vpayments`** (Pagos ePOS): **GATEWAY** — la API hace `curlContents(API_URL/get_vpayments)` (Bancard/Dinelco), devuelve registros + KPIs; BFF proxea; front arma donut+tabla. **`api_key` computado en el service** (`sha1(config->>'accountId')`) porque el middleware API no carga `config.php` (donde se define `API_KEY`) → la constante no existe ahí (causaba 500). **No verificable en dev** (sin Bancard) → estructural: la cadena devuelve `rows:[]` sin romper.
  **TODOS los reportes designados están migrados al BFF (23 + 1 alias).** La migración de reportes está COMPLETA. Ahora en curso: módulos CRUD del panel (no-reportes).

- **`a_outlets` (Sucursales) — 1er módulo CRUD del panel migrado (commit 99d1286, 2026-05-27)**: `OutletsService.php` (list/get/update) + `API/v1/outlets.php` (GET list|single, POST update, gate rol 7) + `bff/outlets.php` (proxy) + `views/outlets.html` (lista ncmDataTables + form Alpine x-model en modal) + `scripts/a_outlets.js` (§17 detached-initTree). **Migración PARCIAL**: list/get/update BFF; create/delete legacy vía `?action=`; businessHours (jQuery widget) y depósitos diferidos. Router: `$bffPartialModules` (nuevo mapa, paralelo a `$bffPartialReports`; fronts en `panel/views/`). TRAP data-JSONB partial-update resuelto (ver `08-convenciones.md §18`). 1er uso Alpine en CRUD con modal (§17.2).

- **`a_settings` (Ajustes) — 2º módulo CRUD del panel migrado (commits 1d8fd03..63435b0, 2026-05-28)**: migración COMPLETA de las 4 tabs reales del legacy (Perfil + Visualización + Monedas + Plantillas de Impresión). Archivos clave: `SettingsService.php` (general/options/taxonomies/currencies/templates) · `API/v1/settings.php` (views + POST types) · `bff/settings.php` (proxy+composición) · `views/settings.html` (tabs Alpine) · `scripts/a_settings.js` (Alpine §17) · `scripts/a_settings_templates.js` (widget jQuery portado verbatim — ver §17.2 + nuevo §20). FIX crítico de prod: el save de Ajustes estaba ROTO en PG (legacy hacía `AutoExecute('setting',…)` pero la tabla `setting` fue eliminada en Phase PG → ahora rutea a `company.config` JSONB vía `ncmUpdate` merge `||`). Fixes de seguridad en templates: quitado `OR companyId=1` (rompe con UUID), quitado `LIMIT 1` en DELETE (inválido PG), agregado `AND companyId=?` en UPDATE (era IDOR). Nota sobre logo: devuelve URL + uploadUrl pero NO verificable en dev (resize/sysimages en infra prod).
  - **Ecommerce N/A (UI MUERTA)**: el legacy `a_settings.php` tiene 4 tabs reales (Perfil/App/Sucursales-link/Plantillas); ecommerce NO existe como tab. Solo queda un handler backend `type=ecommerce` + un `a_settings2.js` huérfano (ruta `/a_settings2` inexistente). La tabla `ecommerce` la consume `franchiser.php` (otro módulo). No se migró — migrar fielmente significa no inventar UI que el legacy no muestra.
  - **Gap de verificación pendiente**: el drag/drop visual del template builder NO se verificó en browser (el shell `@.php` requiere sesión PHP legacy, no JWT — refuerza ADR-001 sobre unificar identidad). Pendiente smoke test visual en el entorno de prod/staging.
  - **Follow-up de seguridad**: `upload.php` confía en `?id=` del cliente (IDOR preexistente) → gatear `companyId` server-side.

**Módulos CRUD pendientes** (orden tentativo, pueden variar por prioridad de negocio):
`a_contacts` · `a_items` · `a_registers` · `a_banks` · `a_billing` · `a_history_billing` · `a_purchase` · `a_modules` · `a_inventory_count` · `a_bulk_*`
- **Integración**: el front corre como **fragmento dentro del shell existente** (`@.php` inyecta `reports/summary.html` en `#bodyContent` por hash-nav). El shell provee head/menú/jQuery/Chart.js/BS3/globals; el modo "standalone 100% autónomo con auth+chrome propio" queda DIFERIDO.
- Redirect a `/login` ante 401 del BFF NO implementado todavía (el shell ya gatea auth; se implementa cuando el front sea standalone).
- **Routing dev**: `panel/router.php` mapea `/a_report_summary` → sirve `reports/summary.html` estático. Prod: replicar con `RewriteRule` en `.htaccess`.

**Nota sobre el "split" previo (commits 5adfc79, d6bcfef)**: summary e inventory solo
tienen el JS extraído a `scripts/` — siguen siendo `.php` que mezclan back+vista HTML.
Es un paso intermedio, NO la estructura objetivo. El piloto los lleva al modelo real.

**Esfuerzo**: el piloto fija el patrón (incl. cómo el `.html` resuelve auth+chrome sin PHP);
replicar a los demás es mecánico una vez probado.

---

## CDN Local completo

**Problema**: Algunos assets todavía referencian CDNs externos. Offline-first requiere todo local.

**Propuesta**: Mover todo a `/assets/vendor/`. Ya hay avance parcial.

**Esfuerzo**: ~4 horas para completar

---

## Higiene de assets — vendoring vía npm (EN CURSO 2026-05-27)

**Objetivo**: gestionar los ~55 vendor JS de `assets/vendor/js/` vía `package.json` (provenance,
versión pineada, `npm audit`) en vez de archivos "misteriosos" commiteados. Alpine ya entró así.

**Plan**: `npm i --save-exact pkg@<versión-vendoreada>` para los npm-ables; `build.sh` (o un
`vendor-sync`) copia el dist canónico de `node_modules/` a `assets/vendor/js/`. **Pinear EXACTO**
(no bumpear — jQuery 3.6.3 / Chart 2.9.4 / Bootstrap 3.4.1 están congelados a propósito).

**Quedan como archivo** (no en npm / custom): `iguider`, `jquery.businessHours-1.0.1` (npm
`business-hours` es otro paquete) + el código propio del proyecto (`ncm.js`, `common.js`,
`documentPrintBuilder`, `ncmMaps`, etc. — no son vendor).

**Bumps de major a testear** (npm no tiene la versión vieja o difiere): `@fingerprintjs/fingerprintjs`
v3→v5, `jsrsasign`, `snap` (verificar snap.svg vs el "snap-1.9.3" vendoreado).

### Follow-up: reemplazar iguider (tour de onboarding)

`iguider` (~107 refs en dashboard/purchase/app POS) no está en npm y parece medio abandonado (hay
un `iguider.stub.js` → puede estar ya stubbeado). **Reemplazo recomendado: `driver.js` 1.4.0 (MIT,
~5KB, sin deps, vanilla).** Shepherd/intro.js son **AGPL** → descartados para SaaS comercial cerrado.
**Antes de migrar: verificar si el tour está activo** — si está muerto, ELIMINAR iguider en vez de
reemplazarlo. Es scope aparte (cambio de comportamiento del tour).

---

# Prioridad BAJA (largo plazo)

## Phase 6 — Arquitectura moderna (Slim 4)

**Dependencia**: Phases 1-5

**Problema**: El monolito PHP sin framework dificulta testing, middleware chains, DI.

**Propuesta**: Introducir Slim 4 como app paralela bajo `/v2/...`. Un endpoint a la vez.

**Esfuerzo**: Setup inicial ~2 días. Migración gradual.

**Riesgos**: Complejidad de mantener dos stacks. Solo hacer cuando haya masa crítica de endpoints nuevos.

---

## Phase AI.4+ — WhatsApp, alertas proactivas, memoria

Items futuros del agente IA. Ver sección "Phase AI.1" arriba para detalle completo.

---

# Items completados (referencia histórica)

## Phase 0 — Security Hotfixes ✅

| # | Qué | Estado |
|---|-----|--------|
| 0.1 | Eliminar bypass key `d41d8cd98f...` | ✅ |
| 0.2 | Reemplazar `Access-Control-Allow-Origin: *` con allowlist | ✅ |
| 0.3 | Gatear `?debug` con `APP_DEBUG=true` en `.env` | ✅ |
| 0.4 | Mover `SALT` a `.env` como `HASHIDS_SALT` | ✅ |
| 0.5 | Headers de seguridad (`X-Content-Type-Options`, `X-Frame-Options`, etc.) | ✅ |

## Phase WS — WebSocket Microservice ✅

Reemplaza Pusher (tercero de alto costo) con infraestructura propia.

### Arquitectura

```
PHP (wsPublish)  →  Redis Pub/Sub  →  ws-server (Node.js)  →  Browser
```

### Archivos creados

| Archivo | Descripción |
|---------|-------------|
| `ws-server/index.js` | Servidor WebSocket Node.js con ioredis |
| `ws-server/package.json` | deps: `ws@^8.17.0`, `ioredis@^5.3.2` |
| `ws-server/Dockerfile` | Node 20 Alpine, non-root |
| `panel/includes/ws_publish.php` | Publica a Redis sin extensión (raw RESP via fsockopen) |
| `app/includes/ws_publish.php` | Idem para el módulo app |
| `panel/standalone/scripts/ncm-ws.js` | Wrapper JS compatible con API de Pusher |

### Archivos migrados (Pusher → NcmWS)

| Archivo | Canal | Evento |
|---------|-------|--------|
| `panel/standalone/kds.php` + `kds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/kds2.php` | `{outletId}-KDS` | `order` |
| `panel/standalone/cds.php` + `cds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/checkoutScreen.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/standalone/checkoutScreen2.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/main.php` | `ncm-ePOS` | `payoutNow` |
| `panel/API/send_webSocket.php` | dinámico | dinámico |

### Protocolo del cliente (`ncm-ws.js`)

```js
// Drop-in replacement de Pusher:
var pusher = new NcmWS(WS_URL);
var ch = pusher.subscribe('outlet123-KDS');
ch.bind('order', (data) => { ... });
ch.unbind('order');
```

## Phase 1 — Auth JWT para `/app` ✅

Reemplazó el mecanismo de identidad falsificable (`$_GET['l']` base64) con JWT.

| # | Qué | Archivo | Estado |
|---|-----|---------|--------|
| 1.1 | Endpoint de login | `app/API/auth.php` | ✅ |
| 1.2 | Middleware JWT | `app/includes/jwt_middleware.php` | ✅ |
| 1.3 | JWT primero, fallback legacy + header `X-Legacy-Auth` | `app/action.php` | ✅ |
| 1.4 | Validar con JWT, mismatch check en POST | `app/fetchs.php` | ✅ |
| 1.5 | Refresh token | `app/API/refresh.php` | ✅ |
| 1.6 | Actualizar JS del POS | `app/index.php` | ⚠️ pendiente verificar |

### Notas de implementación

- Cookie HttpOnly `_jwt` (browser) + header `Authorization: Bearer` (clientes programáticos)
- Payload: `sub` (userId), `cid` (companyId), `oid` (outletId), `rid` (registerId), `role`, `iat`, `exp`
- Fallback legacy activo: si no hay JWT, sigue funcionando con Hashids en `?l=`
- `X-Legacy-Auth: 1` header para monitorear adopción

## Phase 2 (parcial) — Formalizar API del panel

### Archivos creados ✅

| Archivo | Descripción |
|---------|-------------|
| `panel/API/lib/response.php` | Envelope canónico: `apiOk()`, `apiError()`, `apiNotFound()`, etc. |
| `panel/API/lib/api_middleware.php` | Middleware centralizado: JWT + fallback api_key, define constantes |
| `panel/API/auth.php` | Login JWT para el panel (POST email+pass → JWT + HttpOnly cookie) |
| `panel/includes/jwt.php` | Copiado de `app/includes/jwt.php` |
| `panel/API/lib/legacy_db.php` | Helper conexión legacy → PG (creado durante migración MySQL→PG) ✅ |

### Envelope canónico

```json
// Éxito
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }

// Error
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

> Phase 2 sigue parcial. Ver sección "Prioridad ALTA" para items pendientes (2.A, 2.B, 2.C).

## Phase UUID — Migración a UUIDs ✅

enc()/dec() convertidas a identity functions (sin Hashids). UUID v7 auto-generado en ncmInsert.

| # | Qué | Estado |
|---|-----|--------|
| U.1 | `enc()`/`dec()` → identity passthrough (panel, app, crons) | ✅ |
| U.2 | `generateUuidV7()` en `functions.php` | ✅ |
| U.3 | `ncmInsert` auto-genera UUID v7 en la PK correcta por tabla | ✅ |
| U.4 | JWT payload cambiado de int a string para cid/oid/rid/sub | ✅ |
| U.5 | `contactUID` eliminado — reemplazado por `contactId` en ~103 archivos | ✅ |

## Phase PG — MySQL → PostgreSQL ✅

**Dependencia:** Phase UUID completa

| # | Qué | Estado |
|---|-----|--------|
| PG.1 | `db-schema-postgres.sql` v2 — 47 tablas, UUIDs, JSONB, todos los FKs | ✅ |
| PG.2 | `company` mergeada con `setting` + `module` + `companyHours` → `config JSONB` | ✅ |
| PG.3 | `item.data`, `contact.data`, `transaction.meta`, `itemSold.meta` JSONB | ✅ |
| PG.4 | `_flattenJsonb()` — lectura transparente de JSONB en PHP | ✅ |
| PG.5 | `_getTableSchema()` + `_routeToJsonb()` — escritura automática a JSONB | ✅ |
| PG.6 | `ncmInsert` y `ncmUpdate` usan routing JSONB | ✅ |
| PG.7 | `docker-compose.yml` — PostgreSQL 16, sin MySQL | ✅ |
| PG.8 | `panel/includes/db.php` + `app/includes/db.php` → `db.postgres.php` | ✅ |
| PG.9 | Queries a `FROM setting`/`FROM module` → `FROM company` (~95 archivos) | ✅ |
| PG.10 | `settingBlocked`→`blocked`, `settingPlanExpired`→`planExpired`, `settingSlug`→`slug` | ✅ |
| PG.11 | Campos JSONB en SQL: `config->>'settingName'`, `config->>'settingRUC'`, etc. | ✅ |

## Web Push ✅

- VAPID nativo (reemplaza OneSignal)
- Tabla `push_subscriptions` (migración `database/migrations/postgres/03_push_subscriptions.sql`)

---

# Decisiones técnicas vigentes

| Decisión | Elección | Razón |
|----------|----------|-------|
| Lenguaje backend | PHP (mantener) | Sin capacidad de rewrite completo |
| WebSockets | ws-server propio (Node.js) | Eliminar costo de Pusher |
| Pub/Sub | Redis | Ya en el stack, sin dependencia extra |
| JWT | HS256 custom PHP | Sin composer dependency adicional |
| IDs en API | UUID v7 (post Phase UUID) | Hashids deprecados, enc/dec son identity |
| API location | Dentro de panel/ (mantener) | No vale la pena separar aún |
| AI Agent | Microservicio Python separado | No tocar el monolito |
| Conexión BD legacy | `panel/API/lib/legacy_db.php` helper | Centraliza migración MySQL→PG sin tocar lógica |

## Decisiones de convenciones (resueltas 2026-05-16)

- Envelope canónico: migrar TODOS los endpoints progresivamente (Phase 2.A confirmada)
- Estilo PHP: legacy en archivos existentes, PSR-12 en archivos nuevos
- Frontend: jQuery por ahora, decisión post Phase 2 + AI.1
- SQL legacy: auditoría + batch P0 (resultó tener riesgo bajo tras auditar)

---

# Variables de entorno completas

```ini
# Seguridad (Phase 0)
APP_DEBUG=false
HASHIDS_SALT=<random-64-char>

# JWT (Phase 1 + 2)
JWT_SECRET=<random-64-char>
JWT_TTL=28800

# WebSocket (Phase WS)
WS_URL=wss://ws.tudominio.com
REDIS_URL=redis://redis:6379

# AI Agent (Phase AI — pendiente)
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...
PUNTO_API_BASE=https://panel.tudominio.com/API
AGENT_JWT_SECRET=...

# Feature flags (Phase 6 — pendiente)
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```

---

# Notas técnicas importantes

## Patrón de migración endpoints `api_head.php` → envelope canónico

```php
// ANTES
include_once('api_head.php');
// ... lógica ...
jsonDieResult($data, 200);

// DESPUÉS
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// ... lógica sin cambios ...
apiOk($data);
```

## Notas de `api_middleware.php`

```php
// CRÍTICO: $db debe ser global antes de incluir db.php y functions.php
// porque functions.php llama getAllPlans() en scope global en línea 3
global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

// enc()/dec() no están en functions.php, están redefinidos en el middleware
// (en post-Phase UUID, son identity passthrough)
```

## Helper `legacy_db.php` (para endpoints MySQL→PG)

Reemplaza el bloque MySQL hardcoded por:

```php
require_once __DIR__ . '/lib/legacy_db.php';
```

Que internamente:
- Carga `cors.php`
- Conecta a PG via `includes/db.php` (creds desde `.env`)
- Carga `simple.config.php` + `functions.php`
- Define `enc/dec` defensivamente como identity

## Estructura del proyecto

```
system/
├── app/                    # POS — módulo de caja (PHP legacy)
├── panel/                  # Admin/ERP (PHP legacy)
│   ├── API/                # ~93 endpoints REST
│   │   ├── lib/
│   │   │   ├── response.php       # Envelope canónico ✅
│   │   │   ├── api_middleware.php # Middleware JWT ✅
│   │   │   └── legacy_db.php      # Helper conexión PG para endpoints legacy ✅
│   │   └── auth.php               # Login JWT ✅
│   ├── includes/
│   │   ├── simple.config.php      # Constantes globales
│   │   ├── jwt.php                # JWT HS256 puro PHP
│   │   ├── db.postgres.php        # Conexión PG (ADOdb postgres9)
│   │   ├── db.pdo.php             # Conexión PG (PDO drop-in)
│   │   └── ws_publish.php         # Publica eventos a Redis
│   └── standalone/
│       └── scripts/
│           └── ncm-ws.js          # Cliente WebSocket (drop-in Pusher) ✅
├── ws-server/              # Microservicio Node.js WebSocket ✅
├── database/
│   ├── migrations/postgres/
│   └── seeds/
├── docker-compose.yml      # PostgreSQL + Redis + pgAdmin + ws-server
└── context/                # Kit de contexto para Claude (este roadmap está acá)
```
