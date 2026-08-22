# 48 — Escalamiento de datos (particionado, réplica, rollup)

> Estado (2026-08-21): **plan, D1-D8 cerradas por el owner, NO relitigar.**
> Preocupación del owner (textual): SaaS multi-tenant con años de histórico
> por delante — ¿cómo evita que el crecimiento acumulado sobrecargue toda la
> infraestructura? Propuso en caliente 3 bases (caliente/histórica/fría).
> La idea central de esa propuesta — que lo pasado un cierre de período
> quede **inmutable** porque ya alimentó reportes calculados — es correcta y
> se ADOPTA (D7). Lo que se descarta es la implementación como tres bases
> físicas: la inmutabilidad se aplica como regla del sistema (guard en DB)
> sobre una sola Postgres particionada; la partición cerrada ES la "base
> fría/histórica", sin copiar nada a otro lado. Falta: ejecutar E1
> (particionado + `companyId` en `itemsold`) hoy mismo — es la etapa que se
> vuelve dolorosa cuanto más se demora.

## Por qué

Punto es SaaS multi-tenant de operación diaria (POS): cada venta escribe
`transaction` + N `itemSold`. Sin techo de retención (la regla fiscal
obliga a que un documento emitido siga consultable y cancelable
indefinidamente — `project_fiscal_doc_types`, `context/40`), el volumen de
estas tablas crece sin parar mientras el producto viva. El owner planteó la
pregunta con años de horizonte, no con el volumen de hoy: prod tiene 723
`transaction` y 1.029 `itemSold` — chico a propósito, es la ventana donde
particionar cuesta minutos en vez de una migración con downtime.

## Qué resuelve lo que ya existe y qué NO

`context/18` (rollups, F0 implementada 2026-08-21 vía cron cada 10 min —
ver `context/06` "Jobs de mantenimiento") resuelve **reportes por período a
costo constante**: un año de ventas se lee en O(1) filas de
`report_rollup` en vez de agregar millones de `transaction`. Esto es
necesario pero no alcanza:

- **NO achica las tablas de hechos.** `transaction` e `itemSold` siguen
  creciendo sin techo — el rollup es una vista agregada al lado, no un
  reemplazo. Los queries que necesitan la fila individual (detalle de
  transacción, listados, anulación, exports fila-por-fila) siguen pegándole
  a la tabla completa.
- **NO cubre cortes por ítem en rangos largos a costo bajo salvo a grano
  mes/año.** El grano `day` de `item_sales` escala con
  `tenants × outlets × ítems × días` — para catálogos grandes se acerca al
  volumen de la propia `itemSold`. Por eso el corte real de bajo costo es
  mes/año (D5), no día.
- **NO cubre compras, producción ni stock** — RB-3 de `context/18` sigue
  sin implementar; esos dominios leen la tabla fact en vivo hoy
  (confirmado también en `context/47`, que depende de este gap).
- **NO resuelve cruces ad-hoc del BI** (agente IA armando un dataset que el
  catálogo de `context/47` no anticipó) — eso necesita, en el techo,
  un motor analítico aparte (E5).

## Decisiones (D1-D8)

**D1 — Una sola base de datos, particionada por dentro. NO tres bases
físicas — pero SÍ se adopta la inmutabilidad por cierre de período que
las motivaba (D7).** El owner propuso caliente (R/W) + histórica (solo
lectura, reportes) + fría (archivo). El diagnóstico detrás de esa
propuesta es correcto: pasado un corte, un período no debería poder
editarse porque ya alimentó reportes/rollups calculados — eso es
exactamente lo que D7 implementa. Lo que se descarta es la forma:
tres bases físicas. Se adopta UNA Postgres con tres capas internas que
cubren la misma necesidad sin duplicar el dato:

| Necesidad que motivaba la base separada | Capa dentro de la misma Postgres |
|---|---|
| Aislar lectura pesada de reportes de la escritura del POS | Réplica de lectura en streaming (D6/E3) — mismos datos, sin ETL |
| Que lo viejo no infle los índices de lo activo | Particionado por mes (D3/E1) — partición vieja = "fría" sin moverla de motor |
| Archivo barato de lo muy viejo | Partición vieja a tablespace barato o desprendida (E4) |
| **Que lo pasado un corte no se pueda editar** (idea central de la propuesta del owner) | **Cierre de período (D7)** — guard en DB que rechaza mutaciones sobre filas de un período cerrado, sea cual sea la tabla física donde vivan |

Motivos para descartar tres bases físicas (la implementación, no la idea):
- **(a) El borde entre "caliente" e "histórica" no es limpio en un POS.**
  Devoluciones, notas de crédito con CDC de la factura original
  (`context/35`, `transaction_link`), cuentas por cobrar abiertas y
  anulaciones cruzan meses — una venta de hace 8 meses puede anularse hoy.
  Cada query que cruza el borde caliente/histórica pasa a ser lógica de
  aplicación fusionando dos bases, para siempre.
- **(b) La regla fiscal exige que lo viejo siga consultable y cancelable**
  (`project_fiscal_doc_types`) — no se puede excluir una partición del
  camino de escritura solo por ser vieja.
- **(c) Tres bases = ETL propio + schema sincronizado en tres lugares en
  cada migración + tres backups consistentes + una fila que cambia
  después de "migrada" (venta vieja anulada hoy) rompiendo el supuesto de
  que lo histórico es inmutable.**
- **(d) Dos copias = dos fuentes de verdad** — el riesgo exacto que
  `context/47` D1 previene para reportes (un motor, un número) se
  reintroduce a nivel de infraestructura si hay tres bases con el mismo
  dato.

**Cuándo SÍ separar de verdad:** un warehouse columnar real (ClickHouse o
similar) alimentado por CDC, para volumen muy por encima de lo previsible
en años — eso se agrega ENCIMA de esta arquitectura, no la reemplaza (E5).

**D2 — Histórico desde rollup, hoy en vivo** (ya cerrada en `context/18`;
se reafirma acá como el mecanismo que hace que el costo de un reporte por
período no dependa del volumen acumulado — independiente de D1/D3).

**D3 — Particionar `transaction` e `itemSold` AHORA, por rango mensual.**
Particionar con mil filas es una migración de minutos; con 30 millones en
producción es una operación de horas con ventana de downtime. La tabla
está chica hoy a propósito — es la ventana de bajo costo.

- **Clave de partición**: `transactionDate` (`TIMESTAMPTZ NOT NULL`) en
  `transaction`; `itemSoldDate` (`TIMESTAMPTZ NOT NULL`) en `itemSold` —
  ambas ya son la fecha canónica de cada tabla y ya están indexadas
  individualmente (`idx_tx_date`, `idx_itemsold_date`), no hace falta
  denormalizar ninguna fecha nueva.
- **Creación automática de particiones futuras**: función PL/pgSQL
  `ensure_partitions(months_ahead int)` que hace `CREATE TABLE IF NOT
  EXISTS transaction_yYYYY_mMM PARTITION OF transaction FOR VALUES FROM
  (...) TO (...)` (ídem `itemSold`), invocada por un nuevo job
  `partition-ensure` en `api/v1/maintenance.php` — mismo mecanismo que
  `rollup-reconcile`/`einvoice-drain` (cron BusyBox dentro de la imagen
  del API, NO pg_cron — decisión ya cerrada en `context/06`). Corre diario,
  crea con 3 meses de margen; si el cron no corre un día no pasa nada
  grave (margen de sobra), pero si se detiene semanas seguidas, un
  `INSERT` a un mes sin partición falla duro — mismo patrón de "falla
  silenciosa" que ya mordió a `rollup_dirty` (134 pendientes sin nadie
  corriendo el job). Agregar chequeo en el propio endpoint de maintenance
  que alerte (GlitchTip) si faltan particiones para el mes que viene.
- **FK hacia `transaction`**: Postgres exige que la clave de partición
  forme parte de cualquier PK/UNIQUE de la tabla particionada — la PK pasa
  de `transactionId` a `(transactionId, transactionDate)`. Eso rompe toda
  FK `xxx.transactionId REFERENCES transaction(transactionId)`, porque el
  destino ya no es una PK de una sola columna. Tablas que referencian
  `transactionId` hoy: `itemSold`, `stock`, `toTransaction` (2 FK:
  `parentId` y `transactionId`), `toTaxObj`, `toAddress`, `comission`,
  `giftCardSold`, `printServer`, `satisfaction`, `vPayments` — 10 tablas
  satélite (11 columnas FK) más la auto-referencia `transactionParentId`
  dentro de la propia `transaction`.

  Dos caminos:
  1. **FK compuesta** — agregar `transactionDate` denormalizada a las 10
     tablas satélite y cambiar cada FK a `REFERENCES
     transaction(transactionId, transactionDate)`. Mantiene integridad
     referencial a nivel DB, pero infla 10 tablas con una columna que solo
     existe para satisfacer al motor, y cada futuro insert-path tiene que
     acordarse de poblarla en sync.
  2. **Sin FK enforced hacia `transaction`** — se elimina la constraint
     (queda un índice plano sobre `transactionId`, que ya existe en la
     mayoría). Integridad se sostiene en el write-path: estas 10 tablas
     satélite son append-only, escritas por el mismo `Service` en la misma
     transacción DB que el `INSERT` a `transaction` (nunca hay un
     `itemSold` sin su `transaction` porque los inserta el mismo
     `SaleService::save()` dentro del mismo `StartTrans`/`CompleteTrans`).
     `transaction` nunca se hard-delete (`transactionParentId` +
     `transaction_link` son el mecanismo de anulación, `context/40`), así
     que no hay borrado que deje huérfanos tampoco.

  **Se elige (2).** Es el patrón estándar de Postgres para tablas
  particionadas de alto volumen (evitar FK hacia el padre particionado,
  ancho de las FK compuestas no compensa el beneficio marginal cuando el
  write-path ya garantiza la integridad). Antes de dropear cada FK, auditar
  que el `Service` correspondiente efectivamente inserta el satélite
  dentro de la misma transacción DB que el `INSERT` a `transaction` — si
  alguno no lo hace, la FK hoy lo estaba tapando y hay que arreglar el
  Service, no solo dropear la constraint.

- **Índices por partición**: definidos sobre la tabla padre particionada
  (`CREATE INDEX ... ON transaction (...)`), Postgres los propaga
  automáticamente a cada partición existente y a las que se creen después
  vía `PARTITION OF` — no hay que recrear índices a mano por mes.
- **Migración de datos existentes**: con 723/1.029 filas, `ALTER TABLE ...
  RENAME`, crear la tabla particionada con el mismo nombre, `INSERT INTO
  ... SELECT * FROM transaction_old`, recrear índices y FKs entrantes,
  drop de la vieja — todo en una migración SQL de la carpeta de
  `api/database/migrations/postgres/`, sin ventana de downtime real
  (segundos). Esta ruta deja de ser viable a partir de varios millones de
  filas (ahí el patrón es "crear la partición, doble-escritura, backfill
  en batches, swap" — no aplica hoy).

**D4 — `itemSold` gana `companyId`, `outletId` y `registerId` (además del
`userId` que ya tiene) e índice compuesto `(companyId, itemId,
itemSoldDate)`.** Ampliación del owner (2026-08-21): no solo `companyId` —
también sucursal y caja. Son dos UUID más por línea (costo despreciable) y
a cambio cualquier reporte por producto filtra por sucursal o caja sin el
JOIN a `transaction`, que tras D3 es un JOIN contra una tabla particionada
(más caro que hoy). Encaja con la regla de congelar de D8: la sucursal, la
caja y el vendedor de la venta son hechos del momento, no se resuelven
después. Índices adicionales: `(companyId, outletId, itemSoldDate)` y
`(companyId, registerId, itemSoldDate)` solo si las señales de E1 los
piden — no de entrada. Hoy `itemSold` NO tiene `companyId` — el
aislamiento multi-tenant depende de un JOIN a `transaction` en cada
query, y los 4 índices existentes
(`idx_itemsold_tx`, `idx_itemsold_date`, `idx_itemsold_item`,
`idx_itemsold_user`) son de una sola columna, ninguno sirve para el corte
real que pide el owner: *"cuánto vendí de este producto en el año"* —
`WHERE companyId = ? AND itemId = ? AND itemSoldDate BETWEEN ? AND ?`. Sin
la columna desnormalizada ese filtro no puede ni empezar por `companyId`
sin el JOIN. Se pueblan con `UPDATE itemSold i SET companyId = t.companyId, outletId
= t.outletId, registerId = t.registerId FROM transaction t WHERE
t.transactionId = i.transactionId` en la migración, `NOT NULL` después del
backfill (`registerId` puede ser NULL: ventas desde panel sin caja), y
mantenidas en el insert de `SaleService`/`ReturnService` (mismo punto que
ya escribe el resto de las columnas de `itemSold`).

**D5 — Completar dominios de rollup faltantes (compras, producción,
stock — RB-3 de `context/18`).** Relevado en esta sesión: el grano
mes/año de `item_sales` **ya está implementado** (mig
`42_rollup_item_payments.sql`, función `rollup_recompute_period` cubre
day→month→year para `item_sales`/`item_returns`/`payments`) — no es un
gap. Lo que falta es RB-3: los dominios `purchases`, `production` y
`stock_moves` siguen sin rollup y leen la tabla fact en vivo (confirmado
también por `context/47`, que depende de esto para sus datasets de
Compras/Producción/Stock). El gap real de retención es otro: el grano
`day` de `item_sales` no tiene política de poda — para catálogos grandes
crece cerca del volumen de `itemSold` (ver proyección abajo). Falta
definir una retención (ej. podar filas `day` de `item_sales` con más de
90 días una vez finalizado el mes, conservando solo `month`/`year`).

**D6 — Réplica de lectura en streaming cuando la lectura compita con la
escritura.** Misma base de datos, sin ETL — Postgres replica físicamente.
Reportes/exports/BI apuntan a una segunda conexión (`POSTGRES_READ_HOST`/
`POSTGRES_READ_*` en env, con fallback a la primaria si no está
configurada — mismo patrón que otras env vars opcionales del proyecto,
`context/06`). Requiere confirmar si el plan de Coolify actual permite
levantar un segundo container de Postgres en modo réplica sin
configuración fuera de lo versionado — no relevado en esta sesión, ver
Riesgos.

**D7 — Cierre de período: lo pasado un corte es inmutable.** Tabla
`period_close(companyId, period, closedAt, closedBy)`. Ventana abierta:
mes en curso + el anterior (default 2 meses, configurable por tenant — el
owner habló de "un mes o dos"). Guard en la base — trigger `BEFORE UPDATE
OR DELETE` — sobre las tablas de hechos con fecha propia: `transaction`,
`itemSold`, `stock` (movimientos de stock), `cpayments` (cobros de
crédito) y `expenses` (movimientos de caja — extracción/ingreso; el
brief mencionaba `fin_movement`, pero esa tabla no existe en el schema:
la real es `expenses`, ya con `type` 1/2 para extracción/ingreso y su
propio dominio de rollup `drawer_expenses`). El trigger rechaza la
mutación si la fecha de la fila cae en un período cerrado.

Consecuencias:
- **El rollup de un período cerrado queda definitivo**: se recalcula una
  última vez al cerrar (`rollup_recompute_period` sobre ese rango) y el
  período nunca vuelve a entrar a `rollup_dirty`. Esta es la ganancia real
  para reportes — hoy un `report_rollup` teóricamente puede volver a
  recalcularse si alguien logra editar algo viejo; con D7 esa puerta se
  cierra en la base, no en la aplicación.
- **La anulación** (`context/40`, ventana de 48 h) queda naturalmente
  contenida dentro del período abierto — el guard no la afecta si la
  ventana de anulación es más corta que la ventana de cierre (2 meses >
  48 h, hoy no hay conflicto; si la ventana de anulación creciera más
  allá de la de cierre, sí lo habría).
- **Qué es "inmutable" — pregunta abierta para el owner, no resuelta
  acá.** Hay operaciones legítimas posteriores al corte que tocan
  documentos viejos: un cobro de hoy salda una factura a crédito de hace
  4 meses (`transaction.transactionComplete` cambia en la fila vieja); una
  NC de hoy referencia una venta de hace 3 meses vía `transaction_link`
  (la venta en sí no cambia, pero se le cuelga un derivado). Propuesta a
  decidir con el owner: el **hecho económico** es inmutable (montos,
  ítems, fechas, cliente, numeración — ningún reporte de ventas cerrado se
  mueve), pero el **estado de liquidación** (saldada/pendiente) no, porque
  se deriva de documentos posteriores y vive fuera del período cerrado.
  Alternativa más estricta: `transactionComplete` deja de ser columna
  mutable y pasa a derivarse de `transaction_link` al leer — ahí la fila
  vieja literalmente nunca se toca. Relevado en esta sesión para
  dimensionar esa alternativa: **26 archivos PHP / 69 ocurrencias** y
  **6 archivos del frontend** leen/escriben `transactionComplete` hoy —
  migrar a derivado es un refactor real, no un ajuste de trigger.
- **Error detectado después del corte**: no se edita — se corrige con un
  documento nuevo en el período abierto (nota de crédito, ajuste de
  stock, movimiento de caja), igual que en contabilidad. El guard de D7
  hace esto obligatorio, no solo recomendado.
- **Disparo del cierre**: job `period-close` en `api/v1/maintenance.php`
  (mismo mecanismo que `rollup-reconcile`/`partition-ensure`), corre el
  día N del mes siguiente al vencimiento de la ventana; también debe
  poder cerrarse manualmente desde el panel con permiso de owner (fuera
  del cron, para cerrar antes si el tenant lo pide).

**D8 — El grano del rollup lo definen los filtros, no las métricas.**
Observación del owner (2026-08-21), textual en esencia: "si sumo las ventas
del 2025 puedo tenerlas en 12 registros, pero ¿qué pasa si quiero solo las
al contado y no a crédito? De esos meses ya sumados no tengo cómo extraer
cuáles son contado y cuáles crédito — y así como esa hay muchas variables".
Es exactamente el problema: **un agregado solo se puede filtrar por las
dimensiones que forman parte de su clave**; todo filtro que no esté en la
clave se perdió al sumar. Y hoy pasa: `rollup_recompute_period` suma
`transactionType IN (0, 3)` en la misma fila (mig 41 líneas 60/150) y la
clave del grano es solo `companyId, domain, periodType, periodStart,
outletId` (`uq_report_rollup_grain`) — "ventas al contado del año" NO sale
del rollup actual.

Decisiones:
- **Grano diario único, ancho en dimensiones, no muchos dominios angostos.**
  Para ventas: `día × outletId × registerId × condición (contado/crédito) ×
  tipo de documento × estado (vigente/anulada)`. Unas 10-30 filas por día por
  tenant (~5-10k al año) y de ahí sale CUALQUIER combinación con `SUM` +
  `WHERE`: todo el año, solo contado, una sucursal, solo anuladas. Mes y año
  NO se almacenan aparte: se derivan del diario (menos filas que mantener,
  una sola fuente). Para `item_sales`: `día × outletId × itemId ×
  categoría congelada` (acotado por ítems distintos vendidos ese día, no por
  el catálogo entero). Para `payments`: `día × outletId × medio de pago ×
  condición`.
- **Regla para decidir qué entra en la clave**: cardinalidad baja y uso real
  como filtro. Sucursal, caja, condición, tipo, estado, medio de pago,
  categoría, vendedor: SÍ. Cruces de alta cardinalidad entre sí (ítem ×
  cliente, ítem × vendedor × hora): NO — el producto de cardinalidades da un
  agregado más grande que la tabla de hechos. Esas preguntas van a la fact
  particionada (D3) con índice compuesto (D4) y techo de rango.
- **El catálogo de `context/47` declara por dataset qué filtros son
  dimensiones del rollup y cuáles obligan a ir en vivo.** Un filtro fuera de
  la clave nunca "aproxima" desde el rollup: o va en vivo con techo, o se
  rechaza. Es la regla que evita que un dashboard devuelva un número que
  parece correcto y no lo es.
- **Toda dimensión del rollup tiene que estar CONGELADA en el hecho al
  momento de la venta**, nunca resolverse por JOIN al catálogo actual. Si el
  rollup por categoría mirara la categoría de HOY del ítem, recategorizar un
  producto cambiaría el histórico solo — incompatible con D7. Mismo criterio
  que el IVA congelado por línea (`context/38`), que `itemSold.
  itemSoldCategory`, y que **`itemSold.itemSoldCOGS` — el costo del ítem al
  momento de la venta** (señalado por el owner: sin eso el margen histórico
  cambiaría cada vez que se actualiza el costo de un producto; es la métrica
  congelada más importante después del precio). Dimensión o métrica nueva en
  el rollup ⇒ columna congelada en la fact, o no entra.
- **Rollup por día ≠ particionar por día.** El owner propuso el grano diario
  justamente para conservar flexibilidad en el `WHERE` con un techo de 365
  filas al año por combinación de dimensiones — correcto, y es lo que esta
  decisión adopta. Pero son dos cosas distintas: el GRANO del rollup es día
  (tabla agregada), el PARTICIONADO de la fact es por mes (D3, almacenamiento
  físico). Particionar por día daría 365 particiones al año, pesado para el
  planner sin ganancia real; el mes es el corte correcto para la fact.
- **Migración**: la clave de `report_rollup` se amplía (mig nueva) y las
  funciones de recompute se reescriben una vez con el grano definitivo;
  después se recomputa todo desde `rollup_dirty` (hoy la tabla está vacía en
  prod, así que el costo es cero). Hacerlo ANTES de E2 — agregar dominios
  sobre el grano viejo es trabajo que habría que rehacer.

## Etapas — señal de activación, no calendario

| Etapa | Qué se hace | Señal de activación | Costo aprox. |
|---|---|---|---|
| **E0** | Cron de rollups (`rollup-reconcile` cada 10 min, dentro de la imagen API) | Ya activa (hecha hoy 2026-08-21) | Hecho |
| **E1** | Particionado mensual de `transaction`/`itemSold` + `companyId` en `itemSold` + índice compuesto (D3+D4) | **Ahora.** No hay umbral que esperar: cada fila que entra sin particionar hoy es una fila que hay que migrar con downtime mañana. Prod: 723/1.029 filas — ventana de costo mínimo | 1 migración SQL + auditoría de FKs a dropear, sin downtime real a este volumen (horas de trabajo, 0 downtime) |
| **E1b** | Cierre de período (D7): tabla `period_close` + trigger de inmutabilidad + job `period-close` | Junto con E1 — es la pieza que convierte "partición vieja" en "base fría" de verdad (nadie escribe ahí). Sin activador de volumen: es una regla de negocio, no una respuesta a tamaño | Migración + trigger + job de maintenance (horas); requiere cerrar con el owner la pregunta de "qué es inmutable" antes de escribir el trigger |
| **E2** | RB-3: rollup de compras/producción/stock + retención del grano `day` de `item_sales` (D5) | Cuando el catálogo de reportes (`context/47`) necesite exponer Compras/Producción/Stock sobre rangos largos, o cuando una query en vivo de esos dominios pase p95 > 1-2s | Extensión del patrón existente (mismo helper de RB-1/RB-2), sin downtime |
| **E3** | Réplica de lectura en streaming (D6), reportes/exports apuntan ahí | Cualquiera medible: p95 de checkout (POST venta) degradado en horario pico por contención de I/O; o queries de reporting > 25-30% del `total_time` de `pg_stat_statements` de la instancia primaria | Setup de replicación (horas) + verificar soporte en Coolify (bloqueante, ver Riesgos); 0 downtime del lado de escritura |
| **E4** | Particiones de años viejos a tablespace barato o desprendidas (`DETACH PARTITION` + archivo) | Tamaño total del volumen Postgres administrado por Coolify se acerca al techo contratado, o particiones de > N años (ej. 3) con lectura casi nula medida por `pg_stat_user_tables` | Bajo — operación de partición nativa, sin downtime |
| **E5** | Warehouse columnar (ClickHouse o similar) vía CDC, encima de la réplica | Volumen o complejidad de cruces ad-hoc del BI (`context/47`) supera lo que Postgres particionado + réplica resuelve razonablemente — cientos de GB/TB con queries analíticas arbitrarias | Alto — proyecto aparte, pipeline CDC nuevo |

## Proyección numérica (estimación gruesa, no medición — supuestos explícitos)

Supuesto del owner: 10.000 transacciones/mes × 5 ítems/transacción por
tenant → 120.000 `transaction`/año y 600.000 `itemSold`/año por tenant.
Tamaño por fila (tabla + índices, estimado): `transaction` ≈ 800 B/fila
(9 índices), `itemSold` ≈ 500 B/fila (4 índices + el compuesto de D4).

| Horizonte | Tenants | `transaction` (filas / tamaño) | `itemSold` (filas / tamaño) | Total tablas de hechos |
|---|---|---|---|---|
| 1 año | 10 | 1,2 M / ~1 GB | 6 M / ~3 GB | ~4 GB |
| 1 año | 50 | 6 M / ~5 GB | 30 M / ~15 GB | ~20 GB |
| 1 año | 200 | 24 M / ~19 GB | 120 M / ~60 GB | ~79 GB |
| 3 años | 10 | 3,6 M / ~3 GB | 18 M / ~9 GB | ~12 GB |
| 3 años | 50 | 18 M / ~14 GB | 90 M / ~45 GB | ~59 GB |
| 3 años | 200 | 72 M / ~58 GB | 360 M / ~180 GB | ~238 GB |
| 5 años | 10 | 6 M / ~5 GB | 30 M / ~15 GB | ~20 GB |
| 5 años | 50 | 30 M / ~24 GB | 150 M / ~75 GB | ~99 GB |
| 5 años | 200 | 120 M / ~96 GB | 600 M / ~300 GB | ~396 GB |

**El rollup no crece así.** A grano mes/año (el que sirve reportes por
período), las filas de `report_rollup` escalan con
`tenants × sucursales × meses × dominios` (dominios sin dimensión de
entidad: sales/expenses/returns/payments) más
`tenants × sucursales × meses × ítems del catálogo` (solo `item_sales`,
que sí lleva dimensión ítem) — NO con transacciones. Con 200 tenants,
2 sucursales promedio (+1 fila consolidada = 3), 60 meses (5 años),
4 dominios simples y un catálogo promedio de 80 ítems activos:

- Dominios simples: 200 × 3 × 60 × 4 ≈ 144.000 filas.
- `item_sales` a grano mes: 200 × 3 × 60 × 80 ≈ 2,88 M filas.
- Total ≈ 3 M filas × ~400 B/fila ≈ **~1,2 GB** — contra ~396 GB de las
  tablas de hechos en el mismo escenario. Más chico incluso agregando el
  grano `día` reciente (retenido solo los últimos ~90 días, no acumulado
  para siempre — la retención de D5).

El catálogo de ítems (80 en el ejemplo) NO crece con el volumen de venta
de un tenant — un tenant que vende el doble no duplica su catálogo, vende
más de los mismos ítems. Por eso el rollup por ítem escala con
`tenants × meses × catálogo`, un techo mucho más bajo que
`tenants × meses × transacciones`.

## Qué medir desde ya

- **`pg_stat_statements`** — confirmar si está habilitado en la instancia
  managed de Coolify (`postgres:18-alpine`); si no, activarlo. Es la
  fuente para "% del tiempo de CPU/IO que consumen queries de reporting"
  (señal de E3) y para detectar qué queries en vivo de compras/producción/
  stock son las más caras (prioriza qué domain entra primero en E2).
- **Tamaño de tablas/índices** — `pg_total_relation_size('transaction')` y
  `pg_total_relation_size('itemSold')`, medido mensualmente, comparado
  contra esta proyección para saber si el crecimiento real diverge del
  supuesto (10k tx/mes/tenant).
- **p95 de checkout** (POST venta del POS) — vía GlitchTip
  (`monitor.actuo.app`, ya monitorea Punto —
  `reference_glitchtip_monitoring`) o logs de la API. Señal directa de
  contención escritura-vs-lectura (E3).
- **`rollup_dirty`: tamaño de la cola** — ya expuesto por el job de
  reconcile; un backlog creciente es señal de que el cron dejó de correr
  (mismo patrón de falla silenciosa que dejó 134 períodos pendientes sin
  que nadie lo notara) — vale una alerta, no solo una métrica pasiva.
- **Uso de disco del volumen Postgres en Coolify** vs. cupo contratado —
  input directo para la señal de E4.

## Riesgos y preguntas abiertas

- **Qué es "inmutable" bajo D7 (pregunta abierta para el owner).** El hecho
  económico (montos, ítems, fechas, cliente, numeración) vs. el estado de
  liquidación (`transactionComplete`) que legítimamente cambia después del
  cierre por cobros/NC posteriores — ver desarrollo completo en D7. Sin
  esta definición no se puede escribir el trigger de guard sin romper
  flujos reales (cobro de una factura vieja, NC contra venta vieja).
- **Auditoría de write-paths antes de dropear las FK a `transaction`
  (D3).** La decisión de sacar la FK enforced asume que las 10 tablas
  satélite se escriben siempre dentro de la misma transacción DB que el
  `INSERT` a `transaction`. No se auditó call-site por call-site en esta
  sesión — antes de aplicar la migración, verificar cada `Service` que
  escribe `itemSold`/`stock`/`toTransaction`/`toTaxObj`/`toAddress`/
  `comission`/`giftCardSold`/`printServer`/`satisfaction`/`vPayments`.
- **Soporte de réplica de lectura en Coolify** (E3, D6) — no se confirmó
  en esta sesión si el plan/infra actual permite levantar un segundo
  container Postgres en modo réplica sin trabajo de infra fuera de lo
  versionado, ni el costo. Bloqueante para E3 hasta confirmar.
- **Cron de particiones futuras como punto único de falla silenciosa** —
  mismo patrón que ya mordió a `rollup_dirty`/`einvoice-drain`
  (`context/06`): si `crond` dentro de la imagen del API deja de correr,
  no hay quien cree la partición del mes siguiente y un `INSERT` empieza a
  fallar duro. Necesita alerta explícita, no solo el margen de 3 meses.
- **Supuestos de la proyección numérica no validados contra tenants
  reales** — 2 sucursales y 80 ítems de catálogo promedio son estimaciones
  razonables, no medidas; cuando haya más de un tenant con volumen real,
  recalibrar contra datos reales en vez del supuesto.
- **Retención del grano `día` de `item_sales`** (mencionado en D5) — falta
  decidir la ventana exacta (90 días propuesto acá, no cerrado con el
  owner) y si podar significa `DELETE` o mover a una tabla de archivo
  separada.
- **E5 (warehouse columnar) sin decisión de producto** — depende de si
  `context/47` (catálogo de reportes) alguna vez necesita BI ad-hoc
  arbitrario más allá de datasets declarativos; no hay señal de que eso
  vaya a pasar todavía.
