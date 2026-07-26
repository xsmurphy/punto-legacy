# 27 — SLA de tiempo por orden + Delivery (O4)

> Planificado 2026-07-19 con el owner. Dos módulos que llegan juntos pero son
> **independientes**: el SLA de tiempo es transversal (KDS, `/pos/ordenes`,
> espacios, display, producción) y NO depende de delivery; delivery lo
> consume. Se pueden ejecutar en ese orden.

---

# PARTE A — SLA de tiempo por orden

## A.1 El problema del modelo de duración

El owner planteó el punto correcto: **sumar tiempos por producto miente**.
Si una hamburguesa tarda 12 min y una ensalada 5, la orden no tarda 17 —
se hacen en paralelo, tarda ~12. Pero tampoco tarda siempre el máximo: si
son 5 hamburguesas y hay un solo cocinero, se serializan.

**La variable real es la estación, no el producto.** Y Punto ya la modela:
`order_station` + `pos_order_item.stationid` (O0, mig 79) rutean cada ítem a
su estación. Dentro de una estación el trabajo es **serial** (el mismo
cocinero hace una cosa después de la otra); entre estaciones es **paralelo**
(parrilla y barra trabajan a la vez).

```
targetMinutes = MAX sobre estaciones ( Σ prepMinutes de los ítems de esa estación )
```

Con piso en el default del outlet. Este modelo:
- No miente por suma (la barra no le suma tiempo a la parrilla).
- No miente por máximo ingenuo (5 hamburguesas sí suman entre sí).
- **Reusa infraestructura que ya existe** — cero conceptos nuevos.

**Pero** requiere cargar `prepMinutes` por producto, que es data entry que el
comercio no va a hacer el día 1. Por eso se faseó:

| Fase | Modelo | Esfuerzo del comercio |
|---|---|---|
| F-SLA-0 | Default fijo por outlet (ej. 40 min), override por orden | Un número, una vez |
| F-SLA-1 | `prepMinutes` por ítem → max-por-estación, con fallback al default | Opcional, incremental |
| F-SLA-2 | El sistema **sugiere** el número real desde el histórico | Cero — lo observa |

F-SLA-2 es el cierre del círculo: una vez que medís `sent_at → ready_at` de
miles de órdenes, el promedio real (p75, no la media — la cola importa) es
mejor que cualquier número inventado. El default deja de ser una adivinanza.

## A.2 Snapshot, no cálculo en lectura

`targetminutes` se **congela en la orden al crearla**. Cambiar el tiempo de
preparación de un producto NO reescribe la historia de órdenes viejas —
mismo principio que `pos_order_item` ya aplica con `name`/`price`.

Sin esto, el reporte "cumplimos la promesa el 78% de las veces" cambia
retroactivamente cada vez que alguien edita un producto.

## A.3 De qué timestamp corre el reloj

**No hay un reloj, hay tramos.** El pedido no está "demorado" mientras el
cajero todavía lo tipea:

| Tramo | Desde → hasta | A quién mide |
|---|---|---|
| Preparación | `sent_at` → `ready_at` | La cocina |
| Traslado | `dispatched_at` → `delivered_at` | El reparto |
| **Promesa** | `sent_at` → `delivered_at` | Lo que el cliente escuchó |

F-SLA-0 usa **solo la promesa total** (`targetminutes` desde `sent_at`) —
alcanza para el 100% de los casos sin delivery. El split
prep/traslado llega con delivery (F-D), que es cuando juzgar a la cocina por
el tráfico se vuelve injusto.

`pos_order` hoy solo tiene `sent_at` y `closed_at`. **Faltan `ready_at`,
`dispatched_at` y `delivered_at`** — sin ellos no se mide nada ni existe
F-SLA-2.

## A.4 Tiers y color

| Tier | Condición | Color |
|---|---|---|
| `fresh` | < 50% del target | neutro (sin color) |
| `warn` | ≥ 50% | `amber` (`#f59e0b`) |
| `late` | ≥ 100% | `rose` (`#f43f5e`) |

El 50% es configurable (`slaWarnRatio`, default `0.5`).

### ⚠ Conflicto de color a resolver por diseño

Los colores ya están tomados por otras semánticas: estado del espacio
(verde ocupado / rojo cuenta pedida / violeta reservado,
`space-state-visuals.ts`) y modo del carrito (`mode-visuals.ts`). Si el SLA
también pinta el fondo en rojo, "mesa demorada" y "mesa pidió la cuenta" se
vuelven indistinguibles.

**Regla fija: el ESTADO pinta el fondo/borde; el SLA pinta SOLO el pill de
tiempo.** Dos canales, nunca mezclados. Es el precedente ya establecido en
`pos-space-tile.tsx` (2026-07-19): *"el color BASE del tile siempre es el del
estado"*.

Mapping central en `lib/pos/sla-visuals.ts` — espejo de `mode-visuals.ts` /
`space-state-visuals.ts`. **Nunca duplicar el mapping inline** en un
componente.

## A.5 Dónde se configura

- **Default del outlet**: `outlet.data->>'orderTargetMinutes'` (JSONB, no
  necesita mig — es config, no se filtra por ella). Editable en Ajustes.
- **Override por orden**: en el POS, al ordenar. El `NumericPadDialog` ya
  existe (modo `int`).
- **Deprecar los umbrales del KDS**: `lib/kds/config.ts` hoy guarda
  `warnMin`/`lateMin` absolutos en localStorage por dispositivo. Con el
  target por orden pasan a ser dos fuentes de verdad en conflicto → el KDS
  consume el SLA de la orden y los umbrales locales se eliminan.

## A.6 Superficie técnica (F-SLA-0)

**Mig**: `pos_order` += `targetminutes INT`, `ready_at`, `dispatched_at`,
`delivered_at TIMESTAMPTZ`.

**Backend**: `OrderCoreService::create()` resuelve y congela `targetminutes`
(payload > default del outlet); `updateStatus()` estampa el timestamp de cada
transición; `presentOrder()` los expone.

**Front**: `hooks/use-order-sla.ts` (hermano de `useElapsed`, ratio-based en
vez de minutos absolutos) + `lib/pos/sla-visuals.ts`. Consumidores: KDS,
`/pos/ordenes` (las tres vistas), `pos-space-tile`, display.

---

# PARTE B — Delivery (O4)

## B.1 Modelado: `fulfillment` ≠ `source`

**Delivery NO es un canal de origen.** Un pedido telefónico (`source=counter`)
puede ser delivery, y uno de ecommerce puede ser retiro en el local. Meterlo
en `source` obliga a elegir entre "de dónde vino" y "cómo llega" — se pierde
información.

Columna nueva: `fulfillment VARCHAR(12) CHECK IN ('dine_in','takeaway','delivery')`,
default `dine_in`. Ortogonal a `source`, que queda intacto.

(Una orden con `spacesessionid` es `dine_in` por construcción — el service lo
fuerza, igual que ya fuerza `source='table'`.)

## B.2 Estado "En camino"

`out_for_delivery`, entre `ready` y `delivered`:

```
ready             → ['out_for_delivery', 'delivered', 'cancelled']
out_for_delivery  → ['delivered', 'cancelled']
```

Requiere actualizar el CHECK de `pos_order.status` y `ORDER_TRANSITIONS`.

**Guards:**
- Solo válido si `fulfillment='delivery'` — una mesa no sale "en camino".
- **Whitelist por module** (`assertModuleCanSetStatus`, patrón ya existente):
  el KDS **no** puede setear `out_for_delivery` — la cocina marca `ready`,
  el despacho marca la salida. La pantalla de mozos tampoco.

## B.3 Dirección y repartidor

- **Snapshot en la orden**: `deliveryaddress TEXT`, `deliverylat`,
  `deliverylng NUMERIC(10,7)`. Se copian de `contact.contactLatLng` al crear,
  pero quedan **congelados**: el cliente puede mudarse, y ese pedido fue a la
  dirección vieja. Además permite entregar a una dirección distinta de la
  del perfil sin ensuciar el contacto.
- **`courierid UUID`** → contact (staff). Ya existe `contactTrackLocation` y
  el endpoint `userLocation` en `api/v1/orders.php` (legacy) — reusable para
  el tracking del repartidor en F-D-2, no hay que inventarlo.
- **`deliveryfee NUMERIC(14,2)`** — ⚠ FLAG: toca facturación. Definir con el
  owner si el costo de envío entra como ítem de la venta (afecta IVA y el
  comprobante fiscal) o como campo informativo. **No ejecutar sin esa
  decisión.**

## B.4 UI

- **Mapa de `/pos/ordenes`**: hoy se está construyendo filtrando por "órdenes
  con coordenadas". Cuando exista `fulfillment`, el filtro correcto es
  `fulfillment='delivery'` — migrar ahí.
- **Pills de estado**: se suma "En camino".
- **Vista de despacho**: agrupar las `ready` + `out_for_delivery` por
  repartidor; asignar courier y marcar salida.

## B.5 Fases

| Fase | Contenido | Depende de |
|---|---|---|
| **F-SLA-0** | Mig (targetminutes + timestamps), default por outlet, snapshot, `use-order-sla`, `sla-visuals`, consumo en KDS/ordenes/espacios | — |
| **F-D-0** | `fulfillment`, `out_for_delivery`, guards, whitelist por module | F-SLA-0 (timestamps) |
| **F-D-1** | Dirección/coords snapshot, `courierid`, UI de despacho, mapa filtrado | F-D-0 |
| **F-SLA-1** | `prepMinutes` por ítem + agregación max-por-estación | F-SLA-0 |
| **F-D-2** | Tracking del repartidor en vivo (reusa `contactTrackLocation`) | F-D-1 |
| **F-EVT-0** | `pos_order_event` (historial de transiciones, orden e ítem) + `recordEvent()` en los 5 puntos de escritura — ver PARTE C | — (independiente, ejecutable ya) |
| **F-SLA-2** | Target sugerido desde el histórico (p75 de `sent_at→ready_at`) | F-SLA-0 + **F-EVT-0** + volumen de datos |

## B.6 Decisiones pendientes del owner

1. **`deliveryfee` fiscal**: ¿ítem de la venta o campo informativo? Bloquea
   F-D-1.
2. **¿El repartidor tiene app propia?** Si sí, `out_for_delivery` y
   `delivered` los marca él y hace falta un realm/module nuevo (patrón
   device pairing). Si no, los marca el POS y F-D-2 se simplifica mucho.

---

# PARTE C — Historial de transiciones (`pos_order_event`)

Requisito del owner (2026-07-19): cada orden guarda **cuándo pasó por cada
estado**, para medir tiempos promedio y encontrar cuellos de botella en las
líneas de producción.

## C.1 Por qué NO alcanzan las columnas de timestamp

`ready_at`/`dispatched_at`/`delivered_at` (§A.3) son un **cache**, no la
historia. Pierden:

- **Las repeticiones.** Una orden que va `ready → in_progress → ready`
  (salió mal, se rehízo) pisa el timestamp: la columna dice que tardó 8 min
  cuando en realidad tardó 25 y hubo re-trabajo. **El re-trabajo es
  justamente lo que hay que detectar.**
- **Quién lo marcó.** No es lo mismo que la cocina marque `ready` desde el
  KDS a que el cajero lo fuerce desde el POS para sacarse el pedido de
  encima. Sin el actor, el promedio miente.
- **El nivel de ítem.** Una columna en `pos_order` no puede decir cuánto
  tardó *la parrilla* — y ahí está el cuello de botella.

## C.2 El insight: el cuello de botella es de ESTACIÓN, no de orden

El evento a nivel **orden** da el lead time total. El que encuentra el
cuello de botella es el de **ítem**, porque cada ítem ya está ruteado a su
estación (`pos_order_item.stationid`, O0):

> "La parrilla promedia 18 min por ítem y la barra 4" → la parrilla es el
> cuello. Sin eventos por ítem solo sabés que "las órdenes tardan", no dónde.

Por eso la tabla cubre **los dos scopes** (`order` e `item`) en un solo
log — misma partición por estación que ya usan KDS y comandas.

## C.3 Schema

```sql
CREATE TABLE pos_order_event (
  eventid      UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    UUID NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid     UUID NOT NULL,          -- denormalizado: las queries analíticas
                                        -- escanean por outlet sin JOIN
  orderid      UUID NOT NULL REFERENCES pos_order(orderid) ON DELETE CASCADE,
  orderitemid  UUID,                   -- NULL = evento de orden
  stationid    UUID,                   -- snapshot de la estación del ítem
  scope        VARCHAR(8)  NOT NULL CHECK (scope IN ('order','item')),
  from_status  VARCHAR(16),            -- NULL en el evento de creación
  to_status    VARCHAR(16) NOT NULL,
  actor_kind   VARCHAR(12) NOT NULL CHECK (actor_kind IN ('user','device','system')),
  actor_id     UUID,                   -- userid o deviceid
  actor_module VARCHAR(12),            -- pos | kds | display | panel | print
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Índices: `(companyid, orderid, created_at)` para el timeline de una orden;
`(companyid, outletid, created_at DESC)` para reportes; `(companyid,
stationid, to_status, created_at)` para el análisis por estación.

`stationid` se **snapshotea** en el evento: si mañana se re-rutea la
categoría a otra estación, la historia debe seguir diciendo quién lo hizo
esa noche.

## C.4 Invariante: mismo TX que el cambio de estado

**El evento se escribe en la MISMA transacción que el UPDATE de status.** Si
se escriben por separado, cualquier fallo parcial deja la historia mintiendo
—y una historia en la que no confiás no sirve para decidir nada—.

Punto único de escritura: un `recordEvent()` privado en `OrderCoreService`,
llamado desde `create()`, `send()`, `updateStatus()`, `updateItemStatus()` y
`markPaid()`. **No debe existir ningún camino que cambie status sin emitir
evento** — si aparece uno, es un bug, no una excepción.

El actor sale de lo que el endpoint ya conoce: `AUTHED_USER_ID`,
`AUTHED_DEVICE_ID` y el `module` que `assertModuleCanSetStatus()` ya valida.

## C.5 Derivados

- Los timestamps de §A.3 pasan a ser **cache derivado** del log (se siguen
  escribiendo en el mismo UPDATE, para no pagar un subquery en el camino
  caliente del KDS).
- **F-SLA-2 (target sugerido desde el histórico) depende de esta tabla.** Es
  su prerequisito: sin eventos no hay p75 que calcular.
- **Backfill opcional**: las órdenes existentes tienen `created_at`/
  `sent_at`/`closed_at` → se pueden sembrar eventos sintéticos
  (`actor_kind='system'`) para que los reportes no arranquen vacíos.
- **Crecimiento**: ~6 eventos por orden. Alimenta los rollups de
  `context/18` (día/mes por outlet y por estación) y el raw se poda pasados
  N meses. No construir el rollup en esta fase — solo no bloquearlo.

## C.6 Fuera de alcance (registrado)

`space_session` (mesa abierta → cuenta pedida → cerrada) merece el mismo
tratamiento para medir rotación de mesas, pero es otro dominio: se decide
aparte, con el mismo patrón.

## C.7 Fase

**F-EVT-0** — tabla + `recordEvent()` en los 5 puntos de escritura +
exposición del timeline en el detalle de la orden. Sin UI de reportes.
Prerequisito de F-SLA-2. No depende de delivery ni de las decisiones
pendientes de §B.6, así que puede ejecutarse ya.
