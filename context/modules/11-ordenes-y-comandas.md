# 11 — Órdenes y comandas

> Estado del doc: verificado contra código 2026-08-17
> Responsable de la última verificación: sesión 2026-08-17 (este doc)

## 1. Qué resuelve

Registra lo que se preparó y entregó — mostrador, mesa, ecommerce o agenda —
independientemente de si ya se cobró. Es el objeto operativo que la cocina
(KDS) y el panel de órdenes consumen; alimenta las comandas por estación y,
opcionalmente, termina convergiendo en una venta.

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `pos_order` | La orden: `status` (ciclo de preparación), `source`, `spacesessionid`, `saletransactionid`, `fulfillment`. | `saletransactionid` (pago) y `status` (ciclo de vida) son **ORTOGONALES** — una orden puede nacer pagada y seguir `sent`/`in_progress` (`OrderCoreService.php:14-18, 72-78`). `closed` es terreno EXCLUSIVO de `markPaid()` salvo que ya tenga `saletransactionid` (entonces `delivered→closed` vía `updateStatus()` también vale) — `OrderCoreService.php:72-78`. |
| `pos_order_item` | Ítem de la orden: `status` propio, `stationid`, `course`, y desde mig 139 `parentorderitemid`/`addonoptionid`/`pricedelta` para add-ons. `tags` (mig 135). | Una línea hija (`parentorderitemid` no null) **NO tiene ciclo propio** — viaja con su padre; `updateItemStatus` la rechaza explícitamente si se intenta mover sola (`OrderCoreService.php:908-919`). Las hijas van con `price=0`; el recargo vive en `pricedelta` (informativo, no se suma a ningún total — ver regla 3) — `OrderCoreService.php:473-499`. |
| `pos_order_event` | Timeline de transiciones (`scope`, `fromStatus`/`toStatus`, `reason`). | Solo viene en el detalle (`find()`), no en `list()` — `use-orders.ts:103-120, 181-188`. `reason` es obligatorio en cancelación, exigido por el backend, no solo por la UI. |

## 3. Reglas de negocio

1. **Orden ≠ venta.** `transaction` se crea SOLO al cobrar (`SaleService`,
   fuera de este módulo); `markPaid()` únicamente deja el rastro
   (`saletransactionid`) una vez que YA se cobró — `OrderCoreService.php:14-18`.
2. **"Orden en venta" nace pagada y ACTIVA** — el fix `675a4608` (2026-08-03)
   corrigió que naciera cerrada e invisible. Antes: `handleOrdenar()` creaba
   la orden `sent` y la cerraba con `markOrderPaid()`
   (`status='closed'`); `ACTIVE_ORDER_STATUSES` excluye `closed`
   (`use-orders.ts:246-253`), así que la orden quedaba pagada pero invisible
   para panel y cocina. Ahora `create()` acepta `transactionId` opcional y lo
   persiste en el MISMO INSERT — un solo write, sin ventana impaga ni ventana
   cerrada-antes-de-producir (`OrderCoreService.php` diff `675a4608`,
   comentario `pay-dialog.tsx:991-999`).
3. **HALLAZGO — el gap de add-ons en órdenes documentado en el plan YA
   SE ARREGLÓ, pero solo hasta el momento de cobrar.** El commit `46ac668f`
   ("feat(ordenes): add-ons en la orden — líneas hijas y comanda de cocina",
   2026-08-17 00:13 — horas antes de esta verificación, DESPUÉS de que
   `context/modules/02-combos-y-addons.md` se dio por verificado el
   2026-08-16 23:51) cerró dos de los tres huecos que ese doc y
   `context/41-addons-y-combos.md` seguían marcando como abiertos:
   - `CreateOrderItemInput` SÍ tiene `selections` hoy (`use-orders.ts:191-208`,
     comentario explícito "MISMO shape que la venta").
   - `OrderCoreService::create()` valida las selecciones server-side
     (`AddonService::validateSelections`, antes de abrir la TX,
     `OrderCoreService.php:273-307`) y persiste una línea hija por opción
     elegida (`parentorderitemid`/`addonoptionid`/`pricedelta`,
     `OrderCoreService.php:473-525`) — el comentario del INSERT se
     autoetiqueta *"context/41, gap 1 de F5"*.
   - La comanda de cocina (`buildOrderTicketData`,
     `frontend/lib/orders/print-comandas.ts:48-96`) y el board del KDS
     (`screenItems`/`addonChildrenOf`, `frontend/lib/kds/board.ts`,
     consumido en `order-card.tsx:339-350`) SÍ renderizan las hijas indentadas
     — a diferencia del ticket fiscal (D3), la comanda lista TODAS, cobren o
     no (comentario `print-comandas.ts:50-56`).

   **El paso de COBRO quedó cerrado en dos tandas** (2026-08-23 y
   2026-08-25). Hasta la primera, los loaders del carrito reconstruían la
   orden excluyendo las hijas y SIN re-hidratar `selections` en el padre: la
   venta cobraba el monto correcto (el delta ya estaba en `unitPrice` desde
   que se creó la orden) pero **`expandAddonSelections` nunca corría** — sin
   línea hija de `itemSold`, sin descuento del stock de la opción elegida
   (`OrderCoreService::create()` no toca stock — **ninguna orden lo hace**, es
   consecuencia de la regla 1; ver regla 8 y `context/53`) y sin el dato para
   indentar el add-on en el ticket. Plata correcta, inventario y trazabilidad
   rotos.

   `rebuildSelectionsFromOrder` (`frontend/lib/cart/store.ts`) es el puente:
   despeja el precio base del padre con el `priceDelta` CONGELADO en la orden
   y devuelve las selecciones re-cotizadas contra el catálogo vigente. La
   primera tanda la cableó solo en `loadFromOrder`, así que cobrar una MESA
   siguió regalando el add-on del inventario dos días más. La segunda extrajo
   `cartLinesFromOrderItems` —una sola definición de "cómo una orden vuelve al
   carrito", compartida por `loadFromOrder` y `loadFromSession`— y sumó el
   split por ítems (`buildItemsLines`, `lib/spaces/settlement-lines.ts`), que
   ancla el precio al PERSISTIDO porque el pago del ledger se calcula desde
   ahí. **Lo que sigue sin descontar stock: el split por `amount`/`share`** —
   decisión explícita, con las tres razones en el docblock de
   `buildProportionalLines` (qty fraccionada vs. `CartLineAddon.qty` entero,
   recargo que no se prorratea, y N parciales que descontarían N veces). Se
   cierra con la misma solución de raíz que el hueco del ítem prorrateado en
   general, no con una reconstrucción a medias.

   Verificado de punta a punta, sin mocks: la mitad de front en
   `frontend/lib/cart/__tests__/addon-rebuild-paths.test.ts` (los tres
   caminos, la qty por unidad del padre, los fail-safes) y la mitad de back en
   `api/lib/Sales/verify_chain/verify_addon_stock.php` (venta real: hija de
   `itemSold`, ledger de stock movido por optQty × unidades del padre, recargo
   repartido sin duplicar, detalle con `type='addon'` para que el ticket
   indente).
4. **Secundario — la orden "espejo" de `ordenEnVenta` tampoco manda
   `selections`.** `handleOrdenar()` (botón manual "Ordenar" post-venta)
   arma los ítems de la orden espejo desde `orderDraft.lines` sin incluir
   `selections` — `pay-dialog.tsx:1004-1011`. La venta que originó el espejo
   sí cobró el add-on correctamente (esa sí pasó por `expandAddonSelections`
   normal), pero la orden espejo creada después nace SIN desglose de
   add-ons, aunque el mecanismo ya existe (regla 3). Contraste directo con
   `handleOrderClick` de `cart-panel.tsx:341-348` (el flujo normal de
   "Ordenar" desde el carrito), que SÍ manda `selections`.
5. **Bidireccional hasta `ready` (KDS recall/deshacer, 2026-07-27).**
   `ready → preparing` ("devolver a preparación") y `preparing → pending`
   ("deshacer") están permitidos — `delivered`/`cancelled` son terminales
   (`OrderCoreService.php:27-51`). Cada retroceso queda registrado en
   `pos_order_event` como la transición real, no se esconde.
6. **`pos_order_item.tags`** (mig 135) — mismo catálogo/comportamiento que las
   etiquetas de línea de venta: uso interno, viajan a la comanda, NUNCA a la
   factura (`print-comandas.ts:80-84`, `use-orders.ts:52-57`).
7. **Espacio ⇒ `source='table'` y `fulfillment='dine_in'` forzados,
   sin importar lo que mande el payload.** Una mesa no pide delivery
   (`OrderCoreService.php:166-192`).
8. **NINGUNA orden toca stock — es el estado actual y hay un plan, no un
   olvido.** `OrderCoreService` no tiene una sola referencia a
   `stock`/`manageStock`/`Inventory`, y ni `pos_order` ni `pos_order_item`
   tienen columna de consumo, reserva o despacho. El descuento ocurre recién
   al emitir la venta, así que entre que la comanda sale a cocina y la mesa se
   cobra el sistema cree que la mercadería sigue disponible. El modo orden
   está explícitamente sin gate de stock (`cart-panel.tsx:330-333`).
   **El plan que lo cierra es `context/53-orden-y-stock-reserva.md`** (D1-D4
   cerradas por el owner 2026-08-25): Fase 1 es "comprometido" derivado de las
   órdenes abiertas + descuento al facturar, y el descuento al despachar queda
   como interruptor por tenant. Antes de tocar nada de esto, leer ese doc —
   tiene una sección de arquitecturas RECHAZADAS (descontar al crear la orden,
   doble descuento sin marca, reserva calculada en el cliente).

## 4. Flujos principales

**Tomar una orden (mostrador/mesa) → cocina:**
1. `handleOrderClick` (`cart-panel.tsx:320-388`) llama `createOrder.mutateAsync`
   con `sendNow: true` — la orden nace `sent` (comentario `sent_at`).
2. Validación server-side de `selections` ANTES de abrir la transacción — un
   rechazo (422) no deja una TX abierta a medias (`OrderCoreService.php:273-307`).
3. Si `ordenAImpresion` está activo, imprime la comanda por estación
   (best-effort, sin bloquear el éxito de la creación) —
   `cart-panel.tsx:370-379`.
4. Error de red o de negocio → toast, `submittingOrder` se resetea. **No hay
   cola offline para este paso** — ver §6.

**Orden en venta (cobrar primero, producir después):**
1. Venta normal en modo "venta" con `ordenEnVenta=true` en la config de
   caja — al confirmar el pago, se deja un snapshot (`orderDraft`) para el
   botón manual "Ordenar" del modal de éxito (decisión del owner,
   2026-07-31: ya no se autogenera).
2. `handleOrdenar()` crea la orden con `transactionId` — nace pagada y activa
   en el mismo INSERT (regla 2). Ver regla 4 para el gap de add-ons en este
   camino específico.

**Cobrar una orden existente (`/pos/ordenes` → Cobrar):**
1. `loadFromOrder(order)` vuelca los ítems al carrito en modo venta,
   excluyendo cancelados e hijas de add-on — `cart/store.ts:1178-1233`.
2. `pay-dialog.tsx` cobra normal y, al confirmar, llama
   `markOrderPaid({orderId, transactionId})` — cierra el rastro sin afectar
   el ciclo de vida si ya estaba `closed`-elegible (regla 2).
3. Ver regla 3 para lo que se pierde si la orden tenía add-ons.

**Cancelación:** motivo OBLIGATORIO, exigido por `OrderCoreService`, no solo
la UI (`use-orders.ts:441-448`) — queda en el timeline
(`pos_order_event.reason`). Una orden nunca se borra.

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Add-ons (`AddonService`) | `create()` revalida `selections` contra la BD antes de persistir hijas — mismo mecanismo que la venta. | Ver regla 3: la validación y persistencia en la ORDEN están resueltas; el contrato se rompe recién al convertir esa orden en venta (`loadFromOrder` no re-manda `selections`). |
| Cocina / KDS | `screenItems`/`addonChildrenOf` (`lib/kds/board.ts`) arman lo que cada estación ve, incluidas las hijas de add-on indentadas. `updateItemStatus` rechaza mover una hija sola. | Asume que la hija SIEMPRE llega inmediatamente después de su padre en el `ORDER BY` (`ITEMS_HIERARCHY_ORDER`, `OrderCoreService.php:114-119`) — si ese orden se rompiera, el KDS agruparía mal. |
| Espacios | Una orden con `spaceSessionId` fuerza `source='table'`/`dine_in`; el `FOR UPDATE` sobre `space_session` durante `create()` bloquea `request-bill`/`cancel`/`close` concurrentes hasta que la orden termine de crearse (`OrderCoreService.php:251-330`). | Ver `12-espacios.md` — el contrato es que ninguna orden nueva puede colarse mientras se está cerrando/pidiendo la cuenta de la misma sesión. |
| Impresión | `printOrderComandas` reusa el MISMO pipeline (`printSale`) que factura/recibo, particionando por `categoryId` heredado del padre — decisión de diseño explícita para no duplicar el mecanismo de "qué impresora recibe qué" (`print-comandas.ts:1-19`). | Asume que toda impresora con `docTypes` incluyendo `"order"` está configurada correctamente; sin bindings, `printSale` devuelve silencioso (sin toast) por diseño. |
| Venta (cobro) | `loadFromOrder`/`loadFromSession` alimentan el carrito que `SaleService` termina persistiendo. `markPaid()` dejа el rastro post-cobro. | Ver `10-pos-venta.md` regla del add-on money-correct-but-inventory-broken (regla 3 acá). El backend de venta NUNCA sabe que esas líneas vinieron de una orden — no hay `orderParentId` en `transaction`. |
| Sincronización | `order` está mapeado en `ENTITY_TO_QUERY_KEYS` — cualquier mutación server-side publica `realtimePublish('order', ...)` e invalida `["orders"]` en todos los devices conectados (`use-orders.ts:11-15`). El canal KDS (`{companyId}:kds:{outletId}`) es scope aparte, no consumido acá. | Asume conectividad — no hay reconciliación offline para este canal (ver §6). |

## 6. Offline (POS)

**Crear/enviar una orden NO tiene cola offline — a diferencia de la venta.**
`useCreateOrder` (`use-orders.ts:368-381`) es una mutación react-query pura
sobre `posFetch`; `handleOrderClick` no tiene ningún `enqueue()`/IndexedDB de
respaldo — un fallo de red muestra un toast y no pasa nada más
(`cart-panel.tsx:381-384`). Esto es **consistente con la política
documentada** de que el offline-writes scope es "solo ventas simples + alta
de clientes" — mesas/órdenes son online-only por diseño (estado compartido
entre cajas y cocina, la otra mitad de la distinción de §53). Pero matiza una
lectura naive de "emitir/imprimir va offline" (§53): acá "emitir" (crear el
registro `pos_order` que la cocina ve) SÍ requiere conexión — lo único que es
verdaderamente local es el PASO DE IMPRIMIR el ticket ya construido
(`printOrderComandas`/`printSale`), y solo si la impresora es local
(`native`/`escpos`), no `station`. Sin red, un "Ordenar" falla completo: ni
se crea la orden ni se imprime nada.

## 7. Huecos conocidos y NO verificado

- **Cobro de una orden con add-ons pierde el desglose** (regla 3) — plata
  correcta, stock del add-on y trazabilidad fiscal rotos. Gap real, más
  angosto que lo que documentan hoy `context/41-addons-y-combos.md` y
  `context/modules/02-combos-y-addons.md` (ambos anteriores al fix
  `46ac668f` de esta misma madrugada) — valdría actualizarlos para no
  sobre-reportar el alcance del hueco.
- **Orden espejo de `ordenEnVenta` sin `selections`** (regla 4) — gap
  secundario, menor impacto (la venta que la originó ya cobró bien), pero
  la comanda de esa orden espejo no mostraría el desglose si se reimprime.
- **Sin cola offline para crear órdenes** (§6) — consistente con la política
  de scope, pero no hay mensaje específico al cajero más allá de un toast
  genérico "No se pudo enviar la orden".
- **NO VERIFICADO**: si `Reports/OrdersService`, `TransactionDetailService`,
  `SpaceBalanceService`/`SpaceSettlementService` — que el comentario de
  `OrderCoreService.php:485-487` asegura que "quedan INTACTAS" con `price=0`
  en las hijas — efectivamente excluyen esas filas de sus `SUM(qty*price)`
  en vez de simplemente no verse afectadas porque el precio es cero (son
  equivalentes en el resultado, pero no se auditó el código de esos
  servicios en esta sesión).
- **NO VERIFICADO**: comportamiento de `recomputeOrderStatus` cuando una
  familia padre-hijas tiene estados mixtos tras un recall — no se revisó a
  fondo el agregado (`ITEM_STATUS_RANK`).

## 8. Planes y decisiones relacionados

- `context/53-orden-y-stock-reserva.md` — **cuándo la mercadería sale del
  inventario** (regla 8). D1-D4 del owner, fases F1-F4, arquitecturas
  rechazadas. Documenta también que NO existe ningún job que limpie órdenes
  abiertas viejas, y por qué eso es el riesgo central de su Fase 1.
- `context/24-orders-module-plan.md` — plan original del módulo (O0-O2).
- `context/41-addons-y-combos.md` — plan de add-ons; el "gap 1" que citaba
  como abierto se cerró parcialmente por `46ac668f` (ver regla 3).
- `context/modules/02-combos-y-addons.md` — doc de add-ons, escrito ANTES del
  fix de esta sesión; su tabla de interacciones "Órdenes/mesas" está
  desactualizada en la parte de creación/comanda (sigue vigente en la parte
  de cobro).
- `context/27-delivery-sla-plan.md` — fulfillment, repartidor, timeline de
  eventos (F-D-0/1, F-EVT-0).
- `context/15-espacios-module-plan.md` — integración con sesiones de mesa.
