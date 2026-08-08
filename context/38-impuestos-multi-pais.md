# 38 — Impuestos multi-tasa / multi-país

> Estado: **plan CERRADO, en ejecución** (2026-08-08). Basado en auditoría de
> 3 agentes (backend, frontend, reportes/fiscal). D1–D4 cerradas por el owner
> 2026-08-08: D1 redondeo por línea al decimal del tenant · D2 kind
> rate/exempt · D3 histórico queda como está (son demos, sin backfill) ·
> D4 renombre con alias sin migrar plantillas.
> Requerimiento: el sistema apunta a toda LATAM — tasas NO fijas, con opción
> global de IVA incluido en el precio o sumado al precio.
> Progreso 2026-08-08: **F0, F1, F2a, F2b, F3a, F3b hechas**. F3a: EInvoice
> (factura y nota de crédito) lee el IVA congelado por línea, no el catálogo;
> fallback a `resolveTaxRatesForItems` solo para ventas pre-F2a; hardcode
> `{10,5,0}` pasó a ser validación de formato SIFEN, no fuente de la tasa.
> F3b: `TicketItem` (build-ticket-data.ts) lleva id/uid/nota/tasa/monto de
> impuesto por línea — congelado real (F2a) en las reimpresiones desde
> transacción, motor real (lib/tax/engine.ts) en la impresión inmediata del
> POS. Los 9 bloques de `blocks.ts` que daban `null` quedan resueltos, salvo
> `item_tags` (no modelado por línea) y `tax_single` (agregado por-tasa a
> nivel venta, requiere la infraestructura de F3c). Sigue el resto de F3
> (plantillas por tasa — bloques `item_total_by_rate` etc., renombre
> snake_case), después F4 (rollup) y F5 (RG90/Libro Ventas).

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
  queda para compat.
- Con eso: RG90, Libro Ventas y libro compras son SELECTs formateados
  (export XLSX vía DataTable). Son fase propia.

## Fases

| Fase | Contenido | Depende de |
|---|---|---|
| **F0** | Fuente única: backfill taxonomy→tax, signup a `tax`, rate/kind, Ítems+bulk-edit a `/v1/taxes` | — |
| **F1** | Motor TS + PHP espejo + fixtures compartidos | F0 (rate numérico) |
| **F2** | Venta/compra persisten por línea server-side; POS usa el motor; muere TAX_RATE; muere el payload confiado | F1 |
| **F3** | EInvoice congelado; bloques de plantilla por tasa; renombre snake_case | F2 |
| **F4** | rollup_tax + Summary/reportes por tasa | F2 |
| **F5** | RG90 · Libro Ventas · libro compras (exports) | F4 |

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
- **D4 — Renombre de bloques legacy.** `item_taxAmount` →
  `item_tax_amount` con alias al leer plantillas guardadas, sin migración
  de datos. ¿OK?

## Notas

- La fórmula de Compras (`purchase-form-fields.tsx`) es la semilla del motor
  TS — ya es correcta para incluido.
- `outlet.itemsTaxIncluded` hoy es dato muerto: el form lo guarda y nadie lo
  lee. Pasa a ser el default del motor (el ítem puede overridear).
- Los re-zero de `ivaRemoved` en TransactionsService/ProductsService se
  retiran cuando el motor integre el flag.
- context/28 §"La tasa sale del ítem" queda superseded por este plan en lo
  que toca a persistencia (la tasa ahora SÍ se congela por línea).
