# Hand-off — 2026-07-28

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. El historial está en [_session-log.md](_session-log.md).

## Objetivo

Cerrar el circuito de **fulfillment de órdenes**: que cada pedido diga siempre a dónde
va (espacio, mostrador, retiro o envío) en todas las superficies donde alguien lo lee —
carrito, KDS, pantalla de despacho, listado y comanda impresa. El disparador fue que el
mapa de `/pos/ordenes` mezclaba pedidos que no eran envíos y que las comandas mostraban
el nombre del espacio pelado ("2"), sin decir que era un espacio.

## Estado al cerrar

**Deployado** (pusheado a `main`, Coolify deploya solo): F-D-0 completo — columna
`fulfillment` (mig 94), snapshot congelado de la dirección de envío, selector en el
carrito, mapa filtrado por `delivery`, destino explícito en KDS/despacho/listado/comanda,
columnas Origen+Tipo en la vista lista, detalle de orden con formato de transacción y
dropdown de estado, board de 3 columnas en `/display`, y el fix de `validateHttp`.

**Commiteado sin pushear**: `5628cbce` (link "Abrir" a la pantalla de cada dispositivo en
`/settings/devices`) y la consolidación de la skill `/end-session`.

**Sin empezar**: F-D-1 (`out_for_delivery`, `courierid`, vista de despacho por repartidor)
y F-D-1a (costo de envío como ítem del catálogo). Plan en
[27-delivery-sla-plan.md](27-delivery-sla-plan.md) §B.5.

## Archivos y cambios

- `api/lib/App/Helpers/Validation.php` — `isValid()` ya no descarta números negativos.
- `api/lib/Orders/OrderCoreService.php` — `fulfillment` + snapshot de destino en `create()`,
  filtro en `list()`, campos nuevos en `presentOrder()`.
- `api/lib/services/CustomerAddressService.php` — `add()` devuelve el id creado.
- `frontend/lib/orders/order-display.ts` — fuente única de destino/estado: `orderDestination`,
  `orderSourceLabel`, `orderFulfillmentLabel`, `STATUS_ACCENT`, `ORDER_TRANSITIONS`.
- `frontend/hooks/use-order-actions.ts` — Cobrar/Reimprimir/Cancelar compartidas.
- `frontend/hooks/use-form-tab-errors.tsx` — salto a la tab con error al guardar.
- `frontend/app/(screen)/display/` — board de 3 columnas (`display-card`, `display-column`).

## Callejones sin salida

- **El PIN de la sucursal en el mapa NO era un bug del mapa.** Se revisó el componente, el
  bootstrap y el store sin encontrar nada. La causa estaba tres capas más abajo: en
  `Validation::isValid()`, el guard `Arr::sizeOf($value) < 0.00001` recibe el NÚMERO cuando
  el valor es numérico, así que rechazaba en silencio todo negativo — y Paraguay tiene lat
  y lng negativas. Ninguna coordenada llegó nunca a la BD. Lección: ante "el dato no
  aparece", verificar en la BD antes de leer el componente que lo pinta.
- **Dos agentes escribiendo sobre el mismo checkout se pisan.** Ya causó un P0 en `main` en
  la sesión anterior. Los agentes en paralelo van con `isolation: "worktree"`.
- **Lint + build no alcanzan como gate.** Los tres sub-agentes de esta sesión pasaron ambos
  y aun así dejaron: un id de dirección deducido por heurística (mandaba el pedido a otra
  dirección con dos altas concurrentes), estado de envío colgado al cambiar de cliente, y
  los mismos tres handlers duplicados byte por byte en dos componentes. Revisar el diff.

## Próximo paso

Pushear `5628cbce` y la skill consolidada. Después, **recargar a mano las coordenadas de
las 5 sucursales** desde `/outlets/[id]` → tab Ubicación: el fix habilita el guardado pero
los valores viejos nunca se persistieron.

## Trampas conocidas

- `outlet.lat`/`outlet.lng` están en NULL en producción para las 5 sucursales (verificado en
  la BD). Hasta recargarlas, el mapa de `/pos/ordenes` no muestra el PIN del local.
- La mig 94 corre sola en el boot; no requiere acción manual.
- El chip del carrito dice "MOSTRADOR" para `dine_in` y la columna Tipo de la vista lista
  dice "Local". Es deliberado (en la tabla, Origen ya dice "Mostrador"), no un descuido.
- `frontend/public/sw.js` se regenera con cada `npm run build` y aparece siempre modificado.
