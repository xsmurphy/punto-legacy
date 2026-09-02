# Hand-off — 2026-09-02

## Objetivo

Sesión larga, sin un único ticket: destrabar el MCP server (venía de "no
conecta" en el hand-off anterior), subir la calidad del catálogo de tools que
usan el agente y el MCP, arrancar el onboarding conducido por agente
(`context/66`), y una serie de bugs de fecha/franja horaria que el agente
destapó al usarlo contra datos reales. Hubo una sesión PARALELA (`system-da`)
trabajando el mismo repo todo el día, coordinada por mensajes — sus commits
están intercalados, ver rango exacto en el entry de bitácora de hoy.

## Estado al cerrar

`origin/main` = `35af1e62`. **Front y Backend deployados en `35af1e62`, ambos
`running:healthy`** (lo disparó la sesión paralela). Árbol limpio, nada
pendiente de deploy. Suite front 710/710.

## Archivos y cambios

- `frontend/lib/agent/normalize-tool-result.ts` + `tool-field-rules.ts` —
  motor + vocabulario de normalización semántica de las 20 tools (agente+MCP).
- `frontend/lib/domain/sale-type.ts` — fuente única del enum de tipo de
  transacción (antes triplicado PHP/UI/enteros mágicos), con test de paridad
  contra el enum PHP.
- `api/lib/Auth/AuditActor.php` — audita con el operador del PIN, no con el
  contacto que pareó la tablet (llamado desde `apiAuthTenant`).
- `context/66-onboarding-agente.md` — plan de onboarding conducido por
  agente; F1 (`create_outlet`/`create_register`/`assign_role`) implementada.
- `context/67-franja-horaria.md` — plan de franja horaria en reportes; F0/F1/
  F3 implementadas (`Date::hourRange()`, `HourBand`, 5 endpoints, tools).
- `frontend/hooks/use-date-range.ts` — 4 archivos/6 pantallas migradas al
  hook global; sumó `isCustom` y `scope: panel|pos`.
- `context/10-roadmap.md` — limpiado hoy: F2 del POS React (3 de 4 pendientes
  ya resueltos, solo faltaba nombre del operador — ahora cerrado con
  `3d3064f8`); sección "consolidado por sucursal" corregida — `contact_outlet`
  ya existe (mig 66), no hace falta crear `user_outlet`.

## Callejones sin salida

- **Dos errores de MCP parecían el mismo.** "Couldn't register with Punto's
  sign-in service" era el flujo OAuth muriendo en dynamic client registration
  (`/.well-known/*` y `/register` en 404). Resuelto eso, apareció "Couldn't
  reach Punto" — Cloudflare bloqueando el user-agent de Anthropic, causa
  totalmente distinta. Si el owner dice "sigue el mismo error", verificar el
  TEXTO exacto antes de asumir que no hubo avance.
- **Un bloqueo de user-agent es invisible desde curl con UA default.** Todo
  el smoke pasaba. Reproducir con el mismo user-agent que usa el cliente real
  cuando el síntoma es "no conecta desde X" y todo lo demás funciona.
- Afirmé dos veces que había sesión paralela sin verificarlo (lo deduje de
  commits ajenos ya cerrados en el historial) — el owner corrigió. Verificar
  con `git fetch` + `ListAgents`, nunca deducir del log.
- `git checkout <archivo>` para revertir un sabotaje de prueba se lleva
  puestos los cambios sin commitear del MISMO archivo. Pasó dos veces
  (`use-drawer.ts`, `bootstrap.php`), hubo que rehacer el trabajo. Antes de
  un checkout de reversión, `git stash` o commit primero.

## Próximo paso

Ninguna de las tres decisiones abiertas (ver Trampas) está arrancada. La más
barata de arrancar es el **consolidado por sucursal**: `contact_outlet` (mig
66) ya existe y `UsersService` ya la lee, así que el trabajo real es resolver
el SET en `bootstrap.php` y que `Roc::build` emita `IN (...)` en vez de
`= X`. `Roc::build` es el embudo único de 39 archivos de lectura — un error
ahí da números incompletos que parecen correctos, probar contra un tenant con
2+ sucursales asignadas antes de mergear.

## Trampas conocidas

- **Cloudflare "Block AI bots" en la zona `punto.la` sigue desactivada A
  MANO**, fuera del repo. Si alguien la reactiva, el MCP muere con "Couldn't
  reach Punto" sin que el síntoma señale a Cloudflare.
- **Decisión pendiente del owner: reconocimiento facial vs QR para
  asistencia.** Quiere explorar reconocimiento facial EN LUGAR del QR.
  Bloqueado por su propia decisión: cara on-device (privado, offline) vs
  servicio externo (más preciso, manda biometría de empleados a un tercero).
  El módulo `attendance` figura `status: "available"` sin UI ni generador de
  QR en el stack nuevo; su token `md5(companyId.outletId)` es derivable
  (ver `context/10-roadmap.md` §Seguridad P2, ya decidido reemplazarlo por
  secreto rotable — sin implementar).
- **Consolidado por sucursal**: decisión abierta de qué ve un usuario SIN
  asignaciones en `contact_outlet`. No asumir "todas" ni "ninguna" sin
  confirmar con el owner.
- Atajo "Reprocesar" en producción (el caso del pan que se muele ya funciona
  armando una receta) — falta solo descubribilidad, sin plan escrito.
- `tenant_audit` del asistente del POS: P1 de review pendiente — sigue
  atribuyendo storage-level a lo que resolvió `AuditActor.php`, pero no se
  reverificó end-to-end esta sesión.
- Recurrentes: `psql`/SSH a la BD bloqueados por el classifier; `npx vitest`
  desde la raíz falla, correr desde `frontend/`; no confundir horas sin
  convertir a UTC (Paraguay es UTC−3).
