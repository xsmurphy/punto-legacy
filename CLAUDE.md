# Punto POS — Instrucciones para Claude

## Kit de contexto (obligatorio leer al inicio)

El directorio `context/` es nuestro **vault de conocimiento** del proyecto — funciona como un Obsidian vault.
Antes de cualquier trabajo, leer `context/README.md` para el índice y luego los archivos relevantes a la tarea.

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

## Mantenimiento del vault

Al terminar cualquier sesión con cambios significativos, actualizar el archivo correspondiente del vault.
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

## Workflow de Git (estricto — única rama)

**Trabajamos siempre en `main`. No usar feature branches ni worktrees aislados.**

1. **Antes del commit**: ejecutar `Agent(subagent_type="code-reviewer")` sobre el diff staged. Si P0, parar y arreglar. Si P1, justificar.
2. **Commit inmediato** — no acumular cambios sin commitear.
3. **Push inmediato** después del commit — no acumular commits locales.
4. **Excepción**: commits con prefix `wip:` pueden saltearse el reviewer (pero NO el push).

Si una sesión te coloca en un worktree o branch distinta de `main`:
- Hacer el trabajo igual (no es bloqueante)
- Al cerrar, mergear el branch a `main` y eliminar el worktree
- Cualquier actualización al `context/` debe ir a `main`

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

## graphify

Este proyecto tiene un knowledge graph en `graphify-out/`.

Reglas:
- Antes de responder preguntas de arquitectura o código, leer `graphify-out/GRAPH_REPORT.md` para god nodes y comunidades
- Si existe `graphify-out/wiki/index.md`, navegar ahí en vez de leer archivos crudos
- Después de modificar código, correr `.venv/bin/python -c "from graphify.watch import _rebuild_code; from pathlib import Path; _rebuild_code(Path('.'))"` para mantener el grafo al día
