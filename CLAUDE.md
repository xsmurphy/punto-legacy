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

**Si vas a retomar lo que quedó abierto**, leé `context/_handoff.md` PRIMERO:
es el estado de la última sesión (objetivo, qué quedó a medias, qué se intentó
y no funcionó, próximo paso). Se reescribe entero en cada cierre, así que
siempre describe el ahora — el `_session-log.md` es el índice histórico.

### Tabla de docs (en `context/`)

| Tema | Archivo |
|---|---|
| Producto / negocio | `01-producto.md` |
| Arquitectura, flujos | `02-arquitectura.md` (614 L) |
| Stack, versiones | `03-stack.md` |
| Schema, dominio | `04-modelo-de-dominio.md` |
| Módulos | `05-modulos-clave.md` |
| Infra, deploy, env vars | `06-infraestructura.md` |
| Glosario | `07-glosario.md` |
| **Convenciones críticas** | `08-convenciones-criticas.md` (241 L — invariantes nada más) |
| **Convenciones UI / Design system** | `14-ui-conventions.md` (lectura OBLIGATORIA antes de tocar JSX/TSX) |
| **Design system canonico (vivo)** | `20-design-system.md` (patrones de componentes, anti-patterns, changelog de decisiones) |
| Costos / créditos IA | `09-costos-y-creditos.md` |
| **Roadmap (vivo)** | `10-roadmap.md` (699 L) |
| Manual de marca | `11-design-system.md` |
| **Panel rewrite** | `12-panel-rewrite.md` (crítico desde 2026-06-10) |
| Plan refactor Items | `13-items-refactor-plan.md` |
| Plan espacios | `15-espacios-module-plan.md` |
| Rewrite POS (app-next) | `16-app-next-rewrite.md` |
| **POS roadmap (sprint 2026-06-21+)** | `19-pos-roadmap.md` |
| **Auth rewrite (JWT → tokens opacos)** | `21-auth-rewrite.md` (plan cerrado 2026-06-29) |
| **Sucursales, outlet scope, view-scope** | `25-sucursales-y-scopes.md` |
| **Facturación electrónica (Factomate/SIFEN)** | `28-facturacion-electronica-plan.md` |
| **P0 FISCAL — exclusividad de caja + numeración** | `29-numeracion-y-exclusividad-de-caja.md` (verificado contra prod: 4 dispositivos sobre la misma caja pueden duplicar números offline. F0/F1 en `main` — schema + backfill; F2/F3 implementadas en branch `api/numeracion-exclusividad` sin mergear, `register_lease` sigue con 0 filas en prod) |
| **`/admin` SaaS (dashboard, salud, planes, billing)** | `34-admin-saas-plan.md` (F1-F6 implementadas) |
| **Vínculos entre transacciones/órdenes (`transaction_link`)** | `35-transaction-link.md` (mig 115, implementado) |
| **Vouchers (vales por productos)** | `36-vouchers-plan.md` (F1/F2 implementadas 2026-08-07 — canje atómico dentro de la venta; F3 emisión desde caja pendiente) |
| **Numeración correlativa de documentos** | `37-numeracion-documentos.md` (plan abierto, D2/D3/D5 pendientes) |
| **Impuestos multi-tasa / multi-país** | `38-impuestos-multi-pais.md` (F0-F3, F5 implementadas — F3 factura+ticket lee IVA congelado, F5 RG90/Libro Ventas; F4 rollup pendiente) |
| **Detalle de transacción (resolver canónico)** | `39-detalle-transaccion.md` (F1-F3 implementadas — resolver + página `/transactions/{id}` + cotizaciones/pagos recibidos; F4 migrar el POS, abierta) |
| **Anulación y nota de crédito** | `40-anulacion-y-nota-credito.md` (plan cerrado 2026-08-14, D1-D4 decididas, sin implementar) |
| **Add-ons y combos** | `41-addons-y-combos.md` (F1-F5 implementadas, D1-D3 cerradas; F6 reportes y 2 gaps de F5 pendientes) |
| **Multi-moneda (ventas, compras, arqueo)** | `42-multi-moneda.md` (feature request, sin planificar) |
| **Remisión (traslado de mercadería)** | `42-remision.md` (implementada 2026-08-15, sin conexión SIFEN) |
| **Sync incremental del POS (reconexión/arranque, lápidas de borrado)** | `43-sync-incremental.md` (implementado 2026-08-16, delta en frío pendiente) |
| **Listas de precio offline (motor espejo + bajada al bootstrap)** | `44-listas-de-precio-offline.md` (plan sin implementar, D0-D6) |
| **Ítem/contacto como raíces de sync (trigger genérico de satélites)** | `45-satelites-item-contact-sync.md` (plan sin implementar, generaliza el D1 de 44) |
| **Cómo funciona cada módulo (y qué asume de los otros)** | `modules/_index.md` + un doc por módulo — LEER el del módulo que vas a tocar ANTES de integrarte con él |
| **Hand-off de la última sesión** | `_handoff.md` (se reescribe cada cierre) |
| Bitácora de sesiones | `_session-log.md` (índice histórico, append) |

> Items completados / docs superseded archivados en `_archive-*.md` (no se leen en uso normal).

### Archivos prohibidos para Read entero

NO leer estos archivos con Read sin `offset`/`limit` — los chunks
explotan el contexto:

- `context/_archive-convenciones-detalladas.md` (1697 L) y `context/_archive-roadmap-completado.md` (1058 L) — son archives, no contexto vivo; Grep solo si necesitás referencia histórica

---

## Reglas del proyecto (críticas)

1. **Templating: Alpine.js, NO Mustache.js.** Todo template/UI nuevo se hace con
   Alpine (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`). Prohibido crear nuevos
   templates Mustache. Patrón Alpine: `context/08-convenciones-criticas.md` §24.

2. **Marca: "Punto", NO "ENCOM".** No introducir "ENCOM" en código/UI/datos
   nuevos. El rename del nombre BD (`encomdb`), claves de permisos
   (`permissions.encom.*`) y archivos de imagen requieren coordinación infra
   (no es find-replace ciego).

3. **No hardcodear dominios/URLs.** Deben venir de `simple.config.php` →
   `$_ENV[...]` (`APP_URL`, `API_URL`, etc.). CORS es security-sensitive:
   cualquier cambio debe preservar la allowlist actual como fallback.

4. **Design system — shadcn default, NO copiar legacy visual.** Cualquier JSX/TSX
   nuevo en `frontend/` respeta `context/14-ui-conventions.md`: tipografía
   canónica (`h1=text-2xl font-semibold`, etc.), componentes shadcn sin
   sobreescribir tamaños sin razón documentada, `<DataTable>` para listados
   largos, `<EmptyState>` para vacíos, sin hex colors (excepto pedidos
   explícitos), formatos vía helpers, sin emojis. **Screenshots del legacy son
   referencia funcional, NO visual.** El brief de un sub-agente debe leer §14
   antes de tocar JSX y FLAGEAR en el reporte si el brief contradice la regla.

5. **Soluciones arquitectónicas, NUNCA parches** (regla global en `~/.claude/CLAUDE.md`).
   Casos típicos en este codebase donde la respuesta correcta es el wrapper, no
   el call-site: `CaseInsensitiveArray` del DB layer (RecordsetIterator en
   `app/Database/Query.php`), doble prefix `/api/api` (api-client baseUrl), Bearer
   faltante en `/api/pos/*` (lib/api/pos-fetch.ts), `registerId=''` en realm
   panel (guard en bootstrap.php). Si aparece un bug similar a alguno de estos,
   atacar la raíz, no agregar un parche más.

---

## Workflow de Git

**Branch por subproyecto cuando hay sesiones paralelas; `main` para todo lo demás.**

Cuando una sesión va a tocar exclusivamente `frontend/` (o `api/`) y hay
riesgo de que otra sesión esté tocando algo en paralelo, trabajá en una
branch dedicada del subproyecto. Esto evita stomp entre sesiones y simplifica
el merge.

> **El POS vive dentro de `frontend/` en `app/(pos)/pos`** (fusión 2026-06-16).
> El subproyecto `app-next/` fue eliminado — su contenido se movió al panel.
> Ya NO existen branches `app-next/*`.

Convención de nombres:
- `frontend/<slice>` — ej. `frontend/pos-fusion`, `frontend/team-crud`
- `api/<feature>` — solo para refactors grandes de `/api` PHP compartida
- Cualquier otra cosa (fixes triviales, docs, hooks, settings) → directo en `main`

Reglas:
1. **Una branch toca UN subproyecto.** Si necesitás un cambio cross-cutting
   (ej. `frontend/` Y `api/` a la vez de forma acoplada), hacelo en `main`.
2. **`api/`** y **`context/`** se pueden tocar desde la branch del subproyecto
   que los necesita (ej. una branch `frontend/*` puede modificar
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
| `typescript-pro` | TypeScript avanzado (frontend) |
| `react-specialist` | React 18+ en frontend |
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
- `shadcn` — frontend, componentes UI
- `end-session` — cierre: entry en `_session-log.md` + `_handoff.md` reescrito

El resto (operations, design, documentos, enterprise-search) solo si la
tarea lo pide explícitamente.
