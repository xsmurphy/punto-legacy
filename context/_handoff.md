# Hand-off — 2026-09-01

> Sesión EN CURSO al momento de escribir esto. Hay un agente trabajando en la
> branch `frontend/tenencia-caja-explicita`, sin mergear. Ver "En vuelo".

## Objetivo

Cerrar el reporte del owner: en `/settings/devices`, tomar una caja en un
dispositivo, cerrarla y habilitarla en otro dejaba a los dos sin poder
facturar. Al investigar aparecieron tres defectos encadenados alrededor de la
tenencia de caja (`register_lease`, context/29), no uno.

## Estado

`main` en `7baa407e`, **deployado y verificado**: Front (`rx1vdwjnifvofqs0l5qx78uj`)
y Backend (`8w1yfziv8plz6qoyzpiawmft`) en `finished`, las dos apps
`running:healthy`. Ese deploy arrastró también los 5 commits que la sesión
anterior había dejado sin subir (3 de `frontend/`, 2 de `api/`) — ya no hay
nada pendiente de deployar de antes.

Como la mig 183 corre al arrancar el contenedor del backend, que levante
`running:healthy` ES la evidencia de que aplicó (psql/SSH siguen bloqueados
por el classifier, no se verificó por consulta directa).

## Lo que YA está en producción

**Un dispositivo = una sesión.** `DeviceAuth::buildToken()` revoca las
sesiones activas del device antes de emitir la nueva. Va en `buildToken()` y
no en los métodos de emisión: es el único punto por el que pasan las TRES
vías (`issueDeviceToken`, `createDeviceAndIssueToken`,
`issueTokenForExistingDevice`). Antes cada pareo/reconexión APILABA una
sesión y la del device es eterna (`expiresat` NULL), así que nada las cerraba
— el tenant ICAS tenía un device con 4.

**Mig 183** (`183_una_sesion_activa_por_device.sql`) limpia lo ya encolado:
revoca todo menos la más reciente por `deviceid`. No fuerza re-parear ningún
aparato remoto.

**Tenencia visible en `/settings/devices`.** `GET /v1/devices` devuelve
`holdsRegister` + `registerHeldBy`; la columna Caja muestra "En uso acá" /
"En uso por X". El contador de sesiones pasó a badge de anomalía: con el
invariante nuevo, ver más de 1 es una regresión, no un dato.

## En vuelo — branch `frontend/tenencia-caja-explicita`

Agente corriendo en worktree aislado, 4 fases, SIN mergear ni deployar:

- **F1 — el latido confirma, no adquiere.** ESTE es el bug principal del
  reporte. `api/v1/register/claim.php` hace "confirmá O TOMÁ" en la misma
  llamada, y `frontend/hooks/use-register-claim.ts` lo dispara cada
  `HEARTBEAT_MS` (5 min) incondicionalmente. Un POS abierto en esa caja se la
  vuelve a tomar solo apenas se libera: el otro device solo gana si su latido
  cae en la ventana entre la liberación y el próximo latido del okupa. Fix:
  flag `acquire` en el body; latido/`online`/evento realtime/montaje →
  `acquire:false` (solo confirma, nunca inserta). Adquirir queda para dos
  actos: el control nuevo de F4 y el drenaje de la cola offline.
  `acquire` default `true` por compatibilidad con bundles viejos — transitorio.
- **F2 — liberar avisa a todos.** Emitir la entity `register-lease` desde
  `RegisterLeaseService::close()`, el choke point de las cuatro vías de
  liberación. Y al crear tenencia en `claim.php`.
- **F3 — no se borra un device con historial operativo.** Ver "Trampas".
- **F4 — control explícito "Tomar caja"** en el POS cuando la caja está libre
  y este device no la tiene.

## Decisiones del owner cerradas hoy — NO relitigar

1. **Un dispositivo = una sesión**, en todos los módulos (pos/screen/kds/
   display/print). Múltiples sesiones encoladas no tienen sentido.
2. **Crear dispositivos es libre.** Varios devices pueden tener la MISMA caja
   asignada y está bien. Lo único exclusivo es FACTURAR. Se propuso bloquear
   el pareo de una caja ya tomada y el owner lo RECHAZÓ: el control de
   dispositivos existe para administrar aparatos remotos (un KDS a 400 km),
   no para restringir el alta.
3. **El bloqueo de facturación es por CAJA, no por dispositivo.**
4. **El cajero toma la caja EXPLÍCITAMENTE.** Cuando se libera, el otro device
   NO la toma solo — nada de adquisición automática por timer ni por evento.
5. **Un device con historial operativo no se elimina**, porque deja algo
   huérfano.

## Callejones sin salida

- Perseguí varias hipótesis falsas antes de dar con la buena: los caminos de
  LIBERACIÓN (revoke, unpair, cerrar caja, cambio de caja) están TODOS bien
  implementados y cubiertos — el agujero no estaba ahí. Tampoco era el drawer,
  ni el `ShiftCloseGate`, ni el casing de `register_lease` (la mig 150 la
  normalizó a minúsculas y las queries están bien). El problema es de
  ADQUISICIÓN, no de liberación.
- Afirmé que ningún camino emitía `register-lease` — falso, y la corrección
  importa: `/v1/register-lease` (el "Liberar caja" del panel) SÍ lo publica,
  por el default de `realtimeAfterMutation()` (`api/bootstrap.php:538`, deriva
  la entity del path). Los que no avisan son los otros cuatro, porque publican
  `drawer`/`device`/etc. según su propio path. Por eso el fix va en `close()`
  y no en cada endpoint.
- Sospeché que la mig 183 dejaría sesiones vivas en cache: falso. El cache de
  `auth_session` es un stub sin implementar (`_authCacheGet` siempre devuelve
  null, `_authCacheDel` es no-op, `api/includes/auth_session.php:454`).

## Próximo paso

Revisar el diff de la branch en vuelo (el owner pidió explícitamente que se
revise, no solo el reporte del agente), mergear con `--no-ff`, borrar branch +
worktree, y deployar Front y Backend.

## Trampas conocidas

- **El hard delete de un device orphanea en silencio.** `register_lease` es la
  ÚNICA tabla con FK dura a `device` (mig 141, `NOT NULL` sin `ON DELETE`), y
  por eso es la única que tira error — el owner lo vio como
  `SQLSTATE[23503] register_lease_deviceId_fkey`. Pero `auth_session.deviceid`
  (mig 69), `station_printer.deviceid` (mig 83) y `pos_order_event.actor_id`
  (mig 85) llevan el id SIN FK y hoy quedan huérfanas sin avisar. NO agregar
  CASCADE ni SET NULL: `register_lease` dice qué aparato tenía qué caja cuando
  se emitió cada factura. (`printer_binding.bluetoothdeviceid` de la mig 61 NO
  cuenta: es una MAC de Bluetooth.)
- `pos_order_event.actor_id` no distingue si el actor es un usuario o un
  device — hay que comparar contra el deviceid directamente.
- El badge de sesiones en `/settings/devices` usa `title=` nativo en vez del
  `Tooltip` de shadcn. Menor, pendiente para la próxima pasada por esa
  pantalla.
- Cloudflare "Block AI bots" sigue desactivada A MANO en la zona `punto.la`,
  fuera del repo. Si alguien la reactiva, el conector MCP muere con "Couldn't
  reach Punto" y el síntoma no señala a Cloudflare por ningún lado.
- El catálogo del MCP se cachea del lado del cliente: tras cambiar tools hay
  que reconectar el conector.
- El asistente de la caja NO responde hasta que el tenant tenga créditos IA en
  `/admin` → Empresas → Créditos IA (saldo 0 es literal, no bug).
- `tenant_audit` atribuye las escrituras del asistente del POS al contacto que
  pareó la tablet, no al operador del PIN — P1 pendiente.
- D9 de `context/59` sin implementar: `/v1/reports/drawers` no scopea por caja
  ni chequea permisos; `get_drawers` quedó fuera del catálogo como mitigación.
- `context/62-dashboard-operaciones.md` es plan con D1-D9 PROPUESTAS sin OK del
  owner — no asumir ninguna cerrada.
- Plan de compras en el POS (alcance ya cerrado: solo cargar, mismo impacto en
  stock que el panel) sigue sin escribirse.
- Recurrentes: no culpar al deploy sin comparar horas en UTC (Paraguay es
  UTC−3); `npx vitest` desde la raíz del repo falla, correr desde `frontend/`;
  `psql`/SSH a la BD bloqueados por el classifier.
