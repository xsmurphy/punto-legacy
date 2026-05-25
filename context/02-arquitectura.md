<!-- REGLA: Actualizar cuando se agregue un servicio nuevo, cambie la comunicación entre
     componentes, o se modifique un god node. NO actualizar por cambios internos a un módulo. -->

# 02 — Arquitectura

## Vista de 30 segundos

```
┌─────────────────────────────────────────────────────────────┐
│                        BROWSER                               │
│  ┌──────────┐    ┌──────────┐    ┌──────────────────────┐  │
│  │  /app    │    │  /panel  │    │  standalone (KDS,CDS) │  │
│  └────┬─────┘    └────┬─────┘    └──────────┬───────────┘  │
└───────┼────────────────┼─────────────────────┼──────────────┘
        │ HTTP           │ HTTP                │ WebSocket
        ▼                ▼                     ▼
┌───────────────┐  ┌───────────────┐  ┌──────────────────┐
│  PHP /app     │  │  PHP /panel   │  │  ws-server       │
│  (action.php) │  │  (API/*.php)  │  │  (Node.js:6001)  │
└───────┬───────┘  └───────┬───────┘  └────────┬─────────┘
        │                  │                    │
        ▼                  ▼                    ▼
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

1. **Request HTTP** → PHP valida JWT (cookie `_jwt` o `_jwt_panel`) → ejecuta lógica → responde JSON
2. **Evento real-time** → PHP `wsPublish()` → Redis PUBLISH → ws-server → broadcast a clientes suscritos
3. **Facturación electrónica** → PHP → EFATech/TaxPro API → respuesta → guarda en BD

## Patrones arquitectónicos

| Patrón | Dónde |
|--------|-------|
| Monolito con API REST emergente | `/panel/API/*.php` (93 endpoints) |
| Action dispatcher | `/app/action.php` (80+ acciones vía param `l=`) |
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
| `app/action.php` (143KB) | Dispatcher de 80+ acciones del POS |
| `panel/API/lib/api_middleware.php` | Auth de los endpoints migrados |
| `app/includes/jwt_middleware.php` | Auth de /app |
| `ws-server/index.js` | Único archivo del WS |

**Cross-coupling observado**: muchas funciones de `app/includes/functions.php` llaman
a funciones de `panel/includes/functions.php`. No son módulos independientes.

## Comunicación entre módulos

| De → A | Mecanismo | Ejemplo |
|--------|-----------|---------|
| Browser → PHP | HTTP (fetch/AJAX) | Login, CRUD, queries |
| PHP → Browser (real-time) | Redis Pub/Sub → ws-server → WebSocket | Orden nueva en KDS |
| PHP → API externa | HTTP client (curl) | Facturación electrónica, SMS |
| App ↔ Panel | Comparten BD directamente | Misma PostgreSQL, mismo schema |

## Decisiones arquitectónicas vigentes

- **No microservicios** (excepto ws-server) — el monolito funciona y se moderniza in-place
- **No ORM moderno** — ADOdb es legacy pero funcional; las queries son explícitas
- **API dentro de panel/** — no se separa en repo aparte (no vale la pena aún)
- **Agente IA como microservicio Python separado** — no toca el monolito PHP

## Patrón de refactorización por fases (canónico)

> **Esta es la estructura objetivo de TODA refactorización de módulos.**
> Surgió del refactor de Items (ver `context/refactor-items.md`) y se aplica
> a cualquier módulo legacy que vayamos modernizando (compras, clientes,
> ventas, etc.). **No cambiamos de stack** — seguimos en PHP + jQuery +
> Bootstrap 3. Lo que cambia es *cómo está organizado*, no *con qué está hecho*.

### Las 4 capas

```
┌──────────────────────────────────────────────────────────────┐
│  BROWSER                                                       │
│                                                                │
│  ① UI            a_<modulo>.js        JS + jQuery              │
│     (tabla, modal, eventos)           ↑ DataTables + BS3       │
│        │ llama a                                               │
│        ▼                                                       │
│  ② Cliente API   scripts/api/<x>.js   JS vanilla (fetch)       │
│     (itemsApi.create, .update...)     ↑ sin jQuery, portable   │
└────────┼───────────────────────────────────────────────────────┘
         │ HTTP — JSON { ok, data } / { ok:false, error }
┌────────▼───────────────────────────────────────────────────────┐
│  SERVIDOR (PHP)                                                 │
│                                                                │
│  ③ API REST      API/v1/<x>.php       PHP                       │
│     (GET/POST/PUT/DELETE)             ↑ apiMiddleware + apiOk() │
│        │ delega en                                             │
│        ▼                                                       │
│  ④ Dominio       lib/<x>/*.php        PHP                       │
│     (Service + Repository)            ↑ SQL parametrizado,      │
│                                          reglas de negocio      │
│        │                                                       │
│        ▼  PostgreSQL                                           │
└────────────────────────────────────────────────────────────────┘
```

### Regla de oro: separar por capa, no por pureza

| Capa | Stack | Por qué |
|------|-------|---------|
| ① UI (DOM/eventos) | **jQuery** | DataTables y Bootstrap 3 son dependencias duras de jQuery. Sacarlo del código nuevo no reduce el bundle — solo agrega inconsistencia. |
| ② Cliente API | **JS vanilla** (`fetch`) | Portable: si mañana cambia el front, el cliente se reusa. Cero jQuery. |
| ③ API REST | **PHP** | Endpoint fino: auth (`apiMiddleware`) + envelope (`apiOk`/`apiError`) + delega en Services. Sin lógica de negocio. |
| ④ Dominio | **PHP** | `<X>Service` orquesta reglas; `<X>Repository` hace SQL parametrizado. Reutilizable por API REST y handlers legacy. |

**El backend NO cambia de lenguaje** — sigue siendo PHP. Solo se reorganiza:
la lógica que antes vivía mezclada con HTML en `a_<modulo>.php` se extrae a
Services + un endpoint REST limpio.

### Envelope canónico (capa ③)

```jsonc
// éxito
{ "ok": true, "data": { ... }, "meta": { "ts": 1234567890, "v": "1" } }
// error
{ "ok": false, "error": { "message": "...", "code": 422, "details": [] } }
```

Helpers: `apiOk($data)`, `apiError($msg, $code)`, `apiNotFound()`, etc.
(`panel/API/lib/response.php`). El cliente vanilla (capa ②) desempaqueta
`data` o lanza un `ApiError` tipado si `ok === false`.

### Orden de las fases (probado en Items)

| Fase | Qué hace | Riesgo |
|------|----------|--------|
| **0** | Quick wins: borrar duplicados, fix SQLi, migraciones de tipo | bajo |
| **1** | Extraer dominio a `lib/<x>/` (Repository + Services) | bajo (no toca UI) |
| **2** | API REST `/API/v1/<x>/*` que reusa los Services | bajo (superficie nueva) |
| **3** | Schema cleanup (constraints, normalización incremental) | alto (migrations) |
| **4** | Front consume la API: cliente vanilla + UI jQuery llama a la API en vez de a handlers PHP de UI | medio |
| **5** | Decommission: borrar handlers PHP de UI ya migrados | medio |

**`apiMiddleware` acepta 3 vías de auth** (en orden): JWT (`_jwt_panel`) →
`api_key` + `company_id` → sesión PHP del panel (`$_SESSION['user']`). Esto
último permite que el front logueado consuma su propia API con
`fetch(..., { credentials:'include' })` sin credenciales extra.

### Cuándo se reconsidera el stack del front

Solo cuando se decida reemplazar **DataTables** (por tabla vanilla / web
component) y **Bootstrap 3** (por BS5 sin jQuery, o CSS puro). Eso es un
proyecto aparte con su propia fase — hasta entonces, jQuery se queda en la
capa ①.

## Estrategia de modernización del monolito (decisión 2026-05-24)

El panel son **48 módulos / ~45K líneas** + `app/action.php` (POS, 3.6K).
Modernizar todo de punta a punta (como se hizo con Items) tomaría meses.
Decisión estratégica para salir del monolito **rápido**:

**1. Backend primero, en TODOS los módulos.** El desacople de mayor valor
es sacar SQL + lógica de negocio del HTML hacia `lib/<x>/{Repository,Service}`
+ `API/v1/<x>.php`. Eso solo ya saca el módulo del monolito y deja base
para cualquier frontend. Es **mecánico y replicable** (ver el molde abajo).

**2. Frontend = vista PHP pura por defecto.** Mientras el backend se
desacopla, el HTML lo sigue renderizando PHP — pero como **presentación
pura** (sin queries ni lógica; los datos vienen de un Service/view-model).
Eso ya es "desacoplado". NO es obligatorio convertir a JS+hidratación.

**3. Frontend reactivo (Alpine.js) solo donde la UX lo amerita.** Para los
CRUD/POS donde la interactividad importa, usar **Alpine.js** (no Mustache):
reactividad declarativa en el HTML (`x-data`/`x-model`/`x-for`/`x-if`), sin
build, convive con jQuery/BS3, ~15KB. Elimina el view-model manual que hace
Mustache verboso y bug-prone. **Items queda en Mustache** (ya funciona, no
se reescribe); lo nuevo va en Alpine.

**Priorización por tipo de módulo:**

| Tipo | Módulos | Acción | Esfuerzo |
|------|---------|--------|----------|
| Reportes (read-only) | 5 (~13K líneas) | backend→API + listado data-driven; sin tocar forms | bajo |
| CRUD pesado | items✓, contacts (backend✓ 2026-05-25 — UI pendiente), purchase | backend Services+API; frontend Alpine si UX lo pide | medio |
| Config/raros | settings, modules, … | dejar legacy; solo backend si se tocan | diferido |

### El molde backend (replicable por módulo)

Para que cada módulo sea mecánico, no artesanal:

```
lib/<modulo>/
  <Modulo>Repository.php   SQL parametrizado puro
  <Modulo>Service.php      reglas de negocio + orquestación
API/v1/<modulo>.php        REST con apiMiddleware() + apiOk()/apiError()
scripts/api/<modulo>.js    cliente fetch vanilla (window.<modulo>Api)
a_<modulo>.php             vista: HTML de presentación pura (consume el Service)
```

Muchos módulos ya tienen endpoints sueltos en `API/*.php` (73 en total,
ej. `get_customers.php`, `edit_customer.php`) — se consolidan bajo el
Service + `API/v1/` canónico.
