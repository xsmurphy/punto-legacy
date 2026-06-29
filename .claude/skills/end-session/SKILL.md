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

## ⚠️ FORMATO DEL ENTRY — leer ANTES de escribir nada

**Cap duro: ≤ 3 líneas totales por entry.** Titular (línea 1) + 1-2 líneas de prosa con rango de commits y highlights inline. NO usar bullets, NO usar negritas tipo `**Foco 1**`, NO usar párrafos separados por `\n\n`. El detalle vive en los commits — el log es un índice, no una transcripción.

Antes de tipear el entry contá las líneas mentalmente. Si vas por 4+, estás escribiendo el commit log de nuevo. Pará y comprimí.

**❌ MAL** (lo que NO hay que hacer — esto duplica el detalle de los commits):

```markdown
## 2026-06-28 (sábado, jornada larga) — hotfix auth POS + arq wrappers + …

Commits `e3915d80..8a804e87` (70). Cuatro focos mayores más ~40 fixes en cadena.

**Foco 1 — Auth POS + BFF wrapper**: `/pos` daba 502/401 en prod → diagnóstico SSH. Fix en `apiAuthTenant`: resuelve `userId` desde `device.userid`… [3-4 oraciones más por foco]

**Foco 2 — DB layer wrapper**: bugs recurrentes `CaseInsensitiveArray`… [otro párrafo]

**Foco 3**, **Foco 4**, **Cluster UX/bugs**, **Pendientes**… [8+ párrafos en total]
```

**✅ BIEN** (titular + 1-2 líneas, highlights en una sola corrida separados por `;`):

```markdown
## 2026-06-28 — auth POS hotfix + wrappers BFF/DB + pantalla cliente al device flow + multi-outlet

Commits `e3915d80..8a804e87` (70). Highlights: fix `apiAuthTenant` realm pos-app + Bearer pos-fetch (502/401 prod); merge `bff-proxy-unified` (-487 LOC, `bffProxy()`); arq DB wrapper (`RecordsetIterator` + `flattenJsonb` plano, breaking); mig 64 DROP `customer_display`; mig 66 `contact_outlet` M2M. Pendiente: smoke moduleLogout, pay-dialog→MoneyInput, drop `contact.outletid`.
```

### Por qué esta regla existe

- El `_session-log` se carga via CLAUDE.md en cada sesión. Cada línea cuesta tokens en todas las sesiones futuras.
- Los detalles ya están en `git log`/`git show`. Re-escribirlos en prosa es ruido.
- El log es para que la próxima sesión sepa _dónde mirar_, no para reemplazar el diff.

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
3. **Escribir un entry nuevo** al TOPE de `context/_session-log.md` con la fecha de hoy, respetando el formato definido en la sección ⚠️ arriba (titular + 1-2 líneas, ≤ 3 líneas totales, sin bullets ni párrafos por foco).

4. **Editar docs de `/context/` MANUALMENTE si hubo cambio relevante** (la regla del owner — `context-updater` está apagado por completo):
   a. Listar commits de la sesión: `git log --oneline <hash-del-entry-anterior>..HEAD`.
   b. Evaluar cuáles califican como "cambio relevante" per CLAUDE.md (schema/migrations, auth/JWT, módulos nuevos, infra, convenciones críticas, roadmap, términos del dominio).
   c. Si hay al menos uno → editar el doc correspondiente VOS MISMO con `Edit` (no es un agente aparte). Mantenelo breve — el detalle vive en el commit. Para cambios chicos a roadmap/glosario, agregar 1-2 líneas; si es un módulo nuevo, agregar la entrada al `05-modulos-clave.md`.
   d. Si todos son triviales (UI/copy/fixes menores/wip) → skip.
   e. **NUNCA** invocar `Agent(subagent_type="context-updater")`. Está apagado por decisión del owner.
5. Mantener el log en orden CRONOLÓGICO INVERSO (más reciente arriba).
6. Cap del archivo: si pasa de ~100 líneas, archivar las más viejas a `context/_session-log-archive-YYYY-MM.md` y dejar solo las últimas 3 semanas en el principal.

## Reglas estrictas

- **`context-updater` NO se invoca NUNCA.** Está apagado. Si necesitás editar `context/*.md`, hacelo vos mismo con `Edit`.
- **Formato del entry**: ver sección ⚠️ arriba — cap duro ≤ 3 líneas, sin bullets, sin "Foco N". No-negociable.
- **Si la sesión fue trivial** (un fix chico, sin decisiones) NO escribir entry.
- **Auto-check antes de guardar**: si el entry tiene más de 3 líneas o usa `**Foco`, está mal. Comprimí o pedí ayuda al usuario.

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
