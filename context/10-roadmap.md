<!-- REGLA: Este es el roadmap único del proyecto. Actualizar cuando:
     - Se completa un item (mover a _archive-roadmap-completado.md)
     - Se agrega un item nuevo
     - Cambian las prioridades
     - Se cierra una fase o se abre una nueva
     Items históricos completados archivados en _archive-roadmap-completado.md -->

# 10 — Roadmap Técnico (vivo)

Roadmap único del proyecto Punto POS. Solo items vivos / abiertos.
Items completados archivados en [_archive-roadmap-completado.md](_archive-roadmap-completado.md).

> **Última actualización:** 2026-07-29 (fulfillment F-D-0/F-D-1 completo, repartidor asignable, geocoding Photon, P0 ventas resuelto)

---

## Correcciones pendientes (reportadas por el owner)

Lista corta de bugs concretos reportados durante el uso. Se vacía a medida que
se arreglan — lo que crece acá es señal de deuda, no de backlog.

**Auditoría 2026-07-30**: se revisó todo el listado contra el código (no contra
la memoria de quién reportó qué). De ~24 bugs anotados quedan tres categorías:
los que siguen abiertos por decisión de producto pendiente (el owner tiene que
resolver qué se hace, no es un bug de código), los que necesitan repro en
producción (no se pueden confirmar leyendo el código solo), y los que ya
estaban arreglados y nadie había cerrado — esos se movieron a
"Cerrados en la auditoría del 2026-07-30" al final de la sección, con la
evidencia de dónde quedó el fix.

**Resueltos el 2026-07-29** (pendientes de confirmación en uso): descuento de
venta visible y con estado en el menú; UUIDs de medios de pago en Control de
Caja (resuelto en las dos puntas + agrupación por nombre, así el mismo medio no
aparece dos veces); cantidades decimales en el carrito; recibo al pagar una
factura a crédito; cotización que salía en blanco; ventas guardadas que tumbaban
la página; y el chat del agente, que ahora muestra los errores que antes se
tragaba.

### Consumo a cuenta de empresa (viandas) — el caso real detrás de "Interno"

Contado por el owner (2026-07-29). **No es una venta interna: es consumo a
cuenta que se factura al cierre del período.** Un restaurante con convenio
entrega almuerzos a empleados de una empresa; no cobra en el momento, registra
quién pidió y cuánto; a fin de mes emite UNA factura a la empresa por el total,
y la empresa exige el detalle de consumo por empleado.

**Lo que hacen hoy es un workaround**: cada plato sale como *crédito + interno*
— interno para que no emita factura legal, crédito para que no sume en caja
pero quede como pendiente de pago. Cada cliente recibe un link público con su
estado de cuenta. Funciona por efecto colateral de dos flags, no porque el
modelo lo contemple: hoy además el flag `interno` ni siquiera llega al backend
(ver el ítem de abajo), así que esos consumos están tomando numeración fiscal.

**Solución propuesta** (a validar con el owner antes de ejecutar):

1. **Comprobante como documento propio** (no fiscal, contador propio —
   `registerBoletaNumber` está libre). Es lo que respalda la entrega del plato:
   legitima lo que ya hacen en vez de que un flag ignorado tome números de
   factura. La gift card también emite Comprobante (decisión del owner
   2026-07-29), lo que reemplaza la regla actual "gift card → Recibo".
2. **Empleado → Empresa**: el consumo de un empleado acumula en la cuenta de la
   EMPRESA. Requiere relación padre/hijo entre contactos; verificar si el
   modelo actual de `contact` ya la soporta o hay que agregarla.
3. **Facturación por período**: elegir empresa + rango, juntar los comprobantes
   de consumo de sus empleados y emitir **UNA factura fiscal** por el total,
   dejando cada comprobante vinculado a esa factura
   (`parentTransactionId`). Eso da las dos cosas que el cliente necesita: un
   único documento fiscal y el detalle por empleado como respaldo.
4. **Estado de cuenta público**: ya existe por cliente; extenderlo al nivel
   empresa, consolidando por empleado.

⚠ **Invariantes que hay que respetar o se rompe la contabilidad**:
- Los comprobantes de consumo **no son ventas fiscales** y no deben sumar
  ingresos: al facturar el período, el reporte tiene que contar **la factura O
  los comprobantes, nunca los dos** (doble conteo — el error clásico de este
  modelo).
- La factura del período debe registrar **qué comprobantes cubre**, para
  auditar y para que no se facturen dos veces.
- El consumo no toca la caja; el pago de fin de mes sí.

⚠ **Terminología** (duda planteada por el owner): "Recibo" y "Comprobante" se
parecen. En el código conviene fijarlos como conceptos distintos y que nunca
colisionen: `receipt` = recibo de dinero por pago de una factura a crédito;
`comprobante` = documento no fiscal de entrega/consumo. Mantener la palabra que
ya usa el personal, pero documentar la diferencia donde se define
`PrinterDocType`.

- **El botón "Interno" no hace nada — la venta interna consume numeración
  FISCAL** (2026-07-29, hallazgo). El owner definió el catálogo de documentos:
  Factura · **Comprobante (sin valor fiscal, numeración aparte, se activa con
  "Interno")** · Recibo (pago de crédito) · Nota de crédito (devolución) ·
  Remisión · Cotización · Orden. Verificado contra el código: el Comprobante
  **no existe en ninguna capa**.
  - El front manda `interno` en el payload (`lib/commands/create-sale.ts:297`)
    y **el backend no lo lee en ningún lado** (cero referencias a `interno` en
    PHP): el flag muere en el borde de la API.
  - Por lo tanto la venta se persiste como contado type 0 y toma
    `registerInvoiceNumber` — o sea, **una venta sin valor fiscal está
    quemando números de factura**, con el agravante de correlatividad
    número↔fecha de `context/29`.
  - La impresión también lo ignora: `pay-dialog.tsx` elige
    `hasGiftcardIssuance ? "receipt" : "factura"`, sin mirar `interno`. Una
    venta interna imprime **Factura**.
  - `PrinterDocType` (`lib/hardware/printers/binding.ts`) no tiene
    `comprobante`; los bindings de impresora no pueden apuntarle.
  - Contador libre disponible: **`registerBoletaNumber` está en el schema y no
    lo usa nadie** (0 referencias) — candidato natural para la numeración
    aparte del Comprobante.
  ⚠ No se implementó: definir tipo de documento fiscal, contador y ruteo es
  decisión del owner. Lo que sí es seguro afirmar es que hoy el botón engaña.
- **Imprimir la venta EN CURSO — falta definir el documento** (2026-07-29).
  La entrada del drawer de opciones sigue sin conectar, a propósito: el intento
  de conectarla la emitía con docType `receipt`, y en este sistema el Recibo es
  un documento FISCAL (respalda el pago de una factura a crédito). Emitirlo para
  una venta que todavía no existe, sin transactionId ni número, genera un papel
  con forma de comprobante fiscal que no respalda nada. Lo que corresponde es un
  documento NO fiscal tipo pre-cuenta, que hoy no está modelado. **Decisión de
  producto pendiente**: qué es ese documento, qué lleva y si necesita plantilla
  propia.
### Relevamiento del cliente — "Módulo caja" (doc del 2026-07-28)

Bugs concretos del documento. Los que el propio doc marca resueltos (gift cards,
control de caja) no se repiten acá. **Verificar cuáles siguen vivos después del
deploy de hoy antes de atacarlos** — varios eran síntomas del P0 de `itemWaste`,
que abortaba la transacción de CUALQUIER venta y la mandaba a la cola offline.

Los cinco bugs de esta lista ya arreglados (vencimiento/pago inicial de
crédito, cotización en blanco, ventas guardadas, decimales, descuento por
artículo) se movieron a "Cerrados en la auditoría del 2026-07-30" al final de
la sección. Quedan acá los que necesitan repro en producción, ver
"Pendientes de reproducción" abajo.

Cubiertos por el trabajo del 2026-07-28, **pendientes de confirmación del
cliente**: el `25P02` en ventas a crédito y el "todas las ventas aparecen sin
conexión" (los dos eran el P0 de `itemWaste`, commit `da46d29f`), y la vista
previa de impresión que no se parecía a la plantilla configurada (catálogo de
bloques, `3cab66b1` — con la salvedad de que razón social, RUC, email y timbrado
todavía no viajan al POS y salen vacíos).

### Pedidos de funcionalidad del cliente (doc "Módulo caja", 2026-07-28)

No son correcciones: son features a planificar y priorizar aparte.

- **Cierre de turno con listado de productos vendidos** imprimible.
- **Panel de transacciones más rico** — el cliente pidió más detalle del que hay
  hoy (adjuntó referencia visual en el doc).
- **Pago de facturas a crédito desde Clientes** y **línea de crédito por cliente**
  (hoy no hay dónde cargarla).
- **Transacciones del contacto** dentro de su ficha, para anular o reimprimir.
- **Gift card: permitir cargar un código manual** — el que genera el sistema no
  lo reconoce al canjear.
- **Catálogo del POS en vista vertical** al abrir un grupo desde hotkeys, para
  que se vean imagen y detalle del producto.
- **Espacios**: imprimir pre-cuenta, cambiar/unir espacio, asignar mozo,
  renombrar, etiquetas. (Cobro por partes YA existe: split de cuenta F3.)
- **Órdenes**: notas y etiquetas por pedido; atajo de teclado `O` para saltar a
  órdenes.
- **Pedidos de viandas** (semanales/mensuales por cliente o empresa, con resumen
  de consumiciones por fecha) — es un módulo, no un ajuste.
- **Chat de soporte embebido** en un costado de la pantalla.
- **Facturación electrónica** — ya en curso, ver
  [28-facturacion-electronica-plan.md](28-facturacion-electronica-plan.md).

### Pendientes de reproducción (no se pueden verificar leyendo código)

- **Espacios: cobrar la mesa por el total dice "servidor no conectado"** — se
  corrigió la clasificación de 5xx y el timeout el 2026-07-27, pero el error de
  fondo nunca se diagnosticó. Falta: reproducir en un tenant con espacios
  activos y capturar la respuesta real del servidor al cobrar.
- **Al cobrar no se ven medios de pago distintos de Efectivo.** ⚠ NO se
  reproduce (owner, 2026-07-28) — pasa en algún caso particular sin
  identificar. Hoy 2026-07-30 se arregló un 401 de `/v1/payment-methods` que
  hacía caer el POS a los medios de fallback (commit `83854750`) — puede haber
  sido la causa de este reporte. Falta: confirmar con el owner si sigue
  pasando después de este fix.
- **Cierre de caja 500** (`Drawer action error 500: Error al cerrar caja`). El
  doc lo marca resuelto en otro punto pero el mensaje quedó registrado. Falta:
  reproducir el cierre de caja y ver si el 500 todavía sale.
- **El agente no completa la creación de producto** (2026-07-29). El chat ya
  muestra los errores (antes se los tragaba), pero la causa de fondo sigue sin
  identificarse — el logging se agregó recién. Falta: reproducirlo de nuevo y
  revisar los logs server-side del intento.
- **Pago de factura a crédito sin comprobante**: al pagar desde el detalle de
  una transacción a crédito no ofrecía imprimir el recibo de dinero. Falta:
  confirmar si el fix del 2026-07-29 (recibo al pagar una factura a crédito,
  ver nota arriba) ya cubre este caso o si es un flujo distinto.

### Cerrados en la auditoría del 2026-07-30

- **Venta a crédito sin fecha de vencimiento ni pago inicial** — la UI de
  vencimiento y la de pago inicial ya existen
  (`frontend/components/register/pay-dialog.tsx:1060` y `:1145-1195`).
- **Ventas guardadas no se pueden reabrir** — arreglado, commit `229e654c`: el
  GET leía el JSONB con `ncmExecute` y el flatten lo borraba.
- **Cantidades decimales bloqueadas al clickear una línea del carrito** — el
  pad abre en modo decimal
  (`frontend/components/register/qty-edit-dialog.tsx:33-42`).
- **Cotizaciones: la vista previa / PDF sale en blanco** — resuelto el
  2026-07-29, confirmado.
- **Descuento con artículo-descuento no afecta al total** — superado por el
  rework de descuentos del 2026-07-30 (ver abajo): ahora se reparte por ítem
  y queda reflejado en `itemSold.itemSoldDiscount` y en el total real
  cobrado.

**Rework de descuentos y reglas del owner (2026-07-30)**: el descuento
global se repartía sobre el total sin tocar `itemSold.itemSoldDiscount`
(commit `e7a3ad8d`, `frontend/lib/cart/allocate-discounts.ts`, reparto por
resto mayor). El owner cerró dos reglas que una implementación previa mía
había asumido mal (commit `dbbc4aca`): el descuento global congela su
alcance a las líneas presentes al aplicarlo (lo agregado después no se
descuenta), y un producto no puede tener más de un descuento a la vez
(individual bloquea al global). `saleDiscount` pasó a
`{value, mode, lineIds}`. Devoluciones y "cuánto se pagó a crédito" ahora
usan el neto, no el bruto (`9d46ad12`). El toggle "quitar IVA" también
tenía el mismo tipo de bug (persistía mal entre carrito y payload) —
arreglado con mig 101 (`transaction.ivaRemoved`) y `lineGross()` como
fuente única de la fórmula del bruto.

## Módulos nuevos ✅ (cierre 2026-07-19 / 2026-07-27)

- **Producción v1** ✅ — plan `context/23-production-module-plan.md`. F0 recetas canónicas en `item_compound` (mig 75), F1 `production_order`+`waste_event` (migs 76/77, permiso `production.manage`), F2 UI `/produccion`. Pendiente: v2 (parcial/co-productos/reversa).
- **Órdenes** ✅ (O0-O2) — plan `context/24-orders-module-plan.md`. O0 core (`pos_order`/`order_station`, correlativo advisory-lock, canal realtime `kds`), O1 modal POS (Pagar↔Ordenar, comandas), O2 KDS+display device-paired WS. Pendiente: O3 reservas, O4 ecommerce/agenda.
- **Espacios v1** ✅ (ex Mesas, rename migs 81/82) — plan `context/15-espacios-module-plan.md`. F0/F1 schema+editor (react-rnd), F2 operación POS (mapa, sesión, cobro multi-orden). **F3 split de cuenta ✅ (2026-07-27)**: mig 90 (`space_session_payment`+`settledpaymentid`) + mig 91 (índice único anti doble-cobro) + `SpaceSettlementService`, UI 4 modos (total/por ítems/monto libre/partes iguales), no se mezclan familias de modo en una misma mesa.
- **Estación de Impresión (pool)** — plan propio `context/26-print-station-plan.md` (cerrado 2026-07-19: estación router tonto device-paired + cola durable `print_job` + opt-in por binding). P0 backend + P1 pantalla ✅. Pendiente P2 (panel + rama pool del pipeline) y P3 (formatos inkjet/matricial). ⚠ Impresoras de RED no alcanzables desde el browser — ver hallazgo en el doc.
- **SLA de tiempo por orden + Delivery (O4)** — plan `context/27-delivery-sla-plan.md` (2026-07-19). **Historial de transiciones F-EVT-0 ✅ (2026-07-27)**: migs 85/86, tabla `pos_order_event` (scope order|item, actor, station snapshoteado), `recordEvent()` en los 6 caminos que tocan status, misma TX — base del SLA. SLA target = máximo por estación (trabajo paralelo entre estaciones). Delivery con `fulfillment`/`out_for_delivery` **✅ completo (2026-07-29)**: F-D-0 (mig 94, snapshot de dirección, selector Mostrador/Retiro/Envío, mapa filtrado) + F-D-1 (mig 96 estado "En camino", mig 97 `courierid`/asignación de repartidor). Fiscalidad del `deliveryfee` resuelta: ítem del catálogo, cascada zona→banda. Abierto: app propia del repartidor (decisión cerrada — entra como usuario con permiso acotado, no device pareado — falta implementar).
- **KDS — rediseño de flujo horizontal (2026-07-27)**: de columnas por estado a comandas en fila única, estado = color (la tarjeta nunca se mueve), pin local, teclado completo, recall (terminadas salen del board, "devolver a preparación" las trae de vuelta). El KDS nunca está desatendido — TV siempre con teclado/mouse detrás.
- **Libreta de direcciones (2026-07-27)**: extendida sobre `customerAddress` existente (mig 87: `reference`+soft-delete), parser de coords centralizado en `lib/geo/parse-coordinates.ts`.

---

## Pendiente: F-auth-jwt-only — eliminar `$_SESSION` de /app y /panel

**Objetivo**: todas las rutas de auth usan JWT puro (cookie HttpOnly). `$_SESSION` se usa hoy para almacenar datos del tenant en `panel/` y algo en `app/`. Con Redis sessions ya configurado, las sesiones son más confiables, pero la deuda arquitectónica sigue siendo que session y JWT coexisten.

**Alcance estimado**:
1. **Fase 1 (`/app`)**: `app/handoff.php` ya no necesita `$_SESSION` — JWT es la única fuente de verdad del device pairing. Identificar los `$_SESSION` restantes en `/app` y migrar a claims del JWT o a endpoints del BFF.
2. **Fase 2 (`/panel`)**: el panel usa `$_SESSION` para datos del tenant (company, outlet, user). Migrar a un endpoint BFF bootstrap que los BFFs pidan por JWT. `session_start()` desaparece del setup del panel.

**Beneficio**: container PHP truly stateless — sin sesiones PHP ni en disco ni en Redis para la app lógica (Redis sessions sigue necesario si se quiere clustering, pero no debería ser un requisito de funcionalidad).

**Deuda transitoria registrada** (no bloquea ningún trabajo actual):
- `panel/bff/handoff.php` genera `redirectUrl='/@#dashboard'` hardcoded (deuda menor — deberá venir de config o del JWT).
- `app/bff/electronic_invoice.php` expone `send_verification.php` directamente sin pasar por el BFF canónico (deuda arquitectónica).
- Algunos paths legacy en `/app` y `/panel` aún tienen `APP_URL` hardcodeado en strings (deuda del de-hardcode de dominios).
- Runner automático de migraciones sigue pendiente (ver sección Migration Runner).
- **DB.php duplicado** (`/app/includes/lib/DB.php` ≡ `/panel/includes/lib/DB.php`): la deuda se manifestó cuando la copia de panel evolucionó (whereParams en AutoExecute) y la de app quedó atrás → bug 500 silente en TODOS los `ncmUpdate` con WHERE parametrizado. Sincronizadas en el commit del bug fix; **consolidar a un solo archivo compartido** (ej. `/shared/lib/DB.php` o composer autoload) para prevenir drift futuro. Mismo problema potencial con otros archivos duplicados del legacy (functions.php tiene subset overlap entre app/panel).
- **JSONB routing helpers duplicados** (`generateUuidV7`, `_getTableSchema`, `_routeToJsonb` + cuerpos completos de `ncmInsert`/`ncmUpdate`): viven en `/panel/includes/functions.php:4602-5016` y, post-fix, también en `/app/includes/functions.php` (porque el slice 10 PSR-4 había reemplazado los wrappers por delegación a `Query::insert/update` que NO hacía routing — bug "column does not exist" en cualquier INSERT con campos demoted a JSONB). Sincronizados en el commit del bug fix; **consolidar a un módulo compartido** (ej. `/shared/JsonbRouter.php`) cargado desde ambos legacy entry points. `Query::insert/update` ahora delega a `ncmInsert/ncmUpdate` para forzar single source of truth.

## Schema consolidation — campos no-queryables → JSONB (post-F4)

**Idea (charlada 2026-06-10):** generalizar el patrón ya aplicado a `company.config`/
`outlet.data`/`plans.features` (columnas indexables + JSONB para el resto) a las dos tablas
grandes restantes: `contact` (~25 cols) e `item` (~30 cols). El objetivo es flexibilidad y
schema evolution sin migraciones por cada flag nuevo.

**Tradeoff crítico — NO mover todo:** la regla es **indexable y queryable** se queda en
columna; el resto va a `data` JSONB. Hoy `contact` tiene 6 índices específicos
(`idx_contact_phone_company`, `idx_contact_email`, `idx_contact_tin`, `idx_contact_name`...)
porque la app filtra por esos campos — moverlos a JSONB rompería los índices y los casts
`->>` no siempre usan GIN (lo descubrimos cuando `p.features->>'inventory'` necesitó
`COALESCE((...)::int, 0)`).

**Proceso por tabla:**
1. Auditar con `pg_stat_user_indexes` qué columnas se usan realmente en WHERE/JOIN.
2. Migración: `ALTER TABLE` agrega `data JSONB`, backfill no-destructivo desde columnas,
   los `_flattenJsonb` ya re-exponen las keys como propiedades (lectura sigue funcionando).
3. WRITE de cada Service actualizado para enrutar las keys movidas a `data`.
4. `DROP COLUMN` de las viejas en una segunda migración tras N releases (deuda controlada).

**Timing:** post-F4 (cuando el shell `@.php` esté desacoplado y `$_SESSION` muerto). Hacerlo
mid-F2/F3 sería migrar blanco móvil.

---

## Análisis del módulo de inventario / producción / recetas (charlado 2026-06-10)

### Inventory widget — deuda de semántica (bloqueante cierre F2)

Único reporte que no migró en F2. `panel/lib/reports/ReportInventoryService::widget()` delega a
`getAllInventoryAndItemsModule()` (panel/includes/functions.php:2570). El KPI declara "valor de
inventario" (cost, sell, qty) pero su lógica tiene tres problemas:

1. **Fuente cruzada**: itera `getAllItems()` y multiplica por `inventory[id]['cogs'] * inventory[id]['onHand']`,
   donde `onHand` viene de `getAllItemStock` (que lee el ledger `stock`, no la tabla `inventory`).
   Si `stock.stockOnHand` (denormalizado) divergió de `SUM(inventory.inventoryCount WHERE type=0)`,
   el KPI miente sin error.
2. **Sin scope de outlet**: el comentario dice "por sucursal" pero la función NO recibe outletId.
   Suma TODOS los outlets. Para una company multi-sucursal el número es la suma del tenant, no de
   la sucursal activa.
3. **Bug PG conocido** (comentario del Service): el legacy usaba `BETWEEN "..."` con comillas dobles
   que en PG son identificadores, no strings literales — devuelve 0/0/0 silencioso si no se arregló.

**Decisión de producto pendiente:**
- ¿El widget muestra "inventario total del tenant" o "inventario del outlet activo"? Distintos KPIs.
- ¿Suma valor incluye sub-partición depósito? (no debería: el stock por location es sub-partición
  del stock por outlet — sumarlos doble-cuenta. Ver [[project-jerarquia-dominio]]).
- ¿Combos y precombos cuentan? Un combo no tiene stock propio (vende ingredientes); un precombo sí.
  Si se incluyen ambos como "inventario", se valúa dos veces el mismo COGS.

Hasta resolverlo, el widget queda en panel local. Migración técnica = 30 min cuando haya criterio.

### Mapa del módulo (estado actual)

**7 tablas con responsabilidades superpuestas:**

| Tabla | Rol declarado | Quién la escribe | Problema |
|---|---|---|---|
| `inventory` | Batches físicos (count, COGS, expiration) | purchases, count, OutletsService::create, sale (`type=2`=sold) | Es batches FIFO/FEFO pero también tiene `inventoryType` con 3 estados (active/waste/sold) — mezcla concerns |
| `stock` | Ledger de movimientos (event log) | manageStock (god-node, 27 callers), production handler | Tiene `stockOnHand` y `stockOnHandCOGS` denormalizados — running balance dentro del log → fuente cuádruple de "stock actual" |
| `stockTrigger` | Materializado: stock actual por (item, outlet) | a_items.php triggers config, manejo de umbrales | Solo se usa para umbrales de reabastecimiento — su nombre confunde con "running total" |
| `inventoryCount` | Sesiones de conteo físico (auditoría) | a_inventory_count.php | OK — concern aislado |
| `toCompound` | Recetas (item → ingredientes con qty) | CompoundService (F2) | OK — relación N-a-N limpia |
| `toLocation` | Sub-partición del stock por depósito | manageStock | Cumulativo por (item, location) — debería sumar al `stockOnHand` del outlet pero el código no garantiza la invariante |
| `production` | Log de producciones ejecutadas | functions.php:8507 | Concurrencia con `stock` (cada producción escribe AMBAS) — audit duplicado |

**Cuatro fuentes de verdad para "stock actual de item X en outlet Y":**
1. `stock.stockOnHand` del último row (item, outlet) — ledger denormalizado
2. `stockTrigger.stockTriggerCount` — materialized cache
3. `SUM(inventory.inventoryCount) WHERE inventoryType=0` — batches activos
4. `SUM(toLocation.toLocationCount)` — sub-partición depósito (debería ≤ 1, 2 y 3)

**Estas 4 pueden divergir** (no hay constraint que las una). El POS y el panel usan distintas según
el caller — getCurrentStock, getItemMainStock, getAllItemStock, displayableCompounds tienen lógica
propia. Riesgo real de "el stock que ve el panel ≠ el stock que ve el POS".

**God-node `manageStock`** (panel/includes/functions.php:8287 + duplicada en `app/Domain/Inventory.php:349`).
27 callers. Centraliza la escritura del ledger + sub-partición — pero NO actualiza `stockTrigger`
ni `inventory.inventoryCount`. Esas las actualizan otros handlers (purchase, inventory_count,
production) por separado. Implícito y frágil.

**Tipos de item con semántica de stock distinta** (sin clase central que los discrimine):
- `product` → stock normal (descuenta al vender, suma al comprar)
- `combo` → vende los ingredientes (toCompound); el item combo no tiene stock propio
- `precombo` → producto pre-fabricado: se ejecuta producción → suma stock del precombo → al vender descuenta precombo
- `comboAddons` → combo configurable; mismo concepto que combo + selección
- `direct_production` → produce al vender (consume ingredientes al checkout, no antes)
- `compound` → sub-componente; sí tiene stock propio
- `discount`, `giftcard`, `dynamic`, `group` → no stock
- Las reglas viven repartidas en strings inline en a_items.php / a_bulk_*.php / action.php

### Problemas estructurales identificados

1. **Drift entre las 4 fuentes**: nada garantiza consistencia. Una venta offline del POS, una compra
   del panel y un conteo físico pueden dejar las 4 en estados distintos.
2. **`manageStock` duplicada panel vs /app**: dos copias del god-node con riesgo de divergencia
   funcional (ya pasó en el comment original de Inventory.php: "semántica preservada VERBATIM" —
   pero verbatim hoy no garantiza verbatim mañana). Cualquier fix en uno hay que replicarlo.
3. **Tipos de item sin discriminator**: la lógica "si itemType=combo entonces…" está esparcida en
   ~30 lugares. Imposible agregar un tipo nuevo sin cazar todos los if/switch.
4. **toLocation sin invariante**: la suma de `toLocationCount` por (item, outlet) debería igualar
   `stockOnHand`, pero no hay constraint ni trigger. Una venta sin location_id deja la sub-partición
   desactualizada.
5. **Producción tiene dos audits** (production + stock): los reports usan stock con
   `WHERE stockSource='production'` para reconstruir; bug latente si dejan de coincidir.
6. **inventoryType triple-meaning**: 0/1/2 = active/waste/sold mezcla concerns. Sold debería ser un
   evento del ledger, no un estado de batch.
7. **COGS calculation en manageStock** (panel/includes/functions.php:8290 ss): cálculo running de
   COGS promedio ponderado con casos especiales para stock negativo. Complejo y sin test unitario.
   Es money path → cualquier regresión cambia márgenes reportados.

### Propuesta — principios rectores

**1. Una sola fuente de verdad para "stock actual"**. Las otras son **derivadas** con triggers o
   materializaciones explícitas:
   - **Opción A**: `stock` ledger es source of truth. Stock actual = `SELECT stockOnHand FROM stock
     WHERE item, outlet ORDER BY stockDate DESC LIMIT 1`. `inventory` y `stockTrigger` se reconstruyen.
   - **Opción B**: `inventory` batches es source of truth (FEFO/FIFO real). Stock actual =
     `SUM(inventoryCount) WHERE active`. Ledger se mantiene como audit log derivado.
   - **Opción C**: tabla nueva `itemStock(itemId, outletId, count, cost)` con UNIQUE — running total
     materializado, ledger y batches alimentan vía triggers.

   **Recomendación**: opción C. PostgreSQL tiene la herramienta (LISTEN/NOTIFY + triggers); separar
   "balance actual" de "historial" elimina el riesgo de denormalización dentro del log.

**2. `toLocation` con invariante garantizado**. Trigger PG: `SUM(toLocationCount) WHERE item, outlet
   = itemStock.count`. Si el código quiere mover stock entre locations debe hacerlo vía función
   atómica `transferLocation(item, fromLoc, toLoc, qty)`.

**3. Discriminator de itemType**. Tabla `itemTypeRule(type, hasOwnStock, consumesOnSale,
   producesStock, requiresCompound)` — los handlers leen la regla en vez de hardcodear if/else.
   Agregar un tipo nuevo = un INSERT.

**4. `manageStock` único en `/api/lib/Inventory/`** (movible a `Punto\Api\Inventory\Service`).
   El panel y el POS llaman al mismo endpoint o al mismo Service in-process. Cero duplicación.

**5. Eventos del ledger explícitos**:
   - `purchase` → +itemStock, +inventory batch
   - `sale` → -itemStock, -inventory batch (FEFO consume primero)
   - `production` (precombo) → +itemStock(producto), -itemStock(ingredientes) por receta
   - `directProduction` (al vender) → -itemStock(ingredientes), sin afectar item producido
   - `transfer-outlet` → -outletA, +outletB (atómico)
   - `transfer-location` → -locA, +locB (mismo outlet, atómico)
   - `count` → ajuste delta = counted - current
   - `waste` → -itemStock, +log
   - `adjust` → manual

   Cada uno es una función pública del Service con tests propios. `manageStock` legacy se vuelve
   thin dispatcher.

**6. Concurrency**: SELECT FOR UPDATE en itemStock antes de mutar. Hoy el POS offline puede generar
   movimientos concurrentes al sincronizar — sin lock hay risk de last-write-wins silenciosa.

### Plan de migración propuesto (incremental, no big-bang)

| Fase | Qué | Riesgo | Reversible? |
|---|---|---|---|
| **I0** | Documentar invariantes esperados y escribir tests de regresión sobre stock actual para 5 escenarios reales (purchase → sale → production → count → transfer). Sin tocar código. | Bajo | N/A |
| **I1** | Tabla nueva `itemStock` + backfill desde el ledger. Solo lectura por ahora — comparar contra las 3 fuentes existentes con un `/api/v1/diagnostics/stock-divergence` que cuenta divergencias. Telemetría. | Bajo (read-only) | Sí (drop table) |
| **I2** | Servicio `Punto\Api\Inventory\StockService` con métodos por evento (purchase/sale/production/...). Endpoint `/api/v1/inventory/movements?event=X`. Convive con manageStock legacy. | Medio | Sí (revert) |
| **I3** | Triggers PG que mantienen `stockTrigger`, `inventory` aggregate e `itemStock` sincronizados desde el ledger. La tabla `itemStock` pasa a writable solo vía el service. | Medio-alto (triggers en money path) | Trigger DROP |
| **I4** | Itemtype discriminator → tabla + service. Refactor de los handlers que tienen `if itemType=combo`. | Alto (afecta a_items, action.php, bulk_*) | Por slice |
| **I5** | Migración POS: `app/Domain/Inventory::manageStock` deja de escribir directo y llama al endpoint `/api/v1/inventory/movements`. La copia panel se elimina. | Alto (offline mode del POS) | Por endpoint |
| **I6** | Concurrency: SELECT FOR UPDATE + retry policy. inventoryType colapsa a un solo concern (waste/sold pasan al ledger). | Alto | Difícil |

**Timing**: NO ahora. F3 (oleadas legacy) sigue siendo prioridad — F3 toca a_items / a_purchase /
a_bulk_* que son el mismo terreno, hacerlo en paralelo es migrar blanco móvil. Post-F4 es la
ventana correcta. **I0 sí se puede hacer ahora** (documentación + tests sin tocar código) y queda
de plataforma para los demás.

### Pendientes de decisión antes de I0

- ¿Producción y direct_production siguen siendo dos tipos distintos o se colapsan a uno con flag
  "consume al vender vs pre-fabricar"?
- ¿precombo es un caso especial o un alias de producción?
- ¿Permitimos stock negativo en el ledger o lo rechazamos en el service?
- ¿El conteo físico (inventoryCount) corrige hacia el counted o registra un evento "ajuste"?

## Principios del roadmap

- **Progresivo**: cada fase es independientemente deployable.
- **No regresivo**: el código legacy sigue funcionando mientras el nuevo se introduce en paralelo.
- **Smallest safe step**: nada de rewrites completos, solo cambios quirúrgicos y acumulativos.

---

## Estado actual del sistema

| Aspecto | Estado |
|---------|--------|
| Backend | PHP 8.4, sin framework, wrapper PDO propio (`api/includes/lib/DB.php`, sin ORM ni librería externa) ✅ |
| DB | PostgreSQL 16 + Docker, migrations runner automático ✅ |
| Frontend (panel) | **frontend** (Next.js 15 + React 19 + shadcn/ui + TanStack Query). Legacy panel eliminado. ✅ |
| Frontend (POS) | **fusionado dentro de frontend** en `app/(pos)/pos` desde 2026-06-16. ✅ |
| Dominio | **app.punto.la** sirve panel + `/pos` + `/admin` + `/chat` + `/checkout` (POS legacy descontinuado, 2026-06-19) ✅ |
| Auth realms | 3 cookies JWT distintas: `_jwt_panel` (panel, 24h), `_jwt` (pos-app, device pairing 10y), `_jwt_screen` (checkout screen, 10y). Realm gate por `iss` claim. ✅ |
| IDs | UUID v4 (gen_random_uuid) ✅ — v7 fue dropeado en pivot |
| API | `/v1/*` endpoints con envelope canónico, multi-realm allowlist explícita por endpoint ✅ |
| WebSockets | ws-server propio (Node + Redis Pub/Sub) en `ws.punto.la`. Realtime sync panel↔POS funcionando (`<companyId>:invalidate` channel). ✅ |
| Catálogo | M2M completo (category/brand/tag con item_*), UNIQUE case-insensitive, ItemImporter con separadores `\|` (sprint 2026-06-19). ✅ |
| Reportes | Tablas de rollup pre-agregado (`report_rollup`, día/mes/año) — gated por `REPORTS_ROLLUP_ENABLED`. SummaryYear/Categories/Brands/PaymentMethods con cutover + `?verify=1`. ✅ |
| Checkout Screen | Visor al cliente con device pairing por token persistente (`customer_display`), pareo por PIN, realtime via WS. ✅ |
| Agente IA | Chat embebido (FAB + página `/chat`) vía **OpenRouter** (DeepSeek+Gemini default), AI SDK v6, 13 tools acotadas con `confirmToken`, billing débito atómico (`ai_credit_ledger`), historial localStorage con redacción de credenciales + auto-expiración 60s. ✅ AI-1..AI-3b. |
| Seguridad | CORS allowlist, JWT realm isolation, tenant_audit de mutaciones, device revocation, credenciales auto-redactadas en chat. ✅ |

---

## Vista general de fases

```
Phase 0 ✅ → Phase 1 ✅ → Phase 2 ✅ → Phase WS ✅ → Phase UUID ✅ → Phase PG ✅
                                  ↓
              frontend rewrite ✅ (greenfield Next/shadcn, plan context/12)
                                  ↓
                       Fusión POS → frontend ✅ (2026-06-16)
                                  ↓
                       Catálogo M2M ✅ (sprint 2026-06-19, context/14)
                                  ↓
                       Realtime sync ✅ (context/15)
                                  ↓
                       Checkout Screen ✅ (context/16)
                                  ↓
              Reports rollup RB-1+RB-2 ✅ (context/18) — RB-3 pendiente
                                  ↓
                  Agente IA AI-1..AI-3b ✅ (context/17) — AI-4/AI-5 pendiente
```

---

## Orden de ejecución actual (prioridad)

1. **AHORA**: estabilizar el sprint reciente (smoke tests en prod, calibración de pricing del agente, verificación numérica del rollup con `?verify=1`).
2. **Siguiente**: RB-3 (rollup stock/production/commissions/vpayments) + AI-4 (UI /admin para `ai_model_config`).
3. **Después**: AI-5 (OCR de facturas, análisis libre sobre rollup), AI-3 tools extras según uso, dashboards custom por IA.
4. **Paralelo / oportunista**: deuda técnica (JWT-only, schema consolidation) cuando bloquee algo.

---

# Prioridad ALTA (próximas 4-8 semanas)

## F2 — Backend pendiente del POS React (acumulado sesión 2026-06-16)

Estos TODOs están anotados en el código pero requieren backend para completarse. El POS React funciona hoy con stubs.

| # | Pendiente | Detalle |
|---|-----------|---------|
| 1 | ~~**Separar `_jwt_pos` de `_jwt_panel`**~~ ✓ | Cookies independientes ya funcionando (`_jwt` para realm pos-app, `_jwt_panel` para panel, `_jwt_screen` para checkout screen). Memoria [[jwt-two-tokens-rule]]. |
| 2 | **`POST /v1/device/unpair`** | Para "Eliminar dispositivo del comercio" (hoy no existe el endpoint). |
| 3 | **`POST /v1/lock-screen/verify`** | Verificar PIN contra backend + re-emitir `_jwt_pos`. Hoy: `STUB_PIN = "1234"` en `lock-screen.tsx`. |
| 4 | **`bootstrap.user.name` y `bootstrap.user.roleName`** | Agregar al SELECT del bootstrap PHP. `roleName` ya disponible en `UsersService`. |
| 5 | **Persistir `register.data.mergeRepeated`** | Hoy solo en memoria Zustand (default ON). Falta `PUT /v1/register?resource=merge-repeated`. |
| 6 | ~~**Endpoints reales de Control de Caja**~~ ✓ | Implementado: `DrawerService` + `api/v1/drawer.php`. Migs 33/34. |
| 7 | ~~**Endpoints reales de Transacciones**~~ ✓ | Detalle, edición, duplicar/reimprimir, cierre desde panel. Órdenes O0-O2 ✅ (2026-07-19, ver abajo); Agenda pendiente. |
| 8 | **Persistencia de impresoras** | Probable `register.data.printers` JSONB. |
| 9 | **UI panel para gestión de cajas POS pareadas** (`/settings/devices` tab "Cajas") | Tabla `device` (mig 11) ya existe con CRUD backend; falta tab en `/settings/devices` (hoy solo lista checkout screens). |

---

## frontend — Selector de sucursal en menú del usuario (NUEVO 2026-06-12)

**Feature ausente del frontend que SÍ existe en legacy.** En el menú dropdown del usuario (sidebar bottom) el panel legacy permite, cuando la cuenta tiene ≥2 sucursales:

1. Mostrar el **nombre de la sucursal activa** debajo del nombre de la empresa
2. **Cambiar la sucursal seleccionada** (o elegir "Todas las sucursales")
3. Esa selección **scopea** lo que el usuario ve en el resto del panel

### Reglas de scope por recurso

| Recurso | Comportamiento con sucursal seleccionada | Con "Todas" |
|---|---|---|
| **Stock / inventario** | Solo de esa sucursal | Consolidado de todas |
| **Reportes** (ventas, dashboard, etc.) | Solo de esa sucursal | Consolidado |
| **Cajas / drawers** | Solo de esa sucursal | Todas |
| **Contactos** | TODOS (no filtra — son del tenant, no del outlet) | Igual |
| **Items / catálogo** | TODOS (compartidos del tenant) | Igual |
| **Settings (taxonomies / empresa)** | TODOS | Igual |

### Implementación pendiente

1. **Bootstrap / contexto** — `useBootstrap()` ya trae `outletId` activo. Agregar `outlets[]` (lista para el selector) si no está. Verificar que el JWT ya carga `oid` (claim activo del JWT panel) y si no, exponer endpoint para cambiarlo.
2. **Backend** — chequear que cada endpoint que filtra por outlet:
   - `/v1/reports/*` — filtre por el `outletId` del contexto (debería ya).
   - `/v1/items?resource=inventory` — idem.
   - Para "Todas": pasar `outletId=null` o flag `?all=1` y SERVICE consolida (`SUM` cross-outlet).
3. **Frontend** — `app-sidebar.tsx` (`SidebarFooter` → dropdown user menu) agregar:
   - Subtitle del trigger = `bootstrap.activeOutletName` (en vez del actual vacío)
   - Item del dropdown "Sucursal actual: …" con sub-menu para cambiar
   - Mutation `useSetActiveOutlet(outletId|null)` → POST endpoint que re-emite JWT con nuevo `oid` claim + invalida queries cacheadas
4. **Persistencia** — el outlet activo viaja en el JWT (no en cookie aparte) — al cambiar, re-emitir el JWT panel (mismo handoff que existe ya).

### Por qué es importante

- **UX inconsistente** vs legacy — usuario que ya está acostumbrado a cambiar sucursal del legacy se confunde si en frontend no aparece.
- **Multi-outlet es caso común** — la mayoría de tenants medianos tiene 2-4 sucursales y necesita cambiar de scope diariamente (ej. el dueño revisa los 4 dashboards de la mañana).
- **Bloquea adopción del frontend** para tenants multi-outlet — sin esto no pueden migrar.

### Notas técnicas

- El `outletId` del JWT panel ya existe (`F0` del desacople 2026-06-10) pero falta UI para cambiarlo.
- `apiAuthTenant` ya devuelve `outletId` en el contexto — los services consumen vía `$ctx['outletId']`.
- "Todas las sucursales" probablemente requiere `outletId = null` en el JWT y que cada service haga `WHERE outletId = ? OR ? IS NULL` o branch sin filtro.

---

## Admin realm — super-admins de plataforma separados (iniciado 2026-05-28)

**Decisión**: los super-admins de plataforma dejan de ser un "tenant especial" (flag `SAAS_ADM` sobre `MASTER_COMPANY_ID`) y pasan a ser usuarios propios en `admin_user`, con login en `/admin` y JWT criptográficamente separado del realm tenant. Ver [ADR-002](context/adr/ADR-002-admin-realm-separado.md) y `02-arquitectura.md § Admin realm`.

**Dos realms aislados:** `_jwt_panel` (tenant, `JWT_SECRET`) nunca valida en `/admin`; `_jwt_admin` (`ADMIN_JWT_SECRET`, `aud:"admin"`) nunca valida en el panel tenant. Secrets + cookies + audience distintos.

**Login de tenant:** los tenants mantienen su login pero por **TELÉFONO** (no email). `findEmailOrPhoneLogin` ya tiene el branch de phone. El login de tenant NO se depreca, solo cambia el identificador.

**Franchiser:** sigue como realm tenant (`/panel/franchiser.php`, gateado por `isParent`) — NO va a `/admin`.

### 🆕 PIVOTE 2026-06-14 — el /admin se reescribe DE CERO en el stack de frontend

**Decisión del owner**: la UI del realm `/admin` (hoy vanilla JS + Bootstrap en
`panel/admin/*`) se reescribe **greenfield**, **mismo techstack que frontend**
(Next.js 15 App Router + TS + shadcn/ui + Tailwind v4 + TanStack Query).
**NO** nos guiamos por el legacy: no se replica su visual ni su estructura —
se diseña de cero (análogo al pivote del panel tenant → frontend del 2026-06-10).

- El backend del admin (realm aislado `_jwt_admin`/`aud:"admin"`, `adminMiddleware`,
  tablas `admin_user`) **se conserva** — lo que muere es la UI legacy, no la auth.
  Las F0–F6 de abajo quedan como referencia funcional de QUÉ hace el admin, NO de cómo se ve.
- El billing del admin agregado el 2026-06-14 (grantAiCredits / setAddons /
  listRequests / resolveRequest en `CompanyAdminService` + drawer legacy
  `panel/admin`) es **transitorio**: vive en el legacy hasta que exista el shell
  admin en frontend. Migrar esas pantallas al nuevo /admin cuando se construya.
- Prerequisito: auth de realm admin en el `/api` compartido (hoy solo existe en
  `panel/API/v1/admin/*`) o un BFF admin equivalente en el nuevo app, + shell/layout
  + auth-guard del admin en el stack frontend.
- El legacy `panel/admin/*` se borra cuando el nuevo /admin lo cubra 100% (mismo
  criterio que el panel tenant).

### Plan de fases (6 fases, no big-bang — cada una deployable) — UI legacy, ver pivote arriba

| Fase | Qué | Estado |
|------|-----|--------|
| **F0** | Tabla `admin_user` (migración 09) + `bootstrap_seed.php` (CLI idempotente, bcrypt) + vars `.env` (`ADMIN_JWT_SECRET/TTL`, `ADMIN_BOOTSTRAP_EMAIL/PASSWORD`). Verificado E2E en DB local. | ✅ HECHA (commit 01a8929, 2026-05-28) |
| **F1** | Auth del realm `/admin`: login email+pass, JWT propio (`_jwt_admin`, `aud:"admin"`), `adminMiddleware`, `login.html` estático + BFF, rate-limit. | ✅ HECHA (commit 96f8b8f, 2026-05-28) |
| **F2** | CRUD de admins en `/admin` (modelo BFF 3 capas). No permitir desactivar el último admin activo. | ✅ HECHA (commit 89e7388, 2026-05-28) |
| **F3** | Home `/admin` + migrar gestión de companies + billing desde `main.php` (queries cross-tenant aisladas en `lib/admin`). | **✅ COMPLETA — F3.1+F3.2+F3.3+F3.4+F3.5 hechas** |
| **F4** | ⚠️ RIESGO ALTO — desacoplar `SAAS_ADM`/`MASTER_COMPANY_ID` del panel tenant (quitar redirect `@.php:11`, limpiar `config.php`). Va ÚLTIMO porque rompe el gate de identidad legacy. | **✅ HECHA (commit ea7b67f, 2026-06-07)** |
| **F5** | Login de tenant por teléfono (no email) — independiente de F1–F4. | **✅ HECHA (commit ccfa676, 2026-06-07)** |
| **F6** | Decommission de `main.php` como admin + hardening + verificar aislamiento de realms E2E. | **✅ HECHA (commit d310fe4, 2026-06-07)** |


> **Notas técnicas detalladas de F0..F3.5 / F4 / F5 / F6 archivadas** en [_archive-roadmap-completado.md](_archive-roadmap-completado.md) — solo referencia histórica.

---

## ~~Migration Runner~~ ✅

Implementado en `database/migrate.php` + `docker-entrypoint.sh`. Lee `schema_migrations`, compara con `database/migrations/postgres/`, aplica pendientes en orden numérico (no lexicográfico), fail-fast con exit 1. Corre en cada deploy de Coolify antes de exec'ear el CMD.

---

## Sprint 2026-06-19 a 2026-06-21 — entregado

Conjunto grande de slices ejecutados en sesiones consecutivas (Opus orquesta + Sonnet ejecuta + pasada de review). Detalle en `_session-log.md`.

### Catálogo M2M ✅ (plan `context/14`)

- Migs 37 (dedup), 38 (UNIQUE case-insensitive en taxonomy/category/brand/tax), 39 (tabla `tag` + `item_tag` + triggers bidireccionales)
- `Taxonomy::getIdOrInsert` con LOWER comparison + retry ON CONFLICT (race-safe)
- Endpoint `/v1/tags` + `TagService` + branches `resource=brands|tags` en `items.php`
- `/settings/catalog` tab Etiquetas (grid 4-col)
- Form de item con `BrandsPicker` + `TagsPicker` multi-select
- `ItemImporter` acepta listas separadas por `|` en CATEGORIA/MARCA/ETIQUETAS

### Realtime sync panel ↔ POS ✅ (plan `context/15`)

- `realtimePublish` helper PHP wire en `apiAuthTenant::realtimeAfterMutation` (mapeo cerrado endpoint→entity)
- Cliente WS singleton en `frontend/lib/realtime.ts` con reconnect exponential
- `useRealtimeSync(scope)` mapea entity→queryKeys de TanStack
- ws-server deployado en Coolify a `wss://ws.punto.la`
- Convención mantenida: el mapa `realtimeAfterMutation` debe incluir TODO endpoint mutante (caso clásico de drift: `/v1/sales` faltaba)

### Checkout Screen ✅ (plan `context/16`)

- Mig 40 `customer_display` con token persistente (10y)
- Endpoints `/v1/screens/{request,pair,publish,heartbeat,list,revoke}` con Redis fsockopen+RESP (NO phpredis)
- POS publica cart-update/sale-confirmed/cart-cleared
- Sección "Pantalla cliente" en AjustesPanel del POS con dialog InputOTP
- Ruta `(screen)/checkout` standalone (pairing/live/confirmed/idle states)
- `/settings/devices` con CRUD pantallas + revocación

### Reportes rollup pre-agregado — RB-1 + RB-2 ✅ (plan `context/18`)

- Mig 41: `report_rollup` (genérica con métricas fijas + extra JSONB), `rollup_dirty`, funciones `rollup_recompute_period` / `rollup_reconcile`, backfill histórico inline
- Mig 42 extiende a `item_sales`/`item_returns`/`payments`
- Hook `rollupMarkDirty` en `SaleService` (post-CommitTrans) + DrawerService + edición de transacciones
- `RollupReader` con `monthlyBuckets` / `itemSalesRange` / `paymentsRange`
- Cutover `SummaryYearService`/`Categories`/`Brands`/`PaymentMethods` — GATED por `REPORTS_ROLLUP_ENABLED` (default OFF = live; activar tras `?verify=1` con diff vacío)
- **Procedimiento activación** documentado en `context/18` §"Procedimiento de cutover"

### Finanzas Fase 1-3 ✅ (plan `context/22`)

- Fase 1-2: mig 72 (`fin_account`/`fin_category`/`fin_movement`/`fin_check`/`fin_reconciliation`) + Account/Category/Movement/Check/Reconciliation services + endpoints `/v1/finance/*` + UI (Resumen/Cuentas/Categorías/Movimientos/Cheques/Conciliación/Ajustes) + permiso `finance.manage`. Cuenta "Efectivo" del sistema (issystem, mapeo fijo); Ajustes lee medios de pago REALES del tenant (taxonomía `paymentMethod`).
- **Fase 3 (auto-integración, 2026-07-02)**: mig 73 (UNIQUE por `(companyid,source,sourceid,accountid)` para split-payment) + `FinanceLedger` (re-lee la fila de origen; sirve para hook en vivo Y backfill) + hooks best-effort en sales/credit-payments/purchases/transactions + `DrawerService`→`ncmInsert` + backfill CLI + `POST /v1/finance/backfill` (advisory lock) + botón "Importar histórico"
- **Idempotencia de saldo atómica**: `recordDerivedMovement` con `INSERT ... ON CONFLICT DO NOTHING RETURNING` — delta a `currentbalance` una única vez, sin TOCTOU
- **TODO Fase 4**: returns (`transactionType=2/6`) no generan movimiento (sobreestiman ingresos); backfill sin cap de tiempo; dashboard cashflow
- **CRUD de medios de pago ✅ (2026-07-02, branch `pay-methods-crud`)**: `PaymentMethodService` + `/v1/payment-methods` (CRUD sobre `taxonomy` paymentMethod, auto-seed Efectivo/T.Crédito/T.Débito). Tab "Medios de pago" en Settings → Catálogo (`CatalogManager` genérico con `switch`/`select`). `ConfigService::resolveAccountId` dual-path (UUID nuevo vs slug legacy backfill). POS bootstrap trae métodos reales con fallback seguro. `pay-dialog.tsx` re-keyeado a `systemKey` en vez de `id` literal para giftcard/interno
- **Color + orden de medios de pago ✅ (2026-07-02, branch `pay-methods-color-order`)**: medios de pago ganaron color y orden por drag&drop (`dnd-kit`). Paleta unificada (`lib/ui/color-palette.ts`) + `ColorPicker` canonico aplicada tambien a Hotkeys/Usuarios/Impresoras (ver `context/20` §4.8.1)

### Agente IA AI-1..AI-3b ✅ (plan `context/17`)

- Mig 43 `ai_model_config` (capability→model+creditsPerKToken, editable desde /admin — UI pendiente AI-4)
- AI-1: route handler `/api/agent/chat` con AI SDK v6 + OpenRouter (DeepSeek default), tool `get_sales_summary`, FAB + Sheet
- AI-2: gate 402 + débito atómico en `/v1/ai/debit` (lock FOR UPDATE) + ledger `agent_chat`
- AI-3: 13 tools (5 lecturas + 8 escrituras con `confirmToken`) — alcance acotado por memoria [[ai-agent-scope-limits]] (NO ventas/caja/permisos/bulk/hard-delete)
- AI-3b: refinement UI (FAB neutro, MessageCircle, input ChatGPT-style, página `/chat` con sugerencias, integración al menú POS sin overlay conflict)
- Markdown render + acciones (copiar / leer Web Speech API)
- Historial persistente (Zustand persist + localStorage, hook `useAgentChat` compartido FAB/página)
- Credenciales: formato dictado por system prompt + auto-expiración real 60s + redacción ANTES de persistir (defense in depth)

### Bug fixes notables del sprint

- Wrapper PDO: agregados `Affected_Rows`, `BeginTrans/CommitTrans/RollbackTrans` (services del API los llamaban con esos nombres del wrapper legacy que la clase actual todavía no exponía)
- `Taxonomy::getIdOrInsert` usaba `$SQLcompanyId` global vacío en /api → duplicados en imports (commit `6ec65bb`)
- `transactions.php` detalle: `CaseInsensitiveArray` no es `JsonSerializable` → frontend recibía `{}` (fix con `GetRowAssoc()`)
- Cookies JWT mezcladas tras cambio de dominio frontend-dev → app.punto.la: nuevo `/v1/logout` para borrar solo `_jwt_panel`
- `screens.php` usaba `new Redis()` (extension phpredis no instalada) → fix con fsockopen+RESP igual que `wsPublish`
- Mig 37 inicial asumía columnas que no existen en BD real (`outlet.taxId`, `outlet.categoryId`) → self-heal con `information_schema` check
- Barra de categorías POS con ancho fijo (no se ve fea con 1 sola)
- Teléfonos: display nacional, backend E.164 (`formatPhone` helper)
- Logout del menú sidebar tenía `onClick={onLogout}` pero la prop nunca se pasaba → wire en `PanelAuthGuard`

---

# Prioridad MEDIA (2-4 meses)

## Phase AI — Agente IA del producto

> **Estado actual (2026-06-21):** AI-1, AI-2, AI-3 y AI-3b ✅ ENTREGADOS en el sprint reciente — chat embebido funcional en producción. Stack: OpenRouter (NO Anthropic SDK directo como originalmente planeaba este doc), AI SDK v6 + `@ai-sdk/react`, React/Next embebido en frontend (NO microservicio FastAPI). Ver memorias [[ai-agent-openrouter-direction]] y [[ai-agent-scope-limits]] + plan `context/17-ai-agent-plan.md` para arquitectura real.
>
> El texto debajo refleja la VISIÓN ORIGINAL — útil como referencia conceptual (correctitud, dashboards por IA, reportes ad-hoc) pero los detalles de implementación están desactualizados (FastAPI, Python, microservicio). Lo que SÍ aplica vivo: la regla de oro (tool calling determinista, NUNCA text-to-SQL libre) y la visión de "reportes ad-hoc + dashboards custom por IA" (AI-5 pendiente).

### Pendientes reales de Phase AI

> **Norte de largo plazo (2026-07-30):** `context/30-ai-agent-roadmap.md` — el
> agente como capa transversal del ERP: registry de tools por dominio + router
> de dos etapas (floor tools + dominios lazy), OCR de facturas/menús, CRUDs por
> sectores, web_search y análisis sobre rollups. AI-5 queda subsumido en las
> fases F2/F5 de ese doc.

| Slice | Scope | Prioridad |
|---|---|---|
| **AI-4** | UI en `/admin` para editar `ai_model_config` (modelo + creditsPerKToken por capability). Calibración de pricing real vs costo OpenRouter para que el margen cierre. | Alta |
| **AI-5** | Capabilities extra: OCR (foto de factura → Gemini extrae ítems), análisis libre sobre rollup (queries NL → leen `report_rollup`), dashboards custom guardados en `dashboard.config` JSONB. → detalle en `context/30` (F2/F5). | Media |
| **AI-tools++** | Expandir las 13 tools iniciales según uso: tools para reportes específicos, búsquedas avanzadas, recomendaciones (top sellers, stock bajo). | Media |
| **AI proactivo** | Cron que detecta condiciones (stock bajo, ventas anómalas) y notifica al operador. Necesita canal — el FAB del agente puede mostrar un badge. | Baja |
| **AI multi-canal** | Telegram / WhatsApp / SMS. Mismo agente, otros transports. Requiere vincular usuario externo → JWT del tenant (flow de pareo). | Baja |

### Histórico — visión original (referencia conceptual)

## Phase AI.1 — Agente IA básico (HISTÓRICO — ver sección anterior para estado real)

**Problema**: El valor diferencial del producto es ser AI-first. Sin agente funcional
no hay diferenciación.

**Dependencia**: backend de los módulos consultados expuesto como Services/API limpia (las tools del agente leen de ahí). Refuerza la estrategia backend-first de `02-arquitectura.md`: cada módulo modernizado le da más superficie de datos al agente. NO requiere modernizar el frontend de los reportes — el agente los reemplaza.

**Cliente LLM**: OpenRouter (no Anthropic directo), SDK `openai` apuntando a OpenRouter. El "tool use" es function calling estándar (formato OpenAI).

### Visión

Un agente autónomo que habla con la API de Punto via JWT. Los usuarios interactúan con el sistema por chat (widget web, Telegram, WhatsApp) en lenguaje natural. El agente interpreta la intención, llama los endpoints correctos y devuelve respuestas formateadas.

**El agente tiene dos facetas que comparten la misma base** (tools deterministas + LLM orquestador):

1. **Chatbot / asistente genérico** — el usuario pregunta sobre sus datos ("¿cómo van las ventas?", "¿qué producto se vende menos?"), pide recomendaciones ("¿qué debería reponer?", "¿conviene este combo?") y obtiene ayuda operativa. Conversacional, libre.

2. **Analista de datos** — reemplaza la proliferación de reportes hardcodeados (~13K líneas en `report_*`). El usuario pide reportes ad-hoc en NL y **dashboards customizados**: describe qué quiere ver, la IA arma la estructura, se guarda, y se renderiza en vivo. Ver "Reportes y dashboards por IA" abajo.

**Decisión (2026-05-24)**: el agente es el reemplazo de los reportes exploratorios, no un chatbot decorativo. Los reportes legales/contables (facturación, libro de ventas, impositivos) se quedan hardcodeados — formato exacto y auditable, la IA no aporta ahí.

### Regla de oro — correctitud y seguridad (NO negociable)

En finanzas/inventario los números deben ser **exactos**; un dato mal calculado hace que el dueño decida con info falsa.

- **Tool calling determinista, NUNCA text-to-SQL libre.** El LLM elige entre funciones acotadas y parametrizadas (`ventas_periodo(desde, hasta, agrupar_por)`, `top_productos(n)`, `stock_bajo()`). Cada tool ejecuta SQL fija filtrada por `companyId`. El LLM **no escribe SQL ni hace aritmética** — solo decide qué preguntar y presenta el resultado.
- **Por qué**: text-to-SQL libre = fuga multi-tenant (leer otra company), queries que tumban la DB, y JOINs mal hechos = números errados con cara de correctos.
- **Aislamiento de tenant**: el JWT del usuario fija `companyId`; toda tool lo aplica en el WHERE. El LLM nunca recibe ni elige el `companyId`.

### Reportes y dashboards por IA

- **Reporte ad-hoc**: pregunta NL → el LLM elige tool(s) → datos exactos → respuesta formateada (texto + tabla/gráfico).
- **Dashboard custom**: el usuario describe ("ventas diarias del mes, top 5 productos, alertas de stock bajo") → la IA genera una **config** (JSON: lista de widgets, cada uno = una tool + params) → se guarda la config (`dashboard.config` JSONB por usuario/company) → el dashboard se renderiza **en vivo**, cada widget llama su tool.
- **KPIs guardados = DEFINICIONES, no valores.** Se persiste la fórmula/tool/params del KPI, nunca el número calculado (quedaría viejo y, si lo calculó la IA, podría estar mal). Los datos son siempre frescos y deterministas.
- La IA hace el trabajo creativo (estructurar) **una vez**; los números vienen de queries cada vez.

### Arquitectura

```
Telegram / WhatsApp / Widget Web
         ↓
    punto-agent/  (microservicio Python + FastAPI)
    ├── Interpreta intención (LLM via OpenRouter — function calling)
    ├── Llama tools deterministas → panel/API/* con JWT del usuario
    └── Formatea y devuelve respuesta (texto / tabla / config de dashboard)
         ↓
    panel/API/  (los endpoints existentes, sin modificar)
```

### Por qué esto funciona sin tocar el monolito

El agente solo necesita el JWT del usuario y los endpoints. No sabe nada de PHP ni de la base de datos. La API de Punto es su única interfaz.

### Tools de Claude (cada tool = un endpoint)

```python
tools = [
    {
        "name": "get_sales_report",
        "description": "Obtiene ventas de un período",
        "input_schema": {
            "properties": {
                "date_from": {"type": "string"},
                "date_to": {"type": "string"}
            }
        }
    },
    { "name": "get_stock_level", ... },
    { "name": "create_order", ... },
    { "name": "get_customers", ... },
    # ~20 tools para los casos de uso más frecuentes
]
```

### Casos de uso iniciales

| Faceta | Ejemplo de input | Action |
|--------|-----------------|--------|
| Chatbot | "mandame el cierre de hoy" | `ventas_periodo(hoy)` → resumen formateado |
| Chatbot | "cuánto stock me queda de Coca Cola" | `stock_nivel` con filtro |
| Chatbot (recomendación) | "¿qué debería reponer?" | `stock_bajo` + razonamiento sobre el resultado |
| Analista (reporte ad-hoc) | "ventas del mes pasado por categoría" | `ventas_periodo(..., agrupar_por=categoria)` → tabla/gráfico |
| Analista (dashboard) | "armame un dashboard con ventas diarias, top 5 productos y stock bajo" | genera config JSON → se guarda → render en vivo |
| Escritura (AI.3+) | "registrá una venta de 2 hamburguesas" | `create_order` |
| Proactivo (AI.5) | (sin trigger) stock bajo detectado | Alerta automática |

### Stack técnico

```
punto-agent/
├── main.py              # FastAPI app
├── agent.py             # Lógica del agente (Claude tool use)
├── tools/
│   ├── sales.py         # Wrappers para endpoints de ventas
│   ├── inventory.py     # Wrappers para items/stock
│   └── orders.py        # Wrappers para órdenes
├── channels/
│   ├── telegram.py      # python-telegram-bot
│   └── whatsapp.py      # Meta Cloud API o Twilio
└── auth.py              # Vincula usuario Telegram/WA → JWT de Punto
```

### Auth del agente

```
Usuario envía /start en Telegram
    → Bot genera código de vinculación de 6 dígitos
    → Usuario ingresa el código en el panel de Punto
    → Panel registra: telegram_id ↔ companyId + JWT
    → El agente usa ese JWT para todas las llamadas futuras
```

### Fases de implementación

| Fase | Scope | Prioridad |
|------|-------|-----------|
| AI.1 | Agente básico (OpenRouter) + widget web chat + 5 tools de solo lectura (ventas, items, stock, clientes) | Alta |
| AI.2 | **Reportes ad-hoc por NL** — el chatbot responde preguntas de datos con tablas/gráficos (reemplaza reportes exploratorios) | Alta |
| AI.3 | **Dashboards customizados** — IA genera config JSON, se guarda en `dashboard.config`, render en vivo. KPIs = definiciones | Alta |
| AI.4 | Recomendaciones (reponer stock, combos, productos lentos) sobre los datos de las tools | Media |
| AI.5 | Integración Telegram + bot de reportes | Media |
| AI.6 | Tools de escritura (crear órdenes, registrar ventas) | Media |
| AI.7 | WhatsApp (Meta Cloud API) | Media |
| AI.8 | Alertas proactivas (cron que monitorea + notifica) | Media |
| AI.9 | Contexto persistente por usuario (memoria conversacional) | Baja |

**Esfuerzo MVP (AI.1)**: ~2 semanas. AI.2/AI.3 reusan las mismas tools — el costo es el frontend (chat + render de tablas/dashboards), no nuevo backend.

---

## CDN Local completo

**Problema**: Algunos assets todavía referencian CDNs externos. Offline-first requiere todo local.

**Propuesta**: Mover todo a `/assets/vendor/`. Ya hay avance parcial.

**Esfuerzo**: ~4 horas para completar

---

## Higiene de assets — vendoring vía npm (EN CURSO 2026-05-27)

**Objetivo**: gestionar los ~55 vendor JS de `assets/vendor/js/` vía `package.json` (provenance,
versión pineada, `npm audit`) en vez de archivos "misteriosos" commiteados. Alpine ya entró así.

**Plan**: `npm i --save-exact pkg@<versión-vendoreada>` para los npm-ables; `build.sh` (o un
`vendor-sync`) copia el dist canónico de `node_modules/` a `assets/vendor/js/`. **Pinear EXACTO**
(no bumpear — jQuery 3.6.3 / Chart 2.9.4 / Bootstrap 3.4.1 están congelados a propósito).

**Quedan como archivo** (no en npm / custom): `iguider`, `jquery.businessHours-1.0.1` (npm
`business-hours` es otro paquete) + el código propio del proyecto (`ncm.js`, `common.js`,
`documentPrintBuilder`, `ncmMaps`, etc. — no son vendor).

**Bumps de major a testear** (npm no tiene la versión vieja o difiere): `@fingerprintjs/fingerprintjs`
v3→v5, `jsrsasign`, `snap` (verificar snap.svg vs el "snap-1.9.3" vendoreado).

### Follow-up: reemplazar iguider (tour de onboarding)

`iguider` (~107 refs en dashboard/purchase/app POS) no está en npm y parece medio abandonado (hay
un `iguider.stub.js` → puede estar ya stubbeado). **Reemplazo recomendado: `driver.js` 1.4.0 (MIT,
~5KB, sin deps, vanilla).** Shepherd/intro.js son **AGPL** → descartados para SaaS comercial cerrado.
**Antes de migrar: verificar si el tour está activo** — si está muerto, ELIMINAR iguider en vez de
reemplazarlo. Es scope aparte (cambio de comportamiento del tour).

---

# Prioridad BAJA (largo plazo)

## Phase 6 — Arquitectura moderna (Slim 4)

**Dependencia**: Phases 1-5

**Problema**: El monolito PHP sin framework dificulta testing, middleware chains, DI.

**Propuesta**: Introducir Slim 4 como app paralela bajo `/v2/...`. Un endpoint a la vez.

**Esfuerzo**: Setup inicial ~2 días. Migración gradual.

**Riesgos**: Complejidad de mantener dos stacks. Solo hacer cuando haya masa crítica de endpoints nuevos.

---

## Phase AI.4+ — WhatsApp, alertas proactivas, memoria

Items futuros del agente IA. Ver sección "Phase AI.1" arriba para detalle completo.

---

# Decisiones técnicas vigentes

| Decisión | Elección | Razón |
|----------|----------|-------|
| Lenguaje backend | PHP (mantener) | Sin capacidad de rewrite completo |
| WebSockets | ws-server propio (Node.js) | Eliminar costo de Pusher |
| Pub/Sub | Redis | Ya en el stack, sin dependencia extra |
| JWT | HS256 custom PHP | Sin composer dependency adicional |
| IDs en API | UUID v7 (post Phase UUID) | Hashids deprecados, enc/dec son identity |
| API location | Dentro de panel/ (mantener) | No vale la pena separar aún |
| AI Agent | Microservicio Python separado | No tocar el monolito |
| Conexión BD legacy | `panel/API/lib/legacy_db.php` helper | Centraliza migración MySQL→PG sin tocar lógica |


---

# Variables de entorno completas

```ini
# Seguridad (Phase 0)
APP_DEBUG=false
HASHIDS_SALT=<random-64-char>

# JWT (Phase 1 + 2)
JWT_SECRET=<random-64-char>
JWT_TTL=28800

# WebSocket (Phase WS)
WS_URL=wss://ws.tudominio.com
REDIS_URL=redis://redis:6379

# AI Agent (Phase AI — pendiente)
ANTHROPIC_API_KEY=sk-ant-...
TELEGRAM_BOT_TOKEN=...
WHATSAPP_ACCESS_TOKEN=...
PUNTO_API_BASE=https://panel.tudominio.com/API
AGENT_JWT_SECRET=...

# Feature flags (Phase 6 — pendiente)
USE_V2_ITEMS=false
USE_V2_CONTACTS=false
```

---

# Notas técnicas importantes

## Patrón de migración endpoints `api_head.php` → envelope canónico

```php
// ANTES
include_once('api_head.php');
// ... lógica ...
jsonDieResult($data, 200);

// DESPUÉS
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
// ... lógica sin cambios ...
apiOk($data);
```

## Notas de `api_middleware.php`

```php
// CRÍTICO: $db debe ser global antes de incluir db.php y functions.php
// porque functions.php llama getAllPlans() en scope global en línea 3
global $db, $ADODB_CACHE_DIR, $plansValues, $countries;

// enc()/dec() no están en functions.php, están redefinidos en el middleware
// (en post-Phase UUID, son identity passthrough)
```

## Helper `legacy_db.php` (para endpoints MySQL→PG)

Reemplaza el bloque MySQL hardcoded por:

```php
require_once __DIR__ . '/lib/legacy_db.php';
```

Que internamente:
- Carga `cors.php`
- Conecta a PG via `includes/db.php` (creds desde `.env`)
- Carga `simple.config.php` + `functions.php`
- Define `enc/dec` defensivamente como identity

## Estructura del proyecto

```
system/
├── app/                    # POS — módulo de caja (PHP legacy)
├── panel/                  # Admin/ERP (PHP legacy)
│   ├── API/                # ~93 endpoints REST
│   │   ├── lib/
│   │   │   ├── response.php       # Envelope canónico ✅
│   │   │   ├── api_middleware.php # Middleware JWT ✅
│   │   │   └── legacy_db.php      # Helper conexión PG para endpoints legacy ✅
│   │   └── auth.php               # Login JWT ✅
│   ├── includes/
│   │   ├── simple.config.php      # Constantes globales
│   │   ├── jwt.php                # JWT HS256 puro PHP
│   │   ├── db.postgres.php        # Conexión PG (legacy)
│   │   ├── db.pdo.php             # Conexión PG (PDO — actual)
│   │   └── ws_publish.php         # Publica eventos a Redis
│   └── standalone/
│       └── scripts/
│           └── ncm-ws.js          # Cliente WebSocket (drop-in Pusher) ✅
├── ws-server/              # Microservicio Node.js WebSocket ✅
├── database/
│   ├── migrations/postgres/
│   └── seeds/
├── docker-compose.yml      # PostgreSQL + Redis + pgAdmin + ws-server
└── context/                # Kit de contexto para Claude (este roadmap está acá)
```

---

## Backlog testing 2026-07-07 — Panel + POS (feedback testers)

**Fiscal/Reportes:**
- Export RG90 / Libro de ventas (pedido 2x)
- Filtros en transacciones (contado/crédito/internas, cajero, cliente, documento) + columnas tipo doc/método/caja
- Detalle de documento enriquecido (tipo, número, emisión, vencimiento, sucursal, cliente, responsable, IVA desglosado, descuentos)
- Reporte productos detallado (usuario/cliente/documento/fecha por venta)
- Export + imprimir en todos los reportes
- Notificación de facturas crédito por cobrar
- Reporte de transferencias de stock (usuario, sucursal, receptor, productos, fecha) formato nota de remisión
- Medios de pago vista detallada (documento, cliente, RUC, método, sucursal, total)

**Catálogo/Inventario:**
- Crear categoría inline desde el form de artículo
- Sesiones configurables para servicios tipo paquete
- Stock mínimo con notificación automática
- Columnas stock actual + costo de stock en listado de artículos
- Historial de movimientos por artículo
- Imprimir listado de artículos
- Conteo de stock filtrado por categorías
- Producción generable desde producción previa
- Movimiento de inventario → link al documento origen + columna ingresos

**Compras/Gastos:**
- Packs de compra (1 caja = N unidades)
- Categorías de gastos con subcategorías
- Recordar último costo de compra por producto
- Factura de compra contado vs crédito

**Otros:**
- Comisiones por usuario (Gs o %)
- Control de cajas imprimir/PDF + edición por rol admin/jefe
- Timbrado y prefijos en config de caja registradora desde el panel
- Posicionamiento fino de bloques en editor de plantillas (arriba/abajo/ancho/alto)

---

> Items completados archivados en [_archive-roadmap-completado.md](_archive-roadmap-completado.md).
