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

## Sesión paralela `system-da` — qué entró (mismo día, coordinado por mensajes)

Estado al cerrar de esta sesión: `origin/main` = `35af1e62`, Front y Backend
deployados en ese commit, ambos `running:healthy`.

- **Un dispositivo = una sesión.** `DeviceAuth::buildToken()` revoca las
  sesiones activas del device antes de emitir. Va en `buildToken()` porque es
  el único punto por el que pasan las TRES vías de emisión. Mig 183 limpia lo
  ya encolado (dejaba una sesión viva por cada pareo histórico).
- **Tenencia de caja — el latido CONFIRMA, no adquiere.** `claim.php` hacía
  "confirmá o tomá" y el POS lo latía cada 5 min sin condición, así que un POS
  abierto se apropiaba de cualquier caja libre: quién facturaba lo decidía un
  timer. Ahora `acquire` es explícito (`RegisterLeaseService::claim()`), y
  tomar la caja es un acto del cajero o el drenaje de la cola offline.
  `close()` emite la entity `register-lease` — es el choke point de las cuatro
  vías de liberación, y solo el "Liberar caja" del panel avisaba antes.
- **Hora de emisión de las ventas.** `apiAuthPosContext()` no cargaba
  `data.php`, así que no aplicaba `TenantClock` y la sesión de PG quedaba en
  el baseline `APP_TIMEZONE` (sin definir en prod = UTC) mientras `drawer.php`
  corría en la zona del tenant. Una venta de las 12:07 se guardaba 09:07, no
  encontraba turno y desaparecía de Control de Caja. La fecha ahora se deriva
  del `timestamp` epoch que el POS ya mandaba y se descartaba. SIN backfill
  del histórico, por decisión del owner.
- **Permisos de lectura.** El asistente del POS leía con el Bearer del device
  (rol owner), así que cualquiera con la tablet pedía las ventas.
  `OperatorContext::requirePermission()` mide a la PERSONA en los tres realms,
  fail-closed sin operador. Y 27 endpoints de `api/v1/reports/` no chequeaban
  permiso en la lectura — incluidos 6 que un grep daba por seguros porque el
  `hasPermission` estaba solo en la rama POST.
- **Conteo de stock en la caja** (`context/63` F0+F1): `stockCountBlind`
  estaba muerto y se cableó; `/pos/conteo` con lista fija, ciego y
  offline-nativo; permiso `pos.stock.count` contra el operador del PIN (mig
  187); operación atómica idempotente por `opId` (mig 186); flag D9
  `stockCountRecordOnly`. Ajustes → **POS** → "Stock e inventario".
- Menores: deep-link `?section=` en `/settings` (los tabs se buscaban y
  aterrizaban siempre en "Empresa"); resaltado del sidebar por query string
  (entrar a Contactos marcaba las tres opciones).

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
- **Los flags de comercio son system-wide y varios NO deberían serlo**
  (observación del owner, 2026-09-02, sin plan escrito). `stockCountBlind`,
  `stockCountRecordOnly`, las listas de conteo, `settingDrawerTolerance`,
  `drawerRequireClosedOrders`, `blockUsedDocNo`, `autoSendDocs` viven en la
  config del tenant y aplican a TODAS las sucursales por igual — pero el
  conteo en sí ya es por sucursal (`inventory_count.outletid`, correlativo del
  docType por sucursal, esperado contra el ledger de esa sucursal). La regla
  que el owner quiere escrita: **antes de agregar un flag, decidir si la
  funcionalidad afecta a todas las sucursales por igual o solo a algunas.**
  Necesita doc propio con sus decisiones (override por sucursal sobre default
  de empresa, quién gana en conflicto, dónde se edita) ANTES de tocar código.
- Si el dueño edita una lista de conteo mientras una tablet tiene un conteo
  encolado sin red, al drenar se aplica la lista ACTUAL, no la que el cajero
  vio. Es seguro (los ítems nuevos quedan sin diferencia), pero el snapshot
  debería viajar siempre en la operación.
- El default `acquire: true` de `claim.php` es compatibilidad TRANSITORIA con
  bundles viejos del POS. Sacarlo cuando no queden clientes previos al
  2026-09-01.
- `realtimeAfterMutation()` (`api/bootstrap.php:538`) corre DENTRO de
  `apiAuthTenant()`, o sea al ENTRAR la request, antes de que el handler mute
  nada — y publica igual si después falla. Se esquivó para `register-lease`
  (emitiendo desde `close()` y sacando la ruta del default), pero afecta a
  TODAS las entities. Candidato fuerte para lo próximo.
- Recurrentes: `psql`/SSH a la BD bloqueados por el classifier; `npx vitest`
  desde la raíz falla, correr desde `frontend/`; no confundir horas sin
  convertir a UTC (Paraguay es UTC−3).
