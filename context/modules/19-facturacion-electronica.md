# 19 — Facturación electrónica

> Estado del doc: verificado contra código 2026-08-17
> Responsable de la última verificación: sesión 2026-08-17 (docs numeración/impresión/facturación electrónica)

## 1. Qué resuelve

Emite el Documento Electrónico (DE) ante SIFEN vía Factomate (proveedor real, no Automate — ver §8) a partir de una venta ya persistida en Punto, de forma asíncrona (outbox) o inline, y sostiene la nota de crédito electrónica para devoluciones vinculadas a una factura ya emitida.

## 2. Entidades y datos

| Tabla | Qué guarda | Invariantes / trampas |
|---|---|---|
| `einvoice_document` | Un DE por `(companyId, transactionId, doctype)`: `status` (`pending\|issued\|error\|cancelled`), `cdc` (identificador SIFEN), `issued_at`, `cancelled_at`/`cancel_reason`. | Solo un `issued` con `cdc IS NOT NULL` sirve como "factura original" para armar una NC — uno en error/pendiente no tiene CDC, uno ya cancelado no tiene nada que corregir (`api/lib/EInvoice/EInvoiceService.php:1747-1762`). |
| Config de cuenta (JSONB, por companyId) | Credenciales/bearer de Factomate, `autoIssue`/`onlyWithTaxId`/`paymentMethodMap`. | Fuera del alcance de este doc — ver `EInvoiceService::saveConfig`/`getAccount`. |

Este módulo NO tiene tabla de numeración propia — el CDC/número del DE lo asigna Factomate/SIFEN al firmar, no `document_sequence` (ver §5, interacción con Numeración).

## 3. Reglas de negocio

1. **Lee el desglose CONGELADO de la venta, no el catálogo** (F3a, commit `f9db4ffc`, 2026-08-08). `resolveTaxRatesForItems()` usa `taxRate`/`taxKind` ya escritos por línea en `meta.transactionDetails` (`SaleService::enrichWithTaxes`, F2a) como fuente PRIMARIA; solo consulta `tax.rate`/`tax.kind` del catálogo (con `tax.name` como último fallback legacy) para líneas SIN congelado — ventas anteriores al deploy de F2a. Si TODAS las líneas están congeladas, no corre ninguna query (`EInvoiceService.php:1386-1421`). Es la regla central del módulo: si el comercio cambia una tasa después de vender, el documento electrónico declara la tasa que realmente se cobró, no la vigente hoy en el catálogo — evita que el DE diverja de la venta real.
2. **Validación SIFEN admite solo 10\|5\|0 — es validación de FORMATO, no fuente del dato.** `assertSifenRate()` recibe la tasa (congelada o resuelta) y si no matchea exactamente `10`/`5`/`0` (con `exempt` mapeado siempre a `0`), lanza una excepción explícita con la tasa ofensora y el nombre del ítem (`EInvoiceService.php:1368-1384`). Una tasa custom de un tenant multi-país que no sea 10/5/0 hoy ROMPE la emisión — no se factura, no hay degradación silenciosa.
3. **Error de resolución de tasas nunca degrada a "sin impuesto".** Si la query de fallback falla, se lanza `\RuntimeException` explícita en vez de emitir el documento con todo exento — comentario textual: sería "exactamente el bug que este método existe para evitar" (`EInvoiceService.php:1441-1448`).
4. **Bruto de línea = `taxNet + taxAmount` cuando está congelado**, con fallback a `total - totalDiscount` solo para líneas sin congelar (`lineNetForSale()`, `EInvoiceService.php:1351-1357`). Antes de F3a, `taxIncluded=true` funcionaba con `total-totalDiscount` para TODO — con líneas mixtas frozen/no-frozen eso podía divergir de la suma real en modo "impuesto añadido" (comentario textual, `EInvoiceService.php:1520-1526`). El total del documento es SIEMPRE la suma de `$items` ya armados línea por línea (nunca `transactionTotal - transactionDiscount` por separado), para que cierre exacto contra lo que declara el detalle — Factomate rechaza el DE si no cierra (`EInvoiceService.php:1556-1568`).
5. **Líneas excluidas del DE**: giftcard, `inCredit`, cantidad ≤0 o neto 0 — filtradas ANTES de resolver tasas, con la MISMA fórmula (`lineNetForSale`) que arma después `$items`, para que el filtro y el armado nunca diverjan sobre el mismo número (`EInvoiceService.php:1520-1538`). Consecuencia documentada: el total del DE es menor al de la venta completa cuando hay líneas excluidas — es correcto (un DE fiscal no declara giftcards ni movimientos in-credit), pero si algún día una línea excluida debiera facturarse, el cálculo no lo refleja sin revisar el filtro (`EInvoiceService.php:1561-1568`).
6. **Cliente**: `nature` ∈ `contribuyente`\|`fisica`\|`innominado`. Contribuyente exige RUC; física sin RUC exige CI; innominado (consumidor final) solo permitido bajo el tope de Gs. 1.000.000, y nunca para venta a crédito (`SaleToInvoiceMapper.php:358-370`). `contributorType`: CON_RUC=1 (persona física) / SIN_RUC=2 — mapeo verificado contra una emisión real, no intuitivo (uno esperaría 2=jurídica ligado a tener RUC) pero es lo que la API de Factomate acepta; NO "corregir" (`SaleToInvoiceMapper.php:372-376`).

## 4. Flujos principales

- **Emisión por outbox (asíncrono)**: `enqueueForSale()` encola el DE al confirmar la venta; `drain()` (cron, secreto compartido) procesa `pending`/`error` vencidos en lote — `issueClaimedDocument()` reconstruye el shape de venta (`buildSaleArrayForMapper`), resuelve tasas, arma el payload con `SaleToInvoiceMapper`, y llama al provider (`EInvoiceService.php:994-1176`).
- **Emisión inline**: `tryIssueInline()` — mismo camino, sin pasar por el outbox, para el caso donde se necesita el resultado en el momento.
- **Nota de crédito (devolución)**: requiere que la devolución esté vinculada a una venta original vía `transaction_link` (`kind='return'`, mig 115 dropeó `transactionParentId`) y que esa venta original tenga un DE `issued` con CDC (`EInvoiceService.php:1737-1762`). Las líneas salen de `itemSold` (no de `meta.transactionDetails`, que es de la venta), y el `taxRate`/`taxKind` se resuelve contra el detalle CONGELADO de la VENTA ORIGINAL (nunca el catálogo actual, que pudo cambiar) — mismo criterio que regla 1, aplicado un nivel arriba (`EInvoiceService.php:1798-1834`).
- **Cancelación del DE (SIFEN)**: `cancel($companyId, $docId, $reason)` — acción MANUAL de panel, gateada por permiso `einvoice.manage`, vía `POST /v1/einvoice?action=cancel` (`api/v1/einvoice.php:20, 224-231`). Llama a Factomate con el CDC y el motivo, y solo si el provider confirma marca `status='cancelled'` local (`EInvoiceService.php:721-759`). Exige `reason` no vacío y que el documento esté `issued` con CDC.
- **Error / borde — PDF (KuDE) no disponible**: si Factomate todavía no terminó de generar el KuDE, la excepción sube tal cual al endpoint (reintento visible); nunca se marca el documento como `error` por esto — la factura ya se emitió (`EInvoiceService.php:761-770`).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Impuestos | Lee `taxRate`/`taxKind` congelados por línea; solo cae al catálogo para ventas pre-F2a | Que el congelado, cuando existe, es la fuente de verdad — nunca reconsulta el catálogo si ya hay snapshot (mismo contrato documentado en `04-impuestos.md §5`). |
| Numeración | **No consume `DocumentNumber`.** El CDC/número del DE lo asigna Factomate/SIFEN al firmar — es una numeración PARALELA a `document_sequence`, verificado por ausencia total de `invoiceNo`/número interno en `SaleToInvoiceMapper.php`. | Que el `invoiceNo` interno (impreso en el ticket) y el número/CDC del DE son dos identificadores DISTINTOS del mismo hecho económico — no hay reconciliación automática entre ambos documentada en este código. |
| Contactos | `resolveClient()` decide `nature`/`identityDocumentTypeCode` según si el contacto tiene RUC, CI, o ninguno (regla 6) | Que el tipo de documento del comprador (RUC vs CI vs innominado) determina el shape completo del payload — un contacto sin ninguno de los dos solo puede facturarse como innominado, con el tope de Gs. 1.000.000. |
| Anulación y nota de crédito (`context/40`) | La NC electrónica (§4) depende de `transaction_link kind='return'` — el mecanismo de DEVOLUCIÓN ya existente, no del plan de ANULACIÓN de `context/40` | **Divergencia real**: `context/40-anulacion-y-nota-credito.md` está declarado como "plan cerrado, sin implementar" para la anulación de venta/factura — pero la NC electrónica de este módulo YA emite hoy, atada a devoluciones, un flujo distinto y preexistente. Cancelar un DE (`cancel()`) es además una acción manual e independiente de cualquier flujo de anulación de venta — no hay código que dispare `cancel()` automáticamente cuando una venta se anula. |
| Remisión (`context/42`) | Sin interacción directa encontrada en `EInvoiceService`/`SaleToInvoiceMapper` — la remisión electrónica (si existe) no aparece en este código. | **NO VERIFICADO**: si SIFEN exige un DE de remisión separado y si este módulo lo cubre — no se auditó `context/42` a fondo en esta sesión. |

## 6. Huecos conocidos y NO verificado

- **Plan `context/28-facturacion-electronica-plan.md` está desactualizado respecto a F3a.** El encabezado del plan dice "F0–F4, F6 y F7 implementadas" pero no menciona F3a (commit `f9db4ffc`, 2026-08-08, posterior a la última verificación del plan) en ningún lugar del documento — la fuente de verdad hoy es el código, no el plan.
- **Campos que quedan `null` hoy**: `identityDocumentNumber` para innominado, `ruc` para física/innominado (`SaleToInvoiceMapper.php:420, 434, 438`) — por diseño, son mutuamente excluyentes según `nature`.
- **NO VERIFICADO**: cobertura real de remisión electrónica (ver interacción de arriba).
- **NO VERIFICADO**: qué pasa si `cancel()` se llama sobre un DE que ya tiene una NC emitida en su contra — no se encontró un chequeo explícito de esa combinación en el código leído.
- F1–F3 del plan original están "verificadas contra la API real solo en el camino de factura al contado con un único medio de pago" (encabezado de `context/28`) — crédito y multi-pago no tienen la misma verificación declarada.

## 7. Planes y decisiones relacionados

- `context/28-facturacion-electronica-plan.md` — plan del módulo, pivot de proveedor (Automate→Factomate) documentado, desactualizado respecto a F3a (ver huecos).
- `context/38-impuestos-multi-pais.md` — origen de F3a y de la regla "congelado por línea, nunca recalculado".
- `context/40-anulacion-y-nota-credito.md` — plan de anulación de venta, sin implementar; NO es la fuente de la NC electrónica actual (ver interacciones).
- `context/17-numeracion.md` — numeración interna de Punto, paralela e independiente del CDC de Factomate/SIFEN.
