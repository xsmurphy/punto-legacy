# Punto POS — Instrucciones para Claude

## Orden obligatorio de consulta de contexto

Antes de cualquier trabajo, consultar las tres fuentes en este orden:

1. **Mempalace** — el "qué pasó antes": contexto acumulado entre sesiones, decisiones pasadas, errores conocidos.
2. **`context/`** — el "cómo está diseñado": vault estructurado del proyecto (producto, arquitectura, dominio, convenciones, roadmap).
3. **graphify** — el "cómo está conectado el código": knowledge graph del codebase con god nodes y comunidades.

No saltarse pasos. Cada fuente cubre una pregunta distinta; las tres juntas dan el cuadro completo antes de tocar código.

---

## 1) Mempalace — memoria entre sesiones (MCP)

Wing de este proyecto: **`system`**.

### Cuándo consultar
- Al inicio de cualquier sesión antes de arrancar trabajo.
- Ante preguntas sobre decisiones pasadas, errores repetidos, o patrones conocidos.
- Cuando el `context/` vault no tenga la respuesta.

### Cómo consultar
1. `mcp__mempalace__mempalace_search` con términos del tema — punto de entrada principal.
2. `mcp__mempalace__mempalace_list_rooms` en wing `system` para ver las áreas disponibles.
3. `mcp__mempalace__mempalace_get_drawer` si un drawer específico parece relevante.

### Cuándo guardar
- Decisiones de arquitectura no-obvias tomadas en la sesión.
- Errores o trampas que costaron tiempo y podrían repetirse.
- Verificar duplicados con `mcp__mempalace__mempalace_check_duplicate` antes de `add_drawer`.

---

## 2) context/ — vault de conocimiento del proyecto

Leer `context/README.md` primero (índice) y luego los archivos relevantes a la tarea según esta tabla:

| # | Archivo | Cuándo leerlo |
|---|---------|---------------|
| — | [context/README.md](context/README.md) | Siempre al inicio — índice del kit |
| — | [context/_session-log.md](context/_session-log.md) | Para ver qué se hizo en sesiones recientes |
| 01 | [context/01-producto.md](context/01-producto.md) | Para entender el negocio y los principios UX |
| 02 | [context/02-arquitectura.md](context/02-arquitectura.md) | Para preguntas de arquitectura, flujos, god nodes |
| 03 | [context/03-stack.md](context/03-stack.md) | Para versiones exactas de lenguajes/frameworks |
| 04 | [context/04-modelo-de-dominio.md](context/04-modelo-de-dominio.md) | Para queries, schemas, invariantes |
| 05 | [context/05-modulos-clave.md](context/05-modulos-clave.md) | Para entender qué hace cada módulo |
| 06 | [context/06-infraestructura.md](context/06-infraestructura.md) | Para deploy, Docker, env vars, migraciones |
| 07 | [context/07-glosario.md](context/07-glosario.md) | Para términos del producto y del código |
| 08 | [context/08-convenciones.md](context/08-convenciones.md) | **Crítico** — reglas de colaboración |
| 09 | [context/09-costos-y-creditos.md](context/09-costos-y-creditos.md) | Para APIs pagas y modelo de créditos IA |
| 10 | [context/10-roadmap.md](context/10-roadmap.md) | **Crítico** — backlog priorizado, fuente única de verdad del roadmap |
| 11 | [context/11-design-system.md](context/11-design-system.md) | Manual de marca — reutilizar clases/colores existentes (BS3+app.css); skill `brand-manual`. Nunca inventar estilos ni rediseñar |

### Mantenimiento del vault

Al terminar cualquier sesión con cambios significativos, actualizar el archivo correspondiente.
Criterio: *"¿la próxima sesión arrancaría confundida sin esta nota?"*

| Si cambia... | Actualizar... |
|--------------|---------------|
| Roadmap, prioridades, fases | `10-roadmap.md` |
| Servicios nuevos, comunicación entre componentes, god nodes | `02-arquitectura.md` |
| Versiones de lenguajes/frameworks | `03-stack.md` |
| Schemas, entidades, relaciones | `04-modelo-de-dominio.md` |
| Reglas de colaboración o convenciones nuevas | `08-convenciones.md` |
| Resumen de sesión (siempre al cierre) | `_session-log.md` |

El `_session-log.md` tiene cap blando de 200 líneas. Cuando se supere, archivar a `_session-log-archive-YYYY-MM.md`.

---

## 3) graphify — knowledge graph del código

Knowledge graph generado en `graphify-out/`.

### Cómo consultar
- Antes de responder preguntas de arquitectura o código, leer `graphify-out/GRAPH_REPORT.md` para god nodes y comunidades.
- Si existe `graphify-out/wiki/index.md`, navegar ahí en vez de leer archivos crudos.

### Mantenimiento
Después de modificar código, regenerar el grafo:

```bash
.venv/bin/python -c "from graphify.watch import _rebuild_code; from pathlib import Path; _rebuild_code(Path('.'))"
```

El subagente `context-updater` ya lo regenera automáticamente cuando lo invocás post-commit.

---

## Reglas del proyecto (críticas)

1. **Templating: Alpine.js, NO Mustache.js.** Todo template/UI nuevo se hace con
   Alpine (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`). Está PROHIBIDO crear nuevos
   templates Mustache (`<script type="text/html">` + `Mustache.render`/`ncmUIX.mustache`).
   Mustache sigue cargado solo porque quedan ~22 templates legacy en `/app`; se migran
   a Alpine de forma incremental. Patrón de integración Alpine: ver `context/08-convenciones.md` §24.

2. **Marca: "Punto", NO "ENCOM".** "ENCOM" es el nombre viejo del sistema; el actual es
   "Punto". No introducir "ENCOM" en código, UI, nombres ni datos nuevos. Renombrar las
   ocurrencias existentes a "Punto" cuando se toque el código, con cuidado por categoría:
   el nombre de BD (`encomdb`), las claves de permisos en BD (`permissions.encom.*`) y los
   archivos de imagen (`encom_app.png`) requieren coordinación de infra/datos (no es un
   find-replace ciego). Ver el cluster ENCOM→Punto en `context/10-roadmap.md`.

3. **No hardcodear dominios/URLs.** Ningún dominio (`*.encom.app`, `*.punto.app`, etc.) debe
   estar hardcodeado en el código — deben venir de config/env (`simple.config.php` →
   `$_ENV[...]`: `APP_URL`, `API_URL`, `PUBLIC_URL`, `POS_URL`, `WS_URL`, …). Así el rename
   de marca (ENCOM→Punto) en dominios es solo cambiar un valor de env, no editar código.
   Hoy quedan dominios hardcodeados (cors.php allowlists, páginas `*.shtml`, `manifest.json`,
   `.htaccess`) — centralizarlos es deuda registrada en `context/10-roadmap.md`. CORS es
   security-sensitive: cualquier cambio debe preservar la allowlist actual como fallback.

---

## Workflow de Git (estricto — única rama)

**Trabajamos siempre en `main`. No usar feature branches ni worktrees aislados.**

1. **Antes del commit**: ejecutar `Agent(subagent_type="code-reviewer")` sobre el diff staged **solo si el commit es de alto riesgo**. Si P0, parar y arreglar. Si P1, justificar.
   - **Alto riesgo** (reviewer obligatorio): schema/migrations, auth/JWT, admin realm, aislamiento multi-tenant, billing/pagos, hard-delete, cambios en CORS o permisos.
   - **Trivial** (skip reviewer): UI/copy, bug fix de 1 archivo sin lógica de negocio, comentarios, refactors de estilo, commits `wip:`.
2. **Commit inmediato** — no acumular cambios sin commitear.
3. **Push inmediato** después del commit — no acumular commits locales.
4. **Excepción**: commits con prefix `wip:` pueden saltearse el reviewer (pero NO el push).
5. **context-updater al CIERRE, no por commit.** Durante la sesión tomá nota de qué calificó como cambio relevante. `/end-session` consolida y corre el agente UNA sola vez al cerrar.
   - Caso borde: si la sesión cierra sin `/end-session`, al arrancar la próxima corré `git log` desde el último entry del session-log e invocá context-updater manualmente si hay cambios relevantes.

Si una sesión te coloca en un worktree o branch distinta de `main`:
- Hacer el trabajo igual (no es bloqueante).
- Al cerrar, mergear el branch a `main` y eliminar el worktree.
- Cualquier actualización al `context/` debe ir a `main`.

---

## Uso proactivo de agentes y skills

Los **subagentes** y **skills** disponibles deben invocarse por iniciativa propia
cuando la tarea matchee su descripción — no esperar a que el usuario pida.

### Subagentes (`.claude/agents/`)

| Agente | Cuándo invocarlo |
|--------|-----------------|
| `codebase-orchestrator` | Refactors multi-archivo con riesgo de regresión. Cambios estructurales del repo |
| `postgres-pro` | Optimización de queries, índices, performance tuning de PG, replicación, schema design |
| `typescript-pro` | Cuando se introduzca TypeScript en el stack (no aplica hoy) |
| `react-specialist` | Cuando se introduzca React en el stack (no aplica hoy) |
| `Explore` | Búsqueda exploratoria en el codebase (≥ 3 queries esperadas) |
| `Plan` | Planificación de implementación para tareas no-triviales |
| `general-purpose` | Para code-review del diff staged (rol obligatorio antes de commit). Investigaciones abiertas |

### Skills (vía herramienta `Skill`)

Invocar proactivamente cuando aplique:

**Engineering** (las más usadas en este proyecto):
- `engineering:debug` — ante stack traces, errores de prod, divergencia con expected
- `engineering:code-review` — review pre-merge / pre-commit (complementa `Agent(general-purpose)`)
- `engineering:architecture` — al elegir entre tecnologías o documentar trade-offs (ADR)
- `engineering:tech-debt` — al auditar code health o priorizar refactors
- `engineering:testing-strategy` — al diseñar tests o discutir coverage
- `engineering:documentation` — al escribir READMEs, runbooks, API docs
- `engineering:incident-response` — si algo se rompe en prod
- `engineering:deploy-checklist` — antes de release con migrations / feature flags
- `engineering:system-design` — al diseñar nuevos servicios, APIs, data models
- `engineering:standup` — para resúmenes de actividad reciente

**AI / Claude API**:
- `claude-api` — **crítica para Phase AI**. Tool use, prompt caching, model selection, migración entre modelos. Trigger automático si código importa `anthropic` SDK

**Reviews especializadas**:
- `security-review` — auditoría de seguridad del branch actual
- `review` — review de PR
- `simplify` — limpieza de código cambiado (reuso, calidad, eficiencia)

**Operations**:
- `operations:runbook` — al documentar procedimientos repetibles
- `operations:change-request` — al proponer cambios con impact analysis
- `operations:process-doc` — al formalizar workflows
- `operations:risk-assessment` — al evaluar riesgos de un cambio

**Tooling del harness**:
- `update-config` — para modificar `settings.json`, hooks, permisos, env vars
- `fewer-permission-prompts` — para reducir prompts repetitivos de permisos
- `keybindings-help` — atajos de teclado

**Cierre de sesión** (skill local del proyecto):
- `end-session` — appendea un resumen 2-5 bullets a `context/_session-log.md`
  con qué se hizo, decisiones, pendientes. Triggera con `/end-session` o
  "cerremos por hoy". Lee `git log` desde la última entry y sintetiza —
  no duplica commits

**Búsqueda y conocimiento**:
- `enterprise-search:search` — buscar en sources conectadas
- `find-skills` — descubrir e instalar skills nuevas

**UI** (cuando aplique al proyecto):
- `shadcn` — si se introduce shadcn/ui en el futuro
- `design:design-critique`, `design:accessibility-review`, `design:ux-copy` — cuando se rediseñe UI del panel
- `design:user-research`, `design:research-synthesis` — para entender usuarios objetivo

**Documentos** (puntual):
- `anthropic-skills:pdf|docx|xlsx|pptx` — solo si la tarea produce/consume ese archivo
- `pdf-viewer:*` — solo si hay interacción visual con PDF

### Regla general

> Si una skill o agente matchea claramente el trigger declarado en su descripción,
> invocarla sin pedir permiso. Si hay duda razonable, preguntar al usuario qué prefiere.
