# Punto POS — Instrucciones para Claude

## Flujo de contexto — proporcional a la tarea

NO hay protocolo obligatorio "antes de cualquier trabajo". Buscá contexto
proporcional al riesgo de la tarea:

- **Trivial** (fix puntual en un archivo conocido, copy, comentario,
  rename): directo al código. Cero lectura previa.
- **Mediana** (cambio en un módulo): leé **UN solo doc** de `context/`
  según la tabla de abajo. Si pasa de 500 líneas, usá `Grep` para ubicar
  la sección + `Read` con `offset`/`limit`. NUNCA el doc entero.
- **Grande** (arquitectura, refactor multi-módulo, decisión de diseño):
  ahí sí, leé el doc completo + `_session-log.md` para continuidad.

### Tabla de docs (en `context/`)

| Tema | Archivo |
|---|---|
| Producto / negocio | `01-producto.md` |
| Arquitectura, flujos | `02-arquitectura.md` (641 L) |
| Stack, versiones | `03-stack.md` |
| Schema, dominio | `04-modelo-de-dominio.md` |
| Módulos | `05-modulos-clave.md` |
| Infra, deploy, env vars | `06-infraestructura.md` |
| Glosario | `07-glosario.md` |
| **Convenciones de código** | `08-convenciones.md` (1590 L — usar Grep) |
| Costos / créditos IA | `09-costos-y-creditos.md` |
| **Roadmap** | `10-roadmap.md` (1637 L — usar Grep) |
| Manual de marca | `11-design-system.md` |
| **Panel rewrite** | `12-panel-rewrite.md` (crítico desde 2026-06-10) |
| Plan refactor Items | `13-items-refactor-plan.md` |
| Bitácora de sesiones | `_session-log.md` |

### Archivos prohibidos para Read entero

NO leer estos archivos con Read sin `offset`/`limit` — los chunks
explotan el contexto:

- `graphify-out/graph.json` (2.1 MB) — datos crudos del grafo, sirve solo a graphify
- `graphify-out/graph.html` (1.9 MB), `graph.svg` (5.3 MB) — visualizaciones
- `context/10-roadmap.md` y `context/08-convenciones.md` (>1500 L cada uno) — Grep primero

Mempalace y Graphify **NO se usan** en este proyecto (decisión del owner —
gastaban tokens sin retorno). No los invoques aunque haya MCPs disponibles.

---

## Reglas del proyecto (críticas)

1. **Templating: Alpine.js, NO Mustache.js.** Todo template/UI nuevo se hace con
   Alpine (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`). Prohibido crear nuevos
   templates Mustache. Patrón Alpine: `context/08-convenciones.md` §24.

2. **Marca: "Punto", NO "ENCOM".** No introducir "ENCOM" en código/UI/datos
   nuevos. El rename del nombre BD (`encomdb`), claves de permisos
   (`permissions.encom.*`) y archivos de imagen requieren coordinación infra
   (no es find-replace ciego).

3. **No hardcodear dominios/URLs.** Deben venir de `simple.config.php` →
   `$_ENV[...]` (`APP_URL`, `API_URL`, etc.). CORS es security-sensitive:
   cualquier cambio debe preservar la allowlist actual como fallback.

---

## Workflow de Git

**Branch por subproyecto cuando hay sesiones paralelas; `main` para todo lo demás.**

Cuando una sesión va a tocar exclusivamente `app-next/` o `panel-next/` y hay
riesgo de que otra sesión esté tocando el otro en paralelo, trabajá en una
branch dedicada del subproyecto. Esto evita stomp entre sesiones y simplifica
el merge.

Convención de nombres:
- `app-next/<slice>` — ej. `app-next/slice-a6`, `app-next/qz-tray`
- `panel-next/<slice>` — ej. `panel-next/billing-ui`, `panel-next/team-crud`
- `api/<feature>` — solo para refactors grandes de `/api` PHP compartida
- Cualquier otra cosa (fixes triviales, docs, hooks, settings) → directo en `main`

Reglas:
1. **Una branch toca UN subproyecto.** Si necesitás modificar `app-next/` Y
   `panel-next/` en el mismo cambio, hacelo en `main` (los cambios cross-cutting
   son la excepción que justifica saltearse la branch).
2. **`api/`** y **`context/`** se pueden tocar desde la branch del subproyecto
   que los necesita (ej. una branch `app-next/*` puede modificar
   `api/v1/bootstrap.php` si su feature lo requiere — declaralo en el commit).
3. **`code-reviewer`** solo en commits de alto riesgo: schema/migrations,
   auth/JWT, admin realm, aislamiento multi-tenant, billing/pagos,
   hard-delete, CORS, permisos. Trivial (UI/copy/1-archivo): skip.
4. **Commit inmediato** dentro de la branch — no acumular cambios sin commitear.
5. **Push inmediato de la branch a remoto** — sirve de respaldo y permite que
   el owner mire el diff en GitHub mientras la sesión sigue.
6. **Excepción `wip:`**: commits con prefix `wip:` saltean reviewer (NO push).
7. **Merge a `main`** al cierre de sesión (o cuando el slice cierra), con
   `git merge --no-ff` desde main para preservar la historia del slice. Borrar
   la branch local + remota tras el merge.
8. **`context-updater` NO se invoca** post-commit. La bitácora se mantiene
   manualmente con `/end-session` al cierre (UNA llamada por sesión, no
   por commit). El `_session-log.md` se actualiza desde `main` post-merge,
   no desde la branch — así dos sesiones paralelas no compiten por ese archivo.

---

## Subagentes (`.claude/agents/`)

Invocá solo cuando matchee claramente la descripción del agente:

| Agente | Cuándo |
|--------|--------|
| `code-reviewer` | Commits de alto riesgo (ver Workflow §1) |
| `codebase-orchestrator` | Refactors multi-archivo con riesgo de regresión |
| `postgres-pro` | Optimización queries/índices, replicación, schema design |
| `typescript-pro` | TypeScript avanzado (panel-next) |
| `react-specialist` | React 18+ en panel-next |
| `Explore` | Búsqueda exploratoria ≥ 3 queries |
| `Plan` | Planificación de tareas no-triviales |

## Skills

Invocá proactivamente solo si el trigger es claro. Para el día a día,
las más relevantes son:

- `engineering:debug` — stack traces, errores prod, divergencias
- `engineering:code-review`, `simplify` — review pre-commit
- `engineering:architecture`, `engineering:system-design` — decisiones grandes
- `claude-api` — código que importa `anthropic` SDK
- `security-review` — auditoría de seguridad del branch
- `shadcn` — panel-next, componentes UI
- `end-session` — cierre con resumen en `_session-log.md`

El resto (operations, design, documentos, enterprise-search) solo si la
tarea lo pide explícitamente.
