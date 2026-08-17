# 13 — Cotizaciones

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Presupuestar sin cobrar: el cajero arma un carrito, lo guarda como cotización
(`transaction.transactionType = 9`) y se lo entrega al cliente sin mover
stock, sin abrir un pago y sin emitir documento fiscal. Sirve como paso
previo opcional a una venta real — el cliente puede volver más tarde y
"facturar" la cotización.

## 2. Entidades y datos

Cotización usa la MISMA tabla `transaction`/`itemSold` que la venta —
`transactionType = 9` (`SaleType::Quote`, `api/lib/Sales/SaleType.php:23`) es
la única marca que la distingue. No hay tabla propia.

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `transaction.transactionType = 9` | Marca la fila como cotización. | Comparte columnas con la venta (`invoiceNo`, `transactionTotal`, `meta`) pero **NO** comparte numeración con `factura` — ver regla 2. `transactionComplete` se fija en `1` siempre (`SaleService.php:1974`): una cotización nunca queda "pendiente de pago", ese concepto no le aplica. |
| `transaction.drawerId` | Se resuelve igual que en la venta (`DrawerService::resolveOpenDrawerId`), pero la cotización NO requiere caja (turno) abierta — puede quedar `NULL` sin que la operación falle (`SaleService.php:676-682`, comentario "null si no hay caja abierta ... la venta NO falla"). | Sí requiere `registerId` seleccionado — lo exige el guard del endpoint `/v1/sales` (`api/v1/sales.php:41-43`) antes de rutear a cotización o venta. |
| `itemSold` (líneas) | Igual shape que la venta: impuestos congelados por línea (F2a, `context/38`). | `persistQuoteItems()` (`SaleService.php:2021-2115`) es un loop propio, **sin** `manageStock()` — ninguna línea de cotización mueve inventario, ni siquiera si el ítem es de tipo receta. |
| `document_sequence` (scope `cotizacion`) | Contador propio del correlativo de cotización, uno por caja (`SCOPE_REGISTER`). | Independiente del contador de `factura` — mismo `registerId`, secuencias separadas (ver regla 1). NO existe timbrado (`registerInvoiceAuth`/`registerInvoicePrefix`) asociado a este contador — ver regla 2. |
| `transaction_link` (`kind='quote_to_sale'`) | Vínculo cotización↔venta cuando se "factura" una cotización. | El `kind` **existe** en el catálogo válido (`TransactionLinkService::KINDS`, `api/lib/services/TransactionLinkService.php:23`) y el LECTOR ya lo consulta (`TransactionDetailService.php:253-257`) — pero ningún ESCRITOR lo llama nunca para cotizaciones. Ver regla 4, es el hallazgo principal de este doc. |

## 3. Reglas de negocio

1. **La cotización SÍ asigna correlativo vía `DocumentNumber::allocate()`; la venta online NO.** `SaleService::getNextQuoteNumber()` (`api/lib/Sales/SaleService.php:2427-2435`) llama `DocumentNumber::allocate('cotizacion', DocumentNumber::SCOPE_REGISTER, $registerId, $companyId)` DENTRO de la transacción de `saveQuote()` (`:1963`, comentario `:1959-1962`: si la cotización no persiste, el rollback devuelve también el número). La venta (`SaleService::save()`) nunca hace este llamado — ver `context/modules/10-pos-venta.md` regla 4, que documenta que toda venta ONLINE persiste `invoiceNo = NULL`. Es la asimetría inversa a la que uno esperaría: el documento sin valor fiscal (cotización) reserva número atómicamente; el documento CON valor fiscal (venta online) no.
2. **La cotización NO tiene timbrado propio, solo un contador.** `RegisterAdminService::DOC_TYPES = ['factura', 'cotizacion']` (`api/lib/services/RegisterAdminService.php:29`) trata a ambos como "tipo de documento numerable", pero el bloque `fiscal` (`invoiceAuth`, `invoicePrefix`, `invoiceAuthStart`, `invoiceAuthExpiration`) que se arma en `listRegisters()` (`:119-125`) solo existe para la caja en general (que ES el punto de expedición fiscal, `context/29`) — no hay una segunda entrada de timbrado por tipo de documento. `numbering.cotizacion` (`:130`) es un entero suelto, sin rango autorizado ni auth number propios. Consecuencia: la cotización no es, ni pretende ser, un documento fiscal — es un correlativo administrativo interno.
3. **Cotización congela impuestos igual que la venta (F2a).** `enrichWithTaxes(saleArraySanitizer($input->sale), ...)` corre en `saveQuote()` igual que en `save()` (`SaleService.php:1952`) — si la cotización se reimprime o se audita después, muestra la tasa con la que se cotizó, no la del catálogo al momento de mirarla (comentario `:1947-1950`).
4. **HALLAZGO — la conversión cotización→venta NO crea ningún vínculo en el backend, pese a que el frontend cree que sí.** El frontend manda `quoteParentId` como `parentTransactionId` en el payload de `/v1/sales` (`frontend/lib/commands/create-sale.ts:412`, tipo declarado en `:210-213,274-277`) con el comentario "Permite al backend vincular la transacción hija con la cotización padre". **El backend nunca lee ese campo**: `SaleInput::fromPayload()` (`api/lib/Sales/SaleInput.php:86-170`) no tiene `parentTransactionId` entre sus campos, y `grep -rn "parentTransactionId" api/lib/Sales/` no devuelve nada. El propio `SaleService::buildTransactionRecord()` lo documenta explícitamente: *"path simple: sin parentId (B2 omitido). Sub-slices futuros lo agregarán como transaction_link (mig 115, kind='quote_to_sale') — columna dropeada"* (`SaleService.php:652-653`). El `kind='quote_to_sale'` SÍ es válido en `TransactionLinkService::KINDS` (`:23`) y SÍ se lee en el detalle de transacción en ambas direcciones (`TransactionDetailService.php:253-257`) — pero como nadie llama `TransactionLinkService::link(..., 'quote_to_sale')`, esa lectura devuelve `[]` siempre. **El comentario del frontend en `pos-transactions-dialog.tsx:551` ("Link bidireccional con la cotización: al guardar la venta, el back vincula") describe un comportamiento que el backend no implementa.** Hoy no hay forma de, desde una venta o desde una cotización, saber cuál dio origen a cuál — ni desde el panel ni desde el POS.
5. **El precio de la cotización queda CONGELADO al convertir — no se re-resuelve contra la lista de precios vigente.** `pos-transactions-dialog.tsx::handleInvoice()` (`:532-555`) carga al carrito `i.price` tomado directamente del snapshot `detail.transactionDatas` de la cotización original (`:542-549`) — no hay ninguna llamada a `/v1/price_resolve` ni a ningún resolver de precios en este flujo. Si la lista de precios del cliente cambió entre la cotización y la venta, la venta sale al precio VIEJO, no al vigente. Esto es consistente con la expectativa de negocio de un presupuesto ("el precio cotizado es el que se respeta"), pero no está declarado como decisión en ningún doc — queda documentado acá.
6. **Los add-ons se ignoran en silencio al cotizar.** Si una línea trae `selections` (F3, `context/41`), `saveQuote()` las guarda tal cual llegan en el JSON del detalle pero no las expande ni las rechaza (comentario `SaleService.php:1905-1912`): "cotizar con add-ons entra con la UI (F4/F5), junto con la conversión quote→venta" — ninguna de las dos cosas está implementada (ver regla 4 y hueco de este doc).
7. **Duplicado se resuelve igual que la venta: por `transactionUID`.** `saveQuote()` chequea `SELECT transactionId FROM transaction WHERE transactionUID = ?` antes de insertar (`SaleService.php:1922-1933`) y devuelve `duplicated: true` sin volver a persistir — mismo patrón idempotente que `save()`.

## 4. Flujos principales

**Guardar cotización** (`sale-options-drawer.tsx::handleSaveAsQuote` → `createQuote()`, `frontend/lib/commands/create-quote.ts`) — arma el payload (`type: 9`), lo manda a `POST /v1/sales` vía `posApi` (Bearer del device — con el cliente de panel, cookie, el POST sería 401 garantizado, comentario `create-quote.ts:10-12`). El backend rutea por `type` ANTES de construir `SaleInput` (`api/v1/sales.php:65-80`): `type===9` va a `SaleInput::fromQuotePayload()` + `SaleService::saveQuote()`, que no valida `payment` ni caja abierta. Persiste `transaction` + `itemSold` (sin stock), asigna `quoteNo` dentro de la transacción, y devuelve `transactionNo`/`transactionDoc` (vacío: sin documento fiscal que numerar aparte del correlativo).

**Duplicar cotización** (`pos-transactions-dialog.tsx::handleDuplicate`) — carga las líneas al carrito como un carrito nuevo, sin heredar ningún vínculo (`setQuoteParent(null)`, `:527`). Es la vía para "cotizar de nuevo algo parecido", no para facturar.

**Facturar cotización** (`pos-transactions-dialog.tsx::handleInvoice`) — carga las líneas al carrito con el precio congelado de la cotización (regla 5), fija `quoteParentId` en el cart store (`setQuoteParent(encId)`, `:552`), y deja que el cajero complete el flujo normal de venta (`pay-dialog.tsx` → `createSale()`). El `quoteParentId` viaja en el payload como `parentTransactionId` pero el backend lo descarta (regla 4) — la "conversión" hoy es, en los hechos, solo una forma de precargar el carrito; no queda registro server-side de que esta venta vino de aquella cotización.

**Error / cotización vacía** — `createQuote()` (front) tira `Error("El carrito está vacío")` antes de llamar a la API si `lines.length === 0` (`create-quote.ts:48-50`); `handleInvoice`/`handleDuplicate` muestran un toast y no navegan si la cotización no tiene items válidos (`status !== 0`).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Numeración (`17-numeracion.md`) | Reserva atómica de `quoteNo` vía `DocumentNumber::allocate()`, scope `SCOPE_REGISTER` — mismo mecanismo que otros correlativos, pero SIN timbrado asociado (regla 2). | Que el scope `cotizacion` nunca colisiona con `factura` — son secuencias independientes aunque compartan la columna `invoiceNo` (comentario `RegisterAdminService.php:358-362`). |
| Impuestos (`04-impuestos.md`) | La cotización corre el mismo `enrichWithTaxes()`/`TaxEngine` que la venta (F2a) — el desglose queda congelado por línea. | Que reimprimir/auditar una cotización vieja muestra la tasa con la que se cotizó, no la del catálogo actual. |
| Venta (`10-pos-venta.md`) | Comparten `SaleService`, `transaction`/`itemSold`, y el mismo endpoint `/v1/sales` (ruteado por `type`). | Que "venta online nunca asigna `invoiceNo`" (regla 4 de `10-pos-venta.md`) es un contrato DISTINTO al de la cotización — no asumir que ambas se comportan igual frente a numeración. |
| `transaction_link` / Detalle de transacción (`39-detalle-transaccion.md`) | El resolver canónico (`TransactionDetailService`) ya sabe LEER `quote_to_sale` en ambas direcciones. | Falso hoy que algo lo ESCRIBA — el kind existe en el catálogo y en el lector, pero ningún caller de venta o cotización llama `TransactionLinkService::link()` con él (regla 4, hallazgo principal). |
| Stock (`05-stock.md`) | Ninguna — la cotización nunca llama `manageStock()`. | Que "guardar cotización" es 100% no-destructivo para inventario, sin excepciones (ni siquiera para ítems de receta). |
| Impresión (`18-impresion.md`) | `docType` propio (`quote` en `DocumentTemplateService::docType` enum, `context/modules/18-impresion.md` línea 14). | Que el ticket de cotización usa su propia plantilla, no la de factura — consistente con que no es un documento fiscal. |
| Add-ons/combos (`41-addons-y-combos.md`) | Ninguna — `selections` se ignora en silencio al cotizar (regla 6). | Que cotizar un ítem con add-ons no falla, pero tampoco refleja el recargo de las opciones elegidas en el total — riesgo de que el presupuesto entregado al cliente no coincida con lo que después se factura. |

## 6. Offline

**Cotización es 100% online-obligatorio, sin cola.** `createQuote` está registrado con `offlineEligible: false` (`frontend/lib/commands/registry.ts:51-55`, comentario "Cotización (type=9). Online obligatorio."). A diferencia de la venta (`createSale`, `offlineEligible: true`), no existe ningún camino de cola: `frontend/lib/pos/offline-queue.ts` define el store `pendingSales` tipado explícitamente para `CreateSalePayload` (`:24-42`) — no hay store ni tipo equivalente para cotización. `createQuote()` llama directo a `posApi.postLegacy()` sin ningún chequeo de `navigator.onLine` ni manejo de `OfflineError` (`create-quote.ts:106-109`): si no hay conexión, la llamada simplemente falla con un error de red y la cotización no se guarda — no hay pérdida de datos silenciosa, pero tampoco hay reintento automático ni encolado. El cajero debe reintentar manualmente al recuperar conexión.

Esto es consistente con la regla base (`context/08-convenciones-criticas.md §53`): la cotización no "emite" nada con valor legal que deba salir sin conexión — es admisible que dependa de red.

## 7. Huecos conocidos y NO verificado

- **Vínculo cotización→venta inexistente en backend** (regla 4) — hallazgo confirmado de esta sesión, con evidencia en ambos lados (frontend que lo manda y se cree vinculado; backend que lo tira). Cerrar esto requiere: agregar `parentTransactionId` a `SaleInput`, leerlo en `SaleService::save()`, y llamar `TransactionLinkService::link($companyId, $quoteId, $saleId, 'quote_to_sale')` dentro de la transacción de venta — la infraestructura (tabla, kind, lector) ya existe, falta el escritor.
- **Precio no re-resuelto al convertir** (regla 5) — confirmado como comportamiento actual; no verificado si es una decisión explícita del owner en algún acta o solo la consecuencia de que nadie construyó el path de re-resolución.
- **Add-ons en cotización** (regla 6) — el propio código declara que F4/F5 (cotizar con add-ons + conversión) no están hechas; no se investigó si hay un ticket/roadmap item abierto para esto más allá del comentario inline.
- **NO VERIFICADO**: si una cotización puede anularse (voidTransaction) — se encontró un único match de `SaleType::Quote` fuera de `SaleService.php` (`TransactionService.php:600`, comentario "Cubre listType = 'quotes'") que sugiere que el listado la cubre, pero no se rastreó el flujo de anulación completo para type=9.
- **NO VERIFICADO**: comportamiento del panel (fuera del POS) sobre cotizaciones — este doc se relevó desde el lado POS/API; no se buscó una vista de panel dedicada a cotizaciones más allá del detalle de transacción genérico.

## 8. Planes y decisiones relacionados

- `context/37-numeracion-documentos.md` — plan de numeración correlativa de documentos (D2/D3/D5 pendientes); el correlativo de cotización ya usa el mecanismo F2 de ese plan (`DocumentNumber::allocate`).
- `context/38-impuestos-multi-pais.md` — F2a (impuesto congelado por línea), que la cotización comparte con la venta.
- `context/39-detalle-transaccion.md` — F1-F3 implementadas; el resolver canónico ya lee `quote_to_sale`, pendiente que algo lo escriba (regla 4).
- `context/35-transaction-link.md` — modelo completo de `transaction_link`, incluye `quote_to_sale` en la tabla de `kind`.
- `context/modules/10-pos-venta.md` regla 4 — la asimetría de numeración del lado venta (online nunca asigna `invoiceNo`), contraparte de la regla 1 de este doc.
