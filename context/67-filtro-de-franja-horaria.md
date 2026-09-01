# 67 — Filtro de franja horaria en reportes

> Estado: **PLAN, sin implementar.** D1 cerrada por el owner (el pedido se
> abre en dos casos, solo uno es feature). El resto — diseño del helper,
> fases, alcance por reporte — son PROPUESTAS mías y necesitan su OK,
> marcadas **[?]**.

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
| **F0** | Helper: `hourFrom`/`hourTo` en `Date` (validación + resolución del predicado, incluida la franja que cruza medianoche) | — |
| **F1** | Cablear el predicado en los servicios de reportes `ranged: true` que se decida incluir (ver criterio arriba) | F0 |
| **F2** | UI: control de franja horaria en el selector de fechas del panel | F1 |
| **F3** | Parámetro en `read-tools.ts` para el agente/MCP | F1 (no depende de F2) |

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
