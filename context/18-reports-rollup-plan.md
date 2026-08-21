# Plan: Tablas de Rollup para Reportes (pre-agregación)

**Estado:** En ejecución (iniciado 2026-06-21).
**Objetivo:** consultar rangos amplios (ej. ventas por año × 5 años, productos vendidos × 12 meses) en O(períodos) en vez de O(filas), pre-agregando las tablas que crecen sin techo en buckets día/mes/año.

## Problema concreto

`SummaryYearService::yearly()` hace N+1: por cada mes del año consulta por separado `expensesTotal`, `returnsTotal`, `nonAddingTotal`, `newCustomers` — cada uno un full scan parcial de `transaction`/`expenses`/`contact`. 5 años = ~240 queries. Con millones de transacciones esto se vuelve inviable. Mismo patrón en products, categories, brands, users, stock, production.

## Modelo mental

> **History desde rollup, "hoy" (período abierto) computado live.**

- Períodos **cerrados** (estrictamente anteriores a hoy) se leen del rollup — inmutables salvo edición back-dated.
- El **día actual** se computa live sobre la tabla fact (rango chico → rápido igual).
- Un job pg_cron nocturno **finaliza** el día anterior y propaga a mes/año.
- Ediciones/anulaciones/devoluciones back-dated marcan el período **sucio** → el job recalcula ESE período (día → mes → año).

Esto sortea el problema de freshness para el caso común y mantiene corrección ante mutaciones.

## Decisiones (cerradas con el owner)

1. **Híbrido** incremental (dirty-marking en write-path, barato) + reconcile pg_cron (recompute de períodos sucios + finalización nocturna).
2. **Multi-dominio**: no solo ventas. Todas las tablas fact high-volume con campos contabilizables.
3. **Grain** por dominio: `(companyId, outletId, periodType, periodStart, [entityDim])`, `periodType ∈ {day, month, year}`.
4. **Marcar el día sucio basta**: el reconcile re-agrega día → mes → año del período afectado.

## Dominios fact y métricas (del análisis de schema)

| Domain | Tabla fuente | Filtro | Grain extra | Métricas |
|---|---|---|---|---|
| `sales` | `transaction` | type IN (0,3) | — | count, total, tax, discount, unitsSold, cogs |
| `purchases` | `transaction` | type=4 | — | count, total, tax |
| `returns` | `transaction` | type=6 | — | count, total |
| `expenses` | `expenses` | — | type (1=extracción/2=ingreso) | count, amount |
| `orders` | `transaction` | type=12 | status | count, total |
| `item_sales` | `itemSold` | (vía JOIN transaction type 0,3) | itemId | units, total, tax, discount, cogs, comission |
| `stock_moves` | `stock` | — | itemId | moveCount, qtyDelta, cogsDelta |
| `production` | `production` | — | itemId | count, cogs, wasteValue |
| `payments` | `transaction.transactionPaymentType` JSONB | type 0,3,5 | paymentType | count, total |
| `commissions` | `comission` | — | userId | count, total |
| `vpayments` | `vPayments` | — | — | count, amount, payoutAmount, comission, tax |

> El **Slice 1 cubre solo `sales` + `expenses` + `returns`** (lo que alimenta `SummaryYearService`, el reporte más lento). El resto se agrega en slices siguientes con el mismo patrón — la infraestructura (dirty queue, reconcile job, helper de upsert) es compartida.

## Arquitectura

### Tablas

**`report_rollup`** — una tabla genérica por-dominio con métricas en columnas fijas comunes + JSONB para métricas específicas. Decisión: columnas fijas para las métricas universales (count, total, tax, discount, qty, cogs) + `extra JSONB` para lo raro. Evita explosión de tablas y mantiene queries simples.

```sql
CREATE TABLE report_rollup (
  companyId    UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId     UUID,                       -- NULL = consolidado todas las sucursales
  domain       TEXT         NOT NULL,      -- 'sales' | 'expenses' | 'returns' | ...
  periodType   TEXT         NOT NULL,      -- 'day' | 'month' | 'year'
  periodStart  DATE         NOT NULL,      -- 2026-06-01 para mes, 2026-01-01 para año, 2026-06-21 para día
  entityId     UUID,                       -- itemId/userId/etc según domain; NULL si no aplica
  entityKind   TEXT,                       -- 'item' | 'user' | 'paymentType' | NULL
  cnt          BIGINT       NOT NULL DEFAULT 0,
  total        NUMERIC(18,4) NOT NULL DEFAULT 0,
  tax          NUMERIC(18,4) NOT NULL DEFAULT 0,
  discount     NUMERIC(18,4) NOT NULL DEFAULT 0,
  qty          NUMERIC(18,4) NOT NULL DEFAULT 0,
  cogs         NUMERIC(18,4) NOT NULL DEFAULT 0,
  extra        JSONB        NOT NULL DEFAULT '{}',
  updatedAt    TIMESTAMPTZ  NOT NULL DEFAULT now(),
  PRIMARY KEY (companyId, domain, periodType, periodStart, COALESCE(outletId, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(entityId, '00000000-0000-0000-0000-000000000000'::uuid))
);
CREATE INDEX idx_rollup_lookup ON report_rollup (companyId, domain, periodType, periodStart);
CREATE INDEX idx_rollup_entity ON report_rollup (companyId, domain, entityKind, entityId) WHERE entityId IS NOT NULL;
```

> Nota PG: una PK con `COALESCE(...)` no es válida directamente. Se resuelve con columnas generadas `outletKey`/`entityKey` (`GENERATED ALWAYS AS (COALESCE(outletId, '0000...')) STORED`) en la PK, o con un UNIQUE INDEX sobre las expresiones COALESCE. El subagente usa el UNIQUE INDEX sobre expresiones (más simple) + un `id UUID PRIMARY KEY DEFAULT gen_random_uuid()` surrogate.

**`rollup_dirty`** — cola de períodos a recalcular.

```sql
CREATE TABLE rollup_dirty (
  companyId   UUID NOT NULL,
  domain      TEXT NOT NULL,
  periodDay   DATE NOT NULL,       -- siempre el DÍA; el reconcile propaga a mes/año
  enqueuedAt  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (companyId, domain, periodDay)
);
```

### Write-path (incremental, barato)

`app/includes/rollup.php` (helper global, igual estilo que `realtime.php`):

```php
function rollupMarkDirty(string $companyId, array $domains, string $dateYmd): void
{
    // INSERT ON CONFLICT DO NOTHING para cada domain. Best-effort, nunca lanza.
    // Se llama después de cada mutación de una tabla fact con el día afectado.
}
```

Hooks (todos best-effort, después del commit):
- `SaleService::save()` después de `CompleteTrans()` → `rollupMarkDirty(co, ['sales','item_sales','payments'], $input->date)`. Si es type 6 → `returns`. Si type 4 → `purchases`.
- Edición de venta (`transactions.php` PUT) → marcar el día de la transacción editada.
- `expenses.php` (extracción/ingreso) → `['expenses']`.
- etc. (slices siguientes).

### Reconcile (pg_cron + endpoint manual)

`database/jobs/rollup_reconcile.sql` — función PG `rollup_reconcile()`:
1. Toma hasta N períodos de `rollup_dirty`.
2. Para cada `(companyId, domain, periodDay)`: recomputa el bucket `day` desde la tabla fact (DELETE + INSERT agregado), luego recomputa `month` y `year` que contienen ese día (re-agregando desde los buckets `day` del mes/año, no desde la fact — más barato).
3. Borra la fila de `rollup_dirty`.

Más una **finalización nocturna**: marcar como dirty el día de ayer de todos los dominios activos por si quedó algo sin marcar (red de seguridad).

pg_cron: `SELECT cron.schedule('rollup-reconcile', '*/5 * * * *', 'SELECT rollup_reconcile(500)');` (cada 5 min, hasta 500 períodos por corrida). Más `'rollup-nightly'` a las 03:00.

> **Estado real (2026-08-21)**: `pg_cron` NO está instalado en la imagen
> `postgres:18-alpine` de producción y quedó descartado como requisito
> (decisión del owner). El disparo real es `POST /v1/maintenance?job=rollup-reconcile`
> (no `/v1/admin/rollup/reconcile` — un único endpoint de mantenimiento cubre
> este job + los de purga + el drainer de FE), invocado cada 10 min por
> `crond` de BusyBox DENTRO de la imagen del API (`api/docker/cron/`, ver
> `context/06-infraestructura.md` § Jobs de mantenimiento). `p_max` default
> 500, tope 5000 vía query param `limit`. Antes de este cron, `report_rollup`
> tenía 0 filas en prod con 134 períodos acumulados en `rollup_dirty` — nadie
> lo estaba disparando.

### Read-path

`api/lib/Reports/RollupReader.php`: helper que dado `(companyId, domain, periodType, from, to, outletId?)` devuelve los buckets del rollup + mergea el día de hoy live si el rango incluye hoy.

`SummaryYearService::yearly()` se reescribe para leer de `RollupReader` (months desde `report_rollup` domain sales/expenses/returns, periodType=month). El cómputo de `newCustomers` y `nonAddingTotal` se evalúa: si son baratos se dejan live, si no se agregan como dominios.

## Slices

| # | Slice | Resultado |
|---|---|---|
| RB-1 | **Infra + sales/expenses/returns** — mig tablas, helper dirty, reconcile fn, hooks en SaleService/expenses, RollupReader, cutover de SummaryYearService | El reporte más lento usa rollup; infra reusable lista |
| RB-2 | **item_sales + payments** — cutover products/categories/brands/payment-methods reports | Reportes de producto rápidos |
| RB-3 | **stock_moves + production + commissions + vpayments** | Resto de dominios |
| RB-4 | **pg_cron wiring + admin endpoint + backfill** — schedule jobs, endpoint manual, script de backfill histórico | Operación autónoma |
| RB-5 | **Doc vivo** + cronología | Trazabilidad |

### Detalle RB-1 (el que ejecuta Sonnet ahora)

**Migración** `database/migrations/postgres/41_report_rollup.sql`:
- `report_rollup` (con surrogate id + unique index sobre expresiones COALESCE)
- `rollup_dirty`
- función `rollup_recompute_period(companyId, domain, periodDay)` — recomputa day+month+year de un dominio para ese día
- función `rollup_reconcile(maxN int)` — drena la cola
- **Backfill inline al final de la mig**: poblar `report_rollup` para sales/expenses/returns desde toda la historia existente (un INSERT...SELECT con GROUP BY date_trunc por day/month/year). Esto hace el reporte rápido desde el primer deploy sin esperar al job.

**PHP**:
- `app/includes/rollup.php` con `rollupMarkDirty()`
- `api/bootstrap.php`: require del helper
- `SaleService::save()`: hook después de CompleteTrans (sales/returns/purchases según type)
- `api/v1/expenses.php` o su Service: hook
- `api/v1/transactions.php` PUT (edición): hook con el día de la tx
- `api/lib/Reports/RollupReader.php`: nuevo
- `api/lib/Reports/SummaryYearService.php`: cutover de `yearly()` a leer del rollup

**Verificación**: el reporte `/v1/reports/summary-year` debe devolver los mismos números que antes (comparar contra el cómputo live en un rango conocido). Agregar un parámetro temporal `?verify=1` que corra ambos y loguee diferencias, o un script de verificación.

## Riesgos

- **Drift por hooks faltantes**: si una mutación de fact no marca dirty, ese período queda stale hasta la finalización nocturna (que marca ayer) o un reconcile full. Mitigación: el nightly puede hacer un "full recompute" de los últimos 7 días sin importar dirty, como red ancha.
- **Backfill pesado en tenants grandes**: el INSERT...SELECT de toda la historia puede tardar. Mitigación: correr por chunks de año, o como job aparte post-deploy en vez de inline en la mig.
- **Doble verdad**: rollup vs live pueden divergir si la lógica de agregación del rollup no matchea EXACTO la del Service viejo (ej. qué transactionType cuenta, exclusiones). Mitigación: el cutover incluye verificación numérica antes de confiar.
- **PG COALESCE en PK**: usar surrogate id + unique index sobre expresiones, no PK compuesta con COALESCE.

## Procedimiento de cutover (seguridad financiera)

El rollup NO se confía por defecto. `SummaryYearService::yearly()` delega a
`yearlyLive()` salvo que `$_ENV['REPORTS_ROLLUP_ENABLED']` esté activo. Flujo:

1. Deploy de la mig 41 (crea tablas + backfill histórico) + el código.
2. Correr `GET /v1/reports/summary_year?y=<AÑO>&verify=1` con un tenant de
   datos reales. Devuelve `{ rollup, live, diff }`. **`diff` debe estar vacío.**
3. Repetir para varios años / sucursales (incluida "todas").
4. Si `diff` vacío en todos → setear `REPORTS_ROLLUP_ENABLED=1` en Coolify
   (servicio Punto Sys) → el reporte pasa a leer del rollup (rápido).
5. Si `diff` tiene entradas → NO activar; investigar la divergencia (el campo
   y el delta apuntan exactamente dónde). El reporte sigue sirviendo live.

Drift conocido a chequear en el diff: `yearly()` puede listar meses con solo
gastos/devoluciones (sin ventas) que `yearlyLive()` omitía. Es comportamiento
más correcto pero hay que confirmar que no rompe el consumidor del front.

## Cronología de commits

- `bcbb68f` + `26a2f6c` — RB-1: mig 41 (report_rollup, rollup_dirty,
  rollup_recompute_period, rollup_reconcile, backfill inline), rollupMarkDirty
  hookeado en SaleService/DrawerService/transactions PUT, RollupReader, cutover
  de SummaryYearService con `?verify=1`.
- `b8...` (gate RB-1) — `REPORTS_ROLLUP_ENABLED`: `yearly()` default a live
  hasta verificar; `?verify=1` fuerza el rollup real para comparar.
- `e61d3b0` — RB-2: mig 42 extiende `rollup_recompute_period` con dominios
  `item_sales` (tipos 0,3 por item, métricas weighted en cols fijas + comission/
  cogsAbsFlat/discountFlat en extra), `item_returns` (tipo 6, solo poblado),
  `payments` (resumen por método desde `transactionPaymentType` JSONB, entityId=
  md5(type)::uuid). Backfill inline. SaleService marca los nuevos dominios dirty.
  `RollupReader::itemSalesRange/paymentsRange`. Cutover de Categories/Brands/
  PaymentMethods(summary) — todos gated + `?verify=1`. **products general queda
  live** (RB-2b: su semántica ABS-flat + tipos 0,3,6 + prev-period necesita
  verify propio). El `detail` de payment-methods queda SIEMPRE live (es detalle
  transaccional, no rollupeable).

### Estado de cutover por reporte

| Reporte | Domain rollup | Estado |
|---|---|---|
| summary_year | sales/expenses/returns | gated + verify (RB-1) |
| categories | item_sales | gated + verify (RB-2) |
| brands | item_sales | gated + verify (RB-2) |
| payment-methods (summary) | payments | gated + verify (RB-2) |
| products (general) | item_sales/item_returns | **pendiente RB-2b** (dominios ya poblados) |
| stock/production/commissions/vpayments | — | **pendiente RB-3** |

**Activación**: tras deploy, correr `?verify=1` en cada reporte con datos reales;
si `diff` vacío en todos → setear `REPORTS_ROLLUP_ENABLED=1` (un solo flag activa
todos los cutover a la vez).
