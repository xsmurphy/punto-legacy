<!-- REGLA: Este es el roadmap único del proyecto. Actualizar cuando:
     - Se completa un item (marcar ✅)
     - Se agrega un item nuevo
     - Cambian las prioridades
     - Se cierra una fase o se abre una nueva
     Antes era MODERNIZATION.md (consolidado acá el 2026-05-16). -->

# 10 — Roadmap Técnico

Roadmap único del proyecto Punto POS. Objetivo: modernizar progresivamente sin
big-bang rewrites, manteniendo el sistema funcional en cada etapa.

> **Última actualización:** 2026-05-16
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
- `app/scripts/globalv2.js` aún construye el payload completo en `?l=`
  con IDs que el server ya ignora. Limpiar en una sesión futura: el
  cliente debería mandar solo `?l=base64({action})` o pasar directo a
  query params planos (`?action=...`).

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

**Dependencia**: Phase 2 completa (API limpia y predecible)

### Visión

Un agente autónomo que habla con la API de Punto via JWT. Los usuarios interactúan con el sistema por chat (Telegram, WhatsApp, widget web) en lenguaje natural. El agente interpreta la intención, llama los endpoints correctos y devuelve respuestas formateadas.

### Arquitectura

```
Telegram / WhatsApp / Widget Web
         ↓
    punto-agent/  (microservicio Python + FastAPI)
    ├── Interpreta intención (Claude API — tool use)
    ├── Llama panel/API/* con JWT del usuario
    └── Formatea y devuelve respuesta
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

| Canal | Ejemplo de input | Action |
|-------|-----------------|--------|
| Telegram | "mandame el cierre de hoy" | `get_sales` → resumen formateado |
| Telegram | "cuánto stock me queda de Coca Cola" | `get_items` con filtro |
| WhatsApp | "registrá una venta de 2 hamburguesas" | `create_order` |
| Widget | "mostrame los clientes nuevos esta semana" | `get_customers` con filtro |
| Proactivo | (sin trigger) stock bajo detectado | Alerta automática |

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
| AI.1 | Agente básico + widget web + 5 tools de solo lectura (ventas, items, clientes) | Alta |
| AI.2 | Integración Telegram + bot de reportes | Alta |
| AI.3 | Tools de escritura (crear órdenes, registrar ventas) | Media |
| AI.4 | WhatsApp (Meta Cloud API) | Media |
| AI.5 | Alertas proactivas (cron que monitorea + notifica) | Media |
| AI.6 | Contexto persistente por usuario (memoria conversacional) | Baja |

**Esfuerzo MVP (AI.1)**: ~2 semanas

---

## Phase 3 — Desacople HTML/PHP/JS en el panel

**Problema**: Los `a_*.php` mezclan auth + queries + template. Imposible testear o mantener.

**Dependencia**: Phase 2 completa

**Propuesta**: Separar en: page controller (auth + `$pageData` mínimo) + data via API (AJAX).

**Antes:** `a_items.php` = auth + queries SQL + template HTML (todo junto)

**Después:**
- `a_items.php` = solo `include('secure.php')` + `$pageData` mínimo + template HTML
- Data del catálogo → `panel/API/get_items.php` vía AJAX

| # | Qué | Archivo |
|---|-----|---------|
| 3.1 | Piloto: refactorizar `a_items.php` | `panel/a_items.php` |
| 3.2 | Extraer componentes de layout reutilizables | `panel/layout/*.php` |
| 3.3 | Pipeline JS con `esbuild` | `panel/package.json` |
| 3.4 | Migrar 4 páginas más: contacts, dashboard, settings, reports | `panel/a_*.php` |

**Esfuerzo**: ~1 día por página (hay 80+, priorizar las más usadas)

---

## CDN Local completo

**Problema**: Algunos assets todavía referencian CDNs externos. Offline-first requiere todo local.

**Propuesta**: Mover todo a `/assets/vendor/`. Ya hay avance parcial.

**Esfuerzo**: ~4 horas para completar

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
