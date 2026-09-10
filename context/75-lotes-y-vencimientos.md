# 75 — Lotes y vencimientos (análisis de viabilidad)

> Estado: ANÁLISIS pedido por el owner el 2026-09-10 ("analizá la posibilidad
> de añadir manejo de lotes, más que nada referencial; hay empresas que manejan
> productos con vencimientos y necesitan llevar un control de cuándo está por
> vencer un lote y que el sistema les alerte"). **Sin plan cerrado y sin
> decisiones del owner.** Nada de lo de acá está aprobado.

## 1. Veredicto corto

Se puede, y el costo es menor de lo que parece — pero **sólo si el alcance se
mantiene en "referencial"**, que es como lo pidió el owner. La diferencia entre
las dos versiones del rasgo es enorme:

| | Referencial (lo pedido) | Valorizado (FIFO/FEFO real) |
|---|---|---|
| Qué responde | "Este lote vence el 12/11 y quedan 8 unidades" | "Esta venta consumió 3 del lote A y 2 del B, a estos costos" |
| Dónde toca | Una tabla nueva + entradas de stock + alertas | **Los 23 call-sites** de `manageStock()`, el COGS, producción, devoluciones |
| Riesgo | Bajo: es un registro paralelo | Alto: es el money-path del inventario |

Lo que sigue asume la columna izquierda.

## 2. Lo que ya existe y no hay que construir

- **El ledger de stock es append-only y el saldo es derivado** (`stock`,
  `SUM(stockCount)`), con **un solo escritor**: `Inventory::manageStock()`
  (`api/lib/App/Domain/Inventory.php:987`, INSERT en `:1129`). Un choke point
  único es exactamente lo que hace viable agregar una dimensión.
- **El grano ya es (item, outlet, location)** — el depósito ya es dimensión
  física de primera clase por fila. El precedente de "agregarle un eje al
  ledger" ya está sentado.
- **El patrón "próximo a vencer" ya está resuelto dos veces**, con la decisión
  difícil ya tomada: `InvoiceAuthNoticeService` (avisos de timbrado) y
  `PlanLifecycleService` usan **ventanas disjuntas** (`d7` = (3,7], `d3` =
  [0,3], `expired` = [-7,-1]) en vez de igualdad de día, justamente para que
  un día sin corrida del cron no pierda el aviso para siempre. La pieza
  reusable es `api/lib/Notifications/TenantNotice.php` (a qué email va —el
  dueño, no el cajero—, fecha en la zona del tenant, envío que no tumba el
  job). Un tercer aviso de esta familia se engancha ahí.
- **El harness de cron existe**: `api/docker/cron/crontab` + el router de jobs
  de `api/v1/maintenance.php`, con auth por secreto compartido.
- **El centro de notificaciones del panel ya sabe derivar vencimientos EN
  VIVO** sin persistir el aviso: `FeedService` mezcla filas de `notify` con
  obligaciones calculadas al momento (`ObligationsService`, horizonte ≤7 días)
  y sólo persiste el estado por usuario, con `alertKey` determinística. Si la
  obligación se resuelve, el aviso desaparece solo. Un lote por vencer encaja
  en ese molde sin inventar nada.

## 3. La trampa: `inventory` es una tabla de lotes muerta

Existe en el schema (`db-schema-postgres.sql:395-416`) con las columnas ya
diseñadas para esto — **`inventoryExpirationDate`**, `inventoryUID` (el lote),
`inventoryCount`, `inventoryCOGS`, `inventoryType` (0=activo/1=merma/2=vendido)
— y hasta con índices. Es de la época en que se pensó FIFO/FEFO.

**No tiene un solo lector en todo el repo.** Sólo aparece en los DELETE de
limpieza al borrar una sucursal o una empresa. El veredicto ya está escrito en
el propio código (`api/lib/Outlets/OutletsService.php:307-315`): *"nunca se
implementó… si algún día se hace vencimiento/lotes se diseña de cero sobre el
ledger. El DROP va en una mig posterior"*. Coincide con la D5 de `context/52`.

**Revivirla sería el error.** Es una tabla de saldos por lote —o sea, un
segundo lugar donde vive el stock— y `context/52` es todo el trabajo de haber
llegado a que la única fuente sea el ledger. La primera consecuencia práctica
de este análisis es que **la tabla se dropea**, no se hereda.

Segunda trampa de nombres: **"lote" ya significa lote de PRODUCCIÓN** en el
dominio (`production_batch`, mig 194, con su propio doctype de numeración). El
lote de vencimiento necesita otro nombre en el código para no colisionar.

Y una tercera: una **variante** de ítem es otra fila de `item` (un SKU
vendible), no una sub-dimensión del saldo. Un lote no puede modelarse igual —
el cliente no compra "el lote B", compra el producto.

## 4. El punto de diseño que decide todo

**¿Dónde se asigna el lote cuando el stock SALE?**

Al entrar es fácil: quien recibe una compra o termina una producción sabe qué
lote está cargando. Al salir es donde se decide si esto es referencial o no:

- **Referencial**: la venta NO elige lote. El ledger de `stock` no cambia en
  absoluto. El lote es un registro paralelo de "qué entró y cuándo vence", y
  el sistema alerta sobre eso. La contra, que hay que decirla: **el saldo por
  lote se vuelve una estimación**, porque las salidas no se descuentan de un
  lote concreto. Sirve para "tenés mercadería que vence el martes" — no para
  "te quedan exactamente 8 del lote A".
- **Valorizado**: cada salida consume lotes por FEFO y el ledger crece un eje.
  Toca los 23 call-sites, el costo promedio ponderado y la explosión de
  recetas de producción.

El pedido del owner dice "más que nada referencial". Bajo esa lectura, el
alcance mínimo con valor real es: **registrar lotes al ENTRAR, y alertar**.

## 5. Preguntas abiertas para el owner

1. **¿El saldo por lote puede ser aproximado?** Es la consecuencia directa de
   "referencial" y conviene que esté dicha antes de construir, no después: si
   el dueño espera que el número por lote cierre exacto contra el stock total,
   entonces lo que quiere es la versión valorizada.
2. **¿Qué se hace cuando un lote vence?** ¿Sólo se avisa, o el sistema empuja
   una merma (que sí toca stock)? Hoy "Vencimiento" ya existe como motivo de
   ajuste manual, suelto, sin fecha ni lote asociado.
3. **¿El POS tiene que ver algo?** Avisarle al cajero "esto vence mañana" al
   venderlo es otra feature: implica bajar lotes al bootstrap del device, que
   hoy no baja ni el desglose por depósito.
4. **¿Se controla por ítem o por comercio?** Un supermercado tiene lotes en la
   fiambrería y no en el bazar. Un flag por ítem (como `trackInventory`) evita
   pedir vencimiento en cada compra de todo el catálogo.
5. **¿Alcanza con el email al dueño, o el aviso va también al panel?** La
   infraestructura soporta las dos; el feed del panel es poll de 60s.

## 6. Arquitecturas a evitar (antes de proponer nada)

- **Revivir la tabla `inventory`** — ver §3. Es un segundo lugar donde vive el
  stock; contradice la D2 de `context/52` (lector único) que ya costó un plan
  entero.
- **Modelar el lote como variante del ítem** — una variante es un SKU
  vendible con su propio precio y código de barras; un lote no lo es.
- **Meter el lote en `stock` sin decidir antes el §4** — agregar la columna es
  la parte fácil; el problema es qué la llena en las SALIDAS. Una columna que
  las entradas llenan y las salidas dejan en NULL da un ledger que no cierra
  contra sí mismo.
- **Un job que "descuente" lotes vencidos automáticamente** — vencer no es
  salir del inventario: la mercadería sigue físicamente ahí hasta que alguien
  la retira, y ese retiro es una merma con autor. Un ajuste automático de
  stock sin persona detrás rompe la trazabilidad que el resto del módulo
  sostiene.

## 7. Dónde se tocaría (mapa, no plan)

| Capa | Archivo | Qué |
|---|---|---|
| Schema | `api/database/migrations/postgres/` | Tabla nueva de lotes; DROP de `inventory` |
| Escritura | `Inventory::manageStock()` `:987` | Único choke point; las ENTRADAS aportan el lote |
| Entradas | `PurchasesService::create()` `:685`, `ProductionService::complete()` `:698`, `StockTransferService::create()` `:213` | Dónde se captura el dato |
| Alertas | `api/v1/maintenance.php` + `api/docker/cron/crontab` | Job nuevo, clon de `InvoiceAuthNoticeService` sobre `TenantNotice` |
| Panel | `FeedService::buildItems()` `:103,151` | Fuente derivada nueva con `alertKey` propia |
| POS | `lib/pos-bff/reshape.ts`, `lib/types/pos-bootstrap.ts` | Sólo si la pregunta 3 se responde que sí |

## 8. Docs relacionados

- `context/52-stock-ledger-unica-fuente.md` — la ledger única; su D2 (lector
  único) y D5 (tablas espurias) gobiernan este análisis.
- `context/modules/05-stock.md` — cómo funciona el stock hoy.
- `context/modules/08-compras.md` — la recepción, principal punto de captura.
