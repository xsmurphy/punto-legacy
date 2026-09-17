# 81 — Lote compartido: el costo de un lote repartido entre los pedidos que atiende

> Estado: **PLAN sin implementar (2026-09-17). D1-D8 PROPUESTAS, sin OK del
> owner.** Pedido del owner: que el caso de la lavandería se resuelva con el
> MISMO mecanismo que el lote de viandas (`context/70`, etapa B), no con algo
> exclusivo del rubro.

## 1. El pedido

**Lavandería** (cliente potencial, 2026-09-17): en un lavado (un ciclo de
máquina) entran prendas de VARIOS clientes. Quieren saber cuánto costó ese
lavado y, de ahí, cuánto le cuesta cada pedido.

**Viandas** (`context/70`): un lote de producción del día cocina platos para
muchos pedidos a la vez y consume los insumos UNA vez.

Los dos son el mismo problema: **un lote atiende pedidos de N clientes,
consume insumos una sola vez, y su costo se reparte entre esos pedidos.**

## 2. Lo que ya existe (verificado 2026-09-17)

- **El lote ya es entidad**: `production_batch` (mig 194) — sucursal, depósito
  de insumos y de terminado, `docnumber` propio (doctype `lote`), estados
  `draft → confirmed | cancelled`, confirmación atómica
  (`ProductionBatchService::confirm()`), y N `production_order` adentro.
- **El costeo de insumos ya existe**: `ProductionService::complete()` explota
  la receta (recursiva, con merma), costea con `RecipeCosting` (promedio
  ponderado de la sucursal, fallback `item.itemCost`), consume por
  `Inventory::manageStock()` y congela `ingredientcost`/`unitcogs`.
- **Un insumo SIN stock con costo ya se costea sin mover stock** (rama de
  hojas sin inventario en `complete()`). Es la puerta natural para costos
  estándar como energía o agua por ciclo.
- **La demanda ya se agrega por pedido**: `OrderDemandService::pendingByItem()`
  devuelve por producto los `sources[{orderId, qty}]`, y desde la mig 225 filtra
  por fecha (`context/79`).
- **Pedido → venta ya está vinculado**: `order_transaction_link`.
- **Cantidades decimales en todo el camino** (`NUMERIC(15,3)`): un pedido por
  kilo funciona hoy, aunque la unidad es solo texto descriptivo (`itemUOM`).

## 3. Lo que falta (verificado)

1. **El lote no sabe a qué pedidos atiende.** Los `sources` solo viven en la UI
   del lote (`frontend/app/(panel)/produccion/lote/page.tsx:146-152`): "no se
   persiste en el lote".
2. **Un lote siempre fabrica stock.** `ProductionService::create()` exige que el
   ítem producido tenga inventario (`:354`). Un lavado no fabrica nada que entre
   al depósito.
3. **No hay reparto de costo** entre pedidos, ni congelado ni calculado.
4. **No hay costos indirectos** en el costeo: todo es insumo.
5. **No hay máquina/recurso**: nada parecido a "Lavadora 1, 18 kg".

## 4. Decisiones propuestas (SIN OK del owner)

### D1 — Se extiende `production_batch`; no nace una entidad "lavado"
El lote de viandas y el lavado son el mismo objeto: agrupan pedidos, consumen
una vez, tienen número, se confirman atómicamente. Una tabla nueva para la
lavandería duplicaría la confirmación, la numeración y el costeo, y divergiría.

### D2 — El proceso es un ÍTEM con receta que NO lleva stock
El lavado se carga como un ítem de tipo nuevo **`proceso`**: no se vende, no
tiene stock, y su receta es lo que consume UN ciclo ("Lavado 18 kg": 150 ml de
detergente, 80 ml de suavizante…). El lote produce "1 × Lavado 18 kg":
- consume los insumos que llevan stock (por `manageStock`, como hoy);
- **no acredita ingreso**, porque el ítem no lleva inventario.

Es exactamente la regla D1 de `context/70`: **si el lote mueve stock lo decide
el ÍTEM, no un flag del lote.** Por eso se relaja la validación de
`ProductionService::create()` (`:354`) SOLO para ítems `proceso`, y no con un
"lote que no mueve stock" (rechazado en `context/70`).

Hoy el backend ya acepta receta en cualquier ítem (`ItemCompoundService::add()`
no valida el kind); lo que cambia es la validación de producción y la UI.

**¿Por qué no reusar `produccion_directa`?** (pregunta del owner 2026-09-17)
El CONSUMO es idéntico — receta explotada, insumos descontados por
`manageStock`, nada entra al depósito — y `proceso` reusa ese mismo camino, sin
lógica nueva. La diferencia es QUÉ lo dispara: `produccion_directa` consume
**al venderse** (`saleExplodesRecipe()` es true por sus flags, y es vendible);
el proceso consume **al confirmar el lote**. Si el ítem del lote fuera
`produccion_directa`, venderlo descontaría los insumos otra vez: el doble
consumo de D6 quedaría librado a que nadie lo venda. Con `proceso` (no
vendible, no aparece en la caja) el doble consumo es imposible por
construcción. Es la única razón del tipo nuevo.

### D3 — Costos indirectos v1 = insumos sin stock con costo estándar
"Energía por ciclo" o "Agua por ciclo" se cargan como ítems
`insumo_sin_stock` con su costo, dentro de la receta del proceso. El costeo ya
los suma sin mover stock. **No hay mecanismo nuevo.**

Queda FUERA de v1 prorratear gastos reales (la factura de luz del mes) entre
los lotes: exige elegir una base de reparto mensual y vincular `fin_movement`
con producción, que hoy no existe. Se evalúa con el v1 funcionando.

### D4 — El lote persiste los pedidos que atiende, con su base de reparto
Tabla nueva **`production_batch_order`** `(batchid, orderid, basisqty, allocatedcost)`:
- **`basisqty`**: cuánto de ese pedido entró en el lote. No el pedido entero:
  las prendas de un pedido pueden ir en dos lavados.
- **`allocatedcost`**: la parte del costo del lote que le toca, **congelada al
  confirmar** (mismo criterio que `unitcogs`: el costo de ayer no cambia porque
  hoy subió el detergente).
- Un pedido puede estar en VARIOS lotes (lavado, secado, planchado): su costo es
  la suma.

A viandas también le sirve: hoy el lote pierde qué pedidos cubrió.

### D5 — Base de reparto: la cantidad del pedido dentro del lote
`allocatedcost = costo del lote × basisqty / Σ basisqty`.
- Precargada con la cantidad de las líneas del pedido (kilos si se vende por
  kilo, prendas si se vende por prenda).
- **Editable al armar el lote**: la lavandería pesa la ropa en la máquina, y ese
  peso manda sobre lo que se cobró.
- Un lote mezclando unidades distintas (kilos de un pedido, prendas de otro) no
  tiene base común: se exige una sola unidad por lote. *(A decidir con el owner
  si alcanza o si hace falta un peso por pedido siempre.)*
- Redondeo: el último pedido absorbe la diferencia, para que la suma cierre
  exacta con el costo del lote.

### D6 — Consumir UNA sola vez: receta en el proceso O en el servicio vendido, nunca en los dos
Un `servicio` con receta **consume sus insumos al venderse**
(`Inventory::saleExplodesRecipe()`). Si además el lote consume la receta del
proceso, el detergente se descuenta dos veces. Regla:
- en modo lote, el servicio que se vende ("Lavado por kg") **no tiene receta**;
- al confirmar un lote, si alguna línea de sus pedidos es de un ítem con receta,
  se avisa y no se confirma.

### D7 — El margen por pedido sale del lote, no de `itemsold`
El servicio sin receta congela su COGS en la venta, y hoy lo congela en **0**
(no NULL): los reportes de ventas leerían "margen 100%". Para v1:
- el margen por pedido se reporta como **venta del pedido**
  (`order_transaction_link`) **− costo asignado** (`production_batch_order`);
- **no** se reescribe `itemsold.itemsoldcogs` después de la venta: la venta
  puede facturarse antes que el lote, el guard de cierre de período bloquea
  UPDATE y quedarían dos fuentes del mismo costo.

**Hallazgo a corregir aparte**: un servicio sin receta debería congelar COGS
**desconocido (NULL)**, no 0 — mismo contrato que el histórico del migrador
(`context/77` §17): un 0 se lee como "no costó nada".

### D8 — La máquina (recurso) es opcional y va después
Entidad `production_resource` `(nombre, capacidad, unidad)` asociable al lote:
permite "Lavadora 2 — 18 kg", avisar si el lote supera la capacidad y reportar
costo y uso por máquina. **No bloquea v1**: sin ella el lote ya se costea y
reparte.

## 5. Flujo de punta a punta (lavandería)

1. Se carga el proceso "Lavado 18 kg" con su receta: detergente 150 ml,
   suavizante 80 ml, "Energía por ciclo" 1 (costo estándar 2.500), "Agua por
   ciclo" 1 (costo estándar 800).
2. Entran pedidos: María 6 kg, Juan 4 kg, Hotel Sol 8 kg (servicio "Lavado por
   kg", sin receta).
3. Se arma el lote: 1 × "Lavado 18 kg" con los tres pedidos y sus kilos (18).
4. Al confirmar: se consumen detergente y suavizante del depósito, el costo del
   lote es insumos + 3.300 de indirectos (supongamos **9.000 en total**).
5. Reparto congelado: María 3.000, Juan 2.000, Hotel Sol 4.000.
6. Reporte: costo por lote, por máquina (si D8), y margen por pedido = lo
   cobrado − lo asignado.

**Viandas** usa lo mismo: el lote produce platos (sí llevan stock) y reparte su
costo entre los pedidos del día por porciones.

## 6. Fases propuestas

| Fase | Qué |
|---|---|
| **F1** | Tipo `proceso` (mig: CHECK de `itemKind`), UI de receta para `proceso`, relajar `ProductionService::create()` solo para `proceso`, lote que consume sin ingreso. Da **costo por lote**. |
| **F2** | `production_batch_order`: el lote persiste pedidos + `basisqty` editable; reparto congelado al confirmar; validación D6. Viandas empieza a persistir sus `sources`. |
| **F3** | Reportes: costo por lote, costo y margen por pedido. |
| **F4** | (opcional) `production_resource` con capacidad. |
| **Aparte** | COGS NULL (no 0) para servicios sin receta. |

## 7. Preguntas abiertas para el owner

1. ¿La lavandería pesa por pedido al cargar la máquina, o alcanza con lo que se
   cobró? (define si `basisqty` se edita siempre o solo a veces — D5).
2. ¿Hace falta mezclar pedidos por kilo y por prenda en un mismo lavado?
3. ¿Indirectos estándar por ciclo (D3) alcanzan para empezar, o necesitan
   repartir la factura real de luz/agua desde el primer día?
4. ¿La máquina (D8) es necesaria en v1?
5. ¿Confirmar el lote debería mover el estado de los pedidos (ej. a "en
   proceso" / "listo")?

## 8. Arquitecturas rechazadas — no reintroducir

- **Entidad "lavado" propia** separada de `production_batch` — ver D1.
- **Flag de lote "no mueve stock"** — rechazado en `context/70`; lo decide el
  ítem (D2).
- **El lote como N órdenes de producción sueltas** — rechazado en `context/70`,
  pierde la agregación por insumo.
- **Receta en el servicio vendido Y en el proceso** — doble consumo (D6).
- **Reescribir `itemsold.itemsoldcogs` al confirmar el lote** — D7.
- **Repartir el costo en vivo, sin congelar** — el costo de un lote de ayer no
  puede cambiar porque subió un insumo hoy (D4).
- **Prorratear gastos reales (`fin_movement`) en v1** — D3.

## 9. Docs relacionados

- `context/70-viandas.md` — etapa B (lote), D1 y arquitecturas rechazadas.
- `context/79-ordenes-a-fecha-futura.md` — filtro por fecha de la demanda.
- `context/76-produccion-kpis.md` — qué guarda producción hoy y el doble conteo
  de fallas (no bloquea este plan, pero toca el mismo `unitcogs`).
- `context/53-orden-y-stock-reserva.md` — las órdenes no tocan stock.
