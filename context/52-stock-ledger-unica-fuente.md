# 52 — Stock: el ledger como única fuente de verdad

> Estado: **PLAN CERRADO CON EL OWNER, EN EJECUCIÓN** (sesión 2026-08-24).
> Origen: conversación con el owner — "la tabla stock es el ledger como el de
> un banco: no puede existir un solo movimiento que no quede registrado ahí, y
> cada línea posee el stock real al momento del registro". El modelo pedido
> por el owner ES el estándar de la industria (ERPNext Stock Ledger Entry,
> Odoo stock.move): inventario perpetuo, ledger append-only, saldo derivado.
> No se inventa un modelo nuevo — se termina de aplicar el que ya existe.

## El problema (verificado contra código, 2026-08-24)

"¿Cuánto stock hay del ítem X en el outlet Y?" tiene hoy CUATRO respuestas
posibles y nada las ata:

| Fuente | Qué es en realidad | Estado deseado |
|---|---|---|
| `stock` (ledger) | Movimientos con signo + saldo y costo por fila | **ÚNICA fuente de verdad** |
| `stockTrigger` | El MÍNIMO de reabastecimiento (mal nombrado) | Solo umbral, jamás saldo |
| `inventory` | Batches FIFO/FEFO (lote, vencimiento, COGS) | Solo dimensión de lote |
| `toLocation` | Desglose del saldo por depósito | Derivada, con invariante |

Cada lector (`getCurrentStock`, `getItemMainStock`, `getAllItemStock`,
reporte de stock, bootstrap del POS) elige fuente por su cuenta → el panel
puede mostrar un número y el POS otro. Auditoría completa de escritores y
lectores: ver §Auditoría al final (agentes 2026-08-24).

## Lo que YA está bien (no tocar)

- `stock.stockCount` guarda el movimiento CON SIGNO → `SUM(stockCount)` es el
  saldo por definición, inmune a compras con fecha retroactiva (mig 130, bug
  del salmón). `Inventory::onHand()` ya calcula así.
- Reposteo: al insertar un movimiento fechado atrás, el recalculador
  (`Inventory.php:541`) rehace los snapshots (`stockOnHand`/`stockOnHandCOGS`)
  de las filas posteriores. Mismo mecanismo que ERPNext.
- Costeo promedio ponderado móvil: ingreso recalcula promedio, egreso sale al
  promedio vigente sin alterarlo (`Inventory.php:846-886`).
- Ajuste manual desde la ficha del ítem: ya existe (tab Stock → dialog),
  registra en el ledger `source='adjustment'` + motivo/nota/usuario/fecha
  (`StockAdjustmentService::create`).
- **El costo se guarda CON IVA incluido a propósito** (decisión owner
  2026-08-24): es el valor real pagado; el desglose impositivo es etapa
  contable del contador, no operativa. NO "corregir".

## Decisiones

- **D1 — El ledger `stock` es la única fuente de verdad del saldo.** Saldo
  actual = `SUM(stockCount)` por (item, outlet) — o la última fila reposteada,
  que debe coincidir. Todo lo demás deriva.
- **D2 — Un solo lector.** Módulo único de lectura (`StockResolver` o método
  en `Inventory`) que TODOS los consumidores usan: panel, POS bootstrap,
  reportes, conteo físico, alertas. Prohibido leer saldo de
  `stockTrigger`/`inventory`/`toLocation` para responder "cuánto hay".
- **D3 — `stockTrigger` vuelve a ser solo el umbral mínimo.** Ningún lector
  lo trata como saldo. Renombrarlo queda para después (rename = coordinación).
- **D4 — `toLocation` es derivada con invariante.** `SUM(toLocationCount)`
  por (item, outlet) == saldo del ledger, garantizado por trigger PG o por
  función atómica de transferencia — no por disciplina de código.
- **D5 — `inventory` (batches) queda solo para lote/vencimiento/FEFO.**
  No responde "cuánto hay". Reconciliación batch↔ledger es problema aparte y
  NO bloquea este plan.
- **D6 — `manageStock` una sola copia** (`Inventory::manageStock`). La copia
  legacy de `api/includes/functions.php` delega o muere. Todo movimiento pasa
  por ahí: es el único punto que escribe el ledger.
- **D7 — Todo movimiento deja fila en el ledger** (pedido explícito del
  owner). Los gaps que la auditoría encuentre se cierran haciendo que el
  camino pase por `manageStock`, nunca con INSERT directo paralelo.

## Crecimiento del ledger (preocupación del owner)

El miedo: tabla gigante de registros que nunca más se revisan. La respuesta
NO es borrar historia (rompe auditoría y el reposteo) sino el mismo patrón ya
probado en este repo con `transaction`/`itemsold` (context/48, migs 156/157):

1. **Fila de apertura por período (`source='opening'`).** Al cierre de
   período (E1b ya existe) se inserta por (item, outlet) una fila con el
   saldo y costo promedio de arranque. `SUM` y reposteo solo necesitan leer
   desde la última apertura — el costo de la consulta queda acotado al
   período, no crece con la historia. Es exactamente el saldo inicial del
   extracto bancario.
2. **Particionado mensual de `stock`** con la misma maquinaria de la mig 156.
   Partición vieja = fría: no infla los índices de lo activo, se puede mover
   a tablespace barato o desprender (E4 de context/48) sin tocar el motor.
3. **El cierre de período ya la protege** (`fn_period_guard`): un período
   cerrado no acepta mutaciones, así que las particiones viejas son
   efectivamente inmutables — candidatas naturales a archivo.
4. **Los reportes históricos leen rollups** (context/48 D8), no el ledger.
   El ledger crudo solo se consulta para el historial puntual de un ítem
   (paginado) y para auditoría.

Con apertura + particiones, la consulta caliente lee un mes de un ítem — da
igual que la tabla tenga 10 años. Medición previa (context/48 §602): el
histórico frío no le pesa a la consulta caliente si el índice y la partición
acotan el rango.

## Fases

- **F1 — Lector único.** `StockResolver` (o consolidar en `Inventory`):
  `onHand(item, outlet)`, `onHandBulk(outlet)`, `breakdown(item)` (por
  depósito, derivado). Migrar TODOS los lectores detectados por la auditoría.
  Los reportes y el bootstrap del POS leen de acá.
- **F2 — Retirar fuentes espurias.** `stockTrigger` solo umbral;
  `toLocation` derivada con invariante (trigger o función atómica);
  queries que sumaban batches para saldo → lector único.
- **F3 — Cerrar gaps de escritura** (los que la auditoría marque): todo
  camino que mute cantidad física pasa por `manageStock`. Incluye combos,
  add-ons, producción directa/previa, transferencias, conteo, merma,
  anulación/NC, remisión, sync offline.
- **F4 — `manageStock` único** (D6): matar la copia de functions.php.
- **F5 — Crecimiento:** filas de apertura al cierre de período + particionado
  mensual de `stock` (patrón mig 156/157). Se activa por señal (volumen), no
  por calendario — igual que context/48.
- **F6 — Historial completo en la UI:** el historial del ítem (tab Stock)
  muestra saldo anterior, movimiento, saldo resultante y costo promedio
  (`stockOnHandCOGS`) — formato extracto bancario que pidió el owner. El
  legacy no mostraba el promedio; el costo real del inventario es ESE, no el
  costo del último movimiento.

## Auditoría (2026-08-24)

> Los reportes de los dos agentes (escritores del ledger + lectores de saldo)
> se integran acá al terminar. Gaps numerados → F3.

(pendiente de los agentes — completar en esta misma sesión)
