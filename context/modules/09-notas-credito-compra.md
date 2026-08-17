# 09 — Notas de crédito de compra

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Cuando un proveedor emite una nota de crédito (mercadería dañada, faltante,
descuento post-venta), este módulo registra ese documento, decide si mueve
plata (reembolso en efectivo) o solo reduce una deuda a crédito, y decide si
la mercadería vuelve o no al proveedor (movimiento de stock). Es el espejo
estructural de `ReturnService` (devolución de venta) con semántica de plata
opuesta: acá la plata entra o reduce lo que el comercio debe, no sale de una
caja.

## 2. Entidades y datos

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `transaction` (`transactionType=14`) | Documento hermano de la compra (`1`/`4`) — vive en `transaction`/`itemSold` igual que cualquier otro documento del dominio (`PurchaseCreditNoteService.php:9-13`). | `transactionTotal` se guarda en POSITIVO neto de descuento — a diferencia de `ReturnService` (devolución de venta), que lo guarda negativo (`PurchaseCreditNoteService.php:213-224`, decisión explícita documentada en el propio comentario). No asumir el signo por analogía con devoluciones de venta. |
| `itemSold` | Una fila por línea, `itemsoldunits` NEGATIVAS (`:267-288`). | `itemsoldtax` se calcula proporcionalmente por línea pero es **informativo — no se suma al total** (comentario `:99`). No revierte el IVA de la compra original de forma agregada (ver regla 7). |
| `transaction.meta` | Se inserta como literal `'{}'` hardcodeado (`:248`) — nunca se lee después. | El bug histórico de `flattenJsonb`/`meta_raw` de `08-compras.md` regla 6 **NO aplica acá**: esta clase no selecciona `meta` en ningún punto (`void()`, `listForParent()`, `creditedQtyByItem()` no lo tocan). |
| `transaction_link` (`kind='purchase_credit_note'`, mig 122) | Vincula la NC con la compra original: `originId`=compra, `derivedId`=NC. Único mecanismo de referencia — **no hay FK directa** entre las dos filas de `transaction`. | Ver regla 2 sobre cómo se calcula el saldo a partir de este vínculo, y la divergencia de fórmula entre los dos consumidores. |

## 3. Reglas de negocio

1. **Mig 122 no crea tabla ni columna — solo extiende el `CHECK` de `transaction_link.kind`, y esa migración tiró TODOS los deploys ~4 horas.** `122_purchase_credit_note.sql:1-56` agrega `'purchase_credit_note'` al `CHECK` de `kind` (mig 115). La versión original ubicaba el constraint viejo con `pg_get_constraintdef(con.oid) ILIKE '%kind%IN%'` antes de dropearlo — pero Postgres normaliza `kind IN (...)` a `kind = ANY (ARRAY[...])` al persistir la definición, así que ese patrón textual NUNCA matcheaba, ni en la primera corrida. El `ADD CONSTRAINT` de abajo chocaba entonces contra el nombre que la mig 115 ya había autogenerado (`transaction_link_kind_check`), y la migración abortaba el boot del container — tirando todos los deploys. Fix real, commit `52bfde6f`: localizar el constraint por la COLUMNA que referencia (join `con.conkey` con `pg_attribute.attname = 'kind'`), nunca por texto de definición. Consecuencia técnica de una trampa de Postgres, no un error de diseño del modelo — pero vale como advertencia general: **nunca buscar un constraint por el texto de su `CHECK`, siempre por columna**.

2. **El saldo de una compra a crédito sale de sumar `transaction_link`, no de una columna — pero hay DOS fórmulas distintas para el mismo cálculo.** `TransactionLinkService::KINDS` incluye `'purchase_credit_note'` (`api/lib/services/TransactionLinkService.php:22-25`); `PurchaseCreditNoteService::create()` linkea con `kind='purchase_credit_note'` dentro de la misma TX (`:265`). Dos implementaciones paralelas leen ese vínculo para calcular "cuánto se saldó" de la compra padre: `Reports\PurchasesService::payedByParent()` (`api/lib/Reports/PurchasesService.php:399-411`) usa `TransactionLinkService::mapSumDerivedAmounts()` (suma `tl.amount ?? transactionTotal`, SIN restar `transactionDiscount` — comentario `:388-397` documenta la decisión); `Finance\OpenInvoicesService::payedByParent()` (`api/lib/Reports/OpenInvoicesService.php:360-411`) hace su propia query (`ABS(transactionTotal - COALESCE(transactionDiscount,0))`, `:391-393`), explícitamente "discount-aware" porque considera que `amount` de `transaction_link` no aplica para este caso. `TransactionLinkService::sumDerivedAmounts()` (`:216-227`), la superficie única que el patrón general esperaría, **no la usa ninguna de las dos** para `purchase_credit_note`. No hay evidencia de que esto produzca un resultado erróneo hoy (ambas fórmulas coinciden si no hay descuento en la compra padre), pero es una inconsistencia de mantenimiento real: dos lugares calculan lo mismo con criterios distintos para el mismo `kind`.

3. **Solo dos `refundMode`: `'cash'` o `'credit'` — no existe un tercer modo (aplicar contra compra futura, etc).** `PurchaseCreditNoteService::create()` valida con `in_array($refundMode, ['cash','credit'], true)`, tira `InvalidArgumentException` si no matchea (`:74-76`). `'cash'`: genera línea de pago `{type:'cash', total: creditNet}` (`:227-235`), leída post-commit por `FinanceLedger::recordPurchaseCreditNote()` como un INGRESO. `'credit'`: no genera línea de pago (`:236-237`) — solo entra en el cálculo de saldo de la regla 2. Requiere `assertCreditEligible()` (`:527-538`): la compra padre debe ser `transactionType=4` (crédito), `transactionStatus=1` (vigente), `transactionComplete` falso (aún no saldada) — si la compra padre ya está saldada, la NC en modo `'credit'` se rechaza.

4. **`affectsStock` es un eje independiente de `refundMode`.** `true` → el proveedor se lleva la mercadería dañada, resta stock (regla 5). `false` → bonificación/descuento sin devolución física, no toca stock. Un operador puede combinar cualquier `refundMode` con cualquier `affectsStock` — son dos decisiones separadas del mismo formulario.

5. **NC parcial soportada, cupo controlado por línea (ítem), no por total.** El body acepta un array `items` con `qty` por línea; cupo disponible = `purchasedQty - alreadyCredited` por `itemId` (`:174-191`), rechaza si `reqQty > available + 0.001`. Líneas repetidas del mismo `itemId` en el mismo request se mergean ANTES de validar (`:119-129`, comentario explícito: si no, dos líneas del mismo ítem pasarían el chequeo de cupo por separado, evadiéndolo). No hay un chequeo adicional de "total NC vs total compra" — queda acotado transitivamente por el control por-línea.

6. **La NC de compra SÍ mueve stock cuando `affectsStock=true` — confirma que la devolución a proveedor tiene un único dueño.** `PurchaseCreditNoteService.php:290-308`: `Inventory::manageStock(type:'-', source:'purchase_credit_note', ...)`, comentario explícito "el proveedor se lleva la mercadería — resta stock (espejo de `PurchasesService::create`, que suma)". Esto cierra el círculo con `context/modules/20-remision.md:31`, que documenta que el motivo `devolucion_proveedor` de `document_remision` NUNCA mueve stock ahí porque su dueño es justamente `PurchaseCreditNoteService` — **no hay hueco**: el stock de una devolución a proveedor se mueve en un solo lugar, y es este módulo, nunca la remisión.

7. **Anular la NC revierte el stock — detecta si hubo movimiento leyendo `stock`, no un flag propio.** `PurchaseCreditNoteService::void()` (`:356-445`) marca `transactionStatus=6` (no toca `transactionType`, `:392-396`). Para saber si debe revertir, consulta si existe una fila en `stock` con `stocksource='purchase_credit_note'` para esa transacción (`:378-388`) — no guarda un booleano propio; si hubo, revierte con `Inventory::manageStock(type:'+', source:'purchase_credit_note-void', ...)` (`:418-431`). El bug histórico de `flattenJsonb`/`meta_raw` (`08-compras.md` regla 6) no aplica acá — ver sección 2.

8. **La NC no revierte el IVA de la compra original de forma agregada.** `itemsoldtax` se calcula proporcionalmente por línea (`:169,172,195,200`) pero es solo informativo, no se suma al total de la transacción (comentario `:99`). NO VERIFICADO si algún rollup fiscal (RG90/Libro Ventas, F5 de `context/38-impuestos-multi-pais.md`) incluye o excluye estas filas — no se encontró mención de `transactionType=14` en ese doc ni en el código de reportes fiscales revisado.

9. **No usa numeración correlativa propia.** El `INSERT` de la NC (`:242-262`) no incluye `invoiceno`/`invoiceprefix` ni llama `DocumentNumber::allocate()`. `context/37-numeracion-documentos.md` (D2, cerrada) clasifica documentos fiscales bajo scope `register`, pero la fase "F2 — HECHA" solo cubre factura, cotización y orden — la NC de compra queda fuera del asignador migrado. Gap arquitectónico abierto, no un bug de esta sesión.

## 4. Flujos principales

**Creación** — disparada desde el detalle de compra en el panel (`frontend/hooks/use-purchases.ts:233`, `POST /v1/purchases?resource=creditNote`, endpoint `api/v1/purchases.php:60-103`), procesada por `PurchaseCreditNoteService::create()`. Pasos: valida que la compra padre exista y sea `transactionType` 1 o 4 (lectura previa, sin lock); dentro de la TX, re-valida la fila padre con `FOR UPDATE` (`:92-117`, evita que dos requests concurrentes emitan NC sobre el mismo cupo); mergea líneas repetidas (regla 5); valida cupo por línea; si el modo es `'credit'`, corre `assertCreditEligible()` (regla 3); inserta `transaction` (`type=14`) + `itemSold` (unidades negativas) + el `transaction_link` (regla 2); si `affectsStock`, revierte stock línea por línea (regla 6). Cierra TX (`FailTrans`+`CompleteTrans`+rethrow ante cualquier excepción, `:312-316` — atómico, nada queda a medias). Post-commit, best-effort: si `refundMode='cash'`, `FinanceLedger::recordPurchaseCreditNote()` (try/catch que solo loguea, `:323-327`, mismo criterio que `ReturnService`/`recordReturn`).

**Anulación** — `PurchaseCreditNoteService::void()` (`:356-445`), marca `transactionStatus=6`, detecta y revierte stock si corresponde (regla 7). El loop de reversión de stock NO tiene su propio try/catch interno (a diferencia de `create()`) — si `manageStock()` lanzara una excepción no capturada, quedaría sin manejar en ese punto específico; no se encontró evidencia de que `manageStock()` efectivamente lance excepciones en su camino normal (los guards tempranos devuelven `false`, no tiran), así que esto es una observación de robustez, no un bug confirmado.

**Error a mitad de camino** — la creación es atómica dentro de la TX (regla anterior); lo único best-effort y no transaccional es el registro en `FinanceLedger`, que si falla deja la NC creada pero sin su asiento contable — mismo patrón que el resto del sistema de finanzas (ver `08-compras.md` sección 7 sobre `FinanceLedger::recordPurchase`).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide/da la NC de compra | Contrato (qué asume) |
|---|---|---|
| Compras (`08-compras.md`) | Referencia la compra original SOLO vía `transaction_link kind='purchase_credit_note'` — nunca por FK directa. `PurchasesService::find()` expone `creditNotes`/`creditedQty` en el detalle. | Que la compra padre sigue vigente (`transactionStatus=1`) al momento de emitir la NC — re-validado con lock, no solo en la lectura inicial. |
| Stock (`05-stock.md`) | `manageStock()` con `source ∈ {purchase_credit_note, purchase_credit_note-void}` cuando `affectsStock=true`. | Mismo choke point que toda compra/venta — no hay un segundo insert directo a `stock`. |
| Remisión (`20-remision.md`) | Ninguna — es la NC quien mueve el stock de una devolución a proveedor, `document_remision` con motivo `devolucion_proveedor` NUNCA lo hace. | Que ningún caller "complete" el modelo agregando un `manageStock()` en la remisión — sería doble descuento (regla 6). |
| Cuentas por pagar / Previsiones (`15-credito-y-cobranzas.md`, sin escribir) | En modo `'credit'`, reduce el saldo pendiente de una compra a crédito — entra en la suma de `transaction_link` que consumen `Reports/PurchasesService` y `Finance/OpenInvoicesService` (regla 2). | Que ambos consumidores siguen viendo el mismo universo de vínculos aunque calculen el monto con fórmulas distintas — la divergencia de la regla 2 es del cálculo, no de qué filas mira cada uno. |
| Impuestos (`04-impuestos.md`) | No le pide nada activamente — `itemsoldtax` por línea es informativo, no revierte el IVA agregado de la compra original (regla 8). | NO VERIFICADO si los reportes fiscales (RG90/Libro Ventas) incorporan o excluyen `transactionType=14` — hueco de esta investigación. |
| Numeración (`17-numeracion.md`) | No le pide nada — no emite documento numerado por Punto (regla 9). | Mismo contrato que compras: el "número" de la NC (si el proveedor emite uno) es un dato libre, no un correlativo propio. |
| Finanzas (`FinanceLedger`) | `recordPurchaseCreditNote()` post-commit, best-effort, solo si `refundMode='cash'`. | Que un fallo del ledger (solo logueado) nunca revierte la NC ya confirmada. |

## 6. Offline

No aplica — mismo criterio que `08-compras.md` sección 6: la NC de compra se
gestiona desde el panel (`apiAuthTenant(['panel'])`), sin realm de
dispositivo POS involucrado. No es un documento que se emita en el
mostrador.

## 7. Huecos conocidos y NO verificado

- **Dos fórmulas distintas para el mismo saldo** (regla 2) — inconsistencia de mantenimiento confirmada, sin evidencia de que hoy produzca un número erróneo (coinciden mientras la compra padre no tenga descuento), pero es doble superficie para lo que el patrón general pide como una sola.
- **NO VERIFICADO**: si el rollup fiscal (RG90/Libro Ventas) incluye o excluye las filas `itemSold` de `transactionType=14` (regla 8) — no se encontró ese código en esta investigación.
- **`void()` sin try/catch propio en el loop de reversión de stock** (flujo de anulación) — observación de robustez, no bug confirmado; no se encontró evidencia de que `manageStock()` lance excepciones en su camino normal.
- **NC de compra fuera del asignador de numeración correlativa** (regla 9) — gap arquitectónico abierto según `context/37-numeracion-documentos.md`, no implementado.
- **NO VERIFICADO**: si existe algún control de "NC total vs total de la compra padre" más allá del cupo por línea (regla 5) — no se encontró un chequeo agregado explícito, pero el control por-línea lo acota transitivamente en la práctica.

## 8. Planes y decisiones relacionados

- `context/35-transaction-link.md` — mecanismo de vínculo entre transacciones (mig 115), base de la regla 2.
- `context/modules/08-compras.md` — módulo padre; contrato `transactionComplete`/crédito que la regla 3 re-valida.
- `context/modules/20-remision.md` — documenta por qué `devolucion_proveedor` en `document_remision` no mueve stock (regla 6).
- `context/37-numeracion-documentos.md` — numeración correlativa; NC de compra queda fuera de la fase migrada (regla 9).
- `context/38-impuestos-multi-pais.md` — rollup fiscal; no se confirmó si contempla `transactionType=14` (regla 8, hueco abierto).
