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
FRONT   panel/reports/<x>.html  (estático, cero PHP)  +  panel/scripts/<x>.js   (pinta + auth + chrome)
BFF     panel/bff/<area>/<x>.php   → JWT + llama a la API + formatea + JSON   (NO BD, NO HTML)
API     panel/API/v1/<area>/<x>.php  → apiMiddleware + apiOk()/apiError() (envelope) — RAW
DOMINIO panel/lib/<x>/{Repository,Service}.php  (SQL + reglas de escritura)  — vive con la API
```

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
