# verify_chain — arnés end-to-end del proceso de venta

Verifica la cadena completa, con datos persistidos y sin mocks:

```
venta multi-tasa (SaleService real)
  → BD (itemSold / transaction / toTaxObj)
  → facturación electrónica (EInvoiceService + SaleToInvoiceMapper, sin red)
  → impresión (resolvers reales de frontend/lib/hardware/printers/blocks.ts)
```

Mismo patrón que `api/lib/Tax/` (fixtures declarativos + runner, exit code
≠ 0 si algo falla): los casos viven en `fixtures.json` con los valores
esperados calculados **a mano**, no derivados del propio motor.

## Cómo correr

```bash
bash api/lib/Sales/verify_chain/run.sh
```

Un solo comando. Por defecto levanta su propio Postgres descartable en
Docker (schema base + migraciones + fixtures), corre la venta en dos
tenants (PY decimals=0, MX decimals=2), factura electrónica, y la
impresión — y destruye el Postgres al terminar.

Para apuntar a un Postgres ya migrado (más rápido en loops de desarrollo),
exportá antes `POSTGRES_HOST`/`POSTGRES_PORT`/`POSTGRES_DB`/
`POSTGRES_USER`/`POSTGRES_PASSWORD` — el script detecta que ya están
seteados y no toca Docker.

## Qué cubre

- Líneas con IVA incluido y añadido en la misma venta.
- 10%, 5%, 0% real (`kind=rate`) y exenta (`kind=exempt`) conviviendo.
- Precio modificado en la línea y descuento por línea.
- Cantidades decimales (regresión del P0 real de `transactionUnitsSold`).
- Un tenant `decimals=0` (PY) y otro `decimals=2` (MX-style).
- Que `itemSold`/`transaction`/`toTaxObj` persistan los números correctos y
  que Σ(IVA por tasa) == `transactionTax` == Σ(`itemSoldTax`).
- Que el payload de facturación electrónica use el IVA **congelado** de la
  venta (no el catálogo) y mapee las tasas correctas para SIFEN — y que una
  tasa que SIFEN no admite falle con un error claro, sin red.
- Que los bloques de impresión (`item_total_by_rate`, `subtotal_by_rate`,
  `iva_by_rate`, `iva_total`, `item_tax`, `item_tax_amount`,
  `item_price_notax`) resuelvan los números correctos sobre la venta real.
- F5 (context/38 §E): que `FiscalService::rg90()` genere, para el caso
  multi-tasa, una fila que CIERRE (`gravado10 + gravado5 + exento == total
  del comprobante`) y cuyos montos por tasa coincidan con el desglose
  `toTaxObj` ya verificado en el paso 3 (`verifyRg90()` en
  `run_sale_chain.php`).
- Aislamiento multi-tenant: una línea que referencia el `itemId` real de
  OTRO tenant no puede heredar su tasa/precio.

## Fallas conocidas (no se ocultan — ver reporte de la tarea)

El exit code queda en 1 mientras estos dos bugs de impresión sigan sin
arreglar (a propósito: el arnés reporta lo que encuentra, no lo esconde):

1. **`item_discount` imprime el % de descuento como si fuera dinero**
   (`frontend/lib/hardware/printers/blocks.ts:389`) — el campo que persiste
   la venta en esa key es el porcentaje efectivo de la línea
   (`frontend/lib/commands/create-sale.ts:331`), no el monto
   (`totalDiscount`).
2. **`formatMoney()` hardcodea `Intl.NumberFormat("es-PY", {currency:
   "PYG"})`** (`frontend/lib/hardware/printers/blocks.ts:32-34`) — para
   cualquier tenant `decimals=2` los centavos se pierden en el ticket
   impreso (ej. 114.84 → "Gs. 115").

## Estructura

- `seed.sql` — dos tenants aislados (companies/outlets/registers/items/tax),
  idempotente.
- `fixtures.json` — casos declarativos: líneas + valores esperados
  calculados a mano.
- `run_sale_chain.php` — corre `SaleService::save()` real, verifica BD +
  EInvoice, escribe un dump JSON por caso en `$TMPDIR/punto-verify-chain/`.
- `run.sh` — orquesta Postgres + ambos tenants + el paso de impresión.
- `../../../../frontend/lib/hardware/printers/verify_chain/` — paso de
  impresión en Node (runtime nativo de TS, sin tsx/ts-node): `run.mjs` lee
  los dumps y corre los resolvers reales; `alias-loader.mjs` resuelve el
  import `"@/…"` (Node no lo hace fuera de Next.js).
