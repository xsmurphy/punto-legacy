# 42 — Remisión

> Estado: **implementada** (mig 137, 2026-08-15). Documento de traslado
> interno, funcionando en el sistema (se emite, se numera, se imprime, se
> consulta) — **sin** conexión a SIFEN. Esa es una fase aparte, ver
> §"Qué queda para SIFEN" al final. Pedido del owner: *"la remisión, hagamos
> la ya para el uso en el sistema, todavía sin conectar con la SIFEN"*.

## Diagnóstico — qué ya existía y qué reusé

Antes de escribir código se relevó lo construido por otras sesiones. Tres
piezas cubrían más de la mitad del trabajo:

1. **`SaleType::Delivery = 10`** (`api/lib/Sales/SaleType.php`) estaba
   declarado y nada lo emitía (`context/37-numeracion-documentos.md:68`). Se
   deja tal cual — no se usa acá. Una remisión no es una transacción
   financiera (no vive en `transaction`/`itemSold`), así que forzarla a un
   `SaleType` habría sido el mismo error de modelado que `transaction_link`
   corrigió para vínculos (context/35): mezclar dimensiones distintas en una
   sola tabla.

2. **`stock_transfer`** (mig 46, numerada en mig 129 F3) YA ES la remisión
   completa para el motivo "traslado entre sucursales/depósitos propios":
   numeración correlativa (`DocumentNumber`, scope outlet), movimiento de
   stock de doble entrada (egreso+ingreso vía `Inventory::manageStock`),
   cancelación con reversa. **No se duplicó.** Lo único que le faltaba era
   imprimirse con el tipo de documento "Remisión" — se agregó un botón
   "Imprimir" a `/stock-transfer/[id]` que resuelve al mismo `docType:
   "delivery"` que usa esta fase (satisface el pedido histórico del owner en
   `context/_feature-requests.md` 2026-07-31: "reporte de transferencias de
   stock con formato de Nota de Remisión").

3. **Numeración de documentos** (`document_sequence` + `DocumentNumber::
   allocate()`, context/37) — se reusa tal cual, sin tocar el asignador.

4. **`PurchaseCreditNoteService`** (`transactionType = 14`, "Nota de crédito
   de compra") — descubierta durante el relevamiento, **no estaba
   catalogada como tal en context/37**. Es exactamente "devolución a
   proveedor": ya valida contra la compra original, ya mueve stock
   (`affectsStock`), ya tiene anulación con reversa. Este hallazgo cambió el
   diseño — ver §"Por qué ningún motivo mueve stock" abajo.

5. **Catálogo de plantillas de impresión** — el `docType` backend `delivery`
   ya existía en `DocumentTemplateService::VALID_DOC_TYPES` y en el editor
   (`template-editor.tsx`, label "Remito"). No se tocó el catálogo de
   documentos ni se agregó un tipo nuevo — se reusa `delivery` para ambas
   fuentes (`stock_transfer` y `document_remision`).

6. **POS**: `PosModeDialog` (`frontend/components/register/pos-mode-dialog.tsx`)
   ya tiene el tile "Remisión — Próximamente". **Se deja así** — ver
   §"POS" al final.

## Qué NO se reusó (y por qué)

`SaleType::Delivery` — ver punto 1 arriba.

## Arquitectura elegida

**Dos fuentes de datos para un solo concepto de negocio ("remisión"),
unificadas solo en la capa de impresión:**

| Motivo | Documento | Mueve stock |
|---|---|---|
| Traslado entre sucursales/depósitos propios | `stock_transfer` (ya existía) | Sí — doble entrada, StockTransferService |
| Venta | `document_remision` (nuevo) | No |
| Devolución a proveedor | `document_remision` (nuevo) | No |
| Consignación | `document_remision` (nuevo) | No (abierto, ver Decisiones) |
| Exposición / demostración | `document_remision` (nuevo) | No |
| Compra (recepción) | `document_remision` (nuevo) | No |

`document_remision` (mig 137) + `document_remision_item`: cabecera con
`motivo` (columna tipada, CHECK constraint — NO texto libre, es lo que SIFEN
va a exigir), `outletid`/`locationid` (origen), `destinationcontactid`
(cliente/proveedor, nullable) + `destinationnote` (texto libre — feria,
dirección, depósito de terceros), `transactionid` (nullable — factura/NC de
compra/compra, cuando existe), `transferdate` (campo de primera clase, no
"fecha de emisión"), `status` (activa/cancelada), `docnumber`.

`RemisionService::create()` valida outlet/location/contact/transaction
pertenecen al tenant, valida items, asigna el correlativo dentro de la misma
TX, inserta cabecera + líneas. Sin `Inventory::manageStock` en ningún lado —
ver el porqué abajo.

### Por qué ningún motivo de `document_remision` mueve stock

Es la instrucción más explícita del brief: *"no dupliques movimientos, es la
clase de bug que descuadra inventario"*. Cada motivo YA tiene (o puede tener)
un dueño real del movimiento:

- **venta**: la factura, cuando se emite (`SaleService`). La remisión suele
  emitirse ANTES — el traslado documenta la salida física, la factura mueve
  el stock cuando se concreta la venta.
- **devolución a proveedor**: `PurchaseCreditNoteService` (transactionType
  14, `affectsStock=true`) — YA mueve stock si el comercio carga la nota de
  crédito de compra. Si `document_remision` moviera stock también, un
  comercio que carga las dos (remisión del camión + NC de compra formal)
  descontaría dos veces el mismo egreso.
- **compra**: `PurchasesService` (types 1/4) ya suma el stock al recibir.
- **consignación / exposición**: no hay hoy una operación que "consuma" ese
  stock — moverlo de la sucursal inventaría un movimiento sin dueño real.
  Punto abierto, ver Decisiones.

`transactionid` (nullable) es el puente: cuando el documento que sí mueve
plata/stock existe, la remisión lo referencia. Cuando no (remisión emitida
antes, o motivo sin transacción como consignación/exposición), queda NULL —
no es un dato faltante, es el estado normal de "todavía no factura".

### Numeración — scope OUTLET, no register

`context/37` §D2 (cerrada antes de este plan) proponía scope `register` para
remisión "por ser documento fiscal". Se decidió **outlet** en su lugar,
divergiendo de esa nota — la mayoría de estos motivos se emiten desde el
panel/backoffice sin caja de por medio: traslado por compra, devolución a
proveedor, consignación y exposición no tienen un cajero ni una sesión de
POS abierta. Forzar scope register habría dejado esos flujos sin secuencia
utilizable (¿qué caja numera una devolución a proveedor cargada desde
Compras?).

Mismo criterio que F3 (mig 129) usó para producción/merma/transferencia/
conteo: son documentos internos hoy, van por outlet. Cuando llegue la fase
SIFEN y el timbrado exija punto de expedición, la migración de scope se hace
entonces — mismo patrón que F2 (context/37) migró factura/cotización de
`MAX()` al asignador. `context/37` queda con una nota apuntando acá para que
la próxima sesión que lea D2 no relitigue esto sin ver el porqué.

## Consulta e impresión

- Panel: `/remisiones` (listado, `<DataTable>`), `/remisiones/new` (alta),
  `/remisiones/[id]` (detalle + Imprimir + Cancelar). Mismo patrón que
  `/stock-transfer` (list/new/[id]), permiso reusado `inventory.transfer` —
  no se creó una permission key nueva para no fragmentar el mismo dominio de
  traslado de mercadería.
- `/stock-transfer/[id]` ganó el botón "Imprimir" (no tenía ninguno antes).
- **Impresión — bloques, no un renderer propio.** Aclaración del owner
  durante la tarea: el constructor de plantillas no debe restringir qué
  bloque va en qué documento — "es problema del cliente". Se agregaron tres
  bloques nuevos al catálogo único (`frontend/lib/hardware/printers/
  blocks.ts` + `print-template-palette.ts` + `BlockType` en
  `lib/types/print-template.ts`): `transfer_reason`, `transfer_origin`,
  `transfer_destination`. Están disponibles para CUALQUIER `docType` en el
  editor, sin gating — resuelven contra `TicketData.transferReason`/
  `originLabel`/`destinationLabel` (nuevos campos, `null` cuando el origen de
  datos no los puebla, igual que cualquier otro bloque con hueco de dato).
  Dos adapters nuevos en `build-ticket-data.ts` (`buildTicketDataFromStockTransfer`,
  `buildTicketDataFromRemision`) alimentan esos campos desde las dos fuentes.
  Ninguno de los dos manda precios: una remisión ampara traslado, no venta
  (`unitPrice`/`total` en 0 — si el comercio agrega `item_price`/`total` a su
  plantilla de remisión, imprime "Gs. 0", decisión suya).

## POS — tile "Remisión" sigue en "Próximamente"

**No se activó.** El único motivo que tiene sentido emitir desde la caja es
"venta" (traslado por venta, antes de facturar) — los otros cuatro
(devolución a proveedor, consignación, exposición, compra) son operaciones
de backoffice/depósito, no de mostrador. Pero el caso "venta" tampoco quedó
cubierto: esta fase entrega el CRUD de `document_remision` desde el panel,
no un flujo dentro del carrito del POS (selección de motivo, cliente,
dirección de entrega, ítems ya cargados desde la venta en curso). Activar el
tile sin ese flujo real dejaría un botón que abre el panel en otra pestaña —
peor que "Próximamente" (regla del brief: "un tile habilitado que no
funciona es peor"). Queda para una fase POS aparte si el owner la prioriza.

## Decisiones abiertas (necesitan al owner)

- **Consignación — ¿mueve stock o no?** Hoy no lo mueve (documental). Si el
  comercio necesita dejar de contar ese stock como propio mientras está en
  consignación, hace falta un concepto de "ubicación de consignación" (una
  location especial, o un estado de stock aparte) — no existe hoy y no se
  inventó uno para no adivinar la política del owner.
- **Exposición — ¿remisión de vuelta al regresar la mercadería?** Hoy es un
  documento de una sola vía (sale, no hay un "retorno" modelado). Si el
  owner quiere trackear que la mercadería volvió, es una remisión motivo
  `exposicion` en sentido inverso (origen↔destino invertidos) — no
  implementado, la tabla lo soporta sin cambios de schema si se decide.
- **`transactionid` — ¿UI para vincular después?** El backend acepta
  `transactionId` opcional al crear, pero no hay una acción "vincular esta
  remisión a la factura ya emitida" desde el detalle. Vale la pena si el
  flujo real es "remisión primero, factura días después" con necesidad de
  auditar el link — hoy queda manual (nadie lo pidió explícitamente).

## Qué queda para SIFEN

- **Scope de numeración** outlet → register, si el timbrado de remisión
  paraguayo lo exige por punto de expedición (a confirmar contra la
  especificación real de SIFEN para remisión electrónica).
- **Motivo → campos exigidos por SIFEN**: la tabla 3 de la SET.py define
  campos específicos por motivo de traslado (ej. transportista, vehículo,
  chofer para traslados propios) que hoy no se capturan. `motivo` como
  columna tipada (en vez de texto libre) es justamente lo que permite sumar
  esos campos condicionalmente sin romper lo ya emitido.
- **Emisión electrónica real** — `api/lib/EInvoice/*` (Factomate/SIFEN,
  context/28) no fue tocado. Cuando se conecte, `document_remision` y
  `stock_transfer` son las dos fuentes que hay que mapear a la remisión
  electrónica (mismo patrón que `SaleToInvoiceMapper` hace para factura).
- **POS**: flujo de emisión de remisión-por-venta desde el carrito, si el
  owner lo prioriza (ver §POS arriba).
