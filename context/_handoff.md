# Hand-off — 2026-08-21

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar la anulación de venta / nota de crédito (`context/40`, plan cerrado
2026-08-14, sin implementar hasta ahora), auditar el estado real de la
facturación electrónica contra la API de Factomate, y resolver por qué
`report_rollup` seguía vacío después de semanas de features que dependían de
él.

## Estado al cerrar

`main` en `4b5a7bae`, todo pusheado, working tree limpio.

- **Anulación + NC (F1-F6 de `context/40`)**: implementado y mergeado.
  `SaleVoidService` con flag `voidedat`/`voidreason`/`voidedby` (mig 154, NO
  pisa `transactiontype` — el número de factura queda usado), ventana 48h
  server-side, rechazo si hay devoluciones o recibos vigentes, reposición de
  stock línea por línea decidida por el cajero + merma (`waste_event`) para
  lo que no vuelve, cancelación SIFEN con rollback completo si la rechaza.
  Mig 155 excluye anuladas de los rollups (`SaleFilters::notVoidedSql()` en
  ~15 services). D2/D3 de la devolución: `StockReversalPolicy` extraído
  compartido entre `SaleVoidService` y `ReturnService`, `returnOptions` en
  `GET /v1/returns.php`, settings `settingReturnRefund` y
  `settingReturnAllowIngredientReversal`. F5 (NC electrónica) ya estaba
  implementada, se verificó punta a punta. UI: diálogo de anulación +
  `PosReturnSheet` reusado, en `/pos`.
- **Auditoría de facturación electrónica**: completa, 5 agentes en paralelo
  (motor de emisión, mapper/payload SIFEN, onboarding, POS/impresión/offline).
  Informe: https://claude.ai/code/artifact/f34333b8-0dc4-4665-bbe3-dc3c52479550
  Verificado en prod: 0 emisores, 0 documentos, 0/12 cajas con timbrado.
  Se resolvió la pregunta abierta 6 de `context/28` (el admin de Factomate
  autentica solo con `/Token`, sin `PhoneLogin`) contra credenciales DEV
  reales que cargó el owner.
- **Jobs de mantenimiento**: resuelto. `pg_cron` no existe en
  `postgres:18-alpine` ni en ninguna imagen que ofrece Coolify (salvo
  Supabase, otra versión mayor) — las migs 36/138 lo asumían con fallback
  tolerante, por eso nadie notó que nunca corrió. `report_rollup` estaba
  VACÍO con 134 períodos sucios y el drainer de FE tampoco corría. Solución:
  `crond` de BusyBox dentro de la imagen del API + `api/v1/maintenance.php`
  (sin `apiAuthTenant`, gate por `EINVOICE_DRAIN_SECRET`, advisory lock por
  job) + crontab versionado. Desplegado y verificado: `report_rollup` pasó a
  1.209 filas, cola en 0.
- **POS**: toggle de pantalla completa en `/pos/espacios` y `/pos/ordenes`
  (oculta el carrito, solo desktop, `/pos` no cambia de layout). Fix de
  seguimiento: las grillas eran columnas fijas por breakpoint y agrandaban
  las celdas en vez de mostrar más — pasadas a `auto-fill` con ancho fijo
  (8.5rem espacios, 17rem órdenes). Dos componentes migrados del legacy a
  shadcn: popup del mapa de órdenes (`setHTML` string → React con
  `createRoot`) y `seller-picker-dialog.tsx` (→ `Command`+`Avatar`).
- **Planificación**: `context/47-reportes-personalizados-y-export.md` y
  `context/48-escalamiento-de-datos.md` (D1-D9) nuevos, sin implementar.

## Archivos y cambios

- `api/lib/services/SaleVoidService.php`, `api/lib/services/StockReversalPolicy.php`
  — anulación + política de reposición compartida con `ReturnService`.
- `api/database/migrations/postgres/154_sale_void.sql`,
  `155_rollup_exclude_voided_sales.sql`.
- `api/docker/cron/crontab`, `api/v1/maintenance.php` — jobs de
  mantenimiento (rollup, drainer FE, purgas) vía `crond` de BusyBox.
- `frontend/lib/pos/workspace-store.ts` — store del toggle de pantalla
  completa, persistido en localStorage.
- `frontend/components/pos/seller-picker-dialog.tsx` — reescrito con `Command`.
- `context/40-anulacion-y-nota-credito.md` — F1-F6 implementadas (era plan
  cerrado sin código).
- `context/28-facturacion-electronica-plan.md` — pregunta abierta 6 resuelta.
- `context/47-reportes-personalizados-y-export.md`,
  `context/48-escalamiento-de-datos.md` — planes nuevos, sin implementar.
- `context/10-roadmap.md` — anotado separar la entidad fiscal del contacto.

## Callejones sin salida

1. **Verificaciones contra el contenedor equivocado.** El contenedor
   `api-asqhqb6vb5yerc532ls0vql9` NO es Punto (es Node/Prisma, otra app). El
   de Punto tiene PHP: identificarlo con
   `for c in $(docker ps --format '{{.Names}}'); do docker exec $c sh -c 'command -v php'; done`
   → hoy `z645wx54kwtcciczaeoldwvc-*`, con Postgres `w6rtfxm2n6l45r4r9melj3hl`
   (no `postgres-asqhqb*`). Se perdió tiempo concluyendo "no hay env vars de
   FE en prod" mirando la app equivocada.
2. **La discrepancia de montos en `/reports/open-invoices` no se pudo
   reproducir.** El listado (`OpenInvoicesService::general`) y el detalle
   (`contactStatement`) corren la MISMA query con el mismo id, sin filtros de
   fecha/sucursal; contra la base actual ninguno de los dos números del
   screenshot del owner reprodujo. Se encontraron 4 contactos con el mismo
   RUC 4908128-4 con la deuda repartida entre 3 — pero un RUC repetido es
   LEGÍTIMO (50 personas pueden facturar a la misma empresa), no son
   duplicados a fusionar: el modelo mezcla "quién compra" con "a quién se
   factura". Sin resolver; si reaparece, pedir ambas capturas con hora.
3. Un agente se colgó construyendo la imagen Docker en el server (build
   largo en foreground, el watchdog lo mató a los 600s) y dejó su trabajo
   staged sin commitear en el worktree — se rescató a mano. Builds largos en
   el server: lanzar con `nohup ... &` y consultar el log, no foreground.
4. Comentario JSX suelto en una rama de ternario rompe el build
   (`{/* ... */}` seguido del elemento) — va DENTRO del elemento.
5. `classifyLine` recibía `CaseInsensitiveArray`, no `array` — TypeError que
   solo apareció corriendo el arnés real contra Postgres; `php -l` no lo
   detecta.
6. Docker no levanta en la máquina del owner. Todos los arneses corrieron en
   el servidor 167.71.165.221 con Postgres descartable. Queda ahí la imagen
   `punto-php-test` (778 MB, php:8.4-cli + pdo_pgsql) reutilizable.

## Próximo paso

E1 del plan `context/48-escalamiento-de-datos.md` (particionado por mes de
`transaction`/`itemsold`, columnas nuevas en `itemsold`, cierre de período,
grano nuevo del rollup) — conviene hacerlo ya porque las tablas están chicas
(723 y 1.029 filas) y después duele. Antes de arrancar, el owner tiene que
cerrar la decisión abierta: qué es "inmutable" en el cierre de período (solo
el hecho económico, o también el estado de cobranza `transactioncomplete` —
esto son 26 archivos PHP + 6 del frontend).

## Trampas conocidas

- **Bloqueante y del owner**: ninguna caja tiene timbrado cargado (0/12) —
  sin eso `provision()` aborta y no se puede probar facturación electrónica
  end-to-end. Se carga en Sucursales → Cajas.
- Una venta con vale canjeado NO se puede facturar: la línea del vale suma
  al total de ítems pero no a los pagos, y el guard de cuadratura la rechaza
  (falla cerrado, no es bug de datos).
- `measurementUnitCode` fijo en 0 en el payload SIFEN; tipos de documento
  14/17 (comprador extranjero) sin mapeo.
- El ticket impreso NO es un KuDE — falta CDC, QR fiscal real y leyenda
  legal (el QR que imprime hoy es el link al portal propio, no el de SIFEN).
- El listado de transacciones no muestra badge "Anulada" porque `mainList`
  no expone `voidedAt` todavía.
- La rama `claude/silly-ramanujan-b7433c` sigue sin mergear y es obsoleta;
  8 `stash-backup/*` sin revisar.
- `frontend/public/sw.js` había vuelto a aparecer modificado en el checkout
  (artefacto de build colado en un commit, revertido en `f4240de3`) — si
  reaparece modificado sin que vos lo hayas tocado, no commitearlo.
- Credenciales de Factomate DEV (`FACTOMATE_ADMIN_USERNAME_TEST`,
  `FACTOMATE_ADMIN_PASSWORD_TEST`) y `EINVOICE_DRAIN_SECRET` están en
  Coolify, cargadas a mano por el owner — no están en el repo ni hay que
  anotar los valores.
