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

4. Mantener el log en orden CRONOLÓGICO INVERSO (más reciente arriba).
5. Cap del archivo: si pasa de ~200 líneas, archivar las primeras a `context/_session-log-archive-YYYY-MM.md` y dejar solo las últimas 3 semanas en el principal.

## Reglas estrictas

- **No tocar otros docs de `/context/`** — el `/end-session` solo escribe en `_session-log.md`. Si algo amerita actualizar `04-modelo-de-dominio.md` o similar, ESO va por `context-updater` agent en su momento, no acá.
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
