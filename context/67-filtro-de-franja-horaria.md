# 67 — Filtro de franja horaria en reportes

> Estado: **F0, F1 y F3 IMPLEMENTADAS (F0/F1 2026-09-01, F3 2026-09-02);
> F2 (UI) pendiente.** D1 cerrada por el owner (el pedido se abre en dos casos, solo
> uno es feature). El diseño del helper y el alcance por reporte siguen siendo
> propuestas mías —marcadas **[?]**— pero ya están implementadas tal como se
> describen; el alcance efectivo de la F1 y sus exclusiones están más abajo.

## D1 — CERRADA: son dos casos, solo el Caso B es trabajo nuevo

Textual del owner: *"¿qué pasa si por ej. durante un período quiero ver
ventas dentro del día que se hicieron de 07:00 a 11:59? ¿O qué pasa si
quiero ver reportes de todos los días pero dentro de ese rango horario?"*

**Caso A — un rango con horas dentro de un día. YA FUNCIONA, hoy.**
`Date::reportRange()` (`api/lib/App/Helpers/Date.php:219-227`) delega en
`normalizeBound()` (`:237-245`), que solo completa la hora cuando el extremo
llega como fecha sola (`strlen($value) === 10`). Mandar
`from=2026-09-01 07:00:00&to=2026-09-01 11:59:59` a cualquiera de los ~24
endpoints migrados devuelve exactamente esa ventana. No hay nada que
construir para este caso.

**Caso B — la misma franja horaria repetida a lo largo de varios días. ES
la feature.** Un rango es un intervalo CONTINUO: del 1 al 30 de septiembre
de 07:00 a 11:59 con `from`/`to` incluye las 19 horas de cada noche del
medio, no las excluye. Para quedarse solo con la franja de cada día hace
falta la hora del día como **dimensión propia, independiente del rango de
fechas** — un filtro nuevo, no una forma distinta de mandar `from`/`to`.

Este documento es sobre el Caso B.

## Por qué importa (contexto de negocio)

Pedido común en retail y gastronomía: si el turno mañana rinde, si el
horario de almuerzo justifica personal extra, a qué hora conviene reponer
mercadería, si vale abrir temprano. Hoy el dueño no puede responderlo sin
exportar y filtrar a mano.

## Lo que YA existe — el plan construye menos de lo que parece

Hay agregación por hora en varios lugares, pero es **agregación, no
filtro**: dicen cuánto se vendió a cada hora, no dejan acotar el resto del
reporte a una franja.

- `api/v1/reports/sales.php:50-51` — `dataset=hours` → `SalesService::hours()`,
  "conteo de ventas por hora del día".
- `api/lib/Reports/SalesService.php:146` (bucket de `series()` cuando el
  rango es un solo día) y `:192` (`hours()`) — `EXTRACT(HOUR FROM
  transactionDate)::int` como bucket de la serie.
- `api/lib/Reports/DashboardService.php:254` (`topHours()`) — mismo patrón
  para un widget del dashboard.
- `api/lib/services/DrawerService.php:354` (`getHourlyStats()`) — ventas por
  hora del turno en el POS, y es el ÚNICO que documenta hacerlo con
  `date_trunc('hour', transactionDate AT TIME ZONE <tz del tenant>)`
  explícito.

**Hallazgo que ordena el plan — la zona horaria.** La hora de una venta
depende del huso con que se la mire. Verificado: `TenantClock::apply()`
(`api/lib/Support/TenantClock.php:101-111`) corre en el embudo de auth
(`data.php` y `apiAuthPosContext`) y hace `SET TIME ZONE '<tz>'` en la
sesión de Postgres además de `date_default_timezone_set()` en PHP. Eso
significa que `EXTRACT(HOUR FROM transactionDate)` en `SalesService` y
`DashboardService`, aunque no lleve `AT TIME ZONE` explícito, YA sale en
hora del comercio — la sesión ya está en esa zona cuando la query corre. El
`AT TIME ZONE` explícito de `DrawerService` es correcto igual, pero
redundante con la sesión ya seteada, no una corrección de un bug de las
otras dos. Un filtro de franja horaria nuevo puede apoyarse en la misma
sesión — no hace falta resolver el huso de nuevo — pero el diseño tiene que
declararlo así de explícito, porque un filtro que se equivoque de huso
devuelve datos plausibles y falsos: el peor resultado posible.

## El diseño a proponer [?]

**Dos parámetros nuevos** de hora del día (`hourFrom`/`hourTo`, a definir
nombre) que se suman al rango de fechas existente, resueltos en el mismo
lugar que ya centraliza `from`/`to` — `Date::reportRange()` y vecinos en
`api/lib/App/Helpers/Date.php` — para no repetir la lógica en 24 endpoints.
Es la misma lección del bug que motivó ese helper (ver su docblock,
`Date.php:36-46`): el patrón duplicado se corrige una vez ahí, no en cada
`api/v1/reports/*.php`.

Puntos que el plan tiene que resolver o declarar abiertos:

- **La franja que cruza medianoche.** Un bar que opera de 20:00 a 04:00 es
  el caso real. `hora >= 20 AND hora < 4` no devuelve nada leído
  ingenuamente — hay que invertir el predicado a `hora >= 20 OR hora < 4`
  cuando `hourFrom > hourTo`. Si en una primera fase no se soporta, la UI
  tiene que decirlo explícito (deshabilitar el segundo selector cuando cruza
  medianoche, o aceptarlo y documentar el OR) — nunca devolver vacío en
  silencio.
- **Dónde se aplica el predicado.** Cada servicio filtra sobre su propia
  columna de fecha (`transactionDate` en ventas/caja, `itemSoldDate` en
  productos, la que use cada `*Service`), así que el helper puede resolver
  el fragmento SQL pero cada query lo inserta en su propio `WHERE`.
  Evaluar si `Date` devuelve un fragmento parametrizado (ej.
  `hourPredicate(column, hourFrom, hourTo): [string, array]`) o si cada
  servicio arma su propio `EXTRACT(HOUR FROM $col)` a partir de dos enteros
  validados por el helper — el costo de la primera opción es que un
  fragmento SQL armado fuera de la query que lo ejecuta es más fácil de usar
  mal (falta de paréntesis en un `WHERE` con `OR`, por ejemplo el caso de
  medianoche de arriba mezclado con el resto de las condiciones).
- **Qué reportes lo aceptan.** No todos tienen sentido: un balance
  (`context/60`) es una foto a una fecha, no un rango con horas. Proponer el
  subconjunto — candidatos naturales son los reportes `ranged: true` de
  `frontend/lib/agent/read-tools.ts:162-186` (ventas, transacciones,
  productos, órdenes, movimientos de caja) — y el criterio para excluir el
  resto.
- **Índices.** Filtrar por `EXTRACT(HOUR ...)` no usa el índice de fecha
  existente (el que cubre `transactionDate` para el rango sí se usa para
  acotar por fecha, pero la franja horaria queda como filtro residual sobre
  esas filas). `context/48-escalamiento-de-datos.md` no menciona este caso
  puntual — documenta particionado por mes y réplica, no un índice funcional
  sobre `EXTRACT(HOUR ...)`. Con el volumen actual puede no importar, pero
  hay que declararlo: es el tipo de cosa que anda bien en demo y se cae con
  tres años de datos por tabla particionada.
- **La UI.** Hoy el rango se elige con
  `frontend/components/date-range-picker.tsx` (presets + calendario, sin
  noción de hora). Proponer cómo se suma la franja sin ensuciar el caso
  común — la enorme mayoría de las consultas no la va a usar, así que no
  puede aparecer como un control más al lado del selector de fechas por
  default. Candidato: control colapsado/opcional dentro del mismo popover,
  visible solo al expandir "más filtros".
- **El agente y el MCP.** Las tools de lectura
  (`frontend/lib/agent/read-tools.ts`) ganarían el parámetro — hoy cada tool
  con reporte `ranged` declara `from`/`to` como
  `z.string().optional()` (ej. líneas 557-559, 674-677, 740-742) en un
  patrón repetido por tool; sumar `hourFrom`/`hourTo` ahí es barato si el
  helper del backend ya resuelve el filtro, porque es agregar dos campos más
  al mismo `z.object` en cada una. Es probablemente el mejor consumidor de
  la feature: le permite al dueño preguntar "¿cómo me fue en el turno mañana
  este mes?" en lenguaje natural en vez de armar el filtro a mano.

## Fases [?]

| Fase | Qué | Depende de |
|---|---|---|
| **F0** | **IMPLEMENTADA 2026-09-01** — `Date::hourRange()` + `Date::isHourBound()` (`api/lib/App/Helpers/Date.php`): valida `HH:MM[:SS]`, invierte el predicado a `OR` cuando la franja cruza medianoche, devuelve `[sql, params, valid]` con el fragmento parentizado que arranca con ` AND ` (convención `Reports\Roc`), y sin franja devuelve vacío. Arnés: bloques C y D de `api/tests/report_date_range_test.php` (64/64) | — |
| **F1** | **IMPLEMENTADA 2026-09-01** — `hourFrom`/`hourTo` en 5 endpoints, vía el value object `Reports\HourBand` (extremos validados una vez en el endpoint; cada query pide su fragmento con `on($columna)`). Alcance y exclusiones abajo. Arnés: bloque E de `api/tests/report_date_range_test.php` (84/84) | F0 |
| **F2** | UI: control de franja horaria en el selector de fechas del panel | F1 |
| **F3** | **IMPLEMENTADA 2026-09-02** — `hourFrom`/`hourTo` en `get_transactions`, `get_top_products` y `get_report` (solo los 5 ids con `hourly: true` en `REPORT_ROUTES`; el resto se rechaza ANTES del fetch, igual que la franja sin `from`/`to`). La franja viaja también en el baseline de `compareWith`. El MCP la hereda del mismo catálogo, sin tocar `app/api/mcp/route.ts` | F1 (no depende de F2) |

## F1 — qué quedó adentro y qué afuera (para la F2)

El criterio es **si la pregunta "de 7 a 12" tiene sentido**: aplica a lo que
ocurre EN un instante (una venta, una orden, un egreso), no a una foto de
estado ni a un agregado que ya perdió la hora.

**Adentro — la F2 muestra el control en estas cinco pantallas:**

| Endpoint | Servicio / columna filtrada |
|---|---|
| `reports/sales` (los 4 datasets) | `SalesService` — `transactionDate`, más `b.itemSoldDate` en las gift cards y `NonAddingSales` (pagos e internas) |
| `reports/transactions` (`detail`/`cobros`/`quotes`) | `TransactionsService` — `transactionDate` |
| `reports/products` (`general`/`combos`/`detail`) | `ProductsService` — `b.transactionDate` (agregado) y `a.transactionDate` (detalle); el período ANTERIOR de la comparación lleva la misma franja |
| `reports/orders` | `OrdersService` — `pos_order.created_at` |
| `reports/expenses` | `ExpensesService` — `expensesDate` |

**Afuera, y por qué** (esto NO es trabajo pendiente: son exclusiones con
motivo, releerlas antes de "completar" la feature):

- **Fotos de estado** — `balance`, `stock`, `stock-day`, `open_invoices`,
  `giftcards`, `recurring`. No ocurren a una hora: son un saldo a una fecha.
  Ni siquiera aceptan rango.
- **`drawers` (arqueos).** El caso intermedio, y la decisión es NO. Un arqueo
  es un INTERVALO (apertura → cierre), no un instante: filtrar por la hora de
  apertura dejaría afuera el turno que abrió 06:50 y cubrió toda la mañana. Y
  hay algo peor que eso — `DrawersService::listMovements()` calcula los
  componentes de cada caja (`componentsFor()`) sobre el intervalo del PROPIO
  cajón, no sobre el rango del reporte: una fila filtrada "de 7 a 12" seguiría
  mostrando lo vendido en todo el turno. Sería un reporte plausible y falso,
  que es exactamente el modo de falla que la F0 quiso evitar. El turno ya ES la
  unidad de análisis de ese reporte.
- **Reportes servidos por rollup** — `brands`, `categories`, `summary_year`.
  `rollup_*_day` es grano DÍA (`RollupReader.php:11`): la hora individual ya no
  existe ahí. `payment-methods` es el caso más traicionero: es HÍBRIDO —el
  detalle sale live de `transaction` y el resumen del rollup—, así que con
  franja las dos mitades del MISMO payload discreparían. Si alguna vez se
  incluyen, hay que forzar la rama live, no filtrar el rollup. Cierra
  parcialmente la pregunta abierta de más abajo: los rollups NO sirven a este
  filtro.
- **Servicios que obligaban a contorsiones** (candidatos razonables, dejados
  para una fase posterior con el trabajo ya identificado):
  `CustomersService::dashboard()` bindea TRES rangos y dos de ellos van en el
  `SELECT`, no en el `WHERE`; `CashflowService::accountBalances()` bindea seis
  fechas dentro de un `CASE WHEN`; `ProductionService::compound()` mete una
  cantidad VARIABLE de placeholders antes del rango. En los tres, insertar los
  params al final rompe el orden — necesitan inserción posicional, no
  `array_merge`. `vpayments` no es SQL: sale de un HTTP externo.

## F1 — la restricción que la F2 tiene que respetar en la UI

Varios reportes tienen ramas de filtro que **no acotan por rango de fechas**
(comportamiento previo, no introducido acá): buscar una transacción por texto,
por cliente o por documento, y los reportes de producto filtrados por cliente o
por artículo, devuelven el historial completo aunque el selector de fechas
muestre un rango.

En esas ramas la franja **no se aplica**, y los endpoints la **rechazan con
422** en vez de ignorarla en silencio. La razón no es estética: está medida.
`EXPLAIN ANALYZE` sobre 400k transacciones particionadas por mes:

| Caso | Plan | Tiempo |
|---|---|---|
| Rango de un mes, sin franja | `Index Scan` por `transactionDate` | 10,7 ms |
| Mismo rango, con franja 07:00-11:59 | **mismo** `Index Cond`, franja como `Filter` residual | 6,6 ms |
| Mismo rango, franja que cruza medianoche (`OR`) | **mismo** `Index Cond` | 6,4 ms |
| Rama SIN rango + `ORDER BY … LIMIT`, sin franja | `Index Scan Backward` con salida temprana | 3,7 ms |
| **La misma, CON franja** | **`Parallel Seq Scan` de todas las particiones** | **109 ms** |

Es decir: la premisa de la F0 se confirma —junto a un rango, la franja no toca
el plan y hasta lo mejora, y el `OR` de medianoche tampoco lo degrada— y su
CONTRATO también resultó real: sin rango, el predicado tira la salida temprana
del `LIMIT`. Por eso no se agregaron índices funcionales: no hacen falta para
el uso soportado, y agregarlos habilitaría el uso que no lo está.

**Para la F2**: cuando el usuario tenga activo un filtro por texto, cliente,
documento o artículo, el control de franja va deshabilitado (con el motivo a la
vista), no enviado-y-rechazado.

**Hallazgo colateral, no resuelto acá**: que esas ramas ignoren el rango de
fechas mientras el panel muestra un selector de fechas es confuso por sí solo,
franja aparte. Es un defecto anterior y arreglarlo cambia el comportamiento de
reportes en producción — no entra en el alcance de la F1, pero conviene
decidirlo antes de diseñar la UI de la F2.

## Preguntas abiertas — no las resuelvo

- Si la franja se guarda como preferencia del usuario o se elige en cada
  consulta.
- Si además del filtro conviene un modo "comparar franjas" (mañana contra
  tarde) — es la pregunta que el dueño probablemente quiere responder de
  fondo, más que un filtro suelto.
- Si los rollups (`rollup_sales_day`, grano DÍA — confirmado en
  `api/lib/Reports/RollupReader.php:11`) pueden servir a este filtro o lo
  obligan a ir siempre contra las tablas transaccionales, porque el grano
  diario ya perdió la hora individual de cada venta. Ver `context/52` y
  `context/48`.
- Nombre final de los parámetros (`hourFrom`/`hourTo` es un placeholder de
  este doc, no una decisión).

## Arquitecturas rechazadas — no reintroducir

- **Resolver la franja horaria por endpoint, cada uno con su propio
  `EXTRACT(HOUR ...)` inline.** Es exactamente el patrón que
  `Date::reportRange()` ya corrigió una vez para `from`/`to` (24 endpoints
  con el mismo regex duplicado) — repetir el error para la hora del día
  reintroduce el mismo costo de mantenimiento que motivó el helper.
- **Asumir que un `EXTRACT(HOUR FROM transactionDate)` sin `AT TIME ZONE`
  explícito está mal.** Ya sale correcto porque `TenantClock::apply()` fija
  la zona de la sesión de Postgres antes de que la query corra (ver
  hallazgo arriba) — "arreglarlo" agregando `AT TIME ZONE` a mano en cada
  query sería trabajo innecesario, no un fix.

## Docs relacionados

- `context/48-escalamiento-de-datos.md` — particionado e índices; no cubre
  el caso de un índice funcional sobre `EXTRACT(HOUR ...)`.
- `context/52-stock-ledger-unica-fuente.md` — mismo patrón de "el grano del
  rollup limita qué se puede reconstruir después".
- `context/59-asistente-en-la-caja.md` y `context/47-reportes-personalizados-y-export.md`
  — catálogo de tools de lectura que este plan extendería.
