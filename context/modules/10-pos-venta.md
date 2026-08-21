# 10 — POS / venta

> ⚠ **Desactualizado (2026-08-20): el arriendo de bloques offline (`numbering_lease`, `leasedInvoiceNo`, `LEASE_EXPIRED`) fue RECHAZADO Y ELIMINADO** — ver `context/29-numeracion-y-exclusividad-de-caja.md` §6. Cada caja numera localmente con último correlativo + 1 (sin arriendo, sin TTL); las referencias a `numbering_lease`/`leasedInvoiceNo` más abajo (regla 4, tabla de entidades) describen el mecanismo viejo.
>
> Estado del doc: verificado contra código 2026-08-17
> Responsable de la última verificación: sesión 2026-08-17 (este doc)

## 1. Qué resuelve

Es el camino por el que el cajero cobra: arma un carrito, aplica medios de
pago, descuentos y crédito, y emite el comprobante — con o sin conexión a
internet. Cubre venta al contado y a crédito (no cotización, no orden de
mesa — esos son otros módulos que terminan convergiendo acá al cobrar).

## 2. Entidades y datos

| Tabla / estructura | Qué guarda | Invariantes / trampas |
|---|---|---|
| `transaction` (type 0/3) | La venta persistida. `invoiceNo` es el número de comprobante impreso. | **`invoiceNo` NUNCA se asigna en la venta ONLINE** — ver regla 4. `ivaRemoved` (mig 101) marca ventas emitidas sin IVA para que los reportes no lo devenguen — `SaleService.php:634`. `interno` (mig 118) es venta de consumo propio sin valor fiscal — pero SÍ consume numeración fiscal real (ver regla 4) — `SaleService.php:638`. `transactionUID` es la columna UNIQUE de idempotencia — `SaleService.php:69-75`. |
| `CartLine` (Zustand, cliente) | Una línea del carrito en memoria — nunca persiste en disco. | `unitPrice` YA incluye el recargo de add-ons (`selections`) — el servidor no deriva el total del detalle, confía en `unitPrice`/`subtotal` (`frontend/lib/cart/store.ts:130-141`). `basePrice` es la referencia para re-cotizar por lista de precios; una línea sin `basePrice` retroalimenta el precio hacia abajo (bug real ya ocurrido, comentario `store.ts:1081-1084`). |
| `OfflineSaleRow` (IndexedDB `punto-pos-offline`) | Una venta encolada offline: `clientTempId`, `leasedInvoiceNo`, `sale` (payload completo), `status`. | `status` es `pending`/`syncing`/`failed` — `failed` es TERMINAL, no se reintenta solo (`frontend/lib/pos/offline-queue.ts:88-95`). El `leasedInvoiceNo` es el único invoiceNo que existe hasta que el sync lo valida contra `numbering_lease` (ver regla 4). |
| `numbering_lease` (Postgres) | Bloque de números reservado a un `registerId` para vender offline. | Se consume (`consumedAt`) recién al sincronizar exitosamente — `api/v1/offline-sync.php:119-123`. Si el número ya no está `consumedAt IS NULL AND expiresAt > NOW()`, la sync falla con `LEASE_EXPIRED` — `offline-sync.php:41-57`. |

No hay tabla propia de "carrito" — es intencional (ver regla 3).

## 3. Reglas de negocio

1. **Offline-first es la regla base: el backend nunca rechaza una venta ya
   emitida, solo la guarda — decisión del owner (2026-08-16).** Textual:
   *"No podés rechazar una venta en el backend. Esa venta ya se emitió, se
   validó y se imprimió; el backend solo la guarda a ese punto"*
   (`context/08-convenciones-criticas.md:526-539`). La validación de negocio
   (crédito habilitado, stock, límites) vive en el POS contra el cache local
   del bootstrap — el backend solo valida integridad/anti-IDOR (que el
   `clientId` exista y sea del tenant). Caso cerrado citado en el propio
   código: `SaleService::save()` ya NO valida `contactCreditable` —
   `api/lib/Sales/SaleService.php:91-106`.
2. **Cola offline: IndexedDB, tres estados, una fallida no muere en
   silencio.** `pending`/`syncing`/`failed` — `failed` es terminal, requiere
   acción humana (`offline-queue.ts:88-95, 153-164`). Superficie de aviso:
   `OfflineBanner` se pinta en rojo/destructivo siempre que
   `failedCount > 0`, independientemente de si hay sync en curso —
   `context/08-convenciones-criticas.md:541-546`.
3. **El carrito NO se persiste — decisión del owner (2026-08-16), no
   deuda.** `useCartStore` es Zustand puro en memoria, sin `persist()`
   (`frontend/lib/cart/store.ts:828`). Un reload borra la venta en curso, con
   o sin internet. Una auditoría lo señaló como hueco; el owner respondió
   "esto está bien, dejémoslo así" — `context/08-convenciones-criticas.md:548-553`.
   Lo que sí se persiste es la venta ya CONFIRMADA (la cola offline).
4. **Numeración — HALLAZGO: la venta online nunca asigna `invoiceNo`, y el
   número arrendado offline nunca llega al ticket impreso.** Tres piezas
   verificadas, todas con evidencia:
   - El único llamado a `DocumentNumber::allocate()` en todo
     `SaleService.php` es para cotización (`getNextQuoteNumber`,
     `SaleService.php:2427-2435`); `save()` (venta contado/crédito) nunca lo
     invoca — `invoiceNo` se inserta tal cual llega en el payload
     (`SaleService.php:663`).
   - El front (`buildSalePayload`/`CreateSalePayload`,
     `frontend/lib/commands/create-sale.ts`) **no tiene campo `invoiceno`**
     y nunca lo manda en la venta online. `getNextInvoiceNo()` (el consumo
     del lease local) solo se llama dentro del `catch` de red de
     `handleConfirm` — es decir, únicamente en el camino OFFLINE
     (`frontend/components/register/pay-dialog.tsx:542-549`). Consecuencia:
     **toda venta que se emite con conexión persiste `invoiceNo = NULL`**.
   - El número SÍ se asigna, pero recién al sincronizar: `offline-sync.php`
     valida el `leasedInvoiceNo` contra `numbering_lease` e **inyecta**
     `invoiceno` en el payload antes de llamar a `SaleService::save()`
     (`api/v1/offline-sync.php:36-64, 119-123`). Sin lease vigente, la sync
     falla con `LEASE_EXPIRED` — el bloqueo real (`"no puede salir una venta
     sin número de factura"`, comentario del owner citado en
     `pay-dialog.tsx:523-529`) solo protege el camino offline.
   - Además: **`buildTicketData`** (el builder que arma el ticket recién
     cobrado, usado en AMBAS ramas — online y offline —
     `pay-dialog.tsx:303, 588`) **nunca lee `result.invoiceNumber` hacia
     `documentNumber`** — el `return` de la función
     (`frontend/lib/hardware/printers/build-ticket-data.ts:316-352`) no
     incluye esa key. El bloque de impresión `document_number` renderiza
     `data.documentNumber ?? null` → nada (`blocks.ts:261`). Es decir:
     incluso en la venta offline, donde SÍ hubo un número arrendado y el
     gate bloqueó si no lo había, ese número **no aparece en el ticket que
     se imprime automáticamente al confirmar**. **NO VERIFICADO**: si el
     número real que importa legalmente es el de Facturación Electrónica
     (SIFEN, `einvoicePortalUrl`) y `invoiceNo`/`documentNumber` es solo un
     correlativo secundario para negocios sin FE — no se auditó
     `api/lib/EInvoice/*` en esta sesión para confirmar si eso cierra el
     gap.
5. **Medios de pago: array `payment[]`, sin autoridad de precio del
   cliente.** El visor único aplica pagos y auto-confirma al cubrir el total
   (`pay-dialog.tsx:750-842`). QR Bancard difiere el `applyPayment` hasta que
   el PSP acredita (`handleMethodClick`, `pay-dialog.tsx:794-803`). Giftcard
   como pago se consume fire-and-forget DESPUÉS de confirmada la venta —
   errores de canje no revierten la venta, solo avisan a soporte
   (`pay-dialog.tsx:598-612`).
6. **Descuentos: por línea y por venta, nunca ambos en la misma línea.**
   `saleDiscount.lineIds` congela el ALCANCE al momento de aplicarlo — lo
   agregado después no queda cubierto, y una línea con descuento propio sale
   del alcance (`frontend/lib/cart/store.ts:432-446, 1109-1126`). El
   `discount` que viaja al backend es el % EFECTIVO (propio + prorrateo del
   de venta) por `allocateLineDiscounts` — antes solo el descuento de venta
   llegaba a algún total, el de línea se cobraba pero no se registraba
   (`create-sale.ts:313-335, 377-385`).
7. **`ivaRemoved`: informativo pero viaja al backend como flag real.**
   El total del carrito NO cambia (`selectCartTotal` no lo resta aparte); lo
   que cambia es que cada línea se trata como neta y el flag persiste en
   `transaction.ivaRemoved` para que los reportes no devenguen ese IVA
   (`lib/cart/store.ts:222-234, 253-259`, `SaleService.php:634`).
8. **Crédito: requiere cliente Y que el cliente tenga crédito habilitado —
   gate en el POS, no en el backend.** `pay-dialog.tsx` bloquea con throw
   ANTES del round-trip si `!customer` o `!customer.isCreditable`
   (`pay-dialog.tsx:366-376`). El backend deliberadamente NO repite ese check
   — ver regla 1 y el comentario textual en `SaleService.php:91-106`.
   Pregunta abierta del owner, sin resolver: si el POS está offline y el
   cache dice "no creditable", ¿bloquear o permitir y marcar para revisión?
   Hoy bloquea (default conservador) — `context/08-convenciones-criticas.md:539`.
9. **`tax: 0` en el payload es a propósito, no un bug.** El front deja de
   calcular el impuesto de autoridad — el backend corre `TaxEngine` server-side
   dentro de `enrichWithTaxes()` y congela `taxId`/`taxRate`/`taxAmount` por
   línea (F2a, `SaleService.php:111-126, 2130+`). El comentario en
   `create-sale.ts:179` lo declara explícito: *"el front manda 0; el backend
   lo calcula y congela con TaxEngine"*. El ticket impreso SÍ necesita un
   valor antes de esa respuesta — usa el MISMO motor client-side
   (`build-ticket-data.ts:270-280`) solo para mostrar, nunca para cobrar.
10. **Las cinco dimensiones obligatorias de toda transacción son las de la
    OPERACIÓN, nunca las del guardado — decisión del owner (2026-08-17).**
    Textual: si `transactionDate` fuera la fecha del guardado, la fecha del
    recibo/factura IMPRESA no coincidiría con la que muestra el sistema, y en
    el POS offline la operación y la persistencia quedan separadas por horas.
    Verificado: `transactionDate` sale de `$input->date` — el valor que el POS
    arma al confirmar el cobro, nunca `NOW()` del server
    (`api/lib/Sales/SaleService.php:657`, dentro de `buildTransactionRecord`).
    `SaleInput::fromPayload()`/`fromLegacyArray()` lanzan
    `InvalidSaleInputException('Falta date en el payload')` si `date` llega
    vacío (`api/lib/Sales/SaleInput.php:127`, `:203`) — no hay default
    silencioso a "ahora". Las otras cuatro dimensiones (`registerId`,
    `userId`, `outletId`, `companyId`) salen del contexto autenticado
    (`$this->ctx`, resuelto del JWT en el momento del request), no del
    payload (`SaleService.php:670-674`). Para una venta offline, el "momento
    del request" es el SYNC, no la operación — por eso `date` es la única de
    las cinco que viaja explícita en el payload en vez de derivarse del
    contexto: es la única que puede diferir entre operación y guardado, y por
    eso hay que preservarla a mano en vez de confiar en el contexto del
    request que persiste.

    **Corolario (R6, `14-caja.md` regla 5):** el `drawerId` de la venta se
    resuelve por esa MISMA `transactionDate` — no por qué turno esté abierto
    al momento del `INSERT` — vía
    `DrawerService::resolveDrawerIdForDate()` (`SaleService.php:684-688`). Es
    consecuencia directa de esta regla: si las cinco dimensiones son las de
    la operación, el turno de caja que corresponde también tiene que
    resolverse contra la fecha de la operación, no contra la del guardado.

## 4. Flujos principales

**Venta al contado (online, camino feliz):**
1. Cajero arma el carrito (`addItem`), aplica pagos (`applyPayment`); al
   cubrir el total, `handleConfirm` corre automáticamente.
2. Validaciones síncronas: carrito no vacío, crédito con cliente habilitado,
   al menos un pago (`pay-dialog.tsx:363-379`).
3. `buildSalePayload` arma el payload canónico (descuentos ya prorrateados,
   `tax:0`, `invoiceno` AUSENTE) → `posApi.postLegacy('/v1/sales', ...)` con
   timeout de 5s (20s si es cobro de orden/espacio) —
   `pay-dialog.tsx:449-471`.
4. Éxito → `runAutoPrint` (imprime factura, o recibo si hay emisión de gift
   card), consumo de giftcard si aplica, y una de 4 ramas mutuamente
   excluyentes (settlementIntent / sessionParentId / orderParentId /
   ordenEnVenta) — ver §5. `clearCart()` recién en `handleClose` (fase
   success), no antes.

**Venta offline (sin red o timeout):**
1. El `catch` distingue red/timeout (encola) de 4xx (error de negocio,
   relanza) de 5xx (encola también) — `pay-dialog.tsx:480-492`.
2. Si la venta viene de orden/espacio/settlement, NO se encola — esos cobros
   son online-only (ver §6) y el error se propaga para reintento
   (`pay-dialog.tsx:494-513`).
3. Venta simple: `getNextInvoiceNo()` consume el lease local. Sin lease
   vigente, bloquea con throw ANTES de encolar, imprimir o limpiar el
   carrito (`pay-dialog.tsx:542-549`) — pero ver regla 4, este número no
   llega al ticket igual.
4. `enqueue()` a IndexedDB, stock optimista decrementado en el catálogo
   local (`pay-dialog.tsx:554-561`), y la MISMA pantalla de éxito que la
   venta online — la impresión es browser-side y no espera al servidor.
5. Sync posterior (`offline-sync.php`): valida el lease, inyecta `invoiceno`,
   llama a `SaleService::save()` y consume el lease. Errores por venta no
   tumban el lote — `LEASE_EXPIRED` / `INVALID_INPUT` / `STOCK_OUT` /
   `SERVER_ERROR` por ítem (`offline-sync.php:36-130`).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Impuestos | El front manda `tax:0`; el backend corre TaxEngine y congela por línea. | Asume que `enrichWithTaxes` corre SIEMPRE antes de persistir — si se saltea, la venta queda sin impuesto congelado. Ver `04-impuestos.md`. |
| Numeración | Venta offline: consume lease local, bloquea si no hay. Venta online: no pide nada — nunca asigna `invoiceNo` (regla 4). | El backend asume que el front va a mandar `invoiceno` cuando corresponda — pero el front SOLO lo manda en el payload de sync offline, nunca en `/v1/sales` directo. |
| Impresión | `buildTicketData` arma el ticket con el motor de impuestos client-side y el payload recién armado. | Asume que `result.invoiceNumber` no hace falta para imprimir — de hecho ni lo lee (regla 4). El QR/link de FE (`einvoicePortalUrl`) sí viaja. |
| Stock | Cada línea de venta descuenta vía `manageStock`/`explodeRecipe` (`persistItemsAndStock`). Offline: decremento OPTIMISTA en el catálogo local, sin esperar confirmación. | Asume que el catálogo local (`useCatalogStore`) es fuente de verdad suficiente para no vender de más entre dos ventas offline seguidas — no hay lock cross-device sin conexión (ver `22-sincronizacion.md` cuando exista). |
| Sincronización | El bootstrap hidrata el catálogo (precios, impuestos, add-ons, clientes) que el POS usa para TODA validación offline. | Asume que el cache está fresco — un cliente que perdió `isCreditable` recién en un delta no aplicado todavía sigue viéndose creditable en el POS. |
| Caja (drawer) | Si `controlCaja` está activo y la caja está cerrada, el botón de cobro se deshabilita (`drawerClosed`, `pay-dialog.tsx:208-213, 1282-1286`). | Asume que `useDrawerStatus` está actualizado — no hay revalidación server-side de "caja abierta" al confirmar la venta. |
| Órdenes/Espacios | Cobrar una orden/mesa reusa el MISMO `PayDialog` — `orderParentId`/`sessionParentId`/`settlementIntent` cambian qué pasa DESPUÉS de la venta (markPaid, close, registro de pago parcial). | Ver `11-ordenes-y-comandas.md` y `12-espacios.md` — el contrato de "qué se resetea en `clear()`" es crítico para no imputar una venta normal a una mesa vieja. |
| Devolución (`ReturnService`, transactionType=6) | Anulación (`SaleVoidService`) | Ambas reversan stock con el MISMO wrapper compartido `StockReversalPolicy` (`api/lib/services/StockReversalPolicy.php`, extraído 2026-08-21 de `SaleVoidService` al implementar D2 en la devolución) — clasificación D2 (`ownStock`/`ingredientReversal`/`service`), decisión del cajero por línea con clamp a `canRestock`, y `waste_event` para lo que no se repone. `ReturnService::create()` reusa las líneas de la venta original AGREGADAS por itemId (no por `itemSoldId` — a diferencia de `SaleVoidService::voidOptions()`, que trabaja línea física por línea física), porque el request de devolución ya viaja `{itemId, qty}` y el cupo (`alreadyReturned`) siempre fue itemId-level. |

## 6. Offline (POS)

Ver regla 1 (base), regla 2 (cola) y regla 4 (numeración). Resumen de la
línea offline/online:

- **Emite offline**: venta simple (contado/crédito sin cliente de mesa/orden),
  con lease de numeración disponible. Bloquea si no hay lease (regla 4).
- **NO emite offline**: cobro de orden, de espacio, o cobro parcial
  (`settlementIntent`) — son estado compartido entre cajas, online-only por
  diseño (`pay-dialog.tsx:494-513`, mismo criterio que §53).
- **Impresión**: si la impresora es local (`native`/`escpos`), 100% offline.
  Si es `station` (impresora remota vía servidor), depende de internet por
  diseño — decisión del owner, no bug
  (`context/08-convenciones-criticas.md:555-567`).

## 7. Huecos conocidos y NO verificado

- **Numeración online rota** (regla 4): toda venta emitida con conexión
  persiste `invoiceNo = NULL`. No se auditó si Facturación Electrónica
  (`api/lib/EInvoice/*`) provee el número legal real y este campo es
  vestigial para negocios con FE activa — **NO VERIFICADO**.
- **El ticket impreso nunca muestra `documentNumber`** en ninguna de las dos
  ramas (online/offline) porque `buildTicketData` no lo popula desde
  `result.invoiceNumber` — confirmado leyendo el código, no se verificó el
  impacto visual real contra una impresión física.
- **Crédito 100% sin pago inicial**: `effectivePayments` arma un pago
  sintético `{name:"Crédito", type:"credito", total:0}` cuando no hay ningún
  pago aplicado — comentario propio marca esto como TODO pendiente de
  definición con el owner (`pay-dialog.tsx:355-361`).
- **Reimpresión (`handlePrint`) reconstruye el payload a mano**, sin
  `selections`/`giftcard` completos — puede divergir del ticket original en
  casos con add-ons (`pay-dialog.tsx:941-989`). No auditado a fondo en esta
  sesión.
- **Gate de crédito offline con cache desactualizado**: pregunta abierta del
  owner (regla 8), sin resolver.

## 8. Planes y decisiones relacionados

- `context/08-convenciones-criticas.md §53` — regla base offline-first,
  fuente de las decisiones del owner citadas en este doc.
- `context/37-numeracion-documentos.md` — plan de numeración correlativa;
  la tabla `document_sequence`/`DocumentNumber` que este doc muestra sin
  usar en venta (solo cotización) es la migración en curso — F2 (migrar
  factura a `DocumentNumber::allocate`) no está hecha, lo que explica en
  parte el hallazgo de la regla 4.
- `context/38-impuestos-multi-pais.md` — motor de impuestos (`TaxEngine`)
  que congela impuesto por línea (F2a).
- `context/28-facturacion-electronica-plan.md` — FE/SIFEN, no auditado en
  esta sesión respecto al gap de `invoiceNo`.
