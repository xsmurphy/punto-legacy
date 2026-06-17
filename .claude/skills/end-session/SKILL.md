---
name: end-session
description: Resume la jornada de trabajo y la appendea a context/_session-log.md para que la próxima sesión tenga continuidad cross-day
trigger: /end-session
---

# /end-session

Cierra una jornada de trabajo escribiendo un bullet-list de 2-5 líneas en `context/_session-log.md` con: qué se hizo, qué quedó pendiente, decisiones notables. La próxima sesión que abra `/context/` ve esto auto-loaded via CLAUDE.md y arranca sabiendo dónde se quedó la conversación.

## Cuándo invocarlo

- Al final de un bloque de trabajo concreto (no necesariamente fin de día calendario).
- Cuando el usuario explícitamente lo pide ("/end-session" o "cerremos por hoy").

## Cómo funciona

### Paso 0 — Delegar a Sonnet (OBLIGATORIO, antes de todo lo demás)

Esta skill SIEMPRE se ejecuta en Sonnet. Razones: (a) es trabajo de escritura/síntesis mecánico, no decisional; (b) el usuario reservó Opus/Fable para conversación e implementación, no para cierres.

Comprobá el modelo actual al inicio:

- **Si el modelo NO es Sonnet** (estás en Opus/Fable/Haiku/etc):
  1. Compilá un brief con: (a) `git log --oneline <hash-último-entry-del-session-log>..HEAD` de los commits de la sesión; (b) un resumen narrativo extraído de la conversación de qué se hizo, qué se decidió, qué quedó como TODO; (c) wrinkles técnicos o cosas non-obvious del flujo.
  2. Llamá `Agent(subagent_type: "claude", model: "sonnet", description: "Run /end-session", prompt: "<brief + el resto de las instrucciones de esta skill>")` — incluí en el prompt los pasos 1+ de esta misma skill para que el sub-agente sepa qué ejecutar.
  3. Reportá al usuario el resultado del sub-agente.
  4. **SALÍ — no ejecutes los pasos 1+ vos mismo.**

- **Si el modelo SÍ es Sonnet**: procedé con los pasos 1+ inline como siempre.

### Pasos de ejecución

1. **Leer git log de los commits desde el último entry del session log** — eso da el "qué pasó" objetivo.
2. **Inferir el contexto** desde la conversación actual (qué se discutió, qué se aprobó, qué quedó como TODO).
3. **Escribir un entry nuevo** al TOPE de `context/_session-log.md` con la fecha de hoy. Formato:

```markdown
## 2026-05-03 (sábado tarde)

- **Hecho**: porting /contacts a `<DataTable>` (TanStack), column pinning, drawer de preferencias con density/export, scroll-to-top. Commits 529b936..ee378de.
- **Decisión**: server siempre UTC, conversión a TZ tenant en boundaries (commit ddd7ea1). NO `TZ=America/Asuncion`.
- **Pendiente**: portar /tasks/campaigns/pages a `<DataTable>`. Refactor TZ-aware en /calendar/tables/orders (roadmap §7).
- **Atención**: el agent escalate_to_human ahora deja nota interna cuando la notificación falla — verificar después del próximo deploy.
```

4. **Editar docs de `/context/` MANUALMENTE si hubo cambio relevante** (la regla del owner — `context-updater` está apagado por completo):
   a. Listar commits de la sesión: `git log --oneline <hash-del-entry-anterior>..HEAD`.
   b. Evaluar cuáles califican como "cambio relevante" per CLAUDE.md (schema/migrations, auth/JWT, módulos nuevos, infra, convenciones críticas, roadmap, términos del dominio).
   c. Si hay al menos uno → editar el doc correspondiente VOS MISMO con `Edit` (no es un agente aparte). Mantenelo breve — el detalle vive en el commit. Para cambios chicos a roadmap/glosario, agregar 1-2 líneas; si es un módulo nuevo, agregar la entrada al `05-modulos-clave.md`.
   d. Si todos son triviales (UI/copy/fixes menores/wip) → skip.
   e. **NUNCA** invocar `Agent(subagent_type="context-updater")`. Está apagado por decisión del owner (gastaba tokens regenerando graphify).
5. Mantener el log en orden CRONOLÓGICO INVERSO (más reciente arriba).
6. Cap del archivo: si pasa de ~100 líneas, archivar las más viejas a `context/_session-log-archive-YYYY-MM.md` y dejar solo las últimas 3 semanas en el principal.

## Reglas estrictas

- **`context-updater` NO se invoca NUNCA.** Está apagado. Si necesitás editar `context/*.md`, hacelo vos mismo con `Edit`.
- **No regenerés graphify.** También apagado por la misma decisión.
- **Formato del entry: corto.** 1 línea de titular + 1-2 líneas con rango de commits y highlights. El detalle vive en los commits. Patrón nuevo:
  ```
  ## 2026-06-16 — bugfixes team + purchase + dashboard
  Commits `5220d63..1f2e45b` (47). Highlights: fix /v1/users (tabla role inexistente),
  PIN POS 4 dígitos, rediseño dashboard, fix purchase itemSold schema.
  ```
- **Si la sesión fue trivial** (un fix chico, sin decisiones) NO escribir entry.
- **No duplicar el commit log**. El _session-log es para el titular + highlights de un día, no para transcribir cada commit.

## Output esperado

Después de invocar:

```
✓ Session log actualizado: context/_session-log.md
  3 bullets agregados bajo "## 2026-05-03 (sábado tarde)"
  Commits cubiertos: 6 (529b936..ee378de)
```

Si no hay nada que loggear:

```
- Sesión sin cambios notables (commits chicos, sin decisiones). Skip.
```
