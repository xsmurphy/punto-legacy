# 38 — Impuestos multi-tasa / multi-país

> Estado: **plan CERRADO, en ejecución** (2026-08-08). Basado en auditoría de
> 3 agentes (backend, frontend, reportes/fiscal). D1–D4 cerradas por el owner
> 2026-08-08: D1 redondeo por línea al decimal del tenant · D2 kind
> rate/exempt · D3 histórico queda como está (son demos, sin backfill) ·
> D4 renombre con alias sin migrar plantillas.
> Requerimiento: el sistema apunta a toda LATAM — tasas NO fijas, con opción
> global de IVA incluido en el precio o sumado al precio.
> Progreso 2026-08-08: **F0, F1, F2a, F2b, F3a, F3b, F3c hechas — F3
> COMPLETA**. F3a: EInvoice (factura y nota de crédito) lee el IVA congelado
> por línea, no el catálogo; fallback a `resolveTaxRatesForItems` solo para
> ventas pre-F2a; hardcode `{10,5,0}` pasó a ser validación de formato SIFEN,
> no fuente de la tasa. F3b: `TicketItem` (build-ticket-data.ts) lleva
> id/uid/nota/tasa/monto de impuesto por línea — congelado real (F2a) en las
> reimpresiones desde transacción/reportes, motor real (lib/tax/engine.ts) en
> la impresión inmediata del POS. Los 9 bloques de `blocks.ts` que daban
> `null` quedan resueltos; `item_tags` sigue `null` porque ningún builder
> modela etiquetas por línea todavía. F3c: 4 bloques nuevos por-tasa
> (`item_total_by_rate`/`subtotal_by_rate`/`iva_by_rate`/`iva_total`) —
> guardan `taxId` en `block.text`, valores salen de sumar
> `taxNet`/`taxAmount` ya congelados por línea (`groupItemsByTaxRate`,
> blocks.ts — nunca recalcula), paleta del editor pasa a tener una sección
> "Impuestos" función de `useTaxes()`. `tax_single` corregido a su semántica
> real (agregado por tasa a nivel venta, no por línea — se había resuelto mal
> como per-unidad en F3b). D4 (renombre `item_taxAmount`→`item_tax_amount`)
> implementado con alias único en `normalizeBlockType` (blocks.ts), aplicado
> en `sortBlocksForRender` — el único punto de entrada de ambos renderers.
> Fixes de infra 2026-08-09 (mig 121/124, fuera de las fases): `sortOrder` en
> `tax` para orden manual + default al primer impuesto en compras; `toTaxObjText`
> pasó de VARCHAR(255) a TEXT porque con 6+ tasas el desglose JSON excedía el
> límite y abortaba la venta entera (22001).
> Gap heredado de F3b (no nuevo, pero ahora afecta 4 bloques más): la
> reimpresión desde el panel (`buildTicketDataFromTxDetail`) no tiene
> `taxId`/`taxRate`/`taxKind` por línea (el endpoint de reportes no los
> expone), así que ahí los bloques por-tasa imprimen en blanco — el total
> `tax_total` sigue siendo correcto (usa `transactionTax`), solo el desglose
> por tasa queda vacío en ese camino. Ampliar el endpoint es trabajo de
> backend, fuera de F3;
> sin migración de datos. Sigue F4 (rollup) y F5 (RG90/Libro Ventas).
>
> Progreso 2026-08-15: **F5 hecha (RG90 + Libro Ventas), SIN esperar F4.**
> F4 (`rollup_tax`) es agregado diario — RG90 necesita filas por
> COMPROBANTE, no por día, así que el export lee directo del desglose
> congelado por venta (`toTaxObj`/`meta.transactionDetails`), nunca de un
> rollup. La dependencia F4→F5 de la tabla de Fases de abajo no aplicaba en
> la práctica; se corrige acá. F4 (rollup_tax + Summary/reportes por tasa)
> sigue pendiente, desacoplado de F5.
>
> Qué se construyó: `Tax\TaxBreakdownResolver` (nuevo, compartido) extrae la
> regla "primario `toTaxObj`, fallback a `meta.transactionDetails` si falta o
> no parsea" que antes vivía privada en `Transactions\TransactionDetailService`
> — F5 la necesitaba en BATCH para un rango de fechas, así que se sacó al
> wrapper compartido en vez de duplicarla (`resolveMany()`); el detalle de
> transacción (F1, context/39) se refactorizó para delegar en el mismo
> resolver, sin cambios de comportamiento. `Reports\FiscalService::rg90()` /
> `::libroVentas()` arman las filas; endpoint `GET /v1/reports/fiscal?dataset=
> rg90|libro-ventas&from=&to=`, gateado `COUNTRY==='PY'` (403 para otros
> países). Frontend: botón "Exportar fiscal" en `/reports/transactions`
> (visible solo panel + tenant PY), XLSX vía `exportRowsToXlsx` — se extrajo
> el core de `<DataTable>`'s `exportToXlsx` (antes atado al row-model de
> TanStack) porque RG90 es un layout FIJO de 20 columnas con datos que no
> viven en ninguna tabla en pantalla.
>
> Decisiones tomadas en F5 (no relitigar):
> - **Montos "gravado X%"**: el layout SET de RG90 los define CON IVA
>   incluido (`base+amount` del bucket, no la base neta) — así cierran
>   `gravado10+gravado5+exento == total del comprobante`. Verificado con el
>   caso `py-multi-rate` del arnés (`verify_chain`): cierra exacto
>   (58600+14700+14000=87300). El legacy hacía lo mismo (usaba su bucket
>   `'total'` — base+tax — para esa columna); no era el bug, aunque el
>   nombre de columna sea confuso.
> - **`kind=exempt` y `kind=rate,rate=0`**: fiscalmente distintos (D2), pero
>   el layout RG90 solo tiene 3 columnas de monto (10%/5%/exento) — sin una
>   4ª para "gravado a otra tasa". Ambos casos, y cualquier tasa custom
>   ≠10/5 de un tenant multi-país, caen en "MONTO NO GRAVADO O EXENTO" por
>   falta de columna en el formato fijo del SET — limitación del layout, no
>   del dato (que sigue distinguible en Libro Ventas / `toTaxObj`).
> - **Histórico pre-F2a (D3)**: sin desglose congelado reconstruible → la
>   venta se EXCLUYE del export (no se inventa desglose) y se cuenta en
>   `meta.excludedCount` de la respuesta; el front avisa con un toast. Filas
>   con `toTaxObjText` truncado/ausente pero `meta.transactionDetails`
>   congelado (F2a sí corrió) degradan al fallback y se cuentan en
>   `fallbackCount` (informativo, no bloquea).
> - **Anuladas y notas de crédito**: NO incluidas. `transactionType IN
>   (0,3)` únicamente — igual que el WHERE real del legacy (su propio código
>   para NC/anuladas, `transType=110` y las columnas de "comprobante
>   asociado", era código MUERTO: su query nunca traía type=6/7). Hoy
>   tampoco existen: context/40 (anulación y NC) está "sin implementar
>   todavía". Cuando F1/F4 de context/40 aterricen, este Service necesita
>   excluir `voidedAt IS NOT NULL` del total vendido e incluir las NC como
>   filas propias.
> - **`ivaRemoved` (mig 101)**: sin caso especial — F2a ya fuerza
>   taxRate=0/kind=exempt por línea en `enrichWithTaxes` cuando el toggle
>   está activo, así que el desglose congelado YA sale correcto. A
>   diferencia de `TransactionsService::detail()` (que sigue re-chequeando
>   el flag por compat con reportes viejos), `FiscalService` no lo necesita.
> - **Identificación del comprador**: se usa `contact.contactIdType` (Tabla
>   3 SET, mig 125) + `contactTIN`/`contactCI` — NO el parseo legacy
>   ("`strpos(ruc,'-')` → RUC, si no → CI") que ese código hacía contra
>   `contactTIN`. Es la fuente correcta y ya existe (`ContactService::
>   inferIdType`), el legacy es anterior a esa migración.
> - **Libro compras**: fuera de alcance (brief F5) — el legacy lo tenía
>   comentado; otra fuente de datos (`purchase`), documento separado.
>   Pendiente, sin planificar.
> - **No replicado del legacy, a propósito**: el bug de Libro Ventas donde
>   `grav5`/`tax5` (base/IVA de la columna 5%) estaban CRUZADOS en el código
>   fuente (asignaba `$totalTaxes['tax']['5']` a la variable `grav5` y
>   viceversa) — `FiscalService` calcula base5/tax5 directo del bucket
>   correcto, sin ese bug. También se colapsó la columna redundante
>   `EXENTA`/`EXENTO` del legacy (dos columnas con el mismo valor, porque
>   exento no tiene IVA — base==gross) en una sola `EXENTA`.
> - **Moneda extranjera / imputa IVA-IRE-IRP**: se mantuvieron los mismos
>   valores fijos del legacy (`N` / `S,N,N,N`) — el sistema no modela venta
>   en moneda extranjera ni el régimen fiscal del comprador, no hay dato real
>   que usar en su lugar. Documentado como limitación conocida, igual que en
>   el legacy.

## Diagnóstico (auditoría 2026-08-07)

El modelo de datos correcto YA existe; nadie lo consume. La matemática vive
hardcodeada a Paraguay-10% en el carrito, y el dato fiscal que llega a la BD
es `tax: 0` en toda venta.

**Cadena rota, de punta a punta:**

1. `PosItem` llega al POS con `taxId` y `taxIncluded` reales
   (`app/api/pos/bootstrap/route.ts:294`) — **cero referencias** en
   `lib/cart/`, `lib/commands/`, `components/register/`.
2. El carrito liquida TODO con `TAX_RATE = 0.10` fijo
   (`lib/cart/allocate-discounts.ts:29`, `lib/cart/store.ts:250`): una venta
   5% + 10% liquida todo al 10% — mal incluso dentro de PY.
3. El payload manda `tax: 0` siempre (`create-sale.ts:364`, `create-quote.ts`,
   `pay-dialog.tsx:891`).
4. `SaleService` persiste `transactionTax` y `toTaxObj` **tal cual del
   payload** (líneas 571/649) — sin validar, mientras `taxRate` por línea sí
   se resuelve server-side (`withTaxRates`, 2026-08-07). Asimetría de
   confianza; `itemSoldTax` también viene del cliente.
5. TODOS los reportes suman `transactionTax`/`itemSoldTax` → **IVA $0 en
   producción**: Summary, series, byDay, Brands/Categories/Products/
   SummaryYear, rollups (migs 41/42), ticket (`TicketData.taxTotal`).
6. Los bloques de impuesto del ticket resuelven `null`
   (`blocks.ts:270-274`).
7. EInvoice es el ÚNICO que calcula bien, pero: resuelve la tasa del catálogo
   AL FACTURAR (no congelada — si la tasa cambió, el DE difiere de la venta),
   hardcodea `{10,5,0}` con default 10 (`SaleToInvoiceMapper.php:253`), y no
   persiste el resultado para reportes.
8. RG90 / Libro Ventas / libro compras: **no existen en ningún lado** — el
   docblock dice "siguen en panel legacy" pero `panel/` ya no está en el repo.

**Fuente de impuestos duplicada:** mig 23 sacó `tax` de `taxonomy` (mismo
UUID) sin retirar la vieja. Signup siembra en `taxonomy`; Ajustes→Catálogo,
Compras y Sucursales usan `/v1/taxes` (tabla `tax`); el editor de Ítems y
bulk-edit siguen en `/v1/taxonomies` (legacy). `TaxService` solo lee `tax` →
una empresa recién creada no puede listar/editar su IVA. Dos UIs de
"Impuestos" en Ajustes (General edita solo el label `taxName`; Catálogo las
tasas reales).

**Lo único ya congelado:** `withTaxRates` (SaleService) persiste `taxRate`
por línea en el detalle, COALESCE sobre ambas tablas, server-side
(commits 81d5d66d + b20bd721).

**La implementación más correcta del sistema** está aislada en Compras:
`purchase-form-fields.tsx` usa `/v1/taxes` y `sub*rate/(100+rate)` por línea.
Pero el backend de compras confía en el `taxValue` del payload.

## Reglas LATAM que el motor debe satisfacer

- **Tasas por país, N por comercio, NUNCA hardcodeadas**: PY 10/5/exentas ·
  AR 21/10.5/27 · UY 22/10 · CL 19 · MX 16/0/exento · CO 19/5/excluido ·
  PE 18. El comercio crea sus tasas en Ajustes; todo deriva de ahí.
- **Incluido vs añadido**: default por sucursal (`outlet.itemsTaxIncluded`,
  ya existe y se edita, hoy dato muerto) + override por ítem
  (`item.itemTaxIncluded`, ya llega al POS). Incluido: neto = precio ×
  tasa/(100+tasa). Añadido: impuesto = precio × tasa/100, se suma al total.
- **Exento ≠ tasa 0%** (MX/CO lo distinguen fiscalmente) → el impuesto lleva
  `kind`: `rate` | `exempt`.
- **Redondeo**: se fija UNA regla (ver D1); los formatos fiscales exigen que
  la suma de líneas cierre con el total.
- **Documento congelado**: tasa, modo (incluido/añadido) y monto se
  persisten POR LÍNEA al emitir. Reimpresión y DE leen lo congelado, nunca
  el catálogo.
- **No bloquear (fuera de alcance v1)**: percepciones/retenciones (AR),
  impuestos internos, ISC. El modelo por-línea multi-componente los admite
  después (una línea puede tener >1 componente de impuesto en el futuro —
  por eso el desglose persiste como lista, no como campos fijos).

## Arquitectura propuesta

### A. Fuente única de impuestos (tabla `tax`)

- Backfill `taxonomy(type='tax')` → `tax` (mismo UUID, INSERT de faltantes).
- Signup siembra en `tax`. `TaxService` deja de ignorar lo viejo (post
  backfill ya no hace falta fallback).
- Editor de Ítems y bulk-edit migran a `/v1/taxes`.
- Columnas nuevas: `rate DECIMAL(5,2)` (parseada una vez desde `name`, que
  queda como label) + `kind` (`rate`|`exempt`). Mig 23 ya lo dejaba
  planteado.
- La sección "Impuestos" de Ajustes→General se reduce al label del impuesto
  (`taxName` = "IVA"/"VAT"/"Impuesto"); las tasas viven solo en Catálogo.

### B. Motor de cálculo único

`taxEngine(lines, mode) → {porLínea, porTasa, totales}` con línea =
`{qty, unitPrice, taxRate, taxKind, taxIncluded, discount}`.

- **TS** (`frontend/lib/tax/engine.ts`): carrito, ticket, plantillas.
- **PHP** (`api/lib/Tax/TaxEngine.php`): espejo EXACTO — mismos casos
  borde, misma regla de redondeo. El backend es el autoritativo: recalcula
  en la venta y persiste SU resultado; el payload del cliente es
  informativo. (Cierra la vulnerabilidad de `transactionTax`/`toTaxObj`
  sin validar — hallazgo #1 del backend.)
- `ivaRemoved` se integra al motor (tasa efectiva 0 por línea, neto según
  modo), deja de ser un caso especial en reportes.
- Suite de casos compartida (JSON de fixtures que corren contra ambos
  motores) para que no diverjan.

### C. Persistencia por línea (venta y compra)

- Detalle de venta: cada línea lleva `taxId`, `taxRate`, `taxKind`,
  `taxIncluded`, `taxAmount` (todo server-side; `withTaxRates` se absorbe
  en el motor).
- `itemSold.itemSoldTax` = monto calculado por el motor (hoy: cliente).
- `transaction.transactionTax` = suma; `toTaxObj` = desglose por tasa
  generado por el motor (shape lista: `[{taxId, rate, kind, base, amount}]`).
- Compras: mismo motor server-side sobre las líneas; deja de confiar en
  `taxValue` del payload.

### D. Consumidores

- **POS**: IVA por línea y total salen del motor TS. Muere `TAX_RATE`.
- **EInvoice**: lee el desglose congelado de la venta; fallback a
  `resolveTaxRatesForItems` solo para ventas anteriores al cambio. Muere el
  hardcode `{10,5,0}` — valida contra las tasas del comercio.
- **Ticket/plantillas**: bloques parametrizados por tasa —
  `item_total_by_rate`, `subtotal_by_rate`, `iva_by_rate` (el bloque guarda
  `taxId`; la paleta genera una entrada por impuesto del comercio) +
  `iva_total`. Destraba los 9 bloques que hoy dan `null`. Los legacy
  `item_taxAmount`/`item_taxAmount_single` se renombran a snake_case con
  alias de lectura (las plantillas guardadas persisten el string).
- **Reportes**: `totals.tax` vuelve a ser real sin tocar los reportes (leen
  las mismas columnas, ahora pobladas).

### E. Rollup y reportes fiscales

- Tabla nueva `rollup_tax(companyId, outletId, day, taxId, rate, kind,
  base, amount)` — dimensión por tasa; la columna `tax` única de migs 41/42
  queda para compat. **Pendiente (F4 no implementada)** — sigue siendo
  correcta para Summary/reportes agregados por día, que sí toleran esa
  granularidad.
- RG90/Libro Ventas (F5, hecha 2026-08-15) NO usan `rollup_tax`: el formato
  exige una fila por COMPROBANTE con su desglose por tasa, no un agregado
  diario — leen directo `toTaxObj`/`meta.transactionDetails` por venta
  (`Tax\TaxBreakdownResolver`, batch por rango de fechas). Libro compras
  queda pendiente, sin planificar (otra fuente de datos, `purchase`).

## Fases

| Fase | Contenido | Depende de |
|---|---|---|
| **F0** | Fuente única: backfill taxonomy→tax, signup a `tax`, rate/kind, Ítems+bulk-edit a `/v1/taxes` | — |
| **F1** | Motor TS + PHP espejo + fixtures compartidos | F0 (rate numérico) |
| **F2** | Venta/compra persisten por línea server-side; POS usa el motor; muere TAX_RATE; muere el payload confiado | F1 |
| **F3** | EInvoice congelado; bloques de plantilla por tasa; renombre snake_case | F2 |
| **F4** | rollup_tax + Summary/reportes por tasa | F2 |
| **F5** ✅ | RG90 · Libro Ventas (exports). Libro compras quedó FUERA — pendiente sin planificar. **No dependía de F4 en la práctica** (RG90 es por comprobante, no por día — lee `toTaxObj` directo, ver progreso 2026-08-15 arriba) | F2 |

F2 es el corte de deploy delicado: cambia qué persiste cada venta. Ventas
viejas quedan con tax=0 — ver D3.

## Decisiones del owner

- **D1 — Redondeo.** Propuesta: redondear POR LÍNEA al decimal del tenant
  (`config.decimal`: PY 0 decimales, MX 2) y totales = suma de líneas. Es lo
  que exigen RG90/SIFEN. ¿OK?
- **D2 — Exento vs 0%.** Propuesta: `kind` explícito y el seed de PY crea
  "Exentas" como `exempt`. ¿OK?
- **D3 — Histórico.** Ventas previas tienen tax=0. Opciones: (a) dejarlas
  (los reportes fiscales arrancan desde el deploy), (b) backfill derivando
  del catálogo actual marcado `derived=true` (aproximado — la tasa pudo
  cambiar). Propuesta: (a); (b) solo si un tenant lo pide.
- **D4 — Renombre de bloques legacy (cerrada, implementada F3c).**
  `item_taxAmount` → `item_tax_amount` (ídem `_single`) con alias al leer
  plantillas guardadas, sin migración de datos. El alias vive en
  `normalizeBlockType` (frontend/lib/hardware/printers/blocks.ts), aplicado
  una sola vez en `sortBlocksForRender` — punto de entrada único de ambos
  renderers (ESC/POS y HTML) — para no duplicar el mapa. El backend
  (`DocumentTemplateService::present()`) no interpreta `block.type`, solo
  pasa el JSON `config` opaco, así que no necesita el alias.
- **D5 — IVA en canje de voucher (cerrada 2026-08-08).** El canje va EXENTO:
  el vale se vende en caja como ítem normal y su IVA se devenga íntegro en la
  venta de emisión; la línea de canje no aporta al `transactionTotal`
  (context/36 decisión 5), así que computarle IVA duplicaba el devengo en
  `transactionTax` sin respaldo en el total. Implementado: `enrichWithTaxes`
  fuerza `exempt` si la línea trae `voucher` (taxId informativo se preserva),
  `groupTaxByRate` la salta (su neto no entra como base exenta del Libro
  Ventas), y `selectCartIva` (front) la trata igual. Alternativa descartada:
  devengar al canje exigía emitir el vale exento y contradecía context/36
  decisión 4.

## Notas

- La fórmula de Compras (`purchase-form-fields.tsx`) es la semilla del motor
  TS — ya es correcta para incluido.
- `outlet.itemsTaxIncluded` hoy es dato muerto: el form lo guarda y nadie lo
  lee. Pasa a ser el default del motor (el ítem puede overridear).
- Los re-zero de `ivaRemoved` en TransactionsService/ProductsService se
  retiran cuando el motor integre el flag.
- context/28 §"La tasa sale del ítem" queda superseded por este plan en lo
  que toca a persistencia (la tasa ahora SÍ se congela por línea).
