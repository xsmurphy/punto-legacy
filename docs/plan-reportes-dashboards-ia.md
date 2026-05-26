# Plan: Reportes y Dashboards personalizados con copiloto IA

## Contexto

Punto es un ERP SaaS multi-rubro (PHP monolito + PostgreSQL, multi-tenant por columna `companyId`). Hoy los reportes son páginas PHP fijas en `panel/a_report_*.php` y el dashboard (`panel/a_dashboard.php`) es un layout hardcodeado sin persistencia de widgets. El problema recurrente es que cada cliente pide reportes a medida y resulta inviable mantenerlos a mano para todos los rubros.

La solución es habilitar un copiloto conversacional que, con el contexto de la empresa del usuario, genere reportes personalizados, los muestre en vivo, y permita anclarlos a uno o varios **dashboards** propios del usuario. Los reportes estándar siguen existiendo; lo nuevo es **aditivo**: una capa de "saved reports" + "dashboards" + agente IA que sabe traducir lenguaje natural en una especificación de reporte segura y multi-tenant.

Esto encaja con la **Phase AI** ya esbozada en `MODERNIZATION.md:219` (microservicio Python, Claude tool-use, llamadas autenticadas con JWT). Lo que sigue extiende ese plan agregando: persistencia de reportes/dashboards, un DSL seguro de "ReportSpec" en lugar de SQL libre, y la integración en el panel.

## Decisiones clave

1. **DSL "ReportSpec" en vez de text-to-SQL crudo.** El agente devuelve un JSON parametrizado (data source, métrica, dimensiones, filtros, rango de fechas, tipo de gráfico). Un *executor* en PHP traduce ese JSON a SQL parametrizado seguro y siempre inyecta `companyId = COMPANY_ID` server-side. El LLM **nunca** ve el `companyId` ni escribe SQL. Esto elimina el riesgo de fuga entre tenants y de SQL injection, y deja la puerta abierta a una "escape hatch" futura (read-only SQL sandbox) si hace falta.

2. **Microservicio agente separado, panel sigue siendo la fuente de verdad.** El agente Python (`punto-agent/`) recibe el chat, llama a Claude con un set acotado de *tools* y delega toda la ejecución a `panel/API/reports/*` con el JWT del usuario. El panel no necesita PHP-AI; solo nuevos endpoints REST.

3. **Persistencia mínima nueva**: tres tablas (`saved_report`, `dashboard`, `dashboard_widget`). No se tocan los reportes existentes — conviven.

4. **Frontend del dashboard**: usar **gridstack.js** (compatible con jQuery/Bootstrap 3, sin migrar a React) para grilla draggable + redimensionable. El chat copiloto es un widget jQuery flotante que habla con `/API/agent/chat`. No se introduce CopilotKit por ahora porque obligaría a un island React; queda como upgrade futuro cuando Phase 3 desacople el panel.

5. **Diccionario de datos por empresa**: archivo/tabla que describe en lenguaje de negocio qué métricas/dimensiones existen ("ventas netas", "ticket promedio", "stock por sucursal", etc.). Es lo que se inyecta al system prompt de Claude. Es la llave para que el agente entienda los datos sin conocer el esquema SQL.

## Arquitectura

```
Panel (browser)
 ├── a_dashboards.php           ← nuevo: lista/edita dashboards del usuario
 ├── chat widget (jQuery)       ← nuevo: copiloto flotante
 │     POST /API/agent/chat
 └── gridstack widgets          ← cada widget = saved_report renderizado con Chart.js
        GET  /API/reports/run/{id}

panel/API/   (PHP, reusa apiMiddleware + JWT existente)
 ├── agent/chat.php             ← proxy hacia punto-agent + persistencia de hilo
 ├── reports/preview.php        ← ejecuta un ReportSpec sin guardarlo
 ├── reports/save.php           ← persiste saved_report
 ├── reports/list.php
 ├── reports/run.php            ← ejecuta saved_report por id
 ├── reports/delete.php
 ├── dashboards/{list,save,get,delete}.php
 └── dashboards/widgets/{add,update,remove,reorder}.php
        ↓
 lib/report_executor.php        ← núcleo: ReportSpec → SQL seguro → datos
 lib/report_catalog.php         ← diccionario de data sources/metrics

punto-agent/  (Python + FastAPI, ya planificado en MODERNIZATION.md)
 ├── main.py
 ├── agent.py                   ← Claude tool-use loop
 ├── tools/
 │    ├── report_spec.py        ← tool "build_report" → devuelve ReportSpec
 │    ├── preview_report.py     ← tool que llama panel/API/reports/preview
 │    ├── save_report.py        ← tool que llama panel/API/reports/save
 │    └── pin_to_dashboard.py
 └── catalog_loader.py          ← descarga el data dictionary del panel y lo
                                  inyecta al system prompt
```

## Modelo de datos (PostgreSQL)

Agregar al final de `db-schema-postgres.sql`:

```sql
CREATE TABLE saved_report (
  reportId      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId     UUID NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  userId        UUID NOT NULL REFERENCES contact(contactId) ON DELETE CASCADE,
  name          VARCHAR(200) NOT NULL,
  description   TEXT,
  spec          JSONB NOT NULL,        -- ReportSpec validado
  chartType     VARCHAR(40) NOT NULL,  -- bar|line|pie|table|kpi|treemap...
  createdBy     VARCHAR(20) NOT NULL,  -- 'system'|'ai'|'user'
  sourcePrompt  TEXT,                  -- prompt original si fue generado por IA
  createdAt     TIMESTAMPTZ DEFAULT now(),
  updatedAt     TIMESTAMPTZ DEFAULT now()
);
CREATE INDEX idx_saved_report_company_user ON saved_report(companyId, userId);

CREATE TABLE dashboard (
  dashboardId  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId    UUID NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  userId       UUID NOT NULL REFERENCES contact(contactId) ON DELETE CASCADE,
  name         VARCHAR(120) NOT NULL,
  isDefault    BOOLEAN DEFAULT false,
  position     INT DEFAULT 0,
  createdAt    TIMESTAMPTZ DEFAULT now()
);
CREATE INDEX idx_dashboard_company_user ON dashboard(companyId, userId);

CREATE TABLE dashboard_widget (
  widgetId     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  dashboardId  UUID NOT NULL REFERENCES dashboard(dashboardId) ON DELETE CASCADE,
  reportId     UUID NOT NULL REFERENCES saved_report(reportId) ON DELETE CASCADE,
  gridX        INT NOT NULL,
  gridY        INT NOT NULL,
  gridW        INT NOT NULL,
  gridH        INT NOT NULL,
  overrides    JSONB                   -- filtros/título sobreescritos por widget
);

CREATE TABLE agent_thread (
  threadId   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId  UUID NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  userId     UUID NOT NULL REFERENCES contact(contactId) ON DELETE CASCADE,
  title      VARCHAR(200),
  messages   JSONB NOT NULL DEFAULT '[]'::jsonb,
  createdAt  TIMESTAMPTZ DEFAULT now(),
  updatedAt  TIMESTAMPTZ DEFAULT now()
);
```

Todas las tablas llevan `companyId` y se filtrarán siempre vía `getROC()` / `COMPANY_ID` igual que el resto del sistema (`panel/includes/functions.php`, `panel/includes/config.php:209`).

## ReportSpec — formato del DSL

```jsonc
{
  "source": "sales",                   // sales|expenses|inventory|customers|items|orders
  "metric": "net_total",               // declarado en el catálogo, NO una columna SQL
  "aggregation": "sum",                // sum|count|avg|min|max|count_distinct
  "groupBy": ["day", "outlet"],        // dimensiones del catálogo
  "filters": [
    { "field": "categoryId", "op": "in", "value": ["..."] },
    { "field": "paymentMethod", "op": "=", "value": "cash" }
  ],
  "dateRange": { "preset": "last_30d" },     // o {from,to}
  "chart": { "type": "bar", "stacked": true },
  "limit": 500
}
```

El **catálogo** (`lib/report_catalog.php`) es la única fuente que mapea cada `source/metric/dimension/filter` a columnas SQL reales y a las tablas válidas. Si el ReportSpec referencia algo fuera del catálogo, el executor lo rechaza. Esto reemplaza al text-to-SQL libre y es lo que blinda multi-tenant.

## Flujo del copiloto

1. Usuario en panel abre el chat y escribe: "mostrame ventas netas por día en cash de los últimos 30 días en sucursal centro".
2. JS POSTea a `panel/API/agent/chat.php` con `{prompt, threadId}` y JWT.
3. PHP reenvía a `punto-agent` (HTTP interno) pasando JWT + `companyId`.
4. `punto-agent` carga el data dictionary del panel (cacheado por companyId) y llama a Claude con tools: `build_report`, `preview_report`, `save_report`, `pin_to_dashboard`.
5. Claude emite `build_report` → ReportSpec → `preview_report` → el agente recibe los datos y los formatea + propone "¿lo guardo y lo anclo al dashboard X?".
6. Si el usuario confirma: `save_report` + `pin_to_dashboard` y el frontend recarga el grid.

## Endpoints PHP nuevos

Todos usan `apiMiddleware()` (JWT) y devuelven el envelope canónico de `panel/API/lib/response.php`:

- `POST /API/reports/preview` body: `{spec}` → `{rows, columns, chartHint}`
- `POST /API/reports/save` body: `{name, spec, chartType, sourcePrompt?}` → `{reportId}`
- `GET  /API/reports/list` → `[{reportId,name,chartType,createdBy,...}]`
- `POST /API/reports/run` body: `{reportId, overrides?}` → datos para Chart.js
- `POST /API/reports/delete`
- `GET  /API/dashboards/list`
- `POST /API/dashboards/save` (crea / renombra)
- `GET  /API/dashboards/get` body: `{dashboardId}` → dashboard + widgets
- `POST /API/dashboards/widgets/add` `{dashboardId, reportId, gridX,Y,W,H}`
- `POST /API/dashboards/widgets/reorder` `{dashboardId, layout:[{widgetId,x,y,w,h}]}`
- `POST /API/dashboards/widgets/remove`
- `POST /API/agent/chat` `{prompt, threadId?}` → SSE/stream o JSON con respuesta + acciones aplicadas
- `GET  /API/agent/catalog` → diccionario de datos serializado (lo consume `punto-agent`)

## Frontend

- Nueva página `panel/a_dashboards.php` con:
  - Dropdown de dashboards del usuario + botón "+ nuevo".
  - Grid `gridstack.js` que renderiza cada `dashboard_widget` como una card con Chart.js (reusar el bundle ya cargado, Chart.js 2.9.4).
  - Botón flotante "Copiloto" → abre panel lateral de chat.
- `a_dashboard.php` actual queda como está; cuando el usuario tenga al menos un dashboard propio, el menú lo lleva a `a_dashboards.php` por defecto.
- Bundle JS nuevo: `panel/scripts/dashboards.js` (módulo aislado, sin tocar `tdp.js`).

## Microservicio `punto-agent/`

Mismo stack ya planificado en `MODERNIZATION.md:276` (FastAPI + Anthropic SDK). Cambios respecto al plan original:

- Las tools no llaman a endpoints CRUD del ERP sino a los nuevos `reports/*` y `dashboards/*`.
- Se agrega `catalog_loader.py` que cachea el resultado de `GET /API/agent/catalog` por `companyId` (TTL 1 h).
- El system prompt incluye el catálogo + ejemplos few-shot de ReportSpec por rubro.
- El servicio NO tiene credenciales de DB; toda la autorización vive en el JWT que reenvía.

## Archivos críticos a crear/modificar

**Nuevos (PHP)**
- `panel/API/lib/report_executor.php`
- `panel/API/lib/report_catalog.php`
- `panel/API/reports/{preview,save,list,run,delete}.php`
- `panel/API/dashboards/{list,save,get,delete}.php`
- `panel/API/dashboards/widgets/{add,update,remove,reorder}.php`
- `panel/API/agent/{chat,catalog}.php`
- `panel/a_dashboards.php`
- `panel/scripts/dashboards.js`
- `panel/scripts/copilot-chat.js`

**Modificar**
- `db-schema-postgres.sql` — agregar las 4 tablas nuevas (con migración idempotente para entornos existentes).
- `panel/includes/menu.*` (donde sea que viva el menú) — entrada "Mis dashboards".
- `.env.example` — `ANTHROPIC_API_KEY`, `PUNTO_AGENT_URL`.
- `MODERNIZATION.md` — actualizar Phase AI con esta nueva sub-fase.

**Nuevo (Python, ver `MODERNIZATION.md:276` por la base)**
- `punto-agent/main.py`, `agent.py`, `catalog_loader.py`, `tools/*.py`

**Reusar (no tocar)**
- `panel/API/lib/api_middleware.php` — `apiMiddleware()` para auth JWT.
- `panel/API/lib/response.php` — `apiOk/apiError`.
- `panel/includes/functions.php` — `ncmExecute()` para todas las queries y `getROC()` para el filtro multi-tenant.
- `panel/includes/jwt.php` — emitir/validar JWTs.

## Plan de implementación por fases

| Fase | Scope | Entregable |
|---|---|---|
| **R.1** | Schema + executor + catálogo + endpoint `reports/preview` | Probado por curl con un ReportSpec hardcodeado |
| **R.2** | `reports/save`, `list`, `run`, `delete` + página `a_dashboards.php` mínima (sin grid, lista de reportes) | Crear/correr reportes manualmente desde UI |
| **R.3** | Tablas `dashboard` y `dashboard_widget` + endpoints + grid gridstack.js | Anclar reportes y reordenar |
| **R.4** | Microservicio `punto-agent` con tools de solo lectura (`build_report`, `preview_report`) + chat widget | Conversación que devuelve datos sin guardar |
| **R.5** | Tools de escritura (`save_report`, `pin_to_dashboard`) + persistencia de threads | Flujo end-to-end conversacional |
| **R.6** | Migrar 3-5 reportes estándar de `a_report_*.php` a ReportSpecs equivalentes (opcional) | Unifica reportes legacy con el nuevo motor |
| **R.7** | Hardening: límites de filas, timeouts de query, audit log de ejecuciones del agente | Producción |

Cada fase es entregable y reversible.

## Verificación end-to-end

1. **Schema**: aplicar la migración en PG local y verificar con `\d saved_report` que los FKs apuntan a `company` y `contact`.
2. **Executor (sin IA)**: `curl -X POST /panel/API/reports/preview` con un ReportSpec de prueba (cookie JWT válida) y validar que la respuesta solo contiene datos del `companyId` del JWT — repetir con un segundo usuario para confirmar aislamiento.
3. **Inyección de tenant**: forzar manualmente un ReportSpec con `filters: [{field:"companyId",...}]` y verificar que el executor lo rechaza o lo ignora (test crítico de seguridad).
4. **UI dashboard**: crear un dashboard, anclar un reporte, recargar la página y confirmar persistencia + drag/resize.
5. **Agente**: levantar `punto-agent` local (`uvicorn main:app`), enviar prompt vía chat widget, validar que se llama a `preview_report` con un ReportSpec válido y que los datos llegan al chat.
6. **Multi-tenant del agente**: loguearse con dos empresas distintas, pedir el mismo reporte y verificar que cada una solo ve sus datos (mirar logs del executor para confirmar el `WHERE companyId` correcto).
7. **Rate limiting**: verificar que `/API/agent/chat` respeta el límite ya existente (60 req/min en `api_middleware.php:121`).
