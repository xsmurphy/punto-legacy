# 76 — Producción: KPIs de consumo, mermas y rendimiento (plan)

> Estado: PLAN pedido por el owner el 2026-09-10 ("la sección de producción es
> muy básica, no hay datos sobre consumos de materia prima, mermas, KPIs").
> **Decisiones D1-D5 PROPUESTAS, sin OK del owner.** Inventario verificado
> contra código el mismo día.

## 1. El diagnóstico en una línea

El reporte es básico porque **la base no registra lo que un módulo de
producción necesita medir**, no porque falte pantalla. Se puede mostrar mucho
más de lo que hay hoy con el dato existente (§4 F1), pero los KPIs que el owner
pidió —desvío de consumo, merma real de insumos— necesitan que primero se
empiece a GUARDAR otra cosa (§4 F0). Y eso vale solo hacia adelante: el
histórico no se puede reconstruir (§6).

Vocabulario GENÉRICO, igual que órdenes (regla del owner): producción la usa
una panadería, una carpintería o una fábrica de viandas. Nada de "cocina",
"plato", "food cost" — "producto terminado", "insumo", "costo sobre precio".

## 2. Qué hay hoy

- `production_order`: `qtyplanned`, `qtyproduced`, `qtywaste` (unidades
  falladas del terminado), `recipesnapshot` (JSONB), `ingredientcost`,
  `unitcogs`, `created_at`/`started_at`/`completed_at`, `userid` (solo el
  CREADOR), `batchid` (mig 194), estados `draft|in_progress|completed|cancelled`.
- `production_batch` (mig 194): agrupa órdenes; sin cantidades propias.
- `waste_event`: merma con `itemid`, `qty`, `reasonid` (obligatorio),
  `source` `production|manual`, `orderid` opcional, `cost`, `userid`.
- `item_compound`: receta (cantidad por unidad). La merma PLANIFICADA es
  atributo del insumo (`item.data->>'itemWaste'`) por decisión del owner
  2026-09-06 — no es un hueco.
- Ledger `stock`: el consumo sale como `source='production'` negativo y el
  ingreso del terminado como `source='production'` positivo.
- `/reports/production` muestra solo `view=general`: 3 KPIs (unidades, costo,
  utilidad) y una tabla. **El backend ya devuelve `detail`, `compound` y
  `waste`, y la pantalla no los usa.**
- No hay ningún rollup de producción ni de merma.

## 3. Lo que impide los KPIs pedidos (huecos de DATO)

| Hueco | Por qué bloquea | Dónde |
|---|---|---|
| **El consumo teórico se pierde cuando hay ajuste.** `recipesnapshot` guarda UN `qty` por insumo: si el operador corrige el real, el de receta desaparece. | No se puede medir desvío de consumo, que es el KPI central. | `ProductionService.php:560-567, 677-683` |
| **Los lotes nunca registran reales.** `ProductionBatchService::confirm()` completa cada línea con `qtyProduced = qtyplanned`, sin merma ni ajustes. | En producción por lote, real = teórico SIEMPRE: el KPI daría "cero desvío" y sería mentira. | `ProductionBatchService.php:407-410` |
| **La merma real de insumos no es un evento.** Si se carga, queda escondida como un `actualQty` mayor dentro del consumo. | "Cuánto se desperdició de harina" no existe como dato. | `ProductionService.php:560` |
| **El ledger no apunta a la orden.** `transactionId` es null; el único vínculo es el texto `'Producción #<uuid>'` en la nota. Y consumo e ingreso comparten `source`. | Atribuir consumo a una orden o lote obliga a parsear texto. | `ProductionService.php:648-708` |
| **Snapshot solo de nivel 1.** Una sub-preparación es una línea; lo que realmente se descontó abajo está solo en el ledger. | El consumo de materia prima "de verdad" (las hojas) no queda en la orden. | `:604-655` |
| **Responsable incompleto.** `userid` es quien creó; nadie registra quién inició, completó o canceló. La cancelación no guarda fecha ni motivo. | KPIs por responsable imposibles. | `:415, 774` |
| **Tiempos poco confiables.** "Producir ahora" crea y completa en el mismo request: `started_at` nulo, duración de milisegundos. | Duración y throughput serían basura sin declarar cobertura (mismo problema que `context/62`). | `:394-400` |

## 4. Fases

### F0 — Empezar a guardar el dato (bloquea F2)

1. `recipesnapshot` por insumo pasa a `{itemId, theoreticalQty, actualQty,
   plannedWastePct, unitCost, lineCost, tracked, adjusted}` — el teórico y el
   real SIEMPRE, y el % de merma planificada que se aplicó (si el `itemWaste`
   del insumo cambia después, el histórico no se reescribe). Snapshot de las
   HOJAS además del nivel 1.
2. Movimientos de stock de producción con referencia a la orden y al lote
   (columna, no texto), y `source` separado para consumo e ingreso.
3. Merma de insumo durante la producción como `waste_event` propio con
   `orderid`, en vez de esconderla en el `actualQty` (D3).
4. `completed_by`, `cancelled_by`, `cancelled_at`, motivo de cancelación.
5. Lotes: permitir cargar reales al confirmar (D2).

### F1 — Mostrar lo que ya existe (no depende de F0, se puede hacer ya)

`/reports/production` con tabs, reusando lo que el backend YA devuelve:
- **Dashboard**: unidades producidas, costo total, costo unitario promedio y
  su evolución, rendimiento `qtyproduced / qtyplanned` por producto, costo
  sobre precio de venta, merma total y su % sobre lo producido.
- **Mermas**: `view=waste` ya trae filas, totales y desglose por motivo — por
  motivo, por insumo, por origen (producción vs manual), en el tiempo.
- **Consumos**: consumo por insumo por período desde el ledger
  (`stockCount < 0 AND source='production'`), que funciona hoy aunque no se
  pueda atribuir a una orden.
- **Órdenes**: el detalle (`view=detail`) con su snapshot.

Cada bloque con tiempos declara su cobertura (cuántas órdenes tienen
`started_at`), igual que el dashboard de órdenes.

### F2 — KPIs de desvío (requiere F0, solo hacia adelante)

Desvío de consumo por insumo (real − teórico, en unidades y en costo), ranking
de insumos con más desvío, rendimiento real vs esperado, merma de proceso vs
merma de almacenamiento, por responsable.

### F3 — Rollup, solo si la medición lo pide

Hoy los reportes leen las tablas en vivo. Mismo criterio que `context/62` D5:
medir antes de construir un rollup.

## 5. Bugs encontrados en el camino (independientes del plan)

1. **Doble conteo del costo de las unidades falladas.** `unitCogs =
   ingredientCost / qtyProduced` ya reparte el costo de lo fallado sobre las
   unidades buenas, y además `waste_event.cost` vuelve a valuarlas a
   `unitCogs`. Sumar costo de producción + costo de merma cuenta ese costo dos
   veces (D4).
2. **El reporte suma una tabla legacy (`production`) que nada escribe.** Si
   tiene filas viejas, mezcla datos de otra época sin avisar.
3. **Merma manual con costo 0** cuando el ítem no tiene stock
   (`ProductionService.php:850-852`): la merma "no cuesta nada" en el reporte.

## 6. Arquitecturas a evitar

- **Reconstruir el teórico histórico con la receta ACTUAL.** La receta pudo
  cambiar; el desvío calculado así mide el cambio de receta, no el consumo.
  El histórico queda sin desvío y la pantalla lo dice.
- **Atribuir consumo a órdenes parseando `stockNote`.** Es texto libre; el
  vínculo va en una columna (F0.2).
- **Un segundo ledger de consumo.** El consumo ya está en `stock`
  (`context/52`, lector único). F0 le agrega referencia, no otra tabla.
- **Merma planificada por línea de receta.** Decisión cerrada del owner
  2026-09-06: es del insumo.

## 7. Decisiones para el owner

- **D1** — `/reports/production` con tabs Dashboard · Consumos · Mermas ·
  Órdenes. [propuesta]
- **D2** — ¿Los lotes tienen que poder cargar consumos y mermas reales al
  confirmar? Si no, el desvío de producción por lote va a ser siempre cero.
  [abierta — la que más cambia el alcance]
- **D3** — Merma de insumo durante la producción como evento propio. [propuesta]
- **D4** — Cómo contar el costo de las unidades falladas: repartido en las
  buenas (hoy) o reportado aparte como merma. No las dos. [abierta]
- **D5** — ¿F1 ya, antes de F0? Da valor inmediato con el dato actual.
  [propuesta: sí]
