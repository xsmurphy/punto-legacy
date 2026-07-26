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
| **F-SLA-2** | Target sugerido desde el histórico (p75 de `sent_at→ready_at`) | F-SLA-0 + volumen de datos |

## B.6 Decisiones pendientes del owner

1. **`deliveryfee` fiscal**: ¿ítem de la venta o campo informativo? Bloquea
   F-D-1.
2. **¿El repartidor tiene app propia?** Si sí, `out_for_delivery` y
   `delivered` los marca él y hace falta un realm/module nuevo (patrón
   device pairing). Si no, los marca el POS y F-D-2 se simplifica mucho.
