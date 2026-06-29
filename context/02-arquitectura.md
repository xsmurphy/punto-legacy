<!-- REGLA: Actualizar cuando se agregue un servicio nuevo, cambie la comunicación entre
     componentes, o se modifique un god node. NO actualizar por cambios internos a un módulo. -->

# 02 — Arquitectura

## REESTRUCTURA TOTAL — 2026-06-29

> **Sesión estructural máxima.** `/app`, `/panel`, `/screens`, `api/core` eliminados. `panel-next` renombrado a `frontend`. La estructura del repo es ahora definitiva.
> Ver `context/21-auth-rewrite.md` (auth) y `context/22-legacy-cleanup.md` (limpieza).

**Estructura de directorios final (top-level):**
```
api/          → backend PHP único (toda la lógica de negocio)
frontend/     → Next.js: panel + POS + screen + admin + auth (TODO el front)
ws-server/    → Node.js WebSocket
database/     → migraciones SQL
context/      → docs de arquitectura
docs/         → docs públicos
scripts/      → utilidades de dev
```
NO existen más `/app`, `/panel`, `/screens`, `api/core`.

**Modelo obligatorio:** FRONT+BFF (`frontend/`, independiente, sin compartir archivos con API) → HTTP → `api/` (backend PHP unificado) → BD. El browser NUNCA toca el PHP directamente.

**Auth:** sesiones opacas stateful (`auth_session` tabla). Token `pt_`+random, sha256 en BD. `realm` = columna (`panel`/`pos-app`/`admin`/`screen`). Funciones en `api/includes/auth_session.php`. No hay JWT en el sistema.

---

## Vista de 30 segundos

```
┌──────────────────────────────────────────────────────────────────────┐
│                             BROWSER                                   │
│              app.punto.la  (path-based routing)                       │
│  /panel  /pos  /admin  /checkout  /connect  /screen                   │
└───────────────────────────────┬──────────────────────────────────────┘
                                │ HTTP / WebSocket
                                ▼
┌───────────────────────────────────────────────┐  ┌──────────────────┐
│  frontend/  (Next.js — BFF same-origin)       │  │  ws-server       │
│  app/(panel)   → panel de tenant              │  │  (Node.js:6001)  │
│  app/(pos)/pos → POS                          │  └────────┬─────────┘
│  app/(admin)   → admin greenfield             │           │
│  app/(screen)  → screen/display               │           │
└───────────────────────┬───────────────────────┘           │
                        │ HTTP server-side (API_URL, interna)│
                        ▼                                   │
┌────────────────────────────────────┐                      │
│  api/  (PHP — backend único)       │                      │
│  api/v1/*  — endpoints REST        │                      │
│  api/lib/App/*  — servicios        │                      │
│  api/lib/Admin/* — realm admin     │                      │
│  api/includes/auth_session.php     │                      │
└────────────────┬───────────────────┘                      │
                 │                                          │
                 ▼                                          ▼
┌──────────────────────────────────────┐  ┌─────────┐
│         PostgreSQL 16                 │  │  Redis  │
│  (puntoDB — multi-tenant por         │  │  7      │
│   companyId en cada tabla)           │  │         │
└──────────────────────────────────────┘  └─────────┘
                                               ▲
                                               │ Pub/Sub + Sessions
                                    ┌──────────┘
                              PHP wsPublish() / auth_session store
                          (fsockopen → RESP raw / session.save_handler=redis)
```

## Deploy (Coolify) — topología objetivo post-cutover

**Un dominio público `app.punto.la`** = container `frontend` (Next.js, path-based). El PHP (`api/`) es la API INTERNA — el BFF la llama server-side por `API_URL`; el browser nunca toca el PHP.

```
app.punto.la    → container frontend (Next.js)
ws.punto.la     → ws-server (contenedor Node.js separado)
API_URL (interna) → api/ PHP (no expuesto públicamente)
```

> **Pendiente de cutover:** actualizar Coolify build subdir `panel-next`→`frontend`; confirmar `app.punto.la` apunta al container frontend; PHP configurado como API interna por `API_URL`. Cutover = re-login masivo (sesiones opacas, no hay JWT que migrar). `/admin` en prod nunca fue smoke-testeado.

**PHP sessions en Redis (commit 5ea3a2d)**: el `docker-entrypoint.sh` raíz parsea `REDIS_URL` al arrancar y configura `session.save_handler=redis` + `session.save_path=tcp://host:port?auth=...` en el php.ini runtime. Sin esto las sesiones viven en `/tmp/sess_*` y se pierden en cada deploy (el container se recrea). Con Redis, las sesiones persisten entre deploys.

**Dev local**: sigue funcionando con 4 servidores PHP independientes por puerto (panel:8001, app:8002, api:8000). `router.php` raíz es solo para prod single-container.

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
| TTL env var | `JWT_TTL` — **0 = eterno (recomendado POS)** o cualquier valor en segundos (device pairing) | `PANEL_JWT_TTL` — **86400 = 24h** (sesión real del tenant). Si ausente, fallback a `JWT_TTL`. | `ADMIN_JWT_TTL` — **8h** (sesión real del super-admin) |
| Claim `iss` | `'pos-app'` | `'panel'` | `'admin'` |
| Claim `aud` | — | — | `"admin"` |
| Tabla de usuarios | `contact` (POS employees) | `contact` (tenant employees) | `admin_user` (plataforma) |
| Password scheme | sha256 + salt + HASH\_TIMES | sha256 + salt + HASH\_TIMES | bcrypt (`password_hash`) |
| `companyId` | Siempre presente | Siempre presente | Ausente |
| Accede a | POS (app/action.php + /api) | Panel de tenant | Cross-tenant (todas las companies) |

**Modelo "device pairing" de /app vs sesiones de cajero (commit 7e1b26f + a3fefb4, 2026-06-06):** el realm POS tiene DOS mecanismos de auth superpuestos que NO se deben confundir:

| Nivel | Quién | Mecanismo | Persistencia |
|-------|-------|-----------|-------------|
| **Activación de caja** | Admin | JWT (`_jwt`, `JWT_TTL=10y`) + claim `did` (deviceId UUID) | Permanente mientras el secret no rote. Análogo a Apple TV pareado a una cuenta. |
| **Acceso del cajero** | Cajero | PIN de 4 dígitos → `ncmAuth.activeUser` + `lockPad` en JS (legacy) / lock screen React en frontend | Transitorio por turno — el JWT del dispositivo NO se toca. |

El JWT de /app representa "este dispositivo está pareado a esta empresa/outlet". No es una sesión de usuario. El TTL largo (10 años) está justificado porque la revocación per-device ya existe vía la **tabla `device`** (migración 11, commit a3fefb4): el middleware valida `device.status` si el JWT trae claim `did`, con cache de archivo 60s en `sys_get_temp_dir/punto_device_status/{deviceId}_{companyId}.dat`. Para revocar un dispositivo individual: `UPDATE device SET status=0 WHERE deviceId=? AND companyId=?` y opcionalmente llamar `jwtInvalidateDeviceCache()`. Tokens sin `did` (legacy anterior al feat) siguen pasando (backwards compat).

### Modelo de doble sesión del POS React (ACTUALIZADO 2026-06-27)

El POS React (en `frontend/app/(pos)/pos`) maneja **DOS sesiones independientes**:

| Sesión | Mecanismo | Realm | TTL | Quién la cierra |
|--------|-----------|-------|-----|----------------|
| **Operador** | Cookie `_jwt_panel` | `panel` | 24h | "Cerrar sesión" del menú user del sidebar. NO cierra la sesión del POS. |
| **Dispositivo POS** | `Authorization: Bearer` + `localStorage['punto.device.token']` | `pos-app` + claim `did` | 10 años | ÚNICA forma: Ajustes → "Eliminar dispositivo del comercio" → revocación desde panel. |

**IMPORTANTE — cambio 2026-06-27**: el token del device POS migró de cookie HttpOnly `_jwt` a **Bearer + localStorage**. Razón: las cookies HttpOnly no se pueden limpiar desde JS — cuando el admin revoca un device, el browser quedaba con cookie zombie. Con localStorage, el patrón es self-healing: server devuelve 401 → cliente limpia su propio token. `_jwt_panel` del admin sigue siendo cookie (sin tocar).

**Implementación**:
- `lib/auth/device.ts`: `getDeviceToken()` / `setDeviceToken()` / `clearDeviceToken()` — acceso al token en `localStorage['punto.device.token']`.
- Backend (`jwt_middleware._jwtExtractTokens()`): lee `Authorization: Bearer` como fuente principal para realm `pos-app`. Cookie `_jwt` device eliminada.
- `PosAuthGuard`: `refetchInterval: () => getDeviceToken() ? 60_000 : false` — polling cada 60s, se desactiva tras logout (evita 401 loop).
- `lib/auth/module-logout.ts`: `moduleLogout()` — cleanup centralizado: `clearDeviceToken()` + reset Zustand stores (catalog/cart/hotkeys/lock) + `queryClient.clear()`. Llamado por `api-client` en cualquier 401 POS.
- `lib/auth/query-client-singleton.ts`: expone `queryClient` fuera de React (api-client no es módulo React).
- La tabla `device` es shared con el legacy `/app` — no crear tabla paralela.
- PIN del cajero: SHA-256 en `localStorage` via Web Crypto API (no bcrypt).

**Consecuencias de diseño**:
- El lock screen del POS **NO es logout** — solo bloquea la UI hasta que se ingresa el PIN.
- Cambiar de operador (PIN diferente) no altera el pairing del dispositivo.
- El logout del sidebar del panel borra `_jwt_panel` pero NO el device token.
- Revocar device desde panel → próximo fetch POS (max 60s) → `moduleLogout()` se ejecuta automáticamente → UI muestra `DeviceNotConnected`.
- Devices pareados antes del deploy 2026-06-27 perdieron auth (cookie zombie eliminada) y debieron re-pairear — decisión consciente.

### SSO handoff panel→app (commit 01d02a3, 2026-06-09)

El link "Caja" del sidebar del panel abre `app.punto.la`. Como los subdominios son distintos, las cookies no se comparten. El handoff resuelve esto server-side:

```
Panel JS (panel.punto.la)
  → POST /bff/admin/handoff (cookie _jwt_panel)
BFF panel/bff/handoff.php
  → llama a panel/API/v1/handoff.php
API
  → emite JWT corto (iss='pos-app', TTL 60s, claim did=null) con JWT_SECRET
  → retorna {token}
BFF → retorna {handoffUrl: "https://app.punto.la/handoff.php?t=<token>"}
Panel JS → window.open(handoffUrl, '_blank')

app/handoff.php (app.punto.la)
  → valida iss='pos-app' + exp (60s)
  → setcookie('_jwt', token_long, HttpOnly, SameSite=Lax)
  → redirect a /
```

**JWT del POS eterno (commit b5c483a):** cuando `JWT_TTL=0`, `app/handoff.php` y `app/API/auth.php` emiten tokens **sin claim `exp`** — el token nunca vence. Útil para tablets/cajas que no se re-loguean nunca. Si `JWT_TTL > 0`, se incluye `exp` normal. Coolify recomienda `JWT_TTL=0` para producción POS.

**SameSite=Lax (commit 38928d7):** todos los `setcookie()` de JWT usan `SameSite=Lax` (no Strict). Strict bloqueaba el redirect cross-origin del handoff (el browser no enviaba la cookie al cargar `app.punto.la` llegando desde `panel.punto.la`). Lax permite la cookie en top-level navigation cross-site.

**Detección de HTTPS vía X-Forwarded-Proto (commit 38928d7):** Coolify/Traefik termina TLS y hace forward a PHP con HTTP. `$_SERVER['HTTPS']` no está seteado. La detección correcta es `($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'` para setear el flag `Secure` en las cookies.

**Regla de aislamiento (no-negociable):** un token de un realm nunca valida en otro. Los tres realms están aislados por secret + cookie + claim `iss`. Para POS y panel, `JWT_SECRET` es COMPARTIDO — el claim `iss` es la barrera que previene la confusión de privilegios (commit 2de4231, 2026-05-31).

**Claim `iss` — valores canónicos (establecido commit 2de4231, 2026-05-31):**

| Valor | Emitido por | Validado en |
|-------|-------------|-------------|
| `'pos-app'` | `app/API/auth.php`, `app/API/refresh.php`, `app/login.php`, cron service tokens | `app/includes/jwt_middleware.php` |
| `'panel'` | `panel/includes/functions.php` (login tenant) + **`CompanyAdminService::getEnterToken()`** (impersonación F3.5) | `panel/API/lib/api_middleware.php`, `panel/upload.php` |
| `'admin'` | `panel/API/lib/admin_auth.php` | `panel/API/lib/admin_auth.php` (suma al `aud='admin'`) |

Cada middleware compara `($payload['iss'] ?? '') !== '<realm>'` y rechaza con 401 si no coincide. Tokens sin `iss` (emitidos antes del fix) → 401 — pre-producción, forzar re-login es aceptable.

**refresh.php:** valida `iss === 'pos-app'` ANTES de re-emitir, cerrando el privilege-escalation por refresh (un token con `iss=panel` enviado a `refresh.php` no puede obtener un token POS).

### Estado del admin realm (2026-05-28)

- **F0 HECHA (commit 01a8929):** tabla `admin_user` + `bootstrap_seed.php` + vars `.env`. La tabla existe pero nada del runtime actual la usa.
- **F1 HECHA (commit 96f8b8f):** auth propia `/admin` — `v1/admin/login.php` (público, rate-limit) + `v1/admin/me.php` (gated) + `adminMiddleware()` + BFF `bff/admin/{login,me,logout}.php` + front estático standalone `admin/login.html` + `admin/home.html`. Cookie `_jwt_admin` HttpOnly, token no llega al browser como JSON. Aislamiento verificado E2E (token cruzado → 401 en ambas direcciones).
- **F2 HECHA (commit 89e7388):** CRUD de super-admins — stack 3 capas: `panel/lib/admin/AdminUserService.php` (list/get/create/update/setStatus; reglas: email único case-insensitive, password >=8, no desactivar el último admin activo ni a uno mismo) + `panel/API/v1/admin/users.php` (gateado por `adminMiddleware()`) + `panel/bff/admin/users.php` + `panel/admin/users.html` + `panel/admin/scripts/users.js` (standalone, todo con `esc()`). Router `/admin/users`. `home.html` linkea al CRUD. Verificado E2E (list/create/dup-email 422/pass-corto 422/update/setStatus/auto-desactivación 422/get single).
- **F3.1 HECHA (commit 747384d, 2026-06-05):** Companies CRUD read-only — `panel/lib/admin/CompanyAdminService.php` (listAll/get/getCounts/getOwnersBatched/getCountsBatched; owners + counts con IN() — no N+1; filtro post-fetch en PHP; total = count del set filtrado) + `panel/API/v1/admin/companies.php` + `panel/bff/admin/companies.php` + `panel/admin/companies.html` + `panel/admin/scripts/companies.js` (vanilla JS, dark theme, drawer detalle role=dialog aria-modal, `esc()` en todo output). Router `/admin/companies`. `home.html` card "Empresas". Campo `externalCustomerId` (no `encomCustomerId`). **Patrón nuevo**: `mergeConfig()` inline en `CompanyAdminService` aplana el JSONB `company.config` (post-migración PG §22.8) sin importar `functions.php` desde el realm aislado. Ver §27 en `08-convenciones.md`.
- **F3.2 HECHA (commit 5fe4b39, 2026-06-07):** update company — PATCH semántico, 10 campos, JSONB config merge.
- **F3.3 HECHA (commit 5a6e4ab, 2026-06-07):** delete cascade soft+hard — ~57 DELETEs en TX PG única.
- **F3.4 HECHA (commit fb4a691, 2026-06-07):** billing view — `listPlans()` + `getBilling()` (balance, plan, últimos 50 cpayments). `get()` ahora incluye planName/planPrice/balance.
- **F3.5 HECHA (commit 456092f, 2026-06-07): impersonación JWT — "Ingresar como empresa".** Ver patrón abajo.
- **F3 COMPLETO. F4–F6 pendientes:** ver `10-roadmap.md § Admin realm`.

### Patrón de impersonación JWT (F3.5 — decisión arquitectónica)

El admin puede "entrar" al panel de una empresa sin conocer sus credenciales. El flujo es:

```
Admin (browser)
  ↓ POST /bff/admin/companies?id=<uuid>&action=enter  (cookie _jwt_admin)
BFF panel/bff/admin/companies.php
  ↓ reenvía POST con _jwt_admin a la API
API panel/API/v1/admin/companies.php  [gateado por adminMiddleware()]
  ↓ llama CompanyAdminService::getEnterToken(id)
Service panel/lib/admin/CompanyAdminService.php
  → genera JWT _jwt_panel (iss='panel', JWT_SECRET)
    para el contacto principal (role=1, main=true, type=0)
    con primer outlet activo como oid
    SIN setcookie() — retorna {token, expiresIn}
API → BFF (token en JSON)
BFF → setcookie('_jwt_panel', token, HttpOnly, SameSite:Strict)
     → falla 502 si token === '' (P0 code-review fix)
     → retorna {ok:true, redirectUrl:'/@#dashboard'}
Browser → window.open(redirectUrl, '_blank', 'noopener')
```

**Invariante de aislamiento mantenido**: `getEnterToken()` emite `_jwt_panel` usando `JWT_SECRET` (el mismo secret del realm tenant). El claim `iss='panel'` es la barrera que diferencia este token del `_jwt_admin` (`ADMIN_JWT_SECRET`, `iss='admin'`). Los dos realms siguen aislados — el admin no obtiene un `_jwt_admin` para el tenant, sino un `_jwt_panel` legítimo del tenant. El token que genera `getEnterToken` es indistinguible de un token emitido por el propio panel de la empresa.

**Por qué el Service NO hace setcookie()**: el Service vive en el realm admin (`panel/lib/admin/`) y no debe mezclar concerns de respuesta HTTP. El BFF es quien tiene el contexto de HTTP response; centralizar el `setcookie()` ahí es correcto (mismo patrón que el BFF de login del realm tenant).

**UUID validation en API**: `preg_match('/^[0-9a-f-]{36}$/i', $_GET['id'])` antes del lookup — previene inyección y mejora los mensajes de error (P1 code-review fix).

### MASTER\_COMPANY\_ID — rol post-F4 (desacoplado en commit ea7b67f, 2026-06-07)

`MASTER_COMPANY_ID` (env var) **ya NO es gate de IDENTIDAD** para los super-admins (F4 hecha). El flag `SAAS_ADM` + el redirect de `@.php:11` fueron eliminados (commit d310fe4 — F6). Su rol restante es scope de billing/datos de plataforma si aplica. El env var sigue presente pero no condiciona el flujo de autenticación.

### Realm franchiser — NO es /admin

El franchiser (`panel/franchiser.php`, gateado por `isParent`) es un **realm tenant** que opera sobre `/` (el mismo panel), no sobre `/admin`. Un franquiciador es un tenant con acceso cross-tenant acotado a sus hijos (via `franchiser_to_tenant`). No mezclar con el admin realm de plataforma.

## Patrones arquitectónicos

| Patrón | Dónde |
|--------|-------|
| Monolito con API REST emergente | `/panel/API/*.php` (93 endpoints) |
| ~~Action dispatcher~~ → **Vaciado (2026-06-01)** | `/app/action.php` tenía ~43+ acciones vía param `l=`; post-Slice 36 solo queda `processData`. El patrón BFF→API→Service lo reemplazó concern-por-concern. |
| BFF 3 capas (Front→BFF→API→Service) | `/panel/` (completo) + **`/app/` en desacople progresivo** (slice 1: customerAddress ✅, 2026-05-28) |
| **BFF same-origin Next.js (frontend)** | `frontend/app/api/v1/[...path]/route.ts` — catch-all que reenvía `/api/v1/*` al backend PHP preservando cookie `_jwt_panel`. `api-client.ts` del browser usa baseURL `/api` (same-origin, sin CORS). **Patrón canónico para CRUD en frontend desde commit 580d79a (2026-06-12).** Ver §37 en `08-convenciones.md`. |
| **BFF admin same-origin Next.js (frontend)** | `frontend/app/api/admin/[...path]/route.ts` — catch-all análogo al anterior para el realm admin. Solo forwarda cookie `_jwt_admin`; NUNCA forwarda `_jwt_panel`. Proxea a `panel/API/v1/admin/*`. Aislamiento de realm mantenido dentro del proceso Next.js. (commit be39b06, 2026-06-14) |
| **Route group `(admin)` en frontend** | `frontend/app/(admin)/admin/*` — sub-app greenfield del realm admin dentro de frontend. Layout propio, no comparte hooks ni contexto con `(panel)`. Path-based (`/admin`) dentro del mismo dominio, no subdominio dedicado. Ruta de login: `admin/login`. Páginas: dashboard, companies (list+detail), users (CRUD), requests, reports. (commits be39b06 + 605286e, 2026-06-14) |
| Pub/Sub bridge | PHP → Redis → Node.js WS → Browser |
| JSONB extensible | Columnas `config`, `data`, `meta` en tablas principales |
| UUID v7 como PK | Todas las tablas (via `ncmInsert()`) |
| Multi-tenant por filtro | `companyId` en WHERE de toda query |

## God nodes (más rompen si se tocan mal)

God nodes del repo por tamaño + responsabilidad medida (2555 nodos / 4058 edges al momento del análisis).

### Funciones críticas (medidas)

| Función | Edges | Dónde | Qué hace |
|---------|------:|-------|----------|
| `ncmExecute()` | 124 | `panel/includes/functions.php` + duplicado en `app/` | Ejecutor de queries con cache. Todo pasa por acá. **En `/app`: ahora wrapeado por `Punto\App\Database\Query::execute()` (Slice 10, commit 51d600b) — 1035 callers legacy preservados como wrappers; código nuevo usa la clase directamente.** |
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
| ~~`app/load.php` (1714 líneas)~~ → **ELIMINADO (`git rm`, Slice 43, commit cc02762, 2026-06-03)** | Dispatcher de proxies y APIs externas completamente vaciado y eliminado. Todos los handlers migrados al patrón BFF→API→Service en Slices 1-43. Servicios de pagos externos: `BancardService` (createQR/refreshQR/cancelQR) + `PixService` (getToken/createQR/verifyTransaction). Reducción: 1714 → 0 líneas (-100%). Ver `10-roadmap.md § load.php COMPLETAMENTE MIGRADO Y ELIMINADO`. |
| `panel/API/lib/api_middleware.php` | Auth de los endpoints migrados del panel |
| `app/includes/jwt_middleware.php` | Auth de /app — también usada por el bootstrap de `/api` vía `chdir+require` (transitorio) |
| `api/bootstrap.php` | Bootstrap de la API compartida; `apiAuthTenant()` — JWT tenant (cookie `_jwt` \| Bearer \| POST, claim `cid`, `JWT_SECRET`) |
| `ws-server/index.js` | Único archivo del WS |

**`panel/lib/reports/` — vaciado F2 (2026-06-10):** los 24 ReportXxxService.php que vivían en `panel/lib/reports/` fueron portados a `api/lib/Reports/` con namespace `Punto\Api\Reports`. `panel/lib/reports/` solo retiene los dos servicios no migrados: `ReportVpaymentsService.php` (proxy a gateway externo, F5) y `ReportInventoryService.php` (KPI widget — decisión de producto pendiente). El nuevo cluster god es `api/lib/Reports/` con sus 24 services + 3 helpers compartidos.

**Nota /app DB.php (actualizada 2026-06-10, commits 6ed461a + a8c12a1):** `app/includes/lib/DB.php` había divergido del panel — no tenía `whereParams` en `AutoExecute()` ni `_routeToJsonb`/`generateUuidV7` en `ncmInsert`/`ncmUpdate`. Ambos sincronizados. `Query::insert/update` del PSR-4 (Slice 10) ahora delegan a `ncmInsert`/`ncmUpdate` como single source of truth — NO reimplementan el routing JSONB por su cuenta. Ver §34 en `08-convenciones.md` para la regla canónica de write path con JSONB.

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

## Modernización PSR-4 de /app — namespace `Punto\App\*` (iniciado 2026-06-04, commit 8a7819c)

**Dualidad de namespaces en el proyecto:**

| Namespace | Codebase | Autoloader |
|-----------|----------|-----------|
| `Punto\Api\*` | `/api/lib/` | PSR-4 manual en `api/bootstrap.php` (`spl_autoload_register`) |
| `Punto\App\*` | `/app/` (Helpers, Domain, Http, Services, Database) | PSR-4 via `app/composer.json` (`composer dump-autoload`) |

**La frontera semántica es clara**: `Punto\Api\*` = lógica de la API compartida (backend único); `Punto\App\*` = lógica modernizada específica del POS. El legacy global (`app/includes/functions.php` + código sin namespace) coexiste con ambos durante la migración incremental.

**Estructura de directorios `app/` bajo PSR-4 (Slice 0, 2026-06-04):**

```
app/
├── composer.json                    ← autoload PSR-4 (5 prefijos)
├── Helpers/                         ← Punto\App\Helpers\ — utility puras (validity, iftn, toUTF8, niceDate)
├── Domain/
│   ├── Customer/                    ← Punto\App\Domain\Customer\
│   ├── Money/                       ← Punto\App\Domain\Money\
│   ├── Inventory/                   ← Punto\App\Domain\Inventory\
│   ├── Document/                    ← Punto\App\Domain\Document\
│   ├── Store/                       ← Punto\App\Domain\Store\
│   ├── Taxonomy/                    ← Punto\App\Domain\Taxonomy\
│   └── GiftCard/                    ← Punto\App\Domain\GiftCard\
├── Http/
│   └── Response/                    ← Punto\App\Http\Response\ — jsonDieMsg, dai, etc.
├── Services/
│   └── Notification/                ← Punto\App\Services\Notification\ — Email, SMS, Push, FE
└── Database/                        ← Punto\App\Database\ — Query (reemplaza ncmExecute/Insert/Update)
```

**Regla de convivencia legacy ↔ PSR-4**: las funciones globales de `app/includes/functions.php` se mantienen como wrappers hasta que los módulos PSR-4 que las reemplacen estén verificados. Código NUEVO en `/app` debe usar `Punto\App\*`; el código existente no se migra preventivamente. Ver §26 en `08-convenciones.md` para las reglas detalladas.

**Estado post Slices 9-10 (commit 51d600b, 2026-06-05):** 11/16 sub-slices completos. `functions.php` 3599 → 3203 líneas (−396). autoload: 3183 clases. ~6139 callsites migrados acumulados.

- **`Punto\App\Domain\Customer`** (Slice 9) — 11 métodos, 139 callsites. Fix P0 en `getName(mixed $data)`: tipado relajado de `array` a `mixed` + early-return on false (el legacy toleraba recibir `false` sin fatal).
- **`Punto\App\Database\Query`** (Slice 10) — god node `ncmExecute` (1035 callers) ahora tiene hogar namespaced. La función global `ncmExecute()` sigue activa como wrapper de 1 línea; código nuevo usa `Query::execute()`. Auto-referencia interna: `execute()` llama `self::flattenJsonb()` directamente. Ver §26.2 en `08-convenciones.md`.

**Estado post Slices 11-15 (commits 2cae098..532be24, 2026-06-05): PSR-4 prácticamente COMPLETO — 15/16 sub-slices. Slice 16 (deprecation removal) diferido post-release.**

- **`Punto\App\Domain\Document`** (Slice 11) — `getNextDocNumber`, 12 callers. Hogar canónico para numeración de comprobantes.
- **`Punto\App\Domain\Money`** (Slice 12) — 8 métodos, **702 callers**. Hogar canónico para todo el formateo monetario (`formatNumber`, `formatQty`, `formatForDB`) y lógica de impuestos (`addTax`). Sustituye al cluster money/tax de `functions.php`. **Código nuevo en /app que necesite formatear dinero usa esta clase directamente.**
- **`Punto\App\Domain\Inventory`** (Slice 13) — 11 métodos, **116 callers**. Hogar canónico para lógica de stock y COGS (`manageStock` CRÍTICO — 27 callers, `getCompoundsArray`, `getItemStock`, `getAllItemStock`, `getProductionCOGS`, `getComboCOGS`, `getNeedWithWaste`, `getAllWasteValue`, `getProductionCapacity`, `displayableCompounds`, `getItemMainStock`). **Código nuevo en /app que necesite gestionar stock usa esta clase directamente.**
- **`Punto\App\Domain\GiftCard`** (Slice 14) — `insertNew`, 1 caller.
- **`Punto\App\Services\Notification`** (Slice 15, namespace `Punto\App\Services\`) — 7 métodos, **76 callers**. Hogar canónico para envío de notificaciones (`sendEmails`, `sendSMS`, `sendWS`, `sendPush`, `sendEmail`, `sendSMTP`, `sendNCMSMS`).
- **Métricas finales**: `functions.php` **2560 líneas** (−643 en Slices 11-15). autoload: **3188 clases**. ~**7573 callsites** migrados acumulados.

**Plan de migración**: `docs/PLAN_functions_php_PSR4.md` — audit de 180 funciones, 32 dead code candidates, 16 sub-slices, estimación 220h. Ver `10-roadmap.md § Top-5 mejoras estructurales`.

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
| Auth | `apiAuthTenant(array $realms = ['pos-app'])` en `api/bootstrap.php` — JWT de tenant: cookie `_jwt` \| `Authorization: Bearer` \| POST `_jwt`; secret `JWT_SECRET`; claim `cid`. Mismo secret/claims que /panel y /app ya validan → una API autentica ambos clientes. **Allowlist por endpoint (F0, commit c4d3231):** cada endpoint declara qué realm(s) acepta — endpoints del POS usan el default `['pos-app']`; endpoints del panel usan `['panel']`; endpoints mixtos pueden declarar ambos. Tokens POS (eternos, device pairing) no pueden pegar a endpoints del panel y viceversa. El realm autenticado queda en la constante `AUTHED_REALM`. |
| Bearer panel | Los BFFs del panel llaman a `/api` con `Authorization: Bearer <_jwt_panel>` (no cookie) para no colisionar con la cookie `_jwt` del POS en el mismo browser. `panel/bff/lib/api_client.php` tiene dos bases: `'panel'` (legacy, cookie) y `'shared'` (/api compartida, Bearer). |
| Envelope | `apiOk()` / `apiError()` — `api/lib/response.php` (canónico) |
| Servicios | Tres familias coexisten (ver `10-roadmap.md § Servicios`): (1) **legacy** `api/lib/services/*Service.php` — 18 servicios sin namespace, PHP legacy, sin DTOs; (2) **modernos** `api/lib/<Module>/<Module>Service.php` — namespace `Punto\Api\<Module>`, `final class`, `readonly`, DTOs de entrada/salida, enums backed, excepciones custom, DI explícita (convención §22.9, establecida 2026-05-30). El primer módulo moderno es `api/lib/Sales/SaleService.php` (`Punto\Api\Sales`) + `api/lib/Context/TenantContext.php` (`Punto\Api\Context`); (3) **Reports** `api/lib/Reports/*Service.php` — namespace `Punto\Api\Reports`, `final class`, ROC y `$companyId` por parámetro (sin globals), 24 services + 3 helpers (`Roc.php`, `NonAddingSales.php`, `Taxonomy.php`) — migrados desde `panel/lib/reports/` en F2 (commits c4d3231..36fc3e3). El autoloader PSR-4 mínimo (~15 líneas, `spl_autoload_register`) en `api/bootstrap.php` mapea `Punto\Api\Foo\Bar` → `api/lib/Foo/Bar.php`. Código nuevo siempre va en el modelo moderno; los 18 legacy se modernizan al tocarse por razón funcional. |
| Namespace PSR-4 en /app | **`Punto\App\*`** (commit 8a7819c, 2026-06-04) — espejo de `Punto\Api\*`. Ver sección "Modernización PSR-4 de /app" abajo. |
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


---

> Sección "Estrategia de modernización del monolito (decisión 2026-05-24)" + el molde por módulo movidos a [_archive-arquitectura-legacy.md](_archive-arquitectura-legacy.md) — superseded por el rewrite frontend.

---

## Auth — Modelo dual de sesiones POS (ACTUALIZADO 2026-06-27)

El browser del operador tiene UN token de sesión de panel (cookie) y UN token de device POS (localStorage):

| Token | Mecanismo | Realm | TTL | Origen | Fin |
|---|---|---|---|---|---|
| `_jwt_panel` | Cookie HttpOnly | `panel` | 24h | `/login` del panel | Logout o expiración |
| `punto.device.token` | `localStorage` + `Authorization: Bearer` | `pos-app` + claim `did` | 10 años | Invitation flow (ver abajo) | Admin revoca → `moduleLogout()` auto o Ajustes → "Eliminar dispositivo" |

### Flujo de pairing — Invitation-based (reemplaza /pos-pair, 2026-06-27)

Modelo "Netflix/Spotify": el admin genera un link único con los parámetros pre-configurados. El cajero lo abre, el admin aprueba con 1 click. Sin password re-auth.

```
Admin (panel) → /settings/devices → tab "Solicitudes"
  → "Nueva invitación" → elige outlet + caja + nombre + TTL
  → POST /v1/device_invitations → INSERT device_invitation (UUID, status=pending)
  → Admin copia link → manda al cajero (WhatsApp/Slack/etc.)

Cajero → abre https://app.punto.la/connect/{uuid} en el device
  → /connect/[id] page (sin auth requerida)
  → polling GET /v1/device_invitations/{uuid} → status=pending → UI "Esperando aprobación"

Admin → panel recarga tab "Solicitudes" → ve la invitación activa
  → "Aprobar" → POST /v1/device_invitations/{uuid}/approve
    → INSERT en tabla device (con browserLocalId para idempotencia, mig 60)
    → DeviceInvitationService::issueToken() → emite JWT pos-app
    → invitation.status = approved, token guardado

Cajero → polling detecta status=approved → recibe token
  → localStorage['punto.device.token'] = token
  → redirect a /pos
```

**Idempotencia por `browserLocalId`** (mig 60): el device envía un UUID generado en localStorage (`punto.browser.id`). El índice único parcial en `device(browserLocalId, registerId, companyId) WHERE status=1` garantiza que el mismo browser + misma caja = 1 fila (auto-dedup si se vuelve a invitar).

### Flujo de operación POS

```
Browser con punto.device.token → /pos
  → PosAuthGuard: useBootstrap() con Authorization: Bearer
    → si null token → /connect (sin token)
    → si 401 → moduleLogout() → DeviceNotConnected
  → POS carga, polling refetchInterval 60s (desactivado si token nulo)
  → Operador tipea PIN en LockScreen → POST /v1/unlock-pin
  → Operación desbloqueada — identidad del operador en Zustand lock-store
```

### Revocación y auto-cleanup

```
Admin → /settings/devices → "Revocar"
  → device.status = 0 en BD
  → Device afectado: próximo refetch (≤60s) → 401
    → api-client: cualquier 401 POS → moduleLogout()
      → clearDeviceToken() + reset Zustand (catalog/cart/hotkeys/lock) + queryClient.clear()
      → UI muestra DeviceNotConnected (sin redirect a /pos-pair — ese flow fue eliminado)
```

### Modelo de operador (PIN)

El PIN del operador es identidad por-acción, NO genera JWT. El `lock-store` del browser guarda el operador actual. Se pierde con refresh → cajero re-tipea PIN en LockScreen. El device token de localStorage sigue intacto.

### Env vars

Sin env vars nuevas. Se reutilizan `JWT_SECRET` + `COOKIE_DOMAIN`.
