# Punto POS — Roadmap de Modernización

Sistema POS/ERP SaaS en PHP. Objetivo: modernizar progresivamente sin big-bang rewrites, manteniendo el sistema funcional en cada etapa.

> **Última actualización:** Abril 2026
> **Nombre del sistema:** Punto (antes ENCOM)

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.x, sin framework, archivos monolíticos |
| DB | PostgreSQL 16 vía ADOdb + Docker ✅ |
| Frontend | Bootstrap 3 + jQuery, HTML mezclado con PHP |
| Auth (app) | **JWT HS256 ✅** — cookie `_jwt` HttpOnly + fallback legacy activo |
| Auth (panel) | Sesiones PHP + API key + **JWT HS256 ✅** — 10/64 endpoints migrados |
| IDs | **UUID v7 ✅** — enc()/dec() identity, ncmInsert auto-genera PK |
| API | ~93 endpoints en `panel/API/*.php`, 10 con envelope canónico |
| WebSockets | ~~Pusher~~ → ws-server propio (Node.js + Redis Pub/Sub) ✅ |
| Seguridad | Bypass key eliminado, CORS allowlist, debug gateado |

---

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Vista general

```
Phase 0 ✅ → Phase 1 ✅ → Phase 2 (parcial) → Phase 3 → Phase 6
                                  ↓
                       Phase WS ✅ (completado durante Phase 2)
                                  ↓
                       Phase UUID ✅ (enc/dec identity + UUID v7 en ncmInsert)
                                  ↓
                       Phase PG ✅ (PostgreSQL: schema v2 + JSONB + migrations PHP)
                                  ↓
                             Phase AI (nuevo — post Phase 2)
```

---

## Phase 0 — Security Hotfixes ✅ COMPLETO

| # | Qué | Estado |
|---|-----|--------|
| 0.1 | Eliminar bypass key `d41d8cd98f...` | ✅ |
| 0.2 | Reemplazar `Access-Control-Allow-Origin: *` con allowlist | ✅ |
| 0.3 | Gatear `?debug` con `APP_DEBUG=true` en `.env` | ✅ |
| 0.4 | Mover `SALT` a `.env` como `HASHIDS_SALT` | ✅ |
| 0.5 | Headers de seguridad (`X-Content-Type-Options`, `X-Frame-Options`, etc.) | ✅ |

---

## Phase WS — WebSocket Microservice ✅ COMPLETO

> Reemplaza Pusher (tercero de alto costo) con infraestructura propia.

### Arquitectura implementada

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
| `panel/standalone/scripts/ncm-ws.js` | Wrapper JS compatible con API de Pusher (subscribe/bind/unbind) |

### Archivos migrados (Pusher → NcmWS)

| Archivo | Canal | Evento |
|---------|-------|--------|
| `panel/standalone/kds.php` + `kds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/kds2.php` | `{outletId}-KDS` | `order` |
| `panel/standalone/kdsDate.php` | — | (CDN removido) |
| `panel/standalone/kdsDate2.php` | — | (CDN removido) |
| `panel/standalone/cds.php` + `cds.js` | `{outletId}-KDS` | `order` |
| `panel/standalone/checkoutScreen.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/standalone/checkoutScreen2.php` | `{companyId}-{regId}-register` | `checkoutScreen` |
| `panel/main.php` | `ncm-ePOS` | `payoutNow` |
| `panel/API/send_webSocket.php` | dinámico | dinámico |

### Protocolo del cliente (`ncm-ws.js`)

```js
// Drop-in replacement de Pusher:
var pusher = new NcmWS(WS_URL);        // antes: new Pusher('key', { cluster: 'sa1' })
var ch = pusher.subscribe('outlet123-KDS');
ch.bind('order', (data) => { ... });
ch.unbind('order');
```

### Variables de entorno

```ini
WS_URL=wss://ws.tudominio.com      # producción
WS_URL=ws://localhost:6001          # local
REDIS_URL=redis://redis:6379        # dentro de Docker
```

### Docker Compose

```yaml
ws:
  build: { context: ./ws-server }
  container_name: punto_ws
  environment: { WS_PORT: 6001, REDIS_URL: redis://redis:6379 }
  ports: ["6001:6001"]
  depends_on: [redis]
```

---

## Phase 2 — Formalizar la capa API del panel (parcial)

### Archivos creados

| Archivo | Descripción |
|---------|-------------|
| `panel/API/lib/response.php` | Envelope canónico: `apiOk()`, `apiError()`, `apiNotFound()`, etc. |
| `panel/API/lib/api_middleware.php` | Middleware centralizado: JWT + fallback api_key, define constantes |
| `panel/API/auth.php` | Login JWT para el panel (POST email+pass → JWT + HttpOnly cookie) |
| `panel/includes/jwt.php` | Copiado de `app/includes/jwt.php` |

### Envelope canónico

```json
// Éxito
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }

// Error
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

### JWT en el panel: ya implementado

El JWT para `/panel` **ya funciona**. `panel/API/auth.php` emite tokens y `api_middleware.php` los valida con cookie `_jwt_panel`. Cada endpoint migrado a `apiMiddleware()` automáticamente soporta JWT — no hay nada más que diseñar.

### Endpoints retrofitados (10/83)

`get_items`, `get_customers`, `get_categories`, `get_brands`, `get_users`,
`get_outlet`, `get_settings`, `get_company`, `get_sales`, `get_orders`

> Los 54 endpoints restantes aún usan `api_head.php` (legacy). Migrar es mecánico:
> reemplazar `include_once('api_head.php');` por `require_once __DIR__ . '/../lib/api_middleware.php'; apiMiddleware();`

### Patrón de migración por endpoint

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

### Bugs preexistentes detectados (NO relacionados a la migración)

- `get_company` 500: `getTagsDefaults()` itera sin nil check cuando no hay tags
- `get_orders` 500: usa `OUTLET_ID` (hashid string) directamente en SQL sin `dec()`

---

## Phase 2 — Pendiente

| # | Qué | Detalle | Prioridad |
|---|-----|---------|-----------|
| 2.A | Retrofitar los 54 endpoints restantes | Cambio mecánico: `api_head.php` → `apiMiddleware()` | Alta |
| 2.B | Fix `get_company` 500 | `getTagsDefaults()` — agregar nil check | Alta |
| 2.C | Fix `get_orders` 500 | Usar `dec(OUTLET_ID)` en la query SQL | Alta |
| 2.D | OpenAPI spec para los 10 endpoints migrados | `panel/API/openapi.yaml` | Media |
| 2.E | Helpers de validación de request | `panel/API/lib/validate.php` | Baja |

---

## Phase 1 — Auth JWT para el módulo `/app` ✅ COMPLETO

> Reemplazó el mecanismo de identidad falsificable (`$_GET['l']` base64) con JWT.

### Archivos creados/modificados

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

---

## Phase AI — Agente IA / Copiloto (nuevo)

> **Deps:** Phase 2 completa (API limpia y predecible)
> Convierte Punto en un ERP AI-first con copiloto conversacional.

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

### Variables de entorno nuevas

```ini
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...    # Meta Cloud API
PUNTO_API_BASE=https://panel.tudominio.com/API
AGENT_JWT_SECRET=...          # Para tokens de vinculación
```

---

## Phase 3 — Desacople HTML/PHP/JS en el panel

> **Deps:** Phase 2 completa

Separa data-fetching (PHP) de presentación (HTML/JS) en los archivos `panel/a_*.php`.

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

---

## Phase UUID — Migración a UUIDs ✅ COMPLETO

> enc()/dec() convertidas a identity functions (sin Hashids). UUID v7 auto-generado en ncmInsert.

| # | Qué | Estado |
|---|-----|--------|
| U.1 | `enc()`/`dec()` → identity passthrough (panel, app, crons) | ✅ |
| U.2 | `generateUuidV7()` en `functions.php` | ✅ |
| U.3 | `ncmInsert` auto-genera UUID v7 en la PK correcta por tabla | ✅ |
| U.4 | JWT payload cambiado de int a string para cid/oid/rid/sub | ✅ |
| U.5 | `contactUID` eliminado — reemplazado por `contactId` en ~103 archivos | ✅ |

---

## Phase PG — MySQL → PostgreSQL ✅ COMPLETO

> **Deps:** Phase UUID completa

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

---

## Phase 6 — Arquitectura moderna (Slim 4)

> **Deps:** Phases 1-5

Introduce Slim 4 como aplicación paralela. Sin tocar el código legacy. Un endpoint a la vez bajo `/v2/...`.

---

## Variables de entorno completas

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

# AI Agent (Phase AI)
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...
PUNTO_API_BASE=https://panel.tudominio.com/API

# Feature flags (Phase 6)
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```

---

## Próximos pasos (orden recomendado)

1. **Ahora:** Terminar Phase 2 — retrofitar los 54 endpoints restantes + fixes de bugs (2.A, 2.B, 2.C)
2. **Luego:** Phase AI.1 — agente básico con widget web (5 tools solo lectura)
3. **Luego:** Phase AI.2 — bot de Telegram
4. **Paralelo/largo plazo:** Phase 3 (desacople HTML/PHP), Phase 6 (Slim 4)

---

## Contexto técnico importante (para nuevas sesiones)

### Estructura del proyecto

```
system/
├── app/                    # POS — módulo de caja (PHP legacy)
├── panel/                  # Admin/ERP (PHP legacy)
│   ├── API/                # ~93 endpoints REST
│   │   ├── lib/
│   │   │   ├── response.php       # Envelope canónico ✅
│   │   │   └── api_middleware.php # Middleware JWT ✅
│   │   └── auth.php               # Login JWT ✅
│   ├── includes/
│   │   ├── simple.config.php      # Constantes globales (WS_URL, etc.)
│   │   ├── jwt.php                # JWT HS256 puro PHP
│   │   └── ws_publish.php         # Publica eventos a Redis
│   └── standalone/
│       └── scripts/
│           └── ncm-ws.js          # Cliente WebSocket (drop-in Pusher) ✅
├── ws-server/              # Microservicio Node.js WebSocket ✅
│   ├── index.js
│   └── Dockerfile
├── docker-compose.yml      # MySQL + Redis + PHPMyAdmin + ws-server
└── MODERNIZATION.md        # Este archivo
```

### Decisiones técnicas tomadas

| Decisión | Elección | Razón |
|----------|----------|-------|
| Lenguaje backend | PHP (mantener) | Sin capacidad de rewrite completo |
| WebSockets | ws-server propio (Node.js) | Eliminar costo de Pusher |
| Pub/Sub | Redis | Ya en el stack, sin dependencia extra |
| JWT | HS256 custom PHP | Sin composer dependency adicional |
| IDs en API | Hashids (mantener por ahora) | Migrar a UUID en Phase 4 |
| API location | Dentro de panel/ (mantener) | No vale la pena separar aún |
| AI Agent | Microservicio Python separado | No tocar el monolito |

### Notas de `api_middleware.php`

```php
// CRÍTICO: $db debe ser global antes de incluir db.php y functions.php
// porque functions.php llama getAllPlans() en scope global en línea 3
global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

// enc()/dec() no están en functions.php, están redefinidos en el middleware
// con un $HASHIDS_INSTANCE global compartido
```
