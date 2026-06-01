<!-- REGLA: Actualizar cuando se agregue un servicio nuevo, cambie la comunicación entre
     componentes, o se modifique un god node. NO actualizar por cambios internos a un módulo. -->

# 02 — Arquitectura

## Vista de 30 segundos

```
┌──────────────────────────────────────────────────────────────────────┐
│                             BROWSER                                   │
│  ┌──────────┐    ┌──────────┐    ┌──────────────────────┐            │
│  │  /app    │    │  /panel  │    │  standalone (KDS,CDS) │            │
│  └────┬─────┘    └────┬─────┘    └──────────┬───────────┘            │
└───────┼────────────────┼─────────────────────┼────────────────────────┘
        │ HTTP           │ HTTP                │ WebSocket
        ▼                ▼                     ▼
┌───────────────┐  ┌───────────────┐  ┌──────────────────┐
│  PHP /app     │  │  PHP /panel   │  │  ws-server       │
│  (BFF/action) │  │  (BFF/panel)  │  │  (Node.js:6001)  │
└───────┬───────┘  └───────┬───────┘  └────────┬─────────┘
        │ HTTP (BFF→API)   │ HTTP (BFF→API)     │
        └────────┬─────────┘                    │
                 ▼                              │
┌────────────────────────────────────┐          │
│  PHP /api  (API compartida :8000)  │          │
│  backend ÚNICO del sistema         │          │
│  (futuro: server dedicado)         │          │
│  /api/v1/* — superficie pública    │          │
└────────────────┬───────────────────┘          │
                 │                              │
                 ▼                              ▼
┌──────────────────────────────────────┐  ┌─────────┐
│         PostgreSQL 16                 │  │  Redis  │
│  (puntoDB — multi-tenant por         │  │  7      │
│   companyId en cada tabla)           │  │         │
└──────────────────────────────────────┘  └─────────┘
                                               ▲
                                               │ Pub/Sub
                                    ┌──────────┘
                                    │
                              PHP wsPublish()
                          (fsockopen → RESP raw)
```

## Flujo de datos principal

1. **Request HTTP (patrón BFF)** → Browser → BFF (/app o /panel) → API compartida (/api :8000) → Service → PostgreSQL → responde JSON
2. **Evento real-time** → PHP `wsPublish()` → Redis PUBLISH → ws-server → broadcast a clientes suscritos
3. **Facturación electrónica** → PHP → EFATech/TaxPro API → respuesta → guarda en BD

**Flujo BFF 3 capas (canónico desde 2026-05-28):**
```
Front (HTML+JS) → BFF (/app/bff/ o /panel/bff/) → /api/v1/* (API compartida) → Service → Postgres
                   reenvía cookie _jwt               apiAuthTenant()
```

## Admin realm — dos realms de autenticación (decisión 2026-05-28)

> Ver [adr/ADR-002-admin-realm-separado.md](context/adr/ADR-002-admin-realm-separado.md) para el razonamiento completo.

El sistema tiene **dos realms de autenticación criptográficamente aislados**:

| Dimensión | Realm POS (/app) | Realm tenant (panel) | Realm admin (/admin) |
|-----------|-----------------|---------------------|---------------------|
| Ruta de login | `/app/login.php` · `/app/API/auth.php` | `/panel/API/auth.php` | `/admin/login` |
| Cookie JWT | `_jwt` | `_jwt_panel` | `_jwt_admin` |
| Secret env | `JWT_SECRET` | `JWT_SECRET` | `ADMIN_JWT_SECRET` |
| Claim `iss` | `'pos-app'` | `'panel'` | `'admin'` |
| Claim `aud` | — | — | `"admin"` |
| Tabla de usuarios | `contact` (POS employees) | `contact` (tenant employees) | `admin_user` (plataforma) |
| Password scheme | sha256 + salt + HASH\_TIMES | sha256 + salt + HASH\_TIMES | bcrypt (`password_hash`) |
| `companyId` | Siempre presente | Siempre presente | Ausente |
| Accede a | POS (app/action.php + /api) | Panel de tenant | Cross-tenant (todas las companies) |

**Regla de aislamiento (no-negociable):** un token de un realm nunca valida en otro. Los tres realms están aislados por secret + cookie + claim `iss`. Para POS y panel, `JWT_SECRET` es COMPARTIDO — el claim `iss` es la barrera que previene la confusión de privilegios (commit 2de4231, 2026-05-31).

**Claim `iss` — valores canónicos (establecido commit 2de4231, 2026-05-31):**

| Valor | Emitido por | Validado en |
|-------|-------------|-------------|
| `'pos-app'` | `app/API/auth.php`, `app/API/refresh.php`, `app/login.php`, cron service tokens | `app/includes/jwt_middleware.php` |
| `'panel'` | `panel/includes/functions.php` (login tenant) | `panel/API/lib/api_middleware.php`, `panel/upload.php` |
| `'admin'` | `panel/API/lib/admin_auth.php` | `panel/API/lib/admin_auth.php` (suma al `aud='admin'`) |

Cada middleware compara `($payload['iss'] ?? '') !== '<realm>'` y rechaza con 401 si no coincide. Tokens sin `iss` (emitidos antes del fix) → 401 — pre-producción, forzar re-login es aceptable.

**refresh.php:** valida `iss === 'pos-app'` ANTES de re-emitir, cerrando el privilege-escalation por refresh (un token con `iss=panel` enviado a `refresh.php` no puede obtener un token POS).

### Estado del admin realm (2026-05-28)

- **F0 HECHA (commit 01a8929):** tabla `admin_user` + `bootstrap_seed.php` + vars `.env`. La tabla existe pero nada del runtime actual la usa.
- **F1 HECHA (commit 96f8b8f):** auth propia `/admin` — `v1/admin/login.php` (público, rate-limit) + `v1/admin/me.php` (gated) + `adminMiddleware()` + BFF `bff/admin/{login,me,logout}.php` + front estático standalone `admin/login.html` + `admin/home.html`. Cookie `_jwt_admin` HttpOnly, token no llega al browser como JSON. Aislamiento verificado E2E (token cruzado → 401 en ambas direcciones).
- **F2 HECHA (commit 89e7388):** CRUD de super-admins — stack 3 capas: `panel/lib/admin/AdminUserService.php` (list/get/create/update/setStatus; reglas: email único case-insensitive, password >=8, no desactivar el último admin activo ni a uno mismo) + `panel/API/v1/admin/users.php` (gateado por `adminMiddleware()`) + `panel/bff/admin/users.php` + `panel/admin/users.html` + `panel/admin/scripts/users.js` (standalone, todo con `esc()`). Router `/admin/users`. `home.html` linkea al CRUD. Verificado E2E (list/create/dup-email 422/pass-corto 422/update/setStatus/auto-desactivación 422/get single).
- **F3 = SIGUIENTE; F4–F6 pendientes:** ver `10-roadmap.md § Admin realm`.

### MASTER\_COMPANY\_ID — rol post-F0

`MASTER_COMPANY_ID` (env var) **deja de ser gate de IDENTIDAD** para los super-admins. Su rol futuro es scope de billing/datos de plataforma. El flag `SAAS_ADM` + el redirect de `@.php:11` (que hoy gatean como "super-admin = empresa MASTER") **siguen intactos hasta F4**, que los desacopla. No tocar esa lógica hasta entonces.

### Realm franchiser — NO es /admin

El franchiser (`panel/franchiser.php`, gateado por `isParent`) es un **realm tenant** que opera sobre `/` (el mismo panel), no sobre `/admin`. Un franquiciador es un tenant con acceso cross-tenant acotado a sus hijos (via `franchiser_to_tenant`). No mezclar con el admin realm de plataforma.

## Patrones arquitectónicos

| Patrón | Dónde |
|--------|-------|
| Monolito con API REST emergente | `/panel/API/*.php` (93 endpoints) |
| ~~Action dispatcher~~ → **Vaciado (2026-06-01)** | `/app/action.php` tenía ~43+ acciones vía param `l=`; post-Slice 36 solo queda `processData`. El patrón BFF→API→Service lo reemplazó concern-por-concern. |
| BFF 3 capas (Front→BFF→API→Service) | `/panel/` (completo) + **`/app/` en desacople progresivo** (slice 1: customerAddress ✅, 2026-05-28) |
| Pub/Sub bridge | PHP → Redis → Node.js WS → Browser |
| JSONB extensible | Columnas `config`, `data`, `meta` en tablas principales |
| UUID v7 como PK | Todas las tablas (via `ncmInsert()`) |
| Multi-tenant por filtro | `companyId` en WHERE de toda query |

## God nodes (más rompen si se tocan mal)

Derivados de `graphify-out/GRAPH_REPORT.md` (medido sobre 2555 nodos / 4058 edges).
Para detalle vivo: leer ese reporte antes de tocar estas funciones.

### Funciones críticas (medidas)

| Función | Edges | Dónde | Qué hace |
|---------|------:|-------|----------|
| `ncmExecute()` | 124 | `panel/includes/functions.php` + duplicado en `app/` | Ejecutor de queries con cache. Todo pasa por acá. |
| `make_xlsx_lib()` | 82 | exports | Generador de archivos XLSX |
| `validity()` | 80 | `functions.php` | Validador genérico de datos |
| `simple_html_dom_node` | 49 | vendor | Parser HTML |
| `iftn()` | 46 | `functions.php` | Helper if-then-null |
| `toUTF8()` | 40 | `functions.php` | Normalización de encoding |
| `DB` | 26 | clase global | Wrapper de ADOdb |
| `getROC()` | 23 | `functions.php` | Cálculo de ROC (retorno sobre capital) |

### Archivos críticos (por tamaño + responsabilidad)

| Archivo | Por qué es god |
|---------|---------------|
| `panel/includes/functions.php` (282KB) | Host de `ncmExecute()`, `validity()`, `iftn()`, `toUTF8()` |
| `app/includes/functions.php` | Duplicado parcial — cambios al panel suelen requerir sync acá |
| ~~`app/action.php` (143KB)~~ → **1685 líneas post-Slice 36 (2026-06-01)** | Dispatcher de ~43+ acciones del POS completamente vaciado. Ya NO es god node — queda solo el handler `processData` (~1622 líneas, fallback del strangler de ventas). Ver `10-roadmap.md § action.php estado post-Slice 36`. |
| `panel/API/lib/api_middleware.php` | Auth de los endpoints migrados del panel |
| `app/includes/jwt_middleware.php` | Auth de /app — también usada por el bootstrap de `/api` vía `chdir+require` (transitorio) |
| `api/bootstrap.php` | Bootstrap de la API compartida; `apiAuthTenant()` — JWT tenant (cookie `_jwt` \| Bearer \| POST, claim `cid`, `JWT_SECRET`) |
| `ws-server/index.js` | Único archivo del WS |

**Nota /app DB.php (2026-05-28):** `app/includes/lib/DB.php` divergió del panel y **no tiene `Insert_ID()`**. `ncmInsert`/`ncmUpdate` son fatales en /app. Ver `05-modulos-clave.md § Desacople /app` para el patrón de escritura correcto.

**God-helpers de `app/includes/functions.php` arreglados para PG durante slice 35 (2026-05-31):** patrón común: UUID sin comillas en SQL concat + columnas demoted a JSONB leídas sin `_flattenJsonb` + `$db->Prepare()` rompiendo math (§22.10.1). Todos afectaban también al legacy `processData`.

**`groupByPaymentMethod` (commit b3d164f, 2026-06-01) — fix PHP 8.5:** `abs()` ya no acepta string/null a partir de PHP 8.5 → `(float)($val ?? 0)` antes de `abs()`. Aplica a todos los callers que sumaban métodos de pago (voidSale era el más afectado: el `unset('extra')` antes de iterar hacía que el gift card `'extra'` nunca se restaurara; ahora se itera sobre los payments raw antes del groupBy). Ver detalles en `10-roadmap.md § voidTransaction`.

**Guardado de ventas — dos caminos vivos (LIVE desde commit 89b980e, 2026-05-31; actualizado commit dbf2866, 35a.8):** el guardado de ventas (antes 100% en `processData` de `app/action.php`) ahora tiene dos paths:

| Path | Cuándo | Quién persiste |
|------|--------|---------------|
| **SaleService** (`bff/sales → api/v1/sales.php → api/lib/Sales/SaleService.php`) | Ventas simples type 0/3 elegibles (cashsale/creditsale puro, sin parentId) **+ gift card (vender y pagar — 35c ✅) + sesiones agendadas (35d ✅) + storeCredit/points/inCredit (35e ✅) + recurrente (35f ✅) + factura electrónica PY (35b ✅ POST-COMMIT best-effort)** | **AUTORITATIVO** — cubre el 100% del tráfico real de ventas cashsale/creditsale (excepción: parentId, edge raro). EI se despacha post-commit best-effort (no bloquea la tx). |
| **Legacy processData** (`app/action.php?l=processData`) | 422 del SaleService (no elegible — solo parentId). RECHAZA type 0/3 simples con **409** | Sólo retiene: parentId (edge raro). EI ya migrada a SaleService (35b ✅). Ya NO es safety-net de ningún path de venta normal. El cron `cronCreateRecurringInvoice.php` envía re-submisión a action.php sin JWT → 401 (deuda pre-existente — no causada por 35f). |

El front rutea vía `ncmHttp.postSale()` (patrón try-fallback — ver §22.11 en `08-convenciones.md`). A partir de 35a.8: un 422 del SaleService → fallback legacy (payload no-simple, legacy lo posee); un 5xx/timeout → `callback(false)` SIN fallback legacy → orphans → reintenta SaleService. Idempotencia por `transactionUID` + UNIQUE constraint en ambos paths previene duplicados.

**Fuente única de elegibilidad (commit dbf2866, 35a.8; extendida en 35c.1+35c.2+35d+35e+35f+35b):** `saleIsSimplePathEligible($payload, $sale): ?string` en `app/includes/functions.php` es la función COMPARTIDA entre ambos tiers: devuelve `null` si el payload es elegible; devuelve un string-motivo si no lo es. `SaleInput::assertSimplePathEligible` (API, traduce a 422) y el guard de `processData` (legacy, genera 409) la usan ambos — sin duplicar la regla. Es un god-helper más de `app/includes/functions.php`. **35c.1**: `'giftcard'` salió de los payment types rechazados. **35c.2**: `giftcardId` en items salió del rechazo; items type=`'giftcard'` saltean el check de empty-itemId. **35d**: rechazo de `item.duration > 0` eliminado. **35e**: `'points'` y `'storeCredit'` salieron de los payment types rechazados; `'inCredit'` agregado a tipos-sin-itemId válidos (junto a `discount` y `giftcard`). **35f (commit d23ead1)**: `repeat=true` salió del rechazo. **35b (HITO FINAL — commit a246722)**: `electronicInvoicePY` salió del rechazo — era el ÚLTIMO rechazo de payload activo. **Solo quedan rechazados**: parentId (edge raro) e ítems sin itemId de tipo desconocido. Para ventas type 0/3 sin parentId la elegibilidad es siempre NULL (va por SaleService). El strangler type 0/3 está COMPLETAMENTE migrado.

**Riesgo aceptado (35a.8):** SaleService pasa a ser dependencia dura del path simple — ante caída sistémica, las ventas simples se ENCOLAN (cola de orphans) pero no completan hasta que se recupere el SaleService. Un bug del SaleService ya no queda oculto por el guardado legacy silencioso.

- **`manageStock` (commit 6ea1e5a, 35a.4)**: 100% roto en PG para items stockeables — abortaba la tx en la primera línea (UUID sin comillas → SQLSTATE 25P02). Ninguna venta de item con tracking descontaba stock. Fixes: UUID sin comillas (§22.5), `getValue('setting',...)` → constante `COMPANY_NAME`, `iftn($x,NULL)` → `?: null` (§22.8.2), `is_array($stock)` → `instanceof ArrayAccess`.
- **`manageCustomerLoyalty` (commit 1a8d539, 35a.5)**: `$db->Prepare()` qstr-quoteaba el monto numérico (8000 → '8000') → comparación con `loyaltyMin` siempre falsa → ningún cliente acumulaba puntos. Fix: amount parametrizado con `?`; SELECT * para columnas `loyaltyMin`/`loyaltyValue` demoted a JSONB (§22.10.1).
- **`getContactData` (commit a52ecf6, 35a.6)**: UUID sin comillas en el WHERE concatenado → devolvía FALSE para TODOS los clientes, rompiendo notificaciones post-venta, modales de cliente y reportes de clientes en el legacy. Fix: UUID correctamente quoted en el WHERE. Ver §22.10.2 para el patrón best-effort que rodea este helper.

**Cross-coupling observado**: muchas funciones de `app/includes/functions.php` llaman
a funciones de `panel/includes/functions.php`. No son módulos independientes.

## API compartida (/api) — módulo top-level (decisión 2026-05-28)

> Ver [ADR-003-api-compartida-top-level.md](context/adr/ADR-003-api-compartida-top-level.md) si existe, o leer el commit d75dd0b.

**El `/api` es el backend ÚNICO del sistema.** /panel y /app son clientes que lo consumen.
La API está destinada a moverse a un server dedicado; los BFFs apuntarán a esa URL remota.

| Dimensión | Detalle |
|-----------|---------|
| Ubicación | `/api/` — hermano de `/panel` y `/app` |
| Dev server | `php -S localhost:8000 api/router.php` (port :8000) |
| Env var cliente | `PUNTO_API_BASE` (BFFs apuntan ahí; dev fallback `http://localhost:8000`) |
| Superficie pública | Solo `/v1/*` endpoints; `bootstrap.php`, `lib/`, `services/` NO son web-accesibles (anti-traversal vía realpath confinado a `/api/v1`) |
| Auth | `apiAuthTenant()` en `api/bootstrap.php` — JWT de tenant: cookie `_jwt` \| `Authorization: Bearer` \| POST `_jwt`; secret `JWT_SECRET`; claim `cid`. Mismo secret/claims que /panel y /app ya validan → una API autentica ambos clientes. |
| Envelope | `apiOk()` / `apiError()` — `api/lib/response.php` (canónico) |
| Servicios | Dos familias coexisten (ver `10-roadmap.md § Servicios`): (1) **legacy** `api/lib/services/*Service.php` — 18 servicios sin namespace, PHP legacy, sin DTOs; (2) **modernos** `api/lib/<Module>/<Module>Service.php` — namespace `Punto\Api\<Module>`, `final class`, `readonly`, DTOs de entrada/salida, enums backed, excepciones custom, DI explícita (convención §22.9, establecida 2026-05-30). El primer módulo moderno es `api/lib/Sales/SaleService.php` (`Punto\Api\Sales`) + `api/lib/Context/TenantContext.php` (`Punto\Api\Context`). El autoloader PSR-4 mínimo (~15 líneas, `spl_autoload_register`) en `api/bootstrap.php` mapea `Punto\Api\Foo\Bar` → `api/lib/Foo/Bar.php`. Código nuevo siempre va en el modelo moderno; los 18 legacy se modernizan al tocarse por razón funcional. |
| Endpoints | `api/v1/{customer_address,tables,schedule,customer_note,orders,register,transactions,customers,…}.php` |
| Clientes actuales | `/app/bff/*` (vía `app/bff/lib/api_client.php` que reenvía cookie `_jwt`) |
| Nota Alpine /app (Slice 33 reescrito, commit 3d62191; front unificado en Tier 3, commit e97aed7) | `api/v1/customers.php` + `app/bff/customers.php` sirven lecturas que el front renderiza client-side con **Alpine.js** (no Mustache). `GET ?resource=records` devuelve datos estructurados; el componente Alpine `customerRecord` en `app/scripts/app.js` (única fuente — reemplazó `globalv2.js`/`debug.js` en 2026-05-30) clona el `<template id="customerRecordTpl">`, fija atributos y llama `Alpine.initTree(el)`. Alpine 3.14.1 está vendoreado en `/app` (offline). Este es el patrón canónico para handlers HTML server-rendered en /app: **API devuelve datos → front renderiza con Alpine**. Ver §24 en `08-convenciones.md`. Mustache sigue cargado para los ~22 templates legacy restantes (deprecación incremental). |

**Deuda transitoria (documentada):** `api/bootstrap.php` actualmente hace `chdir(/app)` y reusa los includes de /app (`db/functions/jwt_middleware/head.php/data.php`) vía rutas absolutas. La consolidación de un `/api/includes` canónico (independiente de /panel y /app) es la migración gradual pendiente antes de que /api pueda moverse a su propio server. También: `panel/API/*` (~93 endpoints) migra gradualmente hacia /api.

### Patrón "API granular + BFF compone" (decisión arquitectónica, commit c4edef9, 2026-05-31)

**Dirección tomada**: la API expone **recursos granulares reusables** (un concepto de dominio por endpoint); el BFF los **compone en paralelo** (via `bffApiGetMulti()` + curl_multi) para armar el view-model de la pantalla. La API **nunca arma endpoints con la forma de una pantalla**.

| Rol | Responsabilidad |
|-----|----------------|
| API (`/api/v1/*`) | Exponer recursos granulares — `?resource=profile`, `?resource=debt`, `?resource=giftcards`, etc. Cada uno reusable por cualquier cliente. |
| BFF (`/app/bff/` o `/panel/bff/`) | Componer N recursos en paralelo con `bffApiGetMulti()` y mergear en el shape que la pantalla necesita. |

**Plomería**: `bffApiGetMulti(array $endpoints): array` en `app/bff/lib/api_client.php` (curl_multi, wall-clock = el más lento). `bffDecodeEnvelope(string $raw): mixed` (reutilizable).

**Bootstrap común de los BFFs de /app — `app/bff/lib/bff_init.php` (commit 9f30891, 2026-06-01):**
Los 19 BFFs de `/app/bff/` comparten un bloque de boilerplate (include api_client, auth guard, decode `?l=`, setear `$action`) que fue extraído a `bff_init.php`. Cada BFF hace `require_once __DIR__ . '/lib/bff_init.php'` en lugar de repetir las 5-6 líneas. Resultado: -104 líneas netas, lint 19/19 OK, E2E OK.

**Dos roles de los BFFs de /app (decisión P1.5, commit 9f30891, 2026-06-01):**

| Rol | Qué hace | Cuántos |
|-----|----------|---------|
| **Traductor de protocolo** | Decodifica el sobre `?l=` legacy, mapea acciones a verbos REST + resource params, shapea datos. Cada BFF entiende su dominio. | 16 de 19 |
| **Compositor multi-fuente** (§22.12) | Además de traducir, pide N recursos del API EN PARALELO (`bffApiGetMulti`) y ensambla el view-model. | 3: `customers`, `drawer`, `items` |

Los BFFs "pass-through" NO son redundantes — son traductores de protocolo con lógica real de dominio. La estructura por dominio (un archivo por concern) se mantiene intencionalmente: un router único habría creado un switch de ~400 líneas menos debuggable. Ver §22.13 en `08-convenciones.md` para la receta completa.

**Trade-off medido**: composed 95ms vs composite legacy 37ms (~2.5×). Bottleneck = bootstrap por call (`chdir + head.php + data.php + JWT`), no el número de queries. Mitigación: `/api/includes` canónico (deuda pendiente — lo vuelve barato).

**Restricción**: patrón read-only-safe. NO usar donde una escritura o invariante cross-recurso dependa de un único snapshot de DB (N calls = N snapshots independientes).

**Pilots verificados**:
- **Pilot 1** `customerInfo` — 5 recursos GET en paralelo compuestos en el BFF; output byte-idéntico al endpoint fat `getInfo()` legacy. Dataset informativo → degradación graceful aceptable. `getInfo()` + `?resource=info` quedan como composite legacy/backward-compat.
- **Pilot 2** `getSummary` (cierre de caja, commit 8aff931) — 4 recursos granulares (`open|expenses|income|salesByPayment`) en `api/v1/drawer.php`; BFF `app/bff/drawer.php` compone en paralelo y aplica `drawerComposeSummary()`. Dataset financiero → **FAIL-CLOSED** (cualquier hijo que falla → error explícito, no cero silencioso). Output byte-idéntico al composite legacy. `composeSummary()` extraída a función pura (sin DB) en DrawerService; `getSummary()` queda como backward-compat. Ver §22.12.1 en `08-convenciones.md` para la distinción fail-closed vs degradación graceful, y §22.12.2 para la deuda de fórmula duplicada.
- **Pilot 3** `items/getInfo` (commit 2bca565) — 2 recursos granulares (`core|inventory`) en `api/v1/items.php`; BFF `app/bff/items.php` pide ambos EN PARALELO (`bffApiGetMulti`) y mergea. `core` = dependencia dura (lleva el 404); `inventory` = informativo → degradación graceful (igual que `customerInfo`). Ensamblaje PURO — sin rollup ni fórmulas duplicadas (sin deuda §22.12.2). Output byte-idéntico al composite legacy (ítem con tracking, sin tracking, 404). `getInfo()` queda como backward-compat. Segundo caso ensamblaje-puro-graceful junto a `customerInfo`; contrasta con el fail-closed financiero de `drawer`.

Los endpoints fat actuales (`getInfo`, listas de orders/transactions) son **deuda a refactorizar** al patrón granular cuando se toquen.

Ver §22.12 en `08-convenciones.md` para la receta completa y los casos de uso válidos/inválidos.

## Comunicación entre módulos

| De → A | Mecanismo | Ejemplo |
|--------|-----------|---------|
| Browser → PHP | HTTP (fetch/AJAX) | Login, CRUD, queries |
| BFF (/app o /panel) → /api | HTTP curl (reenvía cookie `_jwt`) | CRUD de addresses, mesas, agendamientos |
| PHP → Browser (real-time) | Redis Pub/Sub → ws-server → WebSocket | Orden nueva en KDS |
| PHP → API externa | HTTP client (curl) | Facturación electrónica, SMS |
| App ↔ Panel | Comparten BD directamente | Misma PostgreSQL, mismo schema |

## Decisiones arquitectónicas vigentes

- **No microservicios** (excepto ws-server) — el monolito funciona y se moderniza in-place
- **No ORM moderno** — ADOdb es legacy pero funcional; las queries son explícitas
- **API compartida en `/api` top-level (2026-05-28)** — hermano de /panel y /app; destinada a correr en un server dedicado que /panel y /app consuman remotamente. Los endpoints nuevos del desacople van en `/api/v1/` + `/api/lib/services/`. `panel/API/*` (~93 endpoints) migra gradualmente a /api.
- **Agente IA como microservicio Python separado** — no toca el monolito PHP

## Arquitectura objetivo: BFF de 3 niveles (canónico — decisión 2026-05-26, refinada 2026-05-26)

> **Esta es la estructura objetivo de TODA modernización de módulos, en TODO el sistema.**
> No cambiamos de stack (PHP + jQuery + Bootstrap 3); cambia *cómo está
> organizado* y *quién habla con quién*.

> **🔑 REGLA RAÍZ (no-negociable, refinamiento 2026-05-26): PHP NUNCA sirve HTML.**
> El front son archivos **`.html` estáticos** (HTML + JS, cero PHP). PHP existe
> solo en dos capas: **BFF** (intermedia, devuelve JSON) y **API** (Postgres,
> devuelve JSON). El auth y el chrome (menú/título/constantes) los resuelve el
> **JS del front** pidiéndoselos al BFF — nunca un `include` PHP que renderiza layout.

### El modelo

```
   front.html (HTML+JS)   →        bff.php (PHP)        →        api.php (PHP)     →   BD
   estático, cero PHP              intermedia, SIN BD            motor ERP, única       
   auth+chrome client-side        solo llama a la API           capa con Postgres      

┌─────────────────────────┐   ┌──────────────────────────┐   ┌─────────────────────────┐
│  FRONT — .html estático │   │  BFF — App Punto (PHP)    │   │  API — motor ERP (PHP)  │
│  <x>.html + <x>.js      │   │  bff/<x>.php  (solo JSON) │   │  API/v1/<x>.php         │
│  pinta lo que da el BFF │──▶│  push, websockets, cálcu- │──▶│  thin: auth + CRUD +    │
│  auth + chrome por JS   │   │  los finales, análisis    │   │  datos casi RAW         │
│  (bootstrap del BFF)    │   │  cruzados, formateo       │   │       │ delega en        │
│  NO calcula reglas      │   │  oculta internals/valida- │   │       ▼                 │
│  NINGÚN tag PHP         │   │  ciones del front         │   │  lib/<x>/ (dominio)     │
│                         │   │  NO toca BD ni lib/       │   │  Repository + Service   │
│                         │   │  NO sirve HTML            │   │       │ SQL              │
└─────────────────────────┘   └──────────────────────────┘   │       ▼ PostgreSQL      │
                                                              └─────────────────────────┘
```

### Por qué este modelo

- **La API es un motor ERP genérico y reusable.** Sirve datos casi raw (seguridad +
  CRUD, poco procesamiento) y debe poder alimentar a **otras apps nuestras** sobre el
  mismo motor: ecommerce, billetera digital, lo que se construya encima de Punto. Si le
  metemos lógica específica de la App Punto, deja de ser reusable.
- **El BFF es específico de la App Punto.** Toma lo que da la API y lo procesa para las
  necesidades de la app: push, websockets, cálculos finales, análisis cruzados, **formateo
  de valores**. Oculta funciones/procesos/validaciones internas del front.
- **El front solo muestra.** Cero reglas de negocio en JS.

### 🔑 REGLA RAÍZ 2 — PHP nunca genera front visual (refinada 2026-05-26)

> **Ni la API ni el BFF generan HTML, CSS ni JS. El PHP solo sirve datos en JSON.**
> Todo lo visual (markup, tablas, tarjetas, spans, clases, iconos) lo **arma el front**
> (HTML + JS) a partir del JSON. Esto complementa la REGLA RAÍZ ("PHP nunca sirve HTML"):
> no solo no sirve la *página*, tampoco emite *fragmentos* de markup dentro de un JSON.

**División de responsabilidades (canónica — refinada 2026-05-26):**

> **Todo lo PRESENTACIONAL vive en el front; el PHP (API+BFF) solo sirve DATOS crudos + cálculos.**
> "Presentacional" = formateo de números (`1395000`→`"1.395.000,00"`), de fechas
> (`"2026-05-26"`→`"26 May"`), de `%`, textos de display, y el markup.

| Capa | Hace | NO hace |
|------|------|---------|
| **API** | auth + CRUD + datasets casi raw (números crudos), multi-tenant | formateo, lógica de App Punto, markup |
| **BFF** | cálculos extra (netSales, totales, deltas), cruce de datos, gateway a la API → devuelve **JSON con datos CRUDOS**: números (`1395000`), fechas ISO (`"2026-05-26"`), comparaciones como datos (`{dir,pct,positive,prev:<número>}`) | **NINGÚN** formateo de display, **NINGÚN** HTML/CSS/JS. No arma `"1.000,00"`, ni `"26 May"`, ni tablas, ni spans. |
| **FRONT** (`.html` + JS) | arma **TODO** el visual: formatea números (`formatNumber` con currency/decimal/thousand del bootstrap), fechas, `%`, textos; construye el markup | cálculos de negocio (vienen crudos del BFF) |

**Dos anti-patrones a corregir al migrar un reporte:**
1. **BFF que arma HTML** (lo que hacen `a_report_orders.php` y todos los legacy): devuelve
   `{table:"<table>…"}`. MAL — presentación en el PHP. El BFF devuelve datos, el front arma el `<tr>`.
2. **BFF que pre-formatea números/fechas a strings de display** (`"1.395.000,00"`, `"26 May"`).
   MAL — el formateo es presentación, va en el front. El BFF manda el número/fecha **crudo**.

Para tablas con sort/sumas client-side (DataTables): el BFF manda el valor crudo y el front
arma tanto el display (`formatNumber`) como el `data-order` (crudo) en el `<td>`.

**Supersede** la nota previa que decía "el BFF pre-formatea los valores": era incorrecta.
El BFF NO formatea nada de display; solo datos crudos + cálculos. El front formatea TODO.

### ⚠️ Constraint que define el diseño: App y API en servidores separados

Eventualmente la **App (front + BFF)** y la **API (+ dominio + BD)** viven en
**servidores distintos**. De ahí la regla no-negociable:

> **El BFF NUNCA toca la BD ni `lib/` directamente. Obtiene TODA su data de la API.**

Consecuencias:
- La API debe exponer los **datasets crudos** que el BFF necesita para componer. Ej.: la
  columna "última operación" del listado de clientes **no** se calcula en la API (la
  acoplaría a Punto) — la API expone transacciones crudas y el **BFF las cruza**.
- El cálculo/cross-analysis/formateo vive en el **BFF**, no en la API ni en el front.
- Cachear en el BFF los paths calientes (para no ser chatty contra la API remota).

### Patrón de ESCRITURA (front → BFF → API) — establecido 2026-05-26 (a_report_satisfaction)

Las mutaciones siguen el mismo flujo de 3 capas que las lecturas:

```
front: POST /bff/reports/<x>.php  (action=delete&id=…)   ← ncmHelpers.load httpType:'POST'
  → BFF: bffApiPost('v1/reports/<x>.php', [...])  (reenvía el JWT cookie a la API)
  → API: if method POST → valida + Service->mutate(...)  scopeado por COMPANY_ID
```

Reglas del write:
- **El API valida y scopea por `companyId` del JWT** (ej. `DELETE … WHERE id = ? AND companyId = ?`).
  El legacy de satisfaction borraba solo por id (IDOR) — al migrar SIEMPRE agregar el scope de tenant.
- **`$db->Execute` (no `ncmExecute`) para DELETE/UPDATE**: ncmExecute devuelve `false` para
  sentencias sin filas de retorno aunque ejecuten OK.
- **Validar el id como UUID** antes de mutar; los params siempre bindeados.
- **Permiso**: `allowUser('sales','delete')` NO se puede usar en el API v1 (usa `ROLE_ID`, que
  `apiMiddleware` no define — solo `PANEL_AUTHED_ROLE`). Gate provisional: bloquear el rol
  read-only (7). **Follow-up**: wirear `ROLE_ID` en `apiMiddleware` → habilita `allowUser` en
  TODOS los endpoints de escritura.
- **`bffApiPost`** vive en `panel/bff/lib/api_client.php` (análogo a `bffApiGet`).

### Estado del piloto (commit 051dd59, 2026-05-26)

El modelo de 3 niveles tiene un **piloto completo verificado E2E** en `a_report_summary`:

- **Modo de integración actual: fragmento-en-shell.** El `.html` se inyecta en `#bodyContent` via hash-nav (`@.php`); el shell ya provee head/menú/jQuery/Chart.js/BS3/globals. El modo "standalone 100% autónomo" (con auth+chrome propios y redirect a `/login` ante 401) queda DIFERIDO para cuando el front sea autosuficiente.
- **Routing dev**: `panel/router.php` mapea `/a_report_summary` → sirve `panel/reports/summary.html` estático. En prod replicar con `RewriteRule` en `.htaccess`.
- **Front nunca pega a `/API/v1`**: todo pasa por el BFF (`/bff/reports/summary.php`).

### Transporte y auth (confirmado 2026-05-26)

- **Front → BFF:** el JS del `.html` hace `fetch` al BFF (`/bff/reports/summary.php?action=…`).
  El front nunca pega a `/API/v1` ni ve `api_key`.
- **BFF → API:** cliente PHP HTTP fino en el BFF (`$api->get('/v1/reports/sales', …)`) →
  `localhost/API` hoy, URL del server API mañana. **Boundary HTTP real desde el día 1**
  (ya NO se difiere); el split a servidores separados = cambiar base URL, sin tocar lógica.
  El BFF reenvía el JWT del usuario a la API; service token para crons sin usuario.
- **Auth del front (client-side):** el JS lleva el JWT (cookie `_jwt_panel`); si el BFF
  responde 401, el JS redirige a `/login`. No hay gate PHP que renderice la página.
- **Chrome (client-side):** menú, título, `CURRENCY`, `TAX_NAME`, permisos vienen de un
  `GET /bff/bootstrap` que el JS pide al cargar; el JS pinta el layout. El `.html` trae el
  markup estático del layout; el JS solo hidrata los valores dinámicos.

### Dónde vive cada cosa

```
FRONT   panel/reports/<x>.html    (reportes — estático, cero PHP)    +  panel/scripts/<x>.js
        panel/views/<x>.html      (módulos CRUD — estático, cero PHP) +  panel/scripts/<x>.js
        app/scripts/<x>.js        (POS — habla solo con el BFF de /app)
BFF     panel/bff/<area>/<x>.php  → JWT + llama a la API + JSON   (NO BD, NO HTML)
        app/bff/<x>.php           → decodifica ?l=, reenvía _jwt a /api, traduce al shape legacy
API     panel/API/v1/<area>/<x>.php  → (legacy panel; migra gradualmente a /api)
        api/v1/<x>.php              → apiAuthTenant() + apiOk()/apiError() — NUEVO home canónico
DOMINIO panel/lib/<x>/{Repository,Service}.php  (SQL + reglas panel)
        api/lib/services/<x>Service.php         (SQL + reglas — servicios legacy /app, sin namespace)
        api/lib/<Module>/<Module>Service.php    (SQL + reglas — servicios modernos §22.9: namespace Punto\Api\<Module>, DTOs, enums)
```

**REGLA (desde 2026-05-28):** Los nuevos endpoints del desacople van en `/api/v1/` + `/api/lib/services/`, NO en `/app/API/` (que quedó vacío). Los BFFs de /app consumen `/api`. El `panel/API/` migra gradualmente a `/api`.

**Router (`panel/router.php`) — tres mapas**:
- `$bffStaticReports` — reportes 100% migrados (front sirve siempre)
- `$bffPartialReports` — reportes con acciones legacy aún en PHP (sirve front cuando `empty($_GET['action'])`)
- `$bffPartialModules` — módulos CRUD no-reporte (outlets ✅ — pilot 2026-05-27; mismo patrón que `$bffPartialReports`, fronts en `panel/views/`)

### Envelope canónico (API)

```jsonc
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }   // éxito
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } } // error
```
Helpers en `panel/API/lib/response.php`. El BFF desempaqueta `data` o maneja el error.

### Orden de fases por módulo

| Fase | Qué hace | Riesgo |
|------|----------|--------|
| **1** | Extraer dominio a `lib/<x>/` (Repository + Service: SQL + reglas) | bajo |
| **2** | API REST `/API/v1/<x>` **raw** sobre el dominio (para apps externas) | bajo |
| **3** | BFF: `a_<x>.php?action=…&format=json` consume la API (vía cliente HTTP) + procesa para la app | medio |
| **4** | Front: `render.js`/form pintan lo del BFF; el front habla **solo** con el BFF | medio |
| **5** | Decommission de los handlers de UI legacy ya migrados | medio |

### Desvío corregido (2026-05-26)

El modelo previo era **front → API directo** (4 capas en 2 niveles, sin BFF). Eso hizo
que: (a) el editform v2 de contacts pegue directo a `/API/v1/contacts` desde JS, y (b)
`API/v1/contacts` devuelva data ya formateada para la app (`presentRow`), acoplando la API
genérica a Punto. **Items y Contacts** quedaron con ese desvío y necesitan que se les
inserte el BFF: el front pasa a hablar con el BFF, y `/API/v1/*` se adelgaza a raw.

### Cuándo se reconsidera el stack del front

Solo al reemplazar **DataTables** y **Bootstrap 3** — proyecto aparte. Hasta entonces
jQuery se queda en la capa de UI.

## Estrategia de modernización del monolito (decisión 2026-05-24)

El panel son **48 módulos / ~45K líneas** + `app/action.php` (POS, 3.6K).
Modernizar todo de punta a punta (como se hizo con Items) tomaría meses.
Decisión estratégica para salir del monolito **rápido**:

**1. Backend primero, en TODOS los módulos.** El desacople de mayor valor
es sacar SQL + lógica de negocio del HTML hacia `lib/<x>/{Repository,Service}`
+ `API/v1/<x>.php`. Eso solo ya saca el módulo del monolito y deja base
para cualquier frontend. Es **mecánico y replicable** (ver el molde abajo).

**2. Frontend = `.html` estático (HTML + JS), CERO PHP.** ⚠️ *Superseded 2026-05-26:*
el supuesto previo era "vista PHP pura por defecto". **Ya no.** PHP nunca sirve HTML
(ver REGLA RAÍZ arriba). El front es un `.html` estático que el JS hidrata con datos
del BFF; auth y chrome también client-side. El backend-first sigue siendo válido como
*orden* (primero API+BFF, luego el front estático), pero el destino del front NO es PHP.

**3. Frontend reactivo (Alpine.js) solo donde la UX lo amerita.** Para los
CRUD/POS donde la interactividad importa, usar **Alpine.js** (no Mustache):
reactividad declarativa en el HTML (`x-data`/`x-model`/`x-for`/`x-if`), sin
build, convive con jQuery/BS3, ~15KB. Elimina el view-model manual que hace
Mustache verboso y bug-prone. **Items queda en Mustache** (ya funciona, no
se reescribe); lo nuevo va en Alpine. (Aplica dentro del `.html` estático — Alpine
es JS puro, no rompe la regla de cero-PHP.)

**Priorización por tipo de módulo:**

| Tipo | Módulos | Acción | Esfuerzo |
|------|---------|--------|----------|
| Reportes (read-only) | 5 (~13K líneas) | backend→API + listado data-driven; sin tocar forms | bajo |
| CRUD pesado | items✓, contacts✓ (backend + listado data-driven 3 roles + editform v2 customer — 2026-05-25), purchase | backend Services+API; frontend Alpine si UX lo pide | medio |
| Config/raros | settings, modules, … | dejar legacy; solo backend si se tocan | diferido |

> **Contacts — pendientes post-v2 (2026-05-25)**: (a) editform v2 para roles user/supplier (hoy usan form legacy); (b) custom records (solo en `a_contacts.php` legacy); (c) CSV export (lee columnas ya en JSONB); (d) listado customer muestra note/address/city vacíos (loop generalTable no aplana JSONB — pre-existente).

### El molde por módulo (alineado al BFF de 3 niveles)

> Reemplaza al molde viejo (front → API directo). El cableado canónico es el de
> la sección **"Arquitectura objetivo: BFF de 3 niveles"** arriba.

```
DOMINIO  lib/<modulo>/{<Modulo>Repository.php, <Modulo>Service.php}   SQL + reglas (vive con la API)
API      API/v1/<area>/<modulo>.php   REST raw (apiMiddleware + apiOk/apiError) — reusable por apps externas
BFF      bff/<area>/<modulo>.php?action=…   consume la API (cliente HTTP) + procesa para la app — JSON, NO HTML
FRONT    reports/<modulo>.html (estático) + scripts/<modulo>.js   pinta lo del BFF; habla SOLO con el BFF
```

Reglas: el **front es `.html` estático** (cero PHP) y **nunca pega a `/API/v1`** (pega al BFF).
El **BFF nunca toca BD/`lib/` ni sirve HTML** (pide a la API, devuelve JSON). La **API no formatea
para Punto** (devuelve raw; el BFF compone/cruza/formatea). **PHP nunca sirve HTML.**

Muchos módulos ya tienen endpoints sueltos en `API/*.php` (73 en total,
ej. `get_customers.php`, `edit_customer.php`) — se consolidan bajo el
Service + `API/v1/` canónico (raw).
