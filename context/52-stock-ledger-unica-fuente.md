# 52 — Stock: el ledger como única fuente de verdad

> Estado: **F1-F4 y F6 IMPLEMENTADAS** (2026-08-24, merge `4f95ba50`) —
> lector único (`onHand`/`onHandBulk`/`onHandByLocation`, todo SUM), fuentes
> espurias retiradas (`toLocation` ya no se escribe, `stockTrigger`/`inventory`
> sin lectores), 5 bugs del escritor cerrados (VariantService, doble
> reposición de combos vía `kind='compoundChild'`, void recursivo, manageStock
> lanza en fallo real, guard de tenant), historial formato extracto en la
> ficha. **F5 (apertura por período + particionado) espera señal de volumen**
> (decisión owner). Arnés `api/tests/stock_ledger_test.php` listo, corre con
> `bash api/tests/run_stock_ledger_test.sh` (pendiente de primera corrida —
> requiere Docker/contenedor).
> **D8 — depósito por defecto por sucursal IMPLEMENTADO** (2026-08-24, mig
> 165): ver §"Depósito por defecto". El histórico del ledger (`locationid
> IS NULL`) NO se migró — es decisión explícita del owner, no un olvido.
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
- **D3 — `stockTrigger` se RETIRA** (revisada tras la auditoría: no tiene
  writer vivo, siempre devuelve 0). El único mínimo es `item.itemMinStock`;
  `Reports/StockService` migra a leerlo como ya hacen listado/tab/POS. La
  tabla queda sin lectores; drop en una mig posterior.
- **D4 — `toLocation` es derivada con invariante.** `SUM(toLocationCount)`
  por (item, outlet) == saldo del ledger, garantizado por trigger PG o por
  función atómica de transferencia — no por disciplina de código. El ledger
  ya lleva `locationId` por fila, así que el desglose por depósito puede
  salir del propio ledger (`SUM ... GROUP BY locationId`, como ya hace
  `StockMovementsService::breakdown`) — `toLocation` puede seguir el camino
  de `stockTrigger` si la invariante confirma redundancia.
- **D5 — `inventory` (batches) se DEPRECA** (revisada tras la auditoría:
  cero lectores en todo el repo, write-only muerta; el FEFO/lotes nunca se
  implementó). Si algún día se hace vencimiento/lotes, se diseña de cero
  sobre el ledger. Se retiran sus escritores residuales.
- **D6 — `manageStock` una sola copia** (`Inventory::manageStock`). La copia
  legacy de `api/includes/functions.php` delega o muere. Todo movimiento pasa
  por ahí: es el único punto que escribe el ledger.
- **D7 — Todo movimiento deja fila en el ledger** (pedido explícito del
  owner). Los gaps que la auditoría encuentre se cierran haciendo que el
  camino pase por `manageStock`, nunca con INSERT directo paralelo.
- **D8 — El stock siempre está en un depósito, y hay uno POR DEFECTO por
  sucursal** (regla del owner 2026-08-24, mig 165). Ver §"Depósito por
  defecto" abajo.

## Depósito por defecto (D8 — implementado 2026-08-24, mig 165)

Palabras del owner: *"Cada sucursal sí o sí, por defecto, tiene que tener un
depósito. [...] el stock tiene que estar en un lugar físico, no puede estar en
el aire. [...] el depósito no puede ser opcional, sí o sí tiene que haber uno y
sí o sí se tiene que seleccionar uno. Por ende, ya tiene que estar
preseleccionado el principal."*

**Modelo.** Un depósito es una fila de `taxonomy` con `taxonomytype='location'`
atada a la sucursal por `taxonomy.outletid`. El POR DEFECTO se marca con
`taxonomyextra = {"isDefault": true}` — mismo patrón que los roles seed de
`RoleService`. `taxonomyextra` es **TEXT, no JSONB**: todo acceso lleva cast
explícito, y se usa `->>`, nunca el operador `?` de jsonb (colisiona con el
placeholder de PDO y tumba el boot — migs 74/77).

**Invariante.** Como máximo un default por sucursal, garantizado por el motor:
índice único parcial `uq_taxonomy_location_default ON taxonomy (outletid)
WHERE fn_taxonomy_is_default_location(taxonomytype, taxonomyextra)`. La función
es `IMMUTABLE` (requisito para el predicado) y devuelve `false` ante
`taxonomyextra` no parseable en vez de lanzar — un predicado de índice no
garantiza orden de evaluación de sus `AND`, así que anteponer
`taxonomytype='location'` NO protegía del cast.

**Quién lo crea.** `LocationTaxonomyService::ensureDefault()` es el ÚNICO
creador, idempotente, y lo llaman los dos caminos de producción que dan de alta
una sucursal: `OutletsService::create()` y `Auth\SignupService` (este último lo
saltaba: todo tenant nacía con "Central" sin ningún depósito). **Un camino
nuevo de alta de sucursal debe llamarlo también.**

> Bug latente que esto cerró: el INSERT inline anterior nombraba siempre
> "Depósito Principal", y `uq_taxonomy_company_type_name` (mig 38) es UNIQUE
> sobre `(companyid, taxonomytype, lower(taxonomyname))` → la SEGUNDA sucursal
> de una misma company reventaba por unicidad y, como el fallo aborta la
> transacción, **la sucursal entera no se creaba**. Por eso el nombre por
> defecto es "Depósito &lt;nombre de la sucursal&gt;".

**Lectura — por qué `NULL` se consolida.** `Inventory::ledgerLocationJoin()` +
`ledgerLocationId()` resuelven `stock.locationid IS NULL` al depósito por
defecto de la sucursal de esa fila. Lo usan los tres lectores que agrupan por
depósito: `Inventory::onHandByLocation()`, `StockMovementsService::breakdown()`
y `Reports\StockService::breakdownByLocation()`. Sin esto, el mismo depósito
físico aparecía DOS veces con el saldo partido (verificado en prod: un ítem
mostraba `Almacenamiento de Materia Prima 200` + `(sin depósito) -26` en vez de
`174`). El LEFT JOIN no puede duplicar filas del ledger porque el índice único
prohíbe el segundo default.

### Deuda abierta — DECISIÓN del owner, no olvido

El owner eligió explícitamente **no tocar el histórico** (2026-08-24). Queda
así a propósito:

1. **~678 filas de `stock` con `locationid IS NULL` no se migraron.** Siguen en
   NULL. Se consolidan en la LECTURA, no en los datos.
2. **`stock.locationid` sigue siendo NULLABLE.** No se puso `NOT NULL`.
3. **Los escritores del ledger (venta POS, compra, producción, devolución,
   transferencia, conteo) siguen pudiendo escribir NULL.** Solo el ajuste
   manual desde la ficha del ítem exige depósito hoy.

Consecuencia a tener presente: mientras (1)-(3) sigan vigentes, **cualquier
lector nuevo que agrupe por `locationid` crudo vuelve a partir el saldo**. Usar
siempre `Inventory::ledgerLocationId()`. Cerrar la deuda = migrar el histórico
al default de su sucursal + `NOT NULL` + exigir depósito en todos los
escritores; es un trabajo aparte que el owner todavía no pidió.

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

### Lectores (agente 1 — completo)

Hallazgos que CAMBIAN el plan:

- **`inventory` (fuente C) está MUERTA**: cero lectores en todo el repo; solo
  un INSERT de backfill con count=0 al crear sucursal y DELETEs de limpieza.
  No hay FEFO/lotes implementado. → D5 se reescribe: `inventory` se DEPRECA
  (no "queda para lotes" — no hay nada que quede). Si algún día se hace
  vencimiento/lotes, se diseña de cero.
- **`stockTrigger` (fuente B) no tiene writer vivo**: `applyTriggers()` no se
  instancia en ningún lado → siempre 0. El mínimo real es `item.itemMinStock`.
  → D3 se reescribe: `stockTrigger` se RETIRA; `Reports/StockService` pasa a
  leer `itemMinStock` como el resto (listado, tab Stock, POS).
- **El ledger se lee de DOS maneras que divergen**: A-snapshot (`stockOnHand`
  de la última fila) vs A-ledger (`SUM(stockCount)`). Divergen justo con la
  compra retroactiva (bug del salmón). Canónico: **SUM** (D1).

Ocho inconsistencias concretas (pares que muestran números distintos para el
mismo item/outlet):

1. Listado de ítems (`ItemsQuery.php:290` — SUM **sin filtro de outlet**,
   company-wide) vs cualquier lector por sucursal.
2. Reporte de Stock (`Reports/StockService.php:44`, snapshot) vs tab Stock /
   ficha POS (`StockMovementsService`, SUM).
3. **MONEY PATH** — Conteo de inventario: `expectedQty` sale del snapshot
   (`InventoryCountScope.php:176`), el ajuste que genera usa esa base; el tab
   muestra SUM. Esperado equivocado → ajuste equivocado.
4. Capacidad de producción (`ProductionService.php:192`, snapshot outlet) vs
   tab Stock (SUM company).
5. "Principal" del reporte (`stockOnHand − Σ toLocation`) vs breakdown
   (`SUM WHERE locationId IS NULL`) — dos definiciones incompatibles; la
   resta cuenta doble si el movimiento se escribió en ambos lados.
6. `ItemService::getInventory()` (snapshot + toLocation, con bug de `$dTotal`
   acumulativo que resta de más) vs breakdown (SUM) — endpoint sin consumidor
   front, pero expuesto.
7. `ORDER BY stockDate DESC` SIN desempate por `stockId` en
   `Reports/StockService` y `StockDayService` → fila arbitraria cuando dos
   movimientos comparten fecha (típico: una misma venta).
8. Mínimo: reporte lee `stockTrigger` (siempre 0) vs resto lee
   `itemMinStock` — el semáforo solo existe en la segunda familia.

Además:
- **POS offline sin stock**: `reshape.ts:96` hardcodea `stock: null` (TODO
  vivo) → el badge de stock del buscador y el patch optimista post-cobro son
  no-ops. El único stock que ve el POS es online (breakdown).
- Código muerto: `getItemMainStock`, `getAllItemStock` (solo tests),
  `Admin\ReportInventoryService` (llama función inexistente), rama depósito
  de `getItemStock` con **binds invertidos** (cero callers).

### Escritores (agente 2 — completo)

Lo sano: **un solo INSERT en `stock`** (`Inventory.php:910`, dentro de
`manageStock`); el espejo de `functions.php:1729` solo delega (D6 casi
cumplida). La venta offline sincroniza por el MISMO `SaleService::save` →
mismo `manageStock` — sin ruta paralela. Venta, receta/producción directa,
add-ons, producción previa, merma, compras y sus reversas, void/return,
transferencias, conteo, ajustes e importación: todos pasan por `manageStock`.

**Gaps del escritor, triados:**

| # | Gap | Veredicto |
|---|---|---|
| G2 | `VariantService.php:197` llama `\Inventory::manageStock` — clase inexistente; crear variantes con stock inicial revienta y rollbackea toda la matriz | **BUG P0 — fix ya** |
| G4 | Void/devolución reponen stock de hijas `type='compound'` que nunca descontaron (la venta las saltea en `SaleService:2234`; la reversa no filtra `itemsoldparent`) | **BUG P0 money — fix ya** |
| G5 | Void legacy (`TransactionService:1093`) repone solo nivel 1 de la receta; la venta explota recursivo | **BUG — fix ya** |
| G11 | `manageStock` devuelve `false` igual para "no trackea" (no-op legítimo) y "el INSERT falló"; venta/compra/void/conteo ignoran el retorno → movimiento perdido en silencio | **BUG — fix ya**: lanzar en fallo real, `false` solo para no-op |
| G12 | Guard multi-tenant valida contra `COMPANY_ID` global en vez del `companyId` del caller | **BUG — fix ya** |
| G7/G8 | `toLocation`: los ingresos van con `locationId=null` (solo baja, nunca sube → deriva a negativo), sin scope de outlet/company, sin UNIQUE | **Se RETIRA como tabla escrita** (D4 revisada): el ledger ya lleva `locationId` por fila; el desglose sale de `SUM ... GROUP BY locationId` (breakdown), que ya existe |
| G9/G10 | `stockTrigger` e `inventory` sin escritores/lectores vivos | Retirar escritores residuales (INSERT blanco de OutletsService, clase muerta `Items\StockService`); drop de tablas en mig posterior |
| G1 | Combo dinámico viejo (`combo_group`) no mueve stock | **NO es gap vivo**: deprecado en F5 de context/41 (mig 136); la venta real usa addon groups, que SÍ descuentan |
| G3 | Canje de pack (`sold_pack_usage`) no descuenta componentes físicos | Gap real, módulo aparte — **backlog** (decidir con owner) |
| G6 | Producción completada no tiene reversa (solo ajuste manual) | Feature — backlog |
| G13 | Insumos sin `itemTrackInventory` no dejan fila (agua/sal) | Intencional — solo COGS; documentado como excepción |
| G14 | Remisión no mueve stock | Por diseño (context/42): el motivo que mueve stock tiene su propio documento |
| G15 | Borrar sucursal/company hace `DELETE FROM stock` — destruye ledger | Backlog (soft-delete/archivo); el cierre de período ya protege lo cerrado |
| G16 | Apagar `itemTrackInventory` deja históricas huérfanas + manageStock no-op silencioso | Menor — backlog |
| G17 | = inconsistencia snapshot vs SUM de los lectores | F1 (lector único, SUM) |
| — | Código muerto: `voidSale()` legacy, `Items\StockService`, `Admin\ReportInventoryService`, `getItemMainStock`, `getAllItemStock`, rama depósito de `getItemStock` (binds invertidos) | Se elimina en F2 |
