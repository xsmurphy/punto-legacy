# 41 — Add-ons y combos

> Estado (2026-08-14): **F1–F5 implementadas y en main.** Falta F6 (reportes)
> y dos gaps que F5 destapó (abajo, "Gaps abiertos"). D1–D3 cerradas.
>
> Commits: F1 `59737f80` · F2 `c592e899` · F3 `1da91b40` · F4 `f71496f6` ·
> F5 `79e97e71` (merge `5dc7e6b3`). Migs **134** (tablas) y **136** (migra
> `combo_group` → add-ons; la 135 es de otra sesión).

## Pedido del owner (2026-08-14)

- **Add-ons por producto**: al crear/editar un producto, poder añadir GRUPOS de
  productos como add-ons. Cada grupo con límite mínimo y máximo. Cada add-on
  puede sumar al precio o no. Pueden ser fijos (preseleccionados sin poder
  quitarse).
- **Combos predefinidos**: precio final del combo puede ser menor que la suma de
  sus productos → el descuento queda implícito.
- **Combos dinámicos**: el owner mismo lo dijo — "creo que es básicamente lo
  mismo que producto con add-on". El análisis lo confirma: se unifican.
- Implementar en panel Y en `/pos`.

## Estado actual (relevado, no asumido)

| Pieza | Estado |
|---|---|
| `combo_fijo` / `combo_dinamico` | Kinds declarados (mig 15). Mismos flags, CERO comportamiento distinto entre sí. |
| `item_compound` | Receta del combo fijo. `(parent, child, quantity, sort)`. UNIQUE (parent, child). |
| Venta de combo | El POS lo agrega como ítem plano; `SaleService` explota la receta recursivamente para stock (explodeRecipe, 2026-08-11). Sin UI de selección. |
| `itemSold.itemsoldparent` | Columna YA existe — soporte de líneas padre/hijo sin migración. |
| `toCompound` + `CompoundService` | **Deprecados** (F0 producción). El legacy tenía flags `preselected` — la idea de "fijo" ya existió. NO revivir. |
| Add-ons | (Al inicio) no existía nada. Hoy: F1–F5 completas. |

## Modelo propuesto

Dos tablas nuevas + un vínculo. El add-on ES un producto del catálogo (no un
texto suelto): así hereda stock, receta, costo e impuestos, y la venta lo
descuenta con la misma maquinaria que cualquier ítem.

```sql
-- Grupo de opciones DEL PRODUCTO (D1: por producto, no reusable).
CREATE TABLE addon_group (
  groupId    UUID PRIMARY KEY,
  companyId  UUID NOT NULL,
  itemId     UUID NOT NULL REFERENCES item ON DELETE CASCADE,  -- el dueño
  name       VARCHAR(80) NOT NULL,
  minSelect  SMALLINT NOT NULL DEFAULT 0,   -- 0 = opcional
  maxSelect  SMALLINT,                      -- NULL = sin tope
  sort       SMALLINT NOT NULL DEFAULT 0,
  status     BOOLEAN NOT NULL DEFAULT TRUE
);

-- Opciones del grupo: cada una apunta a un producto real.
CREATE TABLE addon_group_option (
  optionId    UUID PRIMARY KEY,
  groupId     UUID NOT NULL REFERENCES addon_group ON DELETE CASCADE,
  itemId      UUID NOT NULL REFERENCES item,    -- el producto que se agrega
  priceDelta  NUMERIC(14,2) NOT NULL DEFAULT 0, -- 0 = no suma al precio (D2)
  isDefault   BOOLEAN NOT NULL DEFAULT FALSE,   -- preseleccionado
  isLocked    BOOLEAN NOT NULL DEFAULT FALSE,   -- fijo: no se puede quitar
  maxQty      SMALLINT NOT NULL DEFAULT 1,      -- cuántas veces la misma opción
  sort        SMALLINT NOT NULL DEFAULT 0
);
```

Sin tabla N:M: D1 eliminó la reusabilidad, así que el grupo cuelga directo del
producto (`addon_group.itemId`, CASCADE al borrar el producto). Para agilizar la
carga en el panel, "duplicar grupos desde otro producto" es una COPIA — después
de copiar, cada producto edita lo suyo sin efecto dominó.

- `isLocked` implica `isDefault` (un fijo está siempre elegido). El guard va en
  el servicio, no en la UI.
- `minSelect > 0` = el POS no deja confirmar sin elegir (ej. "Bebida: min 1
  max 1" = radio obligatorio).
- **Combo dinámico se unifica acá y NO es un tipo con maquinaria propia**
  (owner, 2026-08-14: "¿es solo un producto con add-on?" — sí). Las tres
  variantes de precio que planteó son el mismo modelo con valores distintos:
  precio base 0 + todo por deltas; base > 0 que crece con deltas; o precio
  fijo menor que la suma de los ítems por separado (descuento implícito,
  deltas en 0). El kind `combo_dinamico` EXISTENTE se conserva como etiqueta
  semántica —filtros de reportes/catálogo y preset de UI en la ficha (grupos
  obligatorios min=max)— pero cero lógica propia: un `producto` común con
  grupos se comporta idéntico. No se crea ningún kind nuevo ni se borra el
  existente.
  - Consecuencia a verificar en F3: un ítem con grupos debe poder tener
    **precio base 0** sin que la venta lo rechace — la variante "el precio se
    construye por add-ons" depende de eso.
- **Combo fijo sigue en `item_compound`** (receta fija, sin elección). Su
  descuento implícito = suma de precios de los hijos − precio del combo; se
  muestra en ficha y ticket, no requiere schema.

## Venta (backend)

- `SaleInput` acepta por línea `selections: [{optionId, qty}]`. El server
  revalida TODO contra el modelo: min/max por grupo, opciones vigentes,
  priceDelta desde la BD — el precio NUNCA viaja del cliente.
- Persistencia: línea padre (el producto, precio base + suma de deltas) +
  líneas hijas en `itemSold` con `itemsoldparent` — columna ya existente. El
  ticket y la cocina imprimen padre + hijas indentadas.
- Stock: cada opción elegida descuenta como lo que es — si el add-on es un
  ítem con stock propio baja su saldo; si es producción directa explota su
  receta (`explodeRecipe`). Los `isLocked/isDefault` descuentan igual aunque el
  cajero no los haya tocado.
- Offline: las selecciones viajan en el payload de la venta encolada; la
  validación corre al sincronizar. Igual que hoy con el resto de la venta.

## POS

- Producto CON grupos → el tap abre modal de selección (radio si min=max=1,
  checkboxes/steppers si no; los `isLocked` aparecen marcados y deshabilitados;
  el precio se actualiza en vivo). Producto SIN grupos → tap agrega directo,
  como hoy. CERO fricción agregada a lo que no usa la feature.
- Carrito: línea padre expandible con sus add-ons; editar reabre el modal con
  lo elegido.
- Teclado: el modal respeta la operación sin mouse (context/pos): flechas +
  espacio para marcar, Enter confirma, ESC cancela.

## Panel

- **Ficha del producto**: sección "Add-ons" — adjuntar grupos existentes,
  crear uno nuevo inline, ordenar. Vista previa de cómo lo ve el cajero.
- **Combo fijo**: la ficha ya edita `item_compound`; se agrega el bloque de
  precio que muestre suma de componentes vs precio del combo y el descuento
  resultante.

## Decisiones (todas cerradas, owner 2026-08-14)

- **D1 — Grupos POR PRODUCTO**, no reusables. Cada producto es dueño de sus
  grupos; sin efecto dominó entre productos. Se descartó la recomendación de
  reusables — prioridad del owner: aislamiento. Consecuencia de modelo: sin
  tabla N:M, `addon_group.itemId` directo. En el panel, "copiar grupos de otro
  producto" mitiga la carga repetida (copia real, no referencia).
- **D2 — El precio lo define CADA OPCIÓN**: `priceDelta` por opción, donde 0 =
  no suma y >0 = recarga. El combo/producto tiene su precio propio y las
  opciones suman o no individualmente — "puede pasar que varios productos no
  sumen pero otros sí" (owner). Es exactamente lo que el modelo ya expresaba;
  no hay % de descuento sobre suma.
- **D3 — El ticket del cliente SOLO lista add-ons con precio.** Los de
  priceDelta=0 van a cocina siempre pero no al ticket fiscal. La impresión de
  cocina y la fiscal divergen por diseño.
- **D4 — La divergencia D3 se resuelve con VARIANTES DE BLOQUE, no con lógica
  en el renderer** (owner, 2026-08-09). Regla general del proyecto: lo que se
  imprime lo decide la plantilla — si el bloque está, sale; si no está, no sale
  (ver `context/20-design-system.md` y la convención de impresión). D3 no es la
  excepción: los add-ons viven DENTRO del bloque de artículos, y ese bloque
  "está más enfocado al artículo que se factura" (owner), así que la decisión
  correcta es ofrecer **más de una variante de listado de artículos** y que el
  comercio elija cuál pone en cada plantilla:

  - una variante **completa** — todas las líneas de la venta u orden, add-ons
    incluidos aunque sean gratis (comandas, órdenes, uso interno);
  - una variante **facturable** — solo líneas con precio (facturas).

  El renderer no decide nada: itera las líneas que la variante define. La
  sección "Artículos" de la paleta ya tiene precedente de esto — `item_receipt`
  ("Listado de Venta"), `item_receipt_4` (sin IVA), `item_receipt_2` (sin
  precios) y `item_receipt_3` (simple) ya son variantes de listado que difieren
  en qué columnas muestran; estas nuevas difieren en qué LÍNEAS incluyen.

  ⚠ **Bloqueada por el primer gap de abajo**: mientras la ORDEN no persista
  líneas hijas, la variante completa no tiene add-ons que listar en una
  comanda. Implementar las variantes antes que eso es pintar una opción vacía.

## El add-on cruza el flujo de orden — cerrado 2026-08-23

Regla del owner, que fija el reparto de responsabilidades entre los dos
documentos: **la orden no lleva montos** — es qué y cuánto, más notas y
etiquetas. **Los montos se cargan al cobrarla, para facturar.** El add-on no es
un texto ni un comentario: a la hora de facturar es un ítem más, con su precio
y su stock, igual que cualquier otro renglón.

De ahí que el add-on exista con dos formas y no sea una inconsistencia:

| | Orden (`pos_order_item`) | Venta (`itemSold`) |
|---|---|---|
| Padre | `price` con el recargo adentro | `price` = precio base pelado |
| Hija | `price = 0` + `pricedelta` congelado | `price` = su propio recargo |
| Para qué | qué preparar (comanda de cocina) | plata, stock, IVA, ticket, reportes |

El puente entre las dos formas es `rebuildSelectionsFromOrder()`
(`frontend/lib/cart/store.ts`): agrupa las hijas por `parentOrderItemId` y las
devuelve al carrito como `CartLine.selections` del padre — la MISMA forma con
la que `<AddonPickerDialog>` arma una línea nueva. De ahí en adelante el cobro
es indistinguible de una venta directa: `create-sale.ts` manda `selections`,
`SaleService::expandAddonSelections` las revalida y persiste las líneas hijas,
y el stock del add-on se descuenta.

**Corre en los TRES caminos de cobro** (los dos últimos recién desde
2026-08-25; hasta ahí solo lo hacía `loadFromOrder` y cobrar una mesa seguía
regalando el add-on):

| Camino | Quién | Precio |
|---|---|---|
| Orden suelta | `loadFromOrder` → `cartLinesFromOrderItems` | re-cotizado con el delta vigente |
| Mesa entera | `loadFromSession` → `cartLinesFromOrderItems` | re-cotizado con el delta vigente |
| Split por ítems | `buildItemsLines` (`lib/spaces/settlement-lines.ts`) | anclado al persistido |

La reconstrucción de los dos primeros es UNA función compartida a propósito:
tenerla inline en un loader fue exactamente lo que dejó el otro camino atrás
durante dos días. El split difiere en el anclaje porque el pago del ledger lo
calcula el backend desde el precio persistido
(`SpaceSettlementService::validateAndComputeAmount`): re-cotizar ahí dejaría la
venta y el asiento diferidos si el add-on cambió de precio con la mesa abierta.
La base se despeja restando el delta vigente, que es justo el que el server le
resta al padre — padre + hijas = lo que cobró la caja = lo que registra el
ledger.

El split por **`amount`/`share` NO reconstruye**, y es decisión, no olvido: la
qty sale fraccionada y `CartLineAddon.qty` es un entero de unidades, el recargo
no se prorratea (la hija se llevaría el delta entero de un cobro parcial), y
como esos modos no marcan lo cobrado, N parciales descontarían el add-on N
veces. Las tres razones están en el docblock de `buildProportionalLines`.

Tres detalles que importan si se toca:

- **`qty` vuelve a ser por unidad del padre.** La orden persiste
  `childQty = optQty × parentQty`; `CartLineAddon.qty` es la qty de la OPCIÓN,
  que el server vuelve a multiplicar. Sin dividir, cobrar 2 hamburguesas
  descontaría 4 quesos.
- **Dos precios con dos roles.** El `pricedelta` CONGELADO de la orden se usa
  solo para despejar el precio base del padre (es lo único que lo recupera
  exacto). El `priceDelta` que viaja en la selección sale del catálogo VIGENTE
  — el mismo que `validateSelections` re-cotiza server-side. Es la regla del
  owner aplicada: el monto se fija al facturar, no al ordenar.
- **Fail-safe explícito.** Si algo no reconstruye con confianza (hija sin
  `addonOptionId`, opción que ya no existe porque `replaceForItem` la borró y
  reinsertó con id nuevo, qty que no divide exacto, base negativa), devuelve
  `undefined` y la línea se cobra como antes. Una selección mal reconstruida la
  rechaza `validateSelections` con 422 y deja la mesa INCOBRABLE — mucho peor
  que perder el descuento de stock de esa línea.

Antes de esto, cobrar una mesa emitía la venta SIN `selections`: la plata salía
bien (ya estaba en el padre) pero `expandAddonSelections` nunca corría, así que
el add-on se regalaba del inventario, no aparecía en el ticket y era invisible
para los reportes por opción (F6) en TODAS las ventas que pasaban por orden o
mesa — el flujo normal en gastronomía.

**Verificación** (no había ninguna: ni un test tocaba add-ons en una venta
real). La cadena se prueba de los dos lados:

- Front — `frontend/lib/cart/__tests__/addon-rebuild-paths.test.ts`: los tres
  caminos devuelven `selections`, la qty vuelve a ser por unidad del padre, el
  merge de la mesa no fusiona el mismo producto con add-ons distintos, y cada
  fail-safe degrada a "línea sin add-ons" en vez de emitir una selección que
  el server rechace.
- Back — `api/lib/Sales/verify_chain/verify_addon_stock.php` (paso propio de
  `run.sh`, Postgres real, sin mocks): una venta con `selections` persiste la
  hija con `itemSoldParent`, **mueve el ledger de stock** por optQty ×
  unidades del padre, reparte el recargo sin duplicarlo (padre + hija =
  subtotal cobrado) y deja el detalle con `type='addon'`, que es la única
  señal con la que el ticket la indenta.

Lo que NO cambió, a propósito: `SpaceBalanceService` y `SpaceSettlementService`
siguen filtrando las hijas (`parentorderitemid IS NULL`). Una hija no es una
unidad cobrable por separado — el queso extra se paga con la hamburguesa, no en
otra parte del split.

## Gaps abiertos (destapados en F5, no bloquean el uso)

- **Detalle de transacción del panel sin padre/hijo.** `TxDetailFull` no expone
  `meta.addon`, así que `buildTicketDataFromTxDetail` (reimpresión desde el
  panel) no puede indentar hijas ni aplicar D3. Se resuelve en context/39
  (detalle de transacción) exponiendo el meta por línea.
- **El split por `amount`/`share` no descuenta el stock del add-on.** Ver
  arriba: es el mismo hueco del ítem prorrateado en general (esos modos no
  marcan lo cobrado), y se cierra con la misma solución de raíz — ítem de
  catálogo dedicado al cobro parcial + línea sin stock en `SaleService` — no
  con una reconstrucción a medias en `buildProportionalLines`.

## Fases

- **F1 — HECHA** — Migs (3 tablas) + `AddonService` (CRUD + validador de selecciones).
- **F2 — HECHA** — Panel: sección "Add-ons" en la ficha del producto (grupos propios,
  D1) + acción "copiar grupos desde otro producto".
- **F3 — HECHA** — Venta: `SaleInput.selections`, revalidación server-side, líneas
  padre/hijo, stock por selección. SIN UI todavía — testeable por API.
- **F4 — HECHA** — POS: modal de selección + carrito expandible + teclado.
- **F5 — HECHA** — Combo fijo: descuento visible en ficha/ticket; combo dinámico
  migrado al mecanismo de grupos; impresión: cocina lista todo, ticket fiscal
  solo add-ons con precio (D3).
  Detalles de la mig 136 que importan si se re-corre o se audita: grupos con el
  mismo nombre en un ítem se FUSIONAN (no se descarta uno — el reviewer propuso
  DISTINCT ON y habría perdido opciones), heredando todas las opciones y, en
  duplicados, el `priceDelta` mayor; `extraPrice`/`isPreselected` del legacy se
  preservan como `priceDelta`/`isDefault` (son lo mismo, D2); los grupos por
  CATEGORÍA se expandieron a las opciones = ítems activos de la categoría al
  momento de migrar, leyendo `item_category` Y el legacy `item.categoryId`
  (solo m2m perdía ítems); `maxSelection=0` se elevó a 1 por el CHECK de la
  134. `combo_group`/`combo_group_item` siguen existiendo (deprecadas, sin
  drop) y `ComboGroupService` + el sub-recurso `combo-groups` responden pero
  con `@deprecated`. El editor viejo se borró.
- **F6** — Reportes: ventas por add-on (qué opciones salen más), respetando
  líneas padre/hijo en los rollups.

## Offline — hueco P0 cerrado 2026-08-16

Auditoría de `context/08 §53`: `useItemAddonsPos` pedía `GET
/api/pos/item-addons?itemId=X` al server CADA VEZ que el cajero abría el
modal — sin conexión, cualquier ítem con un grupo obligatorio (`minSelect >
0`) era invendible. En gastronomía ese es el flujo NORMAL (tamaño/punto de
cocción obligatorios), no un borde.

**Resuelto alineado con `context/45-satelites-item-contact-sync.md`** (add-ons
es satélite de `item`, aunque ese plan sigue sin implementar): `PosItem.
addonGroups` viaja embebido dentro de CADA ítem del bootstrap
(`ItemsQuery.php` — el mismo SELECT que ya comparten bootstrap/bulk-get/delta,
context/43), y `useItemAddonsPos` pasó de fetch a leer
`useCatalogStore.items[].addonGroups` — cero red en el camino de armado del
carrito. `AddonService::listForItem` (usado por el panel) y el BFF
`/api/pos/item-addons` (ahora sin consumidores, se borró) quedan sin cambio de
comportamiento para el panel.

**Tamaño del bootstrap — estimado, no medido contra datos reales de prod**
(sin acceso a un tenant real con volumen de add-ons en esta sesión). Por
ítem CON grupos: ~110 bytes de overhead por grupo + ~180-220 bytes por
opción (dos UUIDs + nombre + numéricos) — un producto típico con 2 grupos ×
3 opciones ronda 1.3-1.5 KB de JSON crudo. Items SIN grupos agregan solo
`"addonGroups":[]` (~16 bytes, ya despreciable). Para un catálogo de 5.000
ítems con una fracción gastronómica realista (ej. 20-30% con add-ons =
1.000-1.500 ítems), eso son **~1.5-2 MB agregados al bootstrap SIN
comprimir**; con gzip (JSON muy repetitivo en claves, comprime bien) baja a
un orden de **200-400 KB**. No se consideró prohibitivo dado que el
bootstrap ya carga 5.000 ítems + 10.000 clientes — pero es una estimación
analítica, no una medición; si un tenant real resulta tener una fracción de
add-ons mucho mayor a la asumida acá, vale remedir antes de asumir que el
número sigue siendo chico.

**Realtime — inconsistencia encontrada, no corregida esta sesión**:
`item_addons.php` publica el evento `entity: 'item'` (alias en
`bootstrap.php`), pero como el `itemId` viaja en el body JSON del POST (no en
`$_GET['id']`), `$__auditTargetId` siempre resuelve `null` — el POS nunca
puede resolver el sync quirúrgico por id (`queueCatalogSync`,
context/15/43) y cae SIEMPRE al fallback genérico, que invalida
`pos-bootstrap` ENTERO por cualquier edición de add-ons (correcto, pero
recarga el catálogo completo en vez de un solo ítem). Es el mismo mecanismo
que ya trae el template edit fresco (ver `context/43` sección de
plantillas), así que la correctness no está en juego — la ineficiencia sí.
Fix quedaría en pasar el `itemId` también por query string en
`item_addons.php` (mismo patrón que `customer_address.php`) o publicar
explícito desde `AddonService` tras `replace`/`copy` — no se tocó por no
estar en el foco de esta sesión (prioridad: numeración > plantillas ≈
add-ons, escalado por el owner 2026-08-16).

## Notas

- **Hallazgo de F2 (2026-08-14), corrige el relevamiento inicial:** SÍ existía
  una maquinaria de combo dinámico a medio construir que el análisis no vio:
  `combo_group`/`combo_group_item` (mig 20) + `ComboGroupService` +
  `ComboGroupsEditor` en la ficha (kind `combo_dinamico`), vía
  `/v1/items?resource=combo-groups`. Es PANEL-ONLY: la venta no la lee — 
  `SaleService` no consulta `combo_group`, así que esos grupos jamás afectaron
  precio ni stock de una venta. En prod hay 2 filas. Per la unificación
  decidida, en F5 se migran esas filas a `addon_group`, se retira el editor
  viejo de la ficha y se depreca `ComboGroupService` + el sub-recurso. Hasta
  F5 conviven: el editor viejo solo aparece en `combo_dinamico` y el nuevo en
  todo lo vendible, así que un combo dinámico hoy muestra los DOS — resolver
  en F5, no antes (tocar la ficha de nuevo ahora es churn).
- NO tocar `toCompound`/`CompoundService` (deprecados). Todo lo nuevo va sobre
  `item_compound` y las tablas nuevas.
- `ProductsService` (reportes) filtra por `combo/precombo/comboAddons` — al
  implementar, revisar que las líneas hijas no dupliquen venta en los rollups
  (la plata vive en el padre + deltas; las hijas llevan cantidad, no importe,
  salvo su delta).
- La ficha de producto del POS (`product-info-dialog`, 2026-08-14) muestra
  stock por sucursal: cuando existan add-ons, esa ficha NO necesita cambios —
  los add-ons son productos y ya se consultan individualmente.
