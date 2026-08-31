# Dashboard de Operaciones — órdenes, cocina y salón

> Estado: **plan sin implementar. D1-D9 son PROPUESTAS, sin OK del owner.**
> Pedido del owner (2026-08-31), textual: *"¿Tenemos suficiente info para crear
> un mapa de calor de espacios? No es urgente pero ¿sería posible, tipo un
> dashboard asociado a espacios + órdenes + pedidos? […] Ej: tiempos promedio
> de cocina, pedidos por días de la semana, ocupación por hora, etc."*
> El "no es urgente" es parte del encargo: nada de esto compite con lo que ya
> está en vuelo.

---

## LA ADVERTENCIA QUE ORDENA TODO EL PLAN

Todos los KPIs de cocina de este documento miden bien **solo si el personal
marca los estados mientras cocina**. Si en la práctica la orden salta de
"pendiente" a "entregado" al cerrar el servicio, los tiempos que salgan de acá
no son un promedio malo: son basura con apariencia de dato, y alguien va a
decidir dotación de cocina con ellos.

No es una hipótesis pesimista. **El salto está explícitamente permitido por la
máquina de estados**: `api/lib/Orders/OrderCoreService.php:82` declara
`'sent' => ['in_progress', 'ready', 'delivered', 'cancelled']`, o sea que una
orden puede ir de "enviada a cocina" a "entregada" en un solo paso. Y ese
`updateStatus` de orden **no cascadea a los ítems ni escribe ningún timestamp
de etapa** (`OrderCoreService.php:739-743`: el único `SET` de fecha es
`closed_at`, y solo para `cancelled`/`closed`). Una orden así queda con todos
sus ítems en `pending`, `ready_at` y `delivered_at` en NULL, y sin embargo
figura entregada.

Es el mismo problema que las coordenadas en el mapa de clientes, donde
terminamos declarando la cobertura en pantalla y gateando la lectura
(`frontend/components/domain/reports/customers/customers-geo-tab.tsx:14-27`).
La conclusión es idéntica y se aplica desde el día uno:

1. **La F0 de este plan es medir la calidad del dato**, no dibujar nada.
2. **El dashboard declara su cobertura arriba, con el número y el porcentaje**,
   igual que el tab geográfico (`customers-geo-tab.tsx:215-241`).
3. Por debajo del umbral el gráfico **se dibuja igual, con la advertencia
   encima** — no se esconde detrás de un clic. Esa corrección ya la pidió el
   owner una vez (`customers-geo-tab.tsx:282-288`) y no hay que repetirla.

Un tiempo promedio de cocina calculado sobre el 20% de las órdenes no es un
promedio: es una anécdota.

---

## Inventario de datos — qué hay REALMENTE

Verificado sobre el código, no sobre lo que los planes anteriores prometieron.
**Cuatro puntos del pedido inicial no se sostienen** y están marcados abajo.

### Órdenes — `pos_order`

`api/database/migrations/postgres/79_orders_core.sql:35-56` define la cabecera.
Columnas de tiempo: `created_at` (default `now()`), `sent_at`, `closed_at`.

- `sent_at` se escribe en el INSERT si la orden nace enviada
  (`OrderCoreService.php:409-414`) o en `send()` (`OrderCoreService.php:629-630`).
- `closed_at` solo en `cancelled`/`closed` (`OrderCoreService.php:740`).

> **NO SE SOSTIENE (1): `pos_order` no tiene `ready_at` ni `delivered_at`.**
> El brief del pedido asumía cinco marcas de etapa en la orden; hay tres. El
> "cuándo estuvo lista" y el "cuándo se entregó" de una ORDEN no existen como
> columna: hay que derivarlos de sus ítems o del log de eventos. Cualquier
> query que escriba `o.ready_at` falla en runtime, no en build.

Dimensiones útiles ya presentes: `outletid`, `registerid`, `userid`,
`source` (`counter|table|ecommerce|schedule`), `spacesessionid`, `customerid`,
y `fulfillment` (`dine_in|takeaway|delivery`) agregada en
`94_order_fulfillment.sql:26,35` con índice
`idx_pos_order_company_fulfillment` (`94_order_fulfillment.sql:45-46`).

> **NO SE SOSTIENE (2): `pos_order.saletransactionid` fue DROPEADA.**
> `115_transaction_link.sql:230` la elimina; el vínculo orden↔venta vive en
> `order_transaction_link` (`115_transaction_link.sql:45-54`). Todo KPI que
> cruce órdenes con plata pasa por ahí, no por un puntero en la cabecera.

### Ítems — `pos_order_item`

`79_orders_core.sql:61-78`: `status` (`pending|preparing|ready|delivered|cancelled`),
`stationid`, `course`, `created_at`, `ready_at`, `delivered_at`. Rango de
estados en `OrderCoreService.php:54-59`.

Los timestamps **espejan** el status incluso al retroceder
(`OrderCoreService.php:1066-1078`): volver un ítem a `preparing` o `pending`
hace `ready_at = NULL`. La decisión está bien argumentada (un ítem que se
re-prepara no está listo) y tiene una consecuencia directa para este plan:

> **NO SE SOSTIENE (3): no existe un timestamp de "empezó a prepararse".**
> `preparing` no escribe ninguna columna. El tiempo de COLA (sent → alguien la
> tomó) y el tiempo de COCCIÓN real (preparing → ready) **no salen de las
> columnas**: salen de `pos_order_event`. Las columnas solo dan el compuesto
> `sent_at → ready_at`, que mezcla cola y cocción en un número.

### El log de eventos — `pos_order_event`

`85_order_events.sql:28-45`. Es la pieza más valiosa del inventario y su
docblock ya anticipa exactamente este dashboard
(`85_order_events.sql:6-12`): las columnas de timestamp son *un cache del
último valor, no la historia*; pierden las repeticiones y el actor.

Trae `scope` (`order|item`), `from_status`, `to_status`, `actor_kind`
(`user|device|system`), `actor_id`, `actor_module` (`pos|kds|display|panel|print`),
`stationid` **snapshoteado** al momento del evento, `outletid` denormalizado a
propósito para queries analíticas sin JOIN, `reason`
(`89_order_event_reason.sql:15-16`) y `created_at`.

Punto único de escritura: `OrderCoreService::recordEvent()`
(`OrderCoreService.php:1429-1490`), en la MISMA transacción que el cambio de
status (invariante §C.4, `85_order_events.sql:21-25`).

Índices ya pensados para esto: `idx_pos_order_event_outlet`
(`85_order_events.sql:49`) y `idx_pos_order_event_station`
(`85_order_events.sql:51`), este último comentado literalmente como
*"Análisis de cuellos de botella por estación (F-SLA-2, p75 sent→ready)"*.

Hay backfill histórico (`86_order_events_backfill.sql`) sin `reason` — filas
históricas reconstruidas, no observadas. Cuentan para volumen, no para calidad.

### Estaciones — `order_station`

`79_orders_core.sql:22-31` + `api/lib/Orders/StationService.php:6-11`. Ruteo
por `categoryids` con comodín `[]` = atiende todo. `stationid` puede ser NULL
en un ítem si ninguna estación matcheó: **eso es un balde "sin estación" que el
dashboard tiene que mostrar, no esconder** — si crece, el ruteo está mal
configurado y ese es el hallazgo.

### Salón — `space`, `space_sector`, `space_session`

`80_mesas_module.sql:45-70` define `space`: `sectorid`, `shape`
(`square|round|rect|bar|decor_wall|decor_plant`), `posx`, `posy`, `width`,
`height`, `rotation`, `status`. Confirmado también en
`api/lib/Spaces/SpaceService.php:292-318`.

**Esto es lo que hace interesante al pedido.** El "mapa de calor de espacios"
**no es un mapa geográfico**: es el **plano real del salón**, el mismo layout
que el mozo ve en el POS, con cada mesa pintada según su uso. No hay que
inventar una proyección ni interpolar densidad como en el mapa de clientes —
las coordenadas son de diseño, no de campo, así que su cobertura es del 100%
por construcción. Es la idea central del tab de salón y la única parte del
dashboard cuyo dato es confiable sin F0.

Detalle que hay que respetar: `decor_wall` y `decor_plant` son decoración
(`80_mesas_module.sql:59-60`). Se dibujan como contexto del plano y **se
excluyen de todo cálculo** — una pared no tiene ocupación.

`space_session` (`80_mesas_module.sql:75-89`): `status`
(`open|bill_requested|closed|cancelled`), `guests` (nullable), `waiterid`,
`opened_at` (default `now()`), `closed_at`, `saletransactionid`, `note`.
Más `alias` y `mergedinto` (`163_space_session_alias_and_merge.sql:22,60-64`).
Escrituras en `api/lib/Spaces/SpaceSessionService.php:87-90` (open),
`:190-191` (cancel), `:298-299` (close), `:612-624` (fusión).

Índice único parcial `uq_space_session_active_per_space`
(`80_mesas_module.sql:95-97`): una sola sesión activa por espacio. Es la
garantía de que "ocupación" es una pregunta bien definida.

`mergedinto` importa para no mentir: una sesión fusionada queda `closed` con
`saletransactionid` NULL y cero órdenes (`163_…:30-40`). **Contarla como una
ocupación más infla la rotación** — hay que excluirla o contarla aparte.

Servicios que ya existen y no hay que duplicar:
`SpaceBalanceService` — LA definición de "cuánto debe un espacio"
(`api/lib/Spaces/SpaceBalanceService.php:6-33`), `SpaceSettlementService`
(split de cuenta, ledger `space_session_payment`,
`api/lib/Spaces/SpaceSettlementService.php:9-23`) y `SpaceSectorService`.
El consumo por mesa se pide a `SpaceBalanceService`, no se recalcula.

### Rollups existentes

`41_report_rollup.sql:5-30` (`report_rollup` genérico + `rollup_dirty`) y
`160_rollup_daily_grain.sql:254-275` (`rollup_sales_day`, tipada y ancha en
dimensiones: `day`, `outletid`, `registerid`, `userid`, `kind`, `status`,
`channel`).

> **NO SE SOSTIENE (4): el rollup existente NO puede responder "por hora".**
> El grano de `rollup_sales_day` es **día** — mes y año se derivan con SUM
> (`160_rollup_daily_grain.sql:6-9`). "Ocupación por hora" y "pedidos por
> franja horaria" no salen de ahí. O se leen en vivo, o necesitan un rollup
> propio con grano hora (D5).

Además `transaction.channel` tiene CHECK `('mostrador','mesa','delivery')`
(`160_rollup_daily_grain.sql:207-216`): **el retiro en mostrador está plegado
dentro de `mostrador`**. El mix mostrador/retiro/envío que pidió el owner sale
de `pos_order.fulfillment`, no del rollup de ventas.

`channel` lo escribe `OrderCoreService::markPaid()`
(`OrderCoreService.php:928-959`), congelado al vincular la orden con la venta.

---

## Decisiones

### D1 [?] — Reporte propio en `/reports/operaciones`, con tabs

**Propuesta: reporte nuevo, no un tab de algo existente.**

`/reports/orders` **ya existe y es otro dominio**: renderiza `OrdersList` sobre
el motor ERP legacy, `transaction` type=12
(`frontend/app/(panel)/reports/orders/page.tsx:1-5`,
`api/v1/reports/orders.php:3-5`). Nada que ver con `pos_order`. Meter el
dashboard operativo ahí sería fusionar dos cosas que solo comparten el
sustantivo "orden" — y confundiría a quien busque los pedidos web.

Patrón a seguir: `/reports/customers`
(`frontend/app/(panel)/reports/customers/page.tsx:3-19`), que es exactamente
esta forma — tres tabs sobre un endpoint, cada sección pedida por separado con
`?include=…`, y la sección cara (`geo`) disparada solo al abrir su tab con
`enabled` (`customers/page.tsx:57-62`).

Tabs propuestos:

| Tab | Pregunta | Fuente principal |
|---|---|---|
| Cocina | ¿Qué traba la producción? | `pos_order_event` + `pos_order_item` |
| Demanda | ¿Cuándo y cómo me piden? | `pos_order` + `order_transaction_link` |
| Salón | ¿Cómo se usa el local? | `space` + `space_session` |

Nombre en la landing: **"Operaciones"**, en el grupo *"Operaciones y equipo"*
que ya existe con dos items (`frontend/app/(panel)/reports/page.tsx:68-76`).

### D2 [?] — Endpoint único `GET /v1/reports/operations` con `include`

**Propuesta: uno solo, seccionado.** Copiar literalmente el contrato de
`api/v1/reports/customers.php:41-54`: whitelist explícita de secciones, sin
`include` devuelve la sección barata, `include` nunca se usa para resolver un
método ni se concatena a SQL. Secciones: `kitchen`, `demand`, `room`,
`coverage`.

Tres endpoints separados serían tres archivos con el mismo preámbulo de fechas,
ROC y permisos. Uno con `include` es el patrón vigente del proyecto.

### D3 [?] — Alcance: sucursal por ROC, fecha por rango, **el plano NO**

**Propuesta:**

- **Sucursal**: `Roc::build(COMPANY_ID, OUTLET_ID)` como todos los reportes
  (`api/lib/Reports/Roc.php:39-51`), que ya respeta `VIEW_OUTLET_ID` del
  dropdown del logo (`Roc.php:33-47`). `pos_order_event.outletid` está
  denormalizado justo para esto (`85_order_events.sql:14-16`).
- **Fecha**: rango estándar con `DateRangePicker`, aplicado a Cocina y Demanda.
- **El plano del salón NO depende del rango.** El layout es la configuración
  actual del local, no un histórico: si el rango es de marzo y desde entonces
  se movieron las mesas, el plano que se dibuja es el de hoy. Lo que sí depende
  del rango es el **color** de cada mesa (su uso en el período).

Esa distinción **se dice en pantalla**, por la misma razón que se dijo en el tab
geográfico: el selector de fechas está a la vista y sería razonable suponer lo
contrario (`customers-geo-tab.tsx:236-241`, `customers/page.tsx:16-18`).

### D4 [?] — Permiso propio `reports.operations.view`

**Propuesta: clave nueva en el catálogo**, no reusar `reports.sales.view`.

El catálogo vive en `api/lib/Auth/PermissionCatalog.php:95-103` (grupo
"Reportes") y el rol se siembra en `api/lib/Auth/RoleService.php:87-89`.

Reusar `reports.sales.view` mezclaría "puede ver la facturación" con "puede ver
cuánto tarda la cocina" — son dos audiencias distintas: un jefe de cocina o un
encargado de salón necesita lo segundo y no debería obtener lo primero de
regalo.

**Y hay que enforcearlo.** Hoy `api/v1/reports/customers.php:25` y
`api/v1/reports/orders.php:15` autentican realm pero **no llaman a
`hasPermission()`** — a diferencia de `audit.php:20`, `drawers.php:21`,
`expenses.php:29` y el resto. El endpoint nuevo no repite esa omisión.

### D5 [?] — En vivo primero; rollup solo si la medición lo pide

**Propuesta: F1 se calcula en vivo. El rollup no se construye por las dudas.**

La regla del proyecto es que los reportes históricos salen de tablas
pre-agregadas (`context/18-reports-rollup-plan.md`,
`context/48-escalamiento-de-datos.md` D8), y este plan no la contradice — la
aplica con evidencia:

- `rollup_sales_day` **no sirve** para esto: grano día, sin hora, sin estación,
  sin estado de ítem (`160_rollup_daily_grain.sql:254-275`).
- `pos_order_event` ya tiene los tres índices que estas queries necesitan
  (`85_order_events.sql:47-51`), y el de estación fue creado explícitamente
  para el p75 `sent→ready`.
- El volumen de eventos de un mes de un local es órdenes de magnitud menor que
  el de `itemsold`.

Construir un `rollup_kitchen_hour` **antes** de saber si el dato existe (F0) y
si la query en vivo es lenta sería pre-agregar basura. La decisión de rollup se
toma en F4, con números de la F1 en la mano, y si se toma se hace con el patrón
`rollup_dirty` + `rollup_recompute_period` que ya existe
(`41_report_rollup.sql:32-38,39-47`).

**Excepción anticipada:** el particionado de `48-escalamiento-de-datos.md` (E1,
mig 156) no cubre `pos_order_event`. Si el dashboard se vuelve de uso diario
sobre rangos largos, el rollup deja de ser opcional.

### D6 [?] — Gating por módulo: el dashboard se degrada, no desaparece

**Propuesta: cada tab se gatea por su módulo; el reporte entero se oculta solo
si no hay ninguno.**

Los módulos son `ordersPanel`, `tables` y `kds`, del allowlist
`api/lib/Modules/ModulesService.php:48`, leídos con `useModules()`
(`frontend/hooks/use-modules.ts:11-17`) y gateados con `moduleEnabled(...)`
como ya hace el sidebar del POS
(`frontend/components/layout/pos-sidebar.tsx:105-106`).

- Sin `tables` → **el tab Salón no se monta**. Un comercio que no usa mesas no
  tiene que ver un plano vacío ni una pestaña muerta.
- Sin `ordersPanel`/`kds` → no se montan Cocina ni Demanda.
- Sin ninguno de los tres → el item no aparece en la landing de `/reports`.

Ojo con el default: `pos-sidebar.tsx:105` usa `!== false`, o sea que mientras
carga o falla asume habilitado. En un dashboard ese default es correcto también
(mejor una pestaña de más un instante que un parpadeo de contenido).

### D7 [?] — La cobertura es una sección del endpoint, no un cálculo del front

**Propuesta:** `include=coverage` devuelve el mismo shape que
`CustomersService::geography` devuelve en `cobertura`
(`customers-geo-tab.tsx:163-166`), y el front solo lo pinta.

Motivo: la cobertura es una propiedad del dato, y calcularla en el cliente
obligaría a bajar las órdenes crudas — justo lo que el `include` evita.

Umbral propuesto: **`COBERTURA_MINIMA_COCINA = 60%`** de órdenes con el ciclo
completo. Más alto que el 30% del mapa de clientes
(`customers-geo-tab.tsx:52-57`) a propósito: una coordenada faltante recorta la
muestra, un timestamp faltante la **sesga sistemáticamente** — las órdenes que
nadie marcó son justamente las de las horas de mayor presión, que son las que
importan.

### D8 [?] — El plano del salón se pinta con el mismo motor que el POS

**Propuesta: reusar el componente de layout de espacios del POS**, en modo
solo-lectura, con una escala de color por métrica.

Redibujar el plano en el panel con otro componente garantiza que en seis meses
el salón se vea distinto en las dos pantallas. El layout ya tiene una sola
definición (`SpaceService.php:292-318`) y el POS ya lo renderiza.

Métrica seleccionable, mesa por mesa: ocupación %, rotación, consumo promedio,
duración promedio, tiempo muerto.

### D9 [?] — El re-trabajo se muestra como KPI de primera línea

**Propuesta: sí, y con nombre en castellano de negocio ("platos rehechos").**

Es el KPI que casi ningún POS muestra y el único que sale gratis: el retroceso
`ready → preparing` ya queda registrado y el docblock lo dice explícito —
*"El re-trabajo NO se esconde"* (`OrderCoreService.php:41-44`). El ciclo es
bidireccional por diseño desde el recall del KDS
(`OrderCoreService.php:30-40`), y el front lo ejerce
(`frontend/app/(screen)/kds/page.tsx:512-516`).

Riesgo asumido y que hay que decir en pantalla: **`preparing → pending` es
"deshacer", el error más común de quien opera** (`OrderCoreService.php:34-36`),
no un plato rehecho. Solo `ready → preparing` cuenta como re-trabajo. Mezclarlos
convierte un indicador de calidad en un contador de dedazos.

---

## KPIs propuestos

Cada uno con **de qué campos sale** y **qué decisión habilita**. Los que se
descartaron están al final, con el motivo.

### Bloque Cocina

| KPI | Sale de | Decide |
|---|---|---|
| Tiempo de preparación, **p90** por estación | `pos_order_event` (`to_status='ready'`) − `pos_order.sent_at`, agrupado por `stationid` | Dónde falta gente o equipamiento |
| Tiempo de preparación, **p90** por ítem | ídem, agrupado por `pos_order_item.itemid`/`name` | Qué plato traba la cocina |
| Tiempo de cola (sent → tomada) | dos filas de `pos_order_event`: `to_status='preparing'` − `to_status='sent'` | Si el problema es capacidad o arranque |
| Tiempo de entrega (ready → delivered) | `pos_order_item.ready_at` → `delivered_at` | Si la comida se enfría esperando mozo |
| Tasa de re-trabajo | `pos_order_event` con `from_status='ready' AND to_status='preparing'` sobre ítems totales | Calidad real de la salida |
| Ítems sin estación | `pos_order_item.stationid IS NULL` | Ruteo de categorías mal configurado |

**Por qué el p90 y no el promedio.** Lo que duele en un servicio no es la media,
es la cola lenta. Con 100 platos a 8 minutos y 10 a 40, el promedio da 11
minutos y suena bien; el p90 dice 40 y describe exactamente lo que vivieron los
diez clientes que se quejaron. El promedio se deja como referencia secundaria
porque es lo que la gente espera ver, nunca como el número grande. El índice
`idx_pos_order_event_station` ya fue creado pensando en un percentil
(`85_order_events.sql:50-51`), así que esto es seguir la intención original.

Se muestran **mediana, p90 y n** juntos. Un p90 sobre 7 órdenes no es un p90.

### Bloque Demanda

| KPI | Sale de | Decide |
|---|---|---|
| Pedidos por día de semana | `pos_order.created_at` | Descanso semanal, promos de día flojo |
| Pedidos por hora | `pos_order.created_at`, franjas de 1h | Turnos y horario de apertura |
| Mapa día × hora | las dos anteriores cruzadas | Dotación por franja, la lectura de un vistazo |
| Ticket promedio por franja | `order_transaction_link` → `transaction` (`115_transaction_link.sql:45-54`) | Dónde conviene empujar venta |
| Mix `dine_in`/`takeaway`/`delivery` | `pos_order.fulfillment` (`94_order_fulfillment.sql:26`) | Inversión en salón vs. reparto |
| Mix por `source` | `pos_order.source` (`79_orders_core.sql:40-41`) | De dónde viene la demanda |
| Tasa de cancelación + motivos | `pos_order_event` con `to_status='cancelled'` + `reason` (`89_order_event_reason.sql:15`) | Qué se pierde y por qué |

El mix de fulfillment **no** puede salir de `rollup_sales_day.channel`: ahí
`takeaway` está plegado en `mostrador`
(`160_rollup_daily_grain.sql:207-216`).

### Bloque Salón

| KPI | Sale de | Decide |
|---|---|---|
| **Plano de calor** por ocupación | `space.posx/posy/width/height/rotation/shape` + `space_session` | Qué zonas del local no se usan |
| Ocupación por hora | solapamiento de `[opened_at, closed_at]` con cada franja | Cuándo hace falta más mozos |
| Duración promedio de sesión | `closed_at − opened_at`, excluyendo `mergedinto IS NOT NULL` | Si la mesa rota o se estanca |
| Rotación (sesiones/mesa/día) | conteo por `tableid` y día | Si hacen falta más mesas o menos |
| Consumo promedio por mesa | `SpaceBalanceService` (`SpaceBalanceService.php:24-33`) | Qué zonas valen más |
| Tiempo muerto entre sesiones | `opened_at` − `closed_at` de la sesión anterior del mismo `tableid` (`LAG`) | Cuánto se pierde limpiando/asignando |
| Ocupación por sector | `space.sectorid` (`80_mesas_module.sql:56`) | Terraza vs. salón vs. barra |
| Consumo por comensal | consumo / `space_session.guests` | Sugerencias, tamaño de porción |

**El plano de calor es la pieza central.** No es un mapa: es el layout real del
salón, el mismo que ve el mozo, con cada mesa pintada por su métrica. Se lee sin
leyenda — el dueño reconoce su propio local y ve la esquina que nadie usa.

Dos advertencias que van en pantalla, no en el código:

- **`guests` es nullable** (`80_mesas_module.sql:82`) y opcional al abrir
  (`SpaceSessionService.php:63`). El consumo por comensal tiene **su propia
  cobertura** y se declara igual que la de cocina, o no se muestra.
- **Las sesiones fusionadas no son ocupaciones.** `mergedinto` existe justo
  para distinguirlas de un cierre normal (`163_…:30-40`). Se excluyen de
  rotación y duración.

### KPIs descartados, con motivo

- **"Tiempo promedio de cocina" como número único del dashboard** — descartado.
  Sin abrir por estación no dice nada accionable, y como promedio esconde la
  cola. Vive adentro del bloque Cocina, abierto y con p90.
- **Tiempo `ready → delivered` para `dine_in` sin pantalla de despacho** —
  descartado como headline. El `delivered` de ítem lo marca la pantalla de
  despacho (`frontend/app/(screen)/display/page.tsx:161`); sin el módulo `cds`
  activo esa marca casi no se escribe y el KPI mide la ausencia de una pantalla,
  no la velocidad del mozo. Se muestra solo con `cds` activo.
- **Cualquier KPI basado en un `ready_at`/`delivered_at` de ORDEN** —
  descartado: las columnas no existen (`79_orders_core.sql:35-56`).
- **Ocupación en tiempo real ("qué mesas están ocupadas ahora")** — descartado
  de este dashboard. Ya es la pantalla de espacios del POS, y un reporte no es
  el lugar para operar. Este dashboard mira hacia atrás.
- **Ranking de mozos por velocidad** — descartado. El dato existe
  (`space_session.waiterid`, `pos_order_event.actor_id`) y es precisamente por
  eso que hay que decidirlo aparte: un tablero de productividad individual es
  una decisión de gestión de personas, no un subproducto de un reporte
  operativo. Si el owner lo quiere, se planifica con su propio permiso.
- **Predicción de demanda / sugerencia de dotación** — descartado de este plan.
  Es otro producto, y encima construido sobre el dato que la F0 todavía no
  validó.

---

## Fases

### F0 — Medir la calidad del dato (BLOQUEA a todas las demás)

Una sola query de diagnóstico contra producción, sin UI y sin endpoint. Por
tenant con órdenes en los últimos 90 días:

1. % de órdenes que llegaron a `delivered`/`closed` **con al menos un ítem con
   `ready_at` no nulo**.
2. % de órdenes que pasaron por `preparing` en `pos_order_event` (o sea, que
   alguien tocó el KDS) contra las que saltaron directo.
3. Distribución de `sent_at → ready_at` (mediana, p90, máximo): un p90 de 4
   horas delata marcados en batch al cierre del servicio, no cocina lenta.
4. % de ítems con `stationid IS NULL`.
5. Para salón: % de `space_session` con `guests` cargado, y % cerradas con
   `closed_at` (contra las que quedaron abiertas para siempre).

**Salida: un número por tenant, no un promedio global.** Un local que marca
bien y veinte que no dan un promedio que no describe a ninguno.

**Si el (2) da bajo de forma generalizada, el plan cambia de forma**: la
prioridad deja de ser el dashboard y pasa a ser que el KDS se use, o que el
POS marque `preparing` automáticamente al imprimir la comanda. Un dashboard
sobre dato inexistente no es un entregable, es una trampa.

- **Depende de**: nada.
- **NO verificado al terminar**: si el dato es malo, si es por falta de
  hábito o por falta de pantalla (un local sin KDS físico no tiene cómo marcar);
  eso lo contesta el owner, no la query.

### F1 — Endpoint + tab Salón + sección de cobertura

Primero el salón porque **su dato es confiable sin depender de F0**: las
coordenadas son de configuración y `opened_at`/`closed_at` los escribe el
sistema, no una persona.

- `GET /v1/reports/operations?include=room,coverage`, patrón de
  `api/v1/reports/customers.php:41-54`, con `hasPermission('reports.operations.view')`
  (D4) y `Roc::build`.
- Clave nueva en `PermissionCatalog.php:95-103` + siembra en
  `RoleService.php:87-89`.
- Página `/reports/operaciones` con tabs, patrón `customers/page.tsx:44-62`.
- Plano de calor solo-lectura (D8), decoración excluida, fusionadas excluidas.
- Banda de cobertura arriba, patrón `customers-geo-tab.tsx:215-241`.
- Alta en la landing, grupo "Operaciones y equipo"
  (`frontend/app/(panel)/reports/page.tsx:68-76`).

- **Depende de**: F0 (para saber qué advertir), D1-D4, D6-D8.
- **NO verificado al terminar**: performance del plano con un salón de 200+
  espacios; si la escala de color se lee bien con pocas mesas; si un salón
  reconfigurado a mitad del rango confunde (el plano es el de hoy, D3).

### F2 — Tab Cocina

- Percentiles por estación y por ítem sobre `pos_order_event`, con `n` visible.
- Tasa de re-trabajo contando **solo** `ready → preparing` (D9).
- Cobertura de cocina arriba, con el umbral de D7 y la advertencia si no llega.
- Balde "sin estación" visible.

- **Depende de**: F0 (sin el número de cobertura este tab no se publica), F1.
- **NO verificado al terminar**: si la query de percentiles aguanta rangos
  largos sin rollup (D5); si los eventos del backfill de la mig 86 ensucian los
  percentiles históricos y hay que cortar por fecha de la mig.

### F3 — Tab Demanda

- Día de semana, hora y el cruce día×hora.
- Ticket promedio por franja vía `order_transaction_link`.
- Mix de `fulfillment` y de `source`; cancelaciones con motivo.
- Todo en la **timezone del tenant**, nunca una fija: "por hora" con el TZ
  equivocado corre el pico del almuerzo y el reporte queda inservible.

- **Depende de**: F1 (endpoint y página ya montados).
- **NO verificado al terminar**: si el pico de la noche cruza la medianoche y
  parte el día en dos (un bar que cierra a las 3 AM); si conviene un "día
  comercial" configurable en vez del día calendario.

### F4 — Decisión de rollup, con números

Recién acá se mide la query en vivo sobre el tenant con más volumen y se decide
si hace falta `rollup_ops_hour`. Si hace falta, se construye con el patrón
`rollup_dirty` + `rollup_recompute_period` (`41_report_rollup.sql:32-47`).

- **Depende de**: F2 y F3 en producción con uso real.
- **NO verificado al terminar**: cómo se reconcilia un rollup de eventos cuando
  un ítem retrocede y vuelve a avanzar (el mismo ítem produce varias
  transiciones `ready` en el mismo día).

### F5 — Export y, si el owner lo pide, alertas

Export XLSX con el `<DataTable>` reusable, según la convención de listados.
Alertas del tipo "el p90 de la barra superó X" quedan **fuera de este plan**:
son un producto de notificaciones (`context/31-centro-de-notificaciones.md`),
no un reporte.

- **Depende de**: F2, F3.
- **NO verificado al terminar**: nada, es el cierre.

---

## Arquitecturas RECHAZADAS

Leer antes de proponer nada.

### 1. Agregar `ready_at`/`delivered_at` a `pos_order`

Sería la respuesta obvia al hallazgo (1) y es la equivocada. `pos_order.status`
es una **función de sus ítems** (`OrderCoreService.php:1298-1330`), y un
`ready_at` de cabecera sería un cache derivado más que mantener sincronizado en
cada retroceso de ítem — exactamente lo que `85_order_events.sql:6-12` explica
que ya salió mal. El "cuándo estuvo lista" se deriva de `MAX(ready_at)` de sus
ítems o del evento correspondiente. Menos columnas, una sola verdad.

### 2. Un rollup de cocina antes de la F0

Pre-agregar tiempos que nadie marcó produce una tabla rápida llena de nada, y
peor: le da al número una solidez institucional que no tiene. El orden es medir,
mostrar con su cobertura, y recién entonces agregar (D5).

### 3. Meterlo como tab de `/reports/orders`

`/reports/orders` es el motor ERP legacy sobre `transaction` type=12
(`api/v1/reports/orders.php:3-5`), un dominio distinto que
`79_orders_core.sql:5-9` declara explícitamente separado y que no se toca. La
colisión es de nombre, no de datos.

### 4. Interpolar densidad sobre el plano como en el mapa de clientes

El mapa de clientes interpola porque los puntos son muestras dispersas de un
continuo geográfico. El salón **no es un continuo**: son N mesas discretas con
posición de diseño. Cada mesa se pinta con su propio valor. Difuminar entre
mesas inventaría "zonas calientes" en el pasillo.

### 5. Calcular la cobertura en el front

Obligaría a bajar las órdenes crudas al browser para contar timestamps nulos —
el costo que el `include` de `customers.php:41-54` existe para evitar. La
cobertura es una propiedad del dato y se calcula donde el dato vive (D7).

### 6. Un dashboard "en vivo" de operaciones

Ya existe: es el KDS (`frontend/app/(screen)/kds/page.tsx`) y la pantalla de
espacios del POS. Duplicar el ahora en un reporte crea dos fuentes para la
misma pregunta y una de las dos va a estar desactualizada. Este dashboard mira
hacia atrás, a propósito.

---

## Riesgos

1. **El dato no existe y el plan igual se construye.** Mitigación: F0 bloquea, y
   su resultado puede cancelar F2 entera. Está escrito arriba para que no se
   negocie después.
2. **La cobertura se vuelve letra chica.** Ya pasó una vez y el owner lo corrigió
   (`customers-geo-tab.tsx:282-288`). El umbral y la advertencia son parte del
   entregable de F1/F2, no un extra.
3. **Timezone.** "Por hora" con el TZ equivocado corre el pico y arruina el KPI
   más pedido. No hardcodear nada: sale del bootstrap del tenant.
4. **`pos_order_event` crece sin particionado.** El particionado de
   `48-escalamiento-de-datos.md` (E1, mig 156) no lo cubre. Con uso diario sobre
   rangos largos, D5 deja de ser opcional.
5. **El p90 sobre pocas órdenes.** Un local chico va a ver percentiles que
   saltan solos. Por eso `n` va siempre al lado del número.
6. **El re-trabajo mal contado.** Si se cuenta `preparing → pending` como plato
   rehecho, el KPI mide dedazos del KDS (D9).

---

## Relacionados

- `context/24-orders-module-plan.md` — el módulo de órdenes que produce el dato.
- `context/27-delivery-sla-plan.md` PARTE C — el origen de `pos_order_event`;
  ahí ya se anticipó el p75 por estación.
- `context/15-espacios-module-plan.md` — espacios, layout, split de cuenta.
- `context/18-reports-rollup-plan.md` y `context/48-escalamiento-de-datos.md` —
  la regla de rollup que D5 acata.
- `context/47-reportes-personalizados-y-export.md` — el export genérico; este
  dashboard es un reporte fijo, no un reporte armable.
- `context/31-centro-de-notificaciones.md` — a donde irían las alertas (F5).
- `context/25-sucursales-y-scopes.md` — el view-scope que D3 usa.

---

## Lo que necesita OK del owner para arrancar

Nada de esto arranca sin tres respuestas:

1. **D1 + D4** — ¿reporte propio en `/reports/operaciones` con permiso propio
   `reports.operations.view`, o cuelga de un permiso existente?
2. **D6** — ¿el tab Salón desaparece para un comercio sin `tables`, o se muestra
   vacío invitando a activar el módulo?
3. **D9** — ¿el re-trabajo va como KPI de primera línea? Es el más honesto y el
   más incómodo: le dice al dueño cuántos platos se rehicieron.

Y una advertencia que no es una decisión: **si la F0 muestra que nadie marca los
estados, la F2 no se construye.** Se construye lo que haga que se marquen.
