# Viandas — pedidos, producción por lote, reposición y cobro a cuenta

> Plan sin implementar. Escrito 2026-09-03 a partir de la conversación con el
> owner de ese día. D1-D6 cerradas por el owner; P1-P5 propuestas SIN su OK
> explícito (no objetadas en la conversación, pero no confirmadas). Leer
> §Arquitecturas rechazadas antes de proponer nada.
>
> Absorbe y corrige la sección "Consumo a cuenta de empresa (viandas)" de
> `context/10-roadmap.md` (2026-07-29), que cubría solo la etapa C y tenía el
> comprobante al revés (ver D3).

## El problema (palabras del owner)

Un restaurante vende almuerzos a oficinas. A la mañana toma los pedidos por
WhatsApp: cada cliente elige platos del menú del día y el restaurante junta
todo — "20 ñoquis con tuco, 50 milanesas con puré, 14 sopas de tomate". Eso
pasa a cocina como una orden de producción donde cocina necesita saber, en
base a las recetas, **cuánto de cada insumo va a usar por el total** — no por
plato: la sopa lleva tomate y los ñoquis también, así que los tomates se
suman. Con eso ve si alcanza y compra lo que falta; el sistema tiene que
calcularlo.

Después los platos se preparan, se envían y se entregan a cada cliente.

Lo complejo es el cobro, porque los clientes son de tres tipos **mezclados
todos los días en los mismos pedidos**:

- Empresas que pagan a fin de mes lo consumido por sus empleados, y exigen el
  detalle de qué pidió cada empleado. Una factura por mes por el total.
- Clientes que pagan antes de que se prepare el pedido.
- Clientes individuales que también pagan a fin de mes.

Lo que se pide es un modelo que por lo menos haga al restaurante **eficiente
y ordenado**.

## El modelo — tres etapas y media

```
A. Pedidos          B. Producción            B.5 Reposición           C. Entrega y cobro
──────────          ─────────────            ───────────────          ──────────────────
pos_order × N   →   lote multi-plato     →   necesidad            →   entrega por pedido
(lote del día)      explota recetas          {ítem, cant, origen}     ├─ prepago: nada (ya facturó)
condición de        agrega por insumo        cubierta por:            └─ mensual: COMPROBANTE
pago del CONTACTO   compara onHand           · compra (OC)                (venta a crédito no fiscal)
                    → faltante               · transferencia                 ↓ acumula en quien paga
                                             · producción             cierre de mes: FACTURA
                                                                       absorbe N comprobantes
```

Lo que hace al restaurante eficiente, en orden de impacto:

1. Pedido estructurado (platos del menú del día) en vez de texto de WhatsApp.
2. **Un clic** del lote de pedidos al lote de producción, con la necesidad de
   insumos ya agregada y el faltante calculado.
3. Lista de entrega agrupada por empresa/dirección.
4. Botón de cierre de mes por empresa: una factura + el detalle por empleado.

### Etapa A — Toma de pedidos

Cada pedido es un `pos_order` con delivery (ya existe). Lo nuevo:

- **Lote del día**: agrupación de los `pos_order` por fecha (y sucursal). No
  es una entidad nueva en v1 — es una vista/filtro. Si más adelante hace
  falta cerrar el lote ("ya no se toman más pedidos para hoy"), ahí se
  materializa.
- **Menú del día**: filtro sobre el catálogo (los platos ofrecidos hoy). No es
  un catálogo público ni un link para el cliente (D2).
- **Condición de pago en el contacto** (P3), no en el pedido. Es lo que hace
  tolerable que los tres tipos de cliente estén mezclados: el cajero carga el
  pedido y no decide nada sobre cobro — el sistema lo lee del cliente.

### Etapa B — Producción por lote

El motor de explosión de recetas **ya existe y es recursivo**
(`Inventory::explodeRecipeDetailed()`, con merma planificada por nivel y
guard de ciclos — ver `context/modules/06-produccion.md` §3 reglas 1-3).
Sumar los tomates de la sopa y de los ñoquis es sumar las hojas de la
explosión de todos los platos del lote. El motor ya produce eso; nadie lo
agrega todavía.

Lo que falta: `production_order` (mig 76) es **un plato por orden**. Hace
falta un **lote de producción multi-plato** que tome `{plato, cantidad}` × N,
explote todo, agregue por insumo, compare con `onHand` de la sucursal y
devuelva la necesidad — y de ahí, la reposición (B.5).

El lote **mueve stock** (D1): consume insumos y acredita platos terminados vía
el mismo `ProductionService::complete()` de hoy; la entrega descuenta platos.
Eso refleja lo físico (cocinan antes de entregar) y da COGS real por lote.

Que sea **opcional por comercio** (D1) NO es un flag del lote: ya es una
propiedad del ítem. `manageStock()` es no-op para `itemTrackInventory < 1`
(`Inventory.php:658-660`) — así funcionan agua y sal hoy: no mueven stock
pero SÍ entran al costeo. Un comercio que lleva el stock a mano tiene sus
insumos sin control de inventario y listo: el lote calcula, imprime la orden
de producción, arma la compra, y las operaciones de stock no hacen nada. Lo
único que cambia para ese comercio es honesto: sin `onHand` no hay
**faltante**, hay **necesidad total** — y eso es lo que se le muestra.

### Etapa B.5 — Reposición y orden de compra

**Reposición es la necesidad. Compra es una de las formas de cubrirla** (D6).
Tres orígenes ya identificados producen necesidades:

- El faltante del lote de producción (etapa B).
- El conteo de stock (`context/63`, F0+F1 implementadas 2026-09-02).
- El umbral `item.itemMinStock` (mig 133), que hoy solo avisa.

Y tres documentos **que ya existen** las cubren:

- **Compra** al proveedor → orden de compra (lo nuevo, D5).
- **Transferencia** desde otra sucursal o depósito propio → `stock_transfer`
  con doble entrada (`context/modules/07-transferencias.md`).
- **Producción** propia → `production_order`.

Se propone (P2) una entidad liviana **`necesidad de reposición`** con líneas
`{ítem, sucursal, cantidad, origen}` donde cada línea registra **con qué se
cubrió** (esta compra, esta transferencia, esta orden de producción) o si
sigue abierta. Una necesidad puede cubrirse en partes por caminos distintos
(20 kg: 12 del depósito central, 8 al proveedor) — eso sale solo si es una
entidad con líneas y no un botón.

**Orden de compra** (D5), el flujo completo:

1. Se genera desde la necesidad (o a mano). Se imprime o se manda por
   WhatsApp al proveedor.
2. El proveedor trae la mercadería. **Recepción**: el receptor chequea línea
   por línea lo que llegó contra lo pedido — faltó, vino de más, vino otra
   cosa.
3. La **factura de compra nace de la recepción confirmada**, no del papel del
   proveedor a secas.
4. Alternativamente el operador sube la factura del proveedor (foto/PDF) → la
   IA la extrae a un `purchase_draft` en borrador (**esto YA EXISTE**, mig
   105, `context/32`) → el operador la **relaciona con la orden de compra** y
   chequea lo que llegó → aprueba. Si el proveedor emite factura electrónica,
   el "subir" puede ser el XML del KuDE y el autocompletado es exacto, sin
   OCR (pregunta abierta).

La orden de compra es la **salida** del lote de producción: sin ella, el
faltante que calcula el lote no tiene a dónde ir. Viandas y órdenes de compra
no son dos módulos independientes.

### Etapa C — Entrega y cobro

La condición de pago del contacto decide qué pasa al entregar:

- **Prepago**: pagó antes de preparar → la venta con factura ocurrió al tomar
  el pedido. La entrega es solo cumplimiento, no emite nada.
- **Mensual** (individual o a cuenta de empresa): la entrega emite un
  **comprobante** (D3) que acumula en la cuenta de quien paga — el propio
  cliente, o su empresa vía `contact.parentId`.
- **Cierre de mes**: elegir empresa (o cliente) + rango → **una factura
  fiscal** por el total, vinculada a los N comprobantes que cubre (P1). El
  detalle por empleado sale de los comprobantes vinculados.
- **Estado de cuenta público**: existe por cliente; falta a nivel empresa,
  consolidando por empleado.

## Decisiones — cerradas por el owner (2026-09-03)

- **D1 — El lote de producción mueve stock, y que sea opcional es propiedad
  del ítem, no del lote.** Hay comercios que llevan el stock a mano pero
  necesitan igual la orden de producción y la compra. Resuelto con
  `itemTrackInventory` (ya existe): sin control de inventario, las
  operaciones de stock son no-op y el faltante degrada a necesidad total.
- **D2 — El pedido lo carga el cajero.** No es obligatorio que entre por link
  ni catálogo público (hoy no hay catálogo). Con que el operador lo ingrese
  alcanza.
- **D3 — El comprobante ES la venta: una boleta a crédito.** Cuatro
  propiedades: (1) **reconoce el ingreso del día** — el comercio necesita
  saber cuánto "vendió" aunque la plata no haya entrado; (2) crea la cuenta
  por cobrar; (3) no tiene valor fiscal; (4) no toca caja. Corrige el
  roadmap y una propuesta inicial de esta misma conversación que lo tenía
  como "no suma ingresos" — ver §Rechazadas.
- **D4 — Recibo ≠ Comprobante, y se mantienen las dos palabras.** Recibo =
  comprobante de pago de una factura a crédito (dinero que entra, cancela
  deuda). Comprobante = documento que avala la operación sin valor fiscal
  (entrega, crea deuda). Están en lados opuestos del libro; lo único que
  comparten es no ser fiscales. En código: `receipt` y `comprobante`, nunca
  colisionan. Sin aclaraciones extra en la UI — la aclaración es que cada
  palabra aparezca solo donde corresponde.
- **D5 — Orden de compra con recepción y vínculo al borrador OCR.** Flujo del
  §B.5 tal cual: generar, imprimir/WhatsApp, recepción línea por línea,
  factura desde la recepción; o subir la factura del proveedor → borrador →
  relacionar con la OC → chequear → aprobar.
- **D6 — Reposición y compra son conceptos distintos.** Un conteo puede
  generar ambos. Reposición es la necesidad; compra, transferencia o
  producción son cómo se cubre.

## Propuestas SIN OK explícito del owner

- **P1 — La factura mensual absorbe la deuda.** Es una transacción nueva
  vinculada a los N comprobantes por `transaction_link` (mig 115,
  `context/35`); los comprobantes quedan saldados *por la factura* (no por
  dinero) y la factura carga el total. Cobranzas sigue sobre un solo
  documento, que es como el cliente lo piensa ("me deben agosto"). La
  alternativa se descartó (ver §Rechazadas).
- **P2 — `necesidad de reposición` como entidad persistida** con líneas y
  cobertura parcial (§B.5). Alternativa descartada: calcularla al vuelo y que
  cada origen arme la compra directo.
- **P3 — La condición de pago vive en el contacto**: `prepago | mensual |
  a cuenta de` (con `parentId` → la empresa). El cajero no decide nada por
  pedido.
- **P4 — Lote del día y menú del día no son entidades** en v1: agrupación de
  `pos_order` por fecha y filtro del catálogo, respectivamente.
- **P5 — El comprobante es un docType propio con contador propio**
  (`registerBoletaNumber` está libre, según el roadmap). Reemplaza el
  workaround crédito+interno. Nota del roadmap que sigue vigente: la gift
  card también emite Comprobante (owner 2026-07-29).

## Invariantes — se rompe la contabilidad si no se respetan

1. **El ingreso se reconoce UNA vez, en el comprobante.** La factura de fin de
   mes es la *fiscalización* de ingresos ya reconocidos: emite los mismos
   montos bajo un número fiscal y **aporta cero ingreso nuevo**. Los reportes
   de ventas cuentan el comprobante; la factura mensual queda marcada para
   que no vuelvan a sumarla. (Este es el sentido correcto del "no doble
   conteo" — no "factura O comprobantes", que era el enunciado viejo.)
2. **La cuenta por cobrar nace en el comprobante** y la factura la consolida,
   no la duplica. Bajo P1, al facturar el período los comprobantes pasan a
   saldados-por-factura y la deuda viva es la de la factura.
3. **La factura mensual registra qué comprobantes cubre**, y un comprobante se
   factura **una sola vez**. Auditable y a prueba de doble facturación.
4. **El comprobante no toca caja; el recibo sí.** Un comprobante nunca genera
   movimiento de efectivo; el pago (recibo) contra la factura mensual es el
   único que entra a caja/finanzas.
5. **El comprobante NO toma numeración fiscal.** Hoy el workaround sí la toma
   (el flag `interno` no llega al backend — roadmap). P5 lo arregla.
6. **La IA nunca escribe stock ni finanzas, solo el borrador** (heredado de
   `context/32`; `purchase_draft.approve()` llama al mismo `create()` del
   alta manual). La OC no cambia eso.
7. **`Inventory::manageStock()` es el único escritor de stock**
   (`context/52`). El lote de producción y la recepción de compra pasan por
   ahí; no se abre un segundo camino.

## Estado del código (verificado 2026-09-03, leyendo fuente)

**Existe y se reusa:**

- `pos_order` con delivery, KDS, estados (`context/modules/11-ordenes-y-comandas.md`).
- Explosión recursiva de recetas con merma planificada y costeo congelado:
  `Inventory::explodeRecipeDetailed()`, `RecipeCosting::unitCosts()`,
  `ProductionService::complete()` (`context/modules/06-produccion.md`).
- `production_order` (mig 76) — **un ítem por orden**, sin completado parcial.
- `manageStock()` no-op para `itemTrackInventory < 1` — es lo que hace
  opcional el stock (D1).
- `item.itemMinStock`/`itemMaxStock` (mig 133) — umbrales, solo avisan.
- `stock_transfer` con doble entrada (`context/modules/07-transferencias.md`).
- `purchase_draft` (mig 105) + `PurchaseDraftService` — OCR de factura del
  proveedor a borrador, aprobación humana, mismo `PurchasesService::create()`.
- `transactionType = Crédito` + `OpenInvoicesService::contactBalance()`
  (`total − payed`) — la cuenta por cobrar ya es "vendido, no cobrado".
- `transaction_link` (mig 115, `context/35`) — vínculos entre transacciones;
  sirve para factura mensual → comprobantes (P1).
- `contact.parentId` — **columna existente, sin lógica de negocio que la lea**
  (`context/modules/21-contactos.md:75`). Empleado→empresa es asignarle
  semántica, no una migración.
- Estado de cuenta público por cliente.
- Conteo de stock (`context/63` F0+F1) — origen de necesidades.

**No existe:**

- Lote de producción multi-plato con agregación por insumo y faltante.
- Necesidad de reposición (P2).
- Orden de compra, recepción, y el vínculo `purchase_draft` → OC.
- Comprobante como docType propio (hoy: workaround crédito+interno que
  **toma numeración fiscal** por un flag que no llega al backend).
- Condición de pago en el contacto (P3).
- Factura mensual consolidada y estado de cuenta a nivel empresa.
- Lista de entrega agrupada.

**Hallazgo del módulo de compras que afecta a esto**
(`context/modules/08-compras.md` regla 11): `purchases.php` y
`purchase-drafts.php` no tienen permission key — cualquier sesión de panel
crea/anula compras. La OC hereda ese agujero si no se cierra antes. Va con
la misma familia de fixes de permisos del 2026-09-02.

## Fases

Orden por valor y por dependencia. F1 va antes que las de viandas "puras"
porque **arregla un bug fiscal vivo**: los consumos a cuenta están tomando
números de factura hoy.

- **F0 — Condición de pago en el contacto + `parentId` con semántica.**
  Campo en el contacto (`prepago | mensual | a cuenta de`), UI en la ficha,
  `parentId` apuntando a la empresa. Barato, sin dependencias, desbloquea C.

- **F1 — Comprobante como docType propio.** Contador propio, sin numeración
  fiscal, `transactionType = Crédito`. Reemplaza el workaround. Los reportes
  de ventas lo cuentan como ingreso (invariante 1). Cierra el bug fiscal.
  Incluye la terminología de D4 fijada en `PrinterDocType`.

- **F2 — Lote del día + lote de producción multi-plato.** Vista de pedidos
  por fecha; desde ahí, un lote que explota todos los platos, agrega por
  insumo, compara con `onHand` y muestra necesidad/faltante. Completar el
  lote produce vía `ProductionService::complete()` (o no-op si el comercio no
  trackea, D1). Este es el corazón de "eficiente y ordenado".

- **F3 — Necesidad de reposición + orden de compra + recepción.** Entidad de
  necesidad (P2) alimentada por F2, conteo y mínimos. OC generada desde la
  necesidad o a mano, impresión/WhatsApp, recepción línea por línea, factura
  de compra desde la recepción, y vínculo `purchase_draft` → OC. Cerrar el
  agujero de permisos de compras antes o junto con esto. **Esta fase sirve
  al negocio entero, no solo a viandas.**

- **F4 — Cierre de mes.** Factura consolidada por empresa/cliente que absorbe
  los comprobantes del período (P1), con `transaction_link`, y la marca para
  que no sume ingreso otra vez. Estado de cuenta público a nivel empresa con
  detalle por empleado.

- **F5 — Lista de entrega.** Pedidos del lote agrupados por empresa/dirección
  para el reparto. Se apoya en `context/27-delivery-sla-plan.md` si ya está
  avanzado.

## Preguntas abiertas para el owner

- **Pedidos recurrentes** ("el empleado X pide milanesa todos los martes"). El
  roadmap los menciona (`10-roadmap.md:1337`). Fuera de v1 a propósito; ¿se
  necesitan pronto?
- **Fish como toma de pedidos por WhatsApp.** Hoy el MCP de Punto es
  read-only (`context/58`), así que Fish puede leer pero no crear pedidos.
  ¿Vale abrir una escritura acotada para eso, o el cajero los carga a mano
  (D2) y punto?
- **Factura electrónica del proveedor como entrada de la OC**: si el
  proveedor emite SIFEN, ¿se sube el XML/KuDE y se salta el OCR?
- **Disputa de un comprobante** por parte de la empresa al cierre de mes
  ("ese empleado no pidió eso"). ¿Se anula el comprobante antes de facturar,
  o se factura igual y se ajusta con nota de crédito (`context/40`)?
- **Cierre del lote del día**: ¿hace falta un estado "cerrado, no se toman
  más pedidos"? En v1 es una vista (P4); si el restaurante necesita el corte,
  se materializa.

## Arquitecturas rechazadas — no reintroducir

- **Comprobante que no suma ingresos** ("no toca caja, no suma ingresos").
  Fue la propuesta inicial de esta conversación y del roadmap. **Rechazada
  por el owner**: el comercio necesita saber cuánto vendió aunque no haya
  cobrado. El comprobante es una boleta a crédito y reconoce ingreso (D3).
- **Invariante "la factura O los comprobantes"** (roadmap 2026-07-29). Era
  la consecuencia del error anterior. El invariante correcto es el 1: el
  ingreso se reconoce una vez en el comprobante y la factura mensual aporta
  cero.
- **Flag de lote "este lote no mueve stock".** Innecesario: `itemTrackInventory`
  ya lo resuelve por ítem (D1). Un flag de lote duplicaría la decisión y
  abriría la puerta a lotes que mueven stock de ítems que no lo trackean.
- **Catálogo público o link obligatorio para tomar pedidos.** Rechazado por
  el owner (D2): el cajero carga el pedido.
- **Que el conteo (o el lote) genere directamente una orden de compra.**
  Obliga a decidir en el momento del conteo si se compra o se trae del
  depósito, y quien cuenta en el mostrador no es quien decide eso. La
  necesidad va primero (D6, P2).
- **Factura mensual como envoltorio fiscal sin transacción propia** (deuda
  repartida en N comprobantes, la factura solo referencia). Alternativa a
  P1, descartada: obliga a que cobranzas agregue y complica el recibo
  parcial. Si el owner prefiere este camino, P1 se revierte antes de F4, no
  después.
- **Lote de producción como N `production_order` de un ítem cada una.**
  Pierde la agregación por insumo, que es el punto entero de la etapa B.

## Docs relacionados

- `context/10-roadmap.md` §"Consumo a cuenta de empresa (viandas)" — la
  versión anterior de la etapa C; este doc la supersede.
- `context/modules/06-produccion.md` — el motor de explosión y `production_order`.
- `context/modules/08-compras.md` y `context/32-ocr-facturas-compra.md` —
  `purchase_draft`, la regla "la IA solo escribe el borrador".
- `context/modules/07-transferencias.md` — una de las tres formas de cubrir
  una necesidad.
- `context/63-conteo-de-stock-en-la-caja.md` — origen de necesidades de
  reposición.
- `context/modules/15-credito-y-cobranzas.md` — `contactBalance()`, recibos.
- `context/35-transaction-link.md` — el vínculo factura mensual → comprobantes.
- `context/40-anulacion-y-nota-credito.md` — para la disputa de comprobantes.
- `context/27-delivery-sla-plan.md` — reparto (F5).
- `context/53-orden-y-stock-reserva.md` — "comprometido" derivado de órdenes
  abiertas; relación con el lote del día si se materializa.
