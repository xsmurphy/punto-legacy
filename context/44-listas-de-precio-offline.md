# 44 — Listas de precio: resolución offline

> Estado (2026-08-16): **plan, sin implementar.** Nace como ampliación de
> alcance mid-sesión sobre el fix de invalidación de `context/15
> §Fix listas de precios` (ese fix SÍ está en `main`). El owner pidió bajar
> las listas al dispositivo y resolver localmente; el volumen real de
> `price_list_item` en prod no se pudo confirmar esta sesión (SSH al
> servidor autorizado, pero la sonda de `docker ps`/`docker inspect` fue
> bloqueada por el classifier de auto-mode a mitad de camino) — el plan
> queda con esa verificación como paso 0, no asumida.
>
> **Revisión 2:** el mecanismo de sync de Decisión 1 (abajo) se generalizó
> mid-sesión de "solo precios" a TODA tabla satélite de `item`/`contact` —
> ver `context/45-satelites-item-contact-sync.md` para el diseño genérico
> (trigger, inventario completo, cuidados). Este doc mantiene lo específico
> de precios: la cabecera va en `settings`, el override viaja con el ítem,
> y el motor espejo TS/PHP para resolver localmente.

## El problema (textual del owner)

> "si no hay internet no va a poder consultar la lista de precio... la
> lista debe descargarse completa en el bootstrap y persistir para el modo
> offline al igual que todo lo que se usa en la caja"

El POS resuelve precios preguntando al server (`POST /v1/price_resolve`).
Sin conexión, la mutación falla y `usePriceContext` (ver
`hooks/use-price-context.ts`) atrapa el error y sigue con precios BASE —
a propósito, para no romper la venta. Pero un cliente con lista de -20%
paga precio lleno si la caja está offline. Es plata, y silencioso: no hay
excepción que lo delate, solo un cobro de más (mismo patrón de riesgo que
`e03c8a2e`, `PriceListService` ya rompió en silencio una vez).

## Hallazgos de esta sesión (relevados, no asumidos)

- **`price_list_item` NO tiene columna `updatedAt`** (mig 32,
  `api/database/migrations/postgres/32_price_lists.sql`) — solo
  `createdAt`. El modelo de delta por fila que usan `item`/`contact`
  (`MAX(updated_at) WHERE companyId`) no tiene de dónde leer acá.
- **El guardado es bulk-replace, no upsert**:
  `PriceListService::setItems()` hace `DELETE FROM price_list_item WHERE
  priceListId=? AND companyId=?` y reinserta TODOS los ítems de la lista en
  la misma transacción — editar UN override reescribe la lista ENTERA.
  Consecuencia directa: un delta "qué filas cambiaron" no tiene sentido acá
  como lo tiene para `item`/`contact` (donde cada fila se actualiza sola).
  La granularidad real del cambio es la LISTA, no la fila.
- **`setItems()` tampoco bumpea `price_list.updatedAt`** — el header
  (`price_list`, que SÍ tiene `updatedAt`) no se entera cuando cambian sus
  ítems. Hay que arreglar esto de cualquier forma, sea cual sea el diseño
  final: es el watermark natural de "esta lista cambió".
- **Volumen: sin confirmar con datos reales** (ver nota de estado arriba).
  La preocupación del owner es válida en el peor caso (una lista con
  overrides para buena parte del catálogo × varias listas = miles de
  filas) — el diseño de abajo asume ese peor caso porque es barato de
  todos modos (ver Decisión 1) y porque no hay dato que la descarte.
- **`item.itemId` referenciado desde `price_list_item`** (`itemId UUID NOT
  NULL REFERENCES item ON DELETE CASCADE`) — un ítem borrado se lleva sus
  overrides por cascade. La lápida de `item` (mig 138, `deleted_row`) ya
  cubre "el ítem desapareció"; el override de precio desaparece con él sin
  necesitar su propia lápida para ESE caso.

## Decisión 1 — El ÍTEM es la unidad de sync (REVISADA 2026-08-16, reemplaza el diseño "sync por lista" original)

Primera versión de este documento proponía una sección de sync propia
(`priceLists`, watermark por lista, delta de header+overrides). El owner la
reemplazó por un modelo más simple, textual: *"si un artículo se modifica
el POS descarga la config entera de ese producto + los precios en las
distintas listas de ese producto. Editar un item en una lista o un item en
sí cuentan por igual como edición del mismo item, por lo tanto dispara el
update."*

**El corte que hace cerrar el modelo — separar por CARDINALIDAD, no tratar
`price_list`/`price_list_item` como una unidad:**

- **Cabecera de lista** (`price_list`: `priceListName`, `defaultAdjustment`,
  `validFrom`/`validTo`, `status`) — pocas filas (decenas, no miles) → va en
  el bundle `settings` (ya existente, recarga completa cuando cambia
  cualquier cosa del bundle). Sumar `'price-list'` a `$settingsEntities`
  (`syncSectionAfterMutation()`, `api/bootstrap.php`) — la alternativa que
  la v1 de este doc había descartado, pero ahí SOLO para la cabecera (unas
  pocas filas), no para `price_list_item` (que es donde vivía el volumen
  real). Si el admin cambia el `defaultAdjustment` general de una lista que
  toca 5.000 productos, NO hay que tocar 5.000 ítems — cambia la cabecera
  (bundle chico), el motor local recalcula con esa cabecera nueva + los
  overrides que cada ítem YA tiene cacheados.
- **Override por ítem** (`price_list_item`) — viaja DENTRO del payload del
  ítem (`PosItem`, bootstrap + delta de `context/43`), como un array de
  `{priceListId, fixedPrice?, itemAdjustment?}`. Editarlo CUENTA como editar
  el ítem: el `item` ya tiene delta por fila + lápida (mig 138) — no hace
  falta inventar un mecanismo nuevo, hay que ENGANCHAR a ese.

**Pieza que falta para que el enganche sea real:** un cambio en
`price_list_item` (INSERT/UPDATE/DELETE) tiene que bumpear
`item.updatedAt` del `itemId` afectado, vía trigger de DB — GENERALIZADO
mid-sesión a TODA tabla satélite de `item`/`contact`, no solo ésta. Diseño
del trigger genérico, inventario completo de satélites, cuidados
(DELETE/UPDATE que cambia de padre, recursión, reloj, amplificación de
escritura) y qué falta para implementar: **ver
`context/45-satelites-item-contact-sync.md`** — `price_list_item` es la
fila de ese inventario que originó este doc, no se repite el diseño acá.

Con el trigger genérico andando, `PriceListService::setItems()`
(bulk-replace DELETE+INSERT) ya bumpea `item.updatedAt` de TODOS los ítems
tocados sin cambiar una línea de PHP — el trigger corre por cada fila del
DELETE y de cada INSERT. El `item.updatedAt` sigue siendo el ÚNICO
watermark de la sección `items` (`context/43`).

**Ya NO hace falta** (quedaban en la v1 de este doc, descartados ahora):
sección de sync propia `priceLists`, watermark por `price_list.updatedAt`
para overrides, ni lápida propia para `price_list_item` — el ítem ya
resuelve borrado/creación/edición de su propio override vía el mecanismo
que `context/43` construyó para `item`.

**Cuidados textuales del owner, no asumidos:**
- El payload del ítem crece con la cantidad de listas del comercio
  (un override por lista en la que el ítem participa). Con pocas listas es
  despreciable; si un tenant tuviera MUCHAS, hay que medirlo antes de
  asumir que sigue siendo barato — no está medido en esta sesión (ver nota
  de estado arriba sobre el acceso a prod bloqueado a mitad de camino).
- Un ítem SIN override en ninguna lista no debe cargar estructura vacía
  (`priceOverrides: []` o el campo directamente ausente, a definir en D3 —
  no `[{}, {}, ...]` ni nulls por cada lista del tenant).

**Verificación esperada en el arnés** (a sumar cuando se implemente):
editar el precio de UN ítem en UNA lista → ese ítem aparece en el delta de
`items` (`verify_sync.php`, mismo mecanismo que ya prueba edición de
`item`). Cambiar el `defaultAdjustment` de la lista → NINGÚN ítem aparece
en el delta de `items` (solo cambia `settings`), pero el precio resuelto
LOCAL (motor espejo, Decisión 2) cambia igual porque usa la cabecera nueva.

## Decisión 2 — Motor espejo TS/PHP, precedente `lib/tax/`

Mismo patrón que el motor de impuestos (`api/lib/Tax/TaxEngine.php` +
`frontend/lib/tax/engine-core.mjs` + `api/lib/Tax/fixtures.json`, con
`verify_engine.php`/`verify-engine.mjs` corriendo LOS MISMOS casos contra
ambos):

- **Extraer** la lógica pura de `PriceListService::resolveActiveList()` +
  `applyList()`/`resolvePrice()` (prioridad: override de línea > lista
  manual del cajero > lista del contacto > lista default del outlet;
  validez por `status`/`validFrom`/`validTo`; por ítem `fixedPrice` o
  `itemAdjustment`, `defaultAdjustment` de la lista como fallback) a una
  función sin I/O — recibe listas+overrides+contexto ya cargados en
  memoria, devuelve precios. Candidato: `api/lib/PriceList/PriceEngine.php`.
- **Espejo TS**: `frontend/lib/price-list/engine-core.mjs` — misma firma,
  mismos casos límite (lista vencida por `validTo`, `status=false`,
  `fixedPrice` vs `itemAdjustment` mutuamente excluyentes, prioridad
  override de línea).
- **Fixtures compartidos**: `api/lib/PriceList/fixtures.json` — casos con
  input (listas, overrides, contexto) y output esperado, consumidos por
  `verify_price_engine.php` Y `verify-price-engine.mjs`. Sin esto, TS y PHP
  divergen con el tiempo sin que nada lo note (motivo textual del owner:
  "es la única forma de que el precio offline y el online coincidan").
- **`PriceListService::resolvePriceBatch()`** pasa a ser un wrapper fino:
  carga listas/overrides de la DB, llama al motor extraído. Cero cambio de
  comportamiento — refactor, no reescritura.

## Decisión 3 — Servidor sigue siendo autoridad; local es para no depender del round-trip

`/v1/price_resolve` NO se borra. Dos usos del motor local:

1. **Offline real** (WS caído, sin red): único camino posible, ya no hay
   servidor al que preguntar.
2. **Online, evitar latencia por cada cambio de carrito**: hoy cada
   agregado de línea dispara un POST (debounce 300ms). Con el motor local
   cargado desde el bootstrap, la UI podría resolver instantáneo y
   sincronizar contra el server como reconciliación, no como único camino.

**No decidido unilateralmente** (el owner lo pidió explícito): ¿el local
pasa a ser el camino DEFAULT siempre (con el server validando solo al
confirmar la venta, que ya es donde se congela el precio real —
`SaleService`), o el server sigue siendo el camino normal y el local es
NADA MÁS el fallback offline? Ambas son válidas; la primera ahorra
round-trips pero duplica más lógica en el camino caliente, la segunda es
el cambio mínimo. Requiere sign-off del owner antes de picar código — no
es una decisión de implementación, es de arquitectura del flujo de venta.

## Decisión 4 — Persistencia offline: hereda el TODO ya existente de items/contactos

`context/43 §Qué quedó afuera` ya documenta que el catálogo (`item`/
`contact`) NO persiste en IndexedDB — el store de Zustand se resetea en
cada reload, así que "offline" hoy significa "la pestaña sigue abierta con
el WS caído", no "cerré y reabrí el navegador sin red". Bajar las listas
de precio al bootstrap sin resolver esto las deja con el MISMO límite que
el resto del catálogo — consistente, no es una regresión nueva, pero
tampoco resuelve el caso "arranco el POS sin red" que el pedido del owner
sugiere. Si se ataca la persistencia real (IndexedDB/`idb`, ya es
dependencia vía `lib/pos/offline-queue.ts`), debería cubrir catálogo +
listas de precio en el mismo trabajo — hacerlo solo para precios dejaría
items/clientes en peor estado relativo sin razón.

## Plan de fases (propuesto, sin cerrar con el owner — revisado 2026-08-16)

1. **D0 — Verificar volumen real** con datos de prod (`SELECT count(*)
   FROM price_list_item`, distribución por `companyId`/lista, y cuántas
   listas por tenant) — informa el caution de `context/45` sobre cuánto
   crece el payload del ítem. No confirmado esta sesión (acceso a prod
   bloqueado a mitad de camino, ver nota de estado arriba).
2. **D1 — Trigger genérico de satélites** (`context/45`): función +
   triggers, EMPEZANDO por `price_list_item` (el caso que originó todo) —
   valida el patrón antes de extenderlo al resto del inventario de ese doc.
3. **D2 — Motor espejo** (Decisión 2 de este doc): extracción PHP + espejo
   TS + fixtures + verify scripts. Sin wiring al POS todavía — solo el
   motor, verificado contra el real.
4. **D3 — `ItemsQuery.php` suma precios por lista** al payload de `PosItem`
   (shape: array de overrides, vacío/ausente si el ítem no tiene ninguno —
   ver caution en Decisión 1) + sumar `'price-list'` a `$settingsEntities`
   para que la CABECERA de lista viaje en `settings`. Un solo cambio cubre
   bootstrap + bulk-get + delta (`context/45` §Payload).
5. **D4 — Store del POS**: `useCatalogStore` guarda cabeceras de lista
   (de `settings`/bootstrap) + usa los overrides que ya vienen en cada
   `PosItem`.
6. **D5 — Wiring en `usePriceContext`**: decidir con el owner (Decisión 3)
   si el motor local es fallback-only o default-con-reconciliación; cablear
   según lo que se decida.
7. **D6 (opcional, mayor)** — Persistencia IndexedDB real (Decisión 4),
   compartida con items/contactos. Bloqueante para "arranco sin red desde
   cero"; no bloqueante para el resto de este plan.

## Referencias

- `context/15-realtime-sync-plan.md` §Fix listas de precios — el fix de
  invalidación EN CALIENTE, ya en `main`, complementario a este plan.
- `context/43-sync-incremental.md` — modelo de delta de `item`/`contact`,
  base sobre la que se engancha este plan (ya NO un contraste — ver
  Decisión 1 revisada).
- `context/45-satelites-item-contact-sync.md` — la regla general (trigger
  genérico, inventario de satélites, cuidados) de la que Decisión 1 de este
  doc es la instancia concreta para `price_list_item`.
- `api/lib/Tax/` + `frontend/lib/tax/engine-core.mjs` — precedente de motor
  espejo con fixtures compartidos.
- `api/lib/services/PriceListService.php` — lógica a extraer (D2).
- `api/lib/Items/ItemsQuery.php` — único punto que arma el shape de
  `PosItem` para bootstrap/bulk-get/delta (D3).
- `api/database/migrations/postgres/32_price_lists.sql` — schema actual
  (sin `updatedAt` en `price_list_item`, no lo necesita con el modelo
  revisado — el watermark es el de `item`).
