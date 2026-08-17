# 08 — Compras

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Registrar lo que el comercio le compra a un proveedor (contado o crédito),
hacer que esa compra ingrese al stock con el costo real pagado, y alimentar
la deuda con el proveedor cuando es a crédito. Incluye el camino de carga
asistida por IA (foto/PDF de factura → borrador → aprobación humana).

## 2. Entidades y datos

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `transaction` | Misma tabla que ventas. Compras filtran `transactionType IN (1,4)` — `1`=contado, `4`=crédito. NC de compra es `transactionType=14`. | `transactionStatus` (1 vigente / 6 anulada) es el estado del DOCUMENTO; `transactionComplete` es el estado de PAGO — ver regla 1, es el campo que más diagnósticos errados generó en este módulo. `userId` es quien CARGÓ la compra, no el proveedor (`supplierId` es columna aparte) — `api/lib/Purchases/PurchasesService.php:130-131` lo aclara explícitamente por la ambigüedad con el patrón de `Reports/UsersService.php`. |
| `itemSold` | Una fila por línea con `itemId` REAL — la FK es `NOT NULL` (`PurchasesService.php:26-27,507-519`). | Líneas de "gasto libre" (sin producto, ej. flete) NUNCA aparecen acá — solo viven en `transaction.meta.details`. Cualquier reporte que joinee compras solo por `itemSold` pierde esas líneas. |
| `transaction.meta` (JSONB) | `{ details: [{itemId, title, qty, price, tax, taxId, packSize}, ...] }` — única fuente completa del detalle de la compra, incluye las líneas con producto real. | Trampa del wrapper (`Query::flattenJsonb`): un `SELECT *`/`find()` sin alias `t.meta::text AS meta_raw` devuelve `meta` vacío. Causó el bug histórico de la regla 6. |
| `purchase_draft` (mig 105, `api/database/migrations/postgres/105_purchase_draft.sql:12-27`) | Borrador OCR: `draftid, companyid, outletid, userid, status('pending'\|'approved'\|'rejected'), imageref, extracted jsonb, edited jsonb, contactid, transactionid, error, created_at, approved_at`. | `extracted` es inmutable (lo que devolvió la IA); `edited` es lo que el usuario corrigió y lo ÚNICO que se usa al aprobar — la IA nunca reinterpreta `extracted` en el server. Ver regla 5. |
| `transaction.invoicePrefix` | Combina `authNo;prefix` del comprobante del PROVEEDOR en un solo string (`PurchasesService.php:451-453`, `combinedPrefix`). | Formato heredado del legacy, preservado por compat de facturación electrónica. No confundir con la numeración correlativa PROPIA del comercio (ver regla 9) — este es un dato libre tipeado/extraído por OCR del papel del proveedor. |

## 3. Reglas de negocio

1. **`transactionStatus` (documento) vs `transactionComplete` (pago) — CRÍTICO.** `transactionStatus`: `1`=vigente, `6`=anulada (`PurchasesService.php:564-565,591`, sin enum en código, es convención de comentarios). `transactionComplete`: `1` si la compra es contado, `0` si es crédito — seteado exactamente en `PurchasesService.php:469`: `'transactionComplete' => $isCredit ? 0 : 1`. Consecuencia técnica del modelo de crédito (regla 2), no una decisión aislada. Consumidores reales que dependen de este contrato, no solo comentarios: `Reports/PurchasesService.php:81,94` (calcula `debt`/`canAddPayment` con `!isComplete(transactionComplete)`), `Reports/OpenInvoicesService.php:51,148,199` (`WHERE transactionComplete = false AND transactionType = 4` para el estado `'outcome'` = proveedores), `Finance/FinanceLedger.php:182-184` (`recordPurchase()` corta en seco si `transactionType !== '1'` — "sin este corte el crédito debitaría la caja dos veces"), `PurchaseCreditNoteService.php:535-538` (una NC en modo `'credit'` rechaza si la compra padre ya tiene `transactionComplete` truthy). Antes del commit `1f7b8dd0` toda compra nacía `type=1, complete=1` — el crédito no existía en el alta aunque la capa de reportes ya lo asumía.

2. **Contado vs crédito lo decide el campo `condition` del payload** (`'cash'` por default, back-compat) — `PurchasesService.php:288-297`. Crédito exige `dueDate`: sin vencimiento tira `RuntimeException` (`:304-306`, "una cuenta por pagar sin vencimiento no entra en Previsiones" — decisión del owner sobre el modelo de crédito, no una validación arbitraria).

3. **A crédito, el método de pago se fuerza a vacío al crear** — `PurchasesService.php:430-432`, `transactionPaymentType` queda `null`. El pago real es un documento aparte (`CreditPaymentService`, `transactionType=5`) vinculado vía `transaction_link kind='purchase_payment'` (mig 115) — la compra a crédito nunca lleva su propio pago adentro.

4. **`packSize` (bulto → unidades) — el precio que carga el cajero es del PAQUETE, no de la unidad.** Conversión: `$effectiveUnits = $units * $packSize` (`PurchasesService.php:352-355,385`) — lo que llega a `itemSold`/`stock` son unidades reales, no paquetes. El costo unitario que se manda a `manageStock()` se recalcula DESPUÉS: `$price = $lineTotal / $units` donde `$units` ahí ya es `effectiveUnits` (`:503-505`) — equivale a `price_pagado_por_paquete / packSize` (comentario explícito `:384`, confirmado en el mensaje del commit `ba92ef9c`). Si `packSize < 1` la creación falla (`:352-355`).

5. **OCR de facturas — la IA nunca escribe stock ni finanzas, solo el borrador.** Decisión del owner, plan cerrado 2026-07-31 (`context/32-ocr-facturas-compra.md`, invariante repetido en `105_purchase_draft.sql:5`, `PurchaseDraftService.php:17-18`, `purchase-drafts.php:21-23`). `PurchaseDraftService::approve()` (`:247-338`) siempre usa `edited` (nunca `extracted` crudo) y llama al MISMO `PurchasesService::create()` que el alta manual — no hay un segundo camino de creación. Lock `FOR UPDATE` + chequeo de `status==='approved'` (`:261,271-280`) evita doble-alta por doble-click. `reject()` (`:341-374`) solo permitido si no está ya aprobado. El detalle de arquitectura/modelo de IA está en `context/32-ocr-facturas-compra.md` — no se duplica acá.

6. **Anular una compra SÍ revierte el stock hoy — pero fue un bug de producción.** `PurchasesService::void()` (`:571-648`) marca `transactionStatus=6` y por cada línea con `itemId` real llama `Inventory::manageStock(type:'-', source:'purchase-void')` (`:626-638`). El código lee `meta` con alias `t.meta::text AS meta_raw` (`:583,596-597`) — comentario explícito: "Sin esto, `details` quedaba vacío y anular una compra NO revertía el stock". Bug real e histórico: `Query::flattenJsonb` (el wrapper de DB) vaciaba `meta` en cualquier `SELECT` sin ese alias; el fix puntual fue el commit `6119a041` ("PurchasesService (anular compra) — el más grave del barrido"), y la solución arquitectónica de fondo fue `5b73d00f` (`Query::rawJsonb()`, side-channel que preserva el JSON crudo sin depender de que cada caller aliasee a mano). El código actual de `void()` sigue usando el alias explícito `meta_raw` (válido cuando uno controla el SQL, según el propio comentario de `5b73d00f`), no el accessor nuevo.

7. **Costeo: compra usa el mismo choke point que todo el sistema (`Inventory::manageStock()`), sin excepción.** `PurchasesService::create()` llama `manageStock()` por cada línea con `itemId`, `type` implícito `'+'` (`:530-542`), con `cogs => $price` = costo unitario REAL post-`packSize` (regla 4). El detalle de cómo `manageStock()` recalcula el promedio ponderado, el bug histórico de `Math::divide` y la reconstrucción de la mig 131 están en `context/modules/05-stock.md` (reglas 3 y 6) — no se duplica acá, aplica igual porque es el mismo código.

8. **Impuestos: el backend de compras resuelve la tasa server-side, no confía en lo que manda el form.** `resolveTaxMeta()` (`PurchasesService.php:685-740`) mira `tax` (rate/kind reales, mig 120) primero, cae a `taxonomy` + inferencia por nombre si el `taxId` no tiene fila poblada; un `taxId` que no resuelve a nada del tenant queda exento en 0, nunca se infiere una tasa (`:369-371`). Esto cerró una vulnerabilidad ("el backend confiaba en el `taxValue` del payload", comentario `:321-326`, fix `e0066eaf`, F2a). Detalle general de impuestos en `context/modules/04-impuestos.md` — no se duplica acá.

9. **Mig 121 (`tax.sortOrder`) NO es específica de compras — es el orden manual de la lista de impuestos en Settings, y el form de compras lo consume para su default.** La migración (`121_tax_sortorder.sql`) solo agrega `tax.sortOrder INT` (drag&drop en Settings → Catálogo → Impuestos), `NULLS LAST` con fallback alfabético. `purchase-form-fields.tsx:142-151`: una línea nueva sin `taxId` explícito se auto-completa con `firstTaxId` = el primer impuesto de la lista YA ordenada por `sortOrder` — "el comercio decide cuál es su default arrastrándolo arriba" (comentario `:142-144`). Es decisión de UX del owner, no un default hardcodeado en el backend.

10. **Compras NO usa numeración correlativa propia del comercio.** `invoiceNo`/`invoicePrefix` son datos LIBRES (tipeados o extraídos por OCR) del comprobante que emitió el PROVEEDOR — sin relación con `numbering_lease`/`RegisterLeaseService`/`DocumentNumber::allocate()` (ningún archivo de `api/lib/Purchases` ni `api/v1/purchases*.php` referencia `numbering`; esos símbolos solo aparecen del lado de emisión de venta). Compras no emite un documento fiscal propio numerado por Punto.

11. **Hallazgo — sin permission key dedicada.** `api/v1/purchases.php:21` y `api/v1/purchase-drafts.php:30` solo exigen `apiAuthTenant(['panel'])` — cualquier usuario con sesión de panel activa, sin importar su rol, puede crear/anular compras o aprobar bordadores OCR. El propio docblock de `purchase-drafts.php:16-19` lo deja constancia explícita. Mismo patrón que `05-stock.md` regla 9 (`inventory.stock.adjust` sin enforce en backend) — no es un caso aislado, es una clase de bug repetida en varios endpoints de panel.

## 4. Flujos principales

**Alta manual (contado o crédito)** — `PurchasesService::create()` (`api/lib/Purchases/PurchasesService.php:262-552`), disparada por `POST /v1/purchases` (`api/v1/purchases.php:105-136`). Valida líneas, resuelve impuestos server-side (regla 8), convierte `packSize` (regla 4), abre TX (`StartTrans`), inserta `transaction` + `itemSold` por línea con producto, corre `manageStock()` por línea (regla 7), cierra TX. Si es crédito, exige `dueDate` (regla 2) y no genera línea de pago (regla 3). Post-commit, best-effort: `FinanceLedger::recordPurchase()` solo actúa si es contado (regla 1).

**Alta asistida por OCR** — foto(s)/PDF → `PurchaseDraftService` extrae con IA (Gemini vía OpenRouter, capability `vision`) → persiste `purchase_draft` en `pending` con `extracted` inmutable → usuario corrige en `edited` → `approve()` llama exactamente al mismo `PurchasesService::create()` del alta manual (regla 5). Ver `context/32-ocr-facturas-compra.md` para el detalle completo del pipeline de extracción.

**Anulación** — `PurchasesService::void()` (`:571-648`), disparada por `DELETE /v1/purchases` (`api/v1/purchases.php:162-181`). Idempotente: rechaza si `transactionStatus !== 1` (`:591-592`). Marca `transactionStatus=6` y revierte stock línea por línea (regla 6). No se encontró reversión de la deuda por pagar más allá de que el reporte de cuentas por pagar ya no computa una compra anulada como pendiente (filtra `transactionStatus=1` en `OpenInvoicesService`) — NO VERIFICADO si además hay algún ajuste explícito de `transactionComplete` al anular una compra a crédito parcialmente pagada.

**Pago a proveedor (saldar crédito)** — vive fuera de este servicio, en `CreditPaymentService` (`transactionType=5`), vinculado vía `transaction_link kind='purchase_payment'`. NO se investigó en profundidad para este doc — mencionado solo como contraparte de la regla 3; el detalle completo pertenece a `context/modules/15-credito-y-cobranzas.md` (sin escribir aún).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide/da Compras | Contrato (qué asume) |
|---|---|---|
| Stock (`05-stock.md`) | `manageStock()` con `source ∈ {purchase, purchase-void}`, costo real post-`packSize` como `cogs`. | Que `manageStock()` es el único camino (no hay un segundo insert directo a `stock`); que el costo de entrada es confiable — `manageStock()` no lo valida, solo lo promedia. |
| Cuentas por pagar / Previsiones (`15-credito-y-cobranzas.md`, sin escribir) | `transactionType=4` + `transactionComplete=false` es el contrato COMPLETO que consumen `OpenInvoicesService` y `Reports/PurchasesService`. | Que ninguna otra ruta del código setea `transactionComplete` para una compra fuera de `create()` y el pago a proveedor — un conteo de filas con `transactionType=4` sin mirar `transactionComplete` NO alcanza para afirmar "no hay compras a crédito pendientes" (fue exactamente el diagnóstico errado que motivó este doc). |
| Impuestos (`04-impuestos.md`) | Resuelve `rate/kind` server-side por `taxId`, nunca confía en el `taxValue` del payload. | Que `tax`/`taxonomy` del tenant son la única fuente de verdad; un `taxId` ausente cae a exento 0, nunca infiere una tasa. |
| Numeración (`17-numeracion.md`) | No le pide nada — compras no emite documento numerado por Punto. | Que `invoiceNo`/`invoicePrefix` de una compra son datos del proveedor, nunca correlativos propios — un reporte que trate ese campo como "número de comprobante propio" se equivoca de dueño. |
| OCR / IA (`context/32`) | Un borrador aprobado dispara el MISMO `create()` que el alta manual. | Que la IA nunca tiene un camino directo a `manageStock()`/`FinanceLedger` — solo escribe `purchase_draft`, nunca `transaction`. |
| Notas de crédito de compra (`09-notas-credito-compra.md`) | La NC referencia la compra original vía `transaction_link kind='purchase_credit_note'`, nunca por FK directa. | Que la compra original sigue vigente (`transactionStatus=1`) y, si el modo es `'credit'`, que aún no está saldada (`!transactionComplete`) — `PurchaseCreditNoteService::assertCreditEligible()` lo re-valida server-side. |
| Finanzas (`FinanceLedger`) | `recordPurchase()` post-commit, best-effort, solo si `transactionType==='1'`. | Que un fallo de `FinanceLedger` (solo logueado, sin cola de reintento visible) nunca revierte la compra ya confirmada — la compra es la fuente de verdad, el ledger la sigue. |

## 6. Offline

Compras no es un módulo del POS: `api/v1/purchases.php` y `purchase-drafts.php`
exigen `apiAuthTenant(['panel'])` exclusivamente — no hay realm de dispositivo
que cargue una compra. No aplica la regla base de "lo que se emite funciona
offline" (`08-convenciones-criticas.md §53`) porque una compra no es un
documento que el POS emita en el mostrador.

## 7. Huecos conocidos y NO verificado

- **Sin permission key dedicada** (regla 11) — hallazgo confirmado de esta sesión, mismo patrón que `05-stock.md` regla 9.
- **NO VERIFICADO**: si anular una compra a crédito PARCIALMENTE pagada ajusta `transactionComplete` o el saldo pendiente de algún modo explícito — solo se confirmó que el reporte de cuentas por pagar deja de listar la compra anulada por filtrar `transactionStatus=1`.
- **NO VERIFICADO**: contenido completo de `CreditPaymentService` (pago a proveedor, `transactionType=5`) — se confirmó que existe y que `transaction_link kind='purchase_payment'` lo vincula, pero no se leyó su flujo línea por línea. Pertenece a `15-credito-y-cobranzas.md`, sin escribir.
- **NO VERIFICADO**: si existe algún job/cron que reintente `FinanceLedger::recordPurchase()` cuando el best-effort post-commit falla (solo se confirmó `error_log`, sin cola visible en el código leído).
- **Legacy de devoluciones/reposiciones sin migrar** — `api/v1/purchases.php:17` deja constancia explícita: "Devoluciones y reposiciones del legacy quedan para iteración posterior". Hoy el único camino de devolución a proveedor es la NC de compra (`09-notas-credito-compra.md`). NO VERIFICADO si el panel legacy PHP (`panel/a_purchase.php`, si existe) todavía expone una ruta distinta y activa en producción.
- **Cuentas de gasto libre (sin `itemId`) no dejan rastro en `itemSold`** — por diseño (FK `NOT NULL`, regla en sección 2), solo viven en `transaction.meta.details`. Cualquier reporte/BI que joinee compras exclusivamente por `itemSold` pierde esas líneas sin error visible.

## 8. Planes y decisiones relacionados

- `context/32-ocr-facturas-compra.md` — plan cerrado 2026-07-31 del pipeline de extracción IA y el modelo borrador→aprobación.
- `context/modules/05-stock.md` — choke point de movimiento e inventario (`manageStock()`), costeo por promedio ponderado.
- `context/modules/04-impuestos.md` — motor de impuestos, tasas, incluido/añadido.
- `context/37-numeracion-documentos.md` — numeración correlativa de documentos internos; compras queda explícitamente fuera del alcance migrado (regla 10).
- `context/35-transaction-link.md` — mecanismo de vínculo entre transacciones que usa la NC de compra y el pago a proveedor.
