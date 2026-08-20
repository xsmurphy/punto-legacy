# Hand-off — 2026-08-20

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar el P0 fiscal de numeración de `context/29` (arriendo de bloques +
exclusividad de caja para que dos dispositivos sobre la misma caja no
dupliquen número de factura offline), y responder al reporte de un tester
externo con una batería de bugs de producto (catálogo por sucursal, costo de
producción, cuentas por cobrar, pago a proveedor, reportes de productos).
En el camino se abrió un incidente de producción (catálogo caído) que llevó
a erradicar un bug de fondo del schema (columnas camelCase entrecomilladas).

## Estado al cerrar

`main` en `737c0bb2`, **todo pusheado**. Deploy confirmado funcionando: el
owner verificó que puede entrar al POS y ver productos tras el incidente.

- **P0 numeración**: el owner corrigió el modelo de raíz a mitad de sesión —
  el punto de expedición es único por `sucursal-caja` + timbrado, no un
  recurso compartido. El arriendo de bloques (F0-F3) se construyó completo
  y quedó **RECHAZADO y eliminado**. Se conservó la tenencia de caja
  (`register_lease`, un dispositivo por caja) pero corregida: solo bloquea
  **facturar**, no el acceso al POS (se puede cotizar/tomar órdenes sin
  tenerla). `context/29` está reescrito entero como modelo canónico, con
  §6 de arquitecturas rechazadas.
- **Normalización camelCase**: mig 150 corrida en prod, 143 columnas de 18
  tablas pasadas a lowercase, verificador ampliado. Cerrado.
- **Incidente prod**: resuelto y con guardrails nuevos (guard de dimensiones
  del device POS, hotkeys huérfanos, verificador de columnas por
  interpolación). Cerrado.
- **Reporte del tester**: la mayoría de los ítems resueltos y mergeados
  (ver bitácora `f2e48b70..737c0bb2` para el detalle commit por commit).
- **Pendiente sin cerrar**: hay un agente que quedó corriendo implementando
  el split de gasto por categoría en compras, branch
  `api/categoria-obligatoria-finanzas` (`28cdc216` y `55466064` ya
  mergeados a esa branch). Falta que una compra con líneas de categorías
  distintas divida el gasto en varios movimientos, prorrateando descuento
  e impuestos sin perder centavos por redondeo. **Mergear esa branch es lo
  primero al retomar.**
- Branches sin mergear además de esa: `claude/silly-ramanujan-b7433c`
  (obsoleta, su fix ya está resuelto por otro camino — se puede borrar) y
  8 `stash-backup/*` (trabajo viejo sin revisar, varias del POS en Alpine.js
  que ya no existe — revisar antes de borrar, no descartar a ciegas).

## Archivos y cambios

- `context/29-numeracion-y-exclusividad-de-caja.md` — reescrito entero,
  modelo canónico + §6 arquitecturas rechazadas. Es la referencia para
  cualquier trabajo futuro de numeración — no reintroducir el arriendo.
- `api/database/migrations/postgres/145_*.sql` — índice único
  `uq_transaction_expedition_invoiceno` (punto de expedición + timbrado +
  correlativo). Filtra `transactiontype IN (0,3)`: cotización y recibo NO
  están cubiertos (ver Trampas).
- `api/database/migrations/postgres/150_*.sql` — normaliza 143 columnas
  camelCase-quoted a lowercase; recrea 2 triggers + 1 job `pg_cron` que
  tenían nombres de columna hardcodeados como texto.
- `api/database/migrations/postgres/151_*.sql` — limpia tenencias huérfanas
  de `register_lease` que quedaban colgadas al mover un dispositivo de caja.
- El fix del incidente: `ci.data->>'itemUOM'` en vez de `ci.itemUOM`
  (commit `75e1cf2c`) en el LATERAL de composición de combos del catálogo.
- Verificador de identificadores (harness, corre en `run.sh`) — ahora
  también detecta `alias.columna` inexistente (no solo comillas), incluida
  SQL armada por interpolación (antes solo leía strings literales).
- `context/10-roadmap.md` — reporte del tester anotado y procesado.

## Callejones sin salida

1. El incidente del catálogo se diagnosticó mal **tres veces** (service
   worker, filtro por sucursal, tenencia de caja) antes de encontrar la
   causa real corriendo la query del catálogo contra la BD directamente.
   Lección: con "no llega ningún dato", correr la query antes de teorizar.
2. Se construyó el arriendo de bloques completo (F0-F3) y el owner lo
   rechazó después de verlo. Se pudo evitar preguntando antes por el modelo
   del punto de expedición — no asumir el diseño de un doc de plan sin
   confirmarlo si toca un invariante fiscal.
3. F2 de exclusividad se mergeó sin verificar que el frontend llamara al
   endpoint `claim.php`. Nadie lo invocaba: `register_lease` quedó vacía y
   `holderConflict()` rechazaba TODA venta con 409 en producción. El arnés
   probaba el endpoint directo, no el flujo real del POS.
4. Editar una migración ya aplicada no tiene efecto — el runner registra el
   nombre en `schema_migrations` y no la re-ejecuta. Hubo que corregir la
   mig 148 con una mig 149 nueva.
5. Docker no levanta en la máquina del owner (RAM al límite con varias
   sesiones/worktrees abiertas). Ninguna verificación corrió localmente en
   toda la sesión — se resolvió corriendo el arnés en el servidor contra un
   Postgres descartable, imagen efímera `php:8.4-cli` + Node 22.
6. La branch de normalización rompía el bootstrap desde cero (migraciones
   inmutables 29/35/36 crean índices sobre columnas camelCase que
   `db-schema-postgres.sql` ya había cambiado a lowercase). Solo se detectó
   por verificar en el servidor antes de mergear — en prod no se habría
   notado hasta montar un entorno nuevo.
7. Tres colisiones del número de migración 144 entre branches paralelas.
   Mirar el remoto antes de numerar una migración nueva, no solo el checkout.

## Próximo paso

Mergear `api/categoria-obligatoria-finanzas` a `main` (split de gasto por
categoría en compras multi-línea) — es el único trabajo en curso sin cerrar.
Revisar el estado del agente que quedó corriendo antes de retomar manualmente.

## Trampas conocidas

- **Ningún register tiene el timbrado cargado.** Es tarea operativa del
  owner, ningún código lo resuelve. Hasta que lo haga, toda factura sale
  con timbrado vacío y los bloques `auth_number`/`auth_expiration` de las
  plantillas quedan en blanco.
- El índice único de correlativo (mig 145) **no cubre cotización ni
  recibo** — el índice filtra `transactiontype IN (0,3)` porque cotización
  comparte el campo `invoiceno` con su propia secuencia. No es regresión,
  pero el invariante fiscal no está cerrado para esos dos documentos.
- El owner tiene **más dispositivos que cajas**; con la exclusividad activa,
  cada dispositivo necesita su propia caja (además de ser lo que exige el
  modelo fiscal).
- Dos casos de `ProductionService` en el arnés quedaron como avisos no
  bloqueantes hasta confirmar si el error está en el código o en el test.
- El filtro de categoría en Artículos cubre solo la categoría principal, no
  las secundarias.
- Docs con número duplicado en `context/`: 14, 15, 16, 22, 30, 42 (el 40 ya
  se corrigió → `46-reportes-fiscales-plan.md`). No asumir que el número
  identifica un solo doc al buscar por prefijo.
- `frontend/public/sw.js` sigue modificado sin commitear en el checkout
  compartido — artefacto de build, arrastrado de sesiones anteriores.
