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

4. **Sincronizar `/context/` y graphify** — después de escribir el entry, consolidar doc-sync de toda la sesión:
   a. Listar commits de la sesión: `git log --oneline <hash-del-entry-anterior>..HEAD`
   b. Evaluar cuáles califican como "cambio relevante" per CLAUDE.md (schema/migrations, auth/JWT, módulos nuevos, infra, convenciones, roadmap, términos del dominio).
   c. Si hay al menos uno → invocar `Agent(subagent_type="context-updater")` **UNA SOLA VEZ**, pasando en el prompt la lista de commits + resumen de lo que cambió.
   d. Si todos son triviales (UI/copy/fixes menores/wip) → skip. Anotar en el entry: `- Docs: sin cambios para /context/ (solo fixes menores).`
   e. Reportar al usuario qué actualizó el context-updater (o confirmar skip con razón).
5. Mantener el log en orden CRONOLÓGICO INVERSO (más reciente arriba).
6. Cap del archivo: si pasa de ~200 líneas, archivar las primeras a `context/_session-log-archive-YYYY-MM.md` y dejar solo las últimas 3 semanas en el principal.

## Reglas estrictas

- **`/end-session` es el único momento para context-updater.** No invocar el agente después de commits individuales — eso se consolidó acá. Una sola llamada al cierre cubre toda la sesión.
- **No editar otros docs de `/context/` directamente** — eso es trabajo del context-updater agent invocado en el paso 4. El `/end-session` solo orquesta, no edita.
- **No filtrar techstack** al log. El log es interno, pero igual: usar lenguaje de negocio cuando sea posible.
- **Conciso**. 2-5 bullets máximo por entry. Si hubo más, sintetizar — no transcribir cada commit.
- **Si la sesión fue trivial** (un fix chico, sin decisiones) NO escribir entry. Saturar el log con ruido lo vuelve inútil.
- **No duplicar el commit log**. Los commits ya viven en git. El _session-log captura el WHY / contexto / pendientes — cosas que el commit no.

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
