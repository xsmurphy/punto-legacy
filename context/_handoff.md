# Hand-off — 2026-09-03

## Objetivo

Dos sesiones en paralelo (`system-09`, cerró 09-02; `system-da`, cerró 09-03)
sobre el mismo repo. `system-09` destrabó el MCP en producción y subió la
calidad de su catálogo. `system-da` arrancó de un reporte del owner —parear
una caja, revocarla y habilitarla en otra dejaba las dos sin facturar— que
destapó una cadena de 5 bugs de auth/tenencia/fecha/permisos, y cerró con el
alcance por sucursal (decisión ya fijada por el owner: la sucursal se define
en el usuario, no en la key ni en el outlet activo) llevado a los dos realms
que faltaban, `api` y `panel`.

## Estado al cerrar

`origin/main` = `f67f5e84`. Front y Backend deployados en `5a0de2d7`, ambos
`running:healthy`. Los dos commits de docs después de ese deploy
(`40128910`, `f67f5e84`) son solo `context/`+`CLAUDE.md`, no necesitan
deploy. Árbol limpio, nada pendiente.

## Archivos y cambios

- `api/lib/Auth/DeviceAuth.php` — `buildToken()` revoca sesiones del device
  al emitir (antes: una sesión viva por cada pareo histórico). Mig 183.
- `api/lib/Pos/RegisterLeaseService.php` — `claim()` separa `acquire`
  (explícito, cajero) de confirmar; `close()` emite `register-lease` por
  realtime, choke point de las 4 vías de liberación.
- `api/bootstrap.php` — `apiAuthPosContext()` aplica `TenantClock`;
  `OperatorContext::requirePermission()` mide a la persona en los 3 realms.
- `api/lib/Outlets/OutletScope.php` — alcance por sucursal: `effectiveIds()`
  + `sqlFilter()`, usado por realm `api` y `panel`. `Roc::build` emite
  `IN (...)`.
- `frontend/lib/api-client.ts` — ante 403 `outlet_out_of_scope` limpia la
  preferencia de `X-Outlet-Id` de `localStorage` y reintenta una vez.
- `frontend/lib/settings/sections.ts` — fuente única de secciones de
  settings, con test contra `routes.ts`.
- `context/70-viandas.md` — plan nuevo, D1-D6 cerradas, sin implementar.
- `context/63`, `context/29`, `context/25`, `context/58`, `CLAUDE.md` —
  actualizados esta sesión (ver abajo).

## Callejones sin salida

- La tenencia de caja parecía un bug de LIBERACIÓN (revoke, unpair, cerrar
  caja) — los cuatro caminos estaban bien. Era de ADQUISICIÓN: el latido de
  `claim.php` tomaba la caja sin condición cada 5 min.
- "Ningún camino emite `register-lease`" era falso — el panel sí, por el
  default de `realtimeAfterMutation()`. Por eso el fix fue en `close()`.
- La hora corrida 3h NO era mezcla `timestamp`/`timestamptz` (refutado en
  Postgres local, ambas son `timestamptz`) — era la zona de sesión de PG
  distinta según el embudo de auth (`data.php` vs `apiAuthPosContext`).
- `get_transactions` del MCP trata `to` como exclusivo — pedir un solo día
  parecía "no hay ventas hoy" y era el reporte, no los datos.
- Aprobé el recorte del query string en `isItemActive` creyendo que solo
  afectaba al palette — rompió las 3 entradas de Contactos en el sidebar
  (`?type=`) en prod. Arreglado al toque.
- Acoté el alcance por sucursal al realm `api` y excluí el panel a
  propósito, cuando el owner ya había fijado la regla general para ambos.
  Corregido al día siguiente.
- Dos veces un agente cortó antes de correr `code-reviewer` (alcance `api` y
  `panel`); forzarlo encontró un P0 real las dos veces (`VIEW_OUTLET_ID=''`
  reinterpretado como tenant entero; `X-Outlet-Id` en `localStorage` dejando
  el panel sin salida ante 403). **El reviewer no es opcional en cambios de
  aislamiento — verificar que corrió, no solo que el agente dice que sí.**

## Próximo paso

Nada quedó a medias operativamente. Lo más barato para arrancar es una
decisión chica y pendiente: `Roc::build` ya no filtra por `registerId` (solo
Outlet y Company) pero el nombre sigue sugiriendo que sí — sumar la R
(param opcional, ~5 líneas) vs. renombrar (42 call-sites, PHP no avisa los
que queden desactualizados). Ninguna urge hoy porque nada reporta por caja.

## Trampas conocidas

- **Sin backfill del histórico de fecha** — ventas guardadas antes de
  `6f94043c` con la hora corrida 3h siguen así en la BD, decisión explícita
  del owner.
- **Cloudflare "Block AI bots" en `punto.la` sigue desactivada A MANO**,
  fuera del repo. Si se reactiva, el MCP muere con "Couldn't reach Punto"
  sin que el síntoma señale a Cloudflare.
- **Flags de comercio system-wide que no deberían serlo** (observación del
  owner, sin plan escrito): `stockCountBlind`, `stockCountRecordOnly`,
  listas de conteo, `settingDrawerTolerance`, `drawerRequireClosedOrders`,
  `blockUsedDocNo`, `autoSendDocs` aplican a TODAS las sucursales por
  igual hoy. Regla que quiere escrita: antes de sumar un flag, decidir si
  afecta a todas las sucursales por igual o solo a algunas. Necesita doc
  propio antes de tocar código.
- **Decisión pendiente: reconocimiento facial vs QR para asistencia** — cara
  on-device (privado, offline) vs. servicio externo (más preciso, manda
  biometría a un tercero). Sin decidir.
- El default `acquire: true` de `claim.php` es compatibilidad TRANSITORIA
  con bundles viejos del POS — sacarlo cuando no queden clientes previos al
  2026-09-01.
- `realtimeAfterMutation()` (`api/bootstrap.php`) corre DENTRO de
  `apiAuthTenant()` al ENTRAR la request, antes de que el handler mute nada
  — publica igual si después falla. Se esquivó puntualmente para
  `register-lease`; sigue afectando a todas las demás entities.
- Recurrentes: `psql`/SSH a la BD bloqueados por el classifier; `npx vitest`
  desde la raíz falla, correr desde `frontend/`; no confundir horas sin
  convertir a UTC (Paraguay es UTC−3).
