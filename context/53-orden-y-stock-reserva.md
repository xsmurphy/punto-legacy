# 53 — Orden y stock: cuándo la mercadería sale del inventario

> Estado: **PLAN, sin implementar.** D1-D4 cerradas por el owner (2026-08-25),
> no relitigar. D5-D8 son derivaciones arquitectónicas de esta sesión, no
> palabras del owner — están marcadas como tales y se pueden discutir.
> El prerequisito ya está en `main`: `48a3e495` cerró que el add-on descuente
> stock al cobrar una mesa y su split por ítems, así que la cadena
> orden→venta ya no pierde el stock de la opción elegida.
> Origen: el owner preguntó si una orden debe descontar stock. La respuesta
> corta es que **hoy ninguna lo hace**, y que eso no es un olvido sino una
> consecuencia de "orden ≠ venta" — pero deja una ventana ciega entre que la
> comanda sale a cocina y la mesa se cobra.

## El problema (verificado contra código, 2026-08-25)

Entre que la comanda sale a cocina y la mesa se cobra, **el sistema cree que
la mercadería sigue disponible**. El descuento ocurre recién al emitir la
venta.

Verificado:

- `api/lib/Orders/OrderCoreService.php` no tiene una sola referencia a
  `stock` / `manageStock` / `Inventory`. El módulo de órdenes no toca
  inventario en absoluto.
- `pos_order` (`api/database/migrations/postgres/79_orders_core.sql:35-55`) y
  `pos_order_item` (:61-77) **no tienen ninguna columna de consumo, reserva o
  despacho**. La única marca de "ya se cobró esto" es
  `pos_order_item.settledpaymentid` (mig 90), y es exclusiva del split de
  mesas por ítems (`kind='items'`).
- El modo orden está explícitamente sin gate de stock:
  `frontend/components/register/cart-panel.tsx:330-333` — *"Modo orden (O1):
  'Ordenar' envía a cocina — sin caja/stock, sin gate de drawer"*, repetido en
  el `PayCta` (:1699-1703).

Y hay un agravante independiente: **el POS hoy no ve stock ninguno.**

- `frontend/lib/pos-bff/reshape.ts:93-96` fija `stock: null` a mano, con un
  TODO desactualizado que dice que el LIST de `/v1/items` no trae saldo.
  **El TODO miente**: `api/lib/Items/ItemsQuery.php:197` sí devuelve
  `COALESCE(st.onhand,0) AS stockOnHand`. El dato llega al BFF y el reshape lo
  tira. (Y el que llega es company-wide: el LATERAL de
  `ItemsQuery.php:334-337` no filtra por sucursal, así que tampoco serviría
  tal cual para la caja.)
- Consecuencia en cadena: el badge de stock del buscador nunca se pinta
  (`product-search-dialog.tsx:224-226` exige `item.stock !== null`), y el
  patch optimista post-cobro (`pay-dialog.tsx:756-757`) es un no-op.
- La cañería de realtime de stock **está completa y funciona**:
  `manageStock` acumula ids y `Inventory::flushRealtimeStockEvents()` publica
  un único `item/update` por request (`api/lib/App/Domain/Inventory.php:76`);
  el POS lo debouncea y re-pide por `/pos/items-batch`
  (`frontend/lib/catalog/realtime-catalog-sync.ts:96-109`)… y ese endpoint
  reshapea con el mismo `reshapeItem`. **La cañería transporta `stock: null`
  de punta a punta.**

O sea: antes de discutir cuándo descontar, hay que aceptar que el POS
tampoco sabe cuánto hay.

## Vocabulario — `reserved` YA está tomado, no lo uses

**`reserved` en este codebase significa reserva de MESA**, no de mercadería:
`api/lib/Spaces/SpaceService.php:359` lo declara como estado futuro del
espacio (*"reservas se suman en F4 — quedaría 'reserved' entre free y
occupied"*). Nombrar la reserva de stock `reserved` haría que dos features sin
relación compartan palabra en el mismo dominio.

Vocabulario canónico de este doc:

| Término | Definición | Fuente |
|---|---|---|
| `onHand` | Saldo real del ledger: `SUM(stock.stockCount)` por (ítem, sucursal). | D1 de `context/52` |
| **`comprometido`** | Cantidad que ya está prometida a una orden abierta y todavía no salió del ledger. **Derivado, nunca un asiento.** | Este doc, D5 |
| **`disponible`** | `onHand − comprometido`. Es lo que se le muestra al cajero. | Este doc, D5 |
| `reserved` | **Reserva de MESA.** No usar para stock. | `SpaceService.php:359` |

## Las decisiones del owner (D1-D4, cerradas)

- **D1 — El evento de salida es el DESPACHO, no la preparación.** Descontar
  "en cocina" se rompe con productos que no se preparan (una gaseosa). El
  criterio no es si se prepara: es que **la mercadería sale igual** — la
  gaseosa sale de la heladera. Punto ya rutea cada ítem a su estación
  (`OrderCoreService::resolveStationId`, `OrderCoreService.php:1410-1421`,
  categorías → `order_station`), y un ítem sin estación sale directo. El
  evento es "la orden se despachó", y aplica a todos los ítems por igual.

- **D2 — Un solo descuento, idempotente, en el primer evento que ocurra.**
  En mostrador y take-away la orden y la venta son el mismo acto, y el riesgo
  es descontar dos veces. Regla: el stock se descuenta UNA vez, en el primer
  evento que ocurra, y el segundo lo respeta. La línea de orden lleva marca de
  consumida y la venta que la cobra la lee.
  **El patrón ya existe en Punto**: es el CAS que marca lo saldado en el split
  de mesas —
  ```sql
  UPDATE pos_order_item SET settledpaymentid = ?
   WHERE orderitemid IN (…) AND companyid = ? AND settledpaymentid IS NULL
  RETURNING orderitemid
  ```
  (`api/lib/Spaces/SpaceSettlementService.php:175-187`; si el `RETURNING` no
  devuelve exactamente los ids esperados, lanza y aborta la TX entera). Ese
  CAS existe justamente porque mezclar dos formas de descontar hacía derivar
  el inventario en silencio — decisión del owner 2026-07-19,
  `context/modules/12-espacios.md` regla 2: *"la plata queda bien... el
  inventario deriva en silencio, que es peor porque no se nota"*.

- **D3 — El KDS no es requisito.** El evento de despacho es la generación de
  la comanda (`frontend/lib/orders/print-comandas.ts`), que funciona sin KDS.
  El KDS es una vista sobre ese mismo evento, no su origen. Si el comercio no
  imprime nada, el evento es la confirmación de la orden.

- **D4 — Fase 1 es COMPROMETIDO + descuento al facturar.** Elección explícita
  del owner. Cubre el 100% de los casos sin ambigüedad, no toca el ledger
  (que recién se saneó en `context/52`), y no depende de KDS ni de comandas.
  El "descuento al despachar" queda como interruptor opcional por tenant para
  gastronomía, más adelante.

## Derivaciones arquitectónicas (D5-D8, de esta sesión)

- **D5 — El comprometido es DERIVADO de las órdenes abiertas. No es un
  asiento del ledger, y no es una tabla propia.** El ledger `stock` es la
  única fuente de verdad del saldo (D1 de `context/52`) y cada fila *"posee el
  stock real al momento del registro"*. Un asiento de reserva no tiene
  contrapartida física: ensuciaría el costeo promedio ponderado móvil y el
  reposteo, que son exactamente los dos mecanismos que `context/52` acaba de
  dejar sanos. Y una tabla de reservas con ciclo propio duplicaría el estado
  que `pos_order.status` ya lleva — dos fuentes de "qué está abierto" que
  pueden divergir es el mismo problema de las cuatro fuentes de saldo que
  `context/52` cerró.

  El comprometido se calcula así (propuesta, dentro del doc, no código):

  ```sql
  SELECT oi.itemid, SUM(oi.qty) AS comprometido
    FROM pos_order_item oi
    JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
   WHERE o.companyid = ?
     AND o.outletid  = ?
     AND o.status    NOT IN ('closed','cancelled')   -- terminales de pos_order
     AND oi.status   <> 'cancelled'                  -- línea anulada dentro de una orden viva
     AND oi.itemid   IS NOT NULL
   GROUP BY oi.itemid
  ```

  Los terminales salen de `ORDER_TRANSITIONS`
  (`OrderCoreService.php:80-92`): `closed` y `cancelled` no tienen salida.

- **D6 — El disponible se sirve desde el lector único, anclado en
  `onHandBulk`. NO se crea un lector nuevo.**
  `Inventory::onHandBulk(string $companyId, string $outletId = '', ?string $sinceDate = null)`
  (`api/lib/App/Domain/Inventory.php:648`) es el punto de anclaje natural, por
  tres razones concretas:
  1. **Ya hace el fence de tenant por el MODELO, no por la columna
     denormalizada**: `JOIN outlet o ON o.outletId = s.outletId WHERE
     o.companyId = ?` — el propio código explica que una fila ajena no puede
     entrar ni una propia quedar afuera por un `companyId` mal escrito. El
     comprometido tiene que heredar ese mismo fence, y heredarlo es gratis si
     vive ahí.
  2. **Ya acepta corte de fecha** (`$sinceDate`), que es la forma del problema
     "saldo a una fecha" — y `disponible` es la misma familia de pregunta.
  3. **D2 de `context/52` prohíbe explícitamente un lector paralelo**: un
     lector nuevo que responda "cuánto hay para vender" al lado del que
     responde "cuánto hay" recrea la divergencia de fuentes que 52 acaba de
     cerrar. `onHand` sigue siendo el saldo; `disponible` es un campo MÁS del
     mismo retorno (`{onHand, cogs, comprometido, disponible}`), no otra
     función que compita.

- **D7 — El comprometido solo ADVIERTE. Nunca rechaza.** Ver §"Offline".

- **D8 — Una orden abierta que nadie cierra es un comprometido eterno, así
  que F1 NO puede shippearse sin vencimiento.** Ver §"Ciclo de vida".

## Ciclo de vida del comprometido

**Se libera al cobrar.** `OrderCoreService::markPaid()` cierra con CAS
(`OrderCoreService.php:912-917`):
```sql
UPDATE pos_order SET status='closed', closed_at=now()
 WHERE orderid=? AND companyid=? AND status NOT IN ('closed','cancelled')
```
en la misma TX que `linkOrder()` (:926), que deja la fila en
`order_transaction_link`. Al pasar a `closed`, la orden sale del conjunto del
comprometido — sin ningún trabajo extra, que es la ventaja de que sea
derivado.

**Se libera al cancelar.** `updateStatus('cancelled')` exige `reason` no vacío
(:686-688, enforcement en backend) y rechaza cancelar una orden que ya tiene
transacción vinculada (:720-725).

**Se libera al cerrar la mesa.** `SpaceSettlementService::settleIfCovered()`
(:468-523) selecciona las órdenes vivas de la sesión
(`WHERE spacesessionid=? AND status NOT IN ('closed','cancelled')`, :492-495),
les hace `markPaid()` (:506) y cierra la sesión (:508).

**NO se libera por vencimiento — porque no hay vencimiento.**

### Órdenes zombie: el riesgo principal de la Fase 1

**No existe ningún job que limpie órdenes viejas.** Verificado en tres
lugares:

1. Los **seis** jobs del cron de la imagen del API
   (`api/docker/cron/crontab:13-18`, despachados por
   `api/v1/maintenance.php:103-143`) son `einvoice-drain`,
   `rollup-reconcile`, `purge-tenant-audit`, `purge-deleted-row`,
   `partition-ensure` y `period-close`. **Ninguno toca `pos_order`.**
2. Los tres `cron.schedule` de `pg_cron` del repo (migs 36, 138, 150) purgan
   `tenant_audit` y `deleted_row`. **Ninguno toca órdenes.** (Y `pg_cron` ni
   siquiera está instalado en la imagen de prod — ver `context/06`.)
3. No hay código con semántica `stale` / `abandoned` / `cleanup` aplicado a
   órdenes.

Peor: **el cierre de caja no las ve.** `DrawerService`,
`Reports/CashCountStatus.php` y `Reports/DrawersService.php` no referencian
`pos_order` en ninguna línea; el arqueo se arma sobre `transaction` y pagos, y
una orden sin cobrar nunca generó transacción. Tampoco aparecen en
`frontend/lib/pos/shift-close-reconciliation.ts`.

Y **el cierre de período tampoco las alcanza**: los triggers de
`fn_period_guard` (`157_period_close.sql:168-184`) cubren `transaction`,
`itemsold`, `stock`, `cpayments` y `expenses` — **no `pos_order`**. Una orden
queda mutable para siempre, y nada la fuerza a cerrar al vencer el período.

El único lugar donde emergen es el listado `OrderCoreService::list()`
(:1151-1259): sin filtro de fecha por defecto, `ORDER BY created_at DESC
LIMIT 500`.

**Por qué esto es la amenaza central de F1:** el comprometido solo crece.
Cada orden abandonada resta disponible para siempre y nunca lo devuelve.
Un tenant con seis meses de mesas mal cerradas vería faltante fantasma en
ítems que tiene en el depósito — y el disponible pasaría de ser una ayuda a
ser una mentira que solo empeora. Hoy ese problema no se nota **porque nadie
lee las órdenes abiertas para nada**; en el momento en que el disponible las
lee, la deuda se cobra de golpe.

Por eso **D8**: F1 incluye vencimiento de órdenes o no se shippea. Forma
mínima propuesta:

- `settingOrderStaleHours` por tenant (mismo patrón que
  `settingPeriodCloseMonths` de `context/48`), default conservador.
- Un job `orders-expire` en el mismo `crontab`, que cancele con
  `reason='vencida automáticamente'` para que quede en `pos_order_event` como
  la transición que es — nunca un `DELETE`, "una orden nunca se borra"
  (`context/modules/11` §4).
- **Pregunta abierta para el owner**: un comercio que deja mesas abiertas de
  un día para el otro es un caso legítimo. El default tiene que ser por
  tenant y probablemente por `source` (una mesa no vence igual que un
  take-away de mostrador).

## Offline — qué se degrada y qué NO (D7)

La regla que gobierna todo este lado es `context/08-convenciones-criticas.md`
§53, textual del owner:

> *"No podés rechazar una venta en el backend. Esa venta ya se emitió, se
> validó y se imprimió; el backend solo la guarda a ese punto. El POS tiene
> que tener el 100% de la data básica almacenada localmente y esa es la fuente
> única de verdad para el POS."*

De ahí sale D7, sin margen de interpretación:

- **El comprometido puede ADVERTIR al emitir, contra el cache local.** Es una
  señal para el cajero, del mismo tenor que el gate de `isCreditable`.
- **El comprometido NUNCA puede rechazar al sincronizar.** Una venta encolada
  offline ya entregó la mercadería y ya imprimió el comprobante; rechazarla al
  reconectar la pierde para siempre. `SaleService::save()` guarda, no rechaza.
- **Lo que NO se degrada es la emisión.** Sin red, la factura, el recibo y la
  comanda salen igual. El disponible es best-effort; la emisión no.
- **Sin red, el disponible es el último valor conocido.** Dos cajas offline
  pueden comprometer el mismo último ítem. Eso es aceptado, no es un bug a
  cerrar: es el precio de §53. Lo que el sistema promete no es "nunca
  sobrevender", es "no mentir sobre lo que sabía".

**Trampa ya existente, a no confundir con esto:** hoy el device puede recibir
un código `STOCK_OUT` con el mensaje *"Stock insuficiente para uno o más ítems
de la venta"* (`api/v1/offline-sync.php:203-211`). No lo produce ninguna regla
de negocio: sale de `SaleAbortedException::isStockFailure()`
(`api/lib/Sales/Exceptions/SaleAbortedException.php:44-47`), que es un **sniff
de texto** — `stripos($this->dbError, 'stock') !== false`. Cualquier rollback
de PG cuyo mensaje contenga la palabra "stock" (una FK, un tipo, un nombre de
columna) se le reporta al cajero como falta de mercadería. Es un
falso-positivo estructural y **no debe tomarse como precedente de que el
backend rechaza por stock** — no rechaza; clasifica mal un error ajeno.

## Dónde se muestra el disponible

Ninguna de estas superficies existe para "disponible" hoy; la columna
"Con disponible" es la propuesta.

| Superficie | Archivo | Hoy | Con disponible |
|---|---|---|---|
| POS — badge del buscador | `frontend/components/register/product-search-dialog.tsx:224-256` | Código vivo pero **muerto en la práctica**: exige `item.stock !== null` y el reshape lo fija en `null`. | Primera superficie. Pinta `disponible`, no `onHand` — es la respuesta a "¿puedo vender esto ahora?". |
| POS — ficha del producto | `frontend/components/register/product-info-dialog.tsx:164-179` | Sí funciona, on-demand y **online-only** (degrada explícito en :382-383). Por sucursal, con la activa primero. | Fila "comprometido" junto al saldo, para que el desglose explique la diferencia. |
| POS — patch optimista post-cobro | `frontend/components/register/pay-dialog.tsx:756-757` | No-op (`stock` es `null`). | Revive solo si baja stock al POS (F2). |
| Panel — ficha del ítem, tab Stock | `frontend/components/items/stock-tab.tsx:75-79, 290-355` | Saldo por sucursal y por depósito, `staleTime: 15s` (`hooks/use-item-stock.ts:62-77`). | Card "Dónde está el stock" suma comprometido como línea aparte. |
| Panel — listado de ítems | `frontend/app/(panel)/items/page.tsx` | `stockOnHand` company-wide (`ItemsQuery.php:197`). | Columna opcional. Prioridad baja. |
| Panel — reporte de stock | `api/lib/Reports/StockService.php:58-101` → `frontend/app/(panel)/reports/stock/page.tsx` | `onHandBulk`, snapshot. | Sale gratis si el disponible vive en `onHandBulk` (D6). |

### Qué pasa cuando el disponible queda negativo

Hoy **no hay ningún guard que impida vender bajo cero**, en ningún lado: no
hay caller de `Inventory::onHand*` en `SaleService`, `TransactionService` ni
`OrderCoreService`, y no hay CHECK ni trigger sobre `stock`. El saldo negativo
es un estado normal del sistema, solo pintado de rojo.

El semáforo compartido es `frontend/lib/stock-status.ts:36-46`, y tiene un
problema para este caso: colapsa `saldo <= 0` en un único estado `quiebre`
(:42). Con disponible eso mezcla dos situaciones que el cajero necesita
distinguir:

- **`onHand <= 0`** — no queda mercadería. No hay nada que despachar.
- **`onHand > 0` pero `disponible <= 0`** — hay mercadería física, pero está
  toda prometida a órdenes abiertas. Si una se cancela, vuelve a haber.

Propuesta: un estado nuevo (`comprometido`) en `StockStatus`, distinto de
`quiebre`, para no obligar al cajero a adivinar cuál de las dos cosas está
mirando. Y el disponible se muestra tal cual si es negativo — nunca se
clampea a 0: un negativo es información (se sobrevendió o se sobre-prometió),
esconderlo es perderla.

## Fases

- **F1 — Comprometido derivado + disponible en el lector único + vencimiento
  de órdenes.** El cálculo de D5 anclado en `onHandBulk` (D6); `onHand` no
  cambia de significado. Incluye SÍ o SÍ el vencimiento de D8 y el estado
  `comprometido` del semáforo. Superficie mínima: ficha del producto en el POS
  y tab Stock del panel (las dos que hoy ya muestran stock de verdad).
  **Desbloquea:** que el comercio vea por primera vez la diferencia entre lo
  que tiene y lo que ya prometió, sin tocar el ledger.

- **F2 — Bajar stock al POS.** Cerrar `reshape.ts:93-96` (leer el
  `stockOnHand` que ya viene y **arreglar que sea por sucursal**, no
  company-wide — `ItemsQuery.php:334-337`), y sumar el saldo al bootstrap
  (`frontend/app/api/pos/bootstrap/route.ts`) y al delta
  (`frontend/lib/catalog/delta-sync.ts`). Revive el badge del buscador y el
  patch optimista, que ya están escritos.
  **Desbloquea:** el disponible offline best-effort de D7 — hoy es imposible,
  porque el snapshot del POS no lleva stock ninguno.

- **F3 — Marca de consumo idempotente en la línea de orden (D2).** Columna
  en `pos_order_item` con el mismo patrón CAS que `settledpaymentid`
  (`UPDATE … WHERE marca IS NULL RETURNING`). No cambia todavía **cuándo** se
  descuenta: hace estructuralmente imposible descontar dos veces.
  **Desbloquea:** F4. Es su prerequisito, no un paso opcional.

- **F4 — Descuento al despachar, interruptor por tenant (D1 + D3).** Solo
  gastronomía, opt-in. **Requiere resolver primero un hueco que hoy existe:
  el evento de despacho no se persiste.** No hay `printed_at` ni
  `dispatched_at` en `pos_order`/`pos_order_item`, y `recordEvent()` solo se
  llama desde transiciones de status — reimprimir una comanda es
  indistinguible de imprimirla por primera vez. Sin persistir el evento, "se
  despachó" no es un hecho consultable y D2 no tiene de dónde leer.
  **Desbloquea:** que el inventario de un restaurante refleje la realidad
  física durante el servicio, no solo al cierre de la mesa.

## Gaps que la Fase 1 NO cierra — dicho con todas las letras

1. **La mercadería que ya salió a cocina sigue contada como stock físico.**
   F1 avisa, no corrige. El ledger y el depósito real divergen durante toda la
   vida de la orden. Lo que F1 cambia es que esa divergencia se vuelve
   **visible**; eliminarla es F4.
2. **El split por `amount`/`share` sigue sin descontar** — decisión explícita
   documentada en el docblock de `buildProportionalLines`
   (`frontend/lib/spaces/settlement-lines.ts`), con sus tres razones. El
   comprometido tampoco sabe prorratear una línea partida por monto libre.
3. **Dos cajas offline pueden comprometer el mismo último ítem.** Aceptado
   por §53 (D7). F1 no lo cierra y no debe intentarlo.
4. **Recetas y compuestos.** Si el comprometido suma `pos_order_item.qty` por
   `itemid`, un combo compromete el combo y **no sus insumos**. La venta sí
   explota la receta (`Inventory::explodeRecipe`), así que el comprometido y
   el descuento medirían cosas distintas. Ver §Preguntas abiertas.
5. **Cotizaciones, ecommerce pendiente y agenda no comprometen.** `source`
   admite `ecommerce` y `schedule` (`79_orders_core.sql:40-41`), pero una
   cotización no es una orden y queda fuera del cálculo.
6. **`itemTrackInventory = false` no compromete nada** — consistente con que
   tampoco deja fila en el ledger (G13 de `context/52`).
7. **El vencimiento de D8 puede cerrar una orden legítima.** Un comercio que
   deja mesas abiertas de un día para el otro es un caso real. Mitigado con
   config por tenant, no eliminado.
8. **El disponible no es transaccional.** Entre que se lee y se emite la
   venta puede cambiar. No hay lock y no debe haberlo: bloquear filas de
   `pos_order` desde el camino de venta acopla dos módulos que hoy no se
   conocen.

## ARQUITECTURAS RECHAZADAS — no reintroducir

| Arquitectura | Veredicto | Por qué |
|---|---|---|
| **Descontar al CREAR la orden** | RECHAZADA | La mercadería no salió: tomar el pedido no es entregarlo. Nadie en la industria lo hace (ERPNext, Odoo y los POS de gastronomía descuentan al despachar o al facturar, nunca al capturar el pedido). Una orden cancelada a los diez segundos ya habría movido el ledger, obligando a un asiento de reposición por cada cambio de opinión — y `pos_order` cambia mucho: `ORDER_TRANSITIONS` (`OrderCoreService.php:80-92`) admite retrocesos hasta `ready`. El ledger se llenaría de ruido que no corresponde a ningún movimiento físico. |
| **Descontar en el cobro Y en el despacho, sin marca de consumo** | RECHAZADA | Doble descuento en mostrador y take-away, donde orden y venta son el mismo acto (D2). Es exactamente el bug que el CAS de `settledpaymentid` existe para prevenir en el split de mesas, y la razón por la que el owner prohibió mezclar familias `items` con `amount`/`share` (2026-07-19): *"la plata queda bien... el inventario deriva en silencio, que es peor porque no se nota"*. Cualquier esquema de dos eventos exige la marca idempotente (F3) ANTES de activarse. |
| **La reserva calculada en el cliente** | RECHAZADA | Si el disponible se calcula en la caja sobre sus propias órdenes, dos cajas offline reservan el mismo último ítem y ninguna de las dos se entera. El comprometido es un agregado sobre órdenes de TODAS las cajas: por definición no puede vivir en una sola. El cálculo es del servidor; el POS lo consume como dato y acepta trabajar con el último valor conocido (D7). |
| **El comprometido como asiento del ledger** (`stock` con `source='reservation'`) | RECHAZADA | Viola D1 de `context/52`: cada fila del ledger *"posee el stock real al momento del registro"*. Un asiento sin contrapartida física rompe el costeo promedio ponderado móvil y el reposteo de snapshots (`Inventory.php:541`), que son justamente los dos mecanismos que `context/52` acaba de dejar sanos. La reserva NO es un movimiento. |
| **Tabla propia de reservas con ciclo de vida propio** | RECHAZADA | Duplica el estado que `pos_order.status` ya lleva. Serían dos fuentes de "qué está abierto" que pueden divergir — la misma clase de problema que las cuatro fuentes de saldo que `context/52` acaba de cerrar. Además habría que sincronizar cada transición de orden con su fila espejo, y toda transición que alguien olvide es un comprometido huérfano. Derivar de `pos_order` no puede desincronizarse porque no hay dos cosas. |
| **Bloquear la venta server-side por falta de disponible** | RECHAZADA | Rompe §53. Una venta ya emitida e impresa que el backend rechaza al sincronizar se pierde: la mercadería ya se entregó y el cliente se fue. El disponible advierte al emitir, contra el cache local; nunca rechaza al recibir. |

## Preguntas abiertas / NO verificado

Anotadas con el archivo donde habría que mirar, para que el próximo no las dé
por resueltas.

1. **Explosión de receta en el comprometido.** No verifiqué la firma de
   `Inventory::explodeRecipe` (`api/lib/App/Domain/Inventory.php`, ~:120 en
   adelante) ni si es reusable fuera del camino de venta. Es lo que decide si
   el gap 4 es barato o caro.
2. **`recomputeOrderStatus()`** (`api/lib/Orders/OrderCoreService.php:1298-1358`)
   — no se leyó el cuerpo. Importa porque persiste el status con un UPDATE
   propio que **no consulta `ORDER_TRANSITIONS`** (comentario en :64-70); si
   ese UPDATE no lleva CAS, el conjunto "órdenes abiertas" puede moverse bajo
   los pies del cálculo del comprometido.
3. **Costo de la query del comprometido a volumen.** Existe
   `idx_pos_order_company_outlet_status` (`79_orders_core.sql:57`) pero no
   cubre `pos_order_item.itemid`. Falta medir con datos reales antes de
   ponerlo en un camino caliente como el bootstrap.
4. **`source='ecommerce'` y `source='schedule'`** — no se relevó si esos
   caminos crean órdenes que queden abiertas por diseño. Si lo hacen, el
   vencimiento de D8 tiene que tratarlos distinto.
5. **Discrepancia encontrada de paso, ajena a este plan:**
   `context/modules/11-ordenes-y-comandas.md` documenta
   `pos_order.saletransactionid` como columna, pero la mig 115 la **dropeó**
   (`115_transaction_link.sql:230`) y la reemplazó por
   `order_transaction_link`. El código ya está migrado (guard en
   `OrderCoreService.php:717`, rastro en :923); el doc del módulo quedó
   viejo en ese punto.

## Docs relacionados

- `context/52-stock-ledger-unica-fuente.md` — el ledger como única fuente de
  verdad; D1 (saldo = SUM), D2 (lector único). Este plan se apoya en los dos.
- `context/08-convenciones-criticas.md` §53 — emisión vs. estado compartido;
  el backend nunca rechaza una venta ya emitida.
- `context/modules/11-ordenes-y-comandas.md` — el módulo de órdenes.
- `context/modules/12-espacios.md` regla 2 — el CAS de `settledpaymentid` y
  por qué no se mezclan familias de cobro parcial.
- `context/41-addons-y-combos.md` — el add-on cruzando el flujo de orden.
- `context/48-escalamiento-de-datos.md` — cierre de período, `fn_period_guard`
  (que **no** cubre `pos_order`).
